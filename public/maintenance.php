<?php
require_once("../src/config.php");
require_once("layout.php");

/**
 * Manteniment / Compres
 * - Pendents: SKUs sota mínim (informatiu) + registrar "comprat" (historial)
 * - Historial: totes les compres registrades, amb filtres
 */

$tab = $_GET['tab'] ?? 'pendents';
if (!in_array($tab, ['pendents', 'historial'], true)) {
    $tab = 'pendents';
}

$err = $_GET['err'] ?? '';
$ok  = $_GET['ok'] ?? '';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if (!$ts) return '—';
    return date('d/m/Y H:i', $ts);
}

// ------------------------------------------
// 1) Pendents (sota mínim) + última compra
// ------------------------------------------
$pendents = [];
if ($tab === 'pendents') {
    $pendents = $pdo->query("
      SELECT
        i.id,
        i.sku,
        i.category,
        i.min_stock,
        i.active,
        COALESCE(u.total_cnt, 0) AS total_stock,
        GREATEST(i.min_stock - COALESCE(u.total_cnt, 0), 0) AS faltants,

        cr_last.qty AS last_qty,
        cr_last.proveidor AS last_proveidor,
        cr_last.created_at AS last_created_at
      FROM items i
      LEFT JOIN (
        SELECT item_id, COUNT(*) AS total_cnt
        FROM item_units
        WHERE estat='actiu'
        GROUP BY item_id
      ) u ON u.item_id = i.id
      LEFT JOIN compres_recanvis cr_last
        ON cr_last.id = (
            SELECT cr2.id
            FROM compres_recanvis cr2
            WHERE cr2.item_id = i.id
            ORDER BY cr2.created_at DESC, cr2.id DESC
            LIMIT 1
        )
      WHERE COALESCE(u.total_cnt, 0) < i.min_stock
      ORDER BY faltants DESC, i.sku ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------------------------
// 2) Historial (totes les compres) + filtres
// ------------------------------------------
$hist = [];
$filters = [
    'sku' => trim((string)($_GET['sku'] ?? '')),
    'proveidor' => trim((string)($_GET['proveidor'] ?? '')),
    'from' => trim((string)($_GET['from'] ?? '')),
    'to' => trim((string)($_GET['to'] ?? '')),
];

if ($tab === 'historial') {
    $where = [];
    $params = [];

    if ($filters['sku'] !== '') {
        $where[] = "i.sku LIKE ?";
        $params[] = "%{$filters['sku']}%";
    }
    if ($filters['proveidor'] !== '') {
        $where[] = "cr.proveidor LIKE ?";
        $params[] = "%{$filters['proveidor']}%";
    }
    // Dates (format YYYY-MM-DD)
    if ($filters['from'] !== '') {
        $where[] = "cr.created_at >= ?";
        $params[] = $filters['from'] . " 00:00:00";
    }
    if ($filters['to'] !== '') {
        $where[] = "cr.created_at <= ?";
        $params[] = $filters['to'] . " 23:59:59";
    }

    $sql = "
      SELECT
        cr.id,
        cr.created_at,
        cr.qty,
        cr.proveidor,
        cr.notes,
        i.sku,
        i.category
      FROM compres_recanvis cr
      JOIN items i ON i.id = cr.item_id
    ";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY cr.created_at DESC, cr.id DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $hist = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>

<h2 class="text-3xl font-bold mb-2">Manteniment · Compres</h2>
<p class="text-gray-500 mb-6">
  Llista de recanvis sota mínim i historial de compres registrades (manual).
</p>

<?php if ($ok === '1'): ?>
  <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded text-sm">
    ✅ Compra registrada a l’historial.
  </div>
<?php endif; ?>

<?php if ($err !== ''): ?>
  <div class="mb-4 p-3 bg-red-100 border border-red-300 text-red-800 rounded text-sm">
    ❌
    <?php
      echo match ($err) {
          'missing_item' => "Falta l’item.",
          'qty' => "La quantitat ha de ser > 0.",
          'proveidor' => "Cal indicar el proveïdor.",
          'item_not_found' => "SKU no trobada.",
          default => "Error en guardar."
      };
    ?>
  </div>
<?php endif; ?>

<!-- Pestanyes -->
<div class="flex items-center gap-2 mb-4">
  <a href="maintenance.php?tab=pendents"
     class="px-4 py-2 rounded border text-sm <?= $tab==='pendents' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white hover:bg-gray-50' ?>">
    Pendents (sota mínim)
  </a>
  <a href="maintenance.php?tab=historial"
     class="px-4 py-2 rounded border text-sm <?= $tab==='historial' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white hover:bg-gray-50' ?>">
    Historial
  </a>
</div>

<?php if ($tab === 'pendents'): ?>

  <div class="bg-white rounded-xl shadow p-4 overflow-x-auto">
    <?php if (empty($pendents)): ?>
      <p class="text-sm text-gray-500">No hi ha cap SKU sota mínim 👌</p>
    <?php else: ?>
      <table class="min-w-full text-sm text-left border-collapse">
        <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
          <tr>
            <th class="px-3 py-2">SKU</th>
            <th class="px-3 py-2">Categoria</th>
            <th class="px-3 py-2 text-center">Estoc</th>
            <th class="px-3 py-2 text-center">Mínim</th>
            <th class="px-3 py-2 text-center">Falten</th>
            <th class="px-3 py-2">Última compra</th>
            <th class="px-3 py-2">Registrar compra</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-gray-100">
          <?php foreach ($pendents as $r): ?>
            <tr class="hover:bg-gray-50 align-top">
              <td class="px-3 py-2 font-semibold">
                <?= h($r['sku']) ?>
                <?php if ((int)$r['active'] === 0): ?>
                  <span class="ml-2 text-[11px] px-2 py-0.5 rounded border bg-gray-50 text-gray-600">
                    descatalogat
                  </span>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2"><?= h($r['category'] ?? '') ?></td>
              <td class="px-3 py-2 text-center font-mono"><?= (int)$r['total_stock'] ?></td>
              <td class="px-3 py-2 text-center font-mono"><?= (int)$r['min_stock'] ?></td>
              <td class="px-3 py-2 text-center font-mono text-red-600 font-semibold"><?= (int)$r['faltants'] ?></td>

              <td class="px-3 py-2 text-sm">
                <?php if (!empty($r['last_created_at'])): ?>
                  <div class="text-gray-700">
                    <div><span class="text-gray-500">Data:</span> <?= fmtDate($r['last_created_at']) ?></div>
                    <div><span class="text-gray-500">Qty:</span> <span class="font-mono"><?= (int)$r['last_qty'] ?></span></div>
                    <div><span class="text-gray-500">Proveïdor:</span> <?= h($r['last_proveidor']) ?></div>
                  </div>
                <?php else: ?>
                  <span class="text-gray-400 italic">— cap compra registrada —</span>
                <?php endif; ?>
              </td>

              <td class="px-3 py-2">
                <form method="POST" action="../src/maintenance_actions.php" class="flex flex-col gap-2">
                  <input type="hidden" name="action" value="mark_bought">
                  <input type="hidden" name="item_id" value="<?= (int)$r['id'] ?>">

                  <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-600 w-14">Qty</label>
                    <input type="number" name="qty" min="1" required
                           class="w-24 border rounded px-2 py-1 text-sm text-right"
                           placeholder="Ex: 2">
                  </div>

                  <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-600 w-14">Prov.</label>
                    <input type="text" name="proveidor" required
                           class="w-56 border rounded px-2 py-1 text-sm"
                           placeholder="Ex: Proveïdor X">
                  </div>

                  <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-600 w-14">Notes</label>
                    <input type="text" name="notes"
                           class="w-72 border rounded px-2 py-1 text-sm"
                           placeholder="Opcional…">
                  </div>

                  <div class="flex justify-end">
                    <button type="submit"
                            class="bg-blue-600 text-white text-xs px-3 py-1.5 rounded hover:bg-blue-700">
                      Marcar comprat
                    </button>
                  </div>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php else: ?>

  <!-- Filtres historial -->
  <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
    <input type="hidden" name="tab" value="historial">

    <div class="min-w-[220px]">
      <label class="block text-sm font-medium text-gray-600 mb-1">SKU</label>
      <input type="text" name="sku" value="<?= h($filters['sku']) ?>"
             class="p-2 border rounded w-full" placeholder="Ex: ENRE001">
    </div>

    <div class="min-w-[220px]">
      <label class="block text-sm font-medium text-gray-600 mb-1">Proveïdor</label>
      <input type="text" name="proveidor" value="<?= h($filters['proveidor']) ?>"
             class="p-2 border rounded w-full" placeholder="Ex: Proveïdor X">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-600 mb-1">Des de</label>
      <input type="date" name="from" value="<?= h($filters['from']) ?>"
             class="p-2 border rounded">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-600 mb-1">Fins</label>
      <input type="date" name="to" value="<?= h($filters['to']) ?>"
             class="p-2 border rounded">
    </div>

    <div class="flex items-center gap-2">
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
        Filtrar
      </button>
      <a href="maintenance.php?tab=historial" class="px-4 py-2 rounded bg-gray-200 hover:bg-gray-300 text-sm">
        Netejar
      </a>
    </div>
  </form>

  <div class="bg-white rounded-xl shadow p-4 overflow-x-auto">
    <?php if (empty($hist)): ?>
      <p class="text-sm text-gray-500">No hi ha compres registrades amb aquests filtres.</p>
    <?php else: ?>
      <table class="min-w-full text-sm text-left border-collapse">
        <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
          <tr>
            <th class="px-3 py-2">Data</th>
            <th class="px-3 py-2">SKU</th>
            <th class="px-3 py-2">Categoria</th>
            <th class="px-3 py-2 text-center">Qty</th>
            <th class="px-3 py-2">Proveïdor</th>
            <th class="px-3 py-2">Notes</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($hist as $row): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-3 py-2 text-gray-600"><?= fmtDate($row['created_at']) ?></td>
              <td class="px-3 py-2 font-semibold"><?= h($row['sku']) ?></td>
              <td class="px-3 py-2"><?= h($row['category'] ?? '') ?></td>
              <td class="px-3 py-2 text-center font-mono"><?= (int)$row['qty'] ?></td>
              <td class="px-3 py-2"><?= h($row['proveidor']) ?></td>
              <td class="px-3 py-2 text-gray-700"><?= h($row['notes'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="text-xs text-gray-500 mt-3">
        Mostrant fins a 500 registres (els més recents).
      </p>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php
$content = ob_get_clean();
renderPage("Manteniment", $content);
