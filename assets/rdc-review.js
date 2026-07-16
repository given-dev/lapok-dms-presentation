/**
 * Manager RDC review workflow (submitted -> review -> approved/rejected/reopened)
 * + comment threads + bulk approve
 */
let rdcReviewSheetsCache = [];
let rdcCommentsDate = '';

function rdcReviewBadge(status) {
  const s = String(status || 'draft');
  const cls = s === 'approved' ? 'bs'
    : s === 'rejected' ? 'bd'
      : s === 'submitted' ? 'bw'
        : s === 'under_review' ? 'bg'
          : s === 'reopened' ? 'bi'
            : 'bw';
  return `<span class="badge ${cls}">${s.replace('_', ' ')}</span>`;
}

function rdcReviewCanBulk(status) {
  return ['submitted', 'under_review', 'reopened'].includes(String(status || ''));
}

function rdcReviewActionButtons(sheet) {
  const status = String(sheet.status || 'draft');
  const btns = [];
  if (['submitted', 'reopened'].includes(status)) {
    btns.push(`<button class="btn btn-sm" onclick="rdcReviewAction('${sheet.balance_date}','start_review')">Start review</button>`);
  }
  if (['submitted', 'under_review'].includes(status)) {
    btns.push(`<button class="btn btn-sm btn-red" onclick="rdcEditReceivedSheet('${sheet.balance_date}')">Edit report</button>`);
  }
  if (['submitted', 'under_review', 'reopened'].includes(status)) {
    btns.push(`<button class="btn btn-sm btn-black" onclick="rdcReviewAction('${sheet.balance_date}','approve')">Approve</button>`);
    btns.push(`<button class="btn btn-sm" onclick="rdcReviewAction('${sheet.balance_date}','reject')">Reject</button>`);
  }
  if (['submitted', 'under_review', 'approved', 'rejected'].includes(status)) {
    btns.push(`<button class="btn btn-sm" onclick="rdcReviewAction('${sheet.balance_date}','reopen')">Reopen</button>`);
  }
  btns.push(`<button class="btn btn-sm" onclick="rdcViewSheet('${sheet.balance_date}')">View</button>`);
  btns.push(`<button class="btn btn-sm" onclick="rdcOpenComments('${sheet.balance_date}')">Comments${sheet.comment_count ? ` (${sheet.comment_count})` : ''}</button>`);
  return btns.join(' ');
}

function rdcReviewEsc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function rdcUpdateBulkBar() {
  const bar = document.getElementById('rdcBulkBar');
  const countEl = document.getElementById('rdcBulkCount');
  const selected = [...document.querySelectorAll('.rdc-bulk-check:checked')].map((el) => el.value);
  if (bar) bar.style.display = selected.length ? 'flex' : 'none';
  if (countEl) countEl.textContent = String(selected.length);
}

function rdcToggleBulkAll(master) {
  document.querySelectorAll('.rdc-bulk-check').forEach((el) => {
    el.checked = !!master.checked;
  });
  rdcUpdateBulkBar();
}

async function loadRdcReviewPage() {
  const table = document.getElementById('rdcReviewTable');
  if (!table) return;
  const monthEl = document.getElementById('rdcReviewMonth');
  if (monthEl && !monthEl.value) {
    const d = new Date();
    monthEl.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  }
  const month = monthEl?.value || new Date().toISOString().slice(0, 7);
  const pendingChip = document.getElementById('rdcReviewPendingChip');
  table.innerHTML = '<tr><th></th><th>Date</th><th>Status</th><th>Grand total</th><th>Variance</th><th>Submitted</th><th>Review</th><th>Action</th></tr><tr><td colspan="8" style="color:var(--gray-mid)">Loading…</td></tr>';
  try {
    const data = await LapokAPI.get('/api/rdc/list_sheets.php?month=' + encodeURIComponent(month));
    rdcReviewSheetsCache = data.sheets || [];
    if (pendingChip) {
      const n = data.pending_review || 0;
      pendingChip.textContent = n ? `${n} pending review` : 'All clear';
      pendingChip.className = 'badge ' + (n ? 'bw' : 'bs');
    }
    const rows = rdcReviewSheetsCache.map((s) => {
      const reviewText = s.review_note
        ? `${rdcReviewEsc(s.review_note)}${s.reviewed_by_name ? ' &middot; ' + rdcReviewEsc(s.reviewed_by_name) : ''}`
        : (s.reviewed_at ? 'Reviewed' : '&mdash;');
      const canBulk = rdcReviewCanBulk(s.status);
      const check = canBulk
        ? `<input type="checkbox" class="rdc-bulk-check" value="${s.balance_date}" onchange="rdcUpdateBulkBar()">`
        : '';
      return `<tr>
        <td>${check}</td>
        <td>${s.balance_date}</td>
        <td>${rdcReviewBadge(s.status)}</td>
        <td>${Number(s.grand_total || 0).toLocaleString()}</td>
        <td class="${Number(s.variance || 0) === 0 ? 'surplus' : 'deficit'}">${Number(s.variance || 0).toLocaleString()}</td>
        <td>${s.submitted_at ? LapokAPI.formatDate(s.submitted_at) + ' ' + LapokAPI.formatTime(s.submitted_at) : '&mdash;'}</td>
        <td>${reviewText}</td>
        <td style="white-space:normal">${rdcReviewActionButtons(s)}</td>
      </tr>`;
    }).join('');
    table.innerHTML = `<tr>
      <th><input type="checkbox" id="rdcBulkMaster" onchange="rdcToggleBulkAll(this)" title="Select all approvable"></th>
      <th>Date</th><th>Status</th><th>Grand total</th><th>Variance</th><th>Submitted</th><th>Review</th><th>Action</th>
    </tr>` + (rows || '<tr><td colspan="8" style="color:var(--gray-mid)">No RDC sheets for selected month.</td></tr>');
    rdcUpdateBulkBar();
    if (rdcCommentsDate) rdcOpenComments(rdcCommentsDate);
  } catch (e) {
    table.innerHTML = `<tr><td colspan="8" style="color:var(--red)">Failed to load RDC sheets: ${rdcReviewEsc(e.message)}</td></tr>`;
  }
}

async function rdcBulkApproveSelected() {
  const dates = [...document.querySelectorAll('.rdc-bulk-check:checked')].map((el) => el.value);
  if (!dates.length) {
    alert('Select one or more sheets waiting for review.');
    return;
  }
  const note = prompt(`Optional note for bulk approve (${dates.length} sheet(s)):`, '') || '';
  if (!confirm(`Approve ${dates.length} RDC sheet(s)?`)) return;
  try {
    const res = await LapokAPI.post('/api/rdc/bulk_approve.php', {
      balance_dates: dates,
      note,
    });
    if (typeof adminToast === 'function') adminToast(res.message || 'Bulk approve done');
    else alert(res.message || 'Bulk approve done');
    const master = document.getElementById('rdcBulkMaster');
    if (master) master.checked = false;
    await loadRdcReviewPage();
  } catch (e) {
    alert(e.message);
  }
}

async function rdcReviewAction(balanceDate, action) {
  const noteActions = ['reject', 'reopen'];
  let note = '';
  if (noteActions.includes(action)) {
    note = prompt(`Add review note for ${action} (${balanceDate}):`, '') || '';
  } else if (action === 'approve') {
    note = prompt(`Optional approve note (${balanceDate}):`, '') || '';
  }
  try {
    await LapokAPI.post('/api/rdc/review_sheet.php', {
      balance_date: balanceDate,
      action,
      note,
    });
    await loadRdcReviewPage();
    if (rdcCommentsDate === balanceDate) rdcOpenComments(balanceDate);
  } catch (e) {
    alert(e.message);
  }
}

async function rdcOpenComments(balanceDate) {
  rdcCommentsDate = balanceDate;
  const panel = document.getElementById('rdcCommentsPanel');
  const title = document.getElementById('rdcCommentsTitle');
  const list = document.getElementById('rdcCommentsList');
  const dateInp = document.getElementById('rdcCommentsDate');
  if (title) title.textContent = `Comment thread &mdash; ${balanceDate}`;
  if (dateInp) dateInp.value = balanceDate;
  if (panel) panel.style.display = 'block';
  if (list) list.innerHTML = '<p style="color:var(--gray-mid)">Loading…</p>';
  try {
    const data = await LapokAPI.get('/api/rdc/comments_list.php?balance_date=' + encodeURIComponent(balanceDate));
    if (data.setup_needed) {
      list.innerHTML = `<div class="alert a-warning"><span>⚠</span><div>${rdcReviewEsc(data.message || 'Run migration 014 to enable comments.')}</div></div>`;
      return;
    }
    const comments = data.comments || [];
    if (!comments.length) {
      list.innerHTML = '<p style="color:var(--gray-mid);font-size:13px">No comments yet. Add a note for the accountant or leave a review trail.</p>';
      return;
    }
    list.innerHTML = comments.map((c) => {
      const when = c.created_at
        ? `${LapokAPI.formatDate(c.created_at)} ${LapokAPI.formatTime(c.created_at)}`
        : '';
      const tag = c.action_tag && c.action_tag !== 'comment'
        ? `<span class="badge bw" style="margin-left:6px">${rdcReviewEsc(String(c.action_tag).replace('_', ' '))}</span>`
        : '';
      return `<div style="padding:.65rem 0;border-bottom:1px solid var(--gray-light)">
        <div style="font-size:12px;color:var(--gray-mid)">${rdcReviewEsc(c.author_name || 'User')}${c.author_role ? ' &middot; ' + rdcReviewEsc(c.author_role) : ''} &middot; ${when}${tag}</div>
        <div style="margin-top:4px;font-size:13px">${rdcReviewEsc(c.body)}</div>
      </div>`;
    }).join('');
  } catch (e) {
    if (list) list.innerHTML = `<p style="color:var(--red)">${rdcReviewEsc(e.message)}</p>`;
  }
}

function rdcCloseComments() {
  rdcCommentsDate = '';
  const panel = document.getElementById('rdcCommentsPanel');
  if (panel) panel.style.display = 'none';
}

async function rdcPostComment() {
  const date = document.getElementById('rdcCommentsDate')?.value || rdcCommentsDate;
  const inp = document.getElementById('rdcCommentsInput');
  const body = inp?.value?.trim() || '';
  if (!date || !body) {
    alert('Enter a comment first.');
    return;
  }
  try {
    await LapokAPI.post('/api/rdc/comments_add.php', { balance_date: date, body });
    if (inp) inp.value = '';
    await rdcOpenComments(date);
    await loadRdcReviewPage();
  } catch (e) {
    alert(e.message);
  }
}

function rdcViewSheet(balanceDate) {
  sessionStorage.removeItem('rdcManagerEdit');
  if (typeof showPage === 'function') showPage('accountant-rdc');
  if (typeof window.openRdcSheetDate === 'function') {
    setTimeout(() => window.openRdcSheetDate(balanceDate), 120);
  }
}

/** Open received sheet in manager edit mode (sales/expenses/cash writable). */
function rdcEditReceivedSheet(balanceDate) {
  sessionStorage.setItem('rdcManagerEdit', '1');
  if (typeof showPage === 'function') showPage('accountant-rdc');
  if (typeof window.openRdcSheetDate === 'function') {
    setTimeout(() => window.openRdcSheetDate(balanceDate), 120);
  }
}

window.loadRdcReviewPage = loadRdcReviewPage;
window.rdcReviewAction = rdcReviewAction;
window.rdcViewSheet = rdcViewSheet;
window.rdcEditReceivedSheet = rdcEditReceivedSheet;
window.rdcBulkApproveSelected = rdcBulkApproveSelected;
window.rdcToggleBulkAll = rdcToggleBulkAll;
window.rdcUpdateBulkBar = rdcUpdateBulkBar;
window.rdcOpenComments = rdcOpenComments;
window.rdcCloseComments = rdcCloseComments;
window.rdcPostComment = rdcPostComment;
