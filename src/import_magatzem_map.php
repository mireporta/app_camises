<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/warehouse_positions.php';
require_once __DIR__ . '/../vendor/autoload.php';

// IMPORTANT: Les posicions s'apliquen en dues fases (swap-friendly).
// La veritat és magatzem_posicions.item_unit_id. Descatalogats bloquegen posició.


session_start();

use PhpOffice\PhpSpreadsheet\IOFactory;

// 🔐 Validació contrasenya import
$pwd = $_POST['import_password'] ?? '';
if (!defined('IMPORT_PASSWORD') || $pwd !== IMPORT_PASSWORD) {
    $_SESSION['map_message'] = "❌ Contrasenya d'importació incorrecta.";
    $_SESSION['map_message_type'] = "error";
    header("Location: ../public/magatzem_map.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['map_message'] = "❌ Accés no permès.";
    $_SESSION['map_message_type'] = "error";
    header("Location: ../public/magatzem_map.php");
    exit;
}

if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['map_message'] = "❌ Cal seleccionar un fitxer XLSX vàlid.";
    $_SESSION['map_message_type'] = "error";
    header("Location: ../public/magatzem_map.php");
    exit;
}

$tmpFile = $_FILES['xlsx_file']['tmp_name'];

try {
    $spreadsheet = IOFactory::load($tmpFile);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);

    if (!$rows || count($rows) < 2) {
        throw new Exception("El fitxer està buit o no té dades.");
    }

    // ✅ Inicialitzacions (faltaven)
    $errors = [];
    $importats = 0;

    // Capçalera
    $headerRow = array_shift($rows);
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $headerRow);

    // IMPORTANT: AQUESTS VALORS SERAN 'A','B','C'...
    $colPos = array_search('posicio', $header, true);
    $colSer = array_search('serial', $header, true);

    if ($colPos === false || $colSer === false) {
        throw new Exception("Capçalera incorrecta. Calen columnes: posicio, serial (sku opcional).");
    }

    // ✅ Comencem transacció (faltava)
    $pdo->beginTransaction();

    $excelRow = 2;

    $desiredPosByUnit = [];
    $desiredUnitByPos = [];
    $incomingUnitIds  = [];
    $warnings         = [];


    foreach ($rows as $row) {
        // Ignora files completament buides
        $isEmpty = true;
        foreach ($row as $v) {
            if (trim((string)$v) !== '') { $isEmpty = false; break; }
        }
        if ($isEmpty) { $excelRow++; continue; }

        // Llegim per lletra de columna (A/B/C...)
        $posicio = trim((string)($row[$colPos] ?? ''));
        $serial  = trim((string)($row[$colSer] ?? ''));

        // Treure BOM si hi és
        $posicio = preg_replace('/^\xEF\xBB\xBF/', '', $posicio);

        // Si la fila no té posició, és una fila dolenta -> error
        if ($posicio === '') {
            $errors[] = "Fila {$excelRow}: cal posicio.";
            $excelRow++;
            continue;
        }

        // ✅ Si NO hi ha serial -> és una posició buida -> la saltem (NO és error)
        if ($serial === '') {
            $excelRow++;
            continue;
        }


        // 1) Validar que la posició existeix
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE magatzem_code='MAG01' AND codi = ?");
        $stmt->execute([$posicio]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = "Fila {$excelRow}: la posició '{$posicio}' no existeix.";
            $excelRow++;
            continue;
        }

        // 2) Trobar unitat pel serial
        $stmt = $pdo->prepare("SELECT id, estat, baixa_motiu FROM item_units WHERE serial = ? LIMIT 1");
        $stmt->execute([$serial]);
        $urow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$urow) {
            $errors[] = "Fila {$excelRow}: no s'ha trobat cap unitat amb serial '{$serial}'.";
            $excelRow++;
            continue;
        }

        $unitId = (int)$urow['id'];

        // 3) Guardar intenció (swap-friendly; s'aplicarà al final)
        $desiredPosByUnit[(int)$unitId] = $posicio;
        $incomingUnitIds[(int)$unitId] = true;

        // Duplicats dins del mateix Excel
        if (isset($desiredUnitByPos[$posicio]) && (int)$desiredUnitByPos[$posicio] !== (int)$unitId) {
            $errors[] = "Fila {$excelRow}: la posició '{$posicio}' està repetida a l'Excel (unitats {$desiredUnitByPos[$posicio]} i {$unitId}).";
            $excelRow++;
            continue;
        }
        $desiredUnitByPos[$posicio] = (int)$unitId;

        $importats++;
        $excelRow++;
        continue;
    }

    if (!empty($errors)) {
        $pdo->rollBack();
        throw new Exception("Errors trobats:\n" . implode("\n", $errors));
    }

    // ✅ FASE 2: aplicar posicions en bloc (permite swaps)

    // 2.1) Validar que cap posició desitjada està ocupada per un "extern" a l'import
    foreach ($desiredPosByUnit as $uId => $pos) {
        $stmt = $pdo->prepare("SELECT item_unit_id FROM magatzem_posicions WHERE magatzem_code='MAG01' AND codi = ? LIMIT 1");
        $stmt->execute([$pos]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $errors[] = "Import: la posició '{$pos}' no existeix al magatzem.";
            continue;
        }

        $occId = $row['item_unit_id'];
        if ($occId !== null) {
            $occId = (int)$occId;

            // Permès si és la mateixa unitat, o si l'ocupant també està a l'import (swap/cadena)
            if ($occId !== (int)$uId && !isset($incomingUnitIds[$occId])) {
                $errors[] = "Import: la posició '{$pos}' està ocupada per la unitat {$occId} (no present a l'import).";
            }
        }
    }

    if (!empty($errors)) {
        // si tens transacció, fes rollback; si no, simplement mostra errors i surt
        if ($pdo->inTransaction()) $pdo->rollBack();
        // aquí retorna/mostra errors com ja facis tu
        exit;
    }

    // 2.2) Alliberar posicions de totes les unitats implicades
    foreach (array_keys($incomingUnitIds) as $uId) {
        // Protecció: no moure descatalogats via import (opcional però recomanat)
        $st = $pdo->prepare("SELECT estat, baixa_motiu FROM item_units WHERE id = ? LIMIT 1");
        $st->execute([(int)$uId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        freePositionByUnit($pdo, (int)$uId);
        $res = setUnitPosition($pdo, (int)$uId, $pos);

    }

        // 2.3) Ocupar segons el desitjat + forçar ubicacio='magatzem'
        foreach ($desiredPosByUnit as $uId => $pos) {
            $res = setUnitPosition($pdo, (int)$uId, $pos);
            if (!$res['ok']) {
                $errors[] = "Unitat {$uId}: " . ($res['error'] ?? "Error assignant posició '{$pos}'.");
                continue;
            }

            $pdo->prepare("UPDATE item_units SET ubicacio='magatzem', updated_at = NOW() WHERE id = ?")
                ->execute([(int)$uId]);
        }

        if (!empty($errors)) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            exit;
        }


    $pdo->commit();

    $_SESSION['map_message'] = "✅ Import completat: {$importats} ubicacions actualitzades.";
    $_SESSION['map_message_type'] = "success";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['map_message'] = "❌ Import fallit:\n" . $e->getMessage();
    $_SESSION['map_message_type'] = "error";
}

header("Location: ../public/magatzem_map.php");
exit;
