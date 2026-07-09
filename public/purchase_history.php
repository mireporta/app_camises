<?php
require_once("../src/config.php");
require_once("layout.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'eliminar_compra') {

    $compraId = (int)($_POST['compra_id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM compres_recanvis_units
        WHERE compra_id = ?
    ");

    $stmt->execute([$compraId]);

    if ((int)$stmt->fetchColumn() === 0) {

        $pdo->prepare("
            DELETE FROM compres_recanvis
            WHERE id = ?
        ")->execute([$compraId]);

    }

    header("Location: purchase_history.php");
    exit;
}

$historial = $pdo->query("
    SELECT 
        c.id,
        c.created_at,
        c.numero_comanda,
        c.qty,
        c.qty_entrada,
        c.proveidor,
        c.estat,
        c.source,
        c.notes,
        i.sku,
        i.category,
        (SELECT COUNT(*)
            FROM compres_recanvis_units cu
            WHERE cu.compra_id = c.id) AS unitats_rebudes
    FROM compres_recanvis c
    JOIN items i ON i.id = c.item_id
    ORDER BY c.created_at DESC, c.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-3xl font-bold">Històric de compres</h2>
        <p class="text-gray-500 text-sm mt-1">
            Registre de compres manuals i automàtiques.
        </p>
    </div>

    <a href="maintenance.php"
       class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded text-sm">
        Tornar a manteniment
    </a>
</div>

<div class="bg-white rounded-lg shadow-md p-6">

    <div class="mb-4">
        <input
            type="text"
            id="buscar-historic"
            class="w-full p-2 border rounded focus:ring focus:ring-blue-200"
            placeholder="Buscar per comanda, proveïdor, SKU, estat o notes...">
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left border">
            <thead class="bg-gray-100 uppercase text-xs text-gray-600">
                <tr>
                    <th class="px-3 py-2">Data</th>
                    <th class="px-3 py-2">Tipus</th>
                    <th class="px-3 py-2">Comanda</th>
                    <th class="px-3 py-2">SKU</th>
                    <th class="px-3 py-2">Qty</th>
                    <th class="px-3 py-2">Entrada</th>
                    <th class="px-3 py-2">Pendent</th>
                    <th class="px-3 py-2">Proveïdor</th>
                    <th class="px-3 py-2">Estat</th>
                    <th class="px-3 py-2">Notes</th>
                    <th class="px-3 py-2 text-center">Accions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
                <?php foreach ($historial as $h): ?>
                    <?php $pendent = (int)$h['qty'] - (int)$h['qty_entrada']; ?>

                    <tr class="hover:bg-gray-50 fila-historic"
                        data-search="<?= htmlspecialchars(strtolower(
                            ($h['numero_comanda'] ?? '') . ' ' .
                            ($h['sku'] ?? '') . ' ' .
                            ($h['proveidor'] ?? '') . ' ' .
                            ($h['estat'] ?? '') . ' ' .
                            ($h['notes'] ?? '')
                        )) ?>">
                        <td class="px-3 py-2">
                            <?= htmlspecialchars(substr($h['created_at'], 0, 16)) ?>
                        </td>

                        <td class="px-3 py-2">
                            <?php if (($h['source'] ?? 'manual') === 'auto'): ?>
                                <span class="text-xs px-2 py-1 rounded bg-orange-100 text-orange-800">AUTO</span>
                            <?php else: ?>
                                <span class="text-xs px-2 py-1 rounded bg-blue-100 text-blue-800">MANUAL</span>
                            <?php endif; ?>
                        </td>

                        <td class="px-3 py-2 font-mono">
                            <?= htmlspecialchars($h['numero_comanda'] ?? '—') ?>
                        </td>

                        <td class="px-3 py-2 font-semibold">
                            <?= htmlspecialchars($h['sku']) ?>
                        </td>

                        <td class="px-3 py-2"><?= (int)$h['qty'] ?></td>

                        <td class="px-3 py-2"><?= (int)$h['qty_entrada'] ?></td>

                        <td class="px-3 py-2 font-semibold <?= $pendent > 0 ? 'text-orange-700' : 'text-green-700' ?>">
                            <?= $pendent ?>
                        </td>

                        <td class="px-3 py-2">
                            <?= htmlspecialchars($h['proveidor'] ?? '') ?>
                        </td>

                        <td class="px-3 py-2">
                            <?php if ($h['estat'] === 'rebuda'): ?>
                                <span class="text-xs px-2 py-1 rounded bg-green-100 text-green-800">
                                    Rebuda
                                </span>
                            <?php elseif ($h['estat'] === 'parcial'): ?>
                                <span class="text-xs px-2 py-1 rounded bg-blue-100 text-blue-800">
                                    Parcial
                                </span>
                            <?php else: ?>
                                <span class="text-xs px-2 py-1 rounded bg-orange-100 text-orange-800">
                                    Demanada
                                </span>
                            <?php endif; ?>
                        </td>

                        <td class="px-3 py-2">
                            <?= htmlspecialchars($h['notes'] ?? '') ?>
                        </td>

                        <td class="px-3 py-2 text-center">

                        <?php if ((int)$h['unitats_rebudes'] === 0): ?>

                        <form method="POST"
                            onsubmit="return confirm('Segur que vols eliminar aquesta compra?');">

                            <input type="hidden" name="action" value="eliminar_compra">
                            <input type="hidden" name="compra_id" value="<?= (int)$h['id'] ?>">

                            <button
                                class="text-red-600 hover:text-red-800 font-semibold"
                                title="Eliminar compra">

                                🗑️

                            </button>

                        </form>

                        <?php else: ?>

                        <span class="text-gray-400">—</span>

                        <?php endif; ?>

                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const buscador = document.getElementById('buscar-historic');

    if (!buscador) return;

    buscador.addEventListener('input', function () {
        const text = this.value.toLowerCase().trim();

        document.querySelectorAll('.fila-historic').forEach(row => {
            const search = row.dataset.search || '';
            row.style.display = search.includes(text) ? '' : 'none';
        });
    });
});
</script>

<?php
$content = ob_get_clean();
renderPage("Històric compres", $content);
?>