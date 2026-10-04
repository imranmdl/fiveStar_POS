<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\VendorPaymentService;

final class VendorPaymentController extends BaseController
{
    public function __construct(private readonly VendorPaymentService $payments)
    {
    }

    /** POST /api/v1/admin/purchase-orders/{uuid}/payments */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,upi,pos',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:255',
        ]);

        return Response::created(
            $this->payments->record((string) $request->routeParam('uuid'), $data, $request),
            'Payment recorded'
        );
    }

    /** GET /api/v1/admin/purchase-orders/{uuid}/payments */
    public function forOrder(Request $request): Response
    {
        return Response::success(
            ['payments' => $this->payments->forOrder((string) $request->routeParam('uuid'))],
            'Payments loaded'
        );
    }
}
