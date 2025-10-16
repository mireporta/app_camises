<?php
function renderOperariPage($title, $content) {
?>
<!DOCTYPE html>
<html lang="ca">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">

  <header class="bg-gray-800 text-white p-4 flex justify-between items-center">
    <h1 class="text-xl font-semibold"><?= htmlspecialchars($title) ?></h1>
    <span class="text-sm opacity-75">Pantalla Operari</span>
  </header>

  <main class="flex items-start justify-center min-h-[calc(100vh-64px)] p-40">
     <div class="w-full max-w-7xl">
      <?= $content ?>
     </div>
    </main>

</body>
</html>
<?php
}
