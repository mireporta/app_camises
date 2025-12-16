<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

try {
    // ==========================================================
    // 📘 ITEMS (SKU) — NO exportar si (active=0 i estoc=0)
    // Estoc sempre basat en unitats actives (estat='actiu')
    // ==========================================================
    $sqlItems = "
        SELECT
            i.sku,
            i.category,
            i.min_stock,
            i.active,
            i.plan_file,
            COALESCE(t.total_cnt, 0)      AS total_stock,
            COALESCE(g.cnt_magatzem, 0)   AS qty_magatzem,
            COALESCE(im.cnt_intermig, 0)  AS qty_intermig,
            COALESCE(p.cnt_preparacio, 0) AS qty_preparacio,
            COALESCE(m.cnt_maquina, 0)    AS qty_maquina
        FROM items i
        LEFT JOIN (
            SELECT item_id, COUNT(*) AS total_cnt
            FROM item_units
            WHERE estat = 'actiu'
            GROUP BY item_id
        ) t ON t.item_id = i.id
        LEFT JOIN (
            SELECT item_id, COUNT(*) AS cnt_magatzem
            FROM item_units
            WHERE estat = 'actiu' AND ubicacio = 'magatzem'
            GROUP BY item_id
        ) g ON g.item_id = i.id
        LEFT JOIN (
            SELECT item_id, COUNT(*) AS cnt_intermig
            FROM item_units
            WHERE estat = 'actiu' AND ubicacio = 'intermig'
            GROUP BY item_id
        ) im ON im.item_id = i.id
        LEFT JOIN (
            SELECT item_id, COUNT(*) AS cnt_preparacio
            FROM item_units
            WHERE estat = 'actiu' AND ubicacio = 'preparacio'
            GROUP BY item_id
        ) p ON p.item_id = i.id
        LEFT JOIN (
            SELECT item_id, COUNT(*) AS cnt_maquina
            FROM item_units
            WHERE estat = 'actiu' AND ubicacio = 'maquina'
            GROUP BY item_id
        ) m ON m.item_id = i.id
        WHERE NOT (i.active = 0 AND COALESCE(t.total_cnt, 0) = 0)
        ORDER BY i.sku ASC
    ";
    $items = $pdo->query($sqlItems)->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================================
    // 📗 UNITATS (SERIAL)
    // Exportar:
    //  - actius
    //  - inactius només si encara estan en ubicació "real"
    // No exportar:
    //  - baixa / NULL (històric pur)
    // ==========================================================
    $sqlUnits = "
        SELECT
            i.sku,
            iu.serial,
            iu.ubicacio,
            iu.sububicacio,
            iu.maquina_actual,
            iu.vida_total,
            iu.vida_utilitzada,
            iu.cicles_maquina,
            iu.estat,
            iu.updated_at
        FROM item_units iu
        JOIN items i ON i.id = iu.item_id
        WHERE
            iu.estat = 'actiu'
            OR (
                iu.estat = 'inactiu'
                AND iu.ubicacio IN ('magatzem','intermig','preparacio','maquina')
            )
        ORDER BY i.sku ASC, iu.serial ASC
    ";
    $unitats = $pdo->query($sqlUnits)->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================================
    // 📄 GENERAR EXCEL
    // ==========================================================
    $spreadsheet = new Spreadsheet();

    // ---------- Pestanya "items"
    $sheetItems = $spreadsheet->getActiveSheet();
    $sheetItems->setTitle('items');

    $headersItems = [
        'sku', 'category', 'min_stock', 'active', 'plan_file',
        'total_stock', 'qty_magatzem', 'qty_intermig', 'qty_preparacio', 'qty_maquina'
    ];
    $sheetItems->fromArray($headersItems, null, 'A1');
    $sheetItems->freezePane('A2');
    $sheetItems->setAutoFilter('A1:J1');

    $r = 2;
    foreach ($items as $row) {
        $sheetItems->setCellValueExplicit("A{$r}", (string)($row['sku'] ?? ''), DataType::TYPE_STRING);

        $sheetItems->fromArray([
            $row['category'] ?? '',
            (int)($row['min_stock'] ?? 0),
            (int)($row['active'] ?? 0),
            (string)($row['plan_file'] ?? ''),
            (int)($row['total_stock'] ?? 0),
            (int)($row['qty_magatzem'] ?? 0),
            (int)($row['qty_intermig'] ?? 0),
            (int)($row['qty_preparacio'] ?? 0),
            (int)($row['qty_maquina'] ?? 0),
        ], null, "B{$r}");

        $r++;
    }

    foreach (range('A', 'J') as $col) {
        $sheetItems->getColumnDimension($col)->setAutoSize(true);
    }

    // ---------- Pestanya "unitats"
    $sheetUnits = $spreadsheet->createSheet();
    $sheetUnits->setTitle('unitats');

    $headersUnits = [
        'sku', 'serial', 'ubicacio', 'sububicacio', 'maquina_actual',
        'vida_total', 'vida_utilitzada', 'cicles_maquina', 'estat', 'updated_at'
    ];
    $sheetUnits->fromArray($headersUnits, null, 'A1');
    $sheetUnits->freezePane('A2');
    $sheetUnits->setAutoFilter('A1:J1');

    $r = 2;
    foreach ($unitats as $u) {
        $sheetUnits->setCellValueExplicit("A{$r}", (string)($u['sku'] ?? ''), DataType::TYPE_STRING);
        $sheetUnits->setCellValueExplicit("B{$r}", (string)($u['serial'] ?? ''), DataType::TYPE_STRING);

        $sheetUnits->fromArray([
            (string)($u['ubicacio'] ?? ''),
            (string)($u['sububicacio'] ?? ''),
            (string)($u['maquina_actual'] ?? ''),
            ($u['vida_total'] === null ? '' : (int)$u['vida_total']),
            ($u['vida_utilitzada'] === null ? '' : (int)$u['vida_utilitzada']),
            ($u['cicles_maquina'] === null ? '' : (int)$u['cicles_maquina']),
            (string)($u['estat'] ?? ''),
            (string)($u['updated_at'] ?? ''),
        ], null, "C{$r}");

        $r++;
    }

    foreach (range('A', 'J') as $col) {
        $sheetUnits->getColumnDimension($col)->setAutoSize(true);
    }

    // ==========================================================
    // 📤 OUTPUT
    // ==========================================================
    if (ob_get_length()) ob_end_clean();

    $filename = 'inventari_complet.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
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
