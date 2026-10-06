<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\SettingsService;

/**
 * Administrator-only: the payment/delivery driver toggle and manual-mode
 * configuration (QR image, VPA, delivery estimate window).
 *
 * This is what lets a store move manual -> razorpay/shiprocket, or back, from
 * the admin console instead of SSH. Restricted to `role:administrator` in
 * routes/api_v1.php — a supervisor or executive has no reason to change which
 * payment gateway the entire store is running on.
 */
final class SettingsController extends BaseController
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /** GET /api/v1/admin/settings */
    public function index(Request $request): Response
    {
        return Response::success($this->settings->current(), 'Settings loaded');
    }

    /** PATCH /api/v1/admin/settings/payment-driver */
    public function setPaymentDriver(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'driver' => 'required|string',
        ]);

        return Response::success(
            $this->settings->setPaymentDriver($request, $data['driver']),
            'Payment driver updated'
        );
    }

    /** PATCH /api/v1/admin/settings/delivery-driver */
    public function setDeliveryDriver(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'driver' => 'required|string',
        ]);

        return Response::success(
            $this->settings->setDeliveryDriver($request, $data['driver']),
            'Delivery driver updated'
        );
    }

    /** PATCH /api/v1/admin/settings/price-change-mode */
    public function setPriceChangeMode(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'mode' => 'required|string',
        ]);

        return Response::success(
            $this->settings->setPriceChangeMode($request, $data['mode']),
            'Price-change mode updated'
        );
    }

    /** PATCH /api/v1/admin/settings/cod */
    /**
     * PATCH /api/v1/admin/settings/otp
     *
     * Switch mobile OTP verification on or off for the whole shop: order confirmation codes, number verification at sign-up, and OTP / phone sign-in. Off = orders go straight to payment and new accounts are signed in at once.
     */
    public function setOtpEnabled(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
        ]);

        return Response::success(
            $this->settings->setOtpEnabled($request, (bool) $data['enabled']),
            $data['enabled'] ? 'OTP verification switched on' : 'OTP verification switched off'
        );
    }

    public function setCodEnabled(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
        ]);

        return Response::success(
            $this->settings->setCodEnabled($request, (bool) $data['enabled']),
            'Cash on Delivery setting updated'
        );
    }

    /**
     * PATCH /api/v1/admin/settings/pos-due-reminder
     *
     * How long to wait before nudging a customer about an unpaid/partially
     * paid POS bill, and how often to repeat it. Whether the reminder is
     * sent at all is the separate on/off switch at
     * PATCH /admin/scheduler/tasks/pos.due_reminders.
     */
    public function setPosDueReminderConfig(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'delay_hours' => 'required|int|min:1|max:720',
            'repeat_hours' => 'required|int|min:1|max:720',
        ]);

        return Response::success(
            $this->settings->setPosDueReminderConfig(
                $request,
                (int) $data['delay_hours'],
                (int) $data['repeat_hours']
            ),
            'POS due-reminder timing updated'
        );
    }

    /** PATCH /api/v1/admin/settings/manual */
    public function updateManual(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'manual_payment_vpa' => 'nullable|string|max:120',
            'manual_payment_payee_name' => 'nullable|string|max:120',
        ]);

        return Response::success(
            $this->settings->updateManualSettings(
                $request,
                $data['manual_payment_vpa'] ?? null,
                $data['manual_payment_payee_name'] ?? null,
            ),
            'Manual settings updated'
        );
    }

    /** POST /api/v1/admin/settings/manual/qr-image */
    public function setManualQrImage(Request $request): Response
    {
        if (!isset($request->files['image'])) {
            throw new HttpException('No image was received.', 422, [
                'image' => ['Attach the file as a multipart field named "image".'],
            ]);
        }

        return Response::success(
            $this->settings->setManualQrImage($request, $request->files['image']),
            'Manual payment QR code updated'
        );
    }

    /**
     * POST /api/v1/admin/settings/logo
     *
     * Uploads a store logo and returns its hosted URL. Does NOT wire the
     * result into the storefront or admin console automatically — both read
     * window.SPICE_BRAND.logoUrl from their own static assets/config.js, so
     * the returned URL still has to be pasted in there once, the same way
     * APP_URL and SPICE_API_BASE already are for this deployment.
     */
    public function setStoreLogo(Request $request): Response
    {
        if (!isset($request->files['image'])) {
            throw new HttpException('No image was received.', 422, [
                'image' => ['Attach the file as a multipart field named "image".'],
            ]);
        }

        return Response::success(
            $this->settings->setStoreLogo($request, $request->files['image']),
            'Logo uploaded — copy store_logo_url into assets/config.js as logoUrl'
        );
    }
}
