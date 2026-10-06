<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\CheckoutService;
use App\Services\PaymentService;

/**
 * Checkout and payment.
 *
 * The flow a client should implement:
 *   1. GET  /checkout/review        — addresses, totals, blockers
 *   2. POST /checkout/place         — creates the order, sends the OTP
 *   3. POST /checkout/orders/{u}/verify-otp   (BR-003)
 *   4. POST /checkout/orders/{u}/payment      — returns the UPI intent
 *   5. POST /checkout/orders/{u}/payment/callback  — after the UPI app returns
 *
 * Step 5 is a convenience, not the source of truth. The webhook confirms the
 * order even if the customer closes the browser mid-payment.
 */
final class CheckoutController extends BaseController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentService $payments,
    ) {
    }

    /**
     * GET /api/v1/checkout/review
     *
     * Everything the checkout screen needs: addresses, priced cart, tender split and blockers.
     */
    public function review(Request $request): Response
    {
        $addressUuid = $request->query('address_uuid');

        return Response::success(
            $this->checkout->review($request, is_string($addressUuid) && $addressUuid !== '' ? $addressUuid : null),
            'Checkout review loaded'
        );
    }

    /**
     * POST /api/v1/checkout/place
     *
     * Create the order from the cart. Re-prices first and refuses if the total moved since it was quoted.
     */
    public function place(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'address_uuid' => 'required|uuid',
            // The total the customer saw. If it has moved, placement is refused
            // and the new figure returned rather than charging a different amount.
            'expected_grand_total' => 'nullable|numeric|min:0',
            'delivery_slot' => 'nullable|string|max:60',
            'delivery_instructions' => 'nullable|string|max:500',
            'customer_note' => 'nullable|string|max:500',
            'is_gift' => 'nullable|boolean',
            'gift_message' => 'nullable|string|max:320',
            'channel' => 'nullable|in:web,android,ios',
            'payment_method' => 'nullable|in:upi,cod',
        ]);

        return Response::created($this->checkout->place($request, $data), 'Order placed');
    }

    /** POST /api/v1/checkout/orders/{uuid}/verify-otp  (BR-003) */
    public function verifyOtp(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'otp' => 'required|digits:6',
            'reference_token' => 'nullable|string|max:120',
        ]);

        return Response::success(
            $this->checkout->verifyOtp(
                $request,
                (string) $request->routeParam('uuid'),
                $data['otp'],
                $data['reference_token'] ?? null
            ),
            'Order verified'
        );
    }

    /** POST /api/v1/checkout/orders/{uuid}/verify-phone  (BR-003 via phone.email) */
    public function verifyPhone(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'user_json_url' => 'required|string|max:300',
        ]);

        return Response::success(
            $this->checkout->verifyPhone($request, (string) $request->routeParam('uuid'), $data['user_json_url']),
            'Order verified'
        );
    }

    /** POST /api/v1/checkout/orders/{uuid}/resend-otp */
    public function resendOtp(Request $request): Response
    {
        return Response::success(
            $this->checkout->resendOtp($request, (string) $request->routeParam('uuid')),
            'Verification code sent'
        );
    }

    /**
     * POST /api/v1/checkout/orders/{uuid}/payment
     *
     * Begin a UPI payment and return what the client needs to complete it.
     */
    public function startPayment(Request $request): Response
    {
        return Response::created(
            $this->payments->start($request, (string) $request->routeParam('uuid')),
            'Payment started'
        );
    }

    /**
     * POST /api/v1/checkout/orders/{uuid}/cod
     *
     * Chooses Cash on Delivery instead of UPI. The order was already created
     * with payment_method='cod' at /checkout/place; this is the step that
     * moves it into the admin approval queue, parallel to startPayment()
     * moving a UPI order into the gateway flow.
     */
    public function chooseCod(Request $request): Response
    {
        return Response::success(
            $this->checkout->chooseCod($request, (string) $request->routeParam('uuid')),
            'Cash on Delivery selected'
        );
    }

    /**
     * POST /api/v1/checkout/orders/{uuid}/payment/callback
     *
     * Report a client-side payment result. Treated as a hint only; the webhook is authoritative.
     */
    public function paymentCallback(Request $request): Response
    {
        return Response::success(
            $this->payments->handleCallback(
                $request,
                (string) $request->routeParam('uuid'),
                $request->all()
            ),
            'Payment processed'
        );
    }
}
