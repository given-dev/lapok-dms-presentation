/**
 * Lapok DMS — Manager operations polish (dashboard, stock, dispatch, exceptions, CCBA tools)
 */
let mgrDispatchData = { vehicles: [], routes: [], users: [], products: [] };

async function loadManagerDashboardExtras() {
  if (!currentUser || !['admin', 'manager'].includes(currentUser.role)) return;
  const handoff = document.getElementById('mgrHandoffCard');
  const exc = document.getElementById('mgrExceptionsCard');
  if (!handoff && !exc) return;
  try {
    const d = await LapokAPI.get('/api/dashboard/manager.php');
    if (handoff) {
      const pack = d.accountant_pack;
      const brief = d.executive_brief_today;
      const boards = d.boards_today || {};
      handoff.innerHTML = `
        <div class="card-header"><span class="card-title">Accountant &amp; executive handoff</span></div>
        <div class="tbl-wrap"><table>
          <tr><th>Item</th><th>Status</th><th>Action</th></tr>
          <tr><td>Finance pack from Accountant</td><td>${pack ? mgrStatusBadge(pack.status) : '<span class="badge bw">Awaiting</span>'}</td>
            <td>${pack ? `<button class="btn btn-sm" onclick="reportOpenPdf(${pack.id})">View PDF</button>` : '<button class="btn btn-sm" onclick="showPage(\'report-exchange\')">Open inbox</button>'}</td></tr>
          <tr><td>Cash trips to confirm</td><td><span class="badge ${d.cash_pending_confirmation ? 'bd' : 'bs'}">${d.cash_pending_confirmation} pending</span></td>
            <td><span style="font-size:11px;color:var(--gray-mid)">Accountant confirms</span></td></tr>
          <tr><td>Executive brief (today)</td><td>${brief ? mgrStatusBadge(brief.status) : '<span class="badge bg">Not sent</span>'}</td>
            <td><button class="btn btn-sm btn-red" onclick="${brief ? 'showPage(\'report-exchange\')' : 'mgrSendExecutiveBrief()'}">${brief ? 'View' : 'Send brief'}</button></td></tr>
          <tr><td>CCBA boards</td><td>Inv: ${mgrBoardBadge(boards.inventory)} · OCCD: ${mgrBoardBadge(boards.occd)}</td>
            <td><button class="btn btn-sm" onclick="showPage('manager-ccba-boards')">Open boards</button></td></tr>
        </table></div>`;
    }
    if (exc) {
      exc.innerHTML = `
        <div class="card-header"><span class="card-title">Exception summary</span>
          <button class="btn btn-sm" onclick="showPage('admin-exceptions')">View all</button></div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:.8rem">
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Low stock</div><div class="metric-value" style="font-size:18px">${d.low_stock_count}</div></div>
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Edit reqs</div><div class="metric-value" style="font-size:18px">${d.pending_edit_requests}</div></div>
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Sales</div><div class="metric-value" style="font-size:18px">${d.pending_orders}</div></div>
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Cash</div><div class="metric-value" style="font-size:18px">${d.cash_pending_confirmation}</div></div>
        </div>`;
    }
    const editMetric = document.querySelector('#page-manager-dashboard .metric-card:nth-child(4) .metric-value');
    if (editMetric) editMetric.textContent = d.pending_edit_requests;
  } catch (e) {
    console.warn('Manager extras:', e.message);
  }
}

function mgrStatusBadge(s) {
  return { sent: '<span class="badge bw">New</span>', read: '<span class="badge bi">Read</span>', acknowledged: '<span class="badge bs">Done</span>' }[s] || `<span class="badge bg">${s}</span>`;
}

function mgrBoardBadge(s) {
  if (!s) return '<span class="badge bg">Draft</span>';
  return s === 'submitted' ? '<span class="badge bs">Submitted</span>' : '<span class="badge bw">Draft</span>';
}

async function loadExceptionsPage() {
  const metrics = document.getElementById('excMetrics');
  const tbody = document.querySelector('#page-admin-exceptions #excTableBody');
  if (!metrics && !tbody) return;
  try {
    const d = await LapokAPI.get('/api/exceptions/fetch.php');
    const s = d.summary || {};
    if (metrics) {
      metrics.innerHTML = `
        <div class="metric-card"><div class="metric-label">Low stock</div><div class="metric-value">${s.stock || 0}</div></div>
        <div class="metric-card"><div class="metric-label">Cash variances</div><div class="metric-value">${s.cash || 0}</div></div>
        <div class="metric-card"><div class="metric-label">Edit requests</div><div class="metric-value">${s.edit_request || 0}</div></div>
        <div class="metric-card"><div class="metric-label">Pending sales</div><div class="metric-value">${s.sale || 0}</div></div>`;
    }
    if (tbody) {
      const typeLabel = { stock: 'Stock', cash: 'Cash', edit_request: 'Edit request', sale: 'Sale' };
      const sev = (x) => x === 'high' ? 'bd' : 'bw';
      tbody.innerHTML = (d.items || []).map((i) => `<tr>
        <td>${typeLabel[i.type] || i.type}</td>
        <td>${escMgr(i.reference)}</td>
        <td><span class="badge ${sev(i.severity)}">${i.severity}</span></td>
        <td>${escMgr(i.owner)}</td>
        <td><span class="badge bg">${i.status}</span></td>
        <td style="font-size:11px;color:var(--gray-mid)">${escMgr(i.detail)}</td>
        <td>${i.type === 'sale' ? '<button class="btn btn-sm" onclick="showPage(\'manager-dashboard\')">Confirm</button>' : ''}
        ${i.type === 'edit_request' ? '<button class="btn btn-sm" onclick="showPage(\'admin-editreqs\')">Review</button>' : ''}
        ${i.type === 'stock' ? '<button class="btn btn-sm" onclick="showPage(\'manager-ccba-order\')">Order</button>' : ''}</td>
      </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--gray-mid)">No open exceptions</td></tr>';
    }
  } catch (e) {
    console.warn('Exceptions:', e.message);
  }
}

async function loadDeliveryList() {
  const el = document.getElementById('deliveryList');
  if (!el) return;
  try {
    const d = await LapokAPI.get('/api/stock/fetch_deliveries.php');
    const list = d.deliveries || [];
    if (!list.length) {
      el.innerHTML = '<p style="padding:1rem;color:var(--gray-mid)">No deliveries recorded today.</p>';
      return;
    }
    el.innerHTML = list.map((del) => {
      const items = (del.items || []).map((i) => {
        const v = i.qty_delivered - i.qty_ordered;
        return `<tr><td>${escMgr(i.product_name)}</td><td>${i.qty_ordered}</td><td>${i.qty_delivered}</td>
          <td><span class="${v < 0 ? 'deficit' : 'surplus'}">${v}</span></td></tr>`;
      }).join('');
      const ccba = del.ccba_lapok_ref ? `<div style="font-size:11px;color:var(--gray-mid)">CCBA order: ${escMgr(del.ccba_lapok_ref)} ${del.ccba_order_no ? '· ' + escMgr(del.ccba_order_no) : ''}</div>` : '';
      return `<div class="delivery-card">
        <div class="delivery-header">
          <div><strong>Delivery — ${LapokAPI.formatDate(del.delivery_date)}</strong>
            <div style="font-size:12px;color:var(--gray-mid)">Waybill: ${escMgr(del.waybill || '—')} · ${escMgr(del.truck_plate || '')}</div>
            ${ccba}
            <div style="font-size:12px;color:var(--gray-mid)">Received by: ${escMgr(del.received_by_name || '—')}</div></div>
          <span class="badge bs">Recorded</span>
        </div>
        <div class="tbl-wrap"><table style="min-width:300px"><tr><th>Product</th><th>Ordered</th><th>Delivered</th><th>Variance</th></tr>${items}</table></div>
        ${del.notes ? `<div style="font-size:12px;color:var(--gray-mid);margin-top:8px">${escMgr(del.notes)}</div>` : ''}
      </div>`;
    }).join('');
  } catch (e) {
    el.innerHTML = `<p style="color:var(--red)">${escMgr(e.message)}</p>`;
  }
}

async function loadDispatchLog() {
  const table = document.getElementById('dispatchLogTable');
  if (!table) return;
  try {
    const d = await LapokAPI.get('/api/trips/dispatch_log.php');
    const trips = d.trips || [];
    table.innerHTML = '<tr><th>Vehicle</th><th>Type</th><th>Crew</th><th>Departed</th><th>Load</th><th>Route</th><th>Returned</th><th>Status</th></tr>' +
      trips.map((t) => {
        const crew = [t.driver_name, t.cadet_name].filter(Boolean).join(' / ') || '—';
        const badge = t.vehicle_type === 'truck' ? 'b-truck' : 'b-tuk';
        const st = t.status === 'on_route' || t.status === 'dispatched' ? 'bs' : 'bg';
        return `<tr><td>${escMgr(t.registration)}</td><td><span class="badge ${badge}">${t.vehicle_type}</span></td>
          <td>${escMgr(crew)}</td><td>${t.dispatched_at ? LapokAPI.formatTime(t.dispatched_at) : '—'}</td>
          <td>${t.load_qty || 0}</td><td>${escMgr(t.route_area || '—')}</td>
          <td>${t.returned_at ? LapokAPI.formatTime(t.returned_at) : '—'}</td>
          <td><span class="badge ${st}">${t.status}</span></td></tr>`;
      }).join('') || '<tr><td colspan="8" style="text-align:center;color:var(--gray-mid)">No dispatches today</td></tr>';
  } catch (e) {
    console.warn('Dispatch log:', e.message);
  }
}

async function prepareDispatchModal() {
  const modal = document.getElementById('dispatchModal');
  if (!modal) return;
  try {
    const [vehicles, routes, users, stock] = await Promise.all([
      LapokAPI.get('/api/vehicles/fetch_vehicles.php'),
      LapokAPI.get('/api/routes/fetch_routes.php'),
      LapokAPI.get('/api/users/fetch_users.php'),
      LapokAPI.get('/api/stock/fetch_stock.php'),
    ]);
    mgrDispatchData.vehicles = vehicles.vehicles || [];
    mgrDispatchData.routes = routes.routes || [];
    mgrDispatchData.users = users.users || [];
    mgrDispatchData.products = stock.stock || [];

    const vSel = document.getElementById('dispatchVehicle');
    if (vSel) {
      vSel.innerHTML = mgrDispatchData.vehicles.map((v) => {
        const icon = v.vehicle_type === 'truck' ? '🚛' : '🛺';
        const crew = v.driver_name || v.cadet_name || 'Unassigned';
        return `<option value="${v.id}" data-driver="${v.driver_id || ''}" data-cadet="${v.cadet_id || ''}" data-route="${escMgr(v.current_route || '')}">${icon} ${v.registration} — ${crew}</option>`;
      }).join('');
    }
    const rSel = document.getElementById('dispatchRoute');
    if (rSel) {
      rSel.innerHTML = '<option value="">— Select route —</option>' +
        mgrDispatchData.routes.map((r) => `<option value="${r.id}">${escMgr(r.name)}</option>`).join('');
    }
    const dSel = document.getElementById('dispatchDriver');
    const cSel = document.getElementById('dispatchCadet');
    const fieldUsers = mgrDispatchData.users.filter((u) => ['driver', 'cadet', 'field_user'].includes(u.role) && u.is_active);
    if (dSel) dSel.innerHTML = '<option value="">—</option>' + fieldUsers.filter((u) => u.role === 'driver').map((u) => `<option value="${u.id}">${escMgr(u.full_name)}</option>`).join('');
    if (cSel) cSel.innerHTML = '<option value="">—</option>' + fieldUsers.filter((u) => ['cadet', 'field_user'].includes(u.role)).map((u) => `<option value="${u.id}">${escMgr(u.full_name)}</option>`).join('');

    const tbody = document.getElementById('dispatchLoadBody');
    if (tbody) {
      tbody.innerHTML = mgrDispatchData.products.map((p) =>
        `<tr data-product-id="${p.product_id}"><td>${escMgr(p.name)}</td><td>${p.warehouse_qty}</td>
        <td><input class="qty-inp dispatch-qty" type="number" min="0" value="0" data-product-id="${p.product_id}"></td></tr>`
      ).join('');
    }
    if (vSel) vSel.onchange = () => {
      const opt = vSel.selectedOptions[0];
      if (!opt) return;
      if (dSel && opt.dataset.driver) dSel.value = opt.dataset.driver;
      if (cSel && opt.dataset.cadet) cSel.value = opt.dataset.cadet;
      const area = document.getElementById('dispatchRouteArea');
      if (area && opt.dataset.route) area.value = opt.dataset.route;
    };
  } catch (e) {
    alert(e.message);
  }
}

async function saveDispatch() {
  const vehicleId = parseInt(document.getElementById('dispatchVehicle')?.value || '0', 10);
  const driverId = parseInt(document.getElementById('dispatchDriver')?.value || '0', 10) || null;
  const cadetId = parseInt(document.getElementById('dispatchCadet')?.value || '0', 10) || null;
  const routeId = parseInt(document.getElementById('dispatchRoute')?.value || '0', 10) || null;
  const routeArea = document.getElementById('dispatchRouteArea')?.value.trim() || '';
  const loadItems = [];
  document.querySelectorAll('.dispatch-qty').forEach((inp) => {
    const qty = parseInt(inp.value || '0', 10);
    if (qty > 0) loadItems.push({ product_id: parseInt(inp.dataset.productId, 10), qty });
  });
  if (!vehicleId || !loadItems.length) {
    alert('Select a vehicle and enter load quantities.');
    return;
  }
  try {
    await LapokAPI.post('/api/vehicles/dispatch.php', {
      vehicle_id: vehicleId, driver_id: driverId, cadet_id: cadetId,
      route_id: routeId, route_area: routeArea, load_items: loadItems,
    });
    closeModal('dispatchModal');
    alert('Vehicle dispatched. Load deducted from warehouse.');
    await Promise.allSettled([loadDispatchLog(), loadStockTable()]);
  } catch (e) {
    alert(e.message);
  }
}

async function prepareIncomingModal() {
  const modal = document.getElementById('incomingModal');
  if (!modal) return;
  const dateInp = modal.querySelector('#incomingDate');
  if (dateInp) dateInp.value = new Date().toISOString().slice(0, 10);
  try {
    if (!productCatalog.length) {
      const stock = await LapokAPI.get('/api/stock/fetch_stock.php');
      productCatalog = stock.stock || [];
    }
    const orders = await LapokAPI.get('/api/ccba/orders/fetch.php?limit=20');
    const sel = document.getElementById('incomingCcbaOrder');
    if (sel) {
      const open = (orders.orders || []).filter((o) => !['closed', 'cancelled'].includes(o.status));
      sel.innerHTML = '<option value="">— None / not linked —</option>' +
        open.map((o) => `<option value="${o.id}">${escMgr(o.lapok_ref)} · ${o.status}${o.ccba_order_no ? ' · ' + o.ccba_order_no : ''}</option>`).join('');
    }
    const tbody = document.getElementById('incomingProductBody');
    if (tbody) {
      tbody.innerHTML = productCatalog.map((p) =>
        `<tr data-product-id="${p.product_id}"><td>${escMgr(p.name)}</td>
        <td><input class="qty-inp" type="number" min="0" value="0" data-f="ordered"></td>
        <td><input class="qty-inp" type="number" min="0" value="0" data-f="delivered"></td>
        <td><input class="qty-inp" type="text" placeholder="${p.sku}-batch" style="width:100px" data-f="batch"></td>
        <td><input class="input" type="date" style="min-height:36px;padding:4px" data-f="expiry"></td>
        <td><input class="qty-inp" type="number" value="${Math.round((p.unit_price || 0) * 0.6)}" data-f="cost"></td></tr>`
      ).join('');
    }
    const recv = document.getElementById('incomingReceivedBy');
    if (recv && currentUser) recv.value = currentUser.full_name;
  } catch (e) {
    console.warn('Incoming modal:', e.message);
  }
}

async function saveDeliveryEnhanced() {
  const modal = document.getElementById('incomingModal');
  if (!modal) return;
  const items = [];
  modal.querySelectorAll('#incomingProductBody tr').forEach((tr) => {
    const productId = parseInt(tr.dataset.productId || '0', 10);
    const p = productCatalog.find((x) => x.product_id === productId);
    if (!p) return;
    const inputs = tr.querySelectorAll('input');
    const qtyDelivered = parseInt(inputs[1]?.value || '0', 10);
    if (qtyDelivered <= 0) return;
    items.push({
      product_id: p.product_id,
      qty_ordered: parseInt(inputs[0]?.value || '0', 10) || qtyDelivered,
      qty_delivered: qtyDelivered,
      batch_number: inputs[2]?.value || `BATCH-${p.sku}-${Date.now()}`,
      expiry_date: inputs[3]?.value || new Date(Date.now() + 180 * 86400000).toISOString().slice(0, 10),
      unit_cost: parseFloat(inputs[4]?.value || '0'),
    });
  });
  if (!items.length) {
    alert('Enter delivered quantities');
    return;
  }
  const ccbaId = parseInt(document.getElementById('incomingCcbaOrder')?.value || '0', 10) || undefined;
  try {
    await LapokAPI.post('/api/stock/receive_delivery.php', {
      delivery_date: document.getElementById('incomingDate')?.value || new Date().toISOString().slice(0, 10),
      delivery_time: document.getElementById('incomingTime')?.value || null,
      waybill: document.getElementById('incomingWaybill')?.value || '',
      invoice_number: document.getElementById('incomingInvoice')?.value || '',
      truck_plate: document.getElementById('incomingTruck')?.value || '',
      driver_name: document.getElementById('incomingDriver')?.value || '',
      notes: document.getElementById('incomingNotes')?.value || '',
      ccba_order_id: ccbaId,
      items,
    });
    closeModal('incomingModal');
    await Promise.allSettled([loadStockTable(), loadDeliveryList()]);
    alert('Delivery recorded and linked to stock.');
  } catch (e) {
    alert(e.message);
  }
}

async function loadCcbaProductMap() {
  const tbody = document.getElementById('ccbaMapBody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="4">Loading…</td></tr>';
  try {
    const d = await LapokAPI.get('/api/ccba/product_map/fetch.php');
    tbody.innerHTML = (d.mappings || []).map((m) => `<tr>
      <td>${escMgr(m.name)}<div style="font-size:10px;color:var(--gray-mid)">${escMgr(m.sku)}</div></td>
      <td><input class="input ccba-map-sku" data-product-id="${m.product_id}" value="${escMgr(m.ccba_sku_code || '')}" placeholder="CCBA catalog code"></td>
      <td><input class="input ccba-map-desc" data-product-id="${m.product_id}" value="${escMgr(m.ccba_pack_desc || '')}" placeholder="Pack description"></td>
      <td><button class="btn btn-sm btn-red" onclick="saveCcbaMapRow(${m.product_id})">Save</button></td>
    </tr>`).join('');
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="4" style="color:var(--red)">${escMgr(e.message)}</td></tr>`;
  }
}

async function saveCcbaMapRow(productId) {
  const sku = document.querySelector(`.ccba-map-sku[data-product-id="${productId}"]`)?.value.trim() || '';
  const desc = document.querySelector(`.ccba-map-desc[data-product-id="${productId}"]`)?.value.trim() || '';
  try {
    await LapokAPI.post('/api/ccba/product_map/save.php', { product_id: productId, ccba_sku_code: sku, ccba_pack_desc: desc });
    alert('SKU mapping saved.');
  } catch (e) {
    alert(e.message);
  }
}

async function runCcbaStockSync() {
  try {
    const d = await LapokAPI.post('/api/ccba/stock_sync/snapshot.php', { snapshot_date: new Date().toISOString().slice(0, 10) });
    alert(d.message || `Snapshot saved for ${d.products} products.`);
  } catch (e) {
    alert(e.message);
  }
}

async function mgrSendExecutiveBrief() {
  if (!confirm('Generate today\'s executive brief PDF from live Lapok data and send to Executive?')) return;
  try {
    await LapokAPI.post('/api/reports/generate_pack.php', {
      report_date: new Date().toISOString().slice(0, 10),
    });
    alert('Executive brief generated and sent.');
    await loadManagerDashboardExtras();
  } catch (e) {
    alert(e.message);
  }
}

function escMgr(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

// Override global saveDelivery when manager-ops loaded
window.saveDelivery = saveDeliveryEnhanced;

const _openModal = window.openModal;
window.openModal = function (id) {
  if (typeof _openModal === 'function') _openModal(id);
  else document.getElementById(id)?.classList.add('open');
  if (id === 'dispatchModal') prepareDispatchModal();
  if (id === 'incomingModal') prepareIncomingModal();
};
