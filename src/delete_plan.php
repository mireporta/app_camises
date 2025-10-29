<?php
require_once("../src/config.php");
header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Falta ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT plan_file FROM items WHERE id = ?");
    $stmt->execute([$id]);
    $file = $stmt->fetchColumn();

    if ($file && file_exists(__DIR__ . '/../public/uploads/' . $file)) {
        unlink(__DIR__ . '/../public/uploads/' . $file);
    }

    $pdo->prepare("UPDATE items SET plan_file = NULL WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
