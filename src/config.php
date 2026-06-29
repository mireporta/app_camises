<?php
// src/config.php

// Valors per defecte (safe, per no petar si falta el local)
$host   = 'localhost';
$dbname = 'inventari_camises_v2';
$user   = 'root';
$pass   = '';

// Si existeix config.local.php, el carreguem
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}


// Password d'import (si no ve del local)
if (!defined('IMPORT_PASSWORD')) {
    define('IMPORT_PASSWORD', 'Camises2026');
}

// Password de configuració/admin (si no ve del local)
if (!defined('CONFIG_PASSWORD')) {
    define('CONFIG_PASSWORD', 'Camises2026');
}

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
    die($e->getMessage());
}

