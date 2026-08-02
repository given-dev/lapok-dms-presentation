# Truehost / cPanel imports

cPanel users cannot run `CREATE DATABASE` / `USE lapok_dms`.

## Live deployment (2 Aug 2026) — how the running system was set up

The production system (`dms.afriboards.com`, DB `afriboar_lapok`) was deployed
with a **single full dump**, not the numbered pack below:

1. Locally, all operational data was wiped (accounts + catalog + vehicles kept).
2. Export via `mysqldump` using **`--result-file`** (plain UTF-8). Do **not**
   redirect with PowerShell `>` — that writes UTF-16 and phpMyAdmin rejects it
   with "#1064 … near '-' at line 1" / "Unexpected character" on every line.
3. In cPanel: **MySQL Databases** → create DB + user (names get your cPanel
   username prefix), grant ALL PRIVILEGES.
4. phpMyAdmin → click the new DB on the left (select DB first) → Import →
   upload the dump.
5. Upload runtime files to the subdomain's document root, create `.env` with the
   cPanel DB credentials, set PHP 8.2, make `storage/` writable.

The numbered pack below remains the correct path for a **fresh install**.

---

**Before import:** in phpMyAdmin, click **your** database on the left (the one from Database Wizard).

Then Import in number order:

1. `01_schema.sql`
2. `02_seed.sql`
3. `03_…` through `21_018_…` (skip `22_fix_encoding.sql`)

`.env` on the server uses your cPanel DB name/user/password — not `lapok_dms` unless that is literally your DB name.

## "Month-end tables not ready" / "request failed (500)" on Month-end or Staff welfare

This 500 means migration 012 was never applied to the hosted database
(the tables `rdc_month_end` and `staff_welfare_entries` are missing).
It is safe to import `15_012_rdc_ops_sync.sql` **by itself** at any time —
it uses `CREATE TABLE IF NOT EXISTS` and does not touch existing data.

If Month-end / welfare still error afterwards, later migrations are probably
missing too. Import the remaining hosting files **in number order**
(`16_013_…` → `21_018_…`). Two notes:

- `16_013_…` uses `ADD COLUMN IF NOT EXISTS` (safe to re-run).
- `20_017_…` deletes demo operational data (orders/trips/packets) — run it only
  on a fresh hosted DB, never on a DB with real customer history.
- Do **not** re-run `12_009_…` (plain `ADD COLUMN`, fails if already applied).

You can verify from the app via the RDC health endpoint
(`api/rdc/health.php`) — it reports `rdc_ops_sync: true` once both tables exist.
