# 🏪 Multi-Tenant POS SaaS — Development Log

> **Yeh file kya hai?**
> Is project ka progress tracker hai. Yahan record rehta hai ke **kya kaam ho chuka hai** aur **kya baqi hai**.
> Jab bhi koi kaam mukammal ho, neeche **Session Log** mein aik entry add karo aur **Task Tracker** mein us item ka checkbox tick kar do.

---

## 📌 Project Info

| | |
|---|---|
| **Project** | Cloud-Based Multi-Tenant Sales Management & POS SaaS |
| **Stack** | Laravel 12 (PHP 8.2.12) + MySQL + Blade + Tailwind CSS v4 + Alpine.js + Livewire 4 |
| **Working Dir** | `C:\xampp\htdocs\pos` |
| **Environment** | Windows 11 + XAMPP (PHP + MySQL/MariaDB) |
| **Start Date** | 2026-08-25 |
| **Current Status** | ✅ **Phase 1 MUKAMMAL (100%)** — Multi-tenant architecture + dono auth panels + password reset + audit trail + dashboards (charts ke saath) mukammal. **50 tests / 146 assertions pass** (MySQL `pos_saas_test`) — tenant isolation (#116/#117) included. Build successful, browser verified, console/network zero errors. ➡️ **Next: Phase 2** (Plans + Subscriptions + Features + Limits). |

---

## 🎯 Status Legend

| Symbol | Matlab |
|--------|--------|
| ⬜ | **Baqi** (Pending — abhi shuru nahi hua) |
| 🔄 | **Chal raha hai** (In Progress) |
| ✅ | **Ho gaya** (Done & tested) |
| 🧪 | **Test pending** (Ban gaya, test hona baqi) |
| ⏭️ | **Skip / Baad mein** (Deferred) |

---

## 📊 Overall Progress (Phase Summary)

| Phase | Module | Status | % |
|-------|--------|--------|---|
| **0** | Project Foundation & Setup | ✅ Ho gaya | 100% |
| **1** | Auth + Super Admin + Tenant Architecture + DB Foundation | ✅ Ho gaya | 100% |
| **2** | Plans + Subscriptions + Features + Limits + Businesses | 🔄 Chal raha hai | ~90% |
| **3** | Roles + Permissions + Branches + POS Counters + Employees | ⬜ Baqi | 0% |
| **4** | Products + Categories + Brands + Units + Inventory | ⬜ Baqi | 0% |
| **5** | Customers + Suppliers (+ Ledgers) | ⬜ Baqi | 0% |
| **6** | Purchases + Supplier Ledger | ⬜ Baqi | 0% |
| **7** | POS + Sales + Payments + Customer Ledger | ⬜ Baqi | 0% |
| **8** | Returns + Stock Adjustments + Transfers | ⬜ Baqi | 0% |
| **9** | Expenses + Profit & Loss | ⬜ Baqi | 0% |
| **10** | Reports | ⬜ Baqi | 0% |
| **11** | Settings + Receipt + QR + Barcode | ⬜ Baqi | 0% |
| **12** | Public Website + Pricing + Trial Registration | ⬜ Baqi | 0% |
| **13** | Animations + UI Polish + Performance | ⬜ Baqi | 0% |
| **14** | Security + Testing | 🔄 Chal raha hai | ~10% |
| **15** | Deployment Preparation | ⬜ Baqi | 0% |
| | **TOTAL PROGRESS** | 🟢 | **~19%** |

---

## 📝 Session Log (Kaam ki History)

> Naya kaam upar add karo (newest first). Har entry mein: **date**, **kya hua**, **kya next hai**.

### 2026-08-25/26 — Phase 1 MUKAMMAL ✅ (Session 3 — 100%, tests pass)

Session 2 ki **BAQI list** ke tamam items ban gaye, aur upar se mandatory tests + build + browser verification bhi ho gayi. **Phase 1 close.**

**✅ JO HO GAYA:**

**1) Business panel (tenant side) — pura wire ho gaya**
- ✅ `resources/views/app/dashboard.blade.php` naya: welcome banner (asli business name + user ka first name), 4 stat cards (**Team members** = asli tenant-scoped count; Sales/Products/Low-stock placeholders unke phase note ke saath), **"Your team" table** (name/email/role/last login `diffForHumans()`), business details `<dl>`, "Data isolation active" note, aur disabled quick actions.
- ✅ `components/layouts/app.blade.php` **poora rewrite**: fake "Admin" user hata ke `auth('web')->user()` + middleware ka shared `$currentBusiness`, sidebar mein business ka naam, `route('app.dashboard')` active-state, logout **POST form** (`route('logout')` + `@csrf`), Owner badge, aur jo modules abhi nahi bane un nav items pe `cursor-not-allowed opacity-50` + `title="Coming in a later phase"` + `aria-disabled` (dead links nahi).
- ✅ `App\DashboardController` — sab queries **auto-scoped** (`User::count()` mein koi `where business_id` nahi likha, phir bhi sirf apne business ka data).
- ✅ Purana root `resources/views/dashboard.blade.php` **delete** (stale placeholder tha).

**2) Password reset flow — #63 (mukammal)**
- ✅ `PasswordResetLinkController` + `NewPasswordController` (thin, Form-Request style validation).
- ✅ Views: `auth/forgot-password.blade.php`, `auth/reset-password.blade.php` (eye/eye-off password toggle + live password-policy hint jo `config('security.password')` se aata hai).
- ✅ Login page pe **"Forgot password?"** link.
- ⚠️ **Security fix (khud pakra):** `config/auth.php` ka `admins` broker shared `password_reset_tokens` table use kar raha tha — wo table **email pe keyed** hai, to same email wala admin aur business user aik doosre ka reset token overwrite kar dete. Naya migration `admin_password_reset_tokens` + broker config fix. (Migrate ho chuka.)
- ✅ **Account enumeration safe:** email exist kare ya na kare, response hamesha aik jaisa generic hota hai (sirf throttle error surface hota hai).
- ✅ **Deactivated users excluded:** broker credentials mein `is_active => true` — band account reset link nahi le sakta.
- ✅ Reset request pe alag rate limit (`PASSWORD_RESET_MAX_ATTEMPTS`, default 3 / 10 min) + `lang/en/passwords.php`.

**3) Strong password policy + session config — #63, #161, #190 (no hard-coding)**
- ✅ Naya `config/security.php` (sab env-driven): password (`min_length`, mixed case, numbers, symbols, `uncompromised`), throttle (login + reset attempts/decay), session (lifetime, expire-on-close, warn-before).
- ✅ `AppServiceProvider::configurePasswordPolicy()` — `Password::defaults()` config se banti hai, is liye rules **kahin hardcode nahi**; ek jagah badlo, poore app mein lagti hai.
- ✅ `.env` + `.env.example` mein security + session block (comments ke saath), `.env.example` DB sqlite → mysql/`pos_saas`.

**4) Audit trail — #61, #94, #133 (foundation live)**
- ✅ `app/Services/AuditService.php`: `log()` / `logChange()` (before→after), actor **admin guard pehle phir web**, business_id resolve order = TenantContext → actor → auditable → null, IP + user-agent record.
- ✅ **Audit kabhi business flow nahi torta:** poora insert `try/catch` + `report($e)` mein hai — log fail ho jaye to sale/purchase phir bhi complete hoti hai.
- ✅ `app/Listeners/AuthEventSubscriber.php` — `auth.login`, `auth.logout`, `auth.failed`, `auth.lockout`, `auth.password_reset` events audit hote hain.
- ⚠️ **Security note:** Laravel ka `Failed` event submitted credentials (password bhi) le kar aata hai — code mein **sirf email** record hoti hai, password kabhi nahi. `subscribe()` pattern use kiya taake Laravel ki auto-discovery se listener double register na ho.

**5) Super Admin dashboard chart — #5 (mukammal)**
- ✅ `npm install apexcharts` + `resources/js/app.js` mein shared `window.chartDefaults()` (brand colors, ek jaisa look poore app mein) + **`window.loadCharts()` lazy loader** (ApexCharts alag chunk mein, sirf zaroorat pe download — POS fast rehni chahiye, #122).
- ✅ `Admin\DashboardController::growthChart()` — pichle 6 months ke **naye businesses per month** (DB-agnostic `whereBetween`, koi raw SQL nahi).
- ✅ Admin dashboard pe smooth gradient area chart; **dark/light toggle pe chart khud re-theme** hota hai (`toggleTheme()` ab `theme-changed` CustomEvent fire karta hai, dono layouts mein).

**6) Test infrastructure**
- ✅ `phpunit.xml` sqlite `:memory:` → **MySQL `pos_saas_test`** (DB bana di). Wajah: tenant migration `->after('id')` + existing table pe NOT NULL FK column add karti hai — SQLite ye nahi kar sakti, aur tests production ke jaise DB pe chalne chahiye.
- ✅ Stale `tests/Feature/ExampleTest.php` delete (`/` ab 302 redirect karta hai, 200 nahi).

**7) ⚠️ Mandatory feature tests — likhe aur PASS (#116, #117)**
- ✅ **`tests/Feature/TenantIsolationTest.php` (16 tests)** — Phase 1 ka sab se important suite. Business A/B banti hain, phir: auto-scoped reads, context switch, doosre tenant ki PK pe `find()` → **null**, scoped aggregates, context na ho to no filter, `creating` hook `business_id` stamp karta hai, **mass-assignment protection**, explicit `business_id` bhejo to bhi context jeetta hai, `forBusiness()` / `allTenants()` / `runFor()` (restore ke saath), **HTTP level pe dashboard isolation**, request input se tenant badalna **na-mumkin**, aur suspended business / deactivated user / soft-deleted business — teeno login pe bounce (`assertGuest`).
- ✅ **`tests/Feature/Auth/AuthenticationTest.php` (~20 tests)** — dono guards + unki hard separation: login render, authenticate → `app.dashboard`, `last_login_at`, ghalat password, required fields, deactivated user block, suspended-business bounce, **config-driven rate limit**, logout, guests block; admin side wahi sab; aur 🔒 **cross-guard denial** — business creds admin login pe reject, admin creds business login pe reject, dono sessions ek doosre ka panel nahi khol sakte, operator dashboard tenant-scoped **nahi** hai.
- ✅ **`tests/Feature/Auth/PasswordResetTest.php` (11 tests)** — link email hota hai, **unknown email pe bilkul same response** (enumeration safe), deactivated user ko link nahi, same account repeat request throttle, **ek IP se multiple emails ka sweep bhi throttle**, reset screen + valid token se reset, weak password reject, invalid token reject, token **reuse nahi** ho sakta.
- ✅ **Result: `php artisan test` → 50 tests / 146 assertions PASS** on MySQL `pos_saas_test` (dobara run kar ke confirm kiya).

**8) Build + browser verification**
- ✅ `npm run build` successful (59 modules). **Performance fix:** ApexCharts har page pe ja raha tha → base bundle **960 kB**. Ab `window.loadCharts()` dynamic `import()` se code-split hai:
  - `app.js` → **50.33 kB** (gzip **19.53 kB**) — har page pe yahi jaata hai, **~19× chhota**
  - `apexcharts.esm.js` → 910.82 kB (gzip 265.07 kB) — **sirf tab download** hota hai jab page pe chart ho
  - `app.css` → 68.41 kB (gzip 12.27 kB)
  - ℹ️ Vite ka ">500 kB chunk" warning ab bhi dikhta hai (apexcharts chunk khud bara hai) — **magar wo lazy hai, page load block nahi karta**. POS screen fast rehni chahiye (#122), is liye ye zaroori tha.
- ✅ **Browser verified (dev server pe):** business login → `/app/dashboard` ("Dashboard · Demo Retail Store", Team members = **2** = sahi tenant scope); admin login → `/admin/dashboard` growth chart render (Mar–Aug 2026, integer ticks) + **dark mode toggle pe chart re-theme**; dono logout sahi jagah; forgot-password + reset-password pages render (hint config se); **har page pe zero console logs aur zero failed network requests**.

**🐞 4 asli bugs jo testing ne pakre (warna production mein jaate):**
1. **Blade `\B@` rule** — Blade ka directive regex word-character ke baad wale `@` ko ignore karta hai, to `characters@if (…)` / `case@endif` **compile hi nahi** hue → unclosed `if` → `/reset-password/{token}` pe **HTTP 500**. Fix: hint ko naye reusable `<x-password-hint />` component mein nikala (PHP mein banti hai, `config('security.password')` se), aur tamam views grep kar ke confirm kiya koi doosri jagah nahi.
2. **`@push('scripts')` layout component ke BAHAR** tha → layout ka `@stack` pehle hi print ho chuka hota hai, is liye chart ka script **chup-chaap kabhi render nahi** hota. Fix: `@push` ko `</x-layouts.admin>` ke andar move kiya + comment likha taake dobara na ho.
3. **Password-reset throttle bypass** — per email+IP limiter ko attacker har request pe naya email de kar bypass kar sakta tha (Laravel ka broker throttle sirf **existing** emails pe lagta hai). Fix: doosri layer **per-IP limiter** (`PASSWORD_RESET_IP_MAX_ATTEMPTS`, default 15) + test.
4. **Hardcoded `5` login attempts** `LoginRequest` aur `AdminLoginController` mein — #190 (no hard-coding) ki violation. Fix: `config('security.throttle.*')` se aata hai, aur admin lockout pe bhi `Lockout` event fire hota hai taake audit log mein aaye.

**⏭️ Deliberately deferred (wajah ke saath):** admin-side "Reset password / Login-as" UI → **Phase 2** (#6 Super Admin business management ka hissa hai); session idle-warning UI → **Phase 13**; `Blameable` attach → **Phase 4+** (jab created_by/updated_by wali tables banengi).

➡️ **Next: Phase 2** — Plans + Subscriptions + Features + Limits + Super Admin business management.


### 2026-08-25 — Phase 1 (Session 2): Multi-Tenant Architecture + Auth 🔄 (~60%)

**Approach chosen:** Hand-rolled row-level multi-tenancy (koi external tenancy package nahi) — Laravel guards + `BelongsToTenant` trait + global scope + tenant middleware.

**✅ JO HO GAYA (done & runtime-verified):**
- ✅ **4 migrations** ban ke `migrate:fresh --seed` se clean apply huin:
  - `admins` (super admins — business users se alag table), `businesses` (tenant root: slug, status, timezone, locale, created_by→admins), `users` mein tenant fields add (`business_id` NON-null FK, is_active, is_business_owner, last_login_at, softDeletes), `audit_logs` (immutable activity log).
- ✅ **Isolation core** (sab se important):
  - `app/Support/TenantContext.php` — request-scoped singleton, tenancy ka single source of truth.
  - `app/Models/Scopes/TenantScope.php` — global scope; context set ho to auto `where business_id = ?`, warna no-op.
  - `app/Models/Concerns/BelongsToTenant.php` — reads auto-scope + `creating` hook `business_id` ko **force** karta hai (user override nahi kar sakta) + `forBusiness()` / `allTenants()` escape hatches.
  - `app/Models/Concerns/Blameable.php` — created_by/updated_by auto-stamp (Phase 4+ tables ke liye foundation).
- ✅ **Models:** `Admin`, `Business` (STATUS_* constants, users/creator relations), `AuditLog`, aur `User` (BelongsToTenant + business_id/is_active/is_business_owner **guarded** = mass-assignment se safe).
- ✅ **Auth guard separation** (`config/auth.php`): naya `admin` guard + `admins` provider + `admins` password broker. Super admin (`/admin`) aur business user (`/app`) bilkul alag.
- ✅ **Middleware** `SetBusinessTenant` (+ `tenant` alias `bootstrap/app.php` mein): tenant SIRF authenticated user se resolve hota hai (kabhi URL/request se nahi); inactive user / trashed / suspended business pe fail-closed (session khatam). Guard-aware login redirects bhi set.
- ✅ **Requests/Controllers (thin):** `LoginRequest` (rate limiting 5 attempts + is_active gate), `BusinessLoginController`, `AdminLoginController`, `App\DashboardController`, `Admin\DashboardController`.
- ✅ **Routes** (`routes/web.php`) restructure: public `/` → login; business auth+`/app` (auth:web + tenant); super-admin auth+`/admin` (auth:admin).
- ✅ **Views:** guest auth layout, business login, admin login, admin panel layout (dark operator console), admin dashboard (stats + recent businesses). `lang/en/auth.php` + naye icons (mail/lock/shield/building/eye…).
- ✅ **Factories + Seeder:** AdminFactory, BusinessFactory, UserFactory + DatabaseSeeder = 1 super admin + 2 demo businesses + 3 users. Login creds (password = `password`): `superadmin@pos.test` (/admin/login), `owner@demo.test` & `owner2@demo.test` (/login).
- ✅ **Isolation runtime test (tinker) PASS:** context=B1 → sirf 2 users, context=B2 → sirf 1 user, cross-tenant `find()` → NULL, creating-hook business_id force ✓, `forBusiness()` escape hatch ✓.

**⬜ BAQI (Phase 1 complete karne ke liye — agli baar):**
- ⬜ **Business dashboard view** `resources/views/app/dashboard.blade.php` banana (yahin ruke the).
- ⬜ **`components/layouts/app.blade.php` wiring:** abhi bhi `route('dashboard')` + fake "Admin" user + logout unwired hai → `route('app.dashboard')`, asli `auth()->user()` naam/business, logout POST form.
- ⬜ Purana `resources/views/dashboard.blade.php` (root) hataana + `/` route ab login pe jaata hai.
- ⬜ **⚠️ Mandatory tests:** `TenantIsolationTest` (#116/#117 — cross-tenant leak = null/404) + `Auth/AuthenticationTest` (login redirect, guard separation, inactive block).
- ⬜ **Browser verification:** business login → /app dashboard, admin login → /admin dashboard, logout dono, dark mode, throttle.
- ⬜ Deferred sub-items: password reset flow (#63), session timeout config (#161), `AuditService` (#61 — table ready, service baqi), super-admin dashboard charts (#5 — ApexCharts pending).
- ➡️ **Next:** upar wale BAQI items complete → Phase 1 close → phir Phase 2 (Plans/Subscriptions).


### 2026-08-25 — Phase 0 MUKAMMAL ✅ (Foundation ready & browser-verified)
- ✅ **Laravel 12** install (PHP 8.2.12; v13 ko PHP 8.3 chahiye tha, isliye latest compatible v12 auto-select hui).
- ✅ **MySQL** `pos_saas` DB banayi + `.env` MySQL pe configure + `migrate:fresh` (default migrations) successful.
- ✅ `php artisan key:generate` + `php artisan storage:link` done.
- ✅ **Frontend stack:** Tailwind CSS v4 + Alpine.js + **Livewire 4** (v4.4.2).
  - ⚠️ **Fix:** Livewire pehle `composer.json` mein `"*"` ke sath tha par `vendor/` mein install NAHI hua tha → `@livewireScripts` literal text ban raha tha. `composer require livewire/livewire` se properly install (v4.4.2), view cache clear kiya, ab `livewire.js` + Alpine dono load ho rahe.
- ✅ **Design system** (`resources/css/app.css`): brand palette, class-based dark mode, reusable `.card / .btn* / .input / .badge* / .nav-link*`. *(Tailwind v4 ka `@apply` sirf real utilities leta hai — isliye button/badge variants self-contained banaye, aik custom class doosri ko `@apply` nahi karti.)*
- ✅ **Base layout** (`components/layouts/app.blade.php`): responsive sidebar + topbar (global search, theme toggle, notifications, user dropdown) + custom Lucide-style `<x-icon>` component (poori icon library ki jagah inline SVG).
- ✅ **Placeholder dashboard** + temporary route (`/` → dashboard).
- ✅ Laravel folder structure: `app/Services`, `app/Support`, `app/Enums`, `app/Data`.
- ✅ **Browser verified:** `npm run build` clean (56 modules, koi `@apply` error nahi), page **200 OK**, light + dark mode dono sahi, `window.Livewire`/`window.Alpine` = true, `livewire.js` 200, **zero console/PHP errors**.
- ⬜ Baqi (Phase 0 optional): ApexCharts/Chart.js (jab dashboards/reports ke charts chahiye — Phase 2/13), Git init (recommended).
- ➡️ **Next: Phase 1** — Auth + Super Admin + Multi-Tenant Architecture + DB Foundation.

### 2026-08-25 — Project shuru / Log banaya
- ✅ `DEVELOPMENT_LOG.md` progress tracker banaya.
- ℹ️ Working directory (`C:\xampp\htdocs\pos`) confirm ki — bilkul khali hai, koi code nahi.
- ⬜ **Abhi tak koi development code nahi hua.**
- ➡️ **Next:** Phase 0 shuru karo → Laravel install, `.env` + MySQL setup, base UI layout.

---
---

# ✅ DETAILED TASK TRACKER

Har phase ke andar tamam tasks checkbox ke saath hain. Jo ho jaye uska `[ ]` ko `[x]` kar do.

---

## PHASE 0 — Project Foundation & Setup
*(Spec ref: #1, #113, #115, #182, #192)*

- [x] Laravel latest stable version install *(Laravel 12 — PHP 8.2.12)*
- [x] `.env` configure (APP_NAME, APP_URL, DB credentials)
- [x] MySQL database create + connection test *(`pos_saas`)*
- [x] `php artisan key:generate`
- [x] `php artisan storage:link`
- [x] Composer dependencies install
- [x] NPM + frontend build setup (Tailwind CSS v4 + Alpine.js + Livewire 4) *(`npm run build` ✓)*
- [x] ApexCharts / Chart.js integrate *(ApexCharts — shared `window.chartDefaults()`; **code-split** via `window.loadCharts()` dynamic import, base bundle 50 kB)*
- [x] Font Awesome / Lucide icons integrate *(custom `<x-icon>` — inline Lucide-style SVG)*
- [x] Laravel folder structure setup (`Services/`, `Support/`, `Enums/`, `Data/` — #182) *(`Policies/` Phase 3 mein banegi)*
- [x] Base master layout (sidebar + topbar + content area)
- [ ] Git repository init (recommended)

---

## PHASE 1 — Auth + Super Admin + Tenant Architecture + DB Foundation
*(Spec ref: #2, #3, #4, #62–64, #65–68, #130–132, #197)*

### Authentication
- [x] Login system (email + password + Remember Me) — #62 *(dono guards)*
- [x] Password security *(hashing + **config-driven** login throttle + reset flow (enumeration-safe, inactive users excluded, per-email **aur** per-IP throttle) + config-driven strong-password rules via `Password::defaults()`)* — #63
- [x] Session security + CSRF protection *(web middleware CSRF + login pe session regenerate)*
- [x] Super Admin vs Business auth separation (`/admin/*` vs `/app/*`) — #64
- [x] Session timeout (configurable) *(`config/security.php` + `SESSION_LIFETIME` / `SESSION_EXPIRE_ON_CLOSE` / `SESSION_WARN_BEFORE_MINUTES`; idle-warning UI → Phase 13)* — #161

### Multi-Tenant Architecture (⚠️ Sab se important)
- [x] `business_id` columns strategy *(users pe business_id; branch_id/pos_id Phase 3)* — #3
- [x] Global Scopes for tenant isolation *(`TenantScope`)* — #3
- [x] `BusinessTenant` middleware *(`SetBusinessTenant`)* — #130
- [x] Current Business Context resolver (request se `business_id` blindly na lo) *(`TenantContext`, sirf auth user se)* — #131, #197
- [x] Mass assignment protection (`business_id` override rok) *(guarded + creating-hook force)* — #132
- [ ] `created_by` / `updated_by` tracking *(⚙️ `Blameable` trait ready — Phase 4+ tables pe attach hoga)* — #3

### Database Foundation
- [x] Core migrations (users, businesses, admins, audit_logs) *(business_user pivot ke bajaye direct `business_id` design)* — #65, #113
- [x] Eloquent relationships + foreign keys — #66
- [x] Indexes *(business_id + status indexed; module-specific sku/barcode/invoice baad ke phases)* — #67
- [x] SoftDeletes on master data *(admins, businesses, users)* — #68
- [x] `TenantService` — #183

### Super Admin (foundation)
- [x] Super Admin dashboard *(stats cards + recent businesses + 6-month growth chart, dark-mode aware)* — #5
- [x] Audit / Activity log foundation *(`audit_logs` table + `AuditService` (fail-safe) + auth events audited)* — #61, #133, #177

### Phase 1 Verification (proof, assumption nahi)
- [x] `phpunit.xml` MySQL (`pos_saas_test`) pe — production jaisi DB
- [x] `TenantIsolationTest` — 16 tests *(#116, #117)*
- [x] `Auth/AuthenticationTest` — ~20 tests (dono guards + separation)
- [x] `Auth/PasswordResetTest` — 11 tests
- [x] **`php artisan test` → 50 tests / 146 assertions PASS**
- [x] `npm run build` successful *(base bundle 960 kB → **50.33 kB**, ApexCharts lazy chunk mein)*
- [x] Browser verification — dono login/logout, dashboards, chart + dark mode, forgot/reset pages, **zero console + zero failed network**

---

## PHASE 2 — Plans + Subscriptions + Features + Limits + Businesses
*(Spec ref: #6–11, #78–84, #125–129, #172–179, #186)*

### Subscription Plans
- [ ] Dynamic plans CRUD (name, desc, prices per cycle, trial, badge, order) — #7
- [ ] Billing cycles (Monthly/Quarterly/Half-Yearly/Yearly/Lifetime/Custom) — #175
- [ ] Free plan (price 0) — #173
- [ ] Lifetime plan — #174
- [ ] "Show on Website" toggle per plan — #172

### Plan Limits (`PlanLimitService`, `LimitRegistry`)
- [ ] Configurable limits (products, customers, employees, branches, POS, invoices, storage...) — #8, #129
- [ ] Numeric vs Unlimited option — #8
- [ ] **Backend limit enforcement** (501st product block, etc.) — #79
- [ ] Plan usage meter display (350/500) — #78

### Dynamic Features (`FeatureService`, `FeatureRegistry`)
- [ ] Central feature registry (feature codes) — #128
- [ ] Enable/disable features per plan (toggles) — #9
- [ ] **Feature enforcement** (menu hide + button hide + backend route deny) — #80, #125
- [ ] `CheckFeature` middleware — #130

### Business-Level Overrides
- [ ] Individual business feature override — #10
- [ ] Individual business limit override — #10
- [ ] Override change audit log — #177

### Subscriptions (`SubscriptionService`)
- [ ] `isActive() / hasFeature() / getLimit() / canCreate() / usage() / daysRemaining()` — #186
- [ ] Subscription expiry behavior (lock / read-only / POS-off) — #11
- [ ] Expiry warnings (7/3/1 days) — #11
- [ ] Grace period (configurable) — #127
- [ ] `CheckSubscription` + `CheckBusinessStatus` middleware — #130
- [ ] Trial system — #81
- [ ] Subscription payment records — #82
- [ ] Upgrade / downgrade (data safe on downgrade) — #83
- [ ] Subscription history — #176

### Super Admin — Business Management
- [ ] Create/Edit/Delete business (full form) — #6
- [ ] Suspend / Activate / Change plan / Extend / Add trial days — #6
- [ ] Reset password / Login-as (impersonate) — #6, #178
- [ ] Business usage details view — #126
- [ ] Plan permission comparison matrix — #84
- [ ] Private internal support notes — #159
- [ ] System notifications (failed jobs, expiring, expired) — #179

---

## PHASE 3 — Roles + Permissions + Branches + POS Counters + Employees
*(Spec ref: #4, #47–52, #138, #140–141, #187–188)*

### Roles & Permissions
- [ ] Module/action based permission system — #51
- [ ] Custom roles (Business Owner creates) — #51
- [ ] Sensitive permissions (view cost/profit, delete/cancel invoice, exports) — #52
- [ ] **3-layer access check: Subscription Feature + User Permission + Tenant** — #187
- [ ] `RolePermission` middleware + Policies/Gates — #130, #188

### Branches
- [ ] Branch management CRUD — #47
- [ ] Branch data control (Owner=all, Manager=own, Cashier=own POS) — #48

### POS Counters
- [ ] POS counter management (per branch) — #49

### Employees
- [ ] Employee management (form + role + branch + POS assign) — #50
- [ ] Branch-level employee access (primary branch) — #138
- [ ] Discount restrictions (cashier max discount %) — #141
- [ ] Returns permission (separate) — #140

---

## PHASE 4 — Products + Categories + Brands + Units + Inventory
*(Spec ref: #24–34, #136, #142, #150–152, #157–158, #185)*

### Catalog
- [ ] Categories / Subcategories / Brands / Units (unlimited) — #26
- [ ] Product management (full form) — #24
- [ ] Product types: Standard / Service / Variable — #25
- [ ] Product variations (size/color, per-variation SKU/price/stock) — #25
- [ ] Product images + placeholder — #149
- [ ] Product status (Active/Inactive) — #105

### Barcode (plan-based)
- [ ] Auto-generate + manual barcode — #27
- [ ] Barcode label printing (custom size, name+price) — #27

### Inventory (`InventoryService` — #185)
- [ ] Inventory listing (stock, value, status badges) — #28
- [ ] Automatic stock movement (purchase↑ sale↓ returns/adjust) — #29
- [ ] Inventory ledger (full history) — #30
- [ ] Stock adjustment (add/remove + reason) — #31
- [ ] Stock transfer (Branch A→B, draft/sent/received) — #32
- [ ] Low stock alerts — #33
- [ ] Expiry management (batch + expiry, reports) — #34
- [ ] Multi-branch inventory (per-branch stock) — #136
- [ ] Negative stock setting (default No) — #142
- [ ] `getAvailableStock() / createMovement()` centralized — #185

### Data Migration Helpers
- [ ] Opening stock support — #152
- [ ] Bulk import (CSV/Excel, plan feature) — #150
- [ ] Bulk export (Excel/CSV) — #151
- [ ] Unit conversion future-ready structure — #158

---

## PHASE 5 — Customers + Suppliers (+ Ledgers)
*(Spec ref: #38–42, #137, #183)*

### Customers
- [ ] Customer management (full form) — #39
- [ ] Customer profile (purchases, paid, due, history) — #39
- [ ] Customer credit sales (plan/permission based) — #40
- [ ] Customer ledger (Debit/Credit/Balance) — #41
- [ ] `CustomerLedgerService` — #183
- [ ] Global business-level customers (multi-branch) — #137
- [ ] Customer status (Active/Blocked) — #105

### Suppliers
- [ ] Supplier management (full form) — #38
- [ ] Supplier profile (purchases, paid, due, returns, history) — #38
- [ ] Supplier ledger (accounting format) — #42
- [ ] `SupplierLedgerService` — #183

---

## PHASE 6 — Purchases + Supplier Ledger
*(Spec ref: #35–37, #119, #183)*

- [ ] Purchase management (full form) — #35
- [ ] Purchase auto stock-increase — #35
- [ ] Purchase status (Draft/Ordered/Received/Partial/Cancelled) — #36
- [ ] Purchase return (stock↓ + supplier ledger + report) — #37
- [ ] **Purchase DB transaction flow** (validate→create→stock→ledger→payment→commit) — #119
- [ ] `PurchaseService` — #183
- [ ] Purchase reports link — #54

---

## PHASE 7 — POS + Sales + Payments + Customer Ledger
*(Spec ref: #14–23, #46, #70, #89–91, #118, #122, #139, #143–148, #184)*

### POS Screen (⚡ main module — fast honi chahiye)
- [ ] POS layout (Left categories / Center grid / Right cart) — #14, #122
- [ ] Product search (name/SKU/barcode/category/brand, instant) — #15
- [ ] Barcode scan → cart add — #15
- [ ] Category filtering — #148
- [ ] Product favourites — #147
- [ ] Customer selection + quick add + Walk-in — #16, #146
- [ ] Cart operations (qty +/-, discount, tax, remove) — #14
- [ ] Hold / suspended sales — #20
- [ ] Keyboard shortcuts (F2/F4/F6/F8/F9/Esc) — #89
- [ ] AJAX live UX (no full reload) — #90
- [ ] Loading states + double-submit prevention — #91

### Payments (`PaymentService`)
- [ ] Default methods (Cash/Card/Bank/QR/Credit/Split) — #17
- [ ] Custom payment methods (JazzCash, EasyPaisa...) — #17
- [ ] QR payment (plan-based, image + reference) — #18
- [ ] Split payment (multi-method, must match total) — #19

### Sales (`SaleService` — #184)
- [ ] Sales listing + filters + actions — #21
- [ ] Configurable invoice number format — #22
- [ ] Invoice/receipt (80mm/58mm/A4, customizable) — #23
- [ ] **POS Sale DB transaction flow (16 steps, rollback on error)** — #118
- [ ] Concurrency / stock race protection (locking) — #70
- [ ] Print UX (Print/New Sale/View, auto-print option) — #145
- [ ] Invoice reprint (+ audit) — #143
- [ ] Custom receipt footer — #144

### Cash Register
- [ ] POS session link (open/close register) — #139
- [ ] Cash management (opening/expected/actual/difference) — #46

---

## PHASE 8 — Returns + Stock Adjustments + Transfers
*(Spec ref: #53, #29, #31, #32, #140)*

- [ ] Sales return (full/partial, qty ≤ sold) — #53
- [ ] Return effects (stock↑ + customer balance + payment adj + reports + profit) — #53
- [ ] Returns permission gate — #140
- [ ] Stock adjustment finalize + audit — #31
- [ ] Stock transfer finalize (receive confirm) — #32

---

## PHASE 9 — Expenses + Profit & Loss
*(Spec ref: #43–45, #135, #183)*

- [ ] Expense categories (dynamic) — #43
- [ ] Expense management (full form + attachment) — #43
- [ ] Other income recording — #44
- [ ] Profit & Loss (Revenue − COGS = Gross; − Expenses + Income = Net) — #45
- [ ] Cost method: Weighted Average Cost — #135
- [ ] `ProfitService` (consistent cost method) — #183

---

## PHASE 10 — Reports
*(Spec ref: #54–56, #134, #183)*

- [ ] Sales reports (daily/monthly/yearly/product/category/customer/employee/branch/POS/payment) — #54
- [ ] Profit reports (daily/product/category/branch/monthly/P&L) — #54
- [ ] Inventory reports (stock/value/low/out/movement/adjustment/expiry/transfer) — #54
- [ ] Purchase reports (purchase/supplier/return/outstanding) — #54
- [ ] Customer reports (purchases/outstanding/ledger) — #54
- [ ] Expense reports (daily/category/branch/monthly) — #54
- [ ] Report filters (date presets + branch/employee/customer/etc.) — #55
- [ ] Export system (PDF/Excel/CSV/Print, plan-based) — #56
- [ ] Reports accuracy (cancelled excluded, returns adjusted) — #134
- [ ] `ReportService` (optimized queries) — #183

---

## PHASE 11 — Settings + Receipt + QR + Barcode
*(Spec ref: #57–60, #76–77, #110–111, #154–160)*

- [ ] Business settings — General — #57
- [ ] Business settings — Sales — #57
- [ ] Business settings — Inventory — #57
- [ ] Business settings — Receipt — #57
- [ ] Business settings — Payment (custom methods + QR) — #57
- [ ] Currency management — #58
- [ ] Taxes (multiple rates, product/invoice level) — #59
- [ ] Discounts (fixed/percentage, product/invoice, permission) — #60
- [ ] Timezone (store UTC, display local) — #154, #153
- [ ] Date formats + currency formatting + decimals — #155, #156, #157
- [ ] SaaS branding (dynamic app name/logo/favicon) — #111
- [ ] Super Admin settings (SaaS name, trial, registration toggle, maintenance...) — #110
- [ ] In-app notifications (bell) — #76
- [ ] Super Admin announcements — #77
- [ ] Maintenance mode — #160

---

## PHASE 12 — Public Website + Pricing + Trial Registration
*(Spec ref: #106–109, #172)*

- [ ] Public marketing website (Home/Features/POS/Inventory/Reports/Pricing/FAQ/Contact) — #106
- [ ] Home page (hero + sections + CTA + footer) — #107
- [ ] Pricing page (auto from active public plans) — #108
- [ ] Registration (public signup + trial assign + admin ON/OFF) — #109

---

## PHASE 13 — Animations + UI Polish + Performance
*(Spec ref: #71–75, #86–88, #92, #96–97, #120–124, #163–171, #199)*

### UI/UX
- [ ] Modern SaaS UI (clean sidebar/topbar/cards, professional) — #72, #120, #199
- [ ] Soft animations (150–300ms, non-blocking) — #73
- [ ] Light/Dark/System mode (plan-based, saved) — #74
- [ ] Global search (product/customer/supplier/invoice) — #75
- [ ] Onboarding wizard (6 steps) — #86
- [ ] Empty states — #87
- [ ] Confirmation dialogs (delete/cancel/return/suspend) — #92
- [ ] Dashboard quick actions — #123
- [ ] Recent activity widgets — #124
- [ ] Responsive design (desktop-first, mobile-friendly) — #71, #163
- [ ] Breadcrumbs — #164
- [ ] Toast success/error messages — #165, #166
- [ ] Business Owner dashboard (cards + graphs + filters) — #12
- [ ] Plan-based navigation (feature-hidden menus) — #13, #125

### Performance
- [ ] Caching (plan features, settings, categories, permissions) + invalidation — #96, #168
- [ ] Large data optimization (pagination, indexing, eager loading) — #97, #167
- [ ] Queue-ready heavy actions — #171
- [ ] Laravel Scheduler tasks (expiry, grace, reminders, cleanup) — #169, #170

---

## PHASE 14 — Security + Testing
*(Spec ref: #93–94, #100–101, #116–117, #133, #197–198)*

### Security
- [ ] All security rules (CSRF/XSS/SQLi/mass-assignment/uploads/throttle) — #100
- [ ] Secure file uploads (MIME/size validate, random names) — #101
- [ ] Error handling (friendly messages, no technical leak in prod) — #93
- [ ] Logging (critical exceptions + failed financial txns) — #94
- [ ] Financial record integrity (edit/void/return, not delete) — #133, #198

### Testing
- [x] Feature tests: Tenant Isolation — #116, #117 *(`TenantIsolationTest` — 16 tests: scoped reads/aggregates, cross-tenant `find()` → null, creating-hook force, mass-assignment, escape hatches, HTTP isolation)*
- [x] Feature tests: Login *(`Auth/AuthenticationTest` ~20 + `Auth/PasswordResetTest` 11 — dono guards + cross-guard denial + throttle + enumeration safety)* — #116 · ⬜ Permissions *(→ Phase 3 mein banenge)*
- [ ] Feature tests: Plan Limits, Plan Features — #116
- [ ] Feature tests: POS Sale, Stock Update — #116
- [ ] Feature tests: Purchase, Returns — #116
- [ ] Feature tests: Customer/Supplier Balance — #116
- [ ] Feature tests: Subscription Expiry — #116
- [x] ⚠️ Tenant leak test (Business A → Business B URL = 403/404) — #117 *(cross-tenant PK `find()` → null; dashboard HTTP test dono tenants pe; request input se tenant switch block)*

> **Ab tak ka test status:** `php artisan test` → **50 tests / 146 assertions PASS** (MySQL `pos_saas_test`). Har naye phase ke saath yahan tests barhte rahenge.

---

## PHASE 15 — Deployment Preparation
*(Spec ref: #95, #112, #114–115, #180, #191–192)*

- [ ] Seeders: Super Admin, plans, demo business/branch/POS/products/customers/suppliers — #112, #114
- [ ] Demo credentials (change on prod) — #191
- [ ] Installation documentation (README) — #192
- [ ] Backup-ready architecture — #95
- [ ] Production `.env` / config — #115
- [ ] Browser support check (Chrome/Edge priority) — #180

---

## 🔁 Cross-Cutting Rules (har phase mein follow karo)

- [ ] Thin controllers, logic in Services — #98
- [ ] Validation in Form Requests — #98
- [ ] Laravel naming conventions — #99
- [ ] Financial actions in DB transactions — #69, #98, #198
- [ ] **No hard-coding** (plans/prices/currency/features/limits — sab DB-driven) — #190
- [ ] API/future-ready reusable services — #189
- [ ] Delete restrictions (referenced records → archive, not delete) — #104
- [ ] Interconnected modules (Sale→Inventory→Ledger→Profit→Reports) — #193

---

## 🧭 Core Principle (yaad rahe)

> **One SaaS · Multiple Businesses · Fully Isolated Data · Multiple Branches · Multiple POS · Flexible Plans · Dynamic Features · Dynamic Limits · Easy POS · Accurate Inventory · Accurate Reports**
>
> Har screen banate waqt poocho: *"Kya user ye kaam aur kam clicks mein kar sakta hai?"* — #195
