<?php
require_once("../src/config.php");
require_once("layout.php");

// Obtenim totes les màquines
$maquines = $pdo->query("SELECT * FROM maquines ORDER BY codi ASC")->fetchAll(PDO::FETCH_ASSOC);

// Per cada màquina, obtenim els recanvis ubicats (usant la taula maquina_items)
$maquinaItems = [];
foreach ($maquines as $maq) {
    $stmt = $pdo->prepare("
        SELECT i.sku, i.name, i.life_expectancy
        FROM items i
        JOIN maquina_items mi ON mi.item_id = i.id
        WHERE mi.maquina = ?
    ");
    $stmt->execute([$maq['codi']]);
    $maquinaItems[$maq['codi']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>

<h2 class="text-2xl font-bold mb-6">Màquines i recanvis ubicats</h2>

<div class="space-y-6">
  <?php foreach ($maquines as $maq): ?>
    <div class="bg-white rounded-xl shadow p-4">
      <div class="flex justify-between items-center mb-3">
        <h3 class="text-xl font-semibold text-blue-700"><?= htmlspecialchars($maq['codi']) ?></h3>
        <span class="text-sm text-gray-500">
          <?= count($maquinaItems[$maq['codi']]) ?> recanvi<?= count($maquinaItems[$maq['codi']]) !== 1 ? 's' : '' ?>
        </span>
      </div>

      <?php if (count($maquinaItems[$maq['codi']]) > 0): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm text-left">
            <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
              <tr>
                <th class="px-4 py-2">SKU</th>
                <th class="px-4 py-2">Nom</th>
                <th class="px-4 py-2">Vida útil</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <?php foreach ($maquinaItems[$maq['codi']] as $item): ?>
                <tr>
                  <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($item['sku']) ?></td>
                  <td class="px-4 py-2"><?= htmlspecialchars($item['name']) ?></td>
                  <td class="px-4 py-2">
                    <div class="flex items-center gap-2">
                      <div class="w-32 bg-gray-200 rounded-full h-2">
                        <div class="bg-green-500 h-2 rounded-full" style="width: <?= max(0, min(100, (int)$item['life_expectancy'])) ?>%;"></div>
                      </div>
                      <span class="text-sm"><?= (int)$item['life_expectancy'] ?>%</span>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-gray-500">No hi ha recanvis ubicats en aquesta màquina.</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php
$content = ob_get_clean();
renderPage("Màquines", $content);
