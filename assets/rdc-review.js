/**
 * Manager RDC review workflow (submitted -> review -> approved/rejected/reopened)
 */
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

function rdcReviewActionButtons(sheet) {
  const status = String(sheet.status || 'draft');
  const btns = [];
  if (['submitted', 'reopened'].includes(status)) {
    btns.push(`<button class="btn btn-sm" onclick="rdcReviewAction('${sheet.balance_date}','start_review')">Start review</button>`);
  }
  if (['submitted', 'under_review', 'reopened'].includes(status)) {
    btns.push(`<button class="btn btn-sm btn-black" onclick="rdcReviewAction('${sheet.balance_date}','approve')">Approve</button>`);
    btns.push(`<button class="btn btn-sm btn-red" onclick="rdcReviewAction('${sheet.balance_date}','reject')">Reject</button>`);
  }
  if (['submitted', 'under_review', 'approved', 'rejected'].includes(status)) {
    btns.push(`<button class="btn btn-sm" onclick="rdcReviewAction('${sheet.balance_date}','reopen')">Reopen</button>`);
  }
  btns.push(`<button class="btn btn-sm" onclick="rdcViewSheet('${sheet.balance_date}')">View</button>`);
  return btns.join(' ');
}

async function loadRdcReviewPage() {
  const table = document.getElementById('rdcReviewTable');
  if (!table) return;
  const monthEl = document.getElementById('rdcReviewMonth');
  if (monthEl && !monthEl.value) monthEl.value = new Date().toISOString().slice(0, 7);
  const month = monthEl?.value || new Date().toISOString().slice(0, 7);
  table.innerHTML = '<tr><th>Date</th><th>Status</th><th>Grand total</th><th>Variance</th><th>Submitted</th><th>Review</th><th>Action</th></tr><tr><td colspan="7" style="color:var(--gray-mid)">Loading…</td></tr>';
  try {
    const data = await LapokAPI.get('/api/rdc/list_sheets.php?month=' + encodeURIComponent(month));
    const rows = (data.sheets || []).map((s) => {
      const reviewText = s.review_note
        ? `${s.review_note}${s.reviewed_by_name ? ' · ' + s.reviewed_by_name : ''}`
        : (s.reviewed_at ? 'Reviewed' : '—');
      return `<tr>
        <td>${s.balance_date}</td>
        <td>${rdcReviewBadge(s.status)}</td>
        <td>${Number(s.grand_total || 0).toLocaleString()}</td>
        <td class="${Number(s.variance || 0) === 0 ? 'surplus' : 'deficit'}">${Number(s.variance || 0).toLocaleString()}</td>
        <td>${s.submitted_at ? LapokAPI.formatDate(s.submitted_at) + ' ' + LapokAPI.formatTime(s.submitted_at) : '—'}</td>
        <td>${reviewText}</td>
        <td>${rdcReviewActionButtons(s)}</td>
      </tr>`;
    }).join('');
    table.innerHTML = '<tr><th>Date</th><th>Status</th><th>Grand total</th><th>Variance</th><th>Submitted</th><th>Review</th><th>Action</th></tr>' +
      (rows || '<tr><td colspan="7" style="color:var(--gray-mid)">No RDC sheets for selected month.</td></tr>');
  } catch (e) {
    table.innerHTML = `<tr><td colspan="7" style="color:var(--red)">Failed to load RDC sheets: ${e.message}</td></tr>`;
  }
}

async function rdcReviewAction(balanceDate, action) {
  const noteActions = ['reject', 'reopen'];
  let note = '';
  if (noteActions.includes(action)) {
    note = prompt(`Add review note for ${action} (${balanceDate}):`, '') || '';
  }
  try {
    await LapokAPI.post('/api/rdc/review_sheet.php', {
      balance_date: balanceDate,
      action,
      note,
    });
    await loadRdcReviewPage();
  } catch (e) {
    alert(e.message);
  }
}

function rdcViewSheet(balanceDate) {
  if (typeof showPage === 'function') showPage('accountant-rdc');
  if (typeof window.openRdcSheetDate === 'function') {
    setTimeout(() => window.openRdcSheetDate(balanceDate), 120);
  }
}

window.loadRdcReviewPage = loadRdcReviewPage;
window.rdcReviewAction = rdcReviewAction;
window.rdcViewSheet = rdcViewSheet;
