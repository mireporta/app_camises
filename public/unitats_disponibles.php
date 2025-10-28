<?php
require_once("config.php");
header('Content-Type: application/json');

$sku = $_GET['sku'] ?? '';
if (!$sku) {
    echo json_encode(['success' => false, 'error' => 'SKU no indicat']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT iu.id, iu.serial, iu.ubicacio, iu.sububicacio
        FROM item_units iu
        JOIN items i ON i.id = iu.item_id
        WHERE i.sku = ? 
          AND iu.estat = 'actiu'
          AND iu.ubicacio IN ('magatzem', 'intermig')
        ORDER BY iu.ubicacio, iu.serial ASC
    ");
    $stmt->execute([$sku]);
    $unitats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'unitats' => $unitats]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
