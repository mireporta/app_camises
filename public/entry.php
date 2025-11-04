<?php
require_once("../src/config.php");
require_once("layout.php");

$message = "";

/* 🧾 1️⃣ Registrar entrada manual (compra o proveïdor) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual') {
    $sku = trim($_POST['sku'] ?? '');
    $serial = trim($_POST['serial'] ?? '');
    $estanteria = trim($_POST['estanteria'] ?? '');
    $ubicacio = 'magatzem';
    $sububicacio = trim($_POST['estanteria'] ?? '');
    $origen = trim($_POST['origen'] ?? 'principal');
    $categoria = trim($_POST['categoria'] ?? '');
    $vida_total = (int)($_POST['vida_total'] ?? 0);

    if ($sku && $serial) {
        $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
        $stmt->execute([$sku]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            $pdo->prepare("
                INSERT INTO items (sku, name, category, stock, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ")->execute([$sku, $sku, $categoria]);
            $itemId = (int)$pdo->lastInsertId();
        } else {
            $itemId = (int)$item['id'];
            if ($categoria) {
                $pdo->prepare("UPDATE items SET category = ? WHERE id = ?")->execute([$categoria, $itemId]);
            }
        }

        $check = $pdo->prepare("SELECT COUNT(*) FROM item_units WHERE serial = ?");
        $check->execute([$serial]);
        if ($check->fetchColumn() > 0) {
            $message = "⚠️ Ja existeix una unitat amb el serial $serial.";
        } else {
            $pdo->prepare("
                INSERT INTO item_units (item_id, serial, ubicacio, sububicacio, estat, vida_utilitzada, vida_total, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'actiu', 0, ?, NOW(), NOW())
            ")->execute([$itemId, $serial, 'magatzem', $sububicacio, $vida_total]);

            $pdo->prepare("
                INSERT INTO moviments (item_id, item_unit_id, tipus, quantitat, ubicacio, maquina, created_at)
                SELECT ?, id, 'entrada', 1, ?, ?, NOW() FROM item_units WHERE serial = ?
            ")->execute([$itemId, 'magatzem', $origen, $serial]);

            $pdo->prepare("UPDATE items SET stock = stock + 1 WHERE id = ?")->execute([$itemId]);

            $message = "✅ Entrada registrada correctament ($serial a $ubicacio).";
        }
    } else {
        $message = "⚠️ Cal omplir SKU i Serial.";
    }
}

/* ✅ 2️⃣ Acceptar recanvi del magatzem intermig */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acceptar_intermig') {
    $unitId = (int)($_POST['unit_id'] ?? 0);

    if ($unitId > 0) {
        $pdo->prepare("
            UPDATE item_units
            SET ubicacio = 'magatzem', updated_at = NOW()
            WHERE id = ?
        ")->execute([$unitId]);

        $pdo->prepare("
            INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
            SELECT iu.id, iu.item_id, 'entrada', 1, 'magatzem', 'INTERMIG', NOW()
            FROM item_units iu WHERE iu.id = ?
        ")->execute([$unitId]);

        $message = "✅ Recanvi acceptat al magatzem principal.";
    }
}

/* ❌ 3️⃣ Donar de baixa recanvi del magatzem intermig */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'baixa_intermig') {
    $unitId = (int)($_POST['unit_id'] ?? 0);

    if ($unitId > 0) {
        // Actualitza estat a 'baixa' i neteja ubicació i màquina
        $pdo->prepare("
            UPDATE item_units
            SET estat = 'baixa',
                ubicacio = NULL,
                sububicacio = NULL,
                maquina_actual = NULL,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$unitId]);

        // Registra moviment
        $pdo->prepare("
            INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
            SELECT iu.id, iu.item_id, 'baixa', 1, 'intermig', 'DESCARTAT', NOW()
            FROM item_units iu WHERE iu.id = ?
        ")->execute([$unitId]);

        $message = "🗑️ Recanvi donat de baixa correctament.";
    }
}

/* 📦 4️⃣ Obtenir recanvis del magatzem intermig */
$intermigItems = $pdo->query("
    SELECT iu.id AS unit_id, i.id AS item_id, i.sku, i.name,
       iu.serial, iu.sububicacio, iu.maquina_actual AS maquina, iu.updated_at
    FROM item_units iu
    JOIN items i ON i.id = iu.item_id
    WHERE iu.estat = 'actiu' AND iu.ubicacio = 'intermig'
    ORDER BY iu.updated_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<h2 class="text-3xl font-bold mb-6">Entrades d'estoc</h2>

<?php if ($message): ?>
  <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

  <!-- 🧾 Entrada manual -->
  <div class="bg-white p-6 rounded-lg shadow-md">
    <h3 class="text-xl font-bold mb-4 text-gray-700">📥 Entrada de recanvi nou</h3>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="manual">
      <div>
        <label class="block mb-1 font-medium">Codi camisa (SKU)</label>
        <input type="text" name="sku" required class="w-full p-2 border rounded focus:ring focus:ring-blue-200" autofocus>
      </div>
      <div>
        <label class="block mb-1 font-medium">Codi sèrie (Serial)</label>
        <input type="text" name="serial" required class="w-full p-2 border rounded focus:ring focus:ring-blue-200" placeholder="Ex: ENRE001.1">
      </div>
      <div>
        <label class="block mb-1 font-medium">Categoria</label>
        <input type="text" name="categoria" class="w-full p-2 border rounded focus:ring focus:ring-blue-200" placeholder="Ex: ENROSCAT / PREMSA / MOTOR">
      </div>
      <div>
        <label class="block mb-1 font-medium">Vida útil total (hores o cicles)</label>
        <input type="number" name="vida_total" min="1" class="w-full p-2 border rounded focus:ring focus:ring-blue-200" placeholder="Ex: 200">
      </div>
      <div>
        <label class="block mb-1 font-medium">Estanteria (opcional)</label>
        <input type="text" name="estanteria" class="w-full p-2 border rounded focus:ring focus:ring-blue-200" placeholder="Ex: E2 o Caixa5">
        <p class="text-xs text-gray-400 mt-1">Es guardarà com a ubicació fixa del magatzem.</p>
      </div>
      <div>
        <label class="block mb-1 font-medium">Origen</label>
        <select name="origen" required class="w-full p-2 border rounded focus:ring focus:ring-blue-200">
          <option value="principal">Proveïdor 1</option>
          <option value="intermig">Proveïdor 2</option>
        </select>
      </div>
      <button type="submit" class="bg-green-600 text-white px-5 py-2 rounded hover:bg-green-700 transition w-full">
        Registrar entrada
      </button>
    </form>
  </div>

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
                  <!-- Acceptar -->
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="acceptar_intermig">
                    <input type="hidden" name="unit_id" value="<?= $item['unit_id'] ?>">
                    <button title="Entrar al magatzem"
                            class="inline-flex items-center justify-center bg-green-500 hover:bg-green-600 text-white rounded-full w-8 h-8 shadow transition">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                           stroke-width="3" stroke="white" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                      </svg>
                    </button>
                  </form>
                  <!-- Donar de baixa -->
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="baixa_intermig">
                    <input type="hidden" name="unit_id" value="<?= $item['unit_id'] ?>">
                    <button title="Donar de baixa"
                            class="inline-flex items-center justify-center bg-red-500 hover:bg-red-600 text-white rounded-full w-8 h-8 shadow transition">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                           stroke-width="3" stroke="white" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </form>
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

<?php
$content = ob_get_clean();
renderPage("Entrades", $content);
?>
