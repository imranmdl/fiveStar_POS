<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Uuid;

/**
 * Batch/lot balances, populated opportunistically whenever a movement
 * carries a batch_no. Not a second stock cache to keep in lockstep with
 * inventory_stock — just an itemised breakdown for batch/expiry reporting.
 */
final class InventoryBatchRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'inventory_batches';
    }

    protected function fillable(): array
    {
        return ['product_variant_id', 'warehouse_id', 'batch_no', 'expiry_date', 'quantity', 'unit_cost'];
    }

    /**
     * Adds (or subtracts, for a negative $quantityDelta) from a batch's
     * running balance, creating the batch row on first use. Mirrors
     * InventoryStockRepository::lockOrCreate()'s upsert-then-lock shape so
     * two concurrent movements against the same batch cannot race each other.
     */
    public function applyDelta(
        int $variantId,
        int $warehouseId,
        string $batchNo,
        float $quantityDelta,
        ?string $expiryDate,
        ?float $unitCost,
        ?int $actorId,
        ?float $mrp = null,
        ?float $sellingPrice = null,
    ): void {
        $this->db->execute(
            'INSERT INTO `inventory_batches`
                 (`uuid`, `product_variant_id`, `warehouse_id`, `batch_no`, `expiry_date`, `quantity`, `unit_cost`,
                  `mrp`, `selling_price`, `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES (:uuid, :variant_id, :warehouse_id, :batch_no, :expiry_date, 0.000, :unit_cost,
                     :mrp, :selling_price, :actor, NOW(), 1, 0, 1)
             ON DUPLICATE KEY UPDATE `id` = `id`',
            [
                'uuid' => Uuid::v4(),
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'batch_no' => $batchNo,
                'expiry_date' => $expiryDate,
                'unit_cost' => $unitCost,
                'mrp' => $mrp,
                'selling_price' => $sellingPrice,
                'actor' => $actorId,
            ]
        );

        $this->db->execute(
            'UPDATE `inventory_batches`
                SET `quantity` = `quantity` + :delta,
                    `unit_cost` = COALESCE(:unit_cost2, `unit_cost`),
                    `expiry_date` = COALESCE(:expiry_date2, `expiry_date`),
                    `mrp` = COALESCE(:mrp2, `mrp`),
                    `selling_price` = COALESCE(:selling_price2, `selling_price`),
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `product_variant_id` = :variant_id AND `warehouse_id` = :warehouse_id AND `batch_no` = :batch_no',
            [
                'delta' => $quantityDelta,
                'unit_cost2' => $unitCost,
                'expiry_date2' => $expiryDate,
                'mrp2' => $mrp,
                'selling_price2' => $sellingPrice,
                'actor' => $actorId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'batch_no' => $batchNo,
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forVariant(int $variantId, ?int $warehouseId = null): array
    {
        $sql = 'SELECT * FROM `inventory_batches`
                  WHERE `product_variant_id` = :variant_id AND `is_deleted` = 0';
        $bindings = ['variant_id' => $variantId];

        if ($warehouseId !== null) {
            $sql .= ' AND `warehouse_id` = :warehouse_id';
            $bindings['warehouse_id'] = $warehouseId;
        }

        return $this->db->select($sql . ' ORDER BY `expiry_date` ASC, `batch_no` ASC', $bindings);
    }

    /** Batches expiring within the given number of days, for a reorder/expiry dashboard. */
    public function expiringWithinDays(int $days): array
    {
        return $this->db->select(
            'SELECT b.*, v.`sku`, v.`variant_name`, w.`code` AS `warehouse_code`
               FROM `inventory_batches` b
               INNER JOIN `product_variants` v ON v.`id` = b.`product_variant_id`
               INNER JOIN `warehouses` w ON w.`id` = b.`warehouse_id`
              WHERE b.`is_deleted` = 0
                AND b.`expiry_date` IS NOT NULL
                AND b.`expiry_date` <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
                AND b.`quantity` > 0
              ORDER BY b.`expiry_date` ASC',
            ['days' => $days]
        );
    }

    /**
     * Batches with a declared expiry, for the Inventory admin's Expiry tab —
     * 'expiring_soon' is a fixed 15-day window (the feature's own business
     * rule), 'expired' is anything already past its date, null/'all' is
     * every batch with an expiry set. Same filter-array convention as
     * InventoryMovementRepository::search().
     *
     * @param array<string, mixed> $filters sku, warehouse_uuid — both optional
     *
     * @return array<int, array<string, mixed>>
     */
    public function withExpiry(?string $status, array $filters = []): array
    {
        // Same gap InventoryStockRepository::search() had: a batch itself
        // being active (b.is_deleted = 0) says nothing about whether the
        // product or variant it belongs to has since been deleted — check
        // both directly rather than assuming a product's delete already
        // cascaded far enough to hide this too.
        $where = ['b.`is_deleted` = 0', 'v.`is_deleted` = 0', 'p.`is_deleted` = 0', 'b.`expiry_date` IS NOT NULL', 'b.`quantity` > 0'];
        $bindings = [];

        if ($status === 'expiring_soon') {
            $where[] = 'b.`expiry_date` >= CURDATE()';
            $where[] = 'b.`expiry_date` <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)';
        } elseif ($status === 'expired') {
            $where[] = 'b.`expiry_date` < CURDATE()';
        }

        if (!empty($filters['sku'])) {
            $where[] = 'v.`sku` LIKE :sku';
            $bindings['sku'] = '%' . $filters['sku'] . '%';
        }

        if (!empty($filters['warehouse_uuid'])) {
            $where[] = 'w.`uuid` = :warehouse_uuid';
            $bindings['warehouse_uuid'] = $filters['warehouse_uuid'];
        }

        return $this->db->select(
            sprintf(
                'SELECT b.*, v.`uuid` AS `variant_uuid`, v.`sku`, v.`variant_name`, p.`name` AS `product_name`,
                        w.`uuid` AS `warehouse_uuid`, w.`code` AS `warehouse_code`, w.`name` AS `warehouse_name`,
                        DATEDIFF(b.`expiry_date`, CURDATE()) AS `days_remaining`
                   FROM `inventory_batches` b
                   INNER JOIN `product_variants` v ON v.`id` = b.`product_variant_id`
                   INNER JOIN `products` p ON p.`id` = v.`product_id`
                   INNER JOIN `warehouses` w ON w.`id` = b.`warehouse_id`
                  WHERE %s
                  ORDER BY b.`expiry_date` ASC',
                implode(' AND ', $where)
            ),
            $bindings
        );
    }

    /** Value (quantity x unit_cost, where costed) of batches expiring exactly today — today's newly-expired stock, for the dashboard's Loss figure. */
    public function valueExpiringOn(string $date): float
    {
        return (float) ($this->db->scalar(
            'SELECT COALESCE(SUM(b.`quantity` * b.`unit_cost`), 0)
               FROM `inventory_batches` b
              WHERE b.`is_deleted` = 0
                AND b.`expiry_date` = :date
                AND b.`quantity` > 0
                AND b.`unit_cost` IS NOT NULL',
            ['date' => $date]
        ) ?? 0);
    }
}
