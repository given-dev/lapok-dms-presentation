/**
 * Outpost DMS — Presentation build API client (Admin · Executive · Manager · Accountant)
 */
const LapokAPI = (() => {
  const base = '';

  function detectApiRoot() {
    if (window.LAPOK_API_ROOT) return window.LAPOK_API_ROOT;
    const path = window.location.pathname || '';
    const idx = path.indexOf('/index.html');
    if (idx > 0) return path.slice(0, idx);
    return path.replace(/\/[^/]*$/, '') || '';
  }

  function resolvePath(path) {
    if (/^https?:\/\//i.test(path)) return path;
    const root = detectApiRoot();
    if (path.startsWith('/')) return root + path;
    return (root ? root + '/' : '') + path;
  }

  async function request(method, path, body) {
    const opts = {
      method,
      credentials: 'include',
      headers: { Accept: 'application/json' },
    };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const url = resolvePath(path);
    const res = await fetch(url, opts);
    const raw = await res.text();
    let json;
    try {
      json = raw ? JSON.parse(raw) : null;
    } catch {
      const hint = raw && raw.trim().startsWith('<') ? ' (server returned HTML — check API URL or PHP errors)' : '';
      throw new Error('Invalid server response' + hint);
    }
    if (!json || !json.success) {
      throw new Error(json?.error || `Request failed (${res.status})`);
    }
    return json.data;
  }

  const navManager = [
    { section: 'Daily' },
    { id: 'manager-dashboard', l: 'Dashboard', i: 'home' },
    { id: 'manager-stock', l: 'Stock taking', i: 'box' },
    { id: 'manager-ccba-boards', l: 'CCBA boards', i: 'chart' },
    { id: 'manager-rdc-review', l: 'RDC daily sheets', i: 'chart' },
    { id: 'report-exchange', l: 'PDF reports', i: 'receipt' },
    { section: 'Approvals' },
    { id: 'admin-exceptions', l: 'Exception center', i: 'chart' },
    { id: 'admin-editreqs', l: 'Edit requests', i: 'edit' },
    { section: 'Business' },
    { id: 'admin-customers', l: 'Customers & receivables', i: 'custs' },
    { id: 'manager-ccba-order', l: 'Order via MyCCBA', i: 'box' },
    { id: 'manager-reports', l: 'Reports & analytics', i: 'chart' },
    { section: 'Monthly' },
    { id: 'accountant-improvements', l: 'Month-end', i: 'chart' },
    { id: 'accountant-welfare', l: 'Staff welfare', i: 'edit' },
  ];

  function navItems(nav) {
    return nav.filter((n) => n.id);
  }

  return {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body),
    formatUgx: (n) => 'UGX ' + Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 }),
    /** Full digits with thousand separators — supports at least 9-digit amounts (e.g. 999,999,999). */
    formatM: (n) => Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 }),
    formatDigits: (n) => Number(n || 0).toLocaleString('en-UG', { maximumFractionDigits: 0 }),
    formatDate: (s) => {
      if (!s) return '—';
      return new Date(s).toLocaleDateString('en-UG', { day: '2-digit', month: 'short', year: 'numeric' });
    },
    formatTime: (s) => {
      if (!s) return '—';
      return new Date(s).toLocaleTimeString('en-UG', { hour: '2-digit', minute: '2-digit' });
    },
    progressClass: (pct) => (pct < 30 ? 'p-red' : pct < 60 ? 'p-amber' : 'p-green'),
    /** Server-branded Excel (logo + Outpost DMS header). Optional ?format=csv still available. */
    exportCsv: (type, params = '') => {
      window.open(resolvePath('/api/reports/export_csv.php?type=' + encodeURIComponent(type) + params), '_blank');
    },
    exportRdcSheet: (date) => {
      const d = date || new Date().toISOString().slice(0, 10);
      window.open(resolvePath('/api/reports/export_csv.php?type=rdc_sheet&date=' + encodeURIComponent(d)), '_blank');
    },
    /**
     * Client-side branded Excel download (for in-browser tables).
     * @param {{ title: string, subtitle?: string, headers: string[], rows: any[][], filename?: string, meta?: Record<string,string> }} opts
     */
    downloadBrandedExcel: async (opts) => {
      const title = opts.title || 'Export';
      const subtitle = opts.subtitle || 'Official depot export';
      const headers = opts.headers || [];
      const rows = opts.rows || [];
      const meta = opts.meta || {};
      let filename = opts.filename || (`Outpost-DMS-${title.replace(/[^a-zA-Z0-9]+/g, '-')}-${new Date().toISOString().slice(0, 10)}.xls`);
      // Client-side HTML Excel cannot embed logos (Excel blocks them) — use OD mark.
      filename = filename.replace(/\.xlsx$/i, '.xls');
      const esc = (v) => String(v ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

      const logoHtml = '<div style="width:56px;height:56px;border-radius:10px;background:#E53E3E;color:#fff;text-align:center;line-height:56px;font-weight:700;font-family:Arial,sans-serif">OD</div>';

      const metaHtml = Object.entries(meta).map(([k, v]) =>
        `<tr><td style="padding:2px 0;color:#64748B;font-size:10pt;width:120px">${esc(k)}</td><td style="padding:2px 0;color:#0F172A;font-size:10pt;font-weight:600">${esc(v)}</td></tr>`
      ).join('');

      const th = headers.map((h) =>
        `<td style="padding:8px 10px;border:1px solid #C53030;background:#E53E3E;color:#fff;font-weight:700;font-family:Calibri,Arial,sans-serif;font-size:11pt">${esc(h)}</td>`
      ).join('');

      const body = rows.length
        ? rows.map((r, i) => {
          const bg = i % 2 === 0 ? '#FFFFFF' : '#F8FAFC';
          const cells = r.map((c) => {
            const n = typeof c === 'number' || (typeof c === 'string' && c !== '' && !Number.isNaN(Number(c)) && !/^0\d+/.test(c));
            const text = n ? Number(c).toLocaleString('en-UG') : (c == null || c === '' ? '—' : c);
            return `<td style="padding:8px 10px;border:1px solid #E2E8F0;font-family:Calibri,Arial,sans-serif;font-size:11pt;text-align:${n ? 'right' : 'left'}">${esc(text)}</td>`;
          }).join('');
          return `<tr style="background:${bg}">${cells}</tr>`;
        }).join('')
        : `<tr><td colspan="${Math.max(1, headers.length)}" style="padding:16px;color:#94A3B8;text-align:center;border:1px solid #E2E8F0">No rows for this export.</td></tr>`;

      const when = new Date().toLocaleString('en-UG', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
      const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>OUTPOST DMS — ${esc(title)}</title></head>
<body style="margin:0;background:#fff">
<table style="width:100%;border-collapse:collapse;margin-bottom:12px;font-family:Calibri,Arial,sans-serif">
  <tr><td colspan="2" style="height:6px;background:#E53E3E;font-size:1px">&nbsp;</td></tr>
  <tr>
    <td style="width:72px;padding:14px 12px 10px 14px;background:#0F172A;vertical-align:middle">${logoHtml}</td>
    <td style="padding:14px 16px 10px 8px;background:#0F172A;vertical-align:middle">
      <div style="font-size:18pt;font-weight:700;color:#fff;letter-spacing:.5px">OUTPOST DMS</div>
      <div style="font-size:10pt;color:#94A3B8;margin-top:2px">Depot Management System</div>
    </td>
  </tr>
  <tr><td colspan="2" style="padding:14px 16px 4px 16px;background:#F8FAFC">
    <div style="font-size:14pt;font-weight:700;color:#0F172A">${esc(title)}</div>
    <div style="font-size:10pt;color:#64748B;margin-top:3px">${esc(subtitle)}</div>
  </td></tr>
  <tr><td colspan="2" style="padding:6px 16px 14px 16px;background:#F8FAFC"><table style="border-collapse:collapse">${metaHtml}</table></td></tr>
</table>
<table style="border-collapse:collapse;width:100%"><thead><tr>${th}</tr></thead><tbody>${body}</tbody></table>
<p style="margin:16px;font-size:9pt;color:#94A3B8;font-family:Calibri,Arial,sans-serif">Generated by Outpost DMS · ${esc(when)}</p>
</body></html>`;

      const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = filename.endsWith('.xls') ? filename : `${filename}.xls`;
      a.click();
      URL.revokeObjectURL(a.href);
    },
    navItems,
    roleNav: {
      admin: [
        { section: 'Overview' },
        { id: 'admin-dashboard', l: 'Admin dashboard', i: 'home' },
        { section: 'Administration' },
        { id: 'admin-users', l: 'User management', i: 'users' },
        { id: 'admin-audit', l: 'Audit log', i: 'edit' },
        { section: 'Operations' },
        { id: 'admin-customers', l: 'Customers & receivables', i: 'custs' },
        { id: 'admin-editreqs', l: 'Edit requests', i: 'edit' },
        { id: 'admin-exceptions', l: 'Exception center', i: 'chart' },
        { section: 'Reports' },
        { id: 'report-exchange', l: 'PDF reports', i: 'receipt' },
        { id: 'admin-reports', l: 'Reports & analytics', i: 'chart' },
        { section: 'RDC / depot' },
        { id: 'accountant-improvements', l: 'Month-end', i: 'chart' },
        { id: 'accountant-welfare', l: 'Staff welfare', i: 'edit' },
      ],
      manager: navManager,
      accountant: [
        { section: 'Today' },
        { id: 'accountant-rdc-hub', l: 'Home', i: 'home' },
        { id: 'accountant-rdc', l: "Today's close", i: 'chart' },
        { section: 'More' },
        { id: 'accountant-cash', l: 'Cash handover', i: 'receipt' },
        { id: 'accountant-improvements', l: 'Month-end', i: 'chart' },
        { id: 'accountant-welfare', l: 'Staff welfare', i: 'edit' },
        { id: 'admin-exceptions', l: 'Depot alerts', i: 'chart' },
      ],
      executive: [
        { section: 'Overview' },
        { id: 'admin-dashboard', l: 'Executive dashboard', i: 'home' },
        { id: 'director-brief', l: 'Director brief', i: 'chart' },
        { section: 'Reports' },
        { id: 'report-exchange', l: 'PDF reports', i: 'receipt' },
        { id: 'admin-reports', l: 'Reports & analytics', i: 'chart' },
        { section: 'Monitoring' },
        { id: 'admin-exceptions', l: 'Exception center', i: 'chart' },
        { id: 'admin-customers', l: 'Receivables overview', i: 'custs' },
        { id: 'accountant-welfare', l: 'Staff welfare', i: 'edit' },
        { id: 'accountant-improvements', l: 'Month-end', i: 'chart' },
      ],
      field_user: [
        { section: 'Access' },
        { id: 'manager-dashboard', l: 'Limited dashboard', i: 'home' },
      ],
      cadet: [
        { id: 'cadet-dashboard', l: 'Dashboard', i: 'home' },
        { id: 'cadet-daily', l: "Today's report", i: 'eod' },
      ],
    },
    rolePill: {
      admin: 'rp-admin',
      executive: 'rp-admin',
      manager: 'rp-manager',
      accountant: 'rp-manager',
      field_user: 'rp-user',
      cadet: 'rp-user',
    },
    roleLabel: {
      admin: 'Admin',
      executive: 'Executive',
      manager: 'Manager',
      accountant: 'Accountant (RDC)',
      field_user: 'Field user',
      cadet: 'Cadet',
    },
    roleHomePage: {
      accountant: 'accountant-rdc-hub',
      cadet: 'cadet-dashboard',
      manager: 'manager-dashboard',
      executive: 'admin-dashboard',
      admin: 'admin-dashboard',
    },
    /**
     * Ownership map (see docs/SYSTEMS_BUILDING_GUIDE.md §9).
     * Wrong-role deep-links bounce home — intentional security.
     * Managers may still open accountant-rdc to view/edit a sheet during review.
     */
    rolePageOwner: {
      'accountant-rdc-hub': 'RDC',
      'accountant-rdc': 'RDC',
      'accountant-cash': 'RDC',
      'manager-dashboard': 'Manager',
      'manager-stock': 'Manager',
      'manager-rdc-review': 'Manager',
      'manager-ccba-boards': 'Manager',
      'manager-ccba-order': 'Manager',
      'admin-users': 'Admin',
      'admin-audit': 'Admin',
      'admin-editreqs': 'Manager / Admin',
      'admin-customers': 'Manager',
      'cadet-dashboard': 'Cadet',
      'cadet-daily': 'Cadet',
    },
    roleBlockedPages: {
      cadet: [
        'accountant-rdc-hub', 'accountant-rdc', 'accountant-cash',
        'accountant-improvements', 'accountant-welfare',
        'manager-dashboard', 'manager-stock', 'manager-rdc-review',
        'manager-ccba-boards', 'manager-ccba-order',
        'admin-dashboard', 'admin-users', 'admin-editreqs', 'admin-exceptions',
        'admin-customers', 'admin-reports', 'admin-audit',
        'director-brief', 'report-exchange',
      ],
      accountant: [
        'manager-dashboard', 'manager-stock', 'manager-rdc-review',
        'manager-ccba-boards', 'manager-ccba-order',
        'admin-users', 'admin-audit', 'admin-editreqs', 'admin-customers',
      ],
      manager: [
        'accountant-rdc-hub', 'accountant-cash',
        'admin-users', 'admin-audit',
        'cadet-dashboard', 'cadet-daily',
      ],
      executive: [
        'accountant-rdc-hub', 'accountant-cash', 'accountant-rdc',
        'manager-dashboard', 'manager-stock', 'manager-rdc-review',
        'manager-ccba-boards', 'manager-ccba-order',
        'admin-users', 'admin-editreqs', 'admin-audit',
        'cadet-dashboard', 'cadet-daily',
      ],
    },
  };
})();
