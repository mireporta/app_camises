<?php
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

require_once("../src/config.php");
header('Content-Type: application/json; charset=utf-8');

if (!isset($pdo) || !$pdo instanceof PDO) {
    echo json_encode(['success' => false, 'error' => 'Error: connexió PDO no disponible']);
    exit;
}

$id       = $_POST['id'] ?? null;
$action   = $_POST['action'] ?? '';
$unit_id  = $_POST['unit_id'] ?? null;

if (!$id || !$action) {
    echo json_encode(['success' => false, 'error' => 'Falten dades.']);
    exit;
}

try {
    if ($action === 'serveix') {
        // Serveix petició
        $stmt = $pdo->prepare("SELECT sku, maquina, estat FROM peticions WHERE id = ?");
        $stmt->execute([$id]);
        $peticio = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$peticio) {
            throw new Exception('Petició no trobada.');
        }
        if ($peticio['estat'] !== 'pendent') {
            throw new Exception('La petició ja està gestionada.');
        }

        // Obtenim unitat disponible per al SKU
        $stmt = $pdo->prepare("
            SELECT iu.id, iu.item_id, iu.estat, iu.ubicacio, iu.sububicacio, i.sku
            FROM item_units iu
            JOIN items i ON i.id = iu.item_id
            WHERE iu.id = ? AND i.sku = ?
        ");
        $stmt->execute([$unit_id, $peticio['sku']]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$unit) {
            throw new Exception('Unitat no vàlida per aquest SKU.');
        }

        // Validacions extra: ha d'estar al magatzem i activa
        if ($unit['estat'] !== 'actiu') {
            throw new Exception('La unitat no està activa.');
        }
        if ($unit['ubicacio'] !== 'magatzem') {
            throw new Exception('La unitat no és al magatzem.');
        }

        // 🔹 MAGATZEM → PREPARACIÓ (no sumem cicles, no compta com a producció)
        $update = $pdo->prepare("
            UPDATE item_units
            SET ubicacio = 'preparacio',
                maquina_actual = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$peticio['maquina'], $unit_id]);

        // 🔹 Actualitza estat de la petició
        $pdo->prepare("UPDATE peticions SET estat='servida', updated_at=NOW() WHERE id=?")
            ->execute([$id]);

        // 🔹 Registra moviment (sortida cap a PREPARACIÓ)
        if ($pdo->query("SHOW TABLES LIKE 'moviments'")->rowCount() > 0) {
            $mov = $pdo->prepare("
                INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
                VALUES (?, ?, 'sortida', 1, 'preparacio', ?, NOW())
            ");
            $mov->execute([$unit_id, $unit['item_id'], $peticio['maquina']]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    elseif ($action === 'anula') {
        $upd = $pdo->prepare("UPDATE peticions SET estat='anulada', updated_at=NOW() WHERE id=?");
        $upd->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    else {
        echo json_encode(['success' => false, 'error' => 'Acció no reconeguda.']);
        exit;
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
