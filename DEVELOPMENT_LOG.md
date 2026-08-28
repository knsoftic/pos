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
| **Current Status** | ✅ **Phase 3 MUKAMMAL (100%)** — Roles + permissions (49 codes, 3-layer check), branches + branch-level data control, POS counters, aur employees (role/branch/till/discount cap) mukammal. **222 tests / 772 assertions pass** (MySQL `pos_saas_test`). Build + browser verified (owner aur cashier dono se), console/network zero errors. ➡️ **Next: Phase 4** (Products + Categories + Brands + Units + Inventory). |

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
| **2** | Plans + Subscriptions + Features + Limits + Businesses | ✅ Ho gaya | 100% |
| **3** | Roles + Permissions + Branches + POS Counters + Employees | ✅ Ho gaya | 100% |
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
| **14** | Security + Testing | 🔄 Chal raha hai | ~25% |
| **15** | Deployment Preparation | ⬜ Baqi | 0% |
| | **TOTAL PROGRESS** | 🟢 | **~31%** |

---

## 📝 Session Log (Kaam ki History)

> Naya kaam upar add karo (newest first). Har entry mein: **date**, **kya hua**, **kya next hai**.

### 2026-08-28 — Phase 3 MUKAMMAL ✅ (Roles + Permissions + Branches + POS Counters + Employees)

Ab sawal sirf "plan mein hai ya nahi" nahi raha — **"ye banda kar sakta hai ya nahi"** aur **"ye banda dekh sakta hai ya nahi"** bhi enforce hota hai. Teeno layer (#187) ek jagah, `PermissionService` mein.

**✅ JO HO GAYA:**

**1) Database — 5 nayi migrations**
- `branches` (name/code/phone/email/address/city/is_main/is_active + created_by/updated_by + softDeletes; code business ke andar unique aur **archive ke baad bhi reserved**), `pos_counters` (branch ke andar, business_id bhi store hota hai taake tenant scope bina join ke chal sake), `roles` (per-business, slug unique, `is_system` = starter preset), `role_permissions` (role_id + permission string), aur `users` mein **role_id / branch_id / pos_counter_id / max_discount_percent** (chaaron **guarded** — form se set nahi ho sakte).
- **Design faisla:** Owner koi role nahi hai. `users.is_business_owner` account ki property hai jo role system se pehle check hoti hai — warna koi role edit kar ke owner ko uske apne business se bahar kar sakta tha.

**2) Permission vocabulary — `Support/PermissionRegistry.php`**
- **49 codes**, `module.action` shakal mein, 10 groups mein; **24 sensitive** (#52 — cost/profit dekhna, invoice void, refunds, exports, aur wo sab jo doosron ke ikhtiyaar badalta hai).
- Har code ke saath optional **`feature`** — yani ye permission kis subscription feature pe khari hai (#187 layer 1). Registry vocabulary hai, **roles tenant ka data hain** (#190).
- ⚠️ Ek asli bug test ne pakra: `inventory.stock_take` **dono** registries mein tha (feature bhi, permission bhi). Permission ko `inventory.stock_count` kar diya, aur ek test likh diya jo hamesha check karta hai ke dono vocabularies **kabhi na takrayein**.

**3) 3-layer access check — `Services/PermissionService.php` (#187, #188)**
- Order: **feature → role → tenant** ka mixture, magar message ke liye tarteeb ahem hai: pehle feature (taake user ko "upgrade karo" bataya jaye), phir role (owner role system se upar), aur tenant check (defence-in-depth — doosre business ka user kabhi pass na kare).
- `allows/denies/allOf/anyOf/authorize/all/grantableCodes/grantableGrouped/dormantCodesFor`.
- **Gates:** har registry code ke liye `Gate::define()` — Blade mein `@can('employees.view')` chalta hai. **Jaan boojh kar koi `Gate::before` owner-bypass nahi** rakha: owner ka shortcut service ke andar hai, taake Gate aur service ka jawab kabhi mukhtalif na ho.
- **Middleware `permission:`** — HTML pe redirect + wajah, API pe **403 JSON**, aur ghalat code likho to **loudly fail**. Chunke service teeno layer chalati hai, kisi route pe `feature:` aur `permission:` dono lagane ki zaroorat nahi.

**4) Branch data control — `Support/BranchContext.php` + `Scopes/BranchScope.php` + `Concerns/BelongsToBranch.php` (#48, #138)**
- Rule: **Owner → sab branches; jis ka branch hai → sirf wahi; jis ka branch nahi → kuch bhi nahi** (fail closed).
- Tenant scope ke **neeche** chalta hai — business pehle, phir uske andar ki shops. Ye kabhi widen nahi kar sakta.
- `SetBusinessTenant` ise bhi authenticated user se resolve karta hai, request se kabhi nahi.
- Abhi `PosCounter` pe laga hua hai (asli enforcement, theory nahi); Phase 4+ ke sales/stock models sirf trait laga kar isi mein aa jayenge.

**5) Services (thin controllers, #98)**
- `RoleService` — 3 starter roles (Manager / Cashier / Stock Keeper) har naye business mein **copy** hote hain (editable rows, policy nahi), CRUD, aur **dormant permissions preserve** karta hai: downgrade pe jo permission plan se nikal gayi wo role mein rehti hai (editor use dikhata hi nahi), upgrade pe dobara chal padti hai.
- `BranchService` — main branch guarantee, doosri branch pe **multi-branch feature** ka gate, quota gate, make-main, close/reopen, delete sirf jab khali ho (#104).
- `PosCounterService` — wahi do gates + teesra: till us branch mein hi lag sakti hai **jo acting user reach kar sakta ho**.
- `EmployeeService` — seat quota + multi-user feature, role/branch/counter ki **tenant-scoped validation**, counter aur branch ka match zaroori, **apne aap ko band karna na-mumkin**, owner ko band/delete karna na-mumkin, password reset, soft delete (seat free, record baqi).
- `OrganizationProvisioner` — naya business = main branch + pehli till + starter roles. Operator console, seeder aur tests **teeno yahi call karte hain**, taake har tenant ek jaisa bane.

**6) UI**
- 4 naye screens: **Roles** (permission editor — groups, sensitive badges, select-all, dormant list), **Branches**, **POS Counters**, **Employees** — sab meters aur feature-aware empty states ke saath.
- **Sidebar ab do gates se filter hota hai**: feature (plan) + permission (banda). Cashier ko Employees/Roles/POS Counters/Settings dikhte hi nahi.
- Dashboard: asli role names (Owner / Cashier / Manager, aur bina role wale pe amber badge), `<x-flash />` add kiya taake refuse hone ki wajah nazar aaye.

**7) ⚠️ Tests — 70 naye, sab PASS**
- `Organization/RolePermissionTest` (31), `Organization/BranchAccessTest` (19), `Organization/EmployeeTest` (20).
- **Result: `php artisan test` → 222 tests / 772 assertions PASS** (MySQL `pos_saas_test`).

**8) Build + browser verification**
- `npm run build` — app.css 101.99 → **102.85 kB** (gzip 16.55), JS waise ka waisa 50.33 kB.
- ✅ Owner se: Branches, Roles (editor khula), Employees (**form se naya employee "Sara Malik" banaya — Manager role, Main Branch, 25% cap**), POS Counters — sab sahi, meters update hue (2/10 → 3/10).
- ✅ **Cashier se login**: sidebar mein sirf Dashboard/POS/Sales/Products/Customers/Branches/Billing — Employees, Roles, POS Counters, Settings **ghayab**. `/app/employees` direct kholne pe wapas dashboard + saaf message: *"You do not have permission to view employees."*
- Zero console errors, zero failed requests.

**🐞 Testing ne 3 asli cheezein pakrin:**
1. **⚠️ Route model binding tenant se PEHLE chal raha tha** — sab se sanjeeda. `SubstituteBindings` `web` group mein hai jo route ke apne middleware se **pehle** chalta hai, is liye `/app/roles/{role}` bina tenant context ke lookup karta tha aur **doosre business ka record 200 ke saath khol deta tha**. Fix: `bootstrap/app.php` mein `prependToPriorityList(SubstituteBindings::class, SetBusinessTenant::class)` — ab har bound route (roles, branches, counters, employees) ek saath mehfooz hai. Cross-tenant 404 ke tests teeno modules pe likhe hain.
2. **Permission/feature code collision** (upar #2) — ab ek test isay rok deta hai.
3. **Test fixture ka gotcha:** tenant context active hote hue `Model::factory()->for($otherBusiness)->create()` **kaam nahi karta** — `BelongsToTenant` ka creating hook business_id ko active context se stamp kar deta hai, to "doosre tenant ka" record asal mein isi tenant mein ban jata hai aur cross-tenant test ghalat wajah se pass ho jata hai. Ab `inAnotherBusiness()` helper `runFor()` ke through fixture banata hai (comment ke saath).

**⏭️ Deliberately deferred (wajah ke saath):**
- **Policies (`app/Policies/`)** — abhi har check module/action level ka hai, per-row ownership wali koi table hai hi nahi. Jab Phase 4/7 mein "apni hi sale edit kar sakta hai" jaisa sawal aayega, tab Policy banegi; Gates aur `PermissionService` pehle se tayyar hain.
- **Discount cap ka POS enforcement** — cap store aur `mayDiscount()` tested hai; asli rok Phase 7 ki sale screen pe lagegi (#141).
- **`BelongsToBranch` sirf counters pe** — baqi models abhi bane hi nahi.
- **Attendance / commission (#TEAM_ATTENDANCE, TEAM_COMMISSION)** — features registry mein hain, module Phase 13+ ya jab spec kahe.

➡️ **Next: Phase 4** — Products + Categories + Brands + Units + Inventory (yahan `Blameable` + `BelongsToBranch` + limit usage resolvers ka asli imtihan hoga).


### 2026-08-26/28 — Phase 2 MUKAMMAL ✅ (Plans + Subscriptions + Features + Limits + Business Management)

Poora SaaS billing layer ban gaya: plan kya deta hai, tenant ne kitna use kiya, aur subscription khatam hone pe kya hota hai — **sab DB se aata hai, code mein kuch hardcode nahi (#190)**.

**✅ JO HO GAYA:**

**1) Database — 11 nayi migrations (sab `Ran`)**
- `plans` (name/slug/description/badge/trial_days/grace_days/is_active/**is_public** = "Show on Website" #172/sort_order/softDeletes), `plan_prices` (har billing cycle ki alag row — #175), `features`, `limits`, `plan_feature` + `plan_limit` pivots, `subscriptions`, `subscription_payments`, `business_feature_overrides`, `business_limit_overrides`, `business_notes`.
- **Design faisla:** "Free" (#173) aur "Lifetime" (#174) **flag nahi hain** — free = jiski prices 0 hain, lifetime = jiske paas `lifetime` price row hai. Jitne kam flags, utni kam states jo aapas mein jhagra karein.
- **Subscriptions append-only history (#176, #198):** renew / upgrade / downgrade **nayi row** banati hai aur purani pe `superseded_at` lagta hai — purani billing kabhi rewrite nahi hoti. "Current" = wo row jiska `superseded_at` NULL hai.
- Plan jo kisi subscription mein use ho raha ho wo **delete nahi hota, archive hota hai** (`restrictOnDelete` + softDeletes — #104), warna purane record ka plan resolve hi na ho.

**2) Entitlement core — 3 services + 2 registries**
- `Support/FeatureRegistry.php` — **57 feature codes** categories mein (accounting, branches, products, POS, customers, inventory, reports, team, integrations…), har ek ke label/description/default ke sath (#128).
- `Support/LimitRegistry.php` — **12 quota codes**: products, categories, brands, customers, suppliers, employees, branches, pos_counters, warehouses, invoices_per_month, sms_per_month, storage_mb (#8, #129).
- `Services/FeatureService.php` — resolution order **business override → plan pivot → registry default → off**; `enabled/disabled/allOf/anyOf/all/enabledCodes/authorize`; poora map cache hota hai (`subscription.cache_ttl`) aur plan/override badalte hi flush.
- `Services/PlanLimitService.php` — `limit/isUnlimited/usage/remaining/canCreate/assertCanCreate/meter/meters`. **NULL = Unlimited** (#8). Usage ginne ke liye `registerUsageResolver()` — abhi sirf `employees` ka resolver hai; har agla phase apni table ka resolver khud register karega, taake ye class un tables ka naam na jane jo abhi wujood mein hi nahi.
- `Services/SubscriptionService.php` (717 lines) — padhne wale: `current/history/isActive/isOnTrial/isInGrace/daysRemaining/plan/expiryBehavior/hasFeature/getLimit/usage/canCreate/meters` (#186); likhne wale: `startTrial` (#81), `assign`, `renew`, `changePlan` (upgrade/downgrade, bache hue din ka credit — data kabhi delete nahi hota #83), `cancel`, `resume`, `extend`, `addTrialDays`, `recordPayment` (#82), `reconcileStatuses`, `expiringWithin`. **Har write DB transaction mein** (#69, #98).

**3) Access gates — middleware**
- `CheckSubscription` — expiry behavior config se (`lock` / `read_only` / `pos_off` — #11), grace period (#127) ke andar tenant andar aata hai magar warning ke sath, aur **`billing` + `logout` hamesha khule** rehte hain warna tenant apna masla theek hi na kar sake.
- `CheckFeature` — route-level deny (#80, #125): HTML pe redirect + flash, API pe **403 JSON**, aur galat feature code likho to **loudly fail** karta hai (chup-chaap allow nahi karta).
- `bootstrap/app.php` mein **`tenant.app` group** = `auth:web` → `tenant` → `subscription`. Wajah: naya route add karte waqt paywall lagana **bhoolna mumkin hi na ho** (#187) — ye Phase 2 ka sab se important security faisla hai.
- ⚠️ **Status column pe kabhi bharosa nahi:** access hamesha **dates se derive** hota hai (`Subscription::effectiveStatus`). Cron band bhi ho jaye to expired tenant sale nahi kar sakta, aur stale column ki wajah se paid tenant lock nahi hota. Dono cases ke tests likhe hain.

**4) Super Admin — business management (#6, #126, #159, #177, #178, #179)**
- **Businesses:** index (search + status/plan/subscription filters + stat cards), create/edit (business + owner user aik hi form mein), **show** = subscription card + actions + payments + **usage vs quota meters** (#126).
- **Actions:** suspend / activate / **owner ka password reset** / **Sign in as owner (impersonate #178)** — impersonation banner ke sath, aur `stop-impersonating` route jaan boojh kar **tenant + subscription gates se bahar** hai taake operator kabhi phanse na.
- **Subscription actions:** change plan / renew / extend expiry / add trial days / cancel / resume / record payment — sab `SubscriptionService` ko delegate (thin controllers #98).
- **Overrides page** (#10): per-business feature on/off aur limit ka number; har change pe **reason + audit log** (#177) + caches flush.
- **Plans:** CRUD + har cycle ki price + features/limits ke toggles + activate/deactivate, aur **comparison matrix** (#84) jisme "off" aur "unconfigured" alag alag dikhte hain.
- **Internal notes** (#159) — sirf operator dekhta hai; pin/edit/delete.
- **System alerts** (#179) — `SystemNotificationService`: expired/lapsing subscriptions, bina plan wale businesses, unconfirmed payments, failed jobs; topbar badge + alag page + manual reconcile button.

**5) Tenant side (business panel)**
- Sidebar **entitlement ke hisab se filter** hota hai (#13, #125) — jo feature plan mein nahi, uska link dikhta hi nahi (grey nahi, bilkul gayab). Dashboard aur Billing hamesha rehte hain.
- Sidebar mein 2–3 sab se tight quota ke **meters**, billing page pe saare.
- `app/billing` — current plan, price, renew date, plan mein kya shamil hai, **usage vs quota** (#78); `app/billing/plans` — cycle switcher (Monthly/Quarterly/Half-Yearly/Yearly) + poora feature comparison.
- `<x-subscription-banner>` — trial / grace / expiring (config ke `warning_days` = **7,3,1** #11) / expired / cancelled — sab states aik component mein, sab se sanjeeda sach pehle.

**6) Config + scheduler**
- Naya `config/subscription.php`: currency, `trial_days`, `grace_days`, `expiry_behavior`, `warning_days`, `payment_methods`, `cache_ttl` — sab env-driven (#190).
- `subscriptions:reconcile` command + **daily 00:10 schedule** (`withoutOverlapping()` + `onOneServer()`) — ye sirf stored status ko dates ke sath sync karta hai; access control iske bharose **nahi** hai.

**7) ⚠️ Tests — 98 naye, sab PASS**
- `Subscription/PlanLimitTest` (29), `Subscription/PlanFeatureTest` (21), `Subscription/SubscriptionExpiryTest` (26), `Subscription/SubscriptionGateTest` (22).
- `TenantIsolationTest` 16 → **20** (subscriptions aur payments ki cross-tenant scoping bhi cover ho gayi).
- **Result: `php artisan test` → 152 tests / 469 assertions PASS** (MySQL `pos_saas_test`).

**8) Build + browser verification (28 Aug)**
- ⚠️ **Build stale tha:** `public/build` 26 Aug ka tha jabke Phase 2 ki views 27 Aug ki — yani Tailwind ne nayi classes scan hi nahi ki thin. `npm run build` dobara chalaya: **app.css 68.41 kB → 101.99 kB** (gzip 16.42 kB). Sabaq: **views badlo to build dobara chalao**, warna UI toota dikhta hai.
- ✅ Browser verified (`php artisan serve`): admin login → dashboard (growth chart render + lazy apexcharts chunk 200), **plans**, **plan comparison matrix**, **businesses index**, **business show** (subscription + meters + payments), **overrides**, **subscriptions**, **system alerts**; business login → dashboard (feature-filtered sidebar + meters), **billing**, **billing/plans**; aur **impersonation start → banner → stop → "Impersonation ended."** — sab sahi. Zero console errors, zero failed requests.

**🐞 Verification ne jo pakra:**
1. **Double-escaped page title** — `resources/views/app/billing/index.blade.php` mein `<x-layouts.app title="Billing &amp;amp; plan">` tha. Blade attribute ki value literal string rehti hai, phir `{{ $title }}` usay dobara escape karta hai → screen pe **"Billing &amp;amp; plan"** likha aa raha tha. Fix: component prop mein seedha `&` likho. (Plain HTML text mein `&amp;amp;` bilkul theek hai — wahan haath nahi lagaya.)
2. **Stale build** (upar #8) — Phase 2 ki poori CSS missing thi.
3. Do purane placeholder labels theek kiye: admin dashboard ka "Tenant management — Phase 2" ab **"All businesses →" ka asli link** hai, aur tenant dashboard ka banner "Phase 1 · Auth & tenancy live" → "Phase 2 · Plan & subscription live".

**9) Git (Phase 0 ka pending item)**
- ✅ `git init` + pehla commit — Phase 0–2 ka poora code. `.gitignore` pehle se sahi tha (`.env`, `vendor`, `node_modules`, `public/build` ignored).

**⏭️ Deliberately deferred (wajah ke sath):**
- **Limit usage resolvers** — abhi sirf `employees` ka hai, kyunke ginne ke liye baqi tables (products/customers/invoices) abhi bani hi nahi. Enforcement ka engine (`assertCanCreate` + `LimitExceededException`) tayyar aur tested hai; har module apna resolver apne phase mein register karega (#79).
- **`CheckFeature` abhi kisi asli route pe laga nahi** — gate karne ke liye modules Phase 3/4 se aayenge. Middleware khud poori tarah tested hai.
- **Public pricing page + self-serve trial signup** → Phase 12 (plan pe `is_public` flag ready hai).
- **Online payment gateway** — abhi payments **manually record** hoti hain (operator entry). Gateway spec mein nahi hai; chahiye to alag se batayen.

➡️ **Next: Phase 3** — Roles + Permissions (3-layer check: Subscription Feature + User Permission + Tenant #187) + Branches + POS Counters + Employees.


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
- [x] Git repository init *(28 Aug 2026 — `git init` + pehla commit: Phase 0–2 ka poora code)*

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
- [x] `created_by` / `updated_by` tracking *(`Blameable` ab **attached** hai — branches, pos_counters aur roles pe; har nayi table isay le kar aayegi)* — #3

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
- [x] Dynamic plans CRUD (name, desc, prices per cycle, trial, badge, order) — #7 *(`Admin\PlanController` + `PlanRequest`; sab kuch DB mein, koi deploy nahi chahiye)*
- [x] Billing cycles (Monthly/Quarterly/Half-Yearly/Yearly/Lifetime/Custom) — #175 *(`BillingCycle` enum + `plan_prices` mein har cycle ki alag row)*
- [x] Free plan (price 0) — #173 *(flag nahi — jiski prices 0 hain wo free hai)*
- [x] Lifetime plan — #174 *(jiske paas `lifetime` price row ho; subscription ka `ends_at` NULL = kabhi expire nahi)*
- [x] "Show on Website" toggle per plan — #172 *(`plans.is_public`; asli pricing website Phase 12)*

### Plan Limits (`PlanLimitService`, `LimitRegistry`)
- [x] Configurable limits (products, customers, employees, branches, POS, invoices, storage...) — #8, #129 *(`LimitRegistry` mein 12 codes + `plan_limit` pivot)*
- [x] Numeric vs Unlimited option — #8 *(NULL = unlimited, `isUnlimited()`)*
- [x] **Backend limit enforcement** (501st product block, etc.) — #79 *(`canCreate()` / `assertCanCreate()` + `LimitExceededException`, poora tested. Usage resolver abhi sirf `employees` ka — baqi module apne phase mein `registerUsageResolver()` karega)*
- [x] Plan usage meter display (350/500) — #78 *(`<x-meter>` — sidebar mein tightest 2–3, billing page pe saare, admin ke business show pe bhi)*

### Dynamic Features (`FeatureService`, `FeatureRegistry`)
- [x] Central feature registry (feature codes) — #128 *(`FeatureRegistry` — 57 codes, categories + labels + defaults)*
- [x] Enable/disable features per plan (toggles) — #9 *(`plan_feature` pivot + plan form ke toggles)*
- [x] **Feature enforcement** (menu hide + button hide + backend route deny) — #80, #125 *(nav entitlement se filter hota hai; `FeatureService::authorize()` + `CheckFeature`. Asli module routes pe Phase 3/4 se lagega — abhi gate karne ko module hi nahi)*
- [x] `CheckFeature` middleware — #130 *(alias `feature:`; HTML redirect, API 403, unknown code pe loudly fail)*

### Business-Level Overrides
- [x] Individual business feature override — #10 *(`business_feature_overrides` + overrides page, dono taraf — on aur off)*
- [x] Individual business limit override — #10 *(`business_limit_overrides`; "follow plan" pe wapas bhi ja sakte hain)*
- [x] Override change audit log — #177 *(reason mandatory, `AuditService` se log, caches flush)*

### Subscriptions (`SubscriptionService`)
- [x] `isActive() / hasFeature() / getLimit() / canCreate() / usage() / daysRemaining()` — #186 *(+ `current/history/isOnTrial/isInGrace/plan/meters`)*
- [x] Subscription expiry behavior (lock / read-only / POS-off) — #11 *(`ExpiryBehavior` enum, `config/subscription.php` se; billing + logout hamesha khule)*
- [x] Expiry warnings (7/3/1 days) — #11 *(`warning_days` config + `<x-subscription-banner>`)*
- [x] Grace period (configurable) — #127 *(resolution: subscription → plan → config)*
- [x] `CheckSubscription` + `CheckBusinessStatus` middleware — #130 *(business status ka check `SetBusinessTenant` mein fail-closed hai, is liye alag middleware ki zaroorat nahi rahi)*
- [x] Trial system — #81 *(`startTrial()` + `addTrialDays()`; self-serve signup Phase 12)*
- [x] Subscription payment records — #82 *(`subscription_payments`, `PaymentStatus` enum, append-only — galti edit se theek hoti hai, delete se nahi. Manual entry; gateway spec mein nahi)*
- [x] Upgrade / downgrade (data safe on downgrade) — #83 *(`changePlan()` — bache hue din ka credit, nayi row, purana data kabhi delete nahi)*
- [x] Subscription history — #176 *(append-only rows + `superseded_at`; "All subscriptions" view)*

### Super Admin — Business Management
- [x] Create/Edit/Delete business (full form) — #6 *(business + owner user aik hi form mein; delete = archive #104)*
- [x] Suspend / Activate / Change plan / Extend / Add trial days — #6 *(sab `SubscriptionService` ke through, har action audited)*
- [x] Reset password / Login-as (impersonate) — #6, #178 *(impersonation banner + `stop-impersonating` route gates se bahar taake operator phanse na)*
- [x] Business usage details view — #126 *(business show pe usage vs quota meters, live counted)*
- [x] Plan permission comparison matrix — #84 *(`admin/plans/matrix` — "off" aur "unconfigured" alag dikhte hain)*
- [x] Private internal support notes — #159 *(`business_notes` — pin/edit/delete, sirf operator ko dikhte hain)*
- [x] System notifications (failed jobs, expiring, expired) — #179 *(`SystemNotificationService` + topbar badge + alerts page + manual reconcile)*

---

## PHASE 3 — Roles + Permissions + Branches + POS Counters + Employees
*(Spec ref: #4, #47–52, #138, #140–141, #187–188)*

### Roles & Permissions
- [x] Module/action based permission system — #51 *(`Support/PermissionRegistry` — 49 codes, `module.action`, 10 groups; ek test ye bhi check karta hai ke permission aur feature codes kabhi na takrayein)*
- [x] Custom roles (Business Owner creates) — #51 *(`roles` + `role_permissions` per-business; 3 starter roles har naye tenant mein copy hote hain — editable, deletable nahi)*
- [x] Sensitive permissions (view cost/profit, delete/cancel invoice, exports) — #52 *(24 codes `sensitive` flagged; editor mein amber badge)*
- [x] **3-layer access check: Subscription Feature + User Permission + Tenant** — #187 *(`Services/PermissionService` — feature pehle taake user ko "upgrade" bataya jaye, phir role, phir tenant; owner role system se upar magar plan se upar nahi)*
- [x] `RolePermission` middleware + Policies/Gates — #130, #188 *(`permission:` middleware + har code ka `Gate::define()`; jaan boojh kar koi `Gate::before` owner-bypass nahi — warna Gate aur service ka jawab alag ho sakta tha. `Policies/` Phase 4+ mein jab per-row ownership wale models aayenge)*

### Branches
- [x] Branch management CRUD — #47 *(`BranchService`; main branch guarantee, code archive ke baad bhi reserved, delete sirf khali branch ka #104)*
- [x] Branch data control (Owner=all, Manager=own, Cashier=own POS) — #48 *(`BranchContext` + `BranchScope` + `BelongsToBranch`; tenant scope ke NEECHE chalta hai — narrow kar sakta hai, widen kabhi nahi. Branch na ho to kuch nahi dikhta)*

### POS Counters
- [x] POS counter management (per branch) — #49 *(`PosCounterService`; multi-counter feature + quota + "sirf apni reachable branch mein")*

### Employees
- [x] Employee management (form + role + branch + POS assign) — #50 *(`EmployeeService`; role/branch/counter sab tenant-scoped validate hote hain, ownership kabhi transfer nahi hoti)*
- [x] Branch-level employee access (primary branch) — #138 *(`users.branch_id` → `BranchContext`; deactivate karte hi agli request pe session khatam)*
- [x] Discount restrictions (cashier max discount %) — #141 *(`users.max_discount_percent` — blank = koi cap nahi, **0 = bilkul discount nahi**; `mayDiscount()` tested. POS screen pe asli rok Phase 7)*
- [x] Returns permission (separate) — #140 *(`sales.return` alag permission, sensitive, aur `sales.returns` feature pe khari)*

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
- [x] Feature tests: Login + Permissions *(`Auth/AuthenticationTest` 22 + `Auth/PasswordResetTest` 11 + `Organization/RolePermissionTest` 31 — dono guards, cross-guard denial, throttle, enumeration safety, aur poora 3-layer permission check)* — #116
- [x] Feature tests: Plan Limits, Plan Features — #116 *(`Subscription/PlanLimitTest` 29 + `Subscription/PlanFeatureTest` 21 — resolution order, unlimited, enforcement, cache invalidation, `CheckFeature` middleware)*
- [ ] Feature tests: POS Sale, Stock Update — #116
- [ ] Feature tests: Purchase, Returns — #116
- [ ] Feature tests: Customer/Supplier Balance — #116
- [x] Feature tests: Subscription Expiry — #116 *(`Subscription/SubscriptionExpiryTest` 26 + `Subscription/SubscriptionGateTest` 22 — trial/grace/expiry, lock vs read-only vs pos-off, stale status column dono taraf se)*
- [x] ⚠️ Tenant leak test (Business A → Business B URL = 403/404) — #117 *(cross-tenant PK `find()` → null; dashboard HTTP test dono tenants pe; request input se tenant switch block)*

> **Ab tak ka test status:** `php artisan test` → **222 tests / 772 assertions PASS** (MySQL `pos_saas_test`) — Auth 22 · PasswordReset 11 · TenantIsolation 20 · PlanLimit 29 · PlanFeature 21 · SubscriptionExpiry 26 · SubscriptionGate 22 · RolePermission 31 · BranchAccess 19 · Employee 20 · Unit 1. Har naye phase ke saath yahan tests barhte rahenge.

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
