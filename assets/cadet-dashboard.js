/**
 * Cadet dashboard — trip status, load summary, report CTA.
 */
(function () {
  function ugx(n) {
    return 'UGX ' + Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 });
  }

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  function statusLabel(status) {
    const map = {
      dispatched: 'Dispatched',
      on_route: 'On route',
      returned: 'Returned',
      pending: 'Not submitted',
      submitted: 'Submitted',
      no_trip: 'No trip',
    };
    return map[status] || status;
  }

  function renderStockTable(groups) {
    const table = document.getElementById('cadetDashStockTable');
    if (!table) return;
    const rows = [];
    (groups || []).forEach((group) => {
      (group.products || []).forEach((p) => {
        if ((p.qty_loaded || 0) <= 0) return;
        rows.push(`<tr><td>${esc(group.category)}</td><td>${esc(p.label)}</td><td>${p.qty_loaded}</td></tr>`);
      });
    });
    table.innerHTML = '<tr><th>Group</th><th>Product</th><th>Loaded</th></tr>' +
      (rows.length ? rows.join('') : '<tr><td colspan="3" style="color:var(--gray-mid)">Nothing loaded yet — check with manager.</td></tr>');
  }

  async function loadCadetDashboardPage() {
    const late = document.getElementById('cadetDashLateBanner');
    const noTrip = document.getElementById('cadetDashNoTrip');
    const submitted = document.getElementById('cadetDashSubmitted');
    const ackBanner = document.getElementById('cadetDashAckBanner');
    if (late) late.style.display = 'none';
    if (noTrip) noTrip.style.display = 'none';
    if (submitted) submitted.style.display = 'none';
    if (ackBanner) ackBanner.style.display = 'none';

    try {
      const data = await LapokAPI.get('/api/cadet/fetch_context.php');
      const trip = data.trip;
      const summary = data.summary || {};
      const groups = data.product_groups || [];

      const icon = document.getElementById('cadetDashVehicleIcon');
      if (icon) icon.textContent = trip?.vehicle_type === 'truck' ? '🚛' : '🛺';
      const title = document.getElementById('cadetDashVehicleTitle');
      if (title) title.textContent = trip ? `${trip.registration} — ${trip.route_name || 'Route'}` : 'No vehicle assigned';
      const detail = document.getElementById('cadetDashVehicleDetail');
      if (detail) {
        detail.textContent = trip
          ? `Trip #${trip.id} · ${statusLabel(trip.status)}`
          : 'Waiting for manager dispatch';
      }
      const badge = document.getElementById('cadetDashStatusBadge');
      if (badge) {
        const rs = summary.report_status || 'no_trip';
        const cls = rs === 'submitted' ? 'bs' : rs === 'pending' ? 'bw' : 'bg';
        badge.innerHTML = `<span class="badge ${cls}">${statusLabel(rs)}</span>`;
      }

      if (ackBanner && trip?.status === 'dispatched') {
        ackBanner.style.display = 'flex';
      }

      document.getElementById('cadetDashLoaded').textContent = String(summary.total_loaded ?? 0);
      document.getElementById('cadetDashProducts').textContent = String(summary.product_lines ?? 0);

      const reportStatus = document.getElementById('cadetDashReportStatus');
      const reportSub = document.getElementById('cadetDashReportSub');
      const reportBtn = document.getElementById('cadetDashReportBtn');
      if (summary.report_status === 'submitted') {
        if (reportStatus) reportStatus.textContent = 'Done';
        if (reportSub) reportSub.textContent = 'Sent to RDC';
        if (reportBtn) reportBtn.textContent = 'View submitted report (read-only) →';
        const flags = summary.flags || [];
        if (submitted) {
          submitted.style.display = 'flex';
          const flagText = flags.length ? ` Flagged: ${flags.join(', ')}.` : ' Consolidated into RDC balancing.';
          submitted.innerHTML = `<span>✓</span><div><strong>Today's report submitted</strong><div style="font-size:13px;margin-top:4px">Sales ${ugx(summary.sales_total)} · Cash ${ugx(summary.cash_handed)}.${flagText}</div><button class="btn btn-sm" type="button" style="margin-top:8px" onclick="showPage('cadet-daily')">Open read-only report</button></div>`;
        }
      } else if (trip) {
        if (reportStatus) reportStatus.textContent = 'Due';
        if (reportSub) reportSub.textContent = 'Submit before 7:00 PM';
        if (reportBtn) reportBtn.textContent = "Today's report →";
      } else {
        if (reportStatus) reportStatus.textContent = '—';
        if (reportSub) reportSub.textContent = 'No trip';
        if (noTrip) noTrip.style.display = 'flex';
        if (reportBtn) reportBtn.textContent = "Today's report →";
      }

      const salesCash = document.getElementById('cadetDashSalesCash');
      const salesSub = document.getElementById('cadetDashSalesSub');
      if (summary.report_status === 'submitted') {
        if (salesCash) salesCash.textContent = ugx(summary.sales_total);
        if (salesSub) salesSub.textContent = `Cash ${ugx(summary.cash_handed)}`;
      } else {
        if (salesCash) salesCash.textContent = '—';
        if (salesSub) salesSub.textContent = 'Enter in today\'s report';
      }

      if (summary.past_cutoff && summary.report_status !== 'submitted' && late) {
        late.style.display = 'flex';
      }

      renderStockTable(groups);
      renderCadetMonthlyTargets(data.monthly_targets);
      if (typeof refreshNotifications === 'function') refreshNotifications();
    } catch (e) {
      const title = document.getElementById('cadetDashVehicleTitle');
      if (title) title.textContent = 'Could not load dashboard';
      const detail = document.getElementById('cadetDashVehicleDetail');
      if (detail) detail.textContent = e.message;
    }
  }

  async function confirmDispatchReceive(btn) {
    if (!btn) return;
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Confirming…';
    try {
      const res = await LapokAPI.post('/api/trips/confirm_receive.php', {});
      await loadCadetDashboardPage();
      if (typeof refreshNotifications === 'function') refreshNotifications();
      if (typeof adminToast === 'function') adminToast(res.message || 'Load confirmed. On route.');
    } catch (e) {
      btn.disabled = false;
      btn.textContent = original;
      if (typeof adminToast === 'function') adminToast(e.message, true);
      else alert(e.message);
    }
  }

  function renderCadetMonthlyTargets(t) {
    const body = document.getElementById('cadetTargetsBody');
    const monthEl = document.getElementById('cadetTargetsMonth');
    if (!body) return;
    if (!t || (!t.has_targets && !Number(t.soda_units || 0) && !Number(t.water_units || 0))) {
      body.innerHTML = '<div class="alert a-info" style="margin:0"><span>ℹ</span><div>No monthly targets set for your vehicle yet — the manager enters them on the Monthly targets page. Check back soon.</div></div>';
      return;
    }
    if (monthEl) monthEl.textContent = String(t.month || '').replace(/^(\d{4})-(\d{2})$/, (m, y, mo) => new Date(+y, +mo - 1, 1).toLocaleDateString('en-UG', { month: 'short', year: '2-digit' }));
    const pct = (a, tg) => (Number(tg) > 0 ? Math.round((Number(a) / Number(tg)) * 100) : 0);
    const row = (label, target, sold, p) => {
      const ok = p >= 100;
      return `<div style="flex:1;min-width:180px;padding:12px 14px;border:1px solid ${ok ? 'rgba(22,163,74,.4)' : 'var(--gray-light)'};border-radius:10px;background:${ok ? 'rgba(22,163,74,.06)' : 'rgba(0,0,0,.02)'}">
        <div style="font-size:11px;color:var(--gray-mid);text-transform:uppercase;letter-spacing:.4px">${label}</div>
        <div style="font-size:20px;font-weight:700;color:var(--black)">${Number(sold || 0).toLocaleString('en-UG')} <span style="font-size:12px;color:var(--gray-mid);font-weight:500">${Number(target || 0) > 0 ? `/ ${Number(target || 0).toLocaleString('en-UG')}` : 'sold'}</span></div>
        <div style="font-size:12px;margin-top:4px">${Number(target || 0) > 0 ? `<span class="badge ${ok ? 'bs' : 'bw'}">${p}%</span> <span style="color:var(--gray-mid)">${ok ? '✓ meeting target' : 'not yet'}</span>` : '<span class="badge bg">target not set yet</span>'}</div>
      </div>`;
    };
    body.innerHTML = `<div style="display:flex;gap:10px;flex-wrap:wrap">
      ${row('SODA', t.soda_target, t.soda_units, pct(t.soda_units, t.soda_target))}
      ${row('WATER', t.water_target, t.water_units, pct(t.water_units, t.water_target))}
    </div>`;
  }

  window.loadCadetDashboardPage = loadCadetDashboardPage;
  window.confirmDispatchReceive = confirmDispatchReceive;
})();
