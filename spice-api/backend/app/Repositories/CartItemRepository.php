<?php

declare(strict_types=1);

namespace App\Repositories;

final class CartItemRepository extends BaseRepository
{
    use UpsertsCartLines;

    protected function table(): string
    {
        return 'cart_items';
    }

    protected function fillable(): array
    {
        return [
            'cart_id', 'product_id', 'variant_id', 'quantity',
            'unit_price_snapshot', 'unit_mrp_snapshot', 'gst_rate_snapshot',
            'unit_weight_snapshot', 'price_changed_date', 'is_saved_for_later',
            'is_gift', 'gift_message', 'notes',
        ];
    }

    /**
     * Cart contents with live pricing attached, via vw_cart_lines. One query
     * for the whole cart regardless of size.
     *
     * @return array<int, array<string, mixed>>
     */
    public function linesForCart(int $cartId): array
    {
        return $this->db->select(
            'SELECT * FROM `vw_cart_lines`
              WHERE `cart_id` = :cart_id
              ORDER BY `is_saved_for_later` ASC, `created_date` ASC',
            ['cart_id' => $cartId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findLineByUuid(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `vw_cart_lines` WHERE `uuid` = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * Includes soft-deleted rows on purpose. UNIQUE (cart_id, variant_id) means
     * a removed line still occupies the slot, so re-adding the same pack size
     * has to revive that row rather than insert a second one.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Finds a line with a locking read.
     *
     * Under REPEATABLE READ a plain SELECT inside a transaction sees the
     * snapshot taken when the transaction began, so a row another request
     * committed a millisecond ago is invisible. A locking read always sees the
     * latest committed version, which is what a lost insert race needs in order
     * to find the winner's row.
     *
     * @return array<string, mixed>|null
     */
    public function findByCartAndVariantForUpdate(int $cartId, int $variantId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `cart_items`
              WHERE `cart_id` = :cart_id AND `variant_id` = :variant_id
              LIMIT 1 FOR UPDATE',
            ['cart_id' => $cartId, 'variant_id' => $variantId]
        );
    }

    public function findByCartAndVariant(int $cartId, int $variantId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `cart_items`
              WHERE `cart_id` = :cart_id AND `variant_id` = :variant_id
              LIMIT 1',
            ['cart_id' => $cartId, 'variant_id' => $variantId]
        );
    }

    /**
     * Revives a previously removed line with fresh pricing.
     *
     * @param array<string, mixed> $attributes
     */
    public function reviveLine(int $itemId, array $attributes, ?int $actorId): void
    {
        $this->db->execute(
            'UPDATE `cart_items`
                SET `quantity` = :quantity,
                    `unit_price_snapshot` = :unit_price,
                    `unit_mrp_snapshot` = :unit_mrp,
                    `gst_rate_snapshot` = :gst_rate,
                    `unit_weight_snapshot` = :unit_weight,
                    `is_saved_for_later` = :saved,
                    `price_changed_date` = NULL,
                    `is_deleted` = 0, `deleted_by` = NULL, `deleted_date` = NULL,
                    `is_active` = 1,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            [
                'quantity' => (int) $attributes['quantity'],
                'unit_price' => $attributes['unit_price_snapshot'],
                'unit_mrp' => $attributes['unit_mrp_snapshot'],
                'gst_rate' => $attributes['gst_rate_snapshot'],
                'unit_weight' => (int) $attributes['unit_weight_snapshot'],
                'saved' => (int) ($attributes['is_saved_for_later'] ?? 0),
                'actor' => $actorId,
                'id' => $itemId,
            ]
        );
    }

    public function setQuantity(int $itemId, int $quantity, ?int $actorId): void
    {
        $this->update($itemId, ['quantity' => $quantity], $actorId);
    }

    public function setSavedForLater(int $itemId, bool $saved, ?int $actorId): void
    {
        $this->update($itemId, ['is_saved_for_later' => $saved ? 1 : 0], $actorId);
    }

    /**
     * Records that the live price has moved away from what the customer was
     * shown, and re-snapshots. The timestamp is what lets the API tell them.
     */
    public function refreshPriceSnapshot(
        int $itemId,
        string $unitPrice,
        string $unitMrp,
        string $gstRate,
        int $unitWeight,
    ): void {
        $this->db->execute(
            'UPDATE `cart_items`
                SET `unit_price_snapshot` = :unit_price,
                    `unit_mrp_snapshot` = :unit_mrp,
                    `gst_rate_snapshot` = :gst_rate,
                    `unit_weight_snapshot` = :unit_weight,
                    `price_changed_date` = NOW(),
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            [
                'unit_price' => $unitPrice,
                'unit_mrp' => $unitMrp,
                'gst_rate' => $gstRate,
                'unit_weight' => $unitWeight,
                'id' => $itemId,
            ]
        );
    }

    public function clearPriceChangeFlag(int $cartId): void
    {
        $this->db->execute(
            'UPDATE `cart_items`
                SET `price_changed_date` = NULL, `updated_date` = NOW()
              WHERE `cart_id` = :cart_id AND `price_changed_date` IS NOT NULL AND `is_deleted` = 0',
            ['cart_id' => $cartId]
        );
    }

    public function countActiveLines(int $cartId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `cart_items`
              WHERE `cart_id` = :cart_id AND `is_deleted` = 0 AND `is_saved_for_later` = 0',
            ['cart_id' => $cartId]
        );
    }

    public function countAllLines(int $cartId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `cart_items`
              WHERE `cart_id` = :cart_id AND `is_deleted` = 0',
            ['cart_id' => $cartId]
        );
    }

    public function clearCart(int $cartId, bool $includeSavedForLater, ?int $actorId): int
    {
        $sql = 'UPDATE `cart_items`
                   SET `is_deleted` = 1, `deleted_by` = :actor, `deleted_date` = NOW(),
                       `version` = `version` + 1
                 WHERE `cart_id` = :cart_id AND `is_deleted` = 0';

        if (!$includeSavedForLater) {
            $sql .= ' AND `is_saved_for_later` = 0';
        }

        return $this->db->execute($sql, ['actor' => $actorId, 'cart_id' => $cartId]);
    }

    public function moveLineToCart(int $itemId, int $targetCartId, ?int $actorId): void
    {
        $this->db->execute(
            'UPDATE `cart_items`
                SET `cart_id` = :target, `updated_by` = :actor,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['target' => $targetCartId, 'actor' => $actorId, 'id' => $itemId]
        );
    }
}
