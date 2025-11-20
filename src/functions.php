<?php
// src/functions.php - funcions comunes (v2, amb item_units)

/**
 * Retorna tots els ítems amb estoc agregat des de item_units.
 * Camps retornats:
 *  - id
 *  - sku
 *  - category
 *  - min_stock
 *  - active
 *  - total_stock
 *  - qty_magatzem
 *  - qty_intermig
 *  - qty_maquina
 */
function find_all_items(PDO $pdo): array
{
    $sql = "
        SELECT 
            i.id,
            i.sku,
            i.category,
            i.min_stock,
            i.active,
            COALESCE(t.total_cnt, 0)     AS total_stock,
            COALESCE(g.cnt_magatzem, 0)  AS qty_magatzem,
            COALESCE(im.cnt_intermig, 0) AS qty_intermig,
            COALESCE(m.cnt_maquina, 0)   AS qty_maquina
        FROM items i
        LEFT JOIN (
            SELECT item_id, COUNT(*) AS total_cnt
            FROM item_units
            WHERE estat = 'actiu'
            GROUP BY item_id
        ) t ON t.item_id = i.id
        LEFT JOIN (
            SELECT item_id, COUNT(*) AS cnt_magatzem
            FROM item_units
            WHERE estat = 'actiu' AND ubicacio = 'magatzem'
            GROUP BY item_id
        ) g ON g.item_id = i.id
        LEFT JOIN (
            SELECT item_id, COUNT(*) AS cnt_intermig
            FROM item_units
            WHERE estat = 'actiu' AND ubicacio = 'intermig'
            GROUP BY item_id
        ) im ON im.item_id = i.id
        LEFT JOIN (
            SELECT item_id, COUNT(*) AS cnt_maquina
            FROM item_units
            WHERE estat = 'actiu' AND ubicacio = 'maquina'
            GROUP BY item_id
        ) m ON m.item_id = i.id
        ORDER BY i.sku ASC
    ";

    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function find_item_by_sku(PDO $pdo, string $sku)
{
    $stmt = $pdo->prepare('SELECT * FROM items WHERE sku = ?');
    $stmt->execute([$sku]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Funcions legacy de la v1
 * Ja no fem servir items.stock ni la taula operations.
 * Les deixem com a no-op o retornant buit per no rebentar si alguna crida antiga queda viva.
 */

function update_stock(PDO $pdo, int $item_id, int $delta): void
{
    // V2: l’estoc es calcula a partir de item_units. No fem res aquí.
    return;
}

function record_operation(PDO $pdo, $item_id, $type, $quantity, $source=null, $destination=null, $machine=null, $user=null, $reason=null): void
{
    // V2: substituït per la taula moviments. Aquesta funció queda buida.
    return;
}

function top_used_items(PDO $pdo, int $limit = 10): array
{
    // V2: aquesta lògica hauria d’anar contra moviments. De moment retornem array buit.
    return [];
}

function items_low_life(PDO $pdo): array
{
    // V2: ja no hi ha life_expectancy a items. La vida útil és per unitat.
    return [];
}

function items_below_min(PDO $pdo): array
{
    // V2: ja no hi ha camp stock a items. Es calcula a partir de item_units.
    return [];
}

function decommission_item(PDO $pdo, $sku, $quantity, $reason, $user='system'): bool
{
    // V2: la baixa es gestiona per unitats (item_units + moviments).
    return false;
}

function items_inactive(PDO $pdo): array
{
    // Només canvia l'ORDER BY: abans per name (ja no existeix), ara per sku.
    $stmt = $pdo->query('SELECT * FROM items WHERE active = 0 ORDER BY sku');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function decommission_stats_by_category(PDO $pdo): array
{
    // V2: la taula operations ja no existeix. Aquesta funció queda sense ús.
    return [];
}
