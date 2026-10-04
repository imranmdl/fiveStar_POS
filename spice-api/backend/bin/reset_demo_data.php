<?php

declare(strict_types=1);

/**
 * Wipes demo/dev/test business data from the LIVE database while keeping
 * everything needed to log in and run the app: roles, permissions,
 * role_permissions, genuine staff/admin accounts, the default warehouse,
 * system settings, delivery/commission/notification config, cms_pages, and
 * every table's schema. Nothing here touches migrations or structure.
 *
 * WHAT GETS REMOVED: all products/variants/media/nutrition/attributes, all
 * categories, all orders/carts and their line items, all inventory
 * stock/movements/batches, all coupons/offers/faq_entries, the one test
 * vendor row, every warehouse except WH-MAIN, every customer-role user, and
 * a short list of known leftover test-fixture staff/admin accounts (see
 * TEST_ACCOUNT_* constants below — matched by name/mobile pattern, not a
 * blanket "delete everyone").
 *
 * SAFETY:
 *   - Default (no flags) is a DRY RUN: prints exactly what would be kept vs
 *     removed, with row counts, and changes nothing.
 *   - --confirm actually runs it. A fresh full backup is taken first (same
 *     mechanism as the Backups admin page), and you're asked to type RESET
 *     to proceed (skip that prompt only with --yes, for non-interactive use).
 *   - Runs as one transaction — a failure partway through rolls back
 *     everything (the ledger truncations below are the one exception, see
 *     their own comment).
 *
 * Usage:
 *   php bin/reset_demo_data.php              Dry run — show what would happen.
 *   php bin/reset_demo_data.php --confirm    Actually do it (asks to type RESET first).
 *   php bin/reset_demo_data.php --confirm --yes   Same, no interactive prompt.
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
use App\Core\Request;
use App\Services\AuditService;
use App\Services\BackupService;

Env::load(APP_ROOT . '/.env');

/** @var \App\Core\Container $container */
$container = require APP_ROOT . '/bootstrap/container.php';
/** @var Database $db */
$db = $container->get(Database::class);
/** @var Config $config */
$config = $container->get(Config::class);
/** @var BackupService $backups */
$backups = $container->get(BackupService::class);
/** @var AuditService $audit */
$audit = $container->get(AuditService::class);

$confirm = in_array('--confirm', $argv, true);
$skipPrompt = in_array('--yes', $argv, true);

// Leftover test-fixture staff/admin accounts identified during investigation
// (2026-09-30) — automated smoke-test rows (TmpWalletAdmin, TmpPosCashier,
// TmpLowStockAdmin, ...) and disposable manual test accounts created during
// this project's own Claude Code sessions (mobile prefix 9111100XXX, a
// countdown convention documented in that session's memory). Matched by
// pattern, not "every staff account" — a real admin/cashier/executive login
// (Administrator, Cashier, Test Executive, rohini, suki, arifa, ...) is left
// alone regardless of what its display name happens to contain.
const TEST_ACCOUNT_NAME_PATTERN = 'Tmp%';
const TEST_ACCOUNT_MOBILE_PATTERN = '9111100%';

function tableCount(Database $db, string $table): int
{
    return (int) $db->scalar("SELECT COUNT(*) FROM `{$table}`");
}

/** @return array<int, array<string, mixed>> */
function usersToRemove(Database $db): array
{
    return $db->select(
        "SELECT u.`id`, u.`full_name`, u.`mobile`, r.`code` AS `role_code`
           FROM `users` u
           INNER JOIN `roles` r ON r.`id` = u.`role_id`
          WHERE r.`code` = 'customer'
             OR u.`mobile` LIKE :mobile_pattern
             OR u.`full_name` LIKE :name_pattern
          ORDER BY u.`id`"
        ,
        ['mobile_pattern' => TEST_ACCOUNT_MOBILE_PATTERN, 'name_pattern' => TEST_ACCOUNT_NAME_PATTERN]
    );
}

/** @return array<int, array<string, mixed>> */
function staffAccountsKept(Database $db): array
{
    return $db->select(
        "SELECT u.`id`, u.`full_name`, u.`mobile`, u.`email`, r.`code` AS `role_code`
           FROM `users` u
           INNER JOIN `roles` r ON r.`id` = u.`role_id`
          WHERE r.`code` <> 'customer'
            AND u.`mobile` NOT LIKE :mobile_pattern
            AND u.`full_name` NOT LIKE :name_pattern
          ORDER BY r.`code`, u.`id`"
        ,
        ['mobile_pattern' => TEST_ACCOUNT_MOBILE_PATTERN, 'name_pattern' => TEST_ACCOUNT_NAME_PATTERN]
    );
}

echo "== 5Star demo data reset ==\n\n";

$removeCounts = [
    'products' => tableCount($db, 'products'),
    'product_variants' => tableCount($db, 'product_variants'),
    'categories' => tableCount($db, 'categories'),
    'orders' => tableCount($db, 'orders'),
    'order_items' => tableCount($db, 'order_items'),
    'carts' => tableCount($db, 'carts'),
    'cart_items' => tableCount($db, 'cart_items'),
    'inventory_stock' => tableCount($db, 'inventory_stock'),
    'inventory_movements' => tableCount($db, 'inventory_movements'),
    'inventory_batches' => tableCount($db, 'inventory_batches'),
    'coupons' => tableCount($db, 'coupons'),
    'offers' => tableCount($db, 'offers'),
    'faq_entries' => tableCount($db, 'faq_entries'),
    'vendors' => tableCount($db, 'vendors'),
    'wallet_transactions' => tableCount($db, 'wallet_transactions'),
    'loyalty_ledger' => tableCount($db, 'loyalty_ledger'),
    'loyalty_point_expiries' => tableCount($db, 'loyalty_point_expiries'),
    'loyalty_accounts' => tableCount($db, 'loyalty_accounts'),
];

$testWarehouses = $db->select("SELECT `code`, `name` FROM `warehouses` WHERE `code` <> 'WH-MAIN'");
$usersGone = usersToRemove($db);
$usersKept = staffAccountsKept($db);

echo "Rows that would be permanently removed:\n";
foreach ($removeCounts as $table => $count) {
    printf("  %-24s %d\n", $table, $count);
}

echo "\nWarehouses removed (all but WH-MAIN):\n";
foreach ($testWarehouses as $row) {
    echo "  {$row['code']} — {$row['name']}\n";
}
if ($testWarehouses === []) {
    echo "  (none — only WH-MAIN exists)\n";
}

echo "\nUser accounts removed (" . count($usersGone) . "):\n";
foreach ($usersGone as $row) {
    echo "  #{$row['id']} {$row['full_name']} ({$row['role_code']}, {$row['mobile']})\n";
}

echo "\nStaff/admin accounts KEPT — these still log in unchanged (" . count($usersKept) . "):\n";
foreach ($usersKept as $row) {
    echo "  #{$row['id']} {$row['full_name']} ({$row['role_code']}, {$row['mobile']})\n";
}

echo "\nAlways kept, untouched by this script: roles, permissions, role_permissions,\n"
    . "settings, delivery zones/pricing, commission rules, notification templates,\n"
    . "cms_pages, audit_logs, and every table's schema/migrations.\n";

if (!$confirm) {
    echo "\nDRY RUN ONLY — nothing was changed. Re-run with --confirm to actually do this.\n";
    exit(0);
}

echo "\n--confirm was passed. This is IRREVERSIBLE for anything not in a backup.\n";

echo "Taking a fresh backup first... ";
$backupRequest = new Request('CLI', 'bin/reset_demo_data.php', [], [], [], [], '127.0.0.1', 'cli-script', uniqid('cli_', true));
$backup = $backups->create($backupRequest, 'pre_demo_data_reset');
echo "done ({$backup['filename']}).\n";

if (!$skipPrompt) {
    if (!defined('STDIN') || !stream_isatty(STDIN)) {
        fwrite(STDERR, "No interactive terminal detected — re-run with --confirm --yes if you're sure.\n");
        exit(1);
    }

    echo "\nType RESET (all caps) to proceed, anything else to abort: ";
    $typed = trim((string) fgets(STDIN));

    if ($typed !== 'RESET') {
        echo "Aborted — nothing was changed. Your backup ({$backup['filename']}) is still there.\n";
        exit(1);
    }
}

$userIds = array_map(static fn (array $row): int => (int) $row['id'], $usersGone);

// loyalty_ledger and wallet_transactions are append-only ledgers — a
// BEFORE DELETE trigger on each unconditionally SIGNALs an error to stop any
// row being deleted, ever (see 049_loyalty_program.sql / 004_promotions_wallet.sql).
// This must run BEFORE the user-deletion transaction below, not after: any
// user being deleted who has wallet/loyalty history would cascade into
// wallet_transactions/loyalty_ledger (via wallet_accounts/loyalty_accounts),
// and that cascaded delete trips the same trigger and fails the whole
// transaction. TRUNCATE is the only way to actually clear these tables (it
// bypasses row triggers in MySQL) and necessarily runs as its own step —
// TRUNCATE causes an implicit commit of its own in MySQL/InnoDB, so it can
// never be part of a rollback-able transaction.
echo "\nClearing append-only ledgers (loyalty_point_expiries, loyalty_ledger, wallet_transactions)...\n";
$db->execute('SET FOREIGN_KEY_CHECKS = 0');
$db->execute('TRUNCATE TABLE `loyalty_point_expiries`');
$db->execute('TRUNCATE TABLE `loyalty_ledger`');
$db->execute('TRUNCATE TABLE `wallet_transactions`');
$db->execute('SET FOREIGN_KEY_CHECKS = 1');

$db->transaction(function () use ($db, $userIds): void {
    $db->execute('SET FOREIGN_KEY_CHECKS = 0');

    try {
        $db->execute('DELETE FROM `order_assignments`');
        $db->execute('DELETE FROM `carts`');
        $db->execute('DELETE FROM `orders`');
        $db->execute('DELETE FROM `inventory_movements`');
        $db->execute('DELETE FROM `products`');
        $db->execute('DELETE FROM `categories`');
        $db->execute('DELETE FROM `coupons`');
        $db->execute('DELETE FROM `offers`');
        $db->execute('DELETE FROM `faq_entries`');
        $db->execute('DELETE FROM `vendors`');
        $db->execute("DELETE FROM `warehouses` WHERE `code` <> 'WH-MAIN'");

        if ($userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            // RESTRICT FKs that would otherwise block deleting these users —
            // cleared explicitly rather than relying on cascade (there is none
            // here on purpose, see 007_staff_commission_bulk.sql).
            $db->execute("DELETE FROM `commission_entries` WHERE `user_id` IN ({$placeholders})", $userIds);
            $db->execute(
                "DELETE FROM `commission_settlements` WHERE `user_id` IN ({$placeholders}) OR `approved_by` IN ({$placeholders})",
                array_merge($userIds, $userIds)
            );
            $db->execute("DELETE FROM `users` WHERE `id` IN ({$placeholders})", $userIds);
        }
    } finally {
        $db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }
});

$auditRequest = new Request('CLI', 'bin/reset_demo_data.php', [], [], [], [], '127.0.0.1', 'cli-script', uniqid('cli_', true));
$audit->log(
    entityName: 'database',
    entityId: null,
    action: 'demo_data_reset',
    oldValues: $removeCounts,
    request: $auditRequest,
    notes: 'Ran via bin/reset_demo_data.php --confirm, backup ' . $backup['filename']
);

echo "\nDone. Row counts after:\n";
foreach (array_keys($removeCounts) as $table) {
    printf("  %-24s %d\n", $table, tableCount($db, $table));
}

echo "\nStaff/admin accounts still able to log in: " . count($usersKept) . " (unchanged).\n";
echo "Backup taken before this run: {$backup['filename']} (see the Backups admin page).\n";
