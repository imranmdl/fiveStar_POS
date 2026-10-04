<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductVariantOptionRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\VariantOptionTypeRepository;
use App\Repositories\VariantOptionValueRepository;

/**
 * Manages the generic variant-dimension schema (Size, Colour, Pack size, ...)
 * so an administrator can configure which dimensions apply to which category
 * — spices get Pack size, clothing gets Size + Colour — without any change to
 * the inventory engine's code.
 */
final class VariantOptionService
{
    public function __construct(
        private readonly VariantOptionTypeRepository $types,
        private readonly VariantOptionValueRepository $values,
        private readonly ProductVariantOptionRepository $variantOptions,
        private readonly CategoryRepository $categories,
        private readonly ProductVariantRepository $variants,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function listTypes(): array
    {
        return array_map(function (array $type): array {
            $type['values'] = $this->values->forType((int) $type['id']);

            return $type;
        }, $this->types->all());
    }

    /** @return array<string, mixed> */
    public function createType(array $data, Request $request): array
    {
        $code = strtolower((string) $data['code']);

        if ($this->types->codeExists($code)) {
            throw new HttpException('A dimension with that code already exists.', 422, [
                'code' => ['That code is already in use.'],
            ]);
        }

        $id = $this->types->create([
            'code' => $code,
            'name' => $data['name'],
            'display_order' => $data['display_order'] ?? 100,
        ], $request->authUserId());

        $this->audit->log('variant_option_types', $id, 'create', newValues: $data, request: $request);

        return (array) $this->types->findById($id);
    }

    /** @return array<string, mixed> */
    public function addValue(string $typeUuid, array $data, Request $request): array
    {
        $type = $this->types->findByUuid($typeUuid);

        if ($type === null) {
            throw new NotFoundException('That dimension does not exist.');
        }

        $typeId = (int) $type['id'];
        $value = trim((string) $data['value']);

        if ($this->values->valueExists($typeId, $value)) {
            throw new HttpException('That value already exists for this dimension.', 422, [
                'value' => ['This value has already been added.'],
            ]);
        }

        $id = $this->values->create([
            'option_type_id' => $typeId,
            'value' => $value,
            'display_order' => $data['display_order'] ?? 100,
        ], $request->authUserId());

        $this->audit->log('variant_option_values', $id, 'create', newValues: $data, request: $request);

        return (array) $this->values->findById($id);
    }

    /**
     * Which dimensions apply to a category, and whether each is required.
     * Configured here rather than hard-coded, so a new category (dry fruits,
     * clothing, whatever comes next) never needs a code change.
     *
     * @param array<int, array{option_type_id:string, is_required?:bool, display_order?:int}> $assignments
     */
    public function setCategoryDimensions(string $categoryUuid, array $assignments, Request $request): void
    {
        $category = $this->categories->findByUuid($categoryUuid);

        if ($category === null) {
            throw new NotFoundException('That category does not exist.');
        }

        $categoryId = (int) $category['id'];

        $this->db->transaction(function () use ($categoryId, $assignments, $request): void {
            $this->db->execute(
                'UPDATE `category_option_types`
                    SET `is_deleted` = 1, `deleted_by` = :actor, `deleted_date` = NOW(), `version` = `version` + 1
                  WHERE `category_id` = :category_id AND `is_deleted` = 0',
                ['actor' => $request->authUserId(), 'category_id' => $categoryId]
            );

            $order = 10;

            foreach ($assignments as $assignment) {
                $typeId = (int) $assignment['option_type_id'];

                $this->db->execute(
                    'DELETE FROM `category_option_types`
                      WHERE `category_id` = :category_id AND `option_type_id` = :type_id AND `is_deleted` = 1',
                    ['category_id' => $categoryId, 'type_id' => $typeId]
                );

                $this->db->insert(
                    'INSERT INTO `category_option_types`
                         (`uuid`, `category_id`, `option_type_id`, `is_required`, `display_order`,
                          `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                     VALUES (:uuid, :category_id, :type_id, :required, :order, :actor, NOW(), 1, 0, 1)',
                    [
                        'uuid' => \App\Helpers\Uuid::v4(),
                        'category_id' => $categoryId,
                        'type_id' => $typeId,
                        'required' => !empty($assignment['is_required']) ? 1 : 0,
                        'order' => $assignment['display_order'] ?? $order,
                        'actor' => $request->authUserId(),
                    ]
                );

                $order += 10;
            }
        });

        $this->audit->log('category_option_types', $categoryId, 'replace', newValues: ['assignments' => $assignments], request: $request);
    }

    /**
     * Friendly variant of setVariantOptions() for inward: takes plain text
     * ({size: "M", color: "Red"}), finds each value case-insensitively and
     * creates it on the spot when staff typed one that is not listed yet.
     * Unknown dimension codes and blank values are skipped.
     *
     * @param array<string, string> $options dimension code => value text
     */
    public function assignByName(int $variantId, array $options, ?int $actorId): void
    {
        $rows = [];

        foreach ($options as $code => $text) {
            $text = trim((string) $text);

            if ($text === '') {
                continue;
            }

            $typeId = $this->db->scalar(
                'SELECT `id` FROM `variant_option_types` WHERE `code` = :code AND `is_deleted` = 0 LIMIT 1',
                ['code' => strtolower((string) $code)]
            );

            if ($typeId === null) {
                continue;
            }

            $valueId = $this->db->scalar(
                'SELECT `id` FROM `variant_option_values`
                  WHERE `option_type_id` = :type_id AND LOWER(`value`) = LOWER(:value) AND `is_deleted` = 0 LIMIT 1',
                ['type_id' => (int) $typeId, 'value' => $text]
            );

            if ($valueId === null) {
                $valueId = $this->values->create([
                    'option_type_id' => (int) $typeId,
                    'value' => mb_substr($text, 0, 60),
                    'display_order' => 200,
                ], $actorId);
            }

            $rows[] = ['option_type_id' => (int) $typeId, 'option_value_id' => (int) $valueId];
        }

        if ($rows !== []) {
            $this->variantOptions->replaceForVariant($variantId, $rows, $actorId);
        }
    }

    /**
     * Sets a variant's dimension values (e.g. Size=L, Colour=Red for
     * clothing; Pack size=500g for spices).
     *
     * @param array<int, array{option_type_id:string, option_value_id:string}> $assignments
     */
    public function setVariantOptions(string $variantUuid, array $assignments, Request $request): void
    {
        $variant = $this->variants->findByUuid($variantUuid);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        $rows = array_map(static fn (array $a): array => [
            'option_type_id' => (int) $a['option_type_id'],
            'option_value_id' => (int) $a['option_value_id'],
        ], $assignments);

        $this->variantOptions->replaceForVariant((int) $variant['id'], $rows, $request->authUserId());

        $this->audit->log(
            'product_variant_options',
            (int) $variant['id'],
            'replace',
            newValues: ['assignments' => $assignments],
            request: $request,
            entityUuid: $variantUuid,
        );
    }
}
