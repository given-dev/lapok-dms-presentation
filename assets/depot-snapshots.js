/**
 * Depot stock snapshots (7am opening / 7pm closing) + manager fixed costs.
 */
(function () {
  let snapshotLines = [];

  function ugx(n) {
    return 'UGX ' + Number(n || 0).toLocaleString();
  }

  function toast(msg, err) {
    if (typeof adminToast === 'function') adminToast(msg, !!err);
  }

  function todayIso() {
    return new Date().toISOString().slice(0, 10);
  }

  function depotCategoryOrder() {
    return ['CSD', 'ENERGY', 'JUICE', 'VAD', 'WATER', 'OTHER'];
  }

  function renderSnapshotTable(tableId, lines) {
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
    depotCategoryOrder().forEach((cat) => {
      const items = groups[cat];
      if (!items?.length) return;
      seen.add(cat);
      html += `<tr class="rdc-cat-row"><td colspan="3">${cat}</td></tr>`;
      items.forEach(({ line, idx }) => {
        html += `<tr>
          <td>${line.product_name || '—'}</td>
          <td>${line.sku || '—'}</td>
          <td><input class="qty-inp depot-snap-qty" data-idx="${idx}" type="number" min="0" value="${Number(line.qty || 0)}"></td>
        </tr>`;
      });
    });
    Object.keys(groups).forEach((cat) => {
      if (seen.has(cat)) return;
      html += `<tr class="rdc-cat-row"><td colspan="3">${cat}</td></tr>`;
      groups[cat].forEach(({ line, idx }) => {
        html += `<tr>
          <td>${line.product_name || '—'}</td>
          <td>${line.sku || '—'}</td>
          <td><input class="qty-inp depot-snap-qty" data-idx="${idx}" type="number" min="0" value="${Number(line.qty || 0)}"></td>
        </tr>`;
      });
    });

    table.innerHTML = html;
    table.querySelectorAll('.depot-snap-qty').forEach((inp) => {
      inp.addEventListener('input', () => {
        const i = Number(inp.getAttribute('data-idx'));
        if (snapshotLines[i]) snapshotLines[i].qty = Number(inp.value || 0);
      });
    });
  }

  async function loadDepotSnapshotEditor(type, tableId, statusId, notesId) {
    const date = todayIso();
    try {
      const d = await LapokAPI.get('/api/depot/fetch_snapshot.php?date=' + encodeURIComponent(date) + '&type=' + encodeURIComponent(type));
      snapshotLines = (d.snapshot?.lines || d.suggested_lines || []).map((l) => ({ ...l }));
      renderSnapshotTable(tableId, snapshotLines);
      const status = document.getElementById(statusId);
      if (status) {
        status.textContent = d.snapshot
          ? `Submitted ${new Date(d.snapshot.submitted_at).toLocaleTimeString('en-UG', { hour: '2-digit', minute: '2-digit' })} by ${d.snapshot.submitted_by_name || 'staff'}`
          : (type === 'opening' ? 'Not submitted — target 7:00 AM' : 'Not submitted — target 7:00 PM');
      }
      const notes = document.getElementById(notesId);
      if (notes && d.snapshot?.notes) notes.value = d.snapshot.notes;
    } catch (e) {
      toast('Could not load snapshot: ' + e.message, true);
    }
  }

  async function saveDepotSnapshot(type, notesId) {
    try {
      await LapokAPI.post('/api/depot/save_snapshot.php', {
        date: todayIso(),
        type,
        lines: snapshotLines,
        notes: document.getElementById(notesId)?.value?.trim() || '',
      });
      toast(type === 'opening' ? 'Opening stock saved (7am).' : 'Closing stock saved (7pm).');
      if (type === 'opening') await loadManagerOpeningStock();
      else await loadAccountantClosingStock();
    } catch (e) {
      toast(e.message, true);
    }
  }

  async function loadManagerOpeningStock() {
    await loadDepotSnapshotEditor('opening', 'mgrOpeningStockTable', 'mgrOpeningStatus', 'mgrOpeningNotes');
  }

  async function loadAccountantClosingStock() {
    await loadDepotSnapshotEditor('closing', 'accClosingStockTable', 'accClosingStatus', 'accClosingNotes');
  }

  async function loadManagerFixedCosts() {
    const month = todayIso().slice(0, 7);
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
        cost_month: todayIso().slice(0, 7),
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
  window.loadAccountantClosingStock = loadAccountantClosingStock;
  window.saveManagerOpeningStock = () => saveDepotSnapshot('opening', 'mgrOpeningNotes');
  window.saveAccountantClosingStock = () => saveDepotSnapshot('closing', 'accClosingNotes');
  window.loadManagerFixedCosts = loadManagerFixedCosts;
  window.saveManagerFixedCosts = saveManagerFixedCosts;
})();
