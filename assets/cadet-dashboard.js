/**
 * Cadet dashboard — trip status, load summary, report CTA.
 */
(function () {
  function ugx(n) {
    return 'UGX ' + Number(n || 0).toLocaleString();
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
    if (late) late.style.display = 'none';
    if (noTrip) noTrip.style.display = 'none';
    if (submitted) submitted.style.display = 'none';

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

      document.getElementById('cadetDashLoaded').textContent = String(summary.total_loaded ?? 0);
      document.getElementById('cadetDashProducts').textContent = String(summary.product_lines ?? 0);

      const reportStatus = document.getElementById('cadetDashReportStatus');
      const reportSub = document.getElementById('cadetDashReportSub');
      if (summary.report_status === 'submitted') {
        if (reportStatus) reportStatus.textContent = 'Done';
        if (reportSub) reportSub.textContent = 'Sent to RDC';
        const flags = summary.flags || [];
        if (submitted) {
          submitted.style.display = 'flex';
          const flagText = flags.length ? ` Flagged: ${flags.join(', ')}.` : ' Consolidated into RDC balancing.';
          submitted.innerHTML = `<span>✓</span><div><strong>Today's report submitted</strong><div style="font-size:13px;margin-top:4px">Sales ${ugx(summary.sales_total)} · Cash ${ugx(summary.cash_handed)}.${flagText}</div></div>`;
        }
      } else if (trip) {
        if (reportStatus) reportStatus.textContent = 'Due';
        if (reportSub) reportSub.textContent = 'Submit before 7:30 PM';
      } else {
        if (reportStatus) reportStatus.textContent = '—';
        if (reportSub) reportSub.textContent = 'No trip';
        if (noTrip) noTrip.style.display = 'flex';
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
      if (typeof refreshNotifications === 'function') refreshNotifications();
    } catch (e) {
      const title = document.getElementById('cadetDashVehicleTitle');
      if (title) title.textContent = 'Could not load dashboard';
      const detail = document.getElementById('cadetDashVehicleDetail');
      if (detail) detail.textContent = e.message;
    }
  }

  window.loadCadetDashboardPage = loadCadetDashboardPage;
})();
