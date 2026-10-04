<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for the catalog module (Phase 2).
 *
 *   Terminal 1:  php -S 127.0.0.1:8080 -t public
 *   Terminal 2:  php bin/smoke_test_catalog.php
 *
 * Signs in as the administrator, creates a product with pack sizes, checks the
 * publish-readiness gate, publishes it, then verifies the storefront can see,
 * filter, sort and search it. Cleans up after itself.
 *
 * Requires: php bin/migrate.php and php bin/seed_admin.php to have been run.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/bootstrap/autoload.php';

use App\Core\Env;

Env::load(APP_ROOT . '/.env');

$baseUrl = rtrim((string) Env::get('APP_URL', 'http://127.0.0.1:8080'), '/') . '/api/v1';
$passed = 0;
$failed = 0;

function call(string $method, string $url, array $body = [], ?string $token = null, ?array $imageField = null): array
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

    if ($imageField !== null) {
        // multipart/form-data — curl sets the boundary and content type.
        $options[CURLOPT_POSTFIELDS] = array_merge($body, $imageField);
    } elseif ($body !== []) {
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

/** Writes a real PNG to a temp file so the upload passes content verification. */
function makeTestPng(int $width = 400, int $height = 400): string
{
    $path = tempnam(sys_get_temp_dir(), 'smoke') . '.png';

    if (function_exists('imagecreatetruecolor')) {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 196, 122, 44));
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    // No GD: emit a minimal valid 1x1 PNG. The service will reject it on the
    // minimum-edge rule, which the test asserts rather than skips.
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
    ));

    return $path;
}

echo "Catalog smoke test\n";
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
$role = $response['body']['data']['user']['role'] ?? '';
check('administrator signed in', $role === 'administrator', 'role was ' . $role);

$suffix = strtoupper(bin2hex(random_bytes(3)));
$productCode = 'SMOKE-' . $suffix;
$productUuid = null;
$productSlug = null;

echo "\n-- Seed data (from database/seeds/002) --\n";

$response = call('GET', $baseUrl . '/categories');
$categories = $response['body']['data']['categories'] ?? [];
check('category tree loads', $response['status'] === 200 && count($categories) >= 7,
    (string) count($categories) . ' top-level categories');

$hasChildren = false;

foreach ($categories as $category) {
    if (!empty($category['children'])) {
        $hasChildren = true;

        break;
    }
}

check('tree is nested (subcategories present)', $hasChildren);

$response = call('GET', $baseUrl . '/products');
$seedTotal = $response['body']['meta']['total'] ?? 0;
check('storefront listing returns seeded products', $response['status'] === 200 && $seedTotal >= 3,
    'total was ' . $seedTotal);

$response = call('GET', $baseUrl . '/products/organic-turmeric-powder');
$product = $response['body']['data']['product'] ?? [];
check('product detail loads by slug', $response['status'] === 200 && ($product['slug'] ?? '') === 'organic-turmeric-powder');
check('detail includes pack sizes with weights', count($product['variants'] ?? []) === 3);
check('detail includes nutrition', ($product['nutrition']['protein_g'] ?? null) !== null);
check('detail includes specifications', count($product['attributes'] ?? []) >= 2);
check('exactly one default pack size', count(array_filter(
    $product['variants'] ?? [],
    static fn (array $v): bool => $v['is_default'] === true
)) === 1);

$live = array_filter($product['variants'] ?? [], static fn (array $v): bool => $v['offer_is_live'] === true);
check('live offer resolves to the offer price', $live !== []
    && (float) reset($live)['effective_price'] < (float) reset($live)['selling_price']);
check('price per kg is computed', ($product['variants'][0]['price_per_kg'] ?? 0) > 0);

echo "\n-- Search, filter, sort --\n";

$response = call('GET', $baseUrl . '/products?search=turmeric');
check('full-text search finds the product', ($response['body']['meta']['total'] ?? 0) >= 1);

$response = call('GET', $baseUrl . '/products?search=haldi');
check('search matches a regional synonym', ($response['body']['meta']['total'] ?? 0) >= 1,
    'search_keywords should match "haldi"');

$response = call('GET', $baseUrl . '/products?search=zzzznotathing');
check('search with no matches returns an empty page', $response['status'] === 200
    && ($response['body']['meta']['total'] ?? -1) === 0);

$response = call('GET', $baseUrl . '/products?category=spices');
check('category filter includes subcategory products', ($response['body']['meta']['total'] ?? 0) >= 2,
    'turmeric and cardamom both sit under child categories of spices');

$response = call('GET', $baseUrl . '/products?has_offer=true');
check('offer filter returns only discounted products', ($response['body']['meta']['total'] ?? 0) >= 1);

$response = call('GET', $baseUrl . '/products?sort=price_low');
$prices = array_map(
    static fn (array $p): float => (float) $p['pricing']['min_price'],
    $response['body']['data'] ?? []
);
$sorted = $prices;
sort($sorted);
check('price_low sort is ascending', $prices === $sorted);

$response = call('GET', $baseUrl . '/products?min_price=500&max_price=100');
check('inverted price range is rejected with 422', $response['status'] === 422);

$response = call('GET', $baseUrl . '/products?category=not-a-category');
check('unknown category filter returns 404', $response['status'] === 404);

$response = call('GET', $baseUrl . '/products?sort=cheapest');
check('unknown sort value is rejected with 422', $response['status'] === 422);

$response = call('GET', $baseUrl . '/products/filters');
check('filter metadata exposes price bounds', ($response['body']['data']['price']['max'] ?? 0) > 0);

echo "\n-- Authorisation --\n";

$response = call('POST', $baseUrl . '/admin/products', ['name' => 'Nope']);
check('unauthenticated product creation returns 401', $response['status'] === 401);

$response = call('GET', $baseUrl . '/admin/products');
check('unauthenticated admin listing returns 401', $response['status'] === 401);

echo "\n-- Product lifecycle --\n";

$response = call('POST', $baseUrl . '/admin/products', [
    'name' => 'Smoke Test Black Pepper ' . $suffix,
    'product_code' => $productCode,
    'category_slug' => 'whole-spices',
    'short_description' => 'Malabar black pepper, sharp and resinous',
    'search_keywords' => 'kali mirch, milagu, peppercorn',
    'gst_rate' => 5,
    'variants' => [
        [
            'sku' => $productCode . '-100',
            'variant_name' => '100 g pouch',
            'weight_grams' => 100,
            'mrp' => 199,
            'selling_price' => 179,
            'is_default' => true,
        ],
        [
            'sku' => $productCode . '-250',
            'variant_name' => '250 g pouch',
            'weight_grams' => 250,
            'mrp' => 449,
            'selling_price' => 399,
            'offer_price' => 349,
        ],
    ],
], $token);

check('product created', $response['status'] === 201, json_encode($response['body']));
$productUuid = $response['body']['data']['product']['uuid'] ?? null;
$productSlug = $response['body']['data']['product']['slug'] ?? null;
check('new product starts as a draft', ($response['body']['data']['product']['status'] ?? '') === 'draft');

$response = call('GET', $baseUrl . '/products/' . (string) $productSlug);
check('draft is invisible on the storefront', $response['status'] === 404);

$response = call('GET', $baseUrl . '/admin/products/' . (string) $productSlug, [], $token);
check('draft is visible to an administrator', $response['status'] === 200);

$response = call('POST', $baseUrl . '/admin/products', [
    'name' => 'Duplicate Code',
    'product_code' => $productCode,
    'category_slug' => 'whole-spices',
    'variants' => [[
        'sku' => $productCode . '-DUP',
        'variant_name' => '100 g',
        'weight_grams' => 100,
        'mrp' => 100,
        'selling_price' => 90,
    ]],
], $token);
check('duplicate product code returns 409', $response['status'] === 409);

$response = call('POST', $baseUrl . '/admin/products', [
    'name' => 'No Pack Sizes',
    'product_code' => 'SMOKE-NOVAR-' . $suffix,
    'category_slug' => 'whole-spices',
    'variants' => [],
], $token);
check('product without pack sizes is rejected with 422', $response['status'] === 422);

$response = call('POST', $baseUrl . '/admin/products', [
    'name' => 'Bad Pricing',
    'product_code' => 'SMOKE-BADPRICE-' . $suffix,
    'category_slug' => 'whole-spices',
    'variants' => [[
        'sku' => 'SMOKE-BADPRICE-' . $suffix,
        'variant_name' => '100 g',
        'weight_grams' => 100,
        'mrp' => 100,
        'selling_price' => 150,
    ]],
], $token);
check('selling price above MRP is rejected with 422', $response['status'] === 422);

// Publish gate: no image yet, so this must fail.
$response = call('POST', $baseUrl . '/admin/products/' . (string) $productUuid . '/publish', [], $token);
check('publishing without an image is refused', $response['status'] === 422,
    json_encode($response['body']['errors'] ?? []));

echo "\n-- Image upload --\n";

$imagePath = makeTestPng();
$imageInfo = @getimagesize($imagePath);
$imageIsUsable = $imageInfo !== false && (int) $imageInfo[0] >= 200;

$response = call(
    'POST',
    $baseUrl . '/admin/products/' . (string) $productUuid . '/images',
    ['alt_text' => 'Smoke test pepper'],
    $token,
    ['image' => new CURLFile($imagePath, 'image/png', 'pepper.png')]
);

if ($imageIsUsable) {
    check('image uploaded', $response['status'] === 201, json_encode($response['body']));
    check('first image becomes primary',
        ($response['body']['data']['product']['primary_image']['url'] ?? null) !== null);
} else {
    check('undersized image is rejected with 422', $response['status'] === 422,
        'install the GD extension to test the success path');
}

// A text file renamed .png must be rejected on content, not extension.
$fakePath = tempnam(sys_get_temp_dir(), 'smoke') . '.png';
file_put_contents($fakePath, "<?php echo 'not an image'; ?>\n");

$response = call(
    'POST',
    $baseUrl . '/admin/products/' . (string) $productUuid . '/images',
    [],
    $token,
    ['image' => new CURLFile($fakePath, 'image/png', 'payload.png')]
);
check('PHP file disguised as a PNG is rejected', $response['status'] === 422,
    'status was ' . $response['status']);

unlink($fakePath);

echo "\n-- Publish and verify on the storefront --\n";

$response = call('POST', $baseUrl . '/admin/products/' . (string) $productUuid . '/publish', [], $token);

if ($imageIsUsable) {
    check('product published', $response['status'] === 200, json_encode($response['body']['errors'] ?? []));

    $response = call('GET', $baseUrl . '/products/' . (string) $productSlug);
    check('published product is visible on the storefront', $response['status'] === 200);

    $response = call('GET', $baseUrl . '/products?search=milagu');
    check('new product is findable by its keyword', ($response['body']['meta']['total'] ?? 0) >= 1);
} else {
    check('publish still gated without a valid image', $response['status'] === 422);
}

echo "\n-- Pack size management --\n";

$response = call('GET', $baseUrl . '/admin/products/' . (string) $productSlug, [], $token);
$variants = $response['body']['data']['product']['variants'] ?? [];
check('both pack sizes present', count($variants) === 2);

$variantUuid = $variants[0]['uuid'] ?? null;

$response = call('PATCH', $baseUrl . '/admin/variants/' . (string) $variantUuid,
    ['selling_price' => 169], $token);
check('pack size price updated', $response['status'] === 200);

$response = call('PATCH', $baseUrl . '/admin/variants/' . (string) $variantUuid,
    ['offer_price' => 200], $token);
check('offer price above selling price is rejected', $response['status'] === 422);

$response = call('DELETE', $baseUrl . '/admin/variants/' . (string) $variantUuid, [], $token);
check('pack size removed', $response['status'] === 200);

$response = call('GET', $baseUrl . '/admin/products/' . (string) $productSlug, [], $token);
$variants = $response['body']['data']['product']['variants'] ?? [];
check('a default pack size is auto-promoted after deletion',
    count(array_filter($variants, static fn (array $v): bool => $v['is_default'] === true)) === 1);

if ($imageIsUsable) {
    $lastVariant = $variants[0]['uuid'] ?? null;
    $response = call('DELETE', $baseUrl . '/admin/variants/' . (string) $lastVariant, [], $token);
    check('removing the last pack size from a published product is refused', $response['status'] === 409);
}

echo "\n-- Nutrition and specifications --\n";

$response = call('PUT', $baseUrl . '/admin/products/' . (string) $productUuid . '/nutrition', [
    'serving_size_g' => 100,
    'energy_kcal' => 251,
    'protein_g' => 10.4,
    'dietary_fibre_g' => 25.3,
], $token);
check('nutrition saved', $response['status'] === 200
    && (float) ($response['body']['data']['product']['nutrition']['protein_g'] ?? 0) === 10.4);

$response = call('PUT', $baseUrl . '/admin/products/' . (string) $productUuid . '/attributes', [
    'attributes' => [
        ['attribute_name' => 'Grade', 'attribute_value' => 'Malabar Garbled'],
        ['attribute_name' => 'Piperine', 'attribute_value' => '4.2%'],
    ],
], $token);
check('specifications saved', $response['status'] === 200
    && count($response['body']['data']['product']['attributes'] ?? []) === 2);

$response = call('PUT', $baseUrl . '/admin/products/' . (string) $productUuid . '/attributes', [
    'attributes' => [
        ['attribute_name' => 'Grade', 'attribute_value' => 'A'],
        ['attribute_name' => 'grade', 'attribute_value' => 'B'],
    ],
], $token);
check('duplicate specification names are rejected', $response['status'] === 422);

echo "\n-- Categories --\n";

$categorySlug = 'smoke-test-' . strtolower($suffix);
$response = call('POST', $baseUrl . '/admin/categories', [
    'name' => 'Smoke Test Category ' . $suffix,
    'slug' => $categorySlug,
    'parent_slug' => 'spices',
], $token);
check('category created', $response['status'] === 201, json_encode($response['body']));
$categoryUuid = $response['body']['data']['category']['uuid'] ?? null;

$response = call('PATCH', $baseUrl . '/admin/categories/' . (string) $categoryUuid,
    ['parent_slug' => $categorySlug], $token);
check('a category cannot be its own parent', $response['status'] === 422);

$response = call('DELETE', $baseUrl . '/admin/categories/spices-not-real', [], $token);
check('deleting an unknown category returns 404', $response['status'] === 404);

$response = call('GET', $baseUrl . '/admin/categories', [], $token);
$spices = null;

foreach ($response['body']['data'] ?? [] as $row) {
    if (($row['slug'] ?? '') === 'spices') {
        $spices = $row;
    }
}

if ($spices !== null) {
    $response = call('DELETE', $baseUrl . '/admin/categories/' . $spices['uuid'], [], $token);
    check('deleting a category with contents returns 409', $response['status'] === 409);
}

$response = call('DELETE', $baseUrl . '/admin/categories/' . (string) $categoryUuid, [], $token);
check('empty category deleted', $response['status'] === 200);

echo "\n-- Banners --\n";

$response = call('GET', $baseUrl . '/banners?placement=home_hero');
check('banner endpoint responds', $response['status'] === 200);

$response = call('GET', $baseUrl . '/banners?placement=not_a_placement');
check('unknown placement is rejected with 422', $response['status'] === 422);

echo "\n-- Cleanup --\n";

$response = call('DELETE', $baseUrl . '/admin/products/' . (string) $productUuid, [], $token);
check('test product deleted', $response['status'] === 200);

if (is_file($imagePath)) {
    unlink($imagePath);
}

printf("\n%d passed, %d failed\n", $passed, $failed);

if (!$imageIsUsable) {
    echo "Note: GD is unavailable, so image success paths were skipped and the\n";
    echo "      rejection paths were asserted instead.\n";
}

exit($failed === 0 ? 0 : 1);
