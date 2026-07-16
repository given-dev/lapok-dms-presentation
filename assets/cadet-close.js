/**
 * Cadet 7pm close/checkpoint (manual, no integrations).
 */
(function () {
  let cadetCloseState = { trip: null, load: [], summary: {} };

  function ugx(n) {
    return 'UGX ' + Number(n || 0).toLocaleString();
  }

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  function nowMinutes() {
    const d = new Date();
    return d.getHours() * 60 + d.getMinutes();
  }

  function renderLateBanner() {
    const late = document.getElementById('cadetCloseLate');
    if (!late) return;
    if (nowMinutes() <= (19 * 60 + 30)) {
      late.style.display = 'none';
      return;
    }
    late.style.display = 'flex';
    late.innerHTML = '<span>⚠</span><div>Submitted after 7:30 PM. Add a short late reason before submit.</div>';
  }

  function updateCloseSummary() {
    const rows = Array.from(document.querySelectorAll('#cadetCloseStockTable tr[data-product-id]'));
    let varianceUnits = 0;
    let returnsCount = 0;
    rows.forEach((row) => {
      const v = Number(row.getAttribute('data-variance') || '0');
      varianceUnits += Math.abs(v);
      if (Number(row.querySelector('input')?.value || '0') > 0) returnsCount += 1;
    });
    const expected = Number(cadetCloseState.summary?.confirmed_revenue || 0);
    const reported = Number(document.getElementById('cadetCashReported')?.value || 0);
    const cashVar = reported - expected;
    const c = document.getElementById('cadetCashVariance');
    if (c) c.textContent = ugx(cashVar);
    if (c) c.style.color = cashVar === 0 ? 'var(--gray-dark)' : 'var(--red)';
    const r = document.getElementById('cadetReturnsCount');
    if (r) r.textContent = String(returnsCount);
    const v = document.getElementById('cadetVarianceUnits');
    if (v) v.textContent = String(varianceUnits);
  }

  function setVariance(row, loaded, sold, counted) {
    const expected = loaded - sold;
    const variance = counted - expected;
    row.setAttribute('data-variance', String(variance));
    const el = row.querySelector('[data-variance-cell]');
    if (!el) return;
    el.textContent = variance === 0 ? '0' : (variance > 0 ? '+' + variance : String(variance));
    el.className = variance < 0 ? 'deficit' : 'surplus';
    updateCloseSummary();
  }

  function renderStockRows(load) {
    const table = document.getElementById('cadetCloseStockTable');
    if (!table) return;
    if (!load.length) {
      table.innerHTML = '<tr><th>Product</th><th>Loaded</th><th>Sold</th><th>Counted now</th><th>Variance</th></tr><tr><td colspan="5" style="color:var(--gray-mid)">No trip stock lines found.</td></tr>';
      return;
    }
    const rows = load.map((item) => {
      const loaded = Number(item.qty_loaded || 0);
      const sold = Number(item.qty_sold || 0);
      const expected = loaded - sold;
      return `<tr data-product-id="${item.product_id}" data-loaded="${loaded}" data-sold="${sold}" data-variance="0">
        <td>${esc(item.product_name)}</td>
        <td>${loaded}</td>
        <td>${sold}</td>
        <td><input class="qty-inp cadet-ret-inp" type="number" min="0" value="${expected}" /></td>
        <td data-variance-cell class="surplus">0</td>
      </tr>`;
    }).join('');
    table.innerHTML = '<tr><th>Product</th><th>Loaded</th><th>Sold</th><th>Counted now</th><th>Variance</th></tr>' + rows;
    table.querySelectorAll('.cadet-ret-inp').forEach((inp) => {
      inp.addEventListener('input', () => {
        const row = inp.closest('tr');
        if (!row) return;
        const loaded = Number(row.getAttribute('data-loaded') || '0');
        const sold = Number(row.getAttribute('data-sold') || '0');
        const counted = Number(inp.value || 0);
        setVariance(row, loaded, sold, counted);
      });
    });
  }

  async function loadCadetClosePage() {
    const info = document.getElementById('cadetCloseInfo');
    renderLateBanner();
    try {
      const d = await LapokAPI.get('/api/dashboard/field_user.php');
      cadetCloseState = d || {};
      const trip = d.trip || null;
      const summary = d.summary || {};

      const vehicle = document.getElementById('cadetTripVehicle');
      if (vehicle) vehicle.textContent = trip?.registration || '&mdash;';
      const route = document.getElementById('cadetTripRoute');
      if (route) route.textContent = trip?.route_name || trip?.route_area || '&mdash;';
      const status = document.getElementById('cadetTripStatus');
      if (status) status.textContent = trip?.status || 'No active trip';
      const sales = document.getElementById('cadetSalesToday');
      if (sales) sales.textContent = ugx(summary.revenue_today || 0);
      const expected = document.getElementById('cadetCashExpected');
      if (expected) expected.textContent = ugx(summary.confirmed_revenue || 0);
      const cashInp = document.getElementById('cadetCashReported');
      if (cashInp && !cashInp.value) cashInp.value = String(Math.round(Number(summary.confirmed_revenue || 0)));
      renderStockRows(d.load || []);
      updateCloseSummary();
      
      const btn = document.getElementById('cadetCloseSubmitBtn');
      if (trip && trip.status === 'returned') {
        if (btn) {
          btn.disabled = true;
          btn.textContent = 'Already submitted today';
        }
        if (info) {
          info.className = 'alert a-info';
          info.innerHTML = '<span>✓</span><div>Your daily report has already been sent.</div>';
        }
      } else {
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'Submit checkpoint';
        }
        if (info) {
          info.className = 'alert a-info';
          info.innerHTML = `<span>ℹ</span><div>Checkpoint for <strong>${trip?.registration || 'today'}</strong>. Submit by 7:00 PM so RDC can close on time.</div>`;
        }
      }
    } catch (e) {
      if (info) {
        info.className = 'alert a-warning';
        info.innerHTML = `<span>⚠</span><div>Could not load checkpoint data: ${esc(e.message)}</div>`;
      }
    }
  }

  async function submitCadetClose() {
    const btn = document.getElementById('cadetCloseSubmitBtn');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Submitting…';
    }
    try {
      const tripId = Number(cadetCloseState.trip?.id || 0);
      if (!tripId) throw new Error('No active trip to close');
      const checkpoint = document.getElementById('cadetCheckpointType')?.value || 'close';
      const lateReason = (document.getElementById('cadetLateReason')?.value || '').trim();
      if (nowMinutes() > (19 * 60 + 30) && !lateReason) {
        throw new Error('Add late submission reason (after 7:30 PM).');
      }
      const returns = Array.from(document.querySelectorAll('#cadetCloseStockTable tr[data-product-id]')).map((row) => ({
        product_id: Number(row.getAttribute('data-product-id') || 0),
        qty_returned: Number(row.querySelector('input')?.value || 0),
      }));
      const payload = {
        trip_id: tripId,
        cash_reported: Number(document.getElementById('cadetCashReported')?.value || 0),
        returns,
        notes: (document.getElementById('cadetVarianceReason')?.value || '').trim(),
        close_meta: {
          checkpoint,
          returns_note: (document.getElementById('cadetReturnsNote')?.value || '').trim(),
          late_reason: lateReason,
          submitted_at: new Date().toISOString(),
        },
      };
      await LapokAPI.post('/api/trips/eod_submit.php', payload);
      if (typeof adminToast === 'function') {
        adminToast(checkpoint === 'checkpoint' ? 'Checkpoint saved.' : '7pm close submitted.');
      }
      await loadCadetClosePage();
    } catch (e) {
      if (typeof adminToast === 'function') adminToast(e.message || 'Submit failed', true);
      else alert(e.message || 'Submit failed');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Submit checkpoint';
      }
    }
  }

  window.loadCadetClosePage = loadCadetClosePage;
  window.submitCadetClose = submitCadetClose;
})();
