# Lapok DMS — Module & Feature Tracker

**Build:** `lapok-dms-presentation` (accountant module **live**)  
**Last updated:** June 2026  
**Purpose:** Single place to track what is live, what is planned, and what we may add next. Update this when modules ship or scope changes.

**Status key**

| Status | Meaning |
|--------|---------|
| **Live** | Usable in this build |
| **Partial** | Works but limited / manual / demo data |
| **Planned** | Agreed for a future phase in this repo |
| **Deferred** | Phase 2 — waiting on external systems or full build |
| **Suggested** | Good idea, not yet scheduled |

---

## Accountant (RDC) module

| Feature | Page / area | Status | Notes |
|---------|-------------|--------|-------|
| RDC Home (default) | `accountant-rdc-hub` | **Live** | 2-step EOD checklist, Continue CTA, optional nudges (cash, receivables, depot), month-end banner |
| Today's close (balancing) | `accountant-rdc` | **Live** | 3-step wizard, sales grouped (CSD / ENERGY / JUICE / VAD / WATER / OTHER), auto-save, sample-data banner, finish-today panel after submit |
| Manager pack | `report-exchange` | **Live** | Accountant: one-tap send; gated on submitted balancing; View PDF from Home |
| Cash handover | `accountant-cash` | **Live** | Optional — More menu + Home nudge when trips pending |
| Depot alerts | `admin-exceptions` | **Live** | Live queue from DB — stock, cash, cadet flags, welfare, edits, sales |
| Month-end tools | `accountant-improvements` | **Live** | DB sync — accountant edits; manager/executive/admin view |
| RDC sheet CSV export | `export_csv.php?type=rdc_sheet` | **Live** | From Home history + post-submit panel |
| Receivables | `admin-customers` | **Live** | **Manager-only** nav; accountant sees Home nudge if total ≥ 8M UGX |
| Staff welfare register | `accountant-welfare` | **Live** | DB sync — accountant/manager write; executive view |
| Module health check | `api/rdc/health.php` | **Live** | Home chip — verifies migrations 008/009/012 |
| Cadet dashboard | `cadet-dashboard` | **Live** | Trip, load summary, report status — home for cadet |
| Cadet notifications | Bell + dashboard | **Live** | Receive from manager/RDC/admin; migration `011` |
| Cadet daily report | `cadet-daily` | **Live** | Depot catalog → auto-sync into RDC **vehicle column** for assigned trip |
| Opening stock (7am) | `manager-stock` | **Live** | Manager manual snapshot |
| Closing stock (7pm) | `accountant-rdc-hub` | **Live** | Accountant manual snapshot — products grouped like cadet LAPOK book |
| Monthly fixed costs | `manager-stock` | **Live** | Rent, salaries, utilities, security, other |
| Director daily brief | `director-brief` | **Live** | P&L, shortages, expense ratio, 7pm readiness |

### Accountant — suggested additions

| Feature | Status | Notes |
|---------|--------|-------|
| Bank feed import (real) | **Suggested** | Command center has manual toggle only |
| Invoice OCR / auto-coding | **Suggested** | Manual capture workflow today |
| Recurring journal automation | **Suggested** | Toggle in command center, no backend |
| Document upload portal (files) | **Suggested** | Evidence table is text/status only |
| Budget vs actual report template | **Suggested** | Removed from lean command center |
| Tax summary template | **Suggested** | Removed from lean command center |
| AP due tracking (real AP ledger) | **Suggested** | Needs AP source table/API |
| Cash runway / burn KPIs | **Suggested** | Needs cash reserve + AP assumptions |
| Integration health panel | **Live** | Home `Module live` chip via `api/rdc/health.php` |
| Auto-generated monthly summary | **Suggested** | Manual textarea in command center |
| In-app notifications on RDC review | **Suggested** | Home alerts today; push/email optional later |

---

## Manager module (RDC / finance overlap)

| Feature | Page / area | Status | Notes |
|---------|-------------|--------|-------|
| Manager dashboard | `manager-dashboard` | **Live** | Ops metrics, handoff, pending sales |
| RDC daily sheets review queue | `manager-rdc-review` | **Live** | Start review, approve, reject, reopen |
| Edit requests | `admin-editreqs` | **Live** | Approve / reject field edits |
| Exception center | `admin-exceptions` | **Live** | Cross-role queue |
| Customers & receivables | `admin-customers` | **Live** | Live totals + customer table |
| Stock & deliveries | `manager-stock` | **Live** | Receive, dispatch |
| PDF report exchange | `report-exchange` | **Live** | Full inbox/outbox chain |
| Reports & analytics | `manager-reports` | **Live** | |

### Manager — suggested additions

| Feature | Status | Notes |
|---------|--------|-------|
| Pending RDC reviews counter on dashboard | **Suggested** | Quick visibility without opening queue |
| RDC review comment thread | **Suggested** | Multiple notes per sheet |
| Bulk approve month-end RDC sheets | **Suggested** | If daily approve is too granular |

---

## Executive module

| Feature | Page / area | Status | Notes |
|---------|-------------|--------|-------|
| Executive dashboard | `admin-dashboard` | **Live** | Read-only |
| PDF inbox | `report-exchange` | **Live** | Manager brief |
| Reports & analytics | `admin-reports` | **Live** | |
| Exception center | `admin-exceptions` | **Live** | |
| Receivables overview | `admin-customers` | **Live** | |

---

## Accountant daily close flow (current)

```
Home → Continue
  Step 1: Today's close (wizard) → Submit
  Finish panel → Send manager pack (one tap) OR Home
Optional (nudges only): cash handover, depot alerts, high receivables
More menu: cash handover, month-end, staff welfare, depot alerts
Month-end (last 3 days): banner on Home → accountant-improvements
```

---

## Cross-cutting / integrations

| Item | Status | Notes |
|------|--------|-------|
| CCBA bank feed | **Deferred** | Phase 2 |
| EFRIS fiscal | **Deferred** | Phase 2 — see `EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md` |
| PDF report chain | **Live** | Accountant → Manager → Executive |

---

## Admin module

| Feature | Page / area | Status | Notes |
|---------|-------------|--------|-------|
| Admin dashboard | `admin-dashboard` | **Live** | |
| User management | `admin-users` | **Live** | |
| Audit log | `admin-audit` | **Live** | |
| Exception center | `admin-exceptions` | **Live** | |

---

## Field user module

| Feature | Page / area | Status | Notes |
|---------|-------------|--------|-------|
| Limited dashboard | `manager-dashboard` | **Partial** | Field login subset |
