# RDC Role — Resident Depot Commissioner

**LAPOK internal title:** Accountant (RDC) in Lapok DMS  
**System user:** `accountant@lapok.ug`  
**Primary daily tool:** RDC daily balancing (replaces Excel workbook)  
**Feature tracker:** [`MODULE_TRACKER.md`](MODULE_TRACKER.md) — live, planned, and suggested modules

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
| **Accounting** | Daily balancing, cash, receivables | **RDC daily balancing**, cash handover, financial reports | Live |
| **Welfare** | Staff welfare matters | Staff welfare register | **Live** — local register in More menu |

---

## RDC daily workflow in Lapok

```
Morning → Check site monitoring (exceptions, stock)
During day → Coordinate field cash / client issues as needed
End of day → RDC daily balancing → Submit to manager
         → Cash handover confirmations
         → PDF finance pack when required
```

---

## Navigation (Accountant · RDC)

| Menu item | Purpose |
|-----------|---------|
| **RDC overview** | Role hub — links to all RDC tools |
| **Daily balancing** | Sales × vehicle, recovery, expenses, cash variance |
| **Cash handover** | Confirm cadet cash vs reported |
| **Receivables** | Customer balances |
| **Site monitoring** | Low stock, pending issues |
| **Stock & assets** | View warehouse / vehicle stock (read-only focus) |
| **Staff welfare** | Welfare register (More menu) |
| **PDF reports** | Send packs to manager |
| **Financial reports** | Summaries and exports |

---

## Why not a separate “RDC” login?

One person holds the role today. Using **`accountant`** in the database with display label **Accountant (RDC)** avoids duplicate users and matches payroll/IT. If LAPOK later splits finance vs depot commissioner, we can add a dedicated `rdc` role without changing the balancing module.

---

*Update this document when LAPOK confirms welfare workflow and any security/incident logging requirements.*
