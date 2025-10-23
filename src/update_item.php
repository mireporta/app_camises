<?php
require_once(__DIR__ . "/config.php");

$id = $_POST['id'] ?? null;
$name = $_POST['name'] ?? null;
$min_stock = $_POST['min_stock'] ?? null;
$life = $_POST['life_expectancy'] ?? null;
$location = $_POST['location'] ?? null;

if (!$id || !$name) {
    die("❌ Error: dades incompletes.");
}

$planFileName = null;

// 📁 Si s'ha pujat un fitxer PDF nou
if (isset($_FILES['plan_file']) && $_FILES['plan_file']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . "/../public/uploads/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileTmp = $_FILES['plan_file']['tmp_name'];
    $fileName = uniqid('plan_') . '.pdf';
    $destination = $uploadDir . $fileName;

    if (move_uploaded_file($fileTmp, $destination)) {
        $planFileName = $fileName;
    }
}

// 🧠 Construïm la consulta dinàmicament segons si hi ha PDF nou
if ($planFileName) {
    $sql = "UPDATE items 
            SET name = ?, 
                min_stock = ?, 
                life_expectancy = ?, 
                location = ?, 
                plan_file = ?, 
                updated_at = NOW() 
            WHERE id = ?";
    $params = [$name, $min_stock, $life, $location, $planFileName, $id];
} else {
    $sql = "UPDATE items 
            SET name = ?, 
                min_stock = ?, 
                life_expectancy = ?, 
                location = ?, 
                updated_at = NOW() 
            WHERE id = ?";
    $params = [$name, $min_stock, $life, $location, $id];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

// ✅ Redirigir de nou a la pàgina d’inventari
header("Location: ../public/inventory.php");
exit;
