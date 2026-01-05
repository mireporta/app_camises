<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../public/maintenance.php");
    exit;
}

$action = $_POST['action'] ?? '';

if ($action !== 'mark_bought') {
    header("Location: ../public/maintenance.php");
    exit;
}

$itemId = (int)($_POST['item_id'] ?? 0);
$qty = (int)($_POST['qty'] ?? 0);
$proveidor = trim((string)($_POST['proveidor'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($itemId <= 0) {
    header("Location: ../public/maintenance.php?tab=pendents&err=missing_item");
    exit;
}
if ($qty <= 0) {
    header("Location: ../public/maintenance.php?tab=pendents&err=qty");
    exit;
}
if ($proveidor === '') {
    header("Location: ../public/maintenance.php?tab=pendents&err=proveidor");
    exit;
}

// ✅ Comprovem que l’item existeix
$stmt = $pdo->prepare("SELECT id FROM items WHERE id = ?");
$stmt->execute([$itemId]);
if (!$stmt->fetchColumn()) {
    header("Location: ../public/maintenance.php?tab=pendents&err=item_not_found");
    exit;
}

// ✅ Inserim compra (historial)
$stmt = $pdo->prepare("
    INSERT INTO compres_recanvis (item_id, qty, proveidor, notes, created_at)
    VALUES (?, ?, ?, ?, NOW())
");
$stmt->execute([
    $itemId,
    $qty,
    $proveidor,
    ($notes === '' ? null : $notes),
]);

header("Location: ../public/maintenance.php?tab=pendents&ok=1");
exit;
