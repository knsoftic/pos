# Deploying KN Softic POS on aaPanel

**Target:** `pos.knbazaar.com` → `/www/wwwroot/pos.knbazaar.com`

This is a runbook, not an overview. Work top to bottom. The last step,
`pos:preflight`, is the one that tells you whether the rest actually worked —
do not skip it because everything "looks fine".

> **Menu names vary.** aaPanel renames things between versions and languages.
> Where a label here does not match your panel, the path in *italics* describes
> what you are looking for.

---

## ⚠️ Read this before you start

Three things about aaPanel specifically will waste your day if you meet them by
surprise. All three are covered in the steps below; they are here so you
recognise them when they bite.

| What you will see | What it actually is |
|---|---|
| The site loads but has **no styling at all** — plain text, no colours | `public/build` is not in git. A clone has no compiled assets. **Step 6.** |
| `php artisan pos:backup` fails, or `storage:link` does nothing | aaPanel disables `proc_open`, `symlink` and `putenv` in PHP by default. **Step 3.** |
| Every URL except the home page returns **404** | The site's run directory is not `/public`, or the rewrite rules are missing. **Step 5.** |

---

## 1. Create the site

*Website → Add site*

| Field | Value |
|---|---|
| Domain | `pos.knbazaar.com` |
| Root directory | `/www/wwwroot/pos.knbazaar.com` |
| PHP version | **8.2 or newer** |
| Database | MySQL — tick it and let aaPanel create one |

Write down the database name, user and password it generates. You need them in
step 4 and nowhere else.

---

## 2. Install PHP and its extensions

*App Store → PHP 8.2 → Settings → Install extensions*

Laravel's usual set, plus two this app specifically needs:

```
bcmath  ctype  curl  dom  fileinfo  json  mbstring  openssl
pcre    pdo    pdo_mysql  tokenizer  xml  zip  gd
```

- **`zip`** — `pos:backup` builds the archive with `ZipArchive`, and the `.xlsx`
  report exporter writes a spreadsheet by hand as a ZIP of XML parts. Without
  it, both fail.
- **`gd`** — product image validation decodes the file to prove it really is an
  image (that is how a PHP script renamed `.jpg` gets refused).

---

## 3. Un-disable the functions aaPanel blocks

*App Store → PHP 8.2 → Settings → Disabled functions*

**This is the step people skip.** aaPanel ships a `disable_functions` list that
is sensible for shared WordPress hosting and wrong for a Laravel application.
Remove these three from the list:

| Function | What breaks without it |
|---|---|
| `proc_open` | `pos:backup` — it runs `mysqldump` through Symfony Process. Composer also wants it. |
| `symlink` | `php artisan storage:link` — product images and uploaded receipts 404 forever. |
| `putenv` | Composer, and anything reading env at runtime. |

Leave the rest of the list alone. `exec` and `shell_exec` are **not** needed —
this application never calls them, and there is no reason to open them.

After saving, restart PHP: *App Store → PHP 8.2 → Service → Restart*.

---

## 4. Get the code and configure it

SSH in as root.

```bash
cd /www/wwwroot/pos.knbazaar.com
rm -rf .user.ini index.html 404.html          # aaPanel's placeholder files
git clone https://github.com/knsoftic/pos.git .
```

Everything below uses the aaPanel PHP binary explicitly, because the system
`php` on the box is often a different, older one:

```bash
PHP=/www/server/php/82/bin/php
$PHP -v
```

Install dependencies **without** dev packages:

```bash
composer install --no-dev --optimize-autoloader
```

> If `composer` is not on PATH: *App Store → PHP 8.2 → Settings → Composer →
> Install*, then use `/www/server/php/82/bin/php /usr/bin/composer`.

Now the environment file. Start from the **production** example, not
`.env.example`:

```bash
cp .env.production.example .env
$PHP artisan key:generate
```

Edit `.env` and fill in:

```ini
APP_URL=https://pos.knbazaar.com

DB_DATABASE=<the name aaPanel generated>
DB_USERNAME=<the user aaPanel generated>
DB_PASSWORD=<the password aaPanel generated>

# aaPanel's own MySQL, not the system one
BACKUP_MYSQLDUMP=/www/server/mysql/bin/mysqldump

# Fill these in from your mail provider. Leaving MAIL_MAILER=log means
# password resets appear to work and no email ever arrives.
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="no-reply@knbazaar.com"
```

`APP_KEY` and `DB_PASSWORD` are empty in the example on purpose — a plausible
placeholder in a committed file is a credential somebody eventually ships.

---

## 5. Point the web server at `public/`

*Website → pos.knbazaar.com → Settings → Site directory*

Set **Running directory** to `/public`.

This is not only about routing. `/www/wwwroot/pos.knbazaar.com` contains `.env`,
`storage/` and `vendor/`. Serving that directory publishes your database
password. Setting the run directory to `/public` puts everything else one level
above the web root, where a browser cannot reach it at all.

Then *Settings → URL Rewrite* → choose the **`laravel5`** preset. If your panel
has no preset, paste this:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

**Check it now**, before going further: `https://pos.knbazaar.com/login` should
return something other than a 404. A 404 here means one of these two settings
did not take.

---

## 6. Build the front-end assets

**`public/build` is not in the repository.** A fresh clone has no compiled CSS
or JS, and the site will render as unstyled text until this is done. Pick one:

**Option A — build on the server** (*App Store → Node.js version manager* →
install Node 18+):

```bash
cd /www/wwwroot/pos.knbazaar.com
npm ci
npm run build
```

**Option B — build on your machine and upload.** Run `npm run build` locally,
then upload the whole `public/build` folder to
`/www/wwwroot/pos.knbazaar.com/public/build`. Do this again after every deploy
that touches a Blade view or CSS — Tailwind v4 scans the templates at build
time, so a new class that was never built simply does not exist.

---

## 7. Migrate — and notice what does *not* happen

```bash
$PHP artisan migrate --seed --force
```

With `APP_ENV=production` this creates the feature and limit registries and a
starting plan catalogue, and **no accounts at all**. That is deliberate, not a
failure: the demo shop lives in a separate seeder that refuses to run in
production. You should see:

```
Production: demo data skipped. Run `php artisan pos:preflight` next.
```

---

## 8. Create your operator login

Nothing was seeded, so nobody can reach `/admin` yet:

```bash
$PHP artisan pos:make-admin
```

It asks for the password rather than taking it as an argument — a password on a
command line is written to the shell history and is visible in `ps` to every
other user on the box while the command runs.

---

## 9. Permissions, links and caches

aaPanel runs nginx and PHP-FPM as the **`www`** user. Files cloned as root are
not writable by it, and Laravel needs to write to two places.

```bash
cd /www/wwwroot/pos.knbazaar.com
chown -R www:www .
chmod -R 755 .
chmod -R 775 storage bootstrap/cache
```

```bash
$PHP artisan storage:link
$PHP artisan optimize
```

`storage:link` needs `symlink` (step 3). If it reports success but
`public/storage` does not exist, that is the disabled function — go back.

> Run `$PHP artisan optimize` again after **every** deploy. A cached config is
> also how a `.env` change silently has no effect.

---

## 10. HTTPS

*Website → pos.knbazaar.com → SSL → Let's Encrypt* → issue, then turn on
**Force HTTPS**.

Once HTTPS is confirmed working in a browser, tighten `.env`:

```ini
SESSION_SECURE_COOKIE=true
SECURITY_HSTS_ENABLED=true
```

```bash
$PHP artisan optimize
```

**Do this in that order.** HSTS tells browsers "never speak to this host over
HTTP again" for a year, and it is not retractable — turn it on before the
certificate works and you have locked people out of your own site until the
max-age expires.

---

## 11. The cron entry

*Cron → Add task*

| Field | Value |
|---|---|
| Type | Shell Script |
| Name | `pos schedule` |
| Period | **Every 1 minute** |

```bash
cd /www/wwwroot/pos.knbazaar.com && /www/server/php/82/bin/php artisan schedule:run >> /dev/null 2>&1
```

That one line drives everything: subscription reconciliation, expiring held
sales, the nightly backup at 01:00, the integrity sweep at 02:00, weekly
pruning, and a heartbeat every five minutes so a stopped cron becomes
*detectable* rather than invisible.

Nothing scheduled is load-bearing for access control — subscription access is
derived from dates on every request, so a broken cron cannot keep an expired
tenant selling.

---

## 12. Ask the server whether it is ready

```bash
$PHP artisan pos:preflight
```

A checklist can tell you to turn debug off. Only the box can tell you whether it
*is* off.

**Everything must pass except the backup warning**, which clears after the first
nightly run. If anything is red, fix it before you give anyone the URL:

| Failure | Fix |
|---|---|
| Debug mode | `APP_DEBUG=false` in `.env`, then `artisan optimize` |
| Operator account | Step 8 |
| Demo accounts / Seeded passwords | Someone ran the demo seeder. Delete those accounts. |
| Writable paths | Step 9 |
| Migrations | `artisan migrate --force` |

Warnings are worth reading but do not block. The scheduler warning clears within
five minutes if step 11 is right.

> ⚠️ Preflight passing does not mean the installation is secure. It means the
> mistakes that are cheap to check for have not been made. It does not inspect
> the server, the database grants or the network.

---

## 13. Backups — move them off this machine

The first backup runs tonight at 01:00 and lands in
`storage/app/private/backups`. **That is on the same disk as the database**, so
it survives a bad migration and nothing else — not a dead disk, not a fire, not
ransomware, which encrypts that folder along with everything else.

Do one of these:

- Set `BACKUP_DISK` to S3 or another off-box disk in `config/filesystems.php`.
- Add `/www/wwwroot/pos.knbazaar.com/storage/app/private/backups` to aaPanel's
  own backup (*Backup → add directory*) with a remote destination.

Then take one by hand and **restore it once, before you need it**:

```bash
$PHP artisan pos:backup
```

```bash
cd /tmp && unzip /www/wwwroot/pos.knbazaar.com/storage/app/private/backups/pos-backup-*.zip -d restore
```

```bash
mysql -u root -p -e "CREATE DATABASE pos_restore_check"
```

```bash
mysql -u root -p pos_restore_check < /tmp/restore/database.sql
```

```bash
cd /www/wwwroot/pos.knbazaar.com && DB_DATABASE=pos_restore_check $PHP artisan pos:check-integrity
```

It should print `Everything reconciles.` — that proves the restored data is
*consistent*, not merely present. Then drop `pos_restore_check`.

A backup nobody has restored is a file.

---

## Deploying an update later

```bash
cd /www/wwwroot/pos.knbazaar.com
git pull
composer install --no-dev --optimize-autoloader
```

```bash
npm ci && npm run build
```

```bash
$PHP artisan migrate --force
$PHP artisan optimize
chown -R www:www storage bootstrap/cache
```

```bash
$PHP artisan pos:preflight
```

If a view or any CSS changed, the `npm run build` is not optional — Tailwind
scans templates at build time.

---

## When something is wrong

| Symptom | Look at |
|---|---|
| Unstyled page | Step 6. `ls public/build` — if it is missing, that is it. |
| 404 on every route but `/` | Step 5 — run directory and rewrite rules. |
| 500 on every page | `storage/logs/laravel.log`. Usually permissions (step 9) or a missing `APP_KEY`. |
| A 500 page showing a short code | That code is in `storage/logs/security.log` with the real stack trace. Grep for it. |
| Images and receipts 404 | `storage:link` did not run — `symlink` is still disabled (step 3). |
| Backup fails | `proc_open` disabled (step 3), or `BACKUP_MYSQLDUMP` points at the wrong binary. |
| Password reset does nothing | `MAIL_MAILER` is still `log`. Preflight warns about this. |
| Held sales piling up, statuses stale | Cron is not running. `pos:preflight` says so within five minutes. |

The application log is `storage/logs/laravel.log`. Two others are worth knowing
about: `security.log` (refusals, lockouts, unexpected errors — kept 180 days)
and `financial.log` (money writes that were rolled back and left no row anywhere
— kept a year).

---

© 2026 KN Softic · [support@knsoftic.com](mailto:support@knsoftic.com)
