/**
 * Accountant month-end workspace — synced via API (all depot roles can view).
 */
(function () {
  const LEGACY_STORE_KEY = 'lapok.accountant.command.center.v1';
  let state = null;
  let latestMetrics = null;
  let readOnly = false;
  let currentMonth = '';

  function currentMonthIso() {
    return LapokAPI.monthIso();
  }

  function toast(msg, err) {
    if (typeof adminToast === 'function') adminToast(msg, !!err);
    else if (!err) alert(msg);
    else alert(msg);
  }

  function deepCopy(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function loadLegacyState() {
    try {
      const raw = localStorage.getItem(LEGACY_STORE_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      if (parsed.templates && typeof parsed.templates === 'object') {
        parsed.templates = { pnl: parsed.templates.pnl || '' };
      }
      return parsed;
    } catch (_) {
      return null;
    }
  }

  function clearLegacyState() {
    try { localStorage.removeItem(LEGACY_STORE_KEY); } catch (_) {}
  }

  function setReadOnlyBanner() {
    const page = document.getElementById('page-accountant-improvements');
    if (!page) return;
    let banner = document.getElementById('accMonthEndReadOnly');
    if (!readOnly) {
      if (banner) banner.remove();
      return;
    }
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'accMonthEndReadOnly';
      banner.className = 'alert a-info';
      banner.style.marginBottom = '1rem';
      page.querySelector('.rdc-bal-toolbar')?.after(banner);
    }
    banner.innerHTML = '<span>ℹ</span><div>View only for checklist/notes — those are edited by the accountant. <strong>Monthly fixed costs</strong> above stay editable for the manager.</div>';
  }

  function applyReadOnlyUi() {
    setReadOnlyBanner();
    const page = document.getElementById('page-accountant-improvements');
    if (!page) return;
    const role = (typeof currentUser !== 'undefined' && currentUser?.role) || '';
    const canEditFixed = role === 'manager' || role === 'admin';
    page.querySelectorAll('input, textarea, select, button').forEach((el) => {
      if (el.closest('.rdc-bal-toolbar')) return;
      if (el.id === 'accMonthPicker') return;

      const inFixed = !!el.closest('#mgrFixedCostsCard');
      if (inFixed) {
        if (canEditFixed) {
          el.removeAttribute('readonly');
          el.disabled = false;
          el.style.display = '';
        } else {
          if (el.tagName === 'BUTTON') {
            el.disabled = true;
            el.style.display = 'none';
          } else {
            el.setAttribute('readonly', 'readonly');
            if (el.tagName === 'SELECT' || el.type === 'checkbox') el.disabled = true;
          }
        }
        return;
      }

      if (readOnly) {
        if (el.tagName === 'BUTTON') {
          el.disabled = true;
          el.style.display = 'none';
        } else {
          el.setAttribute('readonly', 'readonly');
        }
        if (el.tagName === 'SELECT' || el.type === 'checkbox') el.disabled = true;
      } else {
        el.removeAttribute('readonly');
        if (el.tagName === 'SELECT' || el.type === 'checkbox' || el.tagName === 'BUTTON') el.disabled = false;
        if (el.tagName === 'BUTTON') el.style.display = '';
      }
    });
  }

  async function persistState(message) {
    if (readOnly || !state) return;
    try {
      const res = await LapokAPI.post('/api/rdc/save_month_end.php', {
        month: currentMonth,
        state,
      });
      state = res.state || state;
      updateSyncLabel(res.updated_by_name, res.updated_at);
      if (message) toast(message);
    } catch (e) {
      toast(e.message || 'Could not save month-end workspace', true);
    }
  }

  function updateSyncLabel(byName, at) {
    const el = document.getElementById('accMonthEndSync');
    if (!el) return;
    if (!at) {
      el.textContent = 'Not saved yet — shared across depot roles when you save.';
      return;
    }
    const when = new Date(at).toLocaleString('en-UG', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
    el.textContent = `Last saved ${when}${byName ? ' by ' + byName : ''} — synced for manager & leadership.`;
  }

  function fmtUgx(value) {
    return 'UGX ' + Number(value || 0).toLocaleString();
  }

  function renderChecklist() {
    const table = document.getElementById('accChecklistTable');
    if (!table || !state) return;
    const rows = state.checklist.map((item, idx) => `
      <tr>
        <td>${item.task}</td>
        <td><input class="input" value="${item.owner}" onchange="updateAccountantChecklist(${idx}, 'owner', this.value)" ${readOnly ? 'readonly' : ''}></td>
        <td><input class="input" value="${item.due}" onchange="updateAccountantChecklist(${idx}, 'due', this.value)" ${readOnly ? 'readonly' : ''}></td>
        <td>
          <select class="select-inp" onchange="updateAccountantChecklist(${idx}, 'status', this.value)" ${readOnly ? 'disabled' : ''}>
            <option value="pending" ${item.status === 'pending' ? 'selected' : ''}>Pending</option>
            <option value="in_progress" ${item.status === 'in_progress' ? 'selected' : ''}>In progress</option>
            <option value="done" ${item.status === 'done' ? 'selected' : ''}>Done</option>
          </select>
        </td>
      </tr>
    `).join('');
    table.innerHTML = '<tr><th>Task</th><th>Owner</th><th>Due</th><th>Status</th></tr>' + rows;
  }

  function renderProcessFields() {
    if (!state) return;
    const date = document.getElementById('accProcessReviewDate');
    const bottlenecks = document.getElementById('accBottlenecks');
    const sop = document.getElementById('accSopUpdates');
    const summary = document.getElementById('accMonthlySummary');
    if (date) date.value = state.processReviewDate;
    if (bottlenecks) bottlenecks.value = state.bottlenecks;
    if (sop) sop.value = state.sopUpdates;
    if (summary) summary.value = state.monthlySummary;
  }

  function updateKpis(metrics) {
    if (!metrics) return;
    const revenue = Number(metrics.revenue || 0);
    const expenses = Number(metrics.expenses || 0);
    const marginPct = revenue > 0 ? ((revenue - expenses) / revenue) * 100 : 0;
    const set = (id, value) => {
      const el = document.getElementById(id);
      if (el) el.textContent = value;
    };
    set('accKpiCashFlow', fmtUgx(revenue - expenses));
    set('accKpiReceivables', fmtUgx(metrics.total_receivables));
    set('accKpiMargin', marginPct.toFixed(1) + '%');
  }

  function renderAlerts(financial, cashData, meta) {
    const root = document.getElementById('accAlertsList');
    if (!root) return;
    const alerts = [];
    if (meta?.note) {
      alerts.push({ tone: 'a-info', text: meta.note });
    }
    if (financial && (financial.total_receivables || 0) > 8000000) {
      alerts.push({ tone: 'a-danger', text: 'High receivables exposure. Prioritize collections this week.' });
    }
    const trips = cashData?.trips || [];
    const pendingCash = trips.filter((t) => t.cash_collected === null).length;
    if (pendingCash > 0) {
      alerts.push({ tone: 'a-warning', text: pendingCash + ' trips pending cash confirmation.' });
    }
    const varianceTrips = trips.filter((t) => t.variance !== null && Math.abs(Number(t.variance)) > 0);
    if (varianceTrips.length > 0) {
      alerts.push({ tone: 'a-warning', text: varianceTrips.length + ' trips have cash variance and need review.' });
    }
    if (!alerts.length) {
      alerts.push({ tone: 'a-info', text: 'No critical alerts. Keep monitoring daily.' });
    }
    root.innerHTML = alerts.map((a) => `<div class="alert ${a.tone}" style="margin-bottom:8px"><span>${a.tone === 'a-danger' ? '⚠' : 'ℹ'}</span><div>${a.text}</div></div>`).join('');
  }

  function currentRole() {
    return (typeof currentUser !== 'undefined' && currentUser?.role) || '';
  }

  /** Soft-load KPIs by role — never surface raw 403s as "proactive alerts". */
  async function fetchMetrics() {
    const role = currentRole();
    const canFinancial = role === 'accountant' || role === 'admin' || role === 'executive';
    const canCash = role === 'accountant' || role === 'admin';
    const canCustomers = role === 'accountant' || role === 'admin' || role === 'manager' || role === 'executive';

    let financial = { revenue: 0, expenses: 0, total_receivables: 0 };
    let cashData = { trips: [] };
    const notes = [];

    if (canFinancial) {
      try {
        const month = (document.getElementById('accMonthPicker')?.value || currentMonthIso());
        const from = month + '-01';
        const toDate = new Date(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0);
        const to = toDate.getFullYear() + '-' + String(toDate.getMonth() + 1).padStart(2, '0') + '-' + String(toDate.getDate()).padStart(2, '0');
        financial = await LapokAPI.get('/api/reports/financial.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to));
      } catch (e) {
        notes.push('Financial KPIs unavailable for this role.');
      }
    } else if (canCustomers) {
      try {
        const data = await LapokAPI.get('/api/customers/fetch_customers.php');
        const owing = (data.customers || []).filter((c) => Number(c.credit_balance) > 0);
        financial.total_receivables = owing.reduce((s, c) => s + Number(c.credit_balance || 0), 0);
        notes.push('Showing receivables only. Full P&amp;L KPIs are available to the accountant.');
      } catch (_) {
        notes.push('Receivables snapshot unavailable.');
      }
    } else {
      notes.push('Live KPIs are shown for the accountant role.');
    }

    if (canCash) {
      try {
        cashData = await LapokAPI.get('/api/trips/pending_cash.php');
      } catch (_) {
        notes.push('Cash confirmation alerts are accountant-only.');
      }
    } else if (role === 'manager' || role === 'executive') {
      notes.push('Cash confirmation alerts are managed by the accountant (Cash handover).');
    }

    latestMetrics = { financial, cashData, note: notes[0] || '' };
    updateKpis(financial);
    renderAlerts(financial, cashData, { note: latestMetrics.note });
  }

  function renderAll() {
    renderChecklist();
    renderProcessFields();
    applyReadOnlyUi();
    if (latestMetrics?.financial) {
      updateKpis(latestMetrics.financial);
      renderAlerts(latestMetrics.financial, latestMetrics.cashData, { note: latestMetrics.note });
    }
  }

  function setOpsHomeButton() {
    const page = document.getElementById('page-accountant-improvements');
    const btn = page?.querySelector('.rdc-bal-toolbar .btn');
    if (!btn || typeof currentUser === 'undefined' || !currentUser) return;
    const home = {
      accountant: 'accountant-rdc-hub',
      manager: 'manager-dashboard',
      executive: 'admin-dashboard',
      admin: 'admin-dashboard',
    }[currentUser.role] || 'accountant-rdc-hub';
    btn.onclick = () => showPage(home);
  }

  async function loadMonthEndFromApi(month) {
    currentMonth = month;
    const data = await LapokAPI.get('/api/rdc/fetch_month_end.php?month=' + encodeURIComponent(month));
    readOnly = !!data.read_only;
    state = deepCopy(data.state || {});
    updateSyncLabel(data.updated_by_name, data.updated_at);

    const legacy = loadLegacyState();
    if (legacy && !data.updated_at) {
      state = { ...state, ...legacy };
      await persistState('Imported from this device — now synced for all roles.');
      clearLegacyState();
    }
    return data;
  }

  function bindMonthPicker() {
    const picker = document.getElementById('accMonthPicker');
    if (!picker || picker.dataset.bound) return;
    picker.dataset.bound = '1';
    picker.value = currentMonth || currentMonthIso();
    picker.addEventListener('change', async () => {
      try {
        await loadMonthEndFromApi(picker.value);
        renderAll();
        if (typeof loadManagerFixedCosts === 'function') loadManagerFixedCosts();
      } catch (e) {
        toast(e.message, true);
      }
    });
  }

  async function loadAccountantImprovementsPage() {
    const page = document.getElementById('page-accountant-improvements');
    if (!page) return;
    setOpsHomeButton();
    bindMonthPicker();
    const month = document.getElementById('accMonthPicker')?.value || currentMonthIso();
    try {
      await loadMonthEndFromApi(month);
      renderAll();
    } catch (e) {
      toast(e.message || 'Could not load month-end workspace', true);
      return;
    }
    if (typeof loadManagerFixedCosts === 'function') {
      try { await loadManagerFixedCosts(); } catch (_) { /* fixed costs optional for some roles */ }
    }
    try {
      await fetchMetrics();
    } catch (e) {
      const root = document.getElementById('accAlertsList');
      if (root) {
        root.innerHTML = '<div class="alert a-info"><span>ℹ</span><div>Live KPI alerts are available to the accountant. Checklist and notes above still work.</div></div>';
      }
    }
  }

  function updateAccountantChecklist(idx, key, value) {
    if (readOnly) return;
    state.checklist[idx][key] = value;
  }

  async function saveAccountantChecklist() {
    await persistState('Month-end checklist saved.');
  }

  async function saveAccountantNotes() {
    state.processReviewDate = document.getElementById('accProcessReviewDate')?.value || '';
    state.bottlenecks = document.getElementById('accBottlenecks')?.value || '';
    state.sopUpdates = document.getElementById('accSopUpdates')?.value || '';
    state.monthlySummary = document.getElementById('accMonthlySummary')?.value || '';
    await persistState('Process review notes saved.');
  }

  async function refreshAccountantCommandCenter() {
    await loadAccountantImprovementsPage();
  }

  window.loadAccountantImprovementsPage = loadAccountantImprovementsPage;
  window.refreshAccountantCommandCenter = refreshAccountantCommandCenter;
  window.updateAccountantChecklist = updateAccountantChecklist;
  window.saveAccountantChecklist = saveAccountantChecklist;
  window.saveAccountantNotes = saveAccountantNotes;

  document.addEventListener('DOMContentLoaded', () => {
    const originalShowPage = window.showPage;
    if (typeof originalShowPage !== 'function' || originalShowPage.__accHooked) return;
    const wrapped = function (id) {
      originalShowPage(id);
      if (id === 'accountant-improvements') {
        loadAccountantImprovementsPage();
      }
    };
    wrapped.__accHooked = true;
    window.showPage = wrapped;
  });
})();
