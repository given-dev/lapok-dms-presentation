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

function execMonthLabel(ym) {
  if (!ym) return 'MTD';
  const [y, m] = String(ym).split('-');
  const names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return `${names[Number(m) - 1]}-${String(y).slice(2)}`;
}

let execKpiMonth = '';
let execMonthbarReady = false;

function execSyncMonthNote() {
  const note = document.getElementById('execMonthNote');
  if (!note) return;
  if (execKpiMonth) {
    note.textContent = `Reviewing ${execMonthLabel(execKpiMonth)} — live "today" cards hidden`;
    note.style.display = '';
  } else {
    note.textContent = '';
    note.style.display = 'none';
  }
}

function execSetMonth(ym) {
  const cur = LapokAPI.monthIso();
  execKpiMonth = (ym && ym !== cur) ? ym : '';
  const pick = document.getElementById('execMonthPick');
  if (pick) pick.value = ym || cur;
  execSyncMonthNote();
  loadAdminDashboard();
  if (typeof loadLiveCharts === 'function') loadLiveCharts();
}
window.execSetMonth = execSetMonth;

function execMonthbarInit() {
  if (!currentUser || currentUser.role !== 'executive') return;
  const bar = document.getElementById('execMonthbar');
  const pick = document.getElementById('execMonthPick');
  if (!bar || !pick) return;
  bar.style.display = '';
  if (!execMonthbarReady) {
    const now = new Date();
    let opts = '';
    for (let i = 0; i < 13; i++) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      const ym = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
      opts += `<option value="${ym}">${execMonthLabel(ym)}</option>`;
    }
    pick.innerHTML = opts;
    execMonthbarReady = true;
  }
  pick.value = execKpiMonth || LapokAPI.monthIso();
  execSyncMonthNote();
}
window.execMonthbarInit = execMonthbarInit;

function execMetricCard(index, label, value, sub) {  const card = document.querySelector(`#page-admin-dashboard .metric-grid .metric-card:nth-child(${index})`);
  if (!card) return;
  const lbl = card.querySelector('.metric-label');
  const val = card.querySelector('.metric-value');
  const subEl = card.querySelector('.metric-sub');
  if (lbl) lbl.textContent = label;
  if (val) val.textContent = value;
  if (subEl) subEl.innerHTML = sub || '';
}

function execEscHtml(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function execPctBadge(p) {
  const n = Number(p || 0);
  return `<span class="badge ${n >= 100 ? 'bs' : 'bd'}">${n.toFixed(1)}%</span>`;
}

/** Colored percentage — green when the target is met, red otherwise. */
function execPctSpan(p) {
  const n = Number(p || 0);
  return `<span style="color:${n >= 100 ? '#166534' : '#B91C1C'};font-weight:600">${n.toFixed(1)}%</span>`;
}

function renderExecutiveCso(d) {
  const card = document.getElementById('execCsoCard');
  const body = document.getElementById('execCsoBody');
  if (!card || !body) return;
  const cf = d.cash_flow || {};
  const history = Array.isArray(cf.cso_history) ? cf.cso_history : [];
  if (!history.length) {
    card.style.display = 'none';
    return;
  }
  card.style.display = '';
  const fmt = (n) => LapokAPI.formatUgx(n);
  const fmtMonth = (m) => execMonthLabel(m);
  const summary = document.getElementById('execCsoSummary');
  if (summary) {
    const chips = [];
    if (Number(cf.cash_out_mtd || 0) > 0) chips.push(`<span class="chip" style="background:var(--red-light);color:var(--red-dark)">Cash out MTD: ${fmt(cf.cash_out_mtd)}</span>`);
    if (Number(cf.recovery_mtd || 0) > 0) chips.push(`<span class="chip" style="background:var(--green-light);color:#166534">Recovered MTD: ${fmt(cf.recovery_mtd)}</span>`);
    if (!chips.length) chips.push('<span style="font-size:12px;color:var(--gray-mid)">No cash-outs recorded this month.</span>');
    summary.innerHTML = chips.join('');
  }
  const rows = history.map((h, i) => {
    const prev = i > 0 ? Number(history[i - 1].cso || 0) : null;
    const cur = Number(h.cso || 0);
    let vs = '<span style="color:var(--gray-mid)">—</span>';
    if (prev !== null) {
      const delta = cur - prev;
      const cls = delta > 0 ? 'color:var(--red)' : 'color:var(--green)';
      vs = `<span style="${cls};font-weight:600">${delta > 0 ? '▲' : delta < 0 ? '▼' : '—'} ${LapokAPI.formatUgx(Math.abs(delta))}</span>`;
    }
    return `<tr><td>${fmtMonth(h.month)}</td><td class="right" style="font-weight:600">${fmt(cur)}</td><td>${vs}</td></tr>`;
  }).join('');
  body.innerHTML = rows || '<tr><td colspan="3" class="skel">No cash-out history yet.</td></tr>';
}

async function loadExecutiveTargets(cached) {
  const card = document.getElementById('execTargetsCard');
  const body = document.getElementById('execTargetsBody');
  if (!card || !body) return;
  card.style.display = '';
  let d = cached;
  if (!d) {
    try { d = await LapokAPI.get('/api/dashboard/executive.php' + (execKpiMonth ? `?kpi_month=${execKpiMonth}` : '')); }
    catch (e) { body.innerHTML = `<p class="skel">Failed to load targets: ${execEscHtml(e.message)}</p>`; return; }
  }
  const ss = d.sales_split || {};
  const title = document.getElementById('execTargetsTitle');
  if (title) title.textContent = execMonthLabel(d.kpi_month);
  const fmtN = (n) => Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 });
  const hasTargets = Number(ss.soda_target || 0) + Number(ss.water_target || 0) > 0;

  let banner = '';
  if (!hasTargets) {
    banner = `<div class="alert a-info" style="margin:0 0 .8rem"><span>ℹ</span><div><strong>No targets entered for ${execEscHtml(execMonthLabel(d.kpi_month))} yet.</strong> Sales are still tracked per cadet below — once the Manager sets the SODA / WATER targets on the <strong>Monthly targets</strong> page, the target columns sync in automatically.</div></div>`;
  }

  const overall = `
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:1rem">
      <div style="flex:1;min-width:200px;padding:12px 14px;border:1px solid var(--gray-light);border-radius:10px;background:rgba(0,0,0,.02)">
        <div style="font-size:11px;color:var(--gray-mid);text-transform:uppercase;letter-spacing:.4px">Overall SODA ${hasTargets ? 'target' : 'sold'}</div>
        <div style="font-size:22px;font-weight:700;color:var(--black)">${fmtN(hasTargets ? ss.soda_target : ss.soda_units)} crates</div>
        <div style="font-size:12px;color:var(--gray-mid);margin-top:4px">${hasTargets ? `sold ${fmtN(ss.soda_units)} · ${execPctBadge(ss.soda_pct)}` : 'target not set yet'}</div>
      </div>
      <div style="flex:1;min-width:200px;padding:12px 14px;border:1px solid var(--gray-light);border-radius:10px;background:rgba(0,0,0,.02)">
        <div style="font-size:11px;color:var(--gray-mid);text-transform:uppercase;letter-spacing:.4px">Overall WATER ${hasTargets ? 'target' : 'sold'}</div>
        <div style="font-size:22px;font-weight:700;color:var(--black)">${fmtN(hasTargets ? ss.water_target : ss.water_units)} crates</div>
        <div style="font-size:12px;color:var(--gray-mid);margin-top:4px">${hasTargets ? `sold ${fmtN(ss.water_units)} · ${execPctBadge(ss.water_pct)}` : 'target not set yet'}</div>
      </div>
    </div>`;

  const units = Array.isArray(ss.by_unit) ? ss.by_unit : [];
  const cellT = (n) => Number(n || 0) > 0 ? fmtN(n) : '<span style="color:var(--gray-mid)">—</span>';
  const cellPct = (a, tg) => Number(tg || 0) > 0 ? execPctBadge((Number(a || 0) / Number(tg)) * 100) : '<span class="badge bg">—</span>';
  const rows = units.map((u) => {
    const type = u.is_depot ? 'DEPOT' : (u.vehicle_type === 'truck' ? 'TRUCK' : 'TUK-TUK');
    const sodaT = Number(u.soda_target || 0);
    const waterT = Number(u.water_target || 0);
    return `<tr>
      <td>${execEscHtml(u.label)}${u.is_depot ? ' <span class="badge bg">depot</span>' : ''}</td>
      <td>${type}</td>
      <td class="right">${cellT(u.soda_target)}</td>
      <td class="right" style="font-weight:600">${fmtN(u.soda_units)}</td>
      <td class="right">${cellPct(u.soda_units, sodaT)}</td>
      <td class="right">${cellT(u.water_target)}</td>
      <td class="right" style="font-weight:600">${fmtN(u.water_units)}</td>
      <td class="right">${cellPct(u.water_units, waterT)}</td>
      <td class="right">${cellPct(Number(u.soda_units) + Number(u.water_units), sodaT + waterT)}</td>
    </tr>`;
  }).join('');
  const tot = units.reduce((a, u) => {
    a.sodaT += Number(u.soda_target || 0);
    a.waterT += Number(u.water_target || 0);
    a.sodaU += Number(u.soda_units || 0);
    a.waterU += Number(u.water_units || 0);
    return a;
  }, { sodaT: 0, waterT: 0, sodaU: 0, waterU: 0 });
  const totalRow = units.length
    ? `<tr style="border-top:2px solid var(--black);background:rgba(38,74,37,.06);font-weight:700">
        <td>Overall depot</td>
        <td><span class="badge bg">DEPOT + all vehicles</span></td>
        <td class="right">${cellT(tot.sodaT)}</td>
        <td class="right">${fmtN(tot.sodaU)}</td>
        <td class="right">${cellPct(tot.sodaU, tot.sodaT)}</td>
        <td class="right">${cellT(tot.waterT)}</td>
        <td class="right">${fmtN(tot.waterU)}</td>
        <td class="right">${cellPct(tot.waterU, tot.waterT)}</td>
        <td class="right">${cellPct(tot.sodaU + tot.waterU, tot.sodaT + tot.waterT)}</td>
      </tr>`
    : '';

  body.innerHTML = banner + overall + `<div class="tbl-wrap"><table>
    <tr><th>Sales unit</th><th>Type</th>
      <th>SODA target</th><th>SODA sold</th><th>SODA %</th>
      <th>WATER target</th><th>WATER sold</th><th>WATER %</th><th>Total %</th></tr>
    ${rows || '<tr><td colspan="9" class="skel">No sales units configured.</td></tr>'}
    ${totalRow}
  </table></div>`;
}

async function loadManagerTargetsPage() {
  const monthInput = document.getElementById('mgrTargetsMonth');
  if (!monthInput) return;
  const month = monthInput.value || LapokAPI.monthIso();
  monthInput.value = month;
  const label = document.getElementById('mgrTargetsMonthLabel');
  if (label) label.textContent = execMonthLabel(month);
  const body = document.getElementById('mgrTargetsBody');
  const status = document.getElementById('mgrTargetsStatus');
  if (!body) return;
  body.innerHTML = '<tr><td colspan="5" class="skel">Loading…</td></tr>';
  if (status) status.textContent = '';
  try {
    const d = await LapokAPI.get('/api/targets/get.php?month=' + encodeURIComponent(month));
    const rows = (d.units || []).map((u) => {
      const cadet = u.is_depot ? '—' : execEscHtml(u.cadet_name || '');
      const type = u.is_depot ? 'DEPOT' : (u.vehicle_type === 'truck' ? 'TRUCK' : 'TUK-TUK');
      return `<tr data-key="${execEscHtml(u.key)}">
        <td>${execEscHtml(u.label)}${u.is_depot ? ' <span class="badge bg">depot</span>' : ''}</td>
        <td>${cadet}</td>
        <td>${type}</td>
        <td><input class="qty-inp" type="number" min="0" step="any" value="${u.soda_units}" data-cat="soda" aria-label="SODA target ${execEscHtml(u.label)}"></td>
        <td><input class="qty-inp" type="number" min="0" step="any" value="${u.water_units}" data-cat="water" aria-label="WATER target ${execEscHtml(u.label)}"></td>
      </tr>`;
    }).join('');
    body.innerHTML = rows || '<tr><td colspan="5" class="skel">No active vehicles.</td></tr>';
  } catch (e) {
    body.innerHTML = `<tr><td colspan="5" class="skel">Failed to load: ${execEscHtml(e.message)}</td></tr>`;
  }
}

async function saveManagerTargets() {
  const monthInput = document.getElementById('mgrTargetsMonth');
  const body = document.getElementById('mgrTargetsBody');
  const status = document.getElementById('mgrTargetsStatus');
  const month = monthInput ? monthInput.value : '';
  if (!month) { if (status) status.textContent = 'Pick a month first.'; return; }
  if (!body) return;
  const units = [];
  body.querySelectorAll('tr[data-key]').forEach((tr) => {
    const q = (cat) => Number(tr.querySelector(`input[data-cat="${cat}"]`)?.value || 0);
    units.push({ key: tr.dataset.key, soda_units: q('soda'), water_units: q('water') });
  });
  if (status) status.textContent = 'Saving…';
  try {
    await LapokAPI.post('/api/targets/save.php', { month, units });
    if (status) status.textContent = 'Saved targets for ' + execMonthLabel(month) + '.';
    if (typeof adminToast === 'function') adminToast('Monthly targets saved');
    loadManagerTargetsPage();
  } catch (e) {
    if (status) status.textContent = 'Error: ' + e.message;
    if (typeof adminToast === 'function') adminToast('Save failed: ' + e.message, true);
  }
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
      ? '/api/dashboard/executive.php' + (execKpiMonth ? `?kpi_month=${execKpiMonth}` : '')
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
      const isCurrentMonth = !execKpiMonth || d.kpi_month === LapokAPI.monthIso();
      // Live "today" cards (warehouse, revenue today, crates today) and live charts only
      // make sense for the current month — hide them when reviewing a past month.
      document.querySelectorAll('#page-admin-dashboard .metric-grid .metric-card').forEach((cardEl, i) => {
        if ([1, 2, 3].includes(i + 1)) cardEl.style.display = isCurrentMonth ? '' : 'none';
      });
      const execChartsBlock = document.querySelector('#page-admin-dashboard .two-col');
      const profitCard = document.getElementById('profitChart')?.closest('.card');
      if (execChartsBlock) execChartsBlock.style.display = isCurrentMonth ? '' : 'none';
      if (profitCard) profitCard.style.display = isCurrentMonth ? '' : 'none';
      const c4lbl = document.querySelector('#page-admin-dashboard .metric-card:nth-child(4) .metric-label');
      const c4sub = document.querySelector('#page-admin-dashboard .metric-card:nth-child(4) .metric-sub');
      if (c4lbl) c4lbl.textContent = isCurrentMonth ? 'Revenue MTD' : `Revenue (${execMonthLabel(d.kpi_month)})`;
      if (c4sub) c4sub.textContent = isCurrentMonth ? 'UGX' : 'month total';
      if (isCurrentMonth) {
        setTrend(2, d.revenue_today_delta_pct, 'vs yesterday');
        setTrend(3, d.cartons_today_delta_pct, 'vs yesterday');
      }
      setTrend(4, d.revenue_mtd_delta_pct, isCurrentMonth ? 'vs same days last month' : 'vs previous month');
      const ss = d.sales_split || {};
      const cf = d.cash_flow || {};
      const fmtN = (n) => Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 });
      const hasTargets = Number(ss.soda_target || 0) + Number(ss.water_target || 0) > 0;
      const card7 = document.querySelector('#page-admin-dashboard .metric-grid .metric-card:nth-child(7)');
      if (card7) card7.style.display = '';
      if (hasTargets) {
        execMetricCard(5, 'SODA target', fmtN(ss.soda_target), `sold ${fmtN(ss.soda_units)} · ${execPctSpan(ss.soda_pct)}`);
        execMetricCard(6, 'WATER target', fmtN(ss.water_target), `sold ${fmtN(ss.water_units)} · ${execPctSpan(ss.water_pct)}`);
      } else {
        execMetricCard(5, 'SODA sold', fmtN(ss.soda_units), 'target not set');
        execMetricCard(6, 'WATER sold', fmtN(ss.water_units), 'target not set');
      }
      execMetricCard(7, 'Cash still out', LapokAPI.formatUgx(cf.cso_cumulative ?? 0), `open ${LapokAPI.formatUgx(cf.cso_open ?? 0)}`);
      const execChk = document.getElementById('execDailyChecklist');
      if (execChk) execChk.style.display = '';
      if (isCurrentMonth) {
        loadExecutiveHomeExtras(d);
      } else {
        const execBody = document.getElementById('execChecklistBody');
        if (execBody) execBody.innerHTML =
          `<tr><td colspan="4"><span style="font-size:12px;color:var(--gray-mid)">The daily checklist applies to today only — you are reviewing ${execMonthLabel(d.kpi_month)}. Use <strong>Monthly sales targets</strong> and <strong>Cash still out</strong> below for that month.</span></td></tr>`;
        if (typeof loadDirectorBriefWidget === 'function') loadDirectorBriefWidget();
      }
      loadExecutiveTargets(d);
      renderExecutiveCso(d);
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

async function loadAdminConsole() {
  if (!currentUser || currentUser.role !== 'admin') return;
  const body = document.getElementById('admConsoleChecklist');
  if (!body) return;
  try {
    const d = await LapokAPI.get('/api/dashboard/admin.php');
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('admConsoleWarehouse', Number(d.warehouse_cartons).toLocaleString());
    set('admConsoleRequests', Number(d.pending_requests || 0).toLocaleString());
    set('admConsoleExceptions', Number(d.exception_count || 0).toLocaleString());
    set('admConsoleRdc', Number(d.rdc_pending_review || 0).toLocaleString());
    set('admConsoleCash', Number(d.cash_pending || 0).toLocaleString());
    set('admConsoleAudit', Number(d.audit_today || 0).toLocaleString());
    set('admConsoleLowStock', Number(d.low_stock_count ?? (d.low_stock || []).length).toLocaleString());
    set('admConsoleUsers', Number(d.active_users || 0).toLocaleString());
    set('admConsoleWelfare', Number(d.welfare_open_count || 0).toLocaleString());
    set('admConsoleVehicles', `${Number(d.vehicles_out || 0)}/${Number(d.vehicles_total || 0)}`);
    const excSub = document.getElementById('admConsoleExceptionsSub');
    if (excSub) excSub.textContent = `stock ${Number(d.low_stock_count ?? 0)} · cash ${Number(d.cash_pending ?? 0)} · reqs ${Number(d.pending_requests ?? 0)}`;
    const usersSub = document.getElementById('admConsoleUsersSub');
    if (usersSub) usersSub.textContent = `${Number(d.inactive_users || 0).toLocaleString()} inactive`;
    const countBadge = (n) => `<span class="badge ${Number(n) ? 'bd' : 'bs'}">${Number(n || 0).toLocaleString()}</span>`;
    const rows = [
      [1, 'Account management', `${Number(d.active_users || 0).toLocaleString()} active · ${Number(d.inactive_users || 0).toLocaleString()} inactive`, 'admin-users', 'Open'],
      [2, 'Edit requests', countBadge(d.pending_requests), 'admin-editreqs', 'Review'],
      [3, 'Exception center', countBadge(d.exception_count), 'admin-exceptions', 'Resolve'],
      [4, 'Audit log today', `${Number(d.audit_today || 0).toLocaleString()} events logged`, 'admin-audit', 'View'],
      [5, 'RDC sheets under review', countBadge(d.rdc_pending_review), 'admin-exceptions', 'Monitor'],
      [6, 'Cash pending confirmation', countBadge(d.cash_pending), 'admin-exceptions', 'Monitor'],
      [7, 'Executive briefs open', countBadge(d.exec_briefs_open), 'report-exchange', 'Open'],
      [8, 'Low stock products', countBadge(d.low_stock_count), 'admin-exceptions', 'View'],
      [9, 'Staff welfare open', countBadge(d.welfare_open_count), 'accountant-welfare', 'Review'],
    ];
    body.innerHTML = rows.map(([n, s, st, page, action]) =>
      `<tr><td>${n}</td><td>${s}</td><td>${st}</td><td><button class="btn btn-sm" onclick="showPage('${page}')">${action}</button></td></tr>`
    ).join('');
  } catch (e) {
    body.innerHTML = `<tr><td colspan="4" style="color:var(--red)">${escMgr(e.message || 'Could not load console')}</td></tr>`;
  }
}
window.loadAdminConsole = loadAdminConsole;

async function loadAdminFleet() {
  if (!currentUser || currentUser.role !== 'admin') return;
  const body = document.getElementById('fleetTableBody');
  if (!body) return;
  body.innerHTML = '<tr><td colspan="8" class="skel" style="text-align:center">Loading…</td></tr>';
  try {
    const data = await LapokAPI.get('/api/vehicles/fetch_vehicles.php?include_inactive=1');
    const vs = data.vehicles || [];
    if (!vs.length) {
      body.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--gray-mid)">No vehicles registered. Add your first vehicle above.</td></tr>';
      return;
    }
    const typeLabel = (t) => (t === 'truck' ? 'Truck' : 'Tuktuk');
    const statusBadge = (v) => {
      if (!v.is_active) return '<span class="badge bg">Retired</span>';
      return { available: '<span class="badge bs">Available</span>', on_route: '<span class="badge bw">On route</span>' }[v.status] || '<span class="badge bw">' + escMgr(v.status || 'unknown') + '</span>';
    };
    const actionBtn = (v) => v.is_active
      ? `<button class="btn btn-sm" onclick="retireVehicle(${v.id}, 0)">Retire</button>`
      : `<button class="btn btn-sm" onclick="retireVehicle(${v.id}, 1)">Reactivate</button>`;
    body.innerHTML = vs.map((v) => `<tr>
      <td><strong>${escMgr(v.registration)}</strong></td>
      <td>${typeLabel(v.vehicle_type)}</td>
      <td>${escMgr(v.make_model || '—')}</td>
      <td>${Number(v.capacity || 0)}</td>
      <td>${statusBadge(v)}</td>
      <td>${escMgr(v.cadet_name || '—')}</td>
      <td>${escMgr(v.driver_name || '—')}</td>
      <td>${actionBtn(v)}</td>
    </tr>`).join('');
  } catch (e) {
    body.innerHTML = `<tr><td colspan="8" style="color:var(--red)">${escMgr(e.message || 'Could not load fleet')}</td></tr>`;
  }
}
window.loadAdminFleet = loadAdminFleet;

async function submitAddVehicle() {
  const err = document.getElementById('addVehicleErr');
  if (err) err.style.display = 'none';
  const payload = {
    registration: document.getElementById('addVehicleReg')?.value?.trim() || '',
    vehicle_type: document.getElementById('addVehicleType')?.value || 'truck',
    make_model: document.getElementById('addVehicleMake')?.value?.trim() || '',
    capacity: Number(document.getElementById('addVehicleCapacity')?.value || 0),
  };
  try {
    await LapokAPI.post('/api/vehicles/create_vehicle.php', payload);
    closeModal('addVehicleModal');
    ['addVehicleReg', 'addVehicleMake'].forEach((id) => { const el = document.getElementById(id); if (el) el.value = ''; });
    adminToast('Vehicle added');
    await loadAdminFleet();
  } catch (e) {
    if (err) { err.style.display = 'block'; err.textContent = e.message; }
  }
}
window.submitAddVehicle = submitAddVehicle;

async function retireVehicle(id, isActive) {
  if (!confirm(isActive ? 'Reactivate this vehicle?' : 'Retire this vehicle? It will be removed from dispatch and assignments.')) return;
  try {
    await LapokAPI.post('/api/vehicles/update_vehicle.php', { id, is_active: isActive });
    adminToast(isActive ? 'Vehicle reactivated' : 'Vehicle retired');
    await loadAdminFleet();
  } catch (e) {
    adminToast(e.message, true);
  }
}
window.retireVehicle = retireVehicle;

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
        <td><button class="btn btn-sm" onclick="showPage('accountant-welfare')">Welfare</button></td>
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
  // When reviewing a past month, the today-based checklist doesn't apply.
  if (execKpiMonth && execKpiMonth !== LapokAPI.monthIso()) {
    const chk = document.getElementById('execDailyChecklist');
    if (chk) chk.style.display = 'none';
    return;
  }
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
    const welfare = Number(d.welfare_open_count || 0);
    const dir = d.director || {};
    const ss = d.sales_split || {};
    const cf = d.cash_flow || {};
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
    const targetPct = ss.total_pct != null ? ss.total_pct : 0;
    const hasTargets = Number(ss.soda_target || 0) + Number(ss.water_target || 0) > 0;
    const fmtN = (n) => Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 });
    const pctSpan = (p) => `<span style="color:${Number(p || 0) >= 100 ? '#166534' : '#B91C1C'};font-weight:600">${p ?? 0}%</span>`;
    const targetCell = hasTargets
      ? `<span class="badge ${targetPct >= 100 ? 'bs' : 'bd'}">${targetPct}%</span>
         <span style="font-size:11px;color:var(--gray-mid)"> · Soda ${fmtN(ss.soda_units)}/${fmtN(ss.soda_target)} (${pctSpan(ss.soda_pct)}) · Water ${fmtN(ss.water_units)}/${fmtN(ss.water_target)} (${pctSpan(ss.water_pct)})</span>`
      : '<span class="badge bg">Targets not set</span>';

    const briefStatus = brief
      ? execBriefBadge(brief.status) + (brief.packet_ref ? ` <span style="font-size:11px;color:var(--gray-mid)">${brief.packet_ref}</span>` : '')
      : '<span class="badge bg">Awaiting manager</span>';
    const briefAction = brief
      ? `<button class="btn btn-sm ${brief.status !== 'acknowledged' ? 'btn-red' : ''}" onclick="showPage('report-exchange')">Open inbox</button>`
      : '<button class="btn btn-sm" onclick="showPage(\'report-exchange\')">PDF reports</button>';

    body.innerHTML = `
      <tr>
        <td>1</td><td>Sales vs target (${execMonthLabel(d.kpi_month)})</td>
        <td>${targetCell}</td>
        <td><button class="btn btn-sm" onclick="showPage('admin-reports')">Reports</button></td>
      </tr>
      <tr>
        <td>2</td><td>Director brief (today P&amp;L)</td>
        <td><span class="badge ${readinessOk ? 'bs' : 'bw'}">${readinessLabel}</span>
          <span style="font-size:11px;color:var(--gray-mid)"> · Net ${netOp} · RDC ${rdcSt}</span></td>
        <td><button class="btn btn-sm btn-red" onclick="showPage('director-brief')">Open brief</button></td>
      </tr>
      <tr>
        <td>3</td><td>Manager PDF pack${unread ? ` <span class="badge bd">${unread} open</span>` : ''}</td>
        <td>${briefStatus}</td>
        <td>${briefAction}</td>
      </tr>
      <tr>
        <td>4</td><td>Exception radar</td>
        <td><span class="badge ${exc ? 'bw' : 'bs'}">${exc} open</span></td>
        <td><button class="btn btn-sm" onclick="showPage('admin-exceptions')">Monitor</button></td>
      </tr>
      <tr>
        <td>5</td><td>Staff welfare / month-end</td>
        <td><span class="badge ${welfare ? 'bw' : 'bs'}">${welfare} welfare open</span></td>
        <td><button class="btn btn-sm" onclick="showPage('accountant-welfare')">Welfare</button></td>
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
    const rows = [
      ['High', 'Pending edit requests', d.pending_requests || 0, "showPage('admin-editreqs')"],
      ['High', 'Exception queue items', d.exception_count || 0, "showPage('admin-exceptions')"],
      ['Medium', 'Low stock alerts', d.low_stock_count ?? (d.low_stock || []).length, "showPage('admin-exceptions')"],
      ['Medium', 'RDC sheets pending review', d.rdc_pending_review || 0, "showPage('manager-rdc-review')"],
      ['Medium', 'Vehicles out now', `${d.vehicles_out || 0}/${d.vehicles_total || 0}`, "showPage('admin-exceptions')"],
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
  tbody.innerHTML = '<tr><td colspan="6" class="skel" style="text-align:center">Loading…</td></tr>';
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

  const action = String(item.action || 'activity').replace(/_/g, ' ');
  const record = `${item.table_name || 'System'}${item.record_id ? ` #${item.record_id}` : ''}`;
  const when = `${LapokAPI.formatDate(item.created_at)} at ${LapokAPI.formatTime(item.created_at)}`;

  el.replaceChildren();

  const hero = document.createElement('div');
  hero.className = 'audit-detail-hero';
  const icon = document.createElement('div');
  icon.className = 'audit-detail-icon';
  icon.textContent = action.charAt(0) || 'A';
  const heroCopy = document.createElement('div');
  const actionEl = document.createElement('div');
  actionEl.className = 'audit-detail-action';
  actionEl.textContent = action;
  const sub = document.createElement('div');
  sub.className = 'audit-detail-sub';
  sub.textContent = `${item.user_name || 'System'} performed this action on ${record}.`;
  heroCopy.append(actionEl, sub);
  hero.append(icon, heroCopy);
  el.appendChild(hero);

  const grid = document.createElement('div');
  grid.className = 'audit-detail-grid';
  [
    ['User', item.user_name || 'System'],
    ['Date and time', when],
    ['Record', record],
    ['Audit entry', `#${item.id}`],
  ].forEach(([label, value]) => {
    const field = document.createElement('div');
    field.className = 'audit-detail-field';
    const labelEl = document.createElement('span');
    labelEl.className = 'audit-detail-label';
    labelEl.textContent = label;
    const valueEl = document.createElement('span');
    valueEl.className = 'audit-detail-value';
    valueEl.textContent = value;
    field.append(labelEl, valueEl);
    grid.appendChild(field);
  });
  el.appendChild(grid);

  const oldValues = item.old_values && typeof item.old_values === 'object' ? item.old_values : {};
  const newValues = item.new_values && typeof item.new_values === 'object' ? item.new_values : {};
  const keys = Array.from(new Set([...Object.keys(oldValues), ...Object.keys(newValues)]));
  const changes = document.createElement('div');
  const title = document.createElement('div');
  title.className = 'audit-change-title';
  title.textContent = 'Recorded changes';
  changes.appendChild(title);

  if (!keys.length) {
    const empty = document.createElement('div');
    empty.className = 'audit-change-empty';
    empty.textContent = ['login', 'logout'].includes(item.action)
      ? 'No record fields were changed. This entry confirms account activity only.'
      : 'No before-and-after field values were stored for this event.';
    changes.appendChild(empty);
  } else {
    const wrap = document.createElement('div');
    wrap.className = 'tbl-wrap';
    const table = document.createElement('table');
    table.className = 'audit-change-table';
    const head = document.createElement('tr');
    ['Field', 'Before', 'After'].forEach((text) => {
      const th = document.createElement('th');
      th.textContent = text;
      head.appendChild(th);
    });
    table.appendChild(head);
    keys.forEach((key) => {
      const row = document.createElement('tr');
      const values = [
        key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
        auditDisplayValue(oldValues[key]),
        auditDisplayValue(newValues[key]),
      ];
      values.forEach((value) => {
        const td = document.createElement('td');
        td.textContent = value;
        row.appendChild(td);
      });
      table.appendChild(row);
    });
    wrap.appendChild(table);
    changes.appendChild(wrap);
  }
  el.appendChild(changes);
  openModal('auditDetailModal');
}

function auditDisplayValue(value) {
  if (value === null || value === undefined || value === '') return 'Not recorded';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

function initAuditFilters() {
  const from = document.getElementById('auditFrom');
  const to = document.getElementById('auditTo');
  if (from && !from.value) from.value = LapokAPI.monthStartIso();
  if (to && !to.value) to.value = LapokAPI.localIsoDate();

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
    if (isExec) {
      loadVehicleAssignments();
    } else {
      hydrateUserVehicleOptions();
      loadVehicleAssignments();
    }
  } catch (e) { console.warn('Users:', e.message); }
}

async function loadVehicleAssignments() {
  const table = document.getElementById('vehicleAssignmentTable');
  if (!table || !['admin', 'executive'].includes(currentUser?.role)) return;
  try {
    const data = await LapokAPI.get('/api/assignments/fetch.php');
    const canEdit = !!data.can_edit && currentUser?.role === 'admin';
    const cadetNames = {};
    (data.cadets || []).forEach((c) => { cadetNames[Number(c.id)] = c.full_name; });
    const cadetOptions = (selected) => '<option value="">Unassigned</option>' + (data.cadets || []).map((c) =>
      `<option value="${c.id}" ${Number(selected) === Number(c.id) ? 'selected' : ''}>${escMgr(c.full_name)}</option>`
    ).join('');
    const rows = (data.assignments || []).map((v) => {
      const cadetCell = canEdit
        ? `<select class="select-inp" id="assignCadet${v.vehicle_id}">${cadetOptions(v.cadet_id)}</select>`
        : (v.cadet_id ? escMgr(cadetNames[Number(v.cadet_id)] || 'Assigned') : '<span class="badge bd">Unassigned</span>');
      const routeCell = canEdit
        ? `<input class="input" id="assignRoute${v.vehicle_id}" value="${escMgr(v.route_area || '')}" placeholder="e.g. Route A">`
        : (escMgr(v.route_area || '') || '<span class="badge bd">—</span>');
      const actionCell = canEdit
        ? `<td><button class="btn btn-sm btn-red" type="button" onclick="saveVehicleAssignment(${v.vehicle_id}, this)">Save</button></td>`
        : '';
      return `<tr><td><strong>${escMgr(v.registration)}</strong><div style="font-size:11px;color:var(--gray-mid)">${escMgr(v.vehicle_type)}</div></td><td>${cadetCell}</td><td>${routeCell}</td>${actionCell}</tr>`;
    }).join('');
    const actionHeader = canEdit ? '<th>Action</th>' : '';
    const colSpan = canEdit ? 4 : 3;
    table.innerHTML = `<tr><th>Vehicle</th><th>Cadet</th><th>Route (e.g. Route A)</th>${actionHeader}</tr>${rows || `<tr><td colspan="${colSpan}">No active vehicles found.</td></tr>`}`;
  } catch (e) {
    table.innerHTML = `<tr><td style="color:var(--red)">${escMgr(e.message || 'Could not load assignments')}</td></tr>`;
  }
}

async function saveVehicleAssignment(vehicleId, button) {
  const cadetId = document.getElementById(`assignCadet${vehicleId}`)?.value || null;
  const routeArea = document.getElementById(`assignRoute${vehicleId}`)?.value.trim() || '';
  const restore = typeof mgrSetBusy === 'function' ? mgrSetBusy(button, 'Saving...') : () => {};
  try {
    await LapokAPI.post('/api/assignments/save.php', { vehicle_id: vehicleId, cadet_id: cadetId, route_area: routeArea });
    adminToast('Vehicle, cadet and route saved');
    await loadUsersTable();
  } catch (e) {
    adminToast(e.message || 'Could not save assignment', true);
  } finally { restore(); }
}

function applyUsersFilter() {
  const table = document.getElementById('userTable');
  if (!table) return;
  const isExec = currentUser?.role === 'executive';
  const q = (document.getElementById('adminUserSearch')?.value || '').toLowerCase();
  const roleFilter = document.getElementById('adminUserRoleFilter')?.value || '';
  const roleBadge = (r) => `<span class="badge ${r === 'admin' || r === 'executive' ? 'br' : r === 'manager' ? 'bw' : 'bi'}">${LapokAPI.roleLabel[r] || r}</span>`;
  const filtered = adminUsersCache.filter((u) => u.role !== 'admin').filter((u) => {
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
  const roleSel = document.getElementById('editUserRole');
  if (roleSel) {
    const available = ['admin', 'executive', 'manager', 'accountant', 'cadet'];
    const legacy = u.role && !available.includes(u.role);
    const opts = available.map((r) => `<option value="${r}">${LapokAPI.roleLabel[r] || r}</option>`);
    if (legacy) opts.push(`<option value="${u.role}">${LapokAPI.roleLabel[u.role] || u.role} (legacy)</option>`);
    roleSel.innerHTML = opts.join('');
    roleSel.value = u.role || 'cadet';
  }
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
    role: document.getElementById('addUserRole')?.value || 'cadet',
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
    role: document.getElementById('editUserRole')?.value || 'cadet',
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
    filename: 'Outpost-DMS-Users-' + LapokAPI.localIsoDate() + '.xls',

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
      body.innerHTML = `<p>Total revenue: <strong>${LapokAPI.formatUgx(d.summary?.revenue)}</strong> · Trips: ${d.summary?.trips} · Cartons: ${d.summary?.cartons}</p>`;
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
  if (toEl && !toEl.value) toEl.value = LapokAPI.localIsoDate();

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
      if (role) role.value = 'cadet';
      const vehicle = document.getElementById('addUserVehicleId');
      if (vehicle) vehicle.value = '';
      hydrateUserVehicleOptions();
    }
    if (id === 'addVehicleModal') {
      const err = document.getElementById('addVehicleErr');
      if (err) err.style.display = 'none';
      const reg = document.getElementById('addVehicleReg');
      if (reg) reg.value = '';
      const make = document.getElementById('addVehicleMake');
      if (make) make.value = '';
      const type = document.getElementById('addVehicleType');
      if (type) type.value = 'truck';
      const cap = document.getElementById('addVehicleCapacity');
      if (cap) cap.value = '40';
    }
    _origOpenModal(id);
  };
}

// Extend page navigation
document.addEventListener('DOMContentLoaded', () => {
  const hook = window.showPage;
  if (typeof hook !== 'function') return;

  const phase45Pages = {
    'admin-dashboard': () => { execMonthbarInit(); loadAdminDashboard(); loadLiveCharts(); },
    'admin-console': () => loadAdminConsole(),
    'manager-dashboard': () => { loadAdminDashboard(); loadManagerDashboardExtras(); },
    'report-exchange': () => loadReportExchangePage(),
    'user-dashboard': () => loadFieldDashboard(),
    'user-route': () => loadMyRoute(),
    'cadet-dashboard': () => { if (typeof loadCadetDashboardPage === 'function') loadCadetDashboardPage(); },
    'cadet-daily': () => { if (typeof loadCadetDailyPage === 'function') loadCadetDailyPage(); },
    'user-customers': () => loadUserCustomers(),
    'admin-routes': () => loadRoutes(),
    'admin-audit': () => { initAuditFilters(); loadAuditLog(); },
    'admin-reports': () => { initAdminReportFilters(); loadFinancialReports(); loadLiveCharts(); },
    'manager-reports': () => loadSalesReports(),
    'manager-targets': () => loadManagerTargetsPage(),
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
    'admin-fleet': () => loadAdminFleet(),
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
    'manager-dispatch': () => {
      prepareDispatchModal();
      loadDispatchLog();
      loadStockTable();
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
