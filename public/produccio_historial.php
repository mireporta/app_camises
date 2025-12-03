<?php
require_once("../src/config.php");
require_once("layout.php");

// Mode: volem errors clars durant proves
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$message = "";
$messageType = "success";

/* 🟢 Missatges de feedback (després del redirect) */
if (isset($_GET['msg'])) {
    $map = [
        'edit_ok'    => "✅ Producció actualitzada correctament.",
        'edit_error' => "❌ Error en actualitzar la producció."
    ];

    if (isset($map[$_GET['msg']])) {
        $message = $map[$_GET['msg']];
        $messageType = ($_GET['msg'] === 'edit_ok') ? 'success' : 'error';
    }
}

/* 🧮 1) Actualitzar un esdeveniment de producció (responsables) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_event') {
    $eventId  = (int)($_POST['event_id'] ?? 0);
    $nouValor = max(0, (int)($_POST['unitats_correctes'] ?? 0));

    if ($eventId <= 0) {
        header("Location: produccio_historial.php?msg=edit_error");
        exit;
    }

    try {
        // 1️⃣ Llegim l'event
        $stmt = $pdo->prepare("
            SELECT 
              id,
              maquina,
              unitats_originals,
              unitats_correctes,
              created_at
            FROM produccio_events
            WHERE id = ?
        ");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            header("Location: produccio_historial.php?msg=edit_error");
            exit;
        }

        // Valor actualment aplicat a la vida útil
        $aplicat = $event['unitats_correctes'] !== null
          ? (int)$event['unitats_correctes']
          : (int)$event['unitats_originals'];

        // Si no canvia res, no cal tocar vida
        if ($nouValor === $aplicat) {
            header("Location: produccio_historial.php?msg=edit_ok");
            exit;
        }

        $delta = $nouValor - $aplicat;   // pot ser positiu o negatiu

        $pdo->beginTransaction();

        // 2️⃣ Agafem les unitats que van participar en aquest esdeveniment
        $stmtUnits = $pdo->prepare("
            SELECT item_unit_id
            FROM produccio_events_units
            WHERE event_id = ?
        ");
        $stmtUnits->execute([$eventId]);
        $units = $stmtUnits->fetchAll(PDO::FETCH_COLUMN);

        if (!$units) {
            $pdo->rollBack();
            header("Location: produccio_historial.php?msg=edit_error");
            exit;
        }

        // 3️⃣ Actualitzem la vida utilitzada d'aquestes unitats
        $stmtUpd = $pdo->prepare("
            UPDATE item_units
            SET vida_utilitzada = GREATEST(0, vida_utilitzada + ?),
                updated_at      = NOW()
            WHERE id = ?
        ");

        foreach ($units as $unitId) {
            $stmtUpd->execute([$delta, $unitId]);
        }

        // 4️⃣ Guardem el nou valor com a unitats_correctes
        $stmtUpdateEvent = $pdo->prepare("
            UPDATE produccio_events
            SET unitats_correctes = ?
            WHERE id = ?
        ");
        $stmtUpdateEvent->execute([$nouValor, $eventId]);

        $pdo->commit();
        header("Location: produccio_historial.php?msg=edit_ok");
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error update_event historial: " . $e->getMessage());
        header("Location: produccio_historial.php?msg=edit_error");
        exit;
    }
}


/* 🔍 2) Filtres del llistat */
$filtreMaquina = trim($_GET['maquina'] ?? '');
$dataInici     = trim($_GET['inici'] ?? '');
$dataFi        = trim($_GET['fi'] ?? '');

$where = [];
$params = [];

if ($filtreMaquina !== '') {
    $where[] = "e.maquina = ?";
    $params[] = $filtreMaquina;
}
if ($dataInici !== '') {
    $where[] = "DATE(e.created_at) >= ?";
    $params[] = $dataInici;
}
if ($dataFi !== '') {
    $where[] = "DATE(e.created_at) <= ?";
    $params[] = $dataFi;
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

/* 📋 3) Llegir esdeveniments (últims 500) */
$stmt = $pdo->prepare("
    SELECT 
      e.id,
      e.maquina,
      e.unitats_originals,
      e.unitats_correctes,
      e.created_at
    FROM produccio_events e
    $whereSql
    ORDER BY e.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Llista de màquines per al filtre
$maquines = $pdo->query("SELECT codi FROM maquines WHERE activa = 1 ORDER BY codi")
                ->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>

<h1 class="text-3xl font-bold mb-2">Històric de producció</h1>
<p class="text-gray-500 mb-6">Consulta i ajust de produccions per màquina.</p>

<?php if ($message): ?>
  <div class="mb-4 p-3 rounded text-sm
              <?= $messageType === 'success'
                    ? 'bg-green-100 border border-green-300 text-green-800'
                    : 'bg-red-100 border border-red-300 text-red-800' ?>">
    <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>

<!-- 🔎 Filtres -->
<form method="GET" class="mb-6 bg-white p-4 rounded-lg shadow flex flex-wrap gap-4 items-end">
  <div>
    <label class="block text-sm font-medium text-gray-600 mb-1">Màquina</label>
    <select name="maquina" class="border rounded p-2 text-sm min-w-[120px]">
      <option value="">Totes</option>
      <?php foreach ($maquines as $m): ?>
        <option value="<?= htmlspecialchars($m) ?>" <?= $filtreMaquina === $m ? 'selected' : '' ?>>
          <?= htmlspecialchars($m) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label class="block text-sm font-medium text-gray-600 mb-1">Des de</label>
    <input type="date" name="inici" value="<?= htmlspecialchars($dataInici) ?>" class="border rounded p-2 text-sm">
  </div>

  <div>
    <label class="block text-sm font-medium text-gray-600 mb-1">Fins a</label>
    <input type="date" name="fi" value="<?= htmlspecialchars($dataFi) ?>" class="border rounded p-2 text-sm">
  </div>

  <div class="flex items-center gap-3">
    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
      Filtrar
    </button>
    <a href="produccio_historial.php" class="text-sm text-gray-600 hover:underline">Netejar</a>
  </div>
</form>

<!-- 📋 Taula d'esdeveniments -->
<div class="bg-white rounded-xl shadow overflow-x-auto">
  <table class="min-w-full text-sm text-left border-collapse">
    <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
      <tr>
        <th class="px-4 py-2">Data / Hora</th>
        <th class="px-4 py-2">Màquina</th>
        <th class="px-4 py-2 text-right">Unitats declarades</th>
        <th class="px-4 py-2 text-right">Unitats actuals</th>
        <th class="px-4 py-2 text-right">ID</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (empty($events)): ?>
        <tr>
          <td colspan="5" class="px-4 py-4 text-center text-gray-500 italic">
            No s’han trobat esdeveniments amb aquests filtres.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($events as $e): ?>
          <?php
            // Valor que realment està aplicat a la vida útil
            $aplicat = $e['unitats_correctes'] !== null
              ? (int)$e['unitats_correctes']
              : (int)$e['unitats_originals'];
          ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-2 text-gray-500">
              <?= date('d/m/Y H:i', strtotime($e['created_at'])) ?>
            </td>
            <td class="px-4 py-2 font-semibold">
              <?= htmlspecialchars($e['maquina']) ?>
            </td>
            <td class="px-4 py-2 text-right">
              <?= (int)$e['unitats_originals'] ?>
            </td>
            <td class="px-4 py-2 text-right">
              <form method="POST" class="flex items-center justify-end gap-2">
                <input type="hidden" name="action" value="update_event">
                <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                <input type="number"
                       name="unitats_correctes"
                       value="<?= $aplicat ?>"
                       min="0"
                       class="w-24 border rounded px-2 py-1 text-right text-sm">
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs px-3 py-1 rounded">
                  Guardar
                </button>
              </form>
            </td>
            <td class="px-4 py-2 text-right text-xs text-gray-500">
              #<?= (int)$e['id'] ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
$content = ob_get_clean();
renderPage("Històric producció", $content);
