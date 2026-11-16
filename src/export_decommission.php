<?php
declare(strict_types=1);

// Capture any unexpected output from configuration or dependencies.
ob_start();

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/../vendor/autoload.php';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Baixes');

    $sheet->fromArray(
        ['Data', 'SKU', 'Serial', 'Motiu', 'Màquina origen', 'Vida útil'],
        null,
        'A1'
    );

    $query = "
        SELECT iu.updated_at, i.sku, iu.serial, iu.baixa_motiu,
               iu.maquina_baixa, iu.vida_utilitzada
        FROM item_units iu
        JOIN items i ON i.id = iu.item_id
        WHERE iu.estat = 'inactiu'
        ORDER BY iu.updated_at DESC
    ";

    $data = $pdo->query($query)->fetchAll(\PDO::FETCH_ASSOC);
    $rowNum = 2;
    foreach ($data as $r) {
        $sheet->fromArray([
            $r['updated_at'] ? date('d/m/Y H:i', strtotime($r['updated_at'])) : '',
            $r['sku'],
            $r['serial'],
            str_replace('_', ' ', (string)($r['baixa_motiu'] ?? '')),
            $r['maquina_baixa'] ?? '',
            (int)($r['vida_utilitzada'] ?? 0),
        ], null, "A{$rowNum}");
        $rowNum++;
    }

    // Prevent warnings, whitespace or a UTF-8 BOM from corrupting XLSX output.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="baixes.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} catch (\Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('Error exportant baixes: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No s\'ha pogut generar l\'Excel de baixes. Consulteu el registre d\'errors del servidor.';
    exit;
}
