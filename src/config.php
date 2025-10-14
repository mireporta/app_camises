<?php
// src/config.php - configuració per al servidor real proporcionat
return [
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=inventari_camises;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    ],
];
