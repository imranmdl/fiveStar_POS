<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ManualPaymentService;

/**
 * Administrator-only: reviewing and confirming manual UPI QR payments.
 *
 * Every route here is restricted to role:administrator in routes/api_v1.php.
 * That restriction is the entire security model for the manual payment
 * gateway — see ManualGateway and ManualPaymentService for why.
 */
final class ManualPaymentController extends BaseController
{
    public function __construct(private readonly ManualPaymentService $manualPayments)
    {
    }

    /** GET /api/v1/admin/payments/pending */
    public function pending(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        return Response::success(
            $this->manualPayments->pendingQueue($page, $perPage),
            'Pending manual payments loaded'
        );
    }

    /** POST /api/v1/admin/payments/{uuid}/verify */
    public function verify(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'confirmed_amount' => 'required|numeric',
            'utr_or_reference' => 'nullable|string|max:120',
        ]);

        return Response::success(
            $this->manualPayments->verify(
                $request,
                (string) $request->routeParam('uuid'),
                (string) $data['confirmed_amount'],
                (string) ($data['utr_or_reference'] ?? '')
            ),
            'Payment verified and order confirmed'
        );
    }

    /** POST /api/v1/admin/payments/{uuid}/reject */
    public function reject(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:255',
        ]);

        return Response::success(
            $this->manualPayments->reject(
                $request,
                (string) $request->routeParam('uuid'),
                $data['reason']
            ),
            'Payment rejected'
        );
    }
}
