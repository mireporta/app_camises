<?php
require_once("../src/config.php");
require_once("layout.php");

// Sessió per missatges (coherent amb inventory.php)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = $_SESSION['map_message'] ?? "";
$messageType = $_SESSION['map_message_type'] ?? "success";
unset($_SESSION['map_message'], $_SESSION['map_message_type']);

// Per veure errors durant desenvolupament
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 🔎 Filtre: només posicions ocupades?
$onlyOccupied = isset($_GET['only_occupied']) && $_GET['only_occupied'] === '1';

/* 🧱 LLEGIR VISTA DEL MAGATZEM (graella) */
$sql = "
    SELECT 
      mp.codi AS posicio,
      iu.id    AS unit_id,
      i.sku,
      iu.serial,
      iu.vida_utilitzada,
      iu.vida_total
    FROM magatzem_posicions mp
    LEFT JOIN item_units iu
      ON iu.sububicacio = mp.codi
     AND iu.estat = 'actiu'
    LEFT JOIN items i
      ON i.id = iu.item_id
";
if ($onlyOccupied) {
    $sql .= " WHERE iu.id IS NOT NULL ";
}
$sql .= " ORDER BY mp.codi ASC";

$positions = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Agrupar per "zona/passadís" segons els 2 primers caràcters i les files segons lletra
$zones = [];
foreach ($positions as $p) {
    $codi = (string)$p['posicio'];

    // Prestatgeria = 2 primers caràcters (ex: 01, 02...)
    $prestatgeria = substr($codi, 0, 2);
    if (!$prestatgeria) {
        $prestatgeria = 'Altres';
    }

    // Fila = lletra després dels dos dígits (ex: A, B, C, D)
    $lletra = strtoupper(substr($codi, 2, 1));
    if (!in_array($lletra, ['A', 'B', 'C', 'D'], true)) {
        $lletra = 'Altres';
    }

    $p['fila'] = $lletra;
    $zones[$prestatgeria][] = $p;
}

ob_start();
?>

<h1 class="text-3xl font-bold mb-2">Mapa de magatzem</h1>
<p class="text-gray-500 mb-4">
  Vista gràfica de les posicions i eines per exportar / importar ubicacions per serial.
</p>

<?php if ($message): ?>
  <div class="mb-4 p-3 rounded text-sm
              <?= $messageType === 'success'
                    ? 'bg-green-100 border border-green-300 text-green-800'
                    : 'bg-red-100 border border-red-300 text-red-800' ?>">
    <?= nl2br(htmlspecialchars($message)) ?>
  </div>
<?php endif; ?>

<!-- 🔁 Export / Import -->
<div class="flex items-center justify-end gap-3 mb-6">

  <!-- Exportar -->
  <form method="GET" action="../src/export_magatzem_map.php">
    <button type="submit"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded flex items-center gap-2">
      📤 <span>Exportar Excel</span>
    </button>
  </form>

  <!-- Importar -->
  <form method="POST"
      action="../src/import_magatzem_map.php"
      enctype="multipart/form-data"
      id="import-map-form">
  <input type="hidden" name="import_password" id="import-map-password">

  <input type="file" name="xlsx_file" id="import-map-file" accept=".xlsx" class="hidden" required>

  <button type="button"
          onclick="document.getElementById('import-map-file').click()"
          class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded flex items-center gap-2">
    📥 <span>Importar Excel</span>
  </button>
</form>

</div>


<!-- 🔎 Filtre de vista: només ocupades -->
<form method="GET" class="mb-4 flex items-center gap-3 text-sm">
  <label class="inline-flex items-center gap-2">
    <input
      type="checkbox"
      name="only_occupied"
      value="1"
      <?= $onlyOccupied ? 'checked' : '' ?>
      onchange="this.form.submit()"
    >
    <span>Mostrar només posicions ocupades</span>
  </label>

  <?php if ($onlyOccupied): ?>
    <a href="magatzem_map.php" class="text-xs text-blue-600 hover:underline">
      Treure filtre
    </a>
  <?php endif; ?>
</form>

<!-- 🧱 Vista gràfica del magatzem -->
<?php if (empty($positions)): ?>
  <p class="text-gray-500 italic">Encara no hi ha posicions definides al magatzem.</p>
<?php else: ?>

  <?php $primerClau = array_key_first($zones); ?>

  <?php foreach ($zones as $clau => $posList): ?>
    <?php
      $totalPosicions = count($posList);
      $ocupades = 0;
      foreach ($posList as $p) {
          if (!empty($p['unit_id'])) $ocupades++;
      }

      // Agrupem per fila
      $files = [
        'A' => [],
        'B' => [],
        'C' => [],
        'D' => [],
        'Altres' => [],
      ];
      foreach ($posList as $p) {
          $fila = $p['fila'] ?? 'Altres';
          if (!isset($files[$fila])) $fila = 'Altres';
          $files[$fila][] = $p;
      }
    ?>

    <details class="mb-6 bg-white rounded-lg shadow" <?= $clau === $primerClau ? 'closed' : '' ?>>
      <summary class="cursor-pointer px-4 py-2 flex items-center justify-between">
        <span class="font-semibold text-gray-700">
          Prestatgeria <?= htmlspecialchars($clau) ?>
        </span>
        <span class="text-xs text-gray-500">
          <?= (int)$ocupades ?> / <?= (int)$totalPosicions ?> ocupades
        </span>
      </summary>

      <div class="border-t px-4 py-3 space-y-4">
        <?php foreach (['A','B','C','D','Altres'] as $filaKey): ?>
          <?php if (empty($files[$filaKey])) continue; ?>

          <div>
            <p class="text-xs font-semibold text-gray-500 mb-1">
              <?= $filaKey === 'Altres' ? 'Altres posicions' : ('Fila ' . $filaKey) ?>
            </p>

            <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 lg:[grid-template-columns:repeat(13,minmax(0,1fr))] gap-2">
              <?php foreach ($files[$filaKey] as $p):
                $ocupada = !empty($p['unit_id']);
                $vidaPercent = null;

                $vidaTotal = (int)($p['vida_total'] ?? 0);
                $vidaUsada = (int)($p['vida_utilitzada'] ?? 0);

                if ($ocupada && $vidaTotal > 0) {
                    $vidaPercent = max(0, 100 - (int)floor(100 * $vidaUsada / $vidaTotal));
                }
              ?>
                <div class="border rounded-lg p-2 text-xs
                            <?= $ocupada ? 'bg-green-50 border-green-300' : 'bg-gray-50 border-gray-200' ?>">
                  <div class="font-mono font-semibold text-gray-800 mb-1">
                    <?= htmlspecialchars($p['posicio']) ?>
                  </div>

                  <?php if ($ocupada): ?>
                    <div class="text-gray-700 space-y-0.5">
                      <div>
                        <span class="font-semibold">SKU:</span>
                        <?= htmlspecialchars($p['sku'] ?? '—') ?>
                      </div>
                      <div>
                        <span class="font-semibold">Serial:</span>
                        <span class="font-mono"><?= htmlspecialchars($p['serial'] ?? '—') ?></span>
                      </div>

                      <?php if ($vidaPercent !== null): ?>
                        <div class="mt-1">
                          <span class="font-semibold">Vida:</span>
                          <span class="<?= $vidaPercent < 10 ? 'text-red-600' : 'text-gray-800' ?>">
                            <?= (int)$vidaPercent ?>%
                          </span>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div class="text-gray-400 italic">(buida)</div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </details>

  <?php endforeach; ?>
<?php endif; ?>

<script>
const f = document.getElementById('import-map-file');
const form = document.getElementById('import-map-form');
const pwdInput = document.getElementById('import-map-password');

f?.addEventListener('change', function () {
  if (!this.files || this.files.length === 0) return;

  const pwd = prompt("Introdueix la contrasenya d'importació:");
  if (!pwd) {
    this.value = '';
    if (pwdInput) pwdInput.value = '';
    return;
  }

  if (pwdInput) pwdInput.value = pwd;
  form.submit();
});
</script>


<?php
$content = ob_get_clean();
renderPage("Mapa magatzem", $content);
