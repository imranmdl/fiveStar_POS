<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Core\Database;
use App\Helpers\Money;
use App\Helpers\Uuid;
use App\Repositories\SettingRepository;

/**
 * Writes order line items and GST summary rows.
 *
 * Extracted so that checkout and bulk-order conversion cannot drift apart on
 * tax treatment. Two code paths independently deciding how GST is split is the
 * kind of duplication that stays correct for six months and then quietly
 * produces two different invoices for the same rate.
 *
 * Every order in the system passes through here, whatever created it.
 */
final class OrderWriter
{
    public function __construct(
        private readonly Database $db,
        private readonly SettingRepository $settings,
    ) {
    }

    /**
     * Writes one line item.
     *
     * `taxable_value + tax_amount = line_payable` is a CHECK constraint on the
     * table, so a caller that computes tax inconsistently is rejected by the
     * database rather than producing an invoice that does not add up.
     *
     * @param array<string, mixed> $line
     */
    public function writeItem(int $orderId, array $line): void
    {
        $this->db->insert(
            'INSERT INTO `order_items`
                 (`uuid`, `order_id`, `product_id`, `variant_id`, `product_name`, `variant_name`,
                  `sku`, `brand`, `hsn_code`, `quantity`, `unit_mrp`, `unit_price`, `line_mrp`,
                  `line_subtotal`, `product_discount`, `apportioned_discount`, `line_payable`,
                  `taxable_value`, `tax_amount`, `gst_rate`, `line_weight_grams`,
                  `is_gift`, `gift_message`, `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES
                 (:uuid, :order_id, :product_id, :variant_id, :product_name, :variant_name,
                  :sku, :brand, :hsn_code, :quantity, :unit_mrp, :unit_price, :line_mrp,
                  :line_subtotal, :product_discount, :apportioned_discount, :line_payable,
                  :taxable_value, :tax_amount, :gst_rate, :line_weight_grams,
                  :is_gift, :gift_message, NOW(), 1, 0, 1)',
            [
                'uuid' => Uuid::v4(),
                'order_id' => $orderId,
                'product_id' => (int) $line['product_id'],
                'variant_id' => (int) $line['variant_id'],
                'product_name' => (string) $line['product_name'],
                'variant_name' => (string) $line['variant_name'],
                'sku' => (string) $line['sku'],
                'brand' => $line['brand'] ?? null,
                'hsn_code' => $line['hsn_code'] ?? null,
                'quantity' => (int) $line['quantity'],
                'unit_mrp' => (string) $line['unit_mrp'],
                'unit_price' => (string) $line['unit_price'],
                'line_mrp' => (string) $line['line_mrp'],
                'line_subtotal' => (string) $line['line_subtotal'],
                'product_discount' => (string) ($line['product_discount'] ?? '0.00'),
                'apportioned_discount' => (string) ($line['apportioned_discount'] ?? '0.00'),
                'line_payable' => (string) $line['line_payable'],
                'taxable_value' => (string) $line['taxable_value'],
                'tax_amount' => (string) $line['tax_amount'],
                'gst_rate' => (string) $line['gst_rate'],
                'line_weight_grams' => (int) ($line['line_weight_grams'] ?? 0),
                'is_gift' => (int) ($line['is_gift'] ?? 0),
                'gift_message' => $line['gift_message'] ?? null,
            ]
        );
    }

    /**
     * Writes the per-rate GST summary an invoice reproduces.
     *
     * @param array<int, array{rate:mixed, taxable_value:mixed, tax_amount:mixed}> $taxBreakdown
     */
    public function writeTaxLines(int $orderId, array $taxBreakdown, string $buyerState): void
    {
        $sellerState = (string) ($this->settings->value('seller_state') ?? 'Karnataka');

        foreach ($taxBreakdown as $bucket) {
            $tax = Money::fromDecimal((string) $bucket['tax_amount']);
            $split = GstApportioner::split($tax, $sellerState, $buyerState);

            $this->db->insert(
                'INSERT INTO `order_tax_lines`
                     (`uuid`, `order_id`, `gst_rate`, `taxable_value`, `tax_amount`,
                      `cgst_amount`, `sgst_amount`, `igst_amount`,
                      `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :order_id, :gst_rate, :taxable_value, :tax_amount,
                      :cgst, :sgst, :igst, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'order_id' => $orderId,
                    'gst_rate' => (string) $bucket['rate'],
                    'taxable_value' => (string) $bucket['taxable_value'],
                    'tax_amount' => (string) $tax,
                    'cgst' => (string) $split['cgst'],
                    'sgst' => (string) $split['sgst'],
                    'igst' => (string) $split['igst'],
                ]
            );
        }
    }

    /**
     * Groups priced lines into per-rate GST buckets.
     *
     * A cart mixing 5% spices and 12% nuts needs both shown separately on the
     * invoice, and the buckets must sum back to the order totals exactly.
     *
     * @param array<int, array{gst_rate:mixed, taxable_value:mixed, tax_amount:mixed}> $lines
     *
     * @return array<int, array{rate:string, taxable_value:string, tax_amount:string}>
     */
    public function bucketByRate(array $lines): array
    {
        $buckets = [];

        foreach ($lines as $line) {
            $rate = number_format((float) $line['gst_rate'], 2, '.', '');

            if (!isset($buckets[$rate])) {
                $buckets[$rate] = ['taxable' => Money::zero(), 'tax' => Money::zero()];
            }

            $buckets[$rate]['taxable'] = $buckets[$rate]['taxable']
                ->add(Money::fromDecimal((string) $line['taxable_value']));
            $buckets[$rate]['tax'] = $buckets[$rate]['tax']
                ->add(Money::fromDecimal((string) $line['tax_amount']));
        }

        ksort($buckets);

        $result = [];

        foreach ($buckets as $rate => $totals) {
            $result[] = [
                'rate' => (string) $rate,
                'taxable_value' => $totals['taxable']->toDecimal(),
                'tax_amount' => $totals['tax']->toDecimal(),
            ];
        }

        return $result;
    }
}
