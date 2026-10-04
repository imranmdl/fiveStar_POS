<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Uuid;

final class InventoryStockRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'inventory_stock';
    }

    protected function fillable(): array
    {
        return ['product_variant_id', 'warehouse_id', 'quantity', 'reorder_threshold', 'average_cost'];
    }

    /**
     * Locks the (variant, warehouse) balance for the rest of the current
     * transaction, creating a zero row first if none exists yet. Every write
     * to stock goes through this, the same way OrderRepository::lockForUpdate()
     * is the single chokepoint for order status changes.
     *
     * @return array<string, mixed>
     */
    public function lockOrCreate(int $variantId, int $warehouseId, ?int $actorId): array
    {
        // INSERT .. ON DUPLICATE KEY UPDATE rather than "select, then insert if
        // missing": two transactions racing the same (variant, warehouse) pair
        // would otherwise both see no row and both try to insert, and the loser
        // would fail on the unique key instead of simply joining the winner's
        // row. The no-op UPDATE branch still takes the row lock this method
        // promises.
        $this->db->execute(
            'INSERT INTO `inventory_stock`
                 (`uuid`, `product_variant_id`, `warehouse_id`, `quantity`,
                  `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES (:uuid, :variant_id, :warehouse_id, 0.000, :actor, NOW(), 1, 0, 1)
             ON DUPLICATE KEY UPDATE `id` = `id`',
            [
                'uuid' => Uuid::v4(),
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'actor' => $actorId,
            ]
        );

        return (array) $this->db->selectOne(
            'SELECT * FROM `inventory_stock`
              WHERE `product_variant_id` = :variant_id AND `warehouse_id` = :warehouse_id
              FOR UPDATE',
            ['variant_id' => $variantId, 'warehouse_id' => $warehouseId]
        );
    }

    /**
     * Sets the reorder threshold directly — this is configuration, not a
     * stock-changing event, so it bypasses InventoryService::recordMovement()
     * and writes no ledger entry. Creates the (variant, warehouse) row at
     * zero stock first if it doesn't exist yet, so a threshold can be set
     * before the first inward.
     */
    public function setReorderThreshold(int $variantId, int $warehouseId, ?float $threshold, ?int $actorId): void
    {
        $this->db->transaction(function () use ($variantId, $warehouseId, $threshold, $actorId): void {
            $row = $this->lockOrCreate($variantId, $warehouseId, $actorId);

            $this->update((int) $row['id'], [
                'reorder_threshold' => $threshold === null ? null : number_format($threshold, 3, '.', ''),
            ], $actorId);
        });
    }

    /** @return array<string, mixed>|null */
    public function forVariantAndWarehouse(int $variantId, int $warehouseId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `inventory_stock`
              WHERE `product_variant_id` = :variant_id AND `warehouse_id` = :warehouse_id
                AND `is_deleted` = 0',
            ['variant_id' => $variantId, 'warehouse_id' => $warehouseId]
        );
    }

    /**
     * Per-warehouse rows for a variant, plus the total across all of them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forVariant(int $variantId): array
    {
        return $this->db->select(
            'SELECT s.*, w.`uuid` AS `warehouse_uuid`, w.`code` AS `warehouse_code`, w.`name` AS `warehouse_name`,
                    w.`is_default` AS `warehouse_is_default`
               FROM `inventory_stock` s
               INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`
              WHERE s.`product_variant_id` = :variant_id AND s.`is_deleted` = 0
              ORDER BY w.`is_default` DESC, w.`name` ASC',
            ['variant_id' => $variantId]
        );
    }

    /**
     * Stock list with the filters the Inventory Admin UI needs: SKU, barcode,
     * category, warehouse and stock status. Product/variant metadata is
     * joined in because the console has nowhere else to get it from a single
     * call.
     *
     * Starts from `product_variants`, not `inventory_stock` — a variant with
     * no inward/adjustment yet has no inventory_stock row at all (that table
     * is lazily created by InventoryService::recordMovement()'s
     * lockOrCreate()), so a product added via the Products screen and never
     * yet stocked would otherwise never appear here at all, even though it's
     * exactly the kind of thing this screen exists to surface ("this is on
     * file but has no stock recorded anywhere"). The warehouse joined
     * against is either the one requested, or the store's default warehouse
     * when none was — showing every warehouse for every untracked variant
     * would multiply rows for no benefit.
     *
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function search(array $filters, array $params): array
    {
        // A product's own delete normally cascades to soft-delete every one
        // of its variants too (see ProductService), so checking only the
        // variant here usually happens to work — but that's an assumption
        // this query shouldn't need to rely on. Checking the product
        // directly means a product marked deleted stops showing in
        // Inventory immediately, not only once every variant is also
        // confirmed deleted.
        $where = ['v.`is_deleted` = 0', 'p.`is_deleted` = 0'];
        $bindings = [];

        if (!empty($filters['warehouse_uuid'])) {
            $warehouseJoin = 'INNER JOIN `warehouses` w ON w.`uuid` = :warehouse_uuid AND w.`is_deleted` = 0';
            $bindings['warehouse_uuid'] = $filters['warehouse_uuid'];
        } else {
            $warehouseJoin = 'INNER JOIN `warehouses` w ON w.`is_default` = 1 AND w.`is_deleted` = 0';
        }

        if (!empty($filters['category_slug'])) {
            $where[] = 'c.`slug` = :category_slug';
            $bindings['category_slug'] = $filters['category_slug'];
        }

        if (!empty($filters['sku'])) {
            $where[] = 'v.`sku` LIKE :sku';
            $bindings['sku'] = '%' . $filters['sku'] . '%';
        }

        if (!empty($filters['barcode'])) {
            $where[] = 'v.`barcode` = :barcode';
            $bindings['barcode'] = $filters['barcode'];
        }

        // COALESCE to 0: an untracked variant (no inventory_stock row at all)
        // reads as zero stock for these purposes, same as a tracked one that
        // has genuinely run out.
        if (($filters['stock_status'] ?? null) === 'low') {
            $where[] = 's.`reorder_threshold` IS NOT NULL AND COALESCE(s.`quantity`, 0) <= s.`reorder_threshold`';
        } elseif (($filters['stock_status'] ?? null) === 'negative') {
            $where[] = 'COALESCE(s.`quantity`, 0) < 0';
        } elseif (($filters['stock_status'] ?? null) === 'out') {
            $where[] = 'COALESCE(s.`quantity`, 0) <= 0';
        } elseif (($filters['stock_status'] ?? null) === 'alert') {
            // Low Stock Alerts' own "All" filter: everything that tab is
            // about — at/under its minimum level, or gone entirely — in one
            // query, rather than the general Stock tab's single-category
            // low/negative/out (which this leaves untouched).
            $where[] = '((s.`reorder_threshold` IS NOT NULL AND COALESCE(s.`quantity`, 0) <= s.`reorder_threshold`)
                         OR COALESCE(s.`quantity`, 0) <= 0)';
        }

        $whereSql = implode(' AND ', $where);
        $from = "FROM `product_variants` v
                    INNER JOIN `products` p ON p.`id` = v.`product_id`
                    INNER JOIN `categories` c ON c.`id` = p.`category_id`
                    {$warehouseJoin}
                    LEFT JOIN `inventory_stock` s ON s.`product_variant_id` = v.`id`
                        AND s.`warehouse_id` = w.`id` AND s.`is_deleted` = 0";

        $total = (int) $this->db->scalar("SELECT COUNT(*) {$from} WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT s.*, v.`uuid` AS `variant_uuid`, v.`sku`, v.`barcode`, v.`variant_name`,
                        v.`stock_unit_type`, v.`unit_label`, v.`selling_price`, p.`name` AS `product_name`, c.`slug` AS `category_slug`,
                        w.`uuid` AS `warehouse_uuid`, w.`code` AS `warehouse_code`, w.`name` AS `warehouse_name`
                   %s WHERE %s
                  ORDER BY p.`name` ASC, v.`variant_name` ASC
                  LIMIT %d OFFSET %d',
                $from,
                $whereSql,
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Deleted pack sizes for the Inventory admin's Recycle Bin tab —
     * v.`is_deleted` = 1 is the entry condition here, the opposite of every
     * other query in this class. A row lands here either because its whole
     * parent product was deleted (ProductService::delete() cascades
     * product + every variant + product_media together) or because just
     * this one pack size was removed on its own
     * (ProductService::deleteVariant(), product left active either way).
     * `product_is_deleted` tells the caller which case it is, since restore
     * has to behave differently for each (see InventoryService::restoreDeletedVariant()).
     *
     * @param array<string, mixed> $filters product, sku, deleted_from, deleted_to — all optional
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function searchDeletedVariants(array $filters, array $params): array
    {
        $where = ['v.`is_deleted` = 1'];
        $bindings = [];

        if (!empty($filters['product'])) {
            $where[] = 'p.`name` LIKE :product';
            $bindings['product'] = '%' . $filters['product'] . '%';
        }

        // A product-level delete suffixes the SKU (see ProductService::delete())
        // to free the unique key, always by appending "~d{id}" at the very
        // end — a substring LIKE still matches the original SKU an admin
        // would actually type in, so no un-mangling is needed just to filter.
        if (!empty($filters['sku'])) {
            $where[] = 'v.`sku` LIKE :sku';
            $bindings['sku'] = '%' . $filters['sku'] . '%';
        }

        if (!empty($filters['deleted_from'])) {
            $where[] = 'v.`deleted_date` >= :deleted_from';
            $bindings['deleted_from'] = $filters['deleted_from'] . ' 00:00:00';
        }

        if (!empty($filters['deleted_to'])) {
            $where[] = 'v.`deleted_date` <= :deleted_to';
            $bindings['deleted_to'] = $filters['deleted_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $from = 'FROM `product_variants` v
                    INNER JOIN `products` p ON p.`id` = v.`product_id`
                    LEFT JOIN `users` du ON du.`id` = v.`deleted_by`';

        $total = (int) $this->db->scalar("SELECT COUNT(*) {$from} WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT v.`uuid` AS `variant_uuid`, v.`sku`, v.`variant_name`, v.`deleted_date`,
                        p.`uuid` AS `product_uuid`, p.`name` AS `product_name`, p.`is_deleted` AS `product_is_deleted`,
                        du.`full_name` AS `deleted_by_name`,
                        COALESCE((SELECT SUM(s.`quantity`) FROM `inventory_stock` s WHERE s.`product_variant_id` = v.`id`), 0) AS `quantity`
                   %s WHERE %s
                  ORDER BY v.`deleted_date` DESC
                  LIMIT %d OFFSET %d',
                $from,
                $whereSql,
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<int, array<string, mixed>> */
    public function lowStock(): array
    {
        return $this->db->select(
            'SELECT s.*, v.`uuid` AS `variant_uuid`, v.`sku`, v.`barcode`, v.`variant_name`, p.`name` AS `product_name`,
                    w.`uuid` AS `warehouse_uuid`, w.`code` AS `warehouse_code`, w.`name` AS `warehouse_name`
               FROM `inventory_stock` s
               INNER JOIN `product_variants` v ON v.`id` = s.`product_variant_id`
               INNER JOIN `products` p ON p.`id` = v.`product_id`
               INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`
              WHERE s.`is_deleted` = 0
                AND s.`reorder_threshold` IS NOT NULL
                AND s.`quantity` <= s.`reorder_threshold`
              ORDER BY (s.`quantity` - s.`reorder_threshold`) ASC'
        );
    }
}
