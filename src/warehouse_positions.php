<?php
// src/warehouse_positions.php

function normalizePos(?string $codi): ?string {
    if ($codi === null) return null;
    $c = trim($codi);
    if ($c === '') return null;
    return strtoupper($c);
}
function normalizeMag(?string $mag): string {
    $m = strtoupper(trim((string)$mag));
    return $m !== '' ? $m : 'MAG01';
}

// Allibera posició (només dins del magatzem amb mapa: MAG01)
function freePositionByUnit(PDO $pdo, int $unitId, string $magatzemCode = 'MAG01'): void {
    $magatzemCode = normalizeMag($magatzemCode);
    $pdo->prepare("UPDATE magatzem_posicions SET item_unit_id = NULL WHERE magatzem_code = ? AND item_unit_id = ?")
        ->execute([$magatzemCode, $unitId]);
}

function occupyPosition(PDO $pdo, int $unitId, string $codi, string $magatzemCode = 'MAG01'): array {
    $magatzemCode = normalizeMag($magatzemCode);

    $st = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE magatzem_code = ? AND codi = ?");
    $st->execute([$magatzemCode, $codi]);
    if ((int)$st->fetchColumn() === 0) {
        return ['ok' => false, 'error' => "❌ La posició '$codi' no existeix a $magatzemCode."];
    }

    $st = $pdo->prepare("
        UPDATE magatzem_posicions
        SET item_unit_id = ?
        WHERE magatzem_code = ? AND codi = ? AND item_unit_id IS NULL
    ");
    $st->execute([$unitId, $magatzemCode, $codi]);

    if ($st->rowCount() === 0) {
        return ['ok' => false, 'error' => "❌ La posició '$codi' ja està ocupada a $magatzemCode."];
    }

    return ['ok' => true];
}

// Assignar posició (MAG01)
function setUnitPosition(PDO $pdo, int $unitId, ?string $newCodi, string $magatzemCode = 'MAG01'): array {
    $magatzemCode = normalizeMag($magatzemCode);
    $newCodi = normalizePos($newCodi);

    freePositionByUnit($pdo, $unitId, $magatzemCode);

    if ($newCodi === null) {
        $pdo->prepare("UPDATE item_units SET ubicacio='magatzem', magatzem_code=?, sububicacio=NULL WHERE id=?")
            ->execute([$magatzemCode, $unitId]);
        return ['ok'=>true];
    }

    $res = occupyPosition($pdo, $unitId, $newCodi, $magatzemCode);
    if (!$res['ok']) return $res;

    $pdo->prepare("UPDATE item_units SET ubicacio='magatzem', magatzem_code=?, sububicacio=? WHERE id=?")
        ->execute([$magatzemCode, $newCodi, $unitId]);

    return ['ok'=>true];
}

// Moure a magatzem auxiliar (MAG02) sense posicions
function moveUnitToWarehouseNoPositions(PDO $pdo, int $unitId, string $magatzemCode = 'MAG02'): array {
    $magatzemCode = normalizeMag($magatzemCode);

    // si venia de MAG01 amb posició, alliberem MAG01
    freePositionByUnit($pdo, $unitId, 'MAG01');

    $pdo->prepare("UPDATE item_units SET ubicacio='magatzem', magatzem_code=?, sububicacio=NULL WHERE id=?")
        ->execute([$magatzemCode, $unitId]);

    return ['ok'=>true];
}
