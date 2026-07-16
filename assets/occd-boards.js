/**
 * Lapok DMS — Manager daily OCCD / Inventory boards (whiteboard digitization)
 */
let occdBoardDate = '';
let occdInventoryData = null;
let occdDashboardData = null;
let occdRenderContext = 'inv';
let occdActiveTab = 'inventory';
/** Explicit unlock after save/submit — prevents accidental edits. */
let occdUnlock = { inventory_board: false, occd_dashboard: false };

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

function occdBoardHasSaved(board) {
  return !!(board && (board.updated_at || board.submitted_at || board.id || board.status === 'submitted'));
}

function occdIsBoardLocked(boardType) {
  if (!occdCanEdit()) return true;
  if (occdUnlock[boardType]) return false;
  const board = boardType === 'inventory_board' ? occdInventoryData : occdDashboardData;
  if (!board) return false;
  if (board.status === 'submitted') return true;
  return occdBoardHasSaved(board);
}

function occdBoardLocked() {
  const type = occdRenderContext === 'inv' ? 'inventory_board' : 'occd_dashboard';
  return occdIsBoardLocked(type);
}

function unlockOccdBoard(boardType) {
  if (!occdCanEdit()) return;
  const board = boardType === 'inventory_board' ? occdInventoryData : occdDashboardData;
  const submitted = board?.status === 'submitted';
  const msg = submitted
    ? 'Unlock this submitted board for corrections? You will need to save (and re-submit if needed).'
    : 'Unlock this board for editing?';
  if (!confirm(msg)) return;
  occdUnlock[boardType] = true;
  renderOccdBoards();
  updateOccdStatusChips();
  switchOccdTab(occdActiveTab);
}

function occdLockBanner(boardType, label) {
  if (!occdIsBoardLocked(boardType)) return '';
  const board = boardType === 'inventory_board' ? occdInventoryData : occdDashboardData;
  const state = board?.status === 'submitted' ? 'Submitted & locked' : 'Saved & locked';
  return `<div class="alert a-info occd-lock-banner" style="margin:12px 1rem 0">
    <span>ℹ</span>
    <div style="font-size:12px"><strong>${state}.</strong> ${escOccd(label)} is read-only to prevent accidental changes.
      <button type="button" class="btn btn-sm btn-red" style="margin-left:8px" onclick="unlockOccdBoard('${boardType}')">Edit</button>
    </div>
  </div>`;
}

function occdBoardActionButtons(boardType, draftLabel, submitLabel) {
  if (occdIsBoardLocked(boardType)) {
    return `<div class="occd-board-actions">
      <span class="chip">Locked</span>
      <button type="button" class="btn btn-red" onclick="unlockOccdBoard('${boardType}')">Edit board</button>
    </div>`;
  }
  return `<div class="occd-board-actions">
    <button class="btn btn-red occd-save-btn" onclick="saveOccdBoard('${boardType}', false)">${escOccd(draftLabel)}</button>
    <button class="btn btn-black occd-save-btn" onclick="saveOccdBoard('${boardType}', true)">${escOccd(submitLabel)}</button>
  </div>`;
}

function occdNum(v) {
  const n = parseFloat(String(v ?? '').replace(/,/g, '').trim());
  return Number.isFinite(n) ? n : 0;
}

function occdFmt(n) {
  if (n === '' || n === null || n === undefined) return '—';
  const num = typeof n === 'number' ? n : occdNum(n);
  if (!Number.isFinite(num)) return '—';
  return num.toLocaleString();
}

function occdPct(n) {
  if (!Number.isFinite(n) || !isFinite(n)) return '—';
  return n.toFixed(1) + '%';
}

/** Keep data-* attrs on locked cells so totals/collect still work after save. */
function occdInp(val, attrs = '') {
  const raw = val === null || val === undefined ? '' : String(val);
  if (!occdCanEdit() || occdBoardLocked()) {
    return `<span class="occd-readonly-val occd-num-cell" data-occd-val="${escOccd(raw)}" ${attrs}>${occdFmt(occdNum(raw))}</span>`;
  }
  return `<input class="occd-num-inp" type="text" inputmode="numeric" value="${escOccd(raw)}" ${attrs}>`;
}

function occdTxt(val, attrs = '') {
  const raw = val === null || val === undefined ? '' : String(val);
  if (!occdCanEdit() || occdBoardLocked()) {
    return `<span class="occd-readonly-val" data-occd-val="${escOccd(raw)}" ${attrs}>${escOccd(raw) || '—'}</span>`;
  }
  return `<input class="occd-txt-inp" type="text" value="${escOccd(raw)}" ${attrs}>`;
}

function escOccd(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

/** Read a board field from input or locked span. */
function occdReadVal(selector) {
  const el = document.querySelector(selector);
  if (!el) return '';
  if ('value' in el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')) {
    return el.value;
  }
  if (el.dataset && el.dataset.occdVal !== undefined) return el.dataset.occdVal;
  return (el.textContent || '').trim();
}

function occdReadNum(selector) {
  return occdNum(occdReadVal(selector));
}

function inventorySectionTotals(payload) {
  const sums = {};
  const grand = { recommended: 0, opening: 0, on_order: 0 };
  (payload?.lines || []).forEach((line) => {
    if (line.row_type !== 'sku') return;
    const cat = line.category || 'OTHER';
    if (!sums[cat]) sums[cat] = { recommended: 0, opening: 0, on_order: 0 };
    const v = payload.values?.[line.key] || {};
    const recommended = occdNum(v.recommended);
    const opening = occdNum(v.opening);
    const onOrder = occdNum(v.on_order);
    sums[cat].recommended += recommended;
    sums[cat].opening += opening;
    sums[cat].on_order += onOrder;
    grand.recommended += recommended;
    grand.opening += opening;
    grand.on_order += onOrder;
  });
  return { sums, grand };
}

async function loadManagerOccdBoards() {
  const root = document.getElementById('occdBoardsRoot');
  if (!root || !document.getElementById('page-manager-ccba-boards')) return;
  if (!currentUser || !['admin', 'manager'].includes(currentUser.role)) return;

  const dateInp = document.getElementById('occdBoardDate');
  if (dateInp && !dateInp.value) {
    occdBoardDate = LapokAPI.todayIso();
    dateInp.value = occdBoardDate;
  } else if (dateInp) {
    occdBoardDate = dateInp.value;
  } else if (!occdBoardDate) {
    occdBoardDate = LapokAPI.todayIso();
  }

  occdUnlock = { inventory_board: false, occd_dashboard: false };
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
  const setChip = (id, board, boardType) => {
    const el = document.getElementById(id);
    if (!el || !board) return;
    const locked = occdIsBoardLocked(boardType);
    const submitted = board.status === 'submitted';
    el.className = 'badge ' + (submitted ? 'bs' : (locked ? 'bg' : 'bw'));
    el.textContent = submitted ? 'Submitted · locked' : (locked ? 'Saved · locked' : 'Draft · editable');
  };
  setChip('occdInvStatus', occdInventoryData, 'inventory_board');
  setChip('occdDashStatus', occdDashboardData, 'occd_dashboard');

  const invLocked = occdIsBoardLocked('inventory_board');
  const dashLocked = occdIsBoardLocked('occd_dashboard');
  document.querySelectorAll('#occdInventoryBoard .occd-save-btn').forEach((b) => { b.disabled = invLocked; });
  document.querySelectorAll('#occdDashboardBoard .occd-save-btn').forEach((b) => { b.disabled = dashLocked; });
  document.querySelectorAll('.occd-toolbar .occd-save-btn').forEach((b) => {
    b.disabled = invLocked || dashLocked;
    b.title = (invLocked || dashLocked) ? 'Unlock both boards with Edit before saving' : '';
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
  /* SKU map + warehouse sync UI are Phase 2 CCBA integration — see CCBA_INTEGRATION_BLUEPRINT.md */
  occdActiveTab = ['inventory', 'occd'].includes(tab) ? tab : 'inventory';
  document.querySelectorAll('.occd-module-tab').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.occdTab === occdActiveTab);
  });
  document.querySelectorAll('.occd-tab-panel').forEach((panel) => {
    panel.classList.toggle('active', panel.dataset.occdPanel === occdActiveTab);
  });
  const boardsRoot = document.getElementById('occdBoardsRoot');
  if (boardsRoot) boardsRoot.style.display = '';
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
  const { sums, grand } = inventorySectionTotals(p);
  let lastCat = '';
  let rows = '';
  (p.lines || []).forEach((line) => {
    if (line.category && line.category !== lastCat && line.row_type === 'sku') {
      lastCat = line.category;
      rows += `<tr class="occd-cat-row"><td colspan="5"><strong>${escOccd(line.category)}</strong></td></tr>`;
    }
    const isTotal = line.row_type !== 'sku';
    const key = line.key;
    const v = p.values[key] || {};
    if (isTotal) {
      const isGrand = line.row_type === 'grand_total';
      const src = isGrand ? grand : (sums[line.category] || { recommended: 0, opening: 0, on_order: 0 });
      const label = isGrand ? 'GRAND TOTAL' : `${escOccd(line.category || 'SECTION')} TOTAL`;
      const cls = isGrand ? 'occd-grand-row' : 'occd-total-row';
      rows += `<tr class="${cls}" data-inv-total="${key}" data-inv-section="${line.category || 'all'}">
        <td><strong>${label}</strong></td>
        <td class="occd-calc" data-field="recommended">${occdFmt(src.recommended)}</td>
        <td class="occd-calc" data-field="opening">${occdFmt(src.opening)}</td>
        <td class="occd-calc" data-field="on_order">${occdFmt(src.on_order)}</td>
        <td class="occd-calc occd-total-note">—</td>
      </tr>`;
      return;
    }
    rows += `<tr data-inv-key="${key}">
      <td>${escOccd(line.sku)}</td>
      <td class="occd-num-col">${occdInp(v.recommended, `data-inv="${key}" data-field="recommended"`)}</td>
      <td class="occd-num-col"><span class="occd-readonly-val occd-num-cell" data-inv-auto="${key}" data-field="opening" data-occd-val="${escOccd(v.opening ?? 0)}" title="From manager opening stock">${occdFmt(occdNum(v.opening))}</span></td>
      <td class="occd-num-col"><span class="occd-readonly-val occd-num-cell" data-inv-auto="${key}" data-field="on_order" data-occd-val="${escOccd(v.on_order ?? 0)}" title="From open Coca-Cola / CCBA orders">${occdFmt(occdNum(v.on_order))}</span></td>
      <td>${occdTxt(v.comments, `data-inv="${key}" data-field="comments"`)}</td>
    </tr>`;
  });

  return `
    <div class="card occd-board-card${occdIsBoardLocked('inventory_board') ? ' occd-board-locked' : ''}">
      <div class="card-header">
        <span class="card-title">Inventory board</span>
        <span class="chip">Physical cases · daily</span>
      </div>
      ${occdLockBanner('inventory_board', 'Inventory board')}
      ${renderBoardHeader(p.header, 'INVENTORY BOARD', 'inv')}
      <div class="tbl-wrap occd-tbl">
        <p style="font-size:11px;color:var(--gray-mid);margin:0 0 8px;padding:0 4px">
          <strong>Opening</strong> = manager 7am stock · <strong>Qty on order</strong> = open CCBA orders (automatic).
          Section totals and grand total sum each numeric column.
        </p>
        <table class="occd-inv-table">
          <thead>
          <tr>
            <th>SKU / pack</th>
            <th class="occd-num-col">Recommended</th>
            <th class="occd-num-col">Opening <small>auto</small></th>
            <th class="occd-num-col">On order <small>auto</small></th>
            <th>Comments</th>
          </tr>
          </thead>
          <tbody>
          ${rows}
          </tbody>
        </table>
      </div>
      ${occdBoardActionButtons('inventory_board', 'Save inventory draft', 'Submit inventory')}
    </div>`;
}

function renderOccdDashboard() {
  occdRenderContext = 'dash';
  const p = occdDashboardData?.payload;
  if (!p) return '';
  return `
    <div class="card occd-board-card${occdIsBoardLocked('occd_dashboard') ? ' occd-board-locked' : ''}">
      <div class="card-header">
        <span class="card-title">OCCD dashboard</span>
        <span class="chip">CCBA reporting · daily</span>
      </div>
      ${occdLockBanner('occd_dashboard', 'OCCD dashboard')}
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
      ${occdBoardActionButtons('occd_dashboard', 'Save OCCD draft', 'Submit OCCD dashboard')}
    </div>`;
}

function renderOutletPanel(panel) {
  const channels = panel?.channels || [];
  const tiers = panel?.tiers || [];
  const vals = panel?.values || {};

  const rowTotals = {};
  const colTotals = {};
  channels.forEach((c) => { colTotals[c] = 0; });
  let grand = 0;
  tiers.forEach((tier) => {
    let rowTotal = 0;
    channels.forEach((ch) => {
      const n = occdNum(vals[tier]?.[ch]);
      rowTotal += n;
      colTotals[ch] += n;
    });
    rowTotals[tier] = rowTotal;
    grand += rowTotal;
  });

  let head = '<tr><th>Tier</th>';
  channels.forEach((c) => { head += `<th class="occd-num-col">${OCCD_OUTLET_LABELS[c] || c}</th>`; });
  head += '<th class="occd-num-col">Total</th><th class="occd-num-col">% Contribution</th></tr>';

  let body = '';
  tiers.forEach((tier) => {
    body += `<tr data-outlet-tier="${tier}">
      <td><strong>${OCCD_TIER_LABELS[tier] || tier}</strong></td>`;
    channels.forEach((ch) => {
      const v = vals[tier]?.[ch] ?? '';
      body += `<td class="occd-num-col">${occdInp(v, `data-outlet-tier="${tier}" data-outlet-ch="${ch}"`)}</td>`;
    });
    const pct = grand > 0 ? occdPct((rowTotals[tier] / grand) * 100) : '—';
    body += `<td class="occd-calc occd-outlet-row-total">${occdFmt(rowTotals[tier])}</td>
      <td class="occd-calc occd-outlet-row-pct">${pct}</td></tr>`;
  });
  body += `<tr class="occd-total-row" data-outlet-grand>
    <td><strong>TOTAL</strong></td>`;
  channels.forEach((ch) => {
    body += `<td class="occd-calc occd-outlet-col-total" data-outlet-col="${ch}">${occdFmt(colTotals[ch])}</td>`;
  });
  body += `<td class="occd-calc occd-outlet-grand-total">${occdFmt(grand)}</td><td class="occd-calc">100%</td></tr>`;

  return `<div class="tbl-wrap occd-tbl"><table class="occd-dash-table">${head}${body}</table></div>`;
}

function renderSalesPanel(panel) {
  const sections = { current_month: 'Current month', ytd: 'YTD' };
  let html = '';
  Object.entries(sections).forEach(([secKey, secLabel]) => {
    const cats = [...(panel?.categories || []).filter((c) => c !== 'total'), 'total'];
    const sectionVals = panel?.values?.[secKey] || {};
    const totals = { cy: 0, target: 0, py: 0 };
    (panel?.categories || []).filter((c) => c !== 'total').forEach((cat) => {
      const v = sectionVals[cat] || {};
      totals.cy += occdNum(v.cy);
      totals.target += occdNum(v.target);
      totals.py += occdNum(v.py);
    });
    let rows = '';
    cats.forEach((cat) => {
      const isTotal = cat === 'total';
      const v = sectionVals[cat] || {};
      if (isTotal) {
        rows += `<tr class="occd-total-row" data-sales-total="${secKey}">
          <td><strong>TOTAL</strong></td>
          <td class="occd-calc" data-f="cy">${occdFmt(totals.cy)}</td>
          <td class="occd-calc" data-f="target">${occdFmt(totals.target)}</td>
          <td class="occd-calc" data-f="py">${occdFmt(totals.py)}</td>
          <td class="occd-calc" data-f="var_target">${occdFmt(totals.cy - totals.target)}</td>
          <td class="occd-calc" data-f="var_py">${occdFmt(totals.cy - totals.py)}</td>
        </tr>`;
        return;
      }
      const cy = occdNum(v.cy);
      const tg = occdNum(v.target);
      const py = occdNum(v.py);
      rows += `<tr data-sales-sec="${secKey}" data-sales-cat="${cat}">
        <td>${OCCD_SALES_LABELS[cat] || cat}</td>
        <td class="occd-num-col">${occdInp(v.cy, `data-sales-sec="${secKey}" data-sales-cat="${cat}" data-f="cy"`)}</td>
        <td class="occd-num-col">${occdInp(v.target, `data-sales-sec="${secKey}" data-sales-cat="${cat}" data-f="target"`)}</td>
        <td class="occd-num-col">${occdInp(v.py, `data-sales-sec="${secKey}" data-sales-cat="${cat}" data-f="py"`)}</td>
        <td class="occd-calc occd-sales-var-t" data-sales-sec="${secKey}" data-sales-cat="${cat}">${occdFmt(cy - tg)}</td>
        <td class="occd-calc occd-sales-var-p" data-sales-sec="${secKey}" data-sales-cat="${cat}">${occdFmt(cy - py)}</td>
      </tr>`;
    });
    html += `<div class="occd-subpanel"><div class="occd-subtitle">${secLabel}</div>
      <div class="tbl-wrap occd-tbl"><table class="occd-dash-table">
        <tr><th>Category</th><th class="occd-num-col">CY</th><th class="occd-num-col">Target</th><th class="occd-num-col">PY</th><th class="occd-num-col">VAR vs TARGET</th><th class="occd-num-col">VAR vs PY</th></tr>
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
    const opening = v.opening === '' || v.opening == null ? 0 : occdNum(v.opening);
    const onOrder = v.on_order === '' || v.on_order == null ? 0 : occdNum(v.on_order);
    return `<tr data-uf-key="${line.key}">
      <td>${escOccd(line.sku)}</td>
      <td>${occdInp(v.recommended, `data-uf="${line.key}" data-field="recommended"`)}</td>
      <td><span class="occd-readonly-val occd-uf-opening" title="From manager opening stock">${occdFmt(opening)}</span></td>
      <td><span class="occd-readonly-val" title="From open Coca-Cola / CCBA orders">${occdFmt(onOrder)}</span></td>
      <td>${occdTxt(v.comments, `data-uf="${line.key}" data-field="comments"`)}</td>
    </tr>`;
  }).join('');
  return `<div class="tbl-wrap occd-tbl">
    <p style="font-size:11px;color:var(--gray-mid);margin:0 0 8px">Opening = manager 7am stock · Qty on order = open CCBA orders. Both are locked / automatic.</p>
    <table>
    <tr><th>SKU</th><th>Recommended stock</th><th>Opening stock <small style="display:block;font-weight:600;text-transform:none;letter-spacing:0;color:var(--gray-mid)">Auto · manager</small></th><th>Qty on order <small style="display:block;font-weight:600;text-transform:none;letter-spacing:0;color:var(--gray-mid)">Auto · CCBA</small></th><th>Comments</th></tr>
    ${body}
  </table></div>`;
}

function bindOccdInputs() {
  document.querySelectorAll('.occd-num-inp, .occd-txt-inp').forEach((inp) => {
    inp.addEventListener('input', () => {
      if (inp.dataset.inv) {
        const key = inp.dataset.inv;
        const field = inp.dataset.field;
        if (key && field && occdInventoryData?.payload?.values?.[key]) {
          occdInventoryData.payload.values[key][field] = inp.value;
        }
        recalcInventoryTotals();
      }
      if (inp.dataset.outletTier) {
        const tier = inp.dataset.outletTier;
        const ch = inp.dataset.outletCh;
        if (tier && ch && occdDashboardData?.payload?.outlet_data?.values?.[tier]) {
          occdDashboardData.payload.outlet_data.values[tier][ch] = inp.value;
        }
        recalcOutletTotals();
      }
      if (inp.dataset.salesSec) {
        const sec = inp.dataset.salesSec;
        const cat = inp.dataset.salesCat;
        const f = inp.dataset.f;
        if (sec && cat && f && occdDashboardData?.payload?.sales_performance?.values?.[sec]?.[cat]) {
          occdDashboardData.payload.sales_performance.values[sec][cat][f] = inp.value;
        }
        recalcSalesTotals();
      }
    });
  });
}

function recalcInventoryTotals() {
  const p = occdInventoryData?.payload;
  if (!p) return;
  // Prefer live input values, else payload.
  (p.lines || []).forEach((line) => {
    if (line.row_type !== 'sku') return;
    const key = line.key;
    if (!p.values[key]) return;
    const rec = occdReadVal(`[data-inv="${key}"][data-field="recommended"]`);
    if (rec !== '') p.values[key].recommended = rec;
  });
  const { sums, grand } = inventorySectionTotals(p);
  document.querySelectorAll('[data-inv-total]').forEach((row) => {
    const key = row.dataset.invTotal;
    const section = row.dataset.invSection;
    const src = key === 'grand_total' ? grand : (sums[section] || { recommended: 0, opening: 0, on_order: 0 });
    ['recommended', 'opening', 'on_order'].forEach((f) => {
      const cell = row.querySelector(`[data-field="${f}"]`);
      if (cell) cell.textContent = occdFmt(src[f] || 0);
    });
  });
}

function recalcOutletTotals() {
  const panel = occdDashboardData?.payload?.outlet_data;
  if (!panel) return;
  const tiers = panel.tiers || [];
  const channels = panel.channels || [];
  const vals = panel.values || {};
  const colTotals = {};
  const rowTotals = {};
  channels.forEach((c) => { colTotals[c] = 0; });
  let grand = 0;

  tiers.forEach((tier) => {
    let rowTotal = 0;
    channels.forEach((ch) => {
      const live = occdReadVal(`[data-outlet-tier="${tier}"][data-outlet-ch="${ch}"]`);
      const n = live !== '' ? occdNum(live) : occdNum(vals[tier]?.[ch]);
      if (vals[tier]) vals[tier][ch] = String(n || live || vals[tier]?.[ch] || '');
      rowTotal += n;
      colTotals[ch] += n;
    });
    rowTotals[tier] = rowTotal;
    grand += rowTotal;
  });

  tiers.forEach((tier) => {
    const tr = document.querySelector(`tr[data-outlet-tier="${tier}"]`);
    if (!tr) return;
    const tCell = tr.querySelector('.occd-outlet-row-total');
    const pCell = tr.querySelector('.occd-outlet-row-pct');
    if (tCell) tCell.textContent = occdFmt(rowTotals[tier]);
    if (pCell) pCell.textContent = grand > 0 ? occdPct((rowTotals[tier] / grand) * 100) : '—';
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
      const stored = panel.values?.[sec]?.[cat] || {};
      const cy = (() => {
        const live = occdReadVal(`[data-sales-sec="${sec}"][data-sales-cat="${cat}"][data-f="cy"]`);
        return live !== '' ? occdNum(live) : occdNum(stored.cy);
      })();
      const tg = (() => {
        const live = occdReadVal(`[data-sales-sec="${sec}"][data-sales-cat="${cat}"][data-f="target"]`);
        return live !== '' ? occdNum(live) : occdNum(stored.target);
      })();
      const py = (() => {
        const live = occdReadVal(`[data-sales-sec="${sec}"][data-sales-cat="${cat}"][data-f="py"]`);
        return live !== '' ? occdNum(live) : occdNum(stored.py);
      })();
      if (panel.values?.[sec]?.[cat]) {
        panel.values[sec][cat].cy = String(cy);
        panel.values[sec][cat].target = String(tg);
        panel.values[sec][cat].py = String(py);
      }
      totals.cy += cy;
      totals.target += tg;
      totals.py += py;
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
  document.querySelectorAll('[data-inv-header]').forEach((el) => {
    p.header[el.dataset.invHeader] = occdReadVal(`[data-inv-header="${el.dataset.invHeader}"]`) || el.value || '';
  });
  // Prefer header inputs specifically
  document.querySelectorAll('[data-inv-header]').forEach((el) => {
    const key = el.dataset.invHeader;
    if ('value' in el) p.header[key] = el.value;
    else if (el.dataset.occdVal !== undefined) p.header[key] = el.dataset.occdVal;
  });
  Object.keys(p.values).forEach((key) => {
    ['recommended', 'comments'].forEach((f) => {
      const live = occdReadVal(`[data-inv="${key}"][data-field="${f}"]`);
      if (live !== '' || document.querySelector(`[data-inv="${key}"][data-field="${f}"]`)) {
        p.values[key][f] = live;
      }
    });
  });
  return p;
}

function collectDashboardPayload() {
  const p = JSON.parse(JSON.stringify(occdDashboardData.payload));
  document.querySelectorAll('[data-dash-header]').forEach((el) => {
    const key = el.dataset.dashHeader;
    if ('value' in el) p.header[key] = el.value;
    else if (el.dataset.occdVal !== undefined) p.header[key] = el.dataset.occdVal;
  });
  const od = p.outlet_data;
  (od.tiers || []).forEach((tier) => {
    (od.channels || []).forEach((ch) => {
      const live = occdReadVal(`[data-outlet-tier="${tier}"][data-outlet-ch="${ch}"]`);
      if (document.querySelector(`[data-outlet-tier="${tier}"][data-outlet-ch="${ch}"]`)) {
        od.values[tier][ch] = live;
      }
    });
  });
  const sp = p.sales_performance;
  (sp.sections || []).forEach((sec) => {
    (sp.categories || []).filter((c) => c !== 'total').forEach((cat) => {
      ['cy', 'target', 'py'].forEach((f) => {
        const sel = `[data-sales-sec="${sec}"][data-sales-cat="${cat}"][data-f="${f}"]`;
        if (document.querySelector(sel)) sp.values[sec][cat][f] = occdReadVal(sel);
      });
    });
  });
  ['service_model', 'execution_excellence'].forEach((panelKey) => {
    const panel = p[panelKey];
    const prefix = panelKey === 'service_model' ? 'service' : 'execution';
    (panel.rows || []).forEach((row) => {
      ['mtd', 'mtd_target', 'ytd', 'ytd_target'].forEach((f) => {
        const sel = `[data-metric-prefix="${prefix}"][data-metric-row="${row}"][data-f="${f}"]`;
        if (document.querySelector(sel)) panel.values[row][f] = occdReadVal(sel);
      });
    });
  });
  (p.unforgivable_packs.lines || []).forEach((line) => {
    ['recommended', 'comments'].forEach((f) => {
      const sel = `[data-uf="${line.key}"][data-field="${f}"]`;
      if (document.querySelector(sel)) p.unforgivable_packs.values[line.key][f] = occdReadVal(sel);
    });
  });
  return p;
}

async function saveOccdBoard(type, submit) {
  if (!occdCanEdit()) return;
  if (occdIsBoardLocked(type)) {
    alert('This board is locked. Click Edit to make changes.');
    return;
  }
  const board = type === 'inventory_board' ? occdInventoryData : occdDashboardData;
  const payload = type === 'inventory_board' ? collectInventoryPayload() : collectDashboardPayload();
  try {
    await LapokAPI.post('/api/occd/save_board.php', {
      board_date: occdBoardDate,
      board_type: type,
      payload,
      submit,
      allow_edit: board?.status === 'submitted',
    });
    occdUnlock[type] = false;
    // Reload both boards from server so auto fields + saved figures stay in sync.
    await loadManagerOccdBoards();
    alert(submit
      ? 'Board submitted for ' + LapokAPI.formatDate(occdBoardDate + 'T12:00:00') + '. It is now locked.'
      : 'Draft saved. Board is now locked — click Edit to change.');
  } catch (e) {
    alert(e.message);
  }
}

async function saveAllOccdBoards(submit) {
  if (!occdCanEdit()) return;
  if (occdIsBoardLocked('inventory_board') || occdIsBoardLocked('occd_dashboard')) {
    alert('One or both boards are locked. Click Edit on each board you need to change, then save.');
    return;
  }
  try {
    const invPayload = collectInventoryPayload();
    const dashPayload = collectDashboardPayload();
    await LapokAPI.post('/api/occd/save_board.php', {
      board_date: occdBoardDate,
      board_type: 'inventory_board',
      payload: invPayload,
      submit,
      allow_edit: occdInventoryData?.status === 'submitted',
    });
    await LapokAPI.post('/api/occd/save_board.php', {
      board_date: occdBoardDate,
      board_type: 'occd_dashboard',
      payload: dashPayload,
      submit,
      allow_edit: occdDashboardData?.status === 'submitted',
    });
    occdUnlock = { inventory_board: false, occd_dashboard: false };
    await loadManagerOccdBoards();
    alert(submit
      ? 'Both boards submitted and locked.'
      : 'Both boards saved and locked — click Edit to change.');
  } catch (e) {
    alert(e.message);
  }
}
