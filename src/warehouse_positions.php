<?php
// src/warehouse_positions.php

/**
 * Normalitza codi de posició (si vols: trim + uppercase).
 */
function normalizePos(?string $codi): ?string {
    if ($codi === null) return null;
    $c = trim($codi);
    if ($c === '') return null;
    return strtoupper($c);
}

/**
 * Allibera qualsevol posició ocupada per aquesta unitat.
 */
function freePositionByUnit(PDO $pdo, int $unitId): void {
    $pdo->prepare("UPDATE magatzem_posicions SET item_unit_id = NULL WHERE item_unit_id = ?")
        ->execute([$unitId]);
}

/**
 * Ocupa una posició si està lliure (i existeix).
 * Retorna ['ok'=>true] o ['ok'=>false,'error'=>...]
 */
function occupyPosition(PDO $pdo, int $unitId, string $codi): array {
    // existeix?
    $st = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE codi = ?");
    $st->execute([$codi]);
    if ((int)$st->fetchColumn() === 0) {
        return ['ok' => false, 'error' => "❌ La posició '$codi' no existeix al magatzem."];
    }

    // ocupar si lliure
    $st = $pdo->prepare("
        UPDATE magatzem_posicions
        SET item_unit_id = ?
        WHERE codi = ? AND item_unit_id IS NULL
    ");
    $st->execute([$unitId, $codi]);

    if ($st->rowCount() === 0) {
        return ['ok' => false, 'error' => "❌ La posició '$codi' ja està ocupada."];
    }

    return ['ok' => true];
}

/**
 * Mou una unitat a una posició (o zona neutra si null).
 * Manté item_units.sububicacio com a camp derivat.
 *
 * IMPORTANT: crida això sempre dins una transacció si ve junt amb altres operacions.
 */
function setUnitPosition(PDO $pdo, int $unitId, ?string $newCodi): array {
    $newCodi = normalizePos($newCodi);

    // allibera posició actual (si n’hi ha)
    freePositionByUnit($pdo, $unitId);

    // zona neutra
    if ($newCodi === null) {
        $pdo->prepare("UPDATE item_units SET sububicacio = NULL WHERE id = ?")
            ->execute([$unitId]);
        return ['ok' => true];
    }

    // ocupa nova
    $res = occupyPosition($pdo, $unitId, $newCodi);
    if (!$res['ok']) return $res;

    // reflecteix a item_units
    $pdo->prepare("UPDATE item_units SET sububicacio = ? WHERE id = ?")
        ->execute([$newCodi, $unitId]);

    return ['ok' => true];
}
