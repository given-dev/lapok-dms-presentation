/**
 * Lean cadet daily report — depot catalog (grouped), expenses, cash → RDC consolidation.
 */
(function () {
  let cadetCtx = null;

  function ugx(n) {
    return 'UGX ' + Number(n || 0).toLocaleString();
  }

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  function toast(msg, err) {
    if (typeof adminToast === 'function') adminToast(msg, !!err);
  }

  function getSalesTotal() {
    let total = 0;
    document.querySelectorAll('#cadetSalesProductTable tr[data-rdc-key]').forEach((row) => {
      total += Number(row.querySelector('.cadet-line-amount')?.value || 0);
    });
    return total;
  }

  function updateLineAmount(row) {
    const qty = Number(row.querySelector('.cadet-qty-sold')?.value || 0);
    const unitPrice = Number(row.getAttribute('data-unit-price') || 0);
    const amountInp = row.querySelector('.cadet-line-amount');
    if (amountInp && !amountInp.dataset.manual) {
      amountInp.value = String(Math.round(qty * unitPrice));
    }
    const totalEl = document.getElementById('cadetSalesTotalDisplay');
    if (totalEl) totalEl.textContent = ugx(getSalesTotal());
    previewFlags();
  }

  function collectSalesLines() {
    return Array.from(document.querySelectorAll('#cadetSalesProductTable tr[data-rdc-key]')).map((row) => ({
      rdc_key: row.getAttribute('data-rdc-key'),
      qty_sold: Number(row.querySelector('.cadet-qty-sold')?.value || 0),
      qty_loaded: Number(row.getAttribute('data-qty-loaded') || 0),
      amount: Number(row.querySelector('.cadet-line-amount')?.value || 0),
    })).filter((line) => line.qty_sold > 0);
  }

  function previewFlags() {
    const sales = getSalesTotal();
    const cash = Number(document.getElementById('cadetCashHanded')?.value || 0);
    const fuel = Number(document.getElementById('cadetFuelExpense')?.value || 0);
    const other = Number(document.getElementById('cadetOtherExpense')?.value || 0);
    const note = document.getElementById('cadetDailyNote')?.value?.trim() || '';
    const el = document.getElementById('cadetDailyFlagsPreview');
    if (!el) return;
    const flags = [];
    if (sales > 0 && Math.abs(sales - cash) > 5000) flags.push('cash mismatch');
    if (sales > 0 && fuel + other > sales * 0.35) flags.push('high expenses');
    if (sales <= 0) flags.push('sales missing');
    document.querySelectorAll('#cadetSalesProductTable tr[data-rdc-key]').forEach((row) => {
      const sold = Number(row.querySelector('.cadet-qty-sold')?.value || 0);
      const loaded = Number(row.getAttribute('data-qty-loaded') || 0);
      if (loaded > 0 && sold > loaded) flags.push('sold more than loaded');
    });
    const now = new Date();
    if (now.getHours() * 60 + now.getMinutes() > 19 * 60 + 30) flags.push('late submit');
    if (flags.includes('cash mismatch') && !note) flags.push('note required');
    el.textContent = flags.length
      ? 'RDC will be flagged: ' + [...new Set(flags)].join(', ')
      : 'No issues flagged. Sales auto-consolidate into RDC balancing.';
    el.style.color = flags.length ? 'var(--amber)' : 'var(--gray-mid)';
  }

  function savedQtyMap(submittedLines) {
    const map = {};
    (submittedLines || []).forEach((line) => {
      const key = line.rdc_key || line.rdc_label;
      if (key) map[key] = line;
    });
    return map;
  }

  function renderProductGroups(groups, submittedLines, readOnly) {
    const table = document.getElementById('cadetSalesProductTable');
    if (!table) return;
    const saved = savedQtyMap(submittedLines);
    if (!groups.length) {
      table.innerHTML = '<tr><th>Product</th><th>Loaded</th><th>Sold (qty)</th><th>Amount (UGX)</th></tr><tr><td colspan="4" style="color:var(--gray-mid)">Depot catalog unavailable.</td></tr>';
      return;
    }

    let html = '<tr><th>Product</th><th>Loaded</th><th>Sold (qty)</th><th>Amount (UGX)</th></tr>';
    groups.forEach((group) => {
      html += `<tr class="cadet-cat-row"><td colspan="4"><strong>${esc(group.category)}</strong></td></tr>`;
      (group.products || []).forEach((p) => {
        const savedLine = saved[p.rdc_key] || saved[p.label];
        const qty = savedLine ? savedLine.qty_sold : 0;
        const amount = savedLine ? savedLine.amount : 0;
        const dis = readOnly ? 'disabled' : '';
        html += `<tr data-rdc-key="${esc(p.rdc_key)}" data-unit-price="${p.unit_price}" data-qty-loaded="${p.qty_loaded || 0}">
          <td>${esc(p.label)}</td>
          <td>${p.qty_loaded || 0}</td>
          <td><input class="qty-inp cadet-qty-sold" type="number" min="0" max="999" value="${qty}" ${dis} /></td>
          <td><input class="qty-inp cadet-line-amount" type="number" min="0" step="1000" value="${amount}" ${dis} /></td>
        </tr>`;
      });
    });
    table.innerHTML = html;

    if (readOnly) return;
    table.querySelectorAll('.cadet-qty-sold').forEach((inp) => {
      inp.addEventListener('input', () => {
        const row = inp.closest('tr');
        if (!row) return;
        const amountInp = row.querySelector('.cadet-line-amount');
        if (amountInp) delete amountInp.dataset.manual;
        updateLineAmount(row);
      });
    });
    table.querySelectorAll('.cadet-line-amount').forEach((inp) => {
      inp.addEventListener('input', () => {
        inp.dataset.manual = '1';
        const totalEl = document.getElementById('cadetSalesTotalDisplay');
        if (totalEl) totalEl.textContent = ugx(getSalesTotal());
        previewFlags();
      });
    });
    const totalEl = document.getElementById('cadetSalesTotalDisplay');
    if (totalEl) totalEl.textContent = ugx(getSalesTotal());
  }

  function formatSubmittedSummary(report) {
    const lines = report.sales_lines || [];
    if (!lines.length) return `Sales ${ugx(report.sales_total)} · Cash ${ugx(report.cash_handed)}`;
    const parts = lines.slice(0, 4).map((line) => `${line.rdc_label || line.product_name || 'Product'} ×${line.qty_sold}`);
    const more = lines.length > 4 ? ` +${lines.length - 4} more` : '';
    return `${parts.join(', ')}${more} · Total ${ugx(report.sales_total)} · Cash ${ugx(report.cash_handed)}`;
  }

  async function loadCadetDailyPage() {
    const info = document.getElementById('cadetDailyInfo');
    const done = document.getElementById('cadetDailyDone');
    const btn = document.getElementById('cadetDailySubmitBtn');
    if (done) done.style.display = 'none';
    try {
      cadetCtx = await LapokAPI.get('/api/cadet/fetch_context.php');
      const trip = cadetCtx.trip;
      const groups = cadetCtx.product_groups || [];
      const chip = document.getElementById('cadetDailyTripChip');
      if (chip) chip.textContent = trip?.registration || 'No trip';
      const veh = document.getElementById('cadetDailyVehicle');
      if (veh) veh.textContent = trip ? `${trip.registration} · ${trip.route_name || 'Route'}` : 'No active trip';
      if (!trip) {
        renderProductGroups(groups, [], false);
        if (info) {
          info.className = 'alert a-warning';
          info.innerHTML = '<span>⚠</span><div>No active trip. Ask manager to dispatch your vehicle.</div>';
        }
        if (btn) btn.disabled = true;
        return;
      }
      if (trip.status === 'returned' && cadetCtx.submitted_report) {
        const r = cadetCtx.submitted_report;
        renderProductGroups(groups, r.sales_lines || [], true);
        if (info) info.style.display = 'none';
        if (done) {
          done.style.display = 'flex';
          const flags = (r.flags || []).length ? ' Flagged: ' + (r.flags || []).join(', ') + '.' : ' Consolidated into RDC balancing.';
          done.innerHTML = `<span>✓</span><div><strong>Already submitted</strong><div style="font-size:13px;margin-top:4px">${formatSubmittedSummary(r)}.${flags}</div></div>`;
        }
        if (btn) btn.disabled = true;
        return;
      }
      if (info) {
        info.style.display = 'flex';
        info.className = 'alert a-info';
        info.innerHTML = '<span>ℹ</span><div>Enter qty sold for each depot product (grouped like LAPOK book). Loaded = on your vehicle. RDC balancing updates automatically.</div>';
      }
      renderProductGroups(groups, [], false);
      if (btn) btn.disabled = false;
      previewFlags();
    } catch (e) {
      if (info) {
        info.className = 'alert a-warning';
        info.innerHTML = '<span>⚠</span><div>' + e.message + '</div>';
      }
    }
  }

  async function submitCadetDailyReport() {
    const btn = document.getElementById('cadetDailySubmitBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
    try {
      const res = await LapokAPI.post('/api/cadet/submit_report.php', {
        sales_lines: collectSalesLines(),
        fuel_expense: Number(document.getElementById('cadetFuelExpense')?.value || 0),
        other_expense: Number(document.getElementById('cadetOtherExpense')?.value || 0),
        cash_handed: Number(document.getElementById('cadetCashHanded')?.value || 0),
        note: document.getElementById('cadetDailyNote')?.value?.trim() || '',
      });
      toast(res.message || 'Submitted to RDC.');
      await loadCadetDailyPage();
    } catch (e) {
      toast(e.message || 'Submit failed', true);
      if (btn) { btn.disabled = false; btn.textContent = 'Submit to RDC'; }
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    ['cadetFuelExpense', 'cadetOtherExpense', 'cadetCashHanded', 'cadetDailyNote'].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', previewFlags);
    });
  });

  window.loadCadetDailyPage = loadCadetDailyPage;
  window.submitCadetDailyReport = submitCadetDailyReport;
})();
