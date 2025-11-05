<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $sububicacio = trim($_POST['sububicacio'] ?? '');
    $vida_total = isset($_POST['vida_total']) ? (int)$_POST['vida_total'] : null;

    /* ♻️ Restaurar una unitat donada de baixa */
    if ($action === 'restaurar_unitat') {
        $nova_sububicacio = trim($_POST['sububicacio'] ?? '');
        if ($nova_sububicacio === '') {
            echo "❌ Error: Cal indicar una sububicació per restaurar la unitat.";
            exit;
        }

        $stmt = $pdo->prepare("SELECT item_id, estat, ubicacio, maquina_baixa FROM item_units WHERE id = ?");
        $stmt->execute([$id]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$unit) {
            echo "❌ Error: Unitat no trobada.";
            exit;
        }
        if ($unit['estat'] !== 'inactiu') {
            echo "❌ Error: Només es poden restaurar unitats en baixa (inactiu).";
            exit;
        }

        // Registre del moviment de retorn a magatzem
        $pdo->prepare("
            INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, observacions, created_at)
            VALUES (?, ?, 'retorn', 1, 'magatzem', ?, 'Restaurada d\\'una baixa', NOW())
        ")->execute([
            $id,
            (int)$unit['item_id'],
            $unit['maquina_baixa'] ?? null
        ]);

        // Actualitzar la unitat a activa
        $pdo->prepare("
            UPDATE item_units
            SET estat = 'actiu',
                ubicacio = 'magatzem',
                sububicacio = :sub,
                maquina_actual = NULL,
                updated_at = NOW()
            WHERE id = :id
        ")->execute([
            ':sub' => $nova_sububicacio,
            ':id'  => $id
        ]);

        header("Location: ../public/inventory.php?msg=unit_restored");
        exit;
    }

    /* ❌ Validació bàsica d’ID */
    if ($id <= 0) {
        echo "❌ Error: Falta l'ID de la unitat.";
        exit;
    }

    /* 🗑️ Donar de baixa una unitat */
    if ($action === 'baixa_unitat') {
        $motiu = trim($_POST['baixa_motiu'] ?? '');

        if ($motiu === '') {
            echo "❌ Error: Falta el motiu de la baixa.";
            exit;
        }

        // Obtenim dades actuals
        $stmt = $pdo->prepare("SELECT item_id, ubicacio, maquina_actual FROM item_units WHERE id = ?");
        $stmt->execute([$id]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($unit) {
            // Registrar moviment de baixa
            $pdo->prepare("
                INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, observacions, created_at)
                VALUES (?, ?, 'baixa', 1, ?, ?, ?, NOW())
            ")->execute([
                $id,
                $unit['item_id'],
                $unit['ubicacio'] ?? 'magatzem',
                $unit['maquina_actual'] ?? null,
                $motiu
            ]);

            // Actualitzar estat i netejar camps
            $pdo->prepare("
                UPDATE item_units
                SET estat = 'inactiu',
                    baixa_motiu = :motiu,
                    maquina_baixa = :maquina_baixa,
                    maquina_actual = NULL,
                    ubicacio = 'baixa',
                    sububicacio = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ")->execute([
                ':motiu' => $motiu,
                ':maquina_baixa' => $unit['maquina_actual'],
                ':id' => $id
            ]);
        }

        header("Location: ../public/inventory.php?msg=unit_baixa");
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
