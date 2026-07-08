/**
 * Director / executive daily P&L brief — revenue, expenses, shortages, 7pm readiness.
 */
(function () {
  function ugx(n) {
    return 'UGX ' + Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 });
  }

  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function readinessLabel(code) {
    const map = {
      on_track: 'On track',
      opening_missing: 'Opening stock missing',
      due: '7pm close due now',
      late: 'Late — past 7:30pm',
    };
    return map[code] || code || '—';
  }

  async function loadDirectorBriefPage() {
    const date = new Date().toISOString().slice(0, 10);
    const alert = document.getElementById('directorBriefAlert');
    try {
      const d = await LapokAPI.get('/api/reports/director_snapshot.php?date=' + encodeURIComponent(date));
      setText('dirBriefDate', date);
      setText('dirRevenue', ugx(d.revenue?.used || 0));
      setText('dirVariableExp', ugx(d.expenses?.variable || 0));
      setText('dirFixedDaily', ugx(d.expenses?.fixed_daily || 0));
      setText('dirTotalExp', ugx(d.expenses?.total || 0));
      setText('dirGrossProfit', ugx(d.profit?.gross || 0));
      setText('dirNetProfit', ugx(d.profit?.net_operating || 0));
      setText('dirExpenseRatio', String(d.profit?.expense_ratio_pct || 0) + '%');
      setText('dirExpenseRatioChip', String(d.profit?.expense_ratio_pct || 0) + '% exp ratio');
      setText('dirCashShortage', ugx(d.shortages?.cash_variance_ugx || 0));
      setText('dirStockShortage', String(d.shortages?.stock_variance_units || 0) + ' units');
      setText('dirRdcVariance', ugx(d.shortages?.rdc_variance_ugx || 0));
      setText('dirOpeningStatus', d.controls?.opening_submitted ? 'Submitted' : 'Missing');
      setText('dirClosingStatus', d.controls?.closing_submitted ? 'Submitted' : 'Pending');
      setText('dirRdcStatus', d.controls?.rdc_status || '—');
      setText('dirReadiness', readinessLabel(d.controls?.readiness));
      setText('dirTripsReturned', String(d.controls?.trips_returned || 0));
      setText('dirTripsOut', String(d.controls?.trips_out || 0));

      const fixedList = document.getElementById('dirFixedBreakdown');
      if (fixedList) {
        const b = d.expenses?.fixed_breakdown || {};
        fixedList.innerHTML = [
          ['Rent', b.rent],
          ['Salaries', b.salaries],
          ['Utilities', b.utilities],
          ['Security', b.security],
          ['Other fixed', b.other],
        ].map(([label, val]) => `<div class="recon-row"><span>${label} (month)</span><strong>${ugx(val)}</strong></div>`).join('');
      }

      if (alert) {
        const late = d.controls?.readiness === 'late' || d.controls?.readiness === 'due';
        const shortage = Number(d.shortages?.total_flag_ugx || 0) > 0;
        if (late || shortage || !d.controls?.closing_submitted) {
          alert.style.display = 'flex';
          alert.className = 'alert a-warning';
          alert.innerHTML = '<span>⚠</span><div>' + [
            late ? '7pm close is behind schedule.' : '',
            shortage ? 'Shortages/variances need review.' : '',
            !d.controls?.closing_submitted ? 'Closing stock snapshot not submitted yet.' : '',
          ].filter(Boolean).join(' ') + '</div>';
        } else {
          alert.style.display = 'flex';
          alert.className = 'alert a-success';
          alert.innerHTML = '<span>✓</span><div>Depot is on track for today\'s director brief.</div>';
        }
      }
    } catch (e) {
      if (alert) {
        alert.style.display = 'flex';
        alert.className = 'alert a-warning';
        alert.innerHTML = '<span>⚠</span><div>Director brief unavailable: ' + e.message + '. Run migration 010_depot_finance.sql if tables are missing.</div>';
      }
    }
  }

  async function loadDirectorBriefWidget() {
    if (!currentUser || !['executive', 'manager', 'accountant'].includes(currentUser.role)) return;
    const box = document.getElementById('directorBriefWidget');
    if (!box) return;
    try {
      const d = await LapokAPI.get('/api/reports/director_snapshot.php');
      box.innerHTML = `
        <div class="recon-row"><span>Revenue</span><strong>${ugx(d.revenue?.used || 0)}</strong></div>
        <div class="recon-row"><span>Expenses (var + fixed/day)</span><strong>${ugx(d.expenses?.total || 0)}</strong></div>
        <div class="recon-row"><span>Net operating</span><strong>${ugx(d.profit?.net_operating || 0)}</strong></div>
        <div class="recon-row"><span>Shortages flagged</span><strong>${ugx(d.shortages?.total_flag_ugx || 0)}</strong></div>
        <div class="recon-row"><span>7pm readiness</span><strong>${readinessLabel(d.controls?.readiness)}</strong></div>
      `;
    } catch (_) {
      box.innerHTML = '<p style="font-size:12px;color:var(--gray-mid)">Director brief loads after migration 010.</p>';
    }
  }

  window.loadDirectorBriefPage = loadDirectorBriefPage;
  window.loadDirectorBriefWidget = loadDirectorBriefWidget;
})();
