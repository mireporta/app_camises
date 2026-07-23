<?php
session_start();

require_once("../src/config.php");
require_once("layout_operari.php");


// 🔁 Reset de màquina si es demana
if (isset($_GET['reset_maquina']) && $_GET['reset_maquina'] === '1') {
    unset($_SESSION['maquina_actual']);
    header("Location: operari.php");
    exit;
}

// 🟢 1) SET / CANVI DE MÀQUINA
if (!empty($_GET['maquina'])) {
    // Ve de codi de barres o URL
    $_SESSION['maquina_actual'] = $_GET['maquina'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maquina_inicial'])) {
    $maq = trim($_POST['maquina_inicial']);
    if ($maq !== '') {
        $_SESSION['maquina_actual'] = $maq;
    }
    header("Location: operari.php");
    exit;
}

$maquinaActual = $_SESSION['maquina_actual'] ?? null;

// 🔍 Obtenir màquines actives (ho necessitem tant per la pantalla inicial com per la resta)
$maquines = $pdo->query("SELECT codi FROM maquines WHERE activa = 1 ORDER BY codi")
                ->fetchAll(PDO::FETCH_ASSOC);

// ⚠️ 2) Si NO tenim màquina seleccionada → pantalla només per triar màquina
if (!$maquinaActual) {
    ob_start();
    ?>
    <h2 class="text-2xl font-bold mb-4">Selecciona la màquina</h2>
    <p class="mb-4 text-gray-600 text-sm">
        Escaneja el codi de barres de la màquina (URL) o tria-la manualment.
    </p>

    <form method="POST" class="space-y-4 bg-white p-4 rounded shadow max-w-sm">
      <div>
        <label class="block text-sm font-medium mb-1">Màquina</label>
        <select name="maquina_inicial" required class="w-full border p-2 rounded">
          <option value="">-- Selecciona --</option>
          <?php foreach ($maquines as $m): ?>
            <option value="<?= htmlspecialchars($m['codi']) ?>"><?= htmlspecialchars($m['codi']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit"
              class="w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
        Confirmar màquina
      </button>
    </form>
    <?php
    $content = ob_get_clean();
    renderOperariPage("Selecciona màquina", "", $content);
    exit;
}

// 🟢 Missatges de feedback
$message = "";
if (isset($_GET['msg'])) {
    $messages = [
        'peticio_ok'   => "✅ Petició enviada correctament!",
        'vida_ok'      => "🧮 Unitats declarades correctament!",
        'retorn_ok'    => "↩ Camisa retornada al magatzem intermig.",
        'preparacio_ok' => "↩ Camisa deixada a la zona de preparació.",
        'sku_invalid'  => "❌ El codi de camisa (SKU) no és vàlid.",
        'vida_error'       => "❌ No s'ha pogut registrar la producció.",
        'edit_ok'          => "✅ Producció corregida correctament.",
        'edit_tard'        => "⏰ Ja han passat més de 10 minuts, no es pot corregir des d'aquí.",
        'edit_error'       => "❌ Error en corregir la producció."
    ];
    $message = $messages[$_GET['msg']] ?? '';
}

/* 📥 3. Fer petició */
if (isset($_POST['action']) && $_POST['action'] === 'peticio') {
    $maquina = $maquinaActual; // sempre la de sessió
    $sku     = trim($_POST['sku'] ?? '');

    if ($maquina === '' || $sku === '') {
        header("Location: operari.php?msg=sku_invalid");
        exit;
    }

    // Comprovar que el SKU existeix i està actiu
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE sku = ? AND active = 1");
    $stmt->execute([$sku]);
    if ($stmt->fetchColumn() == 0) {
        header("Location: operari.php?msg=sku_invalid");
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO peticions (maquina, sku, estat) VALUES (?, ?, 'pendent')");
    $stmt->execute([$maquina, $sku]);

    header("Location: operari.php?msg=peticio_ok");
    exit;
}

/* 🔧 3b. Confirmar recanvis instal·lats (preparació → màquina, seleccionats) */
if (isset($_POST['action']) && $_POST['action'] === 'instal_lar') {
    $maquina = $maquinaActual; // ja ve de sessió o selecció prèvia

    // IDs d'unitat seleccionades al formulari (checkboxes)
    $selectedIds = $_POST['unit_ids'] ?? [];

    // Netejar i assegurar que són enters
    $selectedIds = array_map('intval', $selectedIds);
    $selectedIds = array_filter($selectedIds, fn($v) => $v > 0);

    if (!$maquina) {
        header("Location: operari.php?msg=no_maquina");
        exit;
    }

    if (empty($selectedIds)) {
        // No s'ha seleccionat cap unitat
        header("Location: operari.php?msg=no_unitats_seleccionades");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Construir placeholders per IN (?,?,?,...)
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

        // 1️⃣ Agafem només les unitats en PREPARACIÓ per a aquesta màquina i seleccionades
        $params = array_merge([$maquina], $selectedIds);
        $sql = "
            SELECT id, item_id
            FROM item_units
            WHERE maquina_actual = ?
              AND ubicacio = 'preparacio'
              AND estat = 'actiu'
              AND id IN ($placeholders)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$units) {
            $pdo->rollBack();
            header("Location: operari.php?msg=no_unitats_valides");
            exit;
        }

        // 2️⃣ Actualitzar unitats: PREPARACIÓ → MÀQUINA i sumar 1 cicle
        $stmtUpd = $pdo->prepare("
            UPDATE item_units
            SET ubicacio = 'maquina',
                cicles_maquina = cicles_maquina + 1,
                updated_at = NOW()
            WHERE id = ?
        ");

        // 3️⃣ Registrar moviments d'entrada a màquina
        $stmtMov = $pdo->prepare("
            INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
            VALUES (?, ?, 'entrada', 1, 'maquina', ?, NOW())
        ");

        foreach ($units as $u) {
            $stmtUpd->execute([$u['id']]);
            $stmtMov->execute([$u['id'], $u['item_id'], $maquina]);
        }

        $pdo->commit();
        header("Location: operari.php?msg=instal_ok");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error instal·lar recanvis: " . $e->getMessage());
        header("Location: operari.php?msg=instal_error");
        exit;
    }
}

/* 🧮 4. Finalitzar producció (actualitzar vida útil a les unitats i registrar event) */
if (isset($_POST['action']) && $_POST['action'] === 'finalitzar') {
    $maquina = trim($_POST['maquina'] ?? '');
    $unitats = (int)($_POST['unitats'] ?? 0);

    if ($maquina === '' || $unitats <= 0) {
        header("Location: operari.php?msg=vida_error");
        exit;
    }

    // Recuperem totes les unitats assignades a la màquina
    $stmt = $pdo->prepare("
        SELECT iu.id, iu.item_id
        FROM item_units iu
        WHERE iu.maquina_actual = ? 
          AND iu.ubicacio = 'maquina' 
          AND iu.estat = 'actiu'
    ");
    $stmt->execute([$maquina]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$units) {
        // No hi ha recanvis muntats → no te sentit registrar producció
        header("Location: operari.php?msg=vida_error");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1️⃣ Crear registre de producció
        $stmtEvent = $pdo->prepare("
            INSERT INTO produccio_events (maquina, unitats_originals, created_at)
            VALUES (?, ?, NOW())
        ");
        $stmtEvent->execute([$maquina, $unitats]);
        $eventId = (int)$pdo->lastInsertId();

        // 2️⃣ Relacionar les unitats amb l'esdeveniment
        $stmtLink = $pdo->prepare("
            INSERT INTO produccio_events_units (event_id, item_unit_id)
            VALUES (?, ?)
        ");

        // 3️⃣ Actualitzar vida utilitzada de cada unitat
        $stmtUpdateVida = $pdo->prepare("
            UPDATE item_units
            SET vida_utilitzada = vida_utilitzada + ?, 
                updated_at = NOW()
            WHERE id = ?
        ");

        foreach ($units as $u) {
            // Relació amb l'event
            $stmtLink->execute([$eventId, $u['id']]);
            // Vida utilitzada
            $stmtUpdateVida->execute([$unitats, $u['id']]);
        }

        $pdo->commit();
        header("Location: operari.php?msg=vida_ok");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error finalitzar produccio: " . $e->getMessage());
        header("Location: operari.php?msg=vida_error");
        exit;
    }
}

/* ✏️ 4b. Corregir una producció recent (modificar unitats i ajustar vida) */
if (isset($_POST['action']) && $_POST['action'] === 'corregir_produccio') {
    $eventId   = (int)($_POST['event_id'] ?? 0);
    $nouValor  = max(0, (int)($_POST['unitats_correctes'] ?? 0));

    if ($eventId <= 0) {
        header("Location: operari.php?msg=edit_error");
        exit;
    }

    try {
        // 1️⃣ Llegim l'event i mirem si encara és editable
        $stmt = $pdo->prepare("
            SELECT 
              id,
              maquina,
              unitats_originals,
              unitats_correctes,
              created_at,
              TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS mins_passats
            FROM produccio_events
            WHERE id = ?
        ");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            header("Location: operari.php?msg=edit_error");
            exit;
        }

        if ((int)$event['mins_passats'] > 10) {
            // Ja no es pot corregir des de la pantalla d'operari
            header("Location: operari.php?msg=edit_tard");
            exit;
        }

        // Valor que actualment "compta" a la vida útil
        $aplicat = $event['unitats_correctes'] !== null
          ? (int)$event['unitats_correctes']
          : (int)$event['unitats_originals'];

        // Si no canvia res, sortim sense tocar la vida
        if ($nouValor === $aplicat) {
            header("Location: operari.php?msg=edit_ok");
            exit;
        }

        $delta = $nouValor - $aplicat;   // pot ser positiu o negatiu

        $pdo->beginTransaction();

        // 2️⃣ Obtenim les unitats que van participar en aquesta producció
        $stmtUnits = $pdo->prepare("
            SELECT item_unit_id
            FROM produccio_events_units
            WHERE event_id = ?
        ");
        $stmtUnits->execute([$eventId]);
        $units = $stmtUnits->fetchAll(PDO::FETCH_COLUMN);

        if (!$units) {
            // No hi ha unitats relacionades: alguna cosa no quadra
            $pdo->rollBack();
            header("Location: operari.php?msg=edit_error");
            exit;
        }

        // 3️⃣ Actualitzem la vida utilitzada de cadascuna
        $stmtUpd = $pdo->prepare("
            UPDATE item_units
            SET vida_utilitzada = GREATEST(0, vida_utilitzada + ?),
                updated_at      = NOW()
            WHERE id = ?
        ");

        foreach ($units as $unitId) {
            $stmtUpd->execute([$delta, $unitId]);
        }

        // 4️⃣ Guardem el nou valor com a unitats_correctes
        $stmtUpdateEvent = $pdo->prepare("
            UPDATE produccio_events
            SET unitats_correctes = ?
            WHERE id = ?
        ");
        $stmtUpdateEvent->execute([$nouValor, $eventId]);

        $pdo->commit();
        header("Location: operari.php?msg=edit_ok");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error corregir produccio: " . $e->getMessage());
        header("Location: operari.php?msg=edit_error");
        exit;
    }
}


/* ↩ 5. Retornar recanvis */
if (isset($_POST['action']) && $_POST['action'] === 'retornar') {
    $maquina = $maquinaActual;
    $unit_id = (int)($_POST['unit_id'] ?? 0);
    $destinacio = trim($_POST['destinacio_retorn'] ?? '');

    // Només permetem les dues destinacions previstes
    if (!in_array($destinacio, ['intermig', 'preparacio'], true)) {
        header("Location: operari.php?msg=retorn_error");
        exit;
    }

    if ($unit_id > 0 && $maquina !== '') {
        try {
            $pdo->beginTransaction();

            // Comprovar que la unitat està activa i instal·lada
            // realment a la màquina seleccionada
            $stmt = $pdo->prepare("
                SELECT item_id
                FROM item_units
                WHERE id = ?
                  AND maquina_actual = ?
                  AND ubicacio = 'maquina'
                  AND estat = 'actiu'
            ");
            $stmt->execute([$unit_id, $maquina]);
            $item_id = $stmt->fetchColumn();

            if (!$item_id) {
                throw new RuntimeException("Unitat no trobada o no instal·lada en aquesta màquina");
            }

            // Intermig: flux habitual cap al magatzem.
            // Preparació: queda vinculada a la mateixa màquina.
            $pdo->prepare("
                UPDATE item_units
                SET ubicacio = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$destinacio, $unit_id]);

            // Registrar el moviment amb la destinació escollida
            $pdo->prepare("
                INSERT INTO moviments (
                    item_unit_id,
                    item_id,
                    tipus,
                    quantitat,
                    ubicacio,
                    maquina,
                    created_at
                )
                VALUES (?, ?, 'retorn', 1, ?, ?, NOW())
            ")->execute([
                $unit_id,
                $item_id,
                $destinacio,
                $maquina
            ]);

            $pdo->commit();

            $missatge = $destinacio === 'preparacio'
                ? 'preparacio_ok'
                : 'retorn_ok';

            header("Location: operari.php?msg=" . $missatge);
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Error retorn unitat: " . $e->getMessage());
            header("Location: operari.php?msg=retorn_error");
            exit;
        }
    } else {
        header("Location: operari.php?msg=retorn_error");
        exit;
    }
}

// 🔍 SKU per autocompletar
$skusDisponibles = $pdo->query("SELECT sku FROM items WHERE active = 1 ORDER BY sku ASC")
                       ->fetchAll(PDO::FETCH_COLUMN);

// 🔍 Unitat a màquina (per JS de retorn)
$unitatsPerMaquina = $pdo->query("
  SELECT iu.id AS unit_id, iu.serial, i.sku, iu.maquina_actual
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  WHERE iu.estat = 'actiu' AND iu.ubicacio = 'maquina'
")->fetchAll(PDO::FETCH_ASSOC);

// 📜 Produccions recents (per mostrar i poder corregir)
// Mostrem, per exemple, les últimes 2 hores, però només seran editables els < 10 min
$recentEvents = [];
if (!empty($maquinaActual)) {
    $stmt = $pdo->prepare("
        SELECT 
          e.id,
          e.maquina,
          e.unitats_originals,
          e.unitats_correctes,
          e.created_at,
          TIMESTAMPDIFF(MINUTE, e.created_at, NOW()) AS mins_passats
        FROM produccio_events e
        WHERE e.maquina = ?
          AND e.created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ORDER BY e.created_at DESC
    ");
    $stmt->execute([$maquinaActual]);
    $recentEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 🔍 Unitats en PREPARACIÓ per a la màquina actual
$unitatsPreparacio = [];
if (!empty($maquinaActual)) {
    $stmt = $pdo->prepare("
        SELECT iu.id, iu.serial, i.sku
        FROM item_units iu
        JOIN items i ON i.id = iu.item_id
        WHERE iu.maquina_actual = ?
          AND iu.ubicacio = 'preparacio'
          AND iu.estat = 'actiu'
        ORDER BY i.sku, iu.serial
    ");
    $stmt->execute([$maquinaActual]);
    $unitatsPreparacio = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 🔧 SKUs instal·lats a la màquina actual (només SKU)
$stmt = $pdo->prepare("
  SELECT DISTINCT i.sku
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  WHERE iu.estat='actiu'
    AND iu.ubicacio='maquina'
    AND iu.maquina_actual = ?
  ORDER BY i.sku ASC
");
$stmt->execute([$maquinaActual]);
$skusInstal·lats = $stmt->fetchAll(PDO::FETCH_COLUMN);


ob_start();
?>

<?php if ($message): ?>
  <div class="mb-2 p-3 bg-green-100 border border-green-300 text-green-800 rounded">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>





<div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-6">


  <!-- 📥 FER PETICIÓ -->
  <div class="bg-white p-4 rounded shadow">
    <h3 class="text-lg font-semibold mb-3">📥 Demanar camisa</h3>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="peticio">
      <input type="hidden" name="maquina" value="<?= htmlspecialchars($maquinaActual) ?>">

      <div>
        <label class="block text-sm font-medium">Màquina</label>
        <div class="w-full border p-2 rounded bg-gray-100 text-gray-700">
          <?= htmlspecialchars($maquinaActual) ?>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium">Codi camisa (SKU)</label>
        <input
          type="text"
          name="sku"
          id="sku-input"
          list="sku-list"
          required
          class="w-full border p-2 rounded"
          placeholder="Comença a escriure el SKU..."
          autofocus
          autocomplete="off"
        >
        <datalist id="sku-list">
          <?php foreach ($skusDisponibles as $sku): ?>
            <option value="<?= htmlspecialchars($sku) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>

      <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-700 w-full">
        Enviar petició
      </button>
    </form>
  </div>

  <!-- 🧮 FINALITZAR PRODUCCIÓ -->
  <div class="bg-white p-4 rounded shadow">
    <h3 class="text-lg font-semibold mb-3">🧮 Finalitzar producció</h3>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="finalitzar">
      <input type="hidden" name="maquina" value="<?= htmlspecialchars($maquinaActual) ?>">

      <div>
        <label class="block text-sm font-medium">Màquina</label>
        <div class="w-full border p-2 rounded bg-gray-100 text-gray-700">
          <?= htmlspecialchars($maquinaActual) ?>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium">Unitats produïdes</label>
        <input type="number" name="unitats" min="1" required class="w-full border p-2 rounded">
      </div>

      <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-700 w-full">
        Confirmar producció
      </button>
    </form>
  </div>

  <!-- ↩ RETORNAR UNITATS -->
  <div class="bg-white p-4 rounded shadow">
    <h3 class="text-lg font-semibold mb-3">↩ Retornar camisa</h3>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="retornar">
      <input type="hidden" name="maquina" value="<?= htmlspecialchars($maquinaActual) ?>">

      <div>
        <label class="block text-sm font-medium">Màquina</label>
        <select name="maquina_dummy" id="select-maquina-retorn"
                class="w-full border p-2 rounded bg-gray-100 text-gray-700" disabled>
          <option value="<?= htmlspecialchars($maquinaActual) ?>">
            <?= htmlspecialchars($maquinaActual) ?>
          </option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium">Unitat (serial)</label>
        <select name="unit_id" id="select-unit-retorn" required class="w-full border p-2 rounded">
          <option value="">-- Carregant unitats... --</option>
        </select>
      </div>

      <div>
          <label class="block text-sm font-medium mb-2">
              On vols deixar la camisa?
          </label>

          <div class="space-y-2">
              <label class="flex items-center gap-2">
                  <input
                      type="radio"
                      name="destinacio_retorn"
                      value="intermig"
                      checked
                      required
                  >
                  <span>Retornar al magatzem</span>
              </label>

              <label class="flex items-center gap-2">
                  <input
                      type="radio"
                      name="destinacio_retorn"
                      value="preparacio"
                      required
                  >
                  <span>Deixar a la màquina</span>
              </label>
          </div>
      </div>

      <button type="submit" class="bg-yellow-600 text-white px-4 py-2 rounded hover:bg-yellow-700 w-full">
        Confirmar retorn
      </button>
    </form>
  </div>

</div>

<!-- Bloc combinat: Recanvis pendents + Produccions recents -->
<div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">

  <!-- 🧩 Columna 1: Pendents + Instal·lats -->
<div class="bg-white p-4 rounded shadow">
  <h3 class="text-lg font-semibold mb-3">Camises</h3>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

    <!-- ✅ Pendents d’entrar (PREPARACIÓ) -->
    <div>
      <h4 class="text-sm font-semibold text-gray-700 mb-2">Pendents d’entrar</h4>

      <?php if (empty($unitatsPreparacio)): ?>
        <p class="text-sm text-gray-500">
          No hi ha recanvis en preparació per a aquesta màquina.
        </p>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="action" value="instal_lar">

          <table class="w-full text-sm border mb-3">
            <thead>
              <tr class="bg-gray-100">
                <th class="border px-2 py-1 text-center w-10">✔</th>
                <th class="border px-2 py-1 text-left">SKU</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($unitatsPreparacio as $u): ?>
                <tr>
                  <td class="border px-2 py-1 text-center">
                    <input
                      type="checkbox"
                      name="unit_ids[]"
                      value="<?= (int)$u['id'] ?>"
                      class="h-4 w-4"
                    >
                  </td>
                  <td class="border px-2 py-1"><?= htmlspecialchars($u['sku']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <button type="submit"
                  class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-700">
            Entrar recanvis
          </button>
        </form>
      <?php endif; ?>
    </div>

    <!-- 🧷 Instal·lats a màquina -->
    <div>
      <h4 class="text-sm font-semibold text-gray-700 mb-2">Produint</h4>

      <?php if (empty($skusInstal·lats)): ?>
        <p class="text-sm text-gray-500">
          No hi ha recanvis instal·lats en aquesta màquina.
        </p>
      <?php else: ?>
        <table class="w-full text-sm border">
          <thead>
            <tr class="bg-gray-100">
              <th class="border px-2 py-1 text-left">SKU</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($skusInstal·lats as $sku): ?>
              <tr>
                <td class="border px-2 py-1"><?= htmlspecialchars($sku) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div>
</div>


  <!-- 🧾 Columna 2: Produccions recents -->
  <div class="bg-white p-4 rounded shadow">
    <h3 class="text-lg font-semibold mb-3">Produccions recents</h3>

    <?php if (empty($recentEvents)): ?>
      <p class="text-sm text-gray-500">Encara no hi ha produccions registrades.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left">
          <thead class="bg-gray-100 text-xs uppercase text-gray-600">
            <tr>
              <th class="px-3 py-2">Data / hora</th>
              <th class="px-3 py-2">Màquina</th>
              <th class="px-3 py-2 text-right">Unitats declarades</th>
              <th class="px-3 py-2 text-right">Unitats actuals</th>
              <th class="px-3 py-2 text-center">Acció</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($recentEvents as $ev):
              $aplicat = $ev['unitats_correctes'] !== null
                ? (int)$ev['unitats_correctes']
                : (int)$ev['unitats_originals'];
              $editable = ((int)$ev['mins_passats'] <= 10);
            ?>
              <tr>
                <td class="px-3 py-2 text-gray-500">
                  <?= date('d/m/Y H:i', strtotime($ev['created_at'])) ?>
                </td>
                <td class="px-3 py-2 font-semibold">
                  <?= htmlspecialchars($ev['maquina']) ?>
                </td>
                <td class="px-3 py-2 text-right">
                  <?= (int)$ev['unitats_originals'] ?>
                </td>
                <td class="px-3 py-2 text-right">
                  <?php if ($editable): ?>
                    <form method="POST" class="flex items-center justify-end gap-2">
                      <input type="hidden" name="action" value="corregir_produccio">
                      <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                      <input
                        type="number"
                        name="unitats_correctes"
                        min="0"
                        value="<?= $aplicat ?>"
                        class="w-24 border rounded px-2 py-1 text-right"
                      >
                  <?php else: ?>
                    <span class="font-mono"><?= $aplicat ?></span>
                  <?php endif; ?>
                </td>
                <td class="px-3 py-2 text-center">
                  <?php if ($editable): ?>
                      <button type="submit" class="bg-red-500 text-white text-xs px-3 py-1 rounded hover:bg-red-700">
                        Corregir
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="text-xs text-gray-400">
                      Tancat (<?= (int)$ev['mins_passats'] ?> min)
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>
<div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="lg:col-span-2 text-right">
    <a href="operari.php?reset_maquina=1"
       class="text-blue-600 text-xs underline hover:text-blue-800">
      Canviar de màquina
    </a>
  </div>
</div>


<script>
  // Dades de unitats a màquina per al select de retorn
  const unitatsPerMaquina = <?= json_encode($unitatsPerMaquina) ?>;
  const maquinaActual = <?= json_encode($maquinaActual) ?>;

  function updateUnitatsList() {
    const select = document.getElementById('select-unit-retorn');
    select.innerHTML = '';

    const unitats = unitatsPerMaquina.filter(u => u.maquina_actual === maquinaActual);

    if (unitats.length === 0) {
      select.innerHTML = '<option value="">-- Cap unitat activa a aquesta màquina --</option>';
      return;
    }

    unitats.forEach(u => {
      const opt = document.createElement('option');
      opt.value = u.unit_id;
      opt.textContent = `${u.sku} (${u.serial})`;
      select.appendChild(opt);
    });
  }

  // inicialitzar llista en carregar
  document.addEventListener('DOMContentLoaded', updateUnitatsList);
</script>

<script>
  // Validació de SKU al formulari de petició
  const validSkus = <?= json_encode($skusDisponibles) ?>;
  const validSkuSet = new Set(validSkus);

  const peticioForm = document.querySelector('form input[name="action"][value="peticio"]').form;
  const skuInput = document.getElementById('sku-input');

  peticioForm.addEventListener('submit', function (e) {
    const sku = (skuInput.value || '').trim();
    if (!validSkuSet.has(sku)) {
      e.preventDefault();
      alert('❌ El codi de camisa (SKU) no és vàlid. Tria un dels suggerits.');
      skuInput.focus();
      return false;
    }
  });

  // Netejar el ?msg després d'uns segons
  if (window.location.search.includes("msg=")) {
    setTimeout(() => {
      const cleanURL = window.location.origin + window.location.pathname;
      window.history.replaceState({}, document.title, cleanURL);
    }, 1200);
  }

  // Auto-refresh cada 60 segons
  setInterval(function () {
    window.location.reload();
  }, 60000);
</script>

<?php
$content = ob_get_clean();
renderOperariPage("$maquinaActual", "Maquinista",$content);
?>
