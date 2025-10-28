<?php
require_once("config.php");
header('Content-Type: application/json');

$id       = $_POST['id'] ?? null;
$action   = $_POST['action'] ?? '';
$unit_id  = $_POST['unit_id'] ?? null;

if (!$id || !$action) {
    echo json_encode(['success' => false, 'error' => 'Falten dades.']);
    exit;
}

try {
    if ($action === 'serveix') {
        // 🔹 Obtenim info petició
        $stmt = $pdo->prepare("SELECT sku, maquina FROM peticions WHERE id = ?");
        $stmt->execute([$id]);
        $peticio = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$peticio) throw new Exception('Petició no trobada.');

        // 🔹 Obtenim info unitat
        $stmt = $pdo->prepare("
            SELECT iu.id, iu.item_id, iu.estat, iu.ubicacio, iu.sububicacio
            FROM item_units iu
            JOIN items i ON i.id = iu.item_id
            WHERE iu.id = ? AND i.sku = ?
        ");
        $stmt->execute([$unit_id, $peticio['sku']]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$unit) throw new Exception('Unitat no vàlida per aquest SKU.');

        // 🔹 Actualitzem la unitat → ubicació = màquina
        $update = $pdo->prepare("
            UPDATE item_units
            SET ubicacio = 'maquina',
                maquina_actual = ?,
                sububicacio = NULL,
                updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$peticio['maquina'], $unit_id]);

        // 🔹 Actualitzem la petició com “servida”
        $updPet = $pdo->prepare("UPDATE peticions SET estat='servida', updated_at=NOW() WHERE id=?");
        $updPet->execute([$id]);

        // 🔹 Registre del moviment (si tens la taula `moviments`)
        if ($pdo->query("SHOW TABLES LIKE 'moviments'")->rowCount() > 0) {
            $mov = $pdo->prepare("
                INSERT INTO moviments (item_unit_id, tipus, origen, desti, maquina, created_at)
                VALUES (?, 'servei', ?, 'maquina', ?, NOW())
            ");
            $mov->execute([$unit_id, $unit['ubicacio'], $peticio['maquina']]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    elseif ($action === 'anula') {
        // 🔸 Anul·lem la petició
        $upd = $pdo->prepare("UPDATE peticions SET estat='anulada', updated_at=NOW() WHERE id=?");
        $upd->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    else {
        echo json_encode(['success' => false, 'error' => 'Acció no reconeguda.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
