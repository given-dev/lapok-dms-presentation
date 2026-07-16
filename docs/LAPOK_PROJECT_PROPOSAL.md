# Lapok DMS — Project Proposal for LAPOK Ventures

**Document version:** 1.0 · June 2026  
**Prepared for:** LAPOK Ventures (Gulu, Uganda) — Coca-Cola distribution  
**Presentation build:** `lapok-dms-presentation` (Manager · Accountant · Executive)  
**Full system:** `lapok-dms-full` (all roles + integrations)

---

## 1. Executive summary

Lapok DMS replaces spreadsheets, WhatsApp threads, and paper EOD sheets with one system for **stock, sales, cash handover, and leadership reporting**.

This proposal splits delivery into:

| Phase | Deliverable | Audience |
|-------|-------------|----------|
| **Phase 1 (presentation / now)** | Leadership demo — core ops without integrations | Manager, Accountant, Executive |
| **Phase 2** | Field roles (cadet, driver), **EFRIS fiscal devices**, CCBA sync, fleet GPS | Full depot |
| **Phase 3** | Hardening, training, production hosting, support | Go-live |

---

## 2. What Phase 1 includes (presentation build)

### Manager
- Live dashboard (warehouse, sales, pending approvals)
- Edit / cancel request queue
- Exception center (low stock, variances)
- Customer & receivables list
- Stock levels, Coca-Cola delivery intake, vehicle dispatch
- PDF report exchange (accountant ↔ manager ↔ executive)
- Sales & financial reports + CSV export

### Accountant
- Cash handover confirmation (field cash vs reported)
- Receivables view
- Finance pack PDF to manager
- Financial reports

### Executive
- Read-only executive dashboard
- PDF report inbox from manager
- Reports & exception monitoring

### Shown as placeholders (Phase 2)
- CCBA MyCCBA ordering & daily boards — see **`docs/CCBA_INTEGRATION_BLUEPRINT.md`** (full repo)
- **EFRIS / URA fiscal receipt import** — see **`docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md`**
- Live fleet map (GPS from field phones)

### Integration blueprints (technical detail)

| Document | Covers |
|----------|--------|
| [`docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md`](EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md) | Fiscal cash register + URA EFRIS — fiscal-first workflow, data model, device webhook, cadet UX |
| [`../lapok-dms-full/docs/CCBA_INTEGRATION_BLUEPRINT.md`](../lapok-dms-full/docs/CCBA_INTEGRATION_BLUEPRINT.md) | MyCCBA replenishment & stock sync |

---

## 3. Realistic build timeline

Assumes **1 lead developer + part-time LAPOK IT contact**, working in parallel with depot operations.

| Phase | Scope | Calendar time |
|-------|--------|----------------|
| **Phase 1a** | Presentation build (3 roles, core modules) | **Done** — ready to demo |
| **Phase 1b** | LAPOK feedback, UI copy, real customer/route data | 2–3 weeks |
| **Phase 2a** | Cadet & driver — EOD, fiscal receipt link, route sales | 4–6 weeks |
| **Phase 2b** | **EFRIS / fiscal device** — vendor webhook, product mapping, EOD Z-report reconcile | 3–5 weeks *(see EFRIS blueprint)* |
| **Phase 2c** | CCBA boards + replenishment workflow | 3–4 weeks |
| **Phase 2d** | Fleet GPS map | 2–3 weeks |
| **Phase 3** | UAT, training, production deploy, 30-day hypercare | 3–4 weeks |

**Total to full production:** approximately **4–6 months** from presentation sign-off.

---

## 4. Development cost (build labour)

Estimates in **Uganda Shillings (UGX)** at local professional rates.

| Item | Low | High | Notes |
|------|-----|------|-------|
| Phase 1 (presentation + polish) | 18,000,000 | 35,000,000 | Mostly complete |
| Phase 2 (field + integrations) | 35,000,000 | 75,000,000 | EFRIS vendor dependency |
| Phase 3 (UAT, training, deploy) | 8,000,000 | 18,000,000 | Includes on-site cadet training |
| **Total development** | **61,000,000** | **128,000,000** | ~**$16k – $34k USD** |

*Optional ongoing support:* **UGX 400,000 – 700,000 / month** (fixes, small changes, EFRIS/CCBA updates).

---

## 5. Production & running costs (money to operate)

Annual costs once live at one depot (Gulu).

| Item | One-time | Annual (UGX) | Notes |
|------|----------|--------------|-------|
| Server / VPS hosting | — | 1,200,000 – 3,600,000 | Local VPS or cloud; MySQL + PHP |
| Domain + SSL | 150,000 | 150,000 | e.g. dms.lapok.ug |
| Backup storage | — | 600,000 – 1,200,000 | Daily DB + file backups |
| Field phones / data | — | 2,400,000 – 6,000,000 | 4–8 cadets, mobile data for GPS + Lapok |
| Fiscal devices | *(existing)* | — | Already owned; Lapok connects via webhook |
| Training & manuals | 2,000,000 – 5,000,000 | 500,000 | Refresh training yearly |
| **Total production (year 1)** | **~2–5M setup** | **~5–12M / year** | Excludes developer support retainer |

---

## 6. What LAPOK gets out of it (value / ROI)

These are **operational returns**, not software resale.

| Benefit | Conservative estimate | How |
|---------|----------------------|-----|
| Reduced stock variance | UGX 3M – 10M / year | Loaded vs sold vs returned tracked per trip |
| Faster cash reconciliation | 2–4 hours/day saved | Accountant digital confirm vs manual tally |
| Less double entry | 15–30 min/sale × cadets | Fiscal-first → Lapok import |
| URA / EFRIS compliance | Risk reduction | Official receipts linked to Lapok orders |
| Manager reporting time | 1–2 hours/day saved | PDF exchange + live dashboards vs whiteboards only |

For a depot with **UGX 500M+ monthly throughput**, even **0.5% improvement in control** ≈ **UGX 2.5M/month** — the system can pay back development in **under 12 months** if adoption is strong.

### What the **developer/vendor** can earn (if not LAPOK-owned)

| Stream | Realistic 3-year total |
|--------|------------------------|
| Phase 2 completion fee | UGX 35M – 75M |
| Annual support contract | UGX 15M – 25M (3 years) |
| **Total vendor upside** | **UGX 50M – 100M** |

---

## 7. Recommendation

1. **Approve Phase 1 demo** (`lapok-dms-presentation`) with Manager, Accountant, Executive.  
2. **Sign off Phase 2 scope** — priority order: **cadet EOD → EFRIS fiscal device (blueprint §13) → CCBA → fleet map**.  
3. **Assign LAPOK IT contact** for **EFD vendor** (device webhook) and CCBA portal credentials.  
4. **Budget Phase 2** at **UGX 45M – 65M** all-in (build + first-year hosting + training).  
5. **Plan go-live** on a single route first (pilot), then roll out all tuktuks/trucks.

### Internal build sequence (presentation folder · July 2026)

Not a commercial change — team coding order inside `lapok-dms-presentation`:

1. **Cadet receive dispatch** (acknowledge load after manager dispatch).  
2. **Accountant (RDC) polish** — primary attacking point (Home → Today's close → cash → manager pack).  
3. Keep CCBA SKU map / warehouse sync off daily boards until MyCCBA integration phase (`CCBA_INTEGRATION_BLUEPRINT.md` §0).

See `docs/MODULE_TRACKER.md` → **Current build focus**.

---

## 8. Folder reference

| Path | Use |
|------|-----|
| `C:\xampp\htdocs\lapok-dms-presentation` | Show LAPOK leadership |
| `C:\xampp\htdocs\lapok-dms-full` | Active development — all modules |
| `docs/LAPOK_PROJECT_PROPOSAL.md` | This document — timeline, costs, ROI |
| `docs/EFRIS_FISCAL_INTEGRATION_BLUEPRINT.md` | Fiscal device + EFRIS integration plan |
| `../lapok-dms-full/docs/CCBA_INTEGRATION_BLUEPRINT.md` | CCBA / MyCCBA integration plan |

---

*Figures are planning estimates for internal discussion — adjust after LAPOK confirms depot size, fleet count, and integration vendor quotes.*
