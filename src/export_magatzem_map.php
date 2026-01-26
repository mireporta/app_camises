<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$stmt = $pdo->query("
    SELECT 
      mp.codi AS posicio,
      iu.serial,
      i.sku
      FROM magatzem_posicions mp
      LEFT JOIN item_units iu
        ON iu.id = mp.item_unit_id
      LEFT JOIN items i
        ON i.id = iu.item_id
        FROM magatzem_posicions mp
...
WHERE mp.magatzem_code='MAG01'
ORDER BY mp.codi

    ORDER BY mp.codi ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('ubicacions');

// Capçalera
$sheet->fromArray(['posicio', 'serial', 'sku'], null, 'A1');
$sheet->getStyle('A1:C1')->getFont()->setBold(true);

// Dades
$r = 2;
foreach ($rows as $row) {
    $sheet->setCellValue("A{$r}", $row['posicio']);
    $sheet->setCellValue("B{$r}", $row['serial'] ?? '');
    $sheet->setCellValue("C{$r}", $row['sku'] ?? '');
    $r++;
}

// Amplades
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(22);
$sheet->getColumnDimension('C')->setWidth(16);

$filename = 'magatzem_ubicacions_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
