<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
session_start();

$pwd = $_POST['import_password'] ?? '';
if (!defined('IMPORT_PASSWORD') || $pwd !== IMPORT_PASSWORD) {
    $_SESSION['map_message'] = "❌ Contrasenya d'importació incorrecta.";
    $_SESSION['map_message_type'] = "error";
    header("Location: ../public/magatzem_map.php");
    exit;
}


use PhpOffice\PhpSpreadsheet\IOFactory;

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

// Capçalera (manté això)
$headerRow = array_shift($rows);
$header = array_map(fn($h) => strtolower(trim((string)$h)), $headerRow);

// IMPORTANT: idxPos i idxSer seran 'A', 'B', ...
$colPos = array_search('posicio', $header, true);
$colSer = array_search('serial', $header, true);

if ($colPos === false || $colSer === false) {
    throw new Exception("Capçalera incorrecta. Calen columnes: posicio, serial (sku opcional).");
}

$excelRow = 2;

foreach ($rows as $row) {
    // Ignora files buides
    $rowValues = array_map(fn($v) => trim((string)$v), $row);
    $isEmpty = true;
    foreach ($rowValues as $v) { if ($v !== '') { $isEmpty = false; break; } }
    if ($isEmpty) { $excelRow++; continue; }

    // ✅ Llegim per lletra de columna (A/B/C...)
    $posicio = trim((string)($row[$colPos] ?? ''));
    $serial  = trim((string)($row[$colSer] ?? ''));

    // Treure BOM si hi és
    $posicio = preg_replace('/^\xEF\xBB\xBF/', '', $posicio);

    if ($posicio === '' || $serial === '') {
        $errors[] = "Fila {$excelRow}: cal posicio i serial.";
        $excelRow++;
        continue;
    }

        // 1) posició existeix
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE codi = ?");
        $stmt->execute([$posicio]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = "Fila {$excelRow}: la posició '{$posicio}' no existeix.";
            $excelRow++;
            continue;
        }

        // 2) unitat activa pel serial
        $stmt = $pdo->prepare("SELECT id FROM item_units WHERE serial = ? AND estat='actiu'");
        $stmt->execute([$serial]);
        $unitId = (int)$stmt->fetchColumn();

        if ($unitId <= 0) {
            $errors[] = "Fila {$excelRow}: no s'ha trobat cap unitat activa amb serial '{$serial}'.";
            $excelRow++;
            continue;
        }

        // 3) posició no ocupada per una altra unitat activa
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM item_units 
            WHERE sububicacio = ? AND estat='actiu' AND id <> ?
        ");
        $stmt->execute([$posicio, $unitId]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = "Fila {$excelRow}: la posició '{$posicio}' ja està ocupada per una altra unitat.";
            $excelRow++;
            continue;
        }

        // 4) assignar posició i forçar ubicació=magatzem
        $stmt = $pdo->prepare("
            UPDATE item_units
            SET sububicacio = ?, ubicacio='magatzem', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$posicio, $unitId]);

        $importats++;
        $excelRow++;
    }

    if ($errors) {
        $pdo->rollBack();
        throw new Exception("Errors trobats:\n" . implode("\n", $errors));
    }

    $pdo->commit();

    $_SESSION['map_message'] = "✅ Import completat: {$importats} ubicacions actualitzades.";
    $_SESSION['map_message_type'] = "success";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['map_message'] = "❌ Import fallit:\n" . $e->getMessage();
    $_SESSION['map_message_type'] = "error";
}

header("Location: ../public/magatzem_map.php");
exit;
