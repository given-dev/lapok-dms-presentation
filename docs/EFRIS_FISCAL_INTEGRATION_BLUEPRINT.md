# Lapok DMS ↔ Fiscal Cash Register & EFRIS Integration Blueprint

**Version:** 1.0 (draft)  
**Date:** June 2026  
**Audience:** Lapok leadership, field managers, cadets, developers, URA/EFD vendor  
**Goal:** Cadets sell **once on the fiscal device**; Lapok records stock, customer, and cash **without re-entering products or amounts**.

---

## 1. Executive summary

In Uganda, taxable sales must be registered with **URA** through **EFRIS** (Electronic Fiscal Receipting and Invoicing Solution). Field cadets use a **fiscal cash register** (EFD — Electronic Fiscal Device): a small machine with a screen and thermal printer that issues official receipts.

| Principle | Decision |
|-----------|----------|
| **Primary workflow** | **Fiscal-first** — ring sale on device; Lapok imports the receipt |
| **Tax authority of record** | **URA / EFRIS** — fiscal invoice number is legally authoritative |
| **Operations of record** | **Lapok** — stock per trip, customer, cash handover, manager reports |
| **Cadet double entry** | Eliminated for line items; cadet only **picks customer** in Lapok |
| **Phase 1 integration** | Device webhook → Lapok ingest (scaffold **built** in full repo) |
| **Phase 2** | Direct EFRIS API polling / QR decode if vendor supports |

---

## 2. What we know — fiscal devices & EFRIS (Uganda)

### 2.1 Fiscal cash register (field device)

Typical characteristics of devices used by distributors and retailers in East Africa:

| Component | Description |
|-----------|-------------|
| **Hardware** | Compact unit — touchscreen, built-in thermal printer, sometimes 4G/Wi‑Fi |
| **Firmware** | Locked-down POS; products, prices, TIN configured per outlet |
| **Output** | Fiscal receipt with **invoice/receipt number**, seller **TIN**, often **QR code** |
| **Compliance** | Writes to fiscal memory; reports to URA via EFRIS backend |
| **Field use** | Cadet on tuktuk/truck rings sale at customer shop → prints receipt → customer keeps copy |

**Lapok does not replace the device** for the legal sale. Lapok **mirrors** the transaction for operations.

### 2.2 EFRIS (URA)

| Topic | Finding |
|-------|---------|
| **Full name** | Electronic Fiscal Receipting and Invoicing Solution |
| **Authority** | Uganda Revenue Authority (URA) |
| **Purpose** | Real-time / near-real-time fiscal document registration |
| **Documents** | Fiscal receipts, invoices, credit notes (as applicable) |
| **Identifiers** | Invoice/receipt number, device serial, seller TIN, verification code / QR |
| **Integration** | Via **certified EFD vendor** middleware and/or **URA EFRIS API** (credentials per TIN) |

### 2.3 What is NOT confirmed for LAPOK yet (**TBD with vendor / URA**)

- Exact **device brand/model** deployed to cadets (e.g. Injonge, Tritek, other URA-certified vendor)
- Whether device can **HTTP POST** to Lapok vs only sync to EFRIS cloud
- **EFRIS API** credentials and sandbox for LAPOK’s TIN
- Whether **customer TIN** is captured on field receipts (B2B) or B2C anonymous
- **Credit sales** handling on device vs Lapok `payment_type = credit`
- Offline behaviour when device has no network (queue + later sync)

**Implication:** Lapok is built for **canonical import + link workflow**; plug in vendor-specific transport when confirmed.

---

## 3. Recommended workflow — fiscal-first

```
┌──────────────┐     ┌─────────────────────┐     ┌─────────────┐
│    Cadet     │────▶│ Fiscal cash register │────▶│ URA / EFRIS │
│  (on route)  │     │  (ring up + print)   │     │  (tax reg)  │
└──────┬───────┘     └──────────┬──────────┘     └─────────────┘
       │                        │
       │                        │ webhook / API (invoice + lines)
       │                        ▼
       │              ┌─────────────────────┐
       └─────────────▶│      LAPOK DMS      │
         pick customer│  import + link order │
                      │  update trip stock  │
                      └─────────────────────┘
```

### 3.1 Step-by-step (target production flow)

| Step | Actor | Action |
|------|-------|--------|
| 1 | Cadet | Opens route in Lapok (optional — knows active trip) |
| 2 | Cadet | Rings sale on **fiscal device** — products, qty, payment |
| 3 | Device | Prints receipt; registers with **EFRIS/URA** |
| 4 | Device / middleware | Pushes completed sale to Lapok **`/api/efris/ingest.php`** |
| 5 | Lapok | Creates `efris_receipts` row — status `pending_link` |
| 6 | Cadet | Opens **Fiscal receipts** in Lapok → selects receipt → **picks customer** |
| 7 | Lapok | Creates `orders` + `order_items`, sets `orders.efris_ref`, updates `trip_load_items.qty_sold` |
| 8 | Manager | Confirms sale if policy requires (`orders.status` pending → confirmed) |
| 9 | Accountant | EOD cash reconciliation vs device Z-report + Lapok totals |

### 3.2 What cadet does NOT re-enter

- Product names and quantities (from device payload)
- Unit prices and line totals (from device payload)
- URA invoice number (stored as `efris_ref` automatically)

### 3.3 What cadet still does in Lapok

- **Customer selection** (unless device captures buyer TIN/name and vendor passes it through)
- **End-of-day** stock return counts and cash summary
- **Route progress** (mark stops arrived)

---

## 4. System of record — who owns what

| Data object | System of record | Lapok field |
|-------------|------------------|-------------|
| Legal fiscal receipt | URA / EFRIS (via device) | `efris_receipts.efris_invoice_no` → `orders.efris_ref` |
| Sale line items (tax) | Device / EFRIS | `efris_receipt_items` → `order_items` |
| Customer relationship | Lapok | `orders.customer_id` |
| Stock on vehicle | Lapok | `trip_load_items.qty_sold` |
| Cash vs credit | Device (primary) + Lapok | `efris_receipts.payment_type` → `orders.payment_type` |
| Manager approval | Lapok | `orders.status`, `confirmed_by` |
| EOD cash handover | Lapok | `delivery_trips.cash_reported` / `cash_collected` |

```
┌──────────────────────────────────────────────────────────────────┐
│                     FISCAL DEVICE + EFRIS                         │
│  Legal receipt · TIN · Invoice no. · QR · Fiscal memory         │
└────────────────────────────┬─────────────────────────────────────┘
                             │ ingest (device_push | efris_api)
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│                         LAPOK DMS                                 │
│  efris_receipts (pending) → orders (linked) · trip stock · cash  │
└──────────────────────────────────────────────────────────────────┘
```

---

## 5. Data model

Migration: `database/migrations/004_efris_integration.sql`  
Helpers: `includes/efris.php`

### 5.1 `efris_config`

Depot-level settings (secrets in DB, not in repo).

| config_key | Purpose |
|------------|---------|
| `integration_mode` | `fiscal_first` (default) or `lapok_first` (future) |
| `seller_tin` | LAPOK / outlet TIN registered with URA |
| `default_device_serial` | Primary field device serial (optional) |
| `ingest_api_key` | Shared secret for device webhook (`X-EFRIS-KEY` header) |
| `efris_api_base` | *(future)* URA API base URL |
| `efris_client_id` | *(future)* API credentials |

### 5.2 `efris_product_map`

Maps Lapok `products.id` ↔ item code on fiscal device / EFRIS catalog.

| Column | Purpose |
|--------|---------|
| `product_id` | Lapok product (unique) |
| `efris_item_code` | Code configured on EFD (unique) |
| `efris_item_name` | Display name on device (optional) |

**Admin must map every sellable SKU before auto-link works reliably.**

### 5.3 `efris_receipts`

One row per imported fiscal sale.

| Column | Purpose |
|--------|---------|
| `efris_invoice_no` | URA / FDMS invoice number (unique) |
| `device_serial` | EFD serial |
| `fiscal_timestamp` | Time on receipt |
| `amount_total` | Grand total |
| `payment_type` | `cash` \| `credit` \| `other` |
| `status` | `pending_link` \| `linked` \| `unmapped` \| `ignored` |
| `source` | `device_push` \| `efris_api` \| `manual` |
| `order_id` | FK after cadet links |
| `payload_json` | Raw vendor payload (audit) |

### 5.4 `efris_receipt_items`

| Column | Purpose |
|--------|---------|
| `receipt_id` | FK |
| `efris_item_code` | From device |
| `item_name` | From device |
| `qty`, `unit_price`, `subtotal` | Line economics |
| `product_id` | Resolved via map (nullable) |
| `map_status` | `mapped` \| `unmapped` |

### 5.5 Link to existing `orders`

On successful link:

- `orders.efris_ref` = `efris_invoice_no`
- `orders.user_id` = cadet who linked
- `orders.trip_id` / `vehicle_id` from active trip
- `order_items` copied from mapped receipt lines

---

## 6. Receipt lifecycle (Lapok statuses)

| Status | Meaning | Next action |
|--------|---------|-------------|
| `pending_link` | Imported; all lines mapped | Cadet picks customer → confirm |
| `unmapped` | Imported; unknown product code(s) | Admin fixes `efris_product_map` → re-import or manual map |
| `linked` | Order created in Lapok | Manager confirm if required |
| `ignored` | Duplicate / test / void | No order |

---

## 7. Integration modes (phased)

### Mode A — Device webhook (Phase 1 — **scaffold built**)

Device or vendor middleware POSTs JSON to Lapok when sale completes.

| Step | Device / vendor | Lapok |
|------|-----------------|-------|
| Sale completed | ✓ Print + EFRIS | |
| POST ingest payload | ✓ | ✓ `efris_ingest_receipt()` |
| Cadet links customer | | ✓ `efris_link_receipt()` |

**Endpoint:** `POST /api/efris/ingest.php`  
**Auth:** Header `X-EFRIS-KEY: {ingest_api_key}` (set in `efris_config`)

**Example payload:**

```json
{
  "efris_invoice_no": "URA-20260624-0042",
  "device_serial": "EFD-TUK-001",
  "fiscal_timestamp": "2026-06-24 14:32:00",
  "payment_type": "cash",
  "amount_total": 360000,
  "items": [
    {
      "efris_item_code": "COKE500-12",
      "item_name": "Coke 500ml x12",
      "qty": 5,
      "unit_price": 20000,
      "subtotal": 100000
    }
  ]
}
```

**Pros:** Real-time; no cadet re-keying  
**Cons:** Requires vendor cooperation or local middleware box

---

### Mode B — EFRIS API poll (Phase 2)

Lapok scheduled job queries URA EFRIS for new documents by TIN + device + time range.

| Step | Lapok | URA |
|------|-------|-----|
| Cron every N minutes | GET new invoices | ✓ |
| Import into `efris_receipts` | ✓ | |
| Cadet links customer | ✓ | |

**Pros:** Device-agnostic if URA API exposes documents  
**Cons:** URA credentials, rate limits, latency; confirm API availability for distributors

---

### Mode C — QR / receipt number scan (Phase 2 fallback)

If webhook/API delayed:

1. Cadet scans QR on printed receipt **or** types invoice number  
2. Lapok fetches document details from EFRIS API (or manual admin entry for pilot)  
3. Same link flow as Mode A  

**Pros:** Works when push fails  
**Cons:** Extra cadet step (minimal — no line re-entry)

---

### Mode D — Lapok-first (Phase 3 — optional)

Cadet enters sale in Lapok → Lapok pushes to device → device prints.

**Not recommended as primary** for LAPOK field ops today (cadets already trained on device UI). Keep as future option if devices expose bidirectional API.

---

## 8. API surface (Lapok — full build)

| Method | Endpoint | Role | Purpose |
|--------|----------|------|---------|
| POST | `/api/efris/ingest.php` | Device (API key) or Admin test | Import fiscal receipt |
| GET | `/api/efris/fetch_pending.php` | Cadet, Manager, Admin | List receipts (`?status=pending`) |
| POST | `/api/efris/confirm.php` | Cadet, Field user | Link customer → create order |
| GET | `/api/efris/config.php` | Admin, Manager | Config + product map list |
| POST | `/api/efris/save_product_map.php` | Admin, Manager | Save SKU ↔ EFRIS code |

**Frontend (full build):** `assets/efris.js` — **Fiscal receipts** page for cadets  
**Presentation build:** placeholder card only — see `lapok-dms-presentation`

---

## 9. Cadet & manager UX

### 9.1 Cadet — Fiscal receipts page

1. After device sale, receipt appears in **Fiscal receipts today** list  
2. Tap receipt → review lines and total  
3. Select **customer** → **Record in Lapok**  
4. Dashboard updates sold qty and today’s orders  

### 9.2 Manager

- Sees orders with `efris_ref` populated  
- Confirms pending sales (existing flow)  
- Exception center: unmapped receipts, duplicate invoices, amount mismatches  

### 9.3 Accountant

- EOD: compare **device Z-report** total vs Lapok `orders` sum vs **cash handover**  
- Flag variance before `cash_confirm.php`  

### 9.4 Admin — EFRIS settings

- Set `seller_tin`, `ingest_api_key`, device serial  
- Maintain **product mapping** table  
- Test ingest button (admin session)  

---

## 10. EOD reconciliation (device ↔ Lapok ↔ cash)

```
Device Z-report (fiscal day close)
        ║
        ║  compare
        ▼
Lapok SUM(orders.efris_ref linked today)
        ║
        ║  compare
        ▼
Cadet cash_reported → Accountant cash_collected
```

| Check | Owner | Action if mismatch |
|-------|-------|-------------------|
| Receipt count | Accountant | Find unlinked `efris_receipts` |
| Amount total | Manager | Review linked orders vs device report |
| Stock variance | Manager | Trip load vs sold vs returned |

---

## 11. Security & compliance

| Topic | Requirement |
|-------|-------------|
| **API key** | Rotate `ingest_api_key`; HTTPS only in production |
| **Payload storage** | `payload_json` for audit; no card PAN data expected |
| **TIN** | Must match LAPOK registered seller TIN on device |
| **Immutability** | Do not edit linked fiscal data in Lapok — use edit request / credit note flow |
| **URA rules** | Follow URA guidance for voids, credit notes, and device decommission |

---

## 12. Questions for EFD vendor / URA (checklist)

1. Device **brand, model, and serial** assigned to each vehicle?  
2. Can completed sales **HTTP POST** to a customer URL (Lapok ingest)?  
3. Sample **JSON payload** for one receipt (all fields)?  
4. Product codes on device — export list for **efris_product_map**?  
5. **Offline queue** behaviour — when do pushes fire?  
6. **Z-report / daily close** export format for accountant reconciliation?  
7. **EFRIS API** access for LAPOK TIN — sandbox credentials and documentation?  
8. **B2B customer TIN** capture on device — passed in payload?  
9. **Credit sales** — supported on device? How flagged?  
10. Support contact for integration failures in the field?

---

## 13. Implementation order (suggested)

| # | Task | Effort | Status |
|---|------|--------|--------|
| 1 | Run migration `004_efris_integration.sql` | S | Ready |
| 2 | Admin product mapping UI | S | Built (full repo) |
| 3 | Device ingest endpoint + auth | M | Built (full repo) |
| 4 | Cadet fiscal receipts page + link flow | M | Built (full repo) |
| 5 | Vendor webhook configuration on device | M | **Blocked on vendor** |
| 6 | EOD reconciliation screen (device vs Lapok) | M | Planned |
| 7 | EFRIS API poll job | L | Phase 2 |
| 8 | QR scan fallback (mobile camera) | M | Phase 2 |
| 9 | Production TIN + URA go-live sign-off | S | Before go-live |

**Effort key:** S = small (days), M = medium (1–2 weeks), L = large (3+ weeks)

---

## 14. Reporting chain (unchanged)

```
Cadet (device sale → Lapok link) → Accountant (cash) → Manager → Executive
         ↑
    EFRIS/URA (legal receipt)
```

Executives see linked sales and `efris_ref` on reports — no URA portal login required in Lapok.

---

## 15. Presentation vs full build

| Feature | `lapok-dms-presentation` | `lapok-dms-full` |
|---------|--------------------------|------------------|
| EFRIS nav item | Placeholder card | Live admin + cadet pages |
| Device ingest | Not configured | `/api/efris/ingest.php` |
| Cadet fiscal receipts | Hidden (no cadet role) | Full workflow |
| Product mapping | Placeholder | Admin UI |

---

## 16. Demo & test (full build)

1. Set API key:  
   `UPDATE efris_config SET config_value = 'your-secret' WHERE config_key = 'ingest_api_key';`  
2. Map products in **EFRIS / URA** admin page  
3. Admin → **Send test receipt** or POST to ingest endpoint  
4. Login as **cadet@lapok.ug** → **Fiscal receipts** → link customer  

| Role | Email | Password |
|------|-------|----------|
| Cadet | cadet@lapok.ug | password123 |
| Manager | manager@lapok.ug | password123 |
| Admin | admin@lapok.ug | password123 |

---

## 17. References

- URA — Electronic Fiscal Receipting and Invoicing (EFRIS) programme  
- Lapok migration: `database/migrations/004_efris_integration.sql`  
- Lapok helpers: `includes/efris.php`  
- Lapok API: `api/efris/*.php`  
- Business case: `docs/LAPOK_PROJECT_PROPOSAL.md` (presentation folder)  
- CCBA blueprint (separate): `docs/CCBA_INTEGRATION_BLUEPRINT.md`

---

*This document is a living blueprint. Update §2 and §12 when the EFD vendor and URA confirm device model, payload format, and API access for LAPOK’s TIN.*
