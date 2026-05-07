<?php
require_once("../src/config.php");
require_once("../src/warehouse_positions.php");
require_once("layout.php");
require_once("../src/new_entry.php");


$message = "";

// 🔹 Carregar totes les posicions definides al magatzem
$allPositions = $pdo->query("
    SELECT codi 
    FROM magatzem_posicions 
    ORDER BY codi ASC
")->fetchAll(PDO::FETCH_COLUMN);

/* /* 🧾 1️⃣ Registrar entrada manual (compra o proveïdor) 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual') {

    $sku         = trim($_POST['sku'] ?? '');
    $serial      = trim($_POST['serial'] ?? '');
    $sububicacio = trim($_POST['sububicacio'] ?? '');
    $ubicacio    = 'magatzem';
    $origen      = trim($_POST['origen'] ?? 'principal');
    $categoria   = trim($_POST['categoria'] ?? '');
    $vida_total  = (int)($_POST['vida_total'] ?? 0);

    // ✅ Validació bàsica
    if (!$sku || !$serial) {
        $message = "⚠️ Cal omplir SKU i Serial.";
    }

    // ✅ Validació de posició si s'ha informat (existència)
    // (La comprovació d'ocupació la farà newEntry() mirant magatzem_posicions)
    if ($message === "" && $sububicacio !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE codi = ?");
        $stmt->execute([$sububicacio]);
        if ((int)$stmt->fetchColumn() === 0) {
            $message = "❌ La posició '$sububicacio' no existeix al magatzem.";
        }
    }

    if ($message === "" && $sku && $serial) {

        // 1) Busquem si ja existeix l'item
        $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
        $stmt->execute([$sku]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            // ➕ Nou item
            $pdo->prepare("
                INSERT INTO items (sku, category, min_stock, vida_total_default, active, created_at)
                VALUES (?, ?, 0, ?, 1, NOW())
            ")->execute([$sku, $categoria, $vida_total]);

            $itemId = (int)$pdo->lastInsertId();

        } else {
            $itemId = (int)$item['id'];

        // Actualitza categoria si ve informada
        if ($categoria) {
            $pdo->prepare("UPDATE items SET category = ? WHERE id = ?")
                ->execute([$categoria, $itemId]);
        }

        // ✅ Actualitza vida_total_default si l'usuari ha informat vida_total (>0)
        if ($vida_total > 0) {
            $pdo->prepare("UPDATE items SET vida_total_default = ? WHERE id = ?")
                ->execute([$vida_total, $itemId]);
        }
    }


        // 2) Registrar entrada (unitat + posició + moviment + stock)
        $result = newEntry(
            $pdo,
            $itemId,
            $serial,
            $sububicacio !== '' ? $sububicacio : null,
            $origen,
            $vida_total,
            null // no ve de compra
        );

        if (!$result['ok']) {
            $message = $result['error'];
        } else {
            $message = "✅ Entrada registrada correctament ($serial a $ubicacio).";
        }
    }
}
 */

/* ✅ 2️⃣ Acceptar recanvi del magatzem intermig (amb escaneig) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acceptar_intermig') {
    $unitId  = (int)($_POST['unit_id'] ?? 0);
    $scanPos = trim($_POST['scan_sububicacio'] ?? '');

    if ($unitId <= 0) {
        $message = "❌ Falta la unitat a acceptar.";
    } else {
        // 1️⃣ Obtenim la unitat i la seva sububicació assignada
        $stmt = $pdo->prepare("SELECT item_id, sububicacio FROM item_units WHERE id = ?");
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$unit) {
            $message = "❌ Unitat no trobada.";
        } else {
            $expected = trim($unit['sububicacio'] ?? '');

            // 🔹 CAS 1: NO té sububicació assignada → acceptem sense escaneig
            if ($expected === '') {
                $pdo->prepare("
                    UPDATE item_units
                    SET ubicacio = 'magatzem',  maquina_actual = NULL, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$unitId]);

                $pdo->prepare("
                    INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
                    VALUES (?, ?, 'entrada', 1, 'magatzem', 'INTERMIG', NOW())
                ")->execute([$unitId, (int)$unit['item_id']]);

                $message = "✅ Recanvi acceptat al magatzem principal (sense posició definida).";

            // 🔹 CAS 2: Té sububicació assignada → escaneig + posició lliure
            } else {

                // 2️⃣ Validem escaneig
                if ($scanPos === '') {
                    $message = "❌ Cal escanejar la posició del magatzem.";
                } elseif (strcasecmp($scanPos, $expected) !== 0) {
                    $message = "❌ La posició escanejada ($scanPos) no coincideix amb la posició assignada ($expected).";
                } else {
                    // 3️⃣ Comprovem que no hi ha cap altre recanvi actiu a la mateixa posició
                    $stmtOcc = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM item_units
                        WHERE sububicacio = ?
                          AND id <> ?
                          AND estat = 'actiu'
                    ");
                    $stmtOcc->execute([$expected, $unitId]);

                    if ($stmtOcc->fetchColumn() > 0) {
                        $message = "❌ La posició '$expected' ja està ocupada per un altre recanvi actiu. Revisa l'inventari abans d'acceptar.";
                    } else {
                        // 4️⃣ Tot OK → Acceptem al magatzem
                        $pdo->prepare("
                            UPDATE item_units
                            SET ubicacio = 'magatzem',  maquina_actual = NULL, updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$unitId]);

                        $pdo->prepare("
                            INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
                            VALUES (?, ?, 'entrada', 1, 'magatzem', 'INTERMIG', NOW())
                        ")->execute([$unitId, (int)$unit['item_id']]);

                        $message = "✅ Recanvi acceptat al magatzem principal.";
                    }
                }
            }
        }
    }
}

/* ❌ 3️⃣ Donar de baixa recanvi del magatzem intermig */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'baixa_intermig') {

    $unitId = (int)($_POST['unit_id'] ?? 0);
    $motiu  = trim($_POST['baixa_motiu'] ?? '');

    // Validació del motiu
    $motiuValid = ['malmesa', 'fi_vida_util', 'altres', 'descatalogat'];
    if ($motiu === '' || !in_array($motiu, $motiuValid, true)) {
        $message = "❌ Cal seleccionar un motiu de baixa.";
    } elseif ($unitId > 0) {

        // 1️⃣ Obtenir dades actuals
        $stmt = $pdo->prepare("SELECT item_id, ubicacio, maquina_actual, sububicacio FROM item_units WHERE id = ?");
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($unit) {
            // 2️⃣ Registrar moviment
            $pdo->prepare("
                INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, observacions, created_at)
                VALUES (?, ?, 'baixa', 1, ?, ?, ?, NOW())
            ")->execute([
                $unitId,
                $unit['item_id'],
                $unit['ubicacio'] ?? 'intermig',
                $unit['maquina_actual'] ?? null,
                $motiu
            ]);

            // 3️⃣ Actualitzar unitat
            $pdo->beginTransaction();

            try {
                if (strtolower($motiu) !== 'descatalogat') {
                    // ✅ Baixa normal: alliberem posició (si en té)
                    freePositionByUnit($pdo, $unitId);

                    $pdo->prepare("
                        UPDATE item_units
                        SET estat = 'inactiu',
                            baixa_motiu = :motiu,
                            maquina_baixa = :maquina_baixa,
                            maquina_actual = NULL,
                            ubicacio = 'baixa',
                            sububicacio = NULL,
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':motiu'         => $motiu,
                        ':maquina_baixa' => $unit['maquina_actual'],
                        ':id'            => $unitId
                    ]);
                } else {
                    // ❗ Descatalogat: NO alliberem i NO toquem sububicacio
                    $pdo->prepare("
                        UPDATE item_units
                        SET estat = 'inactiu',
                            baixa_motiu = :motiu,
                            maquina_baixa = :maquina_baixa,
                            maquina_actual = NULL,
                            ubicacio = 'baixa',
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':motiu'         => $motiu,
                        ':maquina_baixa' => $unit['maquina_actual'],
                        ':id'            => $unitId
                    ]);
                }

                $pdo->commit();
                $message = "🗑️ Recanvi donat de baixa correctament.";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = "❌ Error donant de baixa: " . $e->getMessage();
            }


            $message = "🗑️ Recanvi donat de baixa correctament.";
        } else {
            $message = "⚠️ Unitat no trobada.";
        }
    }
}

/* 📦 4️⃣ Obtenir recanvis del magatzem intermig */
$intermigItems = $pdo->query("
    SELECT iu.id AS unit_id,
           i.id AS item_id,
           i.sku,
           iu.serial,
           iu.sububicacio,
           iu.maquina_actual AS maquina,
           iu.updated_at
    FROM item_units iu
    JOIN items i ON i.id = iu.item_id
    WHERE iu.estat = 'actiu' AND iu.ubicacio = 'intermig'
    ORDER BY iu.updated_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<h2 class="text-3xl font-bold mb-6">Entrades</h2>

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

  <!-- 📦 Magatzem intermig -->
  <div class="bg-white p-6 rounded-lg shadow-md">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-xl font-bold text-gray-700">🏭 Magatzem intermig</h3>
      <span class="text-sm text-gray-500"><?= count($intermigItems) ?> pendents</span>
    </div>

    <?php if (count($intermigItems) > 0): ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left border">
          <thead class="bg-gray-100 uppercase text-xs text-gray-600">
            <tr>
              <th class="px-4 py-2">SKU</th>
              <th class="px-4 py-2">Serial</th>
              <th class="px-4 py-2">Màquina origen</th>
              <th class="px-4 py-2 text-center">Accions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($intermigItems as $item): ?>
              <tr class="hover:bg-gray-50 transition">
                <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($item['sku']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($item['serial']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($item['maquina']) ?></td>
                <td class="px-4 py-2 text-center flex justify-center gap-2">
                  <!-- ✅ Acceptar: ara amb data-* i sense onclick -->
                  <button type="button"
                          class="btn-acceptar-intermig inline-flex items-center justify-center bg-green-500 hover:bg-green-600 
                                text-white rounded-full w-8 h-8 shadow transition"
                          title="Entrar al magatzem"
                          data-unit-id="<?= (int)$item['unit_id'] ?>"
                          data-sku="<?= htmlspecialchars($item['sku']) ?>"
                          data-serial="<?= htmlspecialchars($item['serial']) ?>"
                          data-sububicacio="<?= htmlspecialchars($item['sububicacio'] ?? '') ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                        stroke-width="3" stroke="white" class="w-5 h-5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                  </button>

                  <!-- ❌ Donar de baixa -->
                  <button type="button"
                          title="Donar de baixa"
                          onclick="openBaixaModal(<?= (int)$item['unit_id'] ?>)"
                          class="inline-flex items-center justify-center bg-red-500 hover:bg-red-600 
                                text-white rounded-full w-8 h-8 shadow transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                        stroke-width="3" stroke="white" class="w-5 h-5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>

                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-gray-500 italic">No hi ha recanvis pendents al magatzem intermig.</p>
    <?php endif; ?>
  </div>
</div>

<!-- 💬 Modal de motiu de baixa des de magatzem intermig -->
<div id="baixaIntermigModal"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-sm">
    <h3 class="text-lg font-semibold mb-4">Donar de baixa recanvi</h3>
    <p class="text-sm text-gray-600 mb-4">
      Tria el motiu de la baixa per aquest recanvi del magatzem intermig.
    </p>

    <form method="POST">
      <input type="hidden" name="action" value="baixa_intermig">
      <input type="hidden" name="unit_id" id="baixaIntermigUnitId">

      <label class="block text-sm font-medium text-gray-700 mb-1">
        Motiu de la baixa
      </label>
      <select name="baixa_motiu"
              id="baixaIntermigMotiu"
              required
              class="w-full border rounded px-2 py-2 text-sm mb-4">
        <option value="">Selecciona un motiu…</option>
        <option value="malmesa">Camisa malmesa</option>
        <option value="fi_vida_util">Fi de vida útil</option>
        <option value="altres">Altres</option>
        <option value="descatalogat">Descatalogat</option>
      </select>

      <div class="flex justify-end gap-2">
        <button type="button"
                onclick="closeBaixaModal()"
                class="px-3 py-2 text-sm rounded border border-gray-300 hover:bg-gray-100">
          Cancel·lar
        </button>
        <button type="submit"
                onclick="return confirm('Segur que vols donar de baixa aquest recanvi?');"
                class="px-3 py-2 text-sm rounded bg-red-600 text-white hover:bg-red-700">
          Confirmar baixa
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ✅ Modal ACCEPTAR INTERMIG amb escaneig de posició -->
<div id="acceptIntermigModal"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-sm">
    <h3 class="text-lg font-semibold mb-4">Acceptar recanvi al magatzem</h3>

    <p class="text-sm text-gray-700 mb-2">
      Confirma que deixes el recanvi a la seva posició assignada.
    </p>

    <div class="text-xs bg-gray-50 border rounded p-2 mb-3 space-y-1">
      <div><span class="font-semibold">SKU:</span> <span id="accept-sku"></span></div>
      <div><span class="font-semibold">Serial:</span> <span id="accept-serial"></span></div>
      <div><span class="font-semibold">Posició assignada:</span> <span id="accept-sububicacio" class="font-mono"></span></div>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="acceptar_intermig">
      <input type="hidden" name="unit_id" id="accept-unit-id">

      <label class="block text-sm font-medium text-gray-700 mb-1">
        Escaneja el codi de la posició
      </label>
      <input type="text"
             name="scan_sububicacio"
             id="accept-scan-input"
             class="w-full border rounded px-2 py-2 text-sm mb-4 font-mono"
             placeholder="Ex: 01A03"
             autocomplete="off">

      <div class="flex justify-end gap-2">
        <button type="button"
                onclick="closeAcceptModal()"
                class="px-3 py-2 text-sm rounded border border-gray-300 hover:bg-gray-100">
          Cancel·lar
        </button>
        <button type="submit"
                class="px-3 py-2 text-sm rounded bg-green-600 text-white hover:bg-green-700">
          Confirmar
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  function openBaixaModal(unitId) {
    document.getElementById('baixaIntermigUnitId').value = unitId;
    document.getElementById('baixaIntermigMotiu').value = '';
    document.getElementById('baixaIntermigModal').classList.remove('hidden');
  }

  function closeBaixaModal() {
    document.getElementById('baixaIntermigModal').classList.add('hidden');
  }

  function openAcceptModal(unitId, sku, serial, sububicacio) {
    document.getElementById('accept-unit-id').value = unitId;
    document.getElementById('accept-sku').textContent = sku;
    document.getElementById('accept-serial').textContent = serial;
    document.getElementById('accept-sububicacio').textContent = sububicacio || '(sense posició)';
    const modal = document.getElementById('acceptIntermigModal');
    modal.classList.remove('hidden');

    const input = document.getElementById('accept-scan-input');
    input.value = '';
    setTimeout(() => input.focus(), 50);
  }

  function closeAcceptModal() {
    document.getElementById('acceptIntermigModal').classList.add('hidden');
  }

  // 🔹 ENLLAÇ DELS BOTONS VERDS AMB openAcceptModal (sense onclick en línia)
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.btn-acceptar-intermig').forEach(btn => {
      btn.addEventListener('click', () => {
        const unitId      = btn.dataset.unitId;
        const sku         = btn.dataset.sku;
        const serial      = btn.dataset.serial;
        const sububicacio = btn.dataset.sububicacio || '';
        openAcceptModal(unitId, sku, serial, sububicacio);
      });
    });
  });
</script>

<?php
$content = ob_get_clean();
renderPage("Entrades", $content);
?>
