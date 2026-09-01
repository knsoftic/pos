# pos.knbazaar.com ko aaPanel par live karna

**Target:** `pos.knbazaar.com` → `/www/wwwroot/pos.knbazaar.com`

Ye runbook hai, tafseel nahi. Upar se neeche isi tarteeb mein chalein. Aakhri
step — `pos:preflight` — wo hai jo aap ko batata hai ke baqi sab waqai kaam kar
gaya ya nahi. Sab theek "lag raha ho" tab bhi isay skip na karein.

> **Menu ke naam badalte rehte hain.** aaPanel version aur zaban ke hisab se
> labels change karta hai. Jahan yahan likha label aap ke panel se match na
> kare, wahan *italic* mein diya rasta batata hai ke aap kis cheez ko dhoond
> rahe hain.

---

## ⚠️ Shuru karne se pehle ye parh lein

aaPanel ki teen khaas cheezein aap ka din kha jayengi agar achanak saamne
aayen. Teeno neeche ke steps mein handle ho chuki hain; yahan is liye likhi
hain ke aap unhein **pehchan** lein, debug karte na reh jayen.

| Aap ko kya dikhega | Asal mein kya hai |
|---|---|
| Site khulti hai magar **bilkul styling nahi** — plain text, koi rang nahi | `public/build` git mein hai hi nahi. Fresh clone ke paas compiled assets nahi hote. **Step 6.** |
| `php artisan pos:backup` fail, ya `storage:link` chup-chaap kuch nahi karta | aaPanel default par `proc_open`, `symlink` aur `putenv` band rakhta hai. **Step 3.** |
| Home page ke ilawa **har URL 404** | Run directory `/public` nahi hai, ya rewrite rules nahi lage. **Step 5.** |

---

## 1. Site banayein

*Website → Add site*

| Field | Value |
|---|---|
| Domain | `pos.knbazaar.com` |
| Root directory | `/www/wwwroot/pos.knbazaar.com` |
| PHP version | **8.2 ya us se naya** |
| Database | MySQL — tick kar dein, aaPanel khud bana dega |

Jo database ka naam, user aur password wo generate kare, wo likh lein. Un ki
zaroorat step 4 mein hai aur kahin nahi.

---

## 2. PHP aur uski extensions install karein

*App Store → PHP 8.2 → Settings → Install extensions*

Laravel ka aam set, aur do jo khaas is app ko chahiye:

```
bcmath  ctype  curl  dom  fileinfo  json  mbstring  openssl
pcre    pdo    pdo_mysql  tokenizer  xml  zip  gd
```

- **`zip`** — `pos:backup` apna archive `ZipArchive` se banata hai, aur `.xlsx`
  report exporter spreadsheet ko haath se XML parts ki ZIP ki tarah likhta hai.
  Iske bagair dono fail hote hain.
- **`gd`** — product image validation file ko decode kar ke sabit karti hai ke
  wo waqai tasveer hai. Isi tarah `.jpg` naam wali PHP script refuse hoti hai.

---

## 3. Jo functions aaPanel ne band kar rakhe hain, wo kholein

*App Store → PHP 8.2 → Settings → Disabled functions*

**Yehi wo step hai jo log skip kar dete hain.** aaPanel aik `disable_functions`
list ke saath aata hai jo shared WordPress hosting ke liye theek hai aur Laravel
application ke liye ghalat. Ye teen list se nikaal dein:

| Function | Iske bagair kya tootta hai |
|---|---|
| `proc_open` | `pos:backup` — ye `mysqldump` ko Symfony Process se chalata hai. Composer ko bhi chahiye. |
| `symlink` | `php artisan storage:link` — product images aur uploaded receipts hamesha 404 dete rahenge. |
| `putenv` | Composer, aur wo sab jo runtime par env parhta hai. |

Baqi list ko haath na lagayen. `exec` aur `shell_exec` ki zaroorat **nahi** — ye
application unhein kabhi call nahi karti, aur unhein kholne ki koi wajah nahi.

Phir PHP restart karein: *App Store → PHP 8.2 → Service → Restart*.

---

## 4. Code lein aur configure karein

SSH se root ban kar andar aayen.

```bash
cd /www/wwwroot/pos.knbazaar.com
rm -rf .user.ini index.html 404.html          # aaPanel ki placeholder files
git clone https://github.com/knsoftic/pos.git .
```

Neeche har jagah aaPanel wali PHP binary ka poora rasta likha hai, kyunke box
par system ka `php` aksar koi aur, purana version hota hai:

```bash
PHP=/www/server/php/82/bin/php
$PHP -v
```

Dependencies, **dev packages ke bagair**:

```bash
composer install --no-dev --optimize-autoloader
```

> Agar `composer` PATH par na ho: *App Store → PHP 8.2 → Settings → Composer →
> Install*, phir `/www/server/php/82/bin/php /usr/bin/composer` istemal karein.

Ab environment file. **Production** wali example se shuru karein,
`.env.example` se nahi:

```bash
cp .env.production.example .env
$PHP artisan key:generate
```

`.env` edit kar ke ye bharein:

```ini
APP_URL=https://pos.knbazaar.com

DB_DATABASE=<jo naam aaPanel ne banaya>
DB_USERNAME=<jo user aaPanel ne banaya>
DB_PASSWORD=<jo password aaPanel ne banaya>

# aaPanel ki apni MySQL, system wali nahi
BACKUP_MYSQLDUMP=/www/server/mysql/bin/mysqldump

# MAIL_MAILER=log chhorne ka matlab: password reset kaam karta
# lagega aur email kabhi nahi jayegi.
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="no-reply@knbazaar.com"
```

Example mein `APP_KEY` aur `DB_PASSWORD` jaan boojh kar khali hain — committed
file mein qabil-e-yaqeen placeholder wo credential hai jo koi na koi aakhir ship
kar hi deta hai.

---

## 5. Web server ka rukh `public/` ki taraf karein

*Website → pos.knbazaar.com → Settings → Site directory*

**Running directory** ko `/public` set karein.

**Ye routing ka masla nahi, security ka hai.** `/www/wwwroot/pos.knbazaar.com`
ke andar `.env`, `storage/` aur `vendor/` hain. Us directory ko serve karna aap
ka *database password publish karna* hai. `/public` par le jane se baqi sab web
root se aik level upar chala jata hai, jahan browser pohanch hi nahi sakta.

Phir *Settings → URL Rewrite* → **`laravel5`** preset chunein. Agar aap ke panel
mein preset na ho to ye paste kar dein:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

**Abhi check karein**, aage barhne se pehle:
`https://pos.knbazaar.com/login` ko 404 ke ilawa kuch dena chahiye. Yahan 404 ka
matlab hai ke in do settings mein se koi lagi nahi.

---

## 6. Front-end assets build karein

**`public/build` repository mein hai hi nahi.** Fresh clone ke paas compiled CSS
ya JS nahi hoti, aur jab tak ye na ho site plain text ki tarah dikhegi. Do mein
se aik tareeqa chunein:

**Option A — server par build karein** (*App Store → Node.js version manager* →
Node 18+ install karein):

```bash
cd /www/wwwroot/pos.knbazaar.com
npm ci
npm run build
```

**Option B — apni machine par build kar ke upload karein.** Apne computer par
`npm run build` chalayein, phir poora `public/build` folder
`/www/wwwroot/pos.knbazaar.com/public/build` par upload kar dein.

Ye **har** us deploy ke baad dobara karna hai jismein koi Blade view ya CSS
badla ho — Tailwind v4 templates ko *build time* par scan karta hai, to jo class
kabhi build hi nahi hui wo mojood hi nahi.

---

## 7. Migrate karein — aur dekhein ke kya *nahi* hota

```bash
$PHP artisan migrate --seed --force
```

`APP_ENV=production` ke saath ye feature aur limit registries aur aik starting
plan catalogue banata hai, aur **koi account bilkul nahi**. Ye kharabi nahi,
jaan boojh kar hai: demo shop aik alag seeder mein hai jo production mein chalne
se inkar karta hai. Aap ko ye dikhna chahiye:

```
Production: demo data skipped. Run `php artisan pos:preflight` next.
```

---

## 8. Apna operator login banayein

Kuch seed nahi hua, to abhi `/admin` tak koi pohanch hi nahi sakta:

```bash
$PHP artisan pos:make-admin
```

Ye password *poochta* hai, argument ki tarah leta nahi — command line par likha
password shell history mein mehfooz ho jata hai aur, jab tak command chalti hai,
`ps` mein box ke har doosre user ko dikhta hai.

---

## 9. Permissions, link aur caches

aaPanel nginx aur PHP-FPM ko **`www`** user se chalata hai. Root se clone ki hui
files us ke liye writable nahi hotin, aur Laravel ko do jagah likhna hota hai.

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

`storage:link` ko `symlink` chahiye (step 3). Agar wo kamyabi ka paighaam de
magar `public/storage` bane hi na, to wohi band function wajah hai — wapas
jayen.

> `artisan optimize` **har** deploy ke baad dobara chalayein. Cached config wo
> cheez bhi hai jis ki wajah se `.env` ki tabdeeli chup-chaap bekaar chali jati
> hai.

---

## 10. HTTPS

*Website → pos.knbazaar.com → SSL → Let's Encrypt* → certificate issue karein,
phir **Force HTTPS** on kar dein.

Jab browser mein HTTPS chalta dikh jaye, tab `.env` tight karein:

```ini
SESSION_SECURE_COOKIE=true
SECURITY_HSTS_ENABLED=true
```

```bash
$PHP artisan optimize
```

**Isi tarteeb mein.** HSTS browsers ko kehta hai "is host se ab kabhi HTTP par
baat na karna" — saal bhar ke liye, aur ye *wapas nahi liya ja sakta*.
Certificate kaam karne se pehle on kar diya to aap apni hi site se utni muddat
ke liye bahar ho jayenge.

---

## 11. Cron entry

*Cron → Add task*

| Field | Value |
|---|---|
| Type | Shell Script |
| Name | `pos schedule` |
| Period | **Har 1 minute** |

```bash
cd /www/wwwroot/pos.knbazaar.com && /www/server/php/82/bin/php artisan schedule:run >> /dev/null 2>&1
```

Yehi aik line sab kuch chalati hai: subscription reconciliation, purane held
sales ka khatma, raat 01:00 ka backup, 02:00 ka integrity sweep, haftawar
pruning, aur har paanch minute aik heartbeat — taake ruka hua cron *nazar aa
sake*, chhupa na rahe.

Scheduled kaam mein se koi bhi access control ke liye zaroori nahi —
subscription access har request par tareekhon se nikala jata hai, to kharab cron
kisi expired tenant ko bechte rehne nahi de sakta.

---

## 12. Server se khud poochein ke wo taiyar hai ya nahi

```bash
$PHP artisan pos:preflight
```

Checklist aap ko keh sakti hai ke debug band kar dein. Magar wo *band hai ya
nahi*, ye sirf box bata sakta hai.

**Backup warning ke ilawa sab pass hona chahiye** — wo pehle raat ke backup ke
baad khud saaf ho jati hai. Agar kuch red ho to URL kisi ko dene se pehle theek
karein:

| Failure | Hal |
|---|---|
| Debug mode | `.env` mein `APP_DEBUG=false`, phir `artisan optimize` |
| Operator account | Step 8 |
| Demo accounts / Seeded passwords | Kisi ne demo seeder chala diya. Wo accounts delete karein. |
| Writable paths | Step 9 |
| Migrations | `artisan migrate --force` |

Warnings parhne layeq hain magar rokti nahi. Scheduler wali warning paanch
minute ke andar saaf ho jati hai agar step 11 theek hai.

> ⚠️ Preflight pass ho jane ka matlab **mehfooz hona nahi**. Matlab sirf ye hai
> ke jo ghaltiyan check karna sasta tha, wo nahi hui. Ye server, database grants
> ya network mein kuch nahi dekhti.

---

## 13. Backups — inhein is machine se bahar le jayen

Pehla backup aaj raat 01:00 par `storage/app/private/backups` mein banega.
**Ye database wali hi disk hai**, to ye kharab migration se bacha lega aur bas —
na mari hui disk se, na aag se, na ransomware se, jo us folder ko baqi sab ke
saath encrypt kar deta hai.

Do mein se aik karein:

- `BACKUP_DISK` ko S3 ya kisi aur off-box disk par set karein.
- Ya `/www/wwwroot/pos.knbazaar.com/storage/app/private/backups` ko aaPanel ke
  apne backup (*Backup → add directory*) mein remote destination ke saath daal
  dein.

Phir aik backup haath se lein aur **zaroorat parne se pehle aik baar restore kar
ke dekhein**:

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

Isay `Everything reconciles.` likhna chahiye — is se sabit hota hai ke restore
hua data sirf *mojood* nahi, *durust* bhi hai. Phir `pos_restore_check` drop kar
dein. `files/public/` ko wapas `storage/app/public/` par copy kar dein.

**Jo backup kabhi restore na kiya gaya ho, wo backup nahi — aik file hai.**

---

## Baad mein update deploy karna

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

Agar koi view ya CSS badla ho to `npm run build` optional nahi — Tailwind
templates ko build time par scan karta hai.

---

## Jab kuch kharab ho

| Alamat | Kahan dekhein |
|---|---|
| Bina styling ka page | Step 6. `ls public/build` — agar khali hai to yehi wajah hai. |
| `/` ke ilawa har route par 404 | Step 5 — run directory aur rewrite rules. |
| Har page par 500 | `storage/logs/laravel.log`. Aksar permissions (step 9) ya ghayab `APP_KEY`. |
| 500 page par chhota sa code | Wo code `storage/logs/security.log` mein asli stack trace ke saath mojood hai. Usay grep karein. |
| Images aur receipts 404 | `storage:link` chala hi nahi — `symlink` abhi band hai (step 3). |
| Backup fail hota hai | `proc_open` band hai (step 3), ya `BACKUP_MYSQLDUMP` ghalat binary par hai. |
| Password reset kuch nahi karta | `MAIL_MAILER` abhi `log` hai. Preflight is par warning deti hai. |
| Held sales jama ho rahe hain, statuses purane | Cron nahi chal raha. `pos:preflight` paanch minute ke andar bata deti hai. |

Application ka log `storage/logs/laravel.log` hai. Do aur jaanna zaroori hai:
`security.log` (refusals, lockouts, ghair-mutawaqqa errors — 180 din) aur
`financial.log` (wo money writes jo roll back ho gaye aur kahin koi row nahi
chhori — aik saal).

---

© 2026 KN Softic · [support@knsoftic.com](mailto:support@knsoftic.com)
