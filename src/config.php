<?php
// src/config.php

// Si existeix config.local.php, vol dir que som en local
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    // Configuració per DEFECTE (servidor)
    $host   = 'localhost';
    $dbname = 'inventari_camises_v2';
    $user   = 'hamelin';
    $pass   = 'Camises2025';
}

define('IMPORT_PASSWORD', 'Camises2025');

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('Error de connexió BD: ' . $e->getMessage());
    die('Error de connexió a la base de dades.');
}



