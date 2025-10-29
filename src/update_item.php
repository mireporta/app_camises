<?php
require_once("../src/config.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $min_stock = (int)($_POST['min_stock'] ?? 0);
    $plan_file = null;

    if ($id <= 0) {
        die("Error: falta ID");
    }

    // Comprovem si s’ha pujat un nou fitxer
    if (!empty($_FILES['plan_file']['name'])) {
        $uploadDir = __DIR__ . '/../public/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $fileName = time() . '_' . basename($_FILES['plan_file']['name']);
        $targetPath = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['plan_file']['tmp_name'], $targetPath)) {
            $plan_file = $fileName;
        }
    }

    // Actualitzar registre
    $sql = "UPDATE items SET name=?, category=?, min_stock=?, updated_at=NOW()";
    $params = [$name, $category, $min_stock];

    if ($plan_file) {
        $sql .= ", plan_file=?";
        $params[] = $plan_file;
    }

    $sql .= " WHERE id=?";
    $params[] = $id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header("Location: ../public/inventory.php?msg=item_updated");
    exit;
}
