# Truehost / cPanel imports

cPanel users cannot run `CREATE DATABASE` / `USE lapok_dms`.

**Before import:** in phpMyAdmin, click **your** database on the left (the one from Database Wizard).

Then Import in number order:

1. `01_schema.sql`
2. `02_seed.sql`
3. `03_…` through `21_018_…` (skip `22_fix_encoding.sql`)

`.env` on the server uses your cPanel DB name/user/password — not `lapok_dms` unless that is literally your DB name.
