<?php
/**
 * layout.php
 * Plantilla base per a totes les pàgines de l'aplicació
 * Ús:
 *   ob_start();
 *   ... (contingut de la pàgina)
 *   $content = ob_get_clean();
 *   renderPage("Títol", $content);
 */

function renderPage(string $title, string $content)
{
?>
<!DOCTYPE html>
<html lang="ca">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($title) ?> | Inventari Camises</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100 flex min-h-screen">

  <!-- Sidebar -->
  <aside class="w-64 bg-white shadow-md min-h-screen p-5 flex flex-col">
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-blue-700">Inventari Camises</h1>
    </div>

    <nav class="space-y-3 flex-1">
      <a href="dashboard.php" class="flex items-center py-2 px-3 rounded-lg hover:bg-blue-50 <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'bg-blue-100 text-blue-700 font-medium' : '' ?>">📊 Dashboard</a>
      <a href="inventory.php" class="flex items-center py-2 px-3 rounded-lg hover:bg-blue-50 <?= basename($_SERVER['PHP_SELF']) === 'inventory.php' ? 'bg-blue-100 text-blue-700 font-medium' : '' ?>">📦 Inventari</a>
      <a href="entry.php" class="flex items-center py-2 px-3 rounded-lg hover:bg-blue-50 <?= basename($_SERVER['PHP_SELF']) === 'entry.php' ? 'bg-blue-100 text-blue-700 font-medium' : '' ?>">⬆️ Entrades</a>
      <a href="exit.php" class="flex items-center py-2 px-3 rounded-lg hover:bg-blue-50 <?= basename($_SERVER['PHP_SELF']) === 'exit.php' ? 'bg-blue-100 text-blue-700 font-medium' : '' ?>">⬇️ Sortides</a>
      <a href="decommission.php" class="flex items-center py-2 px-3 rounded-lg hover:bg-blue-50 <?= basename($_SERVER['PHP_SELF']) === 'decommission.php' ? 'bg-blue-100 text-blue-700 font-medium' : '' ?>">🗑️ Baixes</a>
    </nav>

    <footer class="mt-8 text-xs text-gray-400 text-center">
      &copy; <?= date('Y') ?> Inventari Camises
    </footer>
  </aside>

  <!-- Contingut dinàmic -->
  <main class="flex-1 p-8">
    <?= $content ?>
  </main>

</body>
</html>
<?php
}
?>
