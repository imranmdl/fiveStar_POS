<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\BulkOrderService;

/**
 * Wholesale enquiries and quotations.
 *
 * Enquiry submission is open to guests: a business making a first approach
 * should not have to create an account before finding out whether we can
 * supply them. Everything after that needs an account, because it leads to an
 * order.
 */
final class BulkOrderController extends BaseController
{
    private const STATUSES = [
        'new', 'under_review', 'quoted', 'negotiating', 'accepted', 'converted', 'declined', 'expired',
    ];

    public function __construct(private readonly BulkOrderService $bulk)
    {
    }

    /** POST /api/v1/bulk-orders/enquiries */
    public function submit(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'business_name' => 'required|string|min:2|max:180',
            'gstin' => 'nullable|string|min:15|max:15',
            'contact_name' => 'required|string|min:2|max:120',
            'contact_mobile' => 'required|mobile_in',
            'contact_email' => 'nullable|email',
            'delivery_pincode' => 'nullable|digits:6',
            'delivery_city' => 'nullable|string|max:90',
            'delivery_state' => 'nullable|string|max:90',
            'requirements' => 'required|string|min:10|max:5000',
            'expected_delivery_date' => 'nullable|date',
            'estimated_quantity' => 'nullable|string|max:120',
            'estimated_budget' => 'nullable|numeric|min:0',
        ]);

        return Response::created($this->bulk->submitEnquiry($request, $data), 'Enquiry received');
    }

    /** GET /api/v1/bulk-orders/enquiries/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->bulk->showEnquiry($request, (string) $request->routeParam('uuid'), isStaff: false),
            'Enquiry loaded'
        );
    }

    /** POST /api/v1/bulk-orders/quotes/{uuid}/accept */
    public function accept(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'address_uuid' => 'required|uuid',
        ]);

        return Response::created(
            $this->bulk->acceptQuote($request, (string) $request->routeParam('uuid'), $data['address_uuid']),
            'Quotation accepted'
        );
    }

    /** POST /api/v1/bulk-orders/quotes/{uuid}/reject */
    public function reject(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:500',
        ]);

        return Response::success(
            $this->bulk->rejectQuote($request, (string) $request->routeParam('uuid'), $data['reason']),
            'Quotation rejected'
        );
    }

    // -----------------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/bulk-orders */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $status = $request->query('status');

        if (is_string($status) && $status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new HttpException('Unknown enquiry status: ' . $status, 422);
        }

        $result = $this->bulk->listForStaff(
            $params,
            is_string($status) && $status !== '' ? $status : null
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Enquiries loaded');
    }

    /** GET /api/v1/admin/bulk-orders/{uuid} */
    public function adminShow(Request $request): Response
    {
        return Response::success(
            $this->bulk->showEnquiry($request, (string) $request->routeParam('uuid'), isStaff: true),
            'Enquiry loaded'
        );
    }

    /** POST /api/v1/admin/bulk-orders/{uuid}/quote */
    public function quote(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'items' => 'required|array|min:1|max:200',
            'discount_amount' => 'nullable|numeric|min:0',
            'delivery_charge' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|string|max:255',
            'delivery_terms' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        return Response::created(
            $this->bulk->createQuote(
                $request,
                (string) $request->routeParam('uuid'),
                $data['items'],
                [
                    'discount_amount' => $data['discount_amount'] ?? 0,
                    'delivery_charge' => $data['delivery_charge'] ?? 0,
                    'payment_terms' => $data['payment_terms'] ?? null,
                    'delivery_terms' => $data['delivery_terms'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]
            ),
            'Quotation prepared'
        );
    }

    /** POST /api/v1/admin/bulk-orders/quotes/{uuid}/send */
    public function send(Request $request): Response
    {
        return Response::success(
            $this->bulk->sendQuote($request, (string) $request->routeParam('uuid')),
            'Quotation sent'
        );
    }

    /** POST /api/v1/admin/bulk-orders/{uuid}/decline */
    public function decline(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:500',
        ]);

        return Response::success(
            $this->bulk->declineEnquiry($request, (string) $request->routeParam('uuid'), $data['reason']),
            'Enquiry declined'
        );
    }
}
