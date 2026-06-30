/**
 * LAPOK DMS — Presentation build API client (Admin · Executive · Manager · Accountant)
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
    { section: 'Overview' },
    { id: 'manager-dashboard', l: 'Dashboard', i: 'home' },
    { section: 'Approvals' },
    { id: 'admin-editreqs', l: 'Edit requests', i: 'edit' },
    { id: 'admin-exceptions', l: 'Exception center', i: 'chart' },
    { section: 'Operations' },
    { id: 'admin-customers', l: 'Customers & receivables', i: 'custs' },
    { id: 'manager-stock', l: 'Stock & deliveries', i: 'box' },
    { section: 'Reports' },
    { id: 'report-exchange', l: 'PDF reports', i: 'receipt' },
    { id: 'manager-reports', l: 'Reports & analytics', i: 'chart' },
    { section: 'RDC / finance' },
    { id: 'manager-rdc-review', l: 'RDC daily sheets', i: 'chart' },
    { id: 'accountant-improvements', l: 'Month-end', i: 'chart' },
    { id: 'accountant-welfare', l: 'Staff welfare', i: 'edit' },
  ];

  function navItems(nav) {
    return nav.filter((n) => n.id);
  }

  return {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body),
    formatUgx: (n) => 'UGX ' + Number(n || 0).toLocaleString(),
    formatM: (n) => {
      const v = Number(n || 0);
      if (v >= 1e6) return (v / 1e6).toFixed(1) + 'M';
      if (v >= 1e3) return (v / 1e3).toFixed(0) + 'K';
      return v.toLocaleString();
    },
    formatDate: (s) => {
      if (!s) return '—';
      return new Date(s).toLocaleDateString('en-UG', { day: '2-digit', month: 'short', year: 'numeric' });
    },
    formatTime: (s) => {
      if (!s) return '—';
      return new Date(s).toLocaleTimeString('en-UG', { hour: '2-digit', minute: '2-digit' });
    },
    progressClass: (pct) => (pct < 30 ? 'p-red' : pct < 60 ? 'p-amber' : 'p-green'),
    exportCsv: (type, params = '') => {
      window.open(resolvePath('/api/reports/export_csv.php?type=' + encodeURIComponent(type) + params), '_blank');
    },
    exportRdcSheet: (date) => {
      const d = date || new Date().toISOString().slice(0, 10);
      window.open(resolvePath('/api/reports/export_csv.php?type=rdc_sheet&date=' + encodeURIComponent(d)), '_blank');
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
    },
  };
})();
