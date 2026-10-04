<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\CategoryRepository;
use App\Repositories\PricingRuleRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;

/**
 * Configuration for PricingService: which markup/margin applies to which
 * category, product or variant. CRUD only — the resolution and calculation
 * logic itself lives in PricingService, which is what actually reads these
 * rules when a purchase is recorded.
 */
final class PricingRuleService
{
    private const SCOPES = ['global', 'category', 'product', 'variant'];
    private const CALCULATIONS = ['markup_percent', 'margin_percent'];
    private const TAX_MODES = ['exclusive', 'inclusive'];

    public function __construct(
        private readonly PricingRuleRepository $rules,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        return array_map([$this, 'present'], $this->rules->all());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data, Request $request): array
    {
        $attributes = $this->normalise($this->resolveTargetIds($data));

        $id = $this->rules->create($attributes, $request->authUserId());
        $rule = (array) $this->rules->findById($id);
        $detail = (array) $this->rules->detailByUuid((string) $rule['uuid']);

        $this->audit->log('pricing_rules', $id, 'create', newValues: $rule, request: $request, entityUuid: (string) $rule['uuid']);

        return $this->present($detail);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $data, Request $request): array
    {
        $existing = $this->requireByUuid($uuid);

        // Validated as the FULL resulting rule, not just the supplied keys —
        // a partial patch could otherwise leave scope and its target
        // inconsistent (e.g. changing scope to 'global' without also
        // clearing category_id), which would fail at the database's
        // chk_pricing_rules_scope_target CHECK with a much less clear error.
        $merged = array_merge($existing, $this->resolveTargetIds($data));
        $attributes = $this->normalise($merged);

        $this->rules->update((int) $existing['id'], $attributes, $request->authUserId());
        $fresh = (array) $this->rules->findById((int) $existing['id']);
        $detail = (array) $this->rules->detailByUuid($uuid);

        $this->audit->log(
            'pricing_rules',
            (int) $existing['id'],
            'update',
            oldValues: $existing,
            newValues: $fresh,
            request: $request,
            entityUuid: $uuid,
        );

        return $this->present($detail);
    }

    public function deactivate(string $uuid, Request $request): void
    {
        $rule = $this->requireByUuid($uuid);

        $this->rules->update((int) $rule['id'], ['status' => 'inactive'], $request->authUserId());

        $this->audit->log('pricing_rules', (int) $rule['id'], 'deactivate', request: $request, entityUuid: $uuid);
    }

    /**
     * Resolves category_uuid/product_uuid/variant_uuid — ids are never
     * exposed to callers, so this is the only place in the service that
     * sees an internal id at all. normalise() below still decides, from
     * `scope`, which (if any) of the resolved ids actually gets used.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resolveTargetIds(array $data): array
    {
        if (!empty($data['category_uuid'])) {
            $category = $this->categories->findByUuid((string) $data['category_uuid']);

            if ($category === null) {
                throw new NotFoundException('That category does not exist.');
            }

            $data['category_id'] = (int) $category['id'];
        }

        if (!empty($data['product_uuid'])) {
            $product = $this->products->findByUuid((string) $data['product_uuid']);

            if ($product === null) {
                throw new NotFoundException('That product does not exist.');
            }

            $data['product_id'] = (int) $product['id'];
        }

        if (!empty($data['variant_uuid'])) {
            $variant = $this->variants->findByUuid((string) $data['variant_uuid']);

            if ($variant === null) {
                throw new NotFoundException('That pack size does not exist.');
            }

            $data['product_variant_id'] = (int) $variant['id'];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $scope = (string) ($data['scope'] ?? '');

        if (!in_array($scope, self::SCOPES, true)) {
            throw new HttpException('Unknown scope: ' . $scope, 422, [
                'scope' => ['Must be one of: ' . implode(', ', self::SCOPES)],
            ]);
        }

        if (!in_array((string) ($data['calculation'] ?? ''), self::CALCULATIONS, true)) {
            throw new HttpException('Unknown calculation: ' . ($data['calculation'] ?? ''), 422, [
                'calculation' => ['Must be one of: ' . implode(', ', self::CALCULATIONS)],
            ]);
        }

        if (!in_array((string) ($data['tax_mode'] ?? 'exclusive'), self::TAX_MODES, true)) {
            throw new HttpException('Unknown tax mode.', 422, [
                'tax_mode' => ['Must be one of: ' . implode(', ', self::TAX_MODES)],
            ]);
        }

        if ($data['calculation'] === 'margin_percent' && (float) ($data['rate'] ?? 0) >= 100) {
            throw new HttpException('A margin rate must be below 100%.', 422, [
                'rate' => ['A margin-of-selling-price rate of 100% or more is mathematically impossible.'],
            ]);
        }

        // Exactly the reference the declared scope needs, nothing else —
        // matches chk_pricing_rules_scope_target rather than relying on the
        // database to reject an inconsistent combination. Left null (not 0)
        // when missing, so the check below can actually tell the two apart.
        $categoryId = $scope === 'category' && isset($data['category_id']) ? (int) $data['category_id'] : null;
        $productId = $scope === 'product' && isset($data['product_id']) ? (int) $data['product_id'] : null;
        $variantId = $scope === 'variant' && isset($data['product_variant_id']) ? (int) $data['product_variant_id'] : null;

        if ($scope !== 'global' && $categoryId === null && $productId === null && $variantId === null) {
            throw new HttpException(sprintf('A %s-scoped rule needs a %s to apply to.', $scope, $scope), 422);
        }

        return [
            'name' => $data['name'],
            'scope' => $scope,
            'category_id' => $categoryId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'calculation' => $data['calculation'],
            'rate' => $data['rate'],
            'tax_mode' => $data['tax_mode'] ?? 'exclusive',
            'priority' => $data['priority'] ?? 100,
            'status' => $data['status'] ?? 'active',
        ];
    }

    /**
     * Strips internal ids — never exposed to callers — leaving the uuid
     * companions the joined query already provides.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        unset($row['id'], $row['category_id'], $row['product_id'], $row['product_variant_id']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function requireByUuid(string $uuid): array
    {
        $rule = $this->rules->findByUuid($uuid);

        if ($rule === null) {
            throw new NotFoundException('That pricing rule does not exist.');
        }

        return $rule;
    }
}
