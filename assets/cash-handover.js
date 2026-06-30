/**
 * Accountant — field cash handover confirmation
 */
(function () {
  function todayIso() {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  function toast(msg, err) {
    if (typeof adminToast === 'function') adminToast(msg, !!err);
  }

  function setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  }

  function cashVariance(reported, received) {
    return (parseFloat(received) || 0) - (parseFloat(reported) || 0);
  }

  function fmtVariance(v) {
    if (v === 0) return '0';
    return (v > 0 ? '+' : '') + Number(v).toLocaleString();
  }

  function isToday(iso) {
    if (!iso) return false;
    return String(iso).slice(0, 10) === todayIso();
  }

  function bindVarianceInputs() {
    document.querySelectorAll('#cashConfirmTable [data-cash-trip]').forEach((inp) => {
      inp.oninput = () => {
        const tripId = inp.getAttribute('data-cash-trip');
        const reported = parseFloat(inp.getAttribute('data-reported') || '0');
        const v = cashVariance(reported, inp.value);
        const el = document.getElementById('cash-var-' + tripId);
        if (!el) return;
        el.textContent = fmtVariance(v);
        el.className = v === 0 ? 'cash-var ok' : 'cash-var warn';
      };
    });
  }

  function renderPendingTable(pending) {
    const table = document.getElementById('cashConfirmTable');
    if (!table) return;
    const badge = document.getElementById('cashPendingBadge');
    if (badge) badge.textContent = pending.length ? pending.length + ' pending' : 'All clear';

    if (!pending.length) {
      table.innerHTML = `<tr><th>Trip</th><th>Cadet</th><th>Route</th><th>Returned</th><th>Reported</th><th>Received</th><th>Variance</th><th></th></tr>
        <tr><td colspan="8" style="text-align:center;padding:1.5rem;color:var(--gray-mid)">No pending handovers — continue to manager pack.</td></tr>`;
      return;
    }

    const rows = pending.map((t) => {
      const reported = parseFloat(t.cash_reported) || 0;
      const returned = t.returned_at ? LapokAPI.formatTime(t.returned_at) : '—';
      return `<tr>
        <td>#${t.id}</td>
        <td>${t.cadet_name || '—'}<div style="font-size:11px;color:var(--gray-mid)">${t.vehicle_reg || ''}</div></td>
        <td>${t.route_area || '—'}</td>
        <td>${returned}</td>
        <td>${LapokAPI.formatUgx(reported)}</td>
        <td><input class="qty-inp" type="number" min="0" step="1" id="cash-${t.id}" data-cash-trip="${t.id}" data-reported="${reported}" value="${reported}" style="width:110px"></td>
        <td><span class="cash-var ok" id="cash-var-${t.id}">0</span></td>
        <td style="white-space:nowrap">
          <button class="btn btn-sm" type="button" onclick="cashMatchReported(${t.id})">Match</button>
          <button class="btn btn-sm btn-red" type="button" onclick="confirmCash(${t.id})">Confirm</button>
        </td>
      </tr>`;
    }).join('');

    table.innerHTML = '<tr><th>Trip</th><th>Cadet</th><th>Route</th><th>Returned</th><th>Reported</th><th>Received</th><th>Variance</th><th></th></tr>' + rows;
    bindVarianceInputs();
  }

  function renderConfirmedToday(confirmed) {
    const table = document.getElementById('cashConfirmedTable');
    if (!table) return;
    if (!confirmed.length) {
      table.innerHTML = '<tr><th>Trip</th><th>Cadet</th><th>Reported</th><th>Received</th><th>Variance</th></tr><tr><td colspan="5" style="color:var(--gray-mid)">No confirmations yet today.</td></tr>';
      return;
    }
    const rows = confirmed.slice(0, 10).map((t) => {
      const v = Number(t.variance || 0);
      const cls = v === 0 ? 'cash-var ok' : 'cash-var warn';
      return `<tr>
        <td>#${t.id}</td>
        <td>${t.cadet_name || '—'}</td>
        <td>${LapokAPI.formatUgx(t.cash_reported)}</td>
        <td>${LapokAPI.formatUgx(t.cash_collected)}</td>
        <td class="${cls}">${fmtVariance(v)}</td>
      </tr>`;
    }).join('');
    table.innerHTML = '<tr><th>Trip</th><th>Cadet</th><th>Reported</th><th>Received</th><th>Variance</th></tr>' + rows;
  }

  async function loadCashHandoverPage() {
    const page = document.getElementById('page-accountant-cash');
    if (!page) return;

    const errEl = document.getElementById('cashLoadError');
    if (errEl) errEl.style.display = 'none';

    try {
      const d = await LapokAPI.get('/api/trips/pending_cash.php');
      const trips = d.trips || [];
      const pending = trips.filter((t) => t.cash_collected === null);
      const confirmedToday = trips.filter((t) => t.cash_collected !== null && isToday(t.returned_at));

      setText('cashPageChip', pending.length
        ? `${pending.length} trip${pending.length === 1 ? '' : 's'} to confirm`
        : 'Cash handover — all clear');

      renderPendingTable(pending);
      renderConfirmedToday(confirmedToday);

      const sticky = document.getElementById('cashStickyHint');
      const nextBtn = document.getElementById('cashNextBtn');
      if (sticky) {
        sticky.textContent = pending.length
          ? 'Confirm each trip — use Match if received equals reported'
          : 'No pending handovers';
      }
      if (nextBtn) {
        nextBtn.textContent = '← Home';
        nextBtn.className = 'btn btn-sm';
        nextBtn.onclick = () => showPage('accountant-rdc-hub');
      }
    } catch (e) {
      if (errEl) {
        errEl.style.display = 'flex';
        errEl.innerHTML = `<span>⚠</span><div>${e.message}</div>`;
      }
      toast(e.message, true);
    }
  }

  function cashMatchReported(tripId) {
    const inp = document.getElementById('cash-' + tripId);
    if (!inp) return;
    inp.value = inp.getAttribute('data-reported') || inp.value;
    inp.dispatchEvent(new Event('input', { bubbles: true }));
  }

  async function confirmCash(tripId) {
    const inp = document.getElementById('cash-' + tripId);
    const amount = parseFloat(inp?.value) || 0;
    const reported = parseFloat(inp?.getAttribute('data-reported') || '0');
    const v = cashVariance(reported, amount);
    if (v !== 0 && !confirm(`Variance is ${fmtVariance(v)} UGX. Confirm anyway?`)) return;
    try {
      const r = await LapokAPI.post('/api/trips/cash_confirm.php', { trip_id: tripId, cash_collected: amount });
      toast(`Trip #${tripId} confirmed. Variance: ${LapokAPI.formatUgx(r.variance)}`);
      loadCashHandoverPage();
    } catch (e) {
      toast(e.message, true);
    }
  }

  window.loadCashHandoverPage = loadCashHandoverPage;
  window.loadPendingCash = loadCashHandoverPage;
  window.confirmCash = confirmCash;
  window.cashMatchReported = cashMatchReported;
})();
