# Lapok DMS — Multi-Branch Blueprint

**Version:** 0.1 (working draft — the plan we use for now)  
**Date:** 1 August 2026  
**Audience:** Lapok leadership, managers, developers  
**Goal:** Keep one Lapok system serving **multiple depot branches** — each with its own manager, accountant (RDC), drivers, and cadets — while **executives stay shared** across all branches.

> **Working agreement:** this is the single reference document for the multi-branch design.
> Do not scatter branch decisions into `MODULE_TRACKER.md` or changelogs. Update this file
> when the shape changes. `MODULE_TRACKER.md` only keeps a one-line status pointer.

---

## 0. Current state — the system is single-depot today

Nothing in the codebase models more than one branch. These are the assumptions that will break first:

| Where | Current assumption | Multi-branch impact |
|-------|--------------------|----------------------|
| `users` | One global `role` enum (`admin/executive/manager/accountant/cadet/…`), no branch membership | Needs `branch_id`; a user belongs to exactly one branch (except executive/admin = all) |
| `delivery_trips`, `vehicles`, `routes`, `customers`, `orders` | No `branch_id` — all rows are implicitly the single depot's | Needs `branch_id` (trips can inherit it from vehicle/route) |
| `rdc_daily_sheets` | `UNIQUE uq_rdc_balance_date (balance_date)` — one sheet per date, system-wide | Two branches on the same date collide → key becomes `(branch_id, balance_date)` |
| `report_packets` | `to_role` only (`accountant/manager/executive`), no `to_user_id`, no branch | Pack goes to *any* manager; must target a specific branch manager + mark branch |
| `report_accountant_readiness` / `report_manager_readiness` (`includes/report_packets.php`) | Queries the whole table, not scoped | Every readiness count must filter by branch |
| Notifications (e.g. `api/trips/confirm_receive.php`) | Notifies **all** active managers/admins | Must notify only that branch's manager (and admins) |
| Navigation / permissions | `require_permission('reports')` + `api.js` `navItems` / `roleHomePage` are role-only, one depot's pages | Row-level scoping; executive gets a branch selector / consolidated view |
| `depot_stock_snapshots`, `depot_fixed_costs`, `rdc_month_end`, `staff_welfare_entries`, `manager_daily_boards`, exceptions, `audit_log` | No branch | Branch-scoped or branch-tagged as appropriate |

**Proof this is invisible today:** the only place "branch" appears in code is a comment —
`includes/fleet_tracking.php` (“Gulu branch”). There is no `branches` table.

---

## 1. Target shape

One Lapok install, many branches:

```
            ┌────────────── Lapok (one install) ──────────────┐
 Branch A:  cadets/drivers → accountant (RDC A) → manager A ──┼──┐
 Branch B:  cadets/drivers → accountant (RDC B) → manager B ──┼──┼──> Shared executives
 Branch C:  cadets/drivers → accountant (RDC C) → manager C ──┼──┘   (see all branches)
 admin:     cross-branch oversight everywhere                    │
```

**Rules**
- **Vertical** (within a branch): cadet → accountant → manager, all scoped to one branch.
- **Horizontal** (shared): executives and admins see **all** branches; executives get one
  brief per branch plus an optional consolidated view (summed P&L / sales / cash).
- A manager/accountant/cadet **never** sees another branch's rows.

---

## 2. Data model changes (concrete)

### 2.1 New `branches` table

```
branches
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  name          VARCHAR(80)  NOT NULL              -- e.g. "Gulu Main"
  code          VARCHAR(20)  NOT NULL UNIQUE       -- e.g. "GUL"
  region        VARCHAR(80)  DEFAULT NULL
  is_active     TINYINT(1)   NOT NULL DEFAULT 1
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
```

### 2.2 Add `branch_id` (nullable on core tables; NOT NULL once branches exist)

| Table | Column | Notes |
|-------|--------|-------|
| `users` | `branch_id` | `NULL` for executive/admin/old rows; cadet/driver/manager/accountant must have one |
| `vehicles` | `branch_id` | vehicles belong to a branch |
| `routes` | `branch_id` | routes belong to a branch |
| `customers` | `branch_id` | customers belong to a branch |
| `delivery_trips` | `branch_id` | can be derived from vehicle/route at insert time; keep a column for fast filtering |
| `orders` | `branch_id` | |
| `rdc_daily_sheets` | `branch_id` | **plus** drop `uq_rdc_balance_date` → `UNIQUE (branch_id, balance_date)` |
| `report_packets` | `branch_id`, `to_user_id` | branch for scoping; `to_user_id` so a pack targets a specific branch manager |
| `user_notifications` | `branch_id` | branch-scoped notify |
| `manager_daily_boards`, `depot_stock_snapshots`, `depot_fixed_costs`, `rdc_month_end`, `staff_welfare_entries` | `branch_id` | branch-scoped or branch-tagged |

Any other `UNIQUE (date)` key must become `UNIQUE (branch_id, date)` (e.g. daily boards).

---

## 3. Reporting chain — per branch, consolidation at executive

Today the chain is `field → accountant → manager → executive` with `report_packets.to_role`
only. With branches:

1. Cadet EOD packets stay `trip_id`-linked; the trip carries `branch_id` (derived).
2. Branch accountant's **manager pack** (`accountant_pack`) targets `to_user_id = <that branch's manager>` and carries `branch_id`.
3. Branch manager's **executive brief** (`manager_brief`) carries `branch_id` and targets the shared executive inbox.
4. **Executive view:** either
   - **(4a) Per-branch briefs only** — exec inbox lists one packet per branch (minimal change), **or**
   - **(4b) Consolidated brief** — exec dashboard also sums branches (sales, cash, variance, receivables) and links each branch's brief (more work, better for a head office).

**Readiness gates** (`report_accountant_readiness`, `report_manager_readiness`) take a `branch_id`
parameter and count only that branch's trips/sheets/packets.

**Decision needed (team):** 4a or 4b? This is the only new product surface; the rest is scoping.

---

## 4. Row-level permission scoping

- `require_permission('reports')` stays role-based for **which page**, then each query filters by branch.
- Add a helper (e.g. `user_branch_id($user)`) returning the user's branch, or `null` for exec/admin (no filter = all).
- Every scoped list endpoint must add `AND branch_id = ?`: `pending_cash.php`, `dispatch_log.php`,
  `api/rdc/list_sheets.php`, `fetch_sheet.php`, exceptions, welfare, month-end, stock snapshots, dashboard charts, sales/financial reports.
- Executive/admin dashboards aggregate across branches; managers/accountants/cadets never see a branch selector.

---

## 5. Module-by-module impact (rough)

| Module | Change |
|--------|--------|
| Auth / nav | `users.branch_id`; nav for exec = branch selector or per-branch tabs; others fixed to one branch |
| Dispatch / trips | `branch_id` on trips (inherited from vehicle/route); dispatch + notifications scoped to branch manager |
| Cash handover | `pending_cash` filtered by branch |
| RDC balancing | `fetch_sheet`/`list_sheets` scoped; sheet unique per `(branch, date)` |
| Report exchange | `report_packets.branch_id` + `to_user_id`; chain status per branch; exec consolidated (4a/4b) |
| Manager boards / stock | branch-scoped daily boards, opening/closing stock, deliveries |
| Month-end / welfare | branch-tagged registers; exec sees all branches |
| Reports / dashboards | charts, P&L, receivables filtered or aggregated by branch |
| Notifications | notify branch manager + all admins, never "all managers" |

---

## 6. Phased plan

### Phase 0 — "insurance now" (small, safe, future-proofs data)
- Add `branches` table + seed current depot as **Branch 1**.
- Add `users.branch_id`, `rdc_daily_sheets.branch_id` (+ change unique key to `(branch_id, balance_date)`).
- Backfill existing rows to Branch 1.
- **No UI change.** Everything keeps working as single-branch.
- Cost: one small migration + backfill; avoids a painful migration later when branch data already exists.

### Phase 1 — full multi-branch
- Add remaining `branch_id` columns (trips, vehicles, routes, customers, orders, boards, finance, packets).
- `report_packets.branch_id` + `to_user_id`; readiness gates take branch.
- Row-level scoping across all list endpoints.
- Executive per-branch briefs (4a), then consolidated view (4b) if agreed.
- Admin branch-management page (create branches, assign users/vehicles/routes).

---

## 7. Open decisions (resolve before Phase 1)

1. **Is multi-branch a near-term need, or just insurance?**
   - Near-term → go to Phase 1.
   - Not yet → do Phase 0 only; keep this document as the plan.
2. **Executive shape: 4a (per-branch briefs) or 4b (plus consolidated summary)?**
3. **One head-office manager over all branches, or branch managers reporting straight to exec?**
   (If a head-office layer exists, the chain gains a hop: branch manager → head-office → executive.)

---

## 8. Tracking

- Status of each phase is mirrored in `docs/MODULE_TRACKER.md` → Cross-cutting / integrations (one line).
- Detail lives **only** here.
