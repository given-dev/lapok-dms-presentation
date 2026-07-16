let rdcSheet = null;
let rdcReadOnly = false;
let rdcDirty = false;
let rdcEditorMode = 'accountant'; // accountant | manager | viewer
let rdcViewingSubmitted = false; // accountant viewing own submitted sheet (read-only grids)
function rdcLocalIsoDate(d = new Date()) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}
let rdcBalanceDate = rdcLocalIsoDate();
let rdcWizardStep = 1;
let rdcDemoMode = false;
let rdcAutoSaveTimer = null;

function rdcScheduleAutoSave() {
  if (rdcReadOnly) return;
  if (rdcAutoSaveTimer) clearTimeout(rdcAutoSaveTimer);
  rdcAutoSaveTimer = setTimeout(() => {
    if (rdcDirty && rdcSheet && !rdcReadOnly) rdcSaveSheet(true);
  }, 30000);
}

function rdcRenderDemoBanner() {
  const el = document.getElementById('rdcDemoBanner');
  if (el) el.style.display = rdcDemoMode && !rdcReadOnly ? 'flex' : 'none';
}

function rdcShowFinishToday(show) {
  const finish = document.getElementById('rdcFinishToday');
  const main = document.getElementById('rdcBalancingMain');
  const sticky = document.getElementById('rdcStickyActions');
  if (finish) finish.style.display = show && !rdcViewingSubmitted ? 'block' : 'none';
  // Keep sheet visible when accountant opens read-only view, or when manager is editing
  if (main) {
    const hideMain = show && !rdcViewingSubmitted && rdcEditorMode !== 'manager';
    main.style.display = hideMain ? 'none' : 'block';
  }
  if (sticky) {
    if (rdcEditorMode === 'manager' && !rdcReadOnly) sticky.style.display = 'flex';
    else if (show && !rdcViewingSubmitted) sticky.style.display = 'none';
  }
}

function rdcMaybeFinishPanel() {
  if (!rdcSheet) return;
  const s = String(rdcSheet.status || 'draft');
  const isAccountantSubmitted = rdcEditorMode !== 'manager'
    && rdcReadOnly
    && ['submitted', 'under_review', 'approved'].includes(s);
  rdcShowFinishToday(isAccountantSubmitted);
}

function rdcOpenSubmittedView() {
  rdcViewingSubmitted = true;
  rdcShowFinishToday(true);
  rdcSetWizardStep(1);
  rdcNotify('Submitted sheet &mdash; read only.');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function rdcCloseSubmittedView() {
  rdcViewingSubmitted = false;
  rdcMaybeFinishPanel();
}

function rdcExportTodayCsv() {
  if (typeof LapokAPI !== 'undefined' && LapokAPI.exportRdcSheet) {
    LapokAPI.exportRdcSheet(rdcBalanceDate);
  }
}

function rdcNotify(message, isError) {
  if (typeof adminToast === 'function') adminToast(message, !!isError);
  else if (isError) alert(message);
  else alert(message);
}

function rdcMarkDirty() {
  rdcDirty = true;
  const badge = document.getElementById('rdcUnsavedBadge');
  if (badge) badge.style.display = 'inline-flex';
  rdcScheduleAutoSave();
}

function rdcClearDirty() {
  rdcDirty = false;
  const badge = document.getElementById('rdcUnsavedBadge');
  if (badge) badge.style.display = 'none';
}

function rdcGoToday() {
  openRdcSheetDate(LapokAPI.localIsoDate());
}

function rdcShiftDate(delta) {
  const d = new Date(rdcBalanceDate + 'T12:00:00');
  d.setDate(d.getDate() + delta);
  openRdcSheetDate(d.toISOString().slice(0, 10));
}

function rdcAutoExpected() {
  if (!rdcSheet || rdcReadOnly) return;
  rdcRecalcClientTotals();
  const expected = (rdcSheet.grand_total || 0) - (rdcSheet.expenses_total || 0);
  const exp = document.getElementById('rdcExpected');
  if (exp) exp.value = Math.round(expected);
  rdcRecalcClientTotals();
  rdcMarkDirty();
}

function rdcFmt(n) {
  return Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 });
}

function rdcLineTotalQty(qty) {
  return Object.values(qty || {}).reduce((s, v) => s + (parseFloat(v) || 0), 0);
}

function rdcLineAmount(line) {
  const q = rdcLineTotalQty(line.qty);
  return q * (parseFloat(line.price) || 0);
}

function rdcRowAmountSum(row) {
  return Object.values(row.amounts || {}).reduce((s, v) => s + (parseFloat(v) || 0), 0);
}

function rdcRecalcClientTotals() {
  if (!rdcSheet) return;
  const salesTotal = (rdcSheet.sales || []).reduce((s, l) => s + rdcLineAmount(l), 0);
  const recoveryTotal = (rdcSheet.recoveries || []).reduce((s, r) => s + rdcRowAmountSum(r), 0);
  const expensesTotal = (rdcSheet.expenses || []).reduce((s, r) => s + rdcRowAmountSum(r), 0);
  const grandTotal = salesTotal + recoveryTotal;
  const expected = parseFloat(document.getElementById('rdcExpected')?.value) || (grandTotal - expensesTotal);
  const actual = Object.values(rdcSheet.cash_actual || {}).reduce((s, v) => s + (parseFloat(v) || 0), 0);
  const variance = expected - actual;

  rdcSheet.sales_total = salesTotal;
  rdcSheet.recovery_total = recoveryTotal;
  rdcSheet.expenses_total = expensesTotal;
  rdcSheet.grand_total = grandTotal;
  rdcSheet.expected_amount = expected;
  rdcSheet.actual_total = actual;
  rdcSheet.variance = variance;

  const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = rdcFmt(v); };
  set('rdcTotSales', salesTotal);
  set('rdcTotRecovery', recoveryTotal);
  set('rdcTotGrand', grandTotal);
  set('rdcTotExpenses', expensesTotal);
  set('rdcTotActual', actual);
  const varEl = document.getElementById('rdcTotVariance');
  if (varEl) {
    varEl.textContent = (variance === 0 ? '' : variance > 0 ? '+' : '') + rdcFmt(variance);
    varEl.className = variance === 0 ? 'metric-value surplus' : variance > 0 ? 'metric-value surplus' : 'metric-value deficit';
  }
  const varCard = document.getElementById('rdcVarianceCard');
  if (varCard) {
    varCard.classList.remove('rdc-variance-ok', 'rdc-variance-warn');
    if (variance === 0) varCard.classList.add('rdc-variance-ok');
    else varCard.classList.add('rdc-variance-warn');
  }
}

function rdcHasSalesData() {
  if (!rdcSheet?.sales?.length) return false;
  return rdcSheet.sales.some((line) => rdcLineTotalQty(line.qty) > 0);
}

function rdcHasCashData() {
  if (!rdcSheet?.cash_actual) return false;
  return Object.values(rdcSheet.cash_actual).some((v) => (parseFloat(v) || 0) > 0);
}

function rdcInferWizardStep() {
  if (rdcReadOnly) return 3;
  if (!rdcHasSalesData()) return 1;
  if (!rdcHasCashData()) return 2;
  return 3;
}

function rdcSetWizardStep(step) {
  rdcWizardStep = Math.max(1, Math.min(3, step));
  [1, 2, 3].forEach((n) => {
    const panel = document.getElementById('rdcWizardPanel' + n);
    if (panel) panel.style.display = n === rdcWizardStep ? 'block' : 'none';
  });
  rdcRenderBalSteps();
  rdcRenderWizardChrome();
}

function rdcWizardBack() {
  if (rdcWizardStep > 1) rdcSetWizardStep(rdcWizardStep - 1);
}

function rdcWizardNext() {
  if (rdcReadOnly && rdcEditorMode !== 'manager') {
    // Still allow browsing read-only submitted sheets
    if (rdcViewingSubmitted && rdcWizardStep < 3) {
      rdcSetWizardStep(rdcWizardStep + 1);
      return;
    }
    rdcSetWizardStep(3);
    return;
  }
  if (rdcWizardStep === 1) {
    if (!rdcHasSalesData() && rdcEditorMode !== 'manager') {
      rdcNotify('Enter sales quantities, or tap Sample data / Import sales.', true);
      return;
    }
    if (rdcEditorMode !== 'manager') rdcAutoExpected();
    rdcSetWizardStep(2);
    return;
  }
  if (rdcWizardStep === 2) {
    if (rdcEditorMode !== 'manager') rdcAutoExpected();
    if (!rdcHasCashData() && rdcEditorMode !== 'manager') {
      rdcNotify('Enter actual cash received in the table.', true);
      return;
    }
    rdcSetWizardStep(3);
    return;
  }
}

function rdcRenderWizardChrome() {
  const sticky = document.getElementById('rdcStickyActions');
  const actions = document.getElementById('rdcActions');
  const hint = document.getElementById('rdcStickyHint');
  const backBtn = document.getElementById('rdcWizardBackBtn');
  const sampleBtn = document.getElementById('rdcSampleStickyBtn');
  const importBtn = document.getElementById('rdcImportStickyBtn');
  const nextBtn = document.getElementById('rdcWizardNextBtn');
  const submitBtn = document.getElementById('rdcSubmitBtn');
  const mgrSaveBtn = document.getElementById('rdcMgrSaveBtn');
  const mgrApproveBtn = document.getElementById('rdcMgrApproveBtn');
  const closeViewBtn = document.getElementById('rdcCloseViewBtn');
  const title = document.getElementById('rdcWizardTitle');
  const sub = document.getElementById('rdcWizardSub');
  const step = rdcWizardStep;
  const isMgr = rdcEditorMode === 'manager';
  const canEdit = !rdcReadOnly;

  if (sticky) sticky.style.display = (canEdit || rdcViewingSubmitted || isMgr) ? 'flex' : 'none';
  if (actions) actions.style.display = (canEdit || isMgr || rdcViewingSubmitted) ? 'flex' : 'none';
  if (backBtn) backBtn.style.display = step > 1 ? 'inline-flex' : 'none';

  if (sampleBtn) sampleBtn.style.display = step === 1 && canEdit && !isMgr ? 'inline-flex' : 'none';
  if (importBtn) importBtn.style.display = step === 1 && canEdit && !isMgr ? 'inline-flex' : 'none';
  if (nextBtn) {
    nextBtn.style.display = step < 3 ? 'inline-flex' : 'none';
    nextBtn.textContent = step === 1 ? 'Next &mdash; expenses & cash →' : 'Next &mdash; review →';
    nextBtn.className = 'btn btn-sm btn-red';
  }
  if (submitBtn) submitBtn.style.display = step === 3 && canEdit && !isMgr ? 'inline-flex' : 'none';
  if (mgrSaveBtn) mgrSaveBtn.style.display = isMgr && canEdit ? 'inline-flex' : 'none';
  if (mgrApproveBtn) mgrApproveBtn.style.display = isMgr && canEdit && step === 3 ? 'inline-flex' : 'none';
  if (closeViewBtn) closeViewBtn.style.display = rdcViewingSubmitted ? 'inline-flex' : 'none';

  const saveBtn = document.querySelector('#rdcActions > button.btn-sm:not([id])');
  // Prefer the plain Save button (accountant) via query among action children
  document.querySelectorAll('#rdcActions > button').forEach((btn) => {
    if (btn.getAttribute('onclick') === 'rdcSaveSheet()' && !btn.id) {
      btn.style.display = canEdit && !isMgr ? 'inline-flex' : 'none';
    }
  });

  const titles = isMgr ? {
    1: ['Manager review &mdash; Sales', 'Correct cadet/RDC quantities if needed, then continue.'],
    2: ['Manager review &mdash; Expenses & cash', 'Adjust expenses or cash received before approve.'],
    3: ['Manager review &mdash; Totals', 'Save corrections, then Approve or go back to the review queue.'],
  } : rdcViewingSubmitted ? {
    1: ['Submitted report &mdash; Sales (read only)', 'This is what you sent to the manager.'],
    2: ['Submitted report &mdash; Expenses & cash (read only)', 'Figures locked after submit.'],
    3: ['Submitted report &mdash; Totals (read only)', 'Use Back to closeout if you need to send the pack.'],
  } : {
    1: ['Step 1 of 3 &mdash; Sales', 'Enter quantities, or use Sample data / Import sales.'],
    2: ['Step 2 of 3 &mdash; Expenses & cash', 'Record expenses, then enter cash actually on hand.'],
    3: ['Step 3 of 3 &mdash; Review & submit', 'Check totals, add a note if needed, then submit.'],
  };
  if (title) title.textContent = titles[step][0];
  if (sub) sub.textContent = titles[step][1];

  if (hint && rdcSheet) {
    const v = Number(rdcSheet.variance || 0);
    if (isMgr) hint.textContent = v === 0 ? 'Manager can edit, then Approve' : 'Variance ' + rdcFmt(v) + ' &mdash; correct or note before approve';
    else if (rdcViewingSubmitted) hint.textContent = 'Read-only &mdash; submitted to manager';
    else if (step === 3 && v !== 0) hint.textContent = 'Variance ' + rdcFmt(v) + ' &mdash; explain in notes before submit';
    else if (step === 1) hint.textContent = 'Tip: Sample data fills all steps for training';
    else if (step === 2) hint.textContent = 'Expected cash updates when you save';
    else hint.textContent = 'Submit sends the sheet to your manager';
  }
}

function rdcRenderBalSteps() {
  if (!rdcSheet) return;
  const status = String(rdcSheet.status || 'draft');
  const salesDone = rdcHasSalesData();
  const cashDone = rdcHasCashData();
  const submitDone = ['submitted', 'under_review', 'approved'].includes(status);

  const setStep = (id, done, active) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('done', done);
    el.classList.toggle('active', active && !done);
  };

  if (rdcReadOnly && submitDone) {
    setStep('rdcBalStepSales', true, false);
    setStep('rdcBalStepCash', true, false);
    setStep('rdcBalStepSubmit', true, false);
    return;
  }

  const step = rdcWizardStep;
  setStep('rdcBalStepSales', salesDone || step > 1, step === 1);
  setStep('rdcBalStepCash', cashDone || step > 2, step === 2);
  setStep('rdcBalStepSubmit', submitDone || step === 3, step === 3);
}

function rdcScrollToBalStep(step) {
  const map = { sales: 1, cash: 2, submit: 3 };
  rdcSetWizardStep(map[step] || 1);
}

function rdcBalNextStep() {
  rdcWizardNext();
}

function rdcRenderStickyBar() {
  rdcRenderWizardChrome();
}

function rdcSalesColumns() {
  return (rdcSheet.sales_columns || rdcSheet.columns || []).filter(
    (c) => c.key === 'depot' || String(c.key).startsWith('vehicle_')
  );
}

function rdcRecoveryColumns() {
  return (rdcSheet.recovery_columns || []).length
    ? rdcSheet.recovery_columns
    : (rdcSheet.columns || []).filter((c) => c.key === 'depot' || String(c.key).startsWith('cadet_'));
}

function rdcExpenseColumns() {
  const cols = [{ key: 'depot', label: 'DEPOT' }];
  (rdcSheet.columns || []).forEach((c) => {
    if (String(c.key).startsWith('vehicle_')) cols.push(c);
  });
  cols.push({ key: 'other', label: 'OTHER' });
  return cols;
}

function rdcCashColumns() {
  return (rdcSheet.cash_columns || []).length
    ? rdcSheet.cash_columns
    : (rdcSheet.columns || []).filter(
        (c) => c.key === 'depot' || c.key === 'momo' || c.key === 'cash_at_hand'
          || String(c.key).startsWith('cadet_')
          || String(c.key).startsWith('vehicle_')
      );
}

function rdcDisabled() {
  return rdcReadOnly ? 'disabled' : '';
}

function rdcCanEditUnitPrice() {
  return !rdcReadOnly && typeof currentUser !== 'undefined' && currentUser?.role === 'admin';
}

function rdcPriceCellHtml(line, li) {
  const price = Number(line.price || 0);
  if (rdcCanEditUnitPrice()) {
    return `<td><input class="qty-inp rdc-price-inp" type="number" min="0" step="100" value="${price}" data-section="sales-price" data-li="${li}"></td>`;
  }
  return `<td><span class="rdc-price-locked" title="Unit price &mdash; admin only">${rdcFmt(price)}</span></td>`;
}

function rdcSalesRowHtml(line, li, cols) {
  const qtyCells = cols.map((c) => {
    const v = line.qty?.[c.key] ?? 0;
    return `<td><input class="qty-inp rdc-qty" type="number" min="0" step="1" value="${v}" data-section="sales" data-li="${li}" data-key="${c.key}" ${rdcDisabled()}></td>`;
  }).join('');
  const totalQ = rdcLineTotalQty(line.qty);
  const amt = rdcLineAmount(line);
  return `<tr>
    <td><input class="input" style="min-height:36px;padding:6px 8px;font-size:12px;min-width:110px" value="${line.label || ''}" data-section="sales-label" data-li="${li}" ${rdcDisabled()}></td>
    ${qtyCells}
    <td class="rdc-calc">${totalQ}</td>
    ${rdcPriceCellHtml(line, li)}
    <td class="rdc-calc">${rdcFmt(amt)}</td>
  </tr>`;
}

const RDC_PRODUCT_CATEGORIES = ['CSD', 'ENERGY', 'JUICE', 'VAD', 'WATER', 'OTHER'];

function rdcRenderSalesTable() {
  const el = document.getElementById('rdcSalesBody');
  if (!el || !rdcSheet) return;
  const cols = rdcSalesColumns();
  const colSpan = cols.length + 4;
  const lines = rdcSheet.sales || [];
  const groups = {};
  lines.forEach((line, li) => {
    const cat = line.category || 'OTHER';
    if (!groups[cat]) groups[cat] = [];
    groups[cat].push({ line, li });
  });

  let html = '';
  const order = rdcSheet.product_categories || RDC_PRODUCT_CATEGORIES;
  const seen = new Set();
  order.forEach((cat) => {
    const items = groups[cat];
    if (!items?.length) return;
    seen.add(cat);
    html += `<tr class="rdc-cat-row"><td colspan="${colSpan}">${cat}</td></tr>`;
    items.forEach(({ line, li }) => {
      html += rdcSalesRowHtml(line, li, cols);
    });
  });
  Object.keys(groups).forEach((cat) => {
    if (seen.has(cat)) return;
    html += `<tr class="rdc-cat-row"><td colspan="${colSpan}">${cat}</td></tr>`;
    groups[cat].forEach(({ line, li }) => {
      html += rdcSalesRowHtml(line, li, cols);
    });
  });
  el.innerHTML = html;
}

function rdcRenderAmountSection(bodyId, rows, colFn, section) {
  const el = document.getElementById(bodyId);
  if (!el || !rdcSheet) return;
  const cols = colFn();
  el.innerHTML = (rows || []).map((row, ri) => {
    const cells = cols.map((c) => {
      const v = row.amounts?.[c.key] ?? 0;
      return `<td><input class="qty-inp" type="number" min="0" step="1" value="${v}" data-section="${section}" data-ri="${ri}" data-key="${c.key}" ${rdcDisabled()}></td>`;
    }).join('');
    const tot = rdcRowAmountSum(row);
    return `<tr>
      <td><input class="input" style="min-height:32px;padding:4px 6px;font-size:11px" value="${row.label || ''}" data-section="${section}-label" data-ri="${ri}" ${rdcDisabled()} placeholder="Description"></td>
      ${cells}
      <td class="rdc-calc">${rdcFmt(tot)}</td>
    </tr>`;
  }).join('');
}

function rdcRenderCashActual() {
  const el = document.getElementById('rdcCashActualRow');
  if (!el || !rdcSheet) return;
  const cols = rdcCashColumns();
  el.innerHTML = cols.map((c) => {
    const v = rdcSheet.cash_actual?.[c.key] ?? 0;
    return `<td><input class="qty-inp" type="number" min="0" value="${v}" data-section="cash_actual" data-key="${c.key}" ${rdcDisabled()}></td>`;
  }).join('');
}

function rdcRenderAll() {
  const headSales = document.getElementById('rdcSalesHead');
  if (headSales) {
    const cols = rdcSalesColumns();
    headSales.innerHTML = `<tr><th>Brand</th>${cols.map((c) => `<th>${c.label}</th>`).join('')}<th>Total qty</th><th>Price</th><th>Amount</th></tr>`;
  }
  ['rdcRecoveryHead', 'rdcExpenseHead', 'rdcCashOutHead'].forEach((id, idx) => {
    const fn = [rdcRecoveryColumns, rdcExpenseColumns, rdcRecoveryColumns][idx];
    const h = document.getElementById(id);
    if (h) {
      const cols = fn();
      h.innerHTML = `<tr><th>Description</th>${cols.map((c) => `<th>${c.label}</th>`).join('')}<th>Total</th></tr>`;
    }
  });
  const cashHead = document.getElementById('rdcCashActualHead');
  if (cashHead) {
    cashHead.innerHTML = `<tr><th>Actual cash / banking</th>${rdcCashColumns().map((c) => `<th>${c.label}</th>`).join('')}<th>Total</th></tr>`;
  }

  rdcRenderSalesTable();
  rdcRenderAmountSection('rdcRecoveryBody', rdcSheet.recoveries, rdcRecoveryColumns, 'recovery');
  rdcRenderAmountSection('rdcExpenseBody', rdcSheet.expenses, rdcExpenseColumns, 'expense');
  rdcRenderAmountSection('rdcCashOutBody', rdcSheet.cash_out, rdcRecoveryColumns, 'cash_out');
  rdcRenderCashActual();

  const exp = document.getElementById('rdcExpected');
  if (exp) {
    exp.disabled = !!rdcReadOnly;
    exp.value = Math.round(rdcSheet.expected_amount || 0);
  }
  const notes = document.getElementById('rdcNotes');
  if (notes) {
    notes.disabled = !!rdcReadOnly;
    notes.value = rdcSheet.notes || '';
  }
  rdcRenderMeta();
  rdcRenderReadOnly();
  rdcRenderAddRowButtons();
  const st = document.getElementById('rdcStatusBadge');
  if (st) {
    st.textContent = rdcSheet.status || 'draft';
    const s = String(rdcSheet.status || 'draft');
    const cls = s === 'approved' ? 'bs'
      : s === 'rejected' ? 'bd'
        : s === 'submitted' ? 'bw'
          : s === 'under_review' ? 'bg'
            : s === 'reopened' ? 'bi'
              : 'bw';
    st.className = 'badge ' + cls;
  }
  const reviewBanner = document.getElementById('rdcReviewBanner');
  if (reviewBanner) {
    const status = String(rdcSheet.status || 'draft');
    if (['approved', 'rejected', 'reopened', 'under_review'].includes(status)) {
      const note = rdcSheet.review_note ? ` Note: ${rdcSheet.review_note}` : '';
      const tone = status === 'approved' ? 'a-info' : status === 'rejected' ? 'a-danger' : 'a-warning';
      reviewBanner.className = `alert ${tone}`;
      reviewBanner.style.display = 'flex';
      reviewBanner.innerHTML = `<span>${status === 'approved' ? '✓' : '⚠'}</span><div><strong>Manager review:</strong> ${status.replace('_', ' ')}.${note}</div>`;
    } else {
      reviewBanner.style.display = 'none';
      reviewBanner.innerHTML = '';
    }
  }

  rdcRecalcClientTotals();
  rdcRenderBalSteps();
  rdcRenderStickyBar();
  rdcBindInputs();
  rdcRenderDemoBanner();
  rdcMaybeFinishPanel();
}

function rdcRenderMeta() {
  const el = document.getElementById('rdcMetaLine');
  if (!el || !rdcSheet) return;
  const parts = [];
  const status = String(rdcSheet.status || 'draft');
  parts.push(status.replace('_', ' '));
  if (rdcSheet.submitted_at) {
    parts.push('submitted ' + LapokAPI.formatDate(rdcSheet.submitted_at) + ' ' + LapokAPI.formatTime(rdcSheet.submitted_at));
  }
  if (rdcSheet.reviewed_at) {
    parts.push('reviewed ' + LapokAPI.formatDate(rdcSheet.reviewed_at));
  }
  el.textContent = parts.join(' &middot; ');
}

function rdcRenderReadOnly() {
  const banner = document.getElementById('rdcReadOnlyBanner');
  const text = document.getElementById('rdcReadOnlyText');
  if (!banner || !text) return;
  if (rdcEditorMode === 'manager' && !rdcReadOnly) {
    banner.style.display = 'flex';
    banner.className = 'alert a-warning';
    text.textContent = 'Manager edit mode &mdash; correct mistakes on this received sheet, Save, then Approve.';
    return;
  }
  if (!rdcReadOnly) {
    banner.style.display = 'none';
    return;
  }
  const s = String(rdcSheet?.status || '');
  banner.style.display = 'flex';
  banner.className = 'alert a-info';
  if (s === 'approved') {
    text.textContent = 'Approved &mdash; view only. Ask admin or manager to reopen if a correction is needed.';
  } else if (s === 'submitted' || s === 'under_review') {
    text.textContent = 'Submitted report &mdash; read only. Manager reviews (and may correct) this sheet.';
  } else if (s === 'rejected') {
    text.textContent = 'Rejected &mdash; manager will reopen for edits, or contact admin.';
  } else {
    text.textContent = 'This sheet is read-only.';
  }
}

function rdcRenderAddRowButtons() {
  document.querySelectorAll('#page-accountant-rdc [data-rdc-add-row]').forEach((btn) => {
    const blocked = rdcReadOnly || !rdcSheet;
    btn.style.display = blocked ? 'none' : 'inline-flex';
    btn.disabled = blocked;
  });
}

function rdcBindInputs() {
  /* delegated on page &mdash; see DOMContentLoaded */
}

function rdcOnInput(e) {
  const t = e.target;
  const section = t.dataset.section;
  if (!rdcSheet || rdcReadOnly) return;

  if (section === 'sales') {
    const li = parseInt(t.dataset.li, 10);
    rdcSheet.sales[li].qty[t.dataset.key] = parseFloat(t.value) || 0;
  } else if (section === 'sales-label') {
    rdcSheet.sales[parseInt(t.dataset.li, 10)].label = t.value;
  } else if (section === 'sales-price') {
    if (!rdcCanEditUnitPrice()) return;
    rdcSheet.sales[parseInt(t.dataset.li, 10)].price = parseFloat(t.value) || 0;
  } else if (['recovery', 'expense', 'cash_out'].includes(section)) {
    const ri = parseInt(t.dataset.ri, 10);
    const arr = section === 'recovery' ? rdcSheet.recoveries : section === 'expense' ? rdcSheet.expenses : rdcSheet.cash_out;
    if (!arr[ri].amounts) arr[ri].amounts = {};
    arr[ri].amounts[t.dataset.key] = parseFloat(t.value) || 0;
  } else if (section === 'recovery-label' || section === 'expense-label' || section === 'cash_out-label') {
    const ri = parseInt(t.dataset.ri, 10);
    const arr = section.startsWith('recovery') ? rdcSheet.recoveries : section.startsWith('expense') ? rdcSheet.expenses : rdcSheet.cash_out;
    arr[ri].label = t.value;
  } else if (section === 'cash_actual') {
    if (!rdcSheet.cash_actual) rdcSheet.cash_actual = {};
    rdcSheet.cash_actual[t.dataset.key] = parseFloat(t.value) || 0;
  }
  rdcMarkDirty();
  rdcUpdateRowCalcs(t);
  rdcRecalcClientTotals();
  rdcRenderBalSteps();
  rdcRenderStickyBar();
}

function rdcUpdateRowCalcs(target) {
  if (!rdcSheet || !target?.dataset) return;
  const section = target.dataset.section || '';
  const tr = target.closest('tr');
  if (!tr) return;

  if (section === 'sales' || section === 'sales-label' || section === 'sales-price') {
    const li = parseInt(target.dataset.li, 10);
    const line = rdcSheet.sales[li];
    if (!line) return;
    const calcs = tr.querySelectorAll('.rdc-calc');
    if (calcs[0]) calcs[0].textContent = rdcLineTotalQty(line.qty);
    if (calcs[1]) calcs[1].textContent = rdcFmt(rdcLineAmount(line));
    return;
  }

  if (['recovery', 'expense', 'cash_out'].includes(section) || section.endsWith('-label')) {
    const ri = parseInt(target.dataset.ri, 10);
    let row;
    if (section.startsWith('recovery')) row = rdcSheet.recoveries[ri];
    else if (section.startsWith('expense')) row = rdcSheet.expenses[ri];
    else row = rdcSheet.cash_out[ri];
    const calc = tr.querySelector('.rdc-calc');
    if (calc && row) calc.textContent = rdcFmt(rdcRowAmountSum(row));
  }
}

function rdcNormalizeSheet() {
  if (!rdcSheet) return;
  if (!Array.isArray(rdcSheet.recoveries)) rdcSheet.recoveries = [];
  if (!Array.isArray(rdcSheet.expenses)) rdcSheet.expenses = [];
  if (!Array.isArray(rdcSheet.cash_out)) rdcSheet.cash_out = [];
  if (!rdcSheet.cash_actual || typeof rdcSheet.cash_actual !== 'object') rdcSheet.cash_actual = {};
  if (!Array.isArray(rdcSheet.sales)) rdcSheet.sales = [];
}

function rdcAddRowBodyId(section) {
  if (section === 'recovery') return 'rdcRecoveryBody';
  if (section === 'expense') return 'rdcExpenseBody';
  return 'rdcCashOutBody';
}

function rdcAddRow(section) {
  if (!rdcSheet) {
    rdcNotify('Sheet not loaded yet &mdash; wait a moment and try again.', true);
    return;
  }
  if (rdcReadOnly) {
    rdcNotify('This sheet is read-only. Manager must reopen it before you can add rows.', true);
    return;
  }
  rdcNormalizeSheet();
  if (section === 'recovery') {
    const cols = rdcRecoveryColumns();
    const amounts = {};
    cols.forEach((c) => { amounts[c.key] = 0; });
    rdcSheet.recoveries.push({ label: '', amounts });
  } else if (section === 'expense') {
    const cols = rdcExpenseColumns();
    const amounts = {};
    cols.forEach((c) => { amounts[c.key] = 0; });
    rdcSheet.expenses.push({ label: 'NEW ITEM', amounts });
  } else if (section === 'cash_out') {
    const cols = rdcRecoveryColumns();
    const amounts = {};
    cols.forEach((c) => { amounts[c.key] = 0; });
    rdcSheet.cash_out.push({ label: '', amounts });
  } else {
    rdcNotify('Unknown section: ' + section, true);
    return;
  }
  rdcMarkDirty();
  rdcRenderAll();
  const body = document.getElementById(rdcAddRowBodyId(section));
  const lastRow = body?.lastElementChild;
  if (lastRow) lastRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  rdcNotify('Row added.');
}

let rdcCadetReportsCache = [];
let rdcEditCadetTripId = 0;

function rdcEsc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function rdcRenderCadetConsolidationBanner(meta) {
  const root = document.getElementById('page-accountant-rdc');
  if (!root) return;

  let el = document.getElementById('rdcCadetConsolidationBanner');
  if (!el) {
    el = document.createElement('div');
    el.id = 'rdcCadetConsolidationBanner';
    el.className = 'alert a-info';
    const main = document.getElementById('rdcBalancingMain');
    if (main?.parentNode) main.parentNode.insertBefore(el, main);
    else root.prepend(el);
  }

  const reports = meta?.reports || [];
  rdcCadetReportsCache = reports;
  const count = meta?.reports_today || reports.length || 0;
  const card = document.getElementById('rdcCadetVehicleCard');
  const table = document.getElementById('rdcCadetVehicleTable');

  if (!count) {
    el.style.display = 'none';
    if (card) card.style.display = 'none';
    return;
  }

  const lines = reports.map((r) =>
    `<strong>${rdcEsc(r.registration || 'Vehicle')}</strong> &middot; ${rdcEsc(r.cadet_name || 'Cadet')} &mdash; sales UGX ${Number(r.sales_total || 0).toLocaleString()}, cash UGX ${Number(r.cash_handed || 0).toLocaleString()}${(r.flags || []).length ? ' &middot; flagged' : ''}${r.corrected_at ? ' &middot; corrected' : ''}`
  ).join('<br>');
  el.style.display = 'flex';
  el.innerHTML = `<span>ℹ</span><div><strong>${count} cadet report${count === 1 ? '' : 's'} received</strong> &mdash; click <em>Edit</em> below to fix mistakes before you balance.<div style="font-size:13px;margin-top:6px">${lines}</div>${!rdcReadOnly ? '<button class="btn btn-sm" type="button" style="margin-top:8px" onclick="rdcSyncCadetReports()">Refresh from cadets</button>' : ''}</div>`;

  if (card) card.style.display = 'block';
  if (table) {
    table.innerHTML = `<tr><th>Vehicle</th><th>Cadet</th><th>Sales (UGX)</th><th>Cash (UGX)</th><th>Status</th><th></th></tr>` +
      reports.map((r) => {
        const status = r.corrected_at
          ? '<span class="badge bd">Corrected</span>'
          : ((r.flags || []).length ? '<span class="badge bw">Flagged</span>' : '<span class="badge bs">OK</span>');
        const editBtn = rdcReadOnly
          ? '<span style="color:var(--gray-mid);font-size:12px">Locked</span>'
          : `<button type="button" class="btn btn-sm btn-red" onclick="rdcOpenCadetReportEdit(${Number(r.trip_id)})">Edit</button>`;
        return `<tr>
          <td>${rdcEsc(r.registration || '&mdash;')}</td>
          <td>${rdcEsc(r.cadet_name || '&mdash;')}</td>
          <td>${Number(r.sales_total || 0).toLocaleString()}</td>
          <td>${Number(r.cash_handed || 0).toLocaleString()}</td>
          <td>${status}</td>
          <td>${editBtn}</td>
        </tr>`;
      }).join('');
  }
}

function rdcOpenCadetReportEdit(tripId) {
  if (rdcReadOnly) {
    rdcNotify('Sheet is locked &mdash; reopen before correcting cadet reports.', true);
    return;
  }
  const entry = rdcCadetReportsCache.find((r) => Number(r.trip_id) === Number(tripId));
  if (!entry) {
    rdcNotify('Cadet report not found for this date.', true);
    return;
  }
  rdcEditCadetTripId = Number(tripId);
  const report = entry.report || entry;
  const title = document.getElementById('rdcEditCadetTitle');
  const meta = document.getElementById('rdcEditCadetMeta');
  if (title) title.textContent = `Correct report &mdash; ${entry.registration || 'Vehicle'}`;
  if (meta) {
    meta.textContent = `${entry.cadet_name || 'Cadet'} &middot; trip #${tripId}` +
      (entry.corrected_at ? ` &middot; last corrected by ${entry.corrected_by_name || 'RDC'}` : '');
  }

  const body = document.getElementById('rdcEditCadetSalesBody');
  const lines = Array.isArray(report.sales_lines) ? report.sales_lines.slice() : [];
  // Keep empty editable rows for known sheet products the cadet missed
  const known = new Map(lines.map((l) => [String(l.rdc_key || l.rdc_label), l]));
  (rdcSheet?.sales || []).forEach((s) => {
    const key = String(s.rdc_key || s.key || '');
    const label = String(s.label || '');
    const mapKey = key || label;
    if (!mapKey || known.has(mapKey) || known.has(label)) return;
    known.set(mapKey, {
      rdc_key: key,
      rdc_label: label,
      qty_loaded: 0,
      qty_sold: 0,
      unit_price: Number(s.unit_price || s.price || 0),
      amount: 0,
    });
  });
  const rows = [...known.values()];
  if (!body) return;
  body.innerHTML = rows.length
    ? rows.map((line) => {
      const price = Number(line.unit_price || 0);
      const qty = Number(line.qty_sold || 0);
      const amount = Number(line.amount != null ? line.amount : qty * price);
      return `<tr data-rdc-key="${rdcEsc(line.rdc_key || '')}" data-unit-price="${price}">
        <td>${rdcEsc(line.rdc_label || line.rdc_key || 'Product')}</td>
        <td>${Number(line.qty_loaded || 0)}</td>
        <td><input type="number" class="input qty-inp rdc-edit-qty" min="0" step="1" value="${qty}"></td>
        <td><input type="number" class="input rdc-edit-amount" min="0" step="100" value="${Math.round(amount)}"></td>
      </tr>`;
    }).join('')
    : '<tr><td colspan="4" style="text-align:center;color:var(--gray-mid)">No product lines on this report</td></tr>';

  const fuel = document.getElementById('rdcEditCadetFuel');
  const other = document.getElementById('rdcEditCadetOther');
  const cash = document.getElementById('rdcEditCadetCash');
  const note = document.getElementById('rdcEditCadetNote');
  if (fuel) fuel.value = String(Number(report.fuel_expense || entry.fuel_expense || 0));
  if (other) other.value = String(Number(report.other_expense || entry.other_expense || 0));
  if (cash) cash.value = String(Number(report.cash_handed || entry.cash_handed || 0));
  if (note) note.value = String(report.note || entry.note || '');

  body.querySelectorAll('.rdc-edit-qty').forEach((inp) => {
    inp.addEventListener('input', () => {
      const tr = inp.closest('tr');
      const unit = Number(tr?.getAttribute('data-unit-price') || 0);
      const amt = tr?.querySelector('.rdc-edit-amount');
      if (amt) amt.value = String(Math.round(Number(inp.value || 0) * unit));
      rdcUpdateCadetEditTotals();
    });
  });
  body.querySelectorAll('.rdc-edit-amount').forEach((inp) => {
    inp.addEventListener('input', rdcUpdateCadetEditTotals);
  });
  ['rdcEditCadetFuel', 'rdcEditCadetOther', 'rdcEditCadetCash'].forEach((id) => {
    document.getElementById(id)?.addEventListener('input', rdcUpdateCadetEditTotals);
  });
  rdcUpdateCadetEditTotals();
  openModal('rdcEditCadetReportModal');
}

function rdcUpdateCadetEditTotals() {
  const body = document.getElementById('rdcEditCadetSalesBody');
  let sales = 0;
  body?.querySelectorAll('tr[data-rdc-key]').forEach((tr) => {
    sales += Number(tr.querySelector('.rdc-edit-amount')?.value || 0);
  });
  const fuel = Number(document.getElementById('rdcEditCadetFuel')?.value || 0);
  const other = Number(document.getElementById('rdcEditCadetOther')?.value || 0);
  const cash = Number(document.getElementById('rdcEditCadetCash')?.value || 0);
  const el = document.getElementById('rdcEditCadetTotals');
  if (el) {
    el.textContent = `Sales ${sales.toLocaleString()} &middot; Expenses ${(fuel + other).toLocaleString()} &middot; Cash ${cash.toLocaleString()} &middot; Gap ${(sales - cash).toLocaleString()}`;
  }
}

async function rdcSaveCadetReportEdit() {
  if (!rdcEditCadetTripId || rdcReadOnly) return;
  const body = document.getElementById('rdcEditCadetSalesBody');
  const sales_lines = [];
  body?.querySelectorAll('tr[data-rdc-key]').forEach((tr) => {
    const key = tr.getAttribute('data-rdc-key') || '';
    const qty = Number(tr.querySelector('.rdc-edit-qty')?.value || 0);
    const amount = Number(tr.querySelector('.rdc-edit-amount')?.value || 0);
    if (!key || qty <= 0) return;
    sales_lines.push({
      rdc_key: key,
      qty_sold: qty,
      amount,
    });
  });
  const btn = document.getElementById('rdcEditCadetSaveBtn');
  if (btn) btn.disabled = true;
  try {
    const res = await LapokAPI.post('/api/rdc/update_cadet_report.php', {
      trip_id: rdcEditCadetTripId,
      sales_lines,
      fuel_expense: Number(document.getElementById('rdcEditCadetFuel')?.value || 0),
      other_expense: Number(document.getElementById('rdcEditCadetOther')?.value || 0),
      cash_handed: Number(document.getElementById('rdcEditCadetCash')?.value || 0),
      note: document.getElementById('rdcEditCadetNote')?.value || '',
    });
    if (res.sheet) {
      rdcSheet = res.sheet;
      rdcNormalizeSheet();
      rdcRenderAll();
      rdcRecalcClientTotals();
      rdcClearDirty();
    }
    rdcRenderCadetConsolidationBanner(res.cadet_consolidation || null);
    closeModal('rdcEditCadetReportModal');
    rdcNotify(res.message || 'Cadet report corrected.');
    await loadRdcBalancingPage();
  } catch (e) {
    rdcNotify(e.message || 'Could not save correction', true);
  } finally {
    if (btn) btn.disabled = false;
  }
}

async function rdcSyncCadetReports() {
  if (!rdcBalanceDate) return;
  try {
    const res = await LapokAPI.post('/api/rdc/sync_cadet_reports.php', { balance_date: rdcBalanceDate });
    if (res.sheet) {
      rdcSheet = res.sheet;
      rdcNormalizeSheet();
      rdcRenderAll();
      rdcRecalcClientTotals();
    }
    rdcNotify(res.message || 'Cadet data synced');
    await loadRdcBalancingPage();
  } catch (e) {
    rdcNotify(e.message || 'Sync failed', true);
  }
}

window.rdcSyncCadetReports = rdcSyncCadetReports;
window.rdcOpenCadetReportEdit = rdcOpenCadetReportEdit;
window.rdcSaveCadetReportEdit = rdcSaveCadetReportEdit;

async function loadRdcBalancingPage() {
  const root = document.getElementById('page-accountant-rdc');
  if (!root) return;
  const dateInp = document.getElementById('rdcDate');
  if (dateInp) {
    dateInp.value = rdcBalanceDate;
    if (!dateInp.dataset.bound) {
      dateInp.dataset.bound = '1';
      dateInp.onchange = () => {
        if (rdcDirty && !confirm('You have unsaved changes. Switch date anyway?')) {
          dateInp.value = rdcBalanceDate;
          return;
        }
        rdcBalanceDate = dateInp.value;
        loadRdcBalancingPage();
      };
    }
  }
  root.querySelector('.rdc-load-err')?.remove();
  try {
    const fromManager = sessionStorage.getItem('rdcManagerEdit') === '1';
    if (fromManager) sessionStorage.removeItem('rdcManagerEdit');

    const data = await LapokAPI.get('/api/rdc/fetch_sheet.php?date=' + encodeURIComponent(rdcBalanceDate));
    rdcSheet = data.sheet;
    rdcEditorMode = data.editor_mode || (data.read_only ? 'viewer' : 'accountant');
    if (fromManager && (rdcEditorMode === 'manager' || !data.read_only)) {
      rdcEditorMode = 'manager';
    }
    rdcReadOnly = !!data.read_only;
    rdcViewingSubmitted = false;
    rdcRenderCadetConsolidationBanner(data.cadet_consolidation || null);
    rdcNormalizeSheet();
    if (!rdcSheet.sales_columns) {
      rdcSheet.sales_columns = rdcSalesColumns();
      rdcSheet.recovery_columns = rdcRecoveryColumns();
      rdcSheet.cash_columns = rdcCashColumns();
    }
    rdcClearDirty();
    rdcRenderAll();
    const resume = sessionStorage.getItem('rdcResumeWizard');
    if (resume) sessionStorage.removeItem('rdcResumeWizard');
    rdcSetWizardStep(rdcInferWizardStep());
    // Accountant submitted view starts on finish panel; manager starts on sales for editing
    if (rdcEditorMode === 'manager') {
      rdcSetWizardStep(1);
    }
  } catch (e) {
    const err = document.createElement('div');
    err.className = 'alert a-danger rdc-load-err';
    err.innerHTML = '<span>⚠</span>' + e.message;
    root.prepend(err);
  }
}

async function rdcSaveSheet(silent) {
  if (!rdcSheet || rdcReadOnly) return false;
  if (rdcEditorMode !== 'manager') rdcAutoExpected();
  rdcSheet.notes = document.getElementById('rdcNotes')?.value || '';
  rdcRecalcClientTotals();
  try {
    const data = await LapokAPI.post('/api/rdc/save_sheet.php', {
      balance_date: rdcBalanceDate,
      sales: rdcSheet.sales,
      recoveries: rdcSheet.recoveries,
      expenses: rdcSheet.expenses,
      cash_out: rdcSheet.cash_out,
      cash_actual: rdcSheet.cash_actual,
      expected_amount: rdcSheet.expected_amount,
      notes: rdcSheet.notes,
    });
    rdcSheet = data.sheet;
    rdcClearDirty();
    if (silent) {
      const hint = document.getElementById('rdcAutoSaveHint');
      if (hint) hint.textContent = 'Auto-saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } else {
      rdcNotify(rdcEditorMode === 'manager' ? 'Manager corrections saved.' : 'Daily balancing saved.');
      if (rdcEditorMode !== 'manager') loadRdcBalancingPage();
      else rdcRenderAll();
    }
    return true;
  } catch (e) {
    rdcNotify(e.message, true);
    return false;
  }
}

async function rdcManagerApproveFromSheet() {
  if (rdcEditorMode !== 'manager' || !rdcSheet) return;
  if (rdcDirty) {
    const ok = await rdcSaveSheet(false);
    if (!ok) return;
  }
  if (!confirm('Approve this RDC sheet for ' + rdcBalanceDate + '?')) return;
  try {
    await LapokAPI.post('/api/rdc/review_sheet.php', {
      balance_date: rdcBalanceDate,
      action: 'approve',
      note: '',
    });
    rdcNotify('Sheet approved.');
    if (typeof showPage === 'function') showPage('manager-rdc-review');
    if (typeof loadRdcReviewPage === 'function') loadRdcReviewPage();
  } catch (e) {
    rdcNotify(e.message, true);
  }
}

async function rdcSubmitSheet() {
  if (!rdcSheet || rdcReadOnly) return;
  rdcAutoExpected();
  rdcRecalcClientTotals();
  if (Math.abs(Number(rdcSheet.variance || 0)) > 0) {
    if (!confirm('Cash variance is not zero (' + rdcFmt(rdcSheet.variance) + '). Submit anyway?')) return;
  }
  if (!confirm('Submit this day to the manager? You will not be able to edit unless it is reopened.')) return;
  rdcSheet.notes = document.getElementById('rdcNotes')?.value || '';
  rdcRecalcClientTotals();
  try {
    await LapokAPI.post('/api/rdc/save_sheet.php', {
      balance_date: rdcBalanceDate,
      sales: rdcSheet.sales,
      recoveries: rdcSheet.recoveries,
      expenses: rdcSheet.expenses,
      cash_out: rdcSheet.cash_out,
      cash_actual: rdcSheet.cash_actual,
      expected_amount: rdcSheet.expected_amount,
      notes: rdcSheet.notes,
    });
    await LapokAPI.post('/api/rdc/submit_sheet.php', { balance_date: rdcBalanceDate });
    rdcClearDirty();
    rdcReadOnly = true;
    rdcViewingSubmitted = false;
    rdcEditorMode = 'accountant';
    if (rdcSheet) rdcSheet.status = 'submitted';
    rdcRenderAll();
    rdcNotify('Submitted to manager for review.');
  } catch (e) {
    rdcNotify(e.message, true);
  }
}

async function rdcSuggestSales() {
  if (rdcReadOnly) return;
  try {
    const data = await LapokAPI.get('/api/rdc/suggest_sales.php?date=' + encodeURIComponent(rdcBalanceDate));
    if (data.order_count === 0) {
      if (confirm('No depot orders for this date. Load sample demo data instead?')) {
        rdcFillSampleData();
      }
      return;
    }
    rdcSheet.sales = data.sales;
    rdcDemoMode = false;
    rdcMarkDirty();
    rdcRenderAll();
    rdcNotify('Sales updated from depot orders (' + data.order_count + ' groups).');
    rdcSetWizardStep(2);
  } catch (e) {
    rdcNotify(e.message, true);
  }
}

function rdcFillSampleData() {
  if (!rdcSheet || rdcReadOnly) return;
  rdcDemoMode = true;
  rdcNormalizeSheet();

  const vehicles = rdcSalesColumns().filter((c) => String(c.key).startsWith('vehicle_'));
  const v1 = vehicles[0]?.key || 'depot';
  const v2 = vehicles[1]?.key || v1;
  const qtySets = [
    { depot: 3, a: 15, b: 6 },
    { depot: 0, a: 10, b: 4 },
    { depot: 2, a: 8, b: 3 },
    { depot: 0, a: 5, b: 2 },
  ];

  (rdcSheet.sales || []).forEach((line, i) => {
    const set = qtySets[i] || { depot: 0, a: 0, b: 0 };
    if (!line.qty) line.qty = {};
    line.qty.depot = set.depot;
    line.qty[v1] = set.a;
    if (v2 !== v1) line.qty[v2] = set.b;
  });

  const fuel = (rdcSheet.expenses || []).find((e) => e.label === 'FUEL');
  if (fuel) {
    if (!fuel.amounts) fuel.amounts = {};
    fuel.amounts.depot = 20000;
    fuel.amounts[v1] = 95000;
  }
  const lunch = (rdcSheet.expenses || []).find((e) => e.label === 'LUNCH');
  if (lunch) {
    if (!lunch.amounts) lunch.amounts = {};
    lunch.amounts.depot = 15000;
  }

  if (rdcSheet.recoveries?.[0]) {
    rdcSheet.recoveries[0].label = 'Route collection';
    if (!rdcSheet.recoveries[0].amounts) rdcSheet.recoveries[0].amounts = {};
    const cadetKey = rdcRecoveryColumns().find((c) => String(c.key).startsWith('cadet_'))?.key;
    if (cadetKey) rdcSheet.recoveries[0].amounts[cadetKey] = 45000;
  }

  rdcRecalcClientTotals();
  rdcAutoExpected();
  const expected = Math.round(rdcSheet.expected_amount || rdcSheet.grand_total - rdcSheet.expenses_total || 0);
  if (!rdcSheet.cash_actual) rdcSheet.cash_actual = {};
  rdcSheet.cash_actual.cash_at_hand = Math.round(expected * 0.88);
  if ('momo' in rdcSheet.cash_actual || rdcCashColumns().some((c) => c.key === 'momo')) {
    rdcSheet.cash_actual.momo = Math.round(expected * 0.12);
  }

  const notes = document.getElementById('rdcNotes');
  if (notes) notes.value = 'Sample demo entry &mdash; depot training walkthrough.';
  rdcSheet.notes = notes?.value || '';

  rdcMarkDirty();
  rdcRenderAll();
  rdcNotify('Sample data loaded &mdash; review each step, then Save.');
  rdcSetWizardStep(3);
}

document.addEventListener('DOMContentLoaded', () => {
  const exp = document.getElementById('rdcExpected');
  if (exp) exp.addEventListener('input', () => { rdcRecalcClientTotals(); rdcMarkDirty(); });
  const notes = document.getElementById('rdcNotes');
  if (notes) notes.addEventListener('input', rdcMarkDirty);

  const rdcPage = document.getElementById('page-accountant-rdc');
  if (rdcPage) {
    rdcPage.addEventListener('input', (e) => {
      if (e.target.matches('[data-section]')) rdcOnInput(e);
    });
    rdcPage.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-rdc-add-row]');
      if (!btn) return;
      e.preventDefault();
      rdcAddRow(btn.getAttribute('data-rdc-add-row'));
    });
    document.getElementById('rdcBalStepSales')?.addEventListener('click', () => rdcSetWizardStep(1));
    document.getElementById('rdcBalStepCash')?.addEventListener('click', () => rdcSetWizardStep(2));
    document.getElementById('rdcBalStepSubmit')?.addEventListener('click', () => rdcSetWizardStep(3));
  }
});

window.loadRdcBalancingPage = loadRdcBalancingPage;
window.rdcSaveSheet = rdcSaveSheet;
window.rdcSubmitSheet = rdcSubmitSheet;
window.rdcSuggestSales = rdcSuggestSales;
window.rdcAddRow = rdcAddRow;
window.rdcGoToday = rdcGoToday;
window.rdcShiftDate = rdcShiftDate;
window.rdcAutoExpected = rdcAutoExpected;
window.rdcFillSampleData = rdcFillSampleData;
window.rdcBalNextStep = rdcBalNextStep;
window.rdcWizardNext = rdcWizardNext;
window.rdcWizardBack = rdcWizardBack;
window.rdcScrollToBalStep = rdcScrollToBalStep;
window.rdcExportTodayCsv = rdcExportTodayCsv;
window.rdcOpenSubmittedView = rdcOpenSubmittedView;
window.rdcCloseSubmittedView = rdcCloseSubmittedView;
window.rdcManagerApproveFromSheet = rdcManagerApproveFromSheet;
window.rdcPageBack = function () {
  if (rdcEditorMode === 'manager') {
    if (typeof showPage === 'function') showPage('manager-rdc-review');
    if (typeof loadRdcReviewPage === 'function') loadRdcReviewPage();
    return;
  }
  if (rdcViewingSubmitted) {
    rdcCloseSubmittedView();
    return;
  }
  if (typeof showPage === 'function') showPage('accountant-rdc-hub');
};
window.openRdcSheetDate = function (date) {
  if (rdcDirty && date !== rdcBalanceDate && !confirm('You have unsaved changes. Switch date anyway?')) return;
  rdcBalanceDate = date;
  const dateInp = document.getElementById('rdcDate');
  if (dateInp) dateInp.value = date;
  loadRdcBalancingPage();
};
