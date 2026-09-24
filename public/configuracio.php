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

// Eliminar prestatgeria
if ($isLogged && isset($_POST['eliminar_prestatgeria'])) {

    $magatzem = trim($_POST['magatzem_code'] ?? '');
    $estanteria = (int)($_POST['estanteria'] ?? 0);

    if ($magatzem === '' || $estanteria <= 0) {

        $error = 'Cal indicar el magatzem i la prestatgeria.';

    } else {

        // Comprovar que la prestatgeria existeix
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM magatzem_posicions
            WHERE magatzem_code = ?
              AND estanteria = ?
        ");
        $stmt->execute([$magatzem, $estanteria]);

        $totalPosicions = (int)$stmt->fetchColumn();

        if ($totalPosicions === 0) {

            $error = "La prestatgeria {$estanteria} no existeix a {$magatzem}.";

        } else {

            // Comprovar si hi ha alguna posició ocupada
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM magatzem_posicions
                WHERE magatzem_code = ?
                  AND estanteria = ?
                  AND item_unit_id IS NOT NULL
            ");
            $stmt->execute([$magatzem, $estanteria]);

            $ocupades = (int)$stmt->fetchColumn();

            if ($ocupades > 0) {

                $error =
                    "No es pot eliminar la prestatgeria {$estanteria} de {$magatzem}: " .
                    "té {$ocupades} posició/posicions ocupades.";

            } else {

                // Eliminar totes les posicions de la prestatgeria
                $stmt = $pdo->prepare("
                    DELETE FROM magatzem_posicions
                    WHERE magatzem_code = ?
                      AND estanteria = ?
                      AND item_unit_id IS NULL
                ");

                $stmt->execute([$magatzem, $estanteria]);

                $eliminades = $stmt->rowCount();

                $success =
                    "Prestatgeria {$estanteria} de {$magatzem} eliminada correctament. " .
                    "S'han eliminat {$eliminades} ubicacions.";
            }
        }
    }
}

// Eliminar una sububicació

if ($isLogged && isset($_POST['eliminar_ubicacio'])) {

    $magatzem = trim($_POST['magatzem_code'] ?? '');
    $codi = strtoupper(trim($_POST['codi_ubicacio'] ?? ''));

    if ($magatzem === '' || $codi === '') {

        $error = 'Cal indicar el magatzem i la ubicació.';

    } else {

        // Buscar la posició
        $stmt = $pdo->prepare("
            SELECT item_unit_id
            FROM magatzem_posicions
            WHERE magatzem_code = ?
              AND codi = ?
            LIMIT 1
        ");

        $stmt->execute([$magatzem, $codi]);

        $posicio = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$posicio) {

            $error = "La ubicació {$codi} no existeix a {$magatzem}.";

        } elseif ($posicio['item_unit_id'] !== null) {

            $error =
                "No es pot eliminar la ubicació {$codi}: " .
                "actualment està ocupada per una camisa.";

        } else {

            $stmt = $pdo->prepare("
                DELETE FROM magatzem_posicions
                WHERE magatzem_code = ?
                  AND codi = ?
                  AND item_unit_id IS NULL
            ");

            $stmt->execute([$magatzem, $codi]);

            if ($stmt->rowCount() === 1) {

                $success =
                    "Ubicació {$codi} de {$magatzem} eliminada correctament.";

            } else {

                $error =
                    "No s'ha pogut eliminar la ubicació {$codi}.";
            }
        }
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

// Afegir proveïdor
if ($isLogged && isset($_POST['nou_proveidor'])) {

    $nomProveidor = trim($_POST['nou_proveidor']);

    if ($nomProveidor !== '') {

        $stmt = $pdo->prepare("
            SELECT id
            FROM proveidors
            WHERE nom = ?
        ");

        $stmt->execute([$nomProveidor]);

        if ($stmt->fetch()) {
            $error = 'Ja existeix aquest proveïdor';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO proveidors (nom, actiu)
                VALUES (?, 1)
            ");

            $stmt->execute([$nomProveidor]);

            $success = 'Proveïdor creat correctament';
        }
    }
}


// Activar / desactivar proveïdor
if ($isLogged && isset($_POST['toggle_proveidor_id'])) {

    $id = (int)$_POST['toggle_proveidor_id'];
    $nouActiu = (int)$_POST['nou_actiu'];

    $stmt = $pdo->prepare("
        UPDATE proveidors
        SET actiu = ?
        WHERE id = ?
    ");

    $stmt->execute([$nouActiu, $id]);

    header('Location: configuracio.php');
    exit;
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

$proveidors = [];

if ($isLogged) {
    $proveidors = $pdo->query("
        SELECT *
        FROM proveidors
        ORDER BY nom ASC
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

        <!-- ===================================================== -->
<!-- GESTIÓ DEL MAGATZEM -->
<!-- ===================================================== -->

<div class="bg-white rounded-xl shadow p-6">

    <h3 class="text-xl font-semibold mb-2">
        Magatzem
    </h3>

    <p class="text-sm text-gray-500 mb-6">
        Crea o elimina ubicacions i prestatgeries del magatzem.
    </p>


    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- ============================================ -->
        <!-- CREAR UBICACIONS -->
        <!-- ============================================ -->

        <div class="border rounded-lg p-4">

            <h4 class="font-semibold text-gray-800 mb-1">
                Crear ubicacions
            </h4>

            <p class="text-xs text-gray-500 mb-4">
                Crea les posicions d'una prestatgeria.
            </p>

            <form method="post" class="space-y-4">

                <input
                    type="hidden"
                    name="crear_ubicacions"
                    value="1"
                >

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
                        placeholder="Ex: 18"
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
                        autocomplete="off"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none uppercase"
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


                <button
                    type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-semibold"
                >
                    Crear ubicacions
                </button>

            </form>

        </div>


        <!-- ============================================ -->
        <!-- ELIMINAR UBICACIONS -->
        <!-- ============================================ -->

        <div class="border rounded-lg p-4">

            <h4 class="font-semibold text-gray-800 mb-1">
                Eliminar ubicacions
            </h4>

            <p class="text-xs text-gray-500 mb-4">
                Només es poden eliminar posicions que no estiguin ocupades.
            </p>


            <!-- ELIMINAR UNA UBICACIÓ -->

            <form
                method="post"
                class="space-y-4"
                onsubmit="return confirm('Segur que vols eliminar aquesta ubicació?');"
            >

                <input
                    type="hidden"
                    name="eliminar_ubicacio"
                    value="1"
                >

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Magatzem
                    </label>

                    <select
                        name="magatzem_code"
                        required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2"
                    >
                        <option value="MAG01">MAG01</option>
                        <option value="MAG02">MAG02</option>
                    </select>
                </div>


                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Ubicació
                    </label>

                    <input
                        type="text"
                        name="codi_ubicacio"
                        required
                        placeholder="Ex: 18C11"
                        autocomplete="off"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 font-mono uppercase"
                    >
                </div>


                <button
                    type="submit"
                    class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-lg font-semibold"
                >
                    Eliminar ubicació
                </button>

            </form>


            <!-- SEPARADOR -->

            <div class="border-t my-6"></div>


            <!-- ELIMINAR PRESTATGERIA -->

            <form
                method="post"
                class="space-y-4"
                onsubmit="return confirm('Segur que vols eliminar aquesta prestatgeria i totes les seves ubicacions? Aquesta acció no es pot desfer.');"
            >

                <input
                    type="hidden"
                    name="eliminar_prestatgeria"
                    value="1"
                >

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Magatzem
                    </label>

                    <select
                        name="magatzem_code"
                        required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2"
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
                        placeholder="Ex: 18"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2"
                    >
                </div>


                <button
                    type="submit"
                    class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-lg font-semibold"
                >
                    Eliminar prestatgeria
                </button>

            </form>

        </div>

    </div>

</div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-xl font-semibold mb-4">Proveïdors</h3>

            <form method="post" class="space-y-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Nou proveïdor
                    </label>

                    <input
                        type="text"
                        name="nou_proveidor"
                        required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
                    >
                </div>

                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-semibold">
                    Crear proveïdor
                </button>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-left">
                    <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-2">Proveïdor</th>
                            <th class="px-4 py-2">Estat</th>
                            <th class="px-4 py-2">Acció</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($proveidors as $prov): ?>
                            <tr>
                                <td class="px-4 py-3 font-semibold">
                                    <?= htmlspecialchars($prov['nom']) ?>
                                </td>

                                <td class="px-4 py-3">
                                    <?php if ((int)$prov['actiu'] === 1): ?>
                                        <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs font-semibold">
                                            Actiu
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-gray-200 text-gray-600 px-2 py-1 rounded-full text-xs font-semibold">
                                            Inactiu
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-4 py-3">
                                    <form method="post" class="m-0">
                                        <input
                                            type="hidden"
                                            name="toggle_proveidor_id"
                                            value="<?= (int)$prov['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="nou_actiu"
                                            value="<?= ((int)$prov['actiu'] === 1) ? 0 : 1 ?>"
                                        >

                                        <button type="submit"
                                                class="<?= ((int)$prov['actiu'] === 1)
                                                    ? 'bg-red-100 hover:bg-red-200 text-red-700'
                                                    : 'bg-green-100 hover:bg-green-200 text-green-700'
                                                ?> px-4 py-2 rounded-lg text-sm font-semibold">
                                            <?= ((int)$prov['actiu'] === 1) ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>


<div class="bg-white rounded-xl shadow p-6">

    <h3 class="text-xl font-semibold mb-4">
        Màquines
    </h3>

    <!-- AFEGIR MÀQUINA -->
    <form method="post" class="space-y-4 mb-6">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Nova màquina
            </label>

            <input
                type="text"
                name="nova_maquina"
                required
                placeholder="Ex: P351"
                autocomplete="off"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:outline-none"
            >
        </div>

        <button
            type="submit"
            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-semibold"
        >
            Crear màquina
        </button>

    </form>


    <!-- LLISTA DE MÀQUINES -->
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

                                <button
                                    type="submit"
                                    class="<?= ((int)$maq['activa'] === 1)
                                        ? 'bg-red-100 hover:bg-red-200 text-red-700'
                                        : 'bg-green-100 hover:bg-green-200 text-green-700'
                                    ?> px-4 py-2 rounded-lg text-sm font-semibold"
                                >
                                    <?= ((int)$maq['activa'] === 1)
                                        ? 'Desactivar'
                                        : 'Activar'
                                    ?>
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