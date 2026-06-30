/**
 * Staff welfare register — server-synced for accountant, manager, executive, admin.
 */
(function () {
  const LEGACY_STORE_KEY = 'lapok.welfare.register.v1';
  let entries = [];
  let readOnly = false;
  let summary = { open_count: 0, resolved_count: 0, open_amount: 0 };

  function toast(msg, err) {
    if (typeof adminToast === 'function') adminToast(msg, !!err);
    else if (!err) alert(msg);
    else alert(msg);
  }

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  function typeLabel(type) {
    const map = { request: 'Welfare request', advance: 'Salary advance', medical: 'Medical support', other: 'Other' };
    return map[type] || type;
  }

  function loadLegacyEntries() {
    try {
      const raw = localStorage.getItem(LEGACY_STORE_KEY);
      const list = JSON.parse(raw || '[]');
      return Array.isArray(list) ? list : [];
    } catch (_) {
      return [];
    }
  }

  function clearLegacyEntries() {
    try { localStorage.removeItem(LEGACY_STORE_KEY); } catch (_) {}
  }

  function setReadOnlyBanner() {
    const page = document.getElementById('page-accountant-welfare');
    if (!page) return;
    let banner = document.getElementById('welfareReadOnlyBanner');
    if (!readOnly) {
      if (banner) banner.remove();
      return;
    }
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'welfareReadOnlyBanner';
      banner.className = 'alert a-info';
      banner.style.marginBottom = '1rem';
      page.querySelector('.rdc-bal-toolbar')?.after(banner);
    }
    banner.innerHTML = '<span>ℹ</span><div>View only — welfare register is maintained by depot staff. Entries sync across roles.</div>';
  }

  function applyReadOnlyUi() {
    setReadOnlyBanner();
    const form = document.getElementById('welfareFormCard');
    const addBtn = document.querySelector('#page-accountant-welfare .btn-red');
    if (form) form.style.display = readOnly ? 'none' : '';
    if (addBtn && addBtn.textContent.includes('Save entry')) addBtn.style.display = readOnly ? 'none' : '';
  }

  function updateSummaryChip() {
    const chip = document.getElementById('welfareSummaryChip');
    if (!chip) return;
    const n = summary.open_count || 0;
    chip.textContent = n ? `${n} open · UGX ${Number(summary.open_amount || 0).toLocaleString()}` : 'All resolved';
    chip.className = 'badge ' + (n ? 'bw' : 'bs');
  }

  function renderTable() {
    const table = document.getElementById('welfareTable');
    if (!table) return;
    if (!entries.length) {
      table.innerHTML = '<tr><th>Date</th><th>Staff</th><th>Type</th><th>Amount</th><th>Status</th><th>Notes</th><th>Logged by</th><th></th></tr><tr><td colspan="8" style="color:var(--gray-mid);padding:1rem">No entries yet.</td></tr>';
      return;
    }
    const rows = entries.map((e) => {
      const actions = readOnly
        ? ''
        : `<button class="btn btn-sm" type="button" onclick="welfareEditEntry(${e.id})">Edit</button>
           <button class="btn btn-sm" type="button" onclick="welfareRemoveEntry(${e.id})">Remove</button>`;
      return `<tr>
        <td>${esc(e.date)}</td>
        <td>${esc(e.staff)}</td>
        <td>${esc(typeLabel(e.type))}</td>
        <td>${Number(e.amount || 0).toLocaleString()}</td>
        <td><span class="badge ${e.status === 'resolved' ? 'bs' : 'bw'}">${esc(e.status || 'open')}</span></td>
        <td style="max-width:200px;font-size:12px">${esc(e.notes || '—')}</td>
        <td style="font-size:12px;color:var(--gray-mid)">${esc(e.created_by_name || '—')}</td>
        <td style="white-space:nowrap">${actions}</td>
      </tr>`;
    }).join('');
    table.innerHTML = '<tr><th>Date</th><th>Staff</th><th>Type</th><th>Amount</th><th>Status</th><th>Notes</th><th>Logged by</th><th></th></tr>' + rows;
  }

  function setOpsHomeButton() {
    const page = document.getElementById('page-accountant-welfare');
    const btn = page?.querySelector('.rdc-bal-toolbar .btn');
    if (!btn || typeof currentUser === 'undefined' || !currentUser) return;
    const home = {
      accountant: 'accountant-rdc-hub',
      manager: 'manager-dashboard',
      executive: 'admin-dashboard',
      admin: 'admin-dashboard',
    }[currentUser.role] || 'accountant-rdc-hub';
    btn.onclick = () => showPage(home);
  }

  async function migrateLegacyIfNeeded() {
    const legacy = loadLegacyEntries();
    if (!legacy.length) return false;
    if (entries.length > 0) {
      clearLegacyEntries();
      return false;
    }
    for (const e of legacy) {
      await LapokAPI.post('/api/welfare/save.php', {
        date: e.date || new Date().toISOString().slice(0, 10),
        staff: e.staff || 'Unknown',
        type: e.type || 'request',
        amount: Number(e.amount || 0),
        status: e.status || 'open',
        notes: e.notes || '',
      });
    }
    clearLegacyEntries();
    toast('Imported ' + legacy.length + ' welfare entries from this device.');
    return true;
  }

  async function fetchEntries() {
    const data = await LapokAPI.get('/api/welfare/fetch.php');
    entries = data.entries || [];
    summary = data.summary || summary;
    readOnly = !!data.read_only;
    if (await migrateLegacyIfNeeded()) {
      const data2 = await LapokAPI.get('/api/welfare/fetch.php');
      entries = data2.entries || [];
      summary = data2.summary || summary;
      readOnly = !!data2.read_only;
    }
    updateSummaryChip();
    renderTable();
    applyReadOnlyUi();
  }

  function resetForm() {
    const editId = document.getElementById('welfareEditId');
    if (editId) editId.value = '';
    document.getElementById('welfareStaff').value = '';
    document.getElementById('welfareAmount').value = '';
    document.getElementById('welfareNotes').value = '';
    const btn = document.querySelector('#welfareFormCard .btn-red');
    if (btn) btn.textContent = 'Save entry';
  }

  async function loadAccountantWelfarePage() {
    setOpsHomeButton();
    const dateInp = document.getElementById('welfareDate');
    if (dateInp && !dateInp.value) {
      dateInp.value = new Date().toISOString().slice(0, 10);
    }
    try {
      await fetchEntries();
    } catch (e) {
      toast('Could not load welfare register: ' + e.message, true);
    }
  }

  async function welfareAddEntry() {
    if (readOnly) return;
    const staff = document.getElementById('welfareStaff')?.value.trim();
    if (!staff) {
      toast('Enter staff name.', true);
      return;
    }
    const payload = {
      id: Number(document.getElementById('welfareEditId')?.value || 0) || undefined,
      date: document.getElementById('welfareDate')?.value || new Date().toISOString().slice(0, 10),
      staff,
      type: document.getElementById('welfareType')?.value || 'request',
      amount: parseFloat(document.getElementById('welfareAmount')?.value) || 0,
      status: document.getElementById('welfareStatus')?.value || 'open',
      notes: document.getElementById('welfareNotes')?.value.trim() || '',
    };
    try {
      const res = await LapokAPI.post('/api/welfare/save.php', payload);
      toast(res.message || 'Welfare entry saved.');
      resetForm();
      await fetchEntries();
    } catch (e) {
      toast(e.message, true);
    }
  }

  function welfareEditEntry(id) {
    const e = entries.find((row) => row.id === id);
    if (!e) return;
    document.getElementById('welfareEditId').value = String(e.id);
    document.getElementById('welfareDate').value = e.date || '';
    document.getElementById('welfareStaff').value = e.staff || '';
    document.getElementById('welfareType').value = e.type || 'request';
    document.getElementById('welfareAmount').value = String(e.amount || 0);
    document.getElementById('welfareStatus').value = e.status || 'open';
    document.getElementById('welfareNotes').value = e.notes || '';
    const btn = document.querySelector('#welfareFormCard .btn-red');
    if (btn) btn.textContent = 'Update entry';
    document.getElementById('welfareFormCard')?.scrollIntoView({ behavior: 'smooth' });
  }

  async function welfareRemoveEntry(id) {
    if (readOnly || !confirm('Remove this entry?')) return;
    try {
      await LapokAPI.post('/api/welfare/delete.php', { id });
      toast('Welfare entry removed.');
      await fetchEntries();
    } catch (e) {
      toast(e.message, true);
    }
  }

  window.loadAccountantWelfarePage = loadAccountantWelfarePage;
  window.welfareAddEntry = welfareAddEntry;
  window.welfareEditEntry = welfareEditEntry;
  window.welfareRemoveEntry = welfareRemoveEntry;
})();
