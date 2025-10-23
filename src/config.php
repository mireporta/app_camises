<?php
// src/config.php - configuració per al servidor real proporcionat
// return [
//     'db' => [
//         'dsn' => 'mysql:host=localhost;dbname=inventari_camises;charset=utf8mb4',
//         'user' => 'root',
//         'pass' => '',
//         'options' => [
//             PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
//             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
//         ],
//     ],
// ];

$host = "localhost";
$dbname = "inventari_camises_v2";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de connexió: " . $e->getMessage());
}
?>
