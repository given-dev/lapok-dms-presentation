/**
 * Placeholder pages for integrations not included in the LAPOK presentation build.
 */
const INTEGRATION_PLACEHOLDERS = {
  'manager-fleet-map': {
    title: 'Fleet map (GPS tracking)',
    phase: 'Phase 2',
    icon: '🗺️',
    summary: 'Live vehicle positions from cadet phones on route. Feeds the manager fleet map and route progress.',
    includes: ['Leaflet map', 'GPS pings from field devices', 'Route vs actual path', 'Vehicle status chips'],
  },
  'manager-ccba-boards': {
    title: 'CCBA daily boards',
    phase: 'Phase 2',
    icon: '📋',
    summary: 'Digitized inventory and OCCD whiteboards — daily fill aligned with CCBA reporting.',
    includes: ['Inventory board by SKU', 'OCCD dashboard', 'Draft / submit workflow', 'Date-based history'],
  },
  'manager-ccba-order': {
    title: 'Order via MyCCBA',
    phase: 'Phase 2',
    icon: '🛒',
    summary: 'Low-stock replenishment orders from Lapok into CCBA Uganda portal / API.',
    includes: ['Suggested order lines from warehouse levels', 'SKU mapping', 'Order status timeline', 'Delivery receipt link'],
  },
  'admin-efris': {
    title: 'EFRIS / URA fiscal receipts',
    phase: 'Phase 2',
    icon: '🏛️',
    summary: 'Fiscal-first import from field cash registers — cadets sell on device; Lapok links customer and stock.',
    includes: ['Device webhook ingest', 'Product code mapping', 'Pending receipt queue', 'Auto order + trip stock update'],
  },
};

function renderIntegrationPlaceholder(pageId) {
  const cfg = INTEGRATION_PLACEHOLDERS[pageId];
  if (!cfg) return;
  const root = document.getElementById('page-' + pageId);
  if (!root) return;

  root.innerHTML = `
    <div class="card" style="text-align:center;padding:2.5rem 1.5rem;max-width:560px;margin:0 auto">
      <div style="font-size:52px;margin-bottom:1rem">${cfg.icon}</div>
      <div class="card-title" style="font-size:18px;margin-bottom:.5rem">${cfg.title}</div>
      <span class="badge bg" style="margin-bottom:1rem;display:inline-block">${cfg.phase} — coming in full build</span>
      <p style="color:var(--gray-mid);margin-bottom:1.25rem;line-height:1.6">${cfg.summary}</p>
      <div style="text-align:left;background:var(--surface);border:1px solid var(--gray-light);border-radius:8px;padding:1rem">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-mid);margin-bottom:8px">Included in full system</div>
        <ul style="margin:0;padding-left:1.2rem;color:var(--gray-dark);font-size:13px;line-height:1.8">
          ${cfg.includes.map((i) => `<li>${i}</li>`).join('')}
        </ul>
      </div>
      <p style="font-size:12px;color:var(--gray-mid);margin-top:1.25rem">This presentation shows core warehouse, finance, and reporting workflows. Integrations are scoped for the next delivery phase.</p>
    </div>`;
}

document.addEventListener('DOMContentLoaded', () => {
  const hook = window.showPage;
  if (typeof hook !== 'function') return;

  Object.keys(INTEGRATION_PLACEHOLDERS).forEach(renderIntegrationPlaceholder);

  window.showPage = function (id) {
    hook(id);
    if (INTEGRATION_PLACEHOLDERS[id]) renderIntegrationPlaceholder(id);
  };
});
