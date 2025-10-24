<?php
require_once("../src/config.php");
require_once("layout.php");

/**
 * INVENTARI — Nova versió basada en `item_units`
 * 
 * - Cada ítem (SKU) pot tenir diverses unitats físiques (item_units)
 * - Es mostra estoc total i desglossat per ubicació
 * - La vida útil segueix basada en `items.life_expectancy` i `vida_utilitzada`
 */

$stmt = $pdo->query("
  SELECT 
    i.id,
    i.sku,
    i.name,
    i.category,
    i.min_stock,
    i.life_expectancy,
    i.vida_utilitzada,
    i.plan_file,
    i.active,
    i.location,

    COALESCE(t.total_cnt, 0) AS total_stock,
    COALESCE(g.cnt_magatzem, 0) AS qty_magatzem,
    COALESCE(im.cnt_intermig, 0) AS qty_intermig,
    COALESCE(m.cnt_maquina, 0) AS qty_maquina

  FROM items i
  LEFT JOIN (
      SELECT item_id, COUNT(*) AS total_cnt
      FROM item_units
      WHERE estat='actiu'
      GROUP BY item_id
  ) t ON t.item_id = i.id
  LEFT JOIN (
      SELECT item_id, COUNT(*) AS cnt_magatzem
      FROM item_units
      WHERE estat='actiu' AND ubicacio='magatzem'
      GROUP BY item_id
  ) g ON g.item_id = i.id
  LEFT JOIN (
      SELECT item_id, COUNT(*) AS cnt_intermig
      FROM item_units
      WHERE estat='actiu' AND ubicacio='intermig'
      GROUP BY item_id
  ) im ON im.item_id = i.id
  LEFT JOIN (
      SELECT item_id, COUNT(*) AS cnt_maquina
      FROM item_units
      WHERE estat='actiu' AND ubicacio='maquina'
      GROUP BY item_id
  ) m ON m.item_id = i.id
  WHERE i.active = 1
  ORDER BY i.sku ASC
");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Recuperem totes les unitats per cada ítem
$unitsByItem = [];
$stmtUnits = $pdo->query("
  SELECT iu.*, i.life_expectancy
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  ORDER BY iu.serial ASC
");
foreach ($stmtUnits->fetchAll(PDO::FETCH_ASSOC) as $u) {
  $unitsByItem[$u['item_id']][] = $u;
}
ob_start();
?>
<h2 class="text-3xl font-bold mb-6">Inventari</h2>

<!-- Missatge importació -->
<?php
session_start();
if (!empty($_SESSION['import_message'])) {
    echo '<div class="mb-4 p-3 rounded border bg-green-50 text-green-700">';
    echo $_SESSION['import_message'];
    echo '</div>';
    unset($_SESSION['import_message']);
}
?>

<!-- Botons Importar / Exportar -->
<div class="flex items-center justify-between mb-4">
  <div></div>
  <div class="flex gap-3">
    <a href="../src/export_inventory.php" 
       class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded flex items-center gap-2">
      📤 <span>Exportar Excel</span>
    </a>

    <form action="../src/import_inventory.php" method="POST" enctype="multipart/form-data" class="flex items-center gap-2">
      <label class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded cursor-pointer flex items-center gap-2">
        📥 <span>Importar Excel</span>
        <input type="file" name="excel_file" accept=".xlsx" class="hidden" onchange="this.form.submit()">
      </label>
    </form>
  </div>
</div>

<div class="bg-white rounded-xl shadow p-4 overflow-x-auto">
  <table class="min-w-full text-sm text-left border-collapse">
    <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
      <tr>
        <th class="px-4 py-2">SKU</th>
        <th class="px-4 py-2">Nom</th>
        <th class="px-4 py-2">Categoria</th>
        <th class="px-4 py-2 text-center">Total</th>
        <th class="px-4 py-2 text-center">Magatzem</th>
        <th class="px-4 py-2 text-center">Intermig</th>
        <th class="px-4 py-2 text-center">Màquina</th>
        <th class="px-4 py-2 text-center">Mínim</th>
        <!-- <th class="px-4 py-2">Vida útil</th> -->
        <th class="px-4 py-2 text-center">Plànol</th>
        <th class="px-4 py-2 text-right">Accions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($items as $item): ?>
      <?php
        $used = (int)$item['vida_utilitzada'];
        $total = max(1, (int)$item['life_expectancy']);
        $vp = max(0, 100 - floor(100 * $used / $total));
        $barClass = $vp <= 10 ? 'bg-red-500' : ($vp <= 30 ? 'bg-yellow-500' : 'bg-green-500');
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($item['sku']) ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($item['name']) ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($item['category']) ?></td>
        <td class="px-4 py-2 text-center font-semibold"><?= $item['total_stock'] ?></td>
        <td class="px-4 py-2 text-center"><?= $item['qty_magatzem'] ?></td>
        <td class="px-4 py-2 text-center"><?= $item['qty_intermig'] ?></td>
        <td class="px-4 py-2 text-center"><?= $item['qty_maquina'] ?></td>
        <td class="px-4 py-2 text-center"><?= $item['min_stock'] ?></td>

        <!-- <td class="px-4 py-2">
          <div class="flex items-center gap-2">
            <div class="w-32 bg-gray-200 rounded-full h-2">
              <div class="<?= $barClass ?> h-2 rounded-full" style="width: <?= $vp ?>%;"></div>
            </div>
            <span class="text-sm <?= $vp <= 10 ? 'text-red-600 font-semibold' : '' ?>"><?= $vp ?>%</span>
          </div>
          <div class="text-xs text-gray-400 mt-1">
            Usades: <?= $used ?> / Teòriques: <?= $total ?>
          </div>
        </td> -->

        <td class="px-4 py-2 text-center">
          <?php if (!empty($item['plan_file'])): ?>
            <a href="uploads/<?= htmlspecialchars($item['plan_file']) ?>" target="_blank" class="text-blue-600 hover:underline">📎 Obrir</a>
          <?php else: ?>
            <span class="text-gray-400">—</span>
          <?php endif; ?>
        </td>

       <td class="px-4 py-2 text-right">
          <div class="flex justify-end items-center gap-3">
            <button 
              class="px-2 py-1 text-blue-600 hover:text-blue-800 text-sm font-medium"
              onclick='openEditModal(
                <?= (int)$item["id"] ?>,
                <?= json_encode($item["sku"]) ?>,
                <?= json_encode($item["name"]) ?>,
                <?= (int)$item["stock"] ?>,
                <?= (int)$item["min_stock"] ?>,
                <?= (int)$item["life_expectancy"] ?>,
                <?= json_encode($item["location"]) ?>
              )'
            >✏️ <span>Editar</span></button>

            <button 
              class="flex items-center gap-1 text-indigo-600 hover:text-indigo-800 text-sm font-medium"
              onclick="toggleUnits(<?= (int)$item['id'] ?>)"
            >
              📦 <span>Unitats</span>
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
      <tr id="units-row-<?= $item['id'] ?>" class="hidden bg-gray-50">
  <td colspan="11" class="p-4">
    <?php if (!empty($unitsByItem[$item['id']])): ?>
      <table class="min-w-full text-xs text-left border border-gray-200">
        <thead class="bg-gray-100 text-gray-600 uppercase">
          <tr>
            <th class="px-3 py-1">Codi unitat</th>
            <th class="px-3 py-1">Ubicació</th>
            <!-- <th class="px-3 py-1">Màquina actual</th> -->
            <th class="px-3 py-1 text-center">Canvis màquina</th>
            <th class="px-3 py-1 text-center">Vida útil</th>
            <th class="px-3 py-1 text-center">Estat</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($unitsByItem[$item['id']] as $u): 
            $total = max(1, (int)$u['life_expectancy']);
            $vidaPercent = max(0, 100 - floor(100 * $u['vida_utilitzada'] / $total));
            $color = $vidaPercent <= 10 ? 'text-red-600' : ($vidaPercent <= 30 ? 'text-yellow-600' : 'text-green-600');
          ?>
          <tr class="border-t border-gray-100">
            <td class="px-3 py-1 font-mono"><?= htmlspecialchars($u['serial']) ?></td>
            <td class="px-3 py-1 capitalize">
              <?php
                if ($u['ubicacio'] === 'maquina' && !empty($u['maquina_actual'])) {
                  echo 'Màquina ' . htmlspecialchars($u['maquina_actual']);
                } else {
                  echo ucfirst(htmlspecialchars($u['ubicacio']));
                }
              ?>
            </td>
            <td class="px-3 py-1 text-center"><?= (int)$u['cicles_maquina'] ?></td>
            <td class="px-3 py-1 text-center <?= $color ?> font-semibold"><?= $vidaPercent ?>%</td>
            <td class="px-3 py-1 text-center"><?= htmlspecialchars($u['estat']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="text-gray-500 text-sm italic">No hi ha unitats registrades per aquest recanvi.</p>
    <?php endif; ?>
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

      <label class="block mb-2 text-sm font-medium">Estoc mínim</label>
      <input type="number" name="min_stock" id="edit-min_stock" class="w-full mb-3 p-2 border rounded">

      <label class="block mb-2 text-sm font-medium">Vida útil teòrica (unitats)</label>
      <input type="number" name="life_expectancy" id="edit-life" class="w-full mb-3 p-2 border rounded" min="0">

      <label class="block mb-2 text-sm font-medium">Posició (estanteria)</label>
      <input type="text" name="location" id="edit-location" class="w-full mb-3 p-2 border rounded">

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
  function openEditModal(id, sku, name, min_stock, life, location) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-name').value = name;
    document.getElementById('edit-min_stock').value = min_stock;
    document.getElementById('edit-life').value = life;
    document.getElementById('edit-location').value = location || '';
    document.getElementById('editModal').classList.remove('hidden');
  }

  function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
  }

   function deleteItem(id) {
    if (!confirm("Segur que vols donar de baixa aquest recanvi?")) return;

    fetch('../src/delete_item.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(id)
    })
    .then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) {
        const msg = (data && data.error) ? data.error : 'Error desconegut';
        throw new Error(msg);
      }
      // Opcional: efecte visual abans de recarregar
      // document.querySelector(`tr[data-id="${id}"]`)?.remove();
      location.reload();
    })
    .catch(err => {
      alert('❌ No s’ha pogut donar de baixa: ' + err.message);
    });
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

  function toggleUnits(id) {
  const row = document.getElementById('units-row-' + id);
  if (!row) return;
  row.classList.toggle('hidden');
}

</script>

<?php
$content = ob_get_clean();
renderPage("Inventari", $content);
?>
