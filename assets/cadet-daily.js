/**
 * Lean cadet daily report — depot catalog (grouped), expenses, cash → RDC consolidation.
 */
(function () {
  let cadetCtx = null;
  let cadetHistory = [];
  let cadetHistorySelectedDate = '';
  let cadetHistoryOpen = false;

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

  function monthIso(d = new Date()) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    return LapokAPI.formatDate(iso + 'T12:00:00');
  }

  function escAttr(s) {
    return String(s || '').replace(/"/g, '&quot;');
  }

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  function auxiliaryFromDom() {
    return {
      fuel: parseNum(document.getElementById('cadetAuxFuel')?.value),
      lunch: parseNum(document.getElementById('cadetAuxLunch')?.value),
      discount: parseNum(document.getElementById('cadetAuxDiscount')?.value),
      shortage: parseNum(document.getElementById('cadetAuxShortage')?.value),
      repairs: parseNum(document.getElementById('cadetAuxRepairs')?.value),
    };
  }

  function auxiliaryTotal(aux) {
    return Object.values(aux || {}).reduce((sum, n) => sum + Number(n || 0), 0);
  }

  function auxiliaryFromReport(report) {
    const aux = report?.auxiliary;
    if (aux && typeof aux === 'object') {
      return {
        fuel: Number(aux.fuel || 0),
        lunch: Number(aux.lunch || 0),
        discount: Number(aux.discount || 0),
        shortage: Number(aux.shortage || 0),
        repairs: Number(aux.repairs || 0),
      };
    }
    return {
      fuel: Number(report?.fuel_expense || 0),
      lunch: Number(report?.lunch_expense || 0),
      discount: Number(report?.discount || 0),
      shortage: Number(report?.shortage || 0),
      repairs: Number(report?.repairs_expense || report?.other_expense || 0),
    };
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

  function amountForRow(row) {
    const qty = parseNum(row.querySelector('.cadet-qty-sold')?.value);
    const unitPrice = Number(row.getAttribute('data-unit-price') || 0);
    return qty * unitPrice;
  }

  function setAmountValue(inp, amount) {
    if (!inp) return;
    inp.value = digits(Math.round(Number(amount || 0)));
  }

  function updateLineAmount(row) {
    const amountInp = row.querySelector('.cadet-line-amount');
    if (amountInp) setAmountValue(amountInp, amountForRow(row));
    const totalEl = document.getElementById('cadetSalesTotalDisplay');
    if (totalEl) totalEl.textContent = ugx(getSalesTotal());
    previewFlags();
  }

  function collectSalesLines() {
    return Array.from(document.querySelectorAll('#cadetSalesProductTable tr[data-rdc-key]')).map((row) => ({
      rdc_key: row.getAttribute('data-rdc-key'),
      qty_sold: parseNum(row.querySelector('.cadet-qty-sold')?.value),
      qty_loaded: Number(row.getAttribute('data-qty-loaded') || 0),
      amount: amountForRow(row),
    })).filter((line) => line.qty_sold > 0);
  }

  function previewFlags() {
    const sales = getSalesTotal();
    const cash = parseNum(document.getElementById('cadetCashHanded')?.value);
    const aux = auxiliaryFromDom();
    const expenses = auxiliaryTotal(aux);
    const note = document.getElementById('cadetDailyNote')?.value?.trim() || '';
    const el = document.getElementById('cadetDailyFlagsPreview');
    if (!el) return;
    const flags = [];
    if (sales > 0 && Math.abs(sales - cash) > 5000) flags.push('cash mismatch');
    if (sales > 0 && expenses > sales * 0.35) flags.push('high expenses');
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
          <td><input class="qty-inp cadet-line-amount" type="text" inputmode="numeric" value="${digits(amount)}" readonly disabled /></td>
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
        updateLineAmount(row);
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

  function setReportFields(report, readOnly) {
    const aux = auxiliaryFromReport(report);
    const auxIds = [
      ['cadetAuxFuel', aux.fuel],
      ['cadetAuxLunch', aux.lunch],
      ['cadetAuxDiscount', aux.discount],
      ['cadetAuxShortage', aux.shortage],
      ['cadetAuxRepairs', aux.repairs],
    ];
    auxIds.forEach(([id, val]) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.value = String(Number(val || 0));
      el.disabled = !!readOnly;
    });
    const cash = document.getElementById('cadetCashHanded');
    const note = document.getElementById('cadetDailyNote');
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

  function reportFlagText(flags) {
    return (flags || []).length ? flags.join(', ') : 'No flags';
  }

  function renderHistoryCalendar(month, reports) {
    const host = document.getElementById('cadetHistoryCalendar');
    const empty = document.getElementById('cadetHistoryEmpty');
    const summary = document.getElementById('cadetHistorySummary');
    if (!host || !empty || !summary) return;

    const byDate = {};
    (reports || []).forEach((r) => { if (r.date) byDate[r.date] = r; });
    const [year, mon] = month.split('-').map(Number);
    const daysInMonth = new Date(year, mon, 0).getDate();
    const labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const firstDay = new Date(year, mon - 1, 1).getDay();
    const offset = (firstDay + 6) % 7;

    summary.innerHTML = `<span>Reports this month</span><strong>${reports.length}</strong>`;
    empty.style.display = reports.length ? 'none' : 'flex';

    let html = labels.map((label) =>
      `<div style="font-size:11px;color:var(--gray-mid);font-weight:700;text-align:center;padding:4px 0">${label}</div>`
    ).join('');
    for (let i = 0; i < offset; i += 1) {
      html += '<div></div>';
    }
    for (let day = 1; day <= daysInMonth; day += 1) {
      const iso = `${month}-${String(day).padStart(2, '0')}`;
      const entry = byDate[iso];
      const active = cadetHistorySelectedDate === iso;
      const bg = active ? '#fee2e2' : entry ? '#f8fafc' : '#fff';
      const border = active ? '#ef4444' : entry ? 'rgba(15,23,42,.18)' : 'rgba(15,23,42,.08)';
      const marker = entry ? `<div style="font-size:10px;color:${entry.flags?.length ? 'var(--amber)' : 'var(--green)'}">${entry.flags?.length ? 'Flagged' : 'Sent'}</div>` : '';
      html += `<button type="button" onclick="selectCadetHistoryDate('${escAttr(iso)}')" style="min-height:62px;border:1px solid ${border};border-radius:10px;background:${bg};padding:8px 4px;text-align:center;cursor:${entry ? 'pointer' : 'default'}" ${entry ? '' : 'disabled'}>
        <div style="font-weight:700;color:var(--dark)">${day}</div>
        ${marker}
      </button>`;
    }
    host.innerHTML = html;
  }

  function renderHistoryDetail(entry) {
    const detail = document.getElementById('cadetHistoryDetail');
    const table = document.getElementById('cadetHistoryDetailTable');
    if (!detail || !table) return;
    if (!entry) {
      detail.style.display = 'none';
      return;
    }
    detail.style.display = 'block';
    const corrected = entry.corrected_at
      ? `Corrected by ${entry.corrected_by_name || 'RDC'} on ${new Date(entry.corrected_at).toLocaleString('en-UG')}`
      : 'Locked server copy';
    const expenseTotal = auxiliaryTotal(auxiliaryFromReport(entry));
    const aux = auxiliaryFromReport(entry);
    const dateText = `${fmtDate(entry.date)}${entry.returned_at ? ` · ${new Date(entry.returned_at).toLocaleTimeString('en-UG', { hour: '2-digit', minute: '2-digit' })}` : ''}`;
    document.getElementById('cadetHistoryDetailDate').textContent = dateText;
    document.getElementById('cadetHistoryDetailVehicle').textContent = `${entry.vehicle || 'Vehicle'} · ${entry.route || 'Route'}`;
    document.getElementById('cadetHistoryDetailSales').textContent = ugx(entry.sales_total || 0);
    document.getElementById('cadetHistoryDetailCash').textContent = ugx(entry.cash_handed || 0);
    document.getElementById('cadetHistoryDetailExpenses').textContent = ugx(expenseTotal);
    const auxTable = document.getElementById('cadetHistoryAuxTable');
    if (auxTable) {
      const rows = [
        ['Fuel', aux.fuel],
        ['Lunch', aux.lunch],
        ['Discount', aux.discount],
        ['Shortage', aux.shortage],
        ['Repairs', aux.repairs],
      ];
      auxTable.innerHTML = '<tr><th>Item</th><th>Amount (UGX)</th></tr>' + rows.map(([label, amount]) =>
        `<tr><td>${esc(label)}</td><td>${ugx(amount || 0)}</td></tr>`
      ).join('');
    }
    document.getElementById('cadetHistoryDetailStatus').textContent = corrected;
    document.getElementById('cadetHistoryDetailFlags').textContent = reportFlagText(entry.flags);
    document.getElementById('cadetHistoryDetailNote').value = entry.note || '';
    table.innerHTML = '<tr><th>Product</th><th>Sold</th><th>Amount</th></tr>' + ((entry.sales_lines || []).map((line) =>
      `<tr><td>${esc(line.label)}</td><td>${digits(line.qty_sold || 0)}</td><td>${ugx(line.amount || 0)}</td></tr>`
    ).join('') || '<tr><td colspan="3" style="color:var(--gray-mid)">No product lines recorded.</td></tr>');
  }

  function updateHistoryToggleUi() {
    const panel = document.getElementById('cadetHistoryPanel');
    const btn = document.getElementById('cadetHistoryToggleBtn');
    if (panel) panel.style.display = cadetHistoryOpen ? 'block' : 'none';
    if (btn) btn.textContent = cadetHistoryOpen ? 'Hide history calendar' : 'Show history calendar';
  }

  function selectHistoryEntry(date) {
    cadetHistorySelectedDate = date || '';
    const entry = cadetHistory.find((r) => r.date === cadetHistorySelectedDate) || null;
    renderHistoryCalendar(document.getElementById('cadetHistoryMonth')?.value || monthIso(), cadetHistory);
    renderHistoryDetail(entry);
  }

  async function loadCadetHistory() {
    const monthEl = document.getElementById('cadetHistoryMonth');
    if (!monthEl) return;
    const month = monthEl.value || monthIso();
    if (!monthEl.value) monthEl.value = month;
    const data = await LapokAPI.get('/api/cadet/history.php?month=' + encodeURIComponent(month));
    cadetHistory = data.reports || [];
    if (!cadetHistory.some((r) => r.date === cadetHistorySelectedDate)) {
      cadetHistorySelectedDate = cadetHistory[0]?.date || '';
    }
    renderHistoryCalendar(month, cadetHistory);
    renderHistoryDetail(cadetHistory.find((r) => r.date === cadetHistorySelectedDate) || null);
    updateHistoryToggleUi();
  }

  function toggleHistoryPanel() {
    cadetHistoryOpen = !cadetHistoryOpen;
    updateHistoryToggleUi();
  }

  async function loadCadetDailyPage() {
    const info = document.getElementById('cadetDailyInfo');
    const done = document.getElementById('cadetDailyDone');
    const btn = document.getElementById('cadetDailySubmitBtn');
    if (done) done.style.display = 'none';
    setReadOnlyMode(false);
    try {
      const monthEl = document.getElementById('cadetHistoryMonth');
      if (monthEl && !monthEl.value) monthEl.value = monthIso();
      cadetCtx = await LapokAPI.get('/api/cadet/fetch_context.php');
      try {
        await loadCadetHistory();
      } catch (historyErr) {
        const summary = document.getElementById('cadetHistorySummary');
        const empty = document.getElementById('cadetHistoryEmpty');
        if (summary) summary.innerHTML = '<span>Reports this month</span><strong>—</strong>';
        if (empty) {
          empty.style.display = 'flex';
          empty.innerHTML = `<span>⚠</span><div>${historyErr.message || 'Could not load report history.'}</div>`;
        }
      }
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
      const aux = auxiliaryFromDom();
      const res = await LapokAPI.post('/api/cadet/submit_report.php', {
        sales_lines: sales,
        auxiliary: aux,
        fuel_expense: aux.fuel,
        lunch_expense: aux.lunch,
        discount: aux.discount,
        shortage: aux.shortage,
        repairs_expense: aux.repairs,
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
    ['cadetAuxFuel', 'cadetAuxLunch', 'cadetAuxDiscount', 'cadetAuxShortage', 'cadetAuxRepairs', 'cadetCashHanded', 'cadetDailyNote'].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', previewFlags);
    });
    const monthEl = document.getElementById('cadetHistoryMonth');
    if (monthEl) monthEl.addEventListener('change', loadCadetHistory);
    updateHistoryToggleUi();
  });

  window.loadCadetDailyPage = loadCadetDailyPage;
  window.submitCadetDailyReport = submitCadetDailyReport;
  window.selectCadetHistoryDate = selectHistoryEntry;
  window.toggleCadetHistoryPanel = toggleHistoryPanel;
})();
