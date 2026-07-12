# Outpost DMS — Presentation Build

**Product:** Outpost DMS (Depot Management System)  
**Folder:** `lapok-dms-presentation` (legacy folder name; product brand is Outpost)  
**Stack:** PHP APIs + MySQL + vanilla JS (`index.html`, `assets/*.js`)  
**Purpose:** Multi-depot stock, sales, cash handover, and leadership reporting. Cadet, accountant (RDC), manager, executive, and admin workflows are live end-to-end — including stock taking, RDC review, CCBA boards, PDF report chain, and role ownership guards.

Demo tenant data may still use LAPOK Ventures sample emails (`*@lapok.ug`) — that is customer demo data, not the product name.

External systems (CCBA, EFRIS, fleet GPS) are manual or deferred in this build.

**Ownership (who does what):** see [`docs/SYSTEMS_BUILDING_GUIDE.md`](docs/SYSTEMS_BUILDING_GUIDE.md) §9. Wrong-role pages bounce home with a toast — that is intentional.

---

## Quick start (XAMPP)

1. Start **Apache** and **MySQL** in XAMPP.
2. Create and seed the database (see [Database setup](#database-setup) below).
3. Open **http://localhost/project/lapok-dms-presentation/login.html**
4. Sign in as `accountant@lapok.ug` / `password123`
5. After JS changes, hard refresh with **Ctrl+F5**.

---

## Who can log in

| Role | Allowed in this build |
|------|------------------------|
| Admin | Yes |
| Manager | Yes |
| Accountant (RDC) | Yes |
| Executive | Yes |
| Cadet | Yes |
| Driver | Blocked (`presentation-config.js`) |
| Field user | Limited subset |

All demo users share password **`password123`** (see [Demo accounts](#demo-accounts)).

---

## Demo accounts

| Role | Email |
|------|-------|
| Accountant (RDC) | `accountant@lapok.ug` |
| Manager | `manager@lapok.ug` |
| Executive | `executive@lapok.ug` |
| Admin | `admin@lapok.ug` |
| Cadet | `cadet@lapok.ug` |

---

## What works

### Cadet

| Area | Page | Notes |
|------|------|-------|
| Dashboard (home) | `cadet-dashboard` | Trip status, load summary, messages from depot |
| Today's report | `cadet-daily` | All depot products grouped like depot sales book (CSD, ENERGY, JUICE, VAD, WATER, OTHER) |
| Notifications | Bell icon | Receive messages from manager / RDC / admin |

On submit, sales, expenses, and cash **auto-sync** into the accountant's **Today's close** sheet on the **vehicle column** for the assigned trip.

### Manager

**Sidebar — Daily:** Dashboard, Stock taking, **CCBA boards**, RDC daily sheets, PDF reports  
**Sidebar — Approvals:** Exception center, Edit requests  
**Sidebar — Business:** Customers & receivables, Order via MyCCBA, Reports & analytics  
**Sidebar — Monthly:** Month-end (view checklist + manager fixed costs), Staff welfare  

Stock page is daily only: opening/closing counts + delivery confirmation. CCBA boards (inventory + OCCD) feed the executive brief when submitted. Dashboard shows an ordered **daily checklist** with RDC pending review count.

### Accountant (RDC)

| Area | Page | Notes |
|------|------|-------|
| Home (default) | `accountant-rdc-hub` | 2-step EOD checklist, cadet intake nudge, depot/welfare/cash nudges |
| Today's close | `accountant-rdc` | 3-step wizard, products grouped like depot sales book, cadet data by vehicle column, auto-save |
| Manager pack | `report-exchange` | One-tap send; gated on submitted balancing |
| Cash handover | `accountant-cash` | Confirm field trip cash |
| Month-end | `accountant-improvements` | Checklist + monthly notes — **DB sync** across roles |
| Staff welfare | `accountant-welfare` | Welfare register — **DB sync** across roles |
| Closing stock (7pm) | `accountant-rdc-hub` | **View only** — manager enters counts on `manager-stock` |
| Depot alerts | `admin-exceptions` | Live exception queue (see below) |

**Sidebar — Today:** Home, Today's close  
**Sidebar — More:** Cash handover, Month-end, Staff welfare, Depot alerts

Receivables (`admin-customers`) are **manager-only**. Accountants see a Home nudge when outstanding credit ≥ **8M UGX**.

### Executive

Read-only board/MD view. Login: `executive@lapok.ug` / `password123`.

**Sidebar — Overview:** Executive dashboard (daily checklist + P&L widget), Director brief (date picker / today / yesterday)  
**Sidebar — Reports:** PDF reports (acknowledge manager pack), Reports & analytics  
**Sidebar — Monitoring:** Exception center (monitor only), Receivables overview, Staff welfare (view), Month-end (view)

Daily flow: Director brief → acknowledge PDF pack → scan exceptions / receivables / welfare. Admin action center is hidden on this home.

**Bell:** when the manager sends (or replaces) an executive brief, executives get an unread notification linking to PDF reports. Existing open packs are backfilled on bell refresh.

### Admin

Login: `admin@lapok.ug` / `password123`.

**Sidebar:** Admin dashboard, User management, Audit log, Customers & receivables, Edit requests, Exception center, PDF reports, Reports & analytics, Month-end, Staff welfare.

Dashboard shows an **Admin daily checklist** (users → edit requests → exceptions → reporting-chain health → audit → welfare/month-end) plus the action center. Charts (sales/expenses/profit, product share MTD, monthly bars) use live API data. Admin does not own day-to-day depot close (RDC / Manager do).

### Depot alerts (exception center)

**Not a separate ticket system** — a live radar built on each refresh from real depot data:

| Type | Source | Fix in |
|------|--------|--------|
| Stock | Products below minimum | Stock & deliveries |
| Cash | Returned trip, cash not confirmed | Cash handover |
| Cadet report | Today's flagged cadet report | Today's close |
| Welfare | Open welfare entry | Staff welfare |
| Edit request | Pending field edit | Edit requests |
| Sale | Pending order | Manager dashboard |

When the underlying issue is fixed, the row disappears on refresh. Manager dashboard and accountant Home also surface summary counts.

### Daily close flow (accountant)

```
Manager dispatches vehicle → Cadet submits today's report
  → Auto-sync into RDC sheet (vehicle column: sales, fuel/other, cash)

Accountant: Home → Continue
  → Today's close (wizard: sales → expenses/cash → review) → Submit
  → Finish panel → Send manager pack (one tap) OR Back to Home

Manager: Stock taking (opening first, closing from 6:30 PM) + delivery confirmation
  → RDC daily sheets → Approve / reject / reopen
```

Optional nudges on Home: field cash, high receivables, depot alerts, open welfare, cadet flags.  
Month-end banner (last 3 days of month) → Month-end tools.

Home shows a **Module live** chip when core migrations are applied (`api/rdc/health.php`).

### Manager stock-taking control

- **Opening stock (7am):** manager/admin enters on `manager-stock`.
- **Closing stock (7pm):** manager/admin enters on `manager-stock`, but remains **locked until 6:30 PM**.
- RDC sees closing stock and notes as **read-only** for balancing and reporting.
- Manager confirms supplier deliveries separately from stock entry.

---

## Glossary

| Term | Meaning |
|------|---------|
| **MTD** | Month To Date — from the 1st of this month through today |
| **YTD** | Year To Date — from 1 January through today |
| **RDC** | Resident depot accountant role (accountant login) |
| **Depot sales book** | Product grouping: CSD, ENERGY, JUICE, VAD, WATER, OTHER |

---

## Database setup

Database name: **`lapok_dms`**

From the `lapok-dms-presentation` folder in PowerShell:

```powershell
# Schema + seed (first-time only — drops/recreates core tables)
Get-Content database\schema.sql | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content database\seed.sql | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms

# Apply every migration (001–014). Safe to re-run: most use IF NOT EXISTS / ADD COLUMN IF NOT EXISTS.
Get-ChildItem database\migrations\*.sql | Sort-Object Name | ForEach-Object {
  Write-Host "Applying $($_.Name)…"
  Get-Content $_.FullName | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms
}

# Demo passwords → password123
C:\xampp\php\php.exe scripts\setup_passwords.php
```

### Migration catalog

| # | File | Purpose |
|---|------|---------|
| **001** | `001_phase1_polish.sql` | Role enum + legacy column polish (may no-op if schema already evolved) |
| **002** | `002_ccba_integration.sql` | CCBA product map, orders, refs |
| **003** | `003_occd_daily_boards.sql` | Manager daily boards (inventory + OCCD) |
| **004a** | `004_efris_integration.sql` | EFRIS product map + receipt import tables |
| **004b** | `004_fleet_tracking.sql` | Vehicle GPS pings / route geo (Phase 2 map) |
| **005** | `005_report_exchange.sql` | PDF report packets (accountant → manager → executive) |
| **006** | `006_trim_demo_users.sql` | Trim/align demo users |
| **007** | `007_field_role_emails.sql` | Field/cadet demo emails |
| **008** | `008_rdc_daily_balancing.sql` | RDC daily sheets |
| **009** | `009_rdc_review_workflow.sql` | Manager approve / reject / reopen |
| **010** | `010_depot_finance.sql` | Opening/closing stock snapshots + monthly fixed costs |
| **011** | `011_user_notifications.sql` | In-app notification bell |
| **012** | `012_rdc_ops_sync.sql` | Month-end workspace + staff welfare |
| **013** | `013_delivery_confirmation.sql` | Supplier delivery confirm status |
| **014** | `014_rdc_review_comments.sql` | RDC review comment threads |

**Required for this presentation build:** **008–014** (plus **003** / **005** / **011** if those features are empty).

Verify core tables:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root lapok_dms -e "SHOW TABLES LIKE 'rdc_%'; SHOW TABLES LIKE 'report_packets'; SHOW TABLES LIKE 'user_notifications'; SHOW TABLES LIKE 'manager_daily_boards'; SHOW TABLES LIKE 'depot_stock_snapshots'; SHOW TABLES LIKE 'staff_welfare_entries';"
```

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Home shows **Setup needed** | Run migrations **008** and **009** |
| Month-end / welfare save fails | Run migration **012** |
| Cadet notifications empty | Run migration **011** |
| RDC comments panel warns | Run migration **014** |
| Director brief / stock snapshots missing | Run migration **010** |
| Balancing save/submit 500 | Confirm `rdc_daily_sheets` exists |
| Cadet report not in RDC sheet | Vehicle must be dispatched; sheet must not be locked (submitted) |
| Stale UI after edits | Hard refresh **Ctrl+F5** (scripts use `?v=` cache bust) |
| Login fails | Run `scripts/setup_passwords.php`; check Apache + MySQL |
| API returns HTML instead of JSON | Check PHP errors in XAMPP; confirm `htdocs` path |
| `php` not found in terminal | Use `C:\xampp\php\php.exe` full path |

---

## Reporting hierarchy

```
Cadet (field)  →  Accountant (RDC)  →  Manager  →  Executive
  daily report      daily balancing      stock + RDC review   Director brief
                    + manager pack       + executive brief    + PDF acknowledge
```

Admin owns users, audit, and system health — not day-to-day depot close.

---

## Phase 2 (deferred integrations)

Still deferred / placeholder (not required for the role walkthrough):

- Fleet map GPS live tracking (tables from **004b** exist; UI deferred)
- Full CCBA bank/portal automation (boards + MyCCBA order flow are **live manual** in this build)
- EFRIS / URA fiscal device sync (tables from **004a**; UI deferred)

See `docs/CCBA_INTEGRATION_BLUEPRINT.md` and `docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md`.

---

## Key files

| Area | Files |
|------|-------|
| Nav / API client | `assets/api.js`, `assets/app.js`, `assets/phase45.js` |
| Build config | `assets/presentation-config.js` |
| Depot product catalog | `includes/depot_catalog.php` |
| Cadet | `assets/cadet-dashboard.js`, `assets/cadet-daily.js`, `api/cadet/*`, `includes/cadet_reports.php` |
| Notifications | `assets/notifications.js`, `includes/notifications.php`, `api/notifications/*` |
| Accountant Home | `assets/rdc-hub.js`, `#page-accountant-rdc-hub` |
| Daily balancing | `assets/rdc-balancing.js`, `includes/rdc_balancing.php`, `api/rdc/*` |
| Stock snapshots | `assets/depot-snapshots.js`, `includes/depot_finance.php`, `api/depot/*` |
| Manager ops / exceptions | `assets/manager-ops.js` |
| Manager RDC review | `assets/rdc-review.js`, `api/rdc/bulk_approve.php`, `api/rdc/comments_*.php` |
| CCBA boards / MyCCBA order | `assets/occd-boards.js`, `assets/ccba.js`, `includes/occd_boards.php` |
| PDF report chain | `assets/report-exchange.js`, `includes/report_packets.php`, `includes/branded_export.php` |
| Director brief | `assets/director-brief.js`, `api/reports/director_snapshot.php` |
| Cash handover | `assets/cash-handover.js` |
| Month-end | `assets/accountant-improvements.js`, `includes/rdc_month_end.php` |
| Staff welfare | `assets/accountant-welfare.js`, `includes/staff_welfare.php` |
| Ownership / roles | `docs/SYSTEMS_BUILDING_GUIDE.md` §9 |

---

## Documentation

| Document | Description |
|----------|-------------|
| [`docs/MODULE_TRACKER.md`](docs/MODULE_TRACKER.md) | Live, planned, and deferred features by module |
| [`docs/SYSTEMS_BUILDING_GUIDE.md`](docs/SYSTEMS_BUILDING_GUIDE.md) | Stack, hosting, security vocabulary + ownership table |
| [`docs/RDC_ROLE.md`](docs/RDC_ROLE.md) | RDC duties mapped to modules |
| [`docs/LAPOK_PROJECT_PROPOSAL.md`](docs/LAPOK_PROJECT_PROPOSAL.md) | Timeline, costs, and business case |
| [`docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md`](docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md) | EFRIS / fiscal device plan |
| [`docs/CCBA_INTEGRATION_BLUEPRINT.md`](docs/CCBA_INTEGRATION_BLUEPRINT.md) | CCBA MyCCBA replenishment plan |
