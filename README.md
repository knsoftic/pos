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
| **Tenancy** | Hand-rolled row-level isolation. No external tenancy package. |
| **Tests** | `php artisan test` → 300 tests / 966 assertions |
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
Subscriptions are append-only history (`superseded_at`), stock movements have no `updated_at`, and
anything with history is archived rather than deleted. A correction is a new entry that says what
changed, so the past stays readable.

---

## Requirements

- PHP **8.2+** with the usual Laravel extensions
- MySQL 5.7+ / MariaDB 10.4+
- Composer, Node.js 18+
- On Windows: XAMPP works out of the box (this is the reference dev setup)

## Installation

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

Run the migrations with demo data, build the assets, and start the server:

```bash
php artisan migrate:fresh --seed
```

```bash
npm run build
```

```bash
php artisan serve
```

Open **http://localhost:8000**. The seeded logins are documented in
[LOGIN_CREDENTIALS.md](LOGIN_CREDENTIALS.md) — **delete or re-password every one of them before
production.**

### The one cron entry

Subscription statuses are reconciled nightly. Access is always derived from dates, so a missed run can
never keep an expired tenant selling — but the scheduler keeps the stored status column honest:

```bash
* * * * * cd /path/to/pos && php artisan schedule:run >> /dev/null 2>&1
```

---

## Configuration

Nothing about pricing, plans, limits, features or branding is hardcoded. Each of these files is
env-driven and is the single place its subject is decided:

| File | Decides |
|---|---|
| `config/brand.php` | Company name, product name, tagline, support contacts, version |
| `config/security.php` | Password policy, login/reset throttles, session lifetime |
| `config/subscription.php` | Currency, trial length, grace period, expiry behaviour, warning days |
| `config/inventory.php` | Negative stock, low-stock fallback, expiry window, valuation method |

**Rebranding is a config change, not a search-and-replace.** Set `BRAND_*` in `.env` and every screen,
email and footer follows.

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
  Enums/         BillingCycle, ProductType, StockMovementType, …
  Models/        + Concerns/ (BelongsToTenant, BelongsToBranch, Blameable)
  Services/      the business logic — controllers stay thin
  Support/       TenantContext, BranchContext, and the three registries
  Http/          Controllers · Requests (validation) · Middleware (the gates)
database/
  migrations/    31 migrations, each documenting why the schema is shaped that way
resources/views/
  components/    brand/ · layouts/ · reusable UI
```

The registries are worth knowing about: `FeatureRegistry` (57 plan features), `LimitRegistry`
(12 quotas) and `PermissionRegistry` (49 permissions) own the *vocabulary*, while the database owns
the *answers*. A feature only exists because some code path checks it, so its home is the code; which
plan includes it is operator data.

---

## Licence & support

© 2026 KN Softic. All rights reserved.

Support: [support@knsoftic.com](mailto:support@knsoftic.com) · [knsoftic.com](https://knsoftic.com)
