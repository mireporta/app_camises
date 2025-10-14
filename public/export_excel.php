<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../vendor/autoload.php'; // necessita Composer i phpoffice/phpspreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$items = find_all_items($pdo);
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray(['SKU','Nom','Categoria','Ubicació','Stock','Estoc mínim','Vida útil','Actiu'], NULL, 'A1');

$row = 2;
foreach ($items as $it) {
    $sheet->fromArray([
        $it['sku'], $it['name'], $it['category'], $it['location'],
        $it['stock'], $it['min_stock'], $it['life_expectancy'], $it['active']
    ], NULL, "A$row");
    $row++;
}

$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="inventari.xlsx"');
$writer->save('php://output');
exit;
?>
