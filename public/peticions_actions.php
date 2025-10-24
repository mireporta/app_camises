<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../src/config.php';
    if (!isset($pdo) || !$pdo) {
        throw new RuntimeException('PDO no inicialitzat');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
        exit;
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $action = $_POST['action'] ?? '';
    $unitId = isset($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;

    if ($id <= 0 || !in_array($action, ['serveix', 'anula'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'BAD_REQUEST']);
        exit;
    }

    if ($action === 'serveix') {
        if (!$unitId) {
            throw new RuntimeException('Falta el camp unit_id');
        }

        $pdo->beginTransaction();

        // 1️⃣ Recuperar la petició
        $stmt = $pdo->prepare("SELECT sku, maquina FROM peticions WHERE id = ?");
        $stmt->execute([$id]);
        $peticio = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$peticio) {
            throw new RuntimeException('Petició no trobada');
        }

        // 2️⃣ Obtenir la unitat física
        $unitStmt = $pdo->prepare("
            SELECT iu.*, i.sku, i.name 
            FROM item_units iu
            JOIN items i ON i.id = iu.item_id
            WHERE iu.id = ? AND iu.estat = 'actiu'
        ");
        $unitStmt->execute([$unitId]);
        $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);

        if (!$unit) {
            throw new RuntimeException('Unitat no trobada o no disponible');
        }

        // 3️⃣ Assignar la unitat a la màquina
        $assign = $pdo->prepare("
            UPDATE item_units
            SET ubicacio = 'maquina',
                maquina = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $assign->execute([$peticio['maquina'], $unitId]);

        // 4️⃣ Actualitzar estat de la petició
        $pdo->prepare("
            UPDATE peticions
            SET estat = 'servida', updated_at = NOW()
            WHERE id = ?
        ")->execute([$id]);

        // 5️⃣ Registrar moviment
        $pdo->prepare("
            INSERT INTO moviments (item_id, item_unit_id, tipus, quantitat, ubicacio, maquina, created_at)
            VALUES (?, ?, 'sortida', 1, 'magatzem', ?, NOW())
        ")->execute([$unit['item_id'], $unitId, $peticio['maquina']]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Unitat assignada correctament']);
        exit;
    }

    // 🔴 ANUL·LAR PETICIÓ
    if ($action === 'anula') {
        $stmt = $pdo->prepare("
            UPDATE peticions
            SET estat = 'anulada', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Petició anul·lada']);
        exit;
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
