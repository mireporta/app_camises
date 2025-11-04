<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $sububicacio = trim($_POST['sububicacio'] ?? '');
    $vida_total = isset($_POST['vida_total']) ? (int)$_POST['vida_total'] : null;

    if (isset($_POST['action']) && $_POST['action'] === 'baixa_unitat') {
    $id = (int)($_POST['id'] ?? 0);
    $motiu = trim($_POST['baixa_motiu'] ?? '');

    if ($id > 0 && $motiu !== '') {
        $pdo->prepare("
            UPDATE item_units
            SET estat = 'baixa',
                baixa_motiu = ?,
                ubicacio = NULL,
                sububicacio = NULL,
                maquina_actual = NULL,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$motiu, $id]);

        $pdo->prepare("
            INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
            SELECT iu.id, iu.item_id, 'baixa', 1, 'magatzem', ?, NOW()
            FROM item_units iu WHERE iu.id = ?
        ")->execute([$motiu, $id]);

        header("Location: ../public/inventory.php?msg=unit_baixa");
        exit;
    }
}


    if ($id <= 0) {
        echo "❌ Error: Falta l'ID de la unitat.";
        exit;
    }

    /* 🗑️ Donar de baixa una unitat */
    if ($action === 'baixa_unitat') {
        // Obtenim les dades de la unitat abans de modificar res
        $stmt = $pdo->prepare("SELECT item_id, ubicacio FROM item_units WHERE id = ?");
        $stmt->execute([$id]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($unit) {
            // Registrar moviment de baixa
            $pdo->prepare("
                INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
                VALUES (?, ?, 'baixa', 1, ?, 'DESCARTAT', NOW())
            ")->execute([$id, $unit['item_id'], $unit['ubicacio'] ?? 'magatzem']);

            // Actualitzar estat i netejar camps
            $pdo->prepare("
                UPDATE item_units
                SET estat = 'baixa',
                    ubicacio = NULL,
                    sububicacio = NULL,
                    maquina_actual = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$id]);
        }

        header("Location: ../public/inventory.php?msg=unit_deleted");
        exit;
    }

    /* ✏️ Actualitzar sububicació / vida útil */
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
}
