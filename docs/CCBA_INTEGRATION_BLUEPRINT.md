# Lapok DMS ↔ CCBA (MyCCBA) Integration Blueprint

**Version:** 1.1 (draft)  
**Date:** July 2026  
**Audience:** Lapok leadership, managers, developers, CCBA account rep  
**Goal:** Enter replenishment and stock data **once in Lapok**; sync to CCBA without duplicate work.

---

## 0. IMPORTANT — what is live vs Phase 2 (do not mix)

**Manager daily CCBA boards** (`manager-ccba-boards`) are for **internal** Inventory + OCCD reporting (executive brief). They are **not** the MyCCBA integration surface.

| Feature | Status on boards UI | When to restore |
|---------|---------------------|-----------------|
| Inventory board + OCCD dashboard | **Live** (daily) | Now |
| **SKU map** (`ccba_product_map` admin UI) | **Removed from boards** — Phase 2 | When MyCCBA product mapping ships |
| **Sync warehouse snapshot** / daily stock sync | **Removed from boards** — Phase 2 | When MyCCBA stock sync ships (§7.3) |

**Why this matters:** Putting SKU map / warehouse sync on the daily boards confuses managers and looks like live CCBA sync before partner credentials and workflow exist. APIs/tables may already exist in the repo — **do not re-expose them on the boards page** until this integration phase is intentionally activated. Track status in `docs/MODULE_TRACKER.md` (Cross-cutting / integrations).

**Executive reporting (live):** When the manager sends the daily brief, Inventory + OCCD boards are exported as a **companion PDF** (`ccba_boards`) styled like the on-screen boards (navy banner, bordered tables). That is **internal OCCD reporting**, not MyCCBA portal sync.

---

## 1. Executive summary

Lapok is the **operational system of record** for depot life: warehouse stock, field sales, cash, routes, and internal reporting.  
**MyCCBA** ([uganda.myccba.africa](https://uganda.myccba.africa/)) is CCBA’s commercial ordering channel to Coca-Cola Beverages Uganda (CCBU).

| Principle | Decision |
|-----------|----------|
| **Single entry (human)** | Manager creates replenishment orders and daily stock positions in **Lapok first** |
| **CCBA role** | External fulfilment authority — order submission, confirmation, scheduling |
| **Delivery truth** | Physical receipt reconciled in Lapok (`supplier_deliveries`) with waybill, invoice, batch |
| **Executive view** | Read-only status from Lapok (no CCBA login) |
| **Phase 1 integration** | Assisted portal (prefill + capture CCBA refs back) — no public API assumed yet |

---

## 2. What we know about CCBA / MyCCBA (research)

### 2.1 Official platform

- **Product name:** MyCCBA  
- **Uganda URL:** https://uganda.myccba.africa/  
- **Positioning:** Order stock 24/7, schedule orders, repeat orders, track order history  
- **Access:** Existing CCBU customers; onboarding via sales rep / account manager link  
- **Devices:** Web on smart device or desktop (manager phone browser is valid)

### 2.2 Documented behaviour (CCBA FAQ)

| Topic | Finding |
|-------|---------|
| Order placement | Any time; delivery date subject to cut-off and agreed delivery days |
| Notifications | (1) Order placed — awaiting confirmation; (2) Confirmed with **order number** and final price |
| Scheduled orders | Can schedule/repeat; managed under My Account → Scheduled Orders |
| Users per outlet | **One user per outlet** on MyCCBA (important for Lapok role design) |
| Alternatives | MyCCBA recommended; legacy ordering still possible if platform issues |

### 2.3 Uganda distributor context (CCBU)

CCBU describes an **OCCD (Official Coca-Cola Distributor)** digital system for sales tracking, order status, and dispatch planning. Lapok operates as a depot/distributor layer **above** field sales and **below** CCBU supply.

### 2.4 What is NOT publicly documented

- Uganda-specific **manager vs customer** login split (your “levels” — likely separate accounts or modules; **confirm with rep**)
- Partner **API / EDI** specification for outbound orders
- Exact status labels after “confirmed” (dispatched, in transit, delivered)
- SKU mapping between Lapok `products.sku` and MyCCBA catalog codes (e.g. `Coke 500ML 01X12 (PET)`)

**Implication:** Build Lapok for **canonical workflow + ref capture**; plug in API/EDI when CCBA provides partner docs.

---

## 3. Lapok “levels” mapped to CCBA (working model)

| Your description | Likely CCBA surface | Lapok owner | Entry point |
|------------------|---------------------|-------------|-------------|
| Normal customers see products & purchase | MyCCBA shop / customer ordering | N/A (outlet retailers) | MyCCBA only |
| Manager manages stock | Stock sync / depot reporting (level 2 — **TBD exact screen**) | **Manager** | **Lapok → push/sync** |
| Manager sends order to Coca-Cola | MyCCBA replenishment checkout (level 3) | **Manager** | **Lapok → submit** |

**Recommended rule:** Lapok never replaces MyCCBA for **commercial order commitment** until API exists; Lapok **owns the draft and audit trail**, then **submits** to CCBA and stores returned refs.

---

## 4. System of record — who owns what

```
┌─────────────────────────────────────────────────────────────────┐
│                        LAPOK DMS (SoR)                          │
│  Stock levels · Batches · Field sales · Cash · Internal reports │
│  ccba_orders (draft) → submit → ccba_status_events              │
│  supplier_deliveries (physical receipt)                       │
└───────────────────────────┬─────────────────────────────────────┘
                            │ submit / sync (Phase 1: assisted)
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│              MyCCBA (CCBA commercial channel)                   │
│  Order confirmation · CCBA order no. · Scheduling · Pricing   │
└───────────────────────────┬─────────────────────────────────────┘
                            │ physical delivery
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  Lapok: Record Coca-Cola delivery (waybill, invoice, batch)    │
└─────────────────────────────────────────────────────────────────┘
```

| Data object | System of record | Notes |
|-------------|------------------|-------|
| Warehouse qty (operational) | Lapok `batches` | Updated on dispatch, sale, receipt |
| Replenishment intent | Lapok `ccba_orders` | Draft before CCBA submit |
| Commercial order / PO | CCBA MyCCBA | CCBA order number is authoritative |
| Order status (commercial) | CCBA → mirrored in `ccba_status_events` | Poll/manual until API |
| Delivery documents | Lapok `supplier_deliveries` | Waybill, invoice, truck, driver |
| Batch / expiry | Lapok | Per line on receipt |
| Executive dashboards | Lapok (aggregated) | No CCBA login |

---

## 5. Data model

See migration sketch: `database/migrations/002_ccba_integration.sql`

### 5.1 `ccba_orders`

Outbound replenishment header (manager-created in Lapok).

| Column | Purpose |
|--------|---------|
| `lapok_ref` | Internal ref e.g. `ORD-2026-0624-0042` |
| `status` | Lapok lifecycle (see §6) |
| `submission_mode` | `assisted_portal` \| `api` \| `edi` \| `manual` |
| `ccba_order_no` | Returned from MyCCBA after confirmation |
| `ccba_po_no` | PO if distinct from order no. (**TBD**) |
| `ccba_customer_code` | Outlet/depot code on CCBA (**TBD**) |
| `requested_delivery_date` | Manager preference |
| `submitted_at` / `confirmed_at` | Timestamps |
| `created_by` | Manager user id |
| `notes` | Free text |

### 5.2 `ccba_order_items`

| Column | Purpose |
|--------|---------|
| `ccba_order_id` | FK |
| `product_id` | Lapok product |
| `ccba_sku_code` | Mapped MyCCBA product code (**TBD**) |
| `qty_requested` | Cartons to order |
| `qty_confirmed` | From CCBA confirmation (nullable until confirmed) |
| `unit_cost_estimate` | From last delivery or product default |

### 5.3 `ccba_status_events`

Append-only audit of status changes (Lapok and CCBA).

| Column | Purpose |
|--------|---------|
| `ccba_order_id` | FK |
| `status` | Normalized Lapok status |
| `ccba_status_label` | Raw label from portal/API |
| `source` | `lapok` \| `ccba_portal` \| `ccba_api` \| `manager` |
| `payload_json` | Optional snapshot |
| `recorded_by` | User id if manual |
| `recorded_at` | When |

### 5.4 `ccba_refs`

Flexible key-value refs per order or delivery.

| ref_type examples | Example value |
|-------------------|---------------|
| `ccba_order_no` | From confirmation email/screen |
| `ccba_po` | PO number |
| `waybill` | WB-2026-0504-001 |
| `invoice` | INV-CC-0504-0091 |
| `truck_plate` | UBA 223K |
| `driver_name` | Coca-Cola Driver |

### 5.5 `ccba_product_map`

Maps Lapok `products.id` ↔ MyCCBA catalog code.

### 5.6 `ccba_stock_snapshots`

Daily stock position pushed/synced to CCBA (level 2).

| Column | Purpose |
|--------|---------|
| `snapshot_date` | Business date |
| `product_id` | Lapok product |
| `qty_warehouse` | At snapshot time |
| `qty_on_vehicles` | Optional |
| `sync_status` | `pending` \| `synced` \| `failed` |
| `synced_at` | When acknowledged |

### 5.7 Link to existing `supplier_deliveries`

Add nullable `ccba_order_id` on `supplier_deliveries` to tie **inbound receipt** to **outbound CCBA order**.

---

## 6. Status lifecycle

### 6.1 Lapok canonical statuses

| Status | Meaning | Typical actor |
|--------|---------|---------------|
| `draft` | Manager building order in Lapok | Manager |
| `ready_for_ccba` | Validated; ready to submit | Manager |
| `submitted_to_ccba` | Submitted on MyCCBA (awaiting confirmation) | Manager + CCBA |
| `ccba_acknowledged` | CCBA received (notification 1) | CCBA |
| `ccba_confirmed` | Order number + price confirmed (notification 2) | CCBA |
| `scheduled` | Delivery date scheduled on MyCCBA | CCBA / Manager |
| `dispatched` | CC truck left depot | CCBA |
| `delivered` | Arrived at Lapok | Manager |
| `received_in_lapok` | `supplier_deliveries` recorded | Manager |
| `closed` | Quantities and invoice reconciled | Manager |
| `partial_delivery` | Short delivery logged | Manager |
| `cancelled` | Order cancelled | Manager / CCBA |
| `rejected` | CCBA rejected order | CCBA |

### 6.2 Mapping to MyCCBA (initial — confirm with rep)

| MyCCBA (from FAQ) | Lapok status |
|-------------------|--------------|
| Order placed — awaiting confirmation | `submitted_to_ccba` or `ccba_acknowledged` |
| Order confirmed + order number | `ccba_confirmed` |
| Scheduled order (future date) | `scheduled` |
| In My Order History (processed) | `dispatched` or `delivered` (**TBD**) |

---

## 7. Manager UX — single entry flow

### 7.1 Navigation (manager only)

Add under **Stock management**:

1. **Replenishment order (CCBA)** — create/submit/track  
2. **Daily stock sync (CCBA)** — one-click from current Lapok stock  
3. **Record Coca-Cola delivery** — existing modal (unchanged)

### 7.2 Create replenishment order

1. Manager opens **Replenishment order**  
2. Lapok pre-fills lines from low-stock alerts + `products.min_stock`  
3. Manager adjusts quantities, adds notes, sets requested delivery date  
4. Save as `draft` or mark `ready_for_ccba`  
5. **Submit to CCBA** (see integration modes below)  
6. Manager pastes **CCBA order number** (or Lapok polls API later)  
7. Status timeline visible on order detail + executive dashboard  

### 7.3 Daily stock update (level 2)

> **UI status:** Not on manager daily boards until Phase 2 — see §0.

1. Manager clicks **Sync stock to CCBA** on stock page  
2. Lapok snapshots current warehouse (and optionally on-vehicles) qty per product  
3. Phase 1: export/sync pack + open MyCCBA stock screen + manager confirms sync  
4. Lapok records `ccba_stock_snapshots.sync_status = synced`  

### 7.4 Delivery receipt (unchanged pattern)

When CC truck arrives:

1. Manager uses **Record Coca-Cola delivery**  
2. Links to `ccba_order_id` if known  
3. Captures: waybill, invoice, truck, driver, batch, expiry, qty ordered/delivered  
4. Lapok updates `batches` + `stock_movements`  
5. Order → `received_in_lapok` → `closed` after variance check  

### 7.5 Mobile

- Manager can use phone browser for Lapok **and** MyCCBA  
- Phase 1: Lapok shows **mobile-friendly order summary** + “Open MyCCBA” deep link (if rep provides URL pattern)  
- Avoid expecting manager to re-type 20 SKUs on phone — copy/export from Lapok  

---

## 8. Integration modes (phased)

### Mode A — Assisted portal (Phase 1 — **now**)

| Step | Lapok | CCBA |
|------|-------|------|
| Create order | ✓ | |
| Export CSV / printable pick list | ✓ | |
| Open MyCCBA in new tab | Link | ✓ Manager logs in |
| Place order on portal | | ✓ |
| Paste order number back | ✓ Form field | |
| Status updates | Manual or rep email | Source |

**Pros:** No API contract; works today; full audit in Lapok  
**Cons:** One context switch (not full double entry if prefilled)

### Mode B — Semi-automated (Phase 2)

- Scheduled job or button: POST order to CCBA **if** partner endpoint exists  
- Webhook or polling for status → `ccba_status_events`  
- Manager only handles exceptions  

### Mode C — API / EDI (Phase 3)

Industry pattern (if CCBA supports):

| EDI doc | Purpose |
|---------|---------|
| 850 | Purchase order |
| 855 | PO acknowledgment |
| 856 | Advance ship notice (waybill/dispatch) |
| 810 | Invoice |

Map into same Lapok tables — **one canonical model**, multiple transports.

---

## 9. API surface (Lapok — to build)

| Method | Endpoint | Role | Purpose |
|--------|----------|------|---------|
| GET | `/api/ccba/orders/fetch.php` | Manager | List orders |
| POST | `/api/ccba/orders/create.php` | Manager | Create draft |
| POST | `/api/ccba/orders/submit.php` | Manager | Mark submitted + store refs |
| POST | `/api/ccba/orders/confirm_ref.php` | Manager | Paste CCBA order no. |
| GET | `/api/ccba/orders/detail.php?id=` | Manager, Executive | Timeline + lines |
| POST | `/api/ccba/stock/sync.php` | Manager | Snapshot + trigger sync |
| GET | `/api/ccba/product_map/fetch.php` | Admin | SKU mapping |

Executive: read-only on `fetch` + `detail` + dashboard aggregates.

---

## 10. Reporting chain (unchanged)

```
Field agents → Accountant → Manager → Executives
                    ↑
              Manager → CCBA (replenishment)
                    ↓
              Delivery receipt in Lapok
```

Executives see: open CCBA orders, overdue deliveries, stock risk — **not** CCBA login.

---

## 11. Questions for CCBA account rep (checklist)

Copy this into your meeting with CCBU / MyCCBA support:

1. Does Lapok depot have **one or multiple** MyCCBA logins? (customer vs stock vs supply)  
2. What is our **outlet/customer code** on MyCCBA?  
3. Product code list or export for **SKU mapping**?  
4. Exact **status list** after order placement (portal labels)?  
5. Is there a **partner API, EDI, or SFTP** for distributors? Who is technical contact?  
6. Can we get **deep links** to order checkout with prefilled cart? (unlikely — ask anyway)  
7. For **daily stock update**, which MyCCBA screen/module is used? Required fields?  
8. Who receives **waybill and invoice** electronically vs paper only?  
9. **Cut-off times** and delivery day rules for our depot?  
10. Can **second user** be added for manager backup given “one user per outlet” FAQ rule?

---

## 12. Implementation order (suggested)

| # | Task | Effort |
|---|------|--------|
| 1 | Run migration `002_ccba_integration.sql` | S |
| 2 | Manager page: CCBA replenishment (placeholder + draft save) | M |
| 3 | Assisted submit: export + “Open MyCCBA” + paste order no. | M |
| 4 | Link `supplier_deliveries.ccba_order_id` in receive UI | S |
| 5 | Executive dashboard widgets: open orders, delivery SLA | S |
| 6 | `ccba_product_map` admin UI | M | **Phase 2** — not on daily boards (see §0) |
| 7 | Daily stock snapshot button | S | **Phase 2** — not on daily boards (see §0) |
| 8 | API integration (when CCBA docs available) | L |

---

## 13. Demo credentials (existing seed)

| Role | Email | Password |
|------|-------|----------|
| Manager | manager@lapok.ug | password123 |
| Executive | executive@lapok.ug | password123 |

CCBA: use credentials provided by CCBU rep (not stored in Lapok repo).

---

## 14. References

- [MyCCBA Uganda](https://uganda.myccba.africa/)
- [MyCCBA FAQ](https://www.myccba.africa/faq)
- [CCBA Customer FAQ PDF](https://www.myccba.africa/media/wysiwyg/CCBA_Customer_FAQs.pdf)
- [CCBU distribution system (CCBA group)](https://www.ccbagroup.com/coca-cola-beverages-uganda-takes-distribution-to-the-next-level/)
- Lapok existing receipt API: `api/stock/receive_delivery.php`
- Lapok schema: `database/schema.sql`

---

*This document is a living blueprint. Update §2 and §11 when CCBA rep confirms Uganda-specific login and integration options.*
