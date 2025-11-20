<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php'; // Composer: phpoffice/phpspreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

try {
    // Ara aquesta funció ha de retornar dades agregades segons el nou model
    $items = find_all_items($pdo);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Capçaleres adaptades al nou esquema
    $headers = [
        'SKU',
        'Categoria',
        'Estoc total',
        'MAG',
        'INT',
        'MAQ',
        'Estoc mínim',
        'Actiu',
    ];
    $sheet->fromArray($headers, null, 'A1');

    // Files
    $r = 2;
    foreach ($items as $row) {
        // Columna A: sempre SKU com a text
        $sheet->setCellValueExplicit("A{$r}", (string)($row['sku'] ?? ''), DataType::TYPE_STRING);

        // De la B en endavant
        $sheet->fromArray([
            $row['category']       ?? '',
            (int)($row['total_stock']   ?? 0),
            (int)($row['qty_magatzem']  ?? 0),
            (int)($row['qty_intermig']  ?? 0),
            (int)($row['qty_maquina']   ?? 0),
            (int)($row['min_stock']     ?? 0),
            (int)($row['active']        ?? 0),
        ], null, "B{$r}");

        $r++;
    }

    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="inventari.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error exportant l'inventari: " . $e->getMessage();
    exit;
}
