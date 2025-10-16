<?php
require_once __DIR__ . '/../src/functions.php';
$message = null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $sku = $_POST['sku'];
    $quantity = max(1,(int)$_POST['quantity']);
    $from_location = $_POST['from_location'];
    $machine = $_POST['machine'];
    $item = find_item_by_sku($pdo, $sku);
    if(!$item){ $message = 'SKU no trobat.'; }
    else {
        update_stock($pdo, $item['id'], -$quantity);
        record_operation($pdo, $item['id'], 'exit', $quantity, $from_location, null, $machine, 'system', null);
        $message = 'Sortida registrada.';
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Sortida</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
window.addEventListener('DOMContentLoaded', ()=>{
  const input = document.getElementById('scan_input');
  if(input) input.focus();
});
</script>
</head><body class="p-4">
<div class="container">
  <h1>Registrar Sortida</h1>
  <?php if($message): ?><div class="alert alert-info"><?=htmlspecialchars($message)?></div><?php endif; ?>
  <form method="post" class="row g-2">
    <div class="col-md-4"><input id="scan_input" name="sku" class="form-control" placeholder="Escaneja SKU (o escriu)" required></div>
    <!-- <div class="col-md-2"><input name="quantity" type="number" class="form-control" value="1" min="1"></div> -->
    <div class="col-md-3"><input name="from_location" class="form-control" placeholder="Ubicació d'origen (magatzem)"></div>
    <div class="col-md-3"><input name="machine" class="form-control" placeholder="Màquina destí"></div>
    <div class="col-md-12 mt-2"><button class="btn btn-danger">Registrar Sortida</button></div>
  </form>
  <a href="index.php" class="btn btn-secondary mt-3">Tornar</a>
</div>
</body></html>
