<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/warehouse_positions.php';
require_once __DIR__ . '/../vendor/autoload.php';


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
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE codi = ?");
        $stmt->execute([$posicio]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = "Fila {$excelRow}: la posició '{$posicio}' no existeix.";
            $excelRow++;
            continue;
        }

        // 2) Trobar unitat activa pel serial
        $stmt = $pdo->prepare("SELECT id FROM item_units WHERE serial = ? AND estat='actiu'");
        $stmt->execute([$serial]);
        $unitId = (int)$stmt->fetchColumn();

        if ($unitId <= 0) {
            $errors[] = "Fila {$excelRow}: no s'ha trobat cap unitat activa amb serial '{$serial}'.";
            $excelRow++;
            continue;
        }

        // 3) Validar que la posició no està ocupada (via mapa real: inclou descatalogats)
        $stmt = $pdo->prepare("
            SELECT item_unit_id
            FROM magatzem_posicions
            WHERE codi = ?
            LIMIT 1
        ");
        $stmt->execute([$posicio]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $errors[] = "Fila {$excelRow}: la posició '{$posicio}' no existeix al magatzem.";
            $excelRow++;
            continue;
        }

        $ocupantId = $row['item_unit_id']; // pot ser NULL
        if ($ocupantId !== null && (int)$ocupantId !== (int)$unitId) {
            $errors[] = "Fila {$excelRow}: la posició '{$posicio}' ja està ocupada per una altra unitat.";
            $excelRow++;
            continue;
        }


        // 4) Assignar posició a la unitat via helper (sincronitza map + unitat)
        $res = setUnitPosition($pdo, $unitId, $posicio);
        if (!$res['ok']) {
            $errors[] = "Fila {$excelRow}: " . ($res['error'] ?? "Error assignant posició.");
            $excelRow++;
            continue;
        }

        // Forcem ubicacio=magatzem (per coherència)
        $pdo->prepare("UPDATE item_units SET ubicacio='magatzem', updated_at = NOW() WHERE id = ?")
            ->execute([$unitId]);


        $importats++;
        $excelRow++;
    }

    if (!empty($errors)) {
        $pdo->rollBack();
        throw new Exception("Errors trobats:\n" . implode("\n", $errors));
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
