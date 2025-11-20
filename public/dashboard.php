<?php
require_once("../src/config.php");

if (!$pdo) {
    die("❌ Error: la connexió \$pdo no s'ha creat correctament.");
}

// ---------- CONSULTES ----------
$total_stock = (int)$pdo->query("SELECT COUNT(*) FROM item_units WHERE estat='actiu'")->fetchColumn();
$total_items = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE active=1")->fetchColumn();
$low_stock = (int)$pdo->query("
  SELECT SUM(i.min_stock - COALESCE(u.total_cnt, 0)) AS faltants
  FROM items i
  LEFT JOIN (
    SELECT item_id, COUNT(*) AS total_cnt
    FROM item_units
    WHERE estat='actiu'
    GROUP BY item_id
  ) u ON u.item_id = i.id
  WHERE COALESCE(u.total_cnt, 0) < i.min_stock
")->fetchColumn();
$machine_items = (int)$pdo->query("
  SELECT COUNT(*) FROM item_units WHERE estat='actiu' AND ubicacio='maquina'
")->fetchColumn();

// Vida útil <10%
$stmt = $pdo->query("
  SELECT iu.id, i.sku, iu.vida_utilitzada, iu.vida_total
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  WHERE iu.estat='actiu'
");
$low_life = 0;
$items_low_life = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
  $used  = (int)$u['vida_utilitzada'];
  $total = max(1, (int)$u['vida_total']);
  $vida  = max(0, 100 - floor(100 * $used / $total));
  if ($vida < 10) {
    $low_life++;
    $items_low_life[] = [
      'sku'          => $u['sku'],
      'vida_percent' => $vida
    ];
  }
}

// Estoc sota mínim
$items_low_stock = $pdo->query("
  SELECT i.id, i.sku, i.min_stock,
         COALESCE(t.total_cnt, 0) AS total_stock
  FROM items i
  LEFT JOIN (
    SELECT item_id, COUNT(*) AS total_cnt
    FROM item_units
    WHERE estat='actiu'
    GROUP BY item_id
  ) t ON t.item_id = i.id
  WHERE COALESCE(t.total_cnt, 0) < i.min_stock
  ORDER BY COALESCE(t.total_cnt, 0)
")->fetchAll(PDO::FETCH_ASSOC);

// Peticions pendents
$peticionsPendents = $pdo
  ->query("SELECT * FROM peticions WHERE estat='pendent' ORDER BY created_at ASC")
  ->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<h1 class="text-3xl font-bold mb-2">Indicadors</h1>
<p class="text-gray-500 mb-8">Vista general de l'estat de l'inventari</p>

<!-- TARGETES KPI -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
  <div class="card"><h3>Total recanvis</h3><div class="value text-blue-600"><?= $total_stock ?></div></div>
  <div class="card"><h3>Recanvis amb estoc baix</h3><div class="value text-yellow-600"><?= $low_stock ?></div></div>
  <div class="card"><h3>Vida útil &lt;10%</h3><div class="value text-red-600"><?= $low_life ?></div></div>
  <div class="card"><h3>Recanvis a màquines</h3><div class="value text-green-600"><?= $machine_items ?></div></div>
</div>

<!-- SECCIONS INFERIORS -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- Estoc sota mínim -->
  <div class="card col-span-1">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-semibold text-yellow-700 flex items-center gap-2">⚠️ Estoc per sota del mínim</h3>
      <?php if (count($items_low_stock) > 0): ?>
        <span class="bg-red-600 text-white text-xs px-2 py-0.5 rounded"><?= count($items_low_stock) ?></span>
      <?php endif; ?>
    </div>
    <ul class="divide-y divide-gray-100">
      <?php if (empty($items_low_stock)): ?>
        <li class="py-2 text-gray-400 text-sm">No hi ha cap recanvi sota mínim 👌</li>
      <?php else: ?>
        <?php foreach ($items_low_stock as $item): ?>
          <li class="py-2 flex justify-between text-sm">
            <span><?= htmlspecialchars($item['sku']) ?></span>
            <span class="text-red-600 font-semibold">
              <?= (int)$item['total_stock'] ?> / min <?= (int)$item['min_stock'] ?>
            </span>
          </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </div>

  <!-- Top més utilitzats -->
  <div class="card col-span-1">
    <h3 class="font-semibold text-blue-700 mb-3">🏆 Top 10 més utilitzats</h3>
    <canvas id="topChart"></canvas>
  </div>

  <!-- Vida útil <10% -->
  <div class="card col-span-1">
    <h3 class="font-semibold text-red-700 mb-3">❤️ Vida útil &lt;10%</h3>
    <ul class="divide-y divide-gray-100">
      <?php if (empty($items_low_life)): ?>
        <li class="py-2 text-gray-400 text-sm">Tots els recanvis tenen vida útil suficient 👌</li>
      <?php else: ?>
        <?php foreach ($items_low_life as $it): ?>
          <li class="py-1 flex justify-between text-sm">
            <span><?= htmlspecialchars($it['sku']) ?></span>
            <span class="text-red-600 font-semibold"><?= $it['vida_percent'] ?>%</span>
          </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </div>
</div>

<!-- Botó flotant -->
<button id="toggleSidebarBtn" class="fixed top-5 right-5 bg-blue-600 text-white rounded-full px-4 py-2 flex items-center gap-2 shadow-lg hover:bg-blue-700 transition">
  📋 <span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded-full"><?= count($peticionsPendents) ?></span>
</button>

<!-- Sidebar -->
<div id="peticionsSidebar" class="fixed top-0 right-0 w-[32rem] bg-white shadow-lg transform translate-x-full transition-transform z-50">
  <div class="p-4 border-b flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <h3 class="text-lg font-bold flex items-center gap-2">
      📋 Peticions pendents
      <?php if (count($peticionsPendents) > 0): ?>
        <span class="bg-red-600 text-white text-xs px-2 py-1 rounded"><?= count($peticionsPendents) ?></span>
      <?php endif; ?>
    </h3>
    <div class="flex items-center gap-2">
      <select id="filtreMaquina" class="border rounded p-1 text-sm">
        <option value="">Totes</option>
        <?php
        $maquines = $pdo->query("SELECT codi FROM maquines ORDER BY codi ASC")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($maquines as $maq): ?>
          <option value="<?= htmlspecialchars($maq) ?>"><?= htmlspecialchars($maq) ?></option>
        <?php endforeach; ?>
      </select>
      <button id="closeSidebarBtn" class="text-gray-500 hover:text-gray-700 text-xl leading-none">&times;</button>
    </div>
  </div>

  <div class="p-4">
    <?php if (count($peticionsPendents) > 0): ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left" id="taulaPeticions">
          <thead class="bg-gray-100 uppercase text-xs text-gray-600">
            <tr>
              <th class="px-4 py-2">Màquina</th>
              <th class="px-4 py-2">SKU</th>
              <th class="px-4 py-2">Data</th>
              <th class="px-4 py-2 text-center">Accions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($peticionsPendents as $p): ?>
              <tr data-maquina="<?= htmlspecialchars($p['maquina']) ?>">
                <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($p['maquina']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($p['sku']) ?></td>
                <td class="px-4 py-2"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
                <td class="px-4 py-2 text-center flex justify-center gap-2">
                  <button type="button" class="serveix-btn bg-green-500 hover:bg-green-600 text-white p-1.5 rounded-full" data-id="<?= $p['id'] ?>" title="Servir">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                  </button>
                  <button type="button" class="anula-btn bg-red-500 hover:bg-red-600 text-white p-1.5 rounded-full" data-id="<?= $p['id'] ?>" title="Anul·lar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-gray-500">No hi ha peticions pendents 👌</p>
    <?php endif; ?>
  </div>
</div>

<!-- JS del sidebar -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.getElementById('peticionsSidebar');
  const toggleBtn = document.getElementById('toggleSidebarBtn');
  const closeBtn = document.getElementById('closeSidebarBtn');
  const filtre = document.getElementById('filtreMaquina');
  let isSidebarOpen = false;
  toggleBtn.addEventListener('click', () => {
    isSidebarOpen = !isSidebarOpen;
    sidebar.classList.toggle('translate-x-full', !isSidebarOpen);
  });
  closeBtn.addEventListener('click', () => {
    isSidebarOpen = false;
    sidebar.classList.add('translate-x-full');
  });
  filtre.addEventListener('change', (e) => {
    const val = e.target.value;
    document.querySelectorAll('#taulaPeticions tbody tr').forEach(tr => {
      tr.style.display = (val === '' || tr.dataset.maquina === val) ? '' : 'none';
    });
  });
});
</script>

<?php
$content = ob_get_clean();
$extraScripts = '<script src="js/dashboard.js"></script>';
require_once("layout.php");
renderPage("Dashboard", $content, $extraScripts);
?>
