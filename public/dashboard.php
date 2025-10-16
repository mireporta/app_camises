<?php
require_once("../src/config.php");


if (!$pdo) {
    die("❌ Error: la connexió \$pdo no s'ha creat correctament.");
}

// ---------- CONSULTES PRINCIPALS ----------
$total_items = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
$low_stock = $pdo->query("SELECT COUNT(*) FROM items WHERE stock < min_stock")->fetchColumn();
$low_life = $pdo->query("SELECT COUNT(*) FROM items WHERE life_expectancy < 10")->fetchColumn();
$machine_items = $pdo->query("SELECT COUNT(*) FROM items WHERE category LIKE '%maquina%' OR category LIKE '%màquina%'")->fetchColumn();

// Recanvis sota mínim
$items_low_stock = $pdo->query("SELECT * FROM items WHERE stock < min_stock ORDER BY stock ASC")->fetchAll(PDO::FETCH_ASSOC);

// Top 10 més utilitzats (simulat)
$top_used = $pdo->query("SELECT * FROM items ORDER BY created_at ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Vida útil <10%
$items_low_life = $pdo->query("SELECT * FROM items WHERE life_expectancy < 10 ORDER BY life_expectancy ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ca">
<head>
  <meta charset="UTF-8">
  <title>Dashboard | Inventari Camises</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 flex">

  <!-- Sidebar -->
  <aside class="w-64 bg-white shadow-md min-h-screen p-5">
    <h1 class="text-2xl font-bold text-blue-700 mb-8">Inventari Camises</h1>
    <nav class="space-y-3">
      <a href="dashboard.php" class="block py-2 px-3 bg-blue-100 text-blue-700 rounded-lg font-medium">📊 Dashboard</a>
      <a href="inventory.php" class="block py-2 px-3 hover:bg-gray-100 rounded-lg">📦 Inventari</a>
      <a href="entry.php" class="block py-2 px-3 hover:bg-gray-100 rounded-lg">⬇️ Entrades</a>
      <a href="exit.php" class="block py-2 px-3 hover:bg-gray-100 rounded-lg">⬆️ Sortides</a>
      <a href="decommission.php" class="block py-2 px-3 hover:bg-gray-100 rounded-lg">🗑️ Baixes</a>
    </nav>
  </aside>

  <!-- Contingut principal -->
  <main class="flex-1 p-8">
    <h2 class="text-3xl font-bold mb-6">Dashboard General</h2>

    <!-- Targetes -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
      <div class="bg-white p-5 rounded-xl shadow">
        <h3 class="text-gray-500 text-sm">Total recanvis</h3>
        <p class="text-3xl font-bold text-blue-700"><?= $total_items ?></p>
      </div>

      <div class="bg-white p-5 rounded-xl shadow">
        <h3 class="text-gray-500 text-sm">Recanvis amb estoc baix</h3>
        <p class="text-3xl font-bold text-yellow-600"><?= $low_stock ?></p>
      </div>

      <div class="bg-white p-5 rounded-xl shadow">
        <h3 class="text-gray-500 text-sm">Vida útil <10%</h3>
        <p class="text-3xl font-bold text-red-600"><?= $low_life ?></p>
      </div>

      <div class="bg-white p-5 rounded-xl shadow">
        <h3 class="text-gray-500 text-sm">Recanvis a màquines</h3>
        <p class="text-3xl font-bold text-green-600"><?= $machine_items ?></p>
      </div>
    </div>

    <!-- Seccions inferiors -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

      <!-- Recanvis sota mínim -->
      <div class="bg-white p-5 rounded-xl shadow col-span-1">
        <h3 class="text-lg font-bold mb-3 text-yellow-700">⚠️ Estoc per sota del mínim</h3>
        <ul class="divide-y divide-gray-100">
          <?php foreach ($items_low_stock as $item): ?>
          <li class="py-2 flex justify-between">
            <span><?= htmlspecialchars($item['sku']) ?> - <?= htmlspecialchars($item['name']) ?></span>
            <span class="text-red-600 font-semibold"><?= $item['stock'] ?> / min <?= $item['min_stock'] ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Top més utilitzats -->
      <div class="bg-white p-5 rounded-xl shadow col-span-1">
        <h3 class="text-lg font-bold mb-3 text-blue-700">🏆 Top 10 més utilitzats</h3>
        <canvas id="topChart"></canvas>
      </div>

      <!-- Vida útil <10% -->
      <div class="bg-white p-5 rounded-xl shadow col-span-1">
        <h3 class="text-lg font-bold mb-3 text-red-700">❤️ Vida útil <10%</h3>
        <ul class="divide-y divide-gray-100">
          <?php foreach ($items_low_life as $item): ?>
          <li class="py-2 flex justify-between">
            <span><?= htmlspecialchars($item['sku']) ?> - <?= htmlspecialchars($item['name']) ?></span>
            <span class="text-red-500 font-semibold"><?= $item['life_expectancy'] ?>%</span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>

    </div>
  </main>

  <!-- Gràfic -->
  <script>
    const ctx = document.getElementById('topChart').getContext('2d');
    const topData = {
      labels: <?= json_encode(array_column($top_used, 'sku')) ?>,
      datasets: [{
        label: 'Index ús (simulat)',
        data: <?= json_encode(array_map(fn($x) => rand(5,50), $top_used)) ?>,
        backgroundColor: 'rgba(59,130,246,0.6)',
        borderRadius: 5
      }]
    };
    new Chart(ctx, { type: 'bar', data: topData, options: { plugins: { legend: { display: false } } } });
  </script>

</body>
</html>
