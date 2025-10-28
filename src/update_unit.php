<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $sububicacio = trim($_POST['sububicacio'] ?? '');
    $vida_total = isset($_POST['vida_total']) ? (int)$_POST['vida_total'] : null;

    if ($id > 0) {
        $fields = [];
        $params = [];

        if ($sububicacio !== '') {
            $fields[] = "sububicacio = ?";
            $params[] = $sububicacio;
        }
        if ($vida_total !== null) {
            $fields[] = "vida_total = ?";
            $params[] = $vida_total;
        }

        if (!empty($fields)) {
            $sql = "UPDATE item_units SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
            $params[] = $id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        header("Location: ../public/inventory.php?msg=unit_updated");
        exit;
    } else {
        echo "❌ Error: Falta l'ID de la unitat.";
    }
}
