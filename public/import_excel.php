<?php
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['file'])){
    $file = $_FILES['file']['tmp_name'];
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    $header = array_shift($rows);
    foreach($rows as $r){
        // Expectem: SKU, Nom, Categoria, Ubicacio, Stock, Min, Vida, Actiu
        $sku = $r[0] ?? null;
        if(!$sku) continue;
        $name = $r[1] ?? '';
        $cat = $r[2] ?? '';
        $loc = $r[3] ?? '';
        $stock = (int)($r[4] ?? 0);
        $min = (int)($r[5] ?? 0);
        $life = (int)($r[6] ?? 0);
        $active = (int)($r[7] ?? 1);

        $stmt = $pdo->prepare('SELECT id FROM items WHERE sku=?');
        $stmt->execute([$sku]);
        $existing = $stmt->fetch();
        if($existing){
            $stmt = $pdo->prepare('UPDATE items SET name=?,category=?,location=?,stock=?,min_stock=?,life_expectancy=?,active=? WHERE sku=?');
            $stmt->execute([$name,$cat,$loc,$stock,$min,$life,$active,$sku]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO items (sku,name,category,location,stock,min_stock,life_expectancy,active) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$sku,$name,$cat,$loc,$stock,$min,$life,$active]);
        }
    }
    header('Location: inventory.php');
    exit;
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Importar Excel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4">
<div class="container">
  <h1>Importar dades des d'Excel</h1>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="file" accept=".xlsx,.xls" class="form-control mb-3" required>
    <button class="btn btn-primary">Importar</button>
  </form>
  <a href="index.php" class="btn btn-secondary mt-3">Tornar</a>
</div>
</body></html>
