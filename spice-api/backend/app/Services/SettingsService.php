<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Repositories\SettingRepository;

/**
 * Admin-editable runtime settings.
 *
 * Most configuration in this codebase lives in /config/*.php and is read once
 * at boot from environment variables — right for anything that shouldn't
 * change without a deployment (DB credentials, JWT secret). The handful of
 * settings here are different on purpose: which payment/delivery driver is
 * active, and the manual-payment QR/VPA details, are business decisions an
 * administrator needs to change from a browser while the store is running,
 * not something that should require SSH access. bootstrap/container.php reads
 * these through SettingRepository ahead of the .env default every time a
 * gateway or courier adapter is built, so a change here takes effect on the
 * next request — no redeploy, no restart.
 *
 * NOTE ON DELIVERY ESTIMATES: the customer-facing "delivers in N-M days" text
 * does NOT come from here or from CourierAdapterInterface. It comes from
 * `delivery_zones` (DeliveryZoneRepository / DeliveryChargeService), which is
 * already courier-independent — it is a lookup table, not a live API call —
 * and already returns sensible numbers (e.g. 1-2 days for local Bengaluru,
 * 4-7 for the rest of India) regardless of whether delivery_driver is
 * shiprocket or manual. Switching to manual delivery does not change that
 * estimate at all; it only removes live AWB tracking and label generation.
 * Edit sla_min_days/sla_max_days on the zones themselves (via the delivery
 * zone admin endpoints) to change what customers see.
 */
final class SettingsService
{
    private const PAYMENT_DRIVERS = ['manual', 'sandbox', 'razorpay'];
    private const DELIVERY_DRIVERS = ['manual', 'sandbox', 'shiprocket'];
    private const PRICE_CHANGE_MODES = ['always_ask', 'ask_on_increase', 'ask_on_decrease', 'auto_apply', 'never'];

    /** Keys this service will read/write. Anything else in `settings` is out of scope here. */
    private const MANAGED_KEYS = [
        'payment_driver',
        'delivery_driver',
        'manual_payment_vpa',
        'manual_payment_payee_name',
        'manual_payment_qr_url',
        'manual_payment_qr_path',
        'cod_enabled',
        'store_logo_path',
        'inventory_price_change_mode',
    ];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly FileUploadService $uploads,
        private readonly AuditService $audit,
        private readonly Config $config,
        private readonly Database $db,
    ) {
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $qrPath = $this->settings->value('manual_payment_qr_path');
        $logoPath = $this->settings->value('store_logo_path');

        return [
            'payment_driver' => $this->settings->value('payment_driver', 'manual'),
            'delivery_driver' => $this->settings->value('delivery_driver', 'manual'),
            'payment_driver_options' => self::PAYMENT_DRIVERS,
            'delivery_driver_options' => self::DELIVERY_DRIVERS,
            'manual_payment_vpa' => $this->settings->value('manual_payment_vpa', ''),
            'manual_payment_payee_name' => $this->settings->value('manual_payment_payee_name', 'Anjeera Dry Fruits'),
            'manual_payment_qr_url' => $qrPath !== null ? $this->uploads->publicUrl($qrPath) : null,
            // Independent of payment_driver on purpose — a store can accept COD
            // alongside whichever prepaid driver (manual/sandbox/razorpay) is
            // active; see CheckoutService::review()/place() for where a
            // customer actually sees and chooses it.
            'cod_enabled' => $this->settings->boolValue('cod_enabled', false),
            // Text messages (OTP codes, order updates). Any SMS_DRIVER but "http" means
            // nothing is actually sent unless SMS_DRIVER=http — the dashboard warns about it.
            'sms_configured' => (string) $this->config->get('notifications.sms.driver', 'log') === 'http',
            'otp_shown_on_screen' => $this->config->get('auth.otp.expose_in_response', false) === true,
            'image_storage' => $this->imageStorage(),
            // POS due-payment reminders (see 043_pos_due_reminders.sql):
            // whether the pos.due_reminders scheduled task is even allowed to
            // fire lives on that task's own is_enabled column, changed via
            // PATCH /admin/scheduler/tasks/pos.due_reminders — these two are
            // only the timing it uses once it does.
            'pos_due_reminder_delay_hours' => $this->settings->intValue('pos_due_reminder_delay_hours', 24),
            'pos_due_reminder_repeat_hours' => $this->settings->intValue('pos_due_reminder_repeat_hours', 72),
            // NOTE: this URL is not read automatically by the storefront or
            // admin console — both read window.SPICE_BRAND.logoUrl from their
            // own static assets/config.js, the same file that already holds
            // APP_URL/SPICE_API_BASE per deployment. Uploading here gives an
            // admin a real hosted URL to copy into that file once; it does
            // not wire itself in, because nothing else in this codebase reads
            // branding/config from the API at runtime either. Building a
            // public-settings-fetch mechanism just for the logo would be a
            // second, inconsistent way of doing what config.js already does
            // for every other per-deployment value.
            'store_logo_url' => $logoPath !== null ? $this->uploads->publicUrl($logoPath) : null,
            // Brief §7: whether/when a purchase inward should prompt for a
            // selling-price decision. 'never' (the seeded default) changes no
            // Phase 1/2 behaviour at all — see PurchaseOrderService.
            'inventory_price_change_mode' => $this->settings->value('inventory_price_change_mode', 'never'),
            'inventory_price_change_mode_options' => self::PRICE_CHANGE_MODES,
        ];
    }

    /** Turns Cash on Delivery on or off at checkout. */
    public function setCodEnabled(Request $request, bool $enabled): array
    {
        $this->write('cod_enabled', $enabled ? '1' : '0', $request);

        return $this->current();
    }

    /**
     * Replaces the store logo. Same shape as setManualQrImage() — goes
     * through FileUploadService, so the same content-sniffing and
     * path-safety checks apply.
     *
     * @param array<string, mixed> $file
     */
    public function setStoreLogo(Request $request, array $file): array
    {
        $previousPath = $this->settings->value('store_logo_path');

        $stored = $this->uploads->storeImage($file, 'branding');

        $this->write('store_logo_path', $stored['file_path'], $request);

        if ($previousPath !== null && $previousPath !== $stored['file_path']) {
            $this->uploads->delete($previousPath);
        }

        return $this->current();
    }

    /**
     * Switches the payment driver. Razorpay is allowed to be selected here
     * without live keys present — the RazorpayGateway constructor itself
     * refuses to build without RAZORPAY_KEY_ID/SECRET, so a half-configured
     * switch fails loudly on the very next payment attempt rather than here.
     */
    public function setPaymentDriver(Request $request, string $driver): array
    {
        if (!in_array($driver, self::PAYMENT_DRIVERS, true)) {
            throw new HttpException(
                'Unknown payment driver "' . $driver . '".',
                422,
                ['driver' => ['Must be one of: ' . implode(', ', self::PAYMENT_DRIVERS)]]
            );
        }

        $this->write('payment_driver', $driver, $request);

        return $this->current();
    }

    public function setDeliveryDriver(Request $request, string $driver): array
    {
        if (!in_array($driver, self::DELIVERY_DRIVERS, true)) {
            throw new HttpException(
                'Unknown delivery driver "' . $driver . '".',
                422,
                ['driver' => ['Must be one of: ' . implode(', ', self::DELIVERY_DRIVERS)]]
            );
        }

        $this->write('delivery_driver', $driver, $request);

        return $this->current();
    }

    /**
     * Updates the manual-payment display details: the VPA/payee name shown
     * under the QR code at checkout.
     */
    public function updateManualSettings(Request $request, ?string $vpa, ?string $payeeName): array
    {
        if ($vpa !== null) {
            $this->write('manual_payment_vpa', $vpa, $request);
        }

        if ($payeeName !== null) {
            $this->write('manual_payment_payee_name', $payeeName, $request);
        }

        return $this->current();
    }

    /**
     * Replaces the uploaded manual-payment QR image. Goes through
     * FileUploadService, so the same content-sniffing and path-safety
     * guarantees apply here as to product and category images.
     *
     * @param array<string, mixed> $file
     */
    public function setManualQrImage(Request $request, array $file): array
    {
        $previousPath = $this->settings->value('manual_payment_qr_path');

        $stored = $this->uploads->storeImage($file, 'payments');

        $this->write('manual_payment_qr_path', $stored['file_path'], $request);

        if ($previousPath !== null && $previousPath !== $stored['file_path']) {
            $this->uploads->delete($previousPath);
        }

        return $this->current();
    }

    /** Configures the brief's §7 "ask before changing selling price" behaviour. */
    public function setPriceChangeMode(Request $request, string $mode): array
    {
        if (!in_array($mode, self::PRICE_CHANGE_MODES, true)) {
            throw new HttpException(
                'Unknown price-change mode "' . $mode . '".',
                422,
                ['mode' => ['Must be one of: ' . implode(', ', self::PRICE_CHANGE_MODES)]]
            );
        }

        $this->write('inventory_price_change_mode', $mode, $request);

        return $this->current();
    }

    /**
     * Admin-controlled timing for the pos.due_reminders scheduled task —
     * "when should the notification go" per Admin Privilege Management's
     * sensitive-notification requirement. Turning the reminder off entirely
     * stays a separate action: PATCH /admin/scheduler/tasks/pos.due_reminders.
     */
    public function setPosDueReminderConfig(Request $request, int $delayHours, int $repeatHours): array
    {
        if ($delayHours < 1 || $delayHours > 720 || $repeatHours < 1 || $repeatHours > 720) {
            throw new HttpException(
                'Both values must be between 1 and 720 hours.',
                422,
                ['delay_hours' => ['Must be between 1 and 720.'], 'repeat_hours' => ['Must be between 1 and 720.']]
            );
        }

        $this->write('pos_due_reminder_delay_hours', (string) $delayHours, $request);
        $this->write('pos_due_reminder_repeat_hours', (string) $repeatHours, $request);

        return $this->current();
    }

    private function write(string $key, string $value, Request $request): void
    {
        $before = $this->settings->value($key);

        $this->settings->put($key, $value, $request->authUserId());

        $this->audit->log(
            entityName: 'settings',
            entityId: null,
            action: 'setting_updated',
            oldValues: [$key => $before],
            newValues: [$key => $value],
            request: $request,
            entityUuid: null,
            notes: $key,
        );
    }

    /**
     * Whether uploaded images survive a redeploy, and how many product
     * images the database lists whose file is gone. On Railway the files live
     * on the /data volume; without one, every deploy starts with an empty
     * uploads folder while the database still points at the old files, which
     * shows as blank product photos.
     *
     * @return array{volume_mounted:?bool, product_images:int, product_images_missing:int}
     */
    private function imageStorage(): array
    {
        $mounted = getenv('DATA_VOLUME_MOUNTED');
        $root = rtrim((string) $this->config->get('uploads.root_path', ''), '/');
        $rows = $this->db->select(
            "SELECT `file_path` FROM `product_media`
              WHERE `media_type` = 'image' AND `is_deleted` = 0 AND `is_active` = 1
                AND `file_path` IS NOT NULL AND `file_path` <> ''
                AND (`external_url` IS NULL OR `external_url` = '')
              LIMIT 5000"
        );
        $missing = 0;

        foreach ($rows as $row) {
            if ($root === '' || !is_file($root . '/' . ltrim((string) $row['file_path'], '/'))) {
                ++$missing;
            }
        }

        return [
            // null = not running in the container (e.g. shared hosting): unknown.
            'volume_mounted' => $mounted === false ? null : $mounted === 'true',
            'product_images' => count($rows),
            'product_images_missing' => $missing,
        ];
    }
}
