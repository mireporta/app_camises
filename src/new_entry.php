

<?php
/**
 * Registrar entrada d'una unitat al magatzem
 * Serveix tant per:
 * - entrada manual
 * - recepció d'una compra
 */

function newEntry(
    PDO $pdo,
    int $itemId,
    string $serial,
    ?string $sububicacio,
    string $origen,
    int $vida_total = 0,
    ?int $compraId = null
): array {

    $serial = trim($serial);
    $sububicacio = $sububicacio !== null ? trim($sububicacio) : null;
    if ($sububicacio === '') $sububicacio = null;

    $pdo->beginTransaction();

    try {
        // 1️⃣ Serial únic
        $stmt = $pdo->prepare("SELECT 1 FROM item_units WHERE serial = ?");
        $stmt->execute([$serial]);
        if ($stmt->fetch()) {
            throw new Exception("⚠️ Ja existeix una unitat amb aquest serial.");
        }

        // 2️⃣ Validar posició (si informada)
        if ($sububicacio) {
            $stmt = $pdo->prepare("SELECT item_unit_id FROM magatzem_posicions WHERE codi = ?");
            $stmt->execute([$sububicacio]);
            $pos = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pos) {
                throw new Exception("❌ La posició '$sububicacio' no existeix.");
            }
            if ($pos['item_unit_id'] !== null) {
                throw new Exception("❌ La posició '$sububicacio' ja està ocupada.");
            }
        }

        // 3️⃣ Crear unitat

        // Si no s'informa vida_total, agafem el valor per defecte del SKU (items)
        if ($vida_total <= 0) {
            $stmt = $pdo->prepare("SELECT vida_total_default FROM items WHERE id = ?");
            $stmt->execute([$itemId]);
            $vida_total = (int)($stmt->fetchColumn() ?? 0);
        }

        $stmt = $pdo->prepare("
            INSERT INTO item_units
                (item_id, serial, ubicacio, sububicacio, estat,
                vida_utilitzada, vida_total, created_at, updated_at)
            VALUES
                (?, ?, 'magatzem', ?, 'actiu',
                0, ?, NOW(), NOW())
        ");
        $stmt->execute([$itemId, $serial, $sububicacio, $vida_total]);

        $unitId = (int)$pdo->lastInsertId();


        // 4️⃣ Assignar posició al mapa
        if ($sububicacio) {
            $stmt = $pdo->prepare("
                UPDATE magatzem_posicions
                SET item_unit_id = ?
                WHERE codi = ? AND item_unit_id IS NULL
            ");
            $stmt->execute([$unitId, $sububicacio]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("❌ Error assignant la posició '$sububicacio'.");
            }
        }

        // 5️⃣ Moviment
        $stmt = $pdo->prepare("
            INSERT INTO moviments
                (item_id, item_unit_id, tipus, quantitat, ubicacio, maquina, created_at)
            VALUES
                (?, ?, 'entrada', 1, 'magatzem', ?, NOW())
        ");
        $stmt->execute([$itemId, $unitId, $origen]);

        // 6️⃣ Actualitzar stock
        $stmt = $pdo->prepare("UPDATE items SET stock = stock + 1 WHERE id = ?");
        $stmt->execute([$itemId]);

        // 7️⃣ Vincular a compra (opcional)
        if ($compraId !== null) {

            // Taula pont (si existeix)
            $stmt = $pdo->prepare("
                INSERT INTO compres_recanvis_units (compra_id, item_unit_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$compraId, $unitId]);

            // Actualitzar estat compra
            $stmt = $pdo->prepare("
                UPDATE compres_recanvis
                SET qty_entrada = qty_entrada + 1,
                    estat = CASE
                        WHEN qty_entrada + 1 >= qty THEN 'rebuda'
                        ELSE 'parcial'
                    END
                WHERE id = ?
            ");
            $stmt->execute([$compraId]);
        }

        $pdo->commit();
        return [
            'ok'      => true,
            'unit_id'=> $unitId
        ];

    } catch (Throwable $e) {
        $pdo->rollBack();
        return [
            'ok'    => false,
            'error' => $e->getMessage()
        ];
    }
}
