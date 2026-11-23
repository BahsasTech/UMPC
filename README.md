# Unique Multi Purposes Cooperative Society Limited (UMPCSL)

A web-based Cooperative Society Management System for membership, savings,
loans, repayments, payments and reporting. Built with **PHP 8**, **Bootstrap 5**
and either **MySQL/MariaDB** (local/XAMPP) or **PostgreSQL/Supabase** (hosted).

It covers membership, savings, loans, payments, the member portal, the admin
console, role-based access, notifications, a double-entry accounting engine, an
inventory & online-store module, a legacy data migration tool and online
payment gateways (Paystack, Remita and a built-in test simulator).

---

## Features implemented

| Module | What's included |
|--------|-----------------|
| **Membership** | Self-registration and staff registration, KYC profile, passport photo, bank details, next of kin, nominees, referrals, member records |
| **Loans** | Applications, eligibility assessment, guarantors, collateral, approval/rejection, disbursement, repayment schedule, outstanding balances, loan history |
| **Savings** | Monthly savings, share capital, special savings (land & housing), targets, balances, statements |
| **Payments & Repayments** | Unified payment ledger, loan repayments, savings contributions, missed-installment detection, receipts |
| **Online Payments** | Paystack, Remita and a built-in test simulator; member-initiated checkout for savings, loan repayments and store orders; server-side verification, idempotent settlement, signed webhooks, transaction log and gateway settings |
| **Member Portal** | Secure login, profile updates, savings, loan application & status, balances, payment history, printable statements |
| **Admin Console** | Dashboard overview, member management, loan workflow, savings posting, payment recording, system settings and reports |
| **Security** | Role-based permissions (admin / staff / member), `password_hash`, CSRF protection, audit log, prepared statements |
| **Notifications** | Automated SMS and email alerts (loan approvals, disbursements, repayments, savings receipts, reminders, missed payments), branded announcements, delivery log, retry, provider settings and a test sender |
| **Accounting** | Double-entry general ledger, chart of accounts, automatic postings from savings/loans/payments, manual journal entries, general ledger, trial balance, profit & loss and balance sheet (with CSV export) |
| **Inventory & e-Store** | Product categories and catalogue, stock movements (opening, purchase, adjustment, sale, return), reorder levels and low-stock alerts, in-store POS sales, public online store with cart and member checkout, order status workflow, employee performance and best-seller reporting, product/stock/sales CSV export |
| **Legacy Migration** | CSV importers for members, savings opening balances, loans and repayment history with column auto-mapping, preview/validation, templates and an import history log |
| **Reports & Export** | Savings by product, loan portfolio, monthly contribution trend, payments by method, member positions, CSV export, print views |

---

## Requirements

- **PHP 8.0+** with `pdo_mysql` (local MySQL) or `pdo_pgsql` (Supabase)
- **MySQL 5.7+ / MariaDB 10.3+**, or **PostgreSQL/Supabase**
- A web server (XAMPP/Apache, Docker/nginx) or PHP's built-in server
- The `curl` extension is required for Supabase Storage uploads

The project was developed and tested against XAMPP (PHP 8.0.30, MariaDB 10.4).

---

## Setup

1. **Create the config file**

   ```powershell
   Copy-Item config\config.example.php config\config.php
   ```

   Then edit `config/config.php` with your database credentials. Defaults assume
   a stock XAMPP install (`root`, empty password, host `127.0.0.1`).

2. **Start MySQL** (skip if already running)

   ```powershell
   C:\xampp\mysql_start.bat
   ```

3. **Create the database and tables**

   ```powershell
   C:\xampp\php\php.exe bin\migrate.php
   ```

4. **Create the administrator and optional demo data**

   ```powershell
   C:\xampp\php\php.exe bin\seed.php          # settings + admin + demo records
   C:\xampp\php\php.exe bin\seed.php --reset  # wipe and reseed from scratch
   ```

   > Re-running `bin\migrate.php` is safe: it uses `CREATE TABLE IF NOT EXISTS`
   > so new tables (for example `notifications`, the accounting tables, the
   > store tables and `import_logs`) are added to an existing database, and the
   > default chart of accounts is ensured.

---

## Running the app

### Option A – PHP built-in server (quickest)

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public
```

Then open <http://127.0.0.1:8000>.

### Option B – XAMPP / Apache

Point a virtual host's document root at the `public/` folder, or copy the
project into `C:\xampp\htdocs\umpcsl` and browse to
`http://localhost/umpcsl/public/`.

---

## Deploying to Vercel with Supabase

The app keeps its PHP code and runs on Vercel's **Container runtime**. Supabase
is used for PostgreSQL and (optionally) Storage; PHP sessions and staged CSV
imports are stored in the database so multiple instances stay in sync.

### 1. Supabase setup

1. Create a Supabase project and run `database/schema.postgres.sql` in the SQL
   editor (or from a machine that can reach the DB: `php bin/migrate.php` with
   `DATABASE_URL` set to the Supabase **direct** connection string).
2. Run `php bin/seed.php` once to create the admin/staff accounts and settings.
3. For image uploads, create two **public** Storage buckets named
   `umpcsl-photos` and `umpcsl-products` (or set `SUPABASE_BUCKET_*`).
4. Use Supabase's **session pooler** URL (port 5432) for `DATABASE_URL` at
   runtime — this app keeps a persistent PHP-FPM connection and sets the session
   timezone. Add `?sslmode=require`. A transaction pooler (port 6543) also works
   when `pgbouncer=true` is appended; that disables server-side prepared
   statements and session timezone. Use the direct URL for migrations.
5. If you have existing photos/products from a local install, upload those files
   to the matching buckets using the same filenames already stored in the DB.

### 2. Vercel environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgresql://postgres.<ref>:<pass>@<host>:5432/postgres?sslmode=require` | Supabase session-pooler connection |
| `APP_URL` | `https://your-app.vercel.app` | Callback URLs |
| `SESSION_SECURE` | `1` | Optional override for secure session cookies (auto-detected by default) |
| `APP_TIMEZONE` | `Africa/Lagos` | PHP timezone |
| `DB_PGBOUNCER` | `1` | Set when using Supabase transaction pooler |
| `SESSION_DRIVER` | `database` | Shared PHP sessions |
| `APP_STORAGE_DRIVER` | `supabase` | Store uploads in Supabase Storage (do not use `STORAGE_DRIVER` on Vercel—it conflicts with the container builder) |
| `SUPABASE_URL` | `https://<ref>.supabase.co` | Storage API base URL |
| `SUPABASE_SERVICE_ROLE_KEY` | `eyJ...` | Storage API key (server-side only) |
| `CRON_SECRET` | long random string | Protects `/cron.php` |

The root `Dockerfile` is built automatically; `vercel.json` selects the
Container preset and schedules `/cron.php` daily at 06:00 UTC. If another
framework is detected, rename it to `Dockerfile.vercel` or set the framework to
**Container** in Project Settings.

Uploads are no longer written to the container filesystem in this mode, so no
persistent volume is required. `config/config.php` is not copied into the image;
all credentials come from environment variables.

---

## Default logins

| Role  | Email                | Password    |
|-------|----------------------|-------------|
| Admin | `admin@umpcsl.com`   | `admin123`  |
| Staff | `staff@umpcsl.com`   | `staff123`  |
| Member| `aisha@umpcsl.com`   | `password123` |
| Member| `musa@umpcsl.com`    | `password123` |
| Member| `grace@umpcsl.com`   | `password123` |

> Change these credentials and the demo data before handling real money.

---

## Project structure

```
UMPCSL/
├── Dockerfile               # Vercel Container runtime (nginx + PHP-FPM)
├── docker/                  # nginx/supervisor/PHP configuration
├── vercel.json              # Container preset + daily cron
├── config/
│   ├── config.example.php   # template (committed)
│   └── config.php           # your credentials (git-ignored)
├── database/
│   ├── schema.sql           # full MySQL/MariaDB schema
│   └── schema.postgres.sql  # full PostgreSQL/Supabase schema
├── src/
│   ├── bootstrap.php        # loads config, session and modules
│   ├── db.php               # PDO helpers (MySQL / PostgreSQL)
│   ├── session.php          # shared database sessions
│   ├── storage.php          # local / Supabase Storage uploads
│   ├── reminders.php        # shared scheduler logic
│   ├── helpers.php          # formatting, CSRF, flash, utilities
│   ├── auth.php             # login, registration, roles, audit
│   ├── members.php          # membership + savings accounts
│   ├── savings.php          # contributions, withdrawals, statements
│   ├── loans.php            # eligibility, applications, schedules, repayments
│   ├── payments.php         # unified payment ledger
│   ├── notifications.php    # SMS/email delivery, templates, logging, broadcasts
│   ├── accounting.php       # chart of accounts, double-entry ledger, financial reports
│   ├── store.php            # products, stock, sales/orders, cart and reporting
│   ├── import.php           # legacy CSV importers (members, savings, loans, repayments)
│   ├── gateway.php          # online payment gateways (Paystack, Remita, test)
│   ├── reports.php          # reporting queries + CSV output
│   └── layout.php           # shared header/nav/footer
├── bin/
│   ├── migrate.php          # create DB + schema
│   ├── seed.php             # admin + demo data
│   └── send-reminders.php   # scheduled repayment reminders / missed-payment alerts
└── public/                  # web root
    ├── index.php login.php register.php logout.php 404.php
    ├── cron.php             # protected Vercel Cron endpoint
    ├── dashboard.php savings.php loans.php loan-apply.php
    ├── repayments.php statements.php notifications.php
    ├── store.php cart.php checkout.php order.php orders.php
    ├── pay.php test-gateway.php payment-callback.php payment-webhook.php
    ├── profile.php receipt.php
    ├── export.php
    ├── admin/               # admin console pages (incl. data-migration.php)
    ├── assets/              # css, js, img
    └── uploads/
        ├── photos/          # member passport photographs
        └── products/        # product images
```

---

## Notifications

SMS and email alerts are delivered through pluggable drivers and every message
is recorded in the `notifications` table.

**Channels and drivers**

| Channel | Drivers |
|---------|---------|
| Email | `log` (default), `mail` (PHP `mail()`), `smtp` (built-in SMTP client, STARTTLS/SSL + AUTH LOGIN) |
| SMS | `log` (default), `generic_http` (URL template gateway), `termii`, `twilio` |

The **`log`** driver records the message without contacting a provider — the safe
default for development and demos. Switch to a real driver under
**Admin → Notifications → Provider settings** (stored in the `settings` table, so
no file editing is needed). Use **Send a test message** to verify a gateway.

**Automated events** (fired automatically from the relevant actions):

`welcome`, `loan_applied`, `loan_approved`, `loan_rejected`, `loan_disbursed`,
`repayment_received`, `savings_contribution`, `repayment_reminder`, `missed_payment`,
`order_placed`, `order_ready`, `sale_receipt`.

Templates live in `notification_templates()` in `src/notifications.php` and support
placeholders such as `{first_name}`, `{membership_no}`, `{loan_no}`, `{amount}`,
`{outstanding}`, `{org}`.

**Announcements** – Admin → Notifications → *Compose announcement* broadcasts to
all members, active members, members with loans, or staff, over email and/or SMS.

**Reminders scheduler** – run daily (Windows Task Scheduler / cron):

```powershell
C:\xampp\php\php.exe bin\send-reminders.php            # due in 3 days + missed
C:\xampp\php\php.exe bin\send-reminders.php --days=7   # wider window
C:\xampp\php\php.exe bin\send-reminders.php --force    # ignore same-day dedupe
```

Members view their own messages under **Notifications** in the portal; admins get
a full delivery log with filters, retry, and CSV export.

---

## Accounting

A **double-entry general ledger**. Every money movement is recorded as a balanced
journal entry, and all statements are derived from those entries — so the trial
balance, profit & loss and balance sheet always agree with the underlying
transactions.

**Admin → Accounting** contains five screens:

| Screen | Purpose |
|--------|---------|
| Chart of Accounts | View/add/rename accounts; activate or deactivate; balances are computed from postings |
| Journal Entries | Browse and filter entries, view details, and post manual journal entries (validated as balanced) |
| General Ledger | Postings for one account with opening/closing balances and a running balance |
| Trial Balance | Debits vs credits as at any date, with a balanced indicator |
| Financial Statements | Profit & loss for a period and a balance sheet as at a date |

**Default chart of accounts** (installed automatically by the migration and
idempotently re-created as needed):

```
1000 Cash on Hand     1010 Bank Account      1100 Loans Receivable
1200 Inventory        2000 Members' Savings  3000 Share Capital
3100 Statutory Reserve 3200 Opening Balance Equity
4000 Interest Income  4100 Fee & Other Income 4200 Store Sales
5000 Operating Expenses 5100 Loan Loss Expense 5200 Cost of Goods Sold
```

**Automatic postings** — the system writes balanced entries as transactions occur:

| Event | Debit | Credit |
|-------|-------|--------|
| Savings / share-capital contribution | Cash or Bank | Members' Savings (or Share Capital) |
| Savings withdrawal | Members' Savings | Cash or Bank |
| Loan disbursement | Loans Receivable (total repayable) | Members' Savings (principal) + Interest Income |
| Loan repayment | Cash or Bank | Loans Receivable |
| Fee / other income | Cash or Bank | Fee & Other Income |
| Store sale (when paid) | Cash or Bank | Store Sales |
| Cost of goods sold | Cost of Goods Sold | Inventory |
| Imported savings opening balance | Opening Balance Equity | Members' Savings / Share Capital |
| Imported loan opening balance | Loans Receivable | Opening Balance Equity |

`cash` method posts to cash on hand; all other methods post to the bank account.
Errors in automatic postings are swallowed so a money flow never fails because of
the ledger (see `safe_post_journal()` in `src/accounting.php`).

Trial balance, income statement and balance sheet can all be exported to CSV from
their screens or via `export.php?type=trial_balance|income_statement|balance_sheet`.

---

## Inventory & e-Store

The co-operative can sell goods from a physical counter and through a public
online store. Stock is tracked per product with a full movement history, and every
paid sale posts to the ledger automatically.

**Catalogues & stock** — Admin → **Inventory**

| Tab | Purpose |
|-----|---------|
| Products | Add/edit products (SKU, category, unit, cost, price, image, online visibility); low-stock highlighting |
| Stock Movements | Restock (purchase) or adjust stock to an absolute quantity; full movement history with running balance |
| Categories | Manage product categories (delete is admin-only; products become uncategorised) |

**Sales & orders** — Admin → **Sales**

- **New sale** is a point-of-sale screen: pick products, quantities and prices,
  link a member or enter a walk-in name, apply a discount, choose the payment
  method, and record the sale as *completed*, *paid* or *pending*.
- Each sale can be opened to **mark paid**, move it through *paid → fulfilled →
  completed*, or **cancel** it (which returns the stock to inventory).
- **Employee performance** reports sales, revenue, gross profit and gross margin
  per staff member and per product for any date range.

**Online store** — public `store.php` (and the member portal link)

- Anyone can browse the catalogue and search/filter by category; members sign in
  to check out.
- The cart is session-based (`cart.php`); checkout creates a **pending** order,
  reserves stock immediately and notifies the member (`order_placed`).
- Members track orders under **My Orders**; staff fulfil them from the admin
  Sales screen, which fires the `order_ready` notification.

**Statuses:** `pending → paid → fulfilled → completed`, or `cancelled`.
Walk-in sales default to `completed`; online orders start `pending`.

**Accounting** (posted once, when a sale is paid/fulfilled/completed):

| Event | Debit | Credit |
|-------|-------|--------|
| Sale (cash) | Cash on Hand | Store Sales (4200) |
| Sale (other method) | Bank Account | Store Sales (4200) |
| Cost of goods sold | Cost of Goods Sold (5200) | Inventory (1200) |

**Exports:** `export.php?type=products|stock|sales` (also linked from the admin
screens). Product images live in `public/uploads/products/` locally, or in the
Supabase `umpcsl-products` bucket when `APP_STORAGE_DRIVER=supabase`.

---

## Online payments

Members can pay online for **savings**, **loan repayments** and **store orders**
from **Make a Payment** (`pay.php`). Three gateways are supported:

| Gateway | Notes |
|---------|-------|
| **Paystack** | Cards, bank transfer & USSD. Requires public/secret keys. Amounts are sent in kobo; the webhook signature is HMAC-SHA512. |
| **Remita** | Generates an RRR and redirects to the Remita payment page. Requires merchant ID, service type ID, API key and API hash key. |
| **Test (simulator)** | Built in and enabled by default. Exercises the whole flow with no real money or credentials. |

**Configuration** - Admin → **Online Payments** → *Gateway settings*
(administrators only). Settings are stored in the `settings` table with a `pay_`
prefix and override the defaults in `config/config.php`.

**Flow**

1. The member chooses what to pay for, enters an amount (the full total is used
   for orders and capped at the outstanding balance for loans) and picks a gateway.
2. A `transactions` row is created (`pending`) and the member is redirected to the
   gateway (or the built-in test simulator).
3. On return, `payment-callback.php` — and independently `payment-webhook.php` —
   **re-verifies the payment with the gateway server-side**, checks the amount, then
   settles it **once** through the normal savings/loan/sale functions so the ledger
   and stock stay correct. Replays and amount mismatches are rejected.

**Webhook URLs** (configure with each live provider):

```
https://your-domain/payment-webhook.php?provider=paystack
https://your-domain/payment-webhook.php?provider=remita
```

**Accounting** is inherited from the destination: savings → *Dr Bank/Cash / Cr
Members' Savings*; loan repayment → *Dr Bank/Cash / Cr Loans Receivable*; a paid
order → the standard store-sale postings. Non-cash methods post to Bank (1010).
The admin **Online Payments** screen lists all transactions with status, gateway,
purpose and text filters, plus a **Re-check** action for pending payments.

---

## Legacy data migration

Existing paper / spreadsheet records can be loaded from CSV so the co-operative
does not have to re-key historical data. Admin → **Data Migration** (administrators
only) offers four importers, each with a **blank template** download, automatic
column mapping, a validation **preview** and an **import history** log
(`import_logs`).

| Importer | Loads |
|----------|-------|
| **Members** | Existing members (name, contact, KYC, join date). Creates the user account, member profile and the standard savings accounts. Missing membership numbers and emails are generated; duplicates are skipped. |
| **Savings Balances** | Opening balances per savings product (monthly, share capital, special land, special housing), matched by membership number or email. |
| **Loans** | Legacy loans with principal, interest, term, total repayable and **outstanding** balance. A repayment schedule is generated and back-filled to reflect the amount already repaid. |
| **Loan Repayments** | Historical repayments (amount, method, date), applied oldest-first against a loan's schedule. |

**How it works**

1. Upload a CSV (first row = header). Columns are auto-guessed by matching common
   header names; adjust the mapping if needed.
2. A **dry-run preview** shows how many rows are ready, skipped or invalid — with
   per-row messages — before anything is written.
3. Click **Import now**. Each row is committed in its own transaction; the file is
   discarded and a summary is written to the import log.

**Accounting.** Opening balances keep the ledger balanced by posting against
**Opening Balance Equity (3200)**: savings → *Dr 3200 / Cr Members' Savings
(2000) & Share Capital (3000)*; loans → *Dr Loans Receivable (1100) / Cr 3200*.
Import loans at their **current outstanding** balance and do **not** also import
their historical repayments (that would double-count); use the repayments importer
only for loans loaded at full value. No emails or SMS are sent during import.

> Import in order: **Members → Savings Balances → Loans → Loan Repayments**.

---

## Business rules

- **Loan eligibility**: a member with no active loan may borrow up to
  **3×** their savings (monthly + share capital). Configurable in `settings`
  (`loan_max_multiple`, `loan_interest_rate`, `loan_min_guarantors`).
- **Interest** is a flat rate on the principal; `total_repayable = amount + amount × rate%`.
- **Disbursement** credits the net loan amount to the member's monthly savings.
- **Repayments** reduce the outstanding balance and settle installments
  oldest-first; a loan is marked *completed* when fully repaid.
- **Member payments need verification**: savings contributions, loan repayments,
  online payments and store orders submitted by a member remain pending and are
  excluded from balances, statements and the dashboard until staff approve them
  under **Admin → Payment Approvals**. Payments recorded directly by staff are
  posted immediately.
- **Missed installments** are recalculated on every dashboard view.

---

## Roadmap

All modules from the brief are implemented — see the
[Notifications](#notifications), [Accounting](#accounting),
[Inventory & e-Store](#inventory--estore),
[Online payments](#online-payments) and
[Legacy data migration](#legacy-data-migration) sections.

Possible future enhancements: multi-branch support, member-to-member transfers,
statement PDFs, and a scheduled reconciliation job for pending gateway
transactions.

---

## Security notes

This is a solid starting point, not a hardened production system. Before going
live: enable HTTPS, add login rate limiting, email verification, scheduled
database backups, and rotate the seeded credentials.
