<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php'; // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['excel_file']['tmp_name'])) {
    header("Location: ../public/inventory.php");
    exit;
}

try {
    $filePath = $_FILES['excel_file']['tmp_name'];
    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);

    $inserted = 0;
    $updated = 0;
    $ignored = 0;
    $duplicates = 0;

    $seenSkus = [];

    foreach ($rows as $index => $row) {
        if ($index === 1) continue; // salta la capçalera

        $sku              = trim((string)($row['A'] ?? ''));
        $name             = trim((string)($row['B'] ?? ''));
        $category         = trim((string)($row['C'] ?? ''));
        $location         = trim((string)($row['D'] ?? ''));
        $stock            = is_numeric($row['E']) ? (int)$row['E'] : 0;
        $min_stock        = is_numeric($row['F']) ? (int)$row['F'] : 0;
        $life_expectancy  = is_numeric($row['G']) ? (int)$row['G'] : 0;
        $vida_utilitzada  = is_numeric($row['H']) ? (int)$row['H'] : 0;
        $active           = (int)($row['I'] ?? 1);

        // --- VALIDACIONS ---
        if ($sku === '' || $name === '') {
            $ignored++;
            continue; // sense SKU o nom
        }

        if (isset($seenSkus[$sku])) {
            $duplicates++;
            continue; // duplicat dins el fitxer
        }
        $seenSkus[$sku] = true;

        // --- EXISTEIX A LA BD? ---
        $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
        $stmt->execute([$sku]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // ACTUALITZA
            $stmt = $pdo->prepare("
                UPDATE items
                SET name = ?, category = ?, location = ?, stock = ?, min_stock = ?, 
                    life_expectancy = ?, vida_utilitzada = ?, active = ?
                WHERE sku = ?
            ");
            $stmt->execute([
                $name, $category, $location, $stock, $min_stock,
                $life_expectancy, $vida_utilitzada, $active, $sku
            ]);
            $updated++;
        } else {
            // INSEREIX
            $stmt = $pdo->prepare("
                INSERT INTO items (sku, name, category, location, stock, min_stock, life_expectancy, vida_utilitzada, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $sku, $name, $category, $location, $stock, $min_stock,
                $life_expectancy, $vida_utilitzada, $active
            ]);
            $inserted++;
        }
    }

    // --- MISSATGE DE RESULTATS ---
    session_start();
    $_SESSION['import_message'] = sprintf(
        "✅ Importació completada:<br>
         ➕ %d nous<br>
         🔄 %d actualitzats<br>
         ⚠️ %d ignorats per dades incorrectes<br>
         ❗ %d duplicats dins el fitxer",
        $inserted, $updated, $ignored, $duplicates
    );

    header("Location: ../public/inventory.php");
    exit;

} catch (Throwable $e) {
    session_start();
    $_SESSION['import_message'] = "❌ Error en importar: " . htmlspecialchars($e->getMessage());
    header("Location: ../public/inventory.php");
    exit;
}
