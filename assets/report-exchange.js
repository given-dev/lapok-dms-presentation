/**
 * Lapok DMS — PDF report exchange (Accountant ↔ Manager ↔ Executive)
 */
let reportExchangeData = null;

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

async function loadReportExchangePage() {
  const root = document.getElementById('reportExchangeRoot');
  if (!root || !currentUser) return;
  if (!['accountant', 'manager', 'executive', 'admin'].includes(currentUser.role)) {
    root.innerHTML = '<div class="alert a-warning">Report exchange is for Accountant, Manager, and Executive roles.</div>';
    return;
  }

  root.innerHTML = '<p style="color:var(--gray-mid);padding:1rem">Loading report exchange…</p>';
  try {
    reportExchangeData = await LapokAPI.get('/api/reports/exchange_list.php');
    renderReportExchange();
  } catch (e) {
    root.innerHTML = `<div class="alert a-danger"><span>⚠</span>${escReport(e.message)}</div>`;
  }
}

function renderReportExchange() {
  const root = document.getElementById('reportExchangeRoot');
  if (!root || !reportExchangeData) return;

  const role = reportExchangeRole();
  const next = reportExchangeData.next_recipient;
  const inbox = reportExchangeData.inbox || [];
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
        <div class="form-group"><label>Report date</label><input class="input" type="date" id="reportSendDate" value="${new Date().toISOString().slice(0, 10)}"></div>
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
    return `<tr>
      <td style="font-family:monospace;font-size:11px">${escReport(p.packet_ref)}</td>
      <td><strong>${escReport(p.title)}</strong><div style="font-size:11px;color:var(--gray-mid)">${party}</div></td>
      <td>${escReport(p.report_type_label)}</td>
      <td>${LapokAPI.formatDate(p.report_date)}</td>
      <td>${reportStatusBadge(p.status)}</td>
      <td style="white-space:nowrap">
        <button class="btn btn-sm" onclick="reportOpenPdf(${p.id})">View PDF</button>
        ${ackBtn}
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

async function reportGenerateAndSend() {
  if (!reportCanSend()) return;
  const reportDate = document.getElementById('reportSendDate')?.value || new Date().toISOString().slice(0, 10);
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
  const form = event.target;
  const sendDate = document.getElementById('reportSendDate')?.value || new Date().toISOString().slice(0, 10);
  document.getElementById('reportUploadDate').value = sendDate;
  const fd = new FormData(form);
  fd.set('report_date', sendDate);
  const notes = document.getElementById('reportSendNotes')?.value.trim();
  if (notes) fd.set('notes', notes);

  try {
    const path = (() => {
      const currentDir = window.location.pathname.replace(/\/[^/]*$/, '/').replace(/\/+$/, '');
      return currentDir + '/api/reports/upload_packet.php';
    })();
    const res = await fetch(path, { method: 'POST', body: fd, credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'Upload failed');
    alert('PDF uploaded and sent.');
    form.reset();
    await loadReportExchangePage();
  } catch (e) {
    alert(e.message);
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

function escReport(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
