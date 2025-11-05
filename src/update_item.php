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

if (isset($_POST['action']) && $_POST['action'] === 'restaurar_unitat') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        // Actualitza estat a actiu
        $pdo->prepare("
            UPDATE item_units
            SET estat = 'actiu',
                baixa_motiu = NULL,
                updated_at = NOW(),
                ubicacio = 'magatzem'
            WHERE id = ?
        ")->execute([$id]);

        // Registra moviment
        $pdo->prepare("
            INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
            SELECT iu.id, iu.item_id, 'restauracio', 1, 'magatzem', 'BAIXA_RESTAURADA', NOW()
            FROM item_units iu WHERE iu.id = ?
        ")->execute([$id]);

        header("Location: ../public/decommission.php?msg=unit_restored");
        exit;
    }
}
if (isset($_POST['action']) && $_POST['action'] === 'baixa_unitat') {
    $id = (int)($_POST['id'] ?? 0);
    $motiu = trim($_POST['baixa_motiu'] ?? '');

    if ($id > 0) {
        // Si no arriba motiu, assignem un per defecte
        if ($motiu === '') $motiu = 'altres';

        $pdo->prepare("
            UPDATE item_units
            SET estat = 'inactiu',
                baixa_motiu = ?,
                ubicacio = NULL,
                sububicacio = NULL,
                maquina_actual = NULL,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$motiu, $id]);

        // Registra el moviment
        $pdo->prepare("
            INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
            SELECT iu.id, iu.item_id, 'inactiu', 1, 'magatzem', ?, NOW()
            FROM item_units iu WHERE iu.id = ?
        ")->execute([$motiu, $id]);

        header("Location: ../public/inventory.php?msg=unit_baixa");
        exit;
    }
}
