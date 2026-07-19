/**
 * Outpost DMS — Depot Management System (multi-depot product)
 * This presentation build wires Admin · Executive · Manager · Accountant · Cadet.
 * Tenant accounts use the @lapok.ug domain in this installation.
 */
window.OUTPOST_PRODUCT_NAME = 'Outpost DMS';
window.OUTPOST_PRODUCT_TAGLINE = 'Depot Management System';
window.LAPOK_PRESENTATION = true; // legacy flag kept for older scripts
window.LAPOK_ACCOUNTANT_MODULE_LIVE = true;
window.LAPOK_ALLOWED_ROLES = ['admin', 'manager', 'accountant', 'executive', 'cadet'];
window.LAPOK_DISABLED_ROLES = ['driver'];
window.LAPOK_API_ROOT = (() => {
  const path = window.location.pathname || '';
  const idx = path.indexOf('/index.html');
  if (idx > 0) return path.slice(0, idx);
  return path.replace(/\/[^/]*$/, '') || '';
})();
