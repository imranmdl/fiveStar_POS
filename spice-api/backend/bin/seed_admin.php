<?php

declare(strict_types=1);

/**
 * Creates or updates the administrator account.
 *
 *   php bin/seed_admin.php
 *
 * The password is prompted for and hashed at runtime; no credential is ever
 * written to a migration, a seed file or version control.
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
use App\Helpers\Str;
use App\Repositories\UserRepository;

Env::load(APP_ROOT . '/.env');

/** @var \App\Core\Container $container */
$container = require APP_ROOT . '/bootstrap/container.php';

// A raw PDOException stack trace tells someone setting this up nothing useful.
// The two causes are always the same: the driver is not enabled, or the
// credentials are wrong, and setup.php diagnoses both.
set_exception_handler(static function (Throwable $exception): void {
    $message = $exception->getMessage();

    fwrite(STDERR, "\nSetup problem: " . $message . "\n\n");

    if (str_contains($message, 'could not find driver')) {
        fwrite(STDERR, "PHP has no MySQL driver loaded. Enable pdo_mysql in your php.ini.\n");
    }

    fwrite(STDERR, "Run  php bin/setup.php  for a full check with the exact fix.\n");

    exit(1);
});
/** @var Database $db */
$db = $container->get(Database::class);
/** @var Config $config */
$config = $container->get(Config::class);
/** @var UserRepository $users */
$users = $container->get(UserRepository::class);

/**
 * Values supplied on the command line, for hosts with no interactive terminal.
 *
 * Shared hosting often has no SSH, only a cron runner that executes a PHP file
 * and captures its output — which means anything waiting on STDIN hangs forever
 * and the admin account can never be created. That is a real dead end, so every
 * prompt can be answered by an argument instead:
 *
 *   php bin/seed_admin.php --name="Your Name" --mobile=9876543210 \
 *       --email=you@example.com --password='ChooseAStrongOne123'
 *
 * TREAT A PASSWORD ON A COMMAND LINE AS EXPOSED. It can appear in the process
 * list and in shell history, and a cron entry stores it in plain text on the
 * server. Use this to get in, then change the password from your account page
 * and remove the cron entry.
 */
$supplied = getopt('', ['name::', 'mobile::', 'email::', 'password::']);

function prompt(string $question, bool $hidden = false, ?string $key = null): string
{
    global $supplied;

    if ($key !== null && isset($supplied[$key]) && $supplied[$key] !== false) {
        $value = trim((string) $supplied[$key]);
        printf("%s%s\n", $question, $hidden ? str_repeat('*', 8) : $value);

        return $value;
    }

    // No terminal and no argument: say so rather than hanging on a read that
    // will never return.
    if ($key !== null && (!defined('STDIN') || !stream_isatty(STDIN))) {
        fwrite(STDERR, sprintf(
            "\nNo terminal, and --%s was not supplied.\n\n"
            . "On hosting without SSH, pass every value as an argument:\n"
            . "  php bin/seed_admin.php --name=\"Your Name\" --mobile=9876543210 \\\n"
            . "      --email=you@example.com --password='ChooseAStrongOne123'\n",
            $key
        ));

        exit(1);
    }

    echo $question;

    if (!$hidden) {
        return trim((string) fgets(STDIN));
    }

    // Turn off terminal echo where the platform allows it.
    $usedStty = false;

    // shell_exec is DISABLED on most shared hosting, Hostinger included, and
    // calling a disabled function is FATAL — so hiding the typed password must
    // never be the thing that stops an administrator being created. If it is
    // unavailable the password is simply visible while typing, which is a much
    // smaller problem than not being able to sign in at all.
    if (DIRECTORY_SEPARATOR !== '\\'
        && function_exists('shell_exec')
        && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)
        && @shell_exec('which stty 2>/dev/null') !== null) {
        @shell_exec('stty -echo');
        $usedStty = true;
    }

    $value = trim((string) fgets(STDIN));

    if ($usedStty) {
        @shell_exec('stty echo');
        echo "\n";
    }

    return $value;
}

$name = prompt('Full name [System Administrator]: ', false, 'name');
$name = $name === '' ? 'System Administrator' : $name;

$mobile = preg_replace('/\D/', '', prompt('Mobile (10 digits): ', false, 'mobile')) ?? '';

if (preg_match('/^[6-9]\d{9}$/', $mobile) !== 1) {
    fwrite(STDERR, "Invalid mobile number.\n");
    exit(1);
}

$email = strtolower(prompt('Email: ', false, 'email'));

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}

$password = prompt('Password (min 12 chars, letters + digits): ', true, 'password');
$confirm = prompt('Confirm password: ', true, 'password');

if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

if (
    strlen($password) < 12
    || preg_match('/[A-Za-z]/', $password) !== 1
    || preg_match('/\d/', $password) !== 1
) {
    fwrite(STDERR, "Password does not meet the minimum policy for an administrator account.\n");
    exit(1);
}

$roleId = (int) $db->scalar("SELECT id FROM roles WHERE code = 'administrator' AND is_deleted = 0 LIMIT 1");

if ($roleId === 0) {
    fwrite(STDERR, "Administrator role missing. Run: php bin/migrate.php\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => (int) $config->get('auth.password.bcrypt_cost', 12)]);
$existing = $users->findByMobile($mobile);

if ($existing !== null) {
    $users->update((int) $existing['id'], [
        'role_id' => $roleId,
        'full_name' => $name,
        'email' => $email,
        'status' => 'active',
        'is_active' => 1,
    ]);
    $users->updatePassword((int) $existing['id'], $hash);

    printf("Updated existing administrator (uuid %s).\n", (string) $existing['uuid']);
    exit(0);
}

$referralCode = Str::referralCode('ADM');

while ($users->referralCodeExists($referralCode)) {
    $referralCode = Str::referralCode('ADM');
}

$userId = $users->create([
    'role_id' => $roleId,
    'full_name' => $name,
    'mobile' => $mobile,
    'email' => $email,
    'password_hash' => $hash,
    'status' => 'active',
    'mobile_verified_date' => date('Y-m-d H:i:s'),
    'email_verified_date' => date('Y-m-d H:i:s'),
    'referral_code' => $referralCode,
    'is_active' => 1,
]);

$created = $users->findById($userId);

printf("Administrator created.\n  uuid: %s\n  mobile: %s\n", (string) $created['uuid'], $mobile);
echo "Sign in at POST /api/v1/auth/login with identifier = mobile or email.\n";
