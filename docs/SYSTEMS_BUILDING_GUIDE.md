# Systems Building Guide — Stacks, Hosting & Closing Gaps

**Audience:** Team members new to full systems development  
**Purpose:** Shared vocabulary for how we build, host, and harden systems (including products like Outpost / Lapok DMS)  
**Last updated:** July 2026  

---

## 1. What we mean by a “system”

A **system** is more than a webpage. It is the full chain that makes a product usable in real life:

| Piece | Meaning |
|-------|---------|
| **Frontend** | What the user sees and clicks (pages, forms, buttons) |
| **Backend** | Server logic (login, save data, permissions, reports) |
| **Database** | Where records live (users, trips, sales, documents) |
| **Files / storage** | PDFs, images, exports, uploads |
| **Hosting** | The always-on computer(s) that run the app on the internet |
| **Ops** | Backups, monitoring, updates, deployments |

**Example (our world):** Cadet submits a daily report → PHP saves it → MySQL stores it → accountant sees it on the RDC sheet → manager reviews/approves.

---

## 2. Core web technologies (what we already use)

These are not “old” or “wrong.” They are the foundation of most websites.

| Technology | What it does | Analogy |
|------------|--------------|---------|
| **HTML** | Structure of the page (headings, forms, tables) | Skeleton |
| **CSS** | Look and layout (colors, spacing, mobile layout) | Skin / design |
| **JavaScript (JS)** | Behavior in the browser (validation, UI updates, AJAX) | Muscles / reflexes |
| **PHP** | Code that runs on the **server** (auth, DB, business rules) | Brain in the office |
| **SQL / MySQL** | Language + engine for storing and querying data | Filing cabinet |

### How they work together

```
Browser (HTML + CSS + JS)
        ↓  request (HTTPS)
Web server (Apache / Nginx)
        ↓
PHP application
        ↓
MySQL / MariaDB database
```

**Local practice setup:** XAMPP = Apache + MySQL + PHP on your laptop.  
**Production:** Same idea, but on a rented server so users can reach it 24/7.

### Classic stack name: LAMP

- **L**inux (operating system)  
- **A**pache (web server)  
- **M**ySQL (database)  
- **P**HP (backend language)  

This is a valid, industry-used stack. Many serious business systems still run on it.

---

## 3. “Modern” stacks — what they are (and why people mention them)

When people say “best stacks,” they often mean **popular job-market defaults**, not “PHP can’t build systems.”

| Goal | Common stack | Why people pick it |
|------|----------------|--------------------|
| Web apps / SaaS | TypeScript + React/Next.js + Node or Python + PostgreSQL | One language across front/back (TS), big hiring market |
| APIs / services | Go or FastAPI/NestJS + PostgreSQL + Redis | Speed, clear APIs, caching |
| Data / automation / AI later | Python + FastAPI + Postgres | Strong data/ML ecosystem |
| Mobile + backend | React Native or Flutter + same backend | Share API with web |

### Useful related terms

| Term | Meaning |
|------|---------|
| **Stack** | The set of tools used together (e.g. PHP + MySQL + Apache) |
| **Framework** | Structure + helpers on top of a language (e.g. Laravel for PHP, Django for Python) |
| **API** | How programs talk to each other (often JSON over HTTPS) |
| **REST** | Common style of APIs (URLs + GET/POST/PUT/DELETE) |
| **Frontend framework** | Libraries that build complex UIs (React, Vue) — still uses HTML/JS under the hood |
| **TypeScript** | JavaScript with types — catches some bugs earlier |
| **PostgreSQL** | Powerful SQL database (alternative to MySQL) |
| **Redis** | Fast in-memory store — caching, queues, sessions |
| **Docker** | Packages the app so it runs the same on every machine |

### Our practical takeaway for this team

- **HTML + CSS + JS + PHP + MySQL** is a solid path for Outpost/Lapok-style systems.  
- Later we can add structure (e.g. **Laravel**) or TypeScript/React if the product and hiring needs grow.  
- **Language ≠ quality.** Careful PHP beats careless “modern” code.

---

## 4. Strengthening the PHP path (recommended learning path)

Don’t stay on “random PHP files forever.” Grow in layers:

1. Clean structure (folders for pages, APIs, includes)  
2. **PDO + prepared statements** (never glue user input into SQL strings)  
3. Auth: sessions, password hashing, roles (cadet / RDC / manager / admin)  
4. Migrations / schema discipline (don’t edit production DB by hand)  
5. Background-ish jobs where needed (exports, emails, sync)  
6. Optional next step: **Laravel** for routes, auth scaffolds, migrations, security defaults  

---

## 5. How systems are hosted

**Hosting** = putting the app on an always-on machine with a public address so users reach it via a domain name.

### Local vs online

| Environment | Meaning |
|-------------|---------|
| **Local (XAMPP)** | Dev on your PC — practice kitchen |
| **Staging** | Online test copy — try features before go-live |
| **Production** | Real users — the live system |

Rule: **never develop only on production.**

### Hosting options (simple → advanced)

| Option | What it is | Good for |
|--------|------------|----------|
| **Shared hosting** | One server, many websites (cPanel, Hostinger, etc.) | Small apps, early deploy, budget |
| **VPS** | Your own virtual machine (DigitalOcean, Contabo, Linode…) | Real products, more control |
| **Cloud (AWS / Azure / GCP)** | Many building blocks (VMs, managed DB, storage, load balancers) | Scale, compliance, complex setups |
| **PaaS** | “Push code, they run it” (Railway, Render, Fly…) | Fast deploy (common for Node/Python; PHP sometimes) |
| **On‑prem** | Server in the company/office/datacenter | Data must stay inside the organisation |

### Typical go-live path for a PHP system

1. Buy a **domain** (`example.com`)  
2. Rent **hosting** (shared or VPS)  
3. Point **DNS** (domain → server IP)  
4. Upload / deploy **code** (Git, FTP, or CI)  
5. Create **production database** and apply schema  
6. Set **secrets** (DB password, app keys) via environment / panel — **not in Git**  
7. Turn on **HTTPS** (Let’s Encrypt / SSL certificate)  
8. Enable **backups** and test that restores work  

### What often gets hosted separately

| Piece | Typical place |
|-------|----------------|
| App code (PHP) | Web server / VPS / shared host |
| Database | Same machine (small) or managed DB (better) |
| Files / PDFs | Disk folder or object storage (S3-like) |
| Email | SMTP provider (not raw `mail()` forever) |
| Static assets | CDN later (optional) |

### Simple diagram

```
User
  → Domain name (DNS)
    → Hosting server (HTTPS)
      → Apache / Nginx
        → PHP app
          → MySQL
          → File storage
```

---

## 6. Closing loopholes (the gaps that break systems)

These are the areas where “it works on my laptop” still fails in the real world.

### 6.1 Security

| Gap | What to do |
|-----|------------|
| Users inject SQL / break forms | Validate on the **server**; use prepared statements |
| Passwords stolen | Hash with bcrypt/argon2 — never store plain text |
| Traffic intercepted | **HTTPS** everywhere |
| Secrets in Git | Use env vars / panel secrets; rotate if leaked |
| Wrong person sees data | AuthN (who are you?) + AuthZ (what can you do?) |
| Abuse / bots | Rate limits, lockouts, CAPTCHA where needed |
| XSS / CSRF | Escape output; CSRF tokens on state-changing forms |
| Old packages | Keep PHP, OS, and libraries updated |

### 6.2 Data storage

| Gap | What to do |
|-----|------------|
| No schema discipline | Migrations; documented tables |
| Data loss | Automated backups + **restore drills** |
| Half-saved money/inventory | Use DB transactions where needed |
| Huge files in DB | Store files on disk/object storage; DB keeps metadata |
| Dirty deletes | Soft-delete / audit trails for critical records |

### 6.3 Hosting

| Gap | What to do |
|-----|------------|
| No SSL | Force HTTPS |
| Open ports | Firewall; only 80/443 (+ SSH if VPS) |
| DB on the public internet | Prefer private network / restricted access |
| One copy of everything | Separate staging vs production credentials |
| No rollback plan | Keep previous release ready to redeploy |

### 6.4 Scaling

| Gap | What to do |
|-----|------------|
| Slow pages | Indexes, better queries — before buying bigger servers |
| Repeated heavy work | Cache (Redis), CDN for static files |
| Long jobs freeze the UI | Queues (email, exports, sync) |
| Single point of failure | Managed DB + backups; later multiple app servers |

**Note:** Don’t jump to microservices/Kubernetes early. Fix queries and hosting basics first.

### 6.5 Reliability (“production loop”)

| Gap | What to do |
|-----|------------|
| Silent failures | Logging + error monitoring |
| Duplicate payments / webhook chaos | Idempotency (same request twice = safe) |
| Unknown downtime | Uptime checks + alerts |
| Partial outages | Graceful errors; don’t wipe the whole day if one module fails |

### 6.6 Privacy / compliance (often forgotten)

| Gap | What to do |
|-----|------------|
| Storing personal data carelessly | Know what PII you keep and why |
| No deletion path | Retention rules; ability to remove/export when required |
| No accountability | Access logs for sensitive actions |

---

## 7. Suggested learning / build order for the team

1. One full feature with auth + DB (CRUD)  
2. Proper MySQL schema + migrations habit  
3. Deploy somewhere real + backups  
4. Harden security (PDO, roles, HTTPS, secrets)  
5. Add caching / queues only when pain appears  
6. Consider Laravel or TypeScript/React when complexity or hiring needs justify it  

---

## 8. One-page summary for the team

| Topic | Short answer |
|-------|--------------|
| **Our natural stack** | HTML + CSS + JS + PHP + MySQL (LAMP), often via XAMPP locally |
| **Are “modern” stacks better?** | They’re popular alternatives, not a requirement to build real systems |
| **Frameworks** | Optional accelerators (e.g. Laravel) — structure and security patterns |
| **Hosting** | Shared → VPS → cloud as we grow; always HTTPS + backups |
| **Biggest risks** | Weak auth, SQL injection, no backups, secrets in Git, no staging |
| **Priority** | Ship clear modules + close security & backup gaps before chasing hype |

---

## 9. How this connects to Outpost / Lapok DMS

Our product is a **business operations system** (roles, daily close, reports, approvals). That means:

- PHP + MySQL is appropriate for the core.  
- Hosting must protect **financial and operational data**.  
- Role-based access (cadet / RDC / manager / executive / admin) is not optional — it is security.  
- Backups and audit of critical daily sheets matter as much as new features.

---

*Questions or additions from the team: update this doc so everyone shares the same language.*
