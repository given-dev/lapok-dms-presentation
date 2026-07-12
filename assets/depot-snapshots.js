/**
 * Depot stock snapshots (7am opening / 7pm closing) + manager fixed costs.
 * Manager enters opening & closing; RDC / others only view closing status.
 * Closing stock stays inactive until 6:30 PM local time.
 */
(function () {
  /** Separate caches so opening/closing do not overwrite each other on this page. */
  const snapshotCache = { opening: [], closing: [] };
  const CLOSING_OPENS_HOUR = 18;
  const CLOSING_OPENS_MINUTE = 30;

  function ugx(n) {
    return 'UGX ' + Number(n || 0).toLocaleString();
  }

  function toast(msg, err) {
    if (typeof adminToast === 'function') adminToast(msg, !!err);
  }

  function todayIso() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  /** Closing stock entry unlocks at 6:30 PM local. */
  function isClosingStockWindowOpen(now = new Date()) {
    const mins = now.getHours() * 60 + now.getMinutes();
    return mins >= (CLOSING_OPENS_HOUR * 60 + CLOSING_OPENS_MINUTE);
  }

  function closingWindowLabel() {
    return '6:30 PM';
  }

  function setClosingCardLocked(locked) {
    const card = document.getElementById('mgrClosingStockCard');
    const btn = document.getElementById('mgrClosingSaveBtn');
    const notes = document.getElementById('mgrClosingNotes');
    if (btn) {
      btn.disabled = locked;
      btn.title = locked
        ? `Closing stock opens at ${closingWindowLabel()}`
        : 'Save 7pm closing stock';
      btn.style.opacity = locked ? '0.55' : '';
      btn.style.cursor = locked ? 'not-allowed' : '';
    }
    if (notes && locked) {
      notes.readOnly = true;
      notes.disabled = true;
    }
    if (card) {
      card.style.opacity = locked ? '0.72' : '';
      card.classList.toggle('depot-closing-locked', locked);
    }
  }

  function depotCategoryOrder() {
    return ['CSD', 'ENERGY', 'JUICE', 'VAD', 'WATER', 'OTHER'];
  }

  function renderSnapshotTable(tableId, type, lines, readOnly) {
    const table = document.getElementById(tableId);
    if (!table) return;
    if (!lines.length) {
      table.innerHTML = '<tr><th>Product</th><th>SKU</th><th>Qty</th></tr><tr><td colspan="3" style="color:var(--gray-mid)">No stock lines.</td></tr>';
      return;
    }

    const groups = {};
    lines.forEach((line, idx) => {
      const cat = line.category || 'OTHER';
      if (!groups[cat]) groups[cat] = [];
      groups[cat].push({ line, idx });
    });

    let html = '<tr><th>Product</th><th>SKU</th><th>Qty</th></tr>';
    const seen = new Set();
    const paint = (cat, items) => {
      html += `<tr class="rdc-cat-row"><td colspan="3">${cat}</td></tr>`;
      items.forEach(({ line, idx }) => {
        const qty = Number(line.qty || 0);
        const qtyCell = readOnly
          ? `<td><strong>${qty}</strong></td>`
          : `<td><input class="qty-inp depot-snap-qty" data-type="${type}" data-idx="${idx}" type="number" min="0" value="${qty}"></td>`;
        html += `<tr>
          <td>${line.product_name || '—'}</td>
          <td>${line.sku || '—'}</td>
          ${qtyCell}
        </tr>`;
      });
    };
    depotCategoryOrder().forEach((cat) => {
      const items = groups[cat];
      if (!items?.length) return;
      seen.add(cat);
      paint(cat, items);
    });
    Object.keys(groups).forEach((cat) => {
      if (seen.has(cat)) return;
      paint(cat, groups[cat]);
    });

    table.innerHTML = html;
    if (!readOnly) {
      table.querySelectorAll('.depot-snap-qty').forEach((inp) => {
        inp.addEventListener('input', () => {
          const snapType = inp.getAttribute('data-type');
          const i = Number(inp.getAttribute('data-idx'));
          if (snapshotCache[snapType] && snapshotCache[snapType][i]) {
            snapshotCache[snapType][i].qty = Number(inp.value || 0);
          }
        });
      });
    }
  }

  async function loadDepotSnapshotEditor(type, tableId, statusId, notesId, options = {}) {
    const readOnly = !!options.readOnly;
    const date = todayIso();
    try {
      const d = await LapokAPI.get('/api/depot/fetch_snapshot.php?date=' + encodeURIComponent(date) + '&type=' + encodeURIComponent(type));
      const lines = (d.snapshot?.lines || d.suggested_lines || []).map((l) => ({ ...l }));
      if (!readOnly) {
        snapshotCache[type] = lines;
      }
      renderSnapshotTable(tableId, type, lines, readOnly);
      const status = document.getElementById(statusId);
      if (status) {
        if (d.snapshot) {
          status.textContent = `Submitted ${new Date(d.snapshot.submitted_at).toLocaleTimeString('en-UG', { hour: '2-digit', minute: '2-digit' })} by ${d.snapshot.submitted_by_name || 'manager'}`;
        } else if (readOnly) {
          status.textContent = type === 'opening'
            ? 'Not submitted yet — manager enters opening stock at 7:00 AM'
            : 'Not submitted yet — manager enters closing stock from 6:30 PM';
        } else if (type === 'closing' && !isClosingStockWindowOpen()) {
          status.textContent = `Locked until ${closingWindowLabel()} — then enter and save closing stock (target 7:00 PM)`;
        } else {
          status.textContent = type === 'opening' ? 'Not submitted — target 7:00 AM' : 'Open now — target save by 7:00 PM';
        }
      }
      const notes = document.getElementById(notesId);
      if (notes) {
        notes.value = d.snapshot?.notes || '';
        notes.readOnly = readOnly;
        notes.disabled = readOnly;
        if (readOnly) {
          notes.placeholder = d.snapshot?.notes ? '' : 'No notes from manager yet';
        }
      }
    } catch (e) {
      toast('Could not load snapshot: ' + e.message, true);
    }
  }

  async function saveDepotSnapshot(type, notesId, reloadFn) {
    if (type === 'closing' && !isClosingStockWindowOpen()) {
      toast(`Closing stock opens at ${closingWindowLabel()}.`, true);
      return;
    }
    try {
      await LapokAPI.post('/api/depot/save_snapshot.php', {
        date: todayIso(),
        type,
        lines: snapshotCache[type] || [],
        notes: document.getElementById(notesId)?.value?.trim() || '',
      });
      toast(type === 'opening' ? 'Opening stock saved (7am).' : 'Closing stock saved (7pm).');
      if (typeof reloadFn === 'function') await reloadFn();
    } catch (e) {
      toast(e.message, true);
    }
  }

  async function loadManagerOpeningStock() {
    await loadDepotSnapshotEditor('opening', 'mgrOpeningStockTable', 'mgrOpeningStatus', 'mgrOpeningNotes', { readOnly: false });
  }

  async function loadManagerClosingStock() {
    const locked = !isClosingStockWindowOpen();
    setClosingCardLocked(locked);
    await loadDepotSnapshotEditor(
      'closing',
      'mgrClosingStockTable',
      'mgrClosingStatus',
      'mgrClosingNotes',
      { readOnly: locked }
    );
    if (locked) setClosingCardLocked(true);
  }

  async function loadAccountantClosingStock() {
    await loadDepotSnapshotEditor('closing', 'accClosingStockTable', 'accClosingStatus', 'accClosingNotes', { readOnly: true });
  }

  function fixedCostsMonth() {
    return document.getElementById('accMonthPicker')?.value || todayIso().slice(0, 7);
  }

  async function loadManagerFixedCosts() {
    const month = fixedCostsMonth();
    try {
      const d = await LapokAPI.get('/api/depot/fetch_fixed_costs.php?month=' + encodeURIComponent(month));
      const f = d.fixed || {};
      const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = String(Math.round(Number(v || 0))); };
      set('mgrFixedRent', f.rent_ugx);
      set('mgrFixedSalaries', f.salaries_ugx);
      set('mgrFixedUtilities', f.utilities_ugx);
      set('mgrFixedSecurity', f.security_ugx);
      set('mgrFixedOther', f.other_ugx);
      const notes = document.getElementById('mgrFixedNotes');
      if (notes) notes.value = f.notes || '';
      const total = document.getElementById('mgrFixedMonthlyTotal');
      if (total) total.textContent = ugx(d.monthly_total || 0);
    } catch (e) {
      toast('Could not load fixed costs: ' + e.message, true);
    }
  }

  async function saveManagerFixedCosts() {
    try {
      await LapokAPI.post('/api/depot/save_fixed_costs.php', {
        cost_month: fixedCostsMonth(),
        rent_ugx: Number(document.getElementById('mgrFixedRent')?.value || 0),
        salaries_ugx: Number(document.getElementById('mgrFixedSalaries')?.value || 0),
        utilities_ugx: Number(document.getElementById('mgrFixedUtilities')?.value || 0),
        security_ugx: Number(document.getElementById('mgrFixedSecurity')?.value || 0),
        other_ugx: Number(document.getElementById('mgrFixedOther')?.value || 0),
        notes: document.getElementById('mgrFixedNotes')?.value?.trim() || '',
      });
      toast('Monthly fixed costs saved.');
      await loadManagerFixedCosts();
      if (typeof loadDirectorBriefPage === 'function') loadDirectorBriefPage();
    } catch (e) {
      toast(e.message, true);
    }
  }

  window.loadManagerOpeningStock = loadManagerOpeningStock;
  window.loadManagerClosingStock = loadManagerClosingStock;
  window.loadAccountantClosingStock = loadAccountantClosingStock;
  window.saveManagerOpeningStock = () => saveDepotSnapshot('opening', 'mgrOpeningNotes', loadManagerOpeningStock);
  window.saveManagerClosingStock = () => saveDepotSnapshot('closing', 'mgrClosingNotes', loadManagerClosingStock);
  window.saveAccountantClosingStock = () => toast('Closing stock is entered by the manager only.', true);
  window.loadManagerFixedCosts = loadManagerFixedCosts;
  window.saveManagerFixedCosts = saveManagerFixedCosts;
  window.isClosingStockWindowOpen = isClosingStockWindowOpen;
})();
