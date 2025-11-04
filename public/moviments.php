<?php
require_once("../src/config.php");
require_once("layout.php");

// 📅 Filtratge per tipus, dates i cerca
$tipus = $_GET['tipus'] ?? '';
$inici = $_GET['inici'] ?? '';
$fi = $_GET['fi'] ?? '';
$cerca = trim($_GET['cerca'] ?? '');

$where = [];
$params = [];

if ($tipus && $tipus !== 'tots') {
    $where[] = "m.tipus = ?";
    $params[] = $tipus;
}
if ($inici) {
    $where[] = "DATE(m.created_at) >= ?";
    $params[] = $inici;
}
if ($fi) {
    $where[] = "DATE(m.created_at) <= ?";
    $params[] = $fi;
}
if ($cerca !== '') {
    $where[] = "(i.sku LIKE ? OR iu.serial LIKE ?)";
    $params[] = "%$cerca%";
    $params[] = "%$cerca%";
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

// 🔍 Consulta principal amb JOIN
$stmt = $pdo->prepare("
    SELECT 
        m.id,
        m.tipus,
        m.quantitat,
        m.ubicacio AS origen,
        m.maquina AS desti,
        m.created_at,
        i.sku,
        iu.serial
    FROM moviments m
    LEFT JOIN item_units iu ON m.item_unit_id = iu.id
    LEFT JOIN items i ON i.id = iu.item_id
    $whereSql
    ORDER BY m.created_at DESC
    LIMIT 300
");
$stmt->execute($params);
$moviments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 📊 Estadístiques per tipus
$stats = $pdo->query("
    SELECT tipus, COUNT(*) AS total 
    FROM moviments 
    GROUP BY tipus
")->fetchAll(PDO::FETCH_KEY_PAIR);

ob_start();
?>

<h2 class="text-3xl font-bold mb-6">📜 Històric de moviments</h2>

<!-- 🔎 Filtres -->
<form method="GET" class="mb-6 bg-white p-4 rounded-lg shadow flex flex-wrap gap-4 items-end">
  <div>
    <label class="block text-sm font-medium text-gray-600 mb-1">Tipus</label>
    <select name="tipus" class="border rounded p-2 text-sm">
      <option value="tots" <?= $tipus === 'tots' ? 'selected' : '' ?>>Tots</option>
      <option value="entrada" <?= $tipus === 'entrada' ? 'selected' : '' ?>>Entrades</option>
      <option value="sortida" <?= $tipus === 'sortida' ? 'selected' : '' ?>>Sortides</option>
      <option value="retorn" <?= $tipus === 'retorn' ? 'selected' : '' ?>>Retorns</option>
      <option value="baixa" <?= $tipus === 'baixa' ? 'selected' : '' ?>>Baixes</option>
    </select>
  </div>

  <div>
    <label class="block text-sm font-medium text-gray-600 mb-1">Des de</label>
    <input type="date" name="inici" value="<?= htmlspecialchars($inici) ?>" class="border rounded p-2 text-sm">
  </div>

  <div>
    <label class="block text-sm font-medium text-gray-600 mb-1">Fins a</label>
    <input type="date" name="fi" value="<?= htmlspecialchars($fi) ?>" class="border rounded p-2 text-sm">
  </div>

  <div class="flex-1 min-w-[200px]">
    <label class="block text-sm font-medium text-gray-600 mb-1">Cerca (SKU o Serial)</label>
    <input type="text" name="cerca" value="<?= htmlspecialchars($cerca) ?>" placeholder="Ex: ENR001 o SER123"
           class="w-full border rounded p-2 text-sm">
  </div>

  <div class="flex items-center gap-3">
    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Filtrar</button>
    <a href="moviments.php" class="text-sm text-gray-600 hover:underline">Netejar</a>
  </div>

  <a href="../src/export_moviments.php" class="ml-auto bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">
    📤 Exportar Excel
  </a>
</form>

<!-- 📊 Resum -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
  <?php
  $colors = [
    'entrada' => 'bg-green-100 text-green-700',
    'sortida'  => 'bg-blue-100 text-blue-700',
    'retorn'  => 'bg-yellow-100 text-yellow-700',
    'baixa'   => 'bg-red-100 text-red-700'
  ];
  foreach ($stats as $type => $count): ?>
    <div class="rounded-lg p-3 text-center font-semibold <?= $colors[$type] ?? 'bg-gray-100 text-gray-600' ?>">
      <?= ucfirst($type) ?> <br><span class="text-2xl"><?= $count ?></span>
    </div>
  <?php endforeach; ?>
</div>

<!-- 📦 Taula -->
<div class="bg-white shadow-md rounded-lg overflow-hidden">
  <table class="min-w-full text-sm text-left border-collapse">
    <thead class="bg-gray-100 text-gray-700 uppercase text-xs">
      <tr>
        <th class="px-4 py-2">Data / Hora</th>
        <th class="px-4 py-2">Tipus</th>
        <th class="px-4 py-2">SKU</th>
        <th class="px-4 py-2">Serial</th>
        <th class="px-4 py-2">Origen</th>
        <th class="px-4 py-2">Destí / Màquina</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (empty($moviments)): ?>
        <tr><td colspan="6" class="px-4 py-4 text-gray-500 text-center italic">No s’han trobat moviments</td></tr>
      <?php else: ?>
        <?php foreach ($moviments as $m): ?>
          <?php
            $color = match($m['tipus']) {
              'entrada' => 'text-green-600',
              'sortida'  => 'text-blue-600',
              'retorn'  => 'text-yellow-600',
              'baixa'   => 'text-red-600',
              default   => 'text-gray-600'
            };
          ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-2 text-gray-500"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
            <td class="px-4 py-2 font-semibold <?= $color ?>"><?= ucfirst($m['tipus']) ?></td>
            <td class="px-4 py-2"><?= htmlspecialchars($m['sku'] ?? '—') ?></td>
            <td class="px-4 py-2 font-mono"><?= htmlspecialchars($m['serial'] ?? '—') ?></td>
            <td class="px-4 py-2"><?= htmlspecialchars($m['origen'] ?? '—') ?></td>
            <td class="px-4 py-2"><?= htmlspecialchars($m['desti'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
$content = ob_get_clean();
renderPage("Moviments", $content);
