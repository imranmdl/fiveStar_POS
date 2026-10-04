<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Repositories\CategoryRepository;

/**
 * Owner's audit view: for a date range, what came in and at what landed
 * cost, what it is priced to sell at, and where every unit went — so a wrong
 * data-entry (a selling price below cost, a missing cost, a stock figure that
 * no longer matches the ledger) is visible at a glance.
 *
 * Everything is derived from the immutable inventory_movements ledger:
 *   opening  = sum of movements before the range starts
 *   closing  = opening + sum of movements inside the range
 * so opening + inward - outflows = closing holds by construction, and the
 * closing figure can be compared with inventory_stock to catch drift.
 *
 * Read-only. Dates are validated to Y-m-d and inlined as literals, never
 * concatenated from raw input.
 */
final class StockAuditService
{
    /** Margin below this (percent of selling price) is flagged as thin. */
    private const LOW_MARGIN_PERCENT = 5.0;

    public function __construct(
        private readonly Database $db,
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * @param array<string, mixed> $filters vendor_uuid?, category_uuid?, search?
     *
     * @return array<string, mixed>
     */
    public function report(string $from, string $to, string $group, array $filters): array
    {
        $this->assertRange($from, $to);

        if (!in_array($group, ['item', 'category', 'vendor'], true)) {
            throw new HttpException('group must be item, category or vendor.', 422, ['group' => ['Invalid grouping.']]);
        }

        if ($group === 'vendor') {
            return ['from' => $from, 'to' => $to, 'group' => 'vendor', 'rows' => $this->vendorRows($from, $to)];
        }

        $items = $this->itemRows($from, $to, $filters);

        return [
            'from' => $from,
            'to' => $to,
            'group' => $group,
            'rows' => $group === 'item' ? $items : $this->groupByCategory($items),
            'totals' => $this->totals($items),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function movements(string $variantUuid, string $from, string $to): array
    {
        $this->assertRange($from, $to);

        $variant = $this->db->selectOne(
            'SELECT v.`id`, v.`sku`, v.`variant_name`, p.`name` AS `product_name`
               FROM `product_variants` v INNER JOIN `products` p ON p.`id` = v.`product_id`
              WHERE v.`uuid` = :uuid LIMIT 1',
            ['uuid' => $variantUuid]
        );

        if ($variant === null) {
            throw new HttpException('That item does not exist.', 404);
        }

        $rows = $this->db->select(
            "SELECT m.`created_date`, m.`movement_type`, m.`reference_type`, m.`quantity_delta`, m.`quantity_after`,
                    m.`unit_cost`, m.`batch_no`, m.`reason`,
                    u.`full_name` AS `entered_by`,
                    po.`po_number`, vd.`name` AS `vendor_name`,
                    ps.`sale_number`, cu.`full_name` AS `cashier_name`,
                    o.`order_number`, o.`placed_channel`,
                    pr.`return_number`
               FROM `inventory_movements` m
               LEFT JOIN `users` u ON u.`id` = COALESCE(m.`performed_by`, m.`created_by`)
               LEFT JOIN `purchase_orders` po ON m.`reference_type` = 'purchase_order' AND po.`id` = m.`reference_id`
               LEFT JOIN `vendors` vd ON vd.`id` = po.`vendor_id`
               LEFT JOIN `pos_sales` ps ON m.`reference_type` = 'pos_sale' AND ps.`id` = m.`reference_id`
               LEFT JOIN `users` cu ON cu.`id` = ps.`cashier_id`
               LEFT JOIN `orders` o ON m.`reference_type` = 'order' AND o.`id` = m.`reference_id`
               LEFT JOIN `purchase_returns` pr ON m.`reference_type` = 'purchase_return' AND pr.`id` = m.`reference_id`
              WHERE m.`product_variant_id` = :vid AND m.`is_deleted` = 0
                AND m.`created_date` >= '{$from} 00:00:00' AND m.`created_date` < DATE_ADD('{$to}', INTERVAL 1 DAY)
              ORDER BY m.`id` ASC",
            ['vid' => (int) $variant['id']]
        );

        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'date' => $r['created_date'],
                'type' => $r['movement_type'],
                'source' => $this->sourceLabel($r),
                'channel' => $this->channelOf($r),
                'quantity_delta' => (float) $r['quantity_delta'],
                'quantity_after' => (float) $r['quantity_after'],
                'unit_cost' => $r['unit_cost'] !== null ? (float) $r['unit_cost'] : null,
                'batch_no' => $r['batch_no'],
                'reason' => $r['reason'],
                'entered_by' => $r['entered_by'],
            ];
        }

        return [
            'item' => ['sku' => $variant['sku'], 'name' => $variant['product_name'] . ' — ' . $variant['variant_name']],
            'movements' => $out,
        ];
    }

    // -----------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function itemRows(string $from, string $to, array $filters): array
    {
        $start = "'{$from} 00:00:00'";
        $end = "DATE_ADD('{$to}', INTERVAL 1 DAY)";
        $inRange = "m.`created_date` >= {$start} AND m.`created_date` < {$end}";

        $sum = static fn (string $cond): string => "COALESCE(SUM(CASE WHEN {$cond} THEN m.`quantity_delta` END), 0)";

        $where = ['v.`is_deleted` = 0', 'p.`is_deleted` = 0'];
        $bindings = [];

        if (!empty($filters['category_uuid'])) {
            $category = $this->categories->findByUuid((string) $filters['category_uuid']);

            if ($category === null) {
                throw new HttpException('That category does not exist.', 422, ['category_uuid' => ['Unknown category.']]);
            }

            $ids = array_map('intval', $this->categories->rootAndDescendantIds((int) $category['id']));
            $where[] = 'c.`id` IN (' . implode(',', $ids ?: [0]) . ')';
        }

        if (!empty($filters['vendor_uuid'])) {
            $where[] = 'v.`id` IN (SELECT poi.`product_variant_id` FROM `purchase_order_items` poi
                                   INNER JOIN `purchase_orders` po ON po.`id` = poi.`purchase_order_id`
                                   INNER JOIN `vendors` vd ON vd.`id` = po.`vendor_id`
                                   WHERE vd.`uuid` = :vendor_uuid AND po.`is_deleted` = 0)';
            $bindings['vendor_uuid'] = (string) $filters['vendor_uuid'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(v.`sku` LIKE :s1 OR p.`name` LIKE :s2 OR v.`barcode` LIKE :s3)';
            $like = '%' . $filters['search'] . '%';
            $bindings['s1'] = $bindings['s2'] = $bindings['s3'] = $like;
        }

        $sql = "SELECT v.`id`, v.`uuid`, v.`sku`, v.`variant_name`, v.`unit_label`, v.`mrp`, v.`selling_price`,
                       p.`name` AS `product_name`, c.`uuid` AS `category_uuid`, c.`name` AS `category_name`,
                       pc.`name` AS `parent_name`,
                       {$sum("m.`created_date` < {$start}")} AS `opening`,
                       {$sum("{$inRange} AND m.`movement_type` = 'inward'")} AS `inward`,
                       {$sum("{$inRange} AND m.`movement_type` = 'sale' AND m.`reference_type` = 'pos_sale'")} AS `pos_sales`,
                       {$sum("{$inRange} AND m.`movement_type` = 'sale' AND m.`reference_type` = 'order'")} AS `online_sales`,
                       {$sum("{$inRange} AND m.`movement_type` = 'return' AND m.`reference_type` IN ('pos_sale','order')")} AS `customer_returns`,
                       {$sum("{$inRange} AND m.`movement_type` = 'return_to_vendor'")} AS `vendor_returns`,
                       {$sum("{$inRange} AND m.`movement_type` = 'damage'")} AS `damaged`,
                       {$sum("{$inRange} AND m.`movement_type` = 'lost'")} AS `lost`,
                       {$sum("{$inRange} AND m.`movement_type` = 'adjustment'")} AS `adjustments`,
                       {$sum($inRange)} AS `period_total`,
                       (SELECT SUM(s.`quantity`) FROM `inventory_stock` s WHERE s.`product_variant_id` = v.`id` AND s.`is_deleted` = 0) AS `system_stock`,
                       (SELECT SUM(s.`quantity` * s.`average_cost`) / NULLIF(SUM(s.`quantity`), 0)
                          FROM `inventory_stock` s WHERE s.`product_variant_id` = v.`id` AND s.`is_deleted` = 0 AND s.`average_cost` IS NOT NULL) AS `avg_cost`,
                       (SELECT m2.`unit_cost` FROM `inventory_movements` m2
                         WHERE m2.`product_variant_id` = v.`id` AND m2.`movement_type` = 'inward' AND m2.`unit_cost` IS NOT NULL AND m2.`is_deleted` = 0
                         ORDER BY m2.`id` DESC LIMIT 1) AS `last_cost`
                  FROM `product_variants` v
                  INNER JOIN `products` p ON p.`id` = v.`product_id`
                  INNER JOIN `categories` c ON c.`id` = p.`category_id`
                  LEFT JOIN `categories` pc ON pc.`id` = c.`parent_id`
                  LEFT JOIN `inventory_movements` m ON m.`product_variant_id` = v.`id` AND m.`is_deleted` = 0 AND m.`created_date` < {$end}
                 WHERE " . implode(' AND ', $where) . '
                 GROUP BY v.`id`
                 HAVING (ABS(`opening`) + ABS(`period_total`) + ABS(COALESCE(`system_stock`, 0))) > 0
                 ORDER BY p.`name` ASC, v.`display_order` ASC';

        $rows = $this->db->select($sql, $bindings);

        $revenue = $this->revenueByVariant($from, $to);
        $todayInRange = $to >= date('Y-m-d');
        $out = [];

        foreach ($rows as $r) {
            $opening = (float) $r['opening'];
            $closing = $opening + (float) $r['period_total'];
            $known = (float) $r['inward'] + (float) $r['pos_sales'] + (float) $r['online_sales'] + (float) $r['customer_returns']
                + (float) $r['vendor_returns'] + (float) $r['damaged'] + (float) $r['lost'] + (float) $r['adjustments'];
            $cost = $r['avg_cost'] !== null && (float) $r['avg_cost'] > 0 ? (float) $r['avg_cost']
                : ($r['last_cost'] !== null ? (float) $r['last_cost'] : null);
            $selling = (float) $r['selling_price'];
            $mrp = (float) $r['mrp'];
            $margin = ($cost !== null && $selling > 0) ? (($selling - $cost) / $selling) * 100 : null;
            $rev = $revenue[(int) $r['id']] ?? ['pos_value' => 0.0, 'online_value' => 0.0];

            $flags = [];

            if ($cost === null || $cost <= 0) {
                if ($closing > 0 || (float) $r['inward'] > 0) {
                    $flags[] = 'no_cost';
                }
            } elseif ($selling < $cost) {
                $flags[] = 'below_cost';
            } elseif ($margin !== null && $margin < self::LOW_MARGIN_PERCENT) {
                $flags[] = 'low_margin';
            }

            if ($selling > $mrp) {
                $flags[] = 'over_mrp';
            }

            if ($closing < -0.0005) {
                $flags[] = 'negative_stock';
            }

            if ($todayInRange && $r['system_stock'] !== null && abs($closing - (float) $r['system_stock']) > 0.0005) {
                $flags[] = 'ledger_mismatch';
            }

            $out[] = [
                'uuid' => $r['uuid'],
                'sku' => $r['sku'],
                'name' => $r['product_name'] . ' — ' . $r['variant_name'],
                'unit' => $r['unit_label'],
                'category_uuid' => $r['category_uuid'],
                'category' => $r['parent_name'] !== null ? $r['parent_name'] . ' › ' . $r['category_name'] : $r['category_name'],
                'opening' => $opening,
                'inward' => (float) $r['inward'],
                'pos_sales' => abs((float) $r['pos_sales']),
                'online_sales' => abs((float) $r['online_sales']),
                'customer_returns' => (float) $r['customer_returns'],
                'vendor_returns' => abs((float) $r['vendor_returns']),
                'damaged' => abs((float) $r['damaged']),
                'lost' => abs((float) $r['lost']),
                'adjustments' => (float) $r['adjustments'],
                'other' => round((float) $r['period_total'] - $known, 3),
                'closing' => $closing,
                'system_stock' => $r['system_stock'] !== null ? (float) $r['system_stock'] : null,
                'landing_cost' => $cost,
                'last_cost' => $r['last_cost'] !== null ? (float) $r['last_cost'] : null,
                'selling_price' => $selling,
                'mrp' => $mrp,
                'margin_percent' => $margin !== null ? round($margin, 1) : null,
                'closing_cost_value' => $cost !== null ? round($closing * $cost, 2) : null,
                'closing_selling_value' => round($closing * $selling, 2),
                'pos_sales_value' => $rev['pos_value'],
                'online_sales_value' => $rev['online_value'],
                'flags' => $flags,
            ];
        }

        return $out;
    }

    /** @return array<int, array{pos_value: float, online_value: float}> */
    private function revenueByVariant(string $from, string $to): array
    {
        $end = "DATE_ADD('{$to}', INTERVAL 1 DAY)";
        $out = [];

        $pos = $this->db->select(
            "SELECT i.`product_variant_id` AS `vid`,
                    SUM(i.`line_total` * (1 - i.`refunded_quantity` / NULLIF(i.`quantity`, 0))) AS `value`
               FROM `pos_sale_items` i INNER JOIN `pos_sales` s ON s.`id` = i.`pos_sale_id`
              WHERE s.`status` = 'completed' AND s.`created_date` >= '{$from} 00:00:00' AND s.`created_date` < {$end}
              GROUP BY i.`product_variant_id`"
        );

        foreach ($pos as $r) {
            $out[(int) $r['vid']]['pos_value'] = round((float) $r['value'], 2);
        }

        $online = $this->db->select(
            "SELECT oi.`variant_id` AS `vid`, SUM(oi.`line_payable`) AS `value`
               FROM `order_items` oi INNER JOIN `orders` o ON o.`id` = oi.`order_id`
              WHERE o.`status` NOT IN ('created','awaiting_payment','cancelled','returned','refunded')
                AND o.`placed_date` >= '{$from} 00:00:00' AND o.`placed_date` < {$end}
              GROUP BY oi.`variant_id`"
        );

        foreach ($online as $r) {
            $out[(int) $r['vid']]['online_value'] = round((float) $r['value'], 2);
        }

        foreach ($out as $id => $row) {
            $out[$id] = ['pos_value' => $row['pos_value'] ?? 0.0, 'online_value' => $row['online_value'] ?? 0.0];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupByCategory(array $items): array
    {
        $groups = [];
        $sumKeys = ['opening', 'inward', 'pos_sales', 'online_sales', 'customer_returns', 'vendor_returns',
            'damaged', 'lost', 'adjustments', 'other', 'closing', 'closing_cost_value', 'closing_selling_value',
            'pos_sales_value', 'online_sales_value'];

        foreach ($items as $item) {
            $key = $item['category_uuid'];
            $groups[$key] ??= ['uuid' => $key, 'name' => $item['category'], 'items' => 0, 'flagged' => 0]
                + array_fill_keys($sumKeys, 0.0);
            $groups[$key]['items']++;
            $groups[$key]['flagged'] += $item['flags'] !== [] ? 1 : 0;

            foreach ($sumKeys as $k) {
                $groups[$key][$k] += (float) ($item[$k] ?? 0);
            }
        }

        foreach ($groups as &$g) {
            $g['margin_percent'] = $g['closing_selling_value'] > 0 && $g['closing_cost_value'] > 0
                ? round((($g['closing_selling_value'] - $g['closing_cost_value']) / $g['closing_selling_value']) * 100, 1)
                : null;
        }
        unset($g);

        usort($groups, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return array_values($groups);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function totals(array $items): array
    {
        $t = ['items' => count($items), 'flagged' => 0, 'closing_cost_value' => 0.0, 'closing_selling_value' => 0.0,
            'pos_sales_value' => 0.0, 'online_sales_value' => 0.0];

        foreach ($items as $i) {
            $t['flagged'] += $i['flags'] !== [] ? 1 : 0;
            $t['closing_cost_value'] += (float) $i['closing_cost_value'];
            $t['closing_selling_value'] += (float) $i['closing_selling_value'];
            $t['pos_sales_value'] += (float) $i['pos_sales_value'];
            $t['online_sales_value'] += (float) $i['online_sales_value'];
        }

        return $t;
    }

    /** @return array<int, array<string, mixed>> */
    private function vendorRows(string $from, string $to): array
    {
        $purchases = $this->db->select(
            "SELECT vd.`uuid`, vd.`name`,
                    COUNT(DISTINCT po.`id`) AS `orders`,
                    COUNT(DISTINCT poi.`product_variant_id`) AS `items`,
                    SUM(poi.`quantity`) AS `qty`,
                    SUM(poi.`quantity` * poi.`unit_cost`) AS `base_value`,
                    SUM(poi.`quantity` * poi.`landing_cost`) AS `landed_value`,
                    SUM(poi.`quantity` * COALESCE(poi.`selling_price`, v.`selling_price`)) AS `selling_value`,
                    SUM(poi.`quantity` * COALESCE(poi.`mrp`, v.`mrp`)) AS `mrp_value`,
                    SUM(CASE WHEN poi.`selling_price` IS NOT NULL AND poi.`landing_cost` > 0 AND poi.`selling_price` < poi.`landing_cost` THEN 1 ELSE 0 END) AS `below_cost_lines`,
                    SUM(CASE WHEN poi.`landing_cost` <= 0 THEN 1 ELSE 0 END) AS `no_cost_lines`
               FROM `purchase_orders` po
               INNER JOIN `vendors` vd ON vd.`id` = po.`vendor_id`
               INNER JOIN `purchase_order_items` poi ON poi.`purchase_order_id` = po.`id` AND poi.`is_deleted` = 0
               INNER JOIN `product_variants` v ON v.`id` = poi.`product_variant_id`
              WHERE po.`is_deleted` = 0 AND po.`purchase_date` BETWEEN '{$from}' AND '{$to}'
              GROUP BY vd.`id`
              ORDER BY landed_value DESC"
        );

        $returns = [];

        foreach ($this->db->select(
            "SELECT pr.`vendor_id`, SUM(pri.`quantity`) AS `qty`, SUM(pri.`line_amount`) AS `amount`
               FROM `purchase_returns` pr INNER JOIN `purchase_return_items` pri ON pri.`purchase_return_id` = pr.`id`
              WHERE pr.`is_deleted` = 0 AND pr.`return_date` BETWEEN '{$from}' AND '{$to}'
              GROUP BY pr.`vendor_id`"
        ) as $r) {
            $returns[(int) $r['vendor_id']] = $r;
        }

        $vendorIds = [];

        foreach ($this->db->select('SELECT `id`, `uuid` FROM `vendors`') as $v) {
            $vendorIds[$v['uuid']] = (int) $v['id'];
        }

        $out = [];

        foreach ($purchases as $r) {
            $landed = (float) $r['landed_value'];
            $selling = (float) $r['selling_value'];
            $ret = $returns[$vendorIds[$r['uuid']] ?? 0] ?? null;

            $out[] = [
                'uuid' => $r['uuid'],
                'name' => $r['name'],
                'orders' => (int) $r['orders'],
                'items' => (int) $r['items'],
                'quantity' => (float) $r['qty'],
                'base_value' => round((float) $r['base_value'], 2),
                'landed_value' => round($landed, 2),
                'selling_value' => round($selling, 2),
                'mrp_value' => round((float) $r['mrp_value'], 2),
                'expected_margin_percent' => $selling > 0 && $landed > 0 ? round((($selling - $landed) / $selling) * 100, 1) : null,
                'returned_quantity' => $ret !== null ? (float) $ret['qty'] : 0.0,
                'returned_value' => $ret !== null ? round((float) $ret['amount'], 2) : 0.0,
                'below_cost_lines' => (int) $r['below_cost_lines'],
                'no_cost_lines' => (int) $r['no_cost_lines'],
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $r */
    private function sourceLabel(array $r): string
    {
        return match ($r['reference_type']) {
            'purchase_order' => $r['movement_type'] === 'inward'
                ? 'Purchase ' . ($r['po_number'] ?? '') . ($r['vendor_name'] ? ' · ' . $r['vendor_name'] : '')
                : 'Purchase correction ' . ($r['po_number'] ?? ''),
            'pos_sale' => ($r['movement_type'] === 'sale' ? 'POS sale ' : 'POS refund ') . ($r['sale_number'] ?? '')
                . ($r['cashier_name'] ? ' · ' . $r['cashier_name'] : ''),
            'order' => ($r['movement_type'] === 'sale' ? 'Online order ' : 'Online return ') . ($r['order_number'] ?? ''),
            'purchase_return' => 'Return to vendor ' . ($r['return_number'] ?? ''),
            'csv_import' => 'File import',
            'mobile_app' => 'Mobile scan',
            'opening_balance' => 'Opening balance',
            default => 'Manual entry',
        };
    }

    /** @param array<string, mixed> $r */
    private function channelOf(array $r): ?string
    {
        return match ($r['reference_type']) {
            'pos_sale' => 'Counter (POS)',
            'order' => 'Online' . (!empty($r['placed_channel']) ? ' · ' . $r['placed_channel'] : ''),
            default => null,
        };
    }

    private function assertRange(string $from, string $to): void
    {
        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || strtotime($value) === false) {
                throw new HttpException("{$name} must be a date (YYYY-MM-DD).", 422, [$name => ['Invalid date.']]);
            }
        }

        if ($from > $to) {
            throw new HttpException('The start date is after the end date.', 422, ['from' => ['Must not be after the end date.']]);
        }

        if ((strtotime($to) - strtotime($from)) / 86400 > 800) {
            throw new HttpException('Choose a range of at most 800 days.', 422, ['from' => ['Range too long.']]);
        }
    }
}
