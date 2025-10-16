<?php
require_once("../src/config.php");
require_once("layout.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sku = $_POST['sku'];
    $qty = 1; // Sempre 1 per cada sortida
    $ubicacio = $_POST['ubicacio'];
    $maquina = $_POST['maquina'];

    // Buscar item
    $stmt = $pdo->prepare("SELECT id, stock FROM items WHERE sku = ?");
    $stmt->execute([$sku]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($item && $item['stock'] >= $qty) {
        $newStock = $item['stock'] - $qty;

        // Actualitzar estoc
        $pdo->prepare("UPDATE items SET stock = ? WHERE id = ?")->execute([$newStock, $item['id']]);

        // Registrar moviment
        $pdo->prepare("
            INSERT INTO moviments (item_id, tipus, quantitat, ubicacio, maquina)
            VALUES (?, 'sortida', ?, ?, ?)
        ")->execute([$item['id'], $qty, $ubicacio, $maquina]);

        $message = "✅ Sortida registrada correctament";
    } else {
        $message = "❌ Estoc insuficient o SKU no trobat";
    }
}

// 📋 Moviments recents (últimes 10 sortides)
$moviments = $pdo->query("
    SELECT m.*, i.sku, i.name 
    FROM moviments m
    JOIN items i ON m.item_id = i.id
    WHERE m.tipus = 'sortida'
    ORDER BY m.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<h2 class="text-2xl font-bold mb-4">Sortides d'estoc</h2>

<?php if (!empty($message)): ?>
  <div class="mb-4 p-3 bg-yellow-100 border border-yellow-300 text-yellow-800 rounded">
    <?= $message ?>
  </div>
<?php endif; ?>

<form method="POST" class="bg-white p-4 rounded shadow max-w-lg space-y-4 mb-8">
  <div>
    <label class="block mb-1 font-medium">Codi camisa</label>
    <input type="text" name="sku" required class="w-full p-2 border rounded" autofocus>
  </div>

  <div>
    <label class="block mb-1 font-medium">Ubicació (magatzem)</label>
    <input type="text" name="ubicacio" required class="w-full p-2 border rounded" value="MAG01">
  </div>

  <div>
    <label class="block mb-1 font-medium">Màquina</label>
    <select name="maquina" required class="w-full p-2 border rounded">
      <option value="">-- Selecciona una màquina --</option>
      <?php
      $maquines = $pdo->query("SELECT codi, nom FROM maquines WHERE activa = 1 ORDER BY codi")->fetchAll(PDO::FETCH_ASSOC);
      foreach ($maquines as $maq):
      ?>
        <option value="<?= htmlspecialchars($maq['codi']) ?>">
          <?= htmlspecialchars($maq['codi']) ?> - <?= htmlspecialchars($maq['nom']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>


  <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
    Registrar sortida
  </button>
</form>

<!-- 📋 Llista de moviments recents -->
<div class="bg-white rounded shadow p-4">
  <h3 class="text-lg font-bold mb-3">Moviments recents</h3>
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm text-left">
      <thead class="bg-gray-100 uppercase text-xs text-gray-600">
        <tr>
          <th class="px-4 py-2">Data</th>
          <th class="px-4 py-2">SKU</th>
          <th class="px-4 py-2">Nom</th>
          <th class="px-4 py-2 text-center">Ubicació</th>
          <th class="px-4 py-2 text-center">Màquina</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (count($moviments) > 0): ?>
          <?php foreach ($moviments as $m): ?>
            <tr>
              <td class="px-4 py-2"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
              <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($m['sku']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($m['name']) ?></td>
              <td class="px-4 py-2 text-center"><?= htmlspecialchars($m['ubicacio']) ?></td>
              <td class="px-4 py-2 text-center"><?= htmlspecialchars($m['maquina']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="5" class="px-4 py-2 text-center text-gray-400">Encara no hi ha moviments registrats</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$content = ob_get_clean();
renderPage("Sortides", $content);
