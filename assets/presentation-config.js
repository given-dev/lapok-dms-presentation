/**
 * LAPOK DMS — Presentation build (accountant module live)
 * Core scope: Admin · Executive · Manager · Accountant.
 * Integrations are deferred in this version (website integration will be added later).
 */
window.LAPOK_PRESENTATION = true;
window.LAPOK_ACCOUNTANT_MODULE_LIVE = true;
window.LAPOK_ALLOWED_ROLES = ['admin', 'manager', 'accountant', 'executive', 'cadet'];
window.LAPOK_DISABLED_ROLES = ['driver'];
window.LAPOK_API_ROOT = (() => {
  const path = window.location.pathname || '';
  const idx = path.indexOf('/index.html');
  if (idx > 0) return path.slice(0, idx);
  return path.replace(/\/[^/]*$/, '') || '';
})();
