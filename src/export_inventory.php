<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();

/* ==========================================================
   📘 PESTANYA ITEMS (SKU)
========================================================== */
$sheetItems = $spreadsheet->getActiveSheet();
$sheetItems->setTitle('items');

$sheetItems->fromArray([
    ['sku', 'category', 'min_stock', 'active', 'plan_file']
], null, 'A1');

$stmt = $pdo->query("
    SELECT sku, category, min_stock, active, plan_file
    FROM items
    ORDER BY sku ASC
");

$row = 2;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
    $sheetItems->fromArray([
        $it['sku'],
        $it['category'],
        $it['min_stock'],
        $it['active'],
        $it['plan_file'],
    ], null, "A{$row}");
    $row++;
}

/* ==========================================================
   📗 PESTANYA UNITATS (SERIAL)
========================================================== */
$sheetUnitats = $spreadsheet->createSheet();
$sheetUnitats->setTitle('unitats');

$sheetUnitats->fromArray([
    [
        'sku',
        'serial',
        'ubicacio',
        'sububicacio',
        'maquina_actual',
        'vida_total',
        'vida_utilitzada',
        'cicles_maquina',
        'estat'
    ]
], null, 'A1');

$stmt = $pdo->query("
    SELECT
        i.sku,
        iu.serial,
        iu.ubicacio,
        iu.sububicacio,
        iu.maquina_actual,
        iu.vida_total,
        iu.vida_utilitzada,
        iu.cicles_maquina,
        iu.estat
    FROM item_units iu
    JOIN items i ON i.id = iu.item_id
    ORDER BY i.sku ASC, iu.serial ASC
");

$row = 2;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $sheetUnitats->fromArray([
        $u['sku'],
        $u['serial'],
        $u['ubicacio'],
        $u['sububicacio'],
        $u['maquina_actual'],
        $u['vida_total'],
        $u['vida_utilitzada'],
        $u['cicles_maquina'],
        $u['estat'],
    ], null, "A{$row}");
    $row++;
}

/* ==========================================================
   📤 DESCÀRREGA
========================================================== */
$filename = 'inventari_complet_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
