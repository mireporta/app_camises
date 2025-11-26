<?php
require_once("../src/config.php");
require_once("layout.php");

// Sessió per poder guardar missatges entre peticions
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Recuperem (i esborrem) el missatge de la petició anterior
$message = $_SESSION['entry_pda_message'] ?? "";
unset($_SESSION['entry_pda_message']);

// 🔹 Carregar totes les posicions definides al magatzem
$allPositions = $pdo->query("
    SELECT codi 
    FROM magatzem_posicions 
    ORDER BY codi ASC
")->fetchAll(PDO::FETCH_COLUMN);

/* 🧾 1️⃣ Registrar entrada manual (compra o proveïdor) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual') {
    $sku         = trim($_POST['sku'] ?? '');
    $serial      = trim($_POST['serial'] ?? '');
    $sububicacio = trim($_POST['sububicacio'] ?? '');
    $proveidor   = trim($_POST['proveidor'] ?? '');
    $categoria   = trim($_POST['categoria'] ?? '');
    $vida_total  = (int)($_POST['vida_total'] ?? 0);
    $ubicacio    = 'magatzem';

    $message = "";

    // ✅ Validacions bàsiques
    if (!$sku || !$serial) {
        $message = "⚠️ Cal omplir SKU i Serial.";
    } elseif ($proveidor === '') {
        $message = "⚠️ Cal indicar el proveïdor.";
    } else {
        // Validació de sububicació (si s'ha informat)
        if ($sububicacio !== '') {
            // 1) Ha d'existir a magatzem_posicions
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE codi = ?");
            $stmt->execute([$sububicacio]);
            if ($stmt->fetchColumn() == 0) {
                $message = "❌ La posició '$sububicacio' no existeix al magatzem.";
            } else {
                // 2) No la pot estar usant cap altra unitat
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM item_units WHERE sububicacio = ?");
                $stmt->execute([$sububicacio]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "❌ La posició '$sububicacio' ja està ocupada per un altre recanvi.";
                }
            }
        }
    }

    if ($message === "") {
        // busquem si ja existeix l'item
        $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
        $stmt->execute([$sku]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            // ➕ Nou item (sense name ni stock)
            $pdo->prepare("
                INSERT INTO items (sku, category, min_stock, active, created_at)
                VALUES (?, ?, 0, 1, NOW())
            ")->execute([$sku, $categoria]);
            $itemId = (int)$pdo->lastInsertId();
        } else {
            $itemId = (int)$item['id'];
            if ($categoria) {
                $pdo->prepare("UPDATE items SET category = ? WHERE id = ?")
                    ->execute([$categoria, $itemId]);
            }
        }

        // Comprovem que no existeixi el serial
        $check = $pdo->prepare("SELECT COUNT(*) FROM item_units WHERE serial = ?");
        $check->execute([$serial]);
        if ($check->fetchColumn() > 0) {
            $message = "⚠️ Ja existeix una unitat amb el serial $serial.";
        } else {
            // Creem la unitat
            $pdo->prepare("
                INSERT INTO item_units (item_id, serial, ubicacio, sububicacio, estat, vida_utilitzada, vida_total, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'actiu', 0, ?, NOW(), NOW())
            ")->execute([
                $itemId,
                $serial,
                'magatzem',
                $sububicacio !== '' ? $sububicacio : null,
                $vida_total
            ]);

            // Registrem moviment d'entrada
            // 👉 Guardem el proveïdor a la columna `maquina` per les entrades
            $pdo->prepare("
                INSERT INTO moviments (item_id, item_unit_id, tipus, quantitat, ubicacio, maquina, created_at)
                SELECT ?, id, 'entrada', 1, 'magatzem', ?, NOW()
                FROM item_units
                WHERE serial = ?
            ")->execute([
                $itemId,
                $proveidor,
                $serial
            ]);

            $message = "✅ Entrada registrada correctament ($serial a $ubicacio).";
        }
    }

    // POST → Redirect → GET
    $_SESSION['entry_pda_message'] = $message;
    header("Location: entry_pda.php");
    exit;
}

/* ✅ 2️⃣ Acceptar recanvi del magatzem intermig */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acceptar_intermig') {
    $unitId  = (int)($_POST['unit_id'] ?? 0);
    $scanPos = trim($_POST['scan_sububicacio'] ?? '');

    $message = "";

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

            if ($expected === '') {
                $message = "❌ Aquesta unitat no té sububicació assignada al magatzem.";
            } elseif ($scanPos === '') {
                $message = "❌ Cal escanejar la posició del magatzem.";
            } elseif (strcasecmp($scanPos, $expected) !== 0) {
                $message = "❌ La posició escanejada ($scanPos) no coincideix amb la posició assignada ($expected).";
            } else {
                // Tot OK → Acceptem al magatzem
                $pdo->prepare("
                    UPDATE item_units
                    SET ubicacio = 'magatzem', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$unitId]);

                $pdo->prepare("
                    INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
                    VALUES (?, ?, 'entrada', 1, 'magatzem', 'INTERMIG', NOW())
                ")->execute([
                    $unitId,
                    (int)$unit['item_id']
                ]);

                $message = "✅ Recanvi acceptat al magatzem principal.";
            }
        }
    }

    $_SESSION['entry_pda_message'] = $message;
    header("Location: entry_pda.php");
    exit;
}

/* ❌ 3️⃣ Donar de baixa recanvi del magatzem intermig */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'baixa_intermig') {

    $unitId = (int)($_POST['unit_id'] ?? 0);
    $motiu  = trim($_POST['baixa_motiu'] ?? '');

    $message = "";
    $motiuValid = ['malmesa', 'fi_vida_util', 'altres', 'descatalogat'];

    if ($unitId <= 0) {
        $message = "❌ Falta la unitat per donar de baixa.";
    } elseif ($motiu === '' || !in_array($motiu, $motiuValid, true)) {
        $message = "❌ Cal seleccionar un motiu de baixa.";
    } else {
        // 1️⃣ Obtenir dades actuals
        $stmt = $pdo->prepare("SELECT item_id, ubicacio, maquina_actual FROM item_units WHERE id = ?");
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

            $message = "🗑️ Recanvi donat de baixa correctament.";
        } else {
            $message = "⚠️ Unitat no trobada.";
        }
    }

    $_SESSION['entry_pda_message'] = $message;
    header("Location: entry_pda.php");
    exit;
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

<h2 class="text-2xl font-bold mb-4">Entrades (mode PDA)</h2>

<?php if ($message): ?>
  <div class="mb-4 p-3 rounded text-sm
              <?= str_starts_with($message, '✅') ? 'bg-green-100 border border-green-300 text-green-800'
                                                 : 'bg-red-100 border border-red-300 text-red-800' ?>">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-64">

  <!-- 📦 Magatzem intermig (PDA friendly) -->
  <div class="bg-white p-4 rounded-lg shadow-md">
    <div class="flex justify-between items-center mb-3">
      <h3 class="text-lg font-bold text-gray-700">🏭 Magatzem intermig</h3>
      <span class="text-sm text-gray-500"><?= count($intermigItems) ?> pendents</span>
    </div>

    <?php if (count($intermigItems) === 0): ?>
      <p class="text-gray-500 italic text-sm">No hi ha recanvis pendents al magatzem intermig.</p>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($intermigItems as $item): ?>
          <div class="border rounded-lg p-3 text-sm space-y-2">
            <div>
              <div><span class="font-semibold">SKU:</span> <?= htmlspecialchars($item['sku']) ?></div>
              <div><span class="font-semibold">Serial:</span> <span class="font-mono"><?= htmlspecialchars($item['serial']) ?></span></div>
              <div><span class="font-semibold">Màquina origen:</span> <?= htmlspecialchars($item['maquina'] ?? '—') ?></div>
              <div><span class="font-semibold">Posició assignada:</span>
                <span class="font-mono"><?= htmlspecialchars($item['sububicacio'] ?? '(sense posició)') ?></span>
              </div>
            </div>

            <!-- Acceptar al magatzem -->
            <form method="POST" class="mt-2 flex flex-col gap-2">
              <input type="hidden" name="action" value="acceptar_intermig">
              <input type="hidden" name="unit_id" value="<?= (int)$item['unit_id'] ?>">

              <input type="text"
                     name="scan_sububicacio"
                     class="w-full p-2 border rounded font-mono text-base"
                     placeholder="Escaneja posició magatzem (Ex: 01A01)"
                     autocomplete="off">

              <button type="submit"
                      class="bg-green-600 text-white px-4 py-2 rounded text-base font-semibold">
                ✅ Acceptar al magatzem
              </button>
            </form>

            <!-- Donar de baixa -->
            <form method="POST" class="mt-1 flex flex-col gap-2 border-t pt-2">
              <input type="hidden" name="action" value="baixa_intermig">
              <input type="hidden" name="unit_id" value="<?= (int)$item['unit_id'] ?>">

              <select name="baixa_motiu"
                      class="w-full p-2 border rounded text-sm"
                      required>
                <option value="">Motiu de baixa…</option>
                <option value="malmesa">Camisa malmesa</option>
                <option value="fi_vida_util">Fi de vida útil</option>
                <option value="altres">Altres</option>
                <option value="descatalogat">Descatalogat</option>
              </select>

              <button type="submit"
                      class="bg-red-600 text-white px-4 py-2 rounded text-sm font-semibold"
                      onclick="return confirm('Segur que vols donar de baixa aquest recanvi?');">
                🗑️ Donar de baixa
              </button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<datalist id="llista-sububicacions">
  <?php foreach ($allPositions as $pos): ?>
    <option value="<?= htmlspecialchars($pos) ?>"></option>
  <?php endforeach; ?>
</datalist>

  <!-- 🧾 Entrada manual (PDA friendly) -->
  <div class="bg-white p-4 rounded-lg shadow-md">
    <h3 class="text-lg font-bold mb-3 text-gray-700">📥 Entrada de recanvi nou</h3>
    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="manual">

      <div>
        <label class="block mb-1 text-sm font-medium">Codi camisa (SKU)</label>
        <input type="text" name="sku" required
               class="w-full p-2 border rounded text-lg"
               placeholder="Escaneja o escriu SKU">
      </div>

      <div>
        <label class="block mb-1 text-sm font-medium">Codi sèrie (Serial)</label>
        <input type="text" name="serial" required
               class="w-full p-2 border rounded text-lg"
               placeholder="Escaneja o escriu serial">
      </div>

      <div>
        <label class="block mb-1 text-sm font-medium">Categoria</label>
        <input type="text" name="categoria"
               class="w-full p-2 border rounded text-lg"
               placeholder="Ex: A4 / A5 / A4+">
      </div>

      <div>
        <label class="block mb-1 text-sm font-medium">Vida útil total (hores o cicles)</label>
        <input type="number" name="vida_total" min="1"
               class="w-full p-2 border rounded text-lg"
               placeholder="Ex: 200">
      </div>

      <div>
        <label class="block mb-1 text-sm font-medium">Proveïdor</label>
        <input type="text"
               name="proveidor"
               class="w-full p-2 border rounded text-lg"
               placeholder="Ex: Proveïdor 1 / Empresa X"
               required>
      </div>

      <div>
        <label class="block mb-1 text-sm font-medium">Posició magatzem (opcional)</label>
        <input
          type="text"
          name="sububicacio"
          list="llista-sububicacions"
          class="w-full p-2 border rounded text-lg font-mono"
          placeholder="Ex: 01A01 (o buit per posició neutra)"
        >
        <p class="text-xs text-gray-500 mt-1">
          Tria una posició existent del magatzem. Cada posició només pot tenir una unitat.
        </p>
      </div>

      <button type="submit"
              class="bg-green-600 text-white px-4 py-3 rounded text-lg font-semibold w-full">
        Registrar entrada
      </button>
    </form>
  </div>



<?php
$content = ob_get_clean();

// 👇 Passem l’opció noSidebar => true perquè el layout amagui el menú
renderPage("Entrades PDA", $content, '', ['noSidebar' => true]);
?>
