(() => {
  const state = window.APP || {};
  const scheduler = document.getElementById('scheduler');
  const DAY_WIDTH = 56;

  const apartments = Array.isArray(state.apartments) ? state.apartments : [];
  const apartmentMap = new Map(apartments.map((a) => [a.id, `${a.building_name} / ${a.name}`]));

  const manual = Array.isArray(state.manualReservations) ? state.manualReservations : [];
  const ical = Array.isArray(state.icalReservations) ? state.icalReservations : [];
  const allReservations = [...manual, ...ical];

  const monthStart = new Date(`${state.monthStart}T00:00:00Z`);
  const monthDays = Number(state.monthDays || 30);

  const statusColors = state.statusColors || {
    booked: '#2dc26b',
    reserved: '#3498ff',
    checked_out: '#ef4444',
    checkout_tomorrow: '#f59e0b',
    maintenance: '#8b5cf6',
    cancelled: '#6b7280',
    service: '#ff7f50',
  };

  const modal = document.getElementById('reservation-modal');
  const modalClose = document.getElementById('modal-close');
  const modalTitle = document.getElementById('modal-title');
  const modalStatus = document.getElementById('modal-status');
  const modalStart = document.getElementById('modal-start');
  const modalEnd = document.getElementById('modal-end');
  const modalApartment = document.getElementById('modal-apartment');
  const modalSource = document.getElementById('modal-source');
  const modalSave = document.getElementById('modal-save');
  const modalDelete = document.getElementById('modal-delete');
  const modalCancelStatus = document.getElementById('modal-cancel-status');
  const modalNote = document.getElementById('modal-note');

  let selectedReservation = null;

  function toDate(str) {
    return new Date(`${str}T00:00:00Z`);
  }

  function fmt(d) {
    return d.toISOString().slice(0, 10);
  }

  function diffDays(a, b) {
    return Math.round((a.getTime() - b.getTime()) / 86400000);
  }

  function buildBoard() {
    if (!scheduler) return;

    const grouped = new Map();
    apartments.forEach((a) => {
      const key = `${a.building_id}|${a.building_name}`;
      if (!grouped.has(key)) grouped.set(key, []);
      grouped.get(key).push(a);
    });

    const scroller = document.createElement('div');
    scroller.className = 'scroller';
    scroller.style.setProperty('--days', monthDays);

    const header = document.createElement('div');
    header.className = 'grid-header';

    const titleCell = document.createElement('div');
    titleCell.className = 'cell title';
    titleCell.textContent = 'Building / Apartment';
    header.appendChild(titleCell);

    for (let i = 0; i < monthDays; i += 1) {
      const d = new Date(monthStart);
      d.setUTCDate(d.getUTCDate() + i);
      const c = document.createElement('div');
      c.className = 'cell';
      c.textContent = `${d.getUTCDate()}/${d.getUTCMonth() + 1}`;
      header.appendChild(c);
    }

    scroller.appendChild(header);

    grouped.forEach((rows, key) => {
      const [, buildingName] = key.split('|');
      const g = document.createElement('div');
      g.className = 'grid-header group-row';
      const c = document.createElement('div');
      c.className = 'cell title';
      c.textContent = buildingName;
      g.appendChild(c);
      for (let i = 0; i < monthDays; i += 1) {
        const empty = document.createElement('div');
        empty.className = 'cell';
        g.appendChild(empty);
      }
      scroller.appendChild(g);

      rows.forEach((apt) => {
        const row = document.createElement('div');
        row.className = 'apt-row';
        row.dataset.apartmentId = apt.id;

        const label = document.createElement('div');
        label.className = 'apt-label';
        label.textContent = apt.name;
        row.appendChild(label);

        for (let i = 0; i < monthDays; i += 1) {
          const dc = document.createElement('div');
          dc.className = 'day-cell';
          row.appendChild(dc);
        }

        const track = document.createElement('div');
        track.className = 'track';
        row.appendChild(track);

        scroller.appendChild(row);
      });
    });

    scheduler.innerHTML = '';
    scheduler.appendChild(scroller);
  }

  function placeReservations() {
    document.querySelectorAll('.track').forEach((t) => { t.innerHTML = ''; });

    allReservations.forEach((r) => {
      const row = document.querySelector(`.apt-row[data-apartment-id="${CSS.escape(r.apartment_id)}"]`);
      if (!row) return;
      const track = row.querySelector('.track');
      if (!track) return;

      const s = toDate(r.start);
      const e = toDate(r.end);
      const startOffset = Math.max(0, diffDays(s, monthStart));
      const endOffset = Math.min(monthDays, diffDays(e, monthStart));
      const len = Math.max(1, endOffset - startOffset);
      if (endOffset <= 0 || startOffset >= monthDays) return;

      const bar = document.createElement('button');
      bar.type = 'button';
      bar.className = 'bar';
      if (r.readonly) bar.classList.add('readonly');
      bar.dataset.id = r.id;
      bar.style.left = `${startOffset * DAY_WIDTH + 2}px`;
      bar.style.width = `${len * DAY_WIDTH - 4}px`;
      bar.style.background = statusColors[r.status] || '#64748b';
      bar.textContent = `${(r.title || r.status || 'reservation').toUpperCase()} (${r.start} → ${r.end})`;
      bar.addEventListener('click', () => openModal(r));
      track.appendChild(bar);

      if (!r.readonly) enableDrag(bar, r);
    });
  }

  function clampDateToMonth(d) {
    const copy = new Date(d);
    const min = new Date(monthStart);
    const max = new Date(monthStart);
    max.setUTCDate(max.getUTCDate() + monthDays);
    if (copy < min) return min;
    if (copy > max) return max;
    return copy;
  }

  function enableDrag(el, reservation) {
    let startX = 0;
    let baseLeft = 0;
    let active = false;

    el.addEventListener('pointerdown', (ev) => {
      active = true;
      startX = ev.clientX;
      baseLeft = parseFloat(el.style.left || '0');
      el.classList.add('dragging');
      el.setPointerCapture(ev.pointerId);
    });

    el.addEventListener('pointermove', (ev) => {
      if (!active) return;
      const dx = ev.clientX - startX;
      el.style.left = `${Math.max(2, baseLeft + dx)}px`;
    });

    const finish = async (ev) => {
      if (!active) return;
      active = false;
      el.classList.remove('dragging');

      const rowEl = document.elementFromPoint(ev.clientX, ev.clientY)?.closest('.apt-row') || el.closest('.apt-row');
      const targetApartment = rowEl?.dataset.apartmentId || reservation.apartment_id;

      const newStartOffset = Math.round((parseFloat(el.style.left || '0') - 2) / DAY_WIDTH);
      const oldLen = diffDays(toDate(reservation.end), toDate(reservation.start));

      const newStart = new Date(monthStart);
      newStart.setUTCDate(newStart.getUTCDate() + Math.max(0, Math.min(monthDays - 1, newStartOffset)));

      const clampedStart = clampDateToMonth(newStart);
      const newEnd = new Date(clampedStart);
      newEnd.setUTCDate(newEnd.getUTCDate() + oldLen);

      reservation.start = fmt(clampedStart);
      reservation.end = fmt(clampDateToMonth(newEnd));
      reservation.apartment_id = targetApartment;

      await saveManual();
      placeReservations();
    };

    el.addEventListener('pointerup', finish);
    el.addEventListener('pointercancel', finish);
  }

  async function saveManual() {
    const payload = { manual };
    try {
      await fetch('?action=api_save_manual', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
    } catch (_) {
      // ignore network error in shared-hosting-like demo mode
    }
  }

  function setModalEditable(editable) {
    [modalTitle, modalStatus, modalStart, modalEnd, modalApartment].forEach((el) => {
      if (el) el.disabled = !editable;
    });
    if (modalSave) modalSave.disabled = !editable;
    if (modalDelete) modalDelete.disabled = !editable;
    if (modalCancelStatus) modalCancelStatus.disabled = !editable;
  }

  function openModal(reservation) {
    if (!modal) return;
    selectedReservation = reservation;

    modalTitle.value = reservation.title || '';
    modalStatus.value = reservation.status || 'reserved';
    modalStart.value = reservation.start || '';
    modalEnd.value = reservation.end || '';
    modalSource.value = reservation.source || '';

    modalApartment.innerHTML = '';
    apartments.forEach((apt) => {
      const option = document.createElement('option');
      option.value = apt.id;
      option.textContent = `${apt.building_name} / ${apt.name}`;
      if (apt.id === reservation.apartment_id) option.selected = true;
      modalApartment.appendChild(option);
    });

    if (reservation.readonly) {
      setModalEditable(false);
      modalNote.textContent = 'This iCal reservation is read-only and cannot be edited or deleted here.';
    } else {
      setModalEditable(true);
      modalNote.textContent = `Editable manual reservation • ${apartmentMap.get(reservation.apartment_id) || reservation.apartment_id}`;
    }

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    selectedReservation = null;
  }

  async function requestJson(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    return res.json();
  }

  async function saveModalChanges(forceCancelled = false) {
    if (!selectedReservation || selectedReservation.readonly) return;

    selectedReservation.title = modalTitle.value.trim() || 'Manual reservation';
    selectedReservation.status = forceCancelled ? 'cancelled' : modalStatus.value;
    selectedReservation.start = modalStart.value;
    selectedReservation.end = modalEnd.value;
    selectedReservation.apartment_id = modalApartment.value;

    const data = await requestJson('?action=api_update_reservation', { reservation: selectedReservation });
    if (data?.ok) {
      placeReservations();
      closeModal();
    } else {
      modalNote.textContent = 'Update failed. Please try again.';
    }
  }

  async function deleteSelectedReservation() {
    if (!selectedReservation || selectedReservation.readonly) return;
    const data = await requestJson('?action=api_delete_reservation', { id: selectedReservation.id });
    if (data?.ok) {
      const idxManual = manual.findIndex((r) => r.id === selectedReservation.id);
      if (idxManual >= 0) manual.splice(idxManual, 1);
      const idxAll = allReservations.findIndex((r) => r.id === selectedReservation.id);
      if (idxAll >= 0) allReservations.splice(idxAll, 1);
      placeReservations();
      closeModal();
    } else {
      modalNote.textContent = 'Delete failed. Please try again.';
    }
  }

  function wireModalEvents() {
    if (!modal) return;

    modal.addEventListener('click', (ev) => {
      const t = ev.target;
      if (t instanceof HTMLElement && t.dataset.closeModal === '1') {
        closeModal();
      }
    });

    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modalSave) modalSave.addEventListener('click', () => saveModalChanges(false));
    if (modalCancelStatus) modalCancelStatus.addEventListener('click', () => saveModalChanges(true));
    if (modalDelete) modalDelete.addEventListener('click', deleteSelectedReservation);
  }

  buildBoard();
  placeReservations();
  wireModalEvents();
})();
