/**
 * LAPOK DMS — Phases 4 & 5 UI wiring
 */

let liveChartData = null;

async function loadAdminDashboard() {
  if (!currentUser || !['admin', 'executive', 'manager', 'accountant'].includes(currentUser.role)) return;
  try {
    const d = await LapokAPI.get('/api/dashboard/admin.php');
    const set = (sel, v) => { const el = document.querySelector(sel); if (el) el.textContent = v; };
    set('#page-admin-dashboard .metric-card.hi .metric-value', Number(d.warehouse_cartons).toLocaleString());
    set('#page-admin-dashboard .metric-grid .metric-card:nth-child(2) .metric-value', LapokAPI.formatM(d.revenue_today));
    set('#page-admin-dashboard .metric-grid .metric-card:nth-child(3) .metric-value', Number(d.cartons_today).toLocaleString());
    set('#page-admin-dashboard .metric-grid .metric-card:nth-child(4) .metric-value', LapokAPI.formatM(d.revenue_mtd));
    set('#page-admin-dashboard .metric-grid .metric-card:nth-child(5) .metric-value',
      d.vehicles_out + '/' + d.vehicles_total);
    set('#page-admin-dashboard .metric-grid .metric-card:nth-child(6) .metric-value', d.pending_requests);
    set('#page-manager-dashboard .metric-card.hi .metric-value', Number(d.warehouse_cartons).toLocaleString());
    set('#page-manager-dashboard .metric-grid .metric-card:nth-child(2) .metric-value', Number(d.cartons_today).toLocaleString());
    set('#page-manager-dashboard .metric-grid .metric-card:nth-child(2) .metric-sub', LapokAPI.formatUgx(d.revenue_today));
    set('#page-manager-dashboard .metric-grid .metric-card:nth-child(4) .metric-value', d.pending_requests);
  } catch (e) { console.warn('Admin dashboard:', e.message); }
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
    const rows = (d.customers || []).map((c) => {
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
  } catch (e) { alert(e.message); }
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
  const table = document.getElementById('auditTable');
  if (!table) return;
  try {
    const d = await LapokAPI.get('/api/audit/fetch_log.php');
    const rows = (d.entries || []).map((e) => {
      const ch = e.new_values ? JSON.stringify(e.new_values).slice(0, 80) : '—';
      return `<tr><td>${LapokAPI.formatTime(e.created_at)}</td><td>${e.user_name || '—'}</td><td>${e.table_name}</td><td>${e.action}</td><td style="font-size:11px;font-family:monospace">${ch}</td></tr>`;
    }).join('');
    table.innerHTML = '<tr><th>When</th><th>User</th><th>Table</th><th>Action</th><th>Changes</th></tr>' + rows;
  } catch (e) { console.warn('Audit:', e.message); }
}

async function loadUsersTable() {
  const table = document.getElementById('userTable');
  if (!table || currentUser?.role !== 'admin') return;
  try {
    const d = await LapokAPI.get('/api/users/fetch_users.php');
    const roleBadge = (r) => `<span class="badge ${r === 'admin' || r === 'executive' ? 'br' : r === 'manager' ? 'bw' : 'bi'}">${LapokAPI.roleLabel[r] || r}</span>`;
    const rows = (d.users || []).map((u) => {
      const ini = u.full_name.split(' ').map((n) => n[0]).join('').slice(0, 2);
      return `<tr><td><div style="display:flex;align-items:center;gap:8px"><div class="avatar av-red">${ini}</div>${u.full_name}</div></td>
        <td>${roleBadge(u.role)}</td><td>${u.national_id || '—'}</td><td>${u.phone || '—'}</td>
        <td>${u.vehicle_reg ? `<span class="badge b-tuk">${u.vehicle_reg}</span>` : '—'}</td>
        <td><label class="toggle"><input type="checkbox" ${u.is_active ? 'checked' : ''} disabled><span class="slider"></span></label></td>
        <td><button class="btn btn-sm">Edit</button></td></tr>`;
    }).join('');
    table.innerHTML = '<tr><th>Name</th><th>Role</th><th>National ID</th><th>Phone</th><th>Vehicle</th><th>Active</th><th>Actions</th></tr>' + rows;
  } catch (e) { console.warn('Users:', e.message); }
}

async function loadPendingCash() {
  const table = document.getElementById('cashConfirmTable');
  if (!table) return;
  try {
    const d = await LapokAPI.get('/api/trips/pending_cash.php');
    const rows = (d.trips || []).map((t) =>
      `<tr><td>#${t.id}</td><td>${t.cadet_name || '—'}</td><td>${t.vehicle_reg}</td>
      <td>${LapokAPI.formatUgx(t.cash_reported)}</td>
      <td><input class="qty-inp" type="number" id="cash-${t.id}" value="${t.cash_reported}" style="width:100px"></td>
      <td><button class="btn btn-sm btn-red" onclick="confirmCash(${t.id})">Confirm</button></td></tr>`
    ).join('');
    table.innerHTML = '<tr><th>Trip</th><th>Cadet</th><th>Vehicle</th><th>Reported</th><th>Received</th><th>Action</th></tr>' +
      (rows || '<tr><td colspan="6">No pending confirmations</td></tr>');
  } catch (e) { console.warn('Cash:', e.message); }
}

async function confirmCash(tripId) {
  const inp = document.getElementById('cash-' + tripId);
  const amount = parseFloat(inp?.value) || 0;
  try {
    const r = await LapokAPI.post('/api/trips/cash_confirm.php', { trip_id: tripId, cash_collected: amount });
    alert(`Confirmed. Variance: ${LapokAPI.formatUgx(r.variance)}`);
    loadPendingCash();
  } catch (e) { alert(e.message); }
}

async function loadLiveCharts() {
  try {
    liveChartData = await LapokAPI.get('/api/reports/dashboard_charts.php?days=30');
    if (typeof sales !== 'undefined') {
      window.sales = liveChartData.sales;
      window.expenses = liveChartData.expenses;
      window.profit = liveChartData.profit;
      window.days = liveChartData.labels;
      window.chartsDrawn = false;
      if (document.getElementById('page-admin-dashboard')?.classList.contains('active')) {
        drawCharts();
      }
    }
  } catch (e) { console.warn('Charts:', e.message); }
}

async function loadFinancialReports() {
  try {
    const d = await LapokAPI.get('/api/reports/financial.php');
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
    const d = await LapokAPI.get('/api/reports/sales.php');
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
    const d = await LapokAPI.get('/api/reports/stock.php');
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
    alert('Password updated.');
  } catch (e) { alert(e.message); }
}

const _origOpenModal = typeof openModal === 'function' ? openModal : null;
if (_origOpenModal) {
  window.openModal = function (id) {
    if (id === 'profileModal') openProfileModal();
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
    'manager-ccba-boards': () => loadManagerOccdBoards(),
    'manager-fleet-map': () => { loadFleetMapPage(); loadDispatchLog(); },
    'report-exchange': () => loadReportExchangePage(),
    'user-dashboard': () => loadFieldDashboard(),
    'user-route': () => loadMyRoute(),
    'user-customers': () => loadUserCustomers(),
    'admin-customers': () => loadAdminCustomers(),
    'admin-routes': () => loadRoutes(),
    'admin-audit': () => loadAuditLog(),
    'admin-reports': () => { loadFinancialReports(); loadLiveCharts(); },
    'manager-reports': () => loadSalesReports(),
    'accountant-rdc': () => loadRdcBalancingPage(),
    'accountant-cash': () => loadPendingCash(),
    'admin-users': () => loadUsersTable(),
    'manager-ccba-order': () => loadCcbaPage(),
    'admin-exceptions': () => loadExceptionsPage(),
    'admin-editreqs': () => loadEditRequests(),
    'manager-stock': () => { loadStockTable(); loadDeliveryList(); },
  };

  const prev = hook;
  window.showPage = function (id) {
    let occdTab = null;
    if (id === 'manager-ccba-map') {
      id = 'manager-ccba-boards';
      occdTab = 'sku-map';
    }
    if (id !== 'manager-fleet-map' && typeof stopFleetMapRefresh === 'function') stopFleetMapRefresh();
    prev(id);
    if (phase45Pages[id]) phase45Pages[id]();
    if (occdTab && typeof switchOccdTab === 'function') switchOccdTab(occdTab);
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
