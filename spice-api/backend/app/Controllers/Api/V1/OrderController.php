<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\OrderService;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\PaymentStatus;
use App\Services\PaymentService;

/**
 * Orders: the customer's history and tracking, and the staff console.
 */
final class OrderController extends BaseController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {
    }

    /**
     * GET /api/v1/orders
     *
     * Order history for the signed-in customer.
     */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 50);
        $status = $this->optionalStatus($request->query('status'));

        $result = $this->orders->listForCustomer($request, $params, $status);

        return $this->paginated($result['items'], $result['total'], $params, 'Orders loaded');
    }

    /**
     * GET /api/v1/orders/{uuid}
     *
     * One order in full, including its timeline and payment attempts.
     */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->orders->showForCustomer($request, (string) $request->routeParam('uuid')),
            'Order loaded'
        );
    }

    /**
     * GET /api/v1/orders/{uuid}/invoice
     *
     * GST invoice data. Available once payment is confirmed.
     */
    public function invoice(Request $request): Response
    {
        return Response::success(
            $this->orders->invoice($request, (string) $request->routeParam('uuid')),
            'Invoice loaded'
        );
    }

    /**
     * POST /api/v1/orders/{uuid}/cancel
     *
     * Cancel an order. Releases any coupon, returns wallet credit and refunds what reached the gateway.
     */
    public function cancel(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:255',
        ]);

        return Response::success(
            $this->orders->cancel($request, (string) $request->routeParam('uuid'), $data['reason']),
            'Order cancelled'
        );
    }

    /**
     * POST /api/v1/orders/track
     *
     * Order number AND the mobile it shipped to. An order number alone appears
     * on a parcel label, so it must not be enough to reveal an address.
     */
    public function track(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'order_number' => 'required|string|min:6|max:30',
            'mobile' => 'required|mobile_in',
        ]);

        return Response::success(
            $this->orders->track($data['order_number'], $data['mobile']),
            'Order found'
        );
    }

    // -----------------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/orders */
    public function adminIndex(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $status = $this->optionalStatus($request->query('status'));
        $paymentStatus = $request->query('payment_status');

        if (is_string($paymentStatus) && $paymentStatus !== '' && !PaymentStatus::exists($paymentStatus)) {
            throw new HttpException('Unknown payment status: ' . $paymentStatus, 422);
        }

        $result = $this->orders->listForStaff(
            $params,
            $status,
            is_string($paymentStatus) && $paymentStatus !== '' ? $paymentStatus : null
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Orders loaded');
    }

    /** GET /api/v1/admin/orders/{uuid} */
    public function adminShow(Request $request): Response
    {
        return Response::success(
            $this->orders->showForStaff((string) $request->routeParam('uuid')),
            'Order loaded'
        );
    }

    /** POST /api/v1/admin/orders/{uuid}/status */
    public function adminAdvance(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'status' => 'required|string|max:30',
            'note' => 'nullable|string|max:500',
        ]);

        if (!OrderStatus::exists($data['status'])) {
            throw new HttpException('Unknown order status: ' . $data['status'], 422);
        }

        return Response::success(
            $this->orders->advance(
                $request,
                (string) $request->routeParam('uuid'),
                $data['status'],
                $data['note'] ?? null
            ),
            'Order updated'
        );
    }

    /** POST /api/v1/admin/orders/{uuid}/cancel */
    public function adminCancel(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:255',
            // Which of the two admin-facing destinations a captured payment is
            // refunded to. Left out or 'gateway' keeps the original behaviour
            // (refunded to how the customer paid); a customer cancelling their
            // own order (OrderController::cancel() above) has no such choice —
            // OrderService::cancel() only honours this when isStaff is true.
            'refund_method' => 'nullable|in:gateway,wallet',
        ]);

        return Response::success(
            $this->orders->cancel(
                $request,
                (string) $request->routeParam('uuid'),
                $data['reason'],
                isStaff: true,
                refundMethod: $data['refund_method'] ?? null,
            ),
            'Order cancelled'
        );
    }

    /**
     * GET /api/v1/admin/orders/cod/pending
     *
     * COD orders awaiting approval or decline. Kept on OrderController rather
     * than ManualPaymentController — this is an order-approval queue, not a
     * payment-verification one; no PaymentGatewayInterface is involved at all.
     */
    public function adminCodPending(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        return Response::success(
            $this->orders->pendingCod($page, $perPage),
            'Pending Cash on Delivery orders loaded'
        );
    }

    /**
     * POST /api/v1/admin/orders/{uuid}/cod/approve
     *
     * The only route that can pass isCodApproved=true into OrderStateMachine
     * (via OrderService::approveCod). Confirms the order without any payment
     * having been collected — the cash is taken on delivery, not here.
     */
    public function adminCodApprove(Request $request): Response
    {
        return Response::success(
            $this->orders->approveCod($request, (string) $request->routeParam('uuid')),
            'Cash on Delivery approved — order confirmed'
        );
    }

    /** POST /api/v1/admin/orders/{uuid}/cod/decline */
    public function adminCodDecline(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:255',
        ]);

        return Response::success(
            $this->orders->declineCod($request, (string) $request->routeParam('uuid'), $data['reason']),
            'Cash on Delivery declined'
        );
    }

    /** GET /api/v1/admin/orders/{uuid}/invoice */
    public function adminInvoice(Request $request): Response
    {
        return Response::success(
            $this->orders->invoice($request, (string) $request->routeParam('uuid'), isStaff: true),
            'Invoice loaded'
        );
    }

    /** POST /api/v1/admin/orders/expire-unpaid */
    public function adminExpireUnpaid(Request $request): Response
    {
        return Response::success(
            $this->payments->expireUnpaidOrders($request),
            'Unpaid orders released'
        );
    }

    private function optionalStatus(mixed $status): ?string
    {
        if (!is_string($status) || $status === '') {
            return null;
        }

        if (!OrderStatus::exists($status)) {
            throw new HttpException('Unknown order status: ' . $status, 422);
        }

        return $status;
    }
}
