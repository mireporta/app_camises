<?php
require_once("../src/config.php");
require_once("layout.php");
require_once("../src/new_entry.php");

$message = "";
$rxKeepOpen = false;
$rxData = null;


// 🔹 Carregar totes les posicions definides al magatzem
$allPositions = $pdo->query("
    SELECT codi 
    FROM magatzem_posicions 
    ORDER BY codi ASC
")->fetchAll(PDO::FETCH_COLUMN);

/* 🟠 A) Marcar com comprat (comanda real feta) -> CREA compra AUTO a compres_recanvis */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'marcar_comprat') {
    $itemId    = (int)($_POST['item_id'] ?? 0);
    $qtyCompra = (int)($_POST['qty_comprada'] ?? 0);
    $proveidor = trim($_POST['proveidor'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    if ($itemId <= 0 || $qtyCompra <= 0 || $proveidor === '') {
        $message = "⚠️ Cal informar quantitat i proveïdor.";
    } else {
        $pdo->prepare("
            INSERT INTO compres_recanvis
                (item_id, qty, qty_entrada, proveidor, notes, source, estat, created_at, updated_at)
            VALUES
                (?, ?, 0, ?, ?, 'auto', 'demanada', NOW(), NOW())
        ")->execute([$itemId, $qtyCompra, $proveidor, $notes ?: null]);

        $message = "✅ Compra creada (auto).";
    }
}

/* 🧾 1️⃣ Crear una compra manual (comanda feta) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear_compra') {

    $sku       = trim($_POST['sku'] ?? '');
    $qty       = (int)($_POST['qty'] ?? 0);
    $proveidor = trim($_POST['proveidor'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');
    $categoria = trim($_POST['categoria'] ?? '');
    $vidaDefault = (int)($_POST['vida_total_default'] ?? 0);


    if ($sku === '' || $qty <= 0 || $proveidor === '') {
        $message = "⚠️ Cal omplir SKU, quantitat i proveïdor.";
    } else {
        // Buscar item per SKU
        $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
        $stmt->execute([$sku]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            // ✅ SKU nova: demanem vida_total_default
            if ($vidaDefault <= 0) {
                $message = "⚠️ Per una SKU nova cal informar la vida útil per defecte.";
            } else {
                $pdo->prepare("
                    INSERT INTO items (sku, category, min_stock, vida_total_default, active, created_at)
                    VALUES (?, ?, 0, ?, 1, NOW())
                ")->execute([$sku, $categoria, $vidaDefault]);

                $itemId = (int)$pdo->lastInsertId();
            }
        } else {
            $itemId = (int)$item['id'];

            // Actualitza categoria si ve informada
            if ($categoria !== '') {
                $pdo->prepare("UPDATE items SET category = ? WHERE id = ?")
                    ->execute([$categoria, $itemId]);
            }

            // (Opcional) Si l’SKU existeix però té vida_total_default a 0 i l’usuari n’informa una, la guardem
            if ($vidaDefault > 0) {
                $pdo->prepare("
                    UPDATE items
                    SET vida_total_default = CASE WHEN COALESCE(vida_total_default,0)=0 THEN ? ELSE vida_total_default END
                    WHERE id = ?
                ")->execute([$vidaDefault, $itemId]);
            }
        }


        // Crear compra manual
        if ($message === "") {
        $pdo->prepare("
            INSERT INTO compres_recanvis (item_id, qty, qty_entrada, proveidor, notes, source, estat, created_at, updated_at)
            VALUES (?, ?, 0, ?, ?, 'manual', 'demanada', NOW(), NOW())
        ")->execute([$itemId, $qty, $proveidor, $notes ?: null]);

        $message = "✅ Compra manual creada correctament ($sku x$qty).";
        }
    }
}

/* 📦 2️⃣ Registrar recepció (entrada) d’una compra (AUTO o MANUAL) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recepcionar') {

    $compraId    = (int)($_POST['compra_id'] ?? 0);
    $serial      = trim($_POST['serial'] ?? '');
    $sububicacio = trim($_POST['sububicacio'] ?? '');

    if ($compraId <= 0 || $serial === '') {
        $message = "⚠️ Falta compra o serial.";
    } else {

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

                // Validació posició (si informada)
                if ($sububicacio !== '') {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE codi = ?");
                    $st->execute([$sububicacio]);
                    if ((int)$st->fetchColumn() === 0) {
                        $message = "❌ La posició '$sububicacio' no existeix al magatzem.";
                    }
                }

                if ($message === "") {
                    // newEntry: crea unitat + moviment + ocupa posició (si ho tens implementat dins)
                    $result = newEntry(
                        $pdo,
                        (int)$c['item_id'],
                        $serial,
                        $sububicacio !== '' ? $sububicacio : null,
                        $c['proveidor'],
                        0,                 // vida_total: si 0, agafa vida_total_default
                        (int)$c['compra_id']
                    );

                    if (!$result['ok']) {
                        $message = $result['error'];
                    } else {
                        $message = "✅ Recepció OK: {$c['sku']} ($serial).";
                        // 🔁 Mode PDA (A): si s'ha enviat des del modal i encara queda pendent, reobrim el modal
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
                                        'sububicacio' => $sububicacio, // mantenim posició si l'has informat
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

/* 🟠 Llistat: per comprar (sota mínim) amb stock_real + pendent_arribar */
$toBuy = $pdo->query("
  SELECT 
    i.id, i.sku, i.category, i.min_stock,
    COALESCE(u.stock_real, 0) AS stock_real,
    COALESCE(p.pendent_arribar, 0) AS pendent_arribar
  FROM items i
  LEFT JOIN (
    SELECT item_id, COUNT(*) AS stock_real
    FROM item_units
    WHERE estat = 'actiu'
    GROUP BY item_id
  ) u ON u.item_id = i.id
  LEFT JOIN (
    SELECT item_id, SUM(qty - qty_entrada) AS pendent_arribar
    FROM compres_recanvis
    WHERE estat IN ('demanada','parcial')
    GROUP BY item_id
  ) p ON p.item_id = i.id
  WHERE i.active = 1
    AND COALESCE(u.stock_real, 0) < i.min_stock
  ORDER BY (i.min_stock - (COALESCE(u.stock_real,0) + COALESCE(p.pendent_arribar,0))) DESC, i.sku ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* 📋 Pendents de recepció (únic llistat: AUTO + MANUAL) */
$pendents = $pdo->query("
    SELECT c.id, c.qty, c.qty_entrada, c.proveidor, c.notes, c.estat, c.source, c.created_at,
           i.sku, i.category
    FROM compres_recanvis c
    JOIN items i ON i.id = c.item_id
    WHERE c.estat IN ('demanada','parcial')
    ORDER BY c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<h2 class="text-3xl font-bold mb-6">Maintenance</h2>

<?php if ($message): ?>
  <?php
    $isError = (strpos($message, "❌") === 0 || strpos($message, "⚠️") === 0);
    $class = $isError
      ? 'bg-red-100 border-red-300 text-red-800'
      : 'bg-green-100 border-green-300 text-green-800';
  ?>
  <div class="mb-4 p-3 rounded border <?= $class ?>">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

  <!-- 🟠 Per comprar (sota mínim) -->
  <div class="bg-white p-6 rounded-lg shadow-md">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-xl font-bold text-gray-700">🟠 Per comprar (sota mínim)</h3>
      <span class="text-sm text-gray-500"><?= count($toBuy) ?> items</span>
    </div>

    <?php if (count($toBuy) > 0): ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left border">
          <thead class="bg-gray-100 uppercase text-xs text-gray-600">
            <tr>
              <th class="px-3 py-2">SKU</th>
              <th class="px-3 py-2">Stock</th>
              <th class="px-3 py-2">Pendent</th>
              <th class="px-3 py-2">Mínim</th>
              <th class="px-3 py-2">Suggerit</th>
              <th class="px-3 py-2 text-center">Acció</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-gray-100">
            <?php foreach ($toBuy as $it): ?>
              <?php
                $stockReal = (int)$it['stock_real'];
                $pendentArribar = (int)$it['pendent_arribar'];
                $minStock = (int)$it['min_stock'];

                $suggestRaw = $minStock - ($stockReal + $pendentArribar);
                $suggest = max(0, $suggestRaw);
                $canBuy = ($suggestRaw > 0);
              ?>

              <tr class="hover:bg-gray-50 transition">
                <td class="px-3 py-2 font-semibold"><?= htmlspecialchars($it['sku']) ?></td>
                <td class="px-3 py-2"><?= $stockReal ?></td>
                <td class="px-3 py-2"><?= $pendentArribar ?></td>
                <td class="px-3 py-2"><?= $minStock ?></td>

                <td class="px-3 py-2 font-semibold <?= $canBuy ? 'text-orange-700' : 'text-gray-500' ?>">
                  <?= $suggest ?>
                </td>

                <td class="px-3 py-2 text-center">
                  <button type="button"
                          class="btn-marcar-comprat inline-flex items-center justify-center rounded px-3 py-1 shadow transition
                                 <?= $canBuy ? 'bg-blue-500 hover:bg-blue-600 text-white' : 'bg-gray-200 text-gray-400 cursor-not-allowed' ?>"
                          <?= $canBuy ? '' : 'disabled' ?>
                          data-item-id="<?= (int)$it['id'] ?>"
                          data-sku="<?= htmlspecialchars($it['sku']) ?>"
                          data-suggest="<?= $canBuy ? (int)$suggest : 1 ?>">
                    Marcar comprat
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="text-xs text-gray-400 mt-2">
        “Pendent” = unitats demanades però encara no entrades (sumatori de totes les compres pendents).
      </p>

    <?php else: ?>
      <p class="text-gray-500 italic">No hi ha cap item sota mínim pendent de compra.</p>
    <?php endif; ?>
  </div>

  <!-- 🧾 Crear compra manual -->
  <div class="bg-white p-6 rounded-lg shadow-md">
    <h3 class="text-xl font-bold mb-4 text-gray-700">🧾 Nova compra manual</h3>

    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="crear_compra">

      <div>
        <label class="block mb-1 font-medium">SKU</label>
        <input type="text" name="sku" required class="w-full p-2 border rounded focus:ring focus:ring-blue-200" placeholder="Ex: ENRE001">
      </div>

      <div>
        <label class="block mb-1 font-medium">Categoria (si és SKU nova o per actualitzar)</label>
        <input type="text" name="categoria" class="w-full p-2 border rounded focus:ring focus:ring-blue-200" placeholder="Ex: A4 / A5">
      </div>

      <div>
        <label class="block mb-1 font-medium">Vida útil per defecte (només si SKU nova)</label>
        <input type="number" name="vida_total_default" min="0"
              class="w-full p-2 border rounded focus:ring focus:ring-blue-200"
              placeholder="Ex: 200">
        <p class="text-xs text-gray-400 mt-1">
          Si l’SKU ja existeix, aquest camp s’ignora (no sobreescriu el valor actual).
        </p>
      </div>

      <div>
        <label class="block mb-1 font-medium">Quantitat comprada</label>
        <input type="number" name="qty" min="1" required class="w-full p-2 border rounded focus:ring focus:ring-blue-200" value="1">
      </div>

      <div>
        <label class="block mb-1 font-medium">Proveïdor</label>
        <input type="text" name="proveidor" required class="w-full p-2 border rounded focus:ring focus:ring-blue-200" placeholder="Ex: Proveïdor 1">
      </div>

      <div>
        <label class="block mb-1 font-medium">Notes (opcional)</label>
        <input type="text" name="notes" class="w-full p-2 border rounded focus:ring focus:ring-blue-200">
      </div>

      <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700 transition w-full">
        Guardar compra
      </button>
    </form>
  </div>

  <!-- 📦 Pendents de recepció (AUTO + MANUAL) -->
  <div class="bg-white p-6 rounded-lg shadow-md lg:col-span-2">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-xl font-bold text-gray-700">📦 Pendents de recepció</h3>
      <span class="text-sm text-gray-500"><?= count($pendents) ?> pendents</span>
    </div>

    <?php if (count($pendents) > 0): ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left border">
          <thead class="bg-gray-100 uppercase text-xs text-gray-600">
            <tr>
              <th class="px-3 py-2">Data</th>
              <th class="px-3 py-2">Tipus</th>
              <th class="px-3 py-2">SKU</th>
              <th class="px-3 py-2">Qty</th>
              <th class="px-3 py-2">Entrada</th>
              <th class="px-3 py-2">Pendent</th>
              <th class="px-3 py-2">Proveïdor</th>
              <th class="px-3 py-2">Notes</th>
              <th class="px-3 py-2 text-center">Acció</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($pendents as $p): ?>
              <?php $pendent = (int)$p['qty'] - (int)$p['qty_entrada']; ?>
              <tr class="hover:bg-gray-50 transition">
                <td class="px-3 py-2"><?= htmlspecialchars(substr($p['created_at'], 0, 10)) ?></td>

                <td class="px-3 py-2">
                  <?php if (($p['source'] ?? 'manual') === 'auto'): ?>
                    <span class="text-xs px-2 py-1 rounded bg-orange-100 text-orange-800">AUTO</span>
                  <?php else: ?>
                    <span class="text-xs px-2 py-1 rounded bg-blue-100 text-blue-800">MANUAL</span>
                  <?php endif; ?>
                </td>

                <td class="px-3 py-2 font-semibold"><?= htmlspecialchars($p['sku']) ?></td>
                <td class="px-3 py-2"><?= (int)$p['qty'] ?></td>
                <td class="px-3 py-2"><?= (int)$p['qty_entrada'] ?></td>

                <td class="px-3 py-2 font-bold <?= $pendent > 0 ? 'text-orange-700' : 'text-green-700' ?>">
                  <?= $pendent ?>
                </td>

                <td class="px-3 py-2"><?= htmlspecialchars($p['proveidor']) ?></td>
                <td class="px-3 py-2"><?= htmlspecialchars($p['notes']) ?></td>

                <td class="px-3 py-2 text-center">
                  <?php if ($pendent > 0): ?>
                    <button type="button"
                            class="btn-recepcionar inline-flex items-center justify-center bg-green-500 hover:bg-green-600 text-white rounded px-3 py-1 shadow transition"
                            data-compra-id="<?= (int)$p['id'] ?>"
                            data-sku="<?= htmlspecialchars($p['sku']) ?>"
                            data-proveidor="<?= htmlspecialchars($p['proveidor']) ?>"
                            data-pendent="<?= (int)$pendent ?>">
                      Entrar
                    </button>
                  <?php else: ?>
                    <span class="text-xs text-gray-500">Complet</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-gray-500 italic">No hi ha compres pendents.</p>
    <?php endif; ?>
  </div>

</div>

<!-- 🔽 datalist posicions -->
<datalist id="llista-sububicacions">
  <?php foreach ($allPositions as $pos): ?>
    <option value="<?= htmlspecialchars($pos) ?>"></option>
  <?php endforeach; ?>
</datalist>

<!-- ✅ Modal Marcar comprat -->
<div id="compratModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-sm">
    <h3 class="text-lg font-semibold mb-4">Marcar com comprat</h3>

    <div class="text-xs bg-gray-50 border rounded p-2 mb-3 space-y-1">
      <div><span class="font-semibold">SKU:</span> <span id="cb-sku"></span></div>
    </div>

    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="marcar_comprat">
      <input type="hidden" name="item_id" id="cb-item-id">

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Quantitat comprada</label>
        <input type="number" name="qty_comprada" id="cb-qty" min="1"
               class="w-full border rounded px-2 py-2 text-sm" required>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Proveïdor</label>
        <input type="text" name="proveidor" id="cb-proveidor"
               class="w-full border rounded px-2 py-2 text-sm" required>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Notes (opcional)</label>
        <input type="text" name="notes" class="w-full border rounded px-2 py-2 text-sm">
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button"
                onclick="closeCompratModal()"
                class="px-3 py-2 text-sm rounded border border-gray-300 hover:bg-gray-100">
          Cancel·lar
        </button>
        <button type="submit"
                class="px-3 py-2 text-sm rounded bg-blue-600 text-white hover:bg-blue-700">
          Marcar comprat
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ✅ Modal recepcionar compra (auto o manual) -->
<div id="recepcioModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-sm">
    <h3 class="text-lg font-semibold mb-4">Recepcionar compra</h3>

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
          Registrar entrada
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  function openCompratModal(itemId, sku, suggest) {
    document.getElementById('cb-item-id').value = itemId;
    document.getElementById('cb-sku').textContent = sku;
    document.getElementById('cb-qty').value = suggest || 1;
    document.getElementById('cb-proveidor').value = '';
    document.getElementById('compratModal').classList.remove('hidden');
    setTimeout(() => document.getElementById('cb-proveidor').focus(), 50);
  }
  function closeCompratModal() {
    document.getElementById('compratModal').classList.add('hidden');
  }

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

    document.querySelectorAll('.btn-marcar-comprat').forEach(btn => {
      btn.addEventListener('click', () => {
        openCompratModal(btn.dataset.itemId, btn.dataset.sku, btn.dataset.suggest);
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

    // Manté la sububicació si s'havia informat
    const sub = document.getElementById('rx-sububicacio');
    if (sub) sub.value = <?= json_encode($rxData['sububicacio'] ?? '') ?>;
  });
</script>
<?php endif; ?>



<?php
$content = ob_get_clean();
renderPage("Maintenance", $content);
?>
