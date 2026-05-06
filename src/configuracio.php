<?php
require_once __DIR__ . '/config.php';

session_start();

$error = '';
$success = '';

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['config_ok']);
    header('Location: configuracio.php');
    exit;
}

// Login configuració
if (isset($_POST['config_password'])) {
    if ($_POST['config_password'] === CONFIG_PASSWORD) {
        $_SESSION['config_ok'] = true;
        header('Location: configuracio.php');
        exit;
    } else {
        $error = 'Contrasenya incorrecta';
    }
}

// Comprovar login
$isLogged = $_SESSION['config_ok'] ?? false;


// Activar / desactivar màquina
if ($isLogged && isset($_POST['toggle_maquina_id'])) {

    $id = (int)$_POST['toggle_maquina_id'];
    $novaActiva = (int)$_POST['nova_activa'];

    $stmt = $pdo->prepare("
        UPDATE maquines
        SET activa = ?
        WHERE id = ?
    ");

    $stmt->execute([$novaActiva, $id]);

    header('Location: configuracio.php');
    exit;
}


// Crear ubicacions de magatzem
if ($isLogged && isset($_POST['crear_ubicacions'])) {

    $magatzem = trim($_POST['magatzem_code']);
    $estanteria = (int)$_POST['estanteria'];
    $files = explode(',', strtoupper($_POST['files']));
    $profInicial = (int)$_POST['prof_inicial'];
    $profFinal = (int)$_POST['prof_final'];

    $creades = 0;

    if ($profFinal < $profInicial) {
        $error = 'La profunditat final no pot ser menor que la inicial';
    } else {

        foreach ($files as $fila) {

            $fila = trim($fila);

            if ($fila === '') {
                continue;
            }

            for ($p = $profInicial; $p <= $profFinal; $p++) {

                $codi = sprintf(
                    '%02d%s%02d',
                    $estanteria,
                    $fila,
                    $p
                );

                // Comprovar si ja existeix globalment
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM magatzem_posicions
                    WHERE codi = ?
                ");

                $stmt->execute([$codi]);

                if ($stmt->fetch()) {
                    continue;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO magatzem_posicions
                    (
                        estanteria,
                        posicio,
                        profunditat,
                        codi,
                        magatzem_code,
                        item_unit_id
                    )
                    VALUES (?, ?, ?, ?, ?, NULL)
                ");

                $stmt->execute([
                    $estanteria,
                    $fila,
                    $p,
                    $codi,
                    $magatzem
                ]);

                $creades++;
            }
        }

        $success = "S'han creat {$creades} ubicacions";
    }
}


// Afegir màquina
if ($isLogged && isset($_POST['nova_maquina'])) {

    $nom = trim($_POST['nova_maquina']);

    if ($nom !== '') {

        $stmt = $pdo->prepare("
            SELECT id
            FROM maquines
            WHERE codi = ?
        ");

        $stmt->execute([$nom]);

        if ($stmt->fetch()) {
            $error = 'Ja existeix una màquina amb aquest codi';
        } else {

            $stmt = $pdo->prepare("
                INSERT INTO maquines (codi, activa)
                VALUES (?, 1)
            ");

            $stmt->execute([$nom]);

            $success = 'Màquina creada correctament';
        }
    }
}


// Carregar màquines
$maquines = [];

if ($isLogged) {
    $maquines = $pdo->query("
        SELECT *
        FROM maquines
        ORDER BY codi ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="UTF-8">
    <title>Configuració</title>

    <style>
        body{
            font-family: Arial, sans-serif;
            margin:40px;
            background:#f5f5f5;
        }

        .box{
            max-width:900px;
            padding:20px;
            border:1px solid #ccc;
            border-radius:8px;
            background:white;
        }

        input[type=text],
        input[type=password],
        input[type=number],
        select{
            width:100%;
            padding:10px;
            margin-top:5px;
            margin-bottom:15px;
            box-sizing:border-box;
        }

        button{
            padding:10px 20px;
            cursor:pointer;
        }

        .error{
            color:red;
            margin-bottom:15px;
        }

        .success{
            color:green;
            margin-bottom:15px;
        }

        table{
            width:100%;
            border-collapse:collapse;
            margin-top:20px;
        }

        table th,
        table td{
            border:1px solid #ddd;
            padding:10px;
            text-align:left;
        }

        table th{
            background:#f0f0f0;
        }
    </style>
</head>

<body>

<div class="box">

    <h1>Configuració</h1>

    <?php if (!$isLogged): ?>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <label>Contrasenya</label>

            <input
                type="password"
                name="config_password"
                required
            >

            <button type="submit">
                Entrar
            </button>
        </form>

    <?php else: ?>

        <p>
            <a href="configuracio.php?logout=1">
                Tancar sessió
            </a>
        </p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>


        <h2>Afegir màquina</h2>

        <form method="post">
            <label>Codi màquina</label>

            <input
                type="text"
                name="nova_maquina"
                required
            >

            <button type="submit">
                Crear màquina
            </button>
        </form>


        <hr style="margin:40px 0;">


        <h2>Crear ubicacions de magatzem</h2>

        <form method="post" style="max-width:600px;">

            <input type="hidden" name="crear_ubicacions" value="1">

            <div style="margin-bottom:15px;">
                <label>Magatzem</label><br>

                <select name="magatzem_code" required>
                    <option value="MAG01">MAG01</option>
                    <option value="MAG02">MAG02</option>
                </select>
            </div>

            <div style="margin-bottom:15px;">
                <label>Estanteria</label><br>

                <input
                    type="number"
                    name="estanteria"
                    min="1"
                    required
                >
            </div>

            <div style="margin-bottom:15px;">
                <label>Files separades per comes</label><br>

                <input
                    type="text"
                    name="files"
                    placeholder="A,B,C"
                    required
                >
            </div>

            <div style="display:flex; gap:15px; margin-bottom:15px;">
                <div style="flex:1;">
                    <label>Profunditat inicial</label><br>

                    <input
                        type="number"
                        name="prof_inicial"
                        min="1"
                        required
                    >
                </div>

                <div style="flex:1;">
                    <label>Profunditat final</label><br>

                    <input
                        type="number"
                        name="prof_final"
                        min="1"
                        required
                    >
                </div>
            </div>

            <button type="submit">
                Crear ubicacions
            </button>
        </form>


        <hr style="margin:40px 0;">


        <h2>Màquines existents</h2>

        <table>
            <thead>
                <tr>
                    <th>Codi</th>
                    <th>Estat</th>
                    <th>Acció</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($maquines as $maq): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($maq['codi']) ?>
                        </td>

                        <td>
                            <?= ((int)$maq['activa'] === 1) ? 'Activa' : 'Inactiva' ?>
                        </td>

                        <td>
                            <form method="post" style="margin:0;">
                                <input
                                    type="hidden"
                                    name="toggle_maquina_id"
                                    value="<?= (int)$maq['id'] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="nova_activa"
                                    value="<?= ((int)$maq['activa'] === 1) ? 0 : 1 ?>"
                                >

                                <button type="submit">
                                    <?= ((int)$maq['activa'] === 1) ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>

</body>
</html>