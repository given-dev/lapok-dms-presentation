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
