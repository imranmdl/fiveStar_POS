<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\PriceChangeLogRepository;
use App\Repositories\ProductVariantRepository;
use App\Services\PricingService;

/**
 * Applies one of the brief's §7 four price-change decisions — manual,
 * use_average, use_new, keep_old — typically in response to a
 * `price_decisions_pending` entry PurchaseOrderService::create() returned.
 * Restricted to $manager in routes/api_v1.php: a selling-price change is
 * treated as a sensitive action distinct from recording the inward itself.
 */
final class PricingDecisionController extends BaseController
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly ProductVariantRepository $variants,
        private readonly PriceChangeLogRepository $priceChangeLog,
    ) {
    }

    /**
     * GET /api/v1/admin/pricing/live
     * Every pack size's current cost, current price and what its rule would
     * set the price to right now — the real-time link between purchase
     * inward and the online price, laid out for the whole catalogue.
     */
    public function live(Request $request): Response
    {
        return Response::success(['variants' => $this->pricing->liveSnapshot()], 'Live pricing loaded');
    }

    /** POST /api/v1/admin/pricing/decisions */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'variant_uuid' => 'required|uuid',
            'decision' => 'required|in:manual,use_average,use_new,keep_old',
            'manual_price' => 'nullable|numeric|min:0.01',
            'purchase_price' => 'nullable|numeric|min:0',
            'average_cost' => 'nullable|numeric|min:0',
            'reference_type' => 'nullable|in:purchase_order,manual',
            'reference_id' => 'nullable|int|min:1',
            'reason' => 'nullable|string|max:255',
        ]);

        $variant = $this->variants->findByUuid($data['variant_uuid']);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        $result = $this->pricing->applyDecision(
            variantId: (int) $variant['id'],
            decision: $data['decision'],
            manualPrice: isset($data['manual_price']) ? (float) $data['manual_price'] : null,
            purchasePrice: isset($data['purchase_price']) ? (float) $data['purchase_price'] : null,
            averageCost: isset($data['average_cost']) ? (float) $data['average_cost'] : null,
            referenceType: $data['reference_type'] ?? 'manual',
            referenceId: isset($data['reference_id']) ? (int) $data['reference_id'] : null,
            reason: $data['reason'] ?? null,
            performedBy: $request->authUserId(),
            request: $request,
        );

        return Response::success($result, 'Pricing decision applied');
    }

    /**
     * GET /api/v1/admin/pricing/history/{variantUuid}
     * Every logged selling-price change for one pack size — old price, new
     * price, the decision behind it and why. Nothing new is tracked here;
     * PricingService::applyDecision() has written this table on every price
     * change since the pricing-strategy phase, it just had no reader until
     * now.
     */
    public function history(Request $request): Response
    {
        $variant = $this->variants->findByUuid((string) $request->routeParam('variantUuid'));

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        return Response::success(
            $this->priceChangeLog->forVariant((int) $variant['id']),
            'Price history loaded'
        );
    }
}
