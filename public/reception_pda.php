<?php
require_once("../src/config.php");
require_once("layout_operari.php");
require_once("../src/new_entry.php");

$message = "";
$rxKeepOpen = false;
$rxData = null;

// 🔹 Posicions per datalist
$allPositions = $pdo->query("
    SELECT codi 
    FROM magatzem_posicions 
    ORDER BY codi ASC
")->fetchAll(PDO::FETCH_COLUMN);

/* 📦 Recepcionar (Mode A: reobrir modal mentre quedi pendent) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recepcionar') {

    $compraId    = (int)($_POST['compra_id'] ?? 0);
    $serial      = trim($_POST['serial'] ?? '');
    $sububicacio = trim($_POST['sububicacio'] ?? '');

    if ($compraId <= 0 || $serial === '') {
        $message = "⚠️ Falta compra o serial.";
    } else {
        // Validació posició si informada
        if ($sububicacio !== '') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE codi = ?");
            $st->execute([$sububicacio]);
            if ((int)$st->fetchColumn() === 0) {
                $message = "❌ La posició '$sububicacio' no existeix.";
            }
        }

        if ($message === "") {
            // Carrega compra
            $stmt = $pdo->prepare("
                SELECT c.id AS compra_id, c.item_id, c.qty, c.qty_entrada, c.proveidor, i.sku
                FROM compres_recanvis c
                JOIN items i ON i.id = c.item_id
                WHERE c.id = ?
            ");
            $stmt->execute([$compraId]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$c) {
                $message = "❌ Compra no trobada.";
            } else {
                $pendent = (int)$c['qty'] - (int)$c['qty_entrada'];
                if ($pendent <= 0) {
                    $message = "⚠️ Aquesta compra ja està completament recepcionada.";
                } else {
                    $result = newEntry(
                        $pdo,
                        (int)$c['item_id'],
                        $serial,
                        $sububicacio !== '' ? $sububicacio : null,
                        $c['proveidor'],
                        0,
                        (int)$c['compra_id']
                    );

                    if (!$result['ok']) {
                        $message = $result['error'];
                    } else {
                        $message = "✅ OK: {$c['sku']} ($serial)";

                        // 🔁 Mode A: si queda pendent i venim del modal, reobrim modal
                        if (isset($_POST['stay_open']) && $_POST['stay_open'] == '1') {
                            $stmt2 = $pdo->prepare("
                                SELECT c.id, c.qty, c.qty_entrada, c.proveidor, i.sku
                                FROM compres_recanvis c
                                JOIN items i ON i.id = c.item_id
                                WHERE c.id = ?
                            ");
                            $stmt2->execute([$compraId]);
                            $after = $stmt2->fetch(PDO::FETCH_ASSOC);

                            if ($after) {
                                $pendAfter = (int)$after['qty'] - (int)$after['qty_entrada'];
                                if ($pendAfter > 0) {
                                    $rxKeepOpen = true;
                                    $rxData = [
                                        'compra_id'   => (int)$after['id'],
                                        'sku'         => $after['sku'],
                                        'proveidor'   => $after['proveidor'],
                                        'pendent'     => $pendAfter,
                                        'sububicacio' => $sububicacio,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/* 📋 Pendents de recepció */
$pendents = $pdo->query("
    SELECT c.id, c.qty, c.qty_entrada, c.proveidor, c.source, c.created_at,
           i.sku
    FROM compres_recanvis c
    JOIN items i ON i.id = c.item_id
    WHERE c.estat IN ('demanada','parcial')
    ORDER BY c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<div class="mb-10">
<a href="entry_pda.php"
   class="font-semibold px-3 py-2 rounded bg-orange-400 text-white text-sm mb-10">
  Peticions
</a>
</div>

<h2 class="text-2xl font-bold mb-4">Recepció camises</h2>

<?php if ($message): ?>
  <?php
    $isError = (strpos($message, "❌") === 0 || strpos($message, "⚠️") === 0);
    $class = $isError ? 'bg-red-100 border-red-300 text-red-800' : 'bg-green-100 border-green-300 text-green-800';
  ?>
  <div class="mb-3 p-3 rounded border <?= $class ?>">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div class="bg-white p-4 rounded-lg shadow-md">
  <div class="flex justify-between items-center mb-3">
    <div class="text-lg font-bold text-gray-700">⬇️ Camises per entrar</div>
    <div class="text-sm text-gray-500"><?= count($pendents) ?></div>
  </div>

  <?php if (count($pendents) > 0): ?>
    <div class="space-y-2">
      <?php foreach ($pendents as $p): ?>
        <?php $pendent = (int)$p['qty'] - (int)$p['qty_entrada']; ?>
        <button type="button"
                class="btn-recepcionar w-full text-left p-3 rounded border hover:bg-gray-50 active:bg-gray-100"
                data-compra-id="<?= (int)$p['id'] ?>"
                data-sku="<?= htmlspecialchars($p['sku']) ?>"
                data-proveidor="<?= htmlspecialchars($p['proveidor']) ?>"
                data-pendent="<?= (int)$pendent ?>">
          <div class="flex justify-between items-center">
            <div class="font-semibold"><?= htmlspecialchars($p['sku']) ?></div>
            <div class="text-sm font-bold <?= $pendent > 0 ? 'text-orange-700' : 'text-green-700' ?>">
              <?= $pendent ?>
            </div>
          </div>
          <div class="text-xs text-gray-500 mt-1">
            <?= htmlspecialchars($p['proveidor']) ?> · <?= htmlspecialchars(substr($p['created_at'], 0, 10)) ?>
          </div>
        </button>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="text-gray-500 italic">No hi ha entrades pendents.</p>
  <?php endif; ?>
</div>

<!-- 🔽 datalist posicions -->
<datalist id="llista-sububicacions">
  <?php foreach ($allPositions as $pos): ?>
    <option value="<?= htmlspecialchars($pos) ?>"></option>
  <?php endforeach; ?>
</datalist>

<!-- ✅ Modal recepció (PDA) -->
<div id="recepcioModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-xl shadow-lg p-5 w-full max-w-sm">
    <h3 class="text-lg font-semibold mb-3">Entrar unitat</h3>

    <div class="text-xs bg-gray-50 border rounded p-2 mb-3 space-y-1">
      <div><span class="font-semibold">Compra #</span> <span id="rx-compra-id"></span></div>
      <div><span class="font-semibold">SKU:</span> <span id="rx-sku"></span></div>
      <div><span class="font-semibold">Proveïdor:</span> <span id="rx-proveidor"></span></div>
      <div><span class="font-semibold">Pendent:</span> <span id="rx-pendent"></span></div>
    </div>

    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="recepcionar">
      <input type="hidden" name="compra_id" id="rx-compra-input">
      <input type="hidden" name="stay_open" value="1">

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Serial</label>
        <input type="text" name="serial" id="rx-serial"
               class="w-full border rounded px-2 py-2 text-sm font-mono"
               autocomplete="off" required>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Posició (opcional)</label>
        <input type="text" name="sububicacio" id="rx-sububicacio"
               list="llista-sububicacions"
               class="w-full border rounded px-2 py-2 text-sm font-mono"
               placeholder="Ex: 01A03">
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button"
                onclick="closeRecepcioModal()"
                class="px-3 py-2 text-sm rounded border border-gray-300 hover:bg-gray-100">
          Cancel·lar
        </button>
        <button type="submit"
                class="px-3 py-2 text-sm rounded bg-green-600 text-white hover:bg-green-700">
          Registrar
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  function openRecepcioModal(compraId, sku, proveidor, pendent) {
    document.getElementById('rx-compra-id').textContent = compraId;
    document.getElementById('rx-sku').textContent = sku;
    document.getElementById('rx-proveidor').textContent = proveidor;
    document.getElementById('rx-pendent').textContent = pendent;
    document.getElementById('rx-compra-input').value = compraId;

    document.getElementById('recepcioModal').classList.remove('hidden');

    const serial = document.getElementById('rx-serial');
    serial.value = '';
    setTimeout(() => { serial.focus(); serial.select(); }, 50);
  }

  function closeRecepcioModal() {
    document.getElementById('recepcioModal').classList.add('hidden');
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.btn-recepcionar').forEach(btn => {
      btn.addEventListener('click', () => {
        openRecepcioModal(btn.dataset.compraId, btn.dataset.sku, btn.dataset.proveidor, btn.dataset.pendent);
      });
    });
  });
</script>

<?php if (!empty($rxKeepOpen) && !empty($rxData)): ?>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    openRecepcioModal(
      <?= (int)$rxData['compra_id'] ?>,
      <?= json_encode($rxData['sku']) ?>,
      <?= json_encode($rxData['proveidor']) ?>,
      <?= (int)$rxData['pendent'] ?>
    );
    const sub = document.getElementById('rx-sububicacio');
    if (sub) sub.value = <?= json_encode($rxData['sububicacio'] ?? '') ?>;
  });
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
//renderPage("Recepció PDA", $content);
renderOperariPage("PDA","Responsable", $content);
?>
