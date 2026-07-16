/**
 * LAPOK DMS — Phases 4 & 5 UI wiring
 */

let liveChartData = null;
let adminUsersCache = [];
let adminAuditCache = [];
let adminReportFiltersInitialized = false;

function adminToast(message, isError = false) {
  const toast = document.createElement('div');
  toast.textContent = message;
  toast.style.position = 'fixed';
  toast.style.right = '20px';
  toast.style.bottom = '20px';
  toast.style.zIndex = '9999';
  toast.style.padding = '10px 14px';
  toast.style.borderRadius = '10px';
  toast.style.background = isError ? '#991B1B' : '#0F766E';
  toast.style.color = '#fff';
  toast.style.fontSize = '12px';
  toast.style.boxShadow = '0 10px 24px rgba(0,0,0,.18)';
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 2400);
}

function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

function getAdminReportFilters() {
  return {
    from: document.getElementById('reportFrom')?.value || '',
    to: document.getElementById('reportTo')?.value || '',
    route_id: document.getElementById('reportRouteFilter')?.value || '',
    vehicle_id: document.getElementById('reportVehicleFilter')?.value || '',
    user_id: document.getElementById('reportUserFilter')?.value || '',
    group_by: document.getElementById('reportGroupBy')?.value || 'day',
  };
}

function queryFromFilters(filters) {
  const q = new URLSearchParams();
  Object.entries(filters).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) q.set(k, String(v));
  });
  return q.toString();
}

async function loadAdminDashboard() {
  if (!currentUser || !['admin', 'executive', 'manager', 'accountant'].includes(currentUser.role)) return;
  try {
    const dashboardPath = currentUser.role === 'executive'
      ? '/api/dashboard/executive.php'
      : '/api/dashboard/admin.php';
    const d = await LapokAPI.get(dashboardPath);
    const set = (sel, v) => { const el = document.querySelector(sel); if (el) el.textContent = v; };
    const setTrend = (cardIndex, deltaPct, baseLabel) => {
      const card = document.querySelector(`#page-admin-dashboard .metric-grid .metric-card:nth-child(${cardIndex})`);
      if (!card) return;
      let trend = card.querySelector('.metric-trend');
      if (!trend) {
        trend = document.createElement('div');
        trend.className = 'metric-trend';
        card.appendChild(trend);
      }
      const up = Number(deltaPct || 0) >= 0;
      trend.className = 'metric-trend ' + (up ? 'trend-up' : 'trend-dn');
      trend.textContent = `${up ? '↑' : '↓'} ${Math.abs(Number(deltaPct || 0)).toFixed(1)}%`;
      const sub = card.querySelector('.metric-sub');
      if (sub) sub.textContent = baseLabel;
    };
    setText('admMetricWarehouse', Number(d.warehouse_cartons).toLocaleString());
    setText('admMetricRevenueToday', LapokAPI.formatM(d.revenue_today));
    setText('admMetricCartonsToday', Number(d.cartons_today).toLocaleString());
    setText('admMetricRevenueMtd', LapokAPI.formatM(d.revenue_mtd));
    setText('admMetricVehiclesOut', d.vehicles_out + '/' + d.vehicles_total);
    setText('admMetricPendingRequests', d.pending_requests);
    set('#page-manager-dashboard .metric-card.hi .metric-value', Number(d.warehouse_cartons).toLocaleString());
    set('#page-manager-dashboard .metric-grid .metric-card:nth-child(2) .metric-value', Number(d.cartons_today).toLocaleString());
    set('#page-manager-dashboard .metric-grid .metric-card:nth-child(2) .metric-sub', LapokAPI.formatUgx(d.revenue_today));
    set('#page-manager-dashboard .metric-grid .metric-card:nth-child(4) .metric-value', d.pending_requests);
    if (currentUser.role === 'executive') {
      setTrend(2, d.revenue_today_delta_pct, 'vs yesterday');
      setTrend(3, d.cartons_today_delta_pct, 'vs yesterday');
      setTrend(4, d.revenue_mtd_delta_pct, 'vs same days last month');
      set('#page-admin-dashboard .metric-grid .metric-card:nth-child(6) .metric-sub', `${d.pending_orders} sales pending`);
      loadExecutiveHomeExtras(d);
    }
    if (currentUser.role === 'admin') {
      loadAdminHomeExtras(d);
      loadAdminActionCenter(d);
    }
  } catch (e) { console.warn('Admin dashboard:', e.message); }
}

async function refreshAdminHome() {
  if (!currentUser || currentUser.role !== 'admin') return;
  await Promise.allSettled([loadAdminDashboard(), loadLiveCharts()]);
}
window.refreshAdminHome = refreshAdminHome;

async function loadAdminHomeExtras(cachedDashboard = null) {
  if (!currentUser || currentUser.role !== 'admin') return;
  const checklist = document.getElementById('adminDailyChecklist');
  const body = document.getElementById('adminChecklistBody');
  const actionCard = document.getElementById('adminActionCenterCard');
  const execCheck = document.getElementById('execDailyChecklist');
  const reportLine = document.getElementById('admReportingLine');
  if (execCheck) execCheck.style.display = 'none';
  if (checklist) checklist.style.display = '';
  if (actionCard) actionCard.style.display = '';
  if (reportLine) {
    reportLine.innerHTML = '<strong>Admin view:</strong> Keep users and approvals healthy, watch the exception radar, then confirm the Cadet → RDC → Manager → Executive reporting chain is moving.';
  }
  if (!body) return;
  try {
    const d = cachedDashboard || await LapokAPI.get('/api/dashboard/admin.php');
    const pending = Number(d.pending_requests || 0);
    const exc = Number(d.exception_count || 0);
    const low = Number(d.low_stock_count ?? (d.low_stock || []).length);
    const active = Number(d.active_users || 0);
    const inactive = Number(d.inactive_users || 0);
    const welfare = Number(d.welfare_open_count || 0);
    const briefs = Number(d.exec_briefs_open || 0);
    const rdc = Number(d.rdc_pending_review || 0);
    const sales = Number(d.pending_orders || 0);
    const auditToday = Number(d.audit_today || 0);

    body.innerHTML = `
      <tr>
        <td>1</td><td>User management</td>
        <td><span class="badge bs">${active} active</span>
          ${inactive ? `<span class="badge bw">${inactive} inactive</span>` : ''}</td>
        <td><button class="btn btn-sm" onclick="showPage('admin-users')">Users</button></td>
      </tr>
      <tr>
        <td>2</td><td>Edit requests</td>
        <td><span class="badge ${pending ? 'bd' : 'bs'}">${pending} pending</span></td>
        <td><button class="btn btn-sm ${pending ? 'btn-red' : ''}" onclick="showPage('admin-editreqs')">Review</button></td>
      </tr>
      <tr>
        <td>3</td><td>Exception center</td>
        <td><span class="badge ${exc ? 'bw' : 'bs'}">${exc} open</span>
          <span style="font-size:11px;color:var(--gray-mid)"> · ${low} low stock</span></td>
        <td><button class="btn btn-sm ${exc ? 'btn-red' : ''}" onclick="showPage('admin-exceptions')">Open</button></td>
      </tr>
      <tr>
        <td>4</td><td>Reporting chain health</td>
        <td><span class="badge ${rdc ? 'bw' : 'bs'}">${rdc} RDC pending</span>
          <span class="badge ${briefs ? 'bw' : 'bs'}">${briefs} exec packs open</span>
          <span class="badge ${sales ? 'bw' : 'bs'}">${sales} sales pending</span></td>
        <td><button class="btn btn-sm" onclick="showPage('report-exchange')">PDF reports</button></td>
      </tr>
      <tr>
        <td>5</td><td>Audit log</td>
        <td><span class="badge ${auditToday ? 'bw' : 'bs'}">${auditToday} today</span></td>
        <td><button class="btn btn-sm" onclick="showPage('admin-audit')">Open audit</button></td>
      </tr>
      <tr>
        <td>6</td><td>Welfare / month-end</td>
        <td><span class="badge ${welfare ? 'bw' : 'bs'}">${welfare} welfare open</span></td>
        <td><button class="btn btn-sm" onclick="showPage('accountant-welfare')">Welfare</button>
          <button class="btn btn-sm" onclick="showPage('accountant-improvements')">Month-end</button></td>
      </tr>`;
  } catch (e) {
    console.warn('Admin extras:', e.message);
    body.innerHTML = `<tr><td colspan="4" style="color:var(--gray-mid)">Could not load checklist. <button class="btn btn-sm" onclick="refreshAdminHome()">Retry</button></td></tr>`;
  }
}

window.loadAdminHomeExtras = loadAdminHomeExtras;

function execBriefBadge(status) {
  const s = String(status || '');
  if (s === 'acknowledged') return '<span class="badge bs">Acknowledged</span>';
  if (s === 'read') return '<span class="badge bi">Read</span>';
  if (s === 'sent') return '<span class="badge bw">New</span>';
  if (!s) return '<span class="badge bg">None yet</span>';
  return `<span class="badge bg">${s}</span>`;
}

async function loadExecutiveHomeExtras(cachedDashboard = null) {
  if (!currentUser || currentUser.role !== 'executive') return;
  const checklist = document.getElementById('execDailyChecklist');
  const body = document.getElementById('execChecklistBody');
  const actionCard = document.getElementById('adminActionCenterCard');
  const reportLine = document.getElementById('admReportingLine');
  if (actionCard) actionCard.style.display = 'none';
  if (checklist) checklist.style.display = '';
  const adminCheck = document.getElementById('adminDailyChecklist');
  if (adminCheck) adminCheck.style.display = 'none';
  if (reportLine) {
    reportLine.innerHTML = '<strong>Executive view:</strong> Monitor depot KPIs, open Director brief, then acknowledge the manager PDF pack. Operational fixes belong to Manager / RDC.';
  }
  if (!body) return;
  try {
    const d = cachedDashboard || await LapokAPI.get('/api/dashboard/executive.php');
    const brief = d.latest_brief;
    const unread = Number(d.unread_briefs || 0);
    const exc = Number(d.exception_count || 0);
    const recvN = Number(d.receivables_count || 0);
    const recvT = Number(d.receivables_total || 0);
    const welfare = Number(d.welfare_open_count || 0);
    const dir = d.director || {};
    const readiness = dir.readiness || '—';
    const readinessOk = readiness === 'on_track';
    const readinessLabel = ({
      on_track: 'On track',
      opening_missing: 'Opening missing',
      due: 'Close due',
      late: 'Late',
    })[readiness] || readiness;
    const netOp = dir.net_operating != null ? LapokAPI.formatUgx(dir.net_operating) : '—';
    const rdcSt = dir.rdc_status ? String(dir.rdc_status).replace(/_/g, ' ') : '—';

    const briefStatus = brief
      ? execBriefBadge(brief.status) + (brief.packet_ref ? ` <span style="font-size:11px;color:var(--gray-mid)">${brief.packet_ref}</span>` : '')
      : '<span class="badge bg">Awaiting manager</span>';
    const briefAction = brief
      ? `<button class="btn btn-sm ${brief.status !== 'acknowledged' ? 'btn-red' : ''}" onclick="showPage('report-exchange')">Open inbox</button>`
      : '<button class="btn btn-sm" onclick="showPage(\'report-exchange\')">PDF reports</button>';

    body.innerHTML = `
      <tr>
        <td>1</td><td>Director brief (today P&amp;L)</td>
        <td><span class="badge ${readinessOk ? 'bs' : 'bw'}">${readinessLabel}</span>
          <span style="font-size:11px;color:var(--gray-mid)"> · Net ${netOp} · RDC ${rdcSt}</span></td>
        <td><button class="btn btn-sm btn-red" onclick="showPage('director-brief')">Open brief</button></td>
      </tr>
      <tr>
        <td>2</td><td>Manager PDF pack${unread ? ` <span class="badge bd">${unread} open</span>` : ''}</td>
        <td>${briefStatus}</td>
        <td>${briefAction}</td>
      </tr>
      <tr>
        <td>3</td><td>Exception radar</td>
        <td><span class="badge ${exc ? 'bw' : 'bs'}">${exc} open</span></td>
        <td><button class="btn btn-sm" onclick="showPage('admin-exceptions')">Monitor</button></td>
      </tr>
      <tr>
        <td>4</td><td>Receivables overview</td>
        <td><span class="badge ${recvN ? 'bw' : 'bs'}">${recvN} accounts</span>
          <span style="font-size:11px;color:var(--gray-mid)"> · ${LapokAPI.formatUgx(recvT)}</span></td>
        <td><button class="btn btn-sm" onclick="showPage('admin-customers')">Open</button></td>
      </tr>
      <tr>
        <td>5</td><td>Staff welfare / month-end</td>
        <td><span class="badge ${welfare ? 'bw' : 'bs'}">${welfare} welfare open</span></td>
        <td><button class="btn btn-sm" onclick="showPage('accountant-welfare')">Welfare</button>
          <button class="btn btn-sm" onclick="showPage('accountant-improvements')">Month-end</button></td>
      </tr>`;
    if (typeof loadDirectorBriefWidget === 'function') loadDirectorBriefWidget();
  } catch (e) {
    console.warn('Executive extras:', e.message);
    body.innerHTML = `<tr><td colspan="4" style="color:var(--gray-mid)">Could not load checklist. <button class="btn btn-sm" onclick="loadExecutiveHomeExtras()">Retry</button></td></tr>`;
  }
}

window.loadExecutiveHomeExtras = loadExecutiveHomeExtras;

async function loadAdminActionCenter(cachedDashboard = null) {
  if (currentUser?.role !== 'admin') return;
  const tbody = document.getElementById('adminActionCenterBody');
  if (!tbody) return;
  try {
    const d = cachedDashboard || await LapokAPI.get('/api/dashboard/admin.php');
    const recvCount = Number(d.receivables_count || 0);
    const recvTotal = Number(d.receivables_total || 0);
    const rows = [
      ['High', 'Pending edit requests', d.pending_requests || 0, "showPage('admin-editreqs')"],
      ['High', 'Exception queue items', d.exception_count || 0, "showPage('admin-exceptions')"],
      ['Medium', 'Low stock alerts', d.low_stock_count ?? (d.low_stock || []).length, "showPage('admin-exceptions')"],
      ['Medium', 'RDC sheets pending review', d.rdc_pending_review || 0, "showPage('manager-rdc-review')"],
      ['Medium', 'Vehicles out now', `${d.vehicles_out || 0}/${d.vehicles_total || 0}`, "showPage('admin-exceptions')"],
      ['Low', 'Receivables (accounts owing)', `${recvCount} · ${LapokAPI.formatM(recvTotal)}`, "showPage('admin-customers')"],
      ['Low', 'Executive packs awaiting ack', d.exec_briefs_open || 0, "showPage('report-exchange')"],
    ];
    tbody.innerHTML = rows.map((r) =>
      `<tr><td>${r[0]}</td><td>${r[1]}</td><td>${r[2]}</td><td><button class="btn btn-sm" onclick="${r[3]}">Open</button></td></tr>`
    ).join('');
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="4" style="color:var(--red)">Action center load failed: ${e.message}</td></tr>`;
  }
}

async function loadFieldDashboard() {
  if (!currentUser || !['cadet', 'driver', 'field_user'].includes(currentUser.role)) return;
  try {
    const d = await LapokAPI.get('/api/dashboard/field_user.php');
    const trip = d.trip;
    const s = d.summary;
    if (trip) {
      document.getElementById('fieldVehicleIcon').textContent = trip.vehicle_type === 'truck' ? '🚛' : '🛺';
      document.getElementById('fieldVehicleTitle').textContent = trip.registration + ' — Assigned vehicle';
      document.getElementById('fieldVehicleDetail').textContent =
        `Route: ${trip.route_name || trip.route_area || '—'} · Capacity: ${trip.capacity} cartons`;
      document.getElementById('fieldVehicleBadges').innerHTML =
        `<span class="badge bs">${trip.status}</span>`;
      document.getElementById('fieldLoadTotal').textContent = s.total_loaded;
    }
    document.getElementById('fmLoaded').textContent = s.total_loaded;
    document.getElementById('fmSold').textContent = s.total_sold;
    document.getElementById('fmRevenue').textContent = LapokAPI.formatUgx(s.revenue_today);
    document.getElementById('fmRemaining').textContent = s.total_remaining;
    document.getElementById('fmReceipts').textContent = s.receipts_today;
    document.getElementById('fmStops').textContent = s.stops_total ? `0/${s.stops_total}` : '—';

    const lt = document.getElementById('fieldLoadTable');
    if (lt) {
      lt.innerHTML = '<tr><th>Product</th><th>Loaded</th><th>Sold</th><th>Remaining</th></tr>' +
        (d.load || []).map((i) => {
          const rem = i.qty_loaded - i.qty_sold;
          return `<tr><td>${i.product_name}</td><td>${i.qty_loaded}</td><td>${i.qty_sold}</td><td><strong>${rem}</strong></td></tr>`;
        }).join('');
    }
    const ot = document.getElementById('fieldOrdersTable');
    if (ot) {
      ot.innerHTML = '<tr><th>Time</th><th>Customer</th><th>Amount</th><th>Status</th></tr>' +
        (d.orders_today || []).map((o) =>
          `<tr><td>${LapokAPI.formatTime(o.created_at)}</td><td>${o.customer_name || '—'}</td><td>${Number(o.amount_total).toLocaleString()}</td><td><span class="badge ${o.status === 'confirmed' ? 'bs' : 'bw'}">${o.status}</span></td></tr>`
        ).join('');
    }
    if (trip && typeof startFieldLocationPing === 'function') startFieldLocationPing();
  } catch (e) { console.warn('Field dashboard:', e.message); }
}

async function loadMyRoute() {
  try {
    const d = await LapokAPI.get('/api/routes/my_route.php');
    const alert = document.getElementById('routeAlert');
    const list = document.getElementById('routeStopsList');
    if (!d.route) {
      if (alert) alert.innerHTML = '<span>ℹ</span>No route assigned.';
      return;
    }
    if (alert) alert.innerHTML = `<span>ℹ</span>Today's route: <strong>${d.route.name}</strong> — ${d.stops.length} stops.` + (d.trip ? ` Vehicle: ${d.trip.vehicle_reg}` : '');
    if (d.trip) document.getElementById('routeVehicleChip').textContent = d.trip.vehicle_reg;
    document.getElementById('rsStops').textContent = d.stops.length;

    list.innerHTML = '<div class="route-line"></div>' + d.stops.map((st, i) => {
      const done = st.last_order_status === 'confirmed' || st.last_order_status === 'delivered';
      const dot = done ? 'done' : (i === 0 ? 'active' : 'pending');
      const badge = st.last_amount
        ? `<span class="badge ${done ? 'bs' : 'bw'}">${done ? 'Done' : 'Pending'} — ${Number(st.last_amount).toLocaleString()}</span>`
        : '';
      return `<div class="stop"><div class="stop-dot ${dot}"></div><div>
        <div style="font-size:13px;font-weight:600">${st.stop_order}. ${st.name}</div>
        <div style="font-size:12px;color:var(--gray-mid)">${st.location || '—'}</div>
        <div style="margin-top:4px">${badge}</div>
      </div></div>`;
    }).join('');
  } catch (e) { console.warn('Route:', e.message); }
}

async function loadAdminCustomers(q = '') {
  const table = document.getElementById('adminCustomerTable');
  if (!table) return;
  try {
    const path = '/api/customers/fetch_customers.php' + (q ? '?search=' + encodeURIComponent(q) : '');
    const d = await LapokAPI.get(path);
    const customers = d.customers || [];

    if (!q) {
      const owing = customers.filter((c) => Number(c.credit_balance) > 0);
      const total = owing.reduce((s, c) => s + Number(c.credit_balance || 0), 0);
      const totalEl = document.getElementById('recvTotalValue');
      const totalSub = document.getElementById('recvTotalSub');
      const countEl = document.getElementById('recvCountValue');
      const totalCard = document.getElementById('recvTotalCard');
      if (totalEl) totalEl.textContent = LapokAPI.formatM(total);
      if (totalSub) {
        totalSub.textContent = total >= 8000000
          ? 'Above 8M UGX — prioritize collections'
          : 'UGX ' + total.toLocaleString();
      }
      if (countEl) countEl.textContent = String(owing.length);
      if (totalCard) totalCard.classList.toggle('rdc-variance-warn', total >= 8000000);
    }

    const rows = customers.map((c) => {
      const bal = Number(c.credit_balance);
      const balCell = bal > 0 ? `<span class="badge bd">${bal.toLocaleString()}</span>` : '0';
      return `<tr><td>${c.name}</td><td>${c.phone || '—'}</td><td>${c.location || '—'}</td><td><span class="badge bg">${c.category}</span></td><td>${balCell}</td><td><button class="btn btn-sm" onclick="viewCustomerHistory(${c.id},'${c.name.replace(/'/g, "\\'")}')">History</button></td></tr>`;
    }).join('');
    table.innerHTML = '<tr><th>Name</th><th>Phone</th><th>Location</th><th>Category</th><th>Balance (UGX)</th><th>Actions</th></tr>' + (rows || '<tr><td colspan="6">No customers</td></tr>');
  } catch (e) { console.warn('Customers:', e.message); }
}

async function loadUserCustomers() {
  const list = document.getElementById('customerList');
  if (!list) return;
  try {
    const d = await LapokAPI.get('/api/customers/fetch_customers.php');
    list.innerHTML = (d.customers || []).map((c) => {
      const bal = Number(c.credit_balance);
      const balNote = bal > 0 ? ` · Balance: ${LapokAPI.formatUgx(bal)}` : '';
      return `<div class="cust-card" onclick="selectCustomer(this,'${c.name.replace(/'/g, "\\'")}','${(c.phone || '').replace(/'/g, "\\'")}','${(c.location || '').replace(/'/g, "\\'")}',${c.id})">
        <div style="display:flex;justify-content:space-between;gap:8px"><div>
          <div class="cust-name">${c.name}</div>
          <div class="cust-detail">${c.phone || '—'} · ${c.location || '—'}</div>
          <div class="cust-detail" style="margin-top:3px">Total: ${LapokAPI.formatUgx(c.lifetime_total)}${balNote}</div>
        </div><span class="badge bs">${c.category}</span></div></div>`;
    }).join('') || '<p style="color:var(--gray-mid)">No customers on your route.</p>';
  } catch (e) { console.warn('User customers:', e.message); }
}

async function viewCustomerHistory(id, name) {
  try {
    const d = await LapokAPI.get('/api/customers/history.php?customer_id=' + id);
    const rows = (d.orders || []).map((o) =>
      `<tr><td>${o.order_ref}</td><td>${o.status}</td><td>${Number(o.amount_total).toLocaleString()}</td><td>${LapokAPI.formatDate(o.created_at)}</td></tr>`
    ).join('');
    document.getElementById('reportsDetailBody').innerHTML =
      `<h4 style="margin-bottom:.8rem">${name} — Balance: ${LapokAPI.formatUgx(d.customer.credit_balance)}</h4>
      <table><tr><th>Ref</th><th>Status</th><th>Amount</th><th>Date</th></tr>${rows || '<tr><td colspan="4">No orders</td></tr>'}</table>`;
    showPage('admin-reports');
  } catch (e) { adminToast(e.message, true); }
}

async function loadRoutes() {
  const el = document.getElementById('routesList');
  if (!el) return;
  try {
    const d = await LapokAPI.get('/api/routes/fetch_routes.php');
    el.innerHTML = (d.routes || []).map((r) => `
      <div class="card" style="margin-bottom:.8rem">
        <div class="card-header"><span class="card-title">${r.name}</span><span class="chip">${r.zone || '—'} · ${r.stop_count} stops</span></div>
        <p style="font-size:12px;color:var(--gray-mid);margin-bottom:.6rem">${r.description || ''}</p>
        <div class="tbl-wrap"><table><tr><th>#</th><th>Customer</th><th>Location</th></tr>
        ${(r.stops || []).map((s) => `<tr><td>${s.stop_order}</td><td>${s.customer_name}</td><td>${s.location || '—'}</td></tr>`).join('') || '<tr><td colspan="3">No stops assigned</td></tr>'}
        </table></div>
      </div>`).join('') || '<p>No routes yet.</p>';
  } catch (e) { console.warn('Routes:', e.message); }
}

async function loadAuditLog() {
  const tbody = document.getElementById('auditTableBody');
  if (!tbody || currentUser?.role !== 'admin') return;
  const params = new URLSearchParams({
    per_page: document.getElementById('auditPerPage')?.value || '50',
  });
  const action = document.getElementById('auditActionFilter')?.value || '';
  const table = document.getElementById('auditTableFilter')?.value || '';
  const user = document.getElementById('auditUserFilter')?.value || '';
  const from = document.getElementById('auditFrom')?.value || '';
  const to = document.getElementById('auditTo')?.value || '';
  if (action) params.set('action', action);
  if (table) params.set('table', table);
  if (user) params.set('user', user);
  if (from) params.set('from', from);
  if (to) params.set('to', to);
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--gray-mid)">Loading…</td></tr>';
  try {
    const d = await LapokAPI.get('/api/audit/fetch_log.php?' + params.toString());
    adminAuditCache = d.entries || [];
    setText('auditCountChip', `${adminAuditCache.length} entries`);
    const rows = adminAuditCache.map((e, idx) => {
      const when = LapokAPI.formatDate(e.created_at) + ' ' + LapokAPI.formatTime(e.created_at);
      const ch = e.new_values ? JSON.stringify(e.new_values).slice(0, 120) : (e.old_values ? JSON.stringify(e.old_values).slice(0, 120) : '—');
      return `<tr>
        <td>${when}</td>
        <td>${e.user_name || 'System'}</td>
        <td>${e.table_name}${e.record_id ? ` #${e.record_id}` : ''}</td>
        <td><span class="badge bg">${e.action}</span></td>
        <td style="font-size:11px;font-family:monospace">${ch}</td>
        <td><button class="btn btn-sm" onclick="showAuditDetail(${idx})">View</button></td>
      </tr>`;
    }).join('');
    tbody.innerHTML = rows || '<tr><td colspan="6" style="text-align:center;color:var(--gray-mid)">No entries.</td></tr>';
  } catch (e) {
    console.warn('Audit:', e.message);
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--red)">${e.message}</td></tr>`;
  }
}

function showAuditDetail(index) {
  const item = adminAuditCache[index];
  if (!item) return;
  const el = document.getElementById('auditDetailBody');
  if (!el) return;
  el.textContent = JSON.stringify(item, null, 2);
  openModal('auditDetailModal');
}

function initAuditFilters() {
  const from = document.getElementById('auditFrom');
  const to = document.getElementById('auditTo');
  if (from && !from.value) from.value = LapokAPI.monthStartIso();
  if (to && !to.value) to.value = LapokAPI.todayIso();
}

async function loadUsersTable() {
  const table = document.getElementById('userTable');
  if (!table || !['admin', 'executive'].includes(currentUser?.role)) return;
  const isExec = currentUser?.role === 'executive';
  const hint = document.getElementById('execUsersHint');
  const addBtn = document.getElementById('adminAddUserBtn');
  if (hint) hint.style.display = isExec ? '' : 'none';
  if (addBtn) addBtn.style.display = isExec ? 'none' : '';
  try {
    const d = await LapokAPI.get('/api/users/fetch_users.php');
    adminUsersCache = d.users || [];
    applyUsersFilter();
    if (!isExec) hydrateUserVehicleOptions();
  } catch (e) { console.warn('Users:', e.message); }
}

function applyUsersFilter() {
  const table = document.getElementById('userTable');
  if (!table) return;
  const isExec = currentUser?.role === 'executive';
  const q = (document.getElementById('adminUserSearch')?.value || '').toLowerCase();
  const roleFilter = document.getElementById('adminUserRoleFilter')?.value || '';
  const roleBadge = (r) => `<span class="badge ${r === 'admin' || r === 'executive' ? 'br' : r === 'manager' ? 'bw' : 'bi'}">${LapokAPI.roleLabel[r] || r}</span>`;
  const filtered = adminUsersCache.filter((u) => {
    const text = [u.full_name, u.email, u.national_id, u.phone, u.role].join(' ').toLowerCase();
    return (!q || text.includes(q)) && (!roleFilter || u.role === roleFilter);
  });
  const rows = filtered.map((u) => {
      const ini = u.full_name.split(' ').map((n) => n[0]).join('').slice(0, 2);
      const canFreeze = !isExec || (!['admin', 'executive'].includes(u.role) && Number(u.id) !== Number(currentUser?.id));
      const freezeCell = canFreeze
        ? `<label class="toggle"><input type="checkbox" ${u.is_active ? 'checked' : ''} onchange="toggleUserActive(${u.id}, this.checked)"><span class="slider"></span></label>`
        : `<span class="badge ${u.is_active ? 'bs' : 'bd'}">${u.is_active ? 'Active' : 'Frozen'}</span>`;
      const actions = isExec
        ? `<span style="font-size:11px;color:var(--gray-mid)">${u.is_active ? 'Uncheck to freeze' : 'Check to unfreeze'}</span>`
        : `<button class="btn btn-sm" onclick="openEditUserModal(${u.id})">Edit</button>`;
      return `<tr><td><div style="display:flex;align-items:center;gap:8px"><div class="avatar av-red">${ini}</div><div><div>${u.full_name}</div><div style="font-size:11px;color:var(--gray-mid)">${u.email}</div></div></div></td>
        <td>${roleBadge(u.role)}</td><td>${u.national_id || '—'}</td><td>${u.phone || '—'}</td>
        <td>${u.vehicle_reg ? `<span class="badge b-tuk">${u.vehicle_reg}</span>` : '—'}</td>
        <td>${freezeCell}</td>
        <td>${actions}</td></tr>`;
  }).join('');
  table.innerHTML = `<tr><th>Name</th><th>Role</th><th>National ID</th><th>Phone</th><th>Vehicle</th><th>${isExec ? 'Active / Freeze' : 'Active'}</th><th>Actions</th></tr>` +
    (rows || '<tr><td colspan="7" style="text-align:center;color:var(--gray-mid)">No users found</td></tr>');
}

function hydrateUserVehicleOptions() {
  LapokAPI.get('/api/vehicles/fetch_vehicles.php').then((d) => {
    const opts = ['<option value="">None / unassigned</option>'].concat((d.vehicles || []).map((v) =>
      `<option value="${v.id}">${v.registration} (${v.vehicle_type})</option>`
    )).join('');
    const addSel = document.getElementById('addUserVehicleId');
    const editSel = document.getElementById('editUserVehicleId');
    if (addSel) addSel.innerHTML = opts;
    if (editSel) editSel.innerHTML = opts;
  }).catch(() => {});
}

async function toggleUserActive(id, isActive) {
  try {
    await LapokAPI.post('/api/users/edit_user.php', { id, is_active: isActive ? 1 : 0 });
    const row = adminUsersCache.find((u) => u.id === id);
    if (row) row.is_active = isActive ? 1 : 0;
    adminToast(isActive ? 'Account unfrozen' : 'Account frozen');
  } catch (e) {
    adminToast(e.message, true);
    loadUsersTable();
  }
}

function openEditUserModal(id) {
  const u = adminUsersCache.find((x) => x.id === id);
  if (!u) return;
  document.getElementById('editUserId').value = String(u.id);
  document.getElementById('editUserFullName').value = u.full_name || '';
  document.getElementById('editUserEmail').value = u.email || '';
  document.getElementById('editUserRole').value = u.role || 'field_user';
  document.getElementById('editUserNationalId').value = u.national_id || '';
  document.getElementById('editUserPhone').value = u.phone || '';
  document.getElementById('editUserDefaultRoute').value = u.default_route || '';
  document.getElementById('editUserPassword').value = '';
  const idField = document.getElementById('editUserVehicleId');
  if (idField) idField.value = u.vehicle_id || '';
  setText('editUserTitle', `Edit user — ${u.full_name || 'User'}`);
  const err = document.getElementById('editUserErr');
  if (err) err.style.display = 'none';
  openModal('editUserModal');
}

async function submitAddUser() {
  const payload = {
    full_name: document.getElementById('addUserFullName')?.value?.trim() || '',
    email: document.getElementById('addUserEmail')?.value?.trim() || '',
    password: document.getElementById('addUserPassword')?.value || '',
    role: document.getElementById('addUserRole')?.value || 'field_user',
    national_id: document.getElementById('addUserNationalId')?.value?.trim() || '',
    phone: document.getElementById('addUserPhone')?.value?.trim() || '',
    vehicle_id: document.getElementById('addUserVehicleId')?.value || null,
    default_route: document.getElementById('addUserDefaultRoute')?.value?.trim() || '',
  };
  try {
    await LapokAPI.post('/api/users/create_user.php', payload);
    closeModal('addUserModal');
    ['addUserFullName', 'addUserNationalId', 'addUserPhone', 'addUserEmail', 'addUserDefaultRoute', 'addUserPassword']
      .forEach((field) => { const el = document.getElementById(field); if (el) el.value = ''; });
    adminToast('User created');
    await loadUsersTable();
  } catch (e) {
    const err = document.getElementById('addUserErr');
    if (err) { err.style.display = 'block'; err.textContent = e.message; }
  }
}

async function submitEditUser() {
  const id = Number(document.getElementById('editUserId')?.value || 0);
  const payload = {
    id,
    full_name: document.getElementById('editUserFullName')?.value?.trim() || '',
    email: document.getElementById('editUserEmail')?.value?.trim() || '',
    role: document.getElementById('editUserRole')?.value || 'field_user',
    national_id: document.getElementById('editUserNationalId')?.value?.trim() || '',
    phone: document.getElementById('editUserPhone')?.value?.trim() || '',
    vehicle_id: document.getElementById('editUserVehicleId')?.value || null,
    default_route: document.getElementById('editUserDefaultRoute')?.value?.trim() || '',
  };
  const pw = document.getElementById('editUserPassword')?.value || '';
  if (pw) payload.password = pw;
  try {
    await LapokAPI.post('/api/users/edit_user.php', payload);
    closeModal('editUserModal');
    adminToast('User updated');
    await loadUsersTable();
  } catch (e) {
    const err = document.getElementById('editUserErr');
    if (err) { err.style.display = 'block'; err.textContent = e.message; }
  }
}

async function deactivateEditingUser() {
  const id = Number(document.getElementById('editUserId')?.value || 0);
  if (!id) return;
  await toggleUserActive(id, false);
  closeModal('editUserModal');
  loadUsersTable();
}

async function exportUsersCsv() {
  const headers = ['Full name', 'Email', 'Role', 'National ID', 'Phone', 'Vehicle', 'Active'];
  const rows = adminUsersCache.map((u) => [
    u.full_name, u.email, u.role, u.national_id || '', u.phone || '', u.vehicle_reg || '', u.is_active ? 'Yes' : 'No',
  ]);
  await LapokAPI.downloadBrandedExcel({
    title: 'User directory',
    subtitle: 'Active and inactive depot system users',
    headers,
    rows,
    meta: { Users: String(rows.length), 'As of': new Date().toLocaleDateString('en-UG') },
    filename: 'Outpost-DMS-Users-' + LapokAPI.todayIso() + '.xls',
  });
  adminToast('Users Excel exported');
}

async function loadPendingCash() {
  if (typeof loadCashHandoverPage === 'function') return loadCashHandoverPage();
}

async function loadLiveCharts() {
  try {
    liveChartData = await LapokAPI.get('/api/reports/dashboard_charts.php?days=30');
    if (Array.isArray(liveChartData.sales)) sales = liveChartData.sales;
    if (Array.isArray(liveChartData.expenses)) expenses = liveChartData.expenses;
    if (Array.isArray(liveChartData.profit)) profit = liveChartData.profit;
    if (Array.isArray(liveChartData.labels)) days = liveChartData.labels;
    productShareData = liveChartData.product_share || [];
    if (liveChartData.monthly) monthlyChart = liveChartData.monthly;
    chartsDrawn = false;
    if (document.getElementById('page-admin-dashboard')?.classList.contains('active')
      || document.getElementById('page-admin-reports')?.classList.contains('active')) {
      drawCharts();
      if (typeof drawReportChart === 'function') drawReportChart();
    }
  } catch (e) { console.warn('Charts:', e.message); }
}

async function loadFinancialReports() {
  try {
    const filters = getAdminReportFilters();
    const d = await LapokAPI.get('/api/reports/financial.php?' + queryFromFilters(filters));
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = LapokAPI.formatM(v); };
    set('rptRevenueMtd', d.revenue);
    set('rptExpensesMtd', d.expenses);
    set('rptProfitMtd', d.profit);
    const c = document.getElementById('rptCartonsMtd');
    if (c) c.textContent = Number(d.cartons_mtd).toLocaleString();

    const body = document.getElementById('reportsDetailBody');
    if (body) {
      const recv = (d.receivables || []).map((r) =>
        `<tr><td>${r.name}</td><td>${r.phone || '—'}</td><td class="deficit">${Number(r.credit_balance).toLocaleString()}</td></tr>`
      ).join('');
      body.innerHTML = `<p style="margin-bottom:.8rem"><strong>Total receivables:</strong> ${LapokAPI.formatUgx(d.total_receivables)}</p>
        <table><tr><th>Customer</th><th>Phone</th><th>Balance</th></tr>${recv || '<tr><td colspan="3">None</td></tr>'}</table>`;
    }
    chartsDrawn = false;
    if (typeof drawReportChart === 'function') drawReportChart();
  } catch (e) { console.warn('Financial:', e.message); }
}

async function loadSalesReports() {
  try {
    const filters = getAdminReportFilters();
    const d = await LapokAPI.get('/api/reports/sales.php?' + queryFromFilters(filters));
    const st = document.getElementById('mgrSalesTable');
    if (st) {
      st.innerHTML = '<tr><th>Period</th><th>Cartons</th><th>Revenue</th></tr>' +
        (d.by_period || []).map((r) => `<tr><td>${r.period}</td><td>${r.cartons}</td><td>${Number(r.revenue).toLocaleString()}</td></tr>`).join('');
    }
    const vt = document.getElementById('mgrVehicleTable');
    if (vt) {
      vt.innerHTML = '<tr><th>Vehicle</th><th>Trips</th><th>Cartons</th><th>Revenue</th></tr>' +
        (d.by_vehicle || []).map((r) => `<tr><td>${r.registration}</td><td>${r.trips}</td><td>${r.cartons}</td><td>${Number(r.revenue).toLocaleString()}</td></tr>`).join('');
    }
    const pt = document.getElementById('mgrProductTable');
    if (pt) {
      pt.innerHTML = '<tr><th>Product</th><th>Cartons</th><th>Revenue</th></tr>' +
        (d.by_product || []).map((r) => `<tr><td>${r.name}</td><td>${r.cartons}</td><td>${Number(r.revenue).toLocaleString()}</td></tr>`).join('');
    }
    const body = document.getElementById('reportsDetailBody');
    if (body) {
      body.innerHTML = `<p>Total revenue: <strong>${LapokAPI.formatUgx(d.summary?.revenue)}</strong> · Orders: ${d.summary?.orders} · Cartons: ${d.summary?.cartons}</p>`;
    }
  } catch (e) { console.warn('Sales reports:', e.message); }
}

async function loadStockReports() {
  try {
    const filters = getAdminReportFilters();
    const d = await LapokAPI.get('/api/reports/stock.php?' + queryFromFilters(filters));
    const body = document.getElementById('reportsDetailBody');
    if (!body) return;
    const low = (d.low_stock || []).map((l) => `<tr><td>${l.name}</td><td class="deficit">${l.warehouse_qty}</td><td>${l.min_stock}</td></tr>`).join('');
    const exp = (d.expiring_batches || []).map((b) => `<tr><td>${b.product_name}</td><td>${b.batch_number}</td><td>${b.expiry_date}</td><td>${b.qty_warehouse}</td></tr>`).join('');
    body.innerHTML = `<h4 style="margin:.8rem 0">Low stock</h4><table><tr><th>Product</th><th>Qty</th><th>Min</th></tr>${low || '<tr><td colspan="3">OK</td></tr>'}</table>
      <h4 style="margin:1rem 0 .8rem">Expiring within 30 days</h4><table><tr><th>Product</th><th>Batch</th><th>Expiry</th><th>Qty</th></tr>${exp || '<tr><td colspan="4">None</td></tr>'}</table>`;
  } catch (e) { console.warn('Stock reports:', e.message); }
}

function loadReportsTab(tab) {
  if (tab === 'financial') loadFinancialReports();
  else if (tab === 'sales') loadSalesReports();
  else if (tab === 'stock') loadStockReports();
}

async function initAdminReportFilters() {
  if (adminReportFiltersInitialized) return;
  const fromEl = document.getElementById('reportFrom');
  const toEl = document.getElementById('reportTo');
  if (fromEl && !fromEl.value) fromEl.value = LapokAPI.monthStartIso();
  if (toEl && !toEl.value) toEl.value = LapokAPI.todayIso();
  try {
    const [routes, vehicles, users] = await Promise.all([
      LapokAPI.get('/api/routes/fetch_routes.php'),
      LapokAPI.get('/api/vehicles/fetch_vehicles.php'),
      LapokAPI.get('/api/users/fetch_users.php'),
    ]);
    const routeSel = document.getElementById('reportRouteFilter');
    if (routeSel) routeSel.innerHTML = '<option value="">All routes</option>' + (routes.routes || []).map((r) => `<option value="${r.id}">${r.name}</option>`).join('');
    const vehicleSel = document.getElementById('reportVehicleFilter');
    if (vehicleSel) vehicleSel.innerHTML = '<option value="">All vehicles</option>' + (vehicles.vehicles || []).map((v) => `<option value="${v.id}">${v.registration}</option>`).join('');
    const userSel = document.getElementById('reportUserFilter');
    if (userSel) userSel.innerHTML = '<option value="">All users</option>' + (users.users || []).map((u) => `<option value="${u.id}">${u.full_name}</option>`).join('');
  } catch (_) {}
  adminReportFiltersInitialized = true;
}

function applyAdminReportFilters() {
  loadFinancialReports();
}

function resetAdminReportFilters() {
  ['reportRouteFilter', 'reportVehicleFilter', 'reportUserFilter'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const gb = document.getElementById('reportGroupBy');
  if (gb) gb.value = 'day';
  applyAdminReportFilters();
}

function exportFilteredSalesCsv() {
  const q = queryFromFilters(getAdminReportFilters());
  window.open('/api/reports/export_csv.php?type=sales&' + q, '_blank');
}

async function saveNewCustomer() {
  const modal = document.getElementById('addCustModal');
  const inputs = modal.querySelectorAll('.input, .select-inp');
  const name = inputs[0]?.value?.trim();
  const category = (inputs[1]?.value || 'occasional').toLowerCase();
  const phone = inputs[2]?.value?.trim();
  const location = inputs[3]?.value?.trim();
  if (!name) return alert('Name required');
  try {
    await LapokAPI.post('/api/customers/create_customer.php', { name, phone, location, category });
    closeModal('addCustModal');
    loadAdminCustomers();
    loadUserCustomers();
    alert('Customer saved.');
  } catch (e) { alert(e.message); }
}

async function saveNewRoute() {
  const name = document.getElementById('newRouteName')?.value?.trim();
  if (!name) return alert('Route name required');
  try {
    await LapokAPI.post('/api/routes/create_route.php', {
      name,
      zone: document.getElementById('newRouteZone')?.value?.trim(),
      description: document.getElementById('newRouteDesc')?.value?.trim(),
    });
    closeModal('addRouteModal');
    loadRoutes();
  } catch (e) { alert(e.message); }
}

function openProfileModal() {
  if (!currentUser) return;
  document.getElementById('profileName').value = currentUser.full_name;
  document.getElementById('profileEmail').value = currentUser.email;
  document.getElementById('profileRole').value = LapokAPI.roleLabel[currentUser.role] || currentUser.role;
}

async function changePassword() {
  try {
    await LapokAPI.post('/api/auth/change_password.php', {
      current_password: document.getElementById('pwdCurrent').value,
      new_password: document.getElementById('pwdNew').value,
    });
    closeModal('profileModal');
    adminToast('Password updated');
  } catch (e) { adminToast(e.message, true); }
}

const _origOpenModal = typeof openModal === 'function' ? openModal : null;
if (_origOpenModal) {
  window.openModal = function (id) {
    if (id === 'profileModal') openProfileModal();
    if (id === 'addUserModal') {
      const err = document.getElementById('addUserErr');
      if (err) err.style.display = 'none';
      ['addUserFullName', 'addUserNationalId', 'addUserPhone', 'addUserEmail', 'addUserDefaultRoute', 'addUserPassword']
        .forEach((field) => { const el = document.getElementById(field); if (el) el.value = ''; });
      const role = document.getElementById('addUserRole');
      if (role) role.value = 'field_user';
      const vehicle = document.getElementById('addUserVehicleId');
      if (vehicle) vehicle.value = '';
      hydrateUserVehicleOptions();
    }
    _origOpenModal(id);
  };
}

// Extend page navigation
document.addEventListener('DOMContentLoaded', () => {
  const hook = window.showPage;
  if (typeof hook !== 'function') return;

  const phase45Pages = {
    'admin-dashboard': () => { loadAdminDashboard(); loadLiveCharts(); },
    'manager-dashboard': () => { loadAdminDashboard(); loadManagerDashboardExtras(); },
    'report-exchange': () => loadReportExchangePage(),
    'user-dashboard': () => loadFieldDashboard(),
    'user-route': () => loadMyRoute(),
    'cadet-dashboard': () => { if (typeof loadCadetDashboardPage === 'function') loadCadetDashboardPage(); },
    'cadet-daily': () => { if (typeof loadCadetDailyPage === 'function') loadCadetDailyPage(); },
    'user-customers': () => loadUserCustomers(),
    'admin-customers': () => loadAdminCustomers(),
    'admin-routes': () => loadRoutes(),
    'admin-audit': () => { initAuditFilters(); loadAuditLog(); },
    'admin-reports': () => { initAdminReportFilters(); loadFinancialReports(); loadLiveCharts(); },
    'manager-reports': () => loadSalesReports(),
    'manager-rdc-review': () => loadRdcReviewPage(),
    'manager-ccba-boards': () => { if (typeof loadManagerOccdBoards === 'function') loadManagerOccdBoards(); },
    'manager-ccba-order': () => { if (typeof loadCcbaPage === 'function') loadCcbaPage(); },
    'accountant-rdc-hub': () => loadRdcHubPage(),
    'accountant-rdc': () => loadRdcBalancingPage(),
    'accountant-cash': () => loadCashHandoverPage(),
    'accountant-improvements': () => {
      if (typeof loadAccountantImprovementsPage === 'function') loadAccountantImprovementsPage();
      if (typeof loadManagerFixedCosts === 'function') loadManagerFixedCosts();
    },
    'accountant-welfare': () => loadAccountantWelfarePage(),
    'admin-users': () => loadUsersTable(),
    'admin-exceptions': () => loadExceptionsPage(),
    'admin-editreqs': () => loadEditRequests(),
    'manager-stock': () => {
      loadStockTable();
      loadDeliveryList();
      if (typeof loadManagerStockBook === 'function') loadManagerStockBook();
      else {
        if (typeof loadManagerOpeningStock === 'function') loadManagerOpeningStock();
        if (typeof loadManagerClosingStock === 'function') loadManagerClosingStock();
      }
    },
    'manager-delivery': () => {
      if (typeof loadManagerDeliveryPage === 'function') loadManagerDeliveryPage();
    },
    'director-brief': () => { if (typeof loadDirectorBriefPage === 'function') loadDirectorBriefPage(); },
  };

  const prev = hook;
  window.showPage = function (id) {
    const pageId = (typeof resolveAllowedPage === 'function') ? resolveAllowedPage(id) : id;
    prev(pageId);
    if (phase45Pages[pageId]) phase45Pages[pageId]();
  };

  // Enrich init
  const origRefresh = typeof refreshDashboardData === 'function' ? refreshDashboardData : null;
  if (origRefresh) {
    window.refreshDashboardData = async function () {
      await origRefresh();
      await Promise.allSettled([loadAdminDashboard(), loadLiveCharts(), loadFieldDashboard()]);
    };
  }
});
