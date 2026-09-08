<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {

    // =========================================================
    // 1. MAGATZEM A EXPORTAR
    // =========================================================

    $magatzem = $_GET['magatzem'] ?? 'MAG01';


    // =========================================================
    // 2. OBTENIR LES POSICIONS DEL MAGATZEM
    // =========================================================

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


    // =========================================================
    // 3. CREAR L'EXCEL
    // =========================================================

    $spreadsheet = new Spreadsheet();

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('ubicacions');


    // =========================================================
    // 4. CAPÇALERES
    // =========================================================

    $sheet->fromArray(
        [
            'magatzem',
            'posicio',
            'serial',
            'sku'
        ],
        null,
        'A1'
    );

    // Només tenim 4 columnes: A-D
    $sheet->getStyle('A1:D1')
        ->getFont()
        ->setBold(true);


    // =========================================================
    // 5. OMPLIR LES DADES
    // =========================================================

    $r = 2;

    foreach ($rows as $row) {

        $sheet->setCellValue(
            "A{$r}",
            $row['magatzem_code'] ?? ''
        );

        $sheet->setCellValue(
            "B{$r}",
            $row['posicio'] ?? ''
        );

        $sheet->setCellValue(
            "C{$r}",
            $row['serial'] ?? ''
        );

        $sheet->setCellValue(
            "D{$r}",
            $row['sku'] ?? ''
        );

        $r++;
    }


    // =========================================================
    // 6. AJUSTAR AMPLADA DE COLUMNES
    // =========================================================

    foreach (range('A', 'D') as $col) {
        $sheet
            ->getColumnDimension($col)
            ->setAutoSize(true);
    }


    // =========================================================
    // 7. ACTIVAR FILTRES
    // =========================================================

    $sheet->setAutoFilter('A1:D1');


    // =========================================================
    // 8. NOM DEL FITXER
    // =========================================================

    $filename =
        'magatzem_ubicacions_'
        . $magatzem
        . '_'
        . date('Ymd_His')
        . '.xlsx';


    // =========================================================
    // 9. MOLT IMPORTANT A PRODUCCIÓ
    //
    // Eliminem qualsevol sortida anterior:
    // - warnings de PHP
    // - espais
    // - HTML
    // - buffers oberts
    //
    // Qualsevol byte abans de l'XLSX pot corrompre el fitxer.
    // =========================================================

    while (ob_get_level() > 0) {
        ob_end_clean();
    }


    // =========================================================
    // 10. HEADERS DE DESCÀRREGA
    // =========================================================

    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; filename="' . $filename . '"'
    );

    header('Cache-Control: max-age=0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Expires: 0');


    // =========================================================
    // 11. GENERAR I ENVIAR L'EXCEL
    // =========================================================

    $writer = new Xlsx($spreadsheet);

    $writer->save('php://output');


    // =========================================================
    // 12. ALLIBERAR MEMÒRIA
    // =========================================================

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);


    // Impedim que s'enviï qualsevol cosa després del fitxer
    exit;


} catch (Throwable $e) {

    // =========================================================
    // ERROR
    //
    // Si passa alguna cosa al servidor, no intentem descarregar
    // un XLSX corrupte. Mostrem l'error com a text.
    // =========================================================

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);

    header('Content-Type: text/plain; charset=utf-8');

    echo "Error exportant el mapa de magatzem:\n\n";
    echo $e->getMessage();

    exit;
}