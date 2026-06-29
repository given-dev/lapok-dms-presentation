/**
 * RDC daily balancing — Accountant (Resident Depot Commissioner)
 */
let rdcSheet = null;
let rdcReadOnly = false;
let rdcBalanceDate = new Date().toISOString().slice(0, 10);

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
    varEl.textContent = rdcFmt(variance);
    varEl.className = variance === 0 ? 'surplus' : variance > 0 ? 'surplus' : 'deficit';
  }
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
        (c) => c.key === 'depot' || c.key === 'momo' || c.key === 'cash_at_hand' || String(c.key).startsWith('cadet_')
      );
}

function rdcDisabled() {
  return rdcReadOnly ? 'disabled' : '';
}

function rdcRenderSalesTable() {
  const el = document.getElementById('rdcSalesBody');
  if (!el || !rdcSheet) return;
  const cols = rdcSalesColumns();
  el.innerHTML = (rdcSheet.sales || []).map((line, li) => {
    const qtyCells = cols.map((c) => {
      const v = line.qty?.[c.key] ?? 0;
      return `<td><input class="qty-inp rdc-qty" type="number" min="0" step="1" value="${v}" data-section="sales" data-li="${li}" data-key="${c.key}" ${rdcDisabled()}></td>`;
    }).join('');
    const totalQ = rdcLineTotalQty(line.qty);
    const amt = rdcLineAmount(line);
    return `<tr>
      <td><input class="input" style="min-height:32px;padding:4px 6px;font-size:11px" value="${line.label || ''}" data-section="sales-label" data-li="${li}" ${rdcDisabled()}></td>
      ${qtyCells}
      <td class="rdc-calc">${totalQ}</td>
      <td><input class="qty-inp" type="number" min="0" value="${line.price || 0}" data-section="sales-price" data-li="${li}" ${rdcDisabled()}></td>
      <td class="rdc-calc">${rdcFmt(amt)}</td>
    </tr>`;
  }).join('');
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
  if (exp) exp.value = Math.round(rdcSheet.expected_amount || 0);
  const notes = document.getElementById('rdcNotes');
  if (notes) notes.value = rdcSheet.notes || '';
  const st = document.getElementById('rdcStatusBadge');
  if (st) {
    st.textContent = rdcSheet.status || 'draft';
    st.className = 'badge ' + (rdcSheet.status === 'submitted' ? 'bs' : 'bw');
  }
  rdcRecalcClientTotals();
  rdcBindInputs();
}

function rdcBindInputs() {
  document.querySelectorAll('#page-accountant-rdc [data-section]').forEach((inp) => {
    inp.oninput = rdcOnInput;
  });
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
  rdcRenderAll();
}

function rdcAddRow(section) {
  if (rdcReadOnly || !rdcSheet) return;
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
  }
  rdcRenderAll();
}

async function loadRdcBalancingPage() {
  const root = document.getElementById('page-accountant-rdc');
  if (!root) return;
  const dateInp = document.getElementById('rdcDate');
  if (dateInp && !dateInp.dataset.bound) {
    dateInp.value = rdcBalanceDate;
    dateInp.dataset.bound = '1';
    dateInp.onchange = () => { rdcBalanceDate = dateInp.value; loadRdcBalancingPage(); };
  }
  try {
    const data = await LapokAPI.get('/api/rdc/fetch_sheet.php?date=' + encodeURIComponent(rdcBalanceDate));
    rdcSheet = data.sheet;
    rdcReadOnly = !!data.read_only;
    if (!rdcSheet.sales_columns) {
      rdcSheet.sales_columns = rdcSalesColumns();
      rdcSheet.recovery_columns = rdcRecoveryColumns();
      rdcSheet.cash_columns = rdcCashColumns();
    }
    rdcRenderAll();
    const actions = document.getElementById('rdcActions');
    if (actions) actions.style.display = rdcReadOnly ? 'none' : 'flex';
  } catch (e) {
    root.querySelector('.rdc-load-err')?.remove();
    const err = document.createElement('div');
    err.className = 'alert a-danger rdc-load-err';
    err.innerHTML = '<span>⚠</span>' + e.message;
    root.prepend(err);
  }
}

async function rdcSaveSheet() {
  if (!rdcSheet || rdcReadOnly) return;
  const exp = document.getElementById('rdcExpected');
  if (exp) rdcSheet.expected_amount = parseFloat(exp.value) || 0;
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
    alert('Daily balancing saved.');
    loadRdcBalancingPage();
  } catch (e) {
    alert(e.message);
  }
}

async function rdcSubmitSheet() {
  if (!confirm('Submit this day to the manager? You will not be able to edit unless admin reopens.')) return;
  if (!rdcSheet || rdcReadOnly) return;
  const exp = document.getElementById('rdcExpected');
  if (exp) rdcSheet.expected_amount = parseFloat(exp.value) || 0;
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
    alert('Submitted to manager.');
    loadRdcBalancingPage();
  } catch (e) {
    alert(e.message);
  }
}

async function rdcSuggestSales() {
  if (rdcReadOnly) return;
  try {
    const data = await LapokAPI.get('/api/rdc/suggest_sales.php?date=' + encodeURIComponent(rdcBalanceDate));
    if (data.order_count === 0) {
      alert('No Lapok orders found for this date — enter sales manually.');
      return;
    }
    rdcSheet.sales = data.sales;
    rdcRenderAll();
    alert('Sales quantities updated from Lapok orders (' + data.order_count + ' groups). Review and save.');
  } catch (e) {
    alert(e.message);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const exp = document.getElementById('rdcExpected');
  if (exp) exp.addEventListener('input', rdcRecalcClientTotals);
});

window.loadRdcBalancingPage = loadRdcBalancingPage;
window.rdcSaveSheet = rdcSaveSheet;
window.rdcSubmitSheet = rdcSubmitSheet;
window.rdcSuggestSales = rdcSuggestSales;
window.rdcAddRow = rdcAddRow;
