/**
 * Lapok DMS &mdash; Manager CCBA replenishment (assisted portal integration)
 */
let ccbaPortalUrl = 'https://uganda.myccba.africa/';
let ccbaActiveOrderId = null;
let ccbaEditorLines = [];

const CCBA_STATUS_LABEL = {
  draft: 'Draft',
  ready_for_ccba: 'Ready for CCBA',
  submitted_to_ccba: 'Submitted to CCBA',
  ccba_acknowledged: 'CCBA acknowledged',
  ccba_confirmed: 'CCBA confirmed',
  scheduled: 'Scheduled',
  dispatched: 'Dispatched',
  delivered: 'Delivered',
  received_in_lapok: 'Received in Lapok',
  closed: 'Closed',
  partial_delivery: 'Partial delivery',
  cancelled: 'Cancelled',
  rejected: 'Rejected',
};

function ccbaCanEdit() {
  return currentUser && ['admin', 'manager'].includes(currentUser.role);
}

function ccbaStatusBadge(status) {
  const cls = {
    draft: 'bg',
    ready_for_ccba: 'bw',
    submitted_to_ccba: 'bi',
    ccba_confirmed: 'bs',
    closed: 'bs',
    cancelled: 'bd',
    rejected: 'bd',
    partial_delivery: 'bd',
  }[status] || 'bg';
  return `<span class="badge ${cls}">${CCBA_STATUS_LABEL[status] || status}</span>`;
}

async function loadCcbaPage() {
  if (!document.getElementById('page-manager-ccba-order')) return;
  try {
    const cfg = await LapokAPI.get('/api/ccba/config.php');
    ccbaPortalUrl = cfg.portal_url || ccbaPortalUrl;
  } catch (_) {}
  toggleCcbaEditorActions();
  await loadCcbaOrderList();
  if (!ccbaActiveOrderId) {
    await ccbaNewFromLowStock();
  }
}

function toggleCcbaEditorActions() {
  const editable = ccbaCanEdit();
  document.querySelectorAll('.ccba-mgr-only').forEach((el) => {
    el.style.display = editable ? '' : 'none';
  });
}

async function loadCcbaOrderList() {
  const tbody = document.getElementById('ccbaOrderListBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:1rem;color:var(--gray-mid)">Loading…</td></tr>';
  try {
    const data = await LapokAPI.get('/api/ccba/orders/fetch.php?limit=30');
    const orders = data.orders || [];
    if (!orders.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:1rem;color:var(--gray-mid)">No CCBA orders yet.</td></tr>';
      return;
    }
    tbody.innerHTML = orders.map((o) => {
      const active = o.id === ccbaActiveOrderId ? ' style="background:#FFF7F7"' : '';
      const ref = o.ccba_order_no ? `<div style="font-size:10px;color:var(--gray-mid)">CCBA: ${escHtml(o.ccba_order_no)}</div>` : '';
      return `<tr${active}>
        <td style="font-family:monospace;font-size:11px">${escHtml(o.lapok_ref)}${ref}</td>
        <td>${ccbaStatusBadge(o.status)}</td>
        <td>${o.line_count || 0}</td>
        <td>${Number(o.est_total || 0).toLocaleString()}</td>
        <td style="font-size:11px">${LapokAPI.formatDate(o.created_at)}</td>
        <td><button class="btn btn-sm" onclick="ccbaOpenOrder(${o.id})">Open</button></td>
      </tr>`;
    }).join('');
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="6" style="color:var(--red);padding:1rem">${escHtml(e.message)}</td></tr>`;
  }
}

async function ccbaNewFromLowStock() {
  try {
    const data = await LapokAPI.get('/api/ccba/orders/suggest.php');
    ccbaActiveOrderId = null;
    ccbaEditorLines = (data.lines || []).map((l) => ({ ...l }));
    if (!ccbaEditorLines.length) {
      const stock = await LapokAPI.get('/api/stock/fetch_stock.php');
      ccbaEditorLines = (stock.stock || []).slice(0, 6).map((s) => ({
        product_id: s.product_id,
        name: s.name,
        sku: s.sku,
        warehouse_qty: s.warehouse_qty,
        min_stock: s.min_stock,
        qty_requested: 0,
        unit_cost_estimate: (s.unit_price || 0) * 0.6,
        ccba_sku_code: null,
      }));
    }
    renderCcbaEditor();
    document.getElementById('ccbaEditorRef').textContent = 'New order (unsaved)';
    document.getElementById('ccbaEditorStatus').innerHTML = ccbaStatusBadge('draft');
  } catch (e) {
    alert(e.message);
  }
}

async function ccbaOpenOrder(id) {
  try {
    const data = await LapokAPI.get('/api/ccba/orders/detail.php?id=' + id);
    const order = data.order;
    ccbaActiveOrderId = order.id;
    ccbaEditorLines = (order.items || []).map((i) => ({
      product_id: i.product_id,
      name: i.product_name,
      sku: i.sku,
      warehouse_qty: '&mdash;',
      min_stock: '&mdash;',
      qty_requested: i.qty_requested,
      unit_cost_estimate: i.unit_cost_estimate,
      ccba_sku_code: i.ccba_sku_code,
    }));
    document.getElementById('ccbaEditorRef').textContent = order.lapok_ref;
    document.getElementById('ccbaEditorStatus').innerHTML = ccbaStatusBadge(order.status);
    document.getElementById('ccbaRequestedDate').value = order.requested_delivery_date || '';
    document.getElementById('ccbaOrderNotes').value = order.notes || '';
    renderCcbaEditor();
    renderCcbaTimeline(order.events || []);
    await loadCcbaOrderList();
  } catch (e) {
    alert(e.message);
  }
}

function renderCcbaEditor() {
  const tbody = document.getElementById('ccbaEditorBody');
  if (!tbody) return;
  let total = 0;
  tbody.innerHTML = ccbaEditorLines.map((line, i) => {
    const sub = (line.qty_requested || 0) * (line.unit_cost_estimate || 0);
    total += sub;
    const low = line.warehouse_qty !== '&mdash;' && line.warehouse_qty < line.min_stock;
    const wh = line.warehouse_qty === '&mdash;' ? '&mdash;' : (low ? `<span class="badge bd">${line.warehouse_qty}</span>` : line.warehouse_qty);
    return `<tr>
      <td>${escHtml(line.name)}<div style="font-size:10px;color:var(--gray-mid)">${escHtml(line.sku)}</div></td>
      <td>${wh}</td>
      <td>${line.min_stock}</td>
      <td><input class="qty-inp ccba-qty" type="number" min="0" value="${line.qty_requested || 0}" data-idx="${i}" oninput="ccbaRecalcTotal()"></td>
      <td><input class="qty-inp ccba-cost" type="number" min="0" value="${Math.round(line.unit_cost_estimate || 0)}" data-idx="${i}" style="width:90px" oninput="ccbaRecalcTotal()"></td>
      <td class="ccba-line-total" data-idx="${i}">${sub.toLocaleString()}</td>
    </tr>`;
  }).join('');
  document.getElementById('ccbaOrderTotal').textContent = 'UGX ' + total.toLocaleString();
}

function ccbaRecalcTotal() {
  let total = 0;
  document.querySelectorAll('#ccbaEditorBody tr').forEach((tr) => {
    const qtyInp = tr.querySelector('.ccba-qty');
    const costInp = tr.querySelector('.ccba-cost');
    const idx = parseInt(qtyInp?.dataset.idx || '0', 10);
    const qty = parseInt(qtyInp?.value || '0', 10);
    const cost = parseFloat(costInp?.value || '0');
    if (ccbaEditorLines[idx]) {
      ccbaEditorLines[idx].qty_requested = qty;
      ccbaEditorLines[idx].unit_cost_estimate = cost;
    }
    const sub = qty * cost;
    total += sub;
    const cell = tr.querySelector('.ccba-line-total');
    if (cell) cell.textContent = sub.toLocaleString();
  });
  document.getElementById('ccbaOrderTotal').textContent = 'UGX ' + total.toLocaleString();
}

function ccbaCollectPayload(markReady) {
  document.querySelectorAll('.ccba-qty').forEach((inp) => {
    const idx = parseInt(inp.dataset.idx || '0', 10);
    if (ccbaEditorLines[idx]) ccbaEditorLines[idx].qty_requested = parseInt(inp.value || '0', 10);
  });
  document.querySelectorAll('.ccba-cost').forEach((inp) => {
    const idx = parseInt(inp.dataset.idx || '0', 10);
    if (ccbaEditorLines[idx]) ccbaEditorLines[idx].unit_cost_estimate = parseFloat(inp.value || '0');
  });
  return {
    order_id: ccbaActiveOrderId || undefined,
    mark_ready: !!markReady,
    requested_delivery_date: document.getElementById('ccbaRequestedDate')?.value || '',
    notes: document.getElementById('ccbaOrderNotes')?.value || '',
    items: ccbaEditorLines
      .filter((l) => (l.qty_requested || 0) > 0)
      .map((l) => ({
        product_id: l.product_id,
        qty_requested: l.qty_requested,
        unit_cost_estimate: l.unit_cost_estimate,
        ccba_sku_code: l.ccba_sku_code,
      })),
  };
}

async function ccbaSaveDraft(markReady) {
  if (!ccbaCanEdit()) return;
  const payload = ccbaCollectPayload(markReady);
  if (!payload.items.length) {
    alert('Enter at least one quantity to order.');
    return;
  }
  try {
    const data = await LapokAPI.post('/api/ccba/orders/save.php', payload);
    ccbaActiveOrderId = data.order.id;
    document.getElementById('ccbaEditorRef').textContent = data.order.lapok_ref;
    document.getElementById('ccbaEditorStatus').innerHTML = ccbaStatusBadge(data.order.status);
    renderCcbaTimeline(data.order.events || []);
    await loadCcbaOrderList();
    alert(markReady ? 'Order saved and marked ready for CCBA.' : 'Draft saved in Lapok.');
  } catch (e) {
    alert(e.message);
  }
}

async function ccbaSubmitToPortal() {
  if (!ccbaCanEdit()) return;
  if (!ccbaActiveOrderId) {
    await ccbaSaveDraft(true);
    if (!ccbaActiveOrderId) return;
  }
  try {
    const data = await LapokAPI.post('/api/ccba/orders/submit.php', { order_id: ccbaActiveOrderId });
    ccbaPortalUrl = data.portal_url || ccbaPortalUrl;
    document.getElementById('ccbaEditorStatus').innerHTML = ccbaStatusBadge(data.order.status);
    renderCcbaTimeline(data.order.events || []);
    await loadCcbaOrderList();
    window.open(ccbaPortalUrl, '_blank', 'noopener');
    openModal('ccbaConfirmModal');
  } catch (e) {
    alert(e.message);
  }
}

function ccbaOpenPortal() {
  window.open(ccbaPortalUrl, '_blank', 'noopener');
}

async function ccbaExportCsv() {
  const lines = ccbaCollectPayload(false).items;
  if (!lines.length) {
    alert('Nothing to export &mdash; add quantities first.');
    return;
  }
  const rows = ccbaEditorLines
    .filter((l) => (l.qty_requested || 0) > 0)
    .map((l) => [l.sku, l.product_id, l.qty_requested, l.unit_cost_estimate, l.ccba_sku_code || '']);
  const ref = document.getElementById('ccbaEditorRef')?.textContent || 'order';
  if (typeof LapokAPI !== 'undefined' && LapokAPI.downloadBrandedExcel) {
    await LapokAPI.downloadBrandedExcel({
      title: 'CCBA order lines',
      subtitle: 'Pick list for MyCCBA confirmation',
      headers: ['Outpost SKU', 'Product ID', 'Qty', 'Unit cost est.', 'CCBA SKU'],
      rows,
      meta: { Reference: ref, Lines: String(rows.length) },
      filename: 'Outpost-DMS-CCBA-' + String(ref).replace(/\s+/g, '_') + '.xls',
    });
    return;
  }
  const header = 'Outpost SKU,Product ID,Qty,Unit cost est.,CCBA SKU\n';
  const body = rows.map((r) => r.join(',')).join('\n');
  const blob = new Blob([header + body], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = ref.replace(/\s+/g, '_') + '_ccba.csv';
  a.click();
  URL.revokeObjectURL(a.href);
}

async function ccbaConfirmRef(event) {
  event.preventDefault();
  if (!ccbaCanEdit() || !ccbaActiveOrderId) return;
  const ccbaOrderNo = document.getElementById('ccbaConfirmOrderNo')?.value.trim();
  const ccbaPoNo = document.getElementById('ccbaConfirmPoNo')?.value.trim();
  if (!ccbaOrderNo) {
    alert('Enter the CCBA order number from MyCCBA confirmation.');
    return;
  }
  try {
    const data = await LapokAPI.post('/api/ccba/orders/confirm_ref.php', {
      order_id: ccbaActiveOrderId,
      ccba_order_no: ccbaOrderNo,
      ccba_po_no: ccbaPoNo,
    });
    document.getElementById('ccbaEditorStatus').innerHTML = ccbaStatusBadge(data.order.status);
    renderCcbaTimeline(data.order.events || []);
    closeModal('ccbaConfirmModal');
    await loadCcbaOrderList();
    alert('CCBA reference saved. No need to re-enter in Lapok when delivery arrives.');
  } catch (e) {
    alert(e.message);
  }
}

function renderCcbaTimeline(events) {
  const el = document.getElementById('ccbaTimeline');
  if (!el) return;
  if (!events.length) {
    el.innerHTML = '<p style="font-size:12px;color:var(--gray-mid)">No status events yet.</p>';
    return;
  }
  el.innerHTML = events.map((e) => {
    const when = LapokAPI.formatTime(e.recorded_at);
    const label = e.ccba_status_label || CCBA_STATUS_LABEL[e.status] || e.status;
    return `<div class="tl-item"><div class="tl-dot"></div><div class="tl-time">${when}</div><div class="tl-text"><strong>${escHtml(label)}</strong> &middot; ${escHtml(e.source)}</div></div>`;
  }).join('');
}

function escHtml(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

async function loadCcbaProductMap() {
  const body = document.getElementById('ccbaMapBody');
  if (!body) return;
  body.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--gray-mid)">Loading…</td></tr>';
  try {
    const data = await LapokAPI.get('/api/ccba/product_map/fetch.php');
    const maps = data.mappings || [];
    if (!maps.length) {
      body.innerHTML = '<tr><td colspan="4" style="color:var(--gray-mid)">No active products.</td></tr>';
      return;
    }
    body.innerHTML = maps.map((m) => `
      <tr data-product-id="${m.product_id}">
        <td>${escHtml(m.name)}<div style="font-size:11px;color:var(--gray-mid)">${escHtml(m.sku || '')}</div></td>
        <td><input class="input" style="min-height:32px" data-ccba-sku value="${escHtml(m.ccba_sku_code || '')}" placeholder="CCBA SKU"></td>
        <td><input class="input" style="min-height:32px" data-ccba-pack value="${escHtml(m.ccba_pack_desc || '')}" placeholder="Pack desc"></td>
        <td><button class="btn btn-sm btn-red" type="button" onclick="saveCcbaProductMapRow(${m.product_id}, this)">Save</button></td>
      </tr>`).join('');
  } catch (e) {
    body.innerHTML = `<tr><td colspan="4" style="color:var(--red)">${escHtml(e.message)}</td></tr>`;
  }
}

async function saveCcbaProductMapRow(productId, btn) {
  const row = btn?.closest('tr');
  if (!row) return;
  const sku = row.querySelector('[data-ccba-sku]')?.value?.trim() || '';
  const pack = row.querySelector('[data-ccba-pack]')?.value?.trim() || '';
  btn.disabled = true;
  try {
    await LapokAPI.post('/api/ccba/product_map/save.php', {
      product_id: productId,
      ccba_sku_code: sku,
      ccba_pack_desc: pack,
    });
    if (typeof adminToast === 'function') adminToast('SKU map saved');
  } catch (e) {
    alert(e.message);
  } finally {
    btn.disabled = false;
  }
}

async function runCcbaStockSync() {
  try {
    const dateEl = document.getElementById('occdBoardDate');
<<<<<<< HEAD
    const snapshot_date = dateEl?.value || LapokAPI.localIsoDate();
=======
    const snapshot_date = dateEl?.value || LapokAPI.todayIso();
>>>>>>> origin/main
    const data = await LapokAPI.post('/api/ccba/stock_sync/snapshot.php', { snapshot_date });
    const msg = data.message || `Snapshot saved (${data.products || 0} products).`;
    if (typeof adminToast === 'function') adminToast(msg);
    else alert(msg);
  } catch (e) {
    alert(e.message);
  }
}

window.loadCcbaProductMap = loadCcbaProductMap;
window.saveCcbaProductMapRow = saveCcbaProductMapRow;
window.runCcbaStockSync = runCcbaStockSync;
