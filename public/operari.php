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
    renderOperariPage("Selecciona màquina", $content);
    exit;
}

// 🟢 Missatges de feedback
$message = "";
if (isset($_GET['msg'])) {
    $messages = [
        'peticio_ok'   => "✅ Petició enviada correctament!",
        'vida_ok'      => "🧮 Vida actualitzada correctament!",
        'retorn_ok'    => "↩ Camisa retornada al magatzem intermig.",
        'sku_invalid'  => "❌ El codi de camisa (SKU) no és vàlid."
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

/* 🧮 4. Finalitzar producció */
if (isset($_POST['action']) && $_POST['action'] === 'finalitzar') {
    $maquina = $maquinaActual;
    $unitats = (int)$_POST['unitats'];

    $stmt = $pdo->prepare("
        SELECT iu.id, iu.item_id
        FROM item_units iu
        WHERE iu.maquina_actual = ? AND iu.ubicacio = 'maquina' AND iu.estat = 'actiu'
    ");
    $stmt->execute([$maquina]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($units) {
        foreach ($units as $u) {
            $pdo->prepare("
                UPDATE item_units
                SET vida_utilitzada = vida_utilitzada + ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$unitats, $u['id']]);
        }
    }

    header("Location: operari.php?msg=vida_ok");
    exit;
}

/* ↩ 5. Retornar recanvis */
if (isset($_POST['action']) && $_POST['action'] === 'retornar') {
    $maquina = $maquinaActual;
    $unit_id = (int)($_POST['unit_id'] ?? 0);

    if ($unit_id > 0 && $maquina !== '') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT item_id FROM item_units WHERE id = ?");
            $stmt->execute([$unit_id]);
            $item_id = $stmt->fetchColumn();

            if (!$item_id) {
                throw new RuntimeException("Unitat no trobada");
            }

            $pdo->prepare("
                UPDATE item_units
                SET ubicacio = 'intermig',
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$unit_id]);

            $pdo->prepare("
                INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
                VALUES (?, ?, 'retorn', 1, 'intermig', ?, NOW())
            ")->execute([$unit_id, $item_id, $maquina]);

            $pdo->commit();

            header("Location: operari.php?msg=retorn_ok");
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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

ob_start();
?>

<?php if ($message): ?>
  <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<p class="mb-4 text-sm text-gray-600 flex items-center justify-between">
  <span>
    Màquina actual: <span class="font-semibold"><?= htmlspecialchars($maquinaActual) ?></span>
  </span>

  <a href="operari.php?reset_maquina=1"
     class="text-blue-600 text-xs underline hover:text-blue-800">
    Canviar de màquina
  </a>
</p>


<div class="grid grid-cols-1 md:grid-cols-3 gap-6">

  <!-- 📥 FER PETICIÓ -->
  <div class="bg-white p-4 rounded shadow">
    <h3 class="text-lg font-semibold mb-3">📥 Fer petició de camisa</h3>
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

      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 w-full">
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

      <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 w-full">
        Actualitzar vida
      </button>
    </form>
  </div>

  <!-- ↩ RETORNAR UNITATS -->
  <div class="bg-white p-4 rounded shadow">
    <h3 class="text-lg font-semibold mb-3">↩ Retornar recanvis de màquina</h3>
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

      <button type="submit" class="bg-yellow-600 text-white px-4 py-2 rounded hover:bg-yellow-700 w-full">
        Retornar al magatzem intermig
      </button>
    </form>
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
renderOperariPage("Pantalla Operari", $content);
?>
