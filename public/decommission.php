<?php
require_once __DIR__ . '/../src/functions.php';
$message = null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $sku = $_POST['sku'];
    $quantity = max(1,(int)$_POST['quantity']);
    $reason = $_POST['reason'];
    if(decommission_item($pdo,$sku,$quantity,$reason,'system')){
        $message = 'Baixa registrada correctament.';
    } else {
        $message = 'Error: SKU no trobat.';
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Baixa recanvi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4">
<div class="container">
  <h1>Donar de baixa un recanvi</h1>
  <?php if($message): ?><div class="alert alert-info"><?=htmlspecialchars($message)?></div><?php endif; ?>
  <form method="post" class="row g-2">
    <div class="col-md-4"><input name="sku" class="form-control" placeholder="SKU" required></div>
    <div class="col-md-2"><input name="quantity" type="number" class="form-control" min="1" value="1"></div>
    <div class="col-md-6"><input name="reason" class="form-control" placeholder="Motiu (malbé, fi de vida útil, etc.)"></div>
    <div class="col-md-12 mt-2"><button class="btn btn-warning">Registrar baixa</button></div>
  </form>
  <a href="index.php" class="btn btn-secondary mt-3">Tornar</a>
</div>
</body></html>
