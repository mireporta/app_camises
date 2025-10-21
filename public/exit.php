<?php
require_once("../src/config.php");
require_once("layout.php");

// --- Missatge de resultat ---
$message = "";

// --- Processar el formulari ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sku = trim($_POST['sku'] ?? '');
    $ubicacio = trim($_POST['ubicacio'] ?? '');
    $maquina = trim($_POST['maquina'] ?? '');

    if ($sku && $ubicacio && $maquina) {
        // Buscar item
        $stmt = $pdo->prepare("SELECT id, name, stock FROM items WHERE sku = ?");
        $stmt->execute([$sku]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item && $item['stock'] > 0) {
            $newStock = $item['stock'] - 1;

            // Actualitzar estoc
            $pdo->prepare("UPDATE items SET stock = ? WHERE id = ?")->execute([$newStock, $item['id']]);

            // Registrar moviment
            $pdo->prepare("
                INSERT INTO moviments (item_id, tipus, quantitat, ubicacio, maquina, created_at)
                VALUES (?, 'sortida', 1, ?, ?, NOW())
            ")->execute([$item['id'], $ubicacio, $maquina]);

            // Assignar camisa a màquina
            $pdo->prepare("
                INSERT INTO maquina_items (maquina, item_id)
                VALUES (?, ?)
            ")->execute([$maquina, $item['id']]);

            $message = "✅ Sortida registrada correctament";
        } else {
            $message = "❌ Estoc insuficient o SKU no trobat";
        }
    } else {
        $message = "⚠️ Cal omplir tots els camps";
    }
}

// --- Obtenir moviments recents ---
$moviments = $pdo->query("
    SELECT m.*, i.sku, i.name
    FROM moviments m
    JOIN items i ON i.id = m.item_id
    WHERE m.tipus = 'sortida'
    ORDER BY m.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<h2 class="text-3xl font-bold mb-6">Sortides d'estoc</h2>

<?php if (!empty($message)): ?>
  <div class="mb-4 p-4 bg-yellow-100 border border-yellow-300 text-yellow-800 rounded">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<form method="POST" class="bg-white p-6 rounded-lg shadow-md max-w-lg space-y-4">
  <div>
    <label class="block mb-1 font-medium">Codi camisa</label>
    <input type="text" name="sku" required class="w-full p-2 border rounded focus:ring focus:ring-blue-200" autofocus>
  </div>

  <div>
    <label class="block mb-1 font-medium">Ubicació (magatzem)</label>
    <input type="text" name="ubicacio" required class="w-full p-2 border rounded focus:ring focus:ring-blue-200" value="MAG01">
  </div>

  <div>
    <label class="block mb-1 font-medium">Màquina</label>
    <select name="maquina" required class="w-full p-2 border rounded focus:ring focus:ring-blue-200">
      <option value="">-- Selecciona una màquina --</option>
      <option value="P351">P351</option>
      <option value="P352">P352</option>
      <option value="P353">P353</option>
      <option value="P354">P354</option>
      <option value="P355">P355</option>
      <option value="P356">P356</option>
    </select>
  </div>

  <button type="submit" class="bg-red-600 text-white px-5 py-2 rounded hover:bg-red-700 transition">
    Registrar sortida
  </button>
</form>

<!-- Moviments recents -->
<div class="bg-white p-6 rounded-lg shadow-md mt-10">
  <h3 class="text-xl font-bold mb-4">Moviments recents</h3>
  <?php if ($moviments): ?>
    <div class="overflow-x-auto">
      <table class="min-w-full border text-sm">
        <thead class="bg-gray-100 text-gray-600 uppercase">
          <tr>
            <th class="px-4 py-2 text-left">Data</th>
            <th class="px-4 py-2 text-left">SKU</th>
            <th class="px-4 py-2 text-left">Nom</th>
            <th class="px-4 py-2 text-left">Ubicació</th>
            <th class="px-4 py-2 text-left">Màquina</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php foreach ($moviments as $m): ?>
            <tr>
              <td class="px-4 py-2"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($m['sku']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($m['name']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($m['ubicacio']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($m['maquina']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-gray-500">Encara no hi ha moviments de sortida.</p>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
renderPage("Sortides", $content);
