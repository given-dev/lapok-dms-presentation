/**
 * Lapok DMS &mdash; Manager operations polish (dashboard, stock, dispatch, exceptions)
 */
let mgrDispatchData = { vehicles: [], routes: [], users: [], products: [] };
const mgrDispatchCache = { loadedAt: 0, ttlMs: 120000 };

function mgrTodayLocal() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function mgrDatePlusDays(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function mgrEscapeAttr(s) {
  return escMgr(s).replace(/'/g, '&#39;').replace(/>/g, '&gt;');
}

function mgrNotify(message, type = 'info') {
  const text = String(message || 'Done');
  let host = document.getElementById('mgrToastHost');
  if (!host) {
    host = document.createElement('div');
    host.id = 'mgrToastHost';
    host.style.position = 'fixed';
    host.style.right = '14px';
    host.style.bottom = '14px';
    host.style.display = 'grid';
    host.style.gap = '8px';
    host.style.zIndex = '9999';
    document.body.appendChild(host);
  }
  const toast = document.createElement('div');
  toast.style.padding = '10px 12px';
  toast.style.borderRadius = '8px';
  toast.style.color = '#fff';
  toast.style.fontSize = '12px';
  toast.style.maxWidth = '320px';
  toast.style.boxShadow = '0 8px 20px rgba(0,0,0,.18)';
  toast.style.background = type === 'error' ? '#B91C1C' : (type === 'success' ? '#065F46' : '#111827');
  toast.textContent = text;
  host.appendChild(toast);
  setTimeout(() => toast.remove(), 3200);
}

function mgrSetBusy(button, busyText) {
  if (!button) return () => {};
  const prevText = button.textContent;
  const prevDisabled = button.disabled;
  button.disabled = true;
  if (busyText) button.textContent = busyText;
  return () => {
    button.disabled = prevDisabled;
    button.textContent = prevText;
  };
}

async function loadManagerDashboardExtras() {
  if (!currentUser || !['admin', 'manager'].includes(currentUser.role)) return;
  const handoff = document.getElementById('mgrHandoffCard');
  const exc = document.getElementById('mgrExceptionsCard');
  const stockCard = document.getElementById('mgrStockTakingCard');
  if (!handoff && !exc && !stockCard) return;
  try {
    const d = await LapokAPI.get('/api/dashboard/manager.php');
    const openEl = document.getElementById('mgrDashOpeningStatus');
    const closeEl = document.getElementById('mgrDashClosingStatus');
    if (openEl) {
      openEl.textContent = d.opening_stock_done
        ? ('Done' + (d.opening_stock_at ? ' &middot; ' + LapokAPI.formatTime(d.opening_stock_at) : ''))
        : 'Not done &mdash; enter opening stock first';
      openEl.style.color = d.opening_stock_done ? 'var(--green, #166534)' : 'var(--red, #B91C1C)';
    }
    if (closeEl) {
      if (d.closing_stock_done) {
        closeEl.textContent = 'Done' + (d.closing_stock_at ? ' &middot; ' + LapokAPI.formatTime(d.closing_stock_at) : '');
        closeEl.style.color = 'var(--green, #166534)';
      } else if (typeof isClosingStockWindowOpen === 'function' && !isClosingStockWindowOpen()) {
        closeEl.textContent = 'Locked until 6:30 PM';
        closeEl.style.color = 'var(--gray-mid)';
      } else {
        closeEl.textContent = 'Open now &mdash; save by 7:00 PM';
        closeEl.style.color = 'var(--red, #B91C1C)';
      }
    }
    if (stockCard && !d.opening_stock_done) {
      stockCard.style.borderColor = 'rgba(229,62,62,.55)';
    } else if (stockCard) {
      stockCard.style.borderColor = 'rgba(22,101,52,.35)';
    }
    if (handoff) {
      const pack = d.accountant_pack;
      const brief = d.executive_brief_today;
      const boards = d.boards_today || {};
      const invSt = boards.inventory || 'not started';
      const occdSt = boards.occd || 'not started';
      const rdcPending = Number(d.rdc_pending_review || 0);
      const rdcToday = d.rdc_sheet_today;
      const closingOpen = typeof isClosingStockWindowOpen === 'function' && isClosingStockWindowOpen();

      const boardBadge = (st) => {
        if (st === 'submitted') return '<span class="badge bs">Submitted</span>';
        if (st === 'draft') return '<span class="badge bw">Draft</span>';
        return '<span class="badge bg">Not started</span>';
      };
      const doneBadge = (ok, labelOk, labelNo) => ok
        ? `<span class="badge bs">${labelOk}</span>`
        : `<span class="badge bw">${labelNo}</span>`;
      const rdcBadgeHtml = (() => {
        if (!rdcToday) return '<span class="badge bg">No sheet today</span>';
        const s = String(rdcToday.status || '');
        if (s === 'approved') return '<span class="badge bs">Approved</span>';
        if (s === 'rejected') return '<span class="badge bd">Rejected</span>';
        if (s === 'submitted') return '<span class="badge bw">Submitted</span>';
        if (s === 'under_review') return '<span class="badge bg">Under review</span>';
        if (s === 'reopened') return '<span class="badge bi">Reopened</span>';
        return `<span class="badge bw">${s.replace('_', ' ') || 'Draft'}</span>`;
      })();

      const closingStatus = d.closing_stock_done
        ? doneBadge(true, 'Done', '')
        : (closingOpen ? '<span class="badge bd">Due now</span>' : '<span class="badge bg">After 6:30 PM</span>');

      handoff.innerHTML = `
        <div class="card-header">
          <span class="card-title">Daily checklist (in order)</span>
          <span class="badge ${rdcPending ? 'bw' : 'bs'}">${rdcPending} RDC pending</span>
        </div>
        <p style="font-size:12px;color:var(--gray-mid);margin:0 0 .8rem">Walk the day top to bottom. RDC pending = sheets waiting for your review (any date).</p>
        <div class="tbl-wrap"><table>
          <tr><th>#</th><th>Step</th><th>Status</th><th>Action</th></tr>
          <tr>
            <td>1</td><td>Opening stock (7am)</td>
            <td>${doneBadge(!!d.opening_stock_done, 'Done', 'Not done')}</td>
            <td><button class="btn btn-sm ${d.opening_stock_done ? '' : 'btn-red'}" onclick="showPage('manager-stock')">Stock taking</button></td>
          </tr>
          <tr>
            <td>2</td><td>CCBA boards (inventory + OCCD)</td>
            <td>${boardBadge(invSt)} ${boardBadge(occdSt)}</td>
            <td><button class="btn btn-sm" onclick="showPage('manager-ccba-boards')">Open boards</button></td>
          </tr>
          <tr>
            <td>3</td><td>RDC daily sheet review${rdcPending ? ` <span class="badge bd">${rdcPending}</span>` : ''}</td>
            <td>${rdcBadgeHtml}</td>
            <td><button class="btn btn-sm ${rdcPending ? 'btn-red' : ''}" onclick="showPage('manager-rdc-review')">Review queue</button></td>
          </tr>
          <tr>
            <td>4</td><td>Finance pack from Accountant</td>
            <td>${pack ? mgrStatusBadge(pack.status) : '<span class="badge bw">Awaiting</span>'}</td>
            <td>${pack ? `<button class="btn btn-sm" onclick="reportOpenPdf(${pack.id})">View PDF</button>` : '<button class="btn btn-sm" onclick="showPage(\'report-exchange\')">Open inbox</button>'}</td>
          </tr>
          <tr>
            <td>5</td><td>Closing stock (from 6:30 PM)</td>
            <td>${closingStatus}</td>
            <td><button class="btn btn-sm" onclick="showPage('manager-stock')">Stock taking</button></td>
          </tr>
          <tr>
            <td>6</td><td>Executive brief (before 8 PM)</td>
            <td>${brief ? mgrStatusBadge(brief.status) : '<span class="badge bg">Not sent</span>'}</td>
            <td><button class="btn btn-sm btn-red" onclick="${brief ? 'showPage(\'report-exchange\')' : 'mgrSendExecutiveBrief()'}">${brief ? 'View' : 'Send brief'}</button></td>
          </tr>
          <tr>
            <td>&mdash;</td><td>Cash trips (RDC-owned)</td>
            <td><span class="badge ${d.cash_pending_confirmation ? 'bd' : 'bs'}">${d.cash_pending_confirmation} pending</span></td>
            <td><span style="font-size:11px;color:var(--gray-mid)">Accountant confirms</span></td>
          </tr>
        </table></div>`;
    }
    if (exc) {
      const cadetFlags = d.cadet_report_flags ?? 0;
      const welfareOpen = d.welfare_open_count ?? 0;
      const rdcPending = Number(d.rdc_pending_review || 0);
      exc.innerHTML = `
        <div class="card-header"><span class="card-title">Exception summary</span>
          <button class="btn btn-sm" onclick="showPage('admin-exceptions')">View all</button></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-bottom:.8rem">
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">RDC pending</div><div class="metric-value" style="font-size:18px">${rdcPending}</div></div>
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Low stock</div><div class="metric-value" style="font-size:18px">${d.low_stock_count}</div></div>
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Edit reqs</div><div class="metric-value" style="font-size:18px">${d.pending_edit_requests}</div></div>
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Sales</div><div class="metric-value" style="font-size:18px">${d.pending_orders}</div></div>
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Cash (RDC)</div><div class="metric-value" style="font-size:18px">${d.cash_pending_confirmation}</div></div>
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Cadet flags</div><div class="metric-value" style="font-size:18px">${cadetFlags}</div></div>
          <div class="metric-card" style="padding:.7rem"><div class="metric-label">Welfare</div><div class="metric-value" style="font-size:18px">${welfareOpen}</div></div>
        </div>`;
    }
    const editMetric = document.querySelector('#page-manager-dashboard .metric-card:nth-child(4) .metric-value');
    if (editMetric) editMetric.textContent = d.pending_edit_requests;
  } catch (e) {
    console.warn('Manager extras:', e.message);
    if (handoff) {
      handoff.innerHTML = '<div class="card-header"><span class="card-title">Accountant &amp; executive handoff</span></div>' +
        '<p style="padding:.8rem;color:var(--gray-mid)">Could not load handoff data. <button class="btn btn-sm" onclick="loadManagerDashboardExtras()">Retry</button></p>';
    }
    if (exc) {
      exc.innerHTML = '<div class="card-header"><span class="card-title">Exception summary</span></div>' +
        '<p style="padding:.8rem;color:var(--gray-mid)">Could not load exceptions. <button class="btn btn-sm" onclick="loadManagerDashboardExtras()">Retry</button></p>';
    }
  }
}

function mgrStatusBadge(s) {
  return { sent: '<span class="badge bw">New</span>', read: '<span class="badge bi">Read</span>', acknowledged: '<span class="badge bs">Done</span>' }[s] || `<span class="badge bg">${escMgr(s)}</span>`;
}

async function loadExceptionsPage() {
  const metrics = document.getElementById('excMetrics');
  const tbody = document.querySelector('#page-admin-exceptions #excTableBody');
  const pageAlert = document.querySelector('#page-admin-exceptions > .alert.a-info');
  if (!metrics && !tbody) return;
  const role = (typeof currentUser !== 'undefined' && currentUser?.role) || '';
  if (pageAlert) {
    pageAlert.innerHTML = role === 'executive'
      ? '<span>ℹ</span>Monitor-only radar &mdash; open items are owned by Manager / RDC. Use this page to see what is outstanding, not to resolve it.'
      : '<span>ℹ</span>Live exception queue &mdash; aggregated from stock, cash, cadet flags, welfare, edit requests, and pending sales. Resolve each item in its linked screen.';
  }
  try {
    const d = await LapokAPI.get('/api/exceptions/fetch.php');
    const s = d.summary || {};
    if (metrics) {
      metrics.innerHTML = `
        <div class="metric-card"><div class="metric-label">Low stock</div><div class="metric-value">${s.stock || 0}</div></div>
        <div class="metric-card"><div class="metric-label">Cash pending</div><div class="metric-value">${s.cash || 0}</div></div>
        <div class="metric-card"><div class="metric-label">Cadet flags</div><div class="metric-value">${s.cadet_report || 0}</div></div>
        <div class="metric-card"><div class="metric-label">Welfare open</div><div class="metric-value">${s.welfare || 0}</div></div>
        <div class="metric-card"><div class="metric-label">Edit requests</div><div class="metric-value">${s.edit_request || 0}</div></div>
        <div class="metric-card"><div class="metric-label">Pending sales</div><div class="metric-value">${s.sale || 0}</div></div>`;
    }
    if (tbody) {
      const typeLabel = {
        stock: 'Stock',
        cash: 'Cash',
        edit_request: 'Edit request',
        sale: 'Sale',
        cadet_report: 'Cadet report',
        welfare: 'Welfare',
      };
      const sev = (x) => x === 'high' ? 'bd' : 'bw';
      const actionFor = (i) => {
        const isMgr = role === 'manager';
        const isExec = role === 'executive';
        const isAcc = role === 'accountant';
        // Executive = board/MD monitor only &mdash; no deep-links into manager/RDC ops.
        if (isExec) {
          if (i.type === 'welfare') return '<button class="btn btn-sm" onclick="showPage(\'accountant-welfare\')">View</button>';
          return '<span style="font-size:11px;color:var(--gray-mid)">Monitor only</span>';
        }
        if (i.type === 'sale') {
          if (isAcc) return '<span style="font-size:11px;color:var(--gray-mid)">Manager confirms</span>';
          return '<button class="btn btn-sm" onclick="showPage(\'manager-dashboard\')">Confirm</button>';
        }
        if (i.type === 'edit_request') {
          if (isAcc) return '<span style="font-size:11px;color:var(--gray-mid)">Manager reviews</span>';
          return '<button class="btn btn-sm" onclick="showPage(\'admin-editreqs\')">Review</button>';
        }
        if (i.type === 'stock') {
          if (isAcc) return '<span style="font-size:11px;color:var(--gray-mid)">Manager owns stock</span>';
          return '<button class="btn btn-sm" onclick="showPage(\'manager-stock\')">Stock</button>';
        }
        if (i.type === 'cash') {
          // Cash confirmation is accountant-owned &mdash; managers only monitor.
          if (isMgr) return '<span style="font-size:11px;color:var(--gray-mid)">RDC confirms</span>';
          return '<button class="btn btn-sm" onclick="showPage(\'accountant-cash\')">Confirm cash</button>';
        }
        if (i.type === 'cadet_report') {
          if (isMgr) return '<button class="btn btn-sm" onclick="showPage(\'manager-rdc-review\')">RDC review</button>';
          if (isAcc) return '<button class="btn btn-sm" onclick="showPage(\'accountant-rdc\')">Today\'s close</button>';
          return '<span style="font-size:11px;color:var(--gray-mid)">RDC / Manager</span>';
        }
        if (i.type === 'welfare') return '<button class="btn btn-sm" onclick="showPage(\'accountant-welfare\')">Welfare</button>';
        return '';
      };
      tbody.innerHTML = (d.items || []).map((i) => `<tr>
        <td>${escMgr(typeLabel[i.type] || i.type)}</td>
        <td>${escMgr(i.reference)}</td>
        <td><span class="badge ${sev(i.severity)}">${escMgr(i.severity)}</span></td>
        <td>${escMgr(i.owner)}</td>
        <td><span class="badge bg">${escMgr(i.status)}</span></td>
        <td style="font-size:11px;color:var(--gray-mid)">${escMgr(i.detail)}</td>
        <td>${actionFor(i)}</td>
      </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--gray-mid)">No open exceptions</td></tr>';
    }
  } catch (e) {
    console.warn('Exceptions:', e.message);
    if (tbody) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--gray-mid)">Could not load exceptions.</td></tr>';
    }
  }
}

function mgrDeliveryStatusMeta(status) {
  const s = String(status || 'pending_confirm');
  if (s === 'confirmed') return { label: 'Confirmed', badge: 'bs' };
  if (s === 'rejected') return { label: 'Rejected', badge: 'bd' };
  return { label: 'Awaiting confirm', badge: 'bw' };
}

function mgrCanConfirmDeliveries() {
  const role = (typeof currentUser !== 'undefined' && currentUser?.role) || '';
  return role === 'manager' || role === 'admin';
}

async function confirmSupplierDelivery(deliveryId, action) {
  const id = Number(deliveryId || 0);
  if (!id) return;
  if (!mgrCanConfirmDeliveries()) {
    mgrNotify('Only the manager can confirm deliveries', 'error');
    return;
  }
  const verb = action === 'reject' ? 'reject' : 'confirm';
  if (!window.confirm(action === 'reject'
    ? 'Reject this delivery confirmation? Stock already received stays &mdash; note the variance with RDC.'
    : 'Confirm this Coca-Cola delivery matches the waybill?')) {
    return;
  }
  let note = '';
  if (action === 'reject') {
    note = window.prompt('Optional note for RDC / audit:', '') || '';
  }
  try {
    await LapokAPI.post('/api/stock/confirm_delivery.php', {
      delivery_id: id,
      action: verb === 'reject' ? 'reject' : 'confirm',
      note,
    });
    mgrNotify(verb === 'reject' ? 'Delivery marked rejected' : 'Delivery confirmed', 'success');
    await loadDeliveryList();
  } catch (e) {
    mgrNotify(e.message || 'Could not update delivery', 'error');
  }
}

function renderDeliveryConfirmActions(del) {
  if (!mgrCanConfirmDeliveries()) return '';
  const status = String(del.confirm_status || 'pending_confirm');
  if (status !== 'pending_confirm') return '';
  return `<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
    <button class="btn btn-sm btn-red" type="button" onclick="confirmSupplierDelivery(${Number(del.id)}, 'confirm')">Confirm delivery</button>
    <button class="btn btn-sm" type="button" onclick="confirmSupplierDelivery(${Number(del.id)}, 'reject')">Reject</button>
  </div>`;
}

function paintRdcHubDeliveryStrip(pending, total) {
  const strip = document.getElementById('rdcHubDeliveryConfirmStrip');
  const msg = document.getElementById('rdcHubDeliveryConfirmMsg');
  if (!strip || !msg) return;
  strip.style.display = '';
  if (!total) {
    msg.textContent = 'No Coca-Cola deliveries recorded today. Manager confirms deliveries on Stock management when they arrive.';
    return;
  }
  if (pending > 0) {
    const cta = mgrCanConfirmDeliveries()
      ? ` <button class="btn btn-sm" type="button" style="margin-left:6px" onclick="showPage('manager-stock')">Confirm deliveries</button>`
      : ' Ask the manager to confirm on <strong>Stock management</strong>.';
    msg.innerHTML = `<strong>${pending}</strong> delivery(ies) still need manager confirmation.${cta}`;
  } else {
    msg.textContent = `All ${total} delivery(ies) today are confirmed by the manager.`;
  }
}

function paintMgrDeliveryConfirmPanel(list, pending) {
  const chip = document.getElementById('mgrDeliveryConfirmChip');
  const host = document.getElementById('mgrDeliveryConfirmList');
  if (chip) {
    chip.textContent = pending > 0 ? `${pending} pending` : (list.length ? 'All confirmed' : 'No deliveries');
  }
  if (!host) return;
  if (!list.length) {
    host.innerHTML = '<p style="color:var(--gray-mid);margin:0">No deliveries today yet. Record a delivery first, then confirm here before 7pm close.</p>';
    return;
  }
  const pendingRows = list.filter((d) => String(d.confirm_status || 'pending_confirm') === 'pending_confirm');
  if (!pendingRows.length) {
    host.innerHTML = '<p style="color:var(--gray-mid);margin:0">All of today’s deliveries are confirmed. Enter opening/closing stock on this page when due.</p>';
    return;
  }
  host.innerHTML = pendingRows.map((del) => {
    const lines = (del.items || []).map((i) => escMgr(i.product_name) + ' × ' + i.qty_delivered).join(', ');
    return `<div class="delivery-card" style="margin-bottom:10px">
      <div class="delivery-header">
        <div>
          <strong>Waybill ${escMgr(del.waybill || '#' + del.id)}</strong>
          <div style="font-size:12px;color:var(--gray-mid)">${escMgr(del.truck_plate || '&mdash;')} &middot; ${escMgr(del.received_by_name || '&mdash;')}</div>
          <div style="font-size:12px;color:var(--gray-mid);margin-top:4px">${lines || 'No lines'}</div>
        </div>
        <span class="badge bw">Awaiting confirm</span>
      </div>
      ${renderDeliveryConfirmActions(del)}
    </div>`;
  }).join('');
}

async function loadDeliveryList() {
  const el = document.getElementById('deliveryList');
  try {
    const d = await LapokAPI.get('/api/stock/fetch_deliveries.php');
    const list = d.deliveries || [];
    const pending = Number(d.pending_confirm || 0);
    paintMgrDeliveryConfirmPanel(list, pending);
    paintRdcHubDeliveryStrip(pending, list.length);
    if (!el) return;
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
      const meta = mgrDeliveryStatusMeta(del.confirm_status);
      const confirmedLine = del.confirmed_by_name
        ? `<div style="font-size:12px;color:var(--gray-mid)">Confirmed by: ${escMgr(del.confirmed_by_name)}${del.confirmed_at ? ' &middot; ' + escMgr(LapokAPI.formatTime(del.confirmed_at)) : ''}</div>`
        : '';
      const ccba = del.ccba_lapok_ref ? `<div style="font-size:11px;color:var(--gray-mid)">CCBA order: ${escMgr(del.ccba_lapok_ref)} ${del.ccba_order_no ? '&middot; ' + escMgr(del.ccba_order_no) : ''}</div>` : '';
      return `<div class="delivery-card">
        <div class="delivery-header">
          <div><strong>Delivery &mdash; ${LapokAPI.formatDate(del.delivery_date)}</strong>
            <div style="font-size:12px;color:var(--gray-mid)">Waybill: ${escMgr(del.waybill || '&mdash;')} &middot; ${escMgr(del.truck_plate || '')}</div>
            ${ccba}
            <div style="font-size:12px;color:var(--gray-mid)">Received by: ${escMgr(del.received_by_name || '&mdash;')}</div>
            ${confirmedLine}
            ${del.confirm_note ? `<div style="font-size:12px;color:var(--gray-mid)">Note: ${escMgr(del.confirm_note)}</div>` : ''}
          </div>
          <span class="badge ${meta.badge}">${meta.label}</span>
        </div>
        <div class="tbl-wrap"><table style="min-width:300px"><tr><th>Product</th><th>Ordered</th><th>Delivered</th><th>Variance</th></tr>${items}</table></div>
        ${del.notes ? `<div style="font-size:12px;color:var(--gray-mid);margin-top:8px">${escMgr(del.notes)}</div>` : ''}
        ${renderDeliveryConfirmActions(del)}
      </div>`;
    }).join('');
  } catch (e) {
    if (el) el.innerHTML = `<p style="color:var(--red)">${escMgr(e.message)}</p>`;
    const host = document.getElementById('mgrDeliveryConfirmList');
    if (host) host.innerHTML = `<p style="color:var(--red)">${escMgr(e.message)}</p>`;
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
        const crew = [t.driver_name, t.cadet_name].filter(Boolean).join(' / ') || '&mdash;';
        const badge = t.vehicle_type === 'truck' ? 'b-truck' : 'b-tuk';
        const st = t.status === 'on_route' || t.status === 'dispatched' ? 'bs' : 'bg';
        return `<tr><td>${escMgr(t.registration)}</td><td><span class="badge ${badge}">${t.vehicle_type}</span></td>
          <td>${escMgr(crew)}</td><td>${t.dispatched_at ? LapokAPI.formatTime(t.dispatched_at) : '&mdash;'}</td>
          <td>${t.load_qty || 0}</td><td>${escMgr(t.route_area || '&mdash;')}</td>
          <td>${t.returned_at ? LapokAPI.formatTime(t.returned_at) : '&mdash;'}</td>
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
    const stale = !mgrDispatchCache.loadedAt || (Date.now() - mgrDispatchCache.loadedAt > mgrDispatchCache.ttlMs);
    if (stale || !mgrDispatchData.vehicles.length || !mgrDispatchData.users.length || !mgrDispatchData.products.length) {
      const [vehicles, assignments, stock] = await Promise.all([
        LapokAPI.get('/api/vehicles/fetch_vehicles.php'),
        LapokAPI.get('/api/assignments/fetch.php'),
        LapokAPI.get('/api/stock/fetch_stock.php'),
      ]);
      mgrDispatchData.vehicles = vehicles.vehicles || [];
      mgrDispatchData.routes = assignments.assignments || [];
      mgrDispatchData.users = assignments.cadets || [];
      mgrDispatchData.products = stock.stock || [];
      mgrDispatchCache.loadedAt = Date.now();
    }

    const vSel = document.getElementById('dispatchVehicle');
    if (vSel) {
      if (!mgrDispatchData.vehicles.length) {
        vSel.innerHTML = '<option value="">No vehicles available</option>';
      } else {
        vSel.innerHTML = mgrDispatchData.vehicles.map((v) => {
          const icon = v.vehicle_type === 'truck' ? '[TRUCK]' : '[TUK]';
          const today = mgrDispatchData.routes.find((a) => Number(a.vehicle_id) === Number(v.id) && Number(a.day_of_week) === Number(new Date().getDay()));
          const crew = today?.cadet_name || 'Unassigned';
          return `<option value="${v.id}" data-driver="${v.driver_id || ''}" data-cadet="${today?.cadet_id || ''}" data-cadet-name="${mgrEscapeAttr(today?.cadet_name || 'Unassigned')}" data-route="${mgrEscapeAttr(today?.route_area || '')}">${icon} ${escMgr(v.registration)} - ${escMgr(crew)}</option>`;
        }).join('');
      }
    }
    const dSel = document.getElementById('dispatchDriver');
    const cSel = document.getElementById('dispatchCadet');
    if (dSel) dSel.closest('.form-group')?.style && (dSel.closest('.form-group').style.display = 'none');


    const tbody = document.getElementById('dispatchLoadBody');
    if (tbody) {
      const cats = ['300ML RGB', '330ML', 'ENERGY', '500ML', '1 LITRE', 'JUICE', '2 LITRE', 'RWENZORI WATER', 'EMPTIES'];
      const byCat = {};
      mgrDispatchData.products.forEach((p) => {
        const cat = p.category || p.brand || 'OTHER';
        if (!byCat[cat]) byCat[cat] = [];
        byCat[cat].push(p);
      });
      let html = '';
      const paint = (cat, list) => {
        if (!list?.length) return;
        html += `<tr class="rdc-cat-row"><td colspan="3"><strong>${escMgr(cat)}</strong></td></tr>`;
        list.forEach((p) => {
          html += `<tr data-product-id="${p.product_id}"><td>${escMgr(p.name)} <span style="color:var(--gray-mid);font-size:11px">${escMgr(p.sku || '')}</span></td><td>${p.warehouse_qty}</td>
          <td><input class="qty-inp dispatch-qty" type="number" min="0" value="0" data-product-id="${p.product_id}"></td></tr>`;
        });
      };
      cats.forEach((cat) => paint(cat, byCat[cat]));
      Object.keys(byCat).forEach((cat) => {
        if (cats.includes(cat)) return;
        paint(cat, byCat[cat]);
      });
      tbody.innerHTML = html || '<tr><td colspan="3" style="color:var(--gray-mid)">No warehouse products. Refresh the page.</td></tr>';
    }
    if (vSel) vSel.onchange = () => {
      const opt = vSel.selectedOptions[0];
      if (!opt) return;
      if (cSel) cSel.value = opt.dataset.cadet || '';
      const cadetName = document.getElementById('dispatchCadetName');
      if (cadetName) cadetName.value = opt.dataset.cadetName || 'Unassigned';
      const area = document.getElementById('dispatchRouteArea');
      if (area) area.value = opt.dataset.route || '';
    };
    if (vSel) vSel.onchange();
  } catch (e) {
    const vSel = document.getElementById('dispatchVehicle');
    if (vSel) vSel.innerHTML = '<option value="">Could not load vehicles</option>';
    const tbody = document.getElementById('dispatchLoadBody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="3" style="color:var(--red)">${escMgr(e.message || 'Load failed')}</td></tr>`;
    mgrNotify(e.message, 'error');
  }
}

async function saveDispatch(btn) {
  const vehicleId = parseInt(document.getElementById('dispatchVehicle')?.value || '0', 10);
  const driverId = parseInt(document.getElementById('dispatchDriver')?.value || '0', 10) || null;
  const cadetId = parseInt(document.getElementById('dispatchCadet')?.value || '0', 10) || null;
  const routeArea = document.getElementById('dispatchRouteArea')?.value.trim() || '';
  const loadItems = [];
  document.querySelectorAll('.dispatch-qty').forEach((inp) => {
    const qty = parseInt(inp.value || '0', 10);
    if (qty > 0) loadItems.push({ product_id: parseInt(inp.dataset.productId, 10), qty });
  });
  if (!vehicleId || !loadItems.length) {
    mgrNotify('Select a vehicle and enter load quantities.', 'error');
    return;
  }
  if (!cadetId) {
    mgrNotify('Select a cadet / rider.', 'error');
    return;
  }
  if (routeArea.length > 120) {
    mgrNotify('Area / market is too long.', 'error');
    return;
  }
  if (loadItems.some((x) => !Number.isInteger(x.qty) || x.qty <= 0)) {
    mgrNotify('Load quantities must be positive integers.', 'error');
    return;
  }
  const restoreBtn = mgrSetBusy(btn, 'Saving...');
  try {
    await LapokAPI.post('/api/vehicles/dispatch.php', {
      vehicle_id: vehicleId,
      driver_id: driverId,
      load_items: loadItems,
    });
    closeModal('dispatchModal');
    mgrNotify('Vehicle dispatched. Load deducted from warehouse.', 'success');
    await Promise.allSettled([loadDispatchLog(), loadStockTable()]);
  } catch (e) {
    mgrNotify(e.message, 'error');
  } finally {
    restoreBtn();
  }
}

async function loadManagerDeliveryPage() {
  const dateInp = document.getElementById('incomingDate');
  if (dateInp) dateInp.value = mgrTodayLocal();
  try {
    const stale = !mgrDispatchCache.loadedAt || (Date.now() - mgrDispatchCache.loadedAt > mgrDispatchCache.ttlMs);
    if (!productCatalog.length || stale) {
      const stock = await LapokAPI.get('/api/stock/fetch_stock.php');
      productCatalog = stock.stock || [];
      mgrDispatchCache.loadedAt = Date.now();
    }
    const sel = document.getElementById('incomingCcbaOrder');
    if (sel) {
      sel.innerHTML = '<option value="">Not linked in this release</option>';
      sel.disabled = true;
    }
    const tbody = document.getElementById('incomingProductBody');
    const countEl = document.getElementById('incomingProductCount');
    if (tbody) {
      const cats = ['300ML RGB', '330ML', 'ENERGY', '500ML', '1 LITRE', 'JUICE', '2 LITRE', 'RWENZORI WATER', 'EMPTIES'];
      const byCat = {};
      productCatalog.forEach((p) => {
        const cat = p.category || p.brand || 'OTHER';
        if (!byCat[cat]) byCat[cat] = [];
        byCat[cat].push(p);
      });
      let html = '';
      const paint = (cat, list) => {
        if (!list?.length) return;
        html += `<tr class="cat-row"><td colspan="7">${escMgr(cat)}</td></tr>`;
        list.forEach((p, idx) => {
          html += `<tr data-product-id="${p.product_id}">
            <td>${idx === 0 ? escMgr(cat) : ''}</td>
            <td>${escMgr(p.name)} <span style="color:var(--gray-mid);font-size:11px">${escMgr(p.sku || '')}</span></td>
            <td><input class="qty-inp" type="number" min="0" value="0" data-f="ordered"></td>
            <td><input class="qty-inp" type="number" min="0" value="0" data-f="delivered"></td>
            <td><input class="qty-inp" type="text" placeholder="${escMgr(p.sku)}-batch" style="width:110px" data-f="batch"></td>
            <td><input class="input" type="date" style="min-height:36px;padding:4px" data-f="expiry"></td>
            <td><input class="qty-inp" type="number" value="${Math.round((p.unit_price || 0) * 0.6)}" data-f="cost"></td>
          </tr>`;
        });
      };
      cats.forEach((cat) => paint(cat, byCat[cat]));
      Object.keys(byCat).forEach((cat) => {
        if (cats.includes(cat)) return;
        paint(cat, byCat[cat]);
      });
      tbody.innerHTML = html || '<tr><td colspan="7" style="color:var(--gray-mid)">No products in warehouse catalog.</td></tr>';
    }
    if (countEl) countEl.textContent = `${productCatalog.length} SKUs`;
    const recv = document.getElementById('incomingReceivedBy');
    if (recv && currentUser) recv.value = currentUser.full_name;
  } catch (e) {
    mgrNotify('Could not load delivery page: ' + e.message, 'error');
  }
}

async function saveDeliveryEnhanced(btn) {
  const root = document.getElementById('page-manager-delivery') || document;
  const items = [];
  root.querySelectorAll('#incomingProductBody tr[data-product-id]').forEach((tr) => {
    const productId = parseInt(tr.dataset.productId || '0', 10);
    const p = productCatalog.find((x) => x.product_id === productId);
    if (!p) return;
    const ordered = parseInt(tr.querySelector('[data-f="ordered"]')?.value || '0', 10);
    const qtyDelivered = parseInt(tr.querySelector('[data-f="delivered"]')?.value || '0', 10);
    if (qtyDelivered <= 0) return;
    items.push({
      product_id: p.product_id,
      qty_ordered: ordered || qtyDelivered,
      qty_delivered: qtyDelivered,
      batch_number: tr.querySelector('[data-f="batch"]')?.value || `BATCH-${p.sku}-${Date.now()}`,
      expiry_date: tr.querySelector('[data-f="expiry"]')?.value || mgrDatePlusDays(180),
      unit_cost: parseFloat(tr.querySelector('[data-f="cost"]')?.value || '0'),
    });
  });
  if (!items.length) {
    mgrNotify('Enter delivered quantities', 'error');
    return;
  }
  if (items.some((i) => i.qty_delivered <= 0 || i.qty_ordered < 0 || i.unit_cost < 0)) {
    mgrNotify('Delivery values are invalid.', 'error');
    return;
  }
  if (items.some((i) => i.qty_ordered && i.qty_delivered > (i.qty_ordered * 2))) {
    mgrNotify('Delivered qty is unexpectedly high for at least one line.', 'error');
    return;
  }
  const restoreBtn = mgrSetBusy(btn, 'Saving...');
  try {
    await LapokAPI.post('/api/stock/receive_delivery.php', {
      delivery_date: document.getElementById('incomingDate')?.value || mgrTodayLocal(),
      delivery_time: document.getElementById('incomingTime')?.value || null,
      waybill: document.getElementById('incomingWaybill')?.value || '',
      invoice_number: document.getElementById('incomingInvoice')?.value || '',
      truck_plate: document.getElementById('incomingTruck')?.value || '',
      driver_name: document.getElementById('incomingDriver')?.value || '',
      notes: document.getElementById('incomingNotes')?.value || '',
      items,
    });
    mgrNotify('Delivery recorded and linked to stock.', 'success');
    await Promise.allSettled([
      loadStockTable(),
      loadDeliveryList(),
      typeof loadManagerStockBook === 'function' ? loadManagerStockBook() : Promise.resolve(),
    ]);
    showPage('manager-stock');
  } catch (e) {
    mgrNotify(e.message, 'error');
  } finally {
    restoreBtn();
  }
}

async function mgrSendExecutiveBrief() {
  try {
    await LapokAPI.post('/api/reports/generate_pack.php', {
      report_date: mgrTodayLocal(),
    });
    mgrNotify('Executive brief generated and sent.', 'success');
    await loadManagerDashboardExtras();
  } catch (e) {
    mgrNotify(e.message, 'error');
  }
}

function escMgr(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

window.saveDeliveryEnhanced = saveDeliveryEnhanced;
window.loadManagerDeliveryPage = loadManagerDeliveryPage;

function mgrWatchModalOpen(id, onOpen) {
  const el = document.getElementById(id);
  if (!el) return;
  let wasOpen = el.classList.contains('open');
  const observer = new MutationObserver(() => {
    const isOpen = el.classList.contains('open');
    if (isOpen && !wasOpen) onOpen();
    wasOpen = isOpen;
  });
  observer.observe(el, { attributes: true, attributeFilter: ['class'] });
}

document.addEventListener('DOMContentLoaded', () => {
  mgrWatchModalOpen('dispatchModal', prepareDispatchModal);
});
