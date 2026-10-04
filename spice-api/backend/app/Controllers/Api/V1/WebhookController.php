<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Services\PaymentService;
use App\Services\ShipmentService;

/**
 * Inbound payment webhooks.
 *
 * Deliberately unauthenticated: a payment gateway has no bearer token. The
 * SIGNATURE is the authentication, verified against the webhook secret before
 * anything is acted on, which is what makes BR-005 enforceable.
 *
 * Always returns 200 or 202, never a 4xx or 5xx for business reasons. Gateways
 * treat any error as "retry later" and will hammer the endpoint for hours over
 * a problem retrying cannot fix. The response body says what happened; the
 * status code says "received".
 */
final class WebhookController extends BaseController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly ShipmentService $shipments,
    ) {
    }

    /**
     * POST /api/v1/webhooks/tracking
     *
     * Courier scan updates. Same contract as the payment webhook: the signature
     * authenticates it, and an unverified body is recorded and acted on by
     * nothing. Always acknowledged, so a courier does not retry-storm over a
     * problem retrying cannot fix.
     */
    public function tracking(Request $request): Response
    {
        $signature = $request->header('x-shiprocket-signature')
            ?? $request->header('x-webhook-signature')
            ?? $request->header('x-sandbox-signature')
            ?? '';

        $result = $this->shipments->handleWebhook($request, $request->rawBody, (string) $signature);

        return Response::success(
            $result,
            match ($result['status']) {
                'processed' => 'Tracking updated',
                'duplicate' => 'Already processed',
                'unmatched' => 'No matching shipment',
                default => 'Acknowledged',
            },
            $result['status'] === 'processed' ? 200 : 202
        );
    }

    /** POST /api/v1/webhooks/payment */
    public function payment(Request $request): Response
    {
        // The RAW body, not a re-encoded array: signatures are computed over
        // exact bytes, and json_encode(json_decode($body)) is not byte-identical.
        $rawBody = $request->rawBody;

        $signature = $request->header('x-razorpay-signature')
            ?? $request->header('x-webhook-signature')
            ?? $request->header('x-sandbox-signature')
            ?? '';

        $result = $this->payments->handleWebhook($request, $rawBody, (string) $signature);

        return Response::success(
            $result,
            match ($result['status']) {
                'processed' => 'Webhook processed',
                'duplicate' => 'Already processed',
                'rejected' => 'Acknowledged',
                'unmatched' => 'No matching order',
                default => 'Acknowledged',
            },
            $result['status'] === 'processed' ? 200 : 202
        );
    }
}
