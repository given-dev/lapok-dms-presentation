# Outpost DMS — Presentation Build

**Product:** Outpost DMS (Depot Management System)  
**Folder:** `lapok-dms-presentation` (legacy folder name; product brand is Outpost)  
**Stack:** PHP APIs + MySQL + vanilla JS (`index.html`, `assets/*.js`)  
**Purpose:** Multi-depot stock, sales, cash handover, and leadership reporting. The **accountant (RDC)** and **cadet** modules are live — daily close, cadet reports, manager pack, and review workflow work end-to-end without external integrations.

Demo tenant data may still use LAPOK Ventures sample emails (`*@lapok.ug`) — that is customer demo data, not the product name.

External systems (CCBA, EFRIS, fleet GPS) are manual or deferred in this build.

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

Dashboard, edit requests, **exception center** (depot alerts), customers & receivables, stock & deliveries (including 7am opening stock + monthly fixed costs), PDF report exchange, sales/financial reports, **RDC daily sheets review** (approve / reject / reopen), **month-end** (view), **staff welfare** (add / resolve).

### Accountant (RDC)

| Area | Page | Notes |
|------|------|-------|
| Home (default) | `accountant-rdc-hub` | 2-step EOD checklist, cadet intake nudge, depot/welfare/cash nudges |
| Today's close | `accountant-rdc` | 3-step wizard, products grouped like depot sales book, cadet data by vehicle column, auto-save |
| Manager pack | `report-exchange` | One-tap send; gated on submitted balancing |
| Cash handover | `accountant-cash` | Confirm field trip cash |
| Month-end | `accountant-improvements` | Checklist + monthly notes — **DB sync** across roles |
| Staff welfare | `accountant-welfare` | Welfare register — **DB sync** across roles |
| Closing stock (7pm) | `accountant-rdc-hub` | Manual snapshot on Home |
| Depot alerts | `admin-exceptions` | Live exception queue (see below) |

**Sidebar — Today:** Home, Today's close  
**Sidebar — More:** Cash handover, Month-end, Staff welfare, Depot alerts

Receivables (`admin-customers`) are **manager-only**. Accountants see a Home nudge when outstanding credit ≥ **8M UGX**.

### Executive

Read-only dashboard, PDF inbox, reports, exception center, receivables overview, month-end (view), staff welfare (view).

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

Manager: RDC daily sheets → Approve / reject / reopen
```

Optional nudges on Home: field cash, high receivables, depot alerts, open welfare, cadet flags.  
Month-end banner (last 3 days of month) → Month-end tools.

Home shows a **Module live** chip when core migrations are applied (`api/rdc/health.php`).

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
# Schema + seed (first-time only — drops and recreates tables)
Get-Content database\schema.sql | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content database\seed.sql | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms

# Migrations (run in order)
1..12 | ForEach-Object {
  $f = "database\migrations\{0:D3}_*.sql" -f $_
  Get-ChildItem $f -ErrorAction SilentlyContinue | ForEach-Object {
    Get-Content $_.FullName | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms
  }
}
```

**Minimum for accountant module:**

| Migration | Purpose |
|-----------|---------|
| **008** | RDC daily balancing tables |
| **009** | Manager review workflow (approve / reject) |

**Recommended for full depot flow:**

| Migration | Purpose |
|-----------|---------|
| **010** | Opening/closing stock snapshots, monthly fixed costs |
| **011** | In-app notifications (cadet bell, depot messages) |
| **012** | Month-end workspace + staff welfare register (server sync) |

```powershell
Get-Content database\migrations\008_rdc_daily_balancing.sql | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms
Get-Content database\migrations\009_rdc_review_workflow.sql | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms
Get-Content database\migrations\010_depot_finance.sql | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms
Get-Content database\migrations\011_user_notifications.sql | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms
Get-Content database\migrations\012_rdc_ops_sync.sql | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms
```

Reset all demo passwords to `password123`:

```powershell
C:\xampp\php\php.exe scripts\setup_passwords.php
```

Verify RDC tables:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root lapok_dms -e "SHOW TABLES LIKE 'rdc_daily_sheets'; SHOW TABLES LIKE 'staff_welfare_entries';"
```

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Home shows **Setup needed** | Run migrations 008 and 009 |
| Month-end / welfare save fails | Run migration **012** |
| Cadet notifications empty | Run migration **011** |
| Balancing save/submit 500 | Confirm `rdc_daily_sheets` table exists |
| Cadet report not in RDC sheet | Vehicle must be dispatched; sheet must not be locked (submitted) |
| Stale UI after edits | Hard refresh **Ctrl+F5** (cache-busted `?v=` on key JS files) |
| Login fails | Run `scripts/setup_passwords.php`; check Apache + MySQL are running |
| API returns HTML instead of JSON | Check PHP errors in XAMPP; confirm URL matches your `htdocs` path |
| `php` not found in terminal | Use `C:\xampp\php\php.exe` full path |

---

## Reporting hierarchy

```
Cadet (field)  →  Accountant (RDC)  →  Manager  →  Executive
  daily report      daily balancing        (daily brief)   (PDF inbox)
                    + finance pack
```

---

## Phase 2 placeholders

Sidebar items under **Phase 2 — integrations** show a “coming in full build” card:

- Fleet map (GPS)
- CCBA daily boards
- Order via MyCCBA
- EFRIS / URA fiscal receipts

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
| Manager pack | `assets/report-exchange.js` |
| Cash handover | `assets/cash-handover.js` |
| Month-end | `assets/accountant-improvements.js`, `includes/rdc_month_end.php`, `api/rdc/fetch_month_end.php` |
| Staff welfare | `assets/accountant-welfare.js`, `includes/staff_welfare.php`, `api/welfare/*` |
| Depot alerts | `api/exceptions/fetch.php`, `assets/manager-ops.js` (`loadExceptionsPage`) |
| Manager RDC review | `assets/rdc-review.js` |

---

## Documentation

| Document | Description |
|----------|-------------|
| [`docs/MODULE_TRACKER.md`](docs/MODULE_TRACKER.md) | Live, planned, and suggested features by module |
| [`docs/RDC_ROLE.md`](docs/RDC_ROLE.md) | RDC duties mapped to Lapok modules |
| [`docs/LAPOK_PROJECT_PROPOSAL.md`](docs/LAPOK_PROJECT_PROPOSAL.md) | Timeline, costs, and business case |
| [`docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md`](docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md) | EFRIS / fiscal device integration plan |
| [`docs/CCBA_INTEGRATION_BLUEPRINT.md`](docs/CCBA_INTEGRATION_BLUEPRINT.md) | CCBA MyCCBA replenishment plan |
