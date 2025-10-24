<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . '/../src/config.php';

    $sku = $_GET['sku'] ?? '';
    if ($sku === '') {
        echo json_encode(['success' => false, 'error' => 'Falta el paràmetre SKU']);
        exit;
    }

    // Buscar l’item principal
    $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
    $stmt->execute([$sku]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Recanvi no trobat']);
        exit;
    }

    // Buscar unitats disponibles (estat=actiu, ubicació=magatzem o intermig)
    $sql = "
        SELECT id, serial, ubicacio
        FROM item_units
        WHERE item_id = ? AND estat = 'actiu' AND ubicacio IN ('magatzem', 'intermig')
        ORDER BY serial ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$item['id']]);
    $unitats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'unitats' => $unitats
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
