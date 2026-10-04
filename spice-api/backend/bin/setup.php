<?php

declare(strict_types=1);

/**
 * First-time setup.
 *
 *   php bin/setup.php
 *
 * Creates .env from the template, generates the cryptographic secrets, checks
 * the database connection, and tells you exactly what is still wrong.
 *
 * This exists because the alternative is a checklist, and a checklist that says
 * "generate a 32-character secret and paste it in" gets skipped. The failure
 * then arrives much later as a 500 on the first login, pointing at a line of
 * JWT code rather than at the setup step that was missed.
 *
 * Safe to re-run. It never overwrites an existing secret, and never touches a
 * value you have already set.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));

/**
 * The configuration template, embedded so that setup never depends on a file
 * that a Windows extraction tool may have quietly skipped.
 *
 * Kept identical to .env.example. If you change one, change the other.
 */
const ENV_TEMPLATE = <<<'ENVFILE'
# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------
APP_NAME="Spice & Dry Fruits Commerce Platform"
APP_BRAND_NAME="Spice & Dry Fruits"
# local | staging | production
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_TIMEZONE=Asia/Kolkata
APP_CURRENCY=INR

# Exact origins only, comma separated. Leave empty for mobile-only access.
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:8080

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=spice_commerce
DB_USERNAME=spice_app
DB_PASSWORD=change-me
DB_TIME_ZONE=+05:30

# ---------------------------------------------------------------------------
# Authentication
# Generate both secrets with:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
# ---------------------------------------------------------------------------
JWT_SECRET=
JWT_ISSUER=spice-commerce-api
JWT_ACCESS_TTL_SECONDS=900
JWT_REFRESH_TTL_SECONDS=2592000

OTP_PEPPER=
OTP_LENGTH=6
OTP_TTL_SECONDS=300
OTP_RESEND_COOLDOWN_SECONDS=60
OTP_MAX_VERIFY_ATTEMPTS=5
OTP_MAX_PER_HOUR=6
# Returns the OTP in the API response. Honoured only when APP_ENV=local.
OTP_EXPOSE_IN_RESPONSE=true

BCRYPT_COST=12
LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCKOUT_MINUTES=15

# ---------------------------------------------------------------------------
# SMS gateway (log = write to storage/logs, http = call the provider)
# ---------------------------------------------------------------------------
SMS_DRIVER=log
SMS_ENDPOINT=
SMS_API_KEY=
SMS_AUTH_HEADER=Authorization
SMS_SENDER_ID=SPICEC
SMS_DLT_TEMPLATE_ID=
SMS_COUNTRY_CODE=91

# ---------------------------------------------------------------------------
# Uploads
# ---------------------------------------------------------------------------
UPLOAD_MAX_IMAGE_BYTES=5242880
UPLOAD_MIN_IMAGE_EDGE_PX=200
UPLOAD_MAX_IMAGE_EDGE_PX=6000
UPLOAD_MAX_IMAGES_PER_PRODUCT=10

# ---------------------------------------------------------------------------
# Commerce
# ---------------------------------------------------------------------------
CART_MAX_LINE_ITEMS=50
CART_ABANDON_AFTER_DAYS=30
WISHLIST_MAX_ITEMS=200
DELIVERY_GST_RATE=18
WALLET_MAX_REDEEM_PERCENT=20
WALLET_MIN_REDEEM_AMOUNT=10

# ---------------------------------------------------------------------------
# Payment (BR-004: prepaid UPI only)
# ---------------------------------------------------------------------------
# sandbox = settle locally for development; razorpay = live provider.
# The sandbox driver refuses to start unless APP_ENV is local or testing.
PAYMENT_DRIVER=sandbox
PAYMENT_CURRENCY=INR
PAYMENT_TIMEOUT_SECONDS=20

RAZORPAY_KEY_ID=
RAZORPAY_KEY_SECRET=
RAZORPAY_WEBHOOK_SECRET=

SANDBOX_PAYMENT_SECRET=sandbox-local-secret-change-me

# ---------------------------------------------------------------------------
# Delivery (BR-007: automatic courier selection)
# ---------------------------------------------------------------------------
# sandbox = book locally for development; shiprocket = live aggregator.
# The sandbox adapter refuses to start unless APP_ENV is local or testing.
COURIER_DRIVER=sandbox
COURIER_TIMEOUT_SECONDS=25

SHIPROCKET_EMAIL=
SHIPROCKET_PASSWORD=
SHIPROCKET_WEBHOOK_SECRET=
SHIPROCKET_PICKUP_LOCATION=Primary

SANDBOX_COURIER_SECRET=sandbox-courier-secret-change-me
ENVFILE;

$envPath = APP_ROOT . '/.env';
$examplePath = APP_ROOT . '/.env.example';

$problems = [];
$notes = [];

function heading(string $text): void
{
    printf("\n%s\n%s\n", $text, str_repeat('-', strlen($text)));
}

function ok(string $text): void
{
    printf("  OK    %s\n", $text);
}

function fixed(string $text): void
{
    printf("  DONE  %s\n", $text);
}

function problem(string $text): void
{
    global $problems;
    $problems[] = $text;
    printf("  NEEDS %s\n", $text);
}

echo "Spice & Dry Fruits — setup\n";
printf("Install directory: %s\n", APP_ROOT);

// ---------------------------------------------------------------------------
// PHP itself
// ---------------------------------------------------------------------------
heading('PHP');

if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    problem(sprintf('PHP 8.3 or newer. You have %s.', PHP_VERSION));
} else {
    ok(sprintf('PHP %s', PHP_VERSION));
}

// The exact file to edit. On Windows this is the whole answer: XAMPP ships
// several php.ini files and the command line does not necessarily use the one
// the web server does, so "enable it in php.ini" is not actionable on its own.
$iniPath = php_ini_loaded_file();
printf("  PHP binary:       %s\n", PHP_BINARY);
printf("  Using php.ini at: %s\n", $iniPath === false ? '(NONE LOADED)' : $iniPath);

// No php.ini at all is a different problem from a missing extension, and it
// explains ALL of them at once. On Windows it almost always means the `php` on
// PATH is a standalone download rather than the one that came with XAMPP —
// which has its own php.ini and its own extensions already enabled.
if ($iniPath === false) {
    $xamppPhp = 'C:\\xampp\\php\\php.exe';

    problem(
        "no php.ini is loaded, so NO extensions are enabled. Every 'missing extension'"
        . "\n         below has this one cause."
        . "\n"
        . "\n         The PHP you are running is:  " . PHP_BINARY
        . "\n"
        . "\n         If you have XAMPP, its PHP is already configured. Use it directly:"
        . "\n           " . $xamppPhp . " bin\\setup.php"
        . "\n"
        . "\n         Or put it first on your PATH so plain `php` means XAMPP's PHP."
        . "\n"
        . "\n         If you are NOT using XAMPP's PHP, create a php.ini beside "
        . PHP_BINARY . ':'
        . "\n           copy php.ini-development php.ini"
        . "\n         then enable the extensions listed below in it."
    );
}

foreach (['pdo_mysql' => 'database access', 'mbstring' => 'text handling',
          'json' => 'API responses', 'openssl' => 'token signing',
          'curl' => 'calling payment and courier providers'] as $extension => $why) {
    if (extension_loaded($extension)) {
        ok(sprintf('%s extension (%s)', $extension, $why));
        continue;
    }

    problem(sprintf(
        "the %s extension, needed for %s."
        . "\n         Edit:   %s"
        . "\n         Find:   ;extension=%s"
        . "\n         Change to (remove the semicolon):   extension=%s"
        . "\n         Then restart your terminal, and Apache if it is running.",
        $extension,
        $why,
        $iniPath === false ? '(no php.ini is loaded — copy php.ini-development to php.ini)' : $iniPath,
        $extension,
        $extension
    ));
}

// The command line and the web server can load different php.ini files, which
// produces the baffling case where the site works but bin/migrate.php does not
// (or the reverse). Worth stating rather than leaving to be discovered.
if ($iniPath !== false) {
    $notes[] = sprintf(
        'This is the php.ini used by the COMMAND LINE (%s). Your web server may load a '
        . 'different one — check with a phpinfo() page if the site and these scripts '
        . 'disagree about which extensions exist.',
        $iniPath
    );
}

// ---------------------------------------------------------------------------
// The environment file
// ---------------------------------------------------------------------------
heading('Configuration file');

if (!is_file($envPath)) {
    if (is_file($examplePath)) {
        copy($examplePath, $envPath);
        fixed('created .env from .env.example');
    } else {
        // Written from the template built into this script rather than failing.
        //
        // .env.example is a dotfile, and several Windows extraction tools skip
        // dotfiles silently. Refusing to continue because an OPTIONAL template
        // is absent — when the contents are known and can simply be written —
        // turns a cosmetic packaging quirk into a dead end.
        file_put_contents($envPath, ENV_TEMPLATE);
        fixed('created .env from the built-in template (.env.example was missing)');
        $notes[] = 'Your extraction tool appears to have skipped dotfiles. The .htaccess '
            . 'files are also dotfiles and are REQUIRED — without them the API returns '
            . '403 or 404 for every request. Re-extract with 7-Zip, or check that '
            . 'backend/.htaccess and backend/public/.htaccess exist.';
    }
} else {
    ok('.env exists');
}

$env = (string) file_get_contents($envPath);

/** Reads a value from the .env text. */
$read = static function (string $key) use (&$env): ?string {
    if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*(.*)$/m', $env, $match)) {
        return trim($match[1], " \t\"'");
    }

    return null;
};

/** Writes a value, adding the key if it is absent. */
$write = static function (string $key, string $value) use (&$env): void {
    $line = $key . '=' . $value;

    if (preg_match('/^' . preg_quote($key, '/') . '\s*=.*$/m', $env)) {
        $env = (string) preg_replace('/^' . preg_quote($key, '/') . '\s*=.*$/m', $line, $env);

        return;
    }

    $env .= "\n" . $line . "\n";
};

// ---------------------------------------------------------------------------
// Secrets
//
// Generated rather than requested. A secret a person invents is a secret a
// person can remember, which is the opposite of what is wanted here.
// ---------------------------------------------------------------------------
heading('Secrets');

$secrets = [
    'JWT_SECRET' => 'signs access tokens',
    'OTP_PEPPER' => 'protects stored OTP hashes',
    'SANDBOX_PAYMENT_SECRET' => 'signs local test payment webhooks',
    'SANDBOX_COURIER_SECRET' => 'signs local test tracking webhooks',
];

foreach ($secrets as $key => $purpose) {
    $current = $read($key);

    // The shipped placeholders are not secrets; treat them as empty.
    $isPlaceholder = $current === null
        || $current === ''
        || str_contains($current, 'change-me')
        || strlen($current) < 32;

    if ($isPlaceholder) {
        $write($key, bin2hex(random_bytes(32)));
        fixed(sprintf('generated %s (%s)', $key, $purpose));
    } else {
        ok(sprintf('%s is already set', $key));
    }
}

file_put_contents($envPath, $env);

// Nobody but the owner should be able to read this file.
if (DIRECTORY_SEPARATOR === '/') {
    @chmod($envPath, 0o640);

    // 0640 ALONE CAN LOCK OUT THE WEB SERVER.
    //
    // If setup runs as root (or as a deploying user) and PHP-FPM or Apache runs
    // as someone else, tightening permissions without also fixing the group
    // makes .env unreadable to the application — and the symptom is a "Service
    // degraded" health check citing the DEFAULT credentials, because the real
    // ones could not be read. Found exactly that way.
    //
    // The storage directory has to be writable by the web server, so its owner
    // identifies which account that is.
    $storageOwner = @fileowner(APP_ROOT . '/storage/logs');
    $envOwner = @fileowner($envPath);

    if ($storageOwner !== false && $envOwner !== false && $storageOwner !== $envOwner) {
        $storageGroup = @filegroup(APP_ROOT . '/storage/logs');

        if ($storageGroup !== false && @chgrp($envPath, $storageGroup)) {
            fixed('.env set to 0640 and shared with the web server\'s group');
        } else {
            problem(sprintf(
                '.env is mode 0640 but owned by a different account from the web server, '
                . 'which therefore cannot read it. The application will fall back to the '
                . 'template defaults and fail to connect. Fix with:'
                . "\n         sudo chgrp %s %s"
                . "\n         sudo chmod 640 %s",
                'www-data',
                $envPath,
                $envPath
            ));
        }
    } else {
        ok('.env permissions tightened to 0640');
    }
} else {
    $notes[] = 'On Windows, make sure .env is not inside a folder your web server '
        . 'lists publicly. The bundled .htaccess already blocks it on Apache.';
}

// ---------------------------------------------------------------------------
// Writable directories
// ---------------------------------------------------------------------------
heading('Writable directories');

// public/uploads needs its product subdirectory too: the uploader creates
// dated folders beneath it, and creating those requires write access to the
// parent. A product image upload failing with "Permission denied" after the
// merchant has filled in a whole form is a poor way to discover that.
foreach (['storage/logs', 'storage/framework', 'public/uploads',
          'public/uploads/products'] as $relative) {
    $path = APP_ROOT . '/' . $relative;

    if (!is_dir($path)) {
        @mkdir($path, 0o775, true);
    }

    if (is_dir($path) && is_writable($path)) {
        ok($relative);
        continue;
    }

    problem(sprintf(
        '%s must be writable by the web server. On Linux: sudo chown -R www-data:www-data %s',
        $relative,
        $relative
    ));
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
heading('Database');

$host = $read('DB_HOST') ?: '127.0.0.1';
$port = $read('DB_PORT') ?: '3306';
$name = $read('DB_DATABASE') ?: 'spice_commerce';
$user = $read('DB_USERNAME') ?: '';
$password = $read('DB_PASSWORD') ?: '';

printf("  Using %s@%s:%s, database \"%s\"\n", $user === '' ? '(no user)' : $user, $host, $port, $name);

if ($password === 'change-me') {
    problem('DB_PASSWORD is still the placeholder "change-me". Set your real password in .env.');
}

$connected = false;

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );

    ok('connected to MySQL');
    $connected = true;

    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

    if (version_compare($version, '8.0.16', '<')) {
        problem(sprintf(
            'MySQL 8.0.16 or newer. You have %s, which ignores CHECK constraints '
            . 'this schema relies on for correctness.',
            $version
        ));
    } else {
        ok(sprintf('MySQL %s', $version));
    }

    $exists = $pdo->query(
        sprintf(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = %s',
            $pdo->quote($name)
        )
    )->fetchColumn();

    if ((int) $exists === 0) {
        $pdo->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', $name)
        ));
        fixed(sprintf('created the database "%s"', $name));
    } else {
        ok(sprintf('the database "%s" exists', $name));
    }

    $pdo->exec(sprintf('USE `%s`', str_replace('`', '', $name)));

    $tables = (int) $pdo->query(
        sprintf(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s',
            $pdo->quote($name)
        )
    )->fetchColumn();

    if ($tables === 0) {
        $notes[] = 'The database is empty. Run:  php bin/migrate.php';
    } else {
        ok(sprintf('%d table(s) present', $tables));

        $admins = 0;

        try {
            $admins = (int) $pdo->query(
                "SELECT COUNT(*) FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE r.code = 'administrator' AND u.is_deleted = 0"
            )->fetchColumn();
        } catch (Throwable) {
            // Schema not fully applied; the migration note below covers it.
        }

        if ($admins === 0) {
            $notes[] = 'No administrator account yet. Run:  php bin/seed_admin.php';
        } else {
            ok(sprintf('%d administrator account(s)', $admins));
        }
    }
} catch (PDOException $exception) {
    $message = $exception->getMessage();

    // MySQL reports several very different problems through one exception, and
    // the raw text is not much help. Naming the actual cause is the difference
    // between a two-minute fix and an afternoon.
    if (str_contains($message, '1045')) {
        problem(sprintf(
            'correct database credentials. MySQL rejected the username or password '
            . 'for "%s". Under XAMPP the default is user "root" with an EMPTY password '
            . '(leave DB_PASSWORD blank).',
            $user
        ));
    } elseif (str_contains($message, '1044')) {
        problem(sprintf(
            'permission for "%s" to use the database "%s". The credentials are correct '
            . 'but that user cannot create or open it. Either create the database by '
            . 'hand in phpMyAdmin, or grant the user rights:'
            . "\n         CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
            . "\n         GRANT ALL ON `%s`.* TO '%s'@'localhost';",
            $user,
            $name,
            $name,
            $name,
            $user
        ));
    } elseif (str_contains($message, '2002') || str_contains($message, 'refused')) {
        problem(sprintf(
            'MySQL to be running and reachable at %s:%s. Nothing answered there. '
            . 'Under XAMPP, start MySQL from the control panel.',
            $host,
            $port
        ));
    } else {
        problem(sprintf('a working database connection. MySQL said: %s', $message));
        $notes[] = 'Check DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD in .env.';
    }
}

// ---------------------------------------------------------------------------
// Environment sanity
// ---------------------------------------------------------------------------
heading('Environment');

$appEnv = $read('APP_ENV') ?: 'production';
ok(sprintf('APP_ENV=%s', $appEnv));

// APP_URL is not cosmetic. Uploaded image URLs are BUILT from it, so if it does
// not match the address the site is actually served from, every product
// photograph 404s in the browser while the upload itself reported success.
$appUrl = (string) ($read('APP_URL') ?? '');
printf("  APP_URL=%s\n", $appUrl === '' ? '(not set)' : $appUrl);

if ($appUrl === '') {
    problem('APP_URL is not set. Product image URLs are built from it, so images '
        . 'will not load.');
} else {
    $notes[] = sprintf(
        'Confirm APP_URL (%s) is EXACTLY the address you open the API on. Product '
        . 'image URLs are built from it — if it is wrong, uploads succeed but the '
        . 'pictures never appear. For a subdirectory install it looks like '
        . 'http://localhost/spice-api/backend/public',
        $appUrl
    );
}

if ($appEnv === 'local') {
    $notes[] = 'APP_ENV=local exposes OTP codes in API responses and allows the sandbox '
        . 'payment and courier drivers. Set it to production before going live.';
} else {
    if (($read('PAYMENT_DRIVER') ?: '') === 'sandbox') {
        problem('PAYMENT_DRIVER=sandbox outside a local environment. The sandbox gateway '
            . 'refuses to start, and no order could ever be paid for.');
    }

    if (($read('OTP_EXPOSE_IN_RESPONSE') ?: '') === 'true') {
        problem('OTP_EXPOSE_IN_RESPONSE=true outside a local environment. This hands '
            . 'anyone the verification code for any number.');
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo str_repeat('=', 68) . "\n";

if ($problems === []) {
    echo "Setup is complete.\n";

    if ($notes !== []) {
        echo "\nNext:\n";

        foreach ($notes as $note) {
            printf("  · %s\n", $note);
        }
    }

    echo "\nThen check the API answers:\n";
    printf("  %s/api/v1/health\n", rtrim((string) ($read('APP_URL') ?: 'http://localhost'), '/'));
    echo "\nAnd point the storefront at that same address, plus /api/v1, in\n";
    echo "web/assets/js/config.js\n";

    exit(0);
}

printf("%d thing(s) still need attention:\n\n", count($problems));

foreach ($problems as $index => $text) {
    printf("  %d. %s\n", $index + 1, $text);
}

if ($notes !== []) {
    echo "\nAlso worth knowing:\n";

    foreach ($notes as $note) {
        printf("  · %s\n", $note);
    }
}

echo "\nFix those and run this again.\n";

exit(1);
