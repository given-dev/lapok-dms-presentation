/**
 * LAPOK DMS — Presentation build API client (Manager · Accountant · Executive)
 */
const LapokAPI = (() => {
  const base = '';

  function resolvePath(path) {
    if (/^https?:\/\//i.test(path)) return path;
    if (!path.startsWith('/')) return base + path;
    const currentDir = window.location.pathname.replace(/\/[^/]*$/, '/').replace(/\/+$/, '');
    return currentDir + path;
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
    const res = await fetch(resolvePath(path), opts);
    let json;
    try {
      json = await res.json();
    } catch {
      throw new Error('Invalid server response');
    }
    if (!json.success) {
      throw new Error(json.error || 'Request failed');
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
    { id: 'accountant-rdc', l: 'RDC daily sheets', i: 'chart' },
    { section: 'Phase 2 — integrations' },
    { id: 'manager-fleet-map', l: 'Fleet map', i: 'map', placeholder: true },
    { id: 'manager-ccba-boards', l: 'CCBA daily boards', i: 'chart', placeholder: true },
    { id: 'manager-ccba-order', l: 'Order via CCBA', i: 'cart', placeholder: true },
    { id: 'admin-efris', l: 'EFRIS / URA', i: 'receipt', placeholder: true },
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
    navItems,
    roleNav: {
      manager: navManager,
      accountant: [
        { section: 'RDC office' },
        { id: 'accountant-rdc-hub', l: 'RDC overview', i: 'home' },
        { section: 'Accounting' },
        { id: 'accountant-rdc', l: 'Daily balancing', i: 'chart' },
        { id: 'accountant-cash', l: 'Cash handover', i: 'receipt' },
        { id: 'admin-customers', l: 'Receivables', i: 'custs' },
        { section: 'Depot oversight' },
        { id: 'admin-exceptions', l: 'Site monitoring', i: 'chart' },
        { id: 'manager-stock', l: 'Stock & assets', i: 'box' },
        { id: 'accountant-welfare', l: 'Staff welfare', i: 'users' },
        { section: 'Reports & coordination' },
        { id: 'manager-dashboard', l: 'Operations dashboard', i: 'home' },
        { id: 'report-exchange', l: 'PDF reports', i: 'receipt' },
        { id: 'manager-reports', l: 'Financial reports', i: 'chart' },
      ],
      executive: [
        { section: 'Overview' },
        { id: 'admin-dashboard', l: 'Executive dashboard', i: 'home' },
        { section: 'Reports' },
        { id: 'report-exchange', l: 'PDF reports', i: 'receipt' },
        { id: 'admin-reports', l: 'Reports & analytics', i: 'chart' },
        { section: 'Monitoring' },
        { id: 'admin-exceptions', l: 'Exception center', i: 'chart' },
        { id: 'admin-customers', l: 'Receivables overview', i: 'custs' },
        { section: 'Phase 2 — integrations' },
        { id: 'admin-efris', l: 'EFRIS / URA', i: 'receipt', placeholder: true },
      ],
    },
    rolePill: {
      executive: 'rp-admin',
      manager: 'rp-manager',
      accountant: 'rp-manager',
    },
    roleLabel: {
      executive: 'Executive',
      manager: 'Manager',
      accountant: 'Accountant (RDC)',
    },
  };
})();
