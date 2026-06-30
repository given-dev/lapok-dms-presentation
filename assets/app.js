/**
 * LAPOK DMS — Frontend ↔ API wiring (Phases 1–3)
 */
let currentUser = null;
let productCatalog = [];
const PRIMARY_SYSTEM_ROLES = ['admin', 'executive', 'manager', 'accountant'];

function isExecutiveUser() {
  return currentUser?.role === 'executive';
}

function applyExecutiveReadOnlyMode() {
  if (!isExecutiveUser()) return;

  // Hide mutating actions on executive-visible pages for board/MD read-only access.
  const selectors = [
    '#page-admin-exceptions .btn.btn-sm', // queue action buttons
    '#page-admin-exceptions .btn.btn-red', // any red action buttons
  ];

  selectors.forEach((selector) => {
    document.querySelectorAll(selector).forEach((el) => {
      el.style.display = 'none';
    });
  });
}

async function initApp() {
  try {
    const data = await LapokAPI.get('/api/auth/me.php');
    currentUser = data.user;
  } catch {
    location.href = 'login.html';
    return;
  }
  if (!PRIMARY_SYSTEM_ROLES.includes(currentUser.role)) {
    console.info(`Role "${currentUser.role}" logged in with limited access in this release.`);
  }
  const allowed = window.LAPOK_ALLOWED_ROLES;
  const disabled = window.LAPOK_DISABLED_ROLES || [];
  if ((Array.isArray(allowed) && allowed.length && !allowed.includes(currentUser.role))
    || disabled.includes(currentUser.role)) {
    try { await LapokAPI.post('/api/auth/logout.php', {}); } catch (_) {}
    location.href = 'login.html?role=disabled';
    return;
  }
  applyUserSession(currentUser);
  await refreshDashboardData();
}

function navToGroups(nav) {
  const groups = [];
  let current = null;
  nav.forEach((n) => {
    if (n.section) {
      current = { title: n.section, items: [] };
      groups.push(current);
    } else if (n.id) {
      if (!current) {
        current = { title: 'Menu', items: [] };
        groups.push(current);
      }
      current.items.push(n);
    }
  });
  return groups.filter((g) => g.items.length);
}

function renderNavMenu(nav) {
  return navToGroups(nav).map((g, gi) => {
    const gid = 'nav-grp-' + gi;
    const itemsHtml = g.items.map((n) =>
      `<div class="nav-item" onclick="showPage('${n.id}');closeSidebar()" id="nav-${n.id}">${ICONS[n.i] || ''}${n.l}</div>`
    ).join('');
    return `<div class="nav-group" data-nav-group="${gid}">
      <button type="button" class="nav-group-header" onclick="toggleNavGroup('${gid}')" aria-expanded="false">
        <span>${g.title}</span><span class="nav-group-chevron" aria-hidden="true">›</span>
      </button>
      <div class="nav-group-items" id="${gid}">${itemsHtml}</div>
    </div>`;
  }).join('');
}

function toggleNavGroup(groupId) {
  const itemsEl = document.getElementById(groupId);
  const group = itemsEl?.closest('.nav-group');
  if (!group) return;
  const willOpen = !group.classList.contains('open');
  document.querySelectorAll('.nav-group.open').forEach((g) => {
    if (g !== group) {
      g.classList.remove('open');
      g.querySelector('.nav-group-header')?.setAttribute('aria-expanded', 'false');
    }
  });
  group.classList.toggle('open', willOpen);
  group.querySelector('.nav-group-header')?.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
}

function expandNavGroupForPage(pageId) {
  const item = document.getElementById('nav-' + pageId);
  const group = item?.closest('.nav-group');
  if (!group) return;
  group.classList.add('open');
  group.querySelector('.nav-group-header')?.setAttribute('aria-expanded', 'true');
}

window.toggleNavGroup = toggleNavGroup;
window.expandNavGroupForPage = expandNavGroupForPage;

function applyUserSession(user) {
  const role = user.role;
  const nav = LapokAPI.roleNav[role] || LapokAPI.roleNav.field_user || [];
  const items = LapokAPI.navItems(nav);
  document.getElementById('sidebarName').textContent = user.full_name;
  document.getElementById('sidebarEmail').textContent = user.email;
  const rb = document.getElementById('roleBadge');
  rb.textContent = LapokAPI.roleLabel[role] || role;
  rb.className = 'role-pill ' + (LapokAPI.rolePill[role] || 'rp-user');
  document.getElementById('navMenu').innerHTML = renderNavMenu(nav);
  items.forEach((n) => { LABELS[n.id] = n.l; });
  const homePage = LapokAPI.roleHomePage?.[role] || items[0]?.id;
  if (homePage) {
    showPage(homePage);
  }
  applyExecutiveReadOnlyMode();
  if (typeof initNotifications === 'function') initNotifications();
}

async function refreshDashboardData() {
  await Promise.allSettled([
    loadStockTable(),
    loadPendingOrders(),
    loadEditRequests(),
    loadLowStockAlerts(),
  ]);
}

async function loadStockTable() {
  const table = document.getElementById('mgrStockTable') || document.querySelector('#page-manager-stock table');
  if (!table) return;
  try {
    const data = await LapokAPI.get('/api/stock/fetch_stock.php');
    productCatalog = data.stock || [];
    const rows = productCatalog.map((s) => {
      const low = s.low_stock ? `<span class="badge bd">${s.warehouse_qty}</span>` : s.warehouse_qty;
      const exp = s.expiring_soon ? ' style="background:#FFFBEB"' : '';
      return `<tr${exp} data-product-id="${s.product_id}">
        <td>${s.name}</td><td>${s.sku}</td><td>${low}</td><td>${s.on_vehicles_qty}</td><td>${s.sold_today}</td>
        <td><div class="progress-bar"><div class="progress-fill ${LapokAPI.progressClass(s.level_percent)}" style="width:${s.level_percent}%"></div></div></td>
      </tr>`;
    }).join('');
    table.innerHTML = '<tr><th>Product</th><th>SKU</th><th>Warehouse</th><th>With vehicles</th><th>Sold today</th><th>Level</th></tr>' + rows;

    const whEl = document.querySelector('#page-admin-dashboard .metric-card.hi .metric-value');
    if (whEl && data.summary) whEl.textContent = Number(data.summary.total_warehouse_cartons).toLocaleString();
  } catch (e) {
    console.warn('Stock load failed:', e.message);
  }
}

async function loadLowStockAlerts() {
  try {
    const data = await LapokAPI.get('/api/stock/fetch_stock.php?low_only=1');
    const alerts = data.alerts || [];
    document.querySelectorAll('.alert.a-danger').forEach((el) => {
      if (alerts.length) {
        const names = alerts.map((a) => `${a.name} (${a.warehouse_qty} cartons)`).join(' and ');
        el.querySelector('div').innerHTML = `<strong>Low stock:</strong> ${names} below minimum. <a href="#" onclick="showPage('admin-exceptions');return false" style="color:var(--red);font-weight:600">Open exception center →</a>`;
      }
    });
  } catch (_) {}
}

async function loadPendingOrders() {
  const mgrTable = document.getElementById('mgrPendingSalesTable');
  if (!mgrTable) return;
  try {
    const data = await LapokAPI.get('/api/orders/fetch_orders.php?status=pending');
    const orders = data.orders || [];
    const badge = document.querySelector('#page-manager-dashboard .card-header .badge.bd');
    if (badge) badge.textContent = orders.length + ' pending';
    const metric = document.querySelector('#page-manager-dashboard .metric-card:nth-child(3) .metric-value');
    if (metric) metric.textContent = orders.length;
    const rows = orders.map((o) =>
      `<tr data-order-id="${o.id}">
        <td>${o.user_name?.split(' ')[0] || '—'}.</td>
        <td>${o.vehicle_reg ? `<span class="badge b-tuk">${o.vehicle_reg}</span>` : '—'}</td>
        <td>${o.customer_name || '—'}</td>
        <td>${Number(o.amount_total).toLocaleString()}</td>
        <td>${LapokAPI.formatTime(o.created_at)}</td>
        <td><button class="btn btn-sm btn-red" onclick="confirmSale(this,${o.id})">Confirm</button></td>
      </tr>`
    ).join('');
    mgrTable.innerHTML = '<tr><th>User</th><th>Vehicle</th><th>Customer</th><th>Amount</th><th>Time</th><th>Action</th></tr>' +
      (rows || '<tr><td colspan="6" style="color:var(--gray-mid)">No pending sales</td></tr>');
  } catch (e) {
    console.warn('Orders load failed:', e.message);
  }
}

async function loadEditRequests() {
  const pages = ['#page-admin-editreqs table'];
  try {
    const data = await LapokAPI.get('/api/orders/fetch_requests.php');
    const reqs = data.requests || [];
    document.querySelectorAll('#page-admin-editreqs .badge.bd').forEach((b) => {
      b.textContent = reqs.length + ' pending';
    });
    const rowHtml = (r, full) => full
      ? `<tr data-request-id="${r.id}"><td style="font-family:monospace;font-size:11px">${r.order_ref}</td><td>${r.user_name?.split(' ')[0] || '—'}.</td><td><span class="badge ${r.request_type === 'edit' ? 'bw' : 'bd'}">${r.request_type === 'edit' ? 'Edit' : 'Cancel'}</span></td><td>${r.reason}</td><td>${r.details || '—'}</td><td>${LapokAPI.formatTime(r.created_at)}</td><td><button class="btn btn-sm btn-red" onclick="approveReq(this,'approved',${r.id})">Approve</button> <button class="btn btn-sm" onclick="approveReq(this,'rejected',${r.id})">Reject</button></td></tr>`
      : `<tr data-request-id="${r.id}"><td style="font-family:monospace;font-size:11px">${r.order_ref}</td><td>${r.user_name?.split(' ')[0] || '—'}.</td><td><span class="badge ${r.request_type === 'edit' ? 'bw' : 'bd'}">${r.request_type === 'edit' ? 'Edit' : 'Cancel'}</span></td><td><button class="btn btn-sm btn-red" onclick="approveReq(this,'approved',${r.id})">Approve</button></td></tr>`;
    const adminTable = document.querySelector('#page-admin-editreqs table');
    if (adminTable) {
      adminTable.innerHTML = '<tr><th>Ref</th><th>User</th><th>Type</th><th>Reason</th><th>Details</th><th>Time</th><th>Action</th></tr>' +
        (reqs.map((r) => rowHtml(r, true)).join('') || '<tr><td colspan="7">No pending requests</td></tr>');
    }
  } catch (e) {
    console.warn('Requests load failed:', e.message);
  }
}

async function confirmSale(btn, orderId) {
  if (!orderId) {
    btn.textContent = '✓ Done';
    btn.disabled = true;
    btn.closest('tr').style.opacity = '.5';
    return;
  }
  btn.disabled = true;
  btn.textContent = '…';
  try {
    await LapokAPI.post('/api/orders/confirm_order.php', { order_id: orderId });
    btn.textContent = '✓ Done';
    btn.className = 'btn btn-sm';
    btn.closest('tr').style.opacity = '.5';
    await loadPendingOrders();
  } catch (e) {
    alert(e.message);
    btn.disabled = false;
    btn.textContent = 'Confirm';
  }
}

async function approveReq(btn, action, requestId) {
  const row = btn.closest('tr');
  if (!requestId) {
    row.style.opacity = '.5';
    row.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    const last = row.cells[row.cells.length - 1];
    last.innerHTML = action === 'approved' ? '<span class="badge bs">Approved</span>' : '<span class="badge bd">Rejected</span>';
    return;
  }
  row.querySelectorAll('button').forEach((b) => { b.disabled = true; });
  try {
    await LapokAPI.post('/api/orders/approve_request.php', { request_id: requestId, action });
    row.style.opacity = '.5';
    const last = row.cells[row.cells.length - 1];
    last.innerHTML = action === 'approved' ? '<span class="badge bs">Approved</span>' : '<span class="badge bd">Rejected</span>';
    await loadEditRequests();
  } catch (e) {
    alert(e.message);
    row.querySelectorAll('button').forEach((b) => { b.disabled = false; });
  }
}

async function saveDelivery() {
  const modal = document.getElementById('incomingModal');
  const dateInp = modal.querySelector('input[type="date"]');
  const items = [];
  modal.querySelectorAll('tbody tr').forEach((tr, i) => {
    const product = productCatalog[i];
    if (!product) return;
    const inputs = tr.querySelectorAll('input');
    const qtyDelivered = parseInt(inputs[1]?.value) || 0;
    if (qtyDelivered <= 0) return;
    items.push({
      product_id: product.product_id,
      qty_ordered: parseInt(inputs[0]?.value) || qtyDelivered,
      qty_delivered: qtyDelivered,
      batch_number: inputs[2]?.value || `BATCH-${product.sku}-${Date.now()}`,
      expiry_date: inputs[3]?.value || new Date(Date.now() + 180 * 86400000).toISOString().slice(0, 10),
      unit_cost: parseFloat(inputs[4]?.value) || product.unit_price * 0.6,
    });
  });
  if (!items.length) {
    alert('Enter delivered quantities');
    return;
  }
  try {
    await LapokAPI.post('/api/stock/receive_delivery.php', {
      delivery_date: dateInp?.value || new Date().toISOString().slice(0, 10),
      waybill: modal.querySelector('input[placeholder*="Waybill"]')?.value || '',
      items,
    });
    closeModal('incomingModal');
    await loadStockTable();
    alert('Delivery recorded. Stock updated.');
  } catch (e) {
    alert(e.message);
  }
}

async function logout() {
  try { await LapokAPI.post('/api/auth/logout.php', {}); } catch (_) {}
  location.href = 'login.html';
}

document.addEventListener('DOMContentLoaded', () => {
  const originalShowPage = window.showPage;
  if (typeof originalShowPage === 'function') {
    window.showPage = function (id) {
      originalShowPage(id);
      expandNavGroupForPage(id);
      applyExecutiveReadOnlyMode();
      if (id === 'manager-stock') { loadStockTable(); if (typeof loadDeliveryList === 'function') loadDeliveryList(); }
      if (id === 'manager-dashboard') { loadPendingOrders(); loadLowStockAlerts(); if (typeof loadManagerDashboardExtras === 'function') loadManagerDashboardExtras(); }
      if (id === 'admin-editreqs') loadEditRequests();
      if (id === 'admin-exceptions' && typeof loadExceptionsPage === 'function') loadExceptionsPage();
      if (id === 'admin-dashboard') { loadLowStockAlerts(); setTimeout(drawCharts, 100); }
    };
  }
  initApp();
});
