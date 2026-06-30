/**
 * RDC daily balancing — Accountant (Resident Depot Commissioner)
 */
let rdcSheet = null;
let rdcReadOnly = false;
let rdcDirty = false;
let rdcBalanceDate = new Date().toISOString().slice(0, 10);
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
  if (finish) finish.style.display = show ? 'block' : 'none';
  if (main) main.style.display = show ? 'none' : 'block';
  if (sticky && show) sticky.style.display = 'none';
}

function rdcMaybeFinishPanel() {
  if (!rdcSheet) return;
  const s = String(rdcSheet.status || 'draft');
  rdcShowFinishToday(rdcReadOnly && ['submitted', 'under_review', 'approved'].includes(s));
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
  openRdcSheetDate(new Date().toISOString().slice(0, 10));
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
  return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
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
  if (rdcReadOnly) {
    rdcSetWizardStep(3);
    return;
  }
  if (rdcWizardStep === 1) {
    if (!rdcHasSalesData()) {
      rdcNotify('Enter sales quantities, or tap Sample data / Import sales.', true);
      return;
    }
    rdcAutoExpected();
    rdcSetWizardStep(2);
    return;
  }
  if (rdcWizardStep === 2) {
    rdcAutoExpected();
    if (!rdcHasCashData()) {
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
  const title = document.getElementById('rdcWizardTitle');
  const sub = document.getElementById('rdcWizardSub');
  const step = rdcWizardStep;

  if (sticky) sticky.style.display = rdcReadOnly ? 'none' : 'flex';
  if (actions) actions.style.display = rdcReadOnly ? 'none' : 'flex';
  if (backBtn) backBtn.style.display = step > 1 && !rdcReadOnly ? 'inline-flex' : 'none';

  if (sampleBtn) sampleBtn.style.display = step === 1 && !rdcReadOnly ? 'inline-flex' : 'none';
  if (importBtn) importBtn.style.display = step === 1 && !rdcReadOnly ? 'inline-flex' : 'none';
  if (nextBtn) {
    nextBtn.style.display = step < 3 && !rdcReadOnly ? 'inline-flex' : 'none';
    nextBtn.textContent = step === 1 ? 'Next — expenses & cash →' : 'Next — review →';
    nextBtn.className = 'btn btn-sm btn-red';
  }
  if (submitBtn) submitBtn.style.display = step === 3 && !rdcReadOnly ? 'inline-flex' : 'none';

  const titles = {
    1: ['Step 1 of 3 — Sales', 'Enter quantities, or use Sample data / Import sales.'],
    2: ['Step 2 of 3 — Expenses & cash', 'Record expenses, then enter cash actually on hand.'],
    3: ['Step 3 of 3 — Review & submit', 'Check totals, add a note if needed, then submit.'],
  };
  if (title) title.textContent = titles[step][0];
  if (sub) sub.textContent = titles[step][1];

  if (hint && rdcSheet) {
    const v = Number(rdcSheet.variance || 0);
    if (step === 3 && v !== 0) hint.textContent = 'Variance ' + rdcFmt(v) + ' — explain in notes before submit';
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
      const qtyCells = cols.map((c) => {
        const v = line.qty?.[c.key] ?? 0;
        return `<td><input class="qty-inp rdc-qty" type="number" min="0" step="1" value="${v}" data-section="sales" data-li="${li}" data-key="${c.key}" ${rdcDisabled()}></td>`;
      }).join('');
      const totalQ = rdcLineTotalQty(line.qty);
      const amt = rdcLineAmount(line);
      html += `<tr>
        <td><input class="input" style="min-height:32px;padding:4px 6px;font-size:11px" value="${line.label || ''}" data-section="sales-label" data-li="${li}" ${rdcDisabled()}></td>
        ${qtyCells}
        <td class="rdc-calc">${totalQ}</td>
        <td><input class="qty-inp" type="number" min="0" value="${line.price || 0}" data-section="sales-price" data-li="${li}" ${rdcDisabled()}></td>
        <td class="rdc-calc">${rdcFmt(amt)}</td>
      </tr>`;
    });
  });
  Object.keys(groups).forEach((cat) => {
    if (seen.has(cat)) return;
    html += `<tr class="rdc-cat-row"><td colspan="${colSpan}">${cat}</td></tr>`;
    groups[cat].forEach(({ line, li }) => {
      const qtyCells = cols.map((c) => {
        const v = line.qty?.[c.key] ?? 0;
        return `<td><input class="qty-inp rdc-qty" type="number" min="0" step="1" value="${v}" data-section="sales" data-li="${li}" data-key="${c.key}" ${rdcDisabled()}></td>`;
      }).join('');
      const totalQ = rdcLineTotalQty(line.qty);
      const amt = rdcLineAmount(line);
      html += `<tr>
        <td><input class="input" style="min-height:32px;padding:4px 6px;font-size:11px" value="${line.label || ''}" data-section="sales-label" data-li="${li}" ${rdcDisabled()}></td>
        ${qtyCells}
        <td class="rdc-calc">${totalQ}</td>
        <td><input class="qty-inp" type="number" min="0" value="${line.price || 0}" data-section="sales-price" data-li="${li}" ${rdcDisabled()}></td>
        <td class="rdc-calc">${rdcFmt(amt)}</td>
      </tr>`;
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
  el.textContent = parts.join(' · ');
}

function rdcRenderReadOnly() {
  const banner = document.getElementById('rdcReadOnlyBanner');
  const text = document.getElementById('rdcReadOnlyText');
  if (!banner || !text) return;
  if (!rdcReadOnly) {
    banner.style.display = 'none';
    return;
  }
  const s = String(rdcSheet?.status || '');
  banner.style.display = 'flex';
  if (s === 'approved') {
    text.textContent = 'Approved — view only. Ask admin or manager to reopen if a correction is needed.';
  } else if (s === 'submitted' || s === 'under_review') {
    text.textContent = 'Submitted to manager — view only until reopened.';
  } else if (s === 'rejected') {
    text.textContent = 'Rejected — manager will reopen for edits, or contact admin.';
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
  /* delegated on page — see DOMContentLoaded */
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
    rdcNotify('Sheet not loaded yet — wait a moment and try again.', true);
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

function rdcRenderCadetConsolidationBanner(meta) {
  let el = document.getElementById('rdcCadetConsolidationBanner');
  const root = document.getElementById('page-accountant-rdc');
  if (!root) return;
  if (!el) {
    el = document.createElement('div');
    el.id = 'rdcCadetConsolidationBanner';
    el.className = 'alert a-info';
    root.prepend(el);
  }
  const reports = meta?.reports || [];
  const count = meta?.reports_today || reports.length || 0;
  if (!count) {
    el.style.display = 'none';
    const table = document.getElementById('rdcCadetVehicleTable');
    if (table) table.closest('.card')?.remove();
    return;
  }
  const lines = reports.map((r) =>
    `<strong>${r.registration || 'Vehicle'}</strong> · ${r.cadet_name || 'Cadet'} — sales UGX ${Number(r.sales_total || 0).toLocaleString()}, cash UGX ${Number(r.cash_handed || 0).toLocaleString()}${(r.flags || []).length ? ' · flagged' : ''}`
  ).join('<br>');
  el.style.display = 'flex';
  el.innerHTML = `<span>ℹ</span><div><strong>${count} cadet report${count === 1 ? '' : 's'} by vehicle</strong> — synced into matching vehicle columns (sales, expenses, cash).<div style="font-size:13px;margin-top:6px">${lines}</div>${!rdcReadOnly ? '<button class="btn btn-sm" type="button" style="margin-top:8px" onclick="rdcSyncCadetReports()">Refresh from cadets</button>' : ''}</div>`;

  let card = document.getElementById('rdcCadetVehicleCard');
  if (!card) {
    card = document.createElement('div');
    card.id = 'rdcCadetVehicleCard';
    card.className = 'card';
    card.style.marginBottom = '1rem';
    const salesCard = document.getElementById('rdcSalesCard');
    if (salesCard?.parentNode) salesCard.parentNode.insertBefore(card, salesCard);
    else root.querySelector('#rdcBalancingMain')?.prepend(card);
    card.innerHTML = '<div class="card-header"><span class="card-title">Cadet intake by vehicle</span></div><div class="tbl-wrap"><table id="rdcCadetVehicleTable"><tr><th>Vehicle</th><th>Cadet</th><th>Sales</th><th>Cash</th><th>Status</th></tr></table></div>';
  }
  const table = document.getElementById('rdcCadetVehicleTable');
  if (table) {
    table.innerHTML = '<tr><th>Vehicle</th><th>Cadet</th><th>Sales (UGX)</th><th>Cash (UGX)</th><th>Status</th></tr>' +
      reports.map((r) => `<tr><td>${r.registration || '—'}</td><td>${r.cadet_name || '—'}</td><td>${Number(r.sales_total || 0).toLocaleString()}</td><td>${Number(r.cash_handed || 0).toLocaleString()}</td><td>${(r.flags || []).length ? '<span class="badge bw">Flagged</span>' : '<span class="badge bs">OK</span>'}</td></tr>`).join('');
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
    const data = await LapokAPI.get('/api/rdc/fetch_sheet.php?date=' + encodeURIComponent(rdcBalanceDate));
    rdcSheet = data.sheet;
    rdcReadOnly = !!data.read_only;
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
    rdcSetWizardStep(resume ? rdcInferWizardStep() : rdcInferWizardStep());
    if (rdcReadOnly) {
      [1, 2, 3].forEach((n) => {
        const panel = document.getElementById('rdcWizardPanel' + n);
        if (panel) panel.style.display = n === 3 ? 'block' : 'none';
      });
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
  rdcAutoExpected();
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
      rdcNotify('Daily balancing saved.');
      loadRdcBalancingPage();
    }
    return true;
  } catch (e) {
    rdcNotify(e.message, true);
    return false;
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
      if (confirm('No Lapok orders for this date. Load sample demo data instead?')) {
        rdcFillSampleData();
      }
      return;
    }
    rdcSheet.sales = data.sales;
    rdcDemoMode = false;
    rdcMarkDirty();
    rdcRenderAll();
    rdcNotify('Sales updated from Lapok orders (' + data.order_count + ' groups).');
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
  if (notes) notes.value = 'Sample demo entry — depot training walkthrough.';
  rdcSheet.notes = notes?.value || '';

  rdcMarkDirty();
  rdcRenderAll();
  rdcNotify('Sample data loaded — review each step, then Save.');
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
window.openRdcSheetDate = function (date) {
  if (rdcDirty && date !== rdcBalanceDate && !confirm('You have unsaved changes. Switch date anyway?')) return;
  rdcBalanceDate = date;
  const dateInp = document.getElementById('rdcDate');
  if (dateInp) dateInp.value = date;
  loadRdcBalancingPage();
};
