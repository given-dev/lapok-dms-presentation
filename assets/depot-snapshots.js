/**
 * Depot stock snapshots (7am opening / 7pm closing) + manager fixed costs.
 * Manager enters opening & closing; RDC / others only view closing status.
 * Closing stock stays inactive until 6:30 PM local time.
 */
(function () {
  /** Separate caches so opening/closing do not overwrite each other on this page. */
  const snapshotCache = { opening: [], closing: [] };
  /** Opening stock locks after save; the manager must click Edit to change it again. */
  let openingHasSaved = false;
  let openingUnlock = false;
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
    const block = document.getElementById('mgrClosingBlock');
    const btn = document.getElementById('mgrClosingSaveBtn');
    const notes = document.getElementById('mgrClosingNotes');
    const chip = document.getElementById('mgrClosingLockChip');
    if (btn) {
      btn.disabled = locked;
      btn.title = locked
        ? `Closing stock opens at ${closingWindowLabel()}`
        : 'Save 7pm closing stock';
      btn.style.opacity = locked ? '0.55' : '';
      btn.style.cursor = locked ? 'not-allowed' : '';
    }
    if (notes) {
      notes.readOnly = locked;
      notes.disabled = locked;
    }
    if (block) block.classList.toggle('depot-closing-locked', locked);
    if (chip) chip.textContent = locked ? `Locked until ${closingWindowLabel()}` : 'Open now';
  }

  function openingIsLocked() {
    return openingHasSaved && !openingUnlock;
  }

  function setOpeningCardLocked(locked) {
    const saveBtn = document.getElementById('mgrOpeningSaveBtn');
    const editBtn = document.getElementById('mgrOpeningEditBtn');
    const notes = document.getElementById('mgrOpeningNotes');
    if (saveBtn) {
      saveBtn.style.display = locked ? 'none' : '';
      saveBtn.disabled = locked;
    }
    if (editBtn) editBtn.style.display = locked ? '' : 'none';
    if (notes) {
      notes.readOnly = locked;
      notes.disabled = locked;
    }
  }

  function editManagerOpeningStock() {
    if (!confirm('Unlock saved opening stock for corrections? Saving again updates the warehouse live stock.')) return;
    openingUnlock = true;
    setOpeningCardLocked(false);
    renderManagerStockBook();
  }

  function depotBrandOrder() {
    return ['300ML RGB', '300ML PET', '400ML', 'ENERGY', '500ML PET', '1L PET', '280ML', '2L PET', 'RWENZORI WATER', 'EMPTIES'];
  }

  function depotCategoryOrder() {
    return depotBrandOrder();
  }

  // Sections counted separately (their own TOTAL row) but never folded into GRAND TOTAL.
  const GRAND_TOTAL_EXCLUDED_BRANDS = ['EMPTIES'];

  function todayHuman() {
    return new Date().toLocaleDateString('en-UG', {
      weekday: 'short',
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    });
  }

  function setStockBookDate() {
    const el = document.getElementById('mgrStockBookDate');
    if (el) el.textContent = todayHuman();
  }

  function emptyBookRow(line) {
    return {
      product_id: Number(line.product_id || 0),
      product_name: line.product_name || line.name || '—',
      sku: line.sku || '—',
      brand: line.brand || line.category || '',
      category: line.brand || line.category || '',
      sort: Number(line.sort ?? 99999),
      openingQty: Number(line.opening ?? line.qty ?? 0),
      purchaseQty: Number(line.purchase ?? 0),
      salesQty: Number(line.sales ?? 0),
      closingQty: Number(line.closing ?? 0),
    };
  }

  function mergedSnapshotLines() {
    const byId = (lines) => {
      const map = {};
      (lines || []).forEach((line) => {
        const id = Number(line.product_id || 0);
        if (id > 0) map[id] = line;
      });
      return map;
    };
    const openingById = byId(snapshotCache.opening);
    const closingById = byId(snapshotCache.closing);
    const structure = (snapshotCache.template || []).length
      ? snapshotCache.template
      : (snapshotCache.opening || []);

    // Only current LAPOK BOOK rows — never append orphan legacy pack lines.
    const rows = structure.map((line) => {
      const id = Number(line.product_id || 0);
      const open = openingById[id] || {};
      const close = closingById[id] || {};
      const row = emptyBookRow(line);
      if (open.opening != null) row.openingQty = Number(open.opening);
      else if (open.qty != null && open.closing == null) row.openingQty = Number(open.qty);
      if (close.closing != null) row.closingQty = Number(close.closing);
      else if (close.qty != null) row.closingQty = Number(close.qty);
      if (close.opening != null) row.openingQty = Number(close.opening);
      if (open.purchase != null) row.purchaseQty = Number(open.purchase);
      if (close.purchase != null) row.purchaseQty = Number(close.purchase);
      if (open.sales != null) row.salesQty = Number(open.sales);
      if (close.sales != null) row.salesQty = Number(close.sales);
      row.brand = line.brand || line.category || row.brand;
      row.category = row.brand;
      return row;
    });

    return rows.sort((a, b) => {
      const order = depotBrandOrder();
      const ai = order.indexOf(a.brand || a.category || '');
      const bi = order.indexOf(b.brand || b.category || '');
      if (ai !== bi) return (ai < 0 ? 99 : ai) - (bi < 0 ? 99 : bi);
      const sa = Number(a.sort ?? 99999);
      const sb = Number(b.sort ?? 99999);
      if (sa !== sb) return sa - sb;
      return String(a.product_name || '').localeCompare(String(b.product_name || ''));
    });
  }

  function syncCacheField(productId, field, value) {
    ['opening', 'closing', 'template'].forEach((snapType) => {
      const lines = snapshotCache[snapType] || [];
      const existing = lines.find((line) => Number(line.product_id || 0) === Number(productId));
      if (!existing) return;
      existing[field] = value;
      if (field === 'opening') existing.qty = value;
      if (field === 'closing') existing.qty = value;
    });
  }

  function stockBookBrandTotals() {
    const totals = {};
    mergedSnapshotLines().forEach((row) => {
      const brand = row.brand || row.category || 'OTHER';
      if (!totals[brand]) totals[brand] = { opening: 0, purchase: 0, sales: 0, closing: 0 };
      totals[brand].opening += Number(row.openingQty || 0);
      totals[brand].purchase += Number(row.purchaseQty || 0);
      totals[brand].sales += Number(row.salesQty || 0);
      totals[brand].closing += Number(row.closingQty || 0);
    });
    return totals;
  }

  function updateStockBookTotals() {
    const table = document.getElementById('mgrStockBookTable');
    if (!table) return;
    const totals = stockBookBrandTotals();
    const grand = { opening: 0, purchase: 0, sales: 0, closing: 0 };
    Object.entries(totals).forEach(([brand, t]) => {
      if (GRAND_TOTAL_EXCLUDED_BRANDS.includes(brand)) return;
      grand.opening += t.opening;
      grand.purchase += t.purchase;
      grand.sales += t.sales;
      grand.closing += t.closing;
    });
    const setCell = (cell, v) => {
      const strong = cell.querySelector('strong');
      if (strong) strong.textContent = String(v);
      else cell.textContent = String(v);
    };
    table.querySelectorAll('tr.stock-book-total').forEach((tr) => {
      if (!tr.cells) return;
      if (tr.classList.contains('stock-book-grand-total')) {
        setCell(tr.cells[1], grand.opening);
        setCell(tr.cells[2], grand.purchase);
        setCell(tr.cells[3], grand.sales);
        setCell(tr.cells[4], grand.closing);
        return;
      }
      const label = (tr.cells[0]?.textContent || '').replace(/\s*TOTAL$/i, '').trim();
      const t = totals[label];
      if (!t) return;
      setCell(tr.cells[1], t.opening);
      setCell(tr.cells[2], t.purchase);
      setCell(tr.cells[3], t.sales);
      setCell(tr.cells[4], t.closing);
    });
  }

  function renderManagerStockBook() {
    const table = document.getElementById('mgrStockBookTable');
    if (!table) return;
    setStockBookDate();
    const rows = mergedSnapshotLines();
    const openingLocked = openingIsLocked();
    const openingTh = openingLocked
      ? '<th class="qty-col stock-book-th-locked">Opening stock<small>Saved · locked</small></th>'
      : '<th class="qty-col">Opening stock</th>';
    if (!rows.length) {
      table.innerHTML = '<tr><th>Brand</th><th>SKUs</th>' + openingTh + '<th class="stock-book-th-locked">Purchase<small>Locked · from deliveries</small></th><th class="stock-book-th-locked">Sales<small>Locked · from cadets</small></th><th>Closing stock</th></tr><tr><td colspan="6" style="color:var(--gray-mid)">No stock lines.</td></tr>';
      return;
    }

    const closingLocked = !isClosingStockWindowOpen();
    let html = '<tr><th>Brand</th><th>SKUs</th>' + openingTh + '<th class="stock-book-th-locked">Purchase<small>Locked · from deliveries</small></th><th class="stock-book-th-locked">Sales<small>Locked · from cadets</small></th><th>Closing stock</th></tr>';
    let currentBrand = '';
    const brandTotals = stockBookBrandTotals();

    rows.forEach((row, idx) => {
      const brand = row.brand || row.category || 'OTHER';
      if (brand !== currentBrand) {
        if (currentBrand && brandTotals[currentBrand]) {
          const t = brandTotals[currentBrand];
          html += `<tr class="stock-book-total"><td colspan="2" class="stock-book-total-label"><strong>${currentBrand} TOTAL</strong></td>
            <td><strong>${t.opening}</strong></td><td><strong>${t.purchase}</strong></td>
            <td><strong>${t.sales}</strong></td><td><strong>${t.closing}</strong></td></tr>`;
        }
        currentBrand = brand;
        html += `<tr class="cat-row"><td colspan="6">${brand}</td></tr>`;
      }
      const openingCell = openingLocked
        ? `<span class="stock-book-purchase${Number(row.openingQty || 0) === 0 ? ' is-zero' : ''}" title="Locked — saved. Use Edit to change." aria-readonly="true">${Number(row.openingQty || 0)}</span>`
        : `<input class="stock-book-input" data-field="opening" data-product-id="${row.product_id}" type="number" min="0" value="${Number(row.openingQty || 0)}">`;
      html += `<tr data-product-id="${row.product_id}">
        <td>${idx === 0 || rows[idx - 1].brand !== brand ? brand : ''}</td>
        <td>${row.product_name || '—'}</td>
        <td>${openingCell}</td>
        <td><span class="stock-book-purchase${Number(row.purchaseQty || 0) === 0 ? ' is-zero' : ''}" title="Locked — filled from Coca-Cola deliveries" aria-readonly="true">${Number(row.purchaseQty || 0)}</span></td>
        <td><span class="stock-book-purchase${Number(row.salesQty || 0) === 0 ? ' is-zero' : ''}" title="Locked — filled from cadet sales reports" aria-readonly="true">${Number(row.salesQty || 0)}</span></td>
        <td><input class="stock-book-input" data-field="closing" data-product-id="${row.product_id}" type="number" min="0" value="${Number(row.closingQty || 0)}" ${closingLocked ? 'disabled' : ''}></td>
      </tr>`;
    });
    if (currentBrand && brandTotals[currentBrand]) {
      const t = brandTotals[currentBrand];
      html += `<tr class="stock-book-total"><td colspan="2" class="stock-book-total-label"><strong>${currentBrand} TOTAL</strong></td>
        <td><strong>${t.opening}</strong></td><td><strong>${t.purchase}</strong></td>
        <td><strong>${t.sales}</strong></td><td><strong>${t.closing}</strong></td></tr>`;
    }
    const grand = { opening: 0, purchase: 0, sales: 0, closing: 0 };
    Object.entries(brandTotals).forEach(([brand, bt]) => {
      if (GRAND_TOTAL_EXCLUDED_BRANDS.includes(brand)) return;
      grand.opening += bt.opening;
      grand.purchase += bt.purchase;
      grand.sales += bt.sales;
      grand.closing += bt.closing;
    });
    html += `<tr class="stock-book-total stock-book-grand-total"><td colspan="2" class="stock-book-total-label"><strong>GRAND TOTAL</strong></td>
      <td><strong>${grand.opening}</strong></td><td><strong>${grand.purchase}</strong></td>
      <td><strong>${grand.sales}</strong></td><td><strong>${grand.closing}</strong></td></tr>`;
    table.innerHTML = html;
    table.querySelectorAll('.stock-book-input').forEach((inp) => {
      inp.addEventListener('input', () => {
        syncCacheField(Number(inp.getAttribute('data-product-id')), inp.getAttribute('data-field'), Number(inp.value || 0));
        updateStockBookTotals();
      });
    });
  }

  function syncCacheQty(type, productId, qty) {
    syncCacheField(productId, type === 'closing' ? 'closing' : 'opening', qty);
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
      // Only the opening editor carries an "opening" suggestion (previous day's closing stock).
      // The closing editor must NOT set opening on its unsaved rows, otherwise mergedSnapshotLines
      // would overwrite today's opening column with 0 before closing stock is saved.
      const isOpening = type === 'opening';
      const suggested = (d.suggested_lines || []).map((l) => {
        const base = { ...l, qty: 0 };
        if (isOpening) base.opening = Number(l.opening || 0);
        else delete base.opening;
        base.purchase = Number(l.purchase || 0);
        base.sales = Number(l.sales || 0);
        base.closing = Number(l.closing || 0);
        // Read-only views (RDC depot stock status) show today's calculated stock:
        // closing = warehouse ledger + cadet returns, which at start of day is
        // exactly yesterday's closing (= today's opening).
        if (!isOpening && readOnly) base.qty = base.closing;
        return base;
      });
      snapshotCache.template = suggested.map((l) => ({ ...l }));
      if (d.snapshot?.lines?.length) {
        // Server already merged onto current catalog — keep only those rows.
        snapshotCache[type] = d.snapshot.lines.map((l) => ({ ...l }));
      } else {
        snapshotCache[type] = suggested.map((l) => ({ ...l }));
      }
      if (type === 'opening') {
        openingHasSaved = !!(d.snapshot && (d.snapshot.lines || []).length);
        if (!openingHasSaved) openingUnlock = false;
        setOpeningCardLocked(openingIsLocked());
      }
      if (tableId === 'mgrOpeningStockTable' || tableId === 'mgrClosingStockTable') {
        renderManagerStockBook();
      } else {
        renderSnapshotTable(tableId, type, snapshotCache[type], readOnly);
      }
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

  function buildSaveLines(type) {
    return mergedSnapshotLines().map((row) => ({
      product_id: row.product_id,
      product_name: row.product_name,
      sku: row.sku,
      brand: row.brand,
      category: row.brand,
      opening: Number(row.openingQty || 0),
      purchase: Number(row.purchaseQty || 0),
      sales: Number(row.salesQty || 0),
      closing: Number(row.closingQty || 0),
      qty: type === 'closing' ? Number(row.closingQty || 0) : Number(row.openingQty || 0),
    }));
  }

  async function saveDepotSnapshot(type, notesId, reloadFn) {
    if (type === 'closing' && !isClosingStockWindowOpen()) {
      toast(`Closing stock opens at ${closingWindowLabel()}.`, true);
      return;
    }
    try {
      const lines = buildSaveLines(type);
      snapshotCache[type] = lines;
      await LapokAPI.post('/api/depot/save_snapshot.php', {
        date: todayIso(),
        type,
        lines,
        notes: document.getElementById(notesId)?.value?.trim() || '',
      });
      toast(type === 'opening' ? 'Opening stock saved (7am).' : 'Closing stock saved (7pm).');
      if (type === 'opening') {
        openingHasSaved = true;
        openingUnlock = false;
        setOpeningCardLocked(true);
        renderManagerStockBook();
      }
      if (type === 'opening' && typeof loadManagerOccdBoards === 'function'
          && document.getElementById('page-manager-ccba-boards')?.classList.contains('active')) {
        loadManagerOccdBoards();
      }
      if (typeof reloadFn === 'function') await reloadFn();
    } catch (e) {
      toast(e.message, true);
    }
  }

  async function loadManagerStockBook() {
    await loadDepotSnapshotEditor('opening', 'mgrOpeningStockTable', 'mgrOpeningStatus', 'mgrOpeningNotes', { readOnly: false });
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
    renderManagerStockBook();
  }

  async function loadManagerOpeningStock() {
    await loadManagerStockBook();
  }

  async function loadManagerClosingStock() {
    await loadManagerStockBook();
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
  window.loadManagerStockBook = loadManagerStockBook;
  window.loadAccountantClosingStock = loadAccountantClosingStock;
  window.saveManagerOpeningStock = () => saveDepotSnapshot('opening', 'mgrOpeningNotes', loadManagerOpeningStock);
  window.saveManagerClosingStock = () => saveDepotSnapshot('closing', 'mgrClosingNotes', loadManagerClosingStock);
  window.editManagerOpeningStock = editManagerOpeningStock;
  window.saveAccountantClosingStock = () => toast('Closing stock is entered by the manager only.', true);
  window.loadManagerFixedCosts = loadManagerFixedCosts;
  window.saveManagerFixedCosts = saveManagerFixedCosts;
  window.isClosingStockWindowOpen = isClosingStockWindowOpen;
})();
