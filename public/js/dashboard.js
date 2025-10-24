document.addEventListener('DOMContentLoaded', () => {
  const serveixBtns = document.querySelectorAll('.serveix-btn');
  const anulaBtns = document.querySelectorAll('.anula-btn');

  async function handleAction(button, action) {
    const id = button.dataset.id;

    // Si és servir, demana la unitat
    let unitId = null;
    if (action === 'serveix') {
      const sku = button.closest('tr').querySelector('td:nth-child(2)').textContent.trim();

      const res = await fetch(`unitats_disponibles.php?sku=${encodeURIComponent(sku)}`);
      const data = await res.json();
      if (!data.success || !data.unitats?.length) {
        alert(`❌ No hi ha unitats disponibles de ${sku}`);
        return;
      }

      const options = data.unitats.map(u => `${u.id} → ${u.serial} (${u.ubicacio})`).join('\n');
      const eleccio = prompt(`Selecciona ID de la unitat per ${sku}:\n${options}`);
      unitId = parseInt(eleccio);
      if (!unitId || !data.unitats.find(u => u.id === unitId)) return;
    }

    const formData = new URLSearchParams({ id, action });
    if (unitId) formData.append('unit_id', unitId);

    const res = await fetch('peticions_actions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: formData.toString()
    });

    const json = await res.json();
    if (json.success) {
      button.closest('tr').remove();
    } else {
      alert(`❌ Error: ${json.error || 'en actualitzar la petició'}`);
    }
  }

  serveixBtns.forEach(btn => btn.addEventListener('click', () => handleAction(btn, 'serveix')));
  anulaBtns.forEach(btn => btn.addEventListener('click', () => handleAction(btn, 'anula')));
});
