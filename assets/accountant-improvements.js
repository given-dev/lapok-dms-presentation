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

  function renderAutomation() {
    const root = document.getElementById('accAutomationList');
    if (!root || !state) return;
    root.innerHTML = state.automation.map((item, idx) => `
      <label style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--gray-line)">
        <input type="checkbox" ${item.enabled ? 'checked' : ''} onchange="toggleAccountantAutomation(${idx}, this.checked)" ${readOnly ? 'disabled' : ''}>
        <div><strong>${item.label}</strong><div style="font-size:12px;color:var(--gray-mid)">Status: ${item.enabled ? 'Enabled' : 'Not enabled'}</div></div>
      </label>
    `).join('');
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

  function renderControlsAndEvidence() {
    const matrix = document.getElementById('accApprovalMatrix');
    if (matrix && state) matrix.value = state.approvalMatrix;

    const controlsTable = document.getElementById('accControlsTable');
    if (controlsTable && state) {
      controlsTable.innerHTML = '<tr><th>Action</th><th>Maker</th><th>Checker</th><th>Status</th></tr>' + state.controls.map((row, idx) => `
        <tr>
          <td><input class="input" value="${row.action}" onchange="updateAccountantControl(${idx}, 'action', this.value)" ${readOnly ? 'readonly' : ''}></td>
          <td><input class="input" value="${row.maker}" onchange="updateAccountantControl(${idx}, 'maker', this.value)" ${readOnly ? 'readonly' : ''}></td>
          <td><input class="input" value="${row.checker}" onchange="updateAccountantControl(${idx}, 'checker', this.value)" ${readOnly ? 'readonly' : ''}></td>
          <td><select class="select-inp" onchange="updateAccountantControl(${idx}, 'status', this.value)" ${readOnly ? 'disabled' : ''}>
            <option value="active" ${row.status === 'active' ? 'selected' : ''}>Active</option>
            <option value="review" ${row.status === 'review' ? 'selected' : ''}>Needs review</option>
          </select></td>
        </tr>
      `).join('');
    }

    const documentsTable = document.getElementById('accDocumentsTable');
    if (documentsTable && state) {
      documentsTable.innerHTML = '<tr><th>Document</th><th>Source</th><th>Status</th></tr>' + state.documents.map((row, idx) => `
        <tr>
          <td><input class="input" value="${row.name}" onchange="updateAccountantDocument(${idx}, 'name', this.value)" ${readOnly ? 'readonly' : ''}></td>
          <td><input class="input" value="${row.source}" onchange="updateAccountantDocument(${idx}, 'source', this.value)" ${readOnly ? 'readonly' : ''}></td>
          <td><select class="select-inp" onchange="updateAccountantDocument(${idx}, 'status', this.value)" ${readOnly ? 'disabled' : ''}>
            <option value="received" ${row.status === 'received' ? 'selected' : ''}>Received</option>
            <option value="reviewing" ${row.status === 'reviewing' ? 'selected' : ''}>Reviewing</option>
            <option value="missing" ${row.status === 'missing' ? 'selected' : ''}>Missing</option>
          </select></td>
        </tr>
      `).join('');
    }
  }

  function renderTemplates() {
    const tplPnL = document.getElementById('accTplPnL');
    if (tplPnL && state) tplPnL.value = state.templates.pnl;
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
    const marginPct = metrics.revenue > 0 ? ((metrics.revenue - metrics.expenses) / metrics.revenue) * 100 : 0;
    const set = (id, value) => {
      const el = document.getElementById(id);
      if (el) el.textContent = value;
    };
    set('accKpiCashFlow', fmtUgx(metrics.revenue - metrics.expenses));
    set('accKpiReceivables', fmtUgx(metrics.total_receivables));
    set('accKpiMargin', marginPct.toFixed(1) + '%');
  }

  function renderAlerts(financial, cashData) {
    const root = document.getElementById('accAlertsList');
    if (!root) return;
    const alerts = [];
    if ((financial.total_receivables || 0) > 8000000) {
      alerts.push({ tone: 'a-danger', text: 'High receivables exposure. Prioritize collections this week.' });
    }
    const pendingCash = (cashData?.trips || []).filter((t) => t.cash_collected === null).length;
    if (pendingCash > 0) {
      alerts.push({ tone: 'a-warning', text: pendingCash + ' trips pending cash confirmation.' });
    }
    const varianceTrips = (cashData?.trips || []).filter((t) => t.variance !== null && Math.abs(Number(t.variance)) > 0);
    if (varianceTrips.length > 0) {
      alerts.push({ tone: 'a-warning', text: varianceTrips.length + ' trips have cash variance and need review.' });
    }
    if (!alerts.length) {
      alerts.push({ tone: 'a-info', text: 'No critical alerts. Keep monitoring daily.' });
    }
    root.innerHTML = alerts.map((a) => `<div class="alert ${a.tone}" style="margin-bottom:8px"><span>${a.tone === 'a-danger' ? '⚠' : 'ℹ'}</span><div>${a.text}</div></div>`).join('');
  }

  async function fetchMetrics() {
    const today = new Date();
    const year = today.getFullYear();
    const month = today.getMonth() + 1;
    const [financial, cashData] = await Promise.all([
      LapokAPI.get('/api/reports/financial.php?year=' + year + '&month=' + month),
      LapokAPI.get('/api/trips/pending_cash.php'),
    ]);
    latestMetrics = { financial, cashData };
    updateKpis(financial);
    renderAlerts(financial, cashData);
  }

  function renderAll() {
    renderAutomation();
    renderChecklist();
    renderControlsAndEvidence();
    renderTemplates();
    renderProcessFields();
    applyReadOnlyUi();
    if (latestMetrics?.financial) {
      updateKpis(latestMetrics.financial);
      renderAlerts(latestMetrics.financial, latestMetrics.cashData);
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
      await fetchMetrics();
    } catch (e) {
      const root = document.getElementById('accAlertsList');
      if (root) {
        root.innerHTML = `<div class="alert a-warning"><span>⚠</span><div>${e.message}</div></div>`;
      }
      toast(e.message, true);
    }
  }

  function toggleAccountantAutomation(idx, enabled) {
    if (readOnly) return;
    state.automation[idx].enabled = !!enabled;
    renderAutomation();
  }

  function updateAccountantChecklist(idx, key, value) {
    if (readOnly) return;
    state.checklist[idx][key] = value;
  }

  function updateAccountantControl(idx, key, value) {
    if (readOnly) return;
    state.controls[idx][key] = value;
  }

  function updateAccountantDocument(idx, key, value) {
    if (readOnly) return;
    state.documents[idx][key] = value;
  }

  async function saveAccountantAutomation() {
    await persistState('Automation settings saved.');
    renderAutomation();
  }

  async function saveAccountantChecklist() {
    await persistState('Month-end checklist saved.');
  }

  function addAccountantControlLog() {
    if (readOnly) return;
    state.controls.push({ action: 'New control step', maker: 'Accountant', checker: 'Manager', status: 'review' });
    renderControlsAndEvidence();
  }

  function addAccountantDocument() {
    if (readOnly) return;
    state.documents.push({ name: 'New document', source: 'Team', status: 'reviewing' });
    renderControlsAndEvidence();
  }

  async function saveAccountantTemplates() {
    state.templates = { pnl: document.getElementById('accTplPnL')?.value || '' };
    await persistState('P&L template saved.');
  }

  async function saveAccountantNotes() {
    state.approvalMatrix = document.getElementById('accApprovalMatrix')?.value || 'green';
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
  window.toggleAccountantAutomation = toggleAccountantAutomation;
  window.updateAccountantChecklist = updateAccountantChecklist;
  window.updateAccountantControl = updateAccountantControl;
  window.updateAccountantDocument = updateAccountantDocument;
  window.saveAccountantAutomation = saveAccountantAutomation;
  window.saveAccountantChecklist = saveAccountantChecklist;
  window.addAccountantControlLog = addAccountantControlLog;
  window.addAccountantDocument = addAccountantDocument;
  window.saveAccountantTemplates = saveAccountantTemplates;
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
