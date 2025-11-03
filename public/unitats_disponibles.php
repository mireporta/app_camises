<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/../src/config.php'; // 👈 ajusta si el fitxer és a public/
  if (!isset($pdo) || !$pdo) {
    throw new RuntimeException('PDO no inicialitzat');
  }

  $sku = trim($_GET['sku'] ?? '');
  if ($sku === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Falta SKU']);
    exit;
  }

  // Trobem l'item
  $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ? AND active = 1");
  $stmt->execute([$sku]);
  $itemId = $stmt->fetchColumn();
  if (!$itemId) {
    echo json_encode(['success' => true, 'unitats' => []]); // cap disponible
    exit;
  }

  // Unitats disponibles per servir: actives i que no estan a "maquina"
  $units = $pdo->prepare("
    SELECT iu.id, iu.serial, iu.ubicacio, iu.sububicacio
    FROM item_units iu
    WHERE iu.estat = 'actiu'
      AND iu.item_id = ?
      AND iu.ubicacio IN ('magatzem', 'intermig')
    ORDER BY iu.ubicacio ASC, iu.serial ASC
  ");
  $units->execute([$itemId]);
  $rows = $units->fetchAll(PDO::FETCH_ASSOC);

  // formatar ubicació (ex. MAG01 + sububicació si n’hi ha)
  $payload = array_map(function($r) {
    $ubi = $r['ubicacio'];
    if ($ubi === 'magatzem') {
      $txt = 'MAG01';
      if (!empty($r['sububicacio'])) $txt .= ' - ' . $r['sububicacio'];
    } elseif ($ubi === 'intermig') {
      $txt = 'INTERMIG';
    } else {
      $txt = ucfirst($ubi);
    }
    return [
      'id' => (int)$r['id'],
      'serial' => $r['serial'],
      'ubicacio' => $txt,
    ];
  }, $rows);

  echo json_encode(['success' => true, 'unitats' => $payload]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
