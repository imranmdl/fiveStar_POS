<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\InvoiceService;

/**
 * Invoice Tracking & Communication Center.
 *
 * See InvoiceService's own doc comment for what "invoice" means here (a POS
 * till sale) and why. Recording a payment, refunding, or voiding a sale is
 * NOT duplicated on this controller — those stay exactly where they already
 * are (PosSaleController::recordPayment/refund/void); this one only reads
 * and adds the WhatsApp communication layer.
 */
final class InvoiceController extends BaseController
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    /** GET /api/v1/admin/invoices/summary */
    public function summary(Request $request): Response
    {
        return Response::success($this->invoices->summary(), 'Summary loaded');
    }

    /** GET /api/v1/admin/invoices */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'search' => 'nullable|string|max:150',
            'payment_status' => 'nullable|in:unpaid,partial,paid',
            'payment_method' => 'nullable|in:cash,upi,card,other',
            'customer_uuid' => 'nullable|uuid',
            'overdue_only' => 'nullable|boolean',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'amount_min' => 'nullable|numeric|min:0',
            'amount_max' => 'nullable|numeric|min:0',
        ]);

        $params = $this->paginationParams($request, 'created_date', 100);
        $result = $this->invoices->list($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Invoices loaded');
    }

    /** GET /api/v1/admin/invoices/export.csv */
    public function export(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'search' => 'nullable|string|max:150',
            'payment_status' => 'nullable|in:unpaid,partial,paid',
            'payment_method' => 'nullable|in:cash,upi,card,other',
            'overdue_only' => 'nullable|boolean',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            // Same filter set index() accepts, so an export always matches
            // whatever the on-screen filtered list currently shows.
            'amount_min' => 'nullable|numeric|min:0',
            'amount_max' => 'nullable|numeric|min:0',
        ]);

        $rows = $this->invoices->exportRows($filters);

        $columns = [
            'sale_number' => 'Invoice Number', 'customer_name' => 'Customer', 'customer_mobile' => 'Phone',
            'created_date' => 'Date', 'grand_total' => 'Total', 'amount_paid' => 'Paid',
            'balance_due' => 'Remaining', 'discount_amount' => 'Discount', 'payment_method' => 'Payment Method',
            'payment_status' => 'Status',
        ];

        $csv = implode(',', array_values($columns)) . "\r\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(
                static fn (string $key): string => '"' . str_replace('"', '""', (string) ($row[$key] ?? '')) . '"',
                array_keys($columns)
            )) . "\r\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'invoices_');
        file_put_contents((string) $path, $csv);

        return Response::file((string) $path, 'invoices_' . date('Ymd_His') . '.csv', 'text/csv');
    }

    /** GET /api/v1/admin/invoices/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->invoices->detail((string) $request->routeParam('uuid')),
            'Invoice loaded'
        );
    }

    /** GET /api/v1/admin/invoices/{uuid}/whatsapp-preview?template=... */
    public function whatsappPreview(Request $request): Response
    {
        $data = Validator::make($request->all(), ['template' => 'required|string|max:60']);

        return Response::success(
            $this->invoices->previewMessage((string) $request->routeParam('uuid'), $data['template']),
            'Message previewed'
        );
    }

    /** POST /api/v1/admin/invoices/{uuid}/whatsapp-log */
    public function whatsappLog(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'template' => 'required|string|max:60',
            'message' => 'nullable|string|max:1000',
        ]);

        return Response::success(
            $this->invoices->logSend(
                (string) $request->routeParam('uuid'),
                $data['template'],
                $data['message'] ?? null,
                $request
            ),
            'Communication logged'
        );
    }

    /** POST /api/v1/admin/invoices/{uuid}/sms-send */
    public function smsSend(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'template' => 'required|string|max:60',
            'message' => 'nullable|string|max:1000',
        ]);

        return Response::success(
            $this->invoices->logSmsSend(
                (string) $request->routeParam('uuid'),
                $data['template'],
                $data['message'] ?? null,
                $request
            ),
            'SMS sent'
        );
    }

    /** GET /api/v1/admin/invoices/{uuid}/communications */
    public function communications(Request $request): Response
    {
        return Response::success(
            $this->invoices->communicationHistory((string) $request->routeParam('uuid')),
            'Communication history loaded'
        );
    }

    /** GET /api/v1/admin/customers/{uuid}/invoice-summary */
    public function customerSummary(Request $request): Response
    {
        // Resolved via the invoice list's own customer_uuid filter path is
        // avoided here on purpose: this endpoint takes the customer's OWN
        // uuid, not a sale's, so the id lookup happens in the service using
        // the same users table every other admin screen already trusts.
        return Response::success(
            $this->invoices->customerSummaryByUuid((string) $request->routeParam('uuid')),
            'Customer summary loaded'
        );
    }
}
