<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Core\Database;
use App\Helpers\Uuid;
use App\Repositories\SettingRepository;

/**
 * Gapless, sequential numbering for orders and GST invoices.
 *
 * Invoice numbering is a legal requirement in India, not a formatting choice:
 * numbers must be sequential within a financial year and must not have gaps.
 * That rules out deriving them from an AUTO_INCREMENT id, which leaves a hole
 * wherever a transaction rolls back.
 *
 * So the counter is a row, incremented under SELECT ... FOR UPDATE inside the
 * caller's transaction. If that transaction rolls back, the number is released
 * with it and the next order takes it. Two concurrent orders serialise on the
 * lock rather than colliding.
 *
 * The cost is that concurrent placements briefly queue on one row. At the scale
 * in the SRS — a thousand orders an hour, so roughly one every three seconds —
 * a lock held for the length of one INSERT is not a bottleneck. If it ever
 * becomes one, order numbers can move to a per-shard counter; invoice numbers
 * cannot, because the law wants one unbroken series.
 */
final class NumberingService
{
    public function __construct(
        private readonly Database $db,
        private readonly SettingRepository $settings,
    ) {
    }

    /**
     * The Indian financial year for a moment in time: April to March.
     * 15 May 2026 falls in 2026-27; 15 February 2026 falls in 2025-26.
     */
    public function financialYear(?int $timestamp = null): string
    {
        $timestamp ??= time();
        $year = (int) date('Y', $timestamp);
        $month = (int) date('n', $timestamp);

        $start = $month >= 4 ? $year : $year - 1;

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }

    /**
     * Next order number, e.g. SDF2627000042.
     *
     * Must be called inside a transaction; the lock is only meaningful for as
     * long as that transaction lives.
     */
    public function nextOrderNumber(): string
    {
        $prefix = (string) ($this->settings->value('order_number_prefix') ?? 'SDF');

        return $this->next('order', $prefix, 6);
    }

    /**
     * Next GST invoice number, e.g. INV2627000042.
     *
     * Only ever called at payment confirmation. An unpaid or abandoned order
     * must not consume an invoice number, because the resulting gap is exactly
     * what the rule forbids.
     */
    public function nextInvoiceNumber(): string
    {
        $prefix = (string) ($this->settings->value('invoice_number_prefix') ?? 'INV');

        return $this->next('invoice', $prefix, 6);
    }

    /** Next shipment number, e.g. SHP2627000042. */
    public function nextShipmentNumber(): string
    {
        return $this->next('shipment', 'SHP', 6);
    }

    /** Next manifest number, e.g. MFT2627000007. */
    public function nextManifestNumber(): string
    {
        return $this->next('manifest', 'MFT', 6);
    }

    /** Next packing slip number, e.g. PS2627000042. */
    public function nextPackingSlipNumber(): string
    {
        return $this->next('packing_slip', 'PS', 6);
    }

    /** Next bulk enquiry number, e.g. BE2627000009. */
    public function nextBulkEnquiryNumber(): string
    {
        return $this->next('bulk_enquiry', 'BE', 6);
    }

    /** Next bulk quote number, e.g. BQ2627000009. */
    public function nextBulkQuoteNumber(): string
    {
        return $this->next('bulk_quote', 'BQ', 6);
    }

    /** Next commission settlement number, e.g. STL2627000003. */
    public function nextSettlementNumber(): string
    {
        return $this->next('settlement', 'STL', 6);
    }

    /** Next support ticket number, e.g. TKT2627000018. */
    public function nextTicketNumber(): string
    {
        return $this->next('ticket', 'TKT', 6);
    }

    /** Next purchase order number, e.g. PO2627000012. */
    public function nextPurchaseOrderNumber(): string
    {
        return $this->next('purchase_order', 'PO', 6);
    }

    /** Next POS sale number, e.g. POS2627000042. */
    public function nextPosSaleNumber(): string
    {
        return $this->next('pos_sale', 'POS', 6);
    }

    /** Next POS refund number, e.g. POR2627000007. */
    public function nextPosRefundNumber(): string
    {
        return $this->next('pos_refund', 'POR', 6);
    }

    /** Next vendor code, e.g. VEN2627000012. */
    public function nextVendorCode(): string
    {
        return $this->next('vendor', 'VEN', 6);
    }

    /** Next purchase return number, e.g. PR2627000003. */
    public function nextPurchaseReturnNumber(): string
    {
        return $this->next('purchase_return', 'PR', 6);
    }

    private function next(string $purpose, string $prefix, int $padding): string
    {
        $financialYear = $this->financialYear();
        $key = $purpose . ':' . $financialYear;

        // numbering_sequences.purpose is an ENUM('order','invoice'). Shipment and
        // manifest counters behave like order numbers — sequential, no legal
        // gapless requirement — so they store as 'order' rather than widening the
        // enum for what is only a label. The sequence_key keeps them separate.
        $storedPurpose = $purpose === 'invoice' ? 'invoice' : 'order';

        $row = $this->db->selectOne(
            'SELECT * FROM `numbering_sequences` WHERE `sequence_key` = :key FOR UPDATE',
            ['key' => $key]
        );

        if ($row === null) {
            // First order of a new financial year. Created here rather than by a
            // scheduled job, so nobody has to remember to run anything on 1 April.
            $this->db->insert(
                'INSERT INTO `numbering_sequences`
                     (`uuid`, `sequence_key`, `purpose`, `prefix`, `financial_year`, `last_number`,
                      `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES (:uuid, :key, :purpose, :prefix, :financial_year, 0, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'key' => $key,
                    'purpose' => $storedPurpose,
                    'prefix' => $prefix,
                    'financial_year' => $financialYear,
                ]
            );

            $row = $this->db->selectOne(
                'SELECT * FROM `numbering_sequences` WHERE `sequence_key` = :key FOR UPDATE',
                ['key' => $key]
            );
        }

        $next = (int) $row['last_number'] + 1;

        $this->db->execute(
            'UPDATE `numbering_sequences`
                SET `last_number` = :next, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['next' => $next, 'id' => (int) $row['id']]
        );

        // SDF + 2627 + 000042 — the year is compressed so the number stays short
        // enough to read over the phone to a customer.
        return sprintf(
            '%s%s%s',
            $prefix,
            str_replace('-', '', substr($financialYear, 2)),
            str_pad((string) $next, $padding, '0', STR_PAD_LEFT)
        );
    }
}
