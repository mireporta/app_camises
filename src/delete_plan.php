<?php
require_once("config.php");

if (!isset($_POST['id'])) {
    http_response_code(400);
    exit('Falta ID');
}

$id = intval($_POST['id']);

// Obtenim el nom del fitxer actual
$stmt = $pdo->prepare("SELECT plan_file FROM items WHERE id = ?");
$stmt->execute([$id]);
$planFile = $stmt->fetchColumn();

if ($planFile) {
    $filePath = "../public/uploads/" . $planFile;
    if (file_exists($filePath)) {
        unlink($filePath); // 🗑️ Esborra el PDF físic
    }

    // Neteja el camp a la BD
    $update = $pdo->prepare("UPDATE items SET plan_file = NULL WHERE id = ?");
    $update->execute([$id]);
}

http_response_code(200);
