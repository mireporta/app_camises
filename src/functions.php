<?php
// src/functions.php - funcions comunes
function find_all_items($pdo) {
    $stmt = $pdo->query("
        SELECT 
            i.id,
            i.sku,
            i.name,
            i.category,
            i.location,
            i.stock,
            i.min_stock,
            i.life_expectancy,
            COALESCE(i.vida_utilitzada, 0) AS vida_utilitzada,
            i.active
        FROM items i
        ORDER BY i.sku ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function find_item_by_sku($pdo, $sku) {
    $stmt = $pdo->prepare('SELECT * FROM items WHERE sku = ?');
    $stmt->execute([$sku]);
    return $stmt->fetch();
}

function update_stock($pdo, $item_id, $delta) {
    $stmt = $pdo->prepare('UPDATE items SET stock = GREATEST(0, stock + ?) WHERE id = ?');
    $stmt->execute([$delta, $item_id]);
}

function record_operation($pdo, $item_id, $type, $quantity, $source=null, $destination=null, $machine=null, $user=null, $reason=null) {
    $stmt = $pdo->prepare('INSERT INTO operations (item_id, type, quantity, source, destination, machine, created_by, reason) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$item_id, $type, $quantity, $source, $destination, $machine, $user, $reason]);
}

function top_used_items($pdo, $limit = 10) {
    $sql = 'SELECT i.*, COALESCE(SUM(o.quantity),0) as used FROM items i LEFT JOIN operations o ON o.item_id = i.id AND o.type = "exit" GROUP BY i.id ORDER BY used DESC LIMIT ?';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function items_low_life($pdo) {
    $sql = 'SELECT *, (stock / NULLIF(life_expectancy,0)) AS life_ratio FROM items WHERE life_expectancy > 0 AND (stock / life_expectancy) <= 0.10 ORDER BY life_ratio ASC';
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

function items_below_min($pdo) {
    $stmt = $pdo->query('SELECT * FROM items WHERE stock < min_stock ORDER BY (min_stock - stock) DESC');
    return $stmt->fetchAll();
}

function decommission_item($pdo, $sku, $quantity, $reason, $user='system') {
    $stmt = $pdo->prepare('SELECT * FROM items WHERE sku = ?');
    $stmt->execute([$sku]);
    $item = $stmt->fetch();
    if(!$item) return false;
    $quantity = min($item['stock'], $quantity);
    update_stock($pdo, $item['id'], -$quantity);
    record_operation($pdo, $item['id'], 'decommission', $quantity, null, null, null, $user, $reason);
    if($item['stock'] - $quantity <= 0) {
        $pdo->prepare('UPDATE items SET active=0 WHERE id=?')->execute([$item['id']]);
    }
    return true;
}

function items_inactive($pdo){
    $stmt = $pdo->query('SELECT * FROM items WHERE active=0 ORDER BY name');
    return $stmt->fetchAll();
}

function decommission_stats_by_category($pdo){
    $sql = 'SELECT IFNULL(i.category, "(sense categoria)") as category, COUNT(o.id) as num_baixes, SUM(o.quantity) as total_unitats
            FROM operations o JOIN items i ON i.id=o.item_id
            WHERE o.type="decommission" GROUP BY i.category ORDER BY num_baixes DESC';
    return $pdo->query($sql)->fetchAll();
}
