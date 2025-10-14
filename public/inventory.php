<?php
require_once __DIR__ . '/../src/functions.php';
$status = $_GET['status'] ?? 'active';
if($status==='inactive') $items = items_inactive($pdo);
else $items = find_all_items($pdo);

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='add'){
    $sku = $_POST['sku']; $name = $_POST['name']; $cat = $_POST['category']; $loc = $_POST['location'];
    $stock = (int)$_POST['stock']; $min = (int)$_POST['min_stock']; $life = (int)$_POST['life_expectancy'];
    $stmt = $pdo->prepare('INSERT INTO items (sku,name,category,location,stock,min_stock,life_expectancy) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$sku,$name,$cat,$loc,$stock,$min,$life]);
    header('Location: inventory.php'); exit;
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Inventari</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4">
<div class="container">
  <h1>Inventari</h1>

  <div class="mb-3">
    <a href="?status=active" class="btn btn-success btn-sm">Actius</a>
    <a href="?status=inactive" class="btn btn-outline-secondary btn-sm">Inactius</a>
  </div>

  <form method="post" class="row g-2 mb-3">
    <input type="hidden" name="action" value="add">
    <div class="col-md-2"><input name="sku" class="form-control" placeholder="SKU" required></div>
    <div class="col-md-3"><input name="name" class="form-control" placeholder="Nom" required></div>
    <div class="col-md-2"><input name="category" class="form-control" placeholder="Categoria"></div>
    <div class="col-md-2"><input name="location" class="form-control" placeholder="Ubicació"></div>
    <div class="col-md-1"><input name="stock" type="number" class="form-control" placeholder="Stock" value="0"></div>
    <div class="col-md-1"><input name="min_stock" type="number" class="form-control" placeholder="Min" value="0"></div>
    <div class="col-md-1"><input name="life_expectancy" type="number" class="form-control" placeholder="Vida"></div>
    <div class="col-md-12 mt-2"><button class="btn btn-primary">Afegir</button></div>
  </form>

  <table class="table table-sm">
    <thead><tr><th>SKU</th><th>Nom</th><th>Cat</th><th>Ubicació</th><th>Stock</th><th>Min</th><th>Vida</th><th>Actiu</th></tr></thead>
    <tbody>
      <?php foreach($items as $it): ?>
        <tr>
          <td><?=htmlspecialchars($it['sku'])?></td>
          <td><?=htmlspecialchars($it['name'])?></td>
          <td><?=htmlspecialchars($it['category'])?></td>
          <td><?=htmlspecialchars($it['location'])?></td>
          <td><?=intval($it['stock'])?></td>
          <td><?=intval($it['min_stock'])?></td>
          <td><?=intval($it['life_expectancy'])?></td>
          <td><?= $it['active'] ? 'Sí' : 'No' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <a href="index.php" class="btn btn-secondary">Tornar</a>
</div>
</body></html>
