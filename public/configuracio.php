<?php
require_once("../src/config.php");
require_once("layout.php");

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

// Crear ubicacions
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

                $codi = sprintf('%02d%s%02d', $estanteria, $fila, $p);

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

ob_start();
?>

<div class="max-w-6xl mx-auto space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-3xl font-bold text-gray-800">Configuració</h2>
            <p class="text-gray-500 mt-1">
                Gestió d’administració de màquines i ubicacions de magatzem.
            </p>
        </div>

        <?php if ($isLogged): ?>
            <a href="configuracio.php?logout=1"
               class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-semibold">
                Tancar sessió
            </a>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-200 text-red-700 px-4 py-3 rounded-xl">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-200 text-green-700 px-4 py-3 rounded-xl">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!$isLogged): ?>

        <div class="bg-white rounded-xl shadow p-6 max-w-md">
            <h3 class="text-xl font-semibold mb-4">Accés administrador</h3>

            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Contrasenya
                    </label>
                    <input
                        type="password"
                        name="config_password"
                        required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                    >
                </div>

                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-semibold">
                    Entrar
                </button>
            </form>
        </div>

    <?php else: ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <div class="bg-white rounded-xl shadow p-6">
                <h3 class="text-xl font-semibold mb-4">Afegir màquina</h3>

                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Codi màquina
                        </label>
                        <input
                            type="text"
                            name="nova_maquina"
                            required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        >
                    </div>

                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-semibold">
                        Crear màquina
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-xl shadow p-6">
                <h3 class="text-xl font-semibold mb-4">Crear ubicacions de magatzem</h3>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="crear_ubicacions" value="1">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Magatzem
                        </label>
                        <select
                            name="magatzem_code"
                            required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        >
                            <option value="MAG01">MAG01</option>
                            <option value="MAG02">MAG02</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Prestatgeria
                        </label>
                        <input
                            type="number"
                            name="estanteria"
                            min="1"
                            required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Files separades per comes
                        </label>
                        <input
                            type="text"
                            name="files"
                            placeholder="A,B,C,D"
                            required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        >
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Profunditat inicial
                            </label>
                            <input
                                type="number"
                                name="prof_inicial"
                                min="1"
                                required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Profunditat final
                            </label>
                            <input
                                type="number"
                                name="prof_final"
                                min="1"
                                required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            >
                        </div>
                    </div>

                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-semibold">
                        Crear ubicacions
                    </button>
                </form>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-xl font-semibold mb-4">Màquines existents</h3>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-left">
                    <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-2">Codi</th>
                            <th class="px-4 py-2">Estat</th>
                            <th class="px-4 py-2">Acció</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($maquines as $maq): ?>
                            <tr>
                                <td class="px-4 py-3 font-semibold">
                                    <?= htmlspecialchars($maq['codi']) ?>
                                </td>

                                <td class="px-4 py-3">
                                    <?php if ((int)$maq['activa'] === 1): ?>
                                        <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs font-semibold">
                                            Activa
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-gray-200 text-gray-600 px-2 py-1 rounded-full text-xs font-semibold">
                                            Inactiva
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-4 py-3">
                                    <form method="post" class="m-0">
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

                                        <button type="submit"
                                                class="<?= ((int)$maq['activa'] === 1)
                                                    ? 'bg-red-100 hover:bg-red-200 text-red-700'
                                                    : 'bg-green-100 hover:bg-green-200 text-green-700'
                                                ?> px-4 py-2 rounded-lg text-sm font-semibold">
                                            <?= ((int)$maq['activa'] === 1) ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>

<?php
$content = ob_get_clean();
renderPage("Configuració", $content);
?>