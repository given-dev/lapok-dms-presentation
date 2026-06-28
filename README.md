# Lapok DMS — Leadership Presentation Build

**Folder:** `lapok-dms-presentation`  
**Purpose:** Stakeholder demo for LAPOK Ventures — warehouse, finance, depot oversight (RDC), and reporting **without live integrations**.

## Who can log in

| Email | Role |
|-------|------|
| manager@lapok.ug | Manager |
| accountant@lapok.ug | Accountant (RDC) |
| executive@lapok.ug | Executive |

Password: **`password123`**

Cadet, driver, and admin accounts are **blocked** in this build.

## What works (no external integrations)

| Role | Live features |
|------|----------------|
| **Manager** | Dashboard, edit requests, exception center, customers, stock & deliveries, dispatch, PDF report exchange, sales/financial reports, **view submitted RDC daily sheets** |
| **Accountant (RDC)** | **RDC overview hub**, **daily balancing** (replaces Excel workbook), cash handover, receivables, **site monitoring**, **stock & assets (view)**, financial reports, PDF pack to manager |
| **Executive** | Read-only dashboard, PDF inbox, reports, exception overview |

### Accountant (RDC) — Resident Depot Commissioner

The **Accountant (RDC)** login covers the full depot commissioner role at LAPOK — not bookkeeping alone. On login she lands on **RDC overview**, a hub linking all duty areas:

| Duty (LAPOK) | Lapok page |
|--------------|------------|
| Law & order / site monitoring | Site monitoring (exception center) |
| Protect premises & assets | Stock & assets (view) |
| Monitor daily activities | Operations dashboard + exceptions |
| Coordinate staff ↔ directors ↔ clients | PDF reports, receivables, daily balancing submit |
| Accounting | **Daily balancing**, cash handover, financial reports |
| Staff welfare | Staff welfare register (Phase 2 placeholder) |

Role documentation: [`docs/RDC_ROLE.md`](docs/RDC_ROLE.md)

**Key files:** `assets/rdc-balancing.js`, `includes/rdc_balancing.php`, `api/rdc/*`, `page-accountant-rdc-hub` in `index.html`

## Placeholders (Phase 2 — full build)

These appear in the sidebar under **Phase 2 — integrations** with a “coming in full build” card:

- Fleet map (GPS)
- CCBA daily boards
- Order via MyCCBA
- EFRIS / URA fiscal receipts
- Staff welfare register (full module)

## Run locally

Same database as the full project (`lapok_dms`). From XAMPP:

**http://localhost/lapok-dms-presentation/login.html**

### Database setup

Use the same DB steps as [`lapok-dms-full/README.md`](../lapok-dms-full/README.md). At minimum run schema, seed, and migrations **002–008** (008 adds RDC daily balancing):

```powershell
Get-Content database\migrations\008_rdc_daily_balancing.sql | C:\xampp\mysql\bin\mysql.exe -u root lapok_dms
```

## Reporting hierarchy

```
Field agents  →  Accountant (RDC)  →  Manager  →  Executive
     (EOD)         daily balancing         (daily brief)   (PDF inbox)
                   + finance pack
```

## Business case document

See **`docs/LAPOK_PROJECT_PROPOSAL.md`** — timeline, production costs, and expected value for LAPOK.

## Integration blueprints

| Document | Description |
|----------|-------------|
| [`docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md`](docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md) | Fiscal cash register + URA EFRIS — fiscal-first workflow |
| [`../lapok-dms-full/docs/CCBA_INTEGRATION_BLUEPRINT.md`](../lapok-dms-full/docs/CCBA_INTEGRATION_BLUEPRINT.md) | CCBA MyCCBA replenishment (full repo) |
| [`docs/RDC_ROLE.md`](docs/RDC_ROLE.md) | RDC duties → Lapok modules |

## Related folders

| Folder | Description |
|--------|-------------|
| `lapok-dms-full` | Complete system — all roles, CCBA, EFRIS, fleet, field cadet/driver |
| `lapok-dms-presentation` | This demo (3 leadership roles, integration placeholders) |
| `lapok-dms` | Active development workspace (same scope as full) |
