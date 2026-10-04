<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Money;
use App\Helpers\Uuid;
use App\Repositories\OrderRepository;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;
use App\Services\Orders\NumberingService;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\OrderWriter;
use App\Services\Orders\PaymentStatus;

/**
 * Wholesale enquiries, quotations and their conversion into real orders.
 *
 * B2B does not start with a cart. Quantities are negotiated, prices are not on
 * the website, and a GSTIN has to be captured for the invoice — so the flow is
 * enquiry, quote, revision, acceptance.
 *
 * The decision that matters:
 *
 *   AN ACCEPTED QUOTE BECOMES AN ORDINARY ORDER. It gets no payment shortcut,
 *   no dispatch shortcut and no exemptions. BR-003 (OTP), BR-004 (prepaid UPI),
 *   BR-005 (no progress without verified payment) and BR-007 (courier selection)
 *   apply to a fifty-kilo wholesale consignment exactly as they apply to a 200g
 *   pouch. The moment a B2B flow gets its own path is the moment unpaid goods
 *   start leaving the building on a promise, and wholesale is precisely where
 *   the amounts are large enough for that to hurt.
 *
 * Quote revisions are new rows rather than edits. A customer who accepted
 * revision 2 must be held to revision 2 even after revision 3 is drafted, and
 * "what did we actually offer them" is the first question in any dispute.
 */
final class BulkOrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly UserRepository $users,
        private readonly OrderWriter $writer,
        private readonly NumberingService $numbering,
        private readonly OtpService $otp,
        private readonly DeliveryChargeService $delivery,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Records a wholesale enquiry.
     *
     * Open to guests: a business making a first approach should not have to
     * create an account before finding out whether we can supply them.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function submitEnquiry(Request $request, array $data): array
    {
        $enquiryId = $this->db->transaction(function () use ($data, $request): int {
            $number = $this->numbering->nextBulkEnquiryNumber();

            return (int) $this->db->insert(
                'INSERT INTO `bulk_order_enquiries`
                     (`uuid`, `enquiry_number`, `user_id`, `business_name`, `gstin`,
                      `contact_name`, `contact_mobile`, `contact_email`,
                      `delivery_pincode`, `delivery_city`, `delivery_state`,
                      `requirements`, `expected_delivery_date`, `estimated_quantity`,
                      `estimated_budget`, `status`, `source`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :number, :user_id, :business_name, :gstin,
                      :contact_name, :contact_mobile, :contact_email,
                      :pincode, :city, :state, :requirements, :expected_date,
                      :quantity, :budget, \'new\', :source,
                      :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'number' => $number,
                    'user_id' => $request->authUserId(),
                    'business_name' => $data['business_name'],
                    'gstin' => $data['gstin'] ?? null,
                    'contact_name' => $data['contact_name'],
                    'contact_mobile' => $data['contact_mobile'],
                    'contact_email' => $data['contact_email'] ?? null,
                    'pincode' => $data['delivery_pincode'] ?? null,
                    'city' => $data['delivery_city'] ?? null,
                    'state' => $data['delivery_state'] ?? null,
                    'requirements' => $data['requirements'],
                    'expected_date' => $data['expected_delivery_date'] ?? null,
                    'quantity' => $data['estimated_quantity'] ?? null,
                    'budget' => $data['estimated_budget'] ?? null,
                    'source' => $data['source'] ?? 'web',
                    'created_by' => $request->authUserId(),
                ]
            );
        });

        $enquiry = $this->requireEnquiryById($enquiryId);

        $this->logger->info('Bulk enquiry received', [
            'enquiry_number' => $enquiry['enquiry_number'],
            'business' => $enquiry['business_name'],
        ], 'bulk');

        return [
            'enquiry' => $this->presentEnquiry($enquiry, customerView: true),
            'message' => sprintf(
                'Thank you. Your enquiry %s has been received and our team will respond with a quotation.',
                $enquiry['enquiry_number']
            ),
        ];
    }

    /**
     * Builds a quotation.
     *
     * Prices are negotiated and GST-inclusive, like every other price in this
     * system: Indian MRP includes tax, so it is extracted rather than added.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function createQuote(Request $request, string $enquiryUuid, array $items, array $terms = []): array
    {
        $enquiry = $this->requireEnquiry($enquiryUuid);

        if (in_array($enquiry['status'], ['converted', 'declined'], true)) {
            throw new HttpException(
                'This enquiry is ' . $enquiry['status'] . ' and cannot be quoted again.',
                409
            );
        }

        if ($items === []) {
            throw new HttpException('A quotation needs at least one line.', 422);
        }

        $priced = [];
        $subtotal = Money::zero();
        $taxableTotal = Money::zero();
        $taxTotal = Money::zero();
        $totalWeight = 0;

        foreach ($items as $index => $item) {
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw new HttpException(
                    sprintf('Line %d needs a quantity greater than zero.', $index + 1),
                    422
                );
            }

            $unitPrice = Money::fromDecimal((string) ($item['unit_price'] ?? '0'));

            if (!$unitPrice->isPositive()) {
                throw new HttpException(
                    sprintf('Line %d needs a unit price.', $index + 1),
                    422
                );
            }

            $variant = null;

            if (($item['variant_uuid'] ?? null) !== null) {
                $variant = $this->db->selectOne(
                    'SELECT v.*, p.`id` AS `product_id`, p.`name` AS `product_name`, p.`brand`,
                            p.`hsn_code`, p.`gst_rate` AS `product_gst_rate`
                       FROM `product_variants` v
                       INNER JOIN `products` p ON p.`id` = v.`product_id`
                      WHERE v.`uuid` = :uuid AND v.`is_deleted` = 0 LIMIT 1',
                    ['uuid' => (string) $item['variant_uuid']]
                );

                if ($variant === null) {
                    throw new HttpException(
                        sprintf('Line %d refers to a pack size that does not exist.', $index + 1),
                        422
                    );
                }
            }

            $gstRate = (float) ($item['gst_rate'] ?? $variant['product_gst_rate'] ?? 5.00);
            $lineTotal = $unitPrice->multiply($quantity);

            // GST-inclusive, extracted. Consistent with the rest of the
            // platform: a wholesale price quoted at Rs 400/kg is Rs 400
            // delivered, not Rs 400 plus tax.
            $extracted = $lineTotal->extractInclusiveTax($gstRate);

            $priced[] = [
                'variant' => $variant,
                'description' => (string) ($item['description']
                    ?? ($variant === null ? 'Custom line' : $variant['product_name'] . ' ' . $variant['variant_name'])),
                'sku' => $variant['sku'] ?? ($item['sku'] ?? null),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'gst_rate' => $gstRate,
                'taxable_value' => $extracted['net'],
                'tax_amount' => $extracted['tax'],
                'unit_weight_grams' => (int) ($item['unit_weight_grams'] ?? $variant['weight_grams'] ?? 0),
            ];

            $subtotal = $subtotal->add($lineTotal);
            $taxableTotal = $taxableTotal->add($extracted['net']);
            $taxTotal = $taxTotal->add($extracted['tax']);
            $totalWeight += (int) ($item['unit_weight_grams'] ?? $variant['weight_grams'] ?? 0) * $quantity;
        }

        $deliveryCharge = Money::fromDecimal((string) ($terms['delivery_charge'] ?? '0'));
        $discount = Money::fromDecimal((string) ($terms['discount_amount'] ?? '0'));

        if ($discount->paise() > $subtotal->paise()) {
            throw new HttpException('The discount cannot exceed the quotation subtotal.', 422);
        }

        $grandTotal = $subtotal->subtract($discount)->add($deliveryCharge);

        // The tax lines must reconcile against the grand total, or the CHECK
        // constraint on bulk_order_quotes rejects the row. Recomputing the
        // split here keeps a discount or delivery charge from breaking it.
        $finalExtracted = $grandTotal->extractInclusiveTax(
            $priced[0]['gst_rate']
        );

        $taxableValue = $taxableTotal;
        $tax = $taxTotal;

        if (!$discount->isZero() || !$deliveryCharge->isZero()) {
            // With a discount or delivery charge in play the per-line extraction
            // no longer sums to the grand total, so the summary is re-derived
            // from the final figure at the dominant rate. Flagged rather than
            // hidden: a mixed-rate quote with a large discount needs a human to
            // confirm the split before it is sent.
            $taxableValue = $finalExtracted['net'];
            $tax = $finalExtracted['tax'];
        }

        $revision = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(`revision`), 0) + 1 FROM `bulk_order_quotes` WHERE `enquiry_id` = :id',
            ['id' => (int) $enquiry['id']]
        );

        $validityDays = max(1, $this->settings->intValue('bulk_quote_validity_days', 14));

        $quoteId = $this->db->transaction(function () use (
            $enquiry, $priced, $subtotal, $discount, $deliveryCharge, $taxableValue,
            $tax, $grandTotal, $revision, $validityDays, $terms, $request
        ): int {
            // Any earlier open quote is superseded, so a customer cannot accept
            // an old revision after a new one has been issued.
            $this->db->execute(
                "UPDATE `bulk_order_quotes`
                    SET `status` = 'superseded', `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `enquiry_id` = :id AND `status` IN ('draft','sent')",
                ['id' => (int) $enquiry['id']]
            );

            $id = (int) $this->db->insert(
                'INSERT INTO `bulk_order_quotes`
                     (`uuid`, `quote_number`, `enquiry_id`, `revision`, `items_subtotal`,
                      `discount_amount`, `delivery_charge`, `taxable_value`, `tax_total`,
                      `grand_total`, `valid_until`, `payment_terms`, `delivery_terms`,
                      `status`, `prepared_by`, `notes`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :number, :enquiry_id, :revision, :subtotal,
                      :discount, :delivery, :taxable, :tax, :grand_total,
                      :valid_until, :payment_terms, :delivery_terms,
                      \'draft\', :prepared_by, :notes,
                      :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'number' => $this->numbering->nextBulkQuoteNumber(),
                    'enquiry_id' => (int) $enquiry['id'],
                    'revision' => $revision,
                    'subtotal' => $subtotal->toDecimal(),
                    'discount' => $discount->toDecimal(),
                    'delivery' => $deliveryCharge->toDecimal(),
                    'taxable' => $taxableValue->toDecimal(),
                    'tax' => $tax->toDecimal(),
                    'grand_total' => $grandTotal->toDecimal(),
                    'valid_until' => date('Y-m-d', strtotime('+' . $validityDays . ' days')),
                    // BR-004 restated on the document the customer signs off.
                    'payment_terms' => $terms['payment_terms'] ?? '100% advance by UPI before dispatch',
                    'delivery_terms' => $terms['delivery_terms'] ?? null,
                    'prepared_by' => $request->authUserId(),
                    'notes' => $terms['notes'] ?? null,
                    'created_by' => $request->authUserId(),
                ]
            );

            foreach ($priced as $line) {
                $this->db->insert(
                    'INSERT INTO `bulk_order_quote_items`
                         (`uuid`, `quote_id`, `variant_id`, `description`, `sku`, `quantity`,
                          `unit_price`, `line_total`, `gst_rate`, `unit_weight_grams`,
                          `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                     VALUES
                         (:uuid, :quote_id, :variant_id, :description, :sku, :quantity,
                          :unit_price, :line_total, :gst_rate, :weight,
                          :created_by, NOW(), 1, 0, 1)',
                    [
                        'uuid' => Uuid::v4(),
                        'quote_id' => $id,
                        'variant_id' => $line['variant'] === null ? null : (int) $line['variant']['id'],
                        'description' => $line['description'],
                        'sku' => $line['sku'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price']->toDecimal(),
                        'line_total' => $line['line_total']->toDecimal(),
                        'gst_rate' => $line['gst_rate'],
                        'weight' => $line['unit_weight_grams'],
                        'created_by' => $request->authUserId(),
                    ]
                );
            }

            $this->db->execute(
                "UPDATE `bulk_order_enquiries`
                    SET `status` = 'quoted', `updated_by` = :actor, `updated_date` = NOW(),
                        `version` = `version` + 1
                  WHERE `id` = :id",
                ['actor' => $request->authUserId(), 'id' => (int) $enquiry['id']]
            );

            return $id;
        });

        $this->audit->log(
            entityName: 'bulk_order_quotes',
            entityId: $quoteId,
            action: 'create',
            newValues: [
                'enquiry' => $enquiry['enquiry_number'],
                'revision' => $revision,
                'grand_total' => $grandTotal->toDecimal(),
            ],
            request: $request,
        );

        return ['quote' => $this->presentQuote($this->requireQuoteById($quoteId))];
    }

    /** Sends a drafted quote to the customer. */
    public function sendQuote(Request $request, string $quoteUuid): array
    {
        $quote = $this->requireQuote($quoteUuid);

        if ($quote['status'] !== 'draft') {
            throw new HttpException('Only a draft quotation can be sent.', 409);
        }

        $this->db->execute(
            "UPDATE `bulk_order_quotes`
                SET `status` = 'sent', `sent_date` = NOW(), `updated_by` = :actor,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id",
            ['actor' => $request->authUserId(), 'id' => (int) $quote['id']]
        );

        return ['quote' => $this->presentQuote($this->requireQuoteById((int) $quote['id']))];
    }

    /**
     * Customer accepts a quotation, which creates a real order.
     *
     * @return array<string, mixed>
     */
    public function acceptQuote(Request $request, string $quoteUuid, string $addressUuid): array
    {
        $quote = $this->requireQuote($quoteUuid);
        $enquiry = $this->requireEnquiryById((int) $quote['enquiry_id']);

        if ($quote['status'] !== 'sent') {
            throw new HttpException(
                'Only a quotation that has been sent can be accepted. This one is ' . $quote['status'] . '.',
                409
            );
        }

        if (strtotime((string) $quote['valid_until']) < strtotime(date('Y-m-d'))) {
            throw new HttpException(
                sprintf('This quotation expired on %s. Please ask for a fresh one.', $quote['valid_until']),
                409
            );
        }

        $userId = (int) $request->authUserId();

        if ($enquiry['user_id'] !== null && (int) $enquiry['user_id'] !== $userId) {
            throw new NotFoundException('That quotation does not exist.');
        }

        $address = $this->db->selectOne(
            'SELECT * FROM `user_addresses` WHERE `uuid` = :uuid AND `user_id` = :user_id
               AND `is_deleted` = 0 LIMIT 1',
            ['uuid' => $addressUuid, 'user_id' => $userId]
        );

        if ($address === null) {
            throw new NotFoundException('That delivery address does not exist.');
        }

        $items = $this->db->select(
            'SELECT qi.*, v.`id` AS `variant_row_id`, v.`variant_name`,
                    v.`weight_grams`, p.`id` AS `product_row_id`, p.`name` AS `product_name`,
                    p.`brand`, p.`hsn_code`
               FROM `bulk_order_quote_items` qi
               LEFT JOIN `product_variants` v ON v.`id` = qi.`variant_id`
               LEFT JOIN `products` p ON p.`id` = v.`product_id`
              WHERE qi.`quote_id` = :quote_id AND qi.`is_deleted` = 0
              ORDER BY qi.`id`',
            ['quote_id' => (int) $quote['id']]
        );

        // A bespoke line has no catalogue variant, and an order line requires
        // one. Rather than inventing a placeholder product, the conversion is
        // refused with an explanation — a custom blend has to be added to the
        // catalogue before it can be sold, which is also what makes it
        // invoiceable and dispatchable.
        foreach ($items as $item) {
            if ($item['variant_id'] === null) {
                throw new HttpException(
                    sprintf(
                        'The line "%s" is not linked to a catalogue product, so this quotation '
                        . 'cannot be converted. Add the product to the catalogue and re-quote.',
                        $item['description']
                    ),
                    422,
                    ['items' => ['A bespoke line cannot become an order line.']]
                );
            }
        }

        $grandTotal = Money::fromDecimal((string) $quote['grand_total']);

        $placed = $this->db->transaction(function () use ($quote, $enquiry, $items, $address, $grandTotal, $userId, $request): array {
            $orderNumber = $this->numbering->nextOrderNumber();
            $serviceability = $this->delivery->checkServiceability((string) $address['pincode']);
            $expiresAt = date('Y-m-d H:i:s', strtotime(
                '+' . max(5, $this->settings->intValue('order_payment_window_minutes', 30)) . ' minutes'
            ));

            $totalWeight = 0;

            foreach ($items as $item) {
                $totalWeight += (int) ($item['unit_weight_grams'] ?: $item['weight_grams'] ?: 0)
                    * (int) $item['quantity'];
            }

            $orderId = $this->orders->create([
                'order_number' => $orderNumber,
                'user_id' => $userId,
                // Created and unpaid, exactly like a retail order. BR-005 then
                // governs everything that follows.
                'status' => OrderStatus::CREATED,
                'payment_status' => PaymentStatus::PENDING,
                'items_mrp_total' => (string) $quote['items_subtotal'],
                'items_subtotal' => (string) $quote['items_subtotal'],
                'order_discount' => (string) $quote['discount_amount'],
                'delivery_charge' => (string) $quote['delivery_charge'],
                'delivery_charge_before_waiver' => (string) $quote['delivery_charge'],
                'taxable_value' => (string) $quote['taxable_value'],
                'tax_total' => (string) $quote['tax_total'],
                'grand_total' => $grandTotal->toDecimal(),
                'wallet_applied' => '0.00',
                'amount_payable' => $grandTotal->toDecimal(),
                'total_savings' => (string) $quote['discount_amount'],
                'ship_name' => (string) $address['contact_name'],
                'ship_mobile' => (string) $address['contact_mobile'],
                'ship_address_line1' => (string) $address['address_line1'],
                'ship_address_line2' => $address['address_line2'],
                'ship_landmark' => $address['landmark'],
                'ship_city' => (string) $address['city'],
                'ship_state' => (string) $address['state'],
                'ship_pincode' => (string) $address['pincode'],
                'ship_country' => (string) ($address['country'] ?? 'India'),
                'source_address_id' => (int) $address['id'],
                'delivery_zone_code' => $serviceability['zone_code'] ?? null,
                'delivery_sla_min_days' => $serviceability['estimated_days']['min'] ?? null,
                'delivery_sla_max_days' => $serviceability['estimated_days']['max'] ?? null,
                'total_weight_grams' => $totalWeight,
                'customer_note' => sprintf(
                    'Wholesale order from quotation %s (enquiry %s, %s).',
                    $quote['quote_number'],
                    $enquiry['enquiry_number'],
                    $enquiry['business_name']
                ),
                'otp_verified' => 0,
                'placed_date' => date('Y-m-d H:i:s'),
                'expires_date' => $expiresAt,
                'placed_ip' => $request->ip,
                'placed_channel' => 'web',
            ], $userId);

            $lines = [];

            foreach ($items as $item) {
                $quantity = (int) $item['quantity'];
                $unitPrice = Money::fromDecimal((string) $item['unit_price']);
                $lineTotal = Money::fromDecimal((string) $item['line_total']);
                $gstRate = (float) $item['gst_rate'];
                $extracted = $lineTotal->extractInclusiveTax($gstRate);

                $line = [
                    'product_id' => (int) $item['product_row_id'],
                    'variant_id' => (int) $item['variant_id'],
                    'product_name' => (string) $item['product_name'],
                    'variant_name' => (string) $item['variant_name'],
                    'sku' => (string) ($item['sku'] ?? ''),
                    'brand' => $item['brand'],
                    'hsn_code' => $item['hsn_code'],
                    'quantity' => $quantity,
                    'unit_mrp' => $unitPrice->toDecimal(),
                    'unit_price' => $unitPrice->toDecimal(),
                    'line_mrp' => $lineTotal->toDecimal(),
                    'line_subtotal' => $lineTotal->toDecimal(),
                    'line_payable' => $lineTotal->toDecimal(),
                    'taxable_value' => $extracted['net']->toDecimal(),
                    'tax_amount' => $extracted['tax']->toDecimal(),
                    'gst_rate' => $gstRate,
                    'line_weight_grams' => (int) ($item['unit_weight_grams'] ?: $item['weight_grams'] ?: 0) * $quantity,
                ];

                $this->writer->writeItem($orderId, $line);
                $lines[] = $line;
            }

            // Same writer the retail path uses, so the GST split is identical.
            $this->writer->writeTaxLines(
                $orderId,
                $this->writer->bucketByRate($lines),
                (string) $address['state']
            );

            $this->orders->appendTimeline(
                orderId: $orderId,
                fromStatus: null,
                toStatus: OrderStatus::CREATED,
                title: 'Wholesale order placed',
                paymentStatus: PaymentStatus::PENDING,
                note: sprintf('Converted from quotation %s.', $quote['quote_number']),
                changedBy: $userId,
                changedByRole: 'customer',
            );

            $this->db->execute(
                "UPDATE `bulk_order_quotes`
                    SET `status` = 'accepted', `responded_date` = NOW(),
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id",
                ['id' => (int) $quote['id']]
            );

            $this->db->execute(
                "UPDATE `bulk_order_enquiries`
                    SET `status` = 'converted', `converted_order_id` = :order_id,
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id",
                ['order_id' => $orderId, 'id' => (int) $enquiry['id']]
            );

            return ['order_id' => $orderId, 'order_number' => $orderNumber];
        });

        $order = (array) $this->orders->findById($placed['order_id']);

        // BR-003 applies. A wholesale order is confirmed the same way as any
        // other.
        $challenge = null;

        if ($this->settings->boolValue('order_otp_required', true)) {
            $challenge = $this->otp->issue(
                (string) $order['ship_mobile'],
                OtpService::PURPOSE_ORDER_CONFIRMATION,
                $userId,
                $request
            );
        }

        $this->audit->log(
            entityName: 'orders',
            entityId: $placed['order_id'],
            action: 'convert_from_quote',
            newValues: [
                'order_number' => $placed['order_number'],
                'quote_number' => $quote['quote_number'],
                'grand_total' => $grandTotal->toDecimal(),
            ],
            request: $request,
        );

        return [
            'order' => [
                'uuid' => $order['uuid'],
                'order_number' => $order['order_number'],
                'status' => $order['status'],
                'payment_status' => $order['payment_status'],
                'grand_total' => (float) $order['grand_total'],
                'amount_payable' => (float) $order['amount_payable'],
                'expires_date' => $order['expires_date'],
            ],
            'otp' => $challenge === null ? null : [
                'required' => true,
                'reference_token' => $challenge['reference_token'] ?? null,
                'debug_otp' => $challenge['debug_otp'] ?? null,
            ],
            // The wholesale customer follows exactly the same steps from here.
            'next_step' => $challenge === null ? 'start_payment' : 'verify_otp',
        ];
    }

    /** Customer declines a quotation. */
    public function rejectQuote(Request $request, string $quoteUuid, string $reason): array
    {
        $quote = $this->requireQuote($quoteUuid);

        if ($quote['status'] !== 'sent') {
            throw new HttpException('Only a quotation that has been sent can be rejected.', 409);
        }

        $this->db->execute(
            "UPDATE `bulk_order_quotes`
                SET `status` = 'rejected', `responded_date` = NOW(), `rejection_reason` = :reason,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id",
            ['reason' => $reason, 'id' => (int) $quote['id']]
        );

        $this->db->execute(
            "UPDATE `bulk_order_enquiries`
                SET `status` = 'negotiating', `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id",
            ['id' => (int) $quote['enquiry_id']]
        );

        return ['rejected' => true, 'reason' => $reason];
    }

    /** Staff decline an enquiry outright. */
    public function declineEnquiry(Request $request, string $enquiryUuid, string $reason): array
    {
        $enquiry = $this->requireEnquiry($enquiryUuid);

        if ($enquiry['status'] === 'converted') {
            throw new HttpException('This enquiry has already become an order.', 409);
        }

        $this->db->execute(
            "UPDATE `bulk_order_enquiries`
                SET `status` = 'declined', `decline_reason` = :reason, `updated_by` = :actor,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id",
            ['reason' => $reason, 'actor' => $request->authUserId(), 'id' => (int) $enquiry['id']]
        );

        return ['declined' => true, 'reason' => $reason];
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function listForStaff(array $params, ?string $status): array
    {
        $where = ['`is_deleted` = 0'];
        $bindings = [];

        if ($status !== null) {
            $where[] = '`status` = :status';
            $bindings['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `bulk_order_enquiries` WHERE {$whereSql}",
            $bindings
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $rows = $this->db->select(
            sprintf(
                'SELECT * FROM `bulk_order_enquiries` WHERE %s ORDER BY `created_date` %s LIMIT %d OFFSET %d',
                $whereSql,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return [
            'items' => array_map(fn (array $row): array => $this->presentEnquiry($row, customerView: false), $rows),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function showEnquiry(Request $request, string $uuid, bool $isStaff): array
    {
        $enquiry = $this->requireEnquiry($uuid);

        if (!$isStaff) {
            $userId = $request->authUserId();

            if ($enquiry['user_id'] === null || (int) $enquiry['user_id'] !== (int) $userId) {
                throw new NotFoundException('That enquiry does not exist.');
            }
        }

        $quotes = $this->db->select(
            'SELECT * FROM `bulk_order_quotes` WHERE `enquiry_id` = :id AND `is_deleted` = 0
              ORDER BY `revision`',
            ['id' => (int) $enquiry['id']]
        );

        return [
            'enquiry' => $this->presentEnquiry($enquiry, !$isStaff),
            'quotes' => array_map(fn (array $quote): array => $this->presentQuote($quote), $quotes),
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function requireEnquiry(string $uuid): array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM `bulk_order_enquiries` WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $uuid]
        );

        if ($row === null) {
            throw new NotFoundException('That enquiry does not exist.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function requireEnquiryById(int $id): array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM `bulk_order_enquiries` WHERE `id` = :id',
            ['id' => $id]
        );

        if ($row === null) {
            throw new NotFoundException('That enquiry does not exist.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function requireQuote(string $uuid): array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM `bulk_order_quotes` WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $uuid]
        );

        if ($row === null) {
            throw new NotFoundException('That quotation does not exist.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function requireQuoteById(int $id): array
    {
        $row = $this->db->selectOne('SELECT * FROM `bulk_order_quotes` WHERE `id` = :id', ['id' => $id]);

        if ($row === null) {
            throw new NotFoundException('That quotation does not exist.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $enquiry
     *
     * @return array<string, mixed>
     */
    private function presentEnquiry(array $enquiry, bool $customerView): array
    {
        $view = [
            'uuid' => $enquiry['uuid'],
            'enquiry_number' => $enquiry['enquiry_number'],
            'business_name' => $enquiry['business_name'],
            'gstin' => $enquiry['gstin'],
            'contact_name' => $enquiry['contact_name'],
            'contact_mobile' => $enquiry['contact_mobile'],
            'requirements' => $enquiry['requirements'],
            'expected_delivery_date' => $enquiry['expected_delivery_date'],
            'estimated_quantity' => $enquiry['estimated_quantity'],
            'status' => $enquiry['status'],
            'created_date' => $enquiry['created_date'],
        ];

        if ($customerView) {
            return $view;
        }

        // Internal notes and the estimated budget stay on the staff side: a
        // customer should not see what we guessed they could afford.
        return $view + [
            'contact_email' => $enquiry['contact_email'],
            'delivery_pincode' => $enquiry['delivery_pincode'],
            'estimated_budget' => $enquiry['estimated_budget'] === null
                ? null
                : (float) $enquiry['estimated_budget'],
            'internal_notes' => $enquiry['internal_notes'],
            'decline_reason' => $enquiry['decline_reason'],
            'source' => $enquiry['source'],
        ];
    }

    /**
     * @param array<string, mixed> $quote
     *
     * @return array<string, mixed>
     */
    private function presentQuote(array $quote): array
    {
        $items = $this->db->select(
            'SELECT * FROM `bulk_order_quote_items` WHERE `quote_id` = :id AND `is_deleted` = 0 ORDER BY `id`',
            ['id' => (int) $quote['id']]
        );

        return [
            'uuid' => $quote['uuid'],
            'quote_number' => $quote['quote_number'],
            'revision' => (int) $quote['revision'],
            'status' => $quote['status'],
            'items_subtotal' => (float) $quote['items_subtotal'],
            'discount_amount' => (float) $quote['discount_amount'],
            'delivery_charge' => (float) $quote['delivery_charge'],
            'taxable_value' => (float) $quote['taxable_value'],
            'tax_total' => (float) $quote['tax_total'],
            'grand_total' => (float) $quote['grand_total'],
            'valid_until' => $quote['valid_until'],
            'is_expired' => strtotime((string) $quote['valid_until']) < strtotime(date('Y-m-d')),
            'payment_terms' => $quote['payment_terms'],
            'delivery_terms' => $quote['delivery_terms'],
            'notes' => $quote['notes'],
            'sent_date' => $quote['sent_date'],
            'items' => array_map(static fn (array $item): array => [
                'description' => $item['description'],
                'sku' => $item['sku'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'line_total' => (float) $item['line_total'],
                'gst_rate' => (float) $item['gst_rate'],
                'is_catalogue_item' => $item['variant_id'] !== null,
            ], $items),
        ];
    }
}
