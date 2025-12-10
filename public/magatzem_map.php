<?php
require_once("../src/config.php");
require_once("layout.php");

$message = "";
$messageType = "success";

// Per veure errors durant desenvolupament
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 🔎 Filtre: només posicions ocupades?
$onlyOccupied = isset($_GET['only_occupied']) && $_GET['only_occupied'] === '1';

/* 🧾 1) EXPORTAR UBICACIONS A CSV */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv') {

    // Query: totes les posicions + unitat activa (si n'hi ha)
    $stmt = $pdo->query("
        SELECT 
          mp.codi AS posicio,
          iu.serial,
          i.sku
        FROM magatzem_posicions mp
        LEFT JOIN item_units iu
          ON iu.sububicacio = mp.codi
         AND iu.estat = 'actiu'
        LEFT JOIN items i
          ON i.id = iu.item_id
        ORDER BY mp.codi ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'magatzem_ubicacions_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');

    $out = fopen('php://output', 'w');

    // Capçalera CSV
    fputcsv($out, ['posicio', 'serial', 'sku']);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['posicio'],
            $r['serial'],
            $r['sku'],
        ]);
    }

    fclose($out);
    exit;
}

/* 📥 2) IMPORTAR UBICACIONS DES DE CSV */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "❌ Cal seleccionar un fitxer CSV vàlid.";
        $messageType = "error";
    } else {
        $file = $_FILES['csv_file']['tmp_name'];

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $message = "❌ No s'ha pogut llegir el fitxer CSV.";
            $messageType = "error";
        } else {
            $importats = 0;
            $errors = [];
            $linia = 0;

            // 👉 Llegim la primera línia (capçalera) i detectem separador , o ;
            $headerLine = fgets($handle);
            if ($headerLine === false) {
                $message = "❌ El fitxer CSV és buit.";
                $messageType = "error";
            } else {

                $numComes = substr_count($headerLine, ',');
                $numPuntsComa = substr_count($headerLine, ';');

                $delimiter = ($numPuntsComa > $numComes) ? ';' : ',';

                // Parsejem la capçalera però no la fem servir, només la saltem
                $header = str_getcsv($headerLine, $delimiter);
                $linia = 1;

                // 👉 Llegim la resta de línies
                while (($line = fgets($handle)) !== false) {
                    $linia++;

                    if (trim($line) === '') {
                        continue; // línia buida
                    }

                    $data = str_getcsv($line, $delimiter);

                    // Esperem: posicio, serial, [sku opcional]
                    $posicio = trim($data[0] ?? '');
                    $serial  = trim($data[1] ?? '');

                    // Traiem BOM si hi és (sobretot a la 1a columna)
                    $posicio = preg_replace('/^\xEF\xBB\xBF/', '', $posicio);

                    // Si la línia és totalment buida → la saltem
                    if ($posicio === '' && $serial === '') {
                        continue;
                    }

                    if ($posicio === '' || $serial === '') {
                        $errors[] = "Línia $linia: cal posició i serial.";
                        continue;
                    }

                    try {
                        // 1️⃣ Validar que la posició existeix
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM magatzem_posicions WHERE codi = ?");
                        $stmt->execute([$posicio]);
                        if ($stmt->fetchColumn() == 0) {
                            $errors[] = "Línia $linia: la posició '$posicio' no existeix.";
                            continue;
                        }

                        // 2️⃣ Trobar la unitat pel serial
                        $stmt = $pdo->prepare("SELECT id FROM item_units WHERE serial = ? AND estat = 'actiu'");
                        $stmt->execute([$serial]);
                        $unitId = $stmt->fetchColumn();

                        if (!$unitId) {
                            $errors[] = "Línia $linia: no s'ha trobat cap unitat activa amb serial '$serial'.";
                            continue;
                        }

                        // 3️⃣ Comprovar que la posició no està ocupada per una altra unitat activa
                        $stmt = $pdo->prepare("
                            SELECT id 
                            FROM item_units 
                            WHERE sububicacio = ? 
                              AND estat = 'actiu'
                              AND id <> ?
                        ");
                        $stmt->execute([$posicio, $unitId]);
                        $ocupant = $stmt->fetchColumn();

                        if ($ocupant) {
                            $errors[] = "Línia $linia: la posició '$posicio' ja està ocupada per una altra unitat.";
                            continue;
                        }

                        // 4️⃣ Assignar la posició a aquesta unitat
                        $stmt = $pdo->prepare("
                            UPDATE item_units
                            SET sububicacio = ?, ubicacio = 'magatzem', updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$posicio, $unitId]);

                        $importats++;
                    } catch (Throwable $e) {
                        $errors[] = "Línia $linia: error inesperat ({$e->getMessage()}).";
                    }
                }

                fclose($handle);

                if ($importats > 0) {
                    $message = "✅ Import completat: $importats ubicacions actualitzades.";
                    if ($errors) {
                        $message .= " Algunes línies han donat error.";
                    }
                    $messageType = "success";
                } else {
                    $message = "⚠️ No s'ha pogut importar cap ubicació.";
                    if ($errors) {
                        $message .= " Errors: " . implode(" | ", $errors);
                    }
                    $messageType = "error";
                }
            }
        }
    }
}



/* 🧱 3) LLEGIR VISTA DEL MAGATZEM (graella) */
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
    // Si volem només ocupades, filtrem per unitat activa existent
    $sql .= " WHERE iu.id IS NOT NULL ";
}
$sql .= " ORDER BY mp.codi ASC";

$positions = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Agrupar per "zona/passadís" segons els 2 primers caràcters i les files segons lletra
$zones = [];
foreach ($positions as $p) {
    $codi = $p['posicio'];

    // Prestatgeria = 2 primers caràcters (ex: 01, 02...)
    $prestatgeria = substr($codi, 0, 2);
    if ($prestatgeria === false || $prestatgeria === '') {
        $prestatgeria = 'Altres';
    }

    // Fila = lletra després dels dos dígits (ex: A, B, C, D)
    $lletra = strtoupper(substr($codi, 2, 1));
    if (!in_array($lletra, ['A', 'B', 'C', 'D'], true)) {
        $lletra = 'Altres';
    }

    // Guardem la fila dins del mateix registre per reutilitzar-la després
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
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<!-- 🔁 Export / Import -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

  <!-- Export -->
  <div class="bg-white p-4 rounded-lg shadow">
    <h3 class="text-lg font-semibold mb-2">⬇ Exportar ubicacions</h3>
    <p class="text-sm text-gray-600 mb-3">
      Descarrega un CSV amb totes les posicions, serial i SKU (si hi ha unitat).
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="export_csv">
      <button type="submit"
              class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
        Descarregar CSV
      </button>
    </form>
  </div>

  <!-- Import -->
  <div class="bg-white p-4 rounded-lg shadow">
    <h3 class="text-lg font-semibold mb-2">⬆ Importar ubicacions</h3>
    <p class="text-sm text-gray-600 mb-3">
      Pujar un CSV amb format: <span class="font-mono">posicio, serial, sku(opcional)</span>.<br>
      Només es fan servir <strong>posicio</strong> i <strong>serial</strong>.
    </p>
    <form method="POST" enctype="multipart/form-data" class="space-y-3">
      <input type="hidden" name="action" value="import_csv">
      <input type="file" name="csv_file" accept=".csv"
             class="text-sm border rounded p-1 w-full">
      <button type="submit"
              class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">
        Importar CSV
      </button>
    </form>
  </div>

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

  <?php
  // Funció auxiliar per saber si és la primera prestatgeria (per obrir-la per defecte)
  $primerClau = array_key_first($zones);
  ?>

  <?php foreach ($zones as $clau => $posList): ?>
    <?php
      // Comptem posicions totals i ocupades
      $totalPosicions = count($posList);
      $ocupades = 0;
      foreach ($posList as $p) {
          if (!empty($p['unit_id'])) {
              $ocupades++;
          }
      }

      // Agrupem per fila: A, B, C, D, Altres
      $files = [
        'A' => [],
        'B' => [],
        'C' => [],
        'D' => [],
        'Altres' => [],
      ];

      foreach ($posList as $p) {
          $codi = $p['posicio'];
          $lletra = strtoupper(substr($codi, 2, 1) ?: '');
          if (!isset($files[$lletra])) {
              $lletra = 'Altres';
          }
          $files[$lletra][] = $p;
      }
    ?>

    <details class="mb-6 bg-white rounded-lg shadow" <?= $clau === $primerClau ? 'open' : '' ?>>
      <summary class="cursor-pointer px-4 py-2 flex items-center justify-between">
        <span class="font-semibold text-gray-700">
          Prestatgeria <?= htmlspecialchars($clau) ?>
        </span>
        <span class="text-xs text-gray-500">
          <?= $ocupades ?> / <?= $totalPosicions ?> ocupades
        </span>
      </summary>

      <div class="border-t px-4 py-3 space-y-4">
        <?php foreach (['A','B','C','D','Altres'] as $filaKey): ?>
          <?php if (empty($files[$filaKey])) continue; ?>

          <div>
            <p class="text-xs font-semibold text-gray-500 mb-1">
              <?php if ($filaKey === 'Altres'): ?>
                Altres posicions
              <?php else: ?>
                Fila <?= $filaKey ?>
              <?php endif; ?>
            </p>

            <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-2">
              <?php foreach ($files[$filaKey] as $p): 
                $ocupada = !empty($p['unit_id']);
                $vidaPercent = null;
                if ($ocupada && $p['vida_total'] > 0) {
                    $vidaPercent = max(
                      0,
                      100 - floor(100 * (int)$p['vida_utilitzada'] / (int)$p['vida_total'])
                    );
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
                        <?= htmlspecialchars($p['sku']) ?>
                      </div>
                      <div>
                        <span class="font-semibold">Serial:</span>
                        <span class="font-mono"><?= htmlspecialchars($p['serial']) ?></span>
                      </div>
                      <?php if ($vidaPercent !== null): ?>
                        <div class="mt-1">
                          <span class="font-semibold">Vida:</span>
                          <span class="<?= $vidaPercent < 10 ? 'text-red-600' : 'text-gray-800' ?>">
                            <?= $vidaPercent ?>%
                          </span>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div class="text-gray-400 italic">
                      (buida)
                    </div>
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



<?php
$content = ob_get_clean();
renderPage("Mapa magatzem", $content);
