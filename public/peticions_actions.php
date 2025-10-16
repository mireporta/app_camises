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
        // 1️⃣ Marcar la petició com servida
        $stmt = $pdo->prepare("UPDATE peticions SET estat = 'servida', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            // 2️⃣ Recuperar SKU i màquina de la petició
            $infoStmt = $pdo->prepare("SELECT sku, maquina FROM peticions WHERE id = ?");
            $infoStmt->execute([$id]);
            $peticio = $infoStmt->fetch(PDO::FETCH_ASSOC);

            if ($peticio) {
                // 3️⃣ Buscar item_id pel SKU
                $itemStmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
                $itemStmt->execute([$peticio['sku']]);
                $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

                if ($item) {
                    // 4️⃣ Verificar si ja existeix a maquina_items
                    $check = $pdo->prepare("SELECT COUNT(*) FROM maquina_items WHERE maquina = ? AND item_id = ?");
                    $check->execute([$peticio['maquina'], $item['id']]);
                    $exists = $check->fetchColumn();

                    if (!$exists) {
                        // 5️⃣ Inserir la relació màquina ↔ item
                        $insert = $pdo->prepare("INSERT INTO maquina_items (maquina, item_id) VALUES (?, ?)");
                        $insert->execute([$peticio['maquina'], $item['id']]);
                    }
                }
            }
        }
    } else {
        // ANUL·LAR PETICIÓ
        $stmt = $pdo->prepare("UPDATE peticions SET estat = 'anulada', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
    }

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'NOT_FOUND']);
        exit;
    }

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
