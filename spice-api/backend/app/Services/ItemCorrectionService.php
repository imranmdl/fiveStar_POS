<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;

/**
 * Administrator's "Item review": correct an item's data-entry mistakes in
 * one place and have the correction reach everything that depends on it.
 *
 * What a correction changes, and how far it travels:
 *
 *  - Name, category / sub-category, pack name, unit, MRP, selling price —
 *    saved on the item itself (through ProductService, so the usual checks,
 *    price history and audit apply). Purchases, vendor history, stock,
 *    stock audit, the shop and new bills read these live, so they follow at
 *    once. The MRP / selling-price copies kept on the purchase lines and
 *    stock batches that still show the wrong value are corrected too.
 *
 *  - Purchase cost of a purchase line — the line's cost and landing cost,
 *    the whole purchase's totals (the transport share is spread by value,
 *    so every line's landing cost is re-worked), the vendor balance (it is
 *    computed from those totals), any return of that line to the vendor,
 *    the stock batch cost, and the item's average stock cost — rebuilt
 *    from its stock history as if the right cost had been entered.
 *
 * Never changed: customer invoices, POS bills and receipts already issued
 * (they are legal documents of what was charged), the stock movement ledger
 * and the price-change log (history stays as it happened; this correction
 * is recorded in the audit log with the before and after values).
 *
 * A purchase whose amount already paid would exceed its corrected total is
 * refused — the vendor payment has to be corrected first.
 */
final class ItemCorrectionService
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Database $db,
        private readonly ProductService $products,
        private readonly AuditService $audit,
        private readonly InventoryService $inventory,
    ) {
    }

    // -----------------------------------------------------------------
    // Queue
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters status=pending|reviewed|all, q, page
     *
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $status = in_array($filters['status'] ?? 'pending', ['pending', 'reviewed', 'all'], true)
            ? (string) ($filters['status'] ?? 'pending')
            : 'pending';
        $page = max(1, (int) ($filters['page'] ?? 1));
        $where = ['p.`is_deleted` = 0'];
        $bind = [];

        if ($status === 'pending') {
            $where[] = 'p.`admin_reviewed` = 0';
        } elseif ($status === 'reviewed') {
            $where[] = 'p.`admin_reviewed` = 1';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.`name` LIKE :q1 OR p.`product_code` LIKE :q2 OR EXISTS (
                            SELECT 1 FROM `product_variants` sv
                             WHERE sv.`product_id` = p.`id` AND sv.`is_deleted` = 0
                               AND (sv.`sku` LIKE :q3 OR sv.`barcode` LIKE :q4)))';
            $like = '%' . $q . '%';
            $bind += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `products` p WHERE {$whereSql}", $bind);
        $pending = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `products` WHERE `is_deleted` = 0 AND `admin_reviewed` = 0'
        );

        $rows = $this->db->select(
            "SELECT p.`uuid`, p.`name`, p.`status`, p.`admin_reviewed`, p.`review_reason`,
                    p.`review_flagged_date`, p.`reviewed_date`, p.`created_date`,
                    c.`name` AS `category_name`, pc.`name` AS `parent_category_name`,
                    cu.`full_name` AS `created_by_name`, ru.`full_name` AS `reviewed_by_name`,
                    (SELECT COUNT(*) FROM `product_variants` v
                      WHERE v.`product_id` = p.`id` AND v.`is_deleted` = 0) AS `pack_count`,
                    (SELECT MIN(v.`selling_price`) FROM `product_variants` v
                      WHERE v.`product_id` = p.`id` AND v.`is_deleted` = 0) AS `price_from`
               FROM `products` p
               LEFT JOIN `categories` c ON c.`id` = p.`category_id`
               LEFT JOIN `categories` pc ON pc.`id` = c.`parent_id`
               LEFT JOIN `users` cu ON cu.`id` = p.`created_by`
               LEFT JOIN `users` ru ON ru.`id` = p.`reviewed_by`
              WHERE {$whereSql}
              ORDER BY p.`admin_reviewed` ASC, COALESCE(p.`review_flagged_date`, p.`created_date`) DESC, p.`id` DESC
              LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $bind
        );

        return [
            'items' => array_map(static function (array $r): array {
                $r['admin_reviewed'] = (int) $r['admin_reviewed'] === 1;
                $r['pack_count'] = (int) $r['pack_count'];
                $r['price_from'] = $r['price_from'] === null ? null : (float) $r['price_from'];
                $r['category_path'] = $r['parent_category_name'] !== null
                    ? $r['parent_category_name'] . ' › ' . $r['category_name']
                    : $r['category_name'];
                unset($r['parent_category_name']);

                return $r;
            }, $rows),
            'pending_count' => $pending,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            ],
        ];
    }

    // -----------------------------------------------------------------
    // One item, everything a correction would touch
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    public function detail(string $productUuid): array
    {
        $product = $this->db->selectOne(
            'SELECT p.`id`, p.`uuid`, p.`name`, p.`slug`, p.`product_code`, p.`status`,
                    p.`admin_reviewed`, p.`review_reason`, p.`review_flagged_date`, p.`reviewed_date`,
                    p.`created_date`, c.`uuid` AS `category_uuid`, c.`name` AS `category_name`,
                    pc.`name` AS `parent_category_name`,
                    cu.`full_name` AS `created_by_name`, ru.`full_name` AS `reviewed_by_name`
               FROM `products` p
               LEFT JOIN `categories` c ON c.`id` = p.`category_id`
               LEFT JOIN `categories` pc ON pc.`id` = c.`parent_id`
               LEFT JOIN `users` cu ON cu.`id` = p.`created_by`
               LEFT JOIN `users` ru ON ru.`id` = p.`reviewed_by`
              WHERE p.`uuid` = :uuid AND p.`is_deleted` = 0',
            ['uuid' => $productUuid]
        );

        if ($product === null) {
            throw new NotFoundException('That item does not exist.');
        }

        $productId = (int) $product['id'];

        $variants = array_map(static fn (array $v): array => [
            'uuid' => $v['uuid'],
            'sku' => $v['sku'],
            'barcode' => $v['barcode'],
            'variant_name' => $v['variant_name'],
            'stock_unit_type' => $v['stock_unit_type'],
            'unit_label' => $v['unit_label'],
            'weight_grams' => (int) $v['weight_grams'],
            'mrp' => (float) $v['mrp'],
            'selling_price' => (float) $v['selling_price'],
            'stock_quantity' => (float) $v['stock_quantity'],
            'average_cost' => $v['average_cost'] === null ? null : (float) $v['average_cost'],
        ], $this->db->select(
            'SELECT v.*,
                    (SELECT COALESCE(SUM(s.`quantity`), 0) FROM `inventory_stock` s
                      WHERE s.`product_variant_id` = v.`id` AND s.`is_deleted` = 0) AS `stock_quantity`,
                    (SELECT CASE WHEN SUM(GREATEST(s.`quantity`, 0)) > 0
                                 THEN SUM(GREATEST(s.`quantity`, 0) * s.`average_cost`) / SUM(GREATEST(s.`quantity`, 0))
                                 ELSE MAX(s.`average_cost`) END
                       FROM `inventory_stock` s
                      WHERE s.`product_variant_id` = v.`id` AND s.`is_deleted` = 0) AS `average_cost`
               FROM `product_variants` v
              WHERE v.`product_id` = :id AND v.`is_deleted` = 0
              ORDER BY v.`is_default` DESC, v.`weight_grams` ASC, v.`id` ASC',
            ['id' => $productId]
        ));

        // Stock per warehouse, including warehouses that hold none yet, so a
        // count can be entered anywhere.
        $warehouses = $this->db->select(
            'SELECT `id`, `uuid`, `name`, `is_default` FROM `warehouses`
              WHERE `is_deleted` = 0 AND `is_active` = 1 ORDER BY `is_default` DESC, `name` ASC'
        );
        $stockRows = $this->db->select(
            'SELECT v.`uuid` AS `variant_uuid`, s.`warehouse_id`, s.`quantity`, s.`average_cost`
               FROM `inventory_stock` s INNER JOIN `product_variants` v ON v.`id` = s.`product_variant_id`
              WHERE v.`product_id` = :id AND v.`is_deleted` = 0 AND s.`is_deleted` = 0',
            ['id' => $productId]
        );
        $stockIndex = [];
        foreach ($stockRows as $r) {
            $stockIndex[$r['variant_uuid'] . '|' . $r['warehouse_id']] = $r;
        }
        foreach ($variants as $i => $v) {
            $variants[$i]['stock'] = array_map(static function (array $w) use ($v, $stockIndex): array {
                $r = $stockIndex[$v['uuid'] . '|' . $w['id']] ?? null;

                return [
                    'warehouse_uuid' => $w['uuid'],
                    'warehouse_name' => $w['name'],
                    'quantity' => $r === null ? 0.0 : (float) $r['quantity'],
                    'average_cost' => $r === null || $r['average_cost'] === null ? null : (float) $r['average_cost'],
                ];
            }, $warehouses);
        }

        $lines = array_map(static fn (array $l): array => [
            'uuid' => $l['uuid'],
            'variant_uuid' => $l['variant_uuid'],
            'variant_name' => $l['variant_name'],
            'po_uuid' => $l['po_uuid'],
            'po_number' => $l['po_number'],
            'purchase_date' => $l['purchase_date'],
            'invoice_reference' => $l['invoice_reference'],
            'vendor_name' => $l['vendor_name'],
            'warehouse_name' => $l['warehouse_name'],
            'quantity' => (float) $l['quantity'],
            'unit_cost' => (float) $l['unit_cost'],
            'landing_cost' => $l['landing_cost'] === null ? null : (float) $l['landing_cost'],
            'returned_quantity' => (float) $l['returned_quantity'],
            'po_grand_total' => (float) $l['grand_total'],
            'po_amount_paid' => (float) $l['amount_paid'],
            'po_amount_returned' => (float) $l['amount_returned'],
            'po_payment_status' => $l['payment_status'],
            'po_line_count' => (int) $l['po_line_count'],
            'transport_percent' => $l['transport_percent'] === null ? null : (float) $l['transport_percent'],
        ], $this->db->select(
            'SELECT poi.`uuid`, poi.`quantity`, poi.`unit_cost`, poi.`landing_cost`,
                    v.`uuid` AS `variant_uuid`, v.`variant_name`,
                    po.`uuid` AS `po_uuid`, po.`po_number`, po.`purchase_date`, po.`invoice_reference`,
                    po.`grand_total`, po.`amount_paid`, po.`amount_returned`, po.`payment_status`,
                    po.`transport_percent`,
                    ve.`name` AS `vendor_name`, w.`name` AS `warehouse_name`,
                    (SELECT COALESCE(SUM(ri.`quantity`), 0) FROM `purchase_return_items` ri
                      WHERE ri.`purchase_order_item_id` = poi.`id` AND ri.`is_deleted` = 0) AS `returned_quantity`,
                    (SELECT COUNT(*) FROM `purchase_order_items` x
                      WHERE x.`purchase_order_id` = po.`id` AND x.`is_deleted` = 0) AS `po_line_count`
               FROM `purchase_order_items` poi
               INNER JOIN `product_variants` v ON v.`id` = poi.`product_variant_id`
               INNER JOIN `purchase_orders` po ON po.`id` = poi.`purchase_order_id` AND po.`is_deleted` = 0
               LEFT JOIN `vendors` ve ON ve.`id` = po.`vendor_id`
               LEFT JOIN `warehouses` w ON w.`id` = po.`warehouse_id`
              WHERE v.`product_id` = :id AND poi.`is_deleted` = 0
              ORDER BY po.`purchase_date` DESC, poi.`id` DESC
              LIMIT 100',
            ['id' => $productId]
        ));

        $history = array_map(static function (array $h): array {
            return [
                'date' => $h['created_date'],
                'by' => $h['full_name'],
                'notes' => $h['notes'],
                'changes' => $h['new_values'] === null ? null : json_decode((string) $h['new_values'], true),
            ];
        }, $this->db->select(
            "SELECT a.`created_date`, a.`notes`, a.`new_values`, u.`full_name`
               FROM `audit_logs` a
               LEFT JOIN `users` u ON u.`id` = a.`performed_by_user_id`
              WHERE a.`entity_name` = 'products' AND a.`entity_id` = :id
                AND a.`action` IN ('item_correction', 'item_reviewed')
              ORDER BY a.`id` DESC LIMIT 15",
            ['id' => $productId]
        ));

        unset($product['id']);
        $product['admin_reviewed'] = (int) $product['admin_reviewed'] === 1;
        $product['category_path'] = $product['parent_category_name'] !== null
            ? $product['parent_category_name'] . ' › ' . $product['category_name']
            : $product['category_name'];
        unset($product['parent_category_name']);

        return [
            'product' => $product,
            'variants' => $variants,
            'purchase_lines' => $lines,
            'history' => $history,
        ];
    }

    // -----------------------------------------------------------------
    // Apply a correction
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $data name?, category_uuid?, variants?[], purchase_lines?[], mark_reviewed?, note?
     *
     * @return array<string, mixed> Fresh detail plus a plain-language summary of what changed.
     */
    public function apply(string $productUuid, array $data, Request $request): array
    {
        $product = $this->db->selectOne(
            'SELECT * FROM `products` WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $productUuid]
        );

        if ($product === null) {
            throw new NotFoundException('That item does not exist.');
        }

        $productId = (int) $product['id'];
        $actorId = $request->authUserId();
        $note = trim((string) ($data['note'] ?? ''));
        $markReviewed = !empty($data['mark_reviewed']);

        // ---- What exactly is changing (computed up front, nothing written yet)
        $productChanges = $this->productChanges($product, $data);
        $variantChanges = $this->variantChanges($productId, (array) ($data['variants'] ?? []));
        $costChanges = $this->costChanges($productId, (array) ($data['purchase_lines'] ?? []));
        $stockChanges = $this->stockChanges($productId, (array) ($data['stock'] ?? []));
        $corrects = $productChanges !== [] || $variantChanges !== [] || $costChanges !== [] || $stockChanges !== [];

        if (!$corrects && !$markReviewed) {
            throw new HttpException('Nothing to correct — no value differs from what is saved.', 422);
        }

        $summary = [];

        $this->db->transaction(function () use (
            $product,
            $productId,
            $productChanges,
            $variantChanges,
            $costChanges,
            $stockChanges,
            $corrects,
            $markReviewed,
            $note,
            $actorId,
            $request,
            &$summary,
        ): void {
            // 1. The item itself (name, category).
            if ($productChanges !== []) {
                $payload = $productChanges['payload'];
                $this->products->update((string) $product['uuid'], $payload, $request);
                $summary = array_merge($summary, $productChanges['lines']);
            }

            // 2. Packs: name, unit, MRP, selling price (+ copies still showing the old price).
            foreach ($variantChanges as $change) {
                $this->products->updateVariant($change['uuid'], $change['payload'], $request);
                $this->correctPriceCopies($change, $actorId);
                $summary = array_merge($summary, $change['lines']);
            }

            // 3. Purchase costs: purchase totals, vendor balance, returns, batches, average cost.
            if ($costChanges !== []) {
                $summary = array_merge($summary, $this->applyCostChanges($costChanges, $request));
            }

            // 4. Stock on hand, counted: the figure entered is final, so it is
            //    applied after any quantity-received fix above.
            foreach ($stockChanges as $change) {
                $line = $this->applyStockCount($change, $note, $request);
                if ($line !== null) {
                    $summary[] = $line;
                }
            }

            // 5. Review flag.
            if ($markReviewed) {
                $this->db->execute(
                    'UPDATE `products`
                        SET `admin_reviewed` = 1, `review_reason` = NULL, `reviewed_by` = :actor,
                            `reviewed_date` = NOW(), `updated_by` = :actor2, `updated_date` = NOW(),
                            `version` = `version` + 1
                      WHERE `id` = :id',
                    ['actor' => $actorId, 'actor2' => $actorId, 'id' => $productId]
                );
                $summary[] = 'Marked as reviewed by an administrator';
            }

            $this->audit->log(
                entityName: 'products',
                entityId: $productId,
                action: $corrects ? 'item_correction' : 'item_reviewed',
                oldValues: ['admin_reviewed' => (int) $product['admin_reviewed'], 'review_reason' => $product['review_reason']],
                newValues: ['summary' => $summary],
                request: $request,
                entityUuid: (string) $product['uuid'],
                notes: $note === '' ? null : mb_substr($note, 0, 500),
            );
        });

        $result = $this->detail((string) $product['uuid']);
        $result['applied'] = $summary;

        return $result;
    }

    /** @return array{payload?: array<string, mixed>, lines?: array<int, string>} */
    private function productChanges(array $product, array $data): array
    {
        $payload = [];
        $lines = [];

        if (array_key_exists('name', $data)) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $data['name']) ?? '');

            if ($name === '') {
                throw new HttpException('The item name cannot be empty.', 422, ['name' => ['Enter the item name.']]);
            }

            if ($name !== $product['name']) {
                $payload['name'] = $name;
                $lines[] = sprintf('Name: "%s" → "%s"', $product['name'], $name);
            }
        }

        if (!empty($data['category_uuid'])) {
            $category = $this->db->selectOne(
                'SELECT c.`id`, c.`slug`, c.`name`, pc.`name` AS `parent_name`
                   FROM `categories` c LEFT JOIN `categories` pc ON pc.`id` = c.`parent_id`
                  WHERE c.`uuid` = :uuid AND c.`is_deleted` = 0',
                ['uuid' => (string) $data['category_uuid']]
            );

            if ($category === null) {
                throw new HttpException('That category does not exist.', 422, ['category_uuid' => ['Choose a category.']]);
            }

            if ((int) $category['id'] !== (int) $product['category_id']) {
                $old = $this->db->selectOne(
                    'SELECT c.`name`, pc.`name` AS `parent_name` FROM `categories` c
                       LEFT JOIN `categories` pc ON pc.`id` = c.`parent_id` WHERE c.`id` = :id',
                    ['id' => (int) $product['category_id']]
                );
                // ProductService refuses a category that has sub-categories, so
                // "Category › Sub-category" is always complete.
                $payload['category_slug'] = $category['slug'];
                $lines[] = sprintf(
                    'Category: %s → %s',
                    self::path($old['parent_name'] ?? null, $old['name'] ?? '—'),
                    self::path($category['parent_name'], (string) $category['name'])
                );
            }
        }

        return $payload === [] ? [] : ['payload' => $payload, 'lines' => $lines];
    }

    /** @return array<int, array<string, mixed>> */
    private function variantChanges(int $productId, array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['uuid'])) {
                continue;
            }

            $variant = $this->db->selectOne(
                'SELECT * FROM `product_variants` WHERE `uuid` = :uuid AND `product_id` = :pid AND `is_deleted` = 0',
                ['uuid' => (string) $row['uuid'], 'pid' => $productId]
            );

            if ($variant === null) {
                throw new HttpException('A pack size in the correction does not belong to this item.', 422);
            }

            $label = (string) $variant['variant_name'];
            $payload = [];
            $lines = [];

            if (array_key_exists('variant_name', $row)) {
                $name = trim((string) $row['variant_name']);
                if ($name === '') {
                    throw new HttpException(sprintf('Pack "%s": the pack name cannot be empty.', $label), 422);
                }
                if ($name !== $variant['variant_name']) {
                    $payload['variant_name'] = $name;
                    $lines[] = sprintf('Pack name: "%s" → "%s"', $variant['variant_name'], $name);
                }
            }

            if (array_key_exists('stock_unit_type', $row)) {
                $type = (string) $row['stock_unit_type'];
                if (!in_array($type, ['weight', 'quantity'], true)) {
                    throw new HttpException(sprintf('Pack "%s": unit must be by weight or by count.', $label), 422);
                }
                if ($type !== $variant['stock_unit_type']) {
                    $payload['stock_unit_type'] = $type;
                    $lines[] = sprintf('%s: sold by %s → by %s', $label,
                        $variant['stock_unit_type'] === 'weight' ? 'weight' : 'count',
                        $type === 'weight' ? 'weight' : 'count');
                }
            }

            if (array_key_exists('unit_label', $row)) {
                $unit = trim((string) ($row['unit_label'] ?? ''));
                $unit = $unit === '' ? null : mb_substr($unit, 0, 30);
                if ($unit !== ($variant['unit_label'] === null ? null : (string) $variant['unit_label'])) {
                    $payload['unit_label'] = $unit;
                    $lines[] = sprintf('%s: unit "%s" → "%s"', $label, $variant['unit_label'] ?? '—', $unit ?? '—');
                }
            }

            if (array_key_exists('weight_grams', $row) && $row['weight_grams'] !== null && $row['weight_grams'] !== '') {
                $grams = (int) round((float) $row['weight_grams']);
                if ($grams <= 0) {
                    throw new HttpException(sprintf('Pack "%s": weight must be greater than zero.', $label), 422);
                }
                if ($grams !== (int) $variant['weight_grams']) {
                    $payload['weight_grams'] = $grams;
                    $lines[] = sprintf('%s: weight %d g → %d g', $label, (int) $variant['weight_grams'], $grams);
                }
            }

            foreach (['mrp' => 'MRP', 'selling_price' => 'Selling price'] as $field => $caption) {
                if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                    continue;
                }
                $value = round((float) $row[$field], 2);
                if ($value <= 0) {
                    throw new HttpException(sprintf('Pack "%s": %s must be greater than zero.', $label, $caption), 422);
                }
                if (abs($value - (float) $variant[$field]) > 0.004) {
                    $payload[$field] = number_format($value, 2, '.', '');
                    $lines[] = sprintf('%s: %s ₹%s → ₹%s', $label, $caption,
                        number_format((float) $variant[$field], 2), number_format($value, 2));
                }
            }

            $mrp = isset($payload['mrp']) ? (float) $payload['mrp'] : (float) $variant['mrp'];
            $selling = isset($payload['selling_price']) ? (float) $payload['selling_price'] : (float) $variant['selling_price'];
            if ($selling > $mrp + 0.004) {
                throw new HttpException(
                    sprintf('Pack "%s": the selling price (₹%s) cannot be more than the MRP (₹%s).', $label,
                        number_format($selling, 2), number_format($mrp, 2)),
                    422
                );
            }

            if ($payload !== []) {
                $out[] = [
                    'uuid' => (string) $variant['uuid'],
                    'id' => (int) $variant['id'],
                    'old_mrp' => (float) $variant['mrp'],
                    'old_selling_price' => (float) $variant['selling_price'],
                    'payload' => $payload,
                    'lines' => $lines,
                ];
            }
        }

        return $out;
    }

    /**
     * MRP / selling price are also copied onto purchase lines and stock
     * batches. Copies that still show the wrong (old) value take the
     * corrected one; a copy holding some other, genuine earlier price is
     * history and is left alone.
     */
    private function correctPriceCopies(array $change, ?int $actorId): void
    {
        foreach (['mrp' => 'old_mrp', 'selling_price' => 'old_selling_price'] as $field => $oldKey) {
            if (!isset($change['payload'][$field])) {
                continue;
            }

            foreach (['purchase_order_items', 'inventory_batches'] as $table) {
                $this->db->execute(
                    "UPDATE `{$table}`
                        SET `{$field}` = :new, `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
                      WHERE `product_variant_id` = :vid AND `is_deleted` = 0
                        AND `{$field}` IS NOT NULL AND ABS(`{$field}` - :old) < 0.005",
                    [
                        'new' => $change['payload'][$field],
                        'actor' => $actorId,
                        'vid' => $change['id'],
                        'old' => $change[$oldKey],
                    ]
                );
            }
        }
    }

    /**
     * Purchase-line corrections: the cost per unit and/or the quantity received.
     *
     * @return array<int, array{po_id:int, item_id:int, cost:float, quantity:float}>
     */
    private function costChanges(int $productId, array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['uuid'])) {
                continue;
            }

            $hasCost = isset($row['unit_cost']) && $row['unit_cost'] !== '';
            $hasQuantity = isset($row['quantity']) && $row['quantity'] !== '';

            if (!$hasCost && !$hasQuantity) {
                continue;
            }

            $line = $this->db->selectOne(
                'SELECT poi.`id`, poi.`purchase_order_id`, poi.`unit_cost`, poi.`quantity`, v.`variant_name`,
                        (SELECT COALESCE(SUM(ri.`quantity`), 0) FROM `purchase_return_items` ri
                          WHERE ri.`purchase_order_item_id` = poi.`id` AND ri.`is_deleted` = 0) AS `returned`
                   FROM `purchase_order_items` poi
                   INNER JOIN `product_variants` v ON v.`id` = poi.`product_variant_id`
                  WHERE poi.`uuid` = :uuid AND poi.`is_deleted` = 0 AND v.`product_id` = :pid',
                ['uuid' => (string) $row['uuid'], 'pid' => $productId]
            );

            if ($line === null) {
                throw new HttpException('A purchase line in the correction does not belong to this item.', 422);
            }

            $cost = $hasCost ? round((float) $row['unit_cost'], 4) : (float) $line['unit_cost'];
            $quantity = $hasQuantity ? round((float) $row['quantity'], 3) : (float) $line['quantity'];

            if ($cost < 0) {
                throw new HttpException('A purchase cost cannot be negative.', 422);
            }

            if ($quantity <= 0) {
                throw new HttpException(
                    sprintf('%s: the quantity received must be more than 0. To cancel a whole line, use Purchase Inward.', $line['variant_name']),
                    422
                );
            }

            if ($quantity + 0.0005 < (float) $line['returned']) {
                throw new HttpException(
                    sprintf('%s: %s were already returned to the vendor from this purchase, so the quantity received cannot be less than that.',
                        $line['variant_name'], self::qty((float) $line['returned'])),
                    422
                );
            }

            if (abs($cost - (float) $line['unit_cost']) > 0.00005 || abs($quantity - (float) $line['quantity']) > 0.0005) {
                $out[] = [
                    'po_id' => (int) $line['purchase_order_id'],
                    'item_id' => (int) $line['id'],
                    'cost' => $cost,
                    'quantity' => $quantity,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<int, array{po_id:int, item_id:int, cost:float, quantity:float}> $changes
     *
     * @return array<int, string> Summary lines
     */
    private function applyCostChanges(array $changes, Request $request): array
    {
        $actorId = $request->authUserId();
        $byPo = [];
        foreach ($changes as $c) {
            $byPo[$c['po_id']][$c['item_id']] = $c;
        }

        /** @var array<string, array<int, array<string, mixed>>> $quantityMoves "variant|warehouse" => stock moves to post */
        $quantityMoves = [];

        $summary = [];
        /** @var array<string, array{0:int, 1:int}> $affectedStock */
        $affectedStock = [];
        /** @var array<string, float> $landingByMovementKey "po|variant|batch" => corrected landing cost */
        $landingByMovementKey = [];
        /** @var array<string, float> $oldLandingByMovementKey same key => landing cost before */
        $oldLandingByMovementKey = [];

        foreach ($byPo as $poId => $lineChanges) {
            $po = $this->db->selectOne(
                'SELECT * FROM `purchase_orders` WHERE `id` = :id AND `is_deleted` = 0 FOR UPDATE',
                ['id' => $poId]
            );

            if ($po === null) {
                throw new HttpException('One of those purchases no longer exists.', 409);
            }

            $items = $this->db->select(
                'SELECT poi.*, v.`variant_name` FROM `purchase_order_items` poi
                   INNER JOIN `product_variants` v ON v.`id` = poi.`product_variant_id`
                  WHERE poi.`purchase_order_id` = :id AND poi.`is_deleted` = 0
                  ORDER BY poi.`id` ASC FOR UPDATE',
                ['id' => $poId]
            );

            // Re-work the purchase exactly as Purchase Inward computes it.
            $subtotal = 0.0;
            foreach ($items as $i => $item) {
                $change = $lineChanges[(int) $item['id']] ?? null;
                $cost = $change['cost'] ?? (float) $item['unit_cost'];
                $quantity = $change['quantity'] ?? (float) $item['quantity'];
                $items[$i]['new_unit_cost'] = $cost;
                $items[$i]['new_quantity'] = $quantity;
                $items[$i]['new_line_subtotal'] = round($quantity * $cost, 2);
                $subtotal += $quantity * $cost;
            }

            $included = (int) $po['transport_included_in_cost'] === 1;
            $transport = (float) $po['transport_charge'];
            $transportPercent = (!$included && $subtotal > 0 && $transport > 0) ? ($transport / $subtotal) * 100 : null;
            $grandTotal = round(
                $subtotal - (float) $po['discount_amount'] + $transport + (float) $po['other_charges'] + (float) $po['tax_amount'],
                2
            );
            $amountPaid = (float) $po['amount_paid'];

            if ($amountPaid > $grandTotal + 0.005) {
                throw new HttpException(
                    sprintf(
                        'Purchase %s: ₹%s is already recorded as paid to the vendor, but the corrected total would be ₹%s. '
                        . 'Correct the vendor payment on that purchase first, then correct the cost.',
                        $po['po_number'],
                        number_format($amountPaid, 2),
                        number_format($grandTotal, 2)
                    ),
                    422
                );
            }

            $returnDelta = 0.0;

            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $newCost = (float) $item['new_unit_cost'];
                $newLanding = $included || $transportPercent === null ? $newCost : $newCost * (1 + $transportPercent / 100);
                $oldLanding = $item['landing_cost'] !== null ? (float) $item['landing_cost'] : (float) $item['unit_cost'];
                $gst = $item['gst_rate'] === null ? null : $item['new_line_subtotal'] * (float) $item['gst_rate'] / 100;

                $this->db->execute(
                    'UPDATE `purchase_order_items`
                        SET `quantity` = :qty, `unit_cost` = :cost, `landing_cost` = :landing, `line_subtotal` = :subtotal,
                            `gst_amount` = :gst, `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
                      WHERE `id` = :id',
                    [
                        'qty' => number_format((float) $item['new_quantity'], 3, '.', ''),
                        'cost' => number_format($newCost, 4, '.', ''),
                        'landing' => number_format($newLanding, 4, '.', ''),
                        'subtotal' => number_format($item['new_line_subtotal'], 2, '.', ''),
                        'gst' => $gst === null ? null : number_format($gst, 2, '.', ''),
                        'actor' => $actorId,
                        'id' => $itemId,
                    ]
                );

                $variantId = (int) $item['product_variant_id'];
                $warehouseId = (int) $po['warehouse_id'];
                $key = $poId . '|' . $variantId . '|' . (string) $item['batch_no'];

                $quantityDelta = round((float) $item['new_quantity'] - (float) $item['quantity'], 3);

                if (abs($newLanding - $oldLanding) > 0.00005 || abs($quantityDelta) > 0.0005) {
                    $landingByMovementKey[$key] = $newLanding;
                    $oldLandingByMovementKey[$key] = $oldLanding;
                    $affectedStock[$variantId . '|' . $warehouseId] = [$variantId, $warehouseId];
                }

                if (abs($quantityDelta) > 0.0005) {
                    // Received quantity typed wrong: stock moves by the
                    // difference, against this purchase and its batch, at
                    // the line's landing cost (so the movement history can
                    // value it — see rebuildAverageCost()).
                    $quantityMoves[$variantId . '|' . $warehouseId][] = [
                        'delta' => $quantityDelta,
                        'landing' => $newLanding,
                        'po_id' => $poId,
                        'po_number' => (string) $po['po_number'],
                        'batch_no' => $item['batch_no'],
                        'expiry_date' => $item['expiry_date'],
                    ];
                    $summary[] = sprintf(
                        'Purchase %s, %s: quantity received %s → %s (stock %s%s)',
                        $po['po_number'],
                        $item['variant_name'],
                        self::qty((float) $item['quantity']),
                        self::qty((float) $item['new_quantity']),
                        $quantityDelta > 0 ? '+' : '−',
                        self::qty(abs($quantityDelta))
                    );
                }

                if (abs($newLanding - $oldLanding) > 0.00005) {

                    if ($item['batch_no'] !== null) {
                        $this->db->execute(
                            'UPDATE `inventory_batches`
                                SET `unit_cost` = :new, `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
                              WHERE `product_variant_id` = :vid AND `warehouse_id` = :wid AND `batch_no` = :batch',
                            [
                                'new' => number_format($newLanding, 4, '.', ''),
                                'actor' => $actorId,
                                'vid' => $variantId,
                                'wid' => $warehouseId,
                                'batch' => $item['batch_no'],
                            ]
                        );
                    }
                }

                if (isset($lineChanges[$itemId]) && abs($newCost - (float) $item['unit_cost']) > 0.00005) {
                    $summary[] = sprintf(
                        'Purchase %s, %s: cost ₹%s → ₹%s (landing ₹%s → ₹%s)',
                        $po['po_number'],
                        $item['variant_name'],
                        number_format((float) $item['unit_cost'], 2),
                        number_format($newCost, 2),
                        number_format($oldLanding, 2),
                        number_format($newLanding, 2)
                    );

                    // Goods of this line already sent back to the vendor were
                    // credited at the wrong cost too.
                    $returns = $this->db->select(
                        'SELECT `id`, `purchase_return_id`, `quantity`, `line_amount`
                           FROM `purchase_return_items` WHERE `purchase_order_item_id` = :id AND `is_deleted` = 0',
                        ['id' => $itemId]
                    );

                    foreach ($returns as $ret) {
                        $newAmount = round((float) $ret['quantity'] * $newCost, 2);
                        $delta = $newAmount - (float) $ret['line_amount'];
                        $returnDelta += $delta;

                        $this->db->execute(
                            'UPDATE `purchase_return_items`
                                SET `unit_cost` = :cost, `line_amount` = :amount,
                                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
                              WHERE `id` = :id',
                            [
                                'cost' => number_format($newCost, 4, '.', ''),
                                'amount' => number_format($newAmount, 2, '.', ''),
                                'actor' => $actorId,
                                'id' => (int) $ret['id'],
                            ]
                        );
                        $this->db->execute(
                            'UPDATE `purchase_returns`
                                SET `total_amount` = GREATEST(`total_amount` + :delta, 0),
                                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
                              WHERE `id` = :id',
                            ['delta' => $delta, 'actor' => $actorId, 'id' => (int) $ret['purchase_return_id']]
                        );
                    }
                }
            }

            $amountReturned = max(0.0, round((float) $po['amount_returned'] + $returnDelta, 2));

            $this->db->execute(
                'UPDATE `purchase_orders`
                    SET `items_subtotal` = :subtotal, `transport_percent` = :tp, `grand_total` = :total,
                        `amount_returned` = :returned, `payment_status` = :status,
                        `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id',
                [
                    'subtotal' => number_format($subtotal, 2, '.', ''),
                    'tp' => $transportPercent === null ? null : number_format($transportPercent, 4, '.', ''),
                    'total' => number_format($grandTotal, 2, '.', ''),
                    'returned' => number_format($amountReturned, 2, '.', ''),
                    'status' => PurchaseOrderService::derivePaymentStatus($amountPaid, $grandTotal),
                    'actor' => $actorId,
                    'id' => $poId,
                ]
            );

            if (abs($grandTotal - (float) $po['grand_total']) > 0.004) {
                $summary[] = sprintf(
                    'Purchase %s total ₹%s → ₹%s (vendor balance follows)',
                    $po['po_number'],
                    number_format((float) $po['grand_total'], 2),
                    number_format($grandTotal, 2)
                );
            }
        }

        foreach ($affectedStock as $stockKey => [$variantId, $warehouseId]) {
            $summary = array_merge($summary, $this->rebuildAverageCost(
                $variantId,
                $warehouseId,
                $landingByMovementKey,
                $oldLandingByMovementKey,
                $quantityMoves[$stockKey] ?? [],
                $request,
            ));
        }

        return $summary;
    }

    /**
     * Re-works the stock's average cost from its movement history twice — as
     * recorded, and with the corrected purchase lines — and shifts the saved
     * average by the difference. When the history fully explains today's
     * figure this gives exactly the average the stock would have had; when
     * some of it predates the history (opening figures), the correction is
     * still applied without disturbing the part that history cannot explain.
     *
     * Quantity corrections are posted in between: the "before" replay runs on
     * the ledger as it was, the "after" replay includes the new movements.
     *
     * @param array<string, float> $newLanding
     * @param array<string, float> $oldLanding
     * @param array<int, array<string, mixed>> $quantityMoves
     *
     * @return array<int, string> Summary lines
     */
    private function rebuildAverageCost(
        int $variantId,
        int $warehouseId,
        array $newLanding,
        array $oldLanding,
        array $quantityMoves,
        Request $request,
    ): array {
        $actorId = $request->authUserId();
        $stock = $this->db->selectOne(
            'SELECT s.`id`, s.`quantity`, s.`average_cost`, v.`variant_name`
               FROM `inventory_stock` s INNER JOIN `product_variants` v ON v.`id` = s.`product_variant_id`
              WHERE s.`product_variant_id` = :vid AND s.`warehouse_id` = :wid FOR UPDATE',
            ['vid' => $variantId, 'wid' => $warehouseId]
        );

        if ($stock === null) {
            return [];
        }

        $current = $stock['average_cost'] !== null ? (float) $stock['average_cost'] : null;
        $before = self::replay($this->movementsOf($variantId, $warehouseId), $variantId, $oldLanding);
        $summary = [];

        if ($quantityMoves !== []) {
            $net = array_sum(array_map(static fn (array $m): float => (float) $m['delta'], $quantityMoves));

            if ((float) $stock['quantity'] + $net < -0.0005) {
                throw new HttpException(
                    sprintf(
                        '%s: only %s is in stock now — the rest has already been sold or moved — so the quantity received '
                        . 'cannot be lowered by %s. Lower it by at most %s, or correct the stock count instead.',
                        $stock['variant_name'],
                        self::qty((float) $stock['quantity']),
                        self::qty(abs($net)),
                        self::qty(max(0.0, (float) $stock['quantity']))
                    ),
                    422
                );
            }

            foreach ($quantityMoves as $move) {
                $this->inventory->recordMovement(
                    variantId: $variantId,
                    warehouseId: $warehouseId,
                    movementType: 'adjustment',
                    quantityDelta: (float) $move['delta'],
                    unitCost: (float) $move['landing'],
                    referenceType: 'purchase_order',
                    referenceId: (int) $move['po_id'],
                    batchNo: $move['batch_no'],
                    expiryDate: $move['delta'] > 0 ? $move['expiry_date'] : null,
                    reason: 'Item review: quantity received corrected on ' . $move['po_number'],
                    performedBy: $actorId,
                    request: $request,
                );
            }
        }

        $after = self::replay($this->movementsOf($variantId, $warehouseId), $variantId, $newLanding);

        if ($after === null) {
            return $summary;
        }

        $newAverage = $current === null ? $after : max(0.0, round($current + ($after - ($before ?? $current)), 4));

        // Always written: recordMovement() above blends a positive correction
        // on its own terms; the replayed figure is the one that stands.
        $this->db->execute(
            'UPDATE `inventory_stock`
                SET `average_cost` = :avg, `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['avg' => number_format($newAverage, 4, '.', ''), 'actor' => $actorId, 'id' => (int) $stock['id']]
        );

        if ($current === null || abs($newAverage - $current) >= 0.00005) {
            $summary[] = sprintf(
                '%s: average stock cost ₹%s → ₹%s',
                $stock['variant_name'],
                $current === null ? '—' : number_format($current, 2),
                number_format($newAverage, 2)
            );
        }

        return $summary;
    }

    /** @return array<int, array<string, mixed>> */
    private function movementsOf(int $variantId, int $warehouseId): array
    {
        return $this->db->select(
            'SELECT `movement_type`, `reference_type`, `reference_id`, `quantity_delta`, `unit_cost`, `batch_no`
               FROM `inventory_movements`
              WHERE `product_variant_id` = :vid AND `warehouse_id` = :wid
              ORDER BY `id` ASC',
            ['vid' => $variantId, 'wid' => $warehouseId]
        );
    }

    /**
     * Weighted-average cost from a movement history, the way recordMovement()
     * builds it — with each purchase line's goods valued at that line's
     * landing cost from $landing ("po|variant|batch" => cost). The ledger
     * keeps the cost as first typed; the purchase line holds it as last
     * corrected, so repeated corrections stay consistent.
     *
     * One addition: a quantity-received correction that takes goods back out
     * ('adjustment' against a purchase, with a cost) removes their value too,
     * since they never really arrived.
     *
     * @param array<int, array<string, mixed>> $movements
     * @param array<string, float> $landing
     */
    private static function replay(array $movements, int $variantId, array $landing): ?float
    {
        $quantity = 0.0;
        $average = null;

        foreach ($movements as $m) {
            $delta = (float) $m['quantity_delta'];
            $cost = $m['unit_cost'] === null ? null : (float) $m['unit_cost'];
            $fromPurchase = $m['reference_type'] === 'purchase_order' && $cost !== null;

            if ($fromPurchase) {
                $key = $m['reference_id'] . '|' . $variantId . '|' . (string) $m['batch_no'];
                if (isset($landing[$key])) {
                    $cost = $landing[$key];
                }
            }

            if ($cost !== null && $delta > 0) {
                $average = InventoryService::blendAverageCost($quantity, $average, $delta, $cost);
            } elseif ($fromPurchase && $delta < 0 && $m['movement_type'] === 'adjustment' && $average !== null) {
                $remaining = $quantity + $delta;
                if ($remaining > 0.0005 && $quantity > 0) {
                    $average = max(0.0, round(($quantity * $average + $delta * $cost) / $remaining, 4));
                }
            }

            $quantity += $delta;
        }

        return $average;
    }

    /**
     * Stock-on-hand corrections: [{variant_uuid, warehouse_uuid, quantity}],
     * quantity being what is really on the shelf.
     *
     * @return array<int, array{variant_id:int, warehouse_id:int, variant_name:string, warehouse_name:string, quantity:float}>
     */
    private function stockChanges(int $productId, array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['variant_uuid']) || empty($row['warehouse_uuid'])
                || !isset($row['quantity']) || $row['quantity'] === '') {
                continue;
            }

            $pair = $this->db->selectOne(
                'SELECT v.`id` AS `variant_id`, v.`variant_name`, w.`id` AS `warehouse_id`, w.`name` AS `warehouse_name`,
                        (SELECT s.`quantity` FROM `inventory_stock` s
                          WHERE s.`product_variant_id` = v.`id` AND s.`warehouse_id` = w.`id` LIMIT 1) AS `quantity`
                   FROM `product_variants` v
                   INNER JOIN `warehouses` w ON w.`uuid` = :wuuid AND w.`is_deleted` = 0
                  WHERE v.`uuid` = :vuuid AND v.`product_id` = :pid AND v.`is_deleted` = 0',
                ['wuuid' => (string) $row['warehouse_uuid'], 'vuuid' => (string) $row['variant_uuid'], 'pid' => $productId]
            );

            if ($pair === null) {
                throw new HttpException('A stock line in the correction does not belong to this item.', 422);
            }

            $target = round((float) $row['quantity'], 3);

            if ($target < 0) {
                throw new HttpException(sprintf('%s: stock cannot be less than 0.', $pair['variant_name']), 422);
            }

            if (abs($target - (float) ($pair['quantity'] ?? 0)) > 0.0005) {
                $out[] = [
                    'variant_id' => (int) $pair['variant_id'],
                    'warehouse_id' => (int) $pair['warehouse_id'],
                    'variant_name' => (string) $pair['variant_name'],
                    'warehouse_name' => (string) $pair['warehouse_name'],
                    'quantity' => $target,
                ];
            }
        }

        return $out;
    }

    /**
     * Brings recorded stock to the counted figure with an ordinary stock
     * adjustment (the ledger shows who, when and why). The average cost is
     * left as it is: a counting error says nothing about what goods cost.
     */
    private function applyStockCount(array $change, string $note, Request $request): ?string
    {
        $row = $this->db->selectOne(
            'SELECT `quantity` FROM `inventory_stock` WHERE `product_variant_id` = :vid AND `warehouse_id` = :wid',
            ['vid' => $change['variant_id'], 'wid' => $change['warehouse_id']]
        );
        $now = $row === null ? 0.0 : (float) $row['quantity'];
        $delta = round($change['quantity'] - $now, 3);

        if (abs($delta) < 0.0005) {
            return null;
        }

        $this->inventory->manualAdjustment(
            variantId: $change['variant_id'],
            warehouseId: $change['warehouse_id'],
            quantityDelta: $delta,
            movementType: 'adjustment',
            reason: mb_substr('Item review: stock corrected by administrator' . ($note !== '' ? ' — ' . $note : ''), 0, 255),
            performedBy: $request->authUserId(),
            request: $request,
        );

        return sprintf(
            '%s at %s: stock %s → %s',
            $change['variant_name'],
            $change['warehouse_name'],
            self::qty($now),
            self::qty($change['quantity'])
        );
    }

    private static function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private static function path(?string $parent, string $name): string
    {
        return $parent !== null && $parent !== '' ? $parent . ' › ' . $name : $name;
    }
}
