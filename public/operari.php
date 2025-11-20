<?php
require_once("../src/config.php");
require_once("layout_operari.php");

// 🟢 Missatges de feedback
$message = "";
if (isset($_GET['msg'])) {
    $messages = [
        'peticio_ok' => "✅ Petició enviada correctament!",
        'vida_ok' => "🧮 Vida actualitzada correctament!",
        'retorn_ok' => "↩ Camisa retornada al magatzem intermig.",
        'sku_invalid'  => "❌ El codi de camisa (SKU) no és vàlid."
    ];
    $message = $messages[$_GET['msg']] ?? '';
}

/* 📥 1. Fer petició */
if (isset($_POST['action']) && $_POST['action'] === 'peticio') {
    $maquina = $_POST['maquina'] ?? '';
    $sku     = trim($_POST['sku'] ?? '');

    // Validació bàsica
    if ($maquina === '' || $sku === '') {
        header("Location: operari.php?msg=sku_invalid");
        exit;
    }

    // ✅ Comprovar que el SKU existeix i està actiu
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE sku = ? AND active = 1");
    $stmt->execute([$sku]);
    if ($stmt->fetchColumn() == 0) {
        // SKU inventat o inactiu → no acceptem la petició
        header("Location: operari.php?msg=sku_invalid");
        exit;
    }

    // Registra petició
    $stmt = $pdo->prepare("INSERT INTO peticions (maquina, sku, estat) VALUES (?, ?, 'pendent')");
    $stmt->execute([$maquina, $sku]);

    header("Location: operari.php?msg=peticio_ok");
    exit;
}

/* 🧮 2. Finalitzar producció (actualitzar vida útil a les unitats) */
if (isset($_POST['action']) && $_POST['action'] === 'finalitzar') {
    $maquina = $_POST['maquina'];
    $unitats = (int)$_POST['unitats'];

    // Recuperem totes les unitats assignades a la màquina
    $stmt = $pdo->prepare("
        SELECT iu.id, iu.item_id
        FROM item_units iu
        WHERE iu.maquina_actual = ? AND iu.ubicacio = 'maquina' AND iu.estat = 'actiu'
    ");
    $stmt->execute([$maquina]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($units) {
        foreach ($units as $u) {
            // Actualitza vida utilitzada de la unitat
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

/* ↩ 3. Retornar recanvis */
if (isset($_POST['action']) && $_POST['action'] === 'retornar') {
    $maquina = trim($_POST['maquina'] ?? '');
    $unit_id = (int)($_POST['unit_id'] ?? 0);

    if ($unit_id > 0 && $maquina !== '') {
        try {
            $pdo->beginTransaction();

            // 1️⃣ Obtenir item_id associat
            $stmt = $pdo->prepare("SELECT item_id FROM item_units WHERE id = ?");
            $stmt->execute([$unit_id]);
            $item_id = $stmt->fetchColumn();

            if (!$item_id) {
                throw new RuntimeException("Unitat no trobada");
            }

            // 2️⃣ Moure unitat a "intermig"
            $pdo->prepare("
                UPDATE item_units
                SET ubicacio = 'intermig',
                    maquina_actual = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$unit_id]);

            // 3️⃣ Registrar moviment
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


// 🔍 Obtenir màquines actives
$maquines = $pdo->query("SELECT codi FROM maquines WHERE activa = 1 ORDER BY codi")->fetchAll(PDO::FETCH_ASSOC);

// 🔍 Obtenir unitats muntades actualment a cada màquina
$unitsPerMaquina = [];
foreach ($maquines as $m) {
    $stmt = $pdo->prepare("
        SELECT iu.id, iu.serial, i.sku
        FROM item_units iu
        JOIN items i ON i.id = iu.item_id
        WHERE iu.maquina_actual = ? AND iu.ubicacio = 'maquina' AND iu.estat = 'actiu'
        ORDER BY i.sku ASC
    ");
    $stmt->execute([$m['codi']]);
    // 🔍 Obtenir llista de SKU actius per l'autocompletat
    $skusDisponibles = $pdo->query("SELECT sku FROM items WHERE active = 1 ORDER BY sku ASC")
                       ->fetchAll(PDO::FETCH_COLUMN);

    $unitsPerMaquina[$m['codi']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>

<?php if ($message): ?>
  <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">

  <!-- 📥 FER PETICIÓ -->
  <div class="bg-white p-4 rounded shadow">
    <h3 class="text-lg font-semibold mb-3">📥 Fer petició de camisa</h3>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="peticio">

      <div>
        <label class="block text-sm font-medium">Màquina</label>
        <select name="maquina" required class="w-full border p-2 rounded">
          <option value="">-- Selecciona --</option>
          <?php foreach ($maquines as $m): ?>
            <option value="<?= htmlspecialchars($m['codi']) ?>"><?= htmlspecialchars($m['codi']) ?></option>
          <?php endforeach; ?>
        </select>
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

      <div>
        <label class="block text-sm font-medium">Màquina</label>
        <select name="maquina" required class="w-full border p-2 rounded">
          <option value="">-- Selecciona --</option>
          <?php foreach ($maquines as $m): ?>
            <option value="<?= htmlspecialchars($m['codi']) ?>"><?= htmlspecialchars($m['codi']) ?></option>
          <?php endforeach; ?>
        </select>
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

    <!-- Màquina -->
    <div>
      <label class="block text-sm font-medium">Màquina</label>
      <select name="maquina" id="select-maquina-retorn" required class="w-full border p-2 rounded" onchange="updateUnitatsList()">
        <option value="">-- Selecciona --</option>
        <?php foreach ($maquines as $m): ?>
          <option value="<?= htmlspecialchars($m['codi']) ?>"><?= htmlspecialchars($m['codi']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Unitat / Serial -->
    <div>
      <label class="block text-sm font-medium">Unitat (serial)</label>
      <select name="unit_id" id="select-unit-retorn" required class="w-full border p-2 rounded">
        <option value="">-- Selecciona màquina primer --</option>
      </select>
    </div>

    <button type="submit" class="bg-yellow-600 text-white px-4 py-2 rounded hover:bg-yellow-700 w-full">
      Retornar al magatzem intermig
    </button>
  </form>
</div>

<script>
  const unitatsPerMaquina = <?= json_encode(
    $pdo->query("
      SELECT iu.id AS unit_id, iu.serial, i.sku, iu.maquina_actual
      FROM item_units iu
      JOIN items i ON i.id = iu.item_id
      WHERE iu.estat = 'actiu' AND iu.ubicacio = 'maquina'
    ")->fetchAll(PDO::FETCH_ASSOC)
  ) ?>;

  function updateUnitatsList() {
    const maquina = document.getElementById('select-maquina-retorn').value;
    const select = document.getElementById('select-unit-retorn');
    select.innerHTML = '';

    const unitats = unitatsPerMaquina.filter(u => u.maquina_actual === maquina);

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
</script>


</div>

<script>
  const recanvisData = <?= json_encode($unitsPerMaquina) ?>;

  function updateRecanvisList() {
    const maquina = document.getElementById('select-maquina-retorn').value;
    const select = document.getElementById('select-item-retorn');
    select.innerHTML = '';

    if (!maquina || !recanvisData[maquina] || recanvisData[maquina].length === 0) {
      select.innerHTML = '<option value="">-- Cap unitat --</option>';
      return;
    }

    recanvisData[maquina].forEach(r => {
      const opt = document.createElement('option');
      opt.value = r.id;
      opt.textContent = r.sku + ' (' + r.serial + ')';
      select.appendChild(opt);
    });
  }

  // Eliminar paràmetre msg després de mostrar
  if (window.location.search.includes("msg=")) {
    setTimeout(() => {
      const cleanURL = window.location.origin + window.location.pathname;
      window.history.replaceState({}, document.title, cleanURL);
    }, 1200);
  }
</script>

<script>
  // Llista de SKU vàlids generada des de PHP
  const validSkus = <?= json_encode($skusDisponibles) ?>;
  const validSkuSet = new Set(validSkus);

  // Trobar el formulari de "Fer petició"
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
</script>


<?php
$content = ob_get_clean();
renderOperariPage("Pantalla Operari", $content);
