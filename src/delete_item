<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'BAD_REQUEST']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1) Baixa lògica de l’ítem (no l’esborrem)
    $stmt = $pdo->prepare("UPDATE items SET active = 0, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    // 2) Les unitats físiques d’aquest ítem deixen de comptar (estat='baixat'),
    //    i per coherència, les deslliguem de qualsevol màquina
    $stmt2 = $pdo->prepare("
        UPDATE item_units
        SET estat = 'baixat',
            ubicacio = 'magatzem',
            maquina_actual = NULL,
            updated_at = NOW()
        WHERE item_id = ?
    ");
    $stmt2->execute([$id]);

    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
