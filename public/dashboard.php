<?php
require_once("../src/config.php");

if (!$pdo) {
    die("❌ Error: la connexió \$pdo no s'ha creat correctament.");
}

// ---------- CONSULTES (esquema nou amb item_units) ----------

// 1) Total recanvis (unitats físiques actives, sumant magatzem + intermig + màquines)
$total_stock = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM item_units 
    WHERE estat = 'actiu'
")->fetchColumn();

// 2) Nombre d’SKUs (si ho vols mantenir com a metrico opcional)
$total_items = (int)$pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();

// 3) Recanvis amb estoc baix (compta totes les unitats actives de cada SKU)
$low_stock = (int)$pdo->query("
    SELECT COUNT(*)
    FROM items i
    LEFT JOIN (
        SELECT item_id, COUNT(*) AS total_cnt
        FROM item_units
        WHERE estat = 'actiu'
        GROUP BY item_id
    ) u ON u.item_id = i.id
    WHERE COALESCE(u.total_cnt, 0) < i.min_stock
")->fetchColumn();

// 4) Unitats ubicades a màquines
$machine_items = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM item_units 
    WHERE estat = 'actiu' AND ubicacio = 'maquina'
")->fetchColumn();

// 5) Vida útil real (<10%) a partir d’items (igual que ja feies)
$stmt = $pdo->query("
    SELECT id, sku, name, life_expectancy, vida_utilitzada 
    FROM items 
    WHERE active = 1
");
$allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$low_life = 0;
$items_low_life = [];
foreach ($allItems as $item) {
    $used  = (int)$item['vida_utilitzada'];
    $total = max(1, (int)$item['life_expectancy']);
    $vida_percent = max(0, 100 - floor(100 * $used / $total));

    if ($vida_percent < 10) {
        $low_life++;
        $items_low_life[] = [
            'sku' => $item['sku'],
            'name' => $item['name'],
            'vida_percent' => $vida_percent
        ];
    }
}

// 6) Llista detallada dels que estan per sota del mínim (amb desglossament per ubicació)
$items_low_stock = $pdo->query("
  SELECT 
    i.id, i.sku, i.name, i.category, i.min_stock,

    COALESCE(t.total_cnt, 0)          AS total_stock,
    COALESCE(m.cnt_maquina, 0)        AS qty_maquina,
    COALESCE(g.cnt_magatzem, 0)       AS qty_magatzem,
    COALESCE(im.cnt_intermig, 0)      AS qty_intermig

  FROM items i

  LEFT JOIN (
      SELECT item_id, COUNT(*) AS total_cnt
      FROM item_units
      WHERE estat = 'actiu'
      GROUP BY item_id
  ) t ON t.item_id = i.id

  LEFT JOIN (
      SELECT item_id, COUNT(*) AS cnt_maquina
      FROM item_units
      WHERE estat = 'actiu' AND ubicacio = 'maquina'
      GROUP BY item_id
  ) m ON m.item_id = i.id

  LEFT JOIN (
      SELECT item_id, COUNT(*) AS cnt_magatzem
      FROM item_units
      WHERE estat = 'actiu' AND ubicacio = 'magatzem'
      GROUP BY item_id
  ) g ON g.item_id = i.id

  LEFT JOIN (
      SELECT item_id, COUNT(*) AS cnt_intermig
      FROM item_units
      WHERE estat = 'actiu' AND ubicacio = 'intermig'
      GROUP BY item_id
  ) im ON im.item_id = i.id

  WHERE COALESCE(t.total_cnt, 0) < i.min_stock
  ORDER BY COALESCE(t.total_cnt, 0) ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 7) Top 10 (es manté tal qual)
$top_used = $pdo
    ->query("SELECT * FROM items ORDER BY created_at ASC LIMIT 10")
    ->fetchAll(PDO::FETCH_ASSOC);

// 8) Peticions pendents (igual)
$peticionsPendents = $pdo
    ->query("SELECT * FROM peticions WHERE estat = 'pendent' ORDER BY created_at ASC")
    ->fetchAll(PDO::FETCH_ASSOC);


ob_start();

?>

<h1 class="text-3xl font-bold mb-2">Indicadors</h1>
<p class="text-gray-500 mb-8">Vista general de l'estat de l'inventari</p>

<!-- TARGETES KPI -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
  <div class="card">
    <h3>Total recanvis</h3>
    <div class="value text-blue-600"><?= $total_stock ?></div>
  </div>
  <div class="card">
    <h3>Recanvis amb estoc baix</h3>
    <div class="value text-yellow-600"><?= $low_stock ?></div>
  </div>
  <div class="card">
    <h3>Vida útil &lt;10%</h3>
    <div class="value text-red-600"><?= $low_life ?></div>
  </div>
  <div class="card">
    <h3>Recanvis a màquines</h3>
    <div class="value text-green-600"><?= $machine_items ?></div>
  </div>
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
            <span><?= htmlspecialchars($item['sku']) ?> - <?= htmlspecialchars($item['name']) ?></span>
            <span class="text-red-600 font-semibold"
            title="Magatzem: <?= $item['stock'] ?> | Màquines: <?= $item['qty_maquina'] ?> | Intermig: <?= $item['qty_intermig'] ?>">  
            <?= (int)$item['total_stock'] ?> / min <?= (int)$item['min_stock'] ?></span>
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
          <div class="flex justify-between py-1 text-sm">
            <span><?= htmlspecialchars($it['sku']) ?></span>
            <span class="text-red-600 font-semibold"><?= $it['vida_percent'] ?>%</span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </div>
</div>

<!-- Botó flotant peticions -->
<button id="toggleSidebarBtn" class="fixed top-5 right-5 bg-blue-600 text-white rounded-full px-4 py-2 flex items-center gap-2 shadow-lg hover:bg-blue-700 transition">
  📋
  <span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded-full"><?= count($peticionsPendents) ?></span>
</button>

<!-- Sidebar peticions -->
<div id="peticionsSidebar" class="fixed top-0 right-0 w-[32rem] bg-white shadow-lg transform translate-x-full transition-transform z-50">
  <div class="p-4 border-b flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <h3 class="text-lg font-bold flex items-center gap-2">
      📋 Peticions pendents
      <?php if (count($peticionsPendents) > 0): ?>
        <span class="bg-red-600 text-white text-xs px-2 py-1 rounded">
          <?= count($peticionsPendents) ?>
        </span>
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

  <!-- Llista de peticions -->
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
                  <button
                    type="button"
                    class="bg-green-500 hover:bg-green-600 text-white p-1.5 rounded-full serveix-btn"
                    data-id="<?= $p['id'] ?>"
                    title="Servir"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                  </button>

                  <button
                    type="button"
                    class="bg-red-500 hover:bg-red-600 text-white p-1.5 rounded-full anula-btn"
                    data-id="<?= $p['id'] ?>"
                    title="Anul·lar"
                  >
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





<!-- GRÀFIC -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  const sidebar = document.getElementById('peticionsSidebar');
  const toggleBtn = document.getElementById('toggleSidebarBtn');
  const closeBtn = document.getElementById('closeSidebarBtn');
  const filtre = document.getElementById('filtreMaquina');

  let isSidebarOpen = false;

  // Obrir / tancar amb el botó flotant
  toggleBtn.addEventListener('click', () => {
    isSidebarOpen = !isSidebarOpen;
    sidebar.classList.toggle('translate-x-full', !isSidebarOpen);
  });

  // Tancar amb la X
  closeBtn.addEventListener('click', () => {
    isSidebarOpen = false;
    sidebar.classList.add('translate-x-full');
  });

  // Filtrar per màquina
  filtre.addEventListener('change', (e) => {
    const val = e.target.value;
    document.querySelectorAll('#taulaPeticions tbody tr').forEach(tr => {
      tr.style.display = (val === '' || tr.dataset.maquina === val) ? '' : 'none';
    });
  });
</script>

<?php
$content = ob_get_clean();
require_once("layout.php");
renderPage("Dashboard", $content);

