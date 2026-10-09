<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Barcode;
use App\Helpers\Str;
use App\Repositories\CategoryRepository;
use App\Repositories\ImportBatchItemRepository;
use App\Repositories\ImportBatchRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\WarehouseRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk product/stock/purchase import from a CSV or .xlsx file (brief §9).
 *
 * Nothing is written to the database by upload or preview() — both only
 * parse and validate. Only confirm() writes, and it re-parses and
 * re-validates from scratch rather than trusting whatever preview()
 * returned; the token preview() hands back only saves a re-upload of a
 * possibly-large file, never a shortcut around re-validation.
 *
 * A row with a quantity posts through the same, unmodified
 * InventoryService::recordMovement() every other inward channel already
 * uses (referenceType 'csv_import', which migration 014 already added to
 * the enum in anticipation of this phase). A new SKU's product always lands
 * as status='draft' — the same as a manually created product — on top of
 * the preview/confirm gate itself, matching "do not directly commit an
 * uploaded file into production stock."
 *
 * One row = one product + one variant. A spreadsheet with several pack
 * sizes of the same product will create one single-variant product per row
 * rather than grouping them — SKU is the only reliable per-row key this
 * format has, and guessing at "these rows are the same product" from a name
 * match would be fragile. Multi-variant products stay a Products-screen or
 * Purchase-Inward job.
 */
final class ImportService
{
    private const CANONICAL_FIELDS = [
        'sku', 'barcode', 'category', 'product_name', 'variant_name', 'pack_type',
        'weight_grams', 'stock_unit_type', 'unit_label', 'mrp', 'selling_price',
        'warehouse_code', 'quantity', 'reorder_threshold', 'vendor_name',
        'purchase_cost', 'batch_no', 'expiry_date',
    ];

    /** Header aliases matched case-insensitively, whitespace-collapsed. */
    private const HEADER_ALIASES = [
        'sku' => ['sku', 'product code', 'item code'],
        'barcode' => ['barcode', 'ean', 'upc'],
        'category' => ['category', 'category slug'],
        'product_name' => ['product name', 'product', 'name'],
        'variant_name' => ['variant name', 'pack size', 'pack name'],
        'pack_type' => ['pack type'],
        'weight_grams' => ['weight grams', 'weight (g)', 'weight'],
        'stock_unit_type' => ['stock unit type', 'unit type'],
        'unit_label' => ['unit label'],
        'mrp' => ['mrp'],
        'selling_price' => ['selling price', 'price'],
        'warehouse_code' => ['warehouse code', 'warehouse'],
        'quantity' => ['quantity', 'qty'],
        'reorder_threshold' => ['reorder threshold', 'reorder level', 'reorder at'],
        'vendor_name' => ['vendor name', 'vendor', 'supplier'],
        'purchase_cost' => ['purchase cost', 'unit cost', 'cost'],
        'batch_no' => ['batch no', 'batch', 'lot no'],
        'expiry_date' => ['expiry date', 'expiry', 'expires'],
    ];

    private const PACK_TYPES = ['pouch', 'jar', 'box', 'tin', 'gift_box', 'refill', 'other'];
    private const STOCK_UNIT_TYPES = ['weight', 'quantity'];
    private const MAX_ROWS = 5000;
    private const TEMP_FILE_MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
        private readonly CategoryRepository $categories,
        private readonly WarehouseRepository $warehouses,
        private readonly InventoryService $inventory,
        private readonly ImportBatchRepository $batches,
        private readonly ImportBatchItemRepository $items,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Logger $logger,
        private readonly VendorService $vendorService,
        private readonly VariantOptionService $variantOptions,
    ) {
    }

    /**
     * @param array<string, mixed> $file A single entry from $_FILES
     * @param array<string, string>|null $columnMapping canonical field => header text, overrides auto-detection
     *
     * @return array<string, mixed>
     */
    public function preview(array $file, ?array $columnMapping, ?string $warehouseUuid): array
    {
        $this->sweepStaleTempFiles();

        $stored = $this->receiveUpload($file);
        $parsed = $this->parseAndValidate($stored['path'], $stored['file_type'], $columnMapping, $warehouseUuid);

        return array_merge(
            ['token' => $stored['token'], 'file_type' => $stored['file_type'], 'file_name' => $stored['file_name']],
            $parsed
        );
    }

    /**
     * @param array<string, string>|null $columnMapping
     *
     * @return array<string, mixed>
     */
    public function confirm(
        string $token,
        string $fileType,
        ?array $columnMapping,
        ?string $warehouseUuid,
        string $originalFileName,
        Request $request,
    ): array {
        $path = $this->tempFilePath($token, $fileType);

        if (!is_file($path)) {
            throw new HttpException(
                'This import has expired or was already confirmed. Upload the file again.',
                410
            );
        }

        $parsed = $this->parseAndValidate($path, $fileType, $columnMapping, $warehouseUuid);
        $defaultWarehouse = $warehouseUuid === null ? null : $this->warehouses->findByUuid($warehouseUuid);

        try {
            $result = $this->db->transaction(function () use ($parsed, $defaultWarehouse, $fileType, $originalFileName, $request): array {
                $batchId = $this->batches->create([
                    'file_name' => $originalFileName,
                    'file_type' => $fileType,
                    'status' => 'completed',
                    'warehouse_id' => $defaultWarehouse === null ? null : (int) $defaultWarehouse['id'],
                    'total_rows' => $parsed['summary']['total'],
                    'valid_rows' => $parsed['summary']['valid'],
                    'invalid_rows' => $parsed['summary']['invalid'],
                    'created_count' => 0,
                    'updated_count' => 0,
                    'skipped_count' => $parsed['summary']['invalid'],
                ], $request->authUserId());

                $createdCount = 0;
                $updatedCount = 0;

                foreach ($parsed['rows'] as $row) {
                    $variantId = null;
                    $action = 'skipped';

                    if ($row['is_valid']) {
                        $outcome = $this->applyRow($row, $request);
                        $action = $outcome['action'];
                        $variantId = $outcome['variant_id'];

                        if ($action === 'created') {
                            ++$createdCount;
                        } elseif ($action === 'updated') {
                            ++$updatedCount;
                        }
                    }

                    $this->items->create([
                        'import_batch_id' => $batchId,
                        'row_number' => $row['row_number'],
                        'sku' => $row['normalized']['sku'] ?? null,
                        'is_valid' => $row['is_valid'] ? 1 : 0,
                        'validation_errors' => $row['errors'] === [] ? null : json_encode($row['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'action' => $action,
                        'product_variant_id' => $variantId,
                        'raw_data' => json_encode($row['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ], $request->authUserId());
                }

                $this->batches->update($batchId, [
                    'created_count' => $createdCount,
                    'updated_count' => $updatedCount,
                ], $request->authUserId());

                return ['batch_id' => $batchId, 'created_count' => $createdCount, 'updated_count' => $updatedCount];
            });
        } catch (\Throwable $exception) {
            $this->logger->error('CSV/Excel import failed', [
                'file_name' => $originalFileName,
                'reason' => $exception->getMessage(),
            ], 'import');

            $failedBatchId = $this->batches->create([
                'file_name' => $originalFileName,
                'file_type' => $fileType,
                'status' => 'failed',
                'warehouse_id' => $defaultWarehouse === null ? null : (int) $defaultWarehouse['id'],
                'total_rows' => $parsed['summary']['total'],
                'valid_rows' => 0,
                'invalid_rows' => $parsed['summary']['total'],
                'error_message' => substr($exception->getMessage(), 0, 500),
            ], $request->authUserId());

            @unlink($path);

            throw new HttpException(
                'The import could not be completed and no changes were made. It has been recorded as failed (reference: '
                    . $this->batches->findById($failedBatchId)['uuid'] . ').',
                500
            );
        }

        @unlink($path);

        $this->audit->log(
            entityName: 'import_batches',
            entityId: $result['batch_id'],
            action: 'import_confirmed',
            newValues: ['created' => $result['created_count'], 'updated' => $result['updated_count']],
            request: $request,
        );

        return $this->detail((string) $this->batches->findById($result['batch_id'])['uuid']);
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $params): array
    {
        return $this->batches->search($params);
    }

    /** @return array<string, mixed> */
    public function detail(string $uuid): array
    {
        $batch = $this->batches->detailByUuid($uuid);

        if ($batch === null) {
            throw new HttpException('That import batch does not exist.', 404);
        }

        $batch['items'] = array_map(static function (array $item): array {
            $item['validation_errors'] = $item['validation_errors'] === null ? null : json_decode((string) $item['validation_errors'], true);
            $item['raw_data'] = json_decode((string) $item['raw_data'], true);

            return $item;
        }, $this->items->forBatch((int) $batch['id']));

        return $batch;
    }

    /**
     * CSV/Excel → purchase-order line rows, for the purchase-inward screen's
     * bulk-add option. Deliberately narrower than preview()/confirm() above:
     * no import_batch history, no product/variant creation, no DB write of
     * any kind — this only resolves each row's SKU-or-barcode against what
     * already exists (the same ProductVariantRepository::findByCode() the
     * mobile scan lookup uses) and hands back rows for the purchase-order
     * screen's existing line-item table to review/edit like any manually
     * added line. A row whose SKU/barcode isn't recognised comes back as a
     * clear per-row error rather than silently creating a new item — that
     * stays a deliberate "Add new item" action elsewhere on the same screen,
     * since guessing a category from spreadsheet text is a materially
     * bigger and fuzzier problem than resolving a known code.
     *
     * Reuses this class's own upload/parse/column-detection plumbing
     * (receiveUpload/parseFile/detectColumnMapping/applyMapping) rather than
     * duplicating it — sku, barcode, quantity, purchase_cost, batch_no and
     * expiry_date are already canonical fields with header aliases.
     *
     * @param array<string, mixed> $file A single entry from $_FILES
     *
     * @return array<string, mixed>
     */
    /**
     * Reads an uploaded CSV/XLSX into [header, rows] and deletes the temp
     * file — for one-shot parsers that do their own column matching.
     *
     * @param array<string, mixed> $file A single entry from $_FILES
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function readTable(array $file, int $maxRows): array
    {
        $stored = $this->receiveUpload($file);

        try {
            [$header, $rows] = $this->parseFile($stored['path'], $stored['file_type']);

            if (count($rows) > $maxRows) {
                throw new HttpException(sprintf('This file has %d data rows; upload at most %d at a time.', count($rows), $maxRows), 422);
            }

            return [$header, $rows];
        } finally {
            @unlink($stored['path']);
        }
    }

    /**
     * Same as readTable() for a spreadsheet already on disk (e.g. unpacked
     * from an uploaded ZIP). The caller owns the file and its clean-up.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function readTableFromPath(string $path, string $fileType, int $maxRows): array
    {
        [$header, $rows] = $this->parseFile($path, $fileType);

        if (count($rows) > $maxRows) {
            throw new HttpException(sprintf('This file has %d data rows; upload at most %d at a time.', count($rows), $maxRows), 422);
        }

        return [$header, $rows];
    }

    public function parsePurchaseOrderItems(array $file): array
    {
        $stored = $this->receiveUpload($file);

        try {
            [$header, $dataRows] = $this->parseFile($stored['path'], $stored['file_type']);

            if (count($dataRows) > 100) {
                throw new HttpException(
                    sprintf('This file has %d data rows; a purchase order can have at most 100 lines.', count($dataRows)),
                    422
                );
            }

            $mapping = $this->detectColumnMapping($header, null);
            $rows = [];

            foreach ($dataRows as $index => $rawRow) {
                $assoc = $this->applyMapping($rawRow, $mapping);
                $rows[] = $this->resolvePurchaseOrderRow($assoc, $index + 2);
            }

            $matched = count(array_filter($rows, static fn (array $r): bool => $r['is_valid']));

            return [
                'rows' => $rows,
                'summary' => ['total' => count($rows), 'matched' => $matched, 'unmatched' => count($rows) - $matched],
            ];
        } finally {
            // One-shot: unlike preview()/confirm(), there is no later step
            // that re-reads this file, so it is cleaned up immediately
            // rather than left for sweepStaleTempFiles() to find later.
            @unlink($stored['path']);
        }
    }

    /**
     * One-shot, like parsePurchaseOrderItems() above rather than the
     * preview/confirm two-step: a bad vendor row is cheap to fix and
     * re-upload, and there is no shared-inventory side effect here that a
     * confirm step needs to guard against. Exact header names, not the
     * fuzzy alias system CANONICAL_FIELDS/HEADER_ALIASES drive — those are
     * scoped to the product/stock import shape; the sample template this
     * produces IS the header contract, so there is nothing to guess.
     * Duplicates (matched by GSTIN, falling back to phone) are skipped and
     * reported rather than erroring the whole file — one bad row must not
     * block the other forty.
     *
     * @param array<string, mixed> $file A single entry from $_FILES
     *
     * @return array<string, mixed>
     */
    public function importVendors(array $file, Request $request): array
    {
        $stored = $this->receiveUpload($file);

        try {
            [$header, $dataRows] = $this->parseFile($stored['path'], $stored['file_type']);

            if (count($dataRows) > 500) {
                throw new HttpException(
                    sprintf('This file has %d rows; import at most 500 vendors at a time.', count($dataRows)),
                    422
                );
            }

            $normalizedHeader = array_map(static fn (string $h): string => strtolower(trim($h)), $header);
            $columns = [
                'name', 'company_name', 'contact_person', 'phone', 'email',
                'address_line1', 'address_line2', 'city', 'state', 'pincode', 'country',
                'gstin', 'pan', 'bank_account_name', 'bank_account_number', 'bank_ifsc', 'bank_name',
                'payment_terms', 'notes',
            ];

            $results = [];
            $created = 0;

            foreach ($dataRows as $index => $rawRow) {
                $rowNumber = $index + 2;
                $data = [];

                foreach ($columns as $column) {
                    $colIndex = array_search(str_replace('_', ' ', $column), $normalizedHeader, true);
                    $colIndex = $colIndex === false ? array_search($column, $normalizedHeader, true) : $colIndex;
                    $value = $colIndex === false ? null : trim((string) ($rawRow[$colIndex] ?? ''));
                    $data[$column] = $value === '' ? null : $value;
                }

                $name = $data['name'];

                if ($name === null) {
                    $results[] = ['row_number' => $rowNumber, 'is_valid' => false, 'error' => 'Name is required.'];

                    continue;
                }

                if ($data['gstin'] !== null && $this->vendorAlreadyExists('gstin', $data['gstin'])) {
                    $results[] = [
                        'row_number' => $rowNumber, 'is_valid' => false,
                        'error' => "Skipped — a vendor with GSTIN {$data['gstin']} already exists.",
                    ];

                    continue;
                }

                if ($data['gstin'] === null && $data['phone'] !== null && $this->vendorAlreadyExists('phone', $data['phone'])) {
                    $results[] = [
                        'row_number' => $rowNumber, 'is_valid' => false,
                        'error' => "Skipped — a vendor with phone {$data['phone']} already exists.",
                    ];

                    continue;
                }

                $data['country'] = $data['country'] ?? 'India';
                $vendor = $this->vendorService->create($data, $request);
                ++$created;

                $results[] = ['row_number' => $rowNumber, 'is_valid' => true, 'vendor_code' => $vendor['vendor_code'], 'name' => $vendor['name']];
            }

            return [
                'rows' => $results,
                'summary' => ['total' => count($results), 'created' => $created, 'skipped' => count($results) - $created],
            ];
        } finally {
            @unlink($stored['path']);
        }
    }

    private function vendorAlreadyExists(string $column, string $value): bool
    {
        return (bool) $this->db->scalar(
            "SELECT 1 FROM `vendors` WHERE `{$column}` = :value AND `is_deleted` = 0 LIMIT 1",
            ['value' => $value]
        );
    }

    /** @return array<string, mixed> */
    private function resolvePurchaseOrderRow(array $assoc, int $rowNumber): array
    {
        $code = trim((string) ($assoc['sku'] ?? $assoc['barcode'] ?? ''));
        $quantity = $assoc['quantity'] ?? null;
        $unitCost = $assoc['purchase_cost'] ?? null;

        if ($code === '') {
            return ['row_number' => $rowNumber, 'is_valid' => false, 'error' => 'No SKU or barcode in this row.'];
        }

        if (!is_numeric($quantity) || (float) $quantity <= 0) {
            return ['row_number' => $rowNumber, 'is_valid' => false, 'error' => "SKU/barcode {$code}: quantity is missing or not a positive number."];
        }

        if (!is_numeric($unitCost) || (float) $unitCost < 0) {
            return ['row_number' => $rowNumber, 'is_valid' => false, 'error' => "SKU/barcode {$code}: purchase cost is missing or not a valid number."];
        }

        $variant = $this->variants->findByCode($code);

        if ($variant === null) {
            return [
                'row_number' => $rowNumber,
                'is_valid' => false,
                'error' => "\"{$code}\" was not found — add it manually with \"Add new item\" first, or fix the code and re-upload.",
            ];
        }

        return [
            'row_number' => $rowNumber,
            'is_valid' => true,
            'variant_uuid' => $variant['uuid'],
            'sku' => $variant['sku'],
            'barcode' => $variant['barcode'],
            'product_name' => $variant['product_name'],
            'variant_name' => $variant['variant_name'],
            'selling_price' => (float) $variant['selling_price'],
            'quantity' => (float) $quantity,
            'unit_cost' => (float) $unitCost,
            'batch_no' => !empty($assoc['batch_no']) ? (string) $assoc['batch_no'] : null,
            'expiry_date' => !empty($assoc['expiry_date']) ? (string) $assoc['expiry_date'] : null,
        ];
    }

    // -------------------------------------------------------------------
    // Parsing and validation — shared by preview() and confirm(), which is
    // the point: confirm() never trusts a client-echoed preview.
    // -------------------------------------------------------------------

    /**
     * @param array<string, string>|null $columnMapping
     *
     * @return array<string, mixed>
     */
    private function parseAndValidate(
        string $path,
        string $fileType,
        ?array $columnMapping,
        ?string $defaultWarehouseUuid,
    ): array {
        [$header, $dataRows] = $this->parseFile($path, $fileType);

        if (count($dataRows) > self::MAX_ROWS) {
            throw new HttpException(
                sprintf('This file has %d data rows; the limit is %d per import. Split it into smaller files.', count($dataRows), self::MAX_ROWS),
                422
            );
        }

        $mapping = $this->detectColumnMapping($header, $columnMapping);
        $defaultWarehouse = $defaultWarehouseUuid === null ? null : $this->warehouses->findByUuid($defaultWarehouseUuid);

        if ($defaultWarehouseUuid !== null && $defaultWarehouse === null) {
            throw new HttpException('That default warehouse does not exist.', 422);
        }

        $rows = [];
        $seenSkus = [];
        $seenBarcodes = [];
        $validCount = 0;

        foreach ($dataRows as $index => $rawRow) {
            $rowNumber = $index + 2; // header is row 1
            $assoc = $this->applyMapping($rawRow, $mapping);
            $validated = $this->validateRow($assoc, $rowNumber, $seenSkus, $seenBarcodes, $defaultWarehouse);

            if ($validated['is_valid']) {
                ++$validCount;
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'raw' => $assoc,
                'normalized' => $validated['normalized'],
                'is_valid' => $validated['is_valid'],
                'errors' => $validated['errors'],
                'action' => $validated['action'],
            ];
        }

        return [
            'columns_detected' => $mapping,
            'rows' => $rows,
            'summary' => [
                'total' => count($rows),
                'valid' => $validCount,
                'invalid' => count($rows) - $validCount,
                'to_create' => count(array_filter($rows, static fn (array $r): bool => $r['action'] === 'create')),
                'to_update' => count(array_filter($rows, static fn (array $r): bool => $r['action'] === 'update')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $seenSkus
     * @param array<string, int> $seenBarcodes
     *
     * @return array{is_valid:bool, errors:array<string, array<int, string>>, action:string, normalized:array<string, mixed>}
     */
    private function validateRow(array $row, int $rowNumber, array &$seenSkus, array &$seenBarcodes, ?array $defaultWarehouse): array
    {
        $errors = [];
        $sku = trim((string) ($row['sku'] ?? ''));

        // No SKU is fine: the row is matched to an existing pack by its
        // barcode, else by product name + pack name, and a new pack is created
        // WITHOUT a SKU (none is generated here — "Generate Barcode" on the
        // inventory screen assigns it later).
        $rowBarcode = trim((string) ($row['barcode'] ?? ''));
        $rowProduct = trim((string) ($row['product_name'] ?? ''));
        $rowVariant = trim((string) ($row['variant_name'] ?? ''));

        if ($sku === '' && $rowBarcode === '' && ($rowProduct === '' || $rowVariant === '')) {
            return [
                'is_valid' => false,
                'errors' => ['sku' => ['Give a SKU, a barcode, or the product name and pack name.']],
                'action' => 'skip',
                'normalized' => [],
            ];
        }

        $rowKey = $sku !== '' ? 's:' . mb_strtolower($sku)
            : ($rowBarcode !== '' ? 'b:' . $rowBarcode : 'n:' . mb_strtolower($rowProduct) . '|' . mb_strtolower($rowVariant));

        if (isset($seenSkus[$rowKey])) {
            return [
                'is_valid' => false,
                'errors' => ['sku' => ["Duplicate row within this file (same item as row {$seenSkus[$rowKey]})."]],
                'action' => 'skip',
                'normalized' => ['sku' => $sku !== '' ? $sku : null],
            ];
        }

        $seenSkus[$rowKey] = $rowNumber;

        if ($sku !== '') {
            $existing = $this->variants->findBySku($sku);

            if ($existing === null && $this->variants->codeTaken($sku)) {
                return [
                    'is_valid' => false,
                    'errors' => ['sku' => ["SKU {$sku} is already used as another item's barcode."]],
                    'action' => 'skip',
                    'normalized' => ['sku' => $sku],
                ];
            }
        } elseif ($rowBarcode !== '') {
            $existing = $this->variants->findByCode($rowBarcode);
        } else {
            $existing = $this->variants->findByNames($rowProduct, $rowVariant);
        }

        $isNew = $existing === null;
        $normalized = ['sku' => $sku !== '' ? $sku : null];

        if (!$isNew) {
            $normalized['variant_id'] = (int) $existing['id'];
        }

        // --- category (required for a new SKU) --------------------------
        $categorySlug = trim((string) ($row['category'] ?? ''));

        if ($categorySlug !== '') {
            $category = $this->categories->findBySlug($categorySlug);

            if ($category === null) {
                $errors['category'][] = "Unknown category slug: {$categorySlug}";
            } else {
                $normalized['category_id'] = (int) $category['id'];
            }
        } elseif ($isNew) {
            $errors['category'][] = 'Category is required for a new SKU.';
        }

        // --- names (required for a new SKU) ------------------------------
        $productName = trim((string) ($row['product_name'] ?? ''));
        $variantName = trim((string) ($row['variant_name'] ?? ''));

        if ($productName !== '') {
            $normalized['product_name'] = $productName;
        } elseif ($isNew) {
            $errors['product_name'][] = 'Product name is required for a new SKU.';
        }

        if ($variantName !== '') {
            $normalized['variant_name'] = $variantName;
        } elseif ($isNew) {
            $errors['variant_name'][] = 'Variant/pack name is required for a new SKU.';
        }

        // --- pack type / stock unit type ---------------------------------
        $packType = trim((string) ($row['pack_type'] ?? ''));

        if ($packType !== '') {
            if (!in_array($packType, self::PACK_TYPES, true)) {
                $errors['pack_type'][] = 'Must be one of: ' . implode(', ', self::PACK_TYPES);
            } else {
                $normalized['pack_type'] = $packType;
            }
        }

        $stockUnitType = trim((string) ($row['stock_unit_type'] ?? ''));

        if ($stockUnitType !== '') {
            if (!in_array($stockUnitType, self::STOCK_UNIT_TYPES, true)) {
                $errors['stock_unit_type'][] = 'Must be one of: ' . implode(', ', self::STOCK_UNIT_TYPES);
            } else {
                $normalized['stock_unit_type'] = $stockUnitType;
            }
        }

        $barcode = trim((string) ($row['barcode'] ?? ''));

        if ($barcode !== '') {
            if (isset($seenBarcodes[$barcode])) {
                $errors['barcode'][] = "Duplicate barcode within this file (already on row {$seenBarcodes[$barcode]}).";
            } elseif ($this->variants->barcodeExists($barcode, $isNew ? null : (int) $existing['id'])) {
                $errors['barcode'][] = "Barcode {$barcode} is already used by another pack size.";
            } else {
                $seenBarcodes[$barcode] = $rowNumber;
                $normalized['barcode'] = $barcode;
            }
        }

        if (trim((string) ($row['unit_label'] ?? '')) !== '') {
            $normalized['unit_label'] = trim((string) $row['unit_label']);
        }

        // --- weight (required for a new SKU) -----------------------------
        $weight = $this->numericOrError($row['weight_grams'] ?? null, 'weight_grams', $errors, min: 1);

        if ($weight !== null) {
            $normalized['weight_grams'] = (int) $weight;
        } elseif ($isNew && !isset($errors['weight_grams'])) {
            $errors['weight_grams'][] = 'Weight (grams) is required for a new SKU.';
        }

        // --- pricing --------------------------------------------------
        $mrp = $this->numericOrError($row['mrp'] ?? null, 'mrp', $errors, min: 0.01);
        $sellingPrice = $this->numericOrError($row['selling_price'] ?? null, 'selling_price', $errors, min: 0.01);

        if ($mrp !== null) {
            $normalized['mrp'] = $mrp;
        } elseif ($isNew && !isset($errors['mrp'])) {
            $errors['mrp'][] = 'MRP is required for a new SKU.';
        }

        if ($sellingPrice !== null) {
            $normalized['selling_price'] = $sellingPrice;
        } elseif ($isNew && !isset($errors['selling_price'])) {
            $errors['selling_price'][] = 'Selling price is required for a new SKU.';
        }

        $effectiveMrp = $mrp ?? ($isNew ? null : (float) $existing['mrp']);
        $effectiveSelling = $sellingPrice ?? ($isNew ? null : (float) $existing['selling_price']);

        if ($effectiveMrp !== null && $effectiveSelling !== null && $effectiveSelling > $effectiveMrp) {
            $errors['selling_price'][] = 'Selling price cannot be above MRP.';
        }

        // --- warehouse / quantity / reorder threshold --------------------
        $warehouseCode = trim((string) ($row['warehouse_code'] ?? ''));
        $rowWarehouse = $defaultWarehouse;

        if ($warehouseCode !== '') {
            $found = $this->warehouses->findByCode($warehouseCode);

            if ($found === null) {
                $errors['warehouse_code'][] = "Unknown warehouse code: {$warehouseCode}";
            } else {
                $rowWarehouse = $found;
            }
        }

        $quantity = $this->numericOrError($row['quantity'] ?? null, 'quantity', $errors, min: 0);
        $reorderThreshold = $this->numericOrError($row['reorder_threshold'] ?? null, 'reorder_threshold', $errors, min: 0);
        $purchaseCost = $this->numericOrError($row['purchase_cost'] ?? null, 'purchase_cost', $errors, min: 0);

        if (($quantity !== null && $quantity > 0) || $reorderThreshold !== null) {
            if ($rowWarehouse === null) {
                $errors['warehouse_code'][] = 'A warehouse is required to record quantity or a reorder threshold (set a default for the import, or a warehouse_code column).';
            } else {
                $normalized['warehouse_id'] = (int) $rowWarehouse['id'];
            }
        }

        if ($quantity !== null && $quantity > 0) {
            $normalized['quantity'] = $quantity;
            $normalized['purchase_cost'] = $purchaseCost;

            $batchNo = trim((string) ($row['batch_no'] ?? ''));
            $expiryDate = trim((string) ($row['expiry_date'] ?? ''));

            if ($batchNo !== '') {
                $normalized['batch_no'] = $batchNo;
            }

            if ($expiryDate !== '') {
                if (strtotime($expiryDate) === false) {
                    $errors['expiry_date'][] = 'Not a recognisable date.';
                } else {
                    $normalized['expiry_date'] = date('Y-m-d', strtotime($expiryDate));
                }
            }
        }

        if ($reorderThreshold !== null) {
            $normalized['reorder_threshold'] = $reorderThreshold;
        }

        $isValid = $errors === [];

        return [
            'is_valid' => $isValid,
            'errors' => $errors,
            'action' => $isValid ? ($isNew ? 'create' : 'update') : 'skip',
            'normalized' => $normalized,
        ];
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    private function numericOrError(mixed $value, string $field, array &$errors, float $min): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $trimmed = trim((string) $value);

        if (!is_numeric($trimmed)) {
            $errors[$field][] = 'Must be a number.';

            return null;
        }

        $number = (float) $trimmed;

        if ($number < $min) {
            $errors[$field][] = "Must be at least {$min}.";

            return null;
        }

        return $number;
    }

    /**
     * Creates a draft product plus one or more variants from a handful of
     * fields — the brief's "controlled product/barcode mapping or creation
     * workflow" for an item that is not on file yet, and the Purchase Inward
     * "New item" form. Gated to authorized roles at the route level
     * ($manager). Always a create (never an update).
     *
     * Two payload shapes are accepted:
     *  - flat (mobile scan): variant_name, weight_grams, mrp, selling_price,
     *    pack_type, barcode, sku at the top level -> one variant;
     *  - `variants`: a list of {variant_name?, barcode?, sku?, weight_grams?,
     *    mrp, selling_price, pack_type?, stock_unit_type?, unit_label?,
     *    options?: {size, color}} -> one product, many variants (a size x
     *    colour grid for clothing/footwear, or a loose + packed pair).
     *
     * The category's business type (inherited from its top-level ancestor)
     * decides whether a weight is mandatory: grocery and oils are sold by
     * weight/volume so it is; clothing, footwear, toys and stationery are
     * not, and default to 1 g (the column is NOT NULL and must be > 0).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> the first variant, plus `variants` (all of them)
     */
    public function createFromScan(array $data, Request $request, bool $fromImport = false): array
    {
        // $fromImport (Products → Import Excel/CSV): a row with no SKU is saved
        // WITHOUT one — no code is invented. The SKU is assigned later, only
        // when staff press "Generate Barcode" on the inventory screen.
        $category = $this->categories->findByUuid((string) $data['category_uuid']);

        if ($category === null) {
            throw new HttpException('That category does not exist.', 422, ['category_uuid' => ['Unknown category.']]);
        }

        $itemType = $this->effectiveItemType((int) $category['id']);
        $weightRequired = in_array($itemType, ['grocery', 'oils'], true);

        $specs = $data['variants'] ?? null;

        if (!is_array($specs) || $specs === []) {
            $specs = [[
                'variant_name' => $data['variant_name'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'sku' => $data['sku'] ?? null,
                'weight_grams' => $data['weight_grams'] ?? null,
                'mrp' => $data['mrp'] ?? null,
                'selling_price' => $data['selling_price'] ?? null,
                'pack_type' => $data['pack_type'] ?? null,
                'stock_unit_type' => $data['stock_unit_type'] ?? null,
                'unit_label' => $data['unit_label'] ?? null,
                'options' => $data['options'] ?? [],
            ]];
        }

        if (count($specs) > 60) {
            throw new HttpException('Too many variants in one go (max 60).', 422, ['variants' => ['Add fewer size/colour combinations at once.']]);
        }

        $clean = [];
        $seenBarcodes = [];

        foreach ($specs as $i => $spec) {
            $spec = is_array($spec) ? $spec : [];
            $label = 'variants.' . $i;
            $name = trim((string) ($spec['variant_name'] ?? ''));
            $mrp = (float) ($spec['mrp'] ?? 0);
            $selling = (float) ($spec['selling_price'] ?? 0);

            if ($name === '' || mb_strlen($name) > 80) {
                throw new HttpException('Each variant needs a name (up to 80 characters).', 422, [$label . '.variant_name' => ['Required.']]);
            }

            if ($mrp <= 0 || $selling <= 0) {
                throw new HttpException("Enter an MRP and selling price for {$name}.", 422, [$label . '.mrp' => ['Required.']]);
            }

            if ($selling > $mrp) {
                throw new HttpException("Selling price cannot be above MRP ({$name}).", 422, [
                    'selling_price' => ['Must not exceed the MRP.'],
                ]);
            }

            $weight = (int) ($spec['weight_grams'] ?? 0);

            if ($weight <= 0) {
                if ($weightRequired) {
                    throw new HttpException("Enter the weight or volume for {$name}.", 422, ['weight_grams' => ['Required for this kind of item.']]);
                }

                $weight = 1;
            }

            if ($weight > 100000) {
                throw new HttpException('Weight is too large.', 422, ['weight_grams' => ['Must be at most 100000.']]);
            }

            $barcode = Barcode::clean((string) ($spec['barcode'] ?? ''));

            if ($barcode !== '') {
                // findByCode() also catches the same code in another form
                // (leading 0, missing check digit) already on an item.
                if (isset($seenBarcodes[$barcode]) || $this->variants->findByCode($barcode) !== null) {
                    throw new HttpException("Barcode {$barcode} is already mapped to another item.", 422, [
                        'barcode' => ['Already in use — try looking it up instead of creating it.'],
                    ]);
                }

                $seenBarcodes[$barcode] = true;
            }

            $unitType = in_array($spec['stock_unit_type'] ?? null, ['weight', 'quantity'], true)
                ? $spec['stock_unit_type']
                : ($weightRequired ? 'weight' : 'quantity');

            $packType = in_array($spec['pack_type'] ?? null, ['pouch', 'jar', 'box', 'tin', 'gift_box', 'refill', 'other'], true)
                ? $spec['pack_type']
                : ($weightRequired ? 'pouch' : 'other');

            $unitLabel = trim((string) ($spec['unit_label'] ?? ''));

            $clean[] = [
                'name' => $name,
                'barcode' => $barcode,
                'sku' => trim((string) ($spec['sku'] ?? '')),
                'weight' => $weight,
                'mrp' => $mrp,
                'selling' => $selling,
                'pack_type' => $packType,
                'stock_unit_type' => $unitType,
                'unit_label' => $unitLabel !== '' ? mb_substr($unitLabel, 0, 20) : null,
                'options' => is_array($spec['options'] ?? null) ? $spec['options'] : [],
            ];
        }

        $actorId = $request->authUserId();
        $foodLike = $weightRequired;

        $variantIds = $this->db->transaction(function () use ($clean, $category, $data, $actorId, $foodLike, $request, $fromImport): array {
            $firstSku = $clean[0]['sku'] !== '' ? $clean[0]['sku']
                : ($clean[0]['barcode'] !== '' ? $clean[0]['barcode']
                    : ($fromImport ? (string) $data['product_name'] : $this->uniqueGeneratedBarcode()));

            $productId = $this->products->create([
                'category_id' => (int) $category['id'],
                'product_code' => $this->uniqueProductCode($firstSku),
                'slug' => $this->uniqueSlug((string) $data['product_name']),
                'name' => (string) $data['product_name'],
                'brand' => isset($data['brand']) && trim((string) $data['brand']) !== '' ? trim((string) $data['brand']) : null,
                'short_description' => isset($data['short_description']) && trim((string) $data['short_description']) !== '' ? mb_substr(trim((string) $data['short_description']), 0, 320) : null,
                'hsn_code' => isset($data['hsn_code']) && trim((string) $data['hsn_code']) !== '' ? trim((string) $data['hsn_code']) : null,
                'gst_rate' => isset($data['gst_rate']) && $data['gst_rate'] !== '' ? number_format((float) $data['gst_rate'], 2, '.', '') : '5.00',
                'is_vegetarian' => $foodLike ? 1 : 0,
                'is_gift_packable' => $foodLike ? 1 : 0,
                'status' => 'draft',
            ], $actorId);

            $ids = [];
            $usedSkus = [];

            foreach ($clean as $index => $v) {
                if ($fromImport) {
                    // Keep exactly what the file says; nothing generated.
                    $barcode = $v['barcode'] !== '' ? $v['barcode'] : null;
                    $sku = $v['sku'] !== '' ? $v['sku'] : null;
                } else {
                    $barcode = $v['barcode'] !== '' ? $v['barcode'] : $this->uniqueGeneratedBarcode();
                    $sku = $v['sku'] !== '' ? $v['sku'] : $barcode;
                }

                if ($sku !== null && (isset($usedSkus[$sku]) || $this->variants->skuExists($sku)
                    || ($sku !== $barcode && $this->variants->codeTaken($sku)))) {
                    throw new HttpException("SKU {$sku} is already in use.", 422, ['sku' => ['Already in use.']]);
                }

                if ($fromImport && $barcode !== null && $barcode !== $sku && $this->variants->codeTaken($barcode)) {
                    throw new HttpException("Barcode {$barcode} is already in use.", 422, ['barcode' => ['Already in use.']]);
                }

                if ($sku !== null) {
                    $usedSkus[$sku] = true;
                }

                $variantId = $this->variants->create([
                    'product_id' => $productId,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'variant_name' => $v['name'],
                    'weight_grams' => $v['weight'],
                    'pack_type' => $v['pack_type'],
                    'stock_unit_type' => $v['stock_unit_type'],
                    'unit_label' => $v['unit_label'],
                    'mrp' => number_format($v['mrp'], 2, '.', ''),
                    'selling_price' => number_format($v['selling'], 2, '.', ''),
                    'is_default' => $index === 0 ? 1 : 0,
                    'display_order' => 100 + $index,
                ], $actorId);

                if ($v['options'] !== []) {
                    $this->variantOptions->assignByName($variantId, $v['options'], $actorId);
                }

                $ids[] = $variantId;
            }

            $this->audit->log(
                entityName: 'products',
                entityId: $productId,
                action: 'quick_create_from_scan',
                newValues: ['variants' => count($ids), 'item_type' => $this->effectiveItemType((int) $category['id'])],
                request: $request,
            );

            return $ids;
        });

        $variants = array_map(fn (int $id): array => (array) $this->variants->findById($id), $variantIds);
        $first = $variants[0];
        $first['variants'] = $variants;

        return $first;
    }

    /** Walks up the category tree to the first ancestor that carries a business type. */
    private function effectiveItemType(int $categoryId): string
    {
        $cursor = $categoryId;

        for ($depth = 0; $cursor > 0 && $depth < 12; ++$depth) {
            $row = $this->db->selectOne(
                'SELECT `parent_id`, `item_type` FROM `categories` WHERE `id` = :id LIMIT 1',
                ['id' => $cursor]
            );

            if ($row === null) {
                break;
            }

            if ($row['item_type'] !== null) {
                return (string) $row['item_type'];
            }

            $cursor = (int) ($row['parent_id'] ?? 0);
        }

        return 'general';
    }

    // -------------------------------------------------------------------
    // Confirmed writes — the only place this class calls create()/update()
    // on products/variants, or InventoryService.
    // -------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     *
     * @return array{action:string, variant_id:?int}
     */
    private function applyRow(array $row, Request $request): array
    {
        $normalized = $row['normalized'];
        $sku = $normalized['sku'] ?? null;
        // Matched at preview by SKU, barcode or name; re-read so a row whose
        // pack was deleted since then is created again rather than lost.
        $existing = isset($normalized['variant_id'])
            ? $this->variants->findById((int) $normalized['variant_id'])
            : ($sku !== null ? $this->variants->findBySku($sku) : null);
        $actorId = $request->authUserId();

        if ($existing !== null && (int) ($existing['is_deleted'] ?? 0) === 1) {
            $existing = null;
        }

        if ($existing === null) {
            $productId = $this->products->create([
                'category_id' => $normalized['category_id'],
                'product_code' => $this->uniqueProductCode($sku ?? ($normalized['barcode'] ?? $normalized['product_name'])),
                'slug' => $this->uniqueSlug($normalized['product_name']),
                'name' => $normalized['product_name'],
                'status' => 'draft',
            ], $actorId);

            $variantId = $this->variants->create([
                'product_id' => $productId,
                'sku' => $sku,
                'barcode' => $normalized['barcode'] ?? null,
                'variant_name' => $normalized['variant_name'],
                'weight_grams' => $normalized['weight_grams'],
                'pack_type' => $normalized['pack_type'] ?? 'pouch',
                'stock_unit_type' => $normalized['stock_unit_type'] ?? 'weight',
                'unit_label' => $normalized['unit_label'] ?? null,
                'mrp' => number_format($normalized['mrp'], 2, '.', ''),
                'selling_price' => number_format($normalized['selling_price'], 2, '.', ''),
                'is_default' => 1,
            ], $actorId);

            $action = 'created';
        } else {
            $variantId = (int) $existing['id'];
            $productUpdates = [];
            $variantUpdates = [];

            if (isset($normalized['product_name'])) {
                $productUpdates['name'] = $normalized['product_name'];
            }

            if (isset($normalized['category_id'])) {
                $productUpdates['category_id'] = $normalized['category_id'];
            }

            foreach (['barcode', 'variant_name', 'weight_grams', 'pack_type', 'stock_unit_type', 'unit_label'] as $field) {
                if (isset($normalized[$field])) {
                    $variantUpdates[$field] = $normalized[$field];
                }
            }

            if (isset($normalized['mrp'])) {
                $variantUpdates['mrp'] = number_format($normalized['mrp'], 2, '.', '');
            }

            if (isset($normalized['selling_price'])) {
                $variantUpdates['selling_price'] = number_format($normalized['selling_price'], 2, '.', '');
            }

            if ($productUpdates !== []) {
                $this->products->update((int) $existing['product_id'], $productUpdates, $actorId);
            }

            if ($variantUpdates !== []) {
                $this->variants->update($variantId, $variantUpdates, $actorId);
            }

            $action = 'updated';
        }

        if (isset($normalized['quantity']) && $normalized['quantity'] > 0) {
            $this->inventory->recordMovement(
                variantId: $variantId,
                warehouseId: $normalized['warehouse_id'],
                movementType: 'inward',
                quantityDelta: $normalized['quantity'],
                unitCost: $normalized['purchase_cost'] ?? null,
                referenceType: 'csv_import',
                batchNo: $normalized['batch_no'] ?? null,
                expiryDate: $normalized['expiry_date'] ?? null,
                reason: 'CSV/Excel import',
                performedBy: $actorId,
                request: $request,
            );
        }

        if (isset($normalized['reorder_threshold'], $normalized['warehouse_id'])) {
            $this->inventory->setReorderThreshold(
                $variantId,
                $normalized['warehouse_id'],
                $normalized['reorder_threshold'],
                $actorId,
                $request,
            );
        }

        return ['action' => $action, 'variant_id' => $variantId];
    }

    /** A freshly generated EAN-13 that isn't already on another pack size — collisions are astronomically rare but checked anyway. */
    private function uniqueGeneratedBarcode(): string
    {
        do {
            $candidate = Barcode::generateEan13();
        } while ($this->variants->barcodeExists($candidate));

        return $candidate;
    }

    private function uniqueProductCode(string $sku): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $sku) ?: 'SKU', 0, 36));
        $candidate = $base;
        $suffix = 2;

        while ($this->products->productCodeExists($candidate)) {
            $candidate = substr($base, 0, 36) . '-' . $suffix;
            ++$suffix;
        }

        return $candidate;
    }

    private function uniqueSlug(string $productName): string
    {
        $base = Str::slug($productName);
        $candidate = $base;
        $suffix = 2;

        while ($this->products->slugExists($candidate)) {
            $candidate = $base . '-' . $suffix;
            ++$suffix;

            if ($suffix > 100) {
                $candidate = $base . '-' . bin2hex(random_bytes(3));

                break;
            }
        }

        return $candidate;
    }

    // -------------------------------------------------------------------
    // File handling: upload, content-sniffed type detection, parsing,
    // column mapping, temp storage.
    // -------------------------------------------------------------------

    /** @param array<string, mixed> $file */
    private function receiveUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new HttpException('The file could not be uploaded. Please try again.', 422);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if (!is_uploaded_file($tmpName)) {
            throw new HttpException('Upload could not be verified.', 422);
        }

        $maxBytes = 10_485_760; // 10 MB
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > $maxBytes) {
            throw new HttpException('File must be between 1 byte and 10 MB.', 422);
        }

        $fileType = $this->detectFileType($tmpName, (string) ($file['name'] ?? ''));
        $token = bin2hex(random_bytes(16));
        $destination = $this->tempFilePath($token, $fileType);

        $this->ensureDirectory(dirname($destination));

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new HttpException('Uploaded file could not be stored.', 500);
        }

        return [
            'token' => $token,
            'file_type' => $fileType,
            'file_name' => (string) ($file['name'] ?? 'import'),
            'path' => $destination,
        ];
    }

    /** Content-sniffed, not just the extension: an .xlsx is a ZIP container ("PK" magic bytes); anything else is treated as CSV/text. */
    private function detectFileType(string $path, string $originalName): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new HttpException('The file could not be read.', 422);
        }

        $magic = fread($handle, 2);
        fclose($handle);

        if ($magic === 'PK') {
            return 'xlsx';
        }

        if (!str_ends_with(strtolower($originalName), '.csv') && !str_ends_with(strtolower($originalName), '.txt')) {
            // Not a ZIP and not named like a text file — refuse rather than guess.
            throw new HttpException(
                'Only .csv and .xlsx files are accepted.',
                422,
                ['file' => ['Could not recognise this as a CSV or Excel file.']]
            );
        }

        return 'csv';
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>} [header row, data rows]
     */
    private function parseFile(string $path, string $fileType): array
    {
        if ($fileType === 'xlsx') {
            $spreadsheet = IOFactory::load($path);
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

            if ($sheetData === []) {
                throw new HttpException('The spreadsheet has no rows.', 422);
            }

            $header = array_map(static fn (mixed $v): string => trim((string) $v), array_shift($sheetData));

            return [$header, $sheetData];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new HttpException('The file could not be read.', 422);
        }

        // Excel-exported CSVs commonly carry a UTF-8 BOM on the first cell.
        $bom = fread($handle, 3);

        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            throw new HttpException('The file has no rows.', 422);
        }

        $header = array_map(static fn (mixed $v): string => trim((string) $v), $header);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === ['']) {
                continue; // blank line
            }

            $rows[] = $row;
        }

        fclose($handle);

        return [$header, $rows];
    }

    /**
     * @param array<int, string> $header
     * @param array<string, string>|null $override
     *
     * @return array<string, int> canonical field => column index
     */
    private function detectColumnMapping(array $header, ?array $override): array
    {
        // Underscores/hyphens are treated as word separators, same as spaces
        // — a header can read "Weight Grams", "weight_grams" or "weight-grams"
        // and match the same alias, since a canonical field's own snake_case
        // name is exactly what a lot of source spreadsheets will already use.
        $normalizedHeader = array_map([$this, 'normaliseHeaderText'], $header);
        $mapping = [];

        foreach (self::CANONICAL_FIELDS as $field) {
            if ($override !== null && isset($override[$field])) {
                $index = array_search($this->normaliseHeaderText($override[$field]), $normalizedHeader, true);

                if ($index !== false) {
                    $mapping[$field] = $index;
                }

                continue;
            }

            foreach (self::HEADER_ALIASES[$field] as $alias) {
                $index = array_search($alias, $normalizedHeader, true);

                if ($index !== false) {
                    $mapping[$field] = $index;

                    break;
                }
            }
        }

        return $mapping;
    }

    private function normaliseHeaderText(string $header): string
    {
        $spaced = str_replace(['_', '-'], ' ', $header);

        return strtolower(trim(preg_replace('/\s+/', ' ', $spaced) ?? ''));
    }

    /**
     * @param array<int, mixed> $rawRow
     * @param array<string, int> $mapping
     *
     * @return array<string, mixed>
     */
    private function applyMapping(array $rawRow, array $mapping): array
    {
        $assoc = [];

        foreach ($mapping as $field => $index) {
            $assoc[$field] = $rawRow[$index] ?? null;
        }

        return $assoc;
    }

    private function tempFilePath(string $token, string $fileType): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new HttpException('That import reference is invalid.', 422);
        }

        return APP_ROOT . '/storage/imports/tmp/' . $token . '.' . $fileType;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new HttpException('Import directory could not be created.', 500);
        }
    }

    private function sweepStaleTempFiles(): void
    {
        $directory = APP_ROOT . '/storage/imports/tmp';

        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file) && (time() - (int) filemtime($file)) > self::TEMP_FILE_MAX_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }
}
