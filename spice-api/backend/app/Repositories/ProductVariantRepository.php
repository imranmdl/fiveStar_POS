<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Barcode;

final class ProductVariantRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'product_variants';
    }

    protected function fillable(): array
    {
        return [
            'product_id', 'sku', 'barcode', 'expiry_date', 'variant_name', 'weight_grams', 'pack_length_mm', 'pack_width_mm', 'pack_height_mm', 'is_fragile', 'packed_weight_grams',
            'pack_type', 'stock_unit_type', 'unit_label', 'mrp', 'selling_price', 'offer_price', 'offer_start_date',
            'offer_end_date', 'max_order_quantity', 'is_default', 'display_order',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function forProduct(int $productId): array
    {
        return $this->db->select(
            'SELECT * FROM `product_variants`
              WHERE `product_id` = :product_id AND `is_deleted` = 0
              ORDER BY `display_order` ASC, `weight_grams` ASC',
            ['product_id' => $productId]
        );
    }

    /**
     * Priced variant lookup used by cart and checkout in later phases. Reads
     * the effective price from the pricing view rather than recomputing it.
     *
     * @return array<string, mixed>|null
     */
    public function findPricedByUuid(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT vp.*, p.`uuid` AS `product_uuid`, p.`name` AS `product_name`,
                    p.`slug` AS `product_slug`, p.`gst_rate`, p.`status` AS `product_status`
               FROM `vw_variant_pricing` vp
               INNER JOIN `products` p ON p.`id` = vp.`product_id`
              WHERE vp.`uuid` = :uuid AND p.`is_deleted` = 0
              LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        return $this->existsWhere('sku', $sku, $exceptId);
    }

    /**
     * Free-text fallback for a till with no working scanner — matches the
     * product name, pack name or SKU against whatever the cashier typed.
     * Exact scans still go through findByCode(); this is for picking an
     * item by name, one at a time.
     *
     * Includes draft/archived products too, not published only — staff (in
     * Purchase Inward especially) need to see a pack size that exists but
     * isn't live, rather than have it silently vanish from the results as
     * if it didn't exist. `product_status` is returned so each caller can
     * decide what to do with a non-published result (the till refuses to add
     * one to a sale; Purchase Inward records a purchase against it exactly
     * as it would any other). weight_grams comes along so a caller can spot
     * two entries for what looks like the same pack size — that duplication
     * is a data-entry mistake worth surfacing, not choosing between silently.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchByName(string $query, int $limit = 12): array
    {
        $needle = '%' . $query . '%';

        return $this->db->select(
            'SELECT v.`uuid`, v.`sku`, v.`barcode`, v.`variant_name`, v.`weight_grams`, v.`selling_price`,
                    p.`uuid` AS `product_uuid`, p.`name` AS `product_name`, p.`status` AS `product_status`, p.`gst_rate`
               FROM `product_variants` v
               INNER JOIN `products` p ON p.`id` = v.`product_id`
              WHERE (p.`name` LIKE :query_name OR v.`variant_name` LIKE :query_variant OR v.`sku` LIKE :query_sku)
                AND v.`is_deleted` = 0 AND v.`is_active` = 1
                AND p.`is_deleted` = 0
              ORDER BY p.`name` ASC, v.`display_order` ASC
              LIMIT ' . max(1, min($limit, 50)),
            ['query_name' => $needle, 'query_variant' => $needle, 'query_sku' => $needle]
        );
    }

    public function barcodeExists(string $barcode, ?int $exceptId = null): bool
    {
        return $this->existsWhere('barcode', $barcode, $exceptId);
    }

    /**
     * Resolves a typed SKU to its variant, with the product name joined in —
     * used by the purchase-inward form so staff can confirm what they're
     * about to receive before adding the line.
     *
     * @return array<string, mixed>|null
     */
    public function findBySku(string $sku): ?array
    {
        return $this->db->selectOne(
            'SELECT v.*, p.`uuid` AS `product_uuid`, p.`name` AS `product_name`, p.`gst_rate`,
                    p.`status` AS `product_status`,
                    p.`category_id`, c.`parent_id` AS `category_parent_id`,
                    c.`uuid` AS `category_uuid`, c.`name` AS `category_name`
               FROM `product_variants` v
               INNER JOIN `products` p ON p.`id` = v.`product_id`
               INNER JOIN `categories` c ON c.`id` = p.`category_id`
              WHERE v.`sku` = :sku AND v.`is_deleted` = 0
              LIMIT 1',
            ['sku' => $sku]
        );
    }

    /**
     * findByUuid() (inherited from BaseRepository) is a plain, unjoined
     * SELECT — fine for a raw column read, not enough for anything that
     * needs to show or snapshot a product name (a POS sale line, a receipt).
     * This is that joined counterpart, keyed by uuid instead of sku.
     *
     * @return array<string, mixed>|null
     */
    public function findDetailByUuid(string $uuid): ?array
    {
        return $this->db->selectOne(
            'SELECT v.*, p.`uuid` AS `product_uuid`, p.`name` AS `product_name`, p.`gst_rate`,
                    p.`category_id`, c.`parent_id` AS `category_parent_id`,
                    c.`uuid` AS `category_uuid`, c.`name` AS `category_name`
               FROM `product_variants` v
               INNER JOIN `products` p ON p.`id` = v.`product_id`
               INNER JOIN `categories` c ON c.`id` = p.`category_id`
              WHERE v.`uuid` = :uuid AND v.`is_deleted` = 0
              LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /**
     * SKU-or-barcode lookup — what a mobile camera scan actually produces is
     * a barcode, not a SKU, and staff typing on a desktop form may have
     * either in hand. Tries SKU first (the more specific, internally-chosen
     * identifier), then falls back to barcode.
     *
     * @return array<string, mixed>|null
     */
    /**
     * The pack size a scanned or typed code belongs to — by SKU or barcode,
     * accepting every form the same printed barcode can arrive in
     * (see Barcode::lookupCandidates): leading zeros, missing check digit,
     * scanner prefixes, stray whitespace.
     */
    public function findByCode(string $code): ?array
    {
        foreach (Barcode::lookupCandidates($code) as $candidate) {
            $found = $this->findBySku($candidate) ?? $this->db->selectOne(
                'SELECT v.*, p.`uuid` AS `product_uuid`, p.`name` AS `product_name`, p.`gst_rate`,
                        p.`status` AS `product_status`,
                        p.`category_id`, c.`parent_id` AS `category_parent_id`,
                        c.`uuid` AS `category_uuid`, c.`name` AS `category_name`
                   FROM `product_variants` v
                   INNER JOIN `products` p ON p.`id` = v.`product_id`
                   INNER JOIN `categories` c ON c.`id` = p.`category_id`
                  WHERE v.`barcode` = :barcode AND v.`is_deleted` = 0
                  LIMIT 1',
                ['barcode' => $candidate]
            );

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function countForProduct(int $productId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `product_variants`
              WHERE `product_id` = :product_id AND `is_deleted` = 0',
            ['product_id' => $productId]
        );
    }

    /**
     * Exactly one variant per product carries is_default. Called inside the
     * same transaction as the insert/update that sets a new default.
     */
    public function clearDefaultFlag(int $productId, ?int $exceptVariantId = null): void
    {
        $sql = 'UPDATE `product_variants`
                   SET `is_default` = 0, `updated_date` = NOW(), `version` = `version` + 1
                 WHERE `product_id` = :product_id AND `is_deleted` = 0';
        $bindings = ['product_id' => $productId];

        if ($exceptVariantId !== null) {
            $sql .= ' AND `id` <> :except_id';
            $bindings['except_id'] = $exceptVariantId;
        }

        $this->db->execute($sql, $bindings);
    }

    /**
     * Promotes the cheapest remaining variant when the default is deleted, so a
     * product is never left without one.
     */
    public function ensureDefaultExists(int $productId): void
    {
        $hasDefault = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `product_variants`
              WHERE `product_id` = :product_id AND `is_default` = 1 AND `is_deleted` = 0',
            ['product_id' => $productId]
        );

        if ($hasDefault > 0) {
            return;
        }

        $this->db->execute(
            'UPDATE `product_variants`
                SET `is_default` = 1, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `product_id` = :product_id AND `is_deleted` = 0
              ORDER BY `selling_price` ASC, `weight_grams` ASC
              LIMIT 1',
            ['product_id' => $productId]
        );
    }
}
