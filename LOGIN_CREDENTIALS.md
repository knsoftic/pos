# 🔑 KN Softic POS — Demo Login Details

> ⚠️ **Sirf local development ke liye.** Ye sab accounts `DatabaseSeeder` banata hai aur inka password
> ek hi hai. Production pe deploy karne se pehle **saare demo accounts delete ya password change karna
> zaroori hai** (spec #191). Ye file kabhi production server pe na jaye.

**Har seeded account ka password:** `password`

---

## 🛡️ Super Admin (SaaS operator console)

| | |
|---|---|
| **URL** | http://localhost:8000/admin/login |
| **Panel** | KN Softic Operator Console |
| **Email** | `superadmin@pos.test` |
| **Password** | `password` |

Yahan se: businesses banana/suspend karna, plans + prices + features + limits, subscriptions
(assign/renew/extend/trial/cancel), per-business overrides, internal notes, system alerts, aur
**"Sign in as owner"** (impersonation).

---

## 🏪 Business #1 — Demo Retail Store *(Professional plan, paid)*

Business panel URL: **http://localhost:8000/login**

| Role | Email | Password | Branch | Max discount |
|---|---|---|---|---|
| **Owner** (sab kuch) | `owner@demo.test` | `password` | Main Branch | no cap |
| **Cashier** | `cashier@demo.test` | `password` | Main Branch | 10% |

**Kya farq dikhega?** Owner ko poora sidebar milta hai (Employees, Roles, Branches, POS Counters…),
jabke Cashier ko sirf Dashboard / POS / Sales / Products / Customers / Branches / Billing dikhta hai —
aur `/app/employees` khud type kare to wapas dashboard pe *"You do not have permission…"* ke saath aata hai.
Yehi Phase 3 ka permission system live hai.

---

## 🏬 Business #2 — Second Shop *(Starter plan, trial)*

| Role | Email | Password | Branch |
|---|---|---|---|
| **Owner** | `owner2@demo.test` | `password` | Main Branch |

Ye deliberately **doosre plan pe** hai, taake feature gating ka farq nazar aaye (Starter mein kam
features + choti quotas). Iska data Business #1 se poori tarah isolated hai — dono ko alag browser
window mein khol kar khud test kar sakte hain.

---

## 🧪 Phase 3 verification ke waqt banaya gaya account (seeder ka hissa nahi)

| Role | Email | Password | Branch | Max discount |
|---|---|---|---|---|
| **Manager** | `sara@demo.test` | `Str0ng!Passw0rd` | Main Branch | 25% |

⚠️ Ye account **seeder mein nahi hai** — `php artisan migrate:fresh --seed` chalate hi khatam ho jayega.
Upar wale chaar accounts hamesha wapas aa jate hain.

---

## ⚙️ Environment

| | |
|---|---|
| **App URL** | http://localhost:8000 *(`php artisan serve`)* — XAMPP se chalayen to `http://localhost/pos/public` |
| **Dev database** | `pos_saas` (MySQL, user `root`, password khali) |
| **Test database** | `pos_saas_test` — `php artisan test` isi pe chalta hai, dev data ko haath nahi lagata |

### Demo data dobara banane ke liye

```bash
php artisan migrate:fresh --seed
```

Isse milta hai: 1 super admin · 5 plans (Free / Starter / Professional / Business / Lifetime) ·
57 features · 12 limits · 2 businesses (har ek ko Main Branch + Counter 1 + 3 starter roles:
Manager, Cashier, Stock Keeper) · aur upar wale 4 logins.

> **Password policy** `config/security.php` se aati hai (default: kam se kam 8 characters + upper/lower
> + number). Naya employee banate waqt yehi rules lagti hain — is liye demo ka `password` sirf seeder
> se hi ban sakta hai, form se nahi.
