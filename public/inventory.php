<?php
require_once("../src/config.php");
require_once("layout.php");

// 🔹 Carregar totes les posicions definides al magatzem
$allPositions = $pdo->query("
    SELECT codi 
    FROM magatzem_posicions 
    ORDER BY codi ASC
")->fetchAll(PDO::FETCH_COLUMN);

/**
 * INVENTARI — Basat en `item_units`
 * - L’estoc total i per ubicació surt de item_units (estat='actiu')
 * - La vida útil i la localització ara són per unitat
 */

$stmt = $pdo->query("
  SELECT 
    i.id,
    i.sku,
    i.category,
    i.min_stock,
    i.plan_file,
    i.active,
    COALESCE(t.total_cnt, 0) AS total_stock,
    COALESCE(g.cnt_magatzem, 0) AS qty_magatzem,
    COALESCE(im.cnt_intermig, 0) AS qty_intermig,
    COALESCE(m.cnt_maquina, 0) AS qty_maquina
  FROM items i
  LEFT JOIN (
      SELECT item_id, COUNT(*) AS total_cnt
      FROM item_units WHERE estat='actiu' GROUP BY item_id
  ) t ON t.item_id = i.id
  LEFT JOIN (
      SELECT item_id, COUNT(*) AS cnt_magatzem
      FROM item_units WHERE estat='actiu' AND ubicacio='magatzem' GROUP BY item_id
  ) g ON g.item_id = i.id
  LEFT JOIN (
      SELECT item_id, COUNT(*) AS cnt_intermig
      FROM item_units WHERE estat='actiu' AND ubicacio='intermig' GROUP BY item_id
  ) im ON im.item_id = i.id
  LEFT JOIN (
      SELECT item_id, COUNT(*) AS cnt_maquina
      FROM item_units WHERE estat='actiu' AND ubicacio='maquina' GROUP BY item_id
  ) m ON m.item_id = i.id
  WHERE i.active = 1
  AND COALESCE(t.total_cnt, 0) > 0   -- 🔹 només items amb almenys 1 unitat activa
  ORDER BY i.sku ASC
");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** Unitats per ítem */
$unitsByItem = [];
$stmtUnits = $pdo->query("
  SELECT iu.*, i.sku
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  WHERE iu.estat = 'actiu'
  ORDER BY iu.serial ASC
");
foreach ($stmtUnits->fetchAll(PDO::FETCH_ASSOC) as $u) {
  $unitsByItem[$u['item_id']][] = $u;
}

ob_start();
?>
<h2 class="text-3xl font-bold mb-6">Inventari</h2>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['import_message'])): ?>
  <div class="mb-4 p-3 bg-blue-100 border border-blue-300 text-blue-800 rounded text-sm">
    <?= $_SESSION['import_message'] ?>
  </div>
  <?php unset($_SESSION['import_message']); ?>
<?php endif; ?>


<!-- Missatge d’èxit -->
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'unit_updated'): ?>
  <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded">
    ✅ Estanteria actualitzada correctament.
  </div>
<?php endif; ?>

<!-- Import / Export -->
<div class="flex items-center justify-between mb-4">
  <div></div>
  <div class="flex gap-3">
    <a href="../src/export_inventory.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded flex items-center gap-2">
      📤 <span>Exportar Excel</span>
    </a>

      <form id="import-form" action="../src/import_inventory.php" method="POST" enctype="multipart/form-data" class="flex items-center gap-2">
        <!-- aquí guardarem la contrasenya abans d’enviar -->
        <input type="hidden" name="import_password" id="import-password">

        <label class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded cursor-pointer flex items-center gap-2">
          📥 <span>Importar Excel</span>
          <input type="file" name="excel_file" id="import-file" accept=".xlsx" class="hidden">
        </label>
      </form>
  </div>
</div>

<!-- 📦 Taula principal -->
<div class="bg-white rounded-xl shadow p-4 overflow-x-auto">
  <table class="min-w-full text-sm text-left border-collapse">
    <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
      <tr>
        <th class="px-4 py-2">SKU</th>
        <th class="px-4 py-2">Categoria</th>
        <th class="px-4 py-2 text-center">Total</th>
        <th class="px-4 py-2 text-center">
          Ubicacions<br>
          <span class="text-[11px] text-gray-500">(MAG / INT / MAQ)</span>
        </th>
        <th class="px-4 py-2 text-center">Mínim</th>
        <th class="px-4 py-2 text-center">Plànol</th>
        <th class="px-4 py-2 text-right">Accions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($items as $item): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($item['sku']) ?></td>
        <td class="px-4 py-2"><?= htmlspecialchars($item['category']) ?></td>
        <td class="px-4 py-2 text-center font-semibold"><?= (int)$item['total_stock'] ?></td>
        <td class="px-4 py-2 text-center font-mono text-sm">
          <?= (int)$item['qty_magatzem'] ?> /
          <?= (int)$item['qty_intermig'] ?> /
          <?= (int)$item['qty_maquina'] ?>
        </td>
        <td class="px-4 py-2 text-center"><?= (int)$item['min_stock'] ?></td>
        <td class="px-4 py-2 text-center">
          <?php if (!empty($item['plan_file'])): ?>
            <a href="uploads/<?= htmlspecialchars($item['plan_file']) ?>" target="_blank" class="text-blue-600 hover:underline">📎 Obrir</a>
          <?php else: ?>
            <span class="text-gray-400">—</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-right">
          <div class="flex justify-end items-center gap-3">
           <button class="px-2 py-1 text-blue-600 hover:text-blue-800 text-sm font-medium"
              onclick='openItemModal(
                <?= (int)$item["id"] ?>,
                <?= json_encode($item["sku"]) ?>,
                <?= (int)$item["min_stock"] ?>,
                <?= json_encode($item["category"]) ?>,
                <?= json_encode($item["plan_file"]) ?>
              )'>
              ✏️ Editar
            </button>

            <button class="text-indigo-600 hover:text-indigo-800 text-sm font-medium" onclick="toggleUnits(<?= (int)$item['id'] ?>)">
              📦 Unitats
            </button>
          </div>
        </td>
      </tr>

      <!-- 🔽 Unitats -->
      <tr id="units-row-<?= $item['id'] ?>" class="hidden bg-gray-50">
        <td colspan="11" class="p-4">
          <?php if (!empty($unitsByItem[$item['id']])): ?>
          <table class="min-w-full text-xs text-left border border-gray-200">
            <thead class="bg-gray-100 text-gray-600 uppercase">
              <tr>
                <th class="px-3 py-1">Codi unitat</th>
                <th class="px-3 py-1">Ubicació</th>
                <th class="px-3 py-1 text-center">Estanteria</th>
                <th class="px-3 py-1 text-center">Cicles màquina</th>
                <th class="px-3 py-1 text-center">Vida útil</th>
                <th class="px-3 py-1 text-right">Accions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($unitsByItem[$item['id']] as $u): 
                  $lifeTotal = (int)($u['vida_total'] ?? 0);
                  $vidaUsada = (int)($u['vida_utilitzada'] ?? 0);
                  $vidaPercent = $lifeTotal > 0 ? max(0, 100 - floor(100 * $vidaUsada / $lifeTotal)) : null;
                ?>
                <tr class="border-t border-gray-100">
                  <td class="px-3 py-1 font-mono"><?= htmlspecialchars($u['serial']) ?></td>
                  <td class="px-3 py-1 capitalize">
                    <?= $u['ubicacio'] === 'maquina' && $u['maquina_actual'] 
                          ? 'Màquina ' . htmlspecialchars($u['maquina_actual']) 
                          : ucfirst(htmlspecialchars($u['ubicacio'])) ?>
                  </td>
                  <td class="px-3 py-1 text-center"><?= htmlspecialchars($u['sububicacio'] ?? '—') ?></td>
                  <td class="px-3 py-1 text-center"><?= (int)$u['cicles_maquina'] ?></td>
                  <td class="px-3 py-2 text-center">
                    <?php if ($vidaPercent !== null): ?>
                      <div class="flex flex-col items-center space-y-1">
                        <div class="flex items-center gap-1 text-xs font-semibold text-gray-700">
                          <span><?= $vidaPercent ?>%</span>
                        </div>
                        <div class="w-28 bg-gray-200 rounded-full h-2 overflow-hidden">
                          <div class="h-2 rounded-full <?= $vidaPercent <= 10 ? 'bg-red-500' : ($vidaPercent <= 30 ? 'bg-yellow-400' : 'bg-green-500') ?>" style="width: <?= $vidaPercent ?>%;"></div>
                        </div>
                        <div class="text-[11px] text-gray-500 mt-0.5">
                          Usades: <?= $vidaUsada ?> / Teòriques: <?= $lifeTotal ?>
                        </div>
                      </div>
                    <?php else: ?>
                      <div class="text-[12px] text-gray-600 italic">Sense dades de vida útil</div>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-1 text-right flex justify-end gap-2">
                    <!-- ✏️ Editar -->
                    <button 
                      class="text-blue-600 hover:text-blue-800"
                      onclick='openUnitModal(
                        <?= (int)$u["id"] ?>, 
                        <?= json_encode($u["serial"]) ?>, 
                        <?= json_encode($u["sububicacio"] ?? "") ?>,
                        <?= json_encode($u["vida_total"] ?? "") ?>
                      )'
                    >✏️</button>

                   <!-- 🗑️ Donar de baixa unitat -->
                    <button 
                      type="button"
                      class="text-red-600 hover:text-red-800"
                      onclick='openBaixaModal(<?= (int)$u["id"] ?>, <?= json_encode($u["serial"]) ?>)'
                    >🗑️</button>

                  </td>

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

<!-- 🧾 Modal ITEM -->
<div id="editItemModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-6">
    <h3 class="text-lg font-bold mb-4">Editar recanvi</h3>

    <form id="editItemForm" method="POST" action="../src/update_item.php" enctype="multipart/form-data">
      <input type="hidden" name="id" id="edit-item-id">

      <!-- SKU -->
      <label class="block mb-2 text-sm font-medium">SKU</label>
      <input type="text" id="edit-item-sku" class="w-full mb-1 p-2 border rounded bg-gray-100 text-gray-700" readonly>
      
      <!-- Categoria -->
      <label class="block mb-2 text-sm font-medium">Categoria</label>
      <input type="text" name="category" id="edit-item-category" class="w-full mb-3 p-2 border rounded" placeholder="Ex: Camises, Punzons...">

      <!-- Estoc mínim -->
      <label class="block mb-2 text-sm font-medium">Estoc mínim</label>
      <input type="number" name="min_stock" id="edit-item-min_stock" class="w-full mb-3 p-2 border rounded">

      <!-- Plànol -->
      <label class="block mb-2 text-sm font-medium">Plànol actual</label>
      <div id="plan-file-container" class="mb-3 text-sm text-gray-700">
        <span id="plan-file-info" class="italic text-gray-500">Sense plànol adjunt</span>
        <button type="button" id="delete-plan-btn" class="hidden ml-2 text-red-600 hover:underline">🗑️ Eliminar</button>
      </div>

      <label class="block mb-2 text-sm font-medium">Pujar nou plànol</label>
      <input type="file" name="plan_file" id="edit-item-plan_file" accept=".pdf,.png,.jpg,.jpeg,.dwg" class="w-full mb-4 p-2 border rounded bg-gray-50">

      <!-- Botons -->
      <div class="flex justify-end space-x-2 mt-4">
        <button type="button" onclick="closeItemModal()" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Cancel·lar</button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Guardar</button>
      </div>
    </form>
  </div>
</div>


<!-- 🧩 Modal UNIT -->
<div id="editUnitModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-sm p-6">
    <h3 class="text-lg font-bold mb-4">Editar unitat</h3>
    <form id="editUnitForm" method="POST" action="../src/update_unit.php">
      <input type="hidden" name="id" id="edit-unit-id">

      <div class="mb-4">
        <label class="block mb-1 font-medium">Codi unitat (serial)</label>
        <input type="text" id="edit-unit-serial" disabled class="w-full p-2 border rounded bg-gray-100 text-gray-600">
      </div>

      <div class="mb-4">
        <label class="block mb-1 font-medium">Posició magatzem</label>
        <input
          type="text"
          name="sububicacio"
          id="edit-unit-sububicacio"
          class="w-full p-2 border rounded"
          list="llista-sububicacions"
          placeholder="Ex: 01A01"
        >
        <p class="text-xs text-gray-500 mt-1">
          Tria una posició existent del magatzem. Cada posició només pot tenir una unitat.
        </p>
      </div>

      <div class="mb-4">
        <label class="block mb-1 font-medium">Vida útil teòrica</label>
        <input type="number" name="vida_total" id="edit-unit-total" class="w-full p-2 border rounded" min="0" placeholder="Ex: 100">
        <p class="text-xs text-gray-500 mt-1">Aquest valor s'utilitza per calcular el percentatge de vida restant.</p>
      </div>

      <div class="flex justify-end space-x-2">
        <button type="button" onclick="closeUnitModal()" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Cancel·lar</button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- 🗑️ Modal BAIXA UNITAT -->
<div id="baixaModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center z-50">
  <div class="bg-white rounded-lg shadow-lg w-full max-w-sm p-6">
    <h3 class="text-lg font-bold mb-4">Donar de baixa unitat</h3>
    <form id="baixaForm" method="POST" action="../src/update_unit.php">
      <input type="hidden" name="action" value="baixa_unitat">
      <input type="hidden" name="id" id="baixa-unit-id">

      <p class="mb-3 text-gray-700 text-sm">
        Vols donar de baixa la unitat <span id="baixa-unit-serial" class="font-mono font-semibold text-blue-700"></span>?
      </p>

      <div class="mb-4">
        <label class="block mb-1 font-medium">Motiu de baixa</label>
        <select name="baixa_motiu" id="baixa-motiu" required class="w-full p-2 border rounded">
          <option value="">Selecciona...</option>
          <option value="descatalogat">Descatalogat</option>
          <option value="malmesa">Camisa malmesa</option>
          <option value="fi_vida_util">Fi de vida útil</option>
          <option value="altres">Altres</option>
        </select>
      </div>

      <div class="flex justify-end space-x-2">
        <button type="button" onclick="closeBaixaModal()" class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">Cancel·lar</button>
        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">Confirmar baixa</button>
      </div>
    </form>
  </div>
</div>

<datalist id="llista-sububicacions">
  <?php foreach ($allPositions as $pos): ?>
    <option value="<?= htmlspecialchars($pos) ?>"></option>
  <?php endforeach; ?>
</datalist>


<script>
function openItemModal(id, sku, min_stock, category, plan_file) {
  document.getElementById('edit-item-id').value = id;
  document.getElementById('edit-item-sku').value = sku;
  document.getElementById('edit-item-min_stock').value = min_stock;
  document.getElementById('edit-item-category').value = category || '';

  const info = document.getElementById('plan-file-info');
  const delBtn = document.getElementById('delete-plan-btn');

  if (plan_file) {
    info.innerHTML = `<a href="uploads/${plan_file}" target="_blank" class="text-blue-600 hover:underline">${plan_file}</a>`;
    delBtn.classList.remove('hidden');
    delBtn.onclick = () => {
      if (confirm("Vols eliminar aquest plànol?")) {
        fetch('../src/delete_plan.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'id=' + encodeURIComponent(id)
        }).then(res => res.json()).then(data => {
          if (data.success) {
            info.textContent = "Sense plànol adjunt";
            delBtn.classList.add('hidden');
          } else {
            alert("❌ " + (data.error || "No s'ha pogut eliminar"));
          }
        });
      }
    };
  } else {
    info.textContent = "Sense plànol adjunt";
    delBtn.classList.add('hidden');
  }

  document.getElementById('editItemModal').classList.remove('hidden');
}

function closeItemModal() {
  const modal = document.getElementById('editItemModal');
  if (!modal) return;
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}



function openUnitModal(id, serial, sububicacio = '', vida_total = '') {
  document.getElementById('edit-unit-id').value = id;
  document.getElementById('edit-unit-serial').value = serial;
  document.getElementById('edit-unit-sububicacio').value = sububicacio || '';
  document.getElementById('edit-unit-total').value = vida_total || '';
  document.getElementById('editUnitModal').classList.remove('hidden');
}

function closeUnitModal() {
  document.getElementById('editUnitModal').classList.add('hidden');
}

function openBaixaModal(id, serial) {
  document.getElementById('baixa-unit-id').value = id;
  document.getElementById('baixa-unit-serial').textContent = serial;
  document.getElementById('baixaModal').classList.remove('hidden');
}

function closeBaixaModal() {
  document.getElementById('baixaModal').classList.add('hidden');
}

function toggleUnits(id) {
  const row = document.getElementById('units-row-' + id);
  if (row) row.classList.toggle('hidden');
}

function deleteItem(id) {
  if (!confirm("Segur que vols donar de baixa aquest recanvi?")) return;
  fetch('../src/delete_item.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'id=' + encodeURIComponent(id)
  }).then(res => res.json())
    .then(data => {
      if (data.success) location.reload();
      else alert('❌ Error: ' + (data.error || 'No s’ha pogut donar de baixa'));
    }).catch(err => alert('❌ ' + err.message));
}

// 🔐 Gestió de la contrasenya d'importació d'Excel
const importForm = document.getElementById('import-form');
const importFile = document.getElementById('import-file');
const importPwd  = document.getElementById('import-password');

if (importForm && importFile && importPwd) {
  importFile.addEventListener('change', function () {
    if (!this.files || this.files.length === 0) return;

    const pwd = prompt("Introdueix la contrasenya d'importació:");
    if (!pwd) {
      // Cancel·lat → esborrem el fitxer perquè no s'enviï res
      this.value = '';
      importPwd.value = '';
      return;
    }

    // ❗No validem aquí: només l'enviem al servidor
    importPwd.value = pwd;
    importForm.submit();
  });
}

</script>

<?php
$content = ob_get_clean();
renderPage("Inventari", $content);
