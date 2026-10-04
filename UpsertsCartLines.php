<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Uuid;

/**
 * Atomic add-or-increment for a cart line.
 *
 * Extracted into its own trait purely so the SQL can carry the explanation it
 * needs without burying the rest of the repository.
 *
 * THE PROBLEM THIS SOLVES. The obvious implementation is "look for an existing
 * line; increment it if found, insert one if not". Two concurrent requests both
 * find nothing and both insert, and the unique index on (cart_id, variant_id)
 * rejects the loser. Catching that and re-reading does not help either: under
 * MySQL's REPEATABLE READ the loser's transaction snapshot predates the winner's
 * commit, so the re-read finds nothing and rethrows. A locking read does see the
 * row, but blocks until the winner commits, and with eight concurrent requests
 * those waits cascade into lock timeouts — measurably worse.
 *
 * The fix is to stop reading altogether. INSERT ... ON DUPLICATE KEY UPDATE is a
 * single atomic statement: whichever request arrives second updates the row the
 * first created, with no snapshot to be stale and no lock to wait on.
 */
trait UpsertsCartLines
{
    /**
     * Adds a line, or increases the quantity of the one already there.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array{quantity:int, was_new:bool}
     */
    public function upsertLine(array $attributes, ?int $actorId): array
    {
        // ASSIGNMENT ORDER MATTERS. Inside ON DUPLICATE KEY UPDATE, MySQL
        // evaluates assignments left to right, and a column reads its OLD value
        // only until it is itself assigned. `is_deleted` is therefore read by
        // every expression that needs it and set LAST — moving that line up
        // would make every conditional below take the wrong branch.
        //
        // The conditionals exist because a soft-deleted line must be REVIVED
        // with the requested quantity and fresh pricing, while a live line must
        // be INCREMENTED and keep the price it was added at.
        $this->db->execute(
            'INSERT INTO `cart_items`
                 (`uuid`, `cart_id`, `product_id`, `variant_id`, `quantity`,
                  `unit_price_snapshot`, `unit_mrp_snapshot`, `gst_rate_snapshot`,
                  `unit_weight_snapshot`, `is_saved_for_later`, `is_gift`, `gift_message`,
                  `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES
                 (:uuid, :cart_id, :product_id, :variant_id, :quantity,
                  :unit_price, :unit_mrp, :gst_rate, :unit_weight,
                  :saved_for_later, :is_gift, :gift_message,
                  :actor, NOW(), 1, 0, 1)
             ON DUPLICATE KEY UPDATE
                 `quantity` = IF(`is_deleted` = 1, VALUES(`quantity`), `quantity` + VALUES(`quantity`)),
                 `unit_price_snapshot` = IF(`is_deleted` = 1, VALUES(`unit_price_snapshot`), `unit_price_snapshot`),
                 `unit_mrp_snapshot`   = IF(`is_deleted` = 1, VALUES(`unit_mrp_snapshot`),   `unit_mrp_snapshot`),
                 `gst_rate_snapshot`   = IF(`is_deleted` = 1, VALUES(`gst_rate_snapshot`),   `gst_rate_snapshot`),
                 `unit_weight_snapshot`= IF(`is_deleted` = 1, VALUES(`unit_weight_snapshot`),`unit_weight_snapshot`),
                 `price_changed_date`  = IF(`is_deleted` = 1, NULL, `price_changed_date`),
                 `is_gift`             = VALUES(`is_gift`),
                 `gift_message`        = VALUES(`gift_message`),
                 -- Adding an item back always un-saves it: a customer who taps
                 -- "add to cart" wants it in the cart, not in save-for-later.
                 `is_saved_for_later`  = 0,
                 `deleted_by`          = NULL,
                 `deleted_date`        = NULL,
                 `is_active`           = 1,
                 `updated_by`          = VALUES(`created_by`),
                 `updated_date`        = NOW(),
                 `version`             = `version` + 1,
                 -- Last, for the reason above.
                 `is_deleted`          = 0',
            [
                'uuid' => Uuid::v4(),
                'cart_id' => (int) $attributes['cart_id'],
                'product_id' => (int) $attributes['product_id'],
                'variant_id' => (int) $attributes['variant_id'],
                'quantity' => (int) $attributes['quantity'],
                'unit_price' => (string) $attributes['unit_price_snapshot'],
                'unit_mrp' => (string) $attributes['unit_mrp_snapshot'],
                'gst_rate' => (string) $attributes['gst_rate_snapshot'],
                'unit_weight' => (int) $attributes['unit_weight_snapshot'],
                'saved_for_later' => (int) ($attributes['is_saved_for_later'] ?? 0),
                'is_gift' => (int) ($attributes['is_gift'] ?? 0),
                'gift_message' => $attributes['gift_message'] ?? null,
                'actor' => $actorId,
            ]
        );

        // MySQL reports 1 affected row for an insert and 2 for an update that
        // changed something, which is the only way to tell which branch ran
        // without a second query.
        $row = $this->db->selectOne(
            'SELECT `quantity` FROM `cart_items`
              WHERE `cart_id` = :cart_id AND `variant_id` = :variant_id LIMIT 1',
            [
                'cart_id' => (int) $attributes['cart_id'],
                'variant_id' => (int) $attributes['variant_id'],
            ]
        );

        return [
            'quantity' => (int) ($row['quantity'] ?? $attributes['quantity']),
            'was_new' => (int) ($row['quantity'] ?? 0) === (int) $attributes['quantity'],
        ];
    }
}
