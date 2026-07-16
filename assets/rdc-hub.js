/**
 * RDC Home &mdash; accountant daily command desk
 */
(function () {
  const RECEIVABLES_HIGH_UGX = 8000000;

  function todayIso() {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  function fmtTodayLabel() {
    return new Date().toLocaleDateString(undefined, {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    });
  }

  function greeting() {
    const h = new Date().getHours();
    const name = (typeof currentUser !== 'undefined' && currentUser?.full_name)
      ? currentUser.full_name.split(' ')[0]
      : '';
    const sal = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
    return name ? `${sal}, ${name}` : sal;
  }

  function fmtUgx(n) {
    return LapokAPI.formatUgx(n);
  }

  function statusBadge(status) {
    const s = String(status || 'draft');
    const cls = s === 'approved' ? 'bs'
      : s === 'rejected' ? 'bd'
        : s === 'submitted' ? 'bw'
          : s === 'under_review' ? 'bg'
            : s === 'reopened' ? 'bi'
              : 'bw';
    return `<span class="badge ${cls}">${s.replace('_', ' ')}</span>`;
  }

  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function balSubmitted(status) {
    return ['submitted', 'under_review', 'approved'].includes(status);
  }

  function monthProgressLabel(sheets) {
    const list = sheets || [];
    if (!list.length) return 'No sheets submitted this month yet';
    const submitted = list.filter((s) => !['draft'].includes(String(s.status || 'draft'))).length;
    const pendingReview = list.filter((s) => ['submitted', 'under_review', 'reopened'].includes(String(s.status))).length;
    const approved = list.filter((s) => s.status === 'approved').length;
    let label = `${submitted}/${list.length} submitted this month`;
    if (pendingReview > 0) label += ` &middot; ${pendingReview} awaiting review`;
    else if (approved > 0) label += ` &middot; ${approved} approved`;
    return label;
  }

  function workflowState(ctx) {
    const balDone = ctx.sheetLoaded && balSubmitted(ctx.sheetStatus);
    const balActive = ctx.sheetLoaded && ['draft', 'reopened', 'rejected'].includes(ctx.sheetStatus);
    const packDone = ctx.packSentToday;
    const packActive = balDone && !packDone;
    const dayComplete = balDone && packDone;
    const cashPending = ctx.cashPending > 0;
    return { balDone, balActive, packDone, packActive, dayComplete, cashPending };
  }

  function renderGreeting() {
    setText('rdcHubGreeting', greeting());
    setText('rdcHubTodayLabel', fmtTodayLabel());
  }

  function renderProgress(wf) {
    const seg = (id, done, active) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.className = 'rdc-hub-progress-seg' + (done ? ' done' : active ? ' active' : '');
    };
    seg('rdcHubProgBal', wf.balDone, wf.balActive);
    seg('rdcHubProgPack', wf.packDone, wf.packActive);
  }

  function renderStepStates(wf) {
    /* step cards removed &mdash; progress bar only */
  }

  function balChecklistSub(ctx) {
    if (!ctx.sheetLoaded) return 'Could not load &mdash; tap to retry';
    const s = ctx.sheetStatus;
    if (s === 'rejected') return 'Manager rejected &mdash; fix and resubmit';
    if (s === 'reopened') return 'Reopened for correction';
    if (s === 'under_review') return 'Manager reviewing your sheet';
    if (s === 'submitted') return 'Submitted &mdash; awaiting manager review';
    if (s === 'approved') return 'Approved by manager';
    return 'Sales, expenses & cash';
  }

  function isMonthEndWindow() {
    const d = new Date();
    const lastDay = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
    return d.getDate() >= lastDay - 2;
  }

  function checklistItem(num, title, sub, state, page, statusHtml) {
    const cls = state === 'done' ? 'done' : state === 'active' ? 'active' : '';
    const icon = state === 'done' ? '✓' : String(num);
    const attrs = page
      ? `role="button" tabindex="0" onclick="${page === 'accountant-rdc' ? "sessionStorage.setItem('rdcResumeWizard','1');" : ''}showPage('${page}')"`
      : '';
    return `<li class="rdc-hub-check-item ${cls}" ${attrs}>
      <div class="rdc-hub-check-num">${icon}</div>
      <div><div style="font-weight:600;font-size:13px">${title}${statusHtml || ''}</div><div style="font-size:12px;color:var(--gray-mid);margin-top:2px">${sub}</div></div>
    </li>`;
  }

  function renderChecklist(ctx, wf) {
    const root = document.getElementById('rdcHubChecklist');
    if (!root) return;
    const balBadge = ctx.sheetLoaded && ctx.sheetStatus !== 'draft' ? ' ' + statusBadge(ctx.sheetStatus) : '';
    const packSub = wf.packDone ? 'Sent today' : 'One tap to send PDF to manager';
    root.innerHTML = [
      checklistItem(1, 'Daily balancing', balChecklistSub(ctx), wf.balDone ? 'done' : wf.balActive || !ctx.sheetLoaded ? 'active' : '', 'accountant-rdc', balBadge),
      checklistItem(2, 'Manager pack', packSub, wf.packDone ? 'done' : wf.packActive ? 'active' : '', 'report-exchange'),
    ].join('');
  }

  function renderPackView(ctx) {
    const el = document.getElementById('rdcHubPackView');
    if (!el) return;
    if (!ctx.packTodayId) {
      el.style.display = 'none';
      el.innerHTML = '';
      return;
    }
    el.style.display = 'block';
    el.innerHTML = `<li><button class="btn btn-sm" type="button" onclick="reportOpenPdf(${ctx.packTodayId})">View today's manager pack (PDF)</button></li>`;
  }

  function renderCadetNudge(ctx) {
    const box = document.getElementById('rdcHubCadetNudge');
    const title = document.getElementById('rdcHubCadetNudgeTitle');
    const sub = document.getElementById('rdcHubCadetNudgeSub');
    if (!box) return;
    if (ctx.cadetFlagCount) {
      const n = ctx.cadetFlagCount;
      if (title) title.textContent = `${n} cadet report${n === 1 ? '' : 's'} flagged`;
      if (sub) sub.textContent = 'Open Today\'s close → Edit on Cadet reports to fix mistakes.';
      box.style.display = 'flex';
      return;
    }
    if (ctx.cadetReportCount) {
      const n = ctx.cadetReportCount;
      if (title) title.textContent = `${n} cadet report${n === 1 ? '' : 's'} ready to review`;
      if (sub) sub.textContent = 'Open Today\'s close &mdash; Cadet reports received is at the top with Edit.';
      box.style.display = 'flex';
      return;
    }
    box.style.display = 'none';
  }

  function renderDepotNudge(ctx) {
    const box = document.getElementById('rdcHubDepotNudge');
    const title = document.getElementById('rdcHubDepotNudgeTitle');
    const sub = document.getElementById('rdcHubDepotNudgeSub');
    if (!box) return;
    if (!ctx.exceptionCount) {
      box.style.display = 'none';
      return;
    }
    const n = ctx.exceptionCount;
    if (title) title.textContent = `${n} depot alert${n === 1 ? '' : 's'}`;
    if (sub) sub.textContent = 'Optional &mdash; review stock and depot issues when you have time.';
    box.style.display = 'flex';
  }

  function renderMonthEndBanner() {
    const box = document.getElementById('rdcHubMonthEnd');
    if (box) box.style.display = isMonthEndWindow() ? 'flex' : 'none';
  }

  function renderHistoryTools(today) {
    const el = document.getElementById('rdcHubHistoryTools');
    if (!el) return;
    el.innerHTML = `
      <button class="btn btn-sm" type="button" onclick="rdcHubOpenOtherDate()">Open another date…</button>
      <button class="btn btn-sm" type="button" onclick="LapokAPI.exportRdcSheet('${today}')">Export today (Excel)</button>`;
  }

  function renderCashNudge(ctx) {
    const box = document.getElementById('rdcHubCashNudge');
    const title = document.getElementById('rdcHubCashNudgeTitle');
    const sub = document.getElementById('rdcHubCashNudgeSub');
    if (!box) return;
    if (!ctx.cashPending) {
      box.style.display = 'none';
      return;
    }
    const n = ctx.cashPending;
    if (title) title.textContent = `${n} trip${n === 1 ? '' : 's'} need cash confirm`;
    if (sub) sub.textContent = 'Optional &mdash; confirm when cadets return. Under More → Cash handover.';
    box.style.display = 'flex';
  }

  function renderRecvNudge(ctx) {
    const box = document.getElementById('rdcHubRecvNudge');
    const title = document.getElementById('rdcHubRecvNudgeTitle');
    const sub = document.getElementById('rdcHubRecvNudgeSub');
    if (!box) return;
    if (!ctx.receivablesLoaded || ctx.totalReceivables < RECEIVABLES_HIGH_UGX) {
      box.style.display = 'none';
      return;
    }
    if (title) title.textContent = `High receivables &mdash; ${fmtUgx(ctx.totalReceivables)}`;
    if (sub) {
      sub.textContent = `${ctx.receivablesCount} customer${ctx.receivablesCount === 1 ? '' : 's'} owing. Collections are managed by your manager &mdash; not part of today's close.`;
    }
    box.style.display = 'flex';
  }

  function renderPrimaryAction(ctx, wf) {
    const box = document.getElementById('rdcHubPrimaryAction');
    const title = document.getElementById('rdcHubPrimaryTitle');
    const sub = document.getElementById('rdcHubPrimarySub');
    const btn = document.getElementById('rdcHubPrimaryBtn');
    if (!box || !title || !sub || !btn) return;

    let page = 'accountant-rdc';
    let label = 'Continue';
    let headline = 'Start today\'s close';
    let detail = 'Balancing, then one tap to send the manager pack.';
    let done = false;
    let resumeWizard = false;

    if (!ctx.sheetLoaded) {
      headline = 'Could not load today\'s sheet';
      detail = 'Open Today\'s close to retry, or click Refresh.';
      label = 'Open today\'s close';
    } else if (ctx.sheetStatus === 'rejected' || ctx.sheetStatus === 'reopened') {
      headline = ctx.sheetStatus === 'rejected' ? 'Balancing needs correction' : 'Sheet reopened by manager';
      detail = ctx.reviewNote || 'Update the sheet and resubmit.';
      label = 'Fix and resubmit';
      resumeWizard = true;
    } else if (wf.balActive) {
      headline = 'Continue daily balancing';
      detail = 'Step 1 &mdash; enter sales, expenses, and cash.';
      label = 'Continue balancing';
      resumeWizard = true;
    } else if (wf.packActive) {
      page = 'report-exchange';
      headline = 'Send manager pack';
      detail = 'One tap &mdash; Outpost builds a PDF for your manager.';
      label = 'Send pack';
    } else if (wf.dayComplete) {
      headline = 'Today\'s close is complete';
      detail = 'Balancing submitted and manager pack sent.';
      label = 'View sheet';
      done = true;
    } else if (balSubmitted(ctx.sheetStatus)) {
      headline = 'Waiting for manager review';
      detail = 'You can send the manager pack or view the submitted sheet.';
      label = 'Send pack';
      page = 'report-exchange';
    }

    title.textContent = headline;
    sub.textContent = detail;
    btn.textContent = label;
    btn.onclick = () => {
      if (resumeWizard) sessionStorage.setItem('rdcResumeWizard', '1');
      showPage(page);
    };
    box.classList.toggle('done', done);
    box.style.display = 'flex';
  }

  function renderVarianceCard() {
    /* KPI cards removed from home */
  }

  function priorityRow(dotClass, title, detail, pageId) {
    const action = pageId
      ? `<button class="btn btn-sm" style="margin-left:auto;flex-shrink:0" onclick="showPage('${pageId}')">Open</button>`
      : '';
    return `<div class="rdc-priority-row">
      <div class="rdc-priority-dot ${dotClass}"></div>
      <div style="flex:1"><div style="font-size:13px;font-weight:600">${title}</div><div style="font-size:12px;color:var(--gray-mid);margin-top:2px">${detail}</div></div>
      ${action}
    </div>`;
  }

  function renderPriorities(ctx, wf) {
    const root = document.getElementById('rdcHubPriorities');
    if (!root) return;
    const rows = [];

    if (ctx.cashPending > 0) {
      rows.push(priorityRow('warn', 'Field cash', `${ctx.cashPending} trip(s) waiting &mdash; optional handover.`, 'accountant-cash'));
    }
    if (ctx.exceptionCount > 0) {
      rows.push(priorityRow('alert', 'Depot alerts', `${ctx.exceptionCount} item(s) to review.`, 'admin-exceptions'));
    }
    if (ctx.welfareOpen > 0) {
      rows.push(priorityRow('warn', 'Staff welfare', `${ctx.welfareOpen} open request(s) &mdash; UGX ${Number(ctx.welfareOpenAmount || 0).toLocaleString()}.`, 'accountant-welfare'));
    }
    if (!ctx.sheetLoaded) {
      rows.push(priorityRow('alert', 'Sheet not loaded', 'Check connection and refresh.', 'accountant-rdc'));
    } else if (ctx.sheetStatus === 'rejected' && ctx.reviewNote) {
      rows.push(priorityRow('warn', 'Manager note', ctx.reviewNote, 'accountant-rdc'));
    }

    if (!rows.length) {
      root.innerHTML = '<div style="color:var(--gray-mid);font-size:12px">No extra alerts.</div>';
      return;
    }
    root.innerHTML = rows.join('');
  }

  function renderStepLabels() {
    /* removed &mdash; checklist handles labels */
  }

  function renderRecentSheets(sheets, today) {
    const table = document.getElementById('rdcHubRecentTable');
    if (!table) return;
    const recent = (sheets || []).slice(0, 5);
    const rows = recent.map((s) => {
      const variance = Number(s.variance || 0);
      const varCls = variance === 0 ? '' : variance > 0 ? 'surplus' : 'deficit';
      const isToday = s.balance_date === today;
      return `<tr class="${isToday ? 'rdc-hub-today' : ''}">
        <td>${isToday ? 'Today' : s.balance_date}</td>
        <td>${statusBadge(s.status)}</td>
        <td class="${varCls}">${variance === 0 ? '0' : variance.toLocaleString()}</td>
        <td><button class="btn btn-sm" onclick="openRdcSheetDate('${s.balance_date}')">Open</button></td>
      </tr>`;
    }).join('');
    table.innerHTML = '<tr><th>Date</th><th>Status</th><th>Variance</th><th></th></tr>' +
      (rows || '<tr><td colspan="4" style="color:var(--gray-mid)">No sheets this month yet &mdash; start with Step 1.</td></tr>');
  }

  function renderHubAlert(ctx) {
    const el = document.getElementById('rdcHubAlert');
    if (!el) return;
    const msgs = [];
    if (ctx.sheetStatus === 'rejected') {
      msgs.push('Today\'s balancing was rejected &mdash; review the manager note and resubmit.');
      el.className = 'alert a-danger';
    } else if (ctx.sheetStatus === 'reopened') {
      msgs.push('Today\'s sheet was reopened for correction.');
      el.className = 'alert a-warning';
    } else if (ctx.sheetStatus === 'approved') {
      msgs.push('Manager approved today\'s balancing sheet.');
      el.className = 'alert a-success';
    } else if (ctx.sheetStatus === 'submitted') {
      msgs.push('Balancing submitted &mdash; waiting for manager review.');
      el.className = 'alert a-info';
    } else if (ctx.sheetStatus === 'under_review') {
      msgs.push('Manager is reviewing your submitted sheet.');
      el.className = 'alert a-info';
    } else {
      el.className = 'alert a-warning';
    }
    if (ctx.reviewNote && ['reopened', 'rejected', 'under_review'].includes(ctx.sheetStatus)) {
      msgs.push('Manager: ' + ctx.reviewNote);
    }
    if (!msgs.length) {
      el.style.display = 'none';
      el.innerHTML = '';
      return;
    }
    el.style.display = 'flex';
    const icon = String(el.className).includes('a-success') ? '✓'
      : String(el.className).includes('a-info') ? 'ℹ' : '⚠';
    el.innerHTML = `<span>${icon}</span><div>${msgs.join(' ')}</div>`;
  }

  function renderLiveChip(health) {
    const chip = document.getElementById('rdcHubLiveChip');
    if (!chip) return;
    if (!health || !health.ok) {
      chip.textContent = 'Setup needed';
      chip.style.background = 'var(--red-light)';
      chip.style.color = 'var(--red)';
      chip.title = health?.error || 'Could not verify database';
      return;
    }
    const live = health.data?.live;
    chip.textContent = live ? 'Module live' : 'Setup needed';
    chip.style.background = live ? 'var(--green-light)' : 'var(--amber-light)';
    chip.style.color = live ? 'var(--green)' : 'var(--amber)';
    chip.title = health.data?.message || '';
  }

  function showLoading() {
    renderGreeting();
    setText('rdcHubLiveChip', 'Checking…');
    setText('rdcHubMonthChip', 'Refreshing…');
    const list = document.getElementById('rdcHubChecklist');
    if (list) list.innerHTML = '<li class="rdc-hub-check-item"><div class="rdc-hub-check-num">…</div><div><div style="font-weight:600;font-size:13px">Loading…</div></div></li>';
  }

  async function hubFetch(path, label) {
    try {
      return { ok: true, data: await LapokAPI.get(path), label };
    } catch (e) {
      return { ok: false, error: e.message, label };
    }
  }

  async function loadRdcHubPage() {
    const page = document.getElementById('page-accountant-rdc-hub');
    if (!page) return;

    showLoading();
    const today = todayIso();
    const month = today.slice(0, 7);
    const errors = [];

    const results = await Promise.all([
      hubFetch('/api/rdc/health.php', 'Module health'),
      hubFetch('/api/rdc/fetch_sheet.php?date=' + encodeURIComponent(today), 'Today\'s sheet'),
      hubFetch('/api/trips/pending_cash.php', 'Cash handovers'),
      hubFetch('/api/exceptions/fetch.php', 'Depot alerts'),
      hubFetch('/api/rdc/list_sheets.php?month=' + encodeURIComponent(month), 'Recent sheets'),
      hubFetch('/api/reports/exchange_list.php', 'Manager pack'),
      hubFetch('/api/customers/fetch_customers.php', 'Receivables'),
      hubFetch('/api/welfare/fetch.php?status=open&limit=5', 'Staff welfare'),
    ]);

    results.filter((r) => !r.ok && r.label !== 'Module health').forEach((r) => errors.push(r.label + ': ' + r.error));

    const healthRes = results[0];
    renderLiveChip(healthRes);
    if (healthRes.ok && healthRes.data && !healthRes.data.live) {
      errors.unshift('Database: ' + (healthRes.data.message || 'Run migrations 008 and 009'));
    }

    const sheetRes = results[1].ok ? results[1].data : {};
    const cashRes = results[2].ok ? results[2].data : {};
    const excRes = results[3].ok ? results[3].data : {};
    const listRes = results[4].ok ? results[4].data : {};
    const exchangeRes = results[5].ok ? results[5].data : {};
    const customersRes = results[6].ok ? results[6].data : {};
    const welfareRes = results[7].ok ? results[7].data : {};
    const customers = customersRes.customers || [];
    const owing = customers.filter((c) => Number(c.credit_balance) > 0);
    const totalReceivables = owing.reduce((s, c) => s + Number(c.credit_balance || 0), 0);

    const sheet = sheetRes.sheet || {};
    const sheetStatus = String(sheet.status || 'draft');
    const variance = Number(sheet.variance || 0);
    const grandTotal = Number(sheet.grand_total || 0);
    const cashPending = (cashRes.trips || []).filter((t) => t.cash_collected === null).length;
    const cadetFlagCount = excRes.summary?.cadet_report || (excRes.items || []).filter((i) => i.type === 'cadet_report').length;
    const cadetConsolidation = sheetRes.cadet_consolidation || {};
    const cadetReportCount = cadetConsolidation.reports_today || 0;
    const exceptionCount = (excRes.items || []).length;
    const outbox = exchangeRes.outbox || [];
    const packToday = outbox.find((p) => String(p.report_date || '').slice(0, 10) === today);
    const packSentToday = !!packToday;
    let monthSheets = listRes.sheets || [];
    if (results[1].ok && !monthSheets.some((s) => s.balance_date === today)) {
      monthSheets = [{ balance_date: today, status: sheetStatus, variance }, ...monthSheets];
    }

    renderVarianceCard();
    renderMonthChip(monthSheets);

    const ctx = {
      sheetLoaded: results[1].ok,
      sheetStatus,
      reviewNote: sheet.review_note || '',
      cashPending,
      cadetFlagCount,
      cadetReportCount,
      exceptionCount,
      packSentToday,
      packTodayId: packToday?.id || null,
      receivablesLoaded: results[6].ok,
      totalReceivables,
      receivablesCount: owing.length,
      welfareOpen: welfareRes.summary?.open_count || 0,
      welfareOpenAmount: welfareRes.summary?.open_amount || 0,
    };
    const wf = workflowState(ctx);

    renderProgress(wf);
    renderChecklist(ctx, wf);
    renderCashNudge(ctx);
    renderCadetNudge(ctx);
    renderRecvNudge(ctx);
    renderDepotNudge(ctx);
    renderMonthEndBanner();
    renderPackView(ctx);
    renderHistoryTools(today);
    renderPrimaryAction(ctx, wf);
    renderPriorities(ctx, wf);
    renderRecentSheets(monthSheets, today);
    renderHubAlert(ctx);
    if (typeof loadDirectorBriefWidget === 'function') loadDirectorBriefWidget();
    if (typeof loadAccountantClosingStock === 'function') loadAccountantClosingStock();
    if (typeof loadDeliveryList === 'function') loadDeliveryList();

    if (errors.length) {
      const pri = document.getElementById('rdcHubPriorities');
      if (pri) {
        const errHtml = `<div class="alert a-warning" style="margin-bottom:.8rem"><span>⚠</span><div>${errors.join('<br>')}</div></div>`;
        pri.innerHTML = errHtml + pri.innerHTML;
      }
    }
  }

  function renderMonthChip(sheets) {
    const chip = document.getElementById('rdcHubMonthChip');
    if (chip) chip.textContent = monthProgressLabel(sheets);
  }

  window.loadRdcHubPage = loadRdcHubPage;
  window.rdcHubOpenOtherDate = function () {
    const today = todayIso();
    const picked = prompt('Open balancing for date (YYYY-MM-DD):', today);
    if (!picked || !/^\d{4}-\d{2}-\d{2}$/.test(picked)) return;
    if (typeof openRdcSheetDate === 'function') openRdcSheetDate(picked);
    else showPage('accountant-rdc');
  };
})();
