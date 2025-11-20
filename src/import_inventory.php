<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php'; // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

// 🔐 Contrasenya d'importació (canvia-la pel que vulguis)
const IMPORT_PASSWORD = 'camises2025';

session_start();

// Només acceptem POST amb fitxer
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['excel_file']['tmp_name'])) {
    header("Location: ../public/inventory.php");
    exit;
}

// 🔐 Validació de contrasenya d'importació
$pwd = $_POST['import_password'] ?? '';
if ($pwd !== IMPORT_PASSWORD) {
    $_SESSION['import_message'] = "❌ Contrasenya incorrecta. Importació cancel·lada.";
    header("Location: ../public/inventory.php");
    exit;
}

try {
    $filePath    = $_FILES['excel_file']['tmp_name'];
    $spreadsheet = IOFactory::load($filePath);
    $sheet       = $spreadsheet->getActiveSheet();
    $rows        = $sheet->toArray(null, true, true, true);

    $inserted   = 0;
    $updated    = 0;
    $ignored    = 0;
    $duplicates = 0;

    $seenSkus = [];

    foreach ($rows as $index => $row) {
        if ($index === 1) continue; // saltem la capçalera

        // 🧾 Llegim columnes (adaptat a l'estructura nova)
        // A: SKU
        // B: Categoria
        // C: Estoc mínim
        // D: Actiu (0/1)
        $sku       = trim((string)($row['A'] ?? ''));
        $category  = trim((string)($row['B'] ?? ''));
        $min_stock = is_numeric($row['C'] ?? null) ? (int)$row['C'] : 0;
        $active    = ($row['D'] === '' || !isset($row['D']))
                        ? 1
                        : (int)$row['D'];

        // --- VALIDACIONS bàsiques ---
        if ($sku === '') {
            $ignored++;
            continue; // sense SKU
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
            // 🔄 ACTUALITZA ITEM EXISTENT
            $stmt = $pdo->prepare("
                UPDATE items
                SET category   = ?,
                    min_stock  = ?,
                    active     = ?,
                    updated_at = NOW()
                WHERE sku = ?
            ");
            $stmt->execute([
                $category,
                $min_stock,
                $active,
                $sku
            ]);
            $updated++;

        } else {
            // ➕ INSEREIX NOU ITEM
            $stmt = $pdo->prepare("
                INSERT INTO items (sku, category, min_stock, active)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $sku,
                $category,
                $min_stock,
                $active
            ]);
            $inserted++;
        }
    }

    // --- MISSATGE DE RESULTATS ---
    $_SESSION['import_message'] = sprintf(
        "✅ Importació completada:<br>
         ➕ %d nous<br>
         🔄 %d actualitzats<br>
         ⚠️ %d ignorats per dades incorrectes<br>
         ❗ %d duplicats dins el fitxer",
        $inserted,
        $updated,
        $ignored,
        $duplicates
    );

    header("Location: ../public/inventory.php");
    exit;

} catch (Throwable $e) {
    $_SESSION['import_message'] = "❌ Error en importar: " . htmlspecialchars($e->getMessage());
    header("Location: ../public/inventory.php");
    exit;
}
