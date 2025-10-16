<?php
require_once("config.php");

$id = $_POST['id'];
$name = $_POST['name'];
$stock = $_POST['stock'];
$min_stock = $_POST['min_stock'];
$life = $_POST['life_expectancy'];

$planFileName = null;

if (isset($_FILES['plan_file']) && $_FILES['plan_file']['error'] === UPLOAD_ERR_OK) {
    $fileTmp = $_FILES['plan_file']['tmp_name'];
    $fileName = uniqid('plan_') . '.pdf';
    $destination = "../public/uploads/" . $fileName;

    if (move_uploaded_file($fileTmp, $destination)) {
        $planFileName = $fileName;
    }
}

if ($planFileName) {
    // Si s'ha pujat un PDF nou → actualitza també aquesta columna
    $stmt = $pdo->prepare("UPDATE items SET name=?, stock=?, min_stock=?, life_expectancy=?, plan_file=? WHERE id=?");
    $stmt->execute([$name, $stock, $min_stock, $life, $planFileName, $id]);
} else {
    // Sense PDF → només actualitza la resta
    $stmt = $pdo->prepare("UPDATE items SET name=?, stock=?, min_stock=?, life_expectancy=? WHERE id=?");
    $stmt->execute([$name, $stock, $min_stock, $life, $id]);
}

header("Location: ../public/inventory.php");
exit;
