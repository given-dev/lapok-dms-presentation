/**
 * LAPOK DMS — Fiscal-first EFRIS import (device → Lapok)
 */

let efrisActiveReceiptId = null;
let efrisSelectedCustomerId = null;
let efrisCustomersCache = [];

function efrisStatusBadge(status) {
  const map = {
    pending_link: 'bw',
    unmapped: 'bd',
    linked: 'bs',
    ignored: 'bg',
  };
  return `<span class="badge ${map[status] || 'bg'}">${status.replace('_', ' ')}</span>`;
}

async function loadFiscalReceiptsPage() {
  const listEl = document.getElementById('efrisPendingList');
  const detailEl = document.getElementById('efrisReceiptDetail');
  if (!listEl) return;

  listEl.innerHTML = '<p style="color:var(--gray-mid)">Loading fiscal receipts…</p>';
  if (detailEl) detailEl.innerHTML = '<p style="color:var(--gray-mid)">Select a receipt from the fiscal device to link it in Outpost.</p>';

  try {
    const [pending, customerData] = await Promise.all([
      LapokAPI.get('/api/efris/fetch_pending.php?status=pending'),
      LapokAPI.get('/api/customers/fetch_customers.php'),
    ]);

    efrisCustomersCache = customerData.customers || [];

    const sel = document.getElementById('efrisCustomerSelect');
    if (sel) {
      const opts = ['<option value="">— Select customer —</option>'];
      efrisCustomersCache.forEach((c) => {
        opts.push(`<option value="${c.id}"${efrisSelectedCustomerId === c.id ? ' selected' : ''}>${c.name}</option>`);
      });
      sel.innerHTML = opts.join('');
    }

    const receipts = pending.receipts || [];
    const badge = document.getElementById('efrisPendingBadge');
    if (badge) badge.textContent = `${pending.pending_count || 0} pending`;

    if (!receipts.length) {
      listEl.innerHTML = '<div class="alert a-info"><span>ℹ</span>No fiscal receipts waiting. Complete a sale on the cash register — it will appear here automatically.</div>';
      return;
    }

    listEl.innerHTML = receipts.map((r) => {
      const canLink = ['pending_link', 'unmapped'].includes(r.status);
      return `<div class="cust-card" style="cursor:pointer;margin-bottom:8px" onclick="efrisShowReceipt(${r.id})">
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
          <div>
            <div class="cust-name">${r.efris_invoice_no}</div>
            <div class="cust-detail">${LapokAPI.formatTime(r.fiscal_timestamp)} · ${LapokAPI.formatUgx(r.amount_total)}</div>
            <div class="cust-detail" style="margin-top:3px">${(r.items || []).map((i) => i.product_name + ' ×' + i.qty).join(', ') || '—'}</div>
          </div>
          ${efrisStatusBadge(r.status)}
        </div>
        ${canLink ? '<div style="margin-top:8px"><button class="btn btn-sm btn-red" onclick="event.stopPropagation();efrisShowReceipt(' + r.id + ')">Link to customer</button></div>' : ''}
      </div>`;
    }).join('');
  } catch (e) {
    listEl.innerHTML = `<div class="alert a-danger"><span>⚠</span>${e.message}</div>`;
  }
}

async function efrisShowReceipt(receiptId) {
  efrisActiveReceiptId = receiptId;
  const detailEl = document.getElementById('efrisReceiptDetail');
  if (!detailEl) return;

  try {
    const data = await LapokAPI.get('/api/efris/fetch_pending.php?status=all&limit=100');
    const receipt = (data.receipts || []).find((r) => r.id === receiptId);
    if (!receipt) {
      detailEl.innerHTML = '<div class="alert a-danger">Receipt not found.</div>';
      return;
    }

    const lines = (receipt.items || []).map(
      (i) => `<tr>
        <td>${i.product_name}</td>
        <td>${i.qty}</td>
        <td>${Number(i.unit_price).toLocaleString()}</td>
        <td>${Number(i.subtotal).toLocaleString()}</td>
        <td><span class="badge ${i.map_status === 'mapped' ? 'bs' : 'bd'}">${i.map_status}</span></td>
      </tr>`
    ).join('');

    const linked = receipt.status === 'linked';
    const canLink = ['pending_link', 'unmapped'].includes(receipt.status);

    detailEl.innerHTML = `
      <div class="card-header" style="margin-bottom:.8rem">
        <span class="card-title">URA ${receipt.efris_invoice_no}</span>
        ${efrisStatusBadge(receipt.status)}
      </div>
      <div class="recon-row"><span>Fiscal time</span><strong>${LapokAPI.formatTime(receipt.fiscal_timestamp)}</strong></div>
      <div class="recon-row"><span>Total</span><strong>${LapokAPI.formatUgx(receipt.amount_total)}</strong></div>
      <div class="recon-row"><span>Payment</span><strong>${receipt.payment_type}</strong></div>
      ${linked ? `<div class="recon-row"><span>Lapok order</span><strong>${receipt.order_ref || '—'}</strong></div>` : ''}
      <hr class="divider">
      <div class="tbl-wrap"><table>
        <tr><th>Product</th><th>Qty</th><th>Unit</th><th>Subtotal</th><th>Map</th></tr>
        ${lines}
      </table></div>
      ${canLink ? `
        <div class="alert a-info" style="margin-top:1rem"><span>ℹ</span>Sale already happened on the fiscal device. Pick the customer to record it in Outpost — no need to re-enter products.</div>
        <div class="form-group" style="margin-top:.8rem">
          <label>Customer</label>
          <select class="select-inp" id="efrisCustomerSelectDetail" onchange="efrisSelectedCustomerId=parseInt(this.value)||null">
            <option value="">— Select customer —</option>
            ${efrisCustomersCache.map((c) => `<option value="${c.id}"${efrisSelectedCustomerId === c.id ? ' selected' : ''}>${c.name}</option>`).join('')}
          </select>
        </div>
        <button class="btn btn-red btn-full" style="margin-top:.8rem" onclick="confirmFiscalReceipt()" ${receipt.status === 'unmapped' ? 'disabled title="Unmapped products — ask admin"' : ''}>Record in Outpost</button>
      ` : ''}
    `;
  } catch (e) {
    detailEl.innerHTML = `<div class="alert a-danger">${e.message}</div>`;
  }
}

async function confirmFiscalReceipt() {
  const sel = document.getElementById('efrisCustomerSelectDetail') || document.getElementById('efrisCustomerSelect');
  const customerId = sel ? parseInt(sel.value, 10) : efrisSelectedCustomerId;
  if (!efrisActiveReceiptId || !customerId) {
    alert('Select a customer first.');
    return;
  }
  try {
    const r = await LapokAPI.post('/api/efris/confirm.php', {
      receipt_id: efrisActiveReceiptId,
      customer_id: customerId,
    });
    alert(`Recorded in Outpost as ${r.order_ref}\nURA ref: ${r.efris_ref}`);
    efrisActiveReceiptId = null;
    await loadFiscalReceiptsPage();
    if (typeof loadFieldDashboard === 'function') loadFieldDashboard();
  } catch (e) {
    alert(e.message);
  }
}

function efrisSetCustomer(customerId, customerName) {
  efrisSelectedCustomerId = customerId;
  const sel = document.getElementById('efrisCustomerSelect');
  if (sel) sel.value = String(customerId);
  showPage('user-receipt');
}

async function loadEfrisAdminPage() {
  const root = document.getElementById('efrisAdminRoot');
  if (!root) return;

  root.innerHTML = '<p style="color:var(--gray-mid)">Loading EFRIS settings…</p>';

  try {
    const data = await LapokAPI.get('/api/efris/config.php');
    const cfg = data.config || {};
    const maps = data.product_maps || [];

    const unmapped = maps.filter((m) => !m.mapped).length;

    root.innerHTML = `
      <div class="alert a-info"><span>ℹ</span><strong>Fiscal-first mode:</strong> Cadets sell on the cash register. Receipts import into Lapok automatically — they only pick the customer to link stock and reports.</div>
      <div class="metric-grid" style="margin-bottom:1rem">
        <div class="metric-card"><div class="metric-label">Mode</div><div class="metric-value" style="font-size:14px">${cfg.integration_mode || 'fiscal_first'}</div></div>
        <div class="metric-card"><div class="metric-label">Seller TIN</div><div class="metric-value" style="font-size:14px">${cfg.seller_tin || '—'}</div></div>
        <div class="metric-card"><div class="metric-label">Device ingest</div><div class="metric-value" style="font-size:14px">${cfg.ingest_configured ? 'Ready' : 'Not set'}</div></div>
        <div class="metric-card"><div class="metric-label">Unmapped SKUs</div><div class="metric-value">${unmapped}</div></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Device webhook</span></div>
        <p style="font-size:13px;color:var(--gray-mid);margin-bottom:.8rem">POST completed sales to <code>${data.ingest_endpoint}</code> with header <code>X-EFRIS-KEY</code>. Set <code>ingest_api_key</code> in the database.</p>
        <button class="btn btn-sm" onclick="testEfrisIngest()">Send test receipt</button>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Product mapping</span><span class="chip">Lapok ↔ fiscal item code</span></div>
        <div class="tbl-wrap"><table>
          <tr><th>Product</th><th>SKU</th><th>EFRIS item code</th><th>Action</th></tr>
          ${maps.map((m) => `<tr>
            <td>${m.name}</td>
            <td style="font-family:monospace;font-size:11px">${m.sku}</td>
            <td><input class="input" style="min-height:36px;padding:6px 8px" id="efrisCode${m.product_id}" value="${m.efris_item_code || ''}" placeholder="URA item code"></td>
            <td><button class="btn btn-sm" onclick="saveEfrisProductMap(${m.product_id})">Save</button></td>
          </tr>`).join('')}
        </table></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Recent fiscal imports</span></div>
        <div id="efrisAdminRecent"></div>
      </div>
    `;

    const recent = await LapokAPI.get('/api/efris/fetch_pending.php?status=all&limit=20');
    const recEl = document.getElementById('efrisAdminRecent');
    if (recEl) {
      const rows = (recent.receipts || []).map(
        (r) => `<tr>
          <td style="font-family:monospace;font-size:11px">${r.efris_invoice_no}</td>
          <td>${LapokAPI.formatTime(r.fiscal_timestamp)}</td>
          <td>${LapokAPI.formatUgx(r.amount_total)}</td>
          <td>${efrisStatusBadge(r.status)}</td>
          <td>${r.customer_name || '—'}</td>
        </tr>`
      ).join('');
      recEl.innerHTML = `<div class="tbl-wrap"><table>
        <tr><th>URA invoice</th><th>Time</th><th>Amount</th><th>Status</th><th>Customer</th></tr>
        ${rows || '<tr><td colspan="5">No imports yet</td></tr>'}
      </table></div>`;
    }
  } catch (e) {
    root.innerHTML = `<div class="alert a-danger">${e.message}</div>`;
  }
}

async function saveEfrisProductMap(productId) {
  const inp = document.getElementById('efrisCode' + productId);
  const code = inp ? inp.value.trim() : '';
  if (!code) return alert('Enter an EFRIS item code');
  try {
    await LapokAPI.post('/api/efris/save_product_map.php', {
      product_id: productId,
      efris_item_code: code,
    });
    alert('Mapping saved.');
    loadEfrisAdminPage();
  } catch (e) {
    alert(e.message);
  }
}

async function testEfrisIngest() {
  const ref = 'URA-TEST-' + Date.now();
  try {
    await LapokAPI.post('/api/efris/ingest.php', {
      efris_invoice_no: ref,
      fiscal_timestamp: new Date().toISOString().slice(0, 19).replace('T', ' '),
      payment_type: 'cash',
      items: [
        { efris_item_code: 'COKE500', item_name: 'Coke 500ml', qty: 2, unit_price: 20000 },
      ],
    });
    alert('Test receipt imported: ' + ref);
    loadEfrisAdminPage();
  } catch (e) {
    alert(e.message);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const hook = window.showPage;
  if (typeof hook !== 'function') return;

  const pages = {
    'user-receipt': () => loadFiscalReceiptsPage(),
    'admin-efris': () => loadEfrisAdminPage(),
  };

  const prev = hook;
  window.showPage = function (id) {
    prev(id);
    if (pages[id]) pages[id]();
  };
});

window.efrisSetCustomer = efrisSetCustomer;
window.efrisShowReceipt = efrisShowReceipt;
window.confirmFiscalReceipt = confirmFiscalReceipt;
window.saveEfrisProductMap = saveEfrisProductMap;
window.testEfrisIngest = testEfrisIngest;
