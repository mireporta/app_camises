<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 🔍 Paràmetres de filtre
$tipus = $_GET['tipus'] ?? '';
$search = trim($_GET['search'] ?? '');
$date_from = $_GET['from'] ?? '';
$date_to = $_GET['to'] ?? '';

$where = ['1=1'];
$params = [];

if ($tipus !== '' && $tipus !== 'tots') {
    $where[] = "m.tipus = ?";
    $params[] = $tipus;
}
if ($search !== '') {
    $where[] = "(i.sku LIKE ? OR iu.serial LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($date_from !== '') {
    $where[] = "DATE(m.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = "DATE(m.created_at) <= ?";
    $params[] = $date_to;
}

$where_sql = implode(' AND ', $where);

// 📋 Consulta corregida segons l'estructura actual
$stmt = $pdo->prepare("
    SELECT 
        m.created_at,
        m.tipus,
        i.sku,
        iu.serial,
        m.ubicacio AS origen,
        m.maquina AS desti
    FROM moviments m
    LEFT JOIN item_units iu ON m.item_unit_id = iu.id
    LEFT JOIN items i ON iu.item_id = i.id
    WHERE $where_sql
    ORDER BY m.created_at DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


// 🧾 Crear document Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Moviments');

// Capçaleres
$headers = ['Data / Hora', 'Tipus', 'SKU', 'Serial', 'Origen', 'Destí / Màquina'];
$sheet->fromArray($headers, null, 'A1');

// Dades
$rowNum = 2;
foreach ($rows as $r) {
    $sheet->setCellValue("A$rowNum", date('d/m/Y H:i', strtotime($r['created_at'])));
    $sheet->setCellValue("B$rowNum", ucfirst($r['tipus']));
    $sheet->setCellValue("C$rowNum", $r['sku']);
    $sheet->setCellValue("D$rowNum", $r['serial']);
    $sheet->setCellValue("E$rowNum", $r['origen']);
    $sheet->setCellValue("F$rowNum", $r['desti']);
    $rowNum++;
}

// Format estètic
$sheet->getStyle('A1:F1')->getFont()->setBold(true);
foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// 🟢 Sortida
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="moviments.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
