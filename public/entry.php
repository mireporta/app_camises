<?php
require_once __DIR__ . '/../src/functions.php';
$message = null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $sku = $_POST['sku'];
    $quantity = max(0,(int)$_POST['quantity']);
    $to_location = $_POST['to_location'];
    $machine = $_POST['machine'];
    $item = find_item_by_sku($pdo, $sku);
    if(!$item){ $message = 'SKU no trobat.'; }
    else {
        update_stock($pdo, $item['id'], $quantity);
        record_operation($pdo, $item['id'], 'entry', $quantity, null, $to_location, $machine, 'system', null);
        $life = (int)$item['life_expectancy'];
        $msg = 'Entrada registrada.';
        if($life>0){
            if($quantity < $life){ $msg .= " Produït: $quantity < Vida teòrica: $life"; }
            else { $msg .= " Produït: $quantity >= Vida teòrica: $life"; }
        }
        $message = $msg;
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Entrada</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
window.addEventListener('DOMContentLoaded', ()=> document.getElementById('scan_input')?.focus());
</script>
</head><body class="p-4">
<div class="container">
  <h1>Registrar Entrada</h1>
  <?php if($message): ?><div class="alert alert-info"><?=htmlspecialchars($message)?></div><?php endif; ?>
  <form method="post" class="row g-2">
    <div class="col-md-4"><input id="scan_input" name="sku" class="form-control" placeholder="Escaneja SKU (camisa)" required></div>
    <div class="col-md-2"><input name="quantity" type="number" class="form-control" value="0" min="0"></div>
    <div class="col-md-3"><input name="machine" class="form-control" placeholder="Màquina origen"></div>
    <div class="col-md-3"><input name="to_location" class="form-control" placeholder="Ubicació destí (magatzem)"></div>
    <div class="col-md-12 mt-2"><button class="btn btn-success">Registrar Entrada</button></div>
  </form>
  <a href="index.php" class="btn btn-secondary mt-3">Tornar</a>
</div>
</body></html>
