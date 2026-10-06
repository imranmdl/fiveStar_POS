<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\CommunicationLogRepository;
use App\Repositories\InvoiceRegisterRepository;
use App\Repositories\PosSaleRepository;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;
use App\Repositories\WalletRepository;
use App\Services\Notifications\SmsGatewayInterface;

/**
 * Invoice Tracking & Communication Center.
 *
 * TERMINOLOGY: "invoice" here means a POS till sale (`pos_sales`), not an
 * online store order. This store's online orders are prepaid-only (BR-004)
 * and already have their own GST invoice at GET /orders/{uuid}/invoice —
 * there is no partial-payment/due-date concept for them to track. POS
 * counter sales are the one place in this codebase with real partial
 * payment, a running balance, and a due date, which is exactly what this
 * module's brief (dashboard cards, "Paid ₹500 of ₹1,000", due reminders,
 * overdue) describes. Nothing here creates a new invoice or payment
 * record — it reads pos_sales/pos_sale_payments/pos_refunds (via
 * PosSaleRepository/PosSaleService, unchanged) and only adds one new table
 * of its own: communication_log, for the WhatsApp history this brief asks
 * for.
 *
 * WHATSAPP: this project has no WhatsApp Business API credential wired in
 * anywhere (NotificationService's own whatsapp channel is log-only). So
 * "send WhatsApp" here is the wa.me click-to-chat link: it opens WhatsApp
 * with the message pre-filled, and a human still presses send inside
 * WhatsApp itself. That is a real, working, zero-dependency way to satisfy
 * "open WhatsApp with a pre-filled message" — but it means communication_log
 * records that the composer was opened, not a delivery receipt. Because of
 * that, this backend has no way to know in advance whether a given number
 * even has a WhatsApp account — only WhatsApp's own app can tell the admin
 * that, after the link opens. logSmsSend() is the fallback for exactly that
 * case: it sends a real SMS through the same gateway OtpService already
 * uses, which works for any valid mobile number regardless of WhatsApp.
 */
final class InvoiceService
{
    /** Minimum minutes between two sends of the same one-off template — just enough to absorb a double-click. */
    private const ONE_OFF_DEDUPE_MINUTES = 2;

    /** @var array<int, string> */
    private const TEMPLATE_CODES = [
        'invoice_created', 'payment_received', 'partial_reminder', 'payment_due_reminder',
        'overdue', 'offer_available', 'refund_processed', 'order_delivered', 'payment_completed',
    ];

    /** Templates that repeat over time, so they dedupe against the admin-configured reminder window instead of the short one-off window. */
    private const RECURRING_TEMPLATES = ['partial_reminder', 'payment_due_reminder', 'overdue'];

    public function __construct(
        private readonly PosSaleRepository $sales,
        private readonly PosSaleService $saleService,
        private readonly WalletRepository $wallet,
        private readonly OfferService $offers,
        private readonly CommunicationLogRepository $communications,
        private readonly AuditService $audit,
        private readonly SettingRepository $settings,
        private readonly UserRepository $users,
        private readonly Config $config,
        private readonly SmsGatewayInterface $smsGateway,
        private readonly InvoiceRegisterRepository $register,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $pos = $this->sales->invoiceSummary(PosDuePaymentService::overdueAfterDays());
        $online = $this->register->onlineSummary();

        // The cards show both channels together; the split is kept for the
        // "Shop / Online" breakdown. Partially paid and overdue balances only
        // exist on till credit sales.
        return [
            'total' => $pos['total'] + $online['total'],
            'paid' => $pos['paid'] + $online['paid'],
            'partial' => $pos['partial'],
            'unpaid' => $pos['unpaid'] + $online['unpaid'],
            'overdue' => $pos['overdue'],
            'refunded' => $pos['refunded'] + $online['refunded'],
            'cancelled' => $pos['cancelled'] + $online['cancelled'],
            'todays_revenue' => number_format((float) $pos['todays_revenue'] + $online['todays_revenue'], 2, '.', ''),
            'by_channel' => [
                'pos' => $pos,
                'online' => $online,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $filters, array $params): array
    {
        if (!empty($filters['overdue_only'])) {
            $filters['overdue_days'] = PosDuePaymentService::overdueAfterDays();
        }

        // Both channels: till sales and confirmed online orders.
        return $this->register->search($filters, $params);
    }

    /** @return array<string, mixed> */
    public function detail(string $uuid): array
    {
        $sale = $this->saleDetail($uuid);
        $sale['communications'] = $this->communications->forSale((int) $sale['id']);
        $sale['timeline'] = $this->buildTimeline($sale);
        $sale['applicable_offers'] = array_slice($this->offers->liveOffers(), 0, 3);

        if ($sale['customer_id'] !== null) {
            $sale['customer_summary'] = $this->customerSummary((int) $sale['customer_id']);
        }

        return $sale;
    }

    /**
     * PosSaleService::detail() (deliberately unchanged — see this class's
     * own doc comment) only joins cashier/warehouse, not the customer, so it
     * never carries `customer_name`/`customer_mobile` the way
     * PosSaleRepository::searchInvoices() does for the list screen. Every
     * caller in this class that needs to address the customer by name or
     * message their phone goes through this wrapper instead of calling
     * saleService->detail() directly.
     *
     * @return array<string, mixed>
     */
    private function saleDetail(string $uuid): array
    {
        $sale = $this->saleService->detail($uuid);

        if ($sale['customer_id'] !== null) {
            $customer = $this->users->findById((int) $sale['customer_id']);
            $sale['customer_name'] = $customer['full_name'] ?? null;
            $sale['customer_mobile'] = $customer['mobile'] ?? null;
            $sale['customer_uuid'] = $customer['uuid'] ?? null;
        } else {
            $sale['customer_name'] = $sale['walk_in_name'] ?? null;
            $sale['customer_mobile'] = $sale['walk_in_mobile'] ?? null;
            $sale['customer_uuid'] = null;
        }

        return $sale;
    }

    /** @return array<string, mixed> */
    public function customerSummary(int $customerId): array
    {
        $summary = $this->sales->customerInvoiceSummary($customerId);
        $account = $this->wallet->findAccountForUser($customerId);
        $summary['wallet_balance'] = $account['balance_amount'] ?? '0.00';

        return $summary;
    }

    /** @return array<string, mixed> */
    public function customerSummaryByUuid(string $customerUuid): array
    {
        $customer = $this->users->findByUuid($customerUuid);

        if ($customer === null) {
            throw new NotFoundException('That customer does not exist.');
        }

        $summary = $this->customerSummary((int) $customer['id']);
        $summary['customer'] = [
            'uuid' => $customer['uuid'],
            'full_name' => $customer['full_name'],
            'mobile' => $customer['mobile'],
        ];

        return $summary;
    }

    /**
     * Builds the message for a template without recording anything —
     * used for the "preview/edit before sending" step the brief asks for.
     *
     * @return array{phone:?string, message:string, wa_link:?string}
     */
    public function previewMessage(string $saleUuid, string $templateCode): array
    {
        $this->assertKnownTemplate($templateCode);
        $sale = $this->saleDetail($saleUuid);

        return $this->render($sale, $templateCode);
    }

    /**
     * Records that a WhatsApp send was initiated (opened the composer),
     * refusing an accidental duplicate within the dedupe window.
     *
     * @return array<string, mixed>
     */
    public function logSend(string $saleUuid, string $templateCode, ?string $editedMessage, Request $request): array
    {
        $this->assertKnownTemplate($templateCode);
        $sale = $this->saleDetail($saleUuid);
        $rendered = $this->render($sale, $templateCode);

        if ($rendered['phone'] === null) {
            throw new HttpException('This sale has no phone number on file to message.', 422);
        }

        $dedupeMinutes = in_array($templateCode, self::RECURRING_TEMPLATES, true)
            ? max(1, $this->settings->intValue('pos_due_reminder_repeat_hours', 72)) * 60
            : self::ONE_OFF_DEDUPE_MINUTES;

        if ($this->communications->sentRecently((int) $sale['id'], $templateCode, $dedupeMinutes)) {
            throw new HttpException(
                'This reminder was already sent recently for this invoice. Wait before sending it again.',
                409
            );
        }

        $message = $editedMessage !== null && trim($editedMessage) !== '' ? $editedMessage : $rendered['message'];

        $this->communications->create([
            'pos_sale_id' => (int) $sale['id'],
            'channel' => 'whatsapp',
            'template_code' => $templateCode,
            'recipient_mobile' => $rendered['phone'],
            'message_preview' => mb_substr($message, 0, 1000),
            'status' => 'opened',
            'sent_by_user_id' => $request->authUserId(),
        ], $request->authUserId());

        $this->audit->log(
            entityName: 'pos_sales',
            entityId: (int) $sale['id'],
            action: 'whatsapp_sent',
            newValues: ['template' => $templateCode, 'recipient' => $rendered['phone']],
            request: $request,
        );

        return $this->communicationHistory($saleUuid);
    }

    /**
     * Fallback for when WhatsApp turns out not to work for this number
     * (new customer, no WhatsApp account, or the admin just prefers SMS).
     * Unlike logSend(), this actually sends — through the same gateway
     * OtpService uses — rather than recording that a composer was opened.
     *
     * @return array<string, mixed>
     */
    public function logSmsSend(string $saleUuid, string $templateCode, ?string $editedMessage, Request $request): array
    {
        $this->assertKnownTemplate($templateCode);
        $sale = $this->saleDetail($saleUuid);
        $rendered = $this->render($sale, $templateCode);

        if ($rendered['phone'] === null) {
            throw new HttpException('This sale has no phone number on file to message.', 422);
        }

        $dedupeMinutes = in_array($templateCode, self::RECURRING_TEMPLATES, true)
            ? max(1, $this->settings->intValue('pos_due_reminder_repeat_hours', 72)) * 60
            : self::ONE_OFF_DEDUPE_MINUTES;

        if ($this->communications->sentRecently((int) $sale['id'], $templateCode, $dedupeMinutes, 'sms')) {
            throw new HttpException(
                'This reminder was already sent by SMS recently for this invoice. Wait before sending it again.',
                409
            );
        }

        $message = $editedMessage !== null && trim($editedMessage) !== '' ? $editedMessage : $rendered['message'];

        $result = $this->smsGateway->send($rendered['phone'], mb_substr($message, 0, 1000));
        $accepted = (bool) ($result['accepted'] ?? false);

        $this->communications->create([
            'pos_sale_id' => (int) $sale['id'],
            'channel' => 'sms',
            'template_code' => $templateCode,
            'recipient_mobile' => $rendered['phone'],
            'message_preview' => mb_substr($message, 0, 1000),
            'status' => $accepted ? 'sent' : 'failed',
            'sent_by_user_id' => $request->authUserId(),
        ], $request->authUserId());

        $this->audit->log(
            entityName: 'pos_sales',
            entityId: (int) $sale['id'],
            action: 'sms_sent',
            newValues: [
                'template' => $templateCode,
                'recipient' => $rendered['phone'],
                'accepted' => $accepted,
                'provider_detail' => $result['detail'] ?? null,
            ],
            request: $request,
        );

        if (!$accepted) {
            throw new HttpException(
                'SMS gateway did not accept this message: ' . (string) ($result['detail'] ?? 'unknown error'),
                502
            );
        }

        return $this->communicationHistory($saleUuid);
    }

    /** @return array<string, mixed> */
    public function communicationHistory(string $saleUuid): array
    {
        $sale = $this->saleService->detail($saleUuid);

        return ['sale_uuid' => $saleUuid, 'communications' => $this->communications->forSale((int) $sale['id'])];
    }

    /**
     * @return array<int, array<string, mixed>> raw invoice rows for CSV export — same filters as list(), no pagination cap beyond a sane ceiling.
     */
    public function exportRows(array $filters): array
    {
        $params = ['page' => 1, 'per_page' => 5000, 'offset' => 0, 'sort' => 'created_date', 'direction' => 'DESC'];

        if (!empty($filters['overdue_only'])) {
            $filters['overdue_days'] = PosDuePaymentService::overdueAfterDays();
        }

        return $this->register->search($filters, $params)['items'];
    }

    // -------------------------------------------------------------------
    // Message rendering
    // -------------------------------------------------------------------

    private function assertKnownTemplate(string $templateCode): void
    {
        if (!in_array($templateCode, self::TEMPLATE_CODES, true)) {
            throw new HttpException(
                'Unknown message template "' . $templateCode . '".',
                422,
                ['template' => ['Must be one of: ' . implode(', ', self::TEMPLATE_CODES)]]
            );
        }
    }

    /**
     * @param array<string, mixed> $sale
     *
     * @return array{phone:?string, message:string, wa_link:?string}
     */
    private function render(array $sale, string $templateCode): array
    {
        $name = $sale['customer_name'] ?? $sale['walk_in_name'] ?? 'Customer';
        $mobile = $this->resolveMobile($sale);
        $storeName = $this->settings->value('store_name', '5 Star Spices & Dry Fruits');
        $invoiceNo = $sale['sale_number'];
        $total = number_format((float) $sale['grand_total'], 2);
        $paid = number_format((float) $sale['amount_paid'], 2);
        $remaining = number_format(max(0, (float) $sale['grand_total'] - (float) $sale['amount_paid']), 2);
        $dueDate = $this->estimatedDueDate($sale);

        $message = match ($templateCode) {
            'invoice_created' => sprintf(
                'Hello %s, your invoice %s from %s is ready. Total: Rs.%s. %s Thank you for shopping with us!',
                $name, $invoiceNo, $storeName, $total,
                $sale['payment_status'] === 'paid' ? 'Fully paid, no balance due.' : sprintf('Paid: Rs.%s, Remaining: Rs.%s.', $paid, $remaining)
            ),
            'payment_received' => sprintf(
                'Hello %s, we have received your payment of Rs.%s against invoice %s from %s. %s Thank you!',
                $name, $paid, $invoiceNo, $storeName,
                (float) $sale['amount_paid'] >= (float) $sale['grand_total'] ? 'Your bill is now fully paid.' : sprintf('Remaining balance: Rs.%s.', $remaining)
            ),
            'partial_reminder' => sprintf(
                'Hello %s, your invoice %s from %s is Rs.%s. Amount paid: Rs.%s. Remaining due: Rs.%s. Please clear the balance at your convenience.',
                $name, $invoiceNo, $storeName, $total, $paid, $remaining
            ),
            'payment_due_reminder' => sprintf(
                'Hello %s, this is a reminder that Rs.%s is due on invoice %s from %s%s. Please make the payment soon.',
                $name, $remaining, $invoiceNo, $storeName, $dueDate ? ' by ' . $dueDate : ''
            ),
            'overdue' => sprintf(
                'Hello %s, invoice %s from %s has an overdue balance of Rs.%s. Please pay at the earliest to avoid interruption in service.',
                $name, $invoiceNo, $storeName, $remaining
            ),
            'offer_available' => $this->offerMessage($name, $storeName),
            'refund_processed' => sprintf(
                'Hello %s, a refund has been processed for invoice %s from %s. Please check your original payment method or wallet for the credited amount.',
                $name, $invoiceNo, $storeName
            ),
            'order_delivered' => sprintf(
                'Hello %s, your order for invoice %s from %s has been delivered. We hope you enjoy your purchase!',
                $name, $invoiceNo, $storeName
            ),
            'payment_completed' => sprintf(
                'Hello %s, invoice %s from %s (Rs.%s) is now fully paid. Thank you for your business!',
                $name, $invoiceNo, $storeName, $total
            ),
            default => sprintf('Hello %s, regarding invoice %s from %s.', $name, $invoiceNo, $storeName),
        };

        $waLink = $mobile !== null
            ? sprintf(
                'https://wa.me/%s%s?text=%s',
                $this->config->get('notifications.sms.country_code', '91'),
                $mobile,
                rawurlencode($message)
            )
            : null;

        return ['phone' => $mobile, 'message' => $message, 'wa_link' => $waLink];
    }

    private function offerMessage(string $name, string $storeName): string
    {
        $offers = $this->offers->liveOffers();

        if ($offers === []) {
            return sprintf('Hello %s, keep an eye out — new offers from %s are coming soon!', $name, $storeName);
        }

        $offer = $offers[0];
        $title = $offer['title'] ?? $offer['name'] ?? 'a special offer';

        return sprintf(
            'Hello %s, %s has an offer for you: %s. Check it out on our store before it ends!',
            $name, $storeName, $title
        );
    }

    /** @param array<string, mixed> $sale */
    private function resolveMobile(array $sale): ?string
    {
        $mobile = $sale['customer_mobile'] ?? $sale['walk_in_mobile'] ?? null;

        if ($mobile === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $mobile) ?? '';

        return $digits === '' ? null : $digits;
    }

    /** @param array<string, mixed> $sale */
    private function estimatedDueDate(array $sale): ?string
    {
        if (!in_array($sale['payment_status'], ['unpaid', 'partial'], true)) {
            return null;
        }

        $delayHours = $this->settings->intValue('pos_due_reminder_delay_hours', 24);
        $base = strtotime((string) $sale['created_date']);

        return $base === false ? null : date('d M Y', $base + ($delayHours * 3600));
    }

    /**
     * @param array<string, mixed> $sale
     *
     * @return array<int, array{label:string, date:?string, tone:string}>
     */
    private function buildTimeline(array $sale): array
    {
        $steps = [
            ['label' => 'Invoice created', 'date' => $sale['created_date'], 'tone' => 'neutral'],
        ];

        foreach (($sale['due_payments'] ?? []) as $payment) {
            $steps[] = [
                'label' => 'Payment received: Rs.' . number_format((float) $payment['amount'], 2),
                'date' => $payment['payment_date'] ?? $payment['created_date'],
                'tone' => 'success',
            ];
        }

        foreach (($sale['refunds'] ?? []) as $refund) {
            $steps[] = [
                'label' => 'Refund issued: Rs.' . number_format((float) ($refund['refund_amount'] ?? $refund['amount'] ?? 0), 2),
                'date' => $refund['created_date'] ?? null,
                'tone' => 'danger',
            ];
        }

        if ($sale['status'] === 'voided') {
            $steps[] = ['label' => 'Sale voided' . ($sale['void_reason'] ? ': ' . $sale['void_reason'] : ''), 'date' => $sale['voided_date'], 'tone' => 'danger'];
        }

        if ($sale['payment_status'] === 'paid') {
            $steps[] = ['label' => 'Invoice fully paid', 'date' => $sale['updated_date'] ?? $sale['created_date'], 'tone' => 'success'];
        } elseif (in_array($sale['payment_status'], ['unpaid', 'partial'], true)) {
            $steps[] = ['label' => 'Awaiting balance of Rs.' . number_format((float) $sale['grand_total'] - (float) $sale['amount_paid'], 2), 'date' => null, 'tone' => 'pending'];
        }

        usort($steps, static function (array $a, array $b): int {
            return strtotime((string) ($a['date'] ?? '9999-12-31')) <=> strtotime((string) ($b['date'] ?? '9999-12-31'));
        });

        return $steps;
    }
}
