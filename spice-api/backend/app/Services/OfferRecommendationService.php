<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Dynamic Offers & Smart Discounts: "recommended offers" for slow-moving and
 * near-expiry stock, shown on the admin dashboard.
 *
 * DELIBERATELY READ-ONLY. This never creates, activates or modifies an offer
 * itself — it only surfaces a suggestion (which product, why, and a starting
 * discount to consider) that an admin turns into a real offer through the
 * existing Promotions screen, the same way any other offer is created:
 * starting as a draft, requiring an explicit Activate. That is the "never
 * automatically apply a discount without admin configuration/approval"
 * requirement — satisfied by this service having no write path at all,
 * rather than by a permission check that could be bypassed.
 *
 * Reuses ReportingService::slowMovingProducts() (sales velocity, online +
 * POS combined) and InventoryService::expiryReport() (the same batch-expiry
 * data the Inventory admin's own Expiry tab already shows) — no new sales or
 * stock tracking of any kind.
 */
final class OfferRecommendationService
{
    /** A slow-moving variant with zero sales in the window is flagged more urgently than one that merely sold little. */
    private const SLOW_MOVING_WINDOW_DAYS = 30;

    public function __construct(
        private readonly ReportingService $reporting,
        private readonly InventoryService $inventory,
        private readonly Database $db,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function recommendations(int $limit = 20): array
    {
        $alreadyTargeted = $this->productUuidsWithActiveProductOffer();
        $items = [];

        foreach ($this->reporting->slowMovingProducts(self::SLOW_MOVING_WINDOW_DAYS, 30) as $row) {
            if (in_array($row['product_uuid'], $alreadyTargeted, true)) {
                continue;
            }

            $unitsSold = (int) $row['units_sold'];
            $qty = rtrim(rtrim(number_format((float) $row['qty_on_hand'], 2), '0'), '.');

            $items[] = [
                'reason' => 'slow_moving',
                'urgency' => $unitsSold === 0 ? 'high' : 'normal',
                'product_uuid' => $row['product_uuid'],
                'product_name' => $row['product_name'],
                'variant_name' => $row['variant_name'],
                'sku' => $row['sku'],
                'detail' => $unitsSold === 0
                    ? sprintf('No sales in the last %d days — %s in stock', self::SLOW_MOVING_WINDOW_DAYS, $qty)
                    : sprintf('Only %d sold in the last %d days — %s in stock', $unitsSold, self::SLOW_MOVING_WINDOW_DAYS, $qty),
                'suggested_discount_type' => 'percentage',
                'suggested_discount_value' => $unitsSold === 0 ? 15.0 : 10.0,
            ];
        }

        foreach ($this->inventory->expiryReport(['status' => 'expiring_soon'])['items'] as $row) {
            $days = (int) $row['days_remaining'];
            $qty = rtrim(rtrim(number_format((float) $row['quantity'], 2), '0'), '.');

            $items[] = [
                'reason' => 'near_expiry',
                'urgency' => $days <= 5 ? 'high' : 'normal',
                // No product_uuid here — expiryReport() is keyed by batch/variant,
                // not product, so the "already has a product-scoped offer" check
                // above only applies to slow-moving items. A duplicate suggestion
                // here is a minor inconvenience, not an incorrect discount — this
                // service never applies anything by itself.
                'product_uuid' => null,
                'product_name' => $row['product_name'],
                'variant_name' => $row['variant_name'],
                'sku' => $row['sku'],
                'detail' => sprintf('Expires in %d day(s) — %s units in this batch', max(0, $days), $qty),
                'suggested_discount_type' => 'percentage',
                'suggested_discount_value' => $days <= 5 ? 20.0 : 12.0,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = static fn (array $r): int => $r['urgency'] === 'high' ? 0 : 1;

            return $rank($a) <=> $rank($b);
        });

        return array_slice($items, 0, max(1, min($limit, 100)));
    }

    /**
     * Products already covered by a currently-active, product-scoped offer —
     * so a slow-moving item someone already put an offer on isn't suggested
     * again. Category-scoped and storewide ('applies_to' = 'all') offers are
     * intentionally not checked here: resolving "is this specific product
     * within that category" for every slow-moving row would cost a query per
     * row, and an extra suggestion on a product that already has a wider
     * offer is a minor inconvenience an admin dismisses at a glance — not an
     * incorrect discount, since this service never applies anything itself.
     *
     * @return array<int, string>
     */
    private function productUuidsWithActiveProductOffer(): array
    {
        $rows = $this->db->select(
            "SELECT DISTINCT p.`uuid`
               FROM `offer_targets` ot
               INNER JOIN `offers` o ON o.`id` = ot.`offer_id`
               INNER JOIN `products` p ON p.`id` = ot.`product_id`
              WHERE ot.`target_type` = 'product' AND ot.`is_deleted` = 0
                AND o.`status` = 'active' AND o.`is_deleted` = 0"
        );

        return array_column($rows, 'uuid');
    }
}
