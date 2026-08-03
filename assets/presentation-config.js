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

// One-day override: suppress time-window alerts (closing stock locks / "due by 6:30 PM"
// style messages) on this date only, because data was entered late. The matching alerts
// come back automatically the next day — just delete this line when you no longer need it.
window.SUPPRESS_TIME_ALERTS_ON = '2026-08-03';

window.suppressTimeAlertsToday = function () {
  const target = String(window.SUPPRESS_TIME_ALERTS_ON || '');
  if (!target) return false;
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}` === target;
};
