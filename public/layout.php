<?php
function renderPage(string $title, string $content)
{
  global $pdo; // 👈 així tenim accés a la connexió sense carregar res extra

  // comptar recanvis al magatzem intermig
  $pendingIntermig = 0;
  try {
    if ($pdo instanceof PDO) {
      $stmt = $pdo->query("SELECT COUNT(*) FROM intermig_items");
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
  <style>
    body {
      font-family: 'Inter', sans-serif;
      @apply bg-gray-50 text-gray-800;
    }
    .sidebar-link {
      @apply flex items-center gap-2 py-2 px-3 rounded-lg hover:bg-gray-100 transition;
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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>

<body class="min-h-screen flex bg-gray-50">

  <!-- Sidebar -->
  <aside class="w-64 bg-white shadow-sm border-r border-gray-100 flex flex-col">
    <!-- Logo -->
    <div class="flex items-center gap-2 px-5 py-6 border-b">
      <div class="w-8 h-8 bg-blue-600 text-white flex items-center justify-center rounded-lg font-bold text-lg">IC</div>
      <div>
        <h1 class="font-bold text-lg text-gray-800">Inventari Camises</h1>
        <span class="text-xs text-gray-400">Gestió de magatzem</span>
      </div>
    </div>

    <!-- Navegació -->
    <nav class="flex-1 p-4 space-y-1 text-sm">
      <a href="dashboard.php" class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">📊 Dashboard</a>
      <a href="maquines.php" class="flex items-center py-2 px-3 rounded-lg hover:bg-blue-50 <?= basename($_SERVER['PHP_SELF']) === 'maquines.php' ? 'bg-blue-100 text-blue-700 font-medium' : '' ?>">🛠️ Màquines</a>
      <a href="inventory.php" class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'inventory.php' ? 'active' : '' ?>">📦 Inventari</a>

      <a href="entry.php" class="flex items-center justify-between py-2 px-3 rounded-lg hover:bg-blue-50 <?= basename($_SERVER['PHP_SELF']) === 'entry.php' ? 'bg-blue-100 text-blue-700 font-medium' : '' ?>">
        <span class="flex items-center">⬆️ Entrades</span>
        <?php if ($pendingIntermig > 0): ?>
          <span class="ml-2 bg-green-500 text-white text-xs font-semibold rounded-full px-2 py-0.5">
            <?= $pendingIntermig ?>
          </span>
        <?php endif; ?>
      </a>

      <a href="exit.php" class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'exit.php' ? 'active' : '' ?>">⬇️ Sortides</a>
      <a href="decommission.php" class="sidebar-link <?= basename($_SERVER['PHP_SELF']) === 'decommission.php' ? 'active' : '' ?>">🗑️ Baixes</a>
    </nav>

    <div class="p-4 border-t text-xs text-gray-400 text-center">
      &copy; <?= date('Y') ?> Inventari Camises
    </div>
  </aside>

  <!-- Contingut -->
  <main class="flex-1 p-8">
    <?= $content ?>
  </main>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const serveixBtns = document.querySelectorAll('.serveix-btn');
  const anulaBtns = document.querySelectorAll('.anula-btn');

  function handleAction(button, action) {
    const id = button.getAttribute('data-id');
    fetch('peticions_actions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `id=${id}&action=${action}`
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const row = button.closest('tr');
        row.classList.add('opacity-50', 'transition');
        setTimeout(() => row.remove(), 300);
      } else {
        alert('❌ Error en actualitzar la petició');
      }
    })
    .catch(() => alert('❌ Error en el servidor'));
  }

  serveixBtns.forEach(btn => btn.addEventListener('click', () => handleAction(btn, 'serveix')));
  anulaBtns.forEach(btn => btn.addEventListener('click', () => handleAction(btn, 'anula')));
});
</script>

</body>
</html>
<?php
}
?>
