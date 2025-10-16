<?php
require_once("../src/config.php");
require_once("layout.php");

// Obtenir tots els recanvis actius
$stmt = $pdo->query("SELECT * FROM items WHERE active = 1 ORDER BY sku ASC");
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
        <th class="px-4 py-2 text-center">Vida útil</th>
        <th class="px-4 py-2 text-center">Plànol</th>
        <th class="px-4 py-2 text-right">Accions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($items as $item): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($item['sku']) ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($item['name']) ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($item['category']) ?></td>
        <td class="px-4 py-2 text-center"><?= htmlspecialchars($item['stock']) ?></td>
        <td class="px-4 py-2 text-center"><?= htmlspecialchars($item['min_stock']) ?></td>
        <td class="px-4 py-2 text-center">
          <?php if ($item['life_expectancy'] < 10): ?>
            <span class="text-red-600 font-bold"><?= $item['life_expectancy'] ?>%</span>
          <?php else: ?>
            <?= $item['life_expectancy'] ?>%
          <?php endif; ?>
        </td>
        
        <td class="px-4 py-2 text-center">
          <?php if ($item['plan_file']): ?>
            <a href="uploads/<?= htmlspecialchars($item['plan_file']) ?>" target="_blank" class="text-blue-600 hover:underline">📎 Obrir</a>
          <?php else: ?>
            <span class="text-gray-400">—</span>
          <?php endif; ?>
        </td>
        

        <td class="px-4 py-2 text-right space-x-2">
          <button 
            class="px-2 py-1 text-blue-600 hover:text-blue-800 text-sm font-medium"
            onclick="openEditModal(<?= htmlspecialchars($item['id']) ?>, '<?= htmlspecialchars($item['sku']) ?>', '<?= htmlspecialchars($item['name']) ?>', <?= htmlspecialchars($item['stock']) ?>, <?= htmlspecialchars($item['min_stock']) ?>, <?= htmlspecialchars($item['life_expectancy']) ?>)"
          >✏️ Editar</button>
          <button 
            class="px-2 py-1 text-red-600 hover:text-red-800 text-sm font-medium"
            onclick="deleteItem(<?= htmlspecialchars($item['id']) ?>)"
          >🗑️ Baixa</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Modal d'edició -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center">
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

      <label class="block mb-2 text-sm font-medium">Vida útil (%)</label>
      <input type="number" name="life_expectancy" id="edit-life" class="w-full mb-3 p-2 border rounded">

      <label class="block mb-2 text-sm font-medium">Plànol (PDF)</label>
        <input type="file" name="plan_file" accept="application/pdf" class="w-full mb-3 p-2 border rounded">
        <button type="button" 
          onclick="deletePlanFile()" 
          class="text-red-600 hover:text-red-800 text-sm mb-3">
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
