<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;

/**
 * Plain CSV export/templates for the entities the vendor-management brief
 * asks for: vendors, products, purchase orders, inventory, inventory
 * batches, and vendor-payment transactions. Native fputcsv, not
 * phpoffice/phpspreadsheet — that library is already a dependency (used by
 * ImportService for .xlsx reading) but pulling it in just to WRITE a CSV
 * would be a heavier tool than the job needs.
 *
 * A template is generated from the exact same header list export() uses,
 * with one example row — never a hand-maintained file that could drift from
 * what the real export/import actually expects.
 */
final class ExportService
{
    private const ENTITIES = ['vendors', 'products', 'purchase_orders', 'inventory', 'inventory_batches', 'transactions', 'wallet_transactions'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{filename:string, csv:string} */
    public function export(string $entity): array
    {
        $this->assertKnownEntity($entity);

        [$headers, $rows] = match ($entity) {
            'vendors' => [$this->vendorHeaders(), $this->vendorRows()],
            'products' => [$this->productHeaders(), $this->productRows()],
            'purchase_orders' => [$this->purchaseOrderHeaders(), $this->purchaseOrderRows()],
            'inventory' => [$this->inventoryHeaders(), $this->inventoryRows()],
            'inventory_batches' => [$this->inventoryBatchHeaders(), $this->inventoryBatchRows()],
            'transactions' => [$this->transactionHeaders(), $this->transactionRows()],
            'wallet_transactions' => [$this->walletTransactionHeaders(), $this->walletTransactionRows()],
        };

        return [
            'filename' => sprintf('%s_%s.csv', $entity, date('Ymd_His')),
            'csv' => $this->toCsv($headers, $rows),
        ];
    }

    /** @return array{filename:string, csv:string} */
    public function template(string $entity): array
    {
        $this->assertKnownEntity($entity);

        [$headers, $example] = match ($entity) {
            'vendors' => [$this->vendorHeaders(), [
                'Golden Spice Traders', 'Golden Spice Traders Pvt Ltd', 'Ramesh Kumar', '9876543210',
                'vendor@example.com', '12 Market Road', '', 'Mumbai', 'Maharashtra', '400001', 'India',
                '27AAAAA0000A1Z5', 'AAAAA0000A', 'Golden Spice Traders', '123456789012', 'HDFC0001234',
                'HDFC Bank', 'Net 30', '',
            ]],
            'products' => [$this->productHeaders(), ['DEMO-SKU-100', 'Sample Product', '100 g pouch', 'Sample Category', '149.00', '129.00', 'published']],
            'purchase_orders' => [$this->purchaseOrderHeaders(), []],
            'inventory' => [$this->inventoryHeaders(), []],
            'inventory_batches' => [$this->inventoryBatchHeaders(), []],
            'transactions' => [$this->transactionHeaders(), []],
            'wallet_transactions' => [$this->walletTransactionHeaders(), []],
        };

        return [
            'filename' => sprintf('%s_template.csv', $entity),
            'csv' => $this->toCsv($headers, $example === [] ? [] : [$example]),
        ];
    }

    private function assertKnownEntity(string $entity): void
    {
        if (!in_array($entity, self::ENTITIES, true)) {
            throw new HttpException('Unknown export entity: ' . $entity, 404);
        }
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function toCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    // -- Vendors --------------------------------------------------------

    /** @return array<int, string> */
    private function vendorHeaders(): array
    {
        return [
            'name', 'company_name', 'contact_person', 'phone', 'email',
            'address_line1', 'address_line2', 'city', 'state', 'pincode', 'country',
            'gstin', 'pan', 'bank_account_name', 'bank_account_number', 'bank_ifsc', 'bank_name',
            'payment_terms', 'notes',
        ];
    }

    /** @return array<int, array<int, mixed>> */
    private function vendorRows(): array
    {
        $rows = $this->db->select(
            'SELECT `name`, `company_name`, `contact_person`, `phone`, `email`,
                    `address_line1`, `address_line2`, `city`, `state`, `pincode`, `country`,
                    `gstin`, `pan`, `bank_account_name`, `bank_account_number`, `bank_ifsc`, `bank_name`,
                    `payment_terms`, `notes`
               FROM `vendors` WHERE `is_deleted` = 0 ORDER BY `name` ASC'
        );

        return array_map('array_values', $rows);
    }

    // -- Products ---------------------------------------------------------

    /** @return array<int, string> */
    private function productHeaders(): array
    {
        return ['sku', 'product_name', 'variant_name', 'category', 'mrp', 'selling_price', 'status'];
    }

    /** @return array<int, array<int, mixed>> */
    private function productRows(): array
    {
        $rows = $this->db->select(
            'SELECT v.`sku`, p.`name` AS product_name, v.`variant_name`, c.`name` AS category,
                    v.`mrp`, v.`selling_price`, p.`status`
               FROM `product_variants` v
               INNER JOIN `products` p ON p.`id` = v.`product_id`
               INNER JOIN `categories` c ON c.`id` = p.`category_id`
              WHERE v.`is_deleted` = 0 AND p.`is_deleted` = 0
              ORDER BY p.`name` ASC, v.`display_order` ASC'
        );

        return array_map('array_values', $rows);
    }

    // -- Purchase orders ----------------------------------------------------

    /** @return array<int, string> */
    private function purchaseOrderHeaders(): array
    {
        return [
            'po_number', 'vendor_name', 'warehouse_name', 'purchase_date',
            'items_subtotal', 'discount_amount', 'tax_amount', 'grand_total',
            'payment_status', 'amount_paid', 'amount_returned',
        ];
    }

    /** @return array<int, array<int, mixed>> */
    private function purchaseOrderRows(): array
    {
        $rows = $this->db->select(
            'SELECT po.`po_number`, v.`name` AS vendor_name, w.`name` AS warehouse_name, po.`purchase_date`,
                    po.`items_subtotal`, po.`discount_amount`, po.`tax_amount`, po.`grand_total`,
                    po.`payment_status`, po.`amount_paid`, po.`amount_returned`
               FROM `purchase_orders` po
               INNER JOIN `vendors` v ON v.`id` = po.`vendor_id`
               INNER JOIN `warehouses` w ON w.`id` = po.`warehouse_id`
              WHERE po.`is_deleted` = 0
              ORDER BY po.`purchase_date` DESC, po.`id` DESC'
        );

        return array_map('array_values', $rows);
    }

    // -- Inventory (current stock) --------------------------------------

    /** @return array<int, string> */
    private function inventoryHeaders(): array
    {
        return ['sku', 'variant_name', 'warehouse_name', 'quantity', 'average_cost', 'reorder_threshold'];
    }

    /** @return array<int, array<int, mixed>> */
    private function inventoryRows(): array
    {
        $rows = $this->db->select(
            'SELECT v.`sku`, v.`variant_name`, w.`name` AS warehouse_name,
                    s.`quantity`, s.`average_cost`, s.`reorder_threshold`
               FROM `inventory_stock` s
               INNER JOIN `product_variants` v ON v.`id` = s.`product_variant_id`
               INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`
              WHERE s.`is_deleted` = 0
              ORDER BY v.`sku` ASC'
        );

        return array_map('array_values', $rows);
    }

    // -- Inventory batches ------------------------------------------------

    /** @return array<int, string> */
    private function inventoryBatchHeaders(): array
    {
        return ['sku', 'variant_name', 'warehouse_name', 'batch_no', 'expiry_date', 'quantity', 'unit_cost', 'mrp', 'selling_price'];
    }

    /** @return array<int, array<int, mixed>> */
    private function inventoryBatchRows(): array
    {
        $rows = $this->db->select(
            'SELECT v.`sku`, v.`variant_name`, w.`name` AS warehouse_name,
                    b.`batch_no`, b.`expiry_date`, b.`quantity`, b.`unit_cost`, b.`mrp`, b.`selling_price`
               FROM `inventory_batches` b
               INNER JOIN `product_variants` v ON v.`id` = b.`product_variant_id`
               INNER JOIN `warehouses` w ON w.`id` = b.`warehouse_id`
              WHERE b.`is_deleted` = 0
              ORDER BY b.`expiry_date` ASC, v.`sku` ASC'
        );

        return array_map('array_values', $rows);
    }

    // -- Transactions (vendor payments) ----------------------------------

    /** @return array<int, string> */
    private function transactionHeaders(): array
    {
        return ['vendor_name', 'po_number', 'amount', 'payment_method', 'payment_date', 'reference_number', 'status'];
    }

    /** @return array<int, array<int, mixed>> */
    private function transactionRows(): array
    {
        $rows = $this->db->select(
            'SELECT v.`name` AS vendor_name, po.`po_number`, vp.`amount`, vp.`payment_method`,
                    vp.`payment_date`, vp.`reference_number`, vp.`status`
               FROM `vendor_payments` vp
               INNER JOIN `vendors` v ON v.`id` = vp.`vendor_id`
               INNER JOIN `purchase_orders` po ON po.`id` = vp.`purchase_order_id`
              WHERE vp.`is_deleted` = 0
              ORDER BY vp.`payment_date` DESC, vp.`id` DESC'
        );

        return array_map('array_values', $rows);
    }

    // -- Wallet transactions ----------------------------------------------

    /** @return array<int, string> */
    private function walletTransactionHeaders(): array
    {
        return [
            'transaction_uuid', 'customer_name', 'mobile', 'direction', 'source', 'amount',
            'balance_after', 'reference_type', 'reference_id', 'narration', 'status', 'created_date',
        ];
    }

    /** @return array<int, array<int, mixed>> */
    private function walletTransactionRows(): array
    {
        $rows = $this->db->select(
            "SELECT wt.`uuid` AS transaction_uuid, u.`full_name` AS customer_name, u.`mobile`,
                    wt.`direction`, wt.`source`, wt.`amount`, wt.`balance_after`,
                    wt.`reference_type`, wt.`reference_id`, wt.`narration`,
                    IF(wt.`is_deleted` = 1, 'reversed', 'posted') AS `status`,
                    wt.`created_date`
               FROM `wallet_transactions` wt
               INNER JOIN `users` u ON u.`id` = wt.`user_id`
              ORDER BY wt.`created_date` DESC, wt.`id` DESC"
        );

        return array_map('array_values', $rows);
    }
}
