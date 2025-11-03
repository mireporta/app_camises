document.addEventListener('DOMContentLoaded', () => {

  // 🔹 Crear el modal Tailwind un cop (reutilitzable)
  const modal = document.createElement('div');
  modal.innerHTML = `
    <div id="assignModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 transition-opacity">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 transform scale-95 transition-transform">
        <h2 class="text-xl font-semibold mb-4 text-gray-800">Seleccionar unitat disponible</h2>
        <p class="text-sm text-gray-600 mb-3">
          Recanvi <span id="assignSku" class="font-mono font-semibold text-blue-700"></span>
        </p>
        <select id="assignSelect" class="w-full border border-gray-300 rounded-lg p-2 mb-6 focus:ring-2 focus:ring-blue-500 focus:outline-none">
          <option value="">Carregant unitats...</option>
        </select>
        <div class="flex justify-end gap-3">
          <button id="assignCancel" class="px-4 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 transition">Cancel·lar</button>
          <button id="assignConfirm" class="px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition">Confirmar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  const modalEl = document.getElementById('assignModal');
  const assignSku = document.getElementById('assignSku');
  const assignSelect = document.getElementById('assignSelect');
  const assignCancel = document.getElementById('assignCancel');
  const assignConfirm = document.getElementById('assignConfirm');

  let currentBtn = null;
  let currentId = null;

  // 🔸 Obrir el modal amb les opcions carregades
  async function openModal(button, sku, id) {
    try {
      const res = await fetch(`unitats_disponibles.php?sku=${encodeURIComponent(sku)}`);
      const data = await res.json();

      if (!data.success || !data.unitats?.length) {
        alert(`❌ No hi ha unitats disponibles per ${sku}`);
        return;
      }

      assignSku.textContent = sku;
      assignSelect.innerHTML = '';

      data.unitats.forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = `${u.serial} — ${u.ubicacio}`;
        assignSelect.appendChild(opt);
      });

      currentBtn = button;
      currentId = id;

      modalEl.classList.remove('hidden');
      modalEl.classList.add('flex');
      setTimeout(() => {
        modalEl.querySelector('div').classList.remove('scale-95');
        modalEl.querySelector('div').classList.add('scale-100');
        assignSelect.focus();
      }, 10);

    } catch (err) {
      console.error(err);
      alert('❌ Error carregant unitats disponibles');
    }
  }

  // 🔸 Tancar el modal
  function closeModal() {
    const inner = modalEl.querySelector('div');
    inner.classList.add('scale-95');
    inner.classList.remove('scale-100');
    setTimeout(() => {
      modalEl.classList.add('hidden');
      modalEl.classList.remove('flex');
      currentBtn = null;
      currentId = null;
    }, 150);
  }

  assignCancel.addEventListener('click', closeModal);
  modalEl.addEventListener('click', e => { if (e.target === modalEl) closeModal(); });

  // 🔸 Confirmar assignació
  assignConfirm.addEventListener('click', async () => {
    const unitId = assignSelect.value;
    if (!unitId) {
      alert('Selecciona una unitat!');
      return;
    }

    try {
      const formData = new URLSearchParams({
        id: currentId,
        action: 'serveix',
        unit_id: unitId
      });

      const res = await fetch('peticions_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
      });

      const json = await res.json();

      if (json.success) {
        const row = currentBtn.closest('tr');
        row.classList.add('opacity-50', 'transition');
        setTimeout(() => row.remove(), 300);
        closeModal();
      } else {
        alert(`❌ Error: ${json.error || 'no s\'ha pogut actualitzar la petició'}`);
      }
    } catch (err) {
      console.error(err);
      alert('❌ Error de connexió amb el servidor');
    }
  });

  // 🔸 Delegació d'esdeveniments per a botons dinàmics
  document.body.addEventListener('click', e => {
    const serveix = e.target.closest('.serveix-btn');
    const anula = e.target.closest('.anula-btn');

    if (serveix) {
      const id = serveix.dataset.id;
      const sku = serveix.closest('tr').querySelector('td:nth-child(2)').textContent.trim();
      openModal(serveix, sku, id);
    }

    if (anula) {
      const id = anula.dataset.id;
      if (!confirm('Vols anul·lar aquesta petició?')) return;

      const formData = new URLSearchParams({ id, action: 'anula' });
      fetch('peticions_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
      })
        .then(res => res.json())
        .then(json => {
          if (json.success) {
            anula.closest('tr').remove();
          } else {
            alert(`❌ Error: ${json.error || 'no s\'ha pogut anul·lar la petició'}`);
          }
        })
        .catch(err => {
          console.error(err);
          alert('❌ Error de connexió amb el servidor');
        });
    }
  });
});
