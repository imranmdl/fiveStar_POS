<?php

declare(strict_types=1);

/**
 * Go-live readiness check.
 *
 *   php bin/preflight.php
 *
 * Run this before pointing real customers at the site. It refuses to pass while
 * anything is set the way a development install is set.
 *
 * WHY THIS IS SEPARATE FROM setup.php. Setup answers "will this run?", which is
 * a question you ask on your own machine and want answered permissively.
 * Preflight answers "should real people and real money be sent here?", which
 * deserves the opposite disposition: assume nothing, refuse on doubt, and treat
 * a development convenience left switched on as a failure rather than a note.
 *
 * The checks that BLOCK are the ones where getting it wrong loses money, leaks
 * data, or breaks the law. The ones that WARN are things that will hurt later
 * but will not hurt the first customer.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

Env::load(APP_ROOT . '/.env');

$blockers = [];
$warnings = [];
$passes = 0;

function section(string $title): void
{
    printf("\n%s\n%s\n", $title, str_repeat('-', strlen($title)));
}

function pass(string $text): void
{
    global $passes;
    ++$passes;
    printf("  OK     %s\n", $text);
}

function block(string $text, string $fix = ''): void
{
    global $blockers;
    $blockers[] = ['text' => $text, 'fix' => $fix];
    printf("  BLOCK  %s\n", $text);

    if ($fix !== '') {
        printf("         %s\n", str_replace("\n", "\n         ", $fix));
    }
}

function warn(string $text, string $fix = ''): void
{
    global $warnings;
    $warnings[] = ['text' => $text, 'fix' => $fix];
    printf("  WARN   %s\n", $text);

    if ($fix !== '') {
        printf("         %s\n", str_replace("\n", "\n         ", $fix));
    }
}

/** Reads a value straight from .env, since config may apply its own defaults. */
function env(string $key, ?string $default = null): ?string
{
    $value = Env::get($key);

    return $value === null || $value === '' ? $default : (string) $value;
}

echo "Go-live readiness check\n";
printf("Install: %s\n", APP_ROOT);

$appEnv = (string) env('APP_ENV', 'production');
printf("APP_ENV: %s\n", $appEnv);

// ---------------------------------------------------------------------------
// Environment and debugging
// ---------------------------------------------------------------------------
section('Environment');

if ($appEnv === 'production') {
    pass('APP_ENV is production');
} else {
    block(
        sprintf('APP_ENV is "%s", not "production".', $appEnv),
        "Set APP_ENV=production in .env.\nSeveral safety rules below are enforced only outside local mode."
    );
}

if (env('APP_DEBUG', 'false') === 'true') {
    block(
        'APP_DEBUG is on, so exception messages, file paths and stack traces are '
        . 'returned in API responses.',
        'Set APP_DEBUG=false. Errors still go to storage/logs; they just stop '
        . "being shown to whoever sent the request."
    );
} else {
    pass('APP_DEBUG is off');
}

// This one is the reason preflight exists.
if (env('OTP_EXPOSE_IN_RESPONSE', 'false') === 'true') {
    block(
        'OTP_EXPOSE_IN_RESPONSE is on. The verification code is returned in the '
        . 'API response, so anyone can request a code for ANY mobile number and '
        . 'read it back — which defeats registration, OTP login and order '
        . 'confirmation in one setting.',
        'Set OTP_EXPOSE_IN_RESPONSE=false.'
    );
} else {
    pass('OTP codes are not returned in API responses');
}

$appUrl = (string) env('APP_URL', '');

if (str_starts_with($appUrl, 'https://')) {
    pass('APP_URL uses https');
} elseif ($appUrl === '') {
    warn('APP_URL is not set. Links in emails and messages will be wrong.');
} else {
    block(
        sprintf('APP_URL is "%s", which is not https.', $appUrl),
        'Tokens, passwords and payment details must not cross a plain HTTP '
        . "connection.\nInstall a certificate and set APP_URL to the https address."
    );
}

// ---------------------------------------------------------------------------
// Secrets
// ---------------------------------------------------------------------------
section('Secrets');

foreach ([
    'JWT_SECRET' => 'signs every access token; anyone holding it can forge a session as any user',
    'OTP_PEPPER' => 'protects stored OTP hashes',
] as $key => $why) {
    $value = (string) env($key, '');

    if ($value === '') {
        block(sprintf('%s is empty. It %s.', $key, $why),
            'php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"');
    } elseif (strlen($value) < 32) {
        block(sprintf('%s is only %d characters. It %s.', $key, strlen($value), $why),
            'Generate a fresh 64-character value.');
    } elseif (str_contains(strtolower($value), 'change') || str_contains($value, 'example')) {
        block(sprintf('%s still looks like a placeholder.', $key), 'Generate a real one.');
    } else {
        pass(sprintf('%s is set and long enough', $key));
    }
}

// A secret that has been in a shared document, a chat window or a screenshot is
// not a secret, and there is no way for a script to know. Say so.
warn('Rotate any secret that has ever been pasted into a chat, an email, a '
    . 'screenshot or a support ticket.',
    'That includes admin passwords. Treat them as public from the moment they leave your machine.');

// ---------------------------------------------------------------------------
// Payments — BR-004
// ---------------------------------------------------------------------------
section('Payments');

$paymentDriver = (string) env('PAYMENT_DRIVER', 'sandbox');

if ($paymentDriver === 'sandbox') {
    block(
        'PAYMENT_DRIVER is the sandbox. It settles payments locally without any '
        . 'money moving, and it refuses to start outside a local environment — so '
        . 'no order could ever be paid for.',
        'Set PAYMENT_DRIVER=razorpay and fill in the credentials below.'
    );
} else {
    pass(sprintf('PAYMENT_DRIVER is %s', $paymentDriver));

    foreach (['RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET'] as $key) {
        if ((string) env($key, '') === '') {
            block(sprintf('%s is empty.', $key),
                $key === 'RAZORPAY_WEBHOOK_SECRET'
                    ? "Without it, webhook signatures cannot be verified — and an\n"
                      . "unverified webhook means anyone who guesses an order id can mark\n"
                      . 'it paid.'
                    : 'Take it from your Razorpay dashboard.');
        } else {
            pass(sprintf('%s is set', $key));
        }
    }

    warn('Confirm the webhook URL is registered with the payment provider and '
        . 'reachable from the internet.',
        sprintf('It should point at %s/api/v1/webhooks/payment', rtrim($appUrl, '/')));
}

// ---------------------------------------------------------------------------
// Delivery — BR-007
// ---------------------------------------------------------------------------
section('Delivery');

$courierDriver = (string) env('COURIER_DRIVER', 'sandbox');

if ($courierDriver === 'sandbox') {
    block(
        'COURIER_DRIVER is the sandbox. Shipments are booked locally and no parcel '
        . 'is ever collected.',
        'Set COURIER_DRIVER=shiprocket and fill in the credentials.'
    );
} else {
    pass(sprintf('COURIER_DRIVER is %s', $courierDriver));

    foreach (['SHIPROCKET_EMAIL', 'SHIPROCKET_PASSWORD'] as $key) {
        if ((string) env($key, '') === '') {
            block(sprintf('%s is empty.', $key));
        } else {
            pass(sprintf('%s is set', $key));
        }
    }
}

// ---------------------------------------------------------------------------
// Messaging — the one that fails silently
// ---------------------------------------------------------------------------
section('Messaging');

$smsDriver = (string) env('SMS_DRIVER', 'log');

if ($smsDriver === 'log') {
    block(
        'SMS_DRIVER is "log". Messages are written to a file instead of being sent, '
        . 'so no customer would receive an OTP — and no order could be confirmed.',
        'Set SMS_DRIVER=http and fill in SMS_ENDPOINT and SMS_API_KEY.'
    );
} else {
    pass(sprintf('SMS_DRIVER is %s', $smsDriver));

    foreach (['SMS_ENDPOINT', 'SMS_API_KEY', 'SMS_SENDER_ID'] as $key) {
        if ((string) env($key, '') === '') {
            block(sprintf('%s is empty.', $key));
        } else {
            pass(sprintf('%s is set', $key));
        }
    }
}

// ---------------------------------------------------------------------------
// Database and web server
// ---------------------------------------------------------------------------
section('Database');

try {
    $config = new Config(APP_ROOT . '/config');
    $db = new Database((array) $config->get('database'));

    $version = (string) $db->scalar('SELECT VERSION()');
    pass(sprintf('connected to MySQL %s', $version));

    $applied = (int) $db->scalar('SELECT COUNT(*) FROM `schema_migrations`');

    // The migrations may sit beside the backend or inside it, depending on how
    // the archive was extracted. Reporting "0 exist" as a mismatch when the
    // directory simply was not found is a false alarm — and a preflight that
    // cries wolf gets ignored on the day it is right.
    $migrationDirectory = null;

    foreach ([dirname(APP_ROOT) . '/database/migrations', APP_ROOT . '/database/migrations'] as $candidate) {
        if (is_dir($candidate)) {
            $migrationDirectory = $candidate;

            break;
        }
    }

    if ($migrationDirectory === null) {
        warn(sprintf('Could not find the migrations directory, so the %d applied '
            . 'migration(s) could not be checked against it.', $applied),
            'Not necessarily a problem — it matters only if the schema is out of date.');
    } else {
        $files = glob($migrationDirectory . '/*.sql') ?: [];

        if ($applied >= count($files)) {
            pass(sprintf('all %d migration(s) applied', count($files)));
        } else {
            block(sprintf('%d migration(s) applied but %d exist.', $applied, count($files)),
                'php bin/migrate.php');
        }
    }

    $admins = (int) $db->scalar(
        "SELECT COUNT(*) FROM `users` u JOIN `roles` r ON r.`id` = u.`role_id`
          WHERE r.`code` = 'administrator' AND u.`is_deleted` = 0"
    );

    if ($admins > 0) {
        pass(sprintf('%d administrator account(s)', $admins));
    } else {
        block('No administrator account exists.', 'php bin/seed_admin.php');
    }

    // Test data must not go live with the shop.
    $demo = (int) $db->scalar(
        "SELECT COUNT(*) FROM `products`
          WHERE (`name` LIKE 'Test %' OR `name` LIKE 'Probe %' OR `product_code` LIKE 'TST%'
                 OR `product_code` LIKE 'PRB%') AND `is_deleted` = 0"
    );

    if ($demo > 0) {
        warn(sprintf('%d product(s) look like test data.', $demo),
            'Check the catalogue in the console and archive anything that is not real stock.');
    } else {
        pass('no obvious test products in the catalogue');
    }

    $orders = (int) $db->scalar('SELECT COUNT(*) FROM `orders` WHERE `is_deleted` = 0');

    if ($orders > 0) {
        warn(sprintf('%d order(s) already exist, presumably from testing.', $orders),
            "Real revenue reports will include them.\n"
            . 'Consider starting production on a clean database.');
    }

    // Unregistered SMS templates are dropped silently by the Indian DLT
    // platform, which is the single most confusing failure at launch.
    $unregistered = (int) $db->scalar(
        "SELECT COUNT(*) FROM `notification_templates`
          WHERE `channel` = 'sms' AND `is_active` = 1 AND `is_deleted` = 0
            AND (`provider_template_id` IS NULL OR `provider_template_id` = '')"
    );

    if ($unregistered > 0) {
        block(
            sprintf('%d SMS template(s) have no DLT registration id.', $unregistered),
            "Indian operators drop unregistered content SILENTLY — no error, no\n"
            . "delivery, nothing in any log. Register each template on your\n"
            . "provider's DLT portal and store the id:\n"
            . '  UPDATE notification_templates SET provider_template_id = ? WHERE code = ? AND channel = \'sms\';'
        );
    } else {
        pass('all active SMS templates carry a DLT registration id');
    }

    // The seeded policy pages are placeholders and say so.
    $placeholders = (int) $db->scalar(
        "SELECT COUNT(*) FROM `cms_pages` WHERE `body` LIKE '%PLACEHOLDER%' AND `is_deleted` = 0"
    );

    if ($placeholders > 0) {
        block(
            sprintf('%d policy page(s) still contain placeholder text.', $placeholders),
            "Shipping, returns, privacy and terms are a contract with the customer,\n"
            . "and Indian consumer law has specific disclosure requirements for food\n"
            . 'sellers. Have them reviewed by someone qualified, then edit them in the console.'
        );
    } else {
        pass('no placeholder text left in the policy pages');
    }
} catch (Throwable $exception) {
    block('Cannot reach the database: ' . $exception->getMessage(), 'php bin/setup.php');
}

// ---------------------------------------------------------------------------
// Files and permissions
// ---------------------------------------------------------------------------
section('Files');

foreach (['storage/logs', 'public/uploads'] as $relative) {
    $path = APP_ROOT . '/' . $relative;

    if (is_dir($path) && is_writable($path)) {
        pass(sprintf('%s is writable', $relative));
    } else {
        block(sprintf('%s is not writable by this user.', $relative),
            sprintf('sudo chown -R www-data:www-data %s', $relative));
    }
}

foreach (['.htaccess', 'public/.htaccess'] as $relative) {
    if (is_file(APP_ROOT . '/' . $relative)) {
        pass(sprintf('%s is present', $relative));
    } else {
        block(sprintf('%s is missing.', $relative),
            $relative === '.htaccess'
                ? 'Without it, .env and the application source are downloadable.'
                : 'Without it every API route returns 404 or 403.');
    }
}

if (is_file(APP_ROOT . '/.env') && DIRECTORY_SEPARATOR === '/') {
    $mode = fileperms(APP_ROOT . '/.env') & 0o777;

    if (($mode & 0o004) !== 0) {
        block(sprintf('.env is world-readable (mode %o).', $mode), 'chmod 640 .env');
    } else {
        pass(sprintf('.env is not world-readable (mode %o)', $mode));
    }
}

// ---------------------------------------------------------------------------
// Operations
// ---------------------------------------------------------------------------
section('Operations');

warn('Confirm the scheduler is running.',
    "Without it, unpaid orders are never released, notifications are never sent,\n"
    . "and wallet credit never expires:\n"
    . '  * * * * * cd ' . APP_ROOT . ' && php bin/scheduler.php >> storage/logs/scheduler.log 2>&1');

warn('Confirm database backups are running and that a restore has been tested.',
    'An untested backup is a hope, not a backup.');

warn('The admin console (web/admin/) must not be publicly reachable.',
    'Put it behind IP restriction, HTTP auth or a VPN. The API refuses non-staff '
    . 'accounts, but the console should not be an open door to try passwords against.');

warn('Load has never been tested against production hardware.',
    'The concurrency suite proves correctness under parallel requests, not capacity.');

// ---------------------------------------------------------------------------
// Verdict
// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 70) . "\n";

if ($blockers === []) {
    printf("READY. %d check(s) passed.\n", $passes);

    if ($warnings !== []) {
        printf("\n%d thing(s) still worth confirming by hand:\n\n", count($warnings));

        foreach ($warnings as $index => $warning) {
            printf("  %d. %s\n", $index + 1, $warning['text']);
        }
    }

    echo "\nNothing here can tell you whether a real payment settles or a real SMS\n";
    echo "arrives. Place one small real order end to end before you advertise the shop.\n";

    exit(0);
}

printf("NOT READY. %d blocker(s), %d warning(s), %d check(s) passed.\n\n",
    count($blockers), count($warnings), $passes);

foreach ($blockers as $index => $blocker) {
    printf("  %d. %s\n", $index + 1, $blocker['text']);
}

echo "\nFix the blockers and run this again.\n";

exit(1);
