# RDC Role — Resident Depot Commissioner

**LAPOK internal title:** Accountant (RDC) in Lapok DMS  
**System user:** `accountant@lapok.ug`  
**Primary daily tool:** RDC daily balancing (replaces Excel workbook)  
**Feature tracker:** [`MODULE_TRACKER.md`](MODULE_TRACKER.md) — live, planned, and suggested modules  

**Build note (July 2026):** After **cadet receive-dispatch**, the **Accountant (RDC) account is the primary attacking point** — polish Home → Today's close → cash handover → manager pack end-to-end.

---

## What “RDC” means at the depot

The RDC is the **resident authority at the depot** — not only bookkeeping. She confirmed accounting is part of the role, plus **staff welfare**. Lapok groups all of this under the **Accountant (RDC)** login.

---

## Duties (from LAPOK) → Lapok modules

| Duty | In plain language | Lapok module | Status |
|------|-------------------|--------------|--------|
| **Law & order** | Keep order at the site; handle incidents calmly | Site monitoring (exception center) | Live — read alerts |
| **Protect premises & assets** | Guard stock, vehicles, depot property | Stock & assets (view), low-stock alerts | Live — view stock |
| **Monitor daily activities** | Watch what happens in and outside the depot | Operations dashboard, exceptions, EOD intake | Live / partial |
| **Coordinate staff ↔ directors ↔ clients ↔ community** | Bridge between field, management, customers | PDF report exchange, receivables, daily balancing submit | Live |
| **Transparent, secure storage** | Storage under directors’ oversight | Stock levels, future audit trail | Live / planned |
| **Accounting** | Daily balancing, cash, receivables | **RDC daily balancing**, cash handover, financial reports | Live — **polish focus** |
| **Welfare** | Staff welfare matters | Staff welfare register | **Live** — local register in More menu |

---

## RDC daily workflow in Lapok

```
Morning → Check Home nudges (exceptions, cadet intake, cash)
During day → Coordinate field cash / client issues as needed
End of day → Today's close (wizard) → Submit
         → Cash handover confirmations (if trips pending)
         → One-tap manager pack (PDF) when balancing is submitted
```

Cadet reports auto-sync into the balancing sheet **vehicle column** after the cadet submits today's report (requires manager dispatch first).

---

## Navigation (Accountant · RDC)

| Menu item | Purpose |
|-----------|---------|
| **Home** | EOD checklist, Continue CTA, nudges |
| **Today's close** | Sales × vehicle, recovery, expenses, cash variance |
| **Cash handover** | Confirm cadet cash vs reported (More menu) |
| **Depot alerts** | Low stock, pending issues (exception center) |
| **Month-end** | Checklist + notes (More menu) |
| **Staff welfare** | Welfare register (More menu) |
| **PDF reports** | Send manager pack |

Receivables are **manager-owned** in nav; RDC sees a Home nudge when credit is high (≥ 8M UGX). Opening/closing stock are **manager-owned** (RDC view-only on hub).

---

## Why not a separate “RDC” login?

One person holds the role today. Using **`accountant`** in the database with display label **Accountant (RDC)** avoids duplicate users and matches payroll/IT. If LAPOK later splits finance vs depot commissioner, we can add a dedicated `rdc` role without changing the balancing module.

---

## Related docs

| Doc | Use |
|-----|-----|
| [`MODULE_TRACKER.md`](MODULE_TRACKER.md) | Live vs planned; **Current build focus** |
| [`SYSTEMS_BUILDING_GUIDE.md`](SYSTEMS_BUILDING_GUIDE.md) §9 | Role ownership table |
| [`../README.md`](../README.md) | Quick start, demo login |

---

*Update this document when LAPOK confirms welfare workflow and any security/incident logging requirements.*
