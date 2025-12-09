<?php
function renderPage(string $title, string $content, string $extraScripts = '', array $options = [])
{
  global $pdo;

  // Opcions
  $noSidebar = $options['noSidebar'] ?? false;

  // Comptar recanvis pendents del magatzem intermig
  $pendingIntermig = 0;
  try {
    if ($pdo instanceof PDO) {
      $stmt = $pdo->query("SELECT COUNT(*) FROM item_units WHERE ubicacio='intermig' AND estat='actiu'");
      $pendingIntermig = (int)$stmt->fetchColumn();
    }
  } catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="ca">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($title) ?> | Inventari Camises</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Tailwind CSS + Chart.js -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="style.css">

  <style>
    body {
      font-family: 'Inter', sans-serif;
    }
    .sidebar-link {
      @apply flex items-center gap-2 py-1.5 px-2.5 rounded-md hover:bg-blue-50 transition text-gray-700;
    }

    .sidebar-link.active {
      @apply bg-blue-100 text-blue-700 font-semibold;
    }
    .card {
      @apply bg-white rounded-xl shadow-sm p-5 border border-gray-100;
    }
    .card h3 {
      @apply text-gray-500 text-sm font-medium;
    }
    .card .value {
      @apply text-3xl font-bold mt-1;
    }
  </style>
</head>

<body class="min-h-screen flex bg-gray-50 text-gray-800">

  <?php if (!$noSidebar): ?>
  <!-- Sidebar -->
  <aside class="w-60 bg-white shadow-lg border-r border-gray-200 flex flex-col">
    <!-- Logo -->
    <div class="flex items-center gap-3 px-6 py-6 border-b bg-gray-50">
      <div class="w-10 h-10 bg-blue-600 text-white flex items-center justify-center rounded-xl font-bold text-xl">IC</div>
      <div>
        <h1 class="font-bold text-lg text-gray-800 leading-tight">Inventari Camises</h1>
        <span class="text-xs text-gray-400">Gestió de magatzem</span>
      </div>
    </div>

    <!-- Navegació -->
    <nav class="flex-1 px-2.5 py-5 space-y-0.5 text-[15px]">
      <a href="dashboard.php" class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">📊 Indicadors</a>
      <a href="maquines.php"  class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'maquines.php'  ? 'active' : '' ?>">🛠️ Màquines</a>
      <a href="inventory.php" class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'inventory.php'? 'active' : '' ?>">📦 Inventari</a>

      <a href="entry.php" 
         class="flex items-center justify-between py-2 px-3 rounded-lg hover:bg-blue-50 transition 
                <?= basename($_SERVER['PHP_SELF']) === 'entry.php' ? 'bg-blue-100 text-blue-700 font-semibold' : 'text-gray-700' ?>">
        <span class="flex items-center gap-2">⬇️ Entrades</span>
        <?php if ($pendingIntermig > 0): ?>
          <span class="ml-2 bg-green-500 text-white text-xs font-semibold rounded-full px-2 py-0.5">
            <?= $pendingIntermig ?>
          </span>
        <?php endif; ?>
      </a>

      <a href="moviments.php"   class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'moviments.php'   ? 'active' : '' ?>">📜 Moviments</a>
      <a href="produccio_historial.php"   class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'produccio_historial.php' ? 'active' : '' ?>">📈 Històric producció</a>
      <a href="decommission.php"class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'decommission.php'? 'active' : '' ?>">🗑️ Baixes</a>
      <a href="operari.php"     class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'operari.php'    ? 'active' : '' ?>">⚙️ Operari</a>
      <a href="magatzem_map.php"  class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'magatzem_map.php' ? 'active' : '' ?>">🗺️ Mapa magatzem </a>
    </nav>

    <!-- Peu -->
    <div class="px-6 py-5 border-t text-xs text-gray-400 text-center bg-gray-50">
      &copy; <?= date('Y') ?> Inventari Camises
    </div>
  </aside>
  <?php endif; ?>

  <!-- Contingut principal -->
  <main class="flex-1 p-4 sm:p-8 overflow-x-hidden">
    <?= $content ?>
  </main>

  <!-- Scripts específics -->
  <?php if (!empty($extraScripts)): ?>
    <?= $extraScripts ?>
  <?php endif; ?>

</body>
</html>
<?php
}
?>
