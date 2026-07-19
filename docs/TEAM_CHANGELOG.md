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
