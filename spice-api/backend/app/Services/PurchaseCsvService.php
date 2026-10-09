<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * Vendor bill upload: one CSV/XLSX -> a reviewed list of purchase lines that
 * may include brand-new items.
 *
 * The file only needs to say what was bought (item, quantity, cost). Everything
 * derivable is worked out here and shown to the user to verify:
 *   - existing item (matched by SKU / barcode) vs. new item to be created;
 *   - category and sub-category, matched by name (or "Category > Sub" in one
 *     cell, or a sub-category name on its own);
 *   - the business type (grocery, oils, clothing, ...) from the category,
 *     and from it the default GST rate and whether a weight is required;
 *   - weight from a pack size such as "500 g" / "1 kg" / "750 ml";
 *   - loose items priced per kg / litre / piece;
 *   - discount amount, line total, GST, net cost;
 *   - selling price from a markup %, MRP from the selling price (or the
 *     reverse), each recorded in `notes` so nothing is silently invented.
 *
 * Nothing is written here. Rows that cannot be resolved are returned as
 * errors with the reason, so the user can fix them on screen or in the file.
 */
final class PurchaseCsvService
{
    private const MAX_ROWS = 100;

    private const ALIASES = [
        'sku' => ['sku', 'itemcode', 'productcode'],
        'barcode' => ['barcode', 'ean', 'upc'],
        'category' => ['category', 'maincategory'],
        'sub_category' => ['subcategory', 'subcat', 'subcategoryname'],
        'product_name' => ['productname', 'product', 'itemname', 'item', 'name'],
        'brand' => ['brand'],
        'variant_name' => ['variant', 'variantname', 'packsize', 'pack', 'packname'],
        'size' => ['size'],
        'colour' => ['colour', 'color'],
        'weight' => ['weightg', 'weightgrams', 'weight', 'netweight'],
        'unit' => ['unit', 'uom', 'soldby', 'unitlabel'],
        'loose' => ['loose', 'soldloose', 'isloose'],
        'pack_type' => ['packtype'],
        'quantity' => ['quantity', 'qty'],
        'unit_cost' => ['unitcost', 'cost', 'purchasecost', 'rate', 'purchaserate', 'costprice'],
        'discount_percent' => ['discount', 'discountpercent', 'disc', 'vendordiscount', 'discountpct'],
        'gst_rate' => ['gst', 'gstrate', 'gstpercent', 'tax', 'taxrate'],
        'hsn_code' => ['hsn', 'hsncode'],
        'mrp' => ['mrp'],
        'selling_price' => ['sellingprice', 'price', 'saleprice', 'sp'],
        'markup_percent' => ['markup', 'markuppercent', 'markuppct'],
        'batch_no' => ['batchno', 'batch', 'lot', 'lotno'],
        'expiry_date' => ['expirydate', 'expiry', 'expires', 'bestbefore'],
        'publish' => ['publishonline', 'publish', 'online', 'sellonline'],
        'image' => ['image', 'imagefile', 'imagename', 'photo', 'picture', 'imagefilename'],
        'short_description' => ['shortdescription', 'description', 'desc', 'details'],
    ];

    /** @var array<string, array{0: string, 1: float}> unit => [label, grams per unit] */
    private const UNITS = [
        'kg' => ['kg', 1000.0], 'g' => ['g', 1.0], 'litre' => ['litre', 1000.0], 'ml' => ['ml', 1.0],
        'piece' => ['pcs', 1.0], 'dozen' => ['dozen', 1.0],
    ];

    private const GST_BY_TYPE = [
        'grocery' => 5.0, 'oils' => 5.0, 'clothing' => 12.0, 'footwear' => 12.0, 'toys' => 12.0, 'stationery' => 12.0, 'general' => 18.0,
    ];

    public function __construct(
        private readonly ImportService $imports,
        private readonly Database $db,
        private readonly ProductService $products,
    ) {
    }

    // -----------------------------------------------------------------------
    // Parse
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $file  the uploaded CSV / XLSX / ZIP (spreadsheet + images)
     * @param string $mode 'purchase' (quantity and cost required) or 'products' (catalogue only)
     *
     * @return array<string, mixed>
     */
    public function parse(array $file, string $mode = 'purchase'): array
    {
        $images = [];
        $token = null;
        $name = strtolower((string) ($file['name'] ?? ''));

        if (str_ends_with($name, '.zip')) {
            $token = bin2hex(random_bytes(16));
            [$header, $dataRows, $images] = $this->readZip($file, $token);
        } else {
            [$header, $dataRows] = $this->imports->readTable($file, self::MAX_ROWS);
        }

        $map = $this->mapHeader($header);

        if ($mode === 'purchase' && (!isset($map['quantity']) || !isset($map['unit_cost']))) {
            throw new HttpException('The file needs at least Quantity and Unit Cost columns (plus a SKU, or a Category and Product Name for new items).', 422, [
                'file' => ['Missing Quantity / Unit Cost column.'],
            ]);
        }

        if (!isset($map['product_name']) && !isset($map['sku'])) {
            throw new HttpException('The file needs a Product Name column (or a SKU for existing items).', 422, ['file' => ['Missing Product Name column.']]);
        }

        $tree = $this->categoryTree();
        $rows = [];
        $seen = [];
        $today = date('Y-m-d');

        foreach ($dataRows as $i => $raw) {
            $cells = [];

            foreach ($map as $field => $col) {
                $cells[$field] = trim((string) ($raw[$col] ?? ''));
            }

            if (implode('', $cells) === '') {
                continue;
            }

            $rows[] = $this->resolveRow($cells, $i + 2, $tree, $seen, $today, $mode, $images, $token !== null);
        }

        return ['rows' => $rows, 'summary' => $this->summarise($rows), 'image_token' => $token, 'images_found' => count($images)];
    }

    // -----------------------------------------------------------------------
    // ZIP handling: one spreadsheet plus any number of images
    // -----------------------------------------------------------------------

    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    private function imageRoot(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'spice_import_images';
    }

    /**
     * Unpacks the ZIP into a private folder (never trusting entry names as
     * paths — every file is written under a name we choose) and reads the
     * spreadsheet inside it.
     *
     * @param array<string, mixed> $file
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array<string, string>}
     */
    private function readZip(array $file, string $token): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new HttpException('The ZIP file could not be uploaded. Please try again.', 422);
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new HttpException('This server cannot read ZIP files (PHP zip extension missing). Upload the Excel/CSV on its own.', 422);
        }

        $zip = new \ZipArchive();

        if ($zip->open((string) $file['tmp_name']) !== true) {
            throw new HttpException('That is not a valid ZIP file.', 422);
        }

        $this->sweepOldImageFolders();
        $dir = $this->imageRoot() . DIRECTORY_SEPARATOR . $token;

        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            $zip->close();
            throw new HttpException('Could not prepare a folder for the images.', 500);
        }

        if ($zip->numFiles > 400) {
            $zip->close();
            throw new HttpException('The ZIP has too many files (max 400).', 422);
        }

        $sheetPath = null;
        $sheetType = null;
        $images = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entry = (string) $stat['name'];

            if (str_ends_with($entry, '/') || str_contains($entry, '__MACOSX') || str_starts_with(basename($entry), '.') || str_starts_with(basename($entry), '~$')) {
                continue;
            }

            $total += (int) $stat['size'];

            if ($total > 120 * 1024 * 1024) {
                $zip->close();
                throw new HttpException('The ZIP is too large when unpacked (max 120 MB).', 422);
            }

            $base = basename($entry);
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));

            if ($ext === 'xlsx' || $ext === 'csv') {
                if ($sheetPath !== null) {
                    continue; // first spreadsheet wins
                }

                $target = $dir . DIRECTORY_SEPARATOR . 'sheet.' . $ext;
                $this->extract($zip, $i, $target);
                $sheetPath = $target;
                $sheetType = $ext;
            } elseif (in_array($ext, self::IMAGE_EXT, true)) {
                $safe = $this->safeName($base);
                $target = $dir . DIRECTORY_SEPARATOR . $safe;
                $this->extract($zip, $i, $target);
                $images[strtolower($base)] = $safe;
            }
        }

        $zip->close();

        if ($sheetPath === null) {
            throw new HttpException('No Excel (.xlsx) or CSV file was found inside the ZIP.', 422);
        }

        [$header, $rows] = $this->imports->readTableFromPath($sheetPath, (string) $sheetType, self::MAX_ROWS);
        @unlink($sheetPath);

        return [$header, $rows, $images];
    }

    private function extract(\ZipArchive $zip, int $index, string $target): void
    {
        $in = $zip->getStream((string) $zip->getNameIndex($index));
        $out = fopen($target, 'wb');

        if ($in === false || $out === false) {
            throw new HttpException('A file inside the ZIP could not be read.', 422);
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    private function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'image';
    }

    private function sweepOldImageFolders(): void
    {
        $root = $this->imageRoot();

        if (!is_dir($root)) {
            return;
        }

        foreach (glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            if (filemtime($folder) < time() - 86400) {
                foreach (glob($folder . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($folder);
            }
        }
    }

    private function imagePath(string $token, string $name): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $path = $this->imageRoot() . DIRECTORY_SEPARATOR . $token . DIRECTORY_SEPARATOR . $this->safeName(basename($name));

        return is_file($path) ? $path : null;
    }

    /**
     * @param array<string, string> $c
     * @param array<string, mixed> $tree
     * @param array<string, int> $seen
     * @param array<string, string> $images lower-case file name => stored name
     *
     * @return array<string, mixed>
     */
    private function resolveRow(array $c, int $rowNumber, array $tree, array &$seen, string $today, string $mode, array $images, bool $hasZip): array
    {
        $errors = [];
        $warnings = [];
        $notes = [];
        $get = static fn (string $k): string => $c[$k] ?? '';

        $sku = $get('sku');
        $barcode = $get('barcode');
        $existing = null;

        foreach (array_filter([['sku', $sku], ['barcode', $barcode]], static fn (array $p): bool => $p[1] !== '') as [$col, $val]) {
            $existing = $this->db->selectOne(
                "SELECT v.`uuid`, v.`sku`, v.`barcode`, v.`variant_name`, v.`mrp`, v.`selling_price`, v.`unit_label`,
                        p.`name` AS `product_name`, p.`gst_rate`, p.`category_id`
                   FROM `product_variants` v INNER JOIN `products` p ON p.`id` = v.`product_id`
                  WHERE v.`{$col}` = :val AND v.`is_deleted` = 0 LIMIT 1",
                ['val' => $val]
            );

            if ($existing !== null) {
                break;
            }
        }

        $row = [
            'row_number' => $rowNumber,
            'status' => $existing !== null ? 'existing' : 'new',
            'sku' => $sku,
            'barcode' => $barcode,
            'variant_uuid' => $existing['uuid'] ?? null,
            'brand' => $get('brand'),
            'hsn_code' => $get('hsn_code'),
            'batch_no' => $get('batch_no'),
            'publish' => $this->truthy($get('publish')),
            'image' => $get('image'),
            'image_ok' => false,
            'short_description' => mb_substr($get('short_description'), 0, 320),
        ];

        if ($row['image'] !== '') {
            $key = strtolower(basename(str_replace('\\', '/', $row['image'])));

            if (isset($images[$key])) {
                $row['image'] = $images[$key];
                $row['image_ok'] = true;
            } else {
                $warnings[] = $hasZip
                    ? "Image \"{$row['image']}\" was not found in the ZIP — the item will be created without a photo."
                    : "Image \"{$row['image']}\" was named, but images can only be uploaded inside a ZIP with the spreadsheet.";
            }
        }

        // ---- quantity / cost / discount / GST -------------------------------
        $qty = $this->num($get('quantity'));
        $cost = $this->num($get('unit_cost'));
        $disc = $get('discount_percent') === '' ? 0.0 : $this->num($get('discount_percent'));

        if ($mode === 'purchase' && ($qty === null || $qty <= 0)) {
            $errors[] = 'Quantity must be a number above 0.';
        }

        if ($mode === 'purchase' && ($cost === null || $cost <= 0)) {
            $errors[] = 'Unit cost must be a number above 0.';
        }

        if ($disc === null || $disc < 0 || $disc > 100) {
            $errors[] = 'Discount % must be between 0 and 100.';
            $disc = 0.0;
        }

        $qty = $qty ?? 0.0;
        $cost = $cost ?? 0.0;

        // ---- expiry -----------------------------------------------------------
        $expiry = null;

        if ($get('expiry_date') !== '') {
            $expiry = $this->date($get('expiry_date'));

            if ($expiry === null) {
                $errors[] = 'Expiry date is not a valid date (use YYYY-MM-DD).';
            } elseif ($expiry < $today) {
                $errors[] = 'Expiry date is in the past.';
            }
        }

        $row['expiry_date'] = $expiry ?? '';

        // ---- identity: existing item or new item ------------------------------
        $type = 'general';

        if ($existing !== null) {
            $row['product_name'] = $existing['product_name'];
            $row['variant_name'] = $existing['variant_name'];
            $row['sku'] = $existing['sku'];
            $row['barcode'] = $existing['barcode'] ?? '';
            $row['unit'] = $existing['unit_label'];
            $row['category_uuid'] = null;
            $row['category_label'] = '';
            $type = $this->typeOfCategoryId((int) $existing['category_id'], $tree);
            $gstDefault = (float) $existing['gst_rate'];
            $curMrp = (float) $existing['mrp'];
            $curSelling = (float) $existing['selling_price'];
        } else {
            $gstDefault = null;
            $curMrp = $curSelling = null;
            $resolved = $this->resolveCategory($get('category'), $get('sub_category'), $tree);

            if (isset($resolved['error'])) {
                $errors[] = $resolved['error'];
            }

            $row['category_uuid'] = $resolved['uuid'] ?? null;
            $row['category_label'] = $resolved['label'] ?? '';
            $type = $resolved['type'] ?? 'general';
            $row['product_name'] = $get('product_name');
            $row['brand'] = $get('brand');

            if ($row['product_name'] === '') {
                $errors[] = 'Product name is required for a new item.';
            }

            $unitKey = $this->unitKey($get('unit'));
            $loose = $this->truthy($get('loose'));
            $weightRequired = in_array($type, ['grocery', 'oils'], true);
            $pack = $get('variant_name');
            $size = $get('size');
            $colour = $get('colour');
            $weight = $this->num($get('weight'));
            $row['size'] = $size;
            $row['colour'] = $colour;

            if ($loose && $weightRequired) {
                $unitKey ??= $type === 'oils' ? 'litre' : 'kg';
                $row['unit'] = self::UNITS[$unitKey][0];
                $row['loose'] = true;
                $row['weight_grams'] = (int) self::UNITS[$unitKey][1];
                $row['variant_name'] = $pack !== '' ? $pack : 'Loose (per ' . ($unitKey === 'piece' ? 'piece' : $unitKey) . ')';
                $notes[] = "Loose item — priced and stocked per {$unitKey}.";
            } else {
                $row['loose'] = false;
                $row['unit'] = $unitKey !== null ? self::UNITS[$unitKey][0] : '';

                if (($weight === null || $weight <= 0) && $pack !== '') {
                    $parsed = $this->weightFromText($pack);

                    if ($parsed !== null) {
                        $weight = $parsed;
                        $notes[] = "Weight {$parsed} g read from pack size \"{$pack}\".";
                    }
                }

                if (($weight === null || $weight <= 0) && $weightRequired) {
                    $errors[] = 'Weight is required for grocery/oil items (enter Weight (g) or a pack size like "500 g").';
                }

                $row['weight_grams'] = $weight !== null && $weight > 0 ? (int) round($weight) : null;
                $variantName = $pack;

                if ($variantName === '' && ($size !== '' || $colour !== '')) {
                    $variantName = trim($size . ($size !== '' && $colour !== '' ? ' / ' : '') . $colour);
                }

                if ($variantName === '' && $row['weight_grams'] !== null && $weightRequired) {
                    $g = $row['weight_grams'];
                    $variantName = $g >= 1000 && $g % 1000 === 0 ? ($g / 1000) . ' kg' : $g . ' g';
                }

                $row['variant_name'] = $variantName !== '' ? $variantName : 'Standard';

                if (in_array($type, ['clothing', 'footwear'], true) && $size === '') {
                    $warnings[] = 'No size given for a ' . $type . ' item.';
                }
            }

            $row['pack_type'] = $get('pack_type');
        }

        $row['item_type'] = $type;

        $gst = $get('gst_rate') === '' ? null : $this->num($get('gst_rate'));

        if ($get('gst_rate') !== '' && ($gst === null || $gst < 0 || $gst > 28)) {
            $errors[] = 'GST % must be between 0 and 28.';
            $gst = null;
        }

        if ($gst === null) {
            $gst = $gstDefault ?? self::GST_BY_TYPE[$type];
            $notes[] = "GST {$gst}% assumed for " . ($existing !== null ? 'this item' : $type . ' items') . '.';
        }

        // ---- money math ---------------------------------------------------------
        $discountAmount = round($qty * $cost * $disc / 100, 2);
        $netUnitCost = $cost * (1 - $disc / 100);
        $lineTotal = round($qty * $cost - $discountAmount, 2);

        $mrp = $get('mrp') === '' ? null : $this->num($get('mrp'));
        $selling = $get('selling_price') === '' ? null : $this->num($get('selling_price'));
        $markup = $get('markup_percent') === '' ? null : $this->num($get('markup_percent'));

        if (($get('mrp') !== '' && $mrp === null) || ($get('selling_price') !== '' && $selling === null)) {
            $errors[] = 'MRP / Selling price must be numbers.';
        }

        if ($selling === null && $markup !== null && $netUnitCost > 0) {
            $selling = round($netUnitCost * (1 + $markup / 100));
            $notes[] = "Selling price ₹{$selling} = net cost ₹" . round($netUnitCost, 2) . " + {$markup}% markup (rounded to the rupee).";
        }

        if ($existing === null) {
            if ($selling === null && $mrp !== null) {
                $selling = $mrp;
                $notes[] = "Selling price set equal to MRP (₹{$mrp}).";
            }

            if ($mrp === null && $selling !== null) {
                $mrp = $selling;
                $notes[] = "MRP set equal to the selling price (₹{$selling}).";
            }

            if ($selling === null) {
                $errors[] = 'Give a Selling Price, an MRP, or a Markup % so the item can be priced.';
            }
        } else {
            // Existing item: blank means "keep the current price".
            if ($selling === null && $mrp === null) {
                $mrp = $curMrp;
                $selling = $curSelling;
                $notes[] = 'Keeps its current price.';
            } elseif ($selling === null) {
                $selling = min($curSelling, (float) $mrp);
            } elseif ($mrp === null) {
                $mrp = max($curMrp, (float) $selling);
            }
        }

        if ($selling !== null && $mrp !== null) {
            if ($selling > $mrp + 0.0001) {
                if ($get('selling_price') === '' && $markup !== null) {
                    $notes[] = "Computed selling price capped at the MRP (₹{$mrp}).";
                    $selling = $mrp;
                } else {
                    $errors[] = "Selling price ₹{$selling} is above the MRP ₹{$mrp}.";
                }
            }

            if ($netUnitCost > 0) {
                $margin = ($selling - $netUnitCost) / $selling * 100;

                if ($selling < $netUnitCost) {
                    $warnings[] = 'Selling price is below the cost — this item will be sold at a loss.';
                } elseif ($margin < 5) {
                    $warnings[] = 'Margin is under 5%.';
                }

                $row['margin_percent'] = round($margin, 1);
            }
        }

        if ($existing === null && $expiry === null && in_array($type, ['grocery', 'oils'], true)) {
            $warnings[] = 'No expiry date for a food item.';
        }

        // ---- no SKU and no barcode: recognise an item already on file by name -----
        // so importing the same sheet again can't create the products twice.
        if ($existing === null && $sku === '' && $barcode === '' && $row['product_name'] !== '') {
            $sameName = $this->db->selectOne(
                'SELECT v.`sku`, v.`variant_name`, p.`name` AS `product_name`
                   FROM `product_variants` v INNER JOIN `products` p ON p.`id` = v.`product_id`
                  WHERE v.`is_deleted` = 0 AND p.`is_deleted` = 0
                    AND LOWER(TRIM(p.`name`)) = LOWER(TRIM(:product_name))
                    AND LOWER(TRIM(v.`variant_name`)) = LOWER(TRIM(:variant_name))
                  LIMIT 1',
                ['product_name' => $row['product_name'], 'variant_name' => (string) ($row['variant_name'] ?? '')]
            );

            if ($sameName !== null) {
                $errors[] = sprintf(
                    'Duplicate: "%s — %s" is already in your products (%s). Add its SKU or barcode to this row to update it instead.',
                    $sameName['product_name'],
                    $sameName['variant_name'],
                    $sameName['sku'] !== null && $sameName['sku'] !== '' ? 'SKU ' . $sameName['sku'] : 'no SKU yet'
                );
            }
        }

        // ---- duplicate detection within the file --------------------------------
        $key = $existing !== null ? 'v:' . $existing['uuid']
            : 'n:' . ($row['category_uuid'] ?? '') . '|' . mb_strtolower($row['product_name']) . '|' . mb_strtolower($row['variant_name']);

        if (isset($seen[$key])) {
            $errors[] = "Duplicate of row {$seen[$key]} in this file.";
        } else {
            $seen[$key] = $rowNumber;
        }

        $row += [
            'quantity' => $qty,
            'unit_cost' => $cost,
            'discount_percent' => $disc,
            'discount_amount' => $discountAmount,
            'net_unit_cost' => round($netUnitCost, 4),
            'line_total' => $lineTotal,
            'gst_rate' => $gst,
            'gst_amount' => round($lineTotal * $gst / 100, 2),
            'mrp' => $mrp,
            'selling_price' => $selling,
            'errors' => $errors,
            'warnings' => $warnings,
            'notes' => $notes,
        ];

        $row['status'] = $errors !== [] ? 'error' : $row['status'];
        $row['kind'] = $existing !== null ? 'existing' : 'new';

        return $row;
    }

    // -----------------------------------------------------------------------
    // Category matching
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function categoryTree(): array
    {
        $rows = $this->db->select(
            'SELECT `id`, `uuid`, `name`, `slug`, `parent_id`, `item_type` FROM `categories`
              WHERE `is_deleted` = 0 AND `is_active` = 1 ORDER BY `display_order`, `name`'
        );
        $byId = [];

        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r + ['children' => []];
        }

        foreach ($byId as $id => $r) {
            if ($r['parent_id'] !== null && isset($byId[(int) $r['parent_id']])) {
                $byId[(int) $r['parent_id']]['children'][] = $id;
            }
        }

        return ['byId' => $byId];
    }

    /** @param array<string, mixed> $tree */
    private function typeOfCategoryId(int $id, array $tree): string
    {
        for ($d = 0; $id > 0 && $d < 12; ++$d) {
            $c = $tree['byId'][$id] ?? null;

            if ($c === null) {
                break;
            }

            if ($c['item_type'] !== null) {
                return (string) $c['item_type'];
            }

            $id = (int) ($c['parent_id'] ?? 0);
        }

        return 'general';
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array{uuid?: string, label?: string, type?: string, error?: string}
     */
    private function resolveCategory(string $category, string $sub, array $tree): array
    {
        if ($category === '' && $sub === '') {
            return ['error' => 'Category is required for a new item.'];
        }

        // "Clothing > Men" / "Clothing / Men" in a single cell.
        if ($sub === '' && preg_match('/^(.+?)\s*(?:>|›|\/|\|)\s*(.+)$/u', $category, $m)) {
            $category = trim($m[1]);
            $sub = trim($m[2]);
        }

        $norm = static fn (string $s): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace('&amp;', '&', $s))));
        $matches = static function (array $c, string $needle) use ($norm): bool {
            return $norm($c['name']) === $needle || $norm($c['slug']) === $needle;
        };

        $byId = $tree['byId'];
        $tops = array_filter($byId, static fn (array $c): bool => $c['parent_id'] === null);
        $top = null;

        if ($category !== '') {
            foreach ($tops as $c) {
                if ($matches($c, $norm($category))) {
                    $top = $c;
                    break;
                }
            }

            // A sub-category name typed in the Category column.
            if ($top === null && $sub === '') {
                $hits = array_filter($byId, static fn (array $c): bool => $c['parent_id'] !== null && $matches($c, $norm($category)));

                if (count($hits) === 1) {
                    $leaf = array_values($hits)[0];
                    $parent = $byId[(int) $leaf['parent_id']];

                    return ['uuid' => $leaf['uuid'], 'label' => $parent['name'] . ' › ' . $leaf['name'], 'type' => $this->typeOfCategoryId((int) $leaf['id'], $tree)];
                }
            }

            if ($top === null) {
                return ['error' => "Category \"{$category}\" was not found. Available: " . implode(', ', array_map(static fn (array $c): string => $c['name'], $tops)) . '.'];
            }
        }

        if ($top === null) {
            // Only a sub-category given.
            $hits = array_filter($byId, static fn (array $c): bool => $c['parent_id'] !== null && $matches($c, $norm($sub)));

            if (count($hits) !== 1) {
                return ['error' => "Sub-category \"{$sub}\" " . (count($hits) > 1 ? 'exists under several categories — add the Category column.' : 'was not found.')];
            }

            $leaf = array_values($hits)[0];
            $parent = $byId[(int) $leaf['parent_id']];

            return ['uuid' => $leaf['uuid'], 'label' => $parent['name'] . ' › ' . $leaf['name'], 'type' => $this->typeOfCategoryId((int) $leaf['id'], $tree)];
        }

        $children = array_map(static fn (int $id): array => $byId[$id], $top['children']);
        $type = $this->typeOfCategoryId((int) $top['id'], $tree);

        if ($sub === '') {
            if ($children === []) {
                return ['uuid' => $top['uuid'], 'label' => $top['name'], 'type' => $type];
            }

            return ['error' => "\"{$top['name']}\" has sub-categories — add a Sub Category: " . implode(', ', array_map(static fn (array $c): string => $c['name'], $children)) . '.'];
        }

        foreach ($children as $c) {
            if ($matches($c, $norm($sub))) {
                return ['uuid' => $c['uuid'], 'label' => $top['name'] . ' › ' . $c['name'], 'type' => $type];
            }
        }

        return ['error' => "Sub-category \"{$sub}\" was not found under {$top['name']}." . ($children !== [] ? ' Available: ' . implode(', ', array_map(static fn (array $c): string => $c['name'], $children)) . '.' : '')];
    }

    // -----------------------------------------------------------------------
    // Creating the new items (called after the user confirms the preview)
    // -----------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $items each is a createFromScan payload plus `publish`
     *
     * @return array<int, array<string, mixed>>
     */
    public function createItems(array $items, Request $request): array
    {
        if (count($items) > self::MAX_ROWS) {
            throw new HttpException('Too many items in one go.', 422);
        }

        $results = [];

        foreach ($items as $index => $item) {
            try {
                if (empty($item['category_uuid']) || empty($item['product_name'])) {
                    throw new HttpException('Category and product name are required.', 422);
                }

                if (!empty($item['publish']) && trim((string) ($item['short_description'] ?? '')) === '') {
                    $item['short_description'] = $this->autoDescription($item);
                }

                // Import: no SKU in the file means no SKU yet (see "Generate Barcode").
                $created = $this->imports->createFromScan($item, $request, true);
                $published = false;
                $publishNote = null;
                $imageAttached = false;
                $productUuid = (string) $this->db->scalar('SELECT `uuid` FROM `products` WHERE `id` = :id', ['id' => (int) $created['product_id']]);

                if (!empty($item['image']) && !empty($item['image_token'])) {
                    $path = $this->imagePath((string) $item['image_token'], (string) $item['image']);

                    if ($path === null) {
                        $publishNote = 'The image file was not found (the upload expired) — add the photo from Products.';
                    } else {
                        try {
                            $this->products->attachLocalImage($productUuid, $path, ['alt_text' => (string) $item['product_name']], $request);
                            $imageAttached = true;
                        } catch (HttpException $e) {
                            $publishNote = 'Photo not attached: ' . $e->getMessage();
                        }
                    }
                }

                if (!empty($item['publish'])) {
                    try {
                        $this->products->publish($productUuid, $request);
                        $published = true;
                        $publishNote = null;
                    } catch (HttpException $e) {
                        $publishNote = ($publishNote ? $publishNote . ' ' : '') . 'Saved as a draft — it can be published once it has a photo.';
                    }
                }

                $results[] = ['ok' => true, 'variants' => $created['variants'], 'published' => $published, 'image_attached' => $imageAttached, 'publish_note' => $publishNote];
            } catch (HttpException $e) {
                $results[] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Template + generated test data
    // -----------------------------------------------------------------------

    /** A listing needs a short description to be published; build a plain one when the file has none. */
    private function autoDescription(array $item): string
    {
        $packs = array_values(array_filter(array_map(static fn (array $v): string => (string) ($v['variant_name'] ?? ''), (array) ($item['variants'] ?? []))));
        $text = (string) $item['product_name'];

        if (!empty($item['brand'])) {
            $text .= ' by ' . $item['brand'];
        }

        if ($packs !== []) {
            $text .= '. Available in ' . implode(', ', array_slice($packs, 0, 6)) . '.';
        }

        return mb_substr($text, 0, 320);
    }

    /** @return array<int, string> */
    public function headers(): array
    {
        return ['SKU', 'Barcode', 'Category', 'Sub Category', 'Product Name', 'Brand', 'Pack Size', 'Size', 'Colour', 'Weight (g)',
            'Unit', 'Loose', 'Quantity', 'Unit Cost', 'Discount %', 'GST %', 'HSN', 'MRP', 'Selling Price', 'Markup %',
            'Batch No', 'Expiry Date', 'Publish Online', 'Image', 'Short Description'];
    }

    /** @return array<int, array<int, mixed>> */
    public function templateRows(): array
    {
        $exp = date('Y-m-d', strtotime('+12 months'));

        return [
            ['', '', 'Spices', 'Whole Spices', 'Black Pepper', 'Sample Farms', '250 g', '', '', '', '', '', 20, 180, 5, 5, '', 320, '', 30, 'BP-01', $exp, 'yes', 'black-pepper.jpg'],
            ['', '', 'Grocery & Staples', 'Rice & Grains', 'Sona Masoori Rice', '', '', '', '', '', 'kg', 'yes', 50, 52, '', 5, '', '', '', 20, 'RC-01', $exp, ''],
            ['', '', 'Oils', 'Cooking Oils', 'Groundnut Oil', 'Sample Oils', '1 L', '', '', '', '', '', 12, 170, 2, 5, '', 210, 199, '', 'OIL-01', $exp, ''],
            ['', '', 'Clothing', 'Men', 'Cotton T-Shirt', 'Sample Wear', '', 'M', 'Blue', '', '', '', 10, 180, '', 12, '', 499, '', 60, '', '', ''],
            ['', '', 'Clothing', 'Men', 'Cotton T-Shirt', 'Sample Wear', '', 'L', 'Blue', '', '', '', 10, 180, '', 12, '', 499, '', 60, '', '', ''],
            ['', '', 'Footwear', 'Men', 'Canvas Sneakers', '', '', '8', 'Black', '', '', '', 6, 450, 5, 12, '', 999, 849, '', '', '', ''],
            ['', '', 'Toys', 'Soft Toys', 'Teddy Bear 30cm', '', 'Medium', '', 'Brown', '', '', '', 8, 220, '', 12, '', 499, '', 50, '', '', ''],
            ['', '', 'Stationery', 'Pens & Pencils', 'Gel Pen Blue', 'Sample', 'Pack of 10', '', '', '', '', '', 30, 55, '', 12, '', 100, '', 40, '', '', ''],
        ];
    }

    /**
     * Realistic, varied, re-uploadable test data: unique SKUs each time,
     * every business type, some rows leaving prices to be calculated, and
     * (optionally) a few deliberately broken rows to try the fix-on-screen flow.
     *
     * @return array<int, array<int, mixed>>
     */
    public function sampleRows(int $target, bool $withErrors): array
    {
        $target = max(5, min($target, self::MAX_ROWS));
        $tag = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        $seq = 0;
        $exp = static fn (int $m): string => date('Y-m-d', strtotime("+{$m} months"));
        $rows = [];
        $names = $this->sampleNameCatalog();

        $sku = function (string $p) use ($tag, &$seq): string {
            ++$seq;

            return "TST-{$tag}-{$p}" . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        };

        $pick = static fn (array $a): mixed => $a[array_rand($a)];
        $i = 0;

        while (count($rows) < $target - ($withErrors ? 3 : 0)) {
            $kind = ['packed', 'loose', 'oil', 'cloth', 'shoe', 'toy', 'stat'][$i % 7];
            ++$i;
            [$cat, $sub, $prod] = $pick($names[$kind]);

            if ($kind === 'packed' || $kind === 'oil') {
                $packs = $kind === 'oil' ? [['500 ml', 0.5], ['1 L', 1.0], ['5 L', 5.0]] : [['100 g', 0.1], ['250 g', 0.25], ['500 g', 0.5], ['1 kg', 1.0]];
                $perKg = $kind === 'oil' ? mt_rand(110, 220) : mt_rand(200, 1400);

                foreach ($packs as [$label, $kg]) {
                    $cost = round($perKg * $kg, 2);
                    $useMarkup = mt_rand(0, 1) === 1;
                    $rows[] = [$sku('P'), '', $cat, $sub, $prod, $pick(['Sample Farms', 'Golden Harvest', 'Spice Route']), $label, '', '', '', '', '',
                        mt_rand(10, 60), $cost, $pick(['', 2, 5]), 5, '', $useMarkup ? '' : round($cost * 1.6), $useMarkup ? '' : round($cost * 1.4), $useMarkup ? 35 : '',
                        "B{$tag}" . mt_rand(10, 99), $exp(mt_rand(6, 18)), mt_rand(0, 1) ? 'yes' : ''];
                }
            } elseif ($kind === 'loose') {
                $rows[] = ['', '', $cat, $sub, $prod, '', '', '', '', '', $cat === 'Oils' ? 'litre' : 'kg', 'yes', mt_rand(20, 90) + 0.5, mt_rand(45, 140), '', 5, '', '', '', 20,
                    "L{$tag}" . mt_rand(10, 99), $exp(mt_rand(3, 10)), ''];
            } elseif ($kind === 'cloth' || $kind === 'shoe') {
                $sizes = $kind === 'cloth' ? ['S', 'M', 'L', 'XL'] : ['7', '8', '9', '10'];
                $cost = $kind === 'cloth' ? mt_rand(150, 400) : mt_rand(350, 900);

                foreach (array_slice($sizes, 0, mt_rand(2, 4)) as $sz) {
                    foreach ([$pick(['Black', 'Navy']), 'White'] as $col) {
                        $rows[] = ['', '', $cat, $sub, $prod, 'Sample Wear', '', $sz, $col, '', '', '', mt_rand(3, 15), $cost, '', 12, '', round($cost * 2.4), '', 55, '', '', ''];
                    }
                }
            } elseif ($kind === 'toy') {
                $cost = mt_rand(120, 600);
                $rows[] = [$sku('T'), '', $cat, $sub, $prod, 'FunTime', 'Standard', '', '', '', '', '', mt_rand(4, 20), $cost, '', 12, '', round($cost * 2.2), '', 45, '', '', ''];
            } else {
                $cost = mt_rand(20, 200);
                $rows[] = [$sku('S'), '', $cat, $sub, $prod, 'WriteWell', 'Pack of ' . $pick([5, 10, 12]), '', '', '', '', '', mt_rand(10, 80), $cost, $pick(['', 3, 5]), 12, '', round($cost * 1.9), '', 40, '', '', ''];
            }
        }

        $rows = array_slice($rows, 0, $target - ($withErrors ? 3 : 0));

        if ($withErrors) {
            $rows[] = [$sku('E'), '', 'Furniture', '', 'Wooden Stool', '', '', '', '', '', '', '', 5, 800, '', 18, '', 1500, '', '', '', '', ''];
            $rows[] = [$sku('E'), '', 'Toys', 'Soft Toys', 'Overpriced Bunny', '', 'Small', '', 'White', '', '', '', 5, 200, '', 12, '', 300, 450, '', '', '', ''];
            $rows[] = [$sku('E'), '', 'Stationery', 'Pens & Pencils', 'Pencil Box', '', '', '', '', '', '', '', '', 60, '', 12, '', 120, '', '', '', '', ''];
        }

        return $rows;
    }

    /** @return array<string, array<int, array{0: string, 1: string, 2: string}>> */
    private function sampleNameCatalog(): array
    {
        $all = [
            'packed' => [['Spices', 'Whole Spices', 'Cumin Seeds'], ['Spices', 'Ground Spices', 'Turmeric Powder'], ['Spices', 'Ground Spices', 'Red Chilli Powder'],
                ['Dry Fruits', 'Almonds', 'California Almonds'], ['Dry Fruits', 'Cashews', 'Cashew W320'], ['Spices', 'Whole Spices', 'Green Cardamom']],
            'loose' => [['Grocery & Staples', 'Rice & Grains', 'Basmati Rice'], ['Grocery & Staples', 'Pulses & Dals', 'Toor Dal'], ['Grocery & Staples', 'Flours & Atta', 'Wheat Atta'],
                ['Grocery & Staples', 'Sugar, Salt & Jaggery', 'Jaggery'], ['Oils', 'Cold-pressed Oils', 'Sesame Oil']],
            'oil' => [['Oils', 'Cooking Oils', 'Sunflower Oil'], ['Oils', 'Cooking Oils', 'Groundnut Oil'], ['Oils', 'Hair Oils', 'Coconut Hair Oil']],
            'cloth' => [['Clothing', 'Men', 'Cotton T-Shirt'], ['Clothing', 'Women', 'Cotton Kurti'], ['Clothing', 'Kids', 'Kids Shorts']],
            'shoe' => [['Footwear', 'Men', 'Canvas Sneakers'], ['Footwear', 'Sandals & Slippers', 'Flip Flops'], ['Footwear', 'Sports Shoes', 'Running Shoes']],
            'toy' => [['Toys', 'Soft Toys', 'Teddy Bear'], ['Toys', 'Educational Toys', 'Alphabet Blocks'], ['Toys', 'Games & Puzzles', 'Jigsaw Puzzle 100pc']],
            'stat' => [['Stationery', 'Pens & Pencils', 'Gel Pen'], ['Stationery', 'Notebooks & Paper', 'Ruled Notebook A5'], ['Stationery', 'School Supplies', 'Geometry Box']],
        ];

        $have = [];

        foreach ($this->db->select('SELECT `name` FROM `categories` WHERE `is_deleted` = 0') as $r) {
            $have[mb_strtolower($r['name'])] = true;
        }

        foreach ($all as $k => $list) {
            $list = array_values(array_filter($list, static fn (array $x): bool => isset($have[mb_strtolower($x[0])]) && isset($have[mb_strtolower($x[1])])));
            $all[$k] = $list !== [] ? $list : [$all[$k][0]];
        }

        return $all;
    }

    /** @param array<int, array<int, mixed>> $rows */
    public function toCsv(array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fputcsv($h, $this->headers());

        $width = count($this->headers());

        foreach ($rows as $r) {
            fputcsv($h, array_pad($r, $width, ''));
        }

        rewind($h);
        $out = "\xEF\xBB\xBF" . stream_get_contents($h);
        fclose($h);

        return $out;
    }

    // -----------------------------------------------------------------------
    // Small parsing helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<int, string> $header
     *
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $map = [];

        foreach ($header as $col => $text) {
            $norm = preg_replace('/[^a-z0-9]/', '', mb_strtolower(str_replace(['%', '₹'], '', (string) $text)));

            foreach (self::ALIASES as $field => $aliases) {
                if (!isset($map[$field]) && in_array($norm, $aliases, true)) {
                    $map[$field] = $col;
                    break;
                }
            }
        }

        return $map;
    }

    private function num(string $s): ?float
    {
        $s = trim(str_replace(['₹', ',', '%', ' '], '', $s));

        return $s !== '' && is_numeric($s) ? (float) $s : null;
    }

    private function truthy(string $s): bool
    {
        return in_array(mb_strtolower(trim($s)), ['y', 'yes', 'true', '1', 'x', 'loose', 'online'], true);
    }

    private function unitKey(string $s): ?string
    {
        return match (mb_strtolower(trim($s))) {
            'kg', 'kgs', 'kilo', 'kilogram' => 'kg',
            'g', 'gm', 'gms', 'gram', 'grams' => 'g',
            'l', 'ltr', 'litre', 'liter', 'litres', 'liters' => 'litre',
            'ml', 'millilitre' => 'ml',
            'pc', 'pcs', 'piece', 'pieces', 'nos', 'each' => 'piece',
            'dozen', 'doz' => 'dozen',
            default => null,
        };
    }

    private function weightFromText(string $text): ?float
    {
        if (!preg_match('/(\d+(?:\.\d+)?)\s*(kgs?|kg|grams?|gms?|gm|g|ltrs?|litres?|liters?|l|ml)\b/i', $text, $m)) {
            return null;
        }

        $n = (float) $m[1];

        return match (strtolower($m[2])) {
            'kg', 'kgs', 'l', 'ltr', 'ltrs', 'litre', 'litres', 'liter', 'liters' => $n * 1000,
            default => $n,
        };
    }

    private function date(string $s): ?string
    {
        $s = trim($s);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j-M-Y', 'd M Y', 'm/d/Y'] as $format) {
            $d = \DateTime::createFromFormat('!' . $format, $s);

            if ($d !== false && $d->format($format) === $s) {
                return $d->format('Y-m-d');
            }
        }

        $t = strtotime($s);

        return $t !== false ? date('Y-m-d', $t) : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function summarise(array $rows): array
    {
        $s = ['total' => count($rows), 'existing' => 0, 'new' => 0, 'errors' => 0, 'warnings' => 0,
            'discount' => 0.0, 'taxable' => 0.0, 'gst' => 0.0, 'grand_total' => 0.0];

        foreach ($rows as $r) {
            if ($r['status'] === 'error') {
                ++$s['errors'];
                continue;
            }

            ++$s[$r['kind']];
            $s['warnings'] += $r['warnings'] !== [] ? 1 : 0;
            $s['discount'] += $r['discount_amount'];
            $s['taxable'] += $r['line_total'];
            $s['gst'] += $r['gst_amount'];
        }

        $s['grand_total'] = round($s['taxable'] + $s['gst'], 2);

        foreach (['discount', 'taxable', 'gst'] as $k) {
            $s[$k] = round($s[$k], 2);
        }

        return $s;
    }
}
