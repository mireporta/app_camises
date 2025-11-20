<?php
require_once("../src/config.php");
require_once("layout.php");

/* === 🧾 PARÀMETRES DE FILTRE === */
$search = trim($_GET['search'] ?? '');
$motiu = trim($_GET['motiu'] ?? '');
$date_from = $_GET['from'] ?? '';
$date_to = $_GET['to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

/* === 📊 COMPTADORS PER TIPUS DE BAIXA === */
$counts = $pdo->query("
    SELECT baixa_motiu, COUNT(*) AS total
    FROM item_units
    WHERE estat = 'inactiu'
    GROUP BY baixa_motiu
")->fetchAll(PDO::FETCH_KEY_PAIR);

$total_inactius = array_sum($counts);

/* === 🧮 FILTRE SQL === */
$where = ["iu.estat = 'inactiu'"];
$params = [];

if ($search !== '') {
    $where[] = "(iu.serial LIKE ? OR i.sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($motiu !== '') {
    $where[] = "iu.baixa_motiu = ?";
    $params[] = $motiu;
}
if ($date_from !== '') {
    $where[] = "DATE(iu.updated_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = "DATE(iu.updated_at) <= ?";
    $params[] = $date_to;
}

$where_sql = implode(" AND ", $where);

/* === 📋 CONSULTA PRINCIPAL — SENSE i.name === */
$stmt = $pdo->prepare("
    SELECT iu.*, i.sku, i.category
    FROM item_units iu
    JOIN items i ON i.id = iu.item_id
    WHERE $where_sql
    ORDER BY iu.updated_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* === 🔢 TOTAL PER PAGINAR === */
$stmtCount = $pdo->prepare("
    SELECT COUNT(*) 
    FROM item_units iu 
    JOIN items i ON i.id = iu.item_id 
    WHERE $where_sql
");
$stmtCount->execute($params);
$total = $stmtCount->fetchColumn();
$total_pages = ceil($total / $limit);

ob_start();
?>

<h2 class="text-3xl font-bold mb-6">Baixes de camises</h2>

<?php
/* 🧮 Comptadors segons filtres */
$stmtCountFiltered = $pdo->prepare("
  SELECT baixa_motiu, COUNT(*) AS total
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  WHERE $where_sql
  GROUP BY baixa_motiu
");
$stmtCountFiltered->execute($params);
$filteredCounts = $stmtCountFiltered->fetchAll(PDO::FETCH_KEY_PAIR);

$total_filtered = array_sum($filteredCounts);
?>

<!-- 🧮 Comptadors -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
  <div class="bg-gray-100 p-4 rounded-lg text-center">
    <div class="text-lg font-bold"><?= $total_filtered ?></div>
    <div class="text-gray-500 text-sm">Total baixes</div>
  </div>

  <div class="bg-red-100 text-red-700 border border-red-200 rounded-lg p-4 text-center shadow-sm">
    <div class="text-lg font-bold"><?= $filteredCounts['malmesa'] ?? 0 ?></div>
    <div class="text-sm">Camisa malmesa</div>
  </div>

  <div class="bg-green-100 text-green-700 border border-green-200 rounded-lg p-4 text-center shadow-sm">
    <div class="text-lg font-bold"><?= $filteredCounts['fi_vida_util'] ?? 0 ?></div>
    <div class="text-sm">Fi de vida útil</div>
  </div>

  <div class="bg-yellow-100 text-yellow-700 border border-yellow-200 rounded-lg p-4 text-center shadow-sm">
    <div class="text-lg font-bold"><?= $filteredCounts['altres'] ?? 0 ?></div>
    <div class="text-sm">Altres</div>
  </div>

  <div class="bg-blue-100 text-blue-700 border border-blue-200 rounded-lg p-4 text-center shadow-sm">
    <div class="text-lg font-bold"><?= $filteredCounts['descatalogat'] ?? 0 ?></div>
    <div class="text-sm">Descatalogat</div>
  </div>
</div>

<!-- 📊 Gràfic -->
<div class="bg-white p-4 rounded-xl shadow mb-6 flex flex-col items-center">
  <div style="width:220px; height:220px;">
    <canvas id="chartBaixes"></canvas>
  </div>
  <div id="baixesLegend" class="mt-3 flex flex-wrap items-center justify-center gap-8 text-sm"></div>
</div>

<!-- 🔍 Filtres -->
<div class="bg-white p-4 rounded-lg shadow mb-6">
  <form method="GET" class="flex flex-wrap items-end gap-4">
    <div class="flex-1 min-w-[220px]">
      <label class="block text-sm font-medium text-gray-600 mb-1">Cercar (SKU o serial)</label>
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="w-full p-2 border rounded" placeholder="Ex: ENR001 o SER123">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-600 mb-1">Motiu</label>
      <select name="motiu" class="w-full p-2 border rounded">
        <option value="">Tots</option>
        <option value="malmesa" <?= $motiu === 'malmesa' ? 'selected' : '' ?>>Camisa malmesa</option>
        <option value="fi_vida_util" <?= $motiu === 'fi_vida_util' ? 'selected' : '' ?>>Fi de vida útil</option>
        <option value="altres" <?= $motiu === 'altres' ? 'selected' : '' ?>>Altres</option>
        <option value="descatalogat" <?= $motiu === 'descatalogat' ? 'selected' : '' ?>>Descatalogat</option>
      </select>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-600 mb-1">Des de</label>
      <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>" class="w-full p-2 border rounded">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-600 mb-1">Fins</label>
      <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>" class="w-full p-2 border rounded">
    </div>

    <div class="flex items-center gap-2">
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Filtrar</button>
      <a href="decommission.php" class="bg-gray-200 px-4 py-2 rounded hover:bg-gray-300">Netejar</a>
    </div>

    <div class="ml-auto w-full sm:w-auto sm:ml-auto">
      <a href="../src/export_decommission.php?<?= http_build_query($_GET) ?>" class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700">
        📤 Exportar Excel
      </a>
    </div>
  </form>
</div>

<!-- 📋 Taula -->
<div class="bg-white rounded-xl shadow overflow-x-auto">
  <table class="min-w-full text-sm text-left border-collapse">
    <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
      <tr>
        <th class="px-4 py-2">Data / Hora</th>
        <th class="px-4 py-2">SKU</th>
        <th class="px-4 py-2">Serial</th>
        <th class="px-4 py-2">Motiu</th>
        <th class="px-4 py-2 text-center">Màquina origen</th>
        <th class="px-4 py-2 text-right">Accions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (count($rows) > 0): ?>
        <?php foreach ($rows as $r): 
          $colorClass = match($r['baixa_motiu']) {
            'malmesa' => 'text-red-600 font-semibold',
            'fi_vida_util' => 'text-green-600 font-semibold',
            'altres' => 'text-yellow-600 font-semibold',
            'descatalogat' => 'text-blue-600 font-semibold',
            default => 'text-gray-500'
          };
        ?>
          <tr class="hover:bg-gray-50 transition">
            <td class="px-4 py-2"><?= date('d/m/Y H:i', strtotime($r['updated_at'])) ?></td>
            <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($r['sku']) ?></td>
            <td class="px-4 py-2 font-mono"><?= htmlspecialchars($r['serial']) ?></td>
            <td class="px-4 py-2 <?= $colorClass ?>"><?= htmlspecialchars(str_replace('_',' ', $r['baixa_motiu'] ?? '')) ?></td>
            <td class="px-4 py-2 text-center"><?= htmlspecialchars($r['maquina_actual'] ?? '—') ?></td>
            <td class="px-4 py-2 text-right">
              <form method="POST"
                    action="../src/update_unit.php"
                    onsubmit="return confirm('Vols restaurar aquesta unitat?');"
                    class="inline-flex items-center gap-2">
                <input type="hidden" name="action" value="restaurar_unitat">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

                <input
                  type="text"
                  name="sububicacio"
                  placeholder="Ex: E1-A3"
                  required
                  class="border rounded px-2 py-1 text-xs w-24 text-center"
                >

                <button type="submit" class="text-green-600 hover:text-green-800 text-sm font-medium">
                  ♻️ Restaurar
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6" class="text-center text-gray-500 py-4 italic">No s’han trobat recanvis inactius amb aquests filtres.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- 📄 Paginació -->
<?php if ($total_pages > 1): ?>
  <div class="flex justify-center items-center mt-6 space-x-2">
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
         class="px-3 py-1 border rounded <?= $p == $page ? 'bg-blue-600 text-white border-blue-600' : 'bg-white hover:bg-gray-100' ?>">
         <?= $p ?>
      </a>
    <?php endfor; ?>
  </div>
<?php endif; ?>

<!-- 📊 Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const dataCounts = {
  malmesa: <?= (int)($filteredCounts['malmesa'] ?? 0) ?>,
  fi: <?= (int)($filteredCounts['fi_vida_util'] ?? 0) ?>,
  altres: <?= (int)($filteredCounts['altres'] ?? 0) ?>,
  descatalogat: <?= (int)($filteredCounts['descatalogat'] ?? 0) ?>
};

const COLORS = {
  malmesa: '#ef4444',
  fi: '#22c55e',
  altres: '#facc15',
  descatalogat: '#3b82f6'
};

const ctx = document.getElementById('chartBaixes').getContext('2d');
new Chart(ctx, {
  type: 'doughnut',
  data: {
    labels: ['Malmesa', 'Fi vida útil', 'Altres', 'Descatalogat'],
    datasets: [{
      data: [dataCounts.malmesa, dataCounts.fi, dataCounts.altres, dataCounts.descatalogat],
      backgroundColor: [COLORS.malmesa, COLORS.fi, COLORS.altres, COLORS.descatalogat],
      borderWidth: 0
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    cutout: '65%',
    responsive: true
  }
});

const legendContainer = document.getElementById('baixesLegend');
const legends = [
  { label: 'Malmesa', color: COLORS.malmesa },
  { label: 'Fi vida útil', color: COLORS.fi },
  { label: 'Altres', color: COLORS.altres },
  { label: 'Descatalogat', color: COLORS.descatalogat }
];
legendContainer.innerHTML = legends.map(l => `
  <div class="flex items-center gap-2">
    <span class="w-3 h-3 rounded-full" style="background-color:${l.color}"></span>
    <span>${l.label}</span>
  </div>
`).join('');
</script>

<?php
$content = ob_get_clean();
renderPage("Baixes", $content);
