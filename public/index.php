<?php
require_once __DIR__ . '/../src/functions.php';
$top = top_used_items($pdo, 10);
$low_life = items_low_life($pdo);
$below_min = items_below_min($pdo);
$stats = decommission_stats_by_category($pdo);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard - Control d'Estoc</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
  <div class="container">
    <h1>Dashboard</h1>
    <div class="mb-3">
      <a href="inventory.php" class="btn btn-primary">Gestió Inventari</a>
      <a href="entry.php" class="btn btn-success">Registrar Entrada</a>
      <a href="exit.php" class="btn btn-danger">Registrar Sortida</a>
      <a href="decommission.php" class="btn btn-warning">Donar de baixa</a>
      <a href="export_excel.php" class="btn btn-outline-primary">Exportar Excel</a>
      <a href="import_excel.php" class="btn btn-outline-secondary">Importar Excel</a>
    </div>

    <div class="row">
      <div class="col-md-4">
        <h3>Top 10 més utilitzats</h3>
        <table class="table table-sm">
          <thead><tr><th>SKU</th><th>Nom</th><th>Usades</th></tr></thead>
          <tbody>
            <?php foreach($top as $t): ?>
              <tr><td><?=htmlspecialchars($t['sku'])?></td><td><?=htmlspecialchars($t['name'])?></td><td><?=intval($t['used'])?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="col-md-4">
        <h3>Vida útil &lt; 10%</h3>
        <table class="table table-sm">
          <thead><tr><th>SKU</th><th>Nom</th><th>Stock</th><th>Vida</th></tr></thead>
          <tbody>
            <?php foreach($low_life as $i): ?>
              <tr><td><?=htmlspecialchars($i['sku'])?></td><td><?=htmlspecialchars($i['name'])?></td><td><?=intval($i['stock'])?></td><td><?=intval($i['life_expectancy'])?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="col-md-4">
        <h3>Per sota estoc mínim</h3>
        <table class="table table-sm">
          <thead><tr><th>SKU</th><th>Nom</th><th>Stock</th><th>Min</th></tr></thead>
          <tbody>
            <?php foreach($below_min as $i): ?>
              <tr><td><?=htmlspecialchars($i['sku'])?></td><td><?=htmlspecialchars($i['name'])?></td><td><?=intval($i['stock'])?></td><td><?=intval($i['min_stock'])?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <hr>
    <h3>Estadístiques de baixes per categoria</h3>
    <table class="table table-sm">
      <thead><tr><th>Categoria</th><th>Baixes</th><th>Unitats</th></tr></thead>
      <tbody>
      <?php foreach($stats as $s): ?>
        <tr><td><?=htmlspecialchars($s['category'])?></td><td><?=$s['num_baixes']?></td><td><?=$s['total_unitats']?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

  </div>
</body>
</html>
