<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use App\Helpers\Jwt;
use App\Services\NotificationService;
use App\Services\Notifications\HttpSmsGateway;
use App\Services\Notifications\LogChannel;
use App\Services\Notifications\NotificationPolicy;
use App\Services\Notifications\SmsChannel;
use App\Repositories\SettingRepository;
use App\Services\Delivery\CourierAdapterInterface;
use App\Services\Delivery\ManualCourierAdapter;
use App\Services\Delivery\SandboxCourierAdapter;
use App\Services\Delivery\ShiprocketAdapter;
use App\Services\FileUploadService;
use App\Services\Payments\ManualGateway;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\RazorpayGateway;
use App\Services\Payments\SandboxGateway;
use App\Services\Notifications\LogSmsGateway;
use App\Services\Notifications\SmsGatewayInterface;

/**
 * Explicit bindings for everything that cannot be autowired from its type
 * (scalars, config arrays, interface -> implementation choices).
 * Plain services are autowired and need no entry here.
 */
$container = new Container();

$container->bind(Config::class, static fn (): Config => new Config(APP_ROOT . '/config'));

$container->bind(Logger::class, static function (Container $c): Logger {
    return new Logger(APP_ROOT . '/storage/logs');
});

$container->bind(Database::class, static function (Container $c): Database {
    /** @var Config $config */
    $config = $c->get(Config::class);

    return new Database((array) $config->get('database'));
});

// The payment gateway is chosen at runtime from a setting, so switching
// provider is a configuration change rather than a deployment. Everything above
// this line speaks only to PaymentGatewayInterface.
//
// The driver name itself has two sources: the `PAYMENT_DRIVER` env var (set at
// deploy time) and the `payment_driver` row in `settings` (editable from the
// admin console at /admin/settings without a redeploy). The DB value wins when
// present, which is what lets an administrator flip manual -> razorpay -> sandbox
// from a browser once Razorpay is ready, rather than needing SSH every time.

// Shared by both ManualGateway bindings below. The DB stores the QR image as
// a relative PATH (manual_payment_qr_path) set by SettingsController's
// upload endpoint — never a full URL, so it survives an APP_URL change (e.g.
// moving between local, staging and production) without needing re-upload.
// The absolute URL is built fresh on every resolution via FileUploadService,
// the same helper product/category images use, so this file must never read
// a 'manual_payment_qr_url' setting directly — no such row is ever written.
$resolveManualQrUrl = static function (Container $c, SettingRepository $settings): string {
    $path = $settings->value('manual_payment_qr_path');

    if ($path === null || $path === '') {
        return '';
    }

    /** @var FileUploadService $uploads */
    $uploads = $c->get(FileUploadService::class);

    return (string) ($uploads->publicUrl($path) ?? '');
};

$container->bind(PaymentGatewayInterface::class, static function (Container $c) use ($resolveManualQrUrl): PaymentGatewayInterface {
    /** @var Config $config */
    $config = $c->get(Config::class);
    /** @var Logger $logger */
    $logger = $c->get(Logger::class);
    /** @var SettingRepository $settings */
    $settings = $c->get(SettingRepository::class);

    $driver = $settings->value('payment_driver') ?? (string) $config->get('payment.driver', 'sandbox');

    return match ($driver) {
        'razorpay' => new RazorpayGateway(
            (string) $config->get('payment.razorpay.key_id', ''),
            (string) $config->get('payment.razorpay.key_secret', ''),
            (string) $config->get('payment.razorpay.webhook_secret', ''),
            $logger,
            (int) $config->get('payment.timeout_seconds', 20),
        ),
        'sandbox' => new SandboxGateway(
            (string) $config->get('payment.sandbox.secret', ''),
            (string) $config->get('app.env', 'production'),
            $logger,
        ),
        'manual' => new ManualGateway(
            $resolveManualQrUrl($c, $settings),
            (string) ($settings->value('manual_payment_vpa') ?? ''),
            (string) ($settings->value('manual_payment_payee_name') ?? 'Anjeera Dry Fruits'),
            $logger,
        ),
        default => throw new RuntimeException(
            'Unknown payment gateway "' . $driver . '". Set PAYMENT_DRIVER (or the payment_driver '
            . 'setting) to razorpay, sandbox, or manual.'
        ),
    };
});

// ManualGateway is also bound directly under its own class name, independent
// of which driver PaymentGatewayInterface currently resolves to. The admin
// payment-verification screen needs to build a manual PaymentVerification
// (via verifyByAdmin) even when the store has since been switched to
// razorpay for new orders — an in-flight manual payment placed before the
// cutover still has to be confirmable.
$container->bind(ManualGateway::class, static function (Container $c) use ($resolveManualQrUrl): ManualGateway {
    /** @var Logger $logger */
    $logger = $c->get(Logger::class);
    /** @var SettingRepository $settings */
    $settings = $c->get(SettingRepository::class);

    return new ManualGateway(
        $resolveManualQrUrl($c, $settings),
        (string) ($settings->value('manual_payment_vpa') ?? ''),
        (string) ($settings->value('manual_payment_payee_name') ?? 'Anjeera Dry Fruits'),
        $logger,
    );
});

// Courier adapter, chosen at runtime the same way the payment gateway is —
// COURIER_DRIVER env var, overridable from the admin console via the
// `delivery_driver` setting.
$container->bind(CourierAdapterInterface::class, static function (Container $c): CourierAdapterInterface {
    /** @var Config $config */
    $config = $c->get(Config::class);
    /** @var Logger $logger */
    $logger = $c->get(Logger::class);
    /** @var SettingRepository $settings */
    $settings = $c->get(SettingRepository::class);

    $driver = $settings->value('delivery_driver') ?? (string) $config->get('delivery.driver', 'sandbox');

    return match ($driver) {
        'shiprocket' => new ShiprocketAdapter(
            (string) $config->get('delivery.shiprocket.email', ''),
            (string) $config->get('delivery.shiprocket.password', ''),
            (string) $config->get('delivery.shiprocket.webhook_secret', ''),
            (string) $config->get('delivery.shiprocket.pickup_location', 'Primary'),
            $settings,
            $logger,
            (int) $config->get('delivery.timeout_seconds', 25),
        ),
        'sandbox' => new SandboxCourierAdapter(
            (string) $config->get('delivery.sandbox.secret', ''),
            (string) $config->get('app.env', 'production'),
            $logger,
        ),
        'manual' => new ManualCourierAdapter($logger),
        default => throw new RuntimeException(
            'Unknown courier adapter "' . $driver . '". Set COURIER_DRIVER (or the delivery_driver '
            . 'setting) to shiprocket, sandbox, or manual.'
        ),
    };
});

// Notification channels. Each falls back to the log driver in local and
// testing environments, which is what makes the whole queue path — dedupe,
// retry, quiet hours — exercisable without a gateway account.
$container->bind(NotificationService::class, static function (Container $c): NotificationService {
    /** @var Config $config */
    $config = $c->get(Config::class);
    /** @var Logger $logger */
    $logger = $c->get(Logger::class);

    $useLog = in_array((string) $config->get('app.env', 'production'), ['local', 'testing'], true)
        || (string) $config->get('notifications.driver', 'log') === 'log';

    $channels = [
        $useLog
            ? new LogChannel($logger, 'sms')
            : new SmsChannel($c->get(SmsGatewayInterface::class)),
        // Email, WhatsApp and push have no provider wired yet. They log rather
        // than silently discard, so a template addressed to them is visible in
        // the queue and in the log instead of vanishing.
        new LogChannel($logger, 'email'),
        new LogChannel($logger, 'whatsapp'),
        new LogChannel($logger, 'push'),
    ];

    return new NotificationService(
        $channels,
        $c->get(NotificationPolicy::class),
        $c->get(SettingRepository::class),
        $c->get(Database::class),
        $logger,
    );
});

$container->bind(Jwt::class, static function (Container $c): Jwt {
    /** @var Config $config */
    $config = $c->get(Config::class);

    return new Jwt(
        secret: (string) $config->get('auth.jwt.secret'),
        issuer: (string) $config->get('auth.jwt.issuer'),
        accessTtlSeconds: (int) $config->get('auth.jwt.access_ttl_seconds'),
        leewaySeconds: (int) $config->get('auth.jwt.leeway_seconds'),
    );
});

$container->bind(SmsGatewayInterface::class, static function (Container $c): SmsGatewayInterface {
    /** @var Config $config */
    $config = $c->get(Config::class);
    /** @var Logger $logger */
    $logger = $c->get(Logger::class);

    $smsConfig = (array) $config->get('notifications.sms');

    return $smsConfig['driver'] === 'http'
        ? new HttpSmsGateway($smsConfig, $logger)
        : new LogSmsGateway($logger);
});

return $container;
