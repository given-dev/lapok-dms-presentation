/**
 * Lapok DMS — session auth + role navigation + user management
 */
const LapokAPI = {
  async request(path, options = {}) {
    const res = await fetch(path, {
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
      ...options,
    });

    let payload = null;
    try {
      payload = await res.json();
    } catch {
      throw new Error(res.ok ? 'Invalid server response' : `Request failed (${res.status})`);
    }

    if (!res.ok || payload.success === false) {
      throw new Error(payload.error || `Request failed (${res.status})`);
    }

    return payload.data ?? payload;
  },

  login(email, password) {
    return this.request('api/auth/login.php', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    });
  },

  logout() {
    return this.request('api/auth/logout.php', { method: 'POST', body: '{}' });
  },

  me() {
    return this.request('api/auth/me.php');
  },

  changePassword(currentPassword, newPassword, confirmPassword) {
    return this.request('api/auth/change_password.php', {
      method: 'POST',
      body: JSON.stringify({
        current_password: currentPassword,
        new_password: newPassword,
        confirm_password: confirmPassword,
      }),
    });
  },

  fetchAuditLog(limit = 100, filters = {}) {
    const params = new URLSearchParams({ limit });
    if (filters.action)  params.set('action', filters.action);
    if (filters.table)   params.set('table', filters.table);
    if (filters.user)    params.set('user', filters.user);
    return this.request(`api/audit/fetch_log.php?${params}`);
  },

  fetchUsers() {
    return this.request('api/users/fetch_users.php');
  },

  createUser(data) {
    return this.request('api/users/create_user.php', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },

  updateUser(data) {
    return this.request('api/users/update_user.php', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },
};

/* ────────────────────────────────────────────────────────────────
   ROLE METADATA
   ──────────────────────────────────────────────────────────────── */
const ROLE_META = {
  admin:      { pill: 'rp-admin',   badge: 'Admin',      uiKey: 'admin' },
  executive:  { pill: 'rp-admin',   badge: 'Executive',  uiKey: 'admin' },
  manager:    { pill: 'rp-manager', badge: 'Manager',    uiKey: 'manager' },
  accountant: { pill: 'rp-manager', badge: 'Accountant', uiKey: 'accountant' },
  driver:     { pill: 'rp-user',   badge: 'Driver',     uiKey: 'driver' },
  cadet:      { pill: 'rp-user',   badge: 'Cadet',      uiKey: 'cadet' },
  field_user: { pill: 'rp-user',   badge: 'Field User', uiKey: 'user' },
};

function mapRoleToUi(role)    { return ROLE_META[role]?.uiKey  || 'user'; }
function roleBadgeClass(role) { return ROLE_META[role]?.pill   || 'rp-user'; }
function roleBadgeLabel(role) { return ROLE_META[role]?.badge  || role; }

/* ────────────────────────────────────────────────────────────────
   SESSION / AUTH
   ──────────────────────────────────────────────────────────────── */
async function initAuth() {
  try {
    const data = await LapokAPI.me();
    if (!data.authenticated || !data.user) {
      window.location.href = 'login.html';
      return null;
    }
    window.LAPOK_USER = data.user;
    applySessionUser(data.user);
    return data.user;
  } catch {
    window.location.href = 'login.html';
    return null;
  }
}

function applySessionUser(user) {
  const initials = user.name.split(' ').map((p) => p[0]).join('').slice(0, 2).toUpperCase();
  const nameEl  = document.getElementById('sidebarName');
  const emailEl = document.getElementById('sidebarEmail');
  const badgeEl = document.getElementById('roleBadge');

  if (nameEl)  nameEl.textContent  = user.name;
  if (emailEl) emailEl.textContent = user.email;
  if (badgeEl) {
    badgeEl.textContent = roleBadgeLabel(user.role);
    badgeEl.className   = 'role-pill ' + roleBadgeClass(user.role);
  }

  const avatarEl = document.getElementById('sidebarAvatar');
  if (avatarEl) avatarEl.textContent = initials;

  const uiRole = mapRoleToUi(user.role);
  if (typeof switchRole === 'function') switchRole(uiRole, user);
  if (typeof drawCharts === 'function') setTimeout(drawCharts, 300);
}

async function logoutApp() {
  try { await LapokAPI.logout(); } catch { /* redirect anyway */ }
  window.location.href = 'login.html';
}

/* ────────────────────────────────────────────────────────────────
   PASSWORD CHANGE
   ──────────────────────────────────────────────────────────────── */
async function submitPasswordChange(event) {
  event.preventDefault();
  const err = document.getElementById('profileErr');
  const ok  = document.getElementById('profileOk');
  if (err) err.style.display = 'none';
  if (ok)  ok.style.display  = 'none';

  const current = document.getElementById('currentPassword')?.value || '';
  const next    = document.getElementById('newPassword')?.value     || '';
  const confirm = document.getElementById('confirmPassword')?.value || '';

  try {
    await LapokAPI.changePassword(current, next, confirm);
    if (ok) { ok.textContent = 'Password updated successfully.'; ok.style.display = 'block'; }
    event.target.reset();
  } catch (ex) {
    if (err) { err.textContent = ex.message; err.style.display = 'block'; }
  }
}

/* ────────────────────────────────────────────────────────────────
   AUDIT LOG  — rich rendering
   ──────────────────────────────────────────────────────────────── */
const ACTION_STYLES = {
  create: { cls: 'bs',   label: 'Created' },
  update: { cls: 'bi',   label: 'Updated' },
  delete: { cls: 'bd',   label: 'Deleted' },
  login:  { cls: 'bg',   label: 'Login'   },
  logout: { cls: 'bgold',label: 'Logout'  },
};

function renderChangeDiff(oldVal, newVal) {
  if (!oldVal && !newVal) return '<span style="color:var(--gray-mid)">—</span>';
  if (!oldVal) return renderJsonChips(newVal, false);
  if (!newVal) return renderJsonChips(oldVal, true);

  // Show only keys that changed
  const allKeys = new Set([...Object.keys(oldVal || {}), ...Object.keys(newVal || {})]);
  const diffs = [];
  allKeys.forEach((k) => {
    const o = String(oldVal[k] ?? '');
    const n = String(newVal[k] ?? '');
    if (o !== n) {
      diffs.push(`<span style="color:var(--gray-mid);font-size:10px">${k}:</span> `
        + (o ? `<span style="text-decoration:line-through;color:#ef4444;font-size:10px">${esc(o)}</span> ` : '')
        + `<span style="color:#16a34a;font-size:10px">→ ${esc(n)}</span>`);
    }
  });
  return diffs.length ? diffs.join(' &nbsp;·&nbsp; ') : '<span style="color:var(--gray-mid)">—</span>';
}

function renderJsonChips(obj, isOld) {
  if (!obj || typeof obj !== 'object') return esc(String(obj));
  return Object.entries(obj)
    .filter(([, v]) => v !== null && v !== '')
    .map(([k, v]) => `<span style="font-size:10px;color:var(--gray-mid)">${k}:</span> `
      + `<span style="font-size:10px;color:${isOld ? '#ef4444' : '#16a34a'}">${esc(String(v))}</span>`)
    .join(' &nbsp;·&nbsp; ') || '<span style="color:var(--gray-mid)">—</span>';
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function loadAuditLog() {
  const tbody  = document.getElementById('auditLogBody');
  const count  = document.getElementById('auditCount');
  if (!tbody || !window.LAPOK_USER || window.LAPOK_USER.role !== 'admin') return;

  // Read filter values
  const actionFilter = document.getElementById('auditFilterAction')?.value || '';
  const tableFilter  = document.getElementById('auditFilterTable')?.value  || '';
  const userFilter   = document.getElementById('auditFilterUser')?.value   || '';

  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--gray-mid)">Loading…</td></tr>';
  if (count) count.textContent = '';

  try {
    const data    = await LapokAPI.fetchAuditLog(200, { action: actionFilter, table: tableFilter });
    let entries   = data.entries || [];

    // Client-side user filter (server doesn't filter by name)
    if (userFilter.trim()) {
      const q = userFilter.toLowerCase();
      entries = entries.filter((e) =>
        (e.user_name  || '').toLowerCase().includes(q) ||
        (e.user_email || '').toLowerCase().includes(q)
      );
    }

    if (count) count.textContent = entries.length + ' entries';

    if (!entries.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--gray-mid)">No audit entries match your filters.</td></tr>';
      return;
    }

    tbody.innerHTML = entries.map((e) => {
      const when    = new Date(e.logged_at.replace(' ', 'T')).toLocaleString('en-UG');
      const style   = ACTION_STYLES[e.action] || { cls: 'bg', label: e.action };
      const userAv  = (e.user_name || 'SYS').split(' ').map((p) => p[0]).join('').slice(0, 2).toUpperCase();
      const tableLabel = e.table_name.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
      const diff    = renderChangeDiff(e.old_values, e.new_values);

      return `<tr>
        <td style="white-space:nowrap;font-size:11.5px;color:var(--gray-mid)">${when}</td>
        <td>
          <div style="display:flex;align-items:center;gap:7px">
            <div class="avatar" style="width:28px;height:28px;font-size:10px;box-shadow:none;flex-shrink:0">${userAv}</div>
            <div>
              <div style="font-size:12.5px;font-weight:600;line-height:1.2">${esc(e.user_name || 'System')}</div>
              <div style="font-size:10px;color:var(--gray-mid)">${esc(e.user_email || '')}</div>
            </div>
          </div>
        </td>
        <td>
          <span style="font-size:11.5px;background:var(--surface);padding:2px 8px;border-radius:5px;border:1px solid var(--gray-light)">${tableLabel}</span>
          ${e.record_id ? `<span style="font-size:10px;color:var(--gray-mid);margin-left:4px">#${e.record_id}</span>` : ''}
        </td>
        <td><span class="badge ${style.cls}" style="font-size:11px">${style.label}</span></td>
        <td style="max-width:320px;line-height:1.7">${diff}</td>
      </tr>`;
    }).join('');
  } catch (ex) {
    tbody.innerHTML = `<tr><td colspan="6" style="color:var(--red);padding:1.5rem">${esc(ex.message)}</td></tr>`;
  }
}

/* ────────────────────────────────────────────────────────────────
   USER MANAGEMENT — load, render, modals
   ──────────────────────────────────────────────────────────────── */

// Cache of users for the current session load
window._umUsers = [];

const ROLE_BADGE = {
  admin:      '<span class="badge br">Admin</span>',
  manager:    '<span class="badge br" style="background:linear-gradient(90deg,#374151,#1f2937)">Manager</span>',
  accountant: '<span class="badge bi">Accountant</span>',
  driver:     '<span class="badge bs">Driver</span>',
  cadet:      '<span class="badge bgold">Cadet</span>',
  field_user: '<span class="badge bg">Field User</span>',
};

const VEHICLE_BADGE = {
  truck:  (v) => `<span class="badge b-truck">🚛 ${v.code}</span>`,
  tuktuk: (v) => `<span class="badge b-tuk">🛺 ${v.code}</span>`,
};

async function loadUserManagement() {
  const tbody  = document.getElementById('userTableBody');
  const count  = document.getElementById('umCount');
  if (!tbody) return;

  tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--gray-mid)">Loading…</td></tr>';

  try {
    const data = await LapokAPI.fetchUsers();
    window._umUsers = data.users || [];

    // Populate stat cards
    const active   = window._umUsers.filter((u) => u.is_active).length;
    const inactive = window._umUsers.length - active;
    const setS = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    setS('umStatTotal',    window._umUsers.length);
    setS('umStatActive',   active);
    setS('umStatInactive', inactive);

    renderUserTable(window._umUsers);
    if (count) count.textContent = window._umUsers.length + ' users';
  } catch (ex) {
    tbody.innerHTML = `<tr><td colspan="7" style="color:var(--red);padding:1.5rem">${esc(ex.message)}</td></tr>`;
  }
}

function renderUserTable(users) {
  const tbody = document.getElementById('userTableBody');
  if (!tbody) return;

  if (!users.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--gray-mid)">No users found.</td></tr>';
    return;
  }

  tbody.innerHTML = users.map((u) => {
    const initials = u.name.split(' ').map((p) => p[0]).join('').slice(0, 2).toUpperCase();
    const avColor  = u.is_active ? 'background:var(--gradient-primary)' : 'background:var(--gray-light);color:var(--gray)';
    const vehicle  = u.vehicle
      ? (VEHICLE_BADGE[u.vehicle.type]?.(u.vehicle) || `<span class="badge bg">${u.vehicle.code}</span>`)
      : '<span style="color:var(--gray-mid)">—</span>';

    return `<tr style="${!u.is_active ? 'opacity:.55' : ''}">
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div class="avatar" style="width:34px;height:34px;font-size:12px;flex-shrink:0;${avColor}">${initials}</div>
          <div>
            <div style="font-weight:600;font-size:13px">${esc(u.name)}</div>
            <div style="font-size:11px;color:var(--gray-mid)">${esc(u.email)}</div>
          </div>
        </div>
      </td>
      <td>${ROLE_BADGE[u.role] || `<span class="badge bg">${esc(u.role)}</span>`}</td>
      <td style="font-size:12px;color:var(--gray-mid)">${esc(u.national_id || '—')}</td>
      <td style="font-size:12px">${esc(u.phone || '—')}</td>
      <td>${vehicle}</td>
      <td>
        <label class="toggle" title="${u.is_active ? 'Active — click to deactivate' : 'Inactive — click to activate'}">
          <input type="checkbox" ${u.is_active ? 'checked' : ''} onchange="toggleUserActive(${u.id}, this.checked)">
          <span class="slider"></span>
        </label>
      </td>
      <td><button class="btn btn-sm" onclick="openEditUser(${u.id})">Edit</button></td>
    </tr>`;
  }).join('');
}

function filterUserTable(query) {
  const q    = query.toLowerCase();
  const role = document.getElementById('umRoleFilter')?.value || '';
  const filtered = window._umUsers.filter((u) => {
    const matchRole  = !role || u.role === role;
    const matchQuery = !q
      || u.name.toLowerCase().includes(q)
      || u.email.toLowerCase().includes(q)
      || (u.national_id || '').toLowerCase().includes(q)
      || (u.phone       || '').toLowerCase().includes(q)
      || u.role.toLowerCase().includes(q);
    return matchRole && matchQuery;
  });
  renderUserTable(filtered);
}

async function toggleUserActive(userId, active) {
  const user = window._umUsers.find((u) => u.id === userId);
  if (!user) return;
  try {
    await LapokAPI.updateUser({ id: userId, name: user.name, email: user.email, is_active: active });
    user.is_active = active;
  } catch (ex) {
    alert('Error: ' + ex.message);
    // Revert toggle
    loadUserManagement();
  }
}

function openEditUser(userId) {
  const u = window._umUsers.find((u) => u.id === userId);
  if (!u) return;

  const m = document.getElementById('editUserModal');
  if (!m) return;

  m.querySelector('.modal-title').textContent = 'Edit user — ' + u.name;
  _setVal(m, 'euId',        u.id);
  _setVal(m, 'euName',      u.name);
  _setVal(m, 'euEmail',     u.email);
  _setVal(m, 'euRole',      u.role);
  _setVal(m, 'euNatId',     u.national_id || '');
  _setVal(m, 'euPhone',     u.phone       || '');
  _setVal(m, 'euVehicle',   u.vehicle?.code || '');
  _setVal(m, 'euRoute',     u.route || '');

  // Clear password field
  const pwEl = m.querySelector('#euNewPassword');
  if (pwEl) pwEl.value = '';

  // Hide any previous error/success
  const editErr = document.getElementById('editUserErr');
  if (editErr) editErr.style.display = 'none';

  m.classList.add('open');
}

function _setVal(parent, id, val) {
  const el = parent.querySelector('#' + id);
  if (!el) return;
  if (el.tagName === 'SELECT') {
    const opt = el.querySelector(`option[value="${val}"]`);
    if (opt) el.value = val;
  } else {
    el.value = val;
  }
}

async function submitEditUser(event) {
  event.preventDefault();
  const errEl = document.getElementById('editUserErr');
  if (errEl) errEl.style.display = 'none';

  const id = parseInt(document.getElementById('euId')?.value || '0');

  const payload = {
    id,
    name:        document.getElementById('euName')?.value  || '',
    email:       document.getElementById('euEmail')?.value || '',
    role:        document.getElementById('euRole')?.value  || '',
    national_id: document.getElementById('euNatId')?.value || '',
    phone:       document.getElementById('euPhone')?.value || '',
  };

  const pw = document.getElementById('euNewPassword')?.value || '';
  if (pw) payload.new_password = pw;

  try {
    await LapokAPI.updateUser(payload);
    closeModal('editUserModal');
    await loadUserManagement();
  } catch (ex) {
    if (errEl) { errEl.textContent = ex.message; errEl.style.display = 'block'; }
  }
}

async function submitAddUser(event) {
  event.preventDefault();
  const errEl = document.getElementById('addUserErr');
  if (errEl) errEl.style.display = 'none';

  const payload = {
    name:        document.getElementById('auName')?.value     || '',
    email:       document.getElementById('auEmail')?.value    || '',
    role:        document.getElementById('auRole')?.value     || 'field_user',
    password:    document.getElementById('auPassword')?.value || '',
    national_id: document.getElementById('auNatId')?.value    || '',
    phone:       document.getElementById('auPhone')?.value    || '',
  };

  try {
    await LapokAPI.createUser(payload);
    closeModal('addUserModal');
    event.target.reset();
    await loadUserManagement();
  } catch (ex) {
    if (errEl) { errEl.textContent = ex.message; errEl.style.display = 'block'; }
  }
}

/* ────────────────────────────────────────────────────────────────
   DOMContentLoaded bootstrap
   ──────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  if (document.body.dataset.auth === 'required') {
    initAuth();
  }
});
