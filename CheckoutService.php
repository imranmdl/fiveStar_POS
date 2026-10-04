<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\CartRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SettingRepository;
use App\Repositories\AddressRepository;
use App\Repositories\CouponRepository;
use App\Services\Orders\GstApportioner;
use App\Services\Orders\NumberingService;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\PaymentStatus;
use App\Helpers\Uuid;

/**
 * Turns a cart into an order.
 *
 * The whole of placement is one database transaction, and it has to be: it
 * consumes a coupon use, debits wallet credit, takes an order number from a
 * gapless sequence and writes a dozen rows. Any of those succeeding while
 * another fails would leave a customer charged for an order that does not
 * exist, or a coupon burned on nothing.
 *
 * Three rules shape the design:
 *
 *   BR-003  The order is created unconfirmed. It cannot be confirmed until an
 *           OTP has been verified — see OrderStateMachine.
 *   BR-004  Only a prepaid UPI tender is produced. There is no code path here
 *           that could create a cash-on-delivery order.
 *   BR-005  Placement never marks anything paid. Payment is confirmed only by a
 *           signature-verified gateway signal, in PaymentService.
 *
 * And one principle: THE CART IS RE-PRICED AT PLACEMENT. Prices, offers and
 * wallet balances all move while a customer is deciding. The order is built
 * from a fresh quote, then compared against what the customer confirmed; if it
 * has changed, placement is refused and the new figure returned, rather than
 * silently charging a different amount.
 */
final class CheckoutService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CartRepository $carts,
        private readonly OrderRepository $orders,
        private readonly AddressRepository $addresses,
        private readonly CouponRepository $couponRows,
        private readonly CouponService $coupons,
        private readonly WalletService $wallet,
        private readonly OtpService $otp,
        private readonly NumberingService $numbering,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Everything the checkout screen needs: addresses, the priced cart, the
     * tender split and any blockers.
     *
     * @return array<string, mixed>
     */
    public function review(Request $request, ?string $addressUuid = null): array
    {
        $userId = (int) $request->authUserId();
        $addresses = $this->addresses->forUser($userId);

        $selected = null;

        if ($addressUuid !== null) {
            $selected = $this->requireAddress($userId, $addressUuid);
        } else {
            foreach ($addresses as $address) {
                if ((int) $address['is_default'] === 1) {
                    $selected = $address;

                    break;
                }
            }

            $selected ??= $addresses[0] ?? null;
        }

        $cart = $this->cart->view($request, $selected['pincode'] ?? null);

        $blockers = $cart['checkout']['blockers'];

        if ($selected === null) {
            $blockers[] = 'Add a delivery address to continue.';
        }

        return [
            'addresses' => array_map([$this, 'presentAddress'], $addresses),
            'selected_address' => $selected === null ? null : $this->presentAddress($selected),
            'cart' => $cart,
            'checkout' => [
                'is_ready' => $blockers === [],
                'blockers' => $blockers,
                // BR-003 and BR-004, stated so a client never has to infer them.
                'otp_required' => $this->otpRequired(),
                'payment_modes' => ['upi'],
                'prepaid_only' => true,
                'payment_window_minutes' => $this->paymentWindowMinutes(),
            ],
        ];
    }

    /**
     * Places the order.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function place(Request $request, array $data): array
    {
        $userId = (int) $request->authUserId();
        $address = $this->requireAddress($userId, (string) $data['address_uuid']);

        $resolved = $this->cart->resolveCart($request, createIfMissing: false);
        $cartRow = $resolved['cart'];

        // Re-price against the delivery address, not whatever pincode the cart
        // happened to be carrying. Delivery charge depends on destination, and
        // the customer may have changed address since the last quote.
        $view = $this->cart->view($request, (string) $address['pincode']);

        if (!$view['checkout']['is_ready']) {
            throw new HttpException(
                'This order cannot be placed yet.',
                422,
                ['checkout' => $view['checkout']['blockers']]
            );
        }

        if (!(bool) $view['reconciles']) {
            // The engine's own arithmetic check failed. Refusing to take money
            // is the only safe response.
            $this->logger->error('Cart totals did not reconcile at placement', [
                'cart_uuid' => $cartRow['uuid'],
                'user_id' => $userId,
            ], 'checkout');

            throw new HttpException(
                'We could not calculate this order reliably. Please contact support.',
                500
            );
        }

        $this->assertQuotedTotalStillValid($view, $data);

        $pricing = $view['pricing'];
        $payment = $view['payment'];
        $promotions = $view['promotions'];

        $grandTotal = Money::fromDecimal((string) $pricing['summary']['grand_total']);
        $walletApplied = Money::fromDecimal((string) $payment['wallet_applied']);
        $amountPayable = Money::fromDecimal((string) $payment['amount_payable']);

        if (!$amountPayable->isPositive() && !$walletApplied->isPositive()) {
            throw new HttpException('An order must have something to pay.', 422);
        }

        $placed = $this->db->transaction(function () use (
            $request, $userId, $address, $cartRow, $view, $pricing, $payment,
            $promotions, $grandTotal, $walletApplied, $amountPayable, $data
        ): array {
            $orderNumber = $this->numbering->nextOrderNumber();
            $delivery = $pricing['delivery'];
            $summary = $pricing['summary'];

            $couponId = null;
            $couponCode = null;
            $couponDiscount = Money::zero();

            if (($promotions['applied_coupon'] ?? null) !== null) {
                $couponRow = $this->couponRows->findByCode((string) $promotions['applied_coupon']['code']);

                if ($couponRow === null) {
                    throw new HttpException('The coupon on this cart no longer exists.', 409);
                }

                $couponId = (int) $couponRow['id'];
                $couponCode = (string) $couponRow['code'];
                $couponDiscount = Money::fromDecimal((string) $promotions['applied_coupon']['discount_amount']);
            }

            $offerCode = $promotions['applied_offer']['code'] ?? null;
            $offerDiscount = Money::fromDecimal(
                (string) ($promotions['applied_offer']['discount_amount'] ?? 0)
            );

            $expiresAt = date(
                'Y-m-d H:i:s',
                strtotime('+' . $this->paymentWindowMinutes() . ' minutes')
            );

            $expectedDelivery = null;

            if (($delivery['estimated_days']['max'] ?? 0) > 0) {
                $expectedDelivery = date('Y-m-d', strtotime('+' . (int) $delivery['estimated_days']['max'] . ' days'));
            }

            $orderId = $this->orders->create([
                'order_number' => $orderNumber,
                'user_id' => $userId,
                'cart_id' => (int) $cartRow['id'],
                // Created, never paid. BR-005: only a verified gateway signal
                // moves payment_status away from pending.
                'status' => OrderStatus::CREATED,
                'payment_status' => PaymentStatus::PENDING,
                'currency_code' => (string) $cartRow['currency_code'],

                'items_mrp_total' => (string) $summary['items_mrp_total'],
                'items_subtotal' => (string) $summary['items_subtotal'],
                'product_discount' => (string) $summary['product_discount'],
                'order_discount' => (string) $summary['order_discount'],
                'order_surcharge' => (string) $summary['order_surcharge'],
                'delivery_charge' => (string) $summary['delivery_charge'],
                'delivery_charge_before_waiver' => (string) $summary['delivery_charge_before_waiver'],
                'delivery_discount' => (string) $summary['delivery_discount'],
                'taxable_value' => (string) $summary['taxable_value'],
                'tax_total' => (string) $summary['tax_total'],
                'grand_total' => (string) $grandTotal,
                'wallet_applied' => (string) $walletApplied,
                'amount_payable' => (string) $amountPayable,
                'total_savings' => (string) $summary['total_savings'],

                'coupon_id' => $couponId,
                'coupon_code' => $couponCode,
                'coupon_discount' => (string) $couponDiscount,
                'offer_code' => $offerCode,
                'offer_discount' => (string) $offerDiscount,

                'ship_name' => (string) $address['contact_name'],
                'ship_mobile' => (string) $address['contact_mobile'],
                'ship_alternate_mobile' => null,
                'ship_address_line1' => (string) $address['address_line1'],
                'ship_address_line2' => $address['address_line2'] ?? null,
                'ship_landmark' => $address['landmark'] ?? null,
                'ship_city' => (string) $address['city'],
                'ship_state' => (string) $address['state'],
                'ship_pincode' => (string) $address['pincode'],
                'ship_country' => (string) ($address['country'] ?? 'India'),
                'source_address_id' => (int) $address['id'],

                'delivery_zone_code' => $delivery['zone_code'] ?? null,
                'delivery_sla_min_days' => $delivery['estimated_days']['min'] ?? null,
                'delivery_sla_max_days' => $delivery['estimated_days']['max'] ?? null,
                'expected_delivery_date' => $expectedDelivery,
                'delivery_slot' => $data['delivery_slot'] ?? null,
                'delivery_instructions' => $data['delivery_instructions'] ?? null,
                'total_weight_grams' => (int) $summary['total_weight_grams'],

                'is_gift' => (int) ($data['is_gift'] ?? 0),
                'gift_message' => $data['gift_message'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,

                'otp_verified' => 0,
                'placed_date' => date('Y-m-d H:i:s'),
                'expires_date' => $expiresAt,
                'placed_ip' => $request->ip,
                'placed_channel' => $data['channel'] ?? 'web',
            ], $userId);

            $this->writeItems($orderId, $view['items'], $pricing['lines']);
            $this->writeTaxLines($orderId, $pricing['tax_breakdown'], (string) $address['state']);

            // --- Phase 4 integration hooks -------------------------------
            // Coupon usage is consumed HERE, at placement, not when the code was
            // typed into the cart. An abandoned cart must never burn a
            // limited-use coupon.
            if ($couponId !== null) {
                $this->coupons->redeem(
                    couponId: $couponId,
                    userId: $userId,
                    orderReference: $orderNumber,
                    discountAmount: $couponDiscount,
                    orderValue: $grandTotal,
                    cartId: (int) $cartRow['id'],
                    request: $request,
                );
            }

            // Wallet credit is debited at placement, not at payment. Otherwise
            // two orders placed minutes apart could each be quoted the same
            // balance and both spend it. Cancellation and expiry post a
            // compensating credit back.
            if ($walletApplied->isPositive()) {
                $this->wallet->debit(
                    userId: $userId,
                    amount: $walletApplied,
                    source: WalletService::SOURCE_REDEMPTION,
                    narration: 'Applied to order ' . $orderNumber,
                    idempotencyKey: 'order:' . $orderNumber . ':wallet',
                    referenceType: 'orders',
                    referenceId: $orderNumber,
                    request: $request,
                );
            }

            $this->orders->appendTimeline(
                orderId: $orderId,
                fromStatus: null,
                toStatus: OrderStatus::CREATED,
                title: 'Order placed',
                paymentStatus: PaymentStatus::PENDING,
                note: sprintf('Awaiting payment of %s.', $amountPayable->format()),
                changedBy: $userId,
                changedByRole: 'customer',
            );

            // The cart is marked converted rather than emptied, so support can
            // still see exactly what was ordered and from which cart.
            $this->carts->update((int) $cartRow['id'], [
                'status' => 'converted',
                'converted_order_id' => $orderId,
            ], $userId);

            return ['order_id' => $orderId, 'order_number' => $orderNumber];
        });

        $this->audit->log(
            entityName: 'orders',
            entityId: $placed['order_id'],
            action: 'place',
            newValues: [
                'order_number' => $placed['order_number'],
                'grand_total' => $grandTotal->toDecimal(),
                'wallet_applied' => $walletApplied->toDecimal(),
                'amount_payable' => $amountPayable->toDecimal(),
            ],
            request: $request,
        );

        $order = (array) $this->orders->findById($placed['order_id']);

        // BR-003: issue the confirmation OTP straight away so the customer is
        // not left wondering what happens next.
        $challenge = null;

        if ($this->otpRequired()) {
            $challenge = $this->otp->issue(
                (string) $order['ship_mobile'],
                OtpService::PURPOSE_ORDER_CONFIRMATION,
                $userId,
                $request
            );
        }

        return [
            'order' => $this->presentPlacement($order),
            'otp' => $challenge === null ? null : [
                'required' => true,
                'reference_token' => $challenge['reference_token'] ?? null,
                'expires_in_seconds' => $challenge['expires_in_seconds'] ?? null,
                'sent_to' => $this->maskMobile((string) $order['ship_mobile']),
                'debug_otp' => $challenge['debug_otp'] ?? null,
            ],
            'next_step' => $this->otpRequired() ? 'verify_otp' : 'start_payment',
        ];
    }

    /**
     * BR-003: verifies the order OTP. Until this succeeds the order cannot be
     * confirmed, whatever the payment does.
     *
     * @return array<string, mixed>
     */
    public function verifyOtp(Request $request, string $orderUuid, string $otp, ?string $referenceToken): array
    {
        $userId = (int) $request->authUserId();
        $order = $this->requireOwnedOrder($orderUuid, $userId);

        if ((int) $order['otp_verified'] === 1) {
            return ['order' => $this->presentPlacement($order), 'already_verified' => true];
        }

        if (!in_array($order['status'], [OrderStatus::CREATED, OrderStatus::AWAITING_PAYMENT], true)) {
            throw new HttpException(
                'This order can no longer be verified; it is ' . OrderStatus::label((string) $order['status']) . '.',
                409
            );
        }

        $this->otp->verify(
            (string) $order['ship_mobile'],
            OtpService::PURPOSE_ORDER_CONFIRMATION,
            $otp,
            $referenceToken
        );

        $this->orders->update((int) $order['id'], [
            'otp_verified' => 1,
            'otp_verified_date' => date('Y-m-d H:i:s'),
        ], $userId);

        $this->orders->appendTimeline(
            orderId: (int) $order['id'],
            fromStatus: (string) $order['status'],
            toStatus: (string) $order['status'],
            title: 'Order verified by OTP',
            paymentStatus: (string) $order['payment_status'],
            note: 'BR-003 satisfied. The order can be confirmed once payment is received.',
            changedBy: $userId,
            changedByRole: 'customer',
        );

        $this->audit->log(
            entityName: 'orders',
            entityId: (int) $order['id'],
            action: 'otp_verified',
            request: $request,
            entityUuid: $orderUuid,
        );

        return [
            'order' => $this->presentPlacement((array) $this->orders->findById((int) $order['id'])),
            'already_verified' => false,
            'next_step' => 'start_payment',
        ];
    }

    /** Re-sends the order OTP, subject to the same throttling as any other. */
    public function resendOtp(Request $request, string $orderUuid): array
    {
        $userId = (int) $request->authUserId();
        $order = $this->requireOwnedOrder($orderUuid, $userId);

        if ((int) $order['otp_verified'] === 1) {
            throw new HttpException('This order has already been verified.', 409);
        }

        $challenge = $this->otp->issue(
            (string) $order['ship_mobile'],
            OtpService::PURPOSE_ORDER_CONFIRMATION,
            $userId,
            $request
        );

        return [
            'reference_token' => $challenge['reference_token'] ?? null,
            'expires_in_seconds' => $challenge['expires_in_seconds'] ?? null,
            'sent_to' => $this->maskMobile((string) $order['ship_mobile']),
            'debug_otp' => $challenge['debug_otp'] ?? null,
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Refuses placement if the total moved since the customer confirmed it.
     *
     * The client sends back the grand total it displayed. If a price changed or
     * an offer expired in the meantime, the customer is shown the new figure
     * and asked again rather than being charged something they never agreed to.
     *
     * @param array<string, mixed> $view
     * @param array<string, mixed> $data
     */
    private function assertQuotedTotalStillValid(array $view, array $data): void
    {
        if (!isset($data['expected_grand_total'])) {
            return;
        }

        $expected = Money::fromDecimal((string) $data['expected_grand_total']);
        $actual = Money::fromDecimal((string) $view['pricing']['summary']['grand_total']);

        if ($expected->equals($actual)) {
            return;
        }

        throw new HttpException(
            sprintf(
                'The total changed from %s to %s while you were checking out. Please review and confirm again.',
                $expected->format(),
                $actual->format()
            ),
            409,
            [
                'expected_grand_total' => [$expected->toDecimal()],
                'current_grand_total' => [$actual->toDecimal()],
                'price_changes' => $view['price_changes'],
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $pricedLines
     */
    private function writeItems(int $orderId, array $items, array $pricedLines): void
    {
        $byReference = [];

        foreach ($pricedLines as $line) {
            $byReference[$line['reference']] = $line;
        }

        foreach ($items as $item) {
            $line = $byReference[$item['uuid']] ?? null;

            if ($line === null) {
                throw new HttpException('An item could not be priced for this order.', 500);
            }

            $this->db->insert(
                'INSERT INTO `order_items`
                     (`uuid`, `order_id`, `product_id`, `variant_id`, `product_name`, `variant_name`,
                      `sku`, `brand`, `quantity`, `unit_mrp`, `unit_price`, `line_mrp`, `line_subtotal`,
                      `product_discount`, `apportioned_discount`, `line_payable`, `taxable_value`,
                      `tax_amount`, `gst_rate`, `line_weight_grams`, `is_gift`, `gift_message`,
                      `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :order_id, :product_id, :variant_id, :product_name, :variant_name,
                      :sku, :brand, :quantity, :unit_mrp, :unit_price, :line_mrp, :line_subtotal,
                      :product_discount, :apportioned_discount, :line_payable, :taxable_value,
                      :tax_amount, :gst_rate, :line_weight_grams, :is_gift, :gift_message,
                      NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'order_id' => $orderId,
                    'product_id' => $this->productIdFor((string) $item['product']['uuid']),
                    'variant_id' => $this->variantIdFor((string) $item['variant']['uuid']),
                    'product_name' => (string) $item['product']['name'],
                    'variant_name' => (string) $item['variant']['name'],
                    'sku' => (string) $item['variant']['sku'],
                    'brand' => $item['product']['brand'] ?? null,
                    'quantity' => (int) $item['quantity'],
                    'unit_mrp' => (string) $line['unit_mrp'],
                    'unit_price' => (string) $line['unit_price'],
                    'line_mrp' => (string) $line['line_mrp'],
                    'line_subtotal' => (string) $line['line_subtotal'],
                    'product_discount' => (string) $line['product_discount'],
                    'apportioned_discount' => (string) $line['apportioned_discount'],
                    'line_payable' => (string) $line['line_payable'],
                    'taxable_value' => (string) $line['taxable_value'],
                    'tax_amount' => (string) $line['tax_amount'],
                    'gst_rate' => (string) $line['gst_rate'],
                    'line_weight_grams' => (int) $line['line_weight_grams'],
                    'is_gift' => (int) $item['is_gift'],
                    'gift_message' => $item['gift_message'] ?? null,
                ]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $taxBreakdown
     */
    private function writeTaxLines(int $orderId, array $taxBreakdown, string $buyerState): void
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

    private function productIdFor(string $uuid): int
    {
        $id = $this->db->scalar('SELECT `id` FROM `products` WHERE `uuid` = :uuid LIMIT 1', ['uuid' => $uuid]);

        if ($id === null) {
            throw new HttpException('A product in this order no longer exists.', 409);
        }

        return (int) $id;
    }

    private function variantIdFor(string $uuid): int
    {
        $id = $this->db->scalar('SELECT `id` FROM `product_variants` WHERE `uuid` = :uuid LIMIT 1', ['uuid' => $uuid]);

        if ($id === null) {
            throw new HttpException('A pack size in this order no longer exists.', 409);
        }

        return (int) $id;
    }

    /** @return array<string, mixed> */
    private function requireAddress(int $userId, string $uuid): array
    {
        $address = $this->addresses->findByUuid($uuid);

        if ($address === null || (int) $address['user_id'] !== $userId) {
            throw new NotFoundException('That delivery address does not exist.');
        }

        return $address;
    }

    /** @return array<string, mixed> */
    private function requireOwnedOrder(string $uuid, int $userId): array
    {
        $order = $this->orders->findByUuid($uuid);

        if ($order === null || (int) $order['user_id'] !== $userId) {
            throw new NotFoundException('That order does not exist.');
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $address
     *
     * @return array<string, mixed>
     */
    private function presentAddress(array $address): array
    {
        return [
            'uuid' => $address['uuid'],
            'label' => $address['label'],
            'contact_name' => $address['contact_name'],
            'contact_mobile' => $address['contact_mobile'],
            'address_line1' => $address['address_line1'],
            'address_line2' => $address['address_line2'],
            'landmark' => $address['landmark'],
            'city' => $address['city'],
            'district' => $address['district'],
            'address_type' => $address['address_type'],
            'state' => $address['state'],
            'pincode' => $address['pincode'],
            'country' => $address['country'],
            'is_default' => (bool) $address['is_default'],
        ];
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function presentPlacement(array $order): array
    {
        return [
            'uuid' => $order['uuid'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'status_label' => OrderStatus::label((string) $order['status']),
            'payment_status' => $order['payment_status'],
            'grand_total' => (float) $order['grand_total'],
            'wallet_applied' => (float) $order['wallet_applied'],
            'amount_payable' => (float) $order['amount_payable'],
            'otp_verified' => (bool) $order['otp_verified'],
            'expires_date' => $order['expires_date'],
            'placed_date' => $order['placed_date'],
        ];
    }

    private function maskMobile(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile) ?? '';

        return strlen($digits) < 4
            ? '****'
            : substr($digits, 0, 2) . str_repeat('X', max(0, strlen($digits) - 4)) . substr($digits, -2);
    }

    private function otpRequired(): bool
    {
        return $this->settings->boolValue('order_otp_required', true);
    }

    private function paymentWindowMinutes(): int
    {
        return max(5, $this->settings->intValue('order_payment_window_minutes', 30));
    }
}
