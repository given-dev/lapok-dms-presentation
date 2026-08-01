/**
 * Cash outs — cadets issue credit to customers (amount out), record
 * recoveries over time, and settle when the balance reaches zero.
 * Manager / RDC (accountant) get a read-only overview.
 */
(function () {
  let coState = { cashouts: [], open: [], settled: [], summary: {} };
  let coMode = 'view';
  let coModalsCreated = false;
  let coCurrentCashout = null;
  let coActiveMount = null;

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  /** Elements are duplicated across the three mount points — always scope to the active mount. */
  function coEl(id) {
    const root = document.getElementById(coActiveMount);
    return root ? root.querySelector('#' + id) : null;
  }

  function money(n) {
    return 'UGX ' + Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 });
  }

  function statusBadge(status) {
    return status === 'settled'
      ? '<span class="badge bs">Settled</span>'
      : '<span class="badge bw">Open</span>';
  }

  function buildCard(mode) {
    const manage = mode === 'manage';
    const head = manage
      ? '<button class="btn btn-sm btn-red" type="button" onclick="openCashoutCreate()">+ New cash out</button>'
      : '';
    return `<div class="card">
      <div class="card-header">
        <span class="card-title">Cash outs</span>
        <span style="margin-left:auto;display:flex;gap:8px">
          ${head}
          <button class="btn btn-sm" type="button" onclick="refreshCashouts()">Refresh</button>
        </span>
      </div>
      <div class="metric-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:.8rem">
        <div class="metric-card"><div class="metric-label">Open cash outs</div><div class="metric-value" id="coSumOpen">—</div></div>
        <div class="metric-card"><div class="metric-label">Outstanding</div><div class="metric-value" id="coSumBalance">—</div></div>
        <div class="metric-card"><div class="metric-label">Given out</div><div class="metric-value" id="coSumOut">—</div></div>
        <div class="metric-card"><div class="metric-label">Recovered</div><div class="metric-value" id="coSumPaid">—</div></div>
      </div>
      <div class="tbl-wrap"><table id="coTable"></table></div>
      <details class="co-settled" style="margin-top:.6rem">
        <summary>Settled history</summary>
        <div class="tbl-wrap" style="margin-top:.4rem"><table id="coSettledTable"></table></div>
      </details>
    </div>`;
  }

  function buildRows(cashouts, manage) {
    if (!cashouts.length) {
      return `<tr><td colspan="${manage ? 8 : 9}" style="color:var(--gray-mid);text-align:center">No cash outs yet.</td></tr>`;
    }
    return cashouts.map((c) => {
      const recover = manage && c.status === 'open'
        ? `<button class="btn btn-sm" type="button" onclick="openCashoutRecover(${c.id})">Recover</button>`
        : '';
      const cadetCell = manage ? '' : `<td>${esc(c.cadet_name || '—')}</td>`;
      return `<tr>
        ${cadetCell}<td><strong>${esc(c.customer_name)}</strong><div style="font-size:11px;color:var(--gray-mid)">${esc(c.location || '')}</div></td>
        <td>${esc(c.nin || '—')}</td><td>${esc(c.phone || '—')}</td>
        <td>${money(c.amount_out)}</td><td>${money(c.paid_total)}</td>
        <td><strong>${money(c.balance)}</strong></td>
        <td>${statusBadge(c.status)}</td><td>${recover}</td>
      </tr>`;
    }).join('');
  }

  function renderCard() {
    const s = coState.summary || {};
    const set = (id, v) => { const el = coEl(id); if (el) el.textContent = v; };
    set('coSumOpen', String(s.open_count ?? 0));
    set('coSumBalance', money(s.open_balance));
    set('coSumOut', money(s.total_out));
    set('coSumPaid', money(s.total_paid));

    const manage = coMode === 'manage';
    const headers = (manage ? '' : '<th>Cadet</th>') +
      '<th>Customer</th><th>NIN</th><th>Phone</th><th>Out</th><th>Paid</th><th>Balance</th><th>Status</th><th></th>';
    const table = coEl('coTable');
    if (table) {
      table.innerHTML = `<thead><tr>${headers}</tr></thead><tbody>${buildRows(coState.open, manage)}</tbody>`;
    }
    const settled = coEl('coSettledTable');
    if (settled) {
      settled.innerHTML = `<thead><tr>${headers}</tr></thead><tbody>${buildRows(coState.settled, manage)}</tbody>`;
    }
  }

  async function refreshCashouts() {
    if (!coActiveMount || !document.getElementById(coActiveMount)) return;
    try {
      const data = await LapokAPI.get('/api/cashouts/list.php');
      coState.cashouts = data.cashouts || [];
      coState.open = data.open || [];
      coState.settled = data.settled || [];
      coState.summary = data.summary || {};
      renderCard();
    } catch (e) {
      const table = coEl('coTable');
      if (table) {
        table.innerHTML = `<tbody><tr><td colspan="8" style="color:var(--red)">${esc(e.message)}</td></tr></tbody>`;
      }
    }
  }

  function createModals() {
    if (coModalsCreated) return;
    coModalsCreated = true;
    const modals = document.createElement('div');
    modals.innerHTML = `
      <div class="modal-overlay" id="cashoutCreateModal"><div class="modal">
        <div class="modal-handle"></div>
        <div class="modal-title">New cash out</div>
        <div class="alert a-danger" id="cashoutCreateErr" style="display:none"></div>
        <div class="form-section">Customer</div>
        <div class="form-group"><label>Customer</label>
          <select class="select-inp" id="cashoutCustomerSel"><option value="">— Select customer —</option></select>
        </div>
        <button class="btn btn-sm" type="button" onclick="toggleNewCashoutCustomer()" id="cashoutToggleNewBtn">＋ Add new customer</button>
        <div id="cashoutNewCustomerFields" style="display:none;margin-top:.6rem">
          <div class="form-row">
            <div class="form-group"><label>Customer name</label><input class="input" id="cashoutNewName" placeholder="Full name"></div>
            <div class="form-group"><label>NIN</label><input class="input" id="cashoutNewNin" placeholder="National ID number"></div>
          </div>
          <div class="form-group"><label>Phone</label><input class="input" id="cashoutNewPhone" placeholder="+256 7XX XXXXXX"></div>
        </div>
        <div class="form-section">Cash out</div>
        <div class="form-row">
          <div class="form-group"><label>Amount out (UGX)</label><input class="input" id="cashoutAmountOut" type="number" min="0" step="500" placeholder="e.g. 200000"></div>
          <div class="form-group"><label>Notes</label><input class="input" id="cashoutNotes" maxlength="255" placeholder="Optional"></div>
        </div>
        <div class="modal-actions">
          <button class="btn" type="button" onclick="closeModal('cashoutCreateModal')">Cancel</button>
          <button class="btn btn-red" type="button" onclick="submitNewCashout()">Save cash out</button>
        </div>
      </div></div>
      <div class="modal-overlay" id="cashoutRecoverModal"><div class="modal">
        <div class="modal-handle"></div>
        <div class="modal-title">Record recovery</div>
        <div class="alert a-info"><span>ℹ</span><div>Payment from <strong id="coRecoverCustomer">—</strong> · Outstanding: <strong id="coRecoverBalance">—</strong></div></div>
        <div class="alert a-danger" id="cashoutRecoverErr" style="display:none"></div>
        <div class="form-row">
          <div class="form-group"><label>Amount received (UGX)</label><input class="input" id="cashoutRecoverAmount" type="number" min="0" step="500"></div>
          <div class="form-group"><label>Date received</label><input class="input" id="cashoutRecoverDate" type="date"></div>
        </div>
        <div class="modal-actions">
          <button class="btn" type="button" onclick="closeModal('cashoutRecoverModal')">Cancel</button>
          <button class="btn btn-red" type="button" onclick="submitCashoutRecovery()">Save recovery</button>
        </div>
      </div></div>`;
    document.body.appendChild(modals);
  }

  async function openCashoutCreate() {
    const err = document.getElementById('cashoutCreateErr');
    if (err) { err.style.display = 'none'; err.textContent = ''; }
    resetCashoutCreateForm();
    try {
      const data = await LapokAPI.get('/api/customers/fetch_customers.php?search=');
      const sel = document.getElementById('cashoutCustomerSel');
      if (sel) {
        sel.innerHTML = '<option value="">— Select customer —</option>' +
          (data.customers || []).map((c) => {
            const label = [c.name, c.phone, c.nin].filter(Boolean).join(' · ');
            return `<option value="${c.id}">${esc(label)}</option>`;
          }).join('');
      }
    } catch (_) { /* customer list optional — can add new */ }
    if (typeof openModal === 'function') openModal('cashoutCreateModal');
  }

  function toggleNewCashoutCustomer() {
    const box = document.getElementById('cashoutNewCustomerFields');
    const btn = document.getElementById('cashoutToggleNewBtn');
    const sel = document.getElementById('cashoutCustomerSel');
    if (!box) return;
    const show = box.style.display === 'none';
    box.style.display = show ? 'block' : 'none';
    if (btn) btn.textContent = show ? '✕ Use existing customer instead' : '＋ Add new customer';
    if (sel) sel.disabled = show;
  }

  function resetCashoutCreateForm() {
    const ids = ['cashoutCustomerSel', 'cashoutNewName', 'cashoutNewNin', 'cashoutNewPhone', 'cashoutAmountOut', 'cashoutNotes'];
    ids.forEach((id) => { const el = document.getElementById(id); if (el) el.value = ''; });
    const box = document.getElementById('cashoutNewCustomerFields');
    const btn = document.getElementById('cashoutToggleNewBtn');
    const sel = document.getElementById('cashoutCustomerSel');
    if (box) box.style.display = 'none';
    if (btn) btn.textContent = '＋ Add new customer';
    if (sel) sel.disabled = false;
  }

  async function submitNewCashout() {
    try {
      const amountOut = parseFloat(document.getElementById('cashoutAmountOut').value || '0');
      if (!(amountOut > 0)) throw new Error('Enter an amount greater than zero');

      let customerId = parseInt(document.getElementById('cashoutCustomerSel')?.value || '0', 10);
      const addingNew = document.getElementById('cashoutNewCustomerFields')?.style.display !== 'none';
      if (addingNew) {
        const name = document.getElementById('cashoutNewName').value.trim();
        if (!name) throw new Error('Customer name is required for a new customer');
        const res = await LapokAPI.post('/api/customers/create_customer.php', {
          name,
          nin: document.getElementById('cashoutNewNin').value.trim(),
          phone: document.getElementById('cashoutNewPhone').value.trim(),
          location: '',
        });
        customerId = res.customer_id;
      }
      if (!(customerId > 0)) throw new Error('Select or add a customer');

      await LapokAPI.post('/api/cashouts/create.php', {
        customer_id: customerId,
        amount_out: amountOut,
        notes: document.getElementById('cashoutNotes').value.trim(),
      });
      if (typeof closeModal === 'function') closeModal('cashoutCreateModal');
      if (typeof adminToast === 'function') adminToast('Cash out recorded');
      resetCashoutCreateForm();
      refreshCashouts();
    } catch (e) {
      const err = document.getElementById('cashoutCreateErr');
      if (err) { err.style.display = 'block'; err.textContent = e.message; }
      else if (typeof adminToast === 'function') adminToast(e.message, true);
    }
  }

  function openCashoutRecover(id) {
    const cashout = coState.cashouts.find((c) => Number(c.id) === Number(id));
    if (!cashout) return;
    coCurrentCashout = cashout;
    const err = document.getElementById('cashoutRecoverErr');
    if (err) { err.style.display = 'none'; err.textContent = ''; }
    const cust = document.getElementById('coRecoverCustomer');
    if (cust) cust.textContent = cashout.customer_name || '—';
    const bal = document.getElementById('coRecoverBalance');
    if (bal) bal.textContent = money(cashout.balance);
    const amount = document.getElementById('cashoutRecoverAmount');
    if (amount) { amount.value = ''; amount.max = String(cashout.balance); }
    const date = document.getElementById('cashoutRecoverDate');
    if (date) date.value = LapokAPI.todayIso();
    if (typeof openModal === 'function') openModal('cashoutRecoverModal');
  }

  async function submitCashoutRecovery() {
    if (!coCurrentCashout) return;
    try {
      const amount = parseFloat(document.getElementById('cashoutRecoverAmount').value || '0');
      if (!(amount > 0)) throw new Error('Enter an amount greater than zero');
      const paidOn = document.getElementById('cashoutRecoverDate').value || LapokAPI.todayIso();
      const res = await LapokAPI.post('/api/cashouts/recover.php', {
        cashout_id: coCurrentCashout.id,
        amount,
        paid_on: paidOn,
      });
      if (typeof closeModal === 'function') closeModal('cashoutRecoverModal');
      if (typeof adminToast === 'function') {
        adminToast(res.settled ? `Settled — balance is now 0` : `Recovery recorded · balance ${money(res.balance)}`);
      }
      coCurrentCashout = null;
      refreshCashouts();
    } catch (e) {
      const err = document.getElementById('cashoutRecoverErr');
      if (err) { err.style.display = 'block'; err.textContent = e.message; }
      else if (typeof adminToast === 'function') adminToast(e.message, true);
    }
  }

  function initCashouts(mountId, mode) {
    const mount = document.getElementById(mountId);
    if (!mount) return;
    coActiveMount = mountId;
    coMode = mode || 'view';
    mount.innerHTML = buildCard(coMode);
    createModals();
    refreshCashouts();
  }

  // Page hooks — re-init whenever the relevant page is shown.
  const origShowPage = window.showPage;
  window.showPage = function (id) {
    if (typeof origShowPage === 'function') origShowPage(id);
    if (id === 'cadet-dashboard') initCashouts('cashoutsMountCadet', 'manage');
    if (id === 'manager-dashboard') initCashouts('cashoutsMountManager', 'view');
    if (id === 'accountant-rdc-hub') initCashouts('cashoutsMountRdc', 'view');
  };

  window.initCashouts = initCashouts;
  window.refreshCashouts = refreshCashouts;
  window.openCashoutCreate = openCashoutCreate;
  window.toggleNewCashoutCustomer = toggleNewCashoutCustomer;
  window.submitNewCashout = submitNewCashout;
  window.openCashoutRecover = openCashoutRecover;
  window.submitCashoutRecovery = submitCashoutRecovery;
})();
