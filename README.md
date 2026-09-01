# KN Softic POS

**Cloud POS & Sales Management** — a multi-tenant SaaS by [KN Softic](https://knsoftic.com).

One installation serves many businesses. Each one gets its own fully isolated data, its own branches
and tills, its own staff and roles, and only the features its subscription plan includes.

---

## What it is

| | |
|---|---|
| **Stack** | Laravel 12 · PHP 8.2 · MySQL/MariaDB · Blade · Tailwind CSS v4 · Alpine.js · Livewire 4 |
| **Two panels** | `/admin` — KN Softic operator console · `/app` — the customer's own workspace |
| **Public site** | `/` — marketing pages, pricing read from the live plan catalogue, self-service sign-up |
| **Tenancy** | Hand-rolled row-level isolation. No external tenancy package. |
| **Tests** | `php artisan test` → 757 tests / 2,937 assertions |
| **Progress** | See [DEVELOPMENT_LOG.md](DEVELOPMENT_LOG.md) |

---

## The three ideas everything else follows from

**1. A tenant can never see another tenant's data — and not because we remembered to filter.**
`TenantContext` is resolved *only* from the authenticated user, never from a URL or form field.
`BelongsToTenant` adds a global scope to reads and force-stamps `business_id` on writes, so a request
cannot create a row into someone else's business even by posting one. `business_id` is not fillable
anywhere. Cross-tenant lookups return `null`, not a 403 — there is nothing to reveal.

**2. Access is three questions, not one.**
Does the *plan* include this feature → does this *person* have the permission → is the row inside this
*tenant*. All three, in that order, on every gated route. Hiding a menu item is presentation; the
`permission:` and `feature:` middleware are the enforcement.

**3. Records that matter are never rewritten.**
Stock is the sum of an append-only movement ledger and `stocks.quantity` is a cache of it. Party
balances work the same way. Subscriptions are append-only history (`superseded_at`). A completed sale
is voided, never deleted — `ProtectsFinancialRecords` enforces that at the model layer, including
through mass deletes, which normally skip model events entirely. A correction is a new entry that
says what changed, so the past stays readable.

---

## Requirements

- PHP **8.2+** with the usual Laravel extensions, plus `zip` for backups
- MySQL 5.7+ / MariaDB 10.4+, and `mysqldump` on the box
- Composer, Node.js 18+
- On Windows: XAMPP works out of the box (this is the reference dev setup)

### Browsers

Chrome and Edge **111+**, Firefox 128+, Safari 16.4+. This is not a preference — Tailwind CSS v4 is
built on `color-mix()`, `@property` and cascade layers, so below those versions the styling does not
degrade, it does not happen. Anything older is shown a plain notice explaining why, detected with
`@supports` rather than a user-agent string. Chrome and Edge are the priority: that is what tills run.

---

## Installing it locally

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create the database, then point `.env` at it:

```
DB_DATABASE=pos_saas
DB_USERNAME=root
DB_PASSWORD=
```

Migrate with the demo shop, build the assets, and serve:

```bash
php artisan migrate:fresh --seed
```

```bash
npm run build
```

```bash
php artisan serve
```

Open **http://localhost:8000**. The seeder prints the demo logins when it runs — two businesses, an
owner, a cashier and a super admin, all with the password `password`. They exist only outside
production; see below.

---

## Deploying it

### 1. Configure

Start from [.env.production.example](.env.production.example) rather than `.env.example` — it is the
same file with production answers, and a test asserts the two carry identical keys so neither can go
quietly stale. `APP_KEY` and `DB_PASSWORD` are empty on purpose: a plausible placeholder in a
committed file is a credential somebody eventually ships.

```bash
cp .env.production.example .env
php artisan key:generate
```

### 2. Migrate — and note what does *not* happen

```bash
php artisan migrate --seed --force
```

With `APP_ENV=production` this plants the feature and limit registries and a starting plan catalogue,
and **no accounts at all**. That is deliberate. The demo shop lives in `DemoSeeder`, which refuses to
run in production — until Phase 15 this same command created a super admin at `superadmin@pos.test`
whose password was the word `password`, on a box facing the internet, and said nothing about it.

A staging server that honestly reports itself as production can still ask, with `ALLOW_DEMO_SEED=true`.

### 3. Cut yourself a key

Because nothing was seeded, nobody can reach `/admin` yet:

```bash
php artisan pos:make-admin
```

It asks for the password rather than taking a `--password` option — a password on a command line is
in the shell history, is visible in `ps` while the command runs, and ends up pasted into deployment
notes. The same policy the login screen enforces applies here.

### 4. Build, link, optimise

```bash
npm ci && npm run build
```

```bash
php artisan storage:link && php artisan optimize
```

### 5. One cron entry

```bash
* * * * * cd /path/to/pos && php artisan schedule:run >> /dev/null 2>&1
```

That single line drives everything scheduled: subscription reconciliation, expiring held sales, the
nightly backup, the integrity sweep, weekly pruning, and a heartbeat every five minutes so a stopped
cron is *detectable* rather than invisible.

Nothing here is load-bearing for access control — subscription access is derived from dates on every
request, so a misconfigured cron cannot keep an expired tenant selling.

### 6. Ask the machine whether it is ready

```bash
php artisan pos:preflight
```

This is the step worth not skipping. A checklist can tell you to turn debug off; only the box can tell
you whether it *is* off.

It **fails** on: a missing app key, `APP_DEBUG` on in production, pending migrations, no operator
account, demo accounts still present, any owner or admin still signing in with `password` (it names
them), or unwritable paths.

It **warns** on: plain HTTP, an insecure session cookie, HSTS off, `MAIL_MAILER=log` (password resets
appear to work and no mail arrives), uncached config, a missing storage link, a stopped scheduler, and
backups that are missing, stale, or sitting on the same disk as the database.

Only failures set the exit code, so it drops into a deploy pipeline as-is. `--strict` promotes the
warnings.

> It does not claim to make an installation secure. It means the mistakes that are cheap to check for
> have not been made. Nothing in it inspects the server, the database grants or the network.

---

## Backups

```bash
php artisan pos:backup
```

Runs nightly at 01:00 — before the 02:00 integrity sweep, so that if the sweep finds drift the newest
archive predates whatever a repair does to it.

Each archive holds the database dump **and the uploaded files**. Product images and expense receipts
are pointers stored in columns; restoring the rows without the files gives a shop that looks intact
and is not.

**Set `BACKUP_DISK` to something off-box.** The default writes under `storage/app/private/backups`,
which survives a bad migration and nothing else — not a dead disk, not a fire, not ransomware, which
encrypts the backups folder along with everything else. The command says so on every run.

### Restoring — do this once before you need it

A backup nobody has restored is a file. Practise on a scratch database:

```bash
unzip pos-backup-2026-09-01-010000.zip -d /tmp/restore
```

```bash
mysql -u root -p -e "CREATE DATABASE pos_restore_check"
```

```bash
mysql -u root -p pos_restore_check < /tmp/restore/database.sql
```

Then prove the restored copy is *consistent*, not merely present:

```bash
DB_DATABASE=pos_restore_check php artisan pos:check-integrity
```

It should say `Everything reconciles.` Copy `files/public/` back over `storage/app/public/`.

---

## The scheduled work

| Command | When | What it does |
|---|---|---|
| `subscriptions:reconcile` | 00:10 daily | Rewrites stale status columns from the derived state |
| `pos:backup` | 01:00 daily | Database + uploads, pruned to `BACKUP_RETENTION_DAYS` |
| `pos:check-integrity` | 02:00 daily | Proves every cached balance still matches its ledger |
| `pos:expire-holds` | hourly | Discards held sales past each shop's own hold window |
| `pos:prune` | Sundays 03:00 | Old audit entries and orphaned uploads — **never anything financial** |
| heartbeat | every 5 min | A timestamp, so `pos:preflight` can tell a stopped cron from a quiet week |

`pos:check-integrity` **reports and does not repair** unless you pass `--repair`. Quietly correcting a
discrepancy destroys the evidence of whatever caused it, and the cause matters more than the symptom.
`pos:expire-holds` and `pos:prune` both take `--dry-run`; `pos:prune` refuses a retention window under
30 days, because `--days=1` is almost always a typo and it cannot undo itself.

---

## Configuration

Nothing about pricing, plans, limits, features or branding is hardcoded. Each file is env-driven and
is the single place its subject is decided:

| File | Decides |
|---|---|
| `config/brand.php` | Company name, product name, tagline, support contacts, version |
| `config/security.php` | Password policy, throttles, session lifetime, response headers, redaction |
| `config/subscription.php` | Currency, trial length, grace period, expiry behaviour |
| `config/inventory.php` | Negative stock, low-stock fallback, expiry window, valuation method |
| `config/backup.php` | Destination disk, what travels with the dump, retention |
| `config/uploads.php` | Allowed types, size and dimension caps |
| `config/audit.php` | How long the audit trail is kept |

**Rebranding is a config change, not a search-and-replace.** Set `BRAND_*` in `.env` and every screen,
email and footer follows.

Per-shop settings are different again: a setting's **key is the config key it overrides**, so
`config('pos.cash_rounding')` returns that shop's value and roughly a hundred existing call sites
became per-tenant without being edited.

---

## Running the tests

```bash
php artisan test
```

Tests run against a **real MySQL** database (`pos_saas_test`), not SQLite — the tenant migrations use
`->after()` and add a NOT NULL foreign key to an existing table, and tests should exercise the same
engine production does. Create the database once; `RefreshDatabase` handles the rest.

---

## Repository layout

```
app/
  Console/Commands/  pos:backup · pos:preflight · pos:make-admin · pos:check-integrity …
  Enums/             BillingCycle, ProductType, StockMovementType, SaleStatus, …
  Models/            + Concerns/ (BelongsToTenant, BelongsToBranch, ProtectsFinancialRecords)
  Services/          the business logic — controllers stay thin
  Support/           TenantContext, BranchContext, and the registries
  Http/              Controllers · Requests (validation) · Middleware (the gates)
database/
  migrations/        56 migrations, each documenting why the schema is shaped that way
  seeders/           DatabaseSeeder (safe anywhere) · DemoSeeder (never in production)
resources/views/
  components/        brand/ · layouts/ · errors/ · reusable UI
  errors/            403 · 404 · 419 · 429 · 500 · 503 — standalone HTML, no asset pipeline
tests/Feature/
  Security/          CSRF · XSS · SQLi · mass assignment · uploads · financial immutability
  Deployment/        the seeder split, preflight, backups, env parity
```

The registries own the *vocabulary* while the database owns the *answers*: `FeatureRegistry`
(57 plan features), `LimitRegistry` (12 quotas), `PermissionRegistry` (49 permissions),
`ReportRegistry` (30 reports), `SettingRegistry` (32 shop settings) and `PlatformSettingRegistry`
(15 operator settings). A feature only exists because some code path checks it, so its home is the
code; which plan includes it is operator data.

---

## Licence & support

© 2026 KN Softic. All rights reserved.

Support: [support@knsoftic.com](mailto:support@knsoftic.com) · [knsoftic.com](https://knsoftic.com)
