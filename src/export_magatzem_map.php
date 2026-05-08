<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$magatzem = $_GET['magatzem'] ?? 'MAG01';

$stmt = $pdo->prepare("
    SELECT
        mp.codi AS posicio,
        mp.magatzem_code,
        iu.serial,
        i.sku
    FROM magatzem_posicions mp
    LEFT JOIN item_units iu
        ON iu.id = mp.item_unit_id
    LEFT JOIN items i
        ON i.id = iu.item_id
    WHERE mp.magatzem_code = ?
    ORDER BY mp.codi ASC
");

$stmt->execute([$magatzem]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('ubicacions');

$sheet->fromArray(
    ['magatzem', 'posicio', 'serial', 'sku'],
    null,
    'A1'
);

$sheet->getStyle('A1:E1')->getFont()->setBold(true);

$r = 2;

foreach ($rows as $row) {
    $sheet->setCellValue("A{$r}", $row['magatzem_code']);
    $sheet->setCellValue("B{$r}", $row['posicio']);
    $sheet->setCellValue("C{$r}", $row['serial'] ?? '');
    $sheet->setCellValue("D{$r}", $row['sku'] ?? '');
    $r++;
}

foreach (range('A', 'D') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$sheet->setAutoFilter('A1:D1');

$filename = 'magatzem_ubicacions_' . $magatzem . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;