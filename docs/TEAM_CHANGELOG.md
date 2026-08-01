# Team change log

**Purpose:** Track what you and your colleague change in this codebase.  
**When to update:** After you finish a set of edits (or before / after a code push), add a new entry at the **top** of the log (newest first).

Use **Africa/Kampala** date and time (or your local time — say which). Be specific enough that someone else can find the files and understand the “why”.

---

## How to add an entry

1. Copy the template below.
2. Paste it **above** the previous entry (under [Log](#log)).
3. Fill in date, time, your name, push/ref (branch or commit if you have one), and bullet the changes.
4. Save this file with your code push so the history stays with the repo.

### Template

```markdown
### YYYY-MM-DD · HH:MM (Africa/Kampala)

| | |
|--|--|
| **Who** | Your name |
| **Push / ref** | e.g. `main` · commit `abc1234` · or “local WIP, not pushed” |
| **Area** | e.g. Manager stock · CCBA boards · Cadet |

**Changes**
- …
- …

**Notes** (optional)
- …
```

---

## Log

### 2026-08-01 · afternoon (Africa/Kampala) — cash outs now also live on the cadet "Today's report" page

| | |
|--|--|
| **Who** | Dev |
| **Push / ref** | `testing-era` · local WIP |
| **Area** | Cadet · RDC |

**Changes**
- New `api/cashouts/daily.php` — returns today's given-out, recovered, open balance and open count for the current cadet.
- `assets/cashouts.js` + `index.html` — the cadet daily report page now has a **Cash outs &amp; recoveries** card (`cashoutsDailyMount`): one-tap "New cash out today" and "Record recovery" (pick an open cash out) reusing the existing modals, plus today's summary cards and a link to the full ledger on the dashboard. Recorded from either place, the RDC sheet totals prefill from the same ledger. Recovery cash is **not** added to the daily "amount collected" — kept separate (deliberate).

**Notes**
- Verified live: daily endpoint returns given 100,000 / recovered 30,000 / open 70,000 / 1 open after a create + partial recovery; cleaned up after test.

### 2026-08-01 · afternoon (Africa/Kampala) — cadet cash out ledger (credit issued + recoveries, settled over time)

| | |
|--|--|
| **Who** | Dev |
| **Push / ref** | `testing-era` · local WIP |
| **Area** | Cadet · Manager · RDC (accountant) · Customers |

**Changes**
- New ledger tables: `customers.nin` column added; `customer_cashouts` (customer, cadet, optional trip, `amount_out`, running `balance`, `status` open/settled) and `cashout_payments` (each recovery with `paid_on` date). `balance` is updated transactionally and a cashout settles automatically when it reaches 0.
- `includes/cashouts.php` — `cashout_list()`, `cashout_create()`, `cashout_record_recovery()` (transactional; rejects over-recovery), `cashout_daily_totals()` (per-cadet issued / collected for a date), `cashout_prefill_sheet_totals()`.
- `api/cashouts/` — `list.php` (cadet sees own; manager/accountant/executive/admin see all with `view_all`), `create.php` (records the day's cash out; links the cadet's active trip), `recover.php` (records a repayment; settles at 0).
- `api/customers/create_customer.php` — cadets/field users may now add customers on the fly (`customers_write_own`); `nin` accepted on create/edit and searched in `fetch_customers.php`. Edit still manager-only.
- RDC balancing — `rdc_new_sheet_template()` and `rdc_sync_cadet_reports_into_sheet()` prefill the daily sheet's **cash out** and **recoveries** columns per cadet from the ledger (only where the cell is still 0, so RDC manual adjustments are never overwritten).
- `assets/cashouts.js` + `index.html` — cadet dashboard gets full cash out management (new cash out with add-customer inline, recover button, outstanding/settled lists, summary cards); manager dashboard and accountant RDC hub get a read-only overview. Wired into `showPage`.

**Notes**
- Verified live as cadet 4: create cash out → partial recovery keeps it open → final recovery settles it → over-recovery rejected; manager sees all rows (`view_all: true`); RDC sheet for 2026-08-01 prefilled `cadet_4` cash out 250,000 / recoveries 110,000. Test data cleaned up after verification.

### 2026-08-01 · evening (Africa/Kampala) — cadet remainders restock into warehouse on trip close

| | |
|--|--|
| **Who** | Dev |
| **Push / ref** | `testing-era` · local WIP |
| **Area** | Manager dispatch · warehouse stock · RDC |

**Changes**
- `includes/cadet_reports.php` — `cadet_apply_trip_sales()` now closes out the vehicle ledger when a trip's report is applied: the whole load leaves `qty_on_vehicles` and the unsold remainder (`qty_returned`) is added back to `batches.qty_warehouse`, logged as a `return` stock movement. Re-submits only adjust the warehouse by the delta (guarded by the existing `return` movement), so it is idempotent. Resolves the documented "known gap" (remainders were never restocked and on-vehicle counts grew over time).
- `includes/depot_finance.php` — closing stock is now just the live warehouse ledger (remainders are already restocked in, so they are no longer added a second time).
- `api/depot/fetch_snapshot.php` — opening carry-forward uses the warehouse ledger directly (same reason).
- Live DB backfilled: closed trip 47's 29 load lines cleared `qty_on_vehicles` (all products now 0) and its 4-unit remainder (500 ML X 24) was restocked → 2096.

**Notes**
- Verified: `fetch_stock.php` shows 0 products "with vehicles"; warehouse 500 ML X 24 = 2096, 300-COKE = 370; `cadet_apply_trip_sales` re-run on trip 47 with its stored 8 sales lines is idempotent.

### 2026-08-01 · evening (Africa/Kampala) — depot stock carries forward; RDC stock status shows real quantities

| | |
|--|--|
| **Who** | Dev |
| **Push / ref** | `testing-era` · local WIP |
| **Area** | Manager stock · RDC/accountant dashboard |

**Changes**
- `api/depot/fetch_snapshot.php` — opening stock now carries yesterday's **calculated** closing (warehouse ledger + previous day's cadet returns), instead of only searching for saved snapshots. Closing is computed, never stored, so the ledger is the source of truth; saved closing/opening snapshots remain a fallback only when the ledger is empty.
- `assets/depot-snapshots.js` — read-only closing view (RDC "Depot stock status") was zeroing every row's qty in `loadDepotSnapshotEditor`; it now renders the calculated closing (warehouse + returns), which at start of day equals yesterday's closing (= today's opening). Manager editors unchanged.

**Notes**
- Verified live: today's opening pre-fills COKE 300 = 370 (yesterday's computed closing), 500 ML X 24 = 2096 (2092 ledger + 4 returned); accountant closing view returns COKE 300 qty 370 instead of 0.

### 2026-08-01 · afternoon (Africa/Kampala) — accountant dashboard "no activity" fix

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — readiness honesty fix |
| **Area** | Accountant (RDC) Home checklist · manager pack prep page |

**Changes**
- **Tasks no longer show "Done" when nothing was done.** `report_accountant_readiness()` (`includes/report_packets.php`) reported Field EOD / Cash handovers / Trips closed as ready on empty days because their checks were vacuously true (`0 of 0 reports`, `All confirmed`, `All closed`). Each item now carries a `noop` flag plus a `No activity today` status when there were no trips (returned reports or dispatched trips) for the date, and top-level `completed` counts only items that were actually worked.
- RDC Home checklist + progress bar (`assets/rdc-hub.js`) and the manager-pack readiness page (`assets/report-exchange.js`) render `noop` items as neutral (no green `✓`/Done badge, no "Complete" button), while `ready` semantics are unchanged — so the manager-pack gate still passes on a no-activity day once the (empty) balancing sheet is submitted.
- Verified live: 2026-08-01 → `completed 0/4`, three items `No activity today`, sheet `Draft`; 2026-07-31 (real trip) → `completed 4/4`, all items genuinely done.

**Notes**
- Server gate (`report_require_accountant_ready`) untouched — `noop` items are still vacuously ready there on purpose.

### 2026-08-01 · afternoon (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — 3 cadet/manager fixes (on top of RDC polish) |
| **Area** | Cadet daily report · history calendar · manager login |

**Changes**
- **Manager credentials fixed (live DB).** `manager@lapok.ug` (Sarah Nakato, `password123`) was missing from the local database — only 4 users existed. Added user id 5 with the same bcrypt hash as `database/seed.sql`; login + role now verified via `api/auth/login.php`.
- **Cadet history calendar now keeps records.** `api/cadet/history.php` only queried trips `status = 'returned'`, but `api/trips/cash_confirm.php` flips trips to `'completed'` on cash confirmation — so confirmed trips vanished from the calendar. Filter is now `status IN ('returned','completed')`; trip #47 (2026-07-31) now appears.
- **Cadet expense slots expanded from 5 to 10.** Cadets can now enter Fuel, Lunch, Discount, Shortage, Repairs, **Parking, Transport, Paper roll, Promotion, and Other/misc** on the daily report (`index.html` auxiliary section, `assets/cadet-daily.js`).
- The new categories flow end-to-end: stored in `[CADET_REPORT]` note via `cadet_auxiliary_defaults`/`normalize`/`attach` (`includes/cadet_reports.php`), mapped to the matching RDC expense lines **PARKING / TRANSPORT / PAPER ROLL / PROMOTION / OTHER** per vehicle in `rdc_apply_cadet_report_to_sheet()` (`includes/rdc_balancing.php`), shown in the cadet history detail table, and included in the "high expenses vs sales" flag (`api/cadet/submit_report.php` now passes total non-fuel expenses). Verified: PHP unit run mapped all categories into a sheet and history returns the full 10-key auxiliary object.
- `other_expense` (legacy daily-report PDF field) now means "all non-fuel expenses" (was lunch+discount+shortage+repairs).

**Notes**
- No DB schema or migration changes — expenses live inside the report JSON note, not columns. Old reports still parse (new keys default to 0).

### 2026-08-01 · ~11:30 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — multi-branch design (docs only, nothing implemented) |
| **Area** | Cross-cutting — future multi-branch architecture |

**Changes**
- New **`docs/MULTI_BRANCH_BLUEPRINT.md`** — the working reference for multi-branch (no code). Documents that the system is single-depot today (no `branches` table; `rdc_daily_sheets` unique on `balance_date` alone; `report_packets` routes by `to_role` only; readiness gates + notifications are branch-blind), the target shape (shared executives, per-branch managers/RDCs/cadets), the concrete `branch_id` data-model changes, per-branch reporting chain (executive brief per branch, optional consolidation), row-level permission scoping, a module-by-module impact table, and a phased plan (Phase 0 "insurance" vs Phase 1 full scope).
- Open decisions recorded: near-term need vs insurance-only; executive per-branch briefs vs consolidated view; whether a head-office layer exists.
- `docs/MODULE_TRACKER.md` → Cross-cutting / integrations: one status line pointing to the blueprint.

**Notes**
- Design only — no migrations, no schema or UI changes. Nothing to test in the browser.

### 2026-08-01 · ~11:00 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — RDC accountant polish (on top of commit `50706f2` cadet receive) |
| **Area** | Accountant (RDC) daily close — Home · Today's close · cash · manager pack |

**Changes**
- **RDC Home checklist now mirrors the real manager-pack gate.** The Home previously showed 2 steps (Daily balancing / Manager pack) while the pack page blocked on 4 requirements. It now renders the exact `accountant_readiness` items from `api/reports/exchange_list.php` (Field EOD archived / Cash handovers confirmed / Trips closed / Balancing submitted) plus Manager pack, and the progress bar grew from 2 to 5 segments (`index.html`, `assets/rdc-hub.js`).
- **Cash handover is no longer labelled "optional".** It gates the manager pack, so the Home nudge, priority row, and static placeholder now say "required before the manager pack".
- **Today's close wizard guards step-tab jumps.** Clicking the Cash or Submit step tabs now enforces data presence via new `rdcJumpToStep` (previously tabs bypassed `rdcWizardNext` validation), and `rdcSubmitSheet` refuses to submit without sales or cash data (`assets/rdc-balancing.js`).
- **Cash handover UX:** new **Match all to reported** bulk button (shown when >1 pending), and the sticky bar now advances contextually — "← Home" while anything is pending, "Manager pack →" when all handovers are confirmed (`assets/cash-handover.js`, `index.html`).
- **Manager pack page:** disabled "Send pack now" / "Upload & send" buttons carry a tooltip explaining the missing checklist items, and the cover-note + upload panels are hidden once a pack was already sent for that date (`assets/report-exchange.js`).

**Notes**
- Frontend-only changes (no migration). Hard refresh (**Ctrl+F5**) after load.
- Verified the Home's data source live: `accountant_readiness` returns the 4 gate items with `ready`/`status`/`page` for the accountant role.

### 2026-08-01 · ~09:30 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — cadet dispatch confirmation (`f1f9713` = dashboard-source + vehicle-return WIP committed; this feature uncommitted) |
| **Area** | Cadet daily ops · Manager dispatch visibility |

**Changes**
- **Cadet receive dispatch (live):** after the manager dispatches, the cadet dashboard shows a 📦 **Confirm load received** banner above the load table (`assets/cadet-dashboard.js`, `index.html` `#cadetDashAckBanner`). Confirming calls the new `api/trips/confirm_receive.php` which moves the trip `dispatched → on_route`, stamps `acknowledged_at` (migration **019**, applied), writes an audit row, and notifies all active managers/admins.
- Confirmation is **idempotent** — an already-`on_route` trip returns its original `acknowledged_at` without re-notifying.
- **Manager dispatch log** now distinguishes **Awaiting confirm** (amber) from **On route ✓** (`api/trips/dispatch_log.php` now selects `acknowledged_at`; `assets/manager-ops.js` `loadDispatchLog`).
- `api/cadet/fetch_context.php` exposes `acknowledged_at` for the cadet trip.
- Docs updated: `README.md`, `docs/MODULE_TRACKER.md`, `docs/SYSTEMS_BUILDING_GUIDE.md`.

**Notes**
- Apply migration **019** to any other environment (`database/migrations/019_cadet_confirm_receive.sql` / hosting `23_019_…`). Hard refresh (**Ctrl+F5**) after JS changes.
- Verified end-to-end locally: login → confirm → trip on_route with `acknowledged_at` + manager notification (test data cleaned up).

### 2026-07-31 · ~14:00 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — dispatch pack & readiness committed (`383a86d`, `36cfb60`, `aaf67f5`); dashboard-source + vehicle-return fixes local WIP |
| **Area** | Manager daily ops · Executive dashboard · Cadet/field return |

**Changes**
- **Dispatch load list is pack-level** like the cadet daily list (300ML RGB, 300PET, …): `depot_dispatch_pack_groups()` / `depot_split_pack_qty()` in `includes/depot_catalog.php`; `prepareDispatchModal` renders one input per pack (warehouse qty rolled up across SKUs); `saveDispatch` sends `rdc_key`; `api/vehicles/dispatch.php` splits pack qty across SKUs by stock. Dispatch table styled like the cadet sales table (cat-row layout).
- **Daily readiness trimmed to 3 items**: Accountant pack reviewed, RDC daily sheet approved, Opening stock completed. Removed closing stock, Inventory board, and OCCD board items (`report_manager_readiness()` in `includes/report_packets.php`; totals in `assets/report-exchange.js`).
- **Executive login restored**: created `executive@lapok.ug` (Mary Atim, `password123`) directly in the live `users` table.
- **Vehicles free when trips return**: `api/cadet/submit_report.php` and `api/trips/eod_submit.php` both flip the trip's vehicle to `available` and clear `driver_id`/`cadet_id` when the trip closes. Swept stale `on_route` vehicles that had no open trip (TUK-001).
- **Dashboard sales figures now real**: revenue today / crates sold / revenue MTD on executive, admin and manager dashboards, plus `dashboard_charts.php` graphs, read actual depot sales — RDC sheet (`sales_total`) with cadet trip report fallback (`notes` JSON), crates from `trip_load_items.qty_sold`. New helpers `depot_sales_revenue_by_day()` / `depot_cartons_sold_by_day()` in `includes/depot_finance.php`. Replaced `orders`-table queries in `api/dashboard/executive.php`, `api/dashboard/admin.php`, `api/reports/dashboard_charts.php`.
- **Reports pulled from depot sales too**: `api/reports/financial.php` (Revenue / Cartons / Revenue-by-month) and `api/reports/sales.php` (by period / product / vehicle / summary) now read submitted trip reports instead of the empty `orders` table. Filter-aware helpers `depot_trip_revenue_by_day()` / `depot_trip_cartons_by_day()` (route/vehicle/user) added in `includes/depot_finance.php`. Sales CSV export (`api/reports/export_csv.php`) exports per-trip rows (date, trip, vehicle, cadet, sales, cash) instead of orders; reports summary label changed from “Orders” to “Trips” (`assets/phase45.js`).

**Notes**
- Hard refresh (**Ctrl+F5**) after JS/CSS changes; these changes are mostly PHP so refresh is enough.
- Historical sweep was a one-off DB update (no migration) — the return-path fix prevents recurrence.

### 2026-07-21 · ~09:35 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Stock reset for testing |

**Changes**
- Zeroed all `batches` warehouse / on-vehicle qty (dashboard Warehouse = **0**).
- Cleared `depot_stock_snapshots` and `stock_movements` so opening/closing stock can be tested from empty.
- Kept product catalogue (44 active SKUs) — counts start at zero; manager enters opening stock.

### 2026-07-21 · ~09:30 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Dashboard warehouse metric |

**Changes**
- Warehouse crates total was **overstated** (summed inactive product batches too). Now counts active products only — should show **2,450** not **4,030** on this DB.
- Fixed in `api/dashboard/admin.php`, `api/dashboard/executive.php`; stock summary uses full filtered set not just current page.

### 2026-07-21 · ~09:20 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Admin weekly assignments |

**Changes**
- Fixed Weekly cadet/vehicle/route assignments **500** by applying migration **018** (`vehicle_route_assignments`).
- Documented **018** in README migration catalog + troubleshooting.

### 2026-07-21 · ~09:10 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Accountant month-end · Advanced KPIs |

**Changes**
- Month-end **Proactive alerts** no longer dumps raw `Insufficient permissions` for manager/executive.
- Advanced KPIs load by role: accountant/admin get full financial + cash; manager gets receivables-only + info note; executive gets financial when permitted.
- Month-end workspace still loads even if KPI APIs fail (`assets/accountant-improvements.js`).

### 2026-07-19 · Compact dashboard stock warning (Africa/Kampala)

- Replaced the full low-stock product dump with a count, three examples, and a link to the complete Exception Center list.
- Restricted the updater to `admLowStockAlert`; it no longer overwrites unrelated red validation and approval alerts.

### 2026-07-19 · Admin-controlled weekly field assignments (Africa/Kampala)

| | |
|--|--|
| **Who** | Codex + project owner |
| **Push / ref** | Local WIP, not pushed |
| **Area** | Admin users · vehicle dispatch · weekly routes |

**Changes**
- Imported the upper Monday–Saturday route table for Canter Town, Tuk-Tuk 1, Tuk-Tuk 2 and Canter Rural; intentionally excluded the lower assets-per-route table.
- Added an Admin-only board that assigns one cadet to each vehicle and maintains its route for every working day.
- Made Manager dispatch cadet/route fields read-only and added server-side resolution so submitted browser values cannot override Admin assignments.
- Added migration `018_admin_vehicle_route_assignments.sql`, assignment endpoints, audit logging, and incomplete/Sunday assignment guards.

### 2026-07-19 - 11:35 (Africa/Kampala)

| | |
|--|--|
| **Who** | Codex + project owner |
| **Push / ref** | Local WIP, not pushed |
| **Area** | No-demo operational cleanup |

**Changes**
- Preserved the six accounts, four vehicles, product catalogue, genuine 19 July cadet report, RDC sheet, and review notification.
- Removed seeded orders, customers, routes, stock quantities, deliveries, GPS pings, stale trips, test messages, and historical sample report packets.
- Deleted 20 orphaned/demo PDF and text artifacts while retaining the current Field EOD PDF.
- Added migration `017_remove_demo_operational_data.sql` and changed the baseline seed/migrations so demo operations are not recreated.
- Removed RDC sample-data controls, integration placeholder injection, hard-coded customer/returns values, and artificial fleet coordinates.
- Replaced login/demo wording with initial-account guidance and documented the no-demo baseline in `README.md`.

### 2026-07-19 - 11:05 (Africa/Kampala)

| | |
|--|--|
| **Who** | Codex + project owner |
| **Push / ref** | Local WIP, not pushed |
| **Area** | Shared dashboard navigation |

**Changes**
- Changed the left dashboard navigation into an off-canvas menu for every account role.
- Added hover-to-preview and click/tap-to-pin behavior to the top-left three-bar button.
- Added outside-click, navigation-selection, and Escape-key closing behavior.
- Kept the main dashboard full width while navigation is closed and documented the shared interaction in `README.md`.

### 2026-07-19 - 10:50 (Africa/Kampala)

| | |
|--|--|
| **Who** | Codex + project owner |
| **Push / ref** | Local WIP, not pushed |
| **Area** | End-to-end report synchronization |

**Changes**
- Added one date-specific status model for the Field to Accountant to Manager to Executive report chain.
- Added Accountant readiness checks for Field EOD coverage, confirmed cash handovers, closed assigned trips, and submitted RDC balancing.
- Enforced Accountant and Manager readiness on both generated PDFs and replacement uploads.
- Added live readiness and chain-status interfaces to the Accountant, Manager, Executive, and Admin PDF report views.
- Documented the full report ownership, hand-off, and acknowledgement workflow in `README.md`.

### 2026-07-19 · 10:35 (Africa/Kampala)

| | |
|--|--|
| **Who** | Codex + project owner |
| **Push / ref** | Local WIP, not pushed |
| **Area** | Manager PDF reporting desk |

**Changes**
- Replaced the manager's generic PDF exchange with an inbox-first, date-based reporting desk.
- Added six readiness checks: Accountant pack reviewed, RDC sheet approved, opening stock, closing stock, Inventory board, and OCCD board.
- Added direct actions from incomplete checks to their owning manager pages.
- Added clear previews for the Executive operations brief and CCBA boards companion PDF.
- Added a final two-document confirmation popup and executive delivery/acknowledgement outbox.
- Enforced readiness in both generated-pack and uploaded-PDF server paths to prevent bypassing the UI gate.
- Updated `README.md` with the manager reporting workflow and gate.

### 2026-07-19 · 10:25 (Africa/Kampala)

| | |
|--|--|
| **Who** | Codex + project owner |
| **Push / ref** | Local WIP, not pushed |
| **Area** | Authentication · Audit UI · Notifications · RDC cash reconciliation · Docs |

**Changes**
- Prevented the protected dashboard from flashing before authentication and cache-busted the corrected login assets.
- Replaced raw audit-entry JSON with a structured event and before/after interface.
- Split notification behavior into unread bell items and persistent message history; message Open now uses a detail popup.
- Added per-vehicle RDC cash reconciliation: sales, operational expenses, expected cash, handed-over cash, and missing/excess result.
- Fixed completed cash-confirmed trips disappearing from RDC cadet synchronization.
- Updated `README.md` with the correct local URL, migrations 001–016 (including both 004 files), password-reset flag, and current UI/accounting behavior.

**Notes**
- Current 19 July draft was re-synchronized: expected UGX 53,000, handed over UGX 26,000, missing UGX 27,000.

### 2026-07-16 · ~17:15 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Docs sync |

**Changes**
- Synced docs for **styled** executive brief stock table + **styled** companion CCBA boards PDF:
  - `MODULE_TRACKER.md` — Current focus, executive brief section, companion PDF details, PDF chain notes
  - `README.md` — brief/boards description, key files (`simple_pdf.php`), docs table
  - `SYSTEMS_BUILDING_GUIDE.md` §9 — done focus + CCBA companion PDF note
  - `CCBA_INTEGRATION_BLUEPRINT.md` §0 — live companion PDF vs Phase 2 sync

### 2026-07-16 · ~17:10 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Executive / CCBA boards PDF styling |

**Changes**
- CCBA boards companion PDF now uses **navy banners + bordered tables** (category / total / grand row fills) matching the on-screen boards.
- Opening/closing stock on the executive brief uses the same **styled table** stock-book layout.
- PDF engine (`simple_pdf.php`) supports `banner`, `panel_title`, and `table` section types.

### 2026-07-16 · ~16:35 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Executive brief · CCBA boards PDF |

**Changes**
- Opening/closing stock on executive brief is now a **full stock book** (every SKU: Open | Purchase | Sales | Close + brand totals).
- CCBA Inventory + OCCD boards go out as a **separate companion PDF** (`ccba_boards`) mirroring on-screen tables.
- Migration **015** adds `ccba_boards` report type; manager send generates brief + boards together.
- Sample scripts: `scripts/sample_executive_brief.php` writes both PDFs under `storage/reports/`.

### 2026-07-16 · ~16:25 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Docs · roadmap |

**Changes**
- Updated **all key docs** for recent product decisions + next shifts:
  - `MODULE_TRACKER.md` — **Current build focus**; executive brief contents; cadet dispatch **Planned**; accountant polish as **primary attack**
  - `README.md` — next shifts, boards/SKU Phase 2 note, executive brief summary, cadet/accountant notes
  - `SYSTEMS_BUILDING_GUIDE.md` §9 — current build focus + docs map
  - `RDC_ROLE.md` — nav/workflow aligned; polish focus callout
  - `TEAM_CHANGELOG.md` — this entry

**Notes — next coding shifts**
1. **Cadet receive dispatch** (how they get / acknowledge the load after manager dispatch).  
2. **Accountant account** = primary attacking point (Home → close → cash → pack).

### 2026-07-16 · ~16:20 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Executive brief PDF |

**Changes**
- Expanded manager → executive brief (`report_build_manager_layout` in `includes/report_packets.php`) into a fuller day summary.
- New sections: executive attention flags, day at a glance, fuller RDC finance, **opening & closing stock**, **most selling**, **least selling / slow movers**, CCBA boards, stock risk.
- Helpers: `report_rank_rdc_product_sales`, `report_stock_snapshot_brief_lines`, `report_product_sales_flag_lines`.

**Notes**
- Re-send today’s executive brief from PDF reports / manager checklist to regenerate the PDF with the new layout.

### 2026-07-16 · ~16:10 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | CCBA boards · Phase 2 boundary |

**Changes**
- Removed **Sync warehouse snapshot** and **SKU map (setup)** from manager CCBA boards UI (`index.html`, `occd-boards.js`).
- Boards page now Inventory + OCCD only (save/submit drafts).
- Documented as **Phase 2 / Deferred**: `CCBA_INTEGRATION_BLUEPRINT.md` §0, `MODULE_TRACKER.md`, `SYSTEMS_BUILDING_GUIDE.md` §9.

**Notes**
- Do **not** put SKU map / warehouse sync back on daily boards until MyCCBA integration is intentionally activated. Backend APIs may still exist — UI is the gate.

### 2026-07-16 · ~16:05 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | CCBA boards calculations |

**Changes**
- Fixed board calcs after save/lock: locked cells keep data attrs; totals/%/variance read payload + live inputs.
- Inventory/outlet/sales totals computed into the table (not blank `—` after save).
- Save reloads boards from server so auto opening / on-order stay correct.
- Cleaner numeric column alignment on inventory + OCCD tables.

---

### 2026-07-16 · ~15:45 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | CCBA inventory · Unforgivable · Executive freeze |

**Changes**
- Inventory board **Actual opening stock** auto from manager 7am opening; **Qty on order** auto from open CCBA/Coca-Cola orders (both locked).
- Unforgivable packs: opening + on-order both automatic (same sources).
- Executives can open **Freeze accounts** and freeze/unfreeze users (not admin/executive, not self). Create/edit users remains Admin-only.

---

### 2026-07-16 · ~15:36 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local — update when pushed |
| **Area** | Manager nav |

**Changes**
- Removed **Coca-Cola delivery** from the manager sidebar. Page stays reachable from Stock taking (`+ Record Coca-Cola delivery` / Purchase link) with ← Stock taking back button.

---

### 2026-07-16 · ~15:30 (Africa/Kampala)

| | |
|--|--|
| **Who** | Team |
| **Push / ref** | Local presentation build — update this row when you push |
| **Area** | Manager stock book · Coca-Cola delivery · CCBA / OCCD boards · Catalog |

**Changes**
- Warehouse stock table: brand grouping + clearer row spacing (`assets/app.js`, `index.html`).
- Stock book **Purchase** locked and filled from Coca-Cola delivery quantities (`includes/depot_finance.php`, `api/depot/fetch_snapshot.php`, `api/depot/save_snapshot.php`, `assets/depot-snapshots.js`, `assets/manager-ops.js`).
- Catalog: **FANTA BLAST → FANTA PINEAPPLE**; added **1 LITRE COKE** (`1L-COKE`) (`includes/depot_catalog.php`, `includes/depot_finance.php`).
- CCBA boards lock after save/submit; **Edit** unlocks for corrections (`assets/occd-boards.js`, `api/occd/save_board.php`).
- Unforgivable packs **Opening stock** auto-filled from manager 7am opening snapshot; column read-only (`includes/occd_boards.php`, `assets/occd-boards.js`).

**Notes**
- Hard refresh (**Ctrl+F5**) after JS changes.
- First entry for this tracker — replace “Push / ref” when the work is committed/pushed.

---

<!-- Newest entries go above this line’s siblings — keep newest first. -->
