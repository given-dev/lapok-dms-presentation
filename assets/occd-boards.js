/**
 * Lapok DMS — Manager daily OCCD / Inventory boards (whiteboard digitization)
 */
let occdBoardDate = new Date().toISOString().slice(0, 10);
let occdInventoryData = null;
let occdDashboardData = null;
let occdRenderContext = 'inv';
let occdActiveTab = 'inventory';

const OCCD_OUTLET_LABELS = {
  coke: 'Coke', kad: 'KAD', stockists: 'Stockists', superettes: 'Superettes',
  pfns: 'PFNs', horeca: 'HORECA', bars_pubs: 'Bars/Pubs', education: 'Education',
};
const OCCD_TIER_LABELS = { gold: 'Gold', silver: 'Silver', bronze: 'Bronze', tin: 'Tin' };
const OCCD_SALES_LABELS = { csd: 'CSD', water: 'Water', juice: 'Juice', energy: 'Energy', total: 'Total' };
const OCCD_SERVICE_LABELS = {
  total_outlet_universe: 'TOTAL OUTLET UNIVERSE', new_outlets: 'NEW OUTLETS',
  red_outlets_total: '20 RED OUTLETS TOTAL', tier_gold: 'GOLD', tier_silver: 'SILVER',
  tier_bronze: 'BRONZE', tier_tin: 'TIN', call_adherence: 'CALL ADHERENCE',
  strike_rate: 'STRIKE RATE', presale_pct: 'PRESALE %', presale_delivery_pct: 'PRESALE DELIVERY %',
  myccba_delivery_pct: 'MYCCBA DELIVERY %', warehouse_sqm: 'WAREHOUSE SQM',
  capitalization: 'CAPITALIZATION', adca_score: 'ADCA SCORE',
};
const OCCD_EXEC_LABELS = {
  digitized_outlets: 'DIGITIZED OUTLETS', active_digitized_outlets: 'ACTIVE DIGITIZED OUTLETS',
  red_score: 'RED SCORE', unforgivable_nd: 'UNFORGIVABLE ND', buying_customers: 'BUYING CUSTOMERS',
  nps: 'NPS', obe_cwm: 'OBE — CWM', obe_sprite_otg: 'OBE — SPRITE ON THE GO',
  obe_santa_snacking: 'OBE — SANTA SNACKING',
};

function occdCanEdit() {
  return currentUser && ['admin', 'manager'].includes(currentUser.role);
}

function occdBoardLocked() {
  if (occdRenderContext === 'inv') return occdInventoryData?.status === 'submitted';
  if (occdRenderContext === 'dash') return occdDashboardData?.status === 'submitted';
  return false;
}

function occdNum(v) {
  const n = parseFloat(String(v ?? '').replace(/,/g, ''));
  return Number.isFinite(n) ? n : 0;
}

function occdFmt(n) {
  if (!n && n !== 0) return '—';
  return Number(n).toLocaleString();
}

function occdPct(n) {
  if (!Number.isFinite(n) || !isFinite(n)) return '—';
  return n.toFixed(1) + '%';
}

function occdInp(val, attrs = '') {
  if (!occdCanEdit() || occdBoardLocked()) return `<span class="occd-readonly-val">${occdFmt(occdNum(val)) || escOccd(val) || '—'}</span>`;
  return `<input class="occd-num-inp" type="text" inputmode="numeric" value="${escOccd(val)}" ${attrs}>`;
}

function occdTxt(val, attrs = '') {
  if (!occdCanEdit() || occdBoardLocked()) return `<span class="occd-readonly-val">${escOccd(val) || '—'}</span>`;
  return `<input class="occd-txt-inp" type="text" value="${escOccd(val)}" ${attrs}>`;
}

function escOccd(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

async function loadManagerOccdBoards() {
  const root = document.getElementById('occdBoardsRoot');
  if (!root || !document.getElementById('page-manager-ccba-boards')) return;
  if (!currentUser || !['admin', 'manager'].includes(currentUser.role)) return;

  const dateInp = document.getElementById('occdBoardDate');
  if (dateInp) dateInp.value = occdBoardDate;

  root.innerHTML = '<p style="color:var(--gray-mid);padding:1rem">Loading daily boards…</p>';
  try {
    const data = await LapokAPI.get('/api/occd/fetch_board.php?date=' + encodeURIComponent(occdBoardDate) + '&type=all');
    occdInventoryData = data.inventory_board;
    occdDashboardData = data.occd_dashboard;
    renderOccdBoards();
    updateOccdStatusChips();
    switchOccdTab(occdActiveTab);
  } catch (e) {
    root.innerHTML = `<div class="alert a-danger"><span>⚠</span>${escOccd(e.message)}</div>`;
  }
}

function updateOccdStatusChips() {
  const setChip = (id, board) => {
    const el = document.getElementById(id);
    if (!el || !board) return;
    const submitted = board.status === 'submitted';
    el.className = 'badge ' + (submitted ? 'bs' : 'bw');
    el.textContent = submitted ? 'Submitted' : 'Draft';
  };
  setChip('occdInvStatus', occdInventoryData);
  setChip('occdDashStatus', occdDashboardData);

  const invDone = occdInventoryData?.status === 'submitted';
  const dashDone = occdDashboardData?.status === 'submitted';
  document.querySelectorAll('#occdInventoryBoard .occd-save-btn').forEach((b) => { b.disabled = invDone; });
  document.querySelectorAll('#occdDashboardBoard .occd-save-btn').forEach((b) => { b.disabled = dashDone; });
  document.querySelectorAll('.occd-toolbar .occd-save-btn').forEach((b) => {
    b.disabled = invDone && dashDone;
  });
}

function onOccdDateChange() {
  const el = document.getElementById('occdBoardDate');
  if (el?.value) {
    occdBoardDate = el.value;
    loadManagerOccdBoards();
  }
}

function switchOccdTab(tab) {
  occdActiveTab = ['inventory', 'occd', 'sku-map'].includes(tab) ? tab : 'inventory';
  document.querySelectorAll('.occd-module-tab').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.occdTab === occdActiveTab);
  });
  document.querySelectorAll('.occd-tab-panel').forEach((panel) => {
    panel.classList.toggle('active', panel.dataset.occdPanel === occdActiveTab);
  });
  const boardsRoot = document.getElementById('occdBoardsRoot');
  if (boardsRoot) boardsRoot.style.display = occdActiveTab === 'sku-map' ? 'none' : '';
  if (occdActiveTab === 'sku-map' && typeof loadCcbaProductMap === 'function') loadCcbaProductMap();
}

function renderOccdBoards() {
  const root = document.getElementById('occdBoardsRoot');
  if (!root) return;
  root.innerHTML = `
    <div class="occd-tab-panel${occdActiveTab === 'inventory' ? ' active' : ''}" data-occd-panel="inventory" id="occdInventoryBoard">${renderInventoryBoard()}</div>
    <div class="occd-tab-panel${occdActiveTab === 'occd' ? ' active' : ''}" data-occd-panel="occd" id="occdDashboardBoard">${renderOccdDashboard()}</div>
  `;
  document.querySelectorAll('.occd-module-tab').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.occdTab === occdActiveTab);
  });
  bindOccdInputs();
  recalcInventoryTotals();
  recalcOutletTotals();
  recalcSalesTotals();
}

function renderBoardHeader(header, title, prefix) {
  const h = header || {};
  return `
    <div class="occd-board-banner">
      <div class="occd-board-title">${escOccd(title)}</div>
      <div class="occd-board-meta">
        <span><strong>OCCD NAME:</strong> ${occdTxt(h.occd_name || '', `data-${prefix}-header="occd_name"`)}</span>
        <span><strong>REGION:</strong> ${occdTxt(h.region || '', `data-${prefix}-header="region"`)}</span>
        <span><strong>DATE:</strong> ${LapokAPI.formatDate(occdBoardDate + 'T12:00:00')}</span>
      </div>
    </div>`;
}

function renderInventoryBoard() {
  occdRenderContext = 'inv';
  const p = occdInventoryData?.payload;
  if (!p) return '';
  let lastCat = '';
  let rows = '';
  (p.lines || []).forEach((line) => {
    if (line.category && line.category !== lastCat && line.row_type === 'sku') {
      lastCat = line.category;
      rows += `<tr class="occd-cat-row"><td colspan="5"><strong>${escOccd(line.category)}</strong></td></tr>`;
    }
    const isTotal = line.row_type !== 'sku';
    const cls = line.row_type === 'grand_total' ? 'occd-grand-row' : (isTotal ? 'occd-total-row' : '');
    const key = line.key;
    const v = p.values[key] || {};
    if (isTotal) {
      rows += `<tr class="${cls}" data-inv-total="${key}" data-inv-section="${line.category || 'all'}">
        <td><strong>${escOccd(line.sku)}</strong></td>
        <td class="occd-calc" data-field="recommended">—</td>
        <td class="occd-calc" data-field="opening">—</td>
        <td class="occd-calc" data-field="on_order">—</td>
        <td></td>
      </tr>`;
      return;
    }
    rows += `<tr data-inv-key="${key}">
      <td>${escOccd(line.sku)}</td>
      <td>${occdInp(v.recommended, `data-inv="${key}" data-field="recommended"`)}</td>
      <td>${occdInp(v.opening, `data-inv="${key}" data-field="opening"`)}</td>
      <td>${occdInp(v.on_order, `data-inv="${key}" data-field="on_order"`)}</td>
      <td>${occdTxt(v.comments, `data-inv="${key}" data-field="comments"`)}</td>
    </tr>`;
  });

  return `
    <div class="card occd-board-card">
      <div class="card-header">
        <span class="card-title">Inventory board</span>
        <span class="chip">Physical cases · daily</span>
      </div>
      ${renderBoardHeader(p.header, 'INVENTORY BOARD', 'inv')}
      <div class="tbl-wrap occd-tbl">
        <table>
          <tr>
            <th>SKU</th>
            <th>Recommended stock</th>
            <th>Actual opening stock</th>
            <th>Quantity on order</th>
            <th>Comments</th>
          </tr>
          ${rows}
        </table>
      </div>
      <div class="occd-board-actions">
        <button class="btn btn-red occd-save-btn" onclick="saveOccdBoard('inventory_board', false)">Save inventory draft</button>
        <button class="btn btn-black occd-save-btn" onclick="saveOccdBoard('inventory_board', true)">Submit inventory</button>
      </div>
    </div>`;
}

function renderOccdDashboard() {
  occdRenderContext = 'dash';
  const p = occdDashboardData?.payload;
  if (!p) return '';
  return `
    <div class="card occd-board-card">
      <div class="card-header">
        <span class="card-title">OCCD dashboard</span>
        <span class="chip">CCBA reporting · daily</span>
      </div>
      ${renderBoardHeader(p.header, 'OCCD DASHBOARD', 'dash')}
      <div class="occd-panels">
        <details class="occd-panel" open>
          <summary>Outlet data</summary>
          ${renderOutletPanel(p.outlet_data)}
        </details>
        <details class="occd-panel" open>
          <summary>Sales performance (unit cases)</summary>
          ${renderSalesPanel(p.sales_performance)}
        </details>
        <details class="occd-panel">
          <summary>Service model</summary>
          ${renderMetricPanel('service', p.service_model, OCCD_SERVICE_LABELS)}
        </details>
        <details class="occd-panel">
          <summary>Execution excellence</summary>
          ${renderMetricPanel('execution', p.execution_excellence, OCCD_EXEC_LABELS)}
        </details>
        <details class="occd-panel" open>
          <summary>Unforgivable packs inventory (physical cases)</summary>
          ${renderUnforgivablePanel(p.unforgivable_packs)}
        </details>
      </div>
      <div class="occd-board-actions">
        <button class="btn btn-red occd-save-btn" onclick="saveOccdBoard('occd_dashboard', false)">Save OCCD draft</button>
        <button class="btn btn-black occd-save-btn" onclick="saveOccdBoard('occd_dashboard', true)">Submit OCCD dashboard</button>
      </div>
    </div>`;
}

function renderOutletPanel(panel) {
  const channels = panel?.channels || [];
  const tiers = panel?.tiers || [];
  const vals = panel?.values || {};
  let head = '<tr><th>Tier</th>';
  channels.forEach((c) => { head += `<th>${OCCD_OUTLET_LABELS[c] || c}</th>`; });
  head += '<th>Total</th><th>% Contribution</th></tr>';

  let body = '';
  tiers.forEach((tier) => {
    body += `<tr data-outlet-tier="${tier}">
      <td><strong>${OCCD_TIER_LABELS[tier] || tier}</strong></td>`;
    channels.forEach((ch) => {
      const v = vals[tier]?.[ch] ?? '';
      body += `<td>${occdInp(v, `data-outlet-tier="${tier}" data-outlet-ch="${ch}"`)}</td>`;
    });
    body += `<td class="occd-calc occd-outlet-row-total">—</td>
      <td class="occd-calc occd-outlet-row-pct">—</td></tr>`;
  });
  body += `<tr class="occd-total-row" data-outlet-grand>
    <td><strong>Total</strong></td>`;
  channels.forEach((ch) => {
    body += `<td class="occd-calc occd-outlet-col-total" data-outlet-col="${ch}">—</td>`;
  });
  body += `<td class="occd-calc occd-outlet-grand-total">—</td><td>100%</td></tr>`;

  return `<div class="tbl-wrap occd-tbl"><table>${head}${body}</table></div>`;
}

function renderSalesPanel(panel) {
  const sections = { current_month: 'Current month', ytd: 'YTD' };
  let html = '';
  Object.entries(sections).forEach(([secKey, secLabel]) => {
    const cats = [...(panel?.categories || []).filter((c) => c !== 'total'), 'total'];
    let rows = '';
    cats.forEach((cat) => {
      const isTotal = cat === 'total';
      const v = panel?.values?.[secKey]?.[cat] || {};
      if (isTotal) {
        rows += `<tr class="occd-total-row" data-sales-total="${secKey}">
          <td><strong>Total</strong></td>
          <td class="occd-calc" data-f="cy">—</td>
          <td class="occd-calc" data-f="target">—</td>
          <td class="occd-calc" data-f="py">—</td>
          <td class="occd-calc" data-f="var_target">—</td>
          <td class="occd-calc" data-f="var_py">—</td>
        </tr>`;
        return;
      }
      rows += `<tr data-sales-sec="${secKey}" data-sales-cat="${cat}">
        <td>${OCCD_SALES_LABELS[cat] || cat}</td>
        <td>${occdInp(v.cy, `data-sales-sec="${secKey}" data-sales-cat="${cat}" data-f="cy"`)}</td>
        <td>${occdInp(v.target, `data-sales-sec="${secKey}" data-sales-cat="${cat}" data-f="target"`)}</td>
        <td>${occdInp(v.py, `data-sales-sec="${secKey}" data-sales-cat="${cat}" data-f="py"`)}</td>
        <td class="occd-calc occd-sales-var-t" data-sales-sec="${secKey}" data-sales-cat="${cat}">—</td>
        <td class="occd-calc occd-sales-var-p" data-sales-sec="${secKey}" data-sales-cat="${cat}">—</td>
      </tr>`;
    });
    html += `<div class="occd-subpanel"><div class="occd-subtitle">${secLabel}</div>
      <div class="tbl-wrap occd-tbl"><table>
        <tr><th>Category</th><th>CY</th><th>Target</th><th>PY</th><th>VAR vs TARGET</th><th>VAR vs PY</th></tr>
        ${rows}
      </table></div></div>`;
  });
  return html;
}

function renderMetricPanel(prefix, panel, labels) {
  const rows = panel?.rows || [];
  const vals = panel?.values || {};
  let body = rows.map((row) => {
    const v = vals[row] || {};
    return `<tr data-metric-row="${row}" data-metric-prefix="${prefix}">
      <td>${labels[row] || row}</td>
      <td>${occdInp(v.mtd, `data-metric-prefix="${prefix}" data-metric-row="${row}" data-f="mtd"`)}</td>
      <td>${occdInp(v.mtd_target, `data-metric-prefix="${prefix}" data-metric-row="${row}" data-f="mtd_target"`)}</td>
      <td>${occdInp(v.ytd, `data-metric-prefix="${prefix}" data-metric-row="${row}" data-f="ytd"`)}</td>
      <td>${occdInp(v.ytd_target, `data-metric-prefix="${prefix}" data-metric-row="${row}" data-f="ytd_target"`)}</td>
    </tr>`;
  }).join('');
  return `<div class="tbl-wrap occd-tbl"><table>
    <tr><th>Metric</th><th>MTD</th><th>Target</th><th>YTD</th><th>Target</th></tr>
    ${body}
  </table></div>`;
}

function renderUnforgivablePanel(panel) {
  const lines = panel?.lines || [];
  const vals = panel?.values || {};
  let body = lines.map((line) => {
    const v = vals[line.key] || {};
    return `<tr data-uf-key="${line.key}">
      <td>${escOccd(line.sku)}</td>
      <td>${occdInp(v.recommended, `data-uf="${line.key}" data-field="recommended"`)}</td>
      <td>${occdInp(v.opening, `data-uf="${line.key}" data-field="opening"`)}</td>
      <td>${occdInp(v.on_order, `data-uf="${line.key}" data-field="on_order"`)}</td>
      <td>${occdTxt(v.comments, `data-uf="${line.key}" data-field="comments"`)}</td>
    </tr>`;
  }).join('');
  return `<div class="tbl-wrap occd-tbl"><table>
    <tr><th>SKU</th><th>Recommended stock</th><th>Opening stock</th><th>Qty on order</th><th>Comments</th></tr>
    ${body}
  </table></div>`;
}

function bindOccdInputs() {
  document.querySelectorAll('.occd-num-inp, .occd-txt-inp').forEach((inp) => {
    inp.addEventListener('input', () => {
      if (inp.dataset.inv) recalcInventoryTotals();
      if (inp.dataset.outletTier) recalcOutletTotals();
      if (inp.dataset.salesSec) recalcSalesTotals();
    });
  });
}

function recalcInventoryTotals() {
  const p = occdInventoryData?.payload;
  if (!p) return;
  const sums = {};
  (p.lines || []).forEach((line) => {
    if (line.row_type !== 'sku') return;
    const cat = line.category;
    if (!sums[cat]) sums[cat] = { recommended: 0, opening: 0, on_order: 0 };
    ['recommended', 'opening', 'on_order'].forEach((f) => {
      const inp = document.querySelector(`[data-inv="${line.key}"][data-field="${f}"]`);
      sums[cat][f] += occdNum(inp?.value);
    });
  });
  const grand = { recommended: 0, opening: 0, on_order: 0 };
  Object.values(sums).forEach((s) => {
    grand.recommended += s.recommended;
    grand.opening += s.opening;
    grand.on_order += s.on_order;
  });
  document.querySelectorAll('[data-inv-total]').forEach((row) => {
    const section = row.dataset.invSection;
    const src = row.dataset.invTotal === 'grand_total' ? grand : (sums[section] || grand);
    ['recommended', 'opening', 'on_order'].forEach((f) => {
      const cell = row.querySelector(`[data-field="${f}"]`);
      if (cell) cell.textContent = occdFmt(src[f]);
    });
  });
}

function recalcOutletTotals() {
  const panel = occdDashboardData?.payload?.outlet_data;
  if (!panel) return;
  const tiers = panel.tiers || [];
  const channels = panel.channels || [];
  const colTotals = {};
  const rowTotals = {};
  channels.forEach((c) => { colTotals[c] = 0; });
  let grand = 0;

  tiers.forEach((tier) => {
    let rowTotal = 0;
    channels.forEach((ch) => {
      const inp = document.querySelector(`[data-outlet-tier="${tier}"][data-outlet-ch="${ch}"]`);
      rowTotal += occdNum(inp?.value);
    });
    rowTotals[tier] = rowTotal;
    grand += rowTotal;
  });

  tiers.forEach((tier) => {
    channels.forEach((ch) => {
      const inp = document.querySelector(`[data-outlet-tier="${tier}"][data-outlet-ch="${ch}"]`);
      colTotals[ch] += occdNum(inp?.value);
    });
    const tr = document.querySelector(`tr[data-outlet-tier="${tier}"]`);
    if (tr) {
      const tCell = tr.querySelector('.occd-outlet-row-total');
      const pCell = tr.querySelector('.occd-outlet-row-pct');
      if (tCell) tCell.textContent = occdFmt(rowTotals[tier]);
      if (pCell) pCell.textContent = grand > 0 ? occdPct((rowTotals[tier] / grand) * 100) : '—';
    }
  });

  const grandRow = document.querySelector('[data-outlet-grand]');
  if (grandRow) {
    channels.forEach((ch) => {
      const cell = grandRow.querySelector(`[data-outlet-col="${ch}"]`);
      if (cell) cell.textContent = occdFmt(colTotals[ch]);
    });
    const gt = grandRow.querySelector('.occd-outlet-grand-total');
    if (gt) gt.textContent = occdFmt(grand);
  }
}

function recalcSalesTotals() {
  const panel = occdDashboardData?.payload?.sales_performance;
  if (!panel) return;
  (panel.sections || []).forEach((sec) => {
    const cats = (panel.categories || []).filter((c) => c !== 'total');
    const totals = { cy: 0, target: 0, py: 0 };
    cats.forEach((cat) => {
      ['cy', 'target', 'py'].forEach((f) => {
        const inp = document.querySelector(`[data-sales-sec="${sec}"][data-sales-cat="${cat}"][data-f="${f}"]`);
        totals[f] += occdNum(inp?.value);
      });
      const cy = occdNum(document.querySelector(`[data-sales-sec="${sec}"][data-sales-cat="${cat}"][data-f="cy"]`)?.value);
      const tg = occdNum(document.querySelector(`[data-sales-sec="${sec}"][data-sales-cat="${cat}"][data-f="target"]`)?.value);
      const py = occdNum(document.querySelector(`[data-sales-sec="${sec}"][data-sales-cat="${cat}"][data-f="py"]`)?.value);
      const vt = document.querySelector(`.occd-sales-var-t[data-sales-sec="${sec}"][data-sales-cat="${cat}"]`);
      const vp = document.querySelector(`.occd-sales-var-p[data-sales-sec="${sec}"][data-sales-cat="${cat}"]`);
      if (vt) vt.textContent = occdFmt(cy - tg);
      if (vp) vp.textContent = occdFmt(cy - py);
    });
    const totalRow = document.querySelector(`[data-sales-total="${sec}"]`);
    if (totalRow) {
      ['cy', 'target', 'py'].forEach((f) => {
        const cell = totalRow.querySelector(`[data-f="${f}"]`);
        if (cell) cell.textContent = occdFmt(totals[f]);
      });
      const vt = totalRow.querySelector('[data-f="var_target"]');
      const vp = totalRow.querySelector('[data-f="var_py"]');
      if (vt) vt.textContent = occdFmt(totals.cy - totals.target);
      if (vp) vp.textContent = occdFmt(totals.cy - totals.py);
    }
  });
}

function collectInventoryPayload() {
  const p = JSON.parse(JSON.stringify(occdInventoryData.payload));
  document.querySelectorAll('[data-inv-header]').forEach((inp) => {
    p.header[inp.dataset.invHeader] = inp.value;
  });
  Object.keys(p.values).forEach((key) => {
    ['recommended', 'opening', 'on_order', 'comments'].forEach((f) => {
      const inp = document.querySelector(`[data-inv="${key}"][data-field="${f}"]`);
      if (inp) p.values[key][f] = inp.value;
    });
  });
  return p;
}

function collectDashboardPayload() {
  const p = JSON.parse(JSON.stringify(occdDashboardData.payload));
  document.querySelectorAll('[data-dash-header]').forEach((inp) => {
    p.header[inp.dataset.dashHeader] = inp.value;
  });
  const od = p.outlet_data;
  (od.tiers || []).forEach((tier) => {
    (od.channels || []).forEach((ch) => {
      const inp = document.querySelector(`[data-outlet-tier="${tier}"][data-outlet-ch="${ch}"]`);
      if (inp) od.values[tier][ch] = inp.value;
    });
  });
  const sp = p.sales_performance;
  (sp.sections || []).forEach((sec) => {
    (sp.categories || []).filter((c) => c !== 'total').forEach((cat) => {
      ['cy', 'target', 'py'].forEach((f) => {
        const inp = document.querySelector(`[data-sales-sec="${sec}"][data-sales-cat="${cat}"][data-f="${f}"]`);
        if (inp) sp.values[sec][cat][f] = inp.value;
      });
    });
  });
  ['service_model', 'execution_excellence'].forEach((panelKey) => {
    const panel = p[panelKey];
    const prefix = panelKey === 'service_model' ? 'service' : 'execution';
    (panel.rows || []).forEach((row) => {
      ['mtd', 'mtd_target', 'ytd', 'ytd_target'].forEach((f) => {
        const inp = document.querySelector(`[data-metric-prefix="${prefix}"][data-metric-row="${row}"][data-f="${f}"]`);
        if (inp) panel.values[row][f] = inp.value;
      });
    });
  });
  (p.unforgivable_packs.lines || []).forEach((line) => {
    ['recommended', 'opening', 'on_order', 'comments'].forEach((f) => {
      const inp = document.querySelector(`[data-uf="${line.key}"][data-field="${f}"]`);
      if (inp) p.unforgivable_packs.values[line.key][f] = inp.value;
    });
  });
  return p;
}

async function saveOccdBoard(type, submit) {
  if (!occdCanEdit()) return;
  const board = type === 'inventory_board' ? occdInventoryData : occdDashboardData;
  if (board?.status === 'submitted') {
    alert('This board is already submitted for the selected date.');
    return;
  }
  const payload = type === 'inventory_board' ? collectInventoryPayload() : collectDashboardPayload();
  try {
    const data = await LapokAPI.post('/api/occd/save_board.php', {
      board_date: occdBoardDate,
      board_type: type,
      payload,
      submit,
    });
    if (type === 'inventory_board') occdInventoryData = data;
    else occdDashboardData = data;
    updateOccdStatusChips();
    alert(submit ? 'Board submitted for ' + LapokAPI.formatDate(occdBoardDate + 'T12:00:00') + '.' : 'Draft saved.');
    if (submit) loadManagerOccdBoards();
  } catch (e) {
    alert(e.message);
  }
}

async function saveAllOccdBoards(submit) {
  if (!occdCanEdit()) return;
  try {
    const invPayload = collectInventoryPayload();
    const dashPayload = collectDashboardPayload();
    const inv = await LapokAPI.post('/api/occd/save_board.php', {
      board_date: occdBoardDate, board_type: 'inventory_board', payload: invPayload, submit,
    });
    const dash = await LapokAPI.post('/api/occd/save_board.php', {
      board_date: occdBoardDate, board_type: 'occd_dashboard', payload: dashPayload, submit,
    });
    occdInventoryData = inv;
    occdDashboardData = dash;
    updateOccdStatusChips();
    alert(submit ? 'Both boards submitted.' : 'Both boards saved as draft.');
    if (submit) loadManagerOccdBoards();
  } catch (e) {
    alert(e.message);
  }
}
