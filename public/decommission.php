<?php
require_once("../src/config.php");
require_once("layout.php");

// 🔹 Baixes per proveïdor (últim any)
//
// Lògica:
// - m_baixa: moviments de tipus 'baixa'
// - busquem la PRIMERA 'entrada' d'aquella unitat (item_unit_id) per saber quin proveïdor la va servir
//   (les altres entrades poden ser 'INTERMIG' quan torna del magatzem intermig)
$baixesPerProveidorRows = $pdo->query("
  SELECT 
    prov.proveidor,
    COUNT(*) AS num_baixes
  FROM moviments m_baixa
  JOIN (
    SELECT m1.item_unit_id,
           m1.maquina AS proveidor
    FROM moviments m1
    JOIN (
      SELECT item_unit_id, MIN(created_at) AS first_created
      FROM moviments
      WHERE tipus = 'entrada'
      GROUP BY item_unit_id
    ) first_e
      ON first_e.item_unit_id = m1.item_unit_id
     AND first_e.first_created = m1.created_at
    WHERE m1.tipus = 'entrada'
      AND m1.maquina IS NOT NULL
      AND m1.maquina <> ''
      AND m1.maquina <> 'INTERMIG'
  ) prov
    ON prov.item_unit_id = m_baixa.item_unit_id
  WHERE m_baixa.tipus = 'baixa'
    AND m_baixa.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
  GROUP BY prov.proveidor
  ORDER BY num_baixes DESC
")->fetchAll(PDO::FETCH_ASSOC);

$proveidorLabels = array_column($baixesPerProveidorRows, 'proveidor');
$proveidorValues = array_map('intval', array_column($baixesPerProveidorRows, 'num_baixes'));


/* === 🧾 PARÀMETRES DE FILTRE GENERALS (taula, comptadors, etc.) === */
$search    = trim($_GET['search'] ?? '');
$motiu     = trim($_GET['motiu'] ?? '');
$date_from = $_GET['from'] ?? '';
$date_to   = $_GET['to'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = 20;
$offset    = ($page - 1) * $limit;

/* === 🔧 PARÀMETRES ESPECÍFICS PER ALS GRÀFICS === */
// Filtre de màquina per al DONUT
$maquina_chart = trim($_GET['maquina_chart'] ?? '');
// Filtre de motiu per al GRÀFIC DE MÀQUINES
$motiu_chart   = trim($_GET['motiu_chart'] ?? '');

/* === 🧮 FILTRE SQL BASE (s'aplica a tot) === */
$where  = ["iu.estat = 'inactiu'"];
$params = [];

if ($search !== '') {
    $where[]  = "(iu.serial LIKE ? OR i.sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($motiu !== '') {
    $where[]  = "iu.baixa_motiu = ?";
    $params[] = $motiu;
}
if ($date_from !== '') {
    $where[]  = "DATE(iu.updated_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[]  = "DATE(iu.updated_at) <= ?";
    $params[] = $date_to;
}

$where_sql = implode(" AND ", $where);

/* === 📊 COMPTADORS PER TIPUS DE BAIXA (global segons filtres) === */
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

/* === 📋 CONSULTA PRINCIPAL (llista) === */
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
$total       = $stmtCount->fetchColumn();
$total_pages = ceil($total / $limit);

/* === 📋 LLISTA DE MÀQUINES AMB BAIXES (per al filtre del donut) === */
$stmtMaqFilter = $pdo->prepare("
  SELECT DISTINCT iu.maquina_baixa
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  WHERE $where_sql
    AND iu.maquina_baixa IS NOT NULL 
    AND iu.maquina_baixa <> ''
  ORDER BY iu.maquina_baixa
");
$stmtMaqFilter->execute($params);
$maquinesChart = array_column($stmtMaqFilter->fetchAll(PDO::FETCH_ASSOC), 'maquina_baixa');

/* === 🍩 DONUT: COMPTES PER TIPUS DE BAIXA FILTRATS PER MÀQUINA (OPCIONAL) === */
$whereDonut  = $where;
$paramsDonut = $params;

if ($maquina_chart !== '') {
    $whereDonut[]  = "iu.maquina_baixa = ?";
    $paramsDonut[] = $maquina_chart;
}

$whereDonutSql = implode(" AND ", $whereDonut);

$stmtDonut = $pdo->prepare("
  SELECT iu.baixa_motiu, COUNT(*) AS total
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  WHERE $whereDonutSql
  GROUP BY iu.baixa_motiu
");
$stmtDonut->execute($paramsDonut);
$donutRows = $stmtDonut->fetchAll(PDO::FETCH_ASSOC);

$donutCounts = [
    'malmesa'      => 0,
    'fi_vida_util' => 0,
    'altres'       => 0,
    'descatalogat' => 0
];
foreach ($donutRows as $r) {
    if (isset($donutCounts[$r['baixa_motiu']])) {
        $donutCounts[$r['baixa_motiu']] = (int)$r['total'];
    }
}

/* === 📊 GRÀFIC DE MÀQUINES: COMPTES PER MÀQUINA FILTRATS PER TIPUS (motiu_chart) === */
$whereMachines  = $where;
$paramsMachines = $params;

if ($motiu_chart !== '') {
    $whereMachines[]  = "iu.baixa_motiu = ?";
    $paramsMachines[] = $motiu_chart;
}

$whereMachinesSql = implode(" AND ", $whereMachines);

$stmtMachines = $pdo->prepare("
  SELECT iu.maquina_baixa AS maquina, COUNT(*) AS total
  FROM item_units iu
  JOIN items i ON i.id = iu.item_id
  WHERE $whereMachinesSql
    AND iu.maquina_baixa IS NOT NULL
    AND iu.maquina_baixa <> ''
  GROUP BY iu.maquina_baixa
  ORDER BY iu.maquina_baixa
");
$stmtMachines->execute($paramsMachines);
$rowsMachines   = $stmtMachines->fetchAll(PDO::FETCH_ASSOC);
$machinesLabels = array_column($rowsMachines, 'maquina');
$machinesValues = array_map('intval', array_column($rowsMachines, 'total'));

ob_start();
?>

<h2 class="text-3xl font-bold mb-6">Baixes de camises</h2>

<!-- 🧮 Comptadors globals (segons filtres generals) -->
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

<!-- 📊 Zona de gràfics -->
<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">

  <!-- 🍩 Donut motius + filtre per màquina -->
  <div class="bg-white p-4 rounded-xl shadow flex flex-col items-center">
    <div class="w-full flex flex-wrap items-center justify-between gap-2 mb-2">
      <h3 class="font-semibold text-gray-700">Distribució per motiu de baixa</h3>
      <div class="text-sm flex items-center gap-2">
        <label for="maquinaChartSelect" class="text-gray-600">Màquina:</label>
        <select id="maquinaChartSelect" class="border rounded px-2 py-1 text-sm">
          <option value="">Totes</option>
          <?php foreach ($maquinesChart as $maq): ?>
            <option value="<?= htmlspecialchars($maq) ?>" <?= $maquina_chart === $maq ? 'selected' : '' ?>>
              <?= htmlspecialchars($maq) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="width:220px; height:220px;">
      <canvas id="chartBaixes"></canvas>
    </div>
    <div id="baixesLegend" class="mt-3 flex flex-wrap items-center justify-center gap-8 text-sm"></div>
  </div>

  <!-- 📊 Gràfic de màquines + filtre de tipus de baixa -->
  <div class="bg-white p-4 rounded-xl shadow flex flex-col">
    <div class="w-full flex flex-wrap items-center justify-between gap-2 mb-2">
      <h3 class="font-semibold text-gray-700">Baixes per màquina</h3>
      <div class="text-sm flex items-center gap-2">
        <label for="motiuChartSelect" class="text-gray-600">Tipus de baixa:</label>
        <select id="motiuChartSelect" class="border rounded px-2 py-1 text-sm">
          <option value="">Tots</option>
          <option value="malmesa"      <?= $motiu_chart === 'malmesa' ? 'selected' : '' ?>>Camisa malmesa</option>
          <option value="fi_vida_util" <?= $motiu_chart === 'fi_vida_util' ? 'selected' : '' ?>>Fi de vida útil</option>
          <option value="altres"       <?= $motiu_chart === 'altres' ? 'selected' : '' ?>>Altres</option>
          <option value="descatalogat" <?= $motiu_chart === 'descatalogat' ? 'selected' : '' ?>>Descatalogat</option>
        </select>
      </div>
    </div>
    <div class="w-full" style="height:260px;">
      <canvas id="chartMaquines"></canvas>
    </div>
  </div>

</div>

<!-- 📊 Gràfica de baixes per proveïdor (últim any) -->
<div class="bg-white rounded-xl shadow mb-8 p-5">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-gray-800">
      📊 Baixes per proveïdor (últim any)
    </h2>
    <?php if (!empty($proveidorLabels)): ?>
      <span class="text-xs text-gray-500">
        Total baixes: <?= array_sum($proveidorValues) ?>
      </span>
    <?php endif; ?>
  </div>

  <?php if (empty($proveidorLabels)): ?>
    <p class="text-sm text-gray-500 italic">
      Encara no hi ha baixes associades a cap proveïdor en aquest període.
    </p>
  <?php else: ?>
    <div class="max-w-xl">
      <canvas id="baixesProveidorChart"></canvas>
    </div>
  <?php endif; ?>
</div>

<!-- 🔍 Filtres generals -->
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
        <option value="malmesa"      <?= $motiu === 'malmesa' ? 'selected' : '' ?>>Camisa malmesa</option>
        <option value="fi_vida_util" <?= $motiu === 'fi_vida_util' ? 'selected' : '' ?>>Fi de vida útil</option>
        <option value="altres"       <?= $motiu === 'altres' ? 'selected' : '' ?>>Altres</option>
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
            'malmesa'      => 'text-red-600 font-semibold',
            'fi_vida_util' => 'text-green-600 font-semibold',
            'altres'       => 'text-yellow-600 font-semibold',
            'descatalogat' => 'text-blue-600 font-semibold',
            default        => 'text-gray-500'
          };
        ?>
          <tr class="hover:bg-gray-50 transition">
            <td class="px-4 py-2"><?= date('d/m/Y H:i', strtotime($r['updated_at'])) ?></td>
            <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($r['sku']) ?></td>
            <td class="px-4 py-2 font-mono"><?= htmlspecialchars($r['serial']) ?></td>
            <td class="px-4 py-2 <?= $colorClass ?>"><?= htmlspecialchars(str_replace('_',' ', $r['baixa_motiu'] ?? '')) ?></td>
            <td class="px-4 py-2 text-center"><?= htmlspecialchars($r['maquina_baixa'] ?? '—') ?></td>
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

<!-- 📊 Chart.js + datalabels -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

<script>
Chart.register(ChartDataLabels);

// Utilitat per modificar paràmetres de la query i recarregar
function updateQueryParam(key, value) {
  const url = new URL(window.location.href);
  const params = url.searchParams;

  if (value === '' || value === null) {
    params.delete(key);
  } else {
    params.set(key, value);
  }
  // Quan canviem filtres de gràfic, sempre tornem a la pàgina 1
  params.delete('page');
  window.location.search = params.toString();
}

// Canvi de màquina al donut
document.getElementById('maquinaChartSelect').addEventListener('change', function () {
  updateQueryParam('maquina_chart', this.value);
});

// Canvi de motiu al gràfic de màquines
document.getElementById('motiuChartSelect').addEventListener('change', function () {
  updateQueryParam('motiu_chart', this.value);
});

// --- Dades del donut (ja filtrades per màquina al servidor) ---
const dataCounts = {
  malmesa:      <?= (int)$donutCounts['malmesa'] ?>,
  fi:           <?= (int)$donutCounts['fi_vida_util'] ?>,
  altres:       <?= (int)$donutCounts['altres'] ?>,
  descatalogat: <?= (int)$donutCounts['descatalogat'] ?>
};

const COLORS = {
  malmesa:      '#ef4444',
  fi:           '#22c55e',
  altres:       '#facc15',
  descatalogat: '#3b82f6'
};

/* === Donut de motius === */
const ctxDonut = document.getElementById('chartBaixes').getContext('2d');
new Chart(ctxDonut, {
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
    plugins: {
      legend: { display: false },
      datalabels: {
        color: '#111',
        font: { size: 12, weight: 'bold' },
        formatter: (value) => value > 0 ? value : ''
      }
    },
    cutout: '65%',
    responsive: true
  }
});

// Llegenda pròpia del donut
const legendContainer = document.getElementById('baixesLegend');
const legends = [
  { label: 'Malmesa',      color: COLORS.malmesa,      value: dataCounts.malmesa },
  { label: 'Fi vida útil', color: COLORS.fi,           value: dataCounts.fi },
  { label: 'Altres',       color: COLORS.altres,       value: dataCounts.altres },
  { label: 'Descatalogat', color: COLORS.descatalogat, value: dataCounts.descatalogat }
];
legendContainer.innerHTML = legends.map(l => `
  <div class="flex items-center gap-2">
    <span class="w-3 h-3 rounded-full" style="background-color:${l.color}"></span>
    <span>${l.label}</span>
  </div>
`).join('');

/* === Gràfic de màquines (barres) === */
const machinesLabels = <?= json_encode($machinesLabels) ?>;
const machinesValues = <?= json_encode($machinesValues) ?>;

if (machinesLabels.length > 0) {
  const ctxMachines = document.getElementById('chartMaquines').getContext('2d');
  new Chart(ctxMachines, {
    type: 'bar',
    data: {
      labels: machinesLabels,
      datasets: [{
        label: 'Baixes',
        data: machinesValues,
        backgroundColor: '#0ea5e9'
      }]
    },
    options: {
      scales: {
        x: {
          ticks: { autoSkip: true, maxRotation: 0 }
        },
        y: {
          beginAtZero: true,
          precision: 0
        }
      },
      plugins: {
        legend: { display: false },
        datalabels: {
          anchor: 'end',
          align: 'end',
          color: '#111',
          font: { size: 10, weight: 'bold' },
          formatter: (value) => value > 0 ? value : ''
        }
      },
      responsive: true,
      maintainAspectRatio: false
    }
  });
}
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const ctxProv = document.getElementById('baixesProveidorChart');
  if (ctxProv && window.Chart) {
    const labels = <?= json_encode($proveidorLabels, JSON_UNESCAPED_UNICODE) ?>;
    const values = <?= json_encode($proveidorValues, JSON_UNESCAPED_UNICODE) ?>;

    if (labels.length > 0) {
      new Chart(ctxProv, {
        type: 'bar', // si vols 'pie' o 'doughnut' també queda bé
        data: {
          labels: labels,
          datasets: [{
            label: 'Nombre de baixes',
            data: values
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: { enabled: true }
          },
          scales: {
            x: {
              ticks: { autoSkip: false, maxRotation: 45, minRotation: 0 }
            },
            y: {
              beginAtZero: true,
              precision: 0
            }
          }
        }
      });
    }
  }
});
</script>

<?php
$content = ob_get_clean();
renderPage("Baixes", $content);
