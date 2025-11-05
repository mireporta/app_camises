<?php
require_once("config.php");

header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=baixes.xlsx");

require_once("../vendor/autoload.php");
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Baixes');

// Capçaleres
$sheet->fromArray(['Data', 'SKU', 'Serial', 'Motiu', 'Màquina origen'], NULL, 'A1');

// Dades
$query = "
  SELECT iu.updated_at, i.sku, iu.serial, iu.baixa_motiu, iu.maquina_actual
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  WHERE iu.estat='inactiu'
  ORDER BY iu.updated_at DESC
";
$data = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
$rowNum = 2;
foreach ($data as $r) {
    $sheet->fromArray([
        date('d/m/Y H:i', strtotime($r['updated_at'])),
        $r['sku'],
        $r['serial'],
        str_replace('_', ' ', $r['baixa_motiu']),
        $r['maquina_actual'] ?? ''
    ], NULL, "A$rowNum");
    $rowNum++;
}

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
