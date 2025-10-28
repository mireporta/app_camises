<?php
require_once("../src/config.php");
require_once("layout.php");

$message = "";

/* 🧾 1️⃣ Registrar entrada manual (compra o proveïdor) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual') {
    $sku = trim($_POST['sku'] ?? '');
    $serial = trim($_POST['serial'] ?? '');
    $estanteria = trim($_POST['estanteria'] ?? '');
    $ubicacio = 'MAG01' . ($estanteria ? " - " . $estanteria : ''); // 👈 concatena l’estanteria
    $origen = trim($_POST['origen'] ?? 'principal');

    if ($sku && $serial) {
        // Comprova si ja existeix l’ítem
        $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
        $stmt->execute([$sku]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            // Si no existeix, el creem
            $pdo->prepare("
                INSERT INTO items (sku, name, stock, created_at)
                VALUES (?, ?, 0, NOW())
            ")->execute([$sku, $sku]);
            $itemId = (int)$pdo->lastInsertId();
        } else {
            $itemId = (int)$item['id'];
        }

        // Evitar duplicats de serial
        $check = $pdo->prepare("SELECT COUNT(*) FROM item_units WHERE serial = ?");
        $check->execute([$serial]);
        if ($check->fetchColumn() > 0) {
            $message = "⚠️ Ja existeix una unitat amb el serial $serial.";
        } else {
            // Crear nova unitat
            $pdo->prepare("
                INSERT INTO item_units (item_id, serial, ubicacio, estat, created_at, updated_at)
                VALUES (?, ?, ?, 'actiu', NOW(), NOW())
            ")->execute([$itemId, $serial, $ubicacio]);

            // Registrar moviment
            $pdo->prepare("
                INSERT INTO moviments (item_id, item_unit_id, tipus, quantitat, ubicacio, maquina, created_at)
                VALUES (?, (SELECT id FROM item_units WHERE serial = ?), 'entrada', 1, ?, ?, NOW())
            ")->execute([$itemId, $serial, $ubicacio, $origen]);

            // Actualitzar estoc
            $pdo->prepare("UPDATE items SET stock = stock + 1 WHERE id = ?")->execute([$itemId]);

            $message = "✅ Entrada registrada correctament ($serial a $ubicacio).";
        }
    } else {
        $message = "⚠️ Cal omplir SKU i Serial.";
    }
}


/* ✅ 2️⃣ Acceptar recanvi del magatzem intermig */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acceptar_intermig') {
    $unitId = (int)($_POST['unit_id'] ?? 0); // ara el nom coincideix amb el formulari

    if ($unitId > 0) {
        // mou a magatzem
        $pdo->prepare("
            UPDATE item_units
            SET ubicacio = 'magatzem', updated_at = NOW()
            WHERE id = ?
        ")->execute([$unitId]);

        // registre moviment
        $pdo->prepare("
            INSERT INTO moviments (item_unit_id, item_id, tipus, quantitat, ubicacio, maquina, created_at)
            SELECT iu.id, iu.item_id, 'entrada', 1, 'magatzem', 'INTERMIG', NOW()
            FROM item_units iu WHERE iu.id = ?
        ")->execute([$unitId]);

        $message = "✅ Recanvi acceptat al magatzem principal.";
    }
}


/* 📦 3️⃣ Obtenir recanvis del magatzem intermig */
$intermigItems = $pdo->query("
    SELECT iu.id AS unit_id, i.id AS item_id, i.sku, i.name,
           iu.maquina_actual AS maquina, iu.updated_at
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
        <label class="block mb-1 font-medium">Estanteria (opcional)</label>
        <input type="text" name="estanteria" class="w-full p-2 border rounded focus:ring focus:ring-blue-200" placeholder="Ex: E2 o Caixa5">
        <p class="text-xs text-gray-400 mt-1">S'afegirà automàticament a la ubicació MAG01.</p>
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
            <th class="px-4 py-2">Nom</th>
            <th class="px-4 py-2">Màquina origen</th>
            <th class="px-4 py-2 text-center">Acció</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($intermigItems as $item): ?>
            <tr class="hover:bg-gray-50 transition">
              <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($item['sku']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($item['name']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($item['maquina']) ?></td>
              <td class="px-4 py-2 text-center">
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="action" value="acceptar_intermig">
                  <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                  <button title="Entrar al magatzem" 
                          class="inline-flex items-center justify-center bg-green-500 hover:bg-green-600 
                                 text-white rounded-full w-8 h-8 shadow transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" 
                         stroke-width="3" stroke="white" class="w-5 h-5">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
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
// Comptar recanvis pendents al magatzem intermig
$pendingIntermig = (int)$pdo->query("
    SELECT COUNT(*) FROM item_units
    WHERE estat = 'actiu' AND ubicacio = 'intermig'
")->fetchColumn();

$content = ob_get_clean();
renderPage("Entrades", $content);


