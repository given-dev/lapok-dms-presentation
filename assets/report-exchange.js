/**
 * Lapok DMS — PDF report exchange (Accountant ↔ Manager ↔ Executive)
 */
let reportExchangeData = null;
const REPORT_FOLLOW_UP_KEY = 'lapok_report_follow_up';

const REPORT_ROLE_LABELS = {
  accountant: 'Accountant',
  manager: 'Manager',
  executive: 'Executive / Board',
  cadet: 'Field (Cadet)',
  driver: 'Field (Driver)',
  field_user: 'Field agent',
  admin: 'Admin',
};

function reportExchangeRole() {
  return currentUser?.role;
}

function reportCanSend() {
  return currentUser && ['accountant', 'manager', 'admin'].includes(currentUser.role);
}

function reportCanAcknowledge() {
  return currentUser && ['executive', 'admin'].includes(currentUser.role);
}

function reportTodayIso() {
  return new Date().toISOString().slice(0, 10);
}

function reportPdfUrl(id) {
  const path = '/api/reports/download_packet.php?id=' + encodeURIComponent(id);
  const currentDir = window.location.pathname.replace(/\/[^/]*$/, '/').replace(/\/+$/, '');
  return currentDir + path;
}

function reportStatusBadge(status) {
  const cls = { sent: 'bw', read: 'bi', acknowledged: 'bs' }[status] || 'bg';
  const label = { sent: 'New', read: 'Read', acknowledged: 'Acknowledged' }[status] || status;
  return `<span class="badge ${cls}">${label}</span>`;
}

function reportFollowUpSet() {
  try {
    const raw = localStorage.getItem(REPORT_FOLLOW_UP_KEY);
    const ids = JSON.parse(raw || '[]');
    return new Set(Array.isArray(ids) ? ids.map((v) => Number(v)).filter((v) => Number.isFinite(v)) : []);
  } catch (_) {
    return new Set();
  }
}

function reportSaveFollowUpSet(set) {
  localStorage.setItem(REPORT_FOLLOW_UP_KEY, JSON.stringify(Array.from(set.values())));
}

function reportToggleFollowUp(id) {
  const set = reportFollowUpSet();
  if (set.has(id)) set.delete(id);
  else set.add(id);
  reportSaveFollowUpSet(set);
  renderReportExchange();
}

function reportToast(msg, err) {
  if (typeof adminToast === 'function') adminToast(msg, !!err);
  else if (err) alert(msg);
  else alert(msg);
}

async function loadReportExchangePage() {
  const root = document.getElementById('reportExchangeRoot');
  if (!root || !currentUser) return;
  if (!['accountant', 'manager', 'executive', 'admin'].includes(currentUser.role)) {
    root.innerHTML = '<div class="alert a-warning">Report exchange is for Accountant, Manager, and Executive roles.</div>';
    return;
  }

  root.innerHTML = '<p style="color:var(--gray-mid);padding:1rem">Loading…</p>';
  try {
    reportExchangeData = await LapokAPI.get('/api/reports/exchange_list.php');
    if (reportExchangeRole() === 'accountant') {
      try {
        const sheetRes = await LapokAPI.get('/api/rdc/fetch_sheet.php?date=' + encodeURIComponent(reportTodayIso()));
        const st = String(sheetRes.sheet?.status || 'draft');
        reportExchangeData.balancingSubmitted = ['submitted', 'under_review', 'approved'].includes(st);
        reportExchangeData.balancingStatus = st;
      } catch (_) {
        reportExchangeData.balancingSubmitted = false;
        reportExchangeData.balancingStatus = 'draft';
      }
    }
    renderReportExchange();
  } catch (e) {
    root.innerHTML = `<div class="alert a-danger"><span>⚠</span>${escReport(e.message)}</div>`;
  }
}

function renderAccountantPackPage() {
  const root = document.getElementById('reportExchangeRoot');
  if (!root || !reportExchangeData) return;

  const today = reportTodayIso();
  const outbox = reportExchangeData.outbox || [];
  const sentToday = outbox.find((p) => String(p.report_date || '').slice(0, 10) === today);
  const balOk = !!reportExchangeData.balancingSubmitted;
  const balStatus = reportExchangeData.balancingStatus || 'draft';

  const gateHtml = !sentToday && !balOk
    ? `<div class="alert a-warning" style="margin-bottom:1rem"><span>⚠</span><div><strong>Submit balancing first.</strong> Today's sheet is <em>${escReport(balStatus.replace('_', ' '))}</em> — complete Step 1 before sending the pack. <button class="btn btn-sm" type="button" style="margin-left:8px" onclick="showPage('accountant-rdc')">Open today's close</button></div></div>`
    : '';

  const primaryHtml = sentToday
    ? `<div class="rdc-hub-primary done" style="display:flex">
        <div class="rdc-hub-primary-text">
          <div class="rdc-hub-primary-title">Pack sent for today</div>
          <div class="rdc-hub-primary-sub">${escReport(sentToday.title || 'Daily pack')} · ${LapokAPI.formatDate(sentToday.sent_at)} ${LapokAPI.formatTime(sentToday.sent_at)}</div>
        </div>
        <button class="btn btn-red" type="button" onclick="reportOpenPdf(${sentToday.id})">View PDF</button>
      </div>`
    : `<div class="rdc-hub-primary" style="display:flex">
        <div class="rdc-hub-primary-text">
          <div class="rdc-hub-primary-title">Send today's pack to manager</div>
          <div class="rdc-hub-primary-sub">Lapok builds a PDF from today's depot data — one tap to deliver.</div>
        </div>
        <button class="btn btn-red" type="button" id="acctPackSendBtn" onclick="reportAccountantSendPack()" ${balOk ? '' : 'disabled'}>Send pack now</button>
      </div>`;

  root.innerHTML = `
    <div class="rdc-bal-toolbar">
      <button class="btn btn-sm" type="button" onclick="showPage('accountant-rdc-hub')">← Home</button>
      <span class="chip">Step 2 — Manager pack</span>
      <button class="btn btn-sm" type="button" style="margin-left:auto" onclick="loadReportExchangePage()">Refresh</button>
    </div>
    ${gateHtml}
    ${primaryHtml}
    <div class="card" style="margin-top:1rem">
      <div class="form-group" style="margin:0">
        <label>Cover note for manager (optional)</label>
        <textarea class="textarea-inp" id="acctPackNotes" rows="2" placeholder="e.g. Short cash explained on balancing sheet…"></textarea>
      </div>
    </div>
    <input type="hidden" id="reportSendDate" value="${today}">
    <details class="rdc-section" style="margin-top:1rem">
      <summary>Upload your own PDF instead</summary>
      <div class="rdc-section-body">
        <form id="reportUploadForm" onsubmit="reportUploadAndSend(event)">
          <input type="hidden" name="report_date" id="reportUploadDate" value="${today}">
          <div class="form-group"><label>Title</label><input class="input" name="title" required placeholder="Daily pack ${today}"></div>
          <div class="form-group"><label>PDF file</label><input class="input" type="file" name="pdf" accept="application/pdf,.pdf" required></div>
          <button type="submit" class="btn btn-sm">Upload &amp; send</button>
        </form>
      </div>
    </details>
    <div class="rdc-bal-sticky">
      <span style="font-size:12px;color:var(--gray-mid);margin-right:auto">${sentToday ? "Today's close is complete." : 'Make sure daily balancing is submitted first.'}</span>
      <button class="btn btn-sm btn-black" type="button" onclick="showPage('accountant-rdc-hub')">Done → Home</button>
    </div>`;
}

function renderReportExchange() {
  const root = document.getElementById('reportExchangeRoot');
  if (!root || !reportExchangeData) return;

  if (reportExchangeRole() === 'accountant') {
    renderAccountantPackPage();
    return;
  }

  const role = reportExchangeRole();
  const next = reportExchangeData.next_recipient;
  const followUp = reportFollowUpSet();
  const inbox = (reportExchangeData.inbox || [])
    .map((p) => ({ ...p, follow_up: followUp.has(p.id) }))
    .sort((a, b) => {
      const rank = (packet) => (packet.follow_up ? 0 : packet.is_critical ? 1 : packet.is_overdue ? 2 : 3);
      const byRank = rank(a) - rank(b);
      if (byRank !== 0) return byRank;
      return String(b.sent_at || '').localeCompare(String(a.sent_at || ''));
    });
  const outbox = reportExchangeData.outbox || [];
  const unread = inbox.filter((p) => p.status === 'sent').length;

  const chainHtml = (reportExchangeData.chain || []).map((step, i) => {
    const active = step.role === role || (role === 'admin' && step.role === 'manager');
    const arrow = i < reportExchangeData.chain.length - 1 ? '<span class="rx-arrow">→</span>' : '';
    return `<div class="rx-step${active ? ' active' : ''}">
      <div class="rx-step-label">${escReport(step.label)}</div>
      ${step.sends_to ? `<div class="rx-step-to">sends PDF to ${REPORT_ROLE_LABELS[step.sends_to] || step.sends_to}</div>` : '<div class="rx-step-to">receives final brief</div>'}
    </div>${arrow}`;
  }).join('');

  const sendPanel = reportCanSend() && next ? `
    <div class="card" id="reportSendPanel">
      <div class="card-header">
        <span class="card-title">Send to ${REPORT_ROLE_LABELS[next] || next}</span>
        <span class="chip">Outbox</span>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Report date</label><input class="input" type="date" id="reportSendDate" value="${reportTodayIso()}"></div>
        <div class="form-group"><label>Title (optional)</label><input class="input" id="reportSendTitle" placeholder="Auto-generated if blank"></div>
      </div>
      <div class="form-group"><label>Notes (optional)</label><input class="input" id="reportSendNotes" placeholder="Cover note for recipient"></div>
      <div class="btn-group">
        <button class="btn btn-red" onclick="reportGenerateAndSend()">Generate PDF from Lapok &amp; send</button>
      </div>
      <div class="form-section" style="margin-top:1rem">Or upload your own PDF</div>
      <form id="reportUploadForm" onsubmit="reportUploadAndSend(event)">
        <div class="form-group"><label>Title</label><input class="input" name="title" required placeholder="e.g. Finance consolidation 24 Jun"></div>
        <div class="form-group"><label>PDF file</label><input class="input" type="file" name="pdf" accept="application/pdf,.pdf" required></div>
        <input type="hidden" name="report_date" id="reportUploadDate">
        <button type="submit" class="btn btn-black">Upload PDF &amp; send</button>
      </form>
    </div>` : (reportCanAcknowledge() ? `
    <div class="alert a-info"><span>ℹ</span>Executive view: PDF briefs arrive here from the Manager. Open to read; acknowledge when reviewed.</div>` : '');

  root.innerHTML = `
    <div class="rx-chain">${chainHtml}</div>
    ${sendPanel}
    <div class="two-col">
      <div class="card">
        <div class="card-header">
          <span class="card-title">Received (inbox)</span>
          ${unread ? `<span class="badge bd">${unread} new</span>` : '<span class="badge bg">Up to date</span>'}
        </div>
        <div class="tbl-wrap">${renderReportTable(inbox, 'inbox')}</div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Sent (outbox)</span></div>
        <div class="tbl-wrap">${renderReportTable(outbox, 'outbox')}</div>
      </div>
    </div>`;

  const uploadDate = document.getElementById('reportUploadDate');
  const sendDate = document.getElementById('reportSendDate');
  if (uploadDate && sendDate) uploadDate.value = sendDate.value;
  if (sendDate) sendDate.addEventListener('change', () => {
    if (uploadDate) uploadDate.value = sendDate.value;
  });
}

function renderReportTable(packets, mode) {
  if (!packets.length) {
    return `<p style="padding:1rem;color:var(--gray-mid)">No reports ${mode === 'inbox' ? 'received' : 'sent'} yet.</p>`;
  }
  const rows = packets.map((p) => {
    const party = mode === 'inbox'
      ? `From ${escReport(p.from_name)} <span style="color:var(--gray-mid)">(${REPORT_ROLE_LABELS[p.from_role] || p.from_role})</span>`
      : `To ${escReport(REPORT_ROLE_LABELS[p.to_role] || p.to_role)}`;
    const ackBtn = (mode === 'inbox' && p.to_role === 'executive' && reportCanAcknowledge() && p.status !== 'acknowledged')
      ? `<button class="btn btn-sm btn-red" onclick="reportAcknowledge(${p.id})">Acknowledge</button>` : '';
    const followBtn = mode === 'inbox'
      ? `<button class="btn btn-sm" onclick="reportToggleFollowUp(${p.id})">${p.follow_up ? 'Unmark follow-up' : 'Mark follow-up'}</button>`
      : '';
    const overdueBadge = p.is_overdue ? `<span class="badge bd">Overdue ${p.age_hours || 0}h</span>` : '';
    const criticalBadge = p.follow_up ? '<span class="badge br">Follow-up</span>' : (p.is_critical ? '<span class="badge bw">Critical</span>' : '');
    return `<tr>
      <td style="font-family:monospace;font-size:11px">${escReport(p.packet_ref)}</td>
      <td><strong>${escReport(p.title)}</strong><div style="font-size:11px;color:var(--gray-mid)">${party}</div><div style="margin-top:4px">${criticalBadge} ${overdueBadge}</div></td>
      <td>${escReport(p.report_type_label)}</td>
      <td>${LapokAPI.formatDate(p.report_date)}</td>
      <td>${reportStatusBadge(p.status)}</td>
      <td style="white-space:nowrap">
        <button class="btn btn-sm" onclick="reportOpenPdf(${p.id})">View PDF</button>
        ${ackBtn}
        ${followBtn}
      </td>
    </tr>`;
  }).join('');
  return `<table>
    <tr><th>Ref</th><th>Report</th><th>Type</th><th>Date</th><th>Status</th><th></th></tr>
    ${rows}
  </table>`;
}

function reportOpenPdf(id) {
  window.open(reportPdfUrl(id), '_blank', 'noopener');
  setTimeout(() => loadReportExchangePage(), 800);
}

async function reportAccountantSendPack() {
  if (!reportExchangeData?.balancingSubmitted) {
    reportToast('Submit today\'s balancing sheet before sending the pack.', true);
    return;
  }
  const btn = document.getElementById('acctPackSendBtn');
  if (btn) btn.disabled = true;
  const notes = document.getElementById('acctPackNotes')?.value.trim() || '';
  const reportDate = reportTodayIso();
  try {
    await LapokAPI.post('/api/reports/generate_pack.php', {
      report_date: reportDate,
      notes: notes || undefined,
    });
    reportToast('Pack sent to manager.');
    await loadReportExchangePage();
  } catch (e) {
    reportToast(e.message, true);
    if (btn) btn.disabled = false;
  }
}

async function reportGenerateAndSend() {
  if (!reportCanSend()) return;
  const reportDate = document.getElementById('reportSendDate')?.value || reportTodayIso();
  const title = document.getElementById('reportSendTitle')?.value.trim() || '';
  const notes = document.getElementById('reportSendNotes')?.value.trim() || '';
  try {
    await LapokAPI.post('/api/reports/generate_pack.php', {
      report_date: reportDate,
      title: title || undefined,
      notes: notes || undefined,
    });
    alert('PDF generated and sent to ' + (REPORT_ROLE_LABELS[reportExchangeData?.next_recipient] || 'recipient') + '.');
    await loadReportExchangePage();
  } catch (e) {
    alert(e.message);
  }
}

async function reportUploadAndSend(event) {
  event.preventDefault();
  if (!reportCanSend()) return;
  if (reportExchangeRole() === 'accountant' && !reportExchangeData?.balancingSubmitted) {
    reportToast('Submit today\'s balancing sheet before uploading a pack.', true);
    return;
  }
  const form = event.target;
  const sendDate = document.getElementById('reportSendDate')?.value || reportTodayIso();
  const uploadDate = document.getElementById('reportUploadDate');
  if (uploadDate) uploadDate.value = sendDate;
  const fd = new FormData(form);
  fd.set('report_date', sendDate);
  const notes = document.getElementById('reportSendNotes')?.value?.trim()
    || document.getElementById('acctPackNotes')?.value?.trim();
  if (notes) fd.set('notes', notes);

  try {
    const path = (() => {
      const currentDir = window.location.pathname.replace(/\/[^/]*$/, '/').replace(/\/+$/, '');
      return currentDir + '/api/reports/upload_packet.php';
    })();
    const res = await fetch(path, { method: 'POST', body: fd, credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'Upload failed');
    reportToast('PDF uploaded and sent.');
    form.reset();
    await loadReportExchangePage();
  } catch (e) {
    reportToast(e.message, true);
  }
}

async function reportAcknowledge(id) {
  if (!reportCanAcknowledge()) return;
  try {
    await LapokAPI.post('/api/reports/acknowledge_packet.php', { packet_id: id });
    await loadReportExchangePage();
  } catch (e) {
    alert(e.message);
  }
}

window.reportToggleFollowUp = reportToggleFollowUp;
window.reportAccountantSendPack = reportAccountantSendPack;
window.loadReportExchangePage = loadReportExchangePage;
window.reportOpenPdf = reportOpenPdf;
window.reportGenerateAndSend = reportGenerateAndSend;
window.reportUploadAndSend = reportUploadAndSend;
window.reportAcknowledge = reportAcknowledge;

function escReport(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
