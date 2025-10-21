<?php
require_once("../src/config.php");
require_once("layout_operari.php");

// Missatges de feedback
$message = "";
if (isset($_GET['msg'])) {
    $messages = [
        'peticio_ok' => "✅ Petició enviada correctament!",
        'vida_ok' => "🧮 Vida actualitzada correctament!",
        'retorn_ok' => "↩ Camisa retornada al magatzem intermig."
    ];
    $message = $messages[$_GET['msg']] ?? '';
}


/* 📥 1. Fer petició */
if (isset($_POST['action']) && $_POST['action'] === 'peticio') {
    $maquina = $_POST['maquina'];
    $sku = $_POST['sku'];

    $stmt = $pdo->prepare("INSERT INTO peticions (maquina, sku) VALUES (?, ?)");
    $stmt->execute([$maquina, $sku]);
    $message = "✅ Petició enviada correctament!";

    header("Location: operari.php?msg=peticio_ok");
    exit;
}

/* 🧮 2. Finalitzar producció */
if (isset($_POST['action']) && $_POST['action'] === 'finalitzar') {
    $maquina = $_POST['maquina'];
    $unitats = (int)$_POST['unitats'];

    // Obtenir els recanvis d’aquesta màquina
    $stmt = $pdo->prepare("SELECT item_id FROM maquina_items WHERE maquina = ?");
    $stmt->execute([$maquina]);
    $items = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($items) {
        // Actualitzar vida a maquina_items
        $pdo->prepare("
            UPDATE maquina_items
            SET vida_acumulada = vida_acumulada + ?
            WHERE maquina = ?
        ")->execute([$unitats, $maquina]);

        // Actualitzar també la vida acumulada al recanvi (items)
        $inClause = implode(',', array_fill(0, count($items), '?'));
        $params = array_merge([$unitats], $items);
        $pdo->prepare("
            UPDATE items 
            SET vida_utilitzada = vida_utilitzada + ?
            WHERE id IN ($inClause)
        ")->execute($params);

        $message = "🧮 Vida actualitzada per $unitats unitats a la màquina $maquina.";
    } else {
        $message = "⚠️ No hi ha camises assignades a la màquina $maquina.";
    }
     header("Location: operari.php?msg=vida_ok");
    exit;
}

/* ↩ 3. Retornar recanvis */
if (isset($_POST['action']) && $_POST['action'] === 'retornar') {
    $maquina = $_POST['maquina'];
    $itemId = $_POST['item_id'];
    $magatzem = "MAG_INTERMIG";

    // Recuperar info del recanvi
    $stmt = $pdo->prepare("SELECT sku, name FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($item) {
        // 1️⃣ Esborrar de la màquina
        $pdo->prepare("DELETE FROM maquina_items WHERE maquina = ? AND item_id = ?")->execute([$maquina, $itemId]);

        // 2️⃣ Afegir al magatzem intermig
        $pdo->prepare("INSERT INTO intermig_items (item_id, maquina) VALUES (?, ?)")->execute([$itemId, $maquina]);

        // 3️⃣ Registrar moviment
        $pdo->prepare("
            INSERT INTO moviments (item_id, tipus, quantitat, ubicacio, maquina, created_at)
            VALUES (?, 'retorn', 1, ?, ?, NOW())
        ")->execute([$itemId, $magatzem, $maquina]);

        $message = "↩ Camisa retornada correctament al magatzem intermig.";
    }
    header("Location: operari.php?msg=retorn_ok");
    exit;
}

// Obtenir màquines per al desplegable
$maquines = $pdo->query("SELECT codi FROM maquines WHERE activa = 1 ORDER BY codi")->fetchAll(PDO::FETCH_ASSOC);

// Obtenir recanvis actuals a cada màquina (per al bloc de retorn)
$recanvisPerMaquina = [];
foreach ($maquines as $m) {
    $stmt = $pdo->prepare("
        SELECT mi.id AS rel_id, i.id AS item_id, i.sku
        FROM maquina_items mi
        JOIN items i ON mi.item_id = i.id
        WHERE mi.maquina = ?
    ");
    $stmt->execute([$m['codi']]);
    $recanvisPerMaquina[$m['codi']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        <input type="text" name="sku" required class="w-full border p-2 rounded" autofocus>
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

  <!-- ↩ RETORNAR RECANVIS -->
  <div class="bg-white p-4 rounded shadow">
    <h3 class="text-lg font-semibold mb-3">↩ Retornar camises</h3>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="retornar">

      <div>
        <label class="block text-sm font-medium">Màquina</label>
        <select name="maquina" id="select-maquina-retorn" required class="w-full border p-2 rounded" onchange="updateRecanvisList()">
          <option value="">-- Selecciona --</option>
          <?php foreach ($maquines as $m): ?>
            <option value="<?= htmlspecialchars($m['codi']) ?>"><?= htmlspecialchars($m['codi']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium">Camisa</label>
        <select name="item_id" id="select-item-retorn" required class="w-full border p-2 rounded">
          <option value="">-- Selecciona màquina primer --</option>
        </select>
      </div>

      <button type="submit" class="bg-yellow-600 text-white px-4 py-2 rounded hover:bg-yellow-700 w-full">
        Retornar al magatzem intermig
      </button>
    </form>
  </div>

</div>

<script>
  const recanvisData = <?= json_encode($recanvisPerMaquina) ?>;

  function updateRecanvisList() {
    const maquina = document.getElementById('select-maquina-retorn').value;
    const select = document.getElementById('select-item-retorn');
    select.innerHTML = '';

    if (!maquina || !recanvisData[maquina] || recanvisData[maquina].length === 0) {
      select.innerHTML = '<option value="">-- Cap recanvi --</option>';
      return;
    }

    recanvisData[maquina].forEach(r => {
      const opt = document.createElement('option');
      opt.value = r.item_id;
      opt.textContent = r.sku;
      select.appendChild(opt);
    });
  }
</script>

<script>
  if (window.location.search.includes("msg=")) {
    setTimeout(() => {
      const cleanURL = window.location.origin + window.location.pathname;
      window.history.replaceState({}, document.title, cleanURL);
    }, 1000);
  }
</script>

<?php
$content = ob_get_clean();
renderOperariPage("Pantalla Operari", $content);
