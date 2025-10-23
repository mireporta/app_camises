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

    if ($id <= 0 || !in_array($action, ['serveix', 'anula'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'BAD_REQUEST']);
        exit;
    }

    if ($action === 'serveix') {
        // 🔸 Comença transacció per seguretat
        $pdo->beginTransaction();

        // 1️⃣ Recuperar la petició
        $stmt = $pdo->prepare("SELECT sku, maquina FROM peticions WHERE id = ?");
        $stmt->execute([$id]);
        $peticio = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$peticio) {
            throw new RuntimeException('Petició no trobada');
        }

        // 2️⃣ Buscar l’item pel SKU
        $itemStmt = $pdo->prepare("SELECT id, stock FROM items WHERE sku = ?");
        $itemStmt->execute([$peticio['sku']]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new RuntimeException('Recanvi no trobat');
        }

        if ((int)$item['stock'] <= 0) {
            throw new RuntimeException('Stock insuficient al magatzem');
        }

        // 3️⃣ Restar 1 del magatzem principal
        $pdo->prepare("UPDATE items SET stock = stock - 1 WHERE id = ?")
            ->execute([$item['id']]);

        // 4️⃣ Assignar recanvi a la màquina (si no hi és)
        $check = $pdo->prepare("SELECT COUNT(*) FROM maquina_items WHERE maquina = ? AND item_id = ?");
        $check->execute([$peticio['maquina'], $item['id']]);
        $exists = $check->fetchColumn();

        if (!$exists) {
            $pdo->prepare("
                INSERT INTO maquina_items (maquina, item_id, vida_acumulada)
                VALUES (?, ?, 0)
            ")->execute([$peticio['maquina'], $item['id']]);
        }

        // 5️⃣ Marcar la petició com servida
        $pdo->prepare("UPDATE peticions SET estat = 'servida', updated_at = NOW() WHERE id = ?")
            ->execute([$id]);

        // 6️⃣ Registrar moviment per traçabilitat
        $pdo->prepare("
            INSERT INTO moviments (item_id, tipus, quantitat, ubicacio, maquina)
            VALUES (?, 'sortida', 1, 'MAG01', ?)
        ")->execute([$item['id'], $peticio['maquina']]);

        // 7️⃣ Confirmar canvis
        $pdo->commit();
    } 
    else {
        // ANUL·LAR PETICIÓ
        $stmt = $pdo->prepare("UPDATE peticions SET estat = 'anulada', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
