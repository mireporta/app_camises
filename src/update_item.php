<?php
require_once __DIR__ . "/config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: ../public/inventory.php");
  exit;
}

$id         = (int)($_POST['id'] ?? 0);
$name       = trim($_POST['name'] ?? '');
$min_stock  = (int)($_POST['min_stock'] ?? 0);

if ($id <= 0) {
  header("Location: ../public/inventory.php");
  exit;
}

$planFileName = null;

// ✅ Si s'ha pujat un plànol nou (PDF)
if (!empty($_FILES['plan_file']['name']) && $_FILES['plan_file']['error'] === UPLOAD_ERR_OK) {
  $tmp  = $_FILES['plan_file']['tmp_name'];
  $ext  = strtolower(pathinfo($_FILES['plan_file']['name'], PATHINFO_EXTENSION));
  if ($ext === 'pdf') {
    $uploads = realpath(__DIR__ . '/../public/uploads');
    if (!$uploads) {
      $uploads = __DIR__ . '/../public/uploads';
      @mkdir($uploads, 0775, true);
    }
    $planFileName = 'plan_' . uniqid() . '.pdf';
    move_uploaded_file($tmp, $uploads . '/' . $planFileName);
  }
}

// 🔹 Si hi ha plànol nou, s'actualitza també `plan_file`
if ($planFileName) {
  $sql = "UPDATE items 
          SET name = ?, min_stock = ?, plan_file = ?
          WHERE id = ?";
  $params = [$name, $min_stock, $planFileName, $id];
} else {
  $sql = "UPDATE items 
          SET name = ?, min_stock = ?
          WHERE id = ?";
  $params = [$name, $min_stock, $id];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

header("Location: ../public/inventory.php");
exit;

