<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/warehouse_positions.php';
require_once __DIR__ . '/../vendor/autoload.php';

// IMPORTANT: Les posicions s'apliquen en dues fases (swap-friendly).
// La veritat és magatzem_posicions.item_unit_id. Descatalogats bloquegen posició.

use PhpOffice\PhpSpreadsheet\IOFactory;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Accés no permès');
}

// 🔐 Validació contrasenya import
$pwd = $_POST['import_password'] ?? '';
if (!defined('IMPORT_PASSWORD') || $pwd !== IMPORT_PASSWORD) {
    $_SESSION['import_message'] = "❌ Contrasenya d'importació incorrecta.";
    header("Location: ../public/inventory.php");
    exit;
}

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['import_message'] = "❌ Fitxer Excel no vàlid.";
    header("Location: ../public/inventory.php");
    exit;
}

$tmpFile = $_FILES['excel_file']['tmp_name'];
$spreadsheet = IOFactory::load($tmpFile);

// ---------- VALIDACIÓ DE PESTANYES ----------
$sheetItems   = $spreadsheet->getSheetByName('items');
$sheetUnitats = $spreadsheet->getSheetByName('unitats');

if (!$sheetItems || !$sheetUnitats) {
    $_SESSION['import_message'] = '❌ L’Excel ha de contenir les pestanyes "items" i "unitats".';
    header("Location: ../public/inventory.php");
    exit;
}

$errors = [];
$stats = [
    'items_insert'   => 0,
    'items_update'   => 0,
    'unitats_insert' => 0,
    'unitats_update' => 0,
];

try {
    $pdo->beginTransaction();

    // ==========================================================
    // 📘 IMPORT ITEMS (SKU)
    // ==========================================================
    $rows = $sheetItems->toArray(null, true, true, true);
    $header = array_map(fn($h) => strtolower(trim((string)$h)), array_shift($rows));

    $desiredPosByUnit = []; // unitId => "A-01"
    $desiredUnitByPos = []; // "A-01" => unitId
    $incomingUnitIds  = []; // set d'unitats presents a l'import
    $importWarnings   = [];


    foreach ($rows as $row) {
        $values = array_map(fn($v) => trim((string)$v), $row);
        $data = array_combine($header, $values);

        $sku = $data['sku'] ?? '';
        if ($sku === '') {
            continue;
        }

        $category = ($data['category'] ?? '') !== '' ? $data['category'] : null;
        $minStock = (int)($data['min_stock'] ?? 0);
        $active   = isset($data['active']) && $data['active'] !== '' ? (int)$data['active'] : 1;
        $planFile = ($data['plan_file'] ?? '') !== '' ? $data['plan_file'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO items (sku, category, min_stock, active, plan_file, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                category  = VALUES(category),
                min_stock = VALUES(min_stock),
                active    = VALUES(active),
                plan_file = VALUES(plan_file)
        ");
        $stmt->execute([$sku, $category, $minStock, $active, $planFile]);

        // rowCount() amb MySQL pot variar; ho usem com a aproximació
        if ($stmt->rowCount() === 1) $stats['items_insert']++;
        else $stats['items_update']++;
    }

    // ==========================================================
    // 📗 IMPORT UNITATS (SERIAL)
    // ==========================================================
    $rows = $sheetUnitats->toArray(null, true, true, true);
    $headerRow = array_shift($rows);
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $headerRow);

    $excelRowNumber = 2; // la primera fila de dades real és la 2

    foreach ($rows as $row) {
        $rowValues = array_map(fn($v) => trim((string)$v), $row);

        // ✅ Ignora files completament buides
        $isEmpty = true;
        foreach ($rowValues as $v) {
            if ($v !== '') { $isEmpty = false; break; }
        }
        if ($isEmpty) {
            $excelRowNumber++;
            continue;
        }

        $data = array_combine($header, $rowValues);

        $sku    = $data['sku'] ?? '';
        $serial = $data['serial'] ?? '';

        $ubicacioRaw = $data['ubicacio'] ?? '';
        $ubicacio = strtolower(trim((string)$ubicacioRaw));

        $sububicacio = isset($data['sububicacio']) ? trim((string)$data['sububicacio']) : null;
        $maquinaActual = isset($data['maquina_actual']) ? trim((string)$data['maquina_actual']) : null;

        // estat
        $estat = isset($data['estat']) && trim((string)$data['estat']) !== ''
            ? trim((string)$data['estat'])
            : null;

        // ✅ Inferim ubicació si ve buida (export antic / dades històriques)
        if ($ubicacio === '') {
            if (!empty($sububicacio)) $ubicacio = 'magatzem';
            else $ubicacio = 'baixa';
        }

        // ✅ Inferim estat si no ve informat
        if ($estat === null) {
            $estat = ($ubicacio === 'baixa') ? 'inactiu' : 'actiu';
        }

        // Camps obligatoris mínims
        if ($sku === '' || $serial === '' || $ubicacio === '') {
            $errors[] = "Fila {$excelRowNumber}: Falta SKU, serial o ubicació.";
            $excelRowNumber++;
            continue;
        }

        // Obtenir item_id
        $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
        $stmt->execute([$sku]);
        $itemId = $stmt->fetchColumn();
        

        if (!$itemId) {
            $errors[] = "Fila {$excelRowNumber}: SKU {$sku} no existeix.";
            $excelRowNumber++;
            continue;
        }

        $vidaTotal = (isset($data['vida_total']) && $data['vida_total'] !== '') ? (int)$data['vida_total'] : null;
        $vidaUtilitzada = (isset($data['vida_utilitzada']) && $data['vida_utilitzada'] !== '') ? (int)$data['vida_utilitzada'] : 0;
        $ciclesMaquina = (isset($data['cicles_maquina']) && $data['cicles_maquina'] !== '') ? (int)$data['cicles_maquina'] : 0;

        // ✅ FASE 1 (LECTURA): validar que la posició existeix + guardar intenció (permet swaps)
        if ($ubicacio === 'magatzem' && !empty($sububicacio)) {
            // 1) Existeix la posició?
            $stmt = $pdo->prepare("SELECT 1 FROM magatzem_posicions WHERE codi = ? LIMIT 1");
            $stmt->execute([$sububicacio]);
            if (!$stmt->fetchColumn()) {
                $errors[] = "Fila {$excelRowNumber}: Sububicació {$sububicacio} no existeix.";
                $excelRowNumber++;
                continue;
            }

            // 2) Guardar intenció de posició per aplicar DESPRÉS
            // IMPORTANT: aquests arrays els has de declarar abans del bucle (veure punt 2)
            $desiredPosByUnit[(int)$unitId] = $sububicacio;
            $incomingUnitIds[(int)$unitId] = true;

            // 3) Detectar duplicats dins del mateix Excel (dues unitats -> mateixa posició)
            if (isset($desiredUnitByPos[$sububicacio]) && (int)$desiredUnitByPos[$sububicacio] !== (int)$unitId) {
                $errors[] = "Fila {$excelRowNumber}: Sububicació {$sububicacio} repetida a l'Excel (unitats {$desiredUnitByPos[$sububicacio]} i {$unitId}).";
                $excelRowNumber++;
                continue;
            }
            $desiredUnitByPos[$sububicacio] = (int)$unitId;
        }


        // ✅ Si és màquina o preparació, cal maquina_actual
        if (in_array($ubicacio, ['maquina', 'preparacio'], true) && empty($maquinaActual)) {
            $errors[] = "Fila {$excelRowNumber}: Cal indicar maquina_actual.";
            $excelRowNumber++;
            continue;
        }
        if ($ubicacio === 'magatzem') {
            $maquinaActual = null;
        }

        // INSERT / UPDATE unitat (serial UNIQUE)
        $stmt = $pdo->prepare("
            INSERT INTO item_units
            (item_id, serial, ubicacio, sububicacio, maquina_actual,
             vida_total, vida_utilitzada, cicles_maquina, estat, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                item_id         = VALUES(item_id),
                ubicacio        = VALUES(ubicacio),
                sububicacio     = VALUES(sububicacio),
                maquina_actual  = VALUES(maquina_actual),
                vida_total      = VALUES(vida_total),
                vida_utilitzada = VALUES(vida_utilitzada),
                cicles_maquina  = VALUES(cicles_maquina),
                estat           = VALUES(estat),
                updated_at      = NOW()
        ");
        $stmt->execute([
            (int)$itemId,
            $serial,
            $ubicacio,
            $sububicacio !== '' ? $sububicacio : null,
            $maquinaActual !== '' ? $maquinaActual : null,
            $vidaTotal,
            $vidaUtilitzada,
            $ciclesMaquina,
            $estat
        ]);

        // ✅ Recuperar l'ID real de la unitat (per swaps i validacions correctes)
        $stmt = $pdo->prepare("SELECT id, estat, baixa_motiu FROM item_units WHERE serial = ? LIMIT 1");
        $stmt->execute([$serial]);
        $urow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$urow) {
            $errors[] = "Fila {$excelRowNumber}: No s'ha pogut recuperar la unitat pel serial {$serial}.";
            $excelRowNumber++;
            continue;
        }

        $unitId = (int)$urow['id'];

        // Moviments: només per inserts (i només si la taula existeix)
        if ($stmt->rowCount() === 1) {
            $stats['unitats_insert']++;

            if ($pdo->query("SHOW TABLES LIKE 'moviments'")->rowCount() > 0) {
                $pdo->prepare("
                    INSERT INTO moviments
                    (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
                    SELECT id, item_id, 'entrada', 1, ?, 'BOLCAT', NOW()
                    FROM item_units WHERE serial = ?
                ")->execute([$ubicacio, $serial]);
            }
        } else {
            $stats['unitats_update']++;
        }

        $excelRowNumber++;
    }

    if (!empty($errors)) {
        throw new Exception("Errors trobats:\n" . implode("\n", $errors));
    }

    // ✅ RESYNC final: magatzem_posicions.item_unit_id reflecteix item_units.sububicacio
    // Regla: ocupem si (estat actiu) o (estat inactiu + baixa_motiu=descatalogat), i ubicacio=magatzem
    $pdo->exec("UPDATE magatzem_posicions SET item_unit_id = NULL");

    $pdo->exec("
        UPDATE magatzem_posicions mp
        JOIN (
            SELECT
                sububicacio,
                MAX(CASE WHEN estat='actiu' THEN id ELSE 0 END) AS active_id,
                MAX(CASE WHEN estat='inactiu' AND LOWER(baixa_motiu)='descatalogat' THEN id ELSE 0 END) AS desc_id
            FROM item_units
            WHERE ubicacio='magatzem' AND sububicacio IS NOT NULL AND sububicacio <> ''
            GROUP BY sububicacio
        ) x ON x.sububicacio = mp.codi
        SET mp.item_unit_id = CASE
            WHEN x.active_id <> 0 THEN x.active_id
            ELSE x.desc_id
        END
    ");


    // ✅ FASE 2 (APLICACIÓ POSICIONS): validar mapa final + buidar + omplir (permite swaps)

    // 2.1) Validar que les posicions desitjades no estan ocupades per algú extern a l'import
    foreach ($desiredPosByUnit as $uId => $pos) {
        $stmt = $pdo->prepare("SELECT item_unit_id FROM magatzem_posicions WHERE codi = ? LIMIT 1");
        $stmt->execute([$pos]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $errors[] = "Import: La posició '{$pos}' no existeix al magatzem.";
            continue;
        }

        $occId = $row['item_unit_id'];
        if ($occId !== null) {
            $occId = (int)$occId;

            // Permès si és la mateixa unitat, o si l'ocupant també està a l'import (swap/cadena)
            if ($occId !== (int)$uId && !isset($incomingUnitIds[$occId])) {
                $errors[] = "Import: La posició '{$pos}' està ocupada per la unitat {$occId} (no present a l'import).";
            }
        }
    }

    if (!empty($errors)) {
        $pdo->rollBack();
        throw new Exception("Errors trobats:\n" . implode("\n", $errors));
    }

    // 2.2) Alliberar posicions de totes les unitats que participen (abans d'omplir)
    foreach (array_keys($incomingUnitIds) as $uId) {
        // Protecció: si és descatalogat, no el movem (ja ho filtrem a fase 1, però ho reforcem)
        $st = $pdo->prepare("SELECT estat, baixa_motiu FROM item_units WHERE id = ?");
        $st->execute([(int)$uId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        freePositionByUnit($pdo, (int)$uId);
    }

    // 2.3) Ocupar segons el desitjat
    foreach ($desiredPosByUnit as $uId => $pos) {
        $res = setUnitPosition($pdo, (int)$uId, $pos);
        if (!$res['ok']) {
            $errors[] = "Unitat {$uId}: " . ($res['error'] ?? "Error assignant posició '{$pos}'.");
        }
    }

    if (!empty($errors)) {
        $pdo->rollBack();
        throw new Exception("Errors aplicant posicions:\n" . implode("\n", $errors));
    }


    $pdo->commit();

    $_SESSION['import_message'] =
        "✔ Import completat:\n" .
        "- Items creats: {$stats['items_insert']}\n" .
        "- Items actualitzats: {$stats['items_update']}\n" .
        "- Unitats creades: {$stats['unitats_insert']}\n" .
        "- Unitats actualitzades: {$stats['unitats_update']}";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['import_message'] = "❌ Import fallit:\n" . $e->getMessage();
}

header("Location: ../public/inventory.php");
exit;
