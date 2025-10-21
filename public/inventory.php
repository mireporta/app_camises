<?php
require_once("../src/config.php");
require_once("layout.php");

/**
 * INVENTARI — Vida útil real basada en unitats acumulades
 * 
 * - items.life_expectancy → unitats teòriques totals (capacitat màxima)
 * - items.vida_utilitzada → unitats reals produïdes acumulades
 * - vida útil (%) = 100 - floor(100 * vida_utilitzada / life_expectancy)
 */

$stmt = $pdo->query("
  SELECT 
    i.id, i.sku, i.name, i.category, i.stock, i.min_stock,
    i.life_expectancy, i.vida_utilitzada, i.plan_file, i.active,
    mi.maquina
  FROM items i
  LEFT JOIN maquina_items mi ON mi.item_id = i.id
  WHERE i.active = 1
  ORDER BY i.sku ASC
");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<h2 class="text-3xl font-bold mb-6">Inventari</h2>

<div class="bg-white rounded-xl shadow p-4 overflow-x-auto">
  <table class="min-w-full text-sm text-left border-collapse">
    <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
      <tr>
        <th class="px-4 py-2">SKU</th>
        <th class="px-4 py-2">Nom</th>
        <th class="px-4 py-2">Categoria</th>
        <th class="px-4 py-2 text-center">Estoc</th>
        <th class="px-4 py-2 text-center">Estoc mínim</th>
        <th class="px-4 py-2">Vida útil</th>
        <th class="px-4 py-2">Ubicació</th>
        <th class="px-4 py-2 text-center">Plànol</th>
        <th class="px-4 py-2 text-right">Accions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($items as $item): ?>
      <?php
        $used = (int)$item['vida_utilitzada'];
        $total = max(1, (int)$item['life_expectancy']);
        $vp = max(0, 100 - floor(100 * $used / $total)); // % vida restant
        $barClass = $vp <= 10 ? 'bg-red-500' : ($vp <= 30 ? 'bg-yellow-500' : 'bg-green-500');
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($item['sku']) ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($item['name']) ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($item['category']) ?></td>
        <td class="px-4 py-2 text-center"><?= (int)$item['stock'] ?></td>
        <td class="px-4 py-2 text-center"><?= (int)$item['min_stock'] ?></td>

        <!-- Vida útil -->
        <td class="px-4 py-2">
          <div class="flex items-center gap-2">
            <div class="w-32 bg-gray-200 rounded-full h-2">
              <div class="<?= $barClass ?> h-2 rounded-full" style="width: <?= $vp ?>%;"></div>
            </div>
            <span class="text-sm <?= $vp <= 10 ? 'text-red-600 font-semibold' : '' ?>"><?= $vp ?>%</span>
          </div>
          <div class="text-xs text-gray-400 mt-1">
            Usades: <?= $used ?> / Teòriques: <?= $total ?>
          </div>
        </td>

        <!-- Ubicació -->
        <td class="px-4 py-2">
          <?= !empty($item['maquina']) ? ('Màquina ' . htmlspecialchars($item['maquina'])) : 'Magatzem' ?>
        </td>

        <!-- Plànol -->
        <td class="px-4 py-2 text-center">
          <?php if (!empty($item['plan_file'])): ?>
            <a href="uploads/<?= htmlspecialchars($item['plan_file']) ?>" target="_blank" class="text-blue-600 hover:underline">📎 Obrir</a>
          <?php else: ?>
            <span class="text-gray-400">—</span>
          <?php endif; ?>
        </td>

        <!-- Accions -->
      <td class="px-4 py-2 text-right">
        <div class="flex justify-end items-center gap-3">
          <button 
            class="flex items-center gap-1 text-blue-600 hover:text-blue-800 text-sm font-medium"
            onclick="openEditModal(
              <?= (int)$item['id'] ?>,
              '<?= htmlspecialchars($item['sku'], ENT_QUOTES) ?>',
              '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>',
              <?= (int)$item['stock'] ?>,
              <?= (int)$item['min_stock'] ?>,
              <?= (int)$item['life_expectancy'] ?>
            )"
          >
            ✏️ <span>Editar</span>
          </button>

          <button 
            class="flex items-center gap-1 text-red-600 hover:text-red-800 text-sm font-medium"
            onclick="deleteItem(<?= (int)$item['id'] ?>)"
          >
            🗑️ <span>Baixa</span>
          </button>
        </div>
      </td>


      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Modal d'edició -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-6">
    <h3 class="text-lg font-bold mb-4">Editar recanvi</h3>
    <form id="editForm" method="POST" action="../src/update_item.php" enctype="multipart/form-data">
      <input type="hidden" name="id" id="edit-id">
      
      <label class="block mb-2 text-sm font-medium">Nom</label>
      <input type="text" name="name" id="edit-name" class="w-full mb-3 p-2 border rounded">

      <label class="block mb-2 text-sm font-medium">Estoc</label>
      <input type="number" name="stock" id="edit-stock" class="w-full mb-3 p-2 border rounded">

      <label class="block mb-2 text-sm font-medium">Estoc mínim</label>
      <input type="number" name="min_stock" id="edit-min_stock" class="w-full mb-3 p-2 border rounded">

      <label class="block mb-2 text-sm font-medium">Vida útil teòrica (unitats)</label>
      <input type="number" name="life_expectancy" id="edit-life" class="w-full mb-3 p-2 border rounded" min="0">

      <label class="block mb-2 text-sm font-medium">Plànol (PDF)</label>
      <input type="file" name="plan_file" accept="application/pdf" class="w-full mb-3 p-2 border rounded">
      <button type="button" onclick="deletePlanFile()" class="text-red-600 hover:text-red-800 text-sm mb-3">
        🗑️ Eliminar plànol actual
      </button>        

      <div class="flex justify-end space-x-2">
        <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Cancel·lar</button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openEditModal(id, sku, name, stock, min_stock, life) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-name').value = name;
    document.getElementById('edit-stock').value = stock;
    document.getElementById('edit-min_stock').value = min_stock;
    document.getElementById('edit-life').value = life;
    document.getElementById('editModal').classList.remove('hidden');
  }

  function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
  }

  function deleteItem(id) {
    if (confirm("Segur que vols donar de baixa aquest recanvi?")) {
      fetch('../src/delete_item.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
      }).then(() => location.reload());
    }
  }

  function deletePlanFile() {
    const id = document.getElementById('edit-id').value;
    if (!id) {
      alert("⚠️ Has d'obrir primer un recanvi per poder eliminar el plànol.");
      return;
    }
    if (!confirm("Vols eliminar el plànol d'aquest recanvi?")) return;

    fetch('../src/delete_plan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + id
    }).then(response => {
      if (response.ok) {
        alert("✅ Plànol eliminat correctament");
        location.reload();
      } else {
        alert("❌ Error eliminant el plànol");
      }
    });
  }
</script>

<?php
$content = ob_get_clean();
renderPage("Inventari", $content);
