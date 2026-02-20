(() => {
  const state = window.APP || {};
  const scheduler = document.getElementById('scheduler');
  const DAY_WIDTH = 40;

  const apartments = Array.isArray(state.apartments) ? state.apartments : [];
  const apartmentMap = new Map(apartments.map((a) => [a.id, `${a.building_name} / ${a.name}`]));

  const manual = Array.isArray(state.manualReservations) ? state.manualReservations : [];
  const ical = Array.isArray(state.icalReservations) ? state.icalReservations : [];
  const allReservations = [...manual, ...ical];

  const monthStart = new Date(`${state.monthStart}T00:00:00Z`);
  const monthDays = Number(state.monthDays || 30);
  const openReservationId = String(state.openReservationId || '');
  const collapsedBuildings = new Set((() => {
    try {
      const raw = window.localStorage.getItem('crlxCollapsedBuildings');
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed.map(String) : [];
    } catch (_) {
      return [];
    }
  })());

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
  const modalBuilding = document.getElementById('modal-building');
  const modalApartment = document.getElementById('modal-apartment');
  const modalSource = document.getElementById('modal-source');
  const modalFirstName = document.getElementById('modal-first-name');
  const modalLastName = document.getElementById('modal-last-name');
  const modalEmail = document.getElementById('modal-email');
  const modalPhone = document.getElementById('modal-phone');
  const modalCountry = document.getElementById('modal-country');
  const modalDocument = document.getElementById('modal-document');
  const modalAdults = document.getElementById('modal-adults');
  const modalChildren = document.getElementById('modal-children');
  const modalPriceTotal = document.getElementById('modal-price-total');
  const modalCurrency = document.getElementById('modal-currency');
  const modalTax = document.getElementById('modal-tax');
  const modalCleaning = document.getElementById('modal-cleaning');
  const modalDiscount = document.getElementById('modal-discount');
  const modalPaymentStatus = document.getElementById('modal-payment-status');
  const modalPaymentMethod = document.getElementById('modal-payment-method');
  const modalBookingChannel = document.getElementById('modal-booking-channel');
  const modalNotes = document.getElementById('modal-notes');
  const modalSave = document.getElementById('modal-save');
  const modalDelete = document.getElementById('modal-delete');
  const modalCancelStatus = document.getElementById('modal-cancel-status');
  const modalNote = document.getElementById('modal-note');
  const modalDocUpload = document.getElementById('modal-doc-upload');
  const modalDocList = document.getElementById('modal-doc-list');
  const modalDocDropzone = document.getElementById('modal-doc-dropzone');
  const reservationDocuments = state.reservationDocuments || {};
  const modalDocReplaceInput = document.createElement('input');
  modalDocReplaceInput.type = 'file';
  modalDocReplaceInput.accept = '.png,.jpg,.jpeg,.jpn,.pdf';
  modalDocReplaceInput.style.display = 'none';
  document.body.appendChild(modalDocReplaceInput);
  let pendingReplaceDoc = null;

  const editableFields = [
    modalTitle, modalStatus, modalStart, modalEnd, modalBuilding, modalApartment,
    modalFirstName, modalLastName, modalEmail, modalPhone, modalCountry, modalDocument,
    modalAdults, modalChildren, modalPriceTotal, modalCurrency, modalTax, modalCleaning,
    modalDiscount, modalPaymentStatus, modalPaymentMethod, modalBookingChannel, modalNotes,
  ];

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

  function saveCollapsedBuildings() {
    try {
      window.localStorage.setItem('crlxCollapsedBuildings', JSON.stringify([...collapsedBuildings]));
    } catch (_) {
      // ignore storage errors
    }
  }

  function setBuildingCollapsed(buildingId, collapsed) {
    document.querySelectorAll(`.apt-row[data-building-id="${CSS.escape(buildingId)}"]`).forEach((row) => {
      row.classList.toggle('hidden-row', collapsed);
    });
    const toggle = document.querySelector(`.building-toggle[data-building-id="${CSS.escape(buildingId)}"]`);
    if (toggle) {
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      toggle.textContent = `${collapsed ? '▸' : '▾'} ${toggle.dataset.buildingName || ''}`;
    }
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
      const [buildingId, buildingName] = key.split('|');
      const g = document.createElement('div');
      g.className = 'grid-header group-row';
      g.dataset.buildingId = buildingId;
      const c = document.createElement('div');
      c.className = 'cell title';
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'building-toggle';
      toggle.dataset.buildingId = buildingId;
      toggle.dataset.buildingName = buildingName;
      const collapsed = collapsedBuildings.has(buildingId);
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      toggle.textContent = `${collapsed ? '▸' : '▾'} ${buildingName}`;
      toggle.addEventListener('click', () => {
        const isCollapsed = collapsedBuildings.has(buildingId);
        if (isCollapsed) {
          collapsedBuildings.delete(buildingId);
        } else {
          collapsedBuildings.add(buildingId);
        }
        setBuildingCollapsed(buildingId, !isCollapsed);
        saveCollapsedBuildings();
      });
      c.appendChild(toggle);
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
        row.dataset.buildingId = buildingId;
        if (collapsedBuildings.has(buildingId)) row.classList.add('hidden-row');

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

  function updateReservationInArrays(updated) {
    const merge = (r) => {
      Object.keys(updated).forEach((k) => {
        r[k] = updated[k];
      });
    };
    const m = manual.find((r) => r.id === updated.id);
    if (m) merge(m);
    const a = allReservations.find((r) => r.id === updated.id);
    if (a) merge(a);
  }

  function enableDrag(el, reservation) {
    let startX = 0;
    let baseLeft = 0;
    let active = false;
    let moved = false;
    let original = null;

    el.addEventListener('pointerdown', (ev) => {
      active = true;
      original = {
        apartment_id: reservation.apartment_id,
        start: reservation.start,
        end: reservation.end,
      };
      startX = ev.clientX;
      baseLeft = parseFloat(el.style.left || '0');
      moved = false;
      el.classList.add('dragging');
      el.setPointerCapture(ev.pointerId);
    });

    el.addEventListener('pointermove', (ev) => {
      if (!active) return;
      const dx = ev.clientX - startX;
      if (Math.abs(dx) > 5) moved = true;
      el.style.left = `${Math.max(2, baseLeft + dx)}px`;
    });

    const finish = async (ev) => {
      if (!active) return;
      active = false;
      el.classList.remove('dragging');

      if (!moved) {
        el.style.left = `${baseLeft}px`;
        return;
      }

      el.style.visibility = 'hidden';
      const dropTarget = document.elementFromPoint(ev.clientX, ev.clientY);
      el.style.visibility = '';
      const rowEl = dropTarget?.closest('.apt-row') || el.closest('.apt-row');
      const targetApartment = rowEl?.dataset.apartmentId || reservation.apartment_id;

      const newStartOffset = Math.round((parseFloat(el.style.left || '0') - 2) / DAY_WIDTH);
      const oldLen = diffDays(toDate(reservation.end), toDate(reservation.start));

      const newStart = new Date(monthStart);
      newStart.setUTCDate(newStart.getUTCDate() + Math.max(0, Math.min(monthDays - 1, newStartOffset)));

      const clampedStart = clampDateToMonth(newStart);
      const newEnd = new Date(clampedStart);
      newEnd.setUTCDate(newEnd.getUTCDate() + oldLen);

      const next = {
        ...reservation,
        start: fmt(clampedStart),
        end: fmt(clampDateToMonth(newEnd)),
        apartment_id: targetApartment,
      };

      if (
        next.apartment_id === original.apartment_id
        && next.start === original.start
        && next.end === original.end
      ) {
        updateReservationInArrays({ ...reservation, ...original });
        placeReservations();
        return;
      }

      const targetLabel = apartmentMap.get(targetApartment) || targetApartment;
      if (!window.confirm(`Confirm moving this reservation to ${targetLabel}?`)) {
        updateReservationInArrays({ ...reservation, ...original });
        placeReservations();
        return;
      }

      const response = await requestJson('?action=api_update_reservation', { reservation: next });
      if (response?.ok) {
        updateReservationInArrays(next);
      } else {
        window.alert(response?.message || 'Move rejected. This change would create an overlap/double-booking.');
        updateReservationInArrays({ ...reservation, ...original });
      }
      placeReservations();
    };

    el.addEventListener('pointerup', finish);
    el.addEventListener('pointercancel', finish);
  }

  function setModalEditable(editable) {
    editableFields.forEach((el) => {
      if (el) el.disabled = !editable;
    });
    if (modalSave) modalSave.disabled = !editable;
    if (modalDelete) modalDelete.disabled = !editable;
    if (modalCancelStatus) modalCancelStatus.disabled = !editable;
  }


  function populateBuildingOptions(selectedBuildingId = '') {
    if (!modalBuilding) return;
    const unique = new Map();
    apartments.forEach((apt) => {
      if (!unique.has(apt.building_id)) unique.set(apt.building_id, apt.building_name);
    });
    modalBuilding.innerHTML = '';
    unique.forEach((name, id) => {
      const option = document.createElement('option');
      option.value = id;
      option.textContent = name;
      if (id === selectedBuildingId) option.selected = true;
      modalBuilding.appendChild(option);
    });
  }

  function populateApartmentOptions(buildingId, selectedApartmentId = '') {
    if (!modalApartment) return;
    modalApartment.innerHTML = '';
    apartments
      .filter((apt) => apt.building_id === buildingId)
      .forEach((apt) => {
        const option = document.createElement('option');
        option.value = apt.id;
        option.textContent = `${apt.building_name} / ${apt.name}`;
        if (apt.id === selectedApartmentId) option.selected = true;
        modalApartment.appendChild(option);
      });
  }

  function openModal(reservation) {
    if (!modal) return;
    selectedReservation = reservation;

    modalTitle.value = reservation.title || '';
    modalStatus.value = reservation.status || 'reserved';
    modalStart.value = reservation.start || '';
    modalEnd.value = reservation.end || '';
    modalSource.value = reservation.source || '';

    modalFirstName.value = reservation.customer_first_name || '';
    modalLastName.value = reservation.customer_last_name || '';
    modalEmail.value = reservation.customer_email || '';
    modalPhone.value = reservation.customer_phone || '';
    modalCountry.value = reservation.customer_country || '';
    modalDocument.value = reservation.customer_document || '';
    modalAdults.value = Number(reservation.adults || 1);
    modalChildren.value = Number(reservation.children || 0);
    modalPriceTotal.value = reservation.price_total || '';
    modalCurrency.value = reservation.price_currency || 'EUR';
    modalTax.value = reservation.tax_amount || '';
    modalCleaning.value = reservation.cleaning_fee || '';
    modalDiscount.value = reservation.discount_amount || '';
    modalPaymentStatus.value = reservation.payment_status || '';
    modalPaymentMethod.value = reservation.payment_method || '';
    modalBookingChannel.value = reservation.booking_channel || '';
    modalNotes.value = reservation.notes || '';

    const selectedApartment = apartments.find((apt) => apt.id === reservation.apartment_id);
    const selectedBuildingId = selectedApartment ? selectedApartment.building_id : (apartments[0]?.building_id || '');
    populateBuildingOptions(selectedBuildingId);
    populateApartmentOptions(selectedBuildingId, reservation.apartment_id);

    if (reservation.readonly) {
      setModalEditable(false);
      modalNote.textContent = 'This iCal reservation is read-only and cannot be edited or deleted here.';
    } else {
      setModalEditable(true);
      modalNote.textContent = `Editable manual reservation • ${apartmentMap.get(reservation.apartment_id) || reservation.apartment_id}`;
    }

    renderDocList(reservation.id, !!reservation.readonly);

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    selectedReservation = null;
  }

  function canUploadMoreDocs(reservationId) {
    const docs = Array.isArray(reservationDocuments[reservationId]) ? reservationDocuments[reservationId] : [];
    return docs.length < 5;
  }

  function renderDocList(reservationId, readonly = false) {
    if (!modalDocList) return;
    const docs = Array.isArray(reservationDocuments[reservationId]) ? reservationDocuments[reservationId] : [];
    modalDocList.innerHTML = '';

    if (docs.length === 0) {
      const placeholder = document.createElement('div');
      placeholder.className = 'doc-placeholder';
      placeholder.textContent = 'No documents uploaded. Click above to add up to 5 files.';
      modalDocList.appendChild(placeholder);
    } else {
      docs.forEach((doc) => {
        const card = document.createElement('div');
        card.className = 'doc-card';

        const ext = String(doc.ext || '').toLowerCase();
        if (['png', 'jpg', 'jpeg', 'jpn'].includes(ext)) {
          const a = document.createElement('a');
          a.href = doc.url;
          a.target = '_blank';
          a.rel = 'noopener';
          const img = document.createElement('img');
          img.src = doc.url;
          img.className = 'doc-thumb';
          img.alt = 'document preview';
          a.appendChild(img);
          card.appendChild(a);
        } else {
          const pdf = document.createElement('a');
          pdf.href = doc.url;
          pdf.target = '_blank';
          pdf.rel = 'noopener';
          pdf.className = 'doc-pdf';
          pdf.textContent = 'PDF';
          card.appendChild(pdf);
        }

        const label = document.createElement('a');
        label.href = doc.url;
        label.target = '_blank';
        label.rel = 'noopener';
        label.className = 'tiny';
        label.textContent = doc.name || 'Document';
        card.appendChild(label);

        if (!readonly) {
          const actions = document.createElement('div');
          actions.className = 'doc-card-actions';

          const del = document.createElement('button');
          del.type = 'button';
          del.className = 'btn small doc-action-btn';
          del.textContent = 'Delete';
          del.addEventListener('click', () => deleteDocument(reservationId, doc.name));

          const replace = document.createElement('button');
          replace.type = 'button';
          replace.className = 'btn ghost small doc-action-btn';
          replace.textContent = 'Replace';
          replace.addEventListener('click', () => {
            pendingReplaceDoc = { reservationId, filename: doc.name };
            modalDocReplaceInput.click();
          });

          actions.appendChild(del);
          actions.appendChild(replace);
          card.appendChild(actions);
        }

        modalDocList.appendChild(card);
      });
    }

    if (modalDocDropzone) {
      if (readonly) {
        modalDocDropzone.classList.add('disabled');
        modalDocDropzone.textContent = 'Read-only reservation';
      } else {
        modalDocDropzone.classList.remove('disabled');
        modalDocDropzone.textContent = canUploadMoreDocs(reservationId)
          ? `Click to upload documents (${docs.length}/5 used)`
          : 'Maximum 5 documents reached. Delete one to add more.';
      }
    }

    if (modalDocUpload) {
      modalDocUpload.disabled = readonly || !canUploadMoreDocs(reservationId);
    }
  }

  async function uploadDocumentsForSelected(files) {
    if (!selectedReservation || !files || files.length === 0) return;
    if (!canUploadMoreDocs(selectedReservation.id)) {
      modalNote.textContent = 'Max 5 documents reached. Delete one to upload another.';
      return;
    }

    const form = new FormData();
    form.append('reservation_id', selectedReservation.id);
    Array.from(files).forEach((file) => form.append('documents[]', file));

    try {
      const res = await fetch('?action=api_upload_reservation_docs', {
        method: 'POST',
        body: form,
      });
      const data = await res.json();
      if (data?.ok) {
        reservationDocuments[selectedReservation.id] = Array.isArray(data.documents) ? data.documents : [];
        renderDocList(selectedReservation.id, !!selectedReservation.readonly);
      } else {
        modalNote.textContent = data?.message || 'Upload failed.';
      }
    } catch (_) {
      modalNote.textContent = 'Upload failed due to network error.';
    }
  }

  async function deleteDocument(reservationId, filename) {
    if (!window.confirm('Delete this document?')) return;

    const data = await requestJson('?action=api_delete_reservation_doc', {
      reservation_id: reservationId,
      filename,
    });

    if (data?.ok) {
      reservationDocuments[reservationId] = Array.isArray(data.documents) ? data.documents : [];
      renderDocList(reservationId, false);
    } else {
      modalNote.textContent = data?.message || 'Could not delete document.';
    }
  }

  async function replaceDocument(reservationId, filename, file) {
    const form = new FormData();
    form.append('reservation_id', reservationId);
    form.append('filename', filename);
    form.append('replacement', file);

    try {
      const res = await fetch('?action=api_replace_reservation_doc', {
        method: 'POST',
        body: form,
      });
      const data = await res.json();
      if (data?.ok) {
        reservationDocuments[reservationId] = Array.isArray(data.documents) ? data.documents : [];
        renderDocList(reservationId, false);
      } else {
        modalNote.textContent = data?.message || 'Could not replace document.';
      }
    } catch (_) {
      modalNote.textContent = 'Replace failed due to network error.';
    }
  }

  async function requestJson(url, payload) {
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      return await res.json();
    } catch (_) {
      return { ok: false, message: 'Network error' };
    }
  }

  function collectModalReservation(forceCancelled = false) {
    return {
      ...selectedReservation,
      title: modalTitle.value.trim() || 'Manual reservation',
      status: forceCancelled ? 'cancelled' : modalStatus.value,
      start: modalStart.value,
      end: modalEnd.value,
      apartment_id: modalApartment.value,
      customer_first_name: modalFirstName.value.trim(),
      customer_last_name: modalLastName.value.trim(),
      customer_email: modalEmail.value.trim(),
      customer_phone: modalPhone.value.trim(),
      customer_country: modalCountry.value.trim(),
      customer_document: modalDocument.value.trim(),
      adults: Number(modalAdults.value || 1),
      children: Number(modalChildren.value || 0),
      price_total: modalPriceTotal.value,
      price_currency: modalCurrency.value.trim() || 'EUR',
      tax_amount: modalTax.value,
      cleaning_fee: modalCleaning.value,
      discount_amount: modalDiscount.value,
      payment_status: modalPaymentStatus.value.trim(),
      payment_method: modalPaymentMethod.value.trim(),
      booking_channel: modalBookingChannel.value.trim(),
      notes: modalNotes.value,
    };
  }

  async function saveModalChanges(forceCancelled = false) {
    if (!selectedReservation || selectedReservation.readonly) return;
    const actionText = forceCancelled ? 'cancel this reservation' : 'update this reservation';
    if (!window.confirm(`Confirm ${actionText}?`)) return;

    const updated = collectModalReservation(forceCancelled);
    const data = await requestJson('?action=api_update_reservation', { reservation: updated });
    if (data?.ok) {
      updateReservationInArrays(updated);
      placeReservations();
      closeModal();
    } else {
      modalNote.textContent = data?.message || 'Update failed. Overlap/double-booking protection blocked this change.';
    }
  }

  async function deleteSelectedReservation() {
    if (!selectedReservation || selectedReservation.readonly) return;
    if (!window.confirm('Delete this reservation permanently?')) return;

    const data = await requestJson('?action=api_delete_reservation', { id: selectedReservation.id });
    if (data?.ok) {
      const idxManual = manual.findIndex((r) => r.id === selectedReservation.id);
      if (idxManual >= 0) manual.splice(idxManual, 1);
      const idxAll = allReservations.findIndex((r) => r.id === selectedReservation.id);
      if (idxAll >= 0) allReservations.splice(idxAll, 1);
      placeReservations();
      closeModal();
    } else {
      modalNote.textContent = data?.message || 'Delete failed. Please try again.';
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

    if (modalBuilding) {
      modalBuilding.addEventListener('change', () => {
        populateApartmentOptions(modalBuilding.value, '');
      });
    }

    if (modalDocUpload) {
      modalDocUpload.addEventListener('change', async (ev) => {
        const files = ev.target?.files;
        await uploadDocumentsForSelected(files);
        modalDocUpload.value = '';
      });
    }

    modalDocReplaceInput.addEventListener('change', async (ev) => {
      const file = ev.target?.files?.[0];
      if (!file || !pendingReplaceDoc) {
        modalDocReplaceInput.value = '';
        return;
      }
      await replaceDocument(pendingReplaceDoc.reservationId, pendingReplaceDoc.filename, file);
      pendingReplaceDoc = null;
      modalDocReplaceInput.value = '';
    });

    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modalSave) modalSave.addEventListener('click', () => saveModalChanges(false));
    if (modalCancelStatus) modalCancelStatus.addEventListener('click', () => saveModalChanges(true));
    if (modalDelete) modalDelete.addEventListener('click', deleteSelectedReservation);
  }

  buildBoard();
  placeReservations();
  wireModalEvents();

  if (openReservationId) {
    const target = allReservations.find((r) => r.id === openReservationId);
    if (target) openModal(target);
  }
})();
