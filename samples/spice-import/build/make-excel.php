<?php
/**
 * Builds spice-items.xlsx / spice-items.csv (55 rows, 20 spice products) and
 * products.json (used to draw the product images).
 *
 *   php make-excel.php
 */

require 'C:/xampp/htdocs/5star/spice-api/backend/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

mt_srand(2609);

// code, name, sub-category, kind, hsn, cost per kg, packs (grams), discount %, expiry months, colours
$products = [
    ['BPEP', 'Black Pepper',          'Whole Spices',  'whole',  '0904',  720, [50, 100, 250, 500],  2, 24, ['#1c1c1c', '#3a2f2a', '#0f0f0f']],
    ['CARD', 'Green Cardamom 8mm',    'Whole Spices',  'whole',  '0908', 2600, [50, 100, 250],       0, 24, ['#5c8a3a', '#7aa84a', '#486b2d']],
    ['CLOV', 'Cloves',                'Whole Spices',  'whole',  '0907', 1100, [50, 100, 250],       5, 24, ['#5a2d12', '#7a3f1a', '#43200c']],
    ['CINN', 'Cinnamon Sticks',       'Whole Spices',  'stick',  '0906',  480, [100, 250, 500],      0, 24, ['#a0622d', '#b87a3f', '#85501f']],
    ['CUMS', 'Cumin Seeds',           'Whole Spices',  'whole',  '0909',  380, [100, 250, 500],      2, 18, ['#8a7350', '#a08a63', '#6f5c3d']],
    ['CORS', 'Coriander Seeds',       'Whole Spices',  'whole',  '0909',  180, [100, 250, 500],      0, 18, ['#b39a52', '#c8b068', '#96803f']],
    ['STAR', 'Star Anise',            'Whole Spices',  'star',   '0909', 1300, [50, 100],            5, 24, ['#6b3a1e', '#8a4d2a', '#54290f']],
    ['BAYL', 'Bay Leaf',              'Whole Spices',  'leaf',   '0910',  260, [50, 100],            0, 18, ['#7d8a3c', '#95a34c', '#65712d']],
    ['FENN', 'Fennel Seeds',          'Whole Spices',  'whole',  '0909',  340, [100, 250],           2, 18, ['#9aa060', '#b1b874', '#7f8549']],
    ['MUST', 'Mustard Seeds',         'Whole Spices',  'whole',  '1207',  140, [100, 250, 500],      0, 24, ['#3d2b1f', '#54392a', '#2a1b12']],
    ['TURM', 'Turmeric Powder',       'Ground Spices', 'powder', '0910',  220, [100, 250, 500],      2, 12, ['#e0a71a', '#f0b92e', '#c48f0e']],
    ['REDC', 'Red Chilli Powder',     'Ground Spices', 'powder', '0904',  320, [100, 250, 500],      0, 12, ['#b3251b', '#cc3324', '#8f1a12']],
    ['KASH', 'Kashmiri Chilli Powder', 'Ground Spices', 'powder', '0904',  620, [100, 250, 500],      5, 12, ['#c4180f', '#dc2a1c', '#a11009']],
    ['CORP', 'Coriander Powder',      'Ground Spices', 'powder', '0909',  200, [100, 250, 500],      0, 12, ['#a89a4a', '#bcae5c', '#8d8038']],
    ['CUMP', 'Cumin Powder',          'Ground Spices', 'powder', '0909',  420, [100, 250, 500],      2, 12, ['#9c7d4c', '#b2925d', '#816439']],
    ['BPEP2', 'Black Pepper Powder',  'Ground Spices', 'powder', '0904',  760, [50, 100, 250],       0, 12, ['#2b2b2b', '#454545', '#1a1a1a']],
    ['GING', 'Dry Ginger Powder',     'Ground Spices', 'powder', '0910',  480, [100, 250],           5, 12, ['#d2b16a', '#e0c07c', '#b8974f']],
    ['GARM', 'Garam Masala',          'Spice Blends',  'blend',  '0910',  900, [50, 100, 250],       2, 12, ['#7a4a22', '#96602f', '#5e3717']],
    ['BIRY', 'Biryani Masala',        'Spice Blends',  'blend',  '0910',  850, [50, 100],            0, 12, ['#a8552a', '#c26a3a', '#8a4120']],
    ['CHAT', 'Chaat Masala',          'Spice Blends',  'blend',  '0910',  600, [50, 100],            0, 12, ['#b58a4a', '#cc9f5c', '#986f38']],
];

$desc = [
    'BPEP' => 'Bold, aromatic whole black peppercorns with a sharp, warm bite. Freshly packed.',
    'CARD' => 'Plump 8mm green cardamom pods with a sweet, intense fragrance for chai and desserts.',
    'CLOV' => 'Whole hand-picked cloves with a strong, warming aroma. Ideal for biryani and masala chai.',
    'CINN' => 'True cinnamon quills, naturally sweet and fragrant. For curries, tea and baking.',
    'CUMS' => 'Clean, aromatic whole cumin seeds for tempering dals, rice and curries.',
    'CORS' => 'Whole coriander seeds with a citrusy, nutty flavour. Great for grinding fresh.',
    'STAR' => 'Whole star anise with a sweet liquorice note. A must for biryani and garam masala.',
    'BAYL' => 'Dried whole bay leaves that add a subtle, herbal depth to rice and slow-cooked dishes.',
    'FENN' => 'Sweet, cooling fennel seeds — perfect as a mouth freshener or in curries and pickles.',
    'MUST' => 'Small dark mustard seeds that pop in hot oil for a nutty, pungent tadka.',
    'TURM' => 'Deep golden turmeric powder, stone-ground for colour and earthy flavour.',
    'REDC' => 'Bright red chilli powder with balanced heat and rich colour for everyday cooking.',
    'KASH' => 'Vibrant Kashmiri chilli powder — mild heat and a brilliant red colour for gravies.',
    'CORP' => 'Fine, fragrant coriander powder made from carefully cleaned seeds.',
    'CUMP' => 'Freshly ground cumin powder with a warm, earthy aroma for curries and raitas.',
    'BPEP2' => 'Finely ground black pepper with a sharp, lingering heat. Grinds fresh on request.',
    'GING' => 'Dry ginger (saunth) powder with a warming, spicy flavour for tea and kadha.',
    'GARM' => 'A balanced blend of roasted whole spices for a rich, aromatic finish to curries.',
    'BIRY' => 'Layered spice blend crafted for fragrant, restaurant-style biryani at home.',
    'CHAT' => 'Tangy, zesty chaat masala for fruit, salads, snacks and street-food classics.',
];

$premium = [50 => 1.15, 100 => 1.08, 250 => 1.0, 500 => 0.96, 1000 => 0.93];
$round5 = static fn (float $v): float => ceil($v / 5) * 5;
$label = static fn (int $g): string => $g >= 1000 ? ($g / 1000) . ' kg' : $g . ' g';

$headers = ['SKU', 'Barcode', 'Category', 'Sub Category', 'Product Name', 'Brand', 'Pack Size', 'Size', 'Colour', 'Weight (g)',
    'Unit', 'Loose', 'Quantity', 'Unit Cost', 'Discount %', 'GST %', 'HSN', 'MRP', 'Selling Price', 'Markup %',
    'Batch No', 'Expiry Date', 'Publish Online', 'Image', 'Short Description'];

$rows = [];
$meta = [];
$n = 0;

foreach ($products as [$code, $name, $sub, $kind, $hsn, $perKg, $packs, $disc, $months, $colors]) {
    $image = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '.jpg';
    $meta[] = ['file' => $image, 'name' => $name, 'kind' => $kind, 'colors' => $colors, 'sub' => $sub];
    $expiry = date('Y-m-d', strtotime("last day of +{$months} months", strtotime('2026-09-21')));

    foreach ($packs as $g) {
        ++$n;
        $cost = round($perKg * ($g / 1000) * $premium[$g], 2);
        $mrp = $round5($cost * 1.7);
        $useMarkup = ($n % 3 === 0);
        $rows[] = [
            'SP-' . $code . '-' . $g, '', 'Spices', $sub, $name, '5Star Spices', $label($g), '', '', $g,
            '', '', mt_rand(12, 60), $cost, $disc ?: '', 5, $hsn, $mrp,
            $useMarkup ? '' : $round5($cost * 1.45), $useMarkup ? 40 : '',
            'B' . $code . '-0926', $expiry, 'yes', $image, $desc[$code],
        ];
    }
}

// ---- xlsx -------------------------------------------------------------------------
$book = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle('Spice items');
$sheet->fromArray($headers, null, 'A1');

foreach ($rows as $r => $row) {
    foreach ($row as $c => $value) {
        $cell = $sheet->getCell([$c + 1, $r + 2]);

        if ($value === '' || $value === null) {
            continue;
        }

        // Text for SKU/HSN/batch/expiry/etc. so Excel never reformats them.
        if (in_array($headers[$c], ['SKU', 'HSN', 'Batch No', 'Expiry Date', 'Pack Size', 'Image', 'Barcode', 'Short Description'], true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
        } else {
            $cell->setValue($value);
        }
    }
}

$sheet->getStyle('A1:Y1')->getFont()->setBold(true);
$sheet->getStyle('A1:Y1')->getFill()->setFillType('solid')->getStartColor()->setRGB('E8F1EC');
$sheet->freezePane('A2');

foreach (range('A', 'Y') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

(new Xlsx($book))->save(__DIR__ . '/../spice-items.xlsx');

// ---- csv --------------------------------------------------------------------------------
$h = fopen(__DIR__ . '/../spice-items.csv', 'w');
fwrite($h, "\xEF\xBB\xBF");
fputcsv($h, $headers);

foreach ($rows as $row) {
    fputcsv($h, $row);
}

fclose($h);
file_put_contents(__DIR__ . '/products.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo count($rows) . " rows, " . count($meta) . " products\n";
