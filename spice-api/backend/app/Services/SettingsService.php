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
        'store_name',
        'store_address_line1',
        'store_address_line2',
        'store_city',
        'store_state',
        'store_pincode',
        'store_phone',
        'store_email',
        'store_website',
        'seller_gstin',
        'receipt_show_cashier',
        'receipt_show_counter',
        'receipt_show_customer',
        'receipt_show_upi_qr',
        'receipt_show_offer',
        'receipt_offer_coupon_code',
        'receipt_offer_text',
    ];

    /** What the till receipt prints when the sale has it (all on by default). */
    private const RECEIPT_TOGGLES = [
        'receipt_show_cashier' => 'cashier',
        'receipt_show_counter' => 'counter',
        'receipt_show_customer' => 'customer',
        'receipt_show_upi_qr' => 'upi_qr',
        'receipt_show_offer' => 'offer',
    ];

    /**
     * Shop details printed on receipts, editable under Admin → Payments →
     * Settings → Shop details. Field => [label, max length].
     */
    private const SHOP_FIELDS = [
        'store_name' => ['Shop name', 120],
        'store_address_line1' => ['Address line 1', 160],
        'store_address_line2' => ['Address line 2', 160],
        'store_city' => ['City', 80],
        'store_state' => ['State', 80],
        'store_pincode' => ['PIN code', 6],
        'store_phone' => ['Phone', 20],
        'store_email' => ['Email', 120],
        'store_website' => ['Website', 160],
        'seller_gstin' => ['GSTIN', 15],
    ];

    public const DEFAULT_STORE_NAME = 'Five Star Spices & Dry Fruits';
    public const DEFAULT_WEBSITE = 'https://fivestarspices.com';

    /** A UPI ID (VPA) such as "fivestar@okaxis" or "9876543210@ybl". */
    public static function isValidVpa(string $vpa): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,255}@[a-zA-Z][a-zA-Z0-9]{1,63}$/', $vpa) === 1;
    }

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
            'shop' => $this->shopDetails(),
            'razorpay' => $this->razorpayStatus(),
            // Text messages (OTP codes, order updates). Any SMS_DRIVER but "http" means
            // nothing is actually sent unless SMS_DRIVER=http — the dashboard warns about it.
            'sms_configured' => in_array((string) $this->config->get('notifications.sms.driver', 'log'), ['http', 'msg91'], true),
            'otp_shown_on_screen' => $this->config->get('auth.otp.expose_in_response', false) === true,
            'otp_enabled' => $this->settings->boolValue('order_otp_required', true)
                || $this->settings->boolValue('account_otp_required', true),
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
    /**
     * One switch for mobile OTP verification: order confirmation codes,
     * verifying the number at sign-up, and OTP / phone sign-in. Off is meant
     * for a shop with no working SMS yet — orders go straight to payment and
     * new accounts are active at once (their number stays unverified).
     */
    public function setOtpEnabled(Request $request, bool $enabled): array
    {
        $this->write('order_otp_required', $enabled ? '1' : '0', $request);
        $this->write('account_otp_required', $enabled ? '1' : '0', $request);

        return $this->current();
    }

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
     * Whether Razorpay keys are present, and whether they are test or live
     * keys. Never returns the keys themselves.
     *
     * @return array{configured:bool, mode:?string, webhook_configured:bool}
     */
    private function razorpayStatus(): array
    {
        $keyId = (string) $this->config->get('payment.razorpay.key_id', '');
        $secret = (string) $this->config->get('payment.razorpay.key_secret', '');
        $configured = $keyId !== '' && $secret !== '';

        return [
            'configured' => $configured,
            'mode' => $configured ? (str_starts_with($keyId, 'rzp_live_') ? 'live' : 'test') : null,
            'webhook_configured' => (string) $this->config->get('payment.razorpay.webhook_secret', '') !== '',
        ];
    }

    /**
     * Switches the payment driver. Razorpay can only be chosen once its keys
     * are set; otherwise every customer payment would fail.
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

        if ($driver === 'razorpay' && !$this->razorpayStatus()['configured']) {
            throw new HttpException(
                'Razorpay keys are not set. Add RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET to the server settings first.',
                422,
                ['driver' => ['Razorpay keys are not configured.']]
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
     * Shop details for receipts. Address and phone fall back to the given
     * warehouse row (the till's store) when not filled in here. `upi` is the
     * shop's own UPI ID from the payment settings — null when none is set or
     * it isn't a valid UPI ID, so nothing can print a QR code to a bad
     * address. Holds no secrets.
     *
     * @param array<string, mixed>|null $warehouse
     *
     * @return array<string, mixed>
     */
    public function shopDetails(?array $warehouse = null): array
    {
        $get = fn (string $key): string => trim((string) ($this->settings->value($key) ?? ''));

        $hasOwnAddress = $get('store_address_line1') !== '';
        $fromWarehouse = static fn (string $column): string => trim((string) ($warehouse[$column] ?? ''));

        $name = $get('store_name') !== '' ? $get('store_name') : self::DEFAULT_STORE_NAME;
        $vpa = $get('manual_payment_vpa');
        $payee = $get('manual_payment_payee_name');

        return [
            'name' => $name,
            'address_line1' => $hasOwnAddress ? $get('store_address_line1') : $fromWarehouse('store_address_line1'),
            'address_line2' => $hasOwnAddress ? $get('store_address_line2') : $fromWarehouse('store_address_line2'),
            'city' => $hasOwnAddress ? $get('store_city') : $fromWarehouse('store_city'),
            'state' => $hasOwnAddress ? $get('store_state') : $fromWarehouse('store_state'),
            'pincode' => $hasOwnAddress ? $get('store_pincode') : $fromWarehouse('store_pincode'),
            'phone' => $get('store_phone') !== '' ? $get('store_phone') : $fromWarehouse('store_phone'),
            'email' => $get('store_email'),
            'website' => $this->settings->value('store_website') === null ? self::DEFAULT_WEBSITE : $get('store_website'),
            'gstin' => $get('seller_gstin'),
            'upi' => $vpa !== '' && self::isValidVpa($vpa)
                ? ['vpa' => $vpa, 'payee_name' => $payee !== '' ? $payee : $name]
                : null,
            // Optional lines on the printed receipt (each still prints only
            // when the sale actually has it).
            'receipt' => array_combine(
                array_values(self::RECEIPT_TOGGLES),
                array_map(fn (string $key): bool => $this->settings->boolValue($key, true), array_keys(self::RECEIPT_TOGGLES))
            ),
            // Offer box on receipts (see ReceiptOfferService).
            'offer_coupon_code' => $get('receipt_offer_coupon_code'),
            'offer_text' => $get('receipt_offer_text'),
            // Lets the admin page warn about a saved but unusable UPI ID.
            'upi_id_invalid' => $vpa !== '' && !self::isValidVpa($vpa),
        ];
    }

    /**
     * Saves the shop details printed on receipts. Only the fields sent are
     * changed; an empty string clears a field.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateShopDetails(Request $request, array $data): array
    {
        $clean = [];
        $errors = [];

        foreach (self::SHOP_FIELDS as $key => [$label, $max]) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            $value = trim(preg_replace('/\s+/', ' ', (string) $data[$key]) ?? '');

            if (mb_strlen($value) > $max) {
                $errors[$key][] = "{$label} can be at most {$max} characters.";

                continue;
            }

            if ($value !== '') {
                $ok = match ($key) {
                    'store_pincode' => preg_match('/^[1-9][0-9]{5}$/', $value) === 1,
                    'store_phone' => preg_match('/^\+?[0-9][0-9 \-]{6,18}$/', $value) === 1,
                    'store_email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'store_website' => preg_match('#^https?://[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$#i', $value) === 1,
                    'seller_gstin' => preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', strtoupper($value)) === 1,
                    default => true,
                };

                if (!$ok) {
                    $errors[$key][] = match ($key) {
                        'store_pincode' => 'Enter a 6-digit PIN code.',
                        'store_phone' => 'Enter a phone number (digits, spaces or dashes).',
                        'store_email' => 'Enter a valid email address.',
                        'store_website' => 'Enter a full web address starting with https://',
                        'seller_gstin' => 'A GSTIN has 15 characters, e.g. 29ABCDE1234F1Z5.',
                        default => 'Invalid value.',
                    };

                    continue;
                }
            }

            $clean[$key] = $key === 'seller_gstin' ? strtoupper($value) : $value;
        }

        if (array_key_exists('receipt_offer_coupon_code', $data) && $data['receipt_offer_coupon_code'] !== null) {
            $code = strtoupper(trim((string) $data['receipt_offer_coupon_code']));

            if ($code !== '' && preg_match('/^[A-Z0-9_-]{2,30}$/', $code) !== 1) {
                $errors['receipt_offer_coupon_code'][] = 'Pick one of your coupon codes.';
            } else {
                $clean['receipt_offer_coupon_code'] = $code;
            }
        }

        if (array_key_exists('receipt_offer_text', $data) && $data['receipt_offer_text'] !== null) {
            $text = trim(preg_replace('/\s+/', ' ', (string) $data['receipt_offer_text']) ?? '');

            if (mb_strlen($text) > 200) {
                $errors['receipt_offer_text'][] = 'Keep the offer message under 200 characters.';
            } else {
                $clean['receipt_offer_text'] = $text;
            }
        }

        foreach (self::RECEIPT_TOGGLES as $key => $short) {
            $sent = $data['receipt'][$short] ?? ($data[$key] ?? null);

            if ($sent !== null) {
                $clean[$key] = filter_var($sent, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }
        }

        if ($errors !== []) {
            throw new HttpException('Please check the highlighted shop details.', 422, $errors);
        }

        foreach ($clean as $key => $value) {
            $this->write($key, $value, $request);
        }

        return $this->current();
    }

    /**
     * Updates the manual-payment display details: the VPA/payee name shown
     * under the QR code at checkout.
     */
    public function updateManualSettings(Request $request, ?string $vpa, ?string $payeeName): array
    {
        $vpa = $vpa === null ? null : trim($vpa);

        if ($vpa !== null && $vpa !== '' && !self::isValidVpa($vpa)) {
            throw new HttpException(
                'That UPI ID doesn\'t look right. It should look like name@bank, e.g. fivestar@okaxis.',
                422,
                ['manual_payment_vpa' => ['Enter a valid UPI ID (name@bank).']]
            );
        }

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
