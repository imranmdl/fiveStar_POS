<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

/**
 * Flags items for an administrator to check ("admin reviewed" = no).
 *
 * Data-entry staff add items and record purchases; a typo in a name, a unit,
 * a category, a price or a purchase cost then spreads into stock value,
 * vendor balances and the shop. Anything that may carry such a typo is put
 * in the administrator's review queue (Item review page) with the reason:
 *
 *  - an item added by anyone who is not an administrator;
 *  - live prices changed by a non-administrator's purchase entry;
 *  - a purchase cost that looks wrong — far from what the item usually
 *    costs, or above its selling price — whoever entered it.
 *
 * Flagging never blocks the work that triggered it; it only records.
 */
final class ItemReviewService
{
    /** Roles whose own entries need no second look. */
    public const ADMIN_ROLES = ['administrator', 'super_admin'];

    /** A new landing cost this far from the usual cost is probably a typo. */
    public const COST_DEVIATION = 0.25;

    public function __construct(private readonly Database $db)
    {
    }

    public static function isAdmin(?string $role): bool
    {
        return in_array((string) $role, self::ADMIN_ROLES, true);
    }

    /** Puts a product in the review queue (keeps the first reason if already waiting). */
    public function flag(int $productId, string $reason): void
    {
        $this->db->execute(
            'UPDATE `products`
                SET `admin_reviewed` = 0,
                    `review_reason` = CASE WHEN `admin_reviewed` = 0 AND `review_reason` IS NOT NULL
                                           THEN LEFT(CONCAT(`review_reason`, :sep, :reason1), 255)
                                           ELSE LEFT(:reason2, 255) END,
                    `review_flagged_date` = CASE WHEN `admin_reviewed` = 0 AND `review_flagged_date` IS NOT NULL
                                                 THEN `review_flagged_date` ELSE NOW() END
              WHERE `id` = :id AND `is_deleted` = 0',
            ['sep' => ' · ', 'reason1' => $reason, 'reason2' => $reason, 'id' => $productId]
        );
    }

    /** An item created by staff: queued unless an administrator entered it. */
    public function flagIfEnteredByStaff(int $productId, Request $request, string $where): void
    {
        $role = $request->authRole();

        if (self::isAdmin($role)) {
            return;
        }

        $this->flag($productId, sprintf('Added by %s via %s', self::roleLabel($role), $where));
    }

    /** A non-administrator's purchase entry changed the live MRP / selling price. */
    public function flagPriceChangeByStaff(int $variantId, Request $request, string $poNumber): void
    {
        $role = $request->authRole();

        if (self::isAdmin($role)) {
            return;
        }

        $productId = $this->productIdOf($variantId);

        if ($productId !== null) {
            $this->flag($productId, sprintf('Prices changed on purchase %s by %s', $poNumber, self::roleLabel($role)));
        }
    }

    /**
     * Called before a purchase line reaches stock, while the item's usual
     * cost is still the old average: a landing cost far from it, or above
     * the selling price, is most likely mistyped.
     */
    public function checkPurchaseCost(int $variantId, int $warehouseId, float $landingCost, string $poNumber): void
    {
        $row = $this->db->selectOne(
            'SELECT v.`product_id`, v.`selling_price`, v.`variant_name`,
                    (SELECT s.`average_cost` FROM `inventory_stock` s
                      WHERE s.`product_variant_id` = v.`id` AND s.`warehouse_id` = :wh LIMIT 1) AS `average_cost`
               FROM `product_variants` v WHERE v.`id` = :id',
            ['id' => $variantId, 'wh' => $warehouseId]
        );

        if ($row === null) {
            return;
        }

        $selling = (float) $row['selling_price'];
        $usual = $row['average_cost'] !== null ? (float) $row['average_cost'] : 0.0;
        $reason = null;

        if ($selling > 0 && $landingCost > $selling) {
            $reason = sprintf('Purchase %s: landing cost ₹%s is above the selling price ₹%s (%s)',
                $poNumber, self::money($landingCost), self::money($selling), $row['variant_name']);
        } elseif ($usual > 0 && abs($landingCost - $usual) / $usual > self::COST_DEVIATION) {
            $reason = sprintf('Purchase %s: landing cost ₹%s vs usual ₹%s (%s)',
                $poNumber, self::money($landingCost), self::money($usual), $row['variant_name']);
        }

        if ($reason !== null) {
            $this->flag((int) $row['product_id'], $reason);
        }
    }

    private function productIdOf(int $variantId): ?int
    {
        $id = $this->db->scalar('SELECT `product_id` FROM `product_variants` WHERE `id` = :id', ['id' => $variantId]);

        return $id === null || $id === false ? null : (int) $id;
    }

    private static function roleLabel(?string $role): string
    {
        return $role === null || $role === '' ? 'staff' : str_replace('_', ' ', $role);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }
}
