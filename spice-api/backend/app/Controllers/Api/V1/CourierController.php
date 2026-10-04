<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\CourierRepository;
use App\Services\AuditService;

/**
 * Courier administration: which carriers are available, what they charge, where
 * they deliver and how well they perform.
 */
final class CourierController extends BaseController
{
    public function __construct(
        private readonly CourierRepository $couriers,
        private readonly AuditService $audit,
    ) {
    }

    /** GET /api/v1/admin/couriers */
    public function index(Request $request): Response
    {
        return Response::success(
            ['couriers' => array_map([$this, 'present'], $this->couriers->all())],
            'Couriers loaded'
        );
    }

    /** GET /api/v1/admin/couriers/{code} */
    public function show(Request $request): Response
    {
        $courier = $this->requireCourier((string) $request->routeParam('code'));

        return Response::success([
            'courier' => $this->present($courier),
            'serviceability' => $this->couriers->serviceabilityRules((int) $courier['id']),
            'rate_cards' => $this->couriers->rateCards((int) $courier['id']),
        ], 'Courier loaded');
    }

    /** PATCH /api/v1/admin/couriers/{code} */
    public function update(Request $request): Response
    {
        $courier = $this->requireCourier((string) $request->routeParam('code'));

        $data = Validator::make($request->all(), [
            'name' => 'nullable|string|max:120',
            'priority' => 'nullable|int|min:1|max:9999',
            'max_weight_grams' => 'nullable|int|min:1',
            'max_order_value' => 'nullable|numeric|min:0',
            'handles_fragile' => 'nullable|boolean',
            'is_enabled' => 'nullable|boolean',
            'disabled_reason' => 'nullable|string|max:255',
            'support_phone' => 'nullable|string|max:20',
        ]);

        $changes = array_intersect_key($data, $request->all());

        if ($changes === []) {
            throw new \App\Core\Exceptions\HttpException('No changes were supplied.', 422);
        }

        // Disabling a courier without saying why leaves the next person guessing
        // whether it was a rate dispute or a temporary outage.
        if (isset($changes['is_enabled']) && (int) $changes['is_enabled'] === 0
            && ($changes['disabled_reason'] ?? $courier['disabled_reason']) === null) {
            throw new \App\Core\Exceptions\HttpException(
                'Give a reason when disabling a courier.',
                422,
                ['disabled_reason' => ['A reason is required so the next person knows why.']]
            );
        }

        // Re-enabling clears the old reason. Leaving it behind is misleading in
        // the console — a courier showing "Rate contract under renegotiation"
        // while happily accepting parcels — and it also defeats the guard above,
        // because the next disable finds a stale reason already present and
        // lets itself through unexplained.
        if (isset($changes['is_enabled']) && (int) $changes['is_enabled'] === 1) {
            $changes['disabled_reason'] = null;
        }

        $this->couriers->update((int) $courier['id'], $changes, $request->authUserId());

        $this->audit->log(
            entityName: 'couriers',
            entityId: (int) $courier['id'],
            action: 'update',
            oldValues: array_intersect_key($courier, $changes),
            newValues: $changes,
            request: $request,
        );

        return Response::success(
            ['courier' => $this->present((array) $this->couriers->findById((int) $courier['id']))],
            'Courier updated'
        );
    }

    /** GET /api/v1/admin/couriers/performance */
    public function performance(Request $request): Response
    {
        return Response::success(
            ['performance' => $this->couriers->performance()],
            'Courier performance loaded'
        );
    }

    /**
     * POST /api/v1/admin/couriers/recalculate-reliability
     *
     * Feeds real delivery outcomes back into the score BR-007 uses, so the
     * selector improves from its own history instead of a fixed guess.
     */
    public function recalculateReliability(Request $request): Response
    {
        $minimum = max(5, (int) $request->input('minimum_shipments', 20));
        $updated = $this->couriers->recalculateReliability($minimum);

        return Response::success(
            ['couriers_updated' => $updated, 'minimum_shipments' => $minimum],
            $updated === 0
                ? 'No courier has enough delivery history yet to score reliably.'
                : sprintf('%d courier(s) rescored.', $updated)
        );
    }

    /** @return array<string, mixed> */
    private function requireCourier(string $code): array
    {
        $courier = $this->couriers->findByCode($code);

        if ($courier === null) {
            throw new NotFoundException('That courier does not exist.');
        }

        return $courier;
    }

    /**
     * @param array<string, mixed> $courier
     *
     * @return array<string, mixed>
     */
    private function present(array $courier): array
    {
        return [
            'uuid' => $courier['uuid'],
            'code' => $courier['code'],
            'name' => $courier['name'],
            'adapter' => $courier['adapter'],
            'priority' => (int) $courier['priority'],
            'reliability_score' => (float) $courier['reliability_score'],
            'min_weight_grams' => (int) $courier['min_weight_grams'],
            'max_weight_grams' => (int) $courier['max_weight_grams'],
            'max_order_value' => $courier['max_order_value'] === null ? null : (float) $courier['max_order_value'],
            'volumetric_divisor' => (int) $courier['volumetric_divisor'],
            'handles_fragile' => (bool) $courier['handles_fragile'],
            'supports_pickup' => (bool) $courier['supports_pickup'],
            'supports_manifest' => (bool) $courier['supports_manifest'],
            'is_enabled' => (bool) $courier['is_enabled'],
            'disabled_reason' => $courier['disabled_reason'],
            'support_phone' => $courier['support_phone'],
        ];
    }
}
