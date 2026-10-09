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
use App\Repositories\CouponRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SettingRepository;
use App\Services\Orders\NumberingService;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\PaymentStatus;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\PaymentVerification;

/**
 * Payment initiation and confirmation.
 *
 * BR-005 lives at one line in this file: an order's payment_status only becomes
 * 'paid' when a PaymentVerification arrives with signatureVerified === true AND
 * an amount that matches what was owed. Nothing else in the codebase writes
 * that value.
 *
 * That matters because the alternative is common and quietly disastrous:
 * trusting the client's "payment succeeded" callback. Anyone can POST that.
 * Here the callback is only ever a hint that prompts a signature check, and the
 * webhook is the authority.
 *
 * Confirmation is idempotent at three levels, because gateways retry, deliver
 * out of order, and sometimes deliver the webhook before the browser redirect:
 *   1. payment_events has a UNIQUE key on (gateway, event_id).
 *   2. payments has a UNIQUE key on (gateway, gateway_payment_id).
 *   3. The order row is locked FOR UPDATE and re-read before any transition.
 */
final class PaymentService
{
    /** Written by expireUnpaidOrders(); also how a late-paid order is recognised. */
    public const EXPIRY_CANCELLATION_REASON = 'Payment was not completed within the allowed window.';

    /**
     * How long an order with a manual UPI payment awaiting staff review is
     * kept open past its payment window (setting: manual_payment_review_hours).
     */
    public const MANUAL_REVIEW_HOURS = 72;

    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly CouponRepository $couponRows,
        private readonly CouponService $coupons,
        private readonly WalletService $wallet,
        private readonly ReferralService $referrals,
        private readonly OrderStateMachine $stateMachine,
        private readonly NumberingService $numbering,
        private readonly SettingRepository $settings,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly InventoryService $inventory,
        private readonly LoyaltyService $loyalty,
    ) {
    }

    public function gatewayName(): string
    {
        return $this->gateway->name();
    }

    /**
     * Starts a payment attempt and returns what the client needs to pay.
     *
     * @return array<string, mixed>
     */
    public function start(Request $request, string $orderUuid): array
    {
        $userId = (int) $request->authUserId();
        $order = $this->requireOwnedOrder($orderUuid, $userId);

        if (PaymentStatus::isSettled((string) $order['payment_status'])) {
            throw new HttpException('This order has already been paid for.', 409);
        }

        if (in_array($order['status'], [OrderStatus::CANCELLED, OrderStatus::REFUNDED], true)) {
            throw new HttpException('This order has been cancelled.', 409);
        }

        // BR-003 is checked here as well as in the state machine, so a customer
        // is told what to do BEFORE being sent to a payment app rather than
        // after paying.
        if ($this->otpRequired() && (int) $order['otp_verified'] !== 1) {
            throw new HttpException(
                'Verify this order with the OTP we sent you before paying.',
                409,
                ['otp' => ['Order OTP verification is required first.']]
            );
        }

        if ($order['expires_date'] !== null && strtotime((string) $order['expires_date']) < time()) {
            throw new HttpException(
                'The payment window for this order has closed. Please place it again.',
                409
            );
        }

        $amountPayable = Money::fromDecimal((string) $order['amount_payable']);

        if (!$amountPayable->isPositive()) {
            // Fully covered by wallet credit. There is nothing for the gateway
            // to do, so confirm it directly — the money has already moved.
            return $this->confirmFullyWalletPaid($order, $request);
        }

        $intent = $this->gateway->createIntent(
            $amountPayable,
            (string) $order['currency_code'],
            (string) $order['order_number'],
            [
                'order_number' => (string) $order['order_number'],
                'merchant_name' => (string) $this->config->get('app.brand_name', '5 Star Spices & Dry Fruits'),
                'customer_mobile' => (string) $order['ship_mobile'],
            ]
        );

        $paymentId = $this->db->transaction(function () use ($order, $userId, $amountPayable, $intent): int {
            $attempt = $this->payments->nextAttemptNumber((int) $order['id']);

            $id = $this->payments->create([
                'order_id' => (int) $order['id'],
                'user_id' => $userId,
                'gateway' => $this->gateway->name(),
                'gateway_order_id' => $intent->gatewayOrderId,
                'attempt_number' => $attempt,
                'amount' => (string) $amountPayable,
                'currency_code' => (string) $order['currency_code'],
                'status' => 'created',
                'method' => 'upi',
                'expires_date' => $intent->expiresInSeconds === null
                    ? null
                    : date('Y-m-d H:i:s', time() + $intent->expiresInSeconds),
                'checkout_payload' => json_encode($intent->toArray()),
            ], $userId);

            if ($order['status'] === OrderStatus::CREATED) {
                $this->transition(
                    (int) $order['id'],
                    OrderStatus::CREATED,
                    OrderStatus::AWAITING_PAYMENT,
                    'Payment started',
                    (string) $order['payment_status'],
                    sprintf('Awaiting %s by UPI.', $amountPayable->format()),
                    $userId,
                    'customer'
                );
            }

            return $id;
        });

        $this->logger->info('Payment intent created', [
            'order_number' => $order['order_number'],
            'gateway' => $this->gateway->name(),
            'gateway_order_id' => $intent->gatewayOrderId,
            'amount' => $amountPayable->toDecimal(),
        ], 'payment');

        return [
            'payment' => $intent->toArray(),
            // Filled into the payment screen so the customer doesn't retype them.
            'prefill' => [
                'name' => (string) ($order['ship_name'] ?? ''),
                'contact' => (string) ($order['ship_mobile'] ?? ''),
            ],
            'payment_uuid' => (string) ($this->payments->findById($paymentId)['uuid'] ?? ''),
            'order' => [
                'uuid' => $order['uuid'],
                'order_number' => $order['order_number'],
                'amount_payable' => $amountPayable->toDecimal(),
            ],
        ];
    }

    /**
     * Handles a client-side return from the payment app.
     *
     * Treated as a HINT, never as proof. The signature is checked, and on
     * Razorpay the gateway is then queried directly, so a forged callback
     * cannot confirm anything.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function handleCallback(Request $request, string $orderUuid, array $payload): array
    {
        $userId = (int) $request->authUserId();
        $order = $this->requireOwnedOrder($orderUuid, $userId);

        $verification = $this->gateway->verifyCallback($payload);

        if (!$verification->signatureVerified) {
            $this->logger->warning('Rejected an unverified payment callback', [
                'order_number' => $order['order_number'],
                'reason' => $verification->failureReason,
            ], 'payment');

            throw new HttpException(
                'This payment could not be verified. If money has left your account it will be '
                . 'reconciled automatically within a few minutes.',
                422
            );
        }

        $this->applyVerification($order, $verification, $request, 'callback');

        $fresh = (array) $this->orders->findById((int) $order['id']);

        return [
            'order' => [
                'uuid' => $fresh['uuid'],
                'order_number' => $fresh['order_number'],
                'status' => $fresh['status'],
                'status_label' => OrderStatus::label((string) $fresh['status']),
                'payment_status' => $fresh['payment_status'],
                'invoice_number' => $fresh['invoice_number'],
            ],
            'payment' => $verification->toArray(),
        ];
    }

    /**
     * Confirms or rejects a manual payment on an administrator's authority.
     *
     * This is the only way a manual-gateway order reaches PAID: there is no
     * webhook and no signature to check, so the PaymentVerification an admin
     * action produces goes through exactly the same applyVerification() choke
     * point as a Razorpay webhook does. Nothing about BR-005 is relaxed for
     * the other gateways by this method existing — it only ever runs against
     * whichever PaymentVerification ManualPaymentService hands it, and that
     * verification can only be minted by ManualGateway::verifyByAdmin(), which
     * ManualPaymentService only calls after checking the caller is an
     * authenticated administrator.
     *
     * @return array<string, mixed>
     */
    public function applyAdminVerification(
        array $order,
        PaymentVerification $verification,
        Request $request,
        ?int $paymentId = null,
    ): array {
        $this->applyVerification($order, $verification, $request, 'admin_manual', $paymentId);

        $fresh = (array) $this->orders->findById((int) $order['id']);

        return [
            'order' => [
                'uuid' => $fresh['uuid'],
                'order_number' => $fresh['order_number'],
                'status' => $fresh['status'],
                'status_label' => OrderStatus::label((string) $fresh['status']),
                'payment_status' => $fresh['payment_status'],
                'invoice_number' => $fresh['invoice_number'],
            ],
            'payment' => $verification->toArray(),
        ];
    }

    /**
     * Handles an inbound webhook. This is the authoritative path.
     *
     * Unauthenticated by design — the gateway has no bearer token. The
     * signature IS the authentication, which is why an unsigned or badly signed
     * body is recorded and then discarded rather than acted upon.
     *
     * @return array<string, mixed>
     */
    public function handleWebhook(Request $request, string $rawBody, string $signature): array
    {
        $verification = $this->gateway->verifyWebhook($rawBody, $signature);
        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];

        $eventType = (string) ($payload['event'] ?? $payload['sandbox_status'] ?? 'unknown');

        // A REJECTED webhook must never consume the idempotency key of a
        // genuine one.
        //
        // The event id is derived from the payload, and a forged webhook can
        // carry exactly the payload a real one would — only the signature
        // differs. Recording both under the same key meant the forgery landed
        // first, and the genuine gateway callback that followed was discarded
        // as a duplicate: the customer's money was taken and their order never
        // confirmed. One forged request with a guessable payment id was enough.
        //
        // So verification comes first, and rejected attempts are recorded under
        // a key scoped to the attempt itself. They stay in the table, because
        // repeated forgeries are worth seeing, but they cannot block anything.
        $eventId = $verification->signatureVerified
            ? $this->gateway->eventIdFrom($payload, $signature)
            : 'rejected:' . hash('sha256', $signature . '|' . $rawBody . '|' . microtime(true));

        $eventRowId = $this->payments->recordEvent(
            gateway: $this->gateway->name(),
            eventId: $eventId,
            eventType: $eventType,
            payload: $payload,
            signatureValid: $verification->signatureVerified,
            gatewayOrderId: $verification->gatewayOrderId,
            gatewayPaymentId: $verification->gatewayPaymentId,
            ip: $request->ip,
        );

        if ($eventRowId === null) {
            // Already seen. Gateways retry aggressively; acknowledging without
            // reprocessing is exactly right, and stops a duplicate "captured"
            // from paying a referral reward twice.
            $this->logger->info('Duplicate webhook ignored', [
                'gateway' => $this->gateway->name(),
                'event_id' => $eventId,
            ], 'payment');

            return ['status' => 'duplicate', 'processed' => false];
        }

        if (!$verification->signatureVerified) {
            $this->payments->markEventProcessed($eventRowId, null, 'Signature verification failed');

            $this->logger->warning('Webhook signature verification failed', [
                'gateway' => $this->gateway->name(),
                'event_id' => $eventId,
                'ip' => $request->ip,
            ], 'payment');

            // A 202 rather than a 4xx: telling an unauthenticated caller
            // precisely why their signature failed is free reconnaissance.
            return ['status' => 'rejected', 'processed' => false];
        }

        if ($verification->gatewayOrderId === null && $verification->gatewayPaymentId === null) {
            $this->payments->markEventProcessed($eventRowId, null, null);

            return ['status' => 'ignored', 'processed' => false];
        }

        $order = $this->locateOrderFor($verification);

        if ($order === null) {
            $this->payments->markEventProcessed($eventRowId, null, 'No matching order');

            $this->logger->warning('Webhook did not match any order', [
                'gateway_order_id' => $verification->gatewayOrderId,
                'gateway_payment_id' => $verification->gatewayPaymentId,
            ], 'payment');

            return ['status' => 'unmatched', 'processed' => false];
        }

        try {
            $this->applyVerification($order, $verification, $request, 'webhook');
            $this->payments->markEventProcessed($eventRowId, (int) $order['id'], null);
        } catch (\Throwable $exception) {
            $this->payments->markEventProcessed($eventRowId, (int) $order['id'], $exception->getMessage());

            $this->logger->error('Webhook processing failed', [
                'order_number' => $order['order_number'],
                'reason' => $exception->getMessage(),
            ], 'payment');

            throw $exception;
        }

        return ['status' => 'processed', 'processed' => true];
    }

    /**
     * The single place an order becomes paid.
     *
     * @param array<string, mixed> $order
     */
    private function applyVerification(
        array $order,
        PaymentVerification $verification,
        Request $request,
        string $source,
        ?int $paymentId = null,
    ): void {
        $this->db->transaction(function () use ($order, $verification, $request, $source, $paymentId): void {
            // Re-read under a lock. A webhook and a browser callback for the
            // same payment routinely arrive within milliseconds of each other.
            $locked = $this->orders->lockForUpdate((int) $order['id']);

            if ($locked === null) {
                throw new NotFoundException('That order no longer exists.');
            }

            // A staff decision on a manual UPI payment names the exact attempt
            // row it is about. That row must be the one updated — looking it up
            // by the *active* gateway instead would miss it whenever the store
            // has since switched to Razorpay, and a duplicate "razorpay" row
            // would be captured while the real manual attempt stayed pending.
            $payment = $paymentId !== null
                ? $this->lockAdminAttempt($paymentId, $locked, $verification)
                : $this->recordPaymentAttempt($locked, $verification, $request);

            if (!$verification->isSuccessful()) {
                $this->markPaymentFailed($locked, $verification, $payment);

                return;
            }

            if (PaymentStatus::isSettled((string) $locked['payment_status'])) {
                if ($paymentId !== null) {
                    // A staff confirmation must never report success without
                    // recording anything.
                    throw new HttpException(sprintf(
                        'Order %s is already paid, so there is nothing to confirm.',
                        $locked['order_number']
                    ), 409);
                }

                // Already confirmed by whichever signal arrived first.
                return;
            }

            if ($paymentId !== null) {
                $locked = $this->reopenForLatePayment($locked, $source);
            }

            $expected = Money::fromDecimal((string) $locked['amount_payable']);
            $received = $verification->amount;

            // An amount mismatch is never accepted silently. Underpayment must
            // not ship goods; overpayment needs a human to refund the difference.
            if ($received !== null && !$received->equals($expected)) {
                $this->logger->error('Payment amount did not match the order', [
                    'order_number' => $locked['order_number'],
                    'expected' => $expected->toDecimal(),
                    'received' => $received->toDecimal(),
                ], 'payment');

                $this->orders->appendTimeline(
                    orderId: (int) $locked['id'],
                    fromStatus: (string) $locked['status'],
                    toStatus: (string) $locked['status'],
                    title: 'Payment amount mismatch',
                    paymentStatus: (string) $locked['payment_status'],
                    note: sprintf(
                        'Expected %s but the gateway reported %s. Held for review.',
                        $expected->format(),
                        $received->format()
                    ),
                    customerVisible: false,
                );

                throw new HttpException(
                    'The payment amount did not match this order. Our team has been notified.',
                    409
                );
            }

            $invoiceNumber = $this->numbering->nextInvoiceNumber();

            $this->orders->update((int) $locked['id'], [
                'payment_status' => PaymentStatus::PAID,
                'amount_paid' => (string) $expected,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => date('Y-m-d H:i:s'),
                'invoice_financial_year' => $this->numbering->financialYear(),
                'confirmed_date' => date('Y-m-d H:i:s'),
                // Payment received: the window no longer applies.
                'expires_date' => null,
            ], null);

            $this->payments->update((int) $payment['id'], [
                'status' => 'captured',
                'signature_verified' => 1,
                'captured_date' => date('Y-m-d H:i:s'),
                'gateway_payment_id' => $verification->gatewayPaymentId,
                // What the customer actually paid with (upi, card, netbanking…).
                'method' => $verification->method ?? (string) ($payment['method'] ?? 'upi'),
                'upi_vpa' => $verification->upiVpa,
                'upi_transaction_id' => $verification->upiTransactionId,
                // When the customer paid, if staff entered it from the bank
                // statement; otherwise the moment the payment was confirmed.
                'authorized_date' => $verification->raw['customer_paid_at'] ?? date('Y-m-d H:i:s'),
                'gateway_response' => json_encode($verification->raw),
            ], null);

            $this->orders->appendTimeline(
                orderId: (int) $locked['id'],
                fromStatus: (string) $locked['status'],
                toStatus: (string) $locked['status'],
                title: 'Payment received',
                paymentStatus: PaymentStatus::PAID,
                note: $verification->upiTransactionId !== null
                    ? sprintf('%s received by UPI (UTR %s).', $expected->format(), $verification->upiTransactionId)
                    : sprintf('%s received by UPI.', $expected->format()),
                changedByRole: $source,
            );

            // BR-005 is now satisfied, so the order may be confirmed.
            $this->transition(
                (int) $locked['id'],
                (string) $locked['status'],
                OrderStatus::CONFIRMED,
                'Order confirmed',
                PaymentStatus::PAID,
                'Payment verified. Your order is being prepared.',
                null,
                $source
            );

            // Queued, not sent. If the SMS gateway is slow or down, the order
            // is still confirmed and the customer's money is still accounted
            // for — the message catches up when the worker next runs.
            $this->notifications->queue(
                'order.confirmed',
                'sms',
                [
                    'order_number' => (string) $locked['order_number'],
                    'amount' => $expected->format(),
                ],
                [
                    'user_id' => (int) $locked['user_id'],
                    'reference_type' => 'orders',
                    'reference_id' => (string) $locked['order_number'],
                    'dedupe_key' => 'order.confirmed:' . $locked['order_number'],
                ]
            );

            // Phase 4 hook: a referral pays out only once the new customer has
            // actually completed a paid order. This is that moment.
            $this->referrals->qualifyForOrder(
                refereeUserId: (int) $locked['user_id'],
                orderReference: (string) $locked['order_number'],
                orderValue: Money::fromDecimal((string) $locked['grand_total']),
                request: $request,
            );

            // Dynamic Offers & Smart Discounts' sibling brief, Loyalty: the
            // same "payment just confirmed" moment earns loyalty points, a
            // no-op when the program is disabled (LoyaltyService checks its
            // own settings and never throws into this transaction).
            $this->loyalty->earnForPurchase(
                userId: (int) $locked['user_id'],
                orderValue: Money::fromDecimal((string) $locked['grand_total']),
                channel: 'online',
                referenceType: 'orders',
                referenceId: (string) $locked['order_number'],
                request: $request,
            );
        });

        $this->audit->log(
            entityName: 'orders',
            entityId: (int) $order['id'],
            action: 'payment_' . ($verification->isSuccessful() ? 'captured' : 'failed'),
            newValues: $verification->toArray() + ['source' => $source],
            request: $request,
            entityUuid: (string) $order['uuid'],
        );
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    /**
     * The attempt row a staff decision is about, locked, checked to belong to
     * this order and still be open, and — for a confirmation — checked that
     * its UTR has not already paid for something else.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function lockAdminAttempt(int $paymentId, array $order, PaymentVerification $verification): array
    {
        $payment = $this->payments->lockById($paymentId);

        if ($payment === null || (int) $payment['order_id'] !== (int) $order['id']) {
            throw new NotFoundException('That payment attempt does not belong to this order.');
        }

        if (!in_array($payment['status'], ['created', 'pending'], true)) {
            throw new HttpException(sprintf(
                'This payment for order %s has already been %s.',
                $order['order_number'],
                $payment['status'] === 'captured' ? 'confirmed' : $payment['status']
            ), 409);
        }

        if ($verification->isSuccessful() && $verification->upiTransactionId !== null) {
            $used = $this->payments->findSettledByUtr($verification->upiTransactionId, $paymentId);

            if ($used !== null) {
                throw new HttpException(
                    sprintf(
                        'UTR %s was already used to confirm order %s. One bank transfer can only pay for one order — check the reference.',
                        $verification->upiTransactionId,
                        $used['order_number']
                    ),
                    409,
                    ['utr_or_reference' => ['This UTR has already been used for order ' . $used['order_number'] . '.']]
                );
            }
        }

        return $payment;
    }

    /**
     * Staff have confirmed a manual payment for an order the system already
     * closed because nobody had verified it inside the payment window.
     *
     * The money reached the shop, so the order is put back to "awaiting
     * payment" and then confirmed by the normal path below. That is only safe
     * when the expiry gave nothing back: an order whose coupon or wallet
     * credit was released on expiry cannot be silently revived, so staff are
     * told to refund or re-place it instead. Customer or staff cancellations
     * are never reopened.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function reopenForLatePayment(array $order, string $source): array
    {
        if ($order['status'] === OrderStatus::CREATED) {
            $this->transition(
                (int) $order['id'],
                OrderStatus::CREATED,
                OrderStatus::AWAITING_PAYMENT,
                'Payment started',
                (string) $order['payment_status'],
                null,
                null,
                $source
            );

            return array_merge($order, ['status' => OrderStatus::AWAITING_PAYMENT]);
        }

        if ($order['status'] !== OrderStatus::CANCELLED) {
            return $order;
        }

        if ((string) $order['cancellation_reason'] !== self::EXPIRY_CANCELLATION_REASON) {
            throw new HttpException(sprintf(
                'Order %s was cancelled (%s), so it cannot be marked paid. If the customer did pay, refund them.',
                $order['order_number'],
                $order['cancellation_reason'] !== null && $order['cancellation_reason'] !== ''
                    ? $order['cancellation_reason']
                    : 'no reason given'
            ), 409);
        }

        $releasedSomething = Money::fromDecimal((string) $order['wallet_applied'])->isPositive()
            || $order['coupon_id'] !== null;

        if ($releasedSomething) {
            throw new HttpException(sprintf(
                'Order %s was cancelled automatically when its payment window closed, and its coupon / wallet credit '
                . 'was given back to the customer, so it cannot be reopened. Refund this payment or place the order again for the customer.',
                $order['order_number']
            ), 409);
        }

        $this->orders->update((int) $order['id'], [
            'status' => OrderStatus::AWAITING_PAYMENT,
            'cancelled_date' => null,
            'cancellation_reason' => null,
        ], null);

        $this->orders->appendTimeline(
            orderId: (int) $order['id'],
            fromStatus: OrderStatus::CANCELLED,
            toStatus: OrderStatus::AWAITING_PAYMENT,
            title: 'Order reopened',
            paymentStatus: (string) $order['payment_status'],
            note: 'The payment window had closed before staff verified the UPI payment. Payment was verified, so the order was reopened.',
            customerVisible: false,
            changedByRole: $source,
        );

        return array_merge($order, [
            'status' => OrderStatus::AWAITING_PAYMENT,
            'cancelled_date' => null,
            'cancellation_reason' => null,
        ]);
    }

    private function recordPaymentAttempt(array $order, PaymentVerification $verification, Request $request): array
    {
        if ($verification->gatewayPaymentId !== null) {
            $existing = $this->payments->findByGatewayPaymentId(
                $this->gateway->name(),
                $verification->gatewayPaymentId
            );

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($verification->gatewayOrderId !== null) {
            $pending = $this->payments->findByGatewayOrderId(
                $this->gateway->name(),
                $verification->gatewayOrderId
            );

            if ($pending !== null && $pending['gateway_payment_id'] === null) {
                $this->payments->update((int) $pending['id'], [
                    'gateway_payment_id' => $verification->gatewayPaymentId,
                ], null);

                return (array) $this->payments->findById((int) $pending['id']);
            }

            if ($pending !== null) {
                return $pending;
            }
        }

        // A payment arriving with no attempt row is unusual but not impossible
        // (a webhook beating our own INSERT). Record it rather than discard it.
        $id = $this->payments->create([
            'order_id' => (int) $order['id'],
            'user_id' => (int) $order['user_id'],
            'gateway' => $this->gateway->name(),
            'gateway_order_id' => $verification->gatewayOrderId,
            'gateway_payment_id' => $verification->gatewayPaymentId,
            'attempt_number' => $this->payments->nextAttemptNumber((int) $order['id']),
            'amount' => (string) ($verification->amount ?? Money::fromDecimal((string) $order['amount_payable'])),
            'currency_code' => (string) $order['currency_code'],
            'status' => 'pending',
            'method' => $verification->method ?? 'upi',
        ], $request->authUserId());

        return (array) $this->payments->findById($id);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $payment
     */
    private function markPaymentFailed(array $order, PaymentVerification $verification, array $payment): void
    {
        $this->payments->update((int) $payment['id'], [
            'status' => 'failed',
            'signature_verified' => $verification->signatureVerified ? 1 : 0,
            'failure_code' => $verification->failureCode,
            'failure_reason' => $verification->failureReason,
            'failed_date' => date('Y-m-d H:i:s'),
            'gateway_response' => json_encode($verification->raw),
        ], null);

        // A failed or rejected attempt must never un-pay an order that another
        // attempt already settled — only the attempt itself is closed.
        if (PaymentStatus::isSettled((string) $order['payment_status'])) {
            return;
        }

        // The ORDER is not failed — only this attempt. The customer can retry
        // within the payment window, and marking the order dead would force
        // them to rebuild the cart.
        $this->orders->update((int) $order['id'], [
            'payment_status' => PaymentStatus::FAILED,
        ], null);

        $this->orders->appendTimeline(
            orderId: (int) $order['id'],
            fromStatus: (string) $order['status'],
            toStatus: (string) $order['status'],
            title: 'Payment failed',
            paymentStatus: PaymentStatus::FAILED,
            note: $verification->failureReason ?? 'The payment did not complete. You can try again.',
        );
    }

    /**
     * An order fully covered by wallet credit. The money moved at placement, so
     * there is nothing for a gateway to confirm.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function confirmFullyWalletPaid(array $order, Request $request): array
    {
        $this->db->transaction(function () use ($order, $request): void {
            $locked = $this->orders->lockForUpdate((int) $order['id']);

            if ($locked === null || PaymentStatus::isSettled((string) $locked['payment_status'])) {
                return;
            }

            $this->orders->update((int) $locked['id'], [
                'payment_status' => PaymentStatus::PAID,
                'amount_paid' => '0.00',
                'invoice_number' => $this->numbering->nextInvoiceNumber(),
                'invoice_date' => date('Y-m-d H:i:s'),
                'invoice_financial_year' => $this->numbering->financialYear(),
                'confirmed_date' => date('Y-m-d H:i:s'),
                'expires_date' => null,
            ], (int) $locked['user_id']);

            $this->orders->appendTimeline(
                orderId: (int) $locked['id'],
                fromStatus: (string) $locked['status'],
                toStatus: (string) $locked['status'],
                title: 'Paid from wallet credit',
                paymentStatus: PaymentStatus::PAID,
                note: sprintf('%s covered entirely by wallet credit.', (string) $locked['wallet_applied']),
            );

            $this->transition(
                (int) $locked['id'],
                (string) $locked['status'],
                OrderStatus::CONFIRMED,
                'Order confirmed',
                PaymentStatus::PAID,
                'Your order is being prepared.',
                (int) $locked['user_id'],
                'customer'
            );

            $this->referrals->qualifyForOrder(
                refereeUserId: (int) $locked['user_id'],
                orderReference: (string) $locked['order_number'],
                orderValue: Money::fromDecimal((string) $locked['grand_total']),
                request: $request,
            );

            $this->loyalty->earnForPurchase(
                userId: (int) $locked['user_id'],
                orderValue: Money::fromDecimal((string) $locked['grand_total']),
                channel: 'online',
                referenceType: 'orders',
                referenceId: (string) $locked['order_number'],
                request: $request,
            );
        });

        $fresh = (array) $this->orders->findById((int) $order['id']);

        return [
            'payment' => ['gateway' => 'wallet', 'amount' => 0.0, 'methods' => []],
            'order' => [
                'uuid' => $fresh['uuid'],
                'order_number' => $fresh['order_number'],
                'status' => $fresh['status'],
                'payment_status' => $fresh['payment_status'],
                'invoice_number' => $fresh['invoice_number'],
            ],
            'fully_paid_by_wallet' => true,
        ];
    }

    /**
     * Releases orders whose payment window closed: returns the coupon use and
     * credits the wallet back.
     *
     * Without this, an abandoned checkout holds a customer's credit and a
     * limited-use coupon indefinitely.
     *
     * @return array<string, mixed>
     */
    public function expireUnpaidOrders(Request $request, int $limit = 200): array
    {
        $expired = 0;
        $walletReturned = Money::zero();
        $couponsReleased = 0;

        $reviewHours = max(1, $this->settings->intValue('manual_payment_review_hours', self::MANUAL_REVIEW_HOURS));
        $manualReviewSince = date('Y-m-d H:i:s', time() - $reviewHours * 3600);

        foreach ($this->orders->expiredUnpaidOrders($limit, $manualReviewSince) as $order) {
            try {
                $this->db->transaction(function () use ($order, $request, &$walletReturned, &$couponsReleased): void {
                    $locked = $this->orders->lockForUpdate((int) $order['id']);

                    if ($locked === null
                        || PaymentStatus::isSettled((string) $locked['payment_status'])
                        || $locked['status'] === OrderStatus::CANCELLED) {
                        return;
                    }

                    $this->releaseHeldValue($locked, 'Order expired before payment', $request, $walletReturned, $couponsReleased);

                    $this->orders->update((int) $locked['id'], [
                        'status' => OrderStatus::CANCELLED,
                        'cancelled_date' => date('Y-m-d H:i:s'),
                        'cancellation_reason' => self::EXPIRY_CANCELLATION_REASON,
                    ], null);

                    $this->orders->appendTimeline(
                        orderId: (int) $locked['id'],
                        fromStatus: (string) $locked['status'],
                        toStatus: OrderStatus::CANCELLED,
                        title: 'Order cancelled',
                        paymentStatus: (string) $locked['payment_status'],
                        note: 'Payment was not completed in time. Any wallet credit has been returned.',
                    );
                });

                ++$expired;
            } catch (\Throwable $exception) {
                $this->logger->error('Could not expire an unpaid order', [
                    'order_number' => $order['order_number'],
                    'reason' => $exception->getMessage(),
                ], 'payment');
            }
        }

        return [
            'expired_count' => $expired,
            'wallet_returned' => $walletReturned->toDecimal(),
            'coupons_released' => $couponsReleased,
        ];
    }

    /**
     * Returns whatever an unfulfilled order was holding: coupon use and wallet
     * credit. Shared by expiry and cancellation.
     *
     * @param array<string, mixed> $order
     */
    public function releaseHeldValue(
        array $order,
        string $reason,
        Request $request,
        Money &$walletReturned,
        int &$couponsReleased,
    ): void {
        $wallet = Money::fromDecimal((string) $order['wallet_applied']);

        if ($wallet->isPositive()) {
            // A compensating credit, not a reversal: the ledger is append-only,
            // so the original debit stays visible beside the refund.
            $this->wallet->credit(
                userId: (int) $order['user_id'],
                amount: $wallet,
                source: WalletService::SOURCE_ORDER_REFUND,
                narration: 'Wallet credit returned from order ' . $order['order_number'],
                idempotencyKey: 'order:' . $order['order_number'] . ':wallet-return',
                referenceType: 'orders',
                referenceId: (string) $order['order_number'],
                request: $request,
            );

            $walletReturned = $walletReturned->add($wallet);
        }

        if ($order['coupon_id'] !== null) {
            $this->coupons->release(
                (int) $order['coupon_id'],
                (string) $order['order_number'],
                $reason,
                $request
            );

            ++$couponsReleased;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locateOrderFor(PaymentVerification $verification): ?array
    {
        if ($verification->gatewayPaymentId !== null) {
            $payment = $this->payments->findByGatewayPaymentId(
                $this->gateway->name(),
                $verification->gatewayPaymentId
            );

            if ($payment !== null) {
                return $this->orders->findById((int) $payment['order_id']);
            }
        }

        if ($verification->gatewayOrderId !== null) {
            $payment = $this->payments->findByGatewayOrderId(
                $this->gateway->name(),
                $verification->gatewayOrderId
            );

            if ($payment !== null) {
                return $this->orders->findById((int) $payment['order_id']);
            }
        }

        return null;
    }

    private function transition(
        int $orderId,
        string $from,
        string $to,
        string $title,
        string $paymentStatus,
        ?string $note,
        ?int $changedBy,
        ?string $role,
    ): void {
        // Validated even for system-driven transitions. Skipping the check here
        // because "we know payment just succeeded" is precisely the reasoning
        // that lets a bug ship an unpaid order. $paymentStatus is the value
        // written moments ago in this same transaction, not the stale row.
        $this->stateMachine->assert(
            $from,
            $to,
            $paymentStatus,
            otpVerified: true,
            otpRequired: false,
            isStaffOverride: true
        );

        $this->orders->update($orderId, ['status' => $to], $changedBy);

        $this->orders->appendTimeline(
            orderId: $orderId,
            fromStatus: $from,
            toStatus: $to,
            title: $title,
            paymentStatus: $paymentStatus,
            note: $note,
            changedBy: $changedBy,
            changedByRole: $role,
        );

        // Central inventory deduction (5Star inventory-first brief §3): this is
        // the first status BR-005 guarantees is payment-settled, so it is the
        // right moment to deduct — never earlier, or a cart that never pays
        // would reserve stock. Never blocks: an insufficient-stock line still
        // deducts and simply drives the balance negative.
        if ($to === OrderStatus::CONFIRMED) {
            $this->inventory->saleDeduction($orderId, $this->orders->itemsFor($orderId), $changedBy);
        }
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

    private function otpRequired(): bool
    {
        return $this->settings->boolValue('order_otp_required', true);
    }
}
