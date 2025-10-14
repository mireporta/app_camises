<?php
require_once __DIR__ . '/../src/functions.php';
header('Content-Type: application/json');
$action = $_GET['action'] ?? null;
if($action==='find_sku'){
  $sku = $_GET['sku'] ?? '';
  $stmt = $pdo->prepare('SELECT * FROM items WHERE sku = ?');
  $stmt->execute([$sku]);
  echo json_encode($stmt->fetch() ?: []);
  exit;
}
echo json_encode(['ok'=>false]);
?>
