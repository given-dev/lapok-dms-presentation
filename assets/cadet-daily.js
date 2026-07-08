/**
 * Lean cadet daily report — depot catalog (grouped), expenses, cash → RDC consolidation.
 */
(function () {
  let cadetCtx = null;

  function digits(n) {
    return Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 });
  }

  function parseNum(v) {
    if (typeof v === 'number') return v;
    const cleaned = String(v || '').replace(/,/g, '').trim();
    if (cleaned === '') return 0;
    const n = Number(cleaned);
    return Number.isFinite(n) ? n : 0;
  }

  function ugx(n) {
    return 'UGX ' + digits(n);
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
      total += parseNum(row.querySelector('.cadet-line-amount')?.value);
    });
    return total;
  }

  function setAmountValue(inp, amount) {
    if (!inp) return;
    inp.value = digits(Math.round(Number(amount || 0)));
  }

  function updateLineAmount(row) {
    const qty = parseNum(row.querySelector('.cadet-qty-sold')?.value);
    const unitPrice = Number(row.getAttribute('data-unit-price') || 0);
    const amountInp = row.querySelector('.cadet-line-amount');
    if (amountInp && !amountInp.dataset.manual) {
      setAmountValue(amountInp, qty * unitPrice);
    }
    const totalEl = document.getElementById('cadetSalesTotalDisplay');
    if (totalEl) totalEl.textContent = ugx(getSalesTotal());
    previewFlags();
  }

  function collectSalesLines() {
    return Array.from(document.querySelectorAll('#cadetSalesProductTable tr[data-rdc-key]')).map((row) => ({
      rdc_key: row.getAttribute('data-rdc-key'),
      qty_sold: parseNum(row.querySelector('.cadet-qty-sold')?.value),
      qty_loaded: Number(row.getAttribute('data-qty-loaded') || 0),
      amount: parseNum(row.querySelector('.cadet-line-amount')?.value),
    })).filter((line) => line.qty_sold > 0);
  }

  function previewFlags() {
    const sales = getSalesTotal();
    const cash = parseNum(document.getElementById('cadetCashHanded')?.value);
    const fuel = parseNum(document.getElementById('cadetFuelExpense')?.value);
    const other = parseNum(document.getElementById('cadetOtherExpense')?.value);
    const note = document.getElementById('cadetDailyNote')?.value?.trim() || '';
    const el = document.getElementById('cadetDailyFlagsPreview');
    if (!el) return;
    const flags = [];
    if (sales > 0 && Math.abs(sales - cash) > 5000) flags.push('cash mismatch');
    if (sales > 0 && fuel + other > sales * 0.35) flags.push('high expenses');
    if (sales <= 0) flags.push('sales missing');
    document.querySelectorAll('#cadetSalesProductTable tr[data-rdc-key]').forEach((row) => {
      const sold = parseNum(row.querySelector('.cadet-qty-sold')?.value);
      const loaded = Number(row.getAttribute('data-qty-loaded') || 0);
      if (loaded > 0 && sold > loaded) flags.push('sold more than loaded');
    });
    const now = new Date();
    if (now.getHours() * 60 + now.getMinutes() > 19 * 60 + 30) flags.push('late submit');
    if (flags.includes('cash mismatch') && !note) flags.push('note required');
    if (sales <= 0 && !note) flags.push('no-sales note required');
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

  function bindAmountInput(inp) {
    inp.addEventListener('focus', () => {
      const n = parseNum(inp.value);
      inp.value = n ? String(n) : '';
    });
    inp.addEventListener('blur', () => {
      setAmountValue(inp, parseNum(inp.value));
    });
    inp.addEventListener('input', () => {
      inp.dataset.manual = '1';
      const totalEl = document.getElementById('cadetSalesTotalDisplay');
      if (totalEl) totalEl.textContent = ugx(getSalesTotal());
      previewFlags();
    });
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
        const qty = savedLine ? Number(savedLine.qty_sold || 0) : 0;
        const amount = savedLine ? Number(savedLine.amount || 0) : 0;
        const dis = readOnly ? 'disabled' : '';
        html += `<tr data-rdc-key="${esc(p.rdc_key)}" data-unit-price="${p.unit_price}" data-qty-loaded="${p.qty_loaded || 0}">
          <td>${esc(p.label)}</td>
          <td>${Number(p.qty_loaded || 0).toLocaleString('en-UG')}</td>
          <td><input class="qty-inp cadet-qty-sold" type="number" min="0" max="9999" inputmode="numeric" value="${qty}" ${dis} /></td>
          <td><input class="qty-inp cadet-line-amount" type="text" inputmode="numeric" value="${digits(amount)}" ${dis} /></td>
        </tr>`;
      });
    });
    table.innerHTML = html;

    if (readOnly) {
      const totalEl = document.getElementById('cadetSalesTotalDisplay');
      if (totalEl) totalEl.textContent = ugx(getSalesTotal());
      return;
    }
    table.querySelectorAll('.cadet-qty-sold').forEach((inp) => {
      inp.addEventListener('input', () => {
        const row = inp.closest('tr');
        if (!row) return;
        const amountInp = row.querySelector('.cadet-line-amount');
        if (amountInp) delete amountInp.dataset.manual;
        updateLineAmount(row);
      });
    });
    table.querySelectorAll('.cadet-line-amount').forEach(bindAmountInput);
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

  function setReportFields(report, readOnly) {
    const fuel = document.getElementById('cadetFuelExpense');
    const other = document.getElementById('cadetOtherExpense');
    const cash = document.getElementById('cadetCashHanded');
    const note = document.getElementById('cadetDailyNote');
    if (fuel) {
      fuel.value = String(Number(report?.fuel_expense || 0));
      fuel.disabled = !!readOnly;
    }
    if (other) {
      other.value = String(Number(report?.other_expense || 0));
      other.disabled = !!readOnly;
    }
    if (cash) {
      cash.value = String(Number(report?.cash_handed || 0));
      cash.disabled = !!readOnly;
    }
    if (note) {
      note.value = String(report?.note || '');
      note.readOnly = !!readOnly;
    }
  }

  function setReadOnlyMode(on) {
    const banner = document.getElementById('cadetDailyReadOnlyBanner');
    const badge = document.getElementById('cadetDailyRoBadge');
    const title = document.getElementById('cadetDailyTitle');
    const btn = document.getElementById('cadetDailySubmitBtn');
    if (banner) banner.style.display = on ? 'flex' : 'none';
    if (badge) badge.style.display = on ? 'inline-flex' : 'none';
    if (title) title.textContent = on ? 'Submitted report' : "Today's report";
    if (btn) {
      btn.style.display = on ? 'none' : 'block';
      btn.disabled = !!on;
      if (!on) btn.textContent = 'Submit to RDC';
    }
  }

  async function loadCadetDailyPage() {
    const info = document.getElementById('cadetDailyInfo');
    const done = document.getElementById('cadetDailyDone');
    const btn = document.getElementById('cadetDailySubmitBtn');
    if (done) done.style.display = 'none';
    setReadOnlyMode(false);
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
        setReportFields(null, false);
        if (info) {
          info.style.display = 'flex';
          info.className = 'alert a-warning';
          info.innerHTML = '<span>⚠</span><div>No active trip. Ask manager to dispatch your vehicle.</div>';
        }
        if (btn) btn.disabled = true;
        return;
      }
      if (trip.status === 'returned' && cadetCtx.submitted_report) {
        const r = cadetCtx.submitted_report;
        renderProductGroups(groups, r.sales_lines || [], true);
        setReportFields(r, true);
        setReadOnlyMode(true);
        if (info) info.style.display = 'none';
        if (done) {
          done.style.display = 'flex';
          const flags = (r.flags || []).length ? ' Flagged: ' + (r.flags || []).join(', ') + '.' : ' Consolidated into RDC balancing.';
          done.innerHTML = `<span>✓</span><div><strong>Already submitted — viewing read-only copy</strong><div style="font-size:13px;margin-top:4px">${formatSubmittedSummary(r)}.${flags}</div></div>`;
        }
        return;
      }
      if (info) {
        info.style.display = 'flex';
        info.className = 'alert a-info';
        info.innerHTML = '<span>ℹ</span><div>Enter qty sold for each depot product (grouped like the sales book). Loaded = on your vehicle. RDC balancing updates automatically.</div>';
      }
      setReportFields(null, false);
      renderProductGroups(groups, [], false);
      if (btn) btn.disabled = false;
      previewFlags();
    } catch (e) {
      if (info) {
        info.style.display = 'flex';
        info.className = 'alert a-warning';
        info.innerHTML = '<span>⚠</span><div>' + e.message + '</div>';
      }
    }
  }

  async function submitCadetDailyReport() {
    const btn = document.getElementById('cadetDailySubmitBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
    try {
      const sales = collectSalesLines();
      const salesTotal = sales.reduce((sum, line) => sum + Number(line.amount || 0), 0);
      const note = document.getElementById('cadetDailyNote')?.value?.trim() || '';
      if (salesTotal <= 0 && !note) {
        throw new Error('Add a short note before submitting zero sales.');
      }
      const res = await LapokAPI.post('/api/cadet/submit_report.php', {
        sales_lines: sales,
        fuel_expense: parseNum(document.getElementById('cadetFuelExpense')?.value),
        other_expense: parseNum(document.getElementById('cadetOtherExpense')?.value),
        cash_handed: parseNum(document.getElementById('cadetCashHanded')?.value),
        note,
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
