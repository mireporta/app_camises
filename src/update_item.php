<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id        = (int)($_POST['id'] ?? 0);
    $category  = trim($_POST['category'] ?? '');
    $min_stock = isset($_POST['min_stock']) ? (int)$_POST['min_stock'] : 0;
    $plan_file = null;

    if ($id <= 0) {
        die("❌ Error: falta ID d'ítem.");
    }
//Pujar plànol
if (!empty($_FILES['plan_file']['name'])) {

    // Validar extensions segures
    $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg'];
    $ext = strtolower(pathinfo($_FILES['plan_file']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExtensions)) {
        die("Tipus de fitxer no permès.");
    }

    $uploadDir = __DIR__ . '/../public/uploads/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Crear nom segur
    $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $_FILES['plan_file']['name']);
    $targetPath = $uploadDir . $fileName;

    // Moure arxiu pujat
    if (move_uploaded_file($_FILES['plan_file']['tmp_name'], $targetPath)) {
        $plan_file = $fileName;
    }
}


    // 🛠 Construïm l’UPDATE
    $fields = [];
    $params = [];

    // Categoria
    $fields[] = "category = ?";
    $params[] = $category;

    // Estoc mínim
    $fields[] = "min_stock = ?";
    $params[] = $min_stock;

    // Plànol (si n’hi ha de nou)
    if ($plan_file !== null) {
        $fields[] = "plan_file = ?";
        $params[] = $plan_file;
    }

    $sql = "UPDATE items SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
    $params[] = $id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header("Location: ../public/inventory.php?msg=item_updated");
    exit;
}

// ❌ Ja no gestionem restaurar_unitat ni baixa_unitat aquí.
// Això es fa a update_unit.php / decommission.php.
