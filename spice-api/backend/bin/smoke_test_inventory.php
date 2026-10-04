<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for the inventory foundation (Phase 1 of the 5Star
 * inventory-first brief).
 *
 *   Terminal 1:  php -S 127.0.0.1:8080 -t public
 *   Terminal 2:  php bin/smoke_test_inventory.php
 *
 * Signs in as the administrator, creates a second warehouse and a test
 * product, then exercises InventoryService end to end: weighted-average cost
 * across two inward movements at different prices (the brief's own worked
 * example), negative stock allowed without being blocked, multi-warehouse
 * isolation, the movement ledger, the reorder-threshold/low-stock path, and
 * the append-only trigger that blocks any UPDATE on inventory_movements.
 * Cleans up after itself.
 *
 * NOT covered here: the order-confirmation -> saleDeduction() and
 * cancel -> restock() hooks in PaymentService/OrderService. Exercising those
 * needs the full checkout flow (OTP, payment gateway signature, webhook) that
 * smoke_test_checkout.php already drives; verify the inventory side of that
 * flow either by extending that script or with a manual pass — see the phase
 * report for this recommendation.
 *
 * Requires: php bin/migrate.php and php bin/seed_admin.php to have been run.
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

$baseUrl = rtrim((string) Env::get('APP_URL', 'http://127.0.0.1:8080'), '/') . '/api/v1';
$passed = 0;
$failed = 0;

function call(string $method, string $url, array $body = [], ?string $token = null): array
{
    $handle = curl_init($url);
    $headers = ['Accept: application/json'];

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ];

    if ($body !== []) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }

    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($handle, $options);

    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($raw === false) {
        throw new RuntimeException("Request failed: {$error}");
    }

    return ['status' => $status, 'body' => json_decode((string) $raw, true) ?? []];
}

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        ++$passed;
        printf("  PASS  %s\n", $label);

        return;
    }

    ++$failed;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : ' -> ' . $detail);
}

function prompt(string $question, bool $hidden = false): string
{
    echo $question;

    if (!$hidden) {
        return trim((string) fgets(STDIN));
    }

    $usedStty = DIRECTORY_SEPARATOR !== '\\' && shell_exec('which stty 2>/dev/null') !== null;

    if ($usedStty) {
        shell_exec('stty -echo');
    }

    $value = trim((string) fgets(STDIN));

    if ($usedStty) {
        shell_exec('stty echo');
        echo "\n";
    }

    return $value;
}

echo "Inventory foundation smoke test\n";
printf("Base URL: %s\n\n", $baseUrl);

$identifier = prompt('Administrator mobile or email: ');
$password = prompt('Administrator password: ', true);

$response = call('POST', $baseUrl . '/auth/login', ['identifier' => $identifier, 'password' => $password]);

if ($response['status'] !== 200) {
    fwrite(STDERR, "\nAdministrator login failed: " . json_encode($response['body']) . "\n");
    fwrite(STDERR, "Run php bin/seed_admin.php first.\n");
    exit(1);
}

$token = $response['body']['data']['tokens']['access_token'];
check('administrator signed in', ($response['body']['data']['user']['role'] ?? '') === 'administrator');

$suffix = strtoupper(bin2hex(random_bytes(3)));

echo "\n-- Authorisation --\n";

$response = call('GET', $baseUrl . '/admin/inventory/stock');
check('unauthenticated stock lookup returns 401', $response['status'] === 401);

$response = call('POST', $baseUrl . '/admin/inventory/adjust', ['variant_uuid' => 'x', 'warehouse_uuid' => 'x']);
check('unauthenticated adjustment returns 401', $response['status'] === 401);

echo "\n-- Warehouses --\n";

$response = call('GET', $baseUrl . '/admin/warehouses', [], $token);
$warehouses = $response['body']['data'] ?? [];
$default = null;

foreach ($warehouses as $warehouse) {
    if (!empty($warehouse['is_default'])) {
        $default = $warehouse;
    }
}

check('a default warehouse is seeded', $default !== null, json_encode($warehouses));
$defaultUuid = $default['uuid'] ?? null;

$response = call('POST', $baseUrl . '/admin/warehouses', [
    'code' => 'SMOKE-' . $suffix,
    'name' => 'Smoke Test Warehouse ' . $suffix,
    'city' => 'Bengaluru',
], $token);
check('second warehouse created', $response['status'] === 201, json_encode($response['body']));
$secondWarehouseUuid = $response['body']['data']['uuid'] ?? null;

echo "\n-- Test product --\n";

$productCode = 'SMOKE-INV-' . $suffix;
$sku = $productCode . '-500';

$response = call('POST', $baseUrl . '/admin/products', [
    'name' => 'Smoke Test Inventory Turmeric ' . $suffix,
    'product_code' => $productCode,
    'category_slug' => 'whole-spices',
    'variants' => [[
        'sku' => $sku,
        'variant_name' => '500 g pouch',
        'weight_grams' => 500,
        'mrp' => 199,
        'selling_price' => 179,
        'is_default' => true,
    ]],
], $token);
check('test product created', $response['status'] === 201, json_encode($response['body']));
$productUuid = $response['body']['data']['product']['uuid'] ?? null;
$productSlug = $response['body']['data']['product']['slug'] ?? null;

$response = call('GET', $baseUrl . '/admin/products/' . (string) $productSlug, [], $token);
$variants = $response['body']['data']['product']['variants'] ?? [];
$variantUuid = $variants[0]['uuid'] ?? null;
check('test variant resolved', is_string($variantUuid), json_encode($variants));

if ($defaultUuid === null || $variantUuid === null) {
    fwrite(STDERR, "\nCannot continue without a default warehouse and a test variant.\n");
    printf("\n%d passed, %d failed\n", $passed, $failed);
    exit(1);
}

echo "\n-- Weighted-average cost (the brief's own worked example) --\n";

$response = call('POST', $baseUrl . '/admin/inventory/adjust', [
    'variant_uuid' => $variantUuid,
    'warehouse_uuid' => $defaultUuid,
    'movement_type' => 'inward',
    'quantity_delta' => 100,
    'unit_cost' => 400,
    'reason' => 'Smoke test: first inward',
], $token);
check('first inward (100 @ Rs.400) recorded', $response['status'] === 200, json_encode($response['body']));
check('quantity after first inward is 100', (float) ($response['body']['data']['quantity'] ?? 0) === 100.0);
check('average cost after first inward is 400', abs((float) ($response['body']['data']['average_cost'] ?? 0) - 400.0) < 0.01);

$response = call('POST', $baseUrl . '/admin/inventory/adjust', [
    'variant_uuid' => $variantUuid,
    'warehouse_uuid' => $defaultUuid,
    'movement_type' => 'inward',
    'quantity_delta' => 50,
    'unit_cost' => 450,
    'reason' => 'Smoke test: second inward at a different cost',
], $token);
check('second inward (50 @ Rs.450) recorded', $response['status'] === 200, json_encode($response['body']));
check('quantity after second inward is 150', (float) ($response['body']['data']['quantity'] ?? 0) === 150.0);
check(
    'weighted-average cost is Rs.416.67 ((100*400 + 50*450) / 150)',
    abs((float) ($response['body']['data']['average_cost'] ?? 0) - 416.6667) < 0.01,
    'got ' . ($response['body']['data']['average_cost'] ?? 'null')
);

echo "\n-- Movement ledger --\n";

$response = call('GET', $baseUrl . '/admin/inventory/movements?variant_uuid=' . $variantUuid, [], $token);
$movements = $response['body']['data'] ?? [];
check('both inward movements appear in the ledger', count($movements) === 2, (string) count($movements));
check('ledger entries carry unit_cost, not average_cost or selling_price',
    array_filter($movements, static fn (array $m): bool => isset($m['unit_cost'])) !== []);

echo "\n-- Negative stock is allowed, never blocked --\n";

$response = call('POST', $baseUrl . '/admin/inventory/adjust', [
    'variant_uuid' => $variantUuid,
    'warehouse_uuid' => $defaultUuid,
    'movement_type' => 'damage',
    'quantity_delta' => -999,
    'reason' => 'Smoke test: deliberately drive stock negative',
], $token);
check('a movement larger than stock on hand still succeeds (not blocked)', $response['status'] === 200,
    json_encode($response['body']));
check('resulting quantity is negative (150 - 999 = -849)',
    (float) ($response['body']['data']['quantity'] ?? 1) === -849.0);

echo "\n-- Multi-warehouse isolation --\n";

$response = call('GET', $baseUrl . '/admin/inventory/stock/' . $variantUuid, [], $token);
$byWarehouse = $response['body']['data']['warehouses'] ?? [];
$secondRow = null;

foreach ($byWarehouse as $row) {
    if (($row['warehouse_uuid'] ?? null) === $secondWarehouseUuid) {
        $secondRow = $row;
    }
}

check(
    "the second warehouse's stock is untouched by the default warehouse's movements",
    $secondRow === null || (float) $secondRow['quantity'] === 0.0,
    json_encode($secondRow)
);

echo "\n-- Reorder threshold / low stock --\n";

$response = call('PATCH', $baseUrl . '/admin/inventory/reorder-threshold', [
    'variant_uuid' => $variantUuid,
    'warehouse_uuid' => $defaultUuid,
    'reorder_threshold' => 0,
], $token);
check('reorder threshold set', $response['status'] === 200, json_encode($response['body']));

$response = call('GET', $baseUrl . '/admin/inventory/low-stock', [], $token);
$lowStock = $response['body']['data'] ?? [];
$found = false;

foreach ($lowStock as $row) {
    if (($row['variant_uuid'] ?? null) === $variantUuid) {
        $found = true;
    }
}

check('the negative-stock line now appears on the low-stock dashboard', $found, json_encode($lowStock));

echo "\n-- Append-only ledger: the database refuses to UPDATE a movement --\n";

/** @var \App\Core\Container $container */
$container = require APP_ROOT . '/bootstrap/container.php';
/** @var Database $db */
$db = $container->get(Database::class);

$movementId = $db->scalar(
    'SELECT id FROM inventory_movements WHERE reference_type = ? AND reason LIKE ? ORDER BY id DESC LIMIT 1',
    ['manual', 'Smoke test:%']
);

$triggerBlocked = false;

if ($movementId !== null) {
    try {
        $db->execute('UPDATE inventory_movements SET reason = ? WHERE id = ?', ['tampered', (int) $movementId]);
    } catch (\PDOException $exception) {
        $triggerBlocked = str_contains($exception->getMessage(), 'append-only ledger');
    }
}

check('directly editing a movement row is rejected by the database trigger', $triggerBlocked);

echo "\n-- Cleanup --\n";

$response = call('DELETE', $baseUrl . '/admin/products/' . (string) $productUuid, [], $token);
check('test product deleted', $response['status'] === 200, json_encode($response['body']));

if ($secondWarehouseUuid !== null) {
    $response = call('DELETE', $baseUrl . '/admin/warehouses/' . (string) $secondWarehouseUuid, [], $token);
    check('second warehouse deactivated', $response['status'] === 200, json_encode($response['body']));
}

printf("\n%d passed, %d failed\n", $passed, $failed);

if ($failed > 0) {
    exit(1);
}
