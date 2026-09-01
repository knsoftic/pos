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
| **Demo logins** | [LOGIN_CREDENTIALS.md](LOGIN_CREDENTIALS.md) — seeded accounts (dev only, #191) |
| **Current Status** | ✅ **Phase 12 MUKAMMAL (100%)** — Public marketing site (8 pages), pricing **jo asli plans se banti hai** (#108), aur self-service sign-up trial ke saath (#109). **Phases 0–12 mukammal.** **663 tests / 2,621 assertions pass**. Build + browser verified: home, pricing (cycle toggle), feature pages, mobile 375px. ➡️ **Next: Phase 13** (Animations + UI polish + performance). |

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
| **4** | Products + Categories + Brands + Units + Inventory | ✅ Ho gaya | 100% |
| **5** | Customers + Suppliers (+ Ledgers) | ✅ Ho gaya | 100% |
| **6** | Purchases + Supplier Ledger | ✅ Ho gaya | 100% |
| **7** | POS + Sales + Payments + Customer Ledger | ✅ Ho gaya | 100% |
| **8** | Returns + Stock Adjustments + Transfers | ✅ Ho gaya | 100% |
| **9** | Expenses + Profit & Loss | ✅ Ho gaya | 100% |
| **10** | Reports | ✅ Ho gaya | 100% |
| **11** | Settings + Receipt + QR + Barcode | ✅ Ho gaya | 100% |
| **12** | Public Website + Pricing + Trial Registration | ✅ Ho gaya | 100% |
| **13** | Animations + UI Polish + Performance | ⬜ Baqi | 0% |
| **14** | Security + Testing | 🔄 Chal raha hai | ~25% |
| **15** | Deployment Preparation | ⬜ Baqi | 0% |
| | **TOTAL PROGRESS** | 🟢 | **~90%** |

---

## 📝 Session Log (Kaam ki History)

> Naya kaam upar add karo (newest first). Har entry mein: **date**, **kya hua**, **kya next hai**.


### 2026-09-01 — Phase 12 MUKAMMAL ✅ (public website + pricing + sign-up)

Pehli dafa is system ka aik rukh un logon ke liye jinke paas abhi account hai hi nahi.

**✅ JO HO GAYA:**

**1) Alfaz aik jagah, shakl aik jagah (#106, #107)**
- Marketing ke paanch page dar-asal **aik hi page** hain mukhtalif lafzon ke saath: aik hero, aik list, do proof points, aik call to action. Paanch alag templates likhne ka matlab hota ke wo waqt ke saath **bikhar** jate — aik ko naya section milta, aik purani baat pakre rehta, aur dasvi tabdeeli chaar jagah karni parti.
- Isi liye **shakl aik Blade file mein** hai aur **alfaz `MarketingContent` mein**. Naya page = array mein aik entry; koi da'wa badalna = aik line, aik jagah jahan ghair-developer ko bhi bheja ja sakta hai.
- ⚠️ Har bullet wo cheez naam leta hai jo **phases 1–11 mein waqai ban chuki** hai. Marketing page pe wo baat likhna jo software kar hi nahi sakta, sab se mehnga jhoot hai.

**2) Pricing wo hai jo plans hain (#108, #172)**
- Cards, qeematein aur feature ticks **sab `plans` table se**. Jo pricing page apne plans se alag maintain hoti hai wo aik din aisa number likh degi jo system charge karne se **inkar** kar dega — aur wo customer jisne pakra, sahi hai.
- **Sirf active + public plans.** Jo plan operator kisi negotiated deal ya legacy customer ke liye rakhta hai wo aik checkbox unticked karne se website se hat jata hai, aur bas yehi aik cheez usay yaad rakhni chahiye.
- Cycle toggle sirf un cycles ke liye jinki kisi plan ke paas asli qeemat hai. Jo cycle plan bechta hi nahi wo **"Not sold yearly"** likhta hai — us number se behtar jo kisi se liya hi nahi ja sakta.
- Aur agar kuch public nahi to page **saaf keh deta hai** ke abhi kuch shaya nahi hua — banaye hue numbers dikhane se behtar.

**3) Sign-up wohi rasta hai jo admin console ka hai (#109)**
- `RegistrationService` khud rows nahi likhti: `OrganizationProvisioner` + `SubscriptionService` se guzarti hai. Isi liye website se bani dukaan **kabhi** us dukaan se mukhtalif nahi ho sakti jo support ne banai — warna wo bug hafton baad kisi aik raste mein nikalta jo main branch banana bhool gaya.
- **Sab kuch ya kuch nahi.** Aadha bana account nakaam sign-up se **bura** hai: email ab le liya gaya aur banda dobara koshish bhi nahi kar sakta.
- Switch **do baar** check hota hai — form dikhane se pehle (courtesy) aur likhne se pehle (guard). Jo form load ke waqt khula tha wo submit ke waqt band ho sakta hai, aur bookmark ne kabhi ijazat maangi hi nahi.
- 🐞 Yahan aik asli bug pakra: entry plan **Free** hone par `startTrial()` chal rahi thi — aur trial ki **hamesha aik end date** hoti hai. Matlab muft plan expire hota, aur malik se aisi cheez renew karne ko kaha jata jo muft hai. Ab: **free plan assign hota hai, trial nahi**, aur marketing pages `RegistrationService::trialDays()` parhte hain taake aisa trial na bechein jo diya hi nahi jayega.

**🐞 Do aur bugs, dono purane sabaq:**
- **`days@endif`** — Blade ka directive regex `\B@` hai, to lafz se chipki hui directive **compile hoti hi nahi** aur file "unexpected end of file" pe mar jati hai. (Ye gotcha log mein pehle se likha tha aur **phir bhi** ho gaya.) Ab wo jumla PHP mein banta hai.
- **Public layout pe Alpine tha hi nahi.** Is project mein Alpine **Livewire ke andar** aata hai, to jis layout mein `@livewireScripts` nahi uspe har `x-data` chup-chaap kuch nahi karta — pricing toggle aur mobile menu dono murda the. Browser pe pakra: chaaron cycles ki qeematein aik saath dikh rahi thin.

**⚠️ Tests — 19 naye, sab PASS**
- `PublicSite/PublicSiteTest` (19): saare 8 pages render · anjaan slug 404 · **public site operator ki branding pehnti hai** · maintenance mein public site bhi band · pricing asli plans se · **private/inactive plan website tak pohanchta hi nahi** · na bikne wala cycle saaf kehta hai · khali catalogue jhoot nahi bolta · sign-up default band · plan na ho to band · logged-in banda doosri dukaan nahi bana sakta · **sign-up poori chalti hui dukaan banati hai** (branch + till + roles + subscription + sign-in) · paid plan pe trial · **free plan assign, trial nahi** · nakaam sign-up kuch nahi chhorta · form ke lazmi khane · **public form khud ko doosri business mein nahi ghusa sakta** · aik jaise naam ke do shops ke slug alag · nayi dukaan khali aur isolated.
- 🐞 Aik purana test toota aur **theek toota**: `/` ab login pe redirect nahi karta, wo **website hai**. Naye customer ko seedha login form pe phenkna us page ke liye ghalat hai jo zyadatar log sab se pehle dekhte hain.
- **Result: `php artisan test` → 663 tests / 2,621 assertions PASS**

**✅ Browser verification**
- Home (hero + 3 pillars + module cards + plans + FAQ + CTA), `/inventory` feature page, `/pricing` cycle toggle (Monthly $15/$39/$89 → Yearly $150/$390/$890), `/faq`, `/contact`.
- Mobile 375px: horizontal scroll nahi, hamburger menu.
- Sign-up demo install pe wapas **band** kar diya gaya (safe default).

➡️ **Next: Phase 13** — Animations + UI polish + performance (#71–75, #86–88, #92, #96–97, #120–124, #163–171, #199).



### 2026-09-01 — Phase 11 MUKAMMAL ✅ (Session 2 — SaaS operator ki settings)

Phase 11 ka doosra hissa: jo operator tay karta hai, dukaan nahi.

**✅ JO HO GAYA:**

**1) `platform_settings` — alag table, jaan boojh kar (#110)**
- `settings` per business hai aur poochta hai "ye **dukaan** kaise chalti hai?". Ye poore installation ka hai: "ye **installation** kaise chalta hai?" — naam kya hai, koi sign up kar sakta hai ya nahi, band hai ya nahi.
- Aik hi table mein nullable `business_id` ke saath rakhne ka matlab hota har tenant query mein `WHERE business_id = ? OR business_id IS NULL`, aur pehli jagah jo doosra hissa bhoolti wo ya to platform setting miss kar deti ya — kahin bura — **tenant ko wo likhne deti**.
- Wahi usool: sirf jo badla hai wo store hota hai, aur `apply()` config ke upar chadhti hai. Middleware `web` group mein **sab se pehle** chalti hai kyunke jin pages ko sab se zyada zaroorat hai unme koi logged in hi nahi — login screen brand naam parhti hai, maintenance page operator ka message.
- Table na mile to service **chup-chaap defaults pe** chalti hai: deployment naya code purani migration se pehle chalata hai, aur us khirki mein har page ka 500 hona defaults pe chalne se bura hai.

**2) Branding — white-label ka waada, sabit (#111)**
- 8 fields + logo upload. Uploaded logo drawn `<x-brand.mark>` ko replace karta hai; hata do to drawn mark wapas — wo **geometry** hai, image nahi, isi liye favicon, email aur chhape hue receipt sab jagah render hoti hai jahan file kabhi nahi pohanchti.
- Test wahi cheez sabit karta hai jo maayne rakhti hai: teeno naam badlo → **login screen** badal jati hai aur "KN Softic" kahin nahi bachta. Ye sirf isi liye mumkin hai ke kisi Blade file ne kabhi company ka naam **literal** nahi likha (yehi `config/brand.php` ka poora maqsad hai).

**3) Maintenance mode — `artisan down` NAHI (#160)**
- Laravel ka maintenance poori application band karta hai, `/admin` samet — matlab operator usi screen se bahar ho jata hai jo isay wapas chalu karti. Aur wo aik server ki file hai, to do web servers wala platform **aadha** down hota hai.
- Ye DB flag hai aur `/admin` jaan boojh kar khula rehta hai. Khula bhi: **logged-in super admin har jagah** (taake release check kar sake), **`?maintenance_token=…`** (session mein yaad rehta hai, warna har link pe query string dobara lagani parti), **`/up`** health check (planned window mein load balancer ka box nikal dena bilkul ghalat hai), aur **logout** (kisi ko aisi session mein qaid mat karo jo wo khatam na kar sake).
- **503, 200 nahi** — 200 wala maintenance page cache aur index ho jata hai aur kaam khatam hone ke baad bhi serve hota rehta hai.
- 🐞 **Sab se ahem bug isi mein mila:** Laravel apni **priority list** se stack sort karta hai, aur jo middleware us list mein nahi wo sab priority walon ke **peeche** dhakel di jati hai. Nateeja: `Authenticate` pehle chal rahi thi, to band platform pehle dukaandar se login maangta aur **phir** batata ke band hai. Saath hi maintenance `StartSession` se pehle chal rahi thi, to preview token yaad hi nahi rakha ja sakta tha. Ab dono priority list mein anchor hain: `… StartSession → ApplyPlatformSettings → EnforceMaintenanceMode → authentication → …`. Anchor **contract** (`AuthenticatesRequests`) hai, kyunke Laravel ki list wohi naam rakhti hai — concrete `Authenticate` pe anchor karna chup-chaap **kuch nahi** karta.

**4) Bell mein do alag cheezein, ulti tarah se chalti hain (#76, #77)**
- **ALERT aik HAALAT hai** — "6 shelves alert level se neeche", "subscription 4 din mein khatam". Live compute hoti hai aur dukaan ke theek karte hi **khud ghayab** ho jati hai. Isay **dismiss nahi kiya ja sakta**: jo alert sach hote hue chupayi ja sake wo jhoot hai, aur jo dukaandar seekh le ke badge bina kuch theek kiye chup karayi ja sakti hai wo **har** badge chup karana seekh leta hai.
- **ANNOUNCEMENT aik PAIGHAAM hai** operator ki taraf se. Wo jhoot nahi hota, isi liye parh lene wale ko **hatana** aana chahiye — warna bell un cheezon se bhar jati hai jo wo pehle hi nipta chuka aur wo usay kholna chhor deta.
- Isi liye **sirf announcements** ke paas dismissals table hai — aur wo **per shakhs** hai, per business nahi: malik ke parhne ka matlab cashier ne nahi parha.
- **Alerts ke liye koi table nahi, koi queue nahi, koi cache nahi.** Stored alert ko haalat shuru hote hi banana aur khatam hote hi **mitana** parta hai — aur mitana wo hissa hai jo kabhi koi theek nahi karta, to dukaanon ki bell pichle mahine ke hal-shuda masail se bhar jati hai. Per request 4 counting queries hameshaa sach hoti hain.
- Har alert **us screen ki permission** ke peeche hai jispe wo le jata hai: jo cashier inventory khol hi nahi sakta usay ye batana ke usme kya kam hai bekaar hai. Billing sirf malik ko.

**5) Dukaan ka apna payment QR (#57)**
- Upload, generate nahi: wallet kya encode karta hai ye **wallet** ka faisla hai — bank dukaan ko code deta hai aur dukaan wo dikhati hai. Khud banane ka matlab hota aik darjan mulki schemes ka andaza lagana aur zyadatar mein ghalat hona.
- Till pe **full-screen** button, kyunke customer counter ke us paar phone pakre khara hai aur thumbnail scan nahi hoti.

**⚠️ Tests — 21 naye, sab PASS**
- `Settings/PlatformSettingsTest` (21): default config se · save **login screen tak** pohanchti hai · default ke barabar row nahi rakhti · ghalat value refuse · anjaan key refuse · admin screen save + reset · **sirf admins** (dukaan ka malik nahi) · logo upload/remove · **maintenance dukaanein band karti hai magar operator andar rehta hai** · logged-in operator dukaan tak pohanch sakta hai · preview token sirf sahi wala · **health check chalu rehta hai** · **503 + Retry-After** · announcement har dukaan tak + per-shakhs dismiss · window se bahar wala nahi dikhta · non-dismissible refuse karta hai · publish + end · ulti dates refuse · **alert silence nahi ho sakta** · bell sirf wo batati hai jo dekhne ka haq ho · sehatmand dukaan ki bell khali.
- **Result: `php artisan test` → 644 tests / 2,533 assertions PASS**

**✅ Browser verification**
- Admin console: Settings (Branding/Sign-up/Maintenance) + Announcements — dono nav mein.
- Maintenance ON kar ke: `/app/dashboard` → **503 "Back shortly"**, `/admin/login` → 200, `?maintenance_token=letmein` → 200, `/up` → 200.
- Tenant bell: "2 batches expiring soon" (**koi Dismiss nahi**) aur "Scheduled maintenance this Sunday" (**Dismiss ke saath**).
- Settings → Payment methods pe Payment QR upload card.

➡️ **Next: Phase 12** — Public website + pricing + trial registration (#106–109, #172).



### 2026-09-01 — Phase 11 (Session 1) 🔄 — dukaan ki apni settings

Phase 11 ke do hisse hain: **dukaan ki settings** aur **SaaS operator ki settings**. Ye session pehla hissa hai.

**✅ JO HO GAYA:**

**1) Sab se bara faisla: settings config ke UPAR chadhti hain (#57, #190)**
- Har setting ki key **wohi config key hai** jise wo override karti hai. `pos.cash_rounding` ki setting `config('pos.cash_rounding')` ko badal deti hai.
- Tenant middleware tenant set hote hi `SettingsService::apply()` chalata hai — **us line ke baad** sale engine, till, receipt aur reports sab **is dukaan ka** jawab dekhte hain, aur unmein se kisi ko pata bhi nahi ke settings ki koi table hai.
- Doosra raasta — har jagah `setting()` helper — ka matlab hota har us file ko chhoona jo koi knob parhti hai, aur do tareeqe jinme se aik ghalat. Har us jagah aik bug hota jahan koi switch karna bhool gaya.
- **Test isay pin karta hai us tarah jo maayne rakhta hai:** cash rounding badlo → asli sale ka total badal jata hai. Jis setting pe koi amal na kare wo sajawat hai.

**2) Sirf jo BADLA hai wo store hota hai**
- Jo setting chhui hi nahi gayi uski koi row nahi; wo `config/` ke peeche chalti rehti hai. Isi liye behtar default baad mein bhi har us dukaan tak pohanchta hai jisne usay kabhi haath nahi lagaya.
- Default ke barabar value **row hi mita deti hai** — "wapas standard pe" asli cheez hai, dikhawa nahi.
- 🐞 Yahan aik zaroori bug pakra: "default" live config se parha ja raha tha — magar `apply()` config **pehle hi** dukaan ke jawab se bhar chuki hoti hai. Matlab dukaan kabhi standard pe wapas ja hi nahi sakti thi, aur usi process ka agla tenant wo value **wirasat** mein le leta. Ab boot pe **snapshot** liya jata hai, kisi bhi tenant se pehle.
- 🐞 Doosra: `forget()` row to hata deta tha magar overlay dobara nahi lagata tha — usi request mein aage koi knob parhne wala **mit chuki** setting pe amal karta.

**3) Settings aik dukaan se doosri mein LEAK nahi hotin**
- Config poore process ki hai, isi liye ye is phase ka sab se ahem test hai: dusri dukaan ko **shipped defaults** milne chahiye, aur wapis aane pe pehli ko apni value. `TenantContext::runFor` bhi ab overlay lagata aur bahaal karta hai.

**4) Waqt UTC mein store, local mein dikhta (#153, #154)**
- Har timestamp UTC. Business ka `timezone` sirf **dikhane** ke waqt lagta hai. Test: wahi instant UTC mein 06:30, Asia/Karachi mein 11:30, aur **stored row bilkul nahi hilti**.
- `Format` class + `money()` / `qty()` / `localDateTime()` helpers — currency, separators, decimals, date/time sab dukaan ke hisaab se (#155–157).

**5) Tax rates: table, magar product NUMBER rakhta hai (#59)**
- `tax_rates` wo **naam wali list** hai jisme se log chunte hain. Product aur sale line rate ko **number** ki tarah snapshot karte hain.
- Agar rate sirf table mein hota aur sale relation se parhta, to jis din VAT 17% se 18% hoti, **har purani invoice khud ko chup-chaap badal deti**. Test: rate delete karo — bechi hui line par 17% hi rehta hai.
- "Bilkul aik default" transaction hai, constraint nahi — constraint us lamhe ko mana kar deta jab purana default hat chuka aur naya laga nahi.

**6) Discount ki teen tehen (#60, #141)**
- Plan (`sales.discounts`) → **dukaan ki chhat** (`sales.max_discount_percent`) → **shakhs ka cap** (`users.max_discount_percent`).
- Jis shakhs ka apna cap nahi, wo ab **dukaan ki chhat** pe girta hai, "unlimited" pe nahi — warna chhat barhana har till ko be-hisaab ikhtiyar de deta, jo chhat ka ulta maqsad hai.

**7) Receipt ab dukaan ki apni (#57) + QR**
- Upar ki line, tax number, logo, tax breakdown, footer, auto-print — sab settings. Paisa aur tareekh `Format` se.
- **QR mein link nahi, khud invoice ke facts hain** (dukaan, invoice no, date, total, tax). Teen wajah: dukaan ka internet uska sab se kamzor hissa hai aur QR tab hi paranha jata hai jab jhagra ho raha ho; receipt deployment se zyada zinda rehti hai aur link mar jata hai; aur kai tax authorities khud details maangti hain, redirect nahi.
- SVG, PNG nahi: wahi template 203 dpi thermal aur 600 dpi laser dono pe chhapta hai; raster aik pe theek to doosri pe unscannable. QR banane mein kabhi exception nahi phenkta — sale ho chuki hai aur customer khara hai.

**⚠️ Tests — 24 naye, sab PASS**
- `Settings/SettingsTest` (24): default config se aata hai · save config overlay karti hai · default ke barabar value row nahi rakhti · group reset · **setting sale engine tak pohanchti hai** · **tenants ke darmiyan leak nahi** · `runFor` config wapas karti hai · ghalat value refuse aur kuch likha nahi jata · anjaan key refuse · plan se bahar setting na dikhti na post ho sakti · permission · business details + logo replace · anjaan timezone refuse · **timezone display badalta hai, storage nahi** · date format · tax rates + bilkul aik default · off hone pe default nahi reh sakta · 100% se upar refuse · duplicate naam · **rate delete karne se sale nahi badalti** · discount cap dukaan ki chhat pe girta hai · receipt settings + QR · receipt ka paisa dukaan ke format mein.
- 🐞 Aik purana test toota aur wo **theek toota**: `SalesBookTest` request se pehle `config()` poke karta tha. Ab middleware har request pe overlay lagata hai, to poked config mit jati hai — yehi to guarantee hai. Test ab asli setting likhta hai.
- **Result: `php artisan test` → 623 tests / 2,472 assertions PASS**

**✅ Browser verification**
- Settings ke 7 tabs; Business tab (timezone Asia/Karachi), Currency & formats (live preview), Sales tab, Tax rates (Standard 17% add hua).
- Receipt: "Fresh every day" header · "Tax No: NTN 1234567-8" · date **11:26 (Karachi)** jabke UTC 06:26 · **QR** + "Scan to check this receipt" · do line ka footer · total `Rs 800.00`.

**⬜ Phase 11 mein ab baqi (session 2):** SaaS branding (#111) · super-admin settings (#110) · notifications bell (#76) · announcements (#77) · maintenance mode (#160) · dukaan ka apna payment QR image.

➡️ **Next: Phase 11 session 2** — SaaS operator ki settings.



### 2026-09-01 — Phase 10 MUKAMMAL ✅ (30 reports + filters + export system)

Poora reports module. Sab se bara faisla shuru mein hi hua: **30 controllers nahi**.

**✅ JO HO GAYA:**

**1) Aik registry, aik service, aik screen (#54, #183)**
- `ReportRegistry` mein har report **khud ko declare** karti hai: naam, group, kaun sa **plan feature**, kaun si **permission**, aur uske liye kaun se **filters** maayne rakhte hain.
- 30 alag controllers likhne ka matlab hota: yeh chaar baatein 30 files mein bikhri hui, aur 31-wi report 29-wi se copy hoti — yehi wo raasta hai jis se "sales by branch" report chup-chaap branch filter **ignore** karne lagti hai.
- `ReportService::build()` har report ke liye **aik hi shakl** wapas karti hai: columns, rows, totals, meta. Isi wajah se aik screen, aik CSV writer, aik spreadsheet writer aur aik PDF template **teeso ko** serve karte hain — aur nayi report aik query hai, aik feature nahi.
- Test isay pin karta hai: **har declared report waqai build hoti hai**. Catalogue mein aisi entry jiske peeche query na ho, wo customer ke liye rakha hua 500 hai.

**2) Accuracy hi poora maqsad hai (#134)**
Teen usool aik jagah lagte hain, 30 dafa yaad nahi rakhne parte:
- **Sirf completed sales.** Held sale hui hi nahi, voided undo ho chuki — dono mein se koi bhi takings mein aa jaye to report us paise ki baat kar rahi hoti hai jo dukaan ke paas kabhi tha hi nahi.
- **Returns ghataye jate hain, chhupaye nahi.** Har sales/profit figure returns ka net hai, **aur** returns apna alag column rakhte hain — taake adjustment **nazar aaye**.
- **Tax revenue nahi.** Wahi usool jo P&L ka hai, warna "sales by product" ka jama P&L ki kamai se **zyada** nikalta.

**3) Rounding ke bare mein aik usool — aur aik iqrar**
- 🐞 Browser verification mein pakra: P&L keh raha tha COGS **935.95**, profit report **935.96**. Wajah: rounding **associative nahi** hai. 1043.4822 − 107.5258 aik dafa round karo to 935.96, pehle round karo to 935.95.
- **Usool:** paisa banane ke liye pehle round karo, **phir** jama/tafreeq karo — theek usi tarteeb se jaise `ProfitService` karti hai. Isi se statement aur report aik number dikhate hain, **aur** P&L screen pe khud jama karke check karo to sahi baithti hai.
- **Iqrar:** product/category wali **breakdown** har row pe round hoti hai, to uska column document total se aik-do paise hat sakta hai. Ye bug nahi aur isay row chhair kar "theek" nahi karna: har row apni jagah durust hai, aur aisa total jo apne hi upar wali rows se ikhtelaf kare wo is se bura hai. Test dono baatein pin karta hai — summary **bilkul** milti hai, breakdown **0.02 ke andar**.
- Saath hi per-line cost ab `ROUND(qty × cost, 4)` se jamta hai — theek waise jaise sale ne khud store kiya tha.

**4) Export system — chaar shakal, teen gate (#56)**
- **CSV har plan ke saath**, kyunke jo report system se bahar na aa sake usay check bhi nahi kiya ja sakta. **BOM** ke saath, warna Excel har non-ASCII naam ko mojibake bana deta hai aur ilzaam export pe aata hai. Numbers **bina comma** — "1,200.00" wala cell spreadsheet mein jama nahi hota.
- **.xlsx khud likha** (~150 lines, ZipArchive se). Asli spreadsheet aik ZIP hai jis mein chand XML parts hain; library laane ka matlab tha kai MB aur aik build step, sirf aik file format ke liye. Is codebase ki adat wahi hai — **choti cheez khud likho** (dekho `Ean13`). Numbers asli **numbers** jate hain, sortable aur summable.
- **PDF** — `barryvdh/laravel-dompdf` (is phase mein add hui **waahid** dependency). Template jaan boojh kar plain HTML + inline CSS hai: dompdf app ki Tailwind stylesheet nahi parh sakta, "shared" stylesheet wahan **bilkul unstyled** column ban jata. 5 se zyada columns ho to **landscape**, warna kata hua total milta — jo bilkul export na hone se bura hai.
- **Print** ko kuch nahi chahiye, wo bas page hai.

**5) Gates har report pe, route pe nahi (#187)**
- Route sirf wo maangta hai jo **sab se kamzor** report ko chahiye. Har report apna feature aur apni permission le kar chalti hai aur dono **usi report ke liye** controller mein check hote hain — warna route-level gate ko 30 mein se sab se sakht hona parta aur kisi ko kuch nazar na aata.
- Catalogue sirf wo dikhata hai jo is plan aur is role ke liye **khul** sakta hai. Grey rows advert hoti hain, menu nahi — upgrade billing page pe bikta hai.
- **Export apni alag permission** hai: file banane wale ke saath jati hai aur uske account se zyada zinda rehti hai.
- 🐞 Yahan bhi aik bug nikla: `reports.export` permission `reports.export_pdf` **feature** se bandhi hui thi. Matlab jis dukaan ke plan mein PDF nahi, uska **CSV bhi band** — malik ke liye bhi, kyunke malik roles se upar hai magar **subscription se nahi**. Ab wo `reports.basic` se bandhi hai: sawal "ye shakhs figures bahar le ja sakta hai?" ka file format se koi taalluq nahi.

**⚠️ Tests — 29 naye, sab PASS**
- `Reporting/ReportTest` (29): **har declared report build hoti hai** · anjaan report 404 · held/voided kisi takings figure mein nahi · returns ghatte hain **aur dikhte** hain · **reports aur P&L paise tak milte hain** · tax revenue nahi · sirf restocked return cost wapas karta hai · khali din rehte hain · month grouping · **split payment dono methods mein** · stock reports shelf abhi ka parhti hain · ledger ko customer chahiye · ledger closing balance jama nahi hota · branch filter narrow karta hai aur ghair-mutalliqa branch **refuse** · **jo filter report ne maanga hi nahi wo ignore** · ulti date range · CSV (BOM + title + bina format numbers) · **.xlsx asli ZIP hai** · PDF asli PDF · paid formats plan ke saath, CSV nahi · anjaan format 404 · catalogue sirf khulne wali reports · role se bahar report URL se bhi refuse · plan se bahar report billing pe · export apni permission · screen render · cross-tenant · expense reports P&L se milti hain · expense reports ko `expenses.view` bhi chahiye.
- **Result: `php artisan test` → 599 tests / 2,395 assertions PASS**

**✅ Browser verification**
- Catalogue: 6 groups, 30 cards, P&L statement ka button upar.
- `sales.summary`: chart + **khali din bhi rows** + totals (3 sales · 1,590 takings · 400 returns · **1,190 net**) — P&L se bilkul milta hua.
- `profit.by_product`: 1,190 revenue · 935.96 cost · 254.04 gross · 21.3% margin.
- `inventory.valuation`: **koi date range nahi** ("As at ..."), total 45,040.99.
- 🐞 Yahan aik aur pakra: teen "Cotton T-Shirt" rows bilkul aik jaisi dikh rahi thin (variants). Ab row ka naam variant ke saath — "Cotton T-Shirt — L / White". Jis row pe reorder karna hai wo pehchani jani chahiye.
- Mobile 375px: horizontal scroll nahi.

➡️ **Next: Phase 11** — Settings + Receipt + QR + Barcode (#57–60, #76–77, #110–111, #154–160).



### 2026-09-01 — Phase 9 MUKAMMAL ✅ (expenses + other income + Profit & Loss)

Ab system bata sakta hai ke dukaan **kama** kitna rahi hai, sirf bech kitna nahi rahi.

**✅ JO HO GAYA:**

**1) Sab se bara faisla: STOCK KHARCHA NAHI HAI (#43)**
- Bikri ke liye khareeda gaya maal **purchase** hai — uski lagat profit tak **COGS** ke zariye pohanchti hai jis din maal **bikta** hai, us din nahi jis din delivery aayi.
- Kiraya, tankhwah, bijli, marammat — ye **kharchay** hain: jis mahine ke hain usi mahine lagte hain aur inventory ko chhutay bhi nahi.
- Agar stock ko yahan book kar diya jata to **wohi paisa do baar** ginta — aik dafa ab kharche mein, aik dafa bechne pe COGS mein — aur har us mahine P&L ghata dikhati jis mein dukaan ne maal bhara. `ExpenseService` kabhi `stocks` ya `stock_movements` ko haath nahi lagati, jaan boojh kar.

**2) Categories dukaan ki apni hain (#43, #190)**
- Na enum, na config list. Pharmacy "dispensary licence" likhti hai, restaurant "gas cylinders" — dono aik doosre ki list mein fit nahi hote, aur na hi kisi ko is ke liye deployment chahiye.
- Phir bhi naya tenant **5 default headings** ke saath shuru hota hai (Rent, Utilities, Salaries, Transport, Repairs) — pehle expense form pe khali dropdown aik **dead end** hai.
- Jis category ke neeche figures pare hain wo **archive** hoti hai, delete nahi (#104): pichli quarter ki P&L un rows ko parhti hai aur usay heading chahiye.

**3) Cash daraz ko sach batati hai (#46)**
- Khirki saaf karne wale ko till se paisa dena **waqai** daraz khali karta hai. Agar ye khuli session tak na pohanchta to har cash-up shortfall dikhata aur wo **aik** signal jo asal mein maayne rakhta hai — "kya till waqai kam hai?" — shor mein dab jata.
- Cash expense `cash_out` barhata hai, cash income `cash_in` — dono `CashSessionService` ke zariye, taake likhne ka tareeqa aik hi jagah ho.
- **Edit farq se chalta hai, nayi raqam se nahi:** 500 → 400 karne pe till **100 behtar** honi chahiye, 400 buri nahi. (Wahi soch jo stock take ki hai — difference post hota hai, count nahi.)
- **Band session tareekh hai:** uska `difference` close pe stamp ho chuka; do din baad ki gayi edit usay **dobara nahi likhti**.

**4) Profit ka matlab aik hi jagah tay hota hai (#45, #183)**
```
Revenue − COGS                        = GROSS PROFIT
GROSS PROFIT − Expenses + Other income = NET PROFIT
```
- **Tax revenue nahi hai.** Sales tax kisi aur ki taraf se wasool hota hai aur baad mein jama karana hota hai. Usay aamdani ginna revenue aur margin dono ko tax rate ke barabar phula deta — aur margin wohi figure hai jis se malik qeemat lagata hai. Test: 1,100 counter pe liye, revenue **1,000**, margin 60% (66% nahi).
- **Cost wohi jo BECHTE waqt thi (#52, #135).** ProfitService aaj ke shelf average se cost dobara **kabhi** nahi nikalti — warna pichle mahine ka margin har nayi delivery pe badal jata aur band mahina band rehna chhor deta.
- **Sirf restock hone wala return COGS reverse karta hai.** Jo maal write-off hua wo shelf pe wapas aaya hi nahi: dukaan ne uski qeemat di aur ab wo uske paas nahi. Poora `cost_total` reverse karna business ko wo inventory credit kar deta jo uski hai hi nahi — aur tootne ka nuqsan hisaab se **ghayab** ho jata.
- **Other income gross profit ke NEECHE.** Scrap, sublet, rebate — koi maal shelf se nahi gaya, to inhe revenue mein daalna gross profit phula kar margin barbaad kar deta. Test pin karta hai: income add karne se gross profit aur margin **bilkul nahi** hiltay, sirf bottom line hilti hai.
- Sab kuch **aggregate queries** (#183) — saal bhar ki P&L paanch column jorne ke liye saal bhar ki sales memory mein nahi uthati.

**🐞 Aik purana bug pakra gaya (Phase 8 se):**
- `SaleReturn::profitReversed()` **poora** `cost_total` reverse kar raha tha. Poori tarah write-off hue return pe ye kehta tha "profit −78.71 reverse hui", jabke haqeeqat: dukaan ne 240 wapas diye aur badle mein **kuch nahi** mila — profit **−240** giri. Ab `restockedCost()` / `writtenOffCost()` alag hain aur return page dono dikhata hai. Browser proof: RET-000001 ab "Cost recovered 0.00 · Cost written off 161.29 · **Profit reversed −240.00**".

**⚠️ Tests — 41 naye, sab PASS**
- `Accounting/ExpenseTest` (24): reference numbering · branch hamesha · zero refuse · cash till se nikalta hai · bank transfer nahi · **edit farq se** · delete paisa wapas · **band session nahi badalta** · cash income andar · source lazmi · in-use category delete nahi · rename se expenses refile nahi hote · receipt store/replace/remove · **refused expense disk pe orphan nahi chhorta** · delete ke saath receipt bhi jata hai · form + future date + ghalat file type · duplicate category name · income form · view vs manage permission · feature gate · cross-tenant.
- `Accounting/ProfitLossTest` (17): poora statement jorta hai · **tax revenue nahi** · held/voided revenue nahi · cost snapshot hai · restocked return dono reverse karta hai · **write-off sirf revenue reverse karta hai** · return **wapas aane ke din** lagta hai · expenses category-wise + share · **other income margin nahi phulata** · ghata aik number hai · **branch statements jama ho kar business bantay hain** · daily rows statement ke barabar · ulti date range · screen · permission · feature gate · cross-tenant.
- **Result: `php artisan test` → 570 tests / 1,880 assertions PASS**

**✅ Browser verification (asli flow)**
- Categories screen: "Rent" aur "Utilities" banaye (in-use category pe lock icon, delete nahi).
- Expense form: EXP-000001, 25,000 kiraya "Shop landlord" ko → list mein cards (Spent / Entries / Average a day) update.
- Income form: INC-000001, 1,800 "Scrap cartons sold".
- **P&L:** Revenue 1,590 − returns 400 = **1,190** · COGS 1,043.48 − 107.53 restocked = **935.95** (161.28 written off alag dikh raha) · **Gross 254.05 (21.4%)** · Expenses 25,000 · Other income +1,800 · **Net −22,945.95** — ghata saaf lafzon mein, chhupaya nahi gaya. Neeche costing method likha hua.
- "Where the money went" bars + day-by-day chart (ApexCharts lazy chunk se).
- Mobile 375px: horizontal scroll nahi.

**🐞 Do UI bugs isi session mein theek:**
- `<x-layouts.app title="Profit &amp; Loss">` — component attribute mein HTML entity **literal string** rehti hai aur `{{ }}` usay dobara escape karta hai, to tab pe "Profit &amp;amp; Loss" likha aa raha tha. Bare `&` chahiye. (Ye wahi gotcha hai jo Phase 2 mein billing page pe mila tha — dobara ho gaya.)
- Chart ke x-axis labels aik doosre ke upar chhap rahe the (32 din, 8 ticks, rotate 0). Ab `rotate: -45` + `hideOverlappingLabels`.

➡️ **Next: Phase 10** — Reports (#54–56, #134, #183).



### 2026-09-01 — Phase 8 MUKAMMAL ✅ (sale returns)

Maal wapas aane wala poora rasta. Stock adjustments (#31) aur transfers (#32) Phase 4 mein ban chuke the, to is phase ka asal kaam **sale returns** tha.

**✅ JO HO GAYA:**

**1) Sab se bara faisla: `restock` PER LINE hai (#53)**
- Jo customer band dabba wapas laata hai aur jo tuta hua — **dono ka paisa banta hai**, magar shelf pe sirf aik wapas jata hai.
- Agar sab kuch default pe restock hota, to har tuti hui cheez chup-chaap stock **barha** deti, aur dukaandar ko pata agli ginti pe chalta — us waqt tak wajah dhoondna namumkin.
- Isi liye har line pe **“Fit to sell”** checkbox aur uske neeche **condition note** (“What is wrong with it?”). Write-off wali line pe `sale_return` movement post hi **nahi** hoti.
- Browser proof: RET-000001 (3 units, “Seals broken”) → stock **bilkul nahi hila**, 240 account pe credit. RET-000002 (2 units, fit to sell) → shelf **85 → 87**, 160 cash refund.

**2) Return sale ko kabhi dobara nahi likhta**
- Return apna **alag document** hai (`sale_returns` + `sale_return_items`), sale ka edit nahi. Sale ka total wahi rehta hai jo us din liya gaya tha — kyunke us din wahi liya gaya tha.
- Limit **likhne se pehle** check hoti hai: `returnableQuantity()` = becha gaya − pehle se wapas aaya. Aik line bhi zyada ho to **poora return ruk jata hai** (aadha apply nahi hota) — test isay pin karta hai.

**3) Return aur void aik saath nahi ho sakte**
- `Sale::canBeVoided()` ab `hasReturns()` bhi dekhta hai. Dono karne se maal **do baar** shelf pe jata aur paisa **do baar** wapas.
- Sale screen pe: return hone ke baad **Void button gayab**, “Return goods” tab tak rehta hai jab tak kuch returnable bacha ho.

**4) Paisa kahan jaye — default wo jo kam se kam hairat wala ho**
- **Walk-in** ka koi account nahi, to poora **haath mein** wapas. **Account customer** ka **credit** default — jo pehle se udhaar de raha ho usay cash pakrana masla hai, ledger se kaatna dono ke haq mein.
- Sirf aik figure diya to **baqi doosri taraf** chala jata hai (customer kabhi kam nahi rehta). Dono diye to **jama** total ke barabar hona chahiye, warna 422.
- **Walk-in ko credit nahi kiya ja sakta** — ye wahi na-mumkin combination hai jise service mana karti hai (guess nahi karti).
- **Cash refund** khuli session ka `cash_refunds` barhata hai (#46) — daraz halki hui hai, cash-up ko pata hona chahiye. Card refund daraz ko **nahi** chhuta.

**5) Profit us cost pe reverse hoti hai jo BECHTE waqt thi (#52)**
- `unit_cost` sale ke apne snapshot se aata hai, aaj ke shelf average se nahi. Test: 40 pe becha → baad mein 90 wali delivery aayi → return phir bhi **80** cost reverse karta hai, 180 nahi.
- `cost_total` return pe stamp hota hai; `profitReversed()` sirf `reports.view_profit` ke peeche dikhta hai.

**6) Refund apni alag permission hai (#140)**
- `sales.return` — bechna aur **paisa wapas dena** aik ikhtiyar nahi. Bohat si dukaanon mein har koi bech sakta hai magar refund sirf supervisor.
- Permission na ho to **wahin refuse** (redirect back), billing pe **nahi** bheja jata — ye plan ka masla nahi, manager ka faisla hai. Plan mein feature na ho to **billing** pe bheja jata hai. Dono raste alag hain, aur test dono ko pin karta hai.

**🐞 Do bugs jo is session mein pakre gaye:**
- `SaleReturnItem::net()` tax **dobara** laga raha tha — return ka `unit_price` pehle hi all-in hai (discount phaila hua, tax andar). Theek kiya; rate ab sirf record ke liye stored hai.
- Test likhte waqt: doosri business `TenantContext` khuli hui banai to `BelongsToTenant` ne uske owner pe **pehli** business ki stamp laga di — cross-tenant test ghalat wajah se pass ho raha tha. `TenantContext::forget()` pehle, phir doosri dukaan. (Ye product bug nahi, tenant stamp ka **maqsad** hi yehi hai.)

**⚠️ Tests — 27 naye, sab PASS**
- `Sales/SaleReturnTest` (27): goods shelf pe wapas + paisa wapas · sale kabhi dobara nahi likhi jati · becha hua se zyada wapas nahi · returns line pe **jama** hote hain · aik line ghalat ho to poora return ruke · damaged **refund hota hai magar restock nahi** · aik return mein kuch lines restock kuch write-off · walk-in refund vs account credit · walk-in ko credit nahi · refund+credit jama hona chahiye · aik figure diya to baqi doosri taraf · discounted line ka **discounted** hissa wapas · cash refund daraz se · card refund daraz ko nahi chhuta · profit **sale-time cost** pe reverse · returned sale void nahi hoti · sirf completed sale pe return · reason lazmi · form se post + show page · unticked line write-off · returns book ke totals · invoice se search · **permission** · **feature gate** · cross-tenant 404 · customer statement.
- **Result: `php artisan test` → 529 tests / 1,713 assertions PASS**

**✅ Browser verification (asli flow)**
- Sale screen → **“Return goods”** → form: live total, “Already back” column, untick pe condition note.
- RET-000001: 3 units write-off → *“240.00 credited to the account”*, show page pe **Written off** badge + note, profit reversed **−78.71**.
- RET-000002: 2 units restock + 160 cash refund → ledger mein `sale_return | +2 | bal=87`.
- Returns book: 4 cards (Returns / Value returned / Handed back / Credited) + **“3 written off”** badge. Sidebar mein **Returns** link (feature + permission dono ke peeche).
- Mobile 375px: body pe horizontal scroll **nahi** — table apne container mein scroll karta hai.

**✅ #31 aur #32 verify hue (Phase 4 ka kaam):** `InventoryService::adjust()` reason + audit log ke saath, aur transfers ka `send → receive(counted)` shortfall ke saath. Dono checkboxes ab tick.

➡️ **Next: Phase 9** — Expenses + Profit & Loss (#43–45, #135, #183).


### 2026-08-28 — Audit + KN Softic branding + stock transfers 🔄 (Phase 4 ~90%)

Poore project ka audit hua, **KN Softic ki professional branding** lagi, aik purana bug pakra gaya, aur **stock transfers (#32)** mukammal ho gaye.

**🔍 AUDIT (kya mila):**
- 235 PHP files · 14,282 lines (app/) · 33 migrations · 134 routes · **322 tests pass**
- Phases 0–3 mukammal, Phase 4 ~75% (ab ~90%)
- ⚠️ **Bug: tenant dashboard Phase 2 ke baad update hi nahi hua tha** — Products aur Low Stock cards abhi tak `—` placeholder dikha rahe the, jabke 4 products aur 5 stocked shelves live the. Quick actions bhi sab disabled the.
- ⚠️ Koi branding nahi thi — har jagah `config('app.name')` se "POS SaaS" aa raha tha.
- ✅ Responsive theek nikla: mobile (375px) pe dashboard/products/inventory/billing — **kisi pe horizontal overflow nahi** (tables apne `overflow-x-auto` container mein scroll karti hain, page nahi).

**🎨 1) KN SOFTIC BRANDING**

- **`config/brand.php`** — company name, product name, tagline, website, support email, version, copyright year: sab aik jagah, env-driven (#190). **Rebrand ab search-and-replace nahi, config change hai.**
- **`<x-brand.mark>`** — logo SVG **geometry se bana hai, text se nahi**: favicon, email, printed receipt aur us page pe bhi ek jaisa render hona chahiye jahan font CDN load hi na ho. Har instance ko **unique gradient id** milti hai — warna aik hi page pe do marks `<defs>` id share karte aur doosra pehle ki fill le leta (ye asal mein hota hai; test kiya, 3 marks aik page pe, teeno unique).
- `<x-brand.logo>` (mark + wordmark), `<x-brand.footer>` (copyright range **khud barhta hai**: "© 2026" → "© 2026–2031"), `<x-brand.powered-by>`, aur `public/favicon.svg`.
- **⚠️ Sab se ahem faisla — kis ka naam kahan:**
  - `/login`, `/admin`, emails → **KN SOFTIC**. Ye product ki apni surfaces hain.
  - `/app` (tenant ka workspace) → **BUSINESS ka naam**. Dukaandar ka staff apni dukaan mein kaam karta hai, apne supplier ke product mein nahi. KN Softic wahan sirf footer ki aik halki si "Powered by" line hai.
  - Ye ulta karna classic white-label ghalti hai — har customer ko lagta hai wo kisi aur ke product ke andar baitha hai.
- Applied: guest layout (login/forgot/reset — logo + tagline + footer), admin sidebar + topbar badge + footer, tenant sidebar + footer, `APP_NAME`, favicon, aur `.env` + `.env.example` mein poora `BRAND_*` block.

**🐞 2) DASHBOARD BUG FIX (asli pending functionality)**
- Products aur Low Stock cards ab **asli figures** dikhate hain, aur apne module ke **link** hain (number tabhi kaam ka hai jab us pe action ho sake).
- Dono **permission-gated**: `null` ka matlab "aapke role ko nahi dikhana" hai aur wo dash render hota hai — **gumraah karne wala zero nahi**.
- Quick actions ab tabhi live hain jab module **aur** us user ki permission dono mojood hon; warna disabled + wajah hover pe. Jo button live lagta ho aur phir refuse kar de, wo us se bura hai jo kabhi live laga hi nahi.
- Banner ka purana text ("POS, products aur reports agle phases mein…") update hua.

**📦 3) STOCK TRANSFERS — #32 mukammal**

- **2 tables:** `stock_transfers` (reference, from/to branch, status, aur **teen alag timestamps + teen alag log**: drafted / sent / received) + `stock_transfer_items` (**do quantities**: `quantity_sent` aur `quantity_received`).
- **`TransferStatus` enum:** Draft → Sent (in transit) → Received, ya Cancelled.
- **⚠️ Ye teen steps kyun, aik "move" button kyun nahi:** transfer aik **safar** hai. Maal aik shelf se nikal jata hai us se pehle ke doosri pe pohanche, aur us dauran wo **kisi shelf pe nahi hota**. Aik hi movement post karne se van invisible ho jati, aur jab **11 nikle aur 10 pohanche**, to gyarhwan record karne ki koi jagah hi na hoti.
- **Shortfall reconcile NAHI hota.** Ledger seedha dikhata hai: maal nikla aur kabhi pohancha nahi. Transfer apne chehre pe farq likhe rakhta hai (loud rose banner). Jo system numbers ko chup-chaap barabar kar deta hai wo wahi ek signal chhupa raha hota hai jo batata hai ke raste mein kuch ghalat hua.
- **Cost maal ke saath safar karti hai** — receiving branch ko wahi cost milti hai jo asal mein lagi thi (browser test: 55 pe khareeda, catalogue 40, destination pe average = 55).
- **Cancel:** draft cancel karna muft hai; **sent** cancel karne pe maal **wapas source shelf pe** post hota hai (movement ke zariye, taake round trip ledger mein nazar aaye, mit na jaye). Aur **sirf sending branch** hi transit wala transfer cancel kar sakti hai — kyunke maal wahin wapas jana hai.
- **Branch rules (#48):** bhejna source branch ka kaam, receive karna destination ka. List **dono taraf** se dikhti hai (`scopeVisibleTo`) — manager ko apni branch pe **aane wala** maal bhi dikhna chahiye, sirf bheja hua nahi.
- UI: index (status filter + "in transit" count), draft form (Alpine rows), aur show page — send / receive-with-count / cancel sab wahin se.

**⚠️ Tests — 22 naye, sab PASS**
- `Inventory/StockTransferTest` (22): draft kuch move nahi karta · send source se nikalta hai aur **kahin nahi rakhta** · receive destination pe rakhta hai · **cost safar karti hai** · shortfall record hota hai · 0 arrive pe koi movement nahi · stock se zyada nahi bhej sakte (poora rollback) · aik hi product do baar nahi · service transfer nahi ho sakti · sent edit nahi hota · received cancel nahi hota · feature off · draft/sent cancel ka farq · teen branch-access rules · poora HTTP flow · permission · cross-tenant.
- **Result: `php artisan test` → 322 tests / 1,016 assertions PASS**

**✅ Browser verification (asli flow, end-to-end)**
- Login page pe KN Softic logo + tagline + footer; admin console pe branded sidebar; tenant sidebar pe business ka naam + "Powered by KN Softic".
- Transfer: draft (TRF-000001, 11 units) → **send** → *"is on its way"*, Main Branch 30 → 19 → **receive 10 of 11** → *"Received with a shortfall of 1 — the difference left the source and never arrived."*
- Ledger confirm: `transfer_out | Main Branch | −11 | bal=19` aur `transfer_in | Depot | +10 | bal=10`. **Gum shuda 1 kisi shelf pe nahi — yehi sach hai.**
- 🐞 Verification ke dauran khud ko logout kar liya: `querySelector('form')` ne page ka **pehla** form uthaya (topbar ka logout form), transfer form nahi. Sabaq: form hamesha `action` se target karo.

**⬜ Phase 4 mein ab sirf ye baqi:** batch + expiry tracking (#34) · barcode label printing (#27) · product image upload (#149) · CSV import/export (#150/#151).

➡️ **Next:** Phase 4 close (expiry + barcode + import/export), phir **Phase 5** — Customers + Suppliers + ledgers.


### 2026-09-01 — Phase 7 MUKAMMAL ✅ (sales book + receipts + reprint)

Phase 7 ke aakhri teen items. **POS + Sales + Payments + Customer Ledger — poora phase close.**

**✅ JO HO GAYA:**

**1) Sales book (#21) — aur "kis ki sales?" ka jawab**
- `sales.view` bande ko **apni** sales dikhata hai; `sales.view_all` **sab ki**. Do permissions rakhne ka yehi maqsad hai: cashier ko wo receipt dhoondni hoti hai jo usne paanch minute pehle chhapi thi, usay poore hafte ka hisab dekhne ki zaroorat nahi.
- Ye narrowing **query mein** hoti hai, view mein rows chhupa kar nahi — chhupi hui row bhi wo row hai jo fetch, paginate aur count ho chuki. Aur URL guess karne pe **404** milta hai (403 nahi: kisi aur ka invoice number uska masla hi nahi).
- **Held sales book mein kabhi nahi aatin** — abhi kuch hua hi nahi, aur unhein asli sales ke saath ginna din ki takings ko barha kar dikhata.
- Headline figures **usi filtered query** se ginte hain jo table dikhata hai, to cards aur table kabhi ikhtilaf nahi kar sakte.

**2) Receipt (#23) — teenon widths ke liye AIK template**
- 58mm aur 80mm thermal rolls hain; un mein farq **jagah** ka hai, matn ka nahi. A4 wo hai jo lifafe mein jata hai. Aik hi template share karne ka matlab: receipt **kya kehti hai** us mein tabdeeli aik width pe aa kar doosri se chup-chaap chhoot nahi sakti.
- App layout se **bahar**, barcode sheet ki tarah: print job ko sidebar, topbar aur dark mode nahi chahiye. Roll pe monospace, dashed rules — jo thermal printer asal mein kaghaz pe daalta hai.
- **Rounding alag line pe** dikhti hai, total ke andar dabai nahi jati — warna kaghaz pe receipt jama nahi hoti.
- Split payment har method apni line pe (card ka AUTH reference sath), change, aur "On account" alag.

**3) Reprint ginti jati hai (#143)**
- Pehli print **reprint nahi** hai, to woh nahi ginti. Uske baad har copy pe likha aata hai **"REPRINT · copy N"**.
- Wajah: aik hi jaisi do invoices gardish mein hona theek wohi cheez hai jis se #143 bachata hai — aur *"hamein nahi pata kitni copies hain"* koi jawab nahi.
- Har reprint audit log mein bhi jata hai.

**4) Print UX (#145)** — sale mukammal hone ke baad wahi teen cheezein jo chahiye hoti hain: **Print · New sale · View sale**. Receipt **apne tab** mein khulti hai to till kabhi navigate away nahi hoti — agla customer pehle se khara hai. Auto-print **opt-in** hai: jo dukaan sirf maangne pe chhapti hai us pe har sale ke baad print dialog phenkna theek nahi.

**5) Custom footer (#144)** — `config/pos.php` se, aur Phase 11 mein per-business settings mein chala jayega (baqi sab ki tarah).

**6) Void screen se (#198)** — reason lazmi, permission alag (`sales.void`), aur wo sab hota hai jo engine mein tay tha: stock wapas shelf pe, credit clear, **record baqi** — kyunke kisi ke paas kaghaz wali copy hai.

**7) Profit permission hai (#52)** — list pe bhi aur sale pe bhi. Cashier ko sale poori nazar aati hai, margin nahi.

**8) ⚠️ Tests — 15 naye, sab PASS**
`Sales/SalesBookTest`: book totals, **held sales ghair-hazir**, cashier sirf apni dekhta hai (aur URL se bhi nahi khol sakta), view_all poori dukaan, date filter, **profit permission dono screens pe**, teenon receipt widths, ghalat width pe fallback, **custom footer**, **reprint ginti + "copy 2" marker + audit**, voided receipt pe VOIDED, void ki permission aur reason, plan gate, cross-tenant 404, credit sale pe "On account".

**Result: `php artisan test` → 502 tests / 1,607 assertions PASS**.

**9) Browser verification** — demo mein teen sales banai (cash with change, **card+cash split**, aur credit): sales book pe Takings **1,590.00**, On account **500.00**, har row pe profit + margin%. 80mm receipt pe split payment dono lines (card ka AUTH-77120 reference ke sath), 58mm pe credit sale ka **"On account 500.00"** aur **"REPRINT · copy 2"**. Zero console errors.

**🐞 Rukawat:** beech mein MySQL band ho gaya tha (`mysql_error.log` ne "Server socket created" ke baad kuch nahi likha) — dobara start kar ke tests chalaye.

➡️ **Next: Phase 8** — Returns + Stock Adjustments + Transfers (stock adjustments aur transfers Phase 4 mein ban chuke; ab **sale returns** asal kaam hai).


### 2026-08-29 — Phase 7 (Session 2): POS screen 🔄 (~75%)

Till ban gayi. Engine pehle se tayyar tha; ye session uske upar wo screen banane ka hai jis pe dukaan ka poora din guzarta hai.

**✅ JO HO GAYA:**

**1) Sab se bara faisla: cart browser mein rehta hai (#122, #90)**
- Har cart action — quantity ka nudge, line discount, kuch hatana — **foran** hota hai, kyunke koi bhi server ko nahi chhuta. Server se sirf **do** cheezein poochi jati hain: *"is se kya match karta hai?"* aur *"ye rahi mukammal sale"*.
- Wajah: dukaan ka internet aksar uske poore setup ka sab se kamzor hissa hota hai, aur aisi POS jise basket mein pani ki bottle daalne ke liye round trip chahiye, us par **na-qabil-e-istemaal** hoti hai.
- ⚠️ Iska matlab ye **nahi** ke browser pe bharosa hai: prices, stock, credit limit aur totals sab `SaleService` un IDs se **dobara** nikalta hai jo browser bhejta hai. Test isay pin karta hai — 500 wali cheez ko 5 kehne wali cart ko server sahi cost pe laga deta hai, aur profit **manfi** dikhta hai (jaisa dikhna chahiye).

**2) Search: server-side, debounced — poora catalogue preload nahi**
- Business plan wale tenant ke paas hazaaron products ho sakte hain; har till pe har page load pe sab bhejna aik dafa ki sustii ko **hamesha ki sustii** se badalna hota.
- Iske bajaye **favourites preload** hoti hain (#147), to screen khulte hi grid bhara hua hota hai.

**3) Barcode (#15)** — search box mein Enter ka matlab do mein se aik hai: scanner ne abhi barcode type kiya, ya insaan ne search likh kar pehla result maanga hai. **Pehle barcode try hota hai** (exact code mubham nahi hota), warna top match. Variant ka apna barcode ho to **wahi variant** select hota hai — scanner pehle hi bata chuka, dobara poochna peeche hatna hai.

**4) Double-submit ka asli ilaj (#91)**
- Button disable karna **shaistagi** hai, hifazat nahi: wo kharab connection, be-sabri wale double-tap, ya timeout ke baad retry se nahi bachata.
- Till har cart ke liye aik **idempotency key** banati hai, aur `sales` pe unique index hai. Wahi cart chahe jitni dafa aaye, **aik hi sale** banti hai. Test: do dafa bheji hui cart → aik sale, aur stock bhi aik hi dafa kam hua.

**5) Discount cap wahan check hota hai jahan cashier badal nahi sakta (#141)** — cap **shakhs** ka hai, sale ka nahi: wahi basket manager approve kare to jaiz hai. Is liye check `PosCheckoutRequest` mein hai, `SaleService` mein nahi.

**6) Baqi screen**
- **Layout (#14):** bayen categories (parent pe uske children ke products bhi #148), beech mein search + grid, dayen cart.
- **Customer (#16, #146):** picker + **aik field wala quick-add** — counter pe khare shakhs se tax number nahi poocha jata. Credit available bhi sath dikhta hai.
- **Hold/resume (#20):** parked carts bayen taraf; resume karne pe cart wapas bhar jati hai aur hold khatam. Held sale **invoice number kharch nahi karti**.
- **Shortcuts (#89):** F2 search · F4 customer · F6 hold · F8 pay · F9 discount · Esc clear. Function keys is liye ke till pe haath counter pe hote hain, aur ye product ka naam type karne se takraate nahi.
- **Payment (#17, #19):** split across methods, aur **quick-cash buttons** (150 / 200 / 500 / 1,000) — wo jo customer asal mein hath mein deta hai.
- **Till (#139):** agar shop har sale pe cash session maangti hai to POS pehle drawer kholne ko kehta hai.

**🐞 Do cheezein jo verification ne pakrin:**
1. **`products.is_favourite` ka boolean cast reh gaya tha** — JSON `1` bhej raha tha `true` ke bajaye.
2. **Cart lines narrow width pe overflow** kar rahi thin (horizontal scrollbar, discount field kat jata). Ab **wrap** karti hain — jo line kinare se bahar khisak jaye usay cashier theek nahi kar sakta.

**7) ⚠️ Tests — 19 naye, sab PASS**
`Sales/PosScreenTest`: favourites ke saath khulna, JSON search, barcode → aik product, variant ka barcode, till se sale, **wahi cart do dafa = aik sale**, **server ka repricing**, stock se zyada refuse, khali cart, **discount cap** (upar refuse, andar allow), uncapped seller, hold/resume/discard, quick customer + uski permission, favourite toggle, plan aur permission gates, cross-tenant customer.

**Result: `php artisan test` → 487 tests / 1,548 assertions PASS**.

**8) Browser verification** — asli sale ki: do products tap kiye (foran cart mein), Pay (F8), quick-cash **$200** button, Complete sale → **INV-202609-00001 · $150.00 · Change $50.00**. Peeche jaa kar tasdeeq ki: cost **98.31** (shelf ka weighted average, catalogue ka 40+55 nahi), profit **51.69**, Cola 166→165, Water 105→104. Zero console errors. Tablet pe teenon column upar neeche stack ho jate hain.

**⬜ BAQI (Phase 7 ka aakhri session):**
- ⬜ Sales listing + filters + actions (#21)
- ⬜ Invoice/receipt 80mm/58mm/A4 + custom footer (#23, #144)
- ⬜ Print UX aur reprint + audit (#145, #143)

➡️ **Next: Phase 7 Session 3** — sales list aur receipts, phir Phase 7 close.


### 2026-08-29 — Phase 7 (Session 1): Sale engine + cash register 🔄 (~40%)

Phase 7 sab se bara module hai (27 items), is liye teen sessions mein: **engine → POS screen → receipts**. Ye session engine ka hai — jo sab se neeche hai aur jis pe baqi sab khara hoga.

**✅ JO HO GAYA:**

**1) `SaleService` — #118 ke solah steps, aik transaction mein**
```
1 kuch bechne ko hai?          9  invoice number (#22)
2 branch, till, cash session   10 sale header
3 customer (ya walk-in)        11 lines
4 lines resolve                12 stock, ROW LOCK ke saath (#70)
5 price + COST snapshot (#52)  13 payments (#17, #19)
6 document totals              14 bacha hua customer ke khaate mein (#40)
7 tenders validate (#19)       15 drawer ka figure (#46)
8 ── transaction shuru ──      16 audit, commit
```
- **Aadhi sale sab se bura anjaam hai** — stock chala gaya magar invoice nahi, ya invoice ban gaya magar stock nahi — kyunke dukaan ko pata hi nahi chalta kaunsa aadha hua. Is liye sab kuch aik `DB::transaction` mein.
- Test isay pin karta hai: **credit limit paar hone pe** (step 14) steps 9–13 bhi roll back hote hain — na sale, na stock movement, na invoice number kharch.

**2) Step 12 wahan kyun hai jahan hai — race protection (#70)**
- Stock **transaction ke andar** jata hai, `InventoryService` se, jo har shelf row pe **`lockForUpdate`** karta hai. **Wahi lock poori race protection hai:** do till aakhri unit bechne aayein to dono usi row pe queue karte hain, aur doosre ko "kuch nahi bacha" milta hai — dono kamyab nahi hote.
- Pehle availability check karna aur baad mein deduct karna theek wahi gap chhorta jise lock band karta hai.

**3) Cost shelf se snapshot hoti hai, catalogue se nahi (#52)**
- Catalogue mein aik **nominal** cost hoti hai; shelf pe wo hoti hai jo is stock ki **asal** mein lagi. Line pe `unit_cost` usi waqt freeze ho jati hai.
- Warna pichle mahine ka margin har dafa **is mahine** ki cost pe dobara nikalta aur chup-chaap badalta rehta.

**4) Payment ka matlab — aur change kahan jata hai**
- `sale_payments.amount` wo hai jo sale pe **apply** hua, na ke jo hath mein diya gaya. 850 ki sale pe 1,000 dene se **850 ka cash payment + 150 change** record hota hai.
- Faida: `paid_total` total ke barabar rehta hai, **aur drawer ka hisab sacha rehta hai** — till ne 1,000 liye aur 150 wapas diye, yani wahi 850.
- Change hamesha **cash** se nikalta hai — dukaan card payment ka hissa wapas nahi karti.

**5) Split payment (#17, #19)** — `sale_payments` aik **table** hai, column nahi: aadha card aadha cash aam baat hai, aur din ke aakhir mein har method alag reconcile hona chahiye. `credit` bhi aik method hai magar wo **paisa nahi leta** — customer ka khaata charge karta hai. **Walk-in udhaar nahi le sakta** (kis se maangenge?).

**6) Cash register (#46, #139)**
- Session aik **trading period ko bracket** karta hai — isi liye "till short hai?" ka koi jawab banta hai: float + jo aaya − jo gaya = expected, aur counted se farq wo number hai jis ki dukaan ko parwah hai.
- **Aik counter pe aik hi open session** — MySQL mein partial unique index nahi hota, is liye ye check transaction ke andar counter ki sessions lock kar ke hota hai.
- `difference` **stamp** hoti hai, read pe derive nahi hoti: cash-up aik lamhe ka bayan hai, aur agle hafte koi sale void karne se pichle hafte ka shortfall chup-chaap nahi badalna chahiye.

**7) Invoice numbering (#22)** — format config se: `{PREFIX}-{YYYY}{MM}-{SEQ:5}`, aur sequence ka scope business/branch/monthly. **Held sale invoice number kharch nahi karti** (`HOLD-00001`) — numbers sequential hain aur gap wo cheez hai jo tax inspector poochta hai.

**8) ⚠️ Tests — 28 naye, sab PASS**
`Sales/SaleEngineTest`: cash sale, invoice format, **shelf se cost**, line arithmetic, split payment, **change**, unknown method, credit remainder, **walk-in udhaar nahi le sakta**, credit limit pe **poori sale** refuse, stock se zyada bechna refuse, **aakhri unit sirf aik dafa**, service line bina stock ke, **FEFO batch**, held sale kuch post nahi karti, resume, discard, **void postings ulat deta hai aur record rakhta hai**, void ko reason chahiye, cash session mein cash sales, close pe difference, aik hi open session, paid in/out, recalculate, cash rounding, tenancy.

**🐞 Aik cheez theek ki:** `resolveBranch()` ab **main branch pe fallback** karta hai (`InventoryService` ki tarah) — warna jo owner kisi branch mein park nahi hai wo bech hi nahi sakta tha.

**Result: `php artisan test` → 468 tests / 1,481 assertions PASS**.

**⬜ BAQI (Phase 7 ke agle do sessions):**
- ⬜ **POS screen** — layout, instant search, barcode scan, categories, favourites, customer quick-add, cart, hold/resume UI, keyboard shortcuts, AJAX, double-submit prevention (#14–16, #20, #89–91, #122, #146–148)
- ⬜ **Sales list + receipt** — filters/actions, 80mm/58mm/A4 invoice, print UX, reprint + audit, custom footer (#21, #23, #143–145)

➡️ **Next: Phase 7 Session 2** — POS screen (engine tayyar hai, ab uske upar till banegi).


### 2026-08-29 — Phase 6 MUKAMMAL ✅ (Purchases + Supplier Ledger)

Pehla module jo teenon cheezon ko aik saath chhuta hai: **catalogue (Phase 4) + inventory (Phase 4) + supplier ledger (Phase 5)**.

**✅ JO HO GAYA:**

**1) Sab se ahem faisla: order karne se KUCH nahi hota**
- Purchase order aik **darkhwast** hai. Jab tak maal nahi aata, dukaan ke paas na naya stock hai na naya qarz. **Shelf aur supplier ka account dono RECEIPT pe chalte hain** — aur sirf utna jitna asal mein aaya.
- Isi liye **`Partial` apni jagah aik state hai**, koi error nahi: kam maal aana aam baat hai, aur dukaan ko aik nazar mein dikhna chahiye ke kis order ka maal abhi baqi hai.
- States: `Draft` → `Ordered` → `Partial` / `Received`, aur `Cancelled`. Sirf **draft edit** ho sakta hai (order jane ke baad kaghaz aur delivery ka ikhtilaf ho jata, aur receipt ke baad stock ledger bol chuka hota hai).

**2) Receipt ka flow — #119 jo spec mein likha hai, wo aik transaction mein**
```
validate → lines → stock → ledger → payment → commit
```
- **validate** — purchase receive kar sakta hai, branch reachable hai, quantity ordered se zyada nahi.
- **lines** — har line ka `quantity_received` sirf **nayi** quantity se barhta hai (isi liye doosri receipt pehli ko dobara nahi ginti).
- **stock** — `InventoryService` se movement, **line ki apni cost** ke saath, taake shelf usi qeemat pe value ho jo is delivery ki thi (catalogue ki purani cost pe nahi).
- **ledger** — supplier ko **jo aaya us ki value** ka debit. Jo order kiya tha uska nahi.
- **payment** — agar darwaze pe paise diye, wo usi transaction mein credit ho jate hain.
- **commit** — sab kuch, ya kuch bhi nahi.

**3) Zyada delivery chup-chaap qabool nahi hoti** — agar supplier ne ordered se zyada bhej diya to wo **baat karne wali cheez** hai, chup-chaap bill pe charhane wala number nahi. Refuse hota hai, aur kuch bhi aadha apply nahi hota.

**4) Money model — har figure line se banta hai**
- ⚠️ **Document-level discount/shipping ka column jaan boojh kar nahi rakha.** Warna partial delivery pe usay lines pe **apportion** karna parta — aik rounding puzzle jo full receipt aur partial receipt pe **alag jawab** deta. Carriage aik **line** ki tarah jata hai.
- Line: `quantity × cost − discount + tax`. `effectiveUnitCost()` discount ko poori ordered quantity pe phailata hai, to aadhi delivery apna **wajib hissa** discount ka le kar aati hai — warna pehla box poora discount kha jata aur aakhri ko kuch na milta.
- Totals **store** hote hain (recompute nahi), kyunke pichle March ka bill aaj bhi wahi dikhna chahiye jo tab dikhta tha.

**5) Purchase returns (#37) — alag document, negative purchase nahi**
- Wajah: return apni date pe, apni wajah se hota hai, aur aksar delivery ke **hissay** ka hota hai. Usay original mein ghol dena aik aisi document ko dobara likhna hota jis pe amal ho chuka (#198). Aur supplier poochta hai *"kya wapas bheja, kab"* — us sawal ka apna number chahiye.
- **Jo aaya us se zyada wapas nahi ja sakta.** Har return line us **purchase line** ko point karti hai jise wo reverse kar rahi hai, to service jawab de sakti hai: "12 aaye, 5 pehle ja chuke, ab zyada se zyada 7". Ye link na hota to wahi delivery do dafa return ho kar supplier ka account ulta kar deti.
- Stock **usi cost pe** wapas jata hai jis pe aaya tha — maal wapas karne se baqi stock ki value nahi badalni chahiye.

**6) Cancel karna ≠ reverse karna**
- Draft ya untouched order cancel karne se kuch nahi hota. **Partly received order cancel karne pe jo aa chuka wo aaya hi rehta hai** — stock shelf pe, aur supplier ka us pe haq. Sirf baqi outstanding chhoora jata hai. Delivery ulatna **return** hai, cancellation nahi.

**7) Permissions — paanch alag authorities (#52)**
`purchases.view` (parhna) · `purchases.create` (order raise + receive) · `purchases.update` (draft edit) · `purchases.void` (cancel/delete draft) · `purchases.return` (maal wapas). Aur **bill ki payment `suppliers.ledger` pe** hai — supplier ke account pe paisa hilana aik hi authority hai, chahe wo account screen se ho ya purchase se.

**8) ⚠️ Tests — 38 naye, sab PASS**
- `Purchasing/PurchaseTest` (26): draft/order kuch post nahi karte, line arithmetic (500 − 50 + 10% = 495, effective unit 49.5), receipt pe stock **delivery ki cost pe**, short delivery → Partial aur sirf aaye hue ka bill, **doosri receipt double-count nahi karti**, over-delivery refuse (aur kuch aadha apply nahi), door pe payment usi transaction mein, service line bill hoti hai magar shelf pe nahi jati, **batch-tracked line apna lot + expiry le kar jati hai**, partial cancel jo aaya wo rakhta hai, sirf untouched draft delete hota hai, returns (stock↓ + credit, limit, accumulate, bina receipt ke nahi, bina reason ke nahi), plan features, branch aur tenant gates.
- `Purchasing/PurchaseAccessTest` (12): poora owner flow draft→paid, do visits mein partial receive, **paanchon authorities alag** (parhne wala receive nahi kar sakta, order karne wala pay nahi kar sakta, cancel ko reason chahiye, return apni permission), plan gates, cross-tenant 404, future date aur khali lines refuse.
- **Result: `php artisan test` → 440 tests / 1,395 assertions PASS**.

**9) Seeder + build + browser verification**
- Demo data: **PO-000001** (120 Cola + 60 Water = 8,460 · poora received · 5,000 paid · 6 bottles return) aur **PO-000002** (90 Water = 4,770 · abhi supplier ke paas).
- ✅ Browser verified: purchases list (Open orders 1 · Unpaid 3,460 · PO-000002 pe **"Nothing owed yet"** — yani central decision screen pe nazar aata hai), purchase detail (lines + "6 returned" + returns section + **"The bill"** panel jo kehta hai *"You are billed for what arrived, not for what was ordered"*), aur browser se **asli kaam**: bill pay kiya → *"Paid 3,460.00. This bill is now settled."*, phir PO-000002 pe **40 of 90 receive + 1,000 payment** → *"partly received. 1,120.00 still to pay."*
- Numbers end-to-end trace hote hain: water stock 17 opening + 60 + 40 = **117**, supplier balance **27,856**. Zero console errors.

➡️ **Next: Phase 7** — POS + Sales + Payments + Customer Ledger (sab se bara module; inventory aur customer ledger dono tayyar hain).


### 2026-08-29 — Phase 5 MUKAMMAL ✅ (Customers + Suppliers + Ledgers)

Ab dukaan ke **khaate** ban gaye — kaun kitna deta hai, kaun kitna lena hai, aur har rupaye ki poori history.

**✅ JO HO GAYA:**

**1) Aik arithmetic, do heading — sab se ahem faisla**
- **DEBIT** hamesha barhata hai ke account kitna deta hai; **CREDIT** hamesha kam karta hai. Ye dono parties ke liye bilkul aik jaisa hai. Farq sirf ye hai ke *"deta hai"* ka **matlab** kya hai:
  - **Customer** — balance *receivable* hai: positive matlab **customer ne humein dena hai**. Udhaar sale debit, unki payment credit.
  - **Supplier** — balance *payable* hai: positive matlab **humne unko dena hai**. Purchase debit, unki payment credit.
- Faida: screen pe positive balance hamesha aik hi tarah parha jata hai. **Aik arithmetic, do heading** — bajaye do alag sign conventions ke, jo aik din zaroor aapas mein mix ho jate.
- `LedgerEntryType::direction()` waahid jagah hai jo faisla karti hai (bilkul `StockMovementType` ki tarah), to kisi caller ko yaad nahi rakhna parta ke return kis taraf jata hai.

**2) `ledger_entries` — aik table, dono parties (morph)**
- **Append-only, koi `updated_at` nahi.** Financial line kabhi edit nahi hoti; ghalti ka ilaj **ulti entry** post karna hai (#133, #198). Yehi isay saboot banata hai.
- **Do near-identical tables kyun nahi:** running-balance ka logic do jagah copy hota, aur jo copy kam use hoti wohi drift karti. Customer aur supplier mein farq **matlab** ka hai, **hisab** ka nahi — to matlab party pe rehta hai, hisab yahan.
- **`debit` aur `credit` alag non-negative columns**, aik signed amount nahi: screen aik accounting statement hai jise waise bhi do column chahiye, aur signed amount reader ko andaza lagane pe majboor karta hai ke kaunsa sign kya matlab rakhta hai.
- `balance_after` locked transaction ke andar stamp hota hai, to statement ko history dobara compute nahi karni parti.
- ⚠️ **Branch-scoped NAHI** (#137): `branch_id` sirf batata hai kahan hua. Aik customer High Street pe udhaar le kar Retail Park pe utaar sakta hai — yehi to point hai.

**3) `PartyLedgerService` — balance badalne ka waahid raasta (#183)**
- Bilkul `InventoryService` jaisa shape, jaan boojh kar: **masla wahi masla hai.** Append-only ledger sach hai, party ka `balance` column cache hai jo usi locked transaction mein chalta hai, aur `recalculate()` aik ko doosre se dobara banata hai — repair tool bhi, **saboot bhi**.
- Aik entry atomically: party row pe **`lockForUpdate`** (do till aik hi customer se payment lein to queue karein, dono aik hi purana balance na parhein) → type se debit/credit → balance stamp → cache update.
- `CustomerLedgerService` aur `SupplierLedgerService` sirf party-specific vocabulary add karti hain (#183 dono ka naam leta hai).

**4) Credit limits (#40) — teen values, aik convention**
- `NULL` = koi ceiling nahi · `0` = **cash only (DEFAULT)** · `n` = itna hi.
- Default cash-only is liye hai ke **kisi ko udhaar dena aik faisla hona chahiye**, default nahi.
- Gate **service mein** hai, POS mein nahi — to har raasta (till, API, import) aik hi limit se guzarta hai. Blocked customer kabhi paas nahi hota, uski limit chahe kuch bhi ho (#105).
- Credit sale **plan capability** bhi hai: `sales.credit_sales` feature na ho to service khud mana kar deti hai.

**5) Blocked ≠ deleted ≠ hidden (#105)**
- Blocked customer ka record, statement aur **har rupaya** waisa ka waisa rehta hai. Wo sirf transact nahi kar sakta, aur **wajah record hoti hai**. Payment phir bhi li ja sakti hai — blocked customer ka hisab chukana to bilkul wohi cheez hai jo dukaan chahti hai.
- Jis account ki statement hai wo **delete nahi hota, archive hota hai** (#104) — delete uski poori history le jata.

**6) Statement — asli accounting format (#41, #42)**
- Date · Particulars · Debit · Credit · Running balance, **oldest first** (running balance sirf neeche ki taraf hi sahi parha jata hai).
- **Filtered statement "brought forward" carry karta hai** — window se pehle ka balance ledger se nikal kar upar dikhaya jata hai, warna period ka statement add hi nahi hota.
- Footer mein period totals, taake statement khud ko foot kar sake.

**7) Opening balance (#152 ka usool, paison pe)** — jo pehle se lena/dena tha wo bhi **ledger se hi post** hota hai, to din aik usi history ka hissa hai jis ka din hazaar. Aik shelf pe sirf aik dafa; doosri entry correction hai, aur correction adjustment hai.

**🐞 Do asli bugs jo tests ne pakre:**
1. **`credit_limit` NOT NULL thi** jabke poora design `NULL = unlimited` pe chalta hai — migration ka comment ye keh raha tha aur column ye kar hi nahi sakti thi. Column nullable ki.
2. **Credit-limit resolution ka order ghalat tha:** form "unlimited" tick hone pe amount field **disable** kar deta hai, aur disabled input **submit hota hi nahi**. Code pehle missing amount parhta tha → har unlimited account chup-chaap **cash-only** ban jata. Ab "unlimited" pehle check hota hai.

**8) ⚠️ Tests — 41 naye, sab PASS**
- `Parties/PartyLedgerTest` (26): dono taraf ka debit/credit, overpayment se credit balance, returns, credit limits (cash-only / limit paar / unlimited / blocked / plan feature), ghalat side ki entry reject, adjustments + audit, opening balance sirf aik dafa, statement ki running balance, **filtered statement ka brought-forward**, recalculate drift detect karta hai, summary statement se foot karti hai, **branch A pe udhaar → branch B pe payment (#137)**, cross-tenant refuse.
- `Parties/PartyAccessTest` (15): poora owner flow, **teen alag authorities** (view / manage / ledger) — dekhne wala edit nahi kar sakta, edit karne wala paise nahi hila sakta; blocking reason record karti hai; statement wala delete nahi hota; quota; feature gates; cross-tenant 404; validation (positive payment, future date nahi, reason lazmi, duplicate code).
- **Result: `php artisan test` → 402 tests / 1,259 assertions PASS**.

**9) Seeder + build + browser verification**
- Demo data ab asli account history ke saath: **Ayesha Traders** (12,000 opening + 18,500 sale − 20,000 payment − 1,500 return = **9,000**), **Hassan Catering** (5,000 deposit → in credit), **Walk-in Customer** (cash only), **Metro Cash & Carry** (45,000 + 62,000 − 80,000 = **27,000 you owe**), **Gulberg Textiles** (settled). Har figure ledger service se post hua hai, `balance` column mein likha nahi gaya.
- ✅ Browser verified: customers list (Owed to you 9,000 / Held in credit 5,000), **customer profile** (4 cards + details + credit "45,000 still available"), **statement** jisme period total `30,500 | 21,500 | 9,000` bilkul foot karta hai, aur browser se **asli payment** li: *"Received 4,000.00. They now owe 5,000.00."*; suppliers list (You owe 27,000). Zero console errors.

➡️ **Next: Phase 6** — Purchases + Supplier Ledger (ab supplier ledger tayyar hai, purchases usi pe post karengi).


### 2026-08-29 — Phase 4 MUKAMMAL ✅ (batch/expiry + barcode labels + images + import/export)

Phase 4 ke aakhri chaar items bhi ban gaye. **Products + Categories + Brands + Units + Inventory — poora phase close.**

**✅ JO HO GAYA:**

**1) Batch + expiry tracking (#34) — sab se bara item**
- Nayi table `stock_batches` — aik delivery, aik shelf, apni expiry. **Alag table kyun, `stocks` pe do column kyun nahi:** aik shelf pe aik hi product teen alag deliveries se aa sakta hai jo teen alag hafton mein expire hongi. *"Kitne yoghurt hain"* aur *"Friday tak kitne acche rahenge"* do alag sawal hain — doosra jawab sirf per-batch row de sakti hai.
- **FEFO, FIFO nahi** — stock **sab se pehle expire hone wale** batch se nikalta hai, na ke sab se pehle aane wale se. Perishables mein purani delivery hamesha pehle kharab nahi hoti (baad wali delivery ki life choti ho sakti hai), aur lambi date wala maal pehle bech dena hi wo tareeqa hai jis se baqi phenkna parta hai. Undated batch **sab se aakhir** mein — uski koi urgency nahi.
- **Aik movement kabhi do batches mein nahi phailti.** Do deliveries se 6 yoghurt bechne pe **do ledger lines** banti hain, aik line + footnote nahi — to har line khud bata deti hai "kitne, kis batch se, kis cost pe", aur recall ledger se trace ho jata hai.
- **Har product ko iski zaroorat nahi.** Jo dukaan doodh aur phone charger dono bechti hai usay charger ka lot number type nahi karna chahiye — is liye ye **per-product flag** hai (`products.tracks_batches`), plan ke expiry feature ke peeche. Jis product pe off ho uska behaviour bilkul waisa hi hai jaisa is table ke wujood se pehle tha.
- **Batch minus mein nahi ja sakta**, chahe shelf ki policy negative allow karti ho: 4 ke batch se 6 nikalna ghalti hai, negative batch nahi.
- `sellableStock()` — expired maal shelf pe hai magar **bik nahi sakta**, to ye usay chhor deta hai; `getAvailableStock()` phir bhi ginta hai. Do alag sawal, do alag jawab.
- Naya screen **Batches & expiry**: "Already expired" hamesha upar (yehi wo list hai jo har din nazarandaz hone pe paisa khaati hai), phir "Expiring within N days". Adjustment form ab lot number + expiry bhi leta hai.

**2) Barcode label printing (#27)**
- `Support/Ean13.php` — **EAN-13 khud likha** (koi package nahi): encoding aik lookup table aur 95 bars hai, 1977 se nahi badla, aur output waise bhi inline SVG chahiye taake label printer pe crisp chhape. Package hota to jitna kaam karta us se zyada audit karna parta.
- ⚠️ **Sab se aham cheez jo galat ho sakti thi:** EAN-13 ka **pehla digit bars mein draw hota hi nahi** — wo baqi chhe digits ki **parity (L/G mix)** mein chhupa hota hai. Isay na samajhne wala renderer aisa barcode banata hai jo **kisi doosre product ka scan hota hai**. Test isi ko pin karta hai: aik hi baqi digits, alag pehla digit → alag bars.
- Ghalat check digit wale code pe **kuch render nahi hota** — ghalat bars chhapne se behtar hai koi bars na ho.
- Printable sheet **app layout ke bahar** hai: print job ko sidebar, topbar aur dark mode nahi chahiye. Label width (mm) adjustable, kyunke har dukaan ke paas apna label paper hota hai.

**3) Product images (#149) — secure uploads (#101)**
- Teen cheezein har upload ko rokti hain aur **koi bhi browser pe bharosa nahi karti:** `mimes` file ke **asli content** se extension check karta hai; `image` rule usay decode kar ke dikhata hai (yani `.jpg` naam ka PHP script store hone se pehle hi reject); aur naam **random** hota hai, to koi ye tay nahi kar sakta ke file kahan giregi ya kis ki file overwrite hogi.
- Image **replace** hoti hai, jama nahi hoti — nayi aate hi purani delete, warna saal bhar mein disk chup-chaap bhar jati hai. Product delete pe file bhi jati hai, magar **transaction commit ke baad** — rolled-back delete tasveer saath na le jaye.
- SVG jaan boojh kar allowed **nahi**: SVG script container hai, tasveer nahi.

**4) CSV import + export (#150, #151)**
- **Import all-or-nothing hai.** Aadha import sab se bura anjaam hai: dukaan ko pata hi nahi chalta kaun si rows chadhin, to ya dobara import kar ke duplicate banate hain ya sab delete kar ke shuru se. Aik transaction, aur koi bhi kharab row poori file wapas le jati hai — **line number ke saath** report, kyunke 900 rows mein "invalid price" bekaar hai.
- **Matching SKU se** — jis row ka SKU mojood ho wo **update** karti hai, doosra product nahi banati. Corrected price list dobara upload karna wahi karta hai jo dukaan ka matlab hai.
- **Quota pehle check hota hai** (#79), likhne se pehle — kisi ko ye batana ke unki 500-row file row 480 pe fail hui, unki dopeher zaya karna hai.
- Categories/brands **naam se match ya ban** jate hain; **units nahi bantay** — man-ghadat unit of measure data error hai.
- Export **stream** hota hai (poori CSV memory mein banana demo data pe theek chalta aur asli tenant pe girta), UTF-8 BOM ke saath taake Excel accented characters na bigaare. **Cost sirf usay milti hai jo cost dekh sakta ho (#52)** — export sab se aasan tareeqa hai margins le kar bahar nikalne ka.

**🐞 Aik asli bug jo is kaam ne pakra — aur wo Phase 2 se mojood tha:**
- `FeatureService` aur `PlanLimitService` **singleton nahi the**, to har injection ko apna alag `$memo` milta tha. Dono ke paas `flush()` hai (plan ya override badalne pe). Ye do baatein sirf tab kaam karti hain jab **sab aik hi instance** share karein — warna override screen apni copy flush karti aur usi page ka layout purani (stale) copy le kar render karta, yani aik hi sawal ke do alag jawab.
- Seeder ne isay pakra: override enable karne ke baad bhi product `tracks_batches=false` ban raha tha.
- Fix: dono ab `scoped` hain (`singleton` nahi) — aik instance **per request/job**, to long-running worker aik tenant ke entitlements agle tenant ke job mein na le jaye.

**5) ⚠️ Tests — 39 naye, sab PASS**
- `Inventory/BatchExpiryTest` (17): opt-in, plan gate, batch banna, matching deliveries aik batch, alag expiry alag batch, **FEFO**, do batches pe do ledger lines, undated aakhir mein, batch negative nahi, expired vs expiring lists, sellable stock, HTTP screens.
- `Catalog/CatalogToolsTest` (22): EAN-13 check digit, 95-module shape, **parity wala test**, invalid pe kuch nahi, label sheet quantities, plan gate; image random naam se store, **PHP script bhes badal ke reject**, replace pe purani delete, remove; CSV create/update-by-SKU, **aik kharab row pe poora rollback**, line number, quota, cost permission dono taraf (import + export), template.
- **Result: `php artisan test` → 361 tests / 1129 assertions PASS**.

**6) Seeder + build + browser verification**
- Seeder mein ab **Fresh Milk 1L** hai teen batches ke saath (expired / 6d / 21d) — aur ye Professional plan pe **per-business feature override (#10)** se chalta hai, plan badal kar nahi: sirf demo data behtar dikhane ke liye Professional ka matlab badalna har tenant ke entitlements chup-chaap badal deta.
- ✅ Browser verified: **label sheet** (3 asli EAN-13 barcodes, guard bars lambe, digits `2 | 592983 | 382221`, price), **Batches & expiry** (teenon states + values), **Import & export** (tabs, column chips, template link), aur asli export fetch → `text/csv; charset=UTF-8` + sahi header row. Zero console errors.

➡️ **Next: Phase 5** — Customers + Suppliers (+ Ledgers).


### 2026-08-28 — Phase 4 (Session 2): Inventory engine 🔄 (~75% — stock, ledger, adjustments, low stock)

Ab stock ka **asli engine** ban gaya. Baqi Phase 4 mein sirf transfers, batch/expiry, barcode printing, image upload aur import/export reh gaye hain.

**✅ JO HO GAYA:**

**1) Do nayi tables — aur inka rishta sab se ahem hai**
- **`stock_movements` = SACH** (#30). Append-only ledger, har tabdeeli ki aik line. **`updated_at` hai hi nahi** — movement kabhi edit nahi hoti; ghalti ka ilaj ulti movement post karna hai, bilkul waise jaise financial record void hota hai delete nahi (#133, #198). Isi liye ye "evidence" hai, "andaza" nahi.
- **`stocks` = CACHE**, sach nahi. Ye running balance hai jo **usi transaction** mein update hota hai jis mein movement likhi jati hai — taake POS ka sawal ("ye bech sakta hoon?") aik indexed lookup ho, poori history ka SUM nahi. `recalculate()` isay ledger se dobara bana deta hai: ye repair tool bhi hai aur **saboot bhi** ke dono kabhi ghalat taur pe alag nahi ho sakte.
- `quantity` **signed** hai (+ andar, − bahar), to running balance sirf aik SUM hai aur kisi reader ko yaad nahi rakhna parta ke purchase return kis taraf jata hai.
- **`variant_key` generated column** (`COALESCE(product_variant_id, 0)`): SQL ke unique index NULL ko constrain nahi karte — do rows jinka variant NULL ho MySQL ke nazdeek "alag" hain, yani simple product ke **do stock rows** ban sakte the. Generated column NULL ko 0 bana deta hai, ab index asal mein kaam karta hai.

**2) `InventoryService` — stock badalne ka WAAHID raasta (#185)**
- Har module (purchase, POS, returns, transfer, stock take) `createMovement()` se guzarta hai. Ye sirf convention nahi: `stocks.quantity` fillable hi nahi, to doosra raasta hai hi nahi. **Poora ledger** hi stock figure ko andaze se alag karta hai.
- Aik movement **atomically** ye karti hai: shelf row pe **`lockForUpdate`** (do till aik hi aakhri unit na bech dein) → type se sign → **negative stock check (#142)** → incoming pe **weighted average cost** dobara nikalna → ledger line + cached balance, dono aik hi transaction mein.
- API: `getAvailableStock()`, `hasEnough()`, `createMovement()`, `adjust()`, `recordOpeningStock()`, `setStockTo()`, `ledger()`, `lowStock()`, `valuation()`, `recalculate()`, `stockByBranch()`, `isTrackingEnabled()`.
- **Jaan boojh kar ye service authorization NAHI karti** — permissions/features route aur calling service dekhte hain. Warna pehli dafa jab background job ko correction post karni hoti, isay khud ko bypass karne ko kaha jata.

**3) Faisle jo code mein likhe hain**
- **Negative stock default NO (#142)** — `config/inventory.php` se, env-driven. Jo POS stock ko minus mein jane deta hai wo dukaan ko chup-chaap keh raha hota hai ke uske stock figures fiction hain. Magar kuch businesses ko zaroorat hoti hai (bakery jo oven mein para maal bech rahi ho), is liye ye **setting** hai, rule nahi. Phase 11 ise per-business settings mein le jayega.
- **Costing = weighted average**, FIFO nahi: average stock take, negative balance aur correction — teeno ko bina "layers" unwind kiye survive karta hai. Outgoing movement average ko haath nahi lagati (sale pehle se books pe mojood cost pe value consume karti hai).
- **Cost 0 kabhi accidentally nahi:** unit cost na di jaye to shelf ka average, warna catalogue ki cost price. Warna stock chup-chaap zero pe value ho jata.
- **Stock take total nahi, FARQ post karta hai** — ledger mein "kya badla" likha jata hai, to count khud auditable rehta hai, silent overwrite nahi.
- **Low stock threshold** (#33): variant → product → config fallback, aur **SQL mein resolve** hota hai taake sweep aik query rahe. Jis product ne threshold set hi nahi kiya us pe **khamoshi sahi jawab hai** — poore catalogue pe blanket threshold sirf shor paida karta hai jo koi nahi parhta.
- **Valuation mein negative shelves shamil hain** — oversold shelf asli masla hai; usay total se chhupana matlab number ko dukaan ki umeed ke mutabiq banana, record ke mutabiq nahi.

**4) UI — 2 naye screens**
- **Inventory**: 4 stat cards (shelves / units on hand / low / out of stock), search + branch + status filters (low, out, oversold), stock value (sirf jise cost dekhne ki ijazat ho #52), aur per-shelf table with status badges (#28).
- **Ledger** (#30): product ka per-branch stock, poori movement history (newest first, running balance, kis ne kiya), aur **adjust stock form** (#31) — reason **required**, kyunke bina wajah badla hua stock figure hi wo jagah hai jahan shrinkage chhupti hai.

**5) Opening stock (#152)** — `recordOpeningStock()`, aur ye bhi movement ki tarah post hoti hai, to **din aik** usi history ka hissa hai jis ka din hazaar. Aik shelf pe sirf aik dafa; doosri entry correction hai, aur correction adjustment hai.

**6) ⚠️ Tests — 31 naye, sab PASS**
- `Inventory/InventoryTest` (31): sign rules, negative-stock dono taraf, weighted average, cost fallback, adjustments + audit, stock take ka farq, opening stock idempotent, variants per-variant stock, ledger running balance, **recalculate drift detect karta hai**, low-stock teeno levels, valuation, per-branch stock, cashier ko doosri branch ka stock nahi dikhta, unreachable branch mein movement refuse, feature off pe refuse, aur HTTP pe permission split.
- **Result: `php artisan test` → 300 tests / 966 assertions PASS**.

**7) Seeder + build + browser verification**
- Seeder ab demo catalogue ko **opening stock** bhi deta hai (asli service se) — 5 shelves, 87 units, valuation 19,631.
- ✅ Browser verified: Inventory screen (cards + table + value), ledger page, aur **asli adjustment browser se ki**: −2 "Two bottles broke in the crate" → flash *"Stock adjusted by −2 — now 30."*, ledger mein nayi line (balance 30, Store Owner ke naam ke saath), opening stock line (+32, System) neeche. Zero console errors.

**⬜ BAQI (Phase 4 close karne ke liye):**
- ⬜ **Stock transfer Branch A→B (draft/sent/received)** — #32
- ⬜ **Batch + expiry tracking aur uski reports** — #34
- ⬜ **Barcode label printing** — #27 · **Product image upload** — #149 · **CSV import/export** — #150/#151

➡️ **Next: Phase 4 Session 3** — transfers + expiry, phir barcode printing aur import/export; us ke baad Phase 4 close.


### 2026-08-28 — Phase 4 (Session 1): Catalog mukammal 🔄 (~40% — Products + Categories + Brands + Units)

Phase 4 ka **catalog hissa poora ban gaya**. Inventory (stock, movements, ledger, adjustments, transfers) **agli session** mein — neeche "BAQI" list mein saaf likha hai.

**✅ JO HO GAYA:**

**1) Database — 5 nayi migrations**
- `categories` (self-referencing `parent_id` — subcategories #26), `brands`, `units`, `products`, `product_variants`. Sab pe `created_by`/`updated_by` + softDeletes.
- **Paisa hamesha `decimal`, kabhi `float` nahi.** Cost mein **4 decimal places** (case price ko divide karo to unit cost paise ka bhi hissa ban jati hai), selling price mein 2 (jo customer se asal mein liya jata hai).
- **Design faisla:** category ke liye do tables (categories + subcategories) nahi — **aik self-referencing table**. Aaj jo shop sab kuch "Drinks" mein rakhti hai wo kal "Drinks → Cold → Cans" chahegi; do-level schema ko us waqt migrate karna parta.
- **`products` mein stock ka column NAHI hai.** Product ke paas "aik quantity" hoti hi nahi — uski quantity **har branch ki alag** hoti hai (#136), jo inventory tables mein aayegi. Yahan aik cached total rakhna doosri "sachai" bana deta jo waqt ke saath ghalat ho jati.
- **`product_variants.options`** JSON hai (`{"Size":"L","Colour":"Red"}`) — attributes/values/pivot ki teen tables nahi. Aur naam **`options` hai, `attributes` nahi**: `$attributes` Eloquent ki apni internal property hai, us naam ka column bahar se theek parhta hai magar **class ke andar se raw attribute bag** de deta hai.

**2) `ProductType` enum (#25)** — Standard / Service / Variable. `tracksStock()` aur `hasVariants()` isi enum pe hain, taake "service ka stock nahi hota" **aik hi jagah** likha ho aur har module ka jawab aik jaisa rahe.

**3) `CatalogService` (#26, #158)**
- Categories/brands/units ka CRUD; **quota** categories aur brands pe (#79), **units pe jaan boojh kar nahi** — apna maal theek se describe karne ki qeemat tenant se nahi leni chahiye.
- Category ka parent: doosre tenant ka ho to 404-jaisa refuse, apna aap parent na ban sake, aur **apni hi subcategory ke neeche na ja sake** (warna tree ring ban jata hai aur har recursive read hang).
- **Unit conversion ka dhaancha ready (#158):** base unit + `conversion_factor`. Stock hamesha **base unit** mein rehta hai — Dozen bechne pe 12 Piece kam hote hain. Derived unit `catalog.multi_unit` feature maangta hai; chain (Gross → Dozen → Piece) abhi refuse hoti hai.
- Naya business ab **aik base unit "Piece" ke saath** shuru hota hai, taake pehla product add karne se pehle unit invent na karni pare (#195).

**4) `ProductService` (#24, #25, #27)** — teen cheezein yahan isliye hain ke aur kahin mehfooz nahi rakhi ja saktin:
- **Code allocation:** SKU aur barcode ka namespace **products + variants dono pe aik hi hai** — till pe scan kabhi ambiguous nahi hona chahiye — is liye dono tables ek hi jagah se allocate hote hain. (Test: product ka SKU variant ke SKU se takra nahi sakta.)
- **Variant contract:** variable product ki qeemat variants pe, standard ki apne upar. Type badlo to variants **archive** hote hain, delete nahi (#198).
- **Gates:** product quota, `catalog.variants` feature, aur "service kabhi stock track nahi karega — form kuch bhi kahe".
- **Barcode auto-generate (#27):** EAN-13 **check digit ke saath**, prefix `2` (GS1 ka restricted-circulation range — yani jo codes dukaan apne liye khud banati hai). Asli manufacturer prefix use karna kisi asli product se takra sakta tha.

**5) ⚠️ Cost price aik PERMISSION hai, sirf aik column nahi (#52)**
- List aur form dono `products.view_cost` poochte hain.
- **Aur sirf chupana kaafi nahi:** jo banda cost dekh nahi sakta, wo usay **overwrite bhi nahi kar sakta**. Form request cost ko drop kar deti hai aur service "key missing = jaisa hai waisa rehne do" samajhti hai — warna cashier product ka naam theek karte hue uski cost chup-chaap 0 kar deta. Iska apna test hai.

**6) UI — 4 naye screens**
- **Products**: search (name/SKU/barcode, **variant ke code se bhi**), category/brand/status filters, **pagination** (#97), type badges, price range (variable ke liye), margin (sirf jise ijazat ho), activate/deactivate, quota meter.
- **Categories**: parent + nested children aik hi table mein; **in-use category ka delete button greyed** ("switch it off instead").
- **Brands**, **Units** (base/derived, conversion factor, "whole numbers vs decimals").
- Chaaron pe aik **catalog tab strip**, jo permission ke hisab se filter hoti hai.
- Product form: type radio cards (Variable **plan mein na ho to disabled + "Not in your plan"**), Alpine se variant rows add/remove, SKU khali chhoro to generate ho jata hai.

**7) ⚠️ Tests — 47 naye, sab PASS**
- `Catalog/CatalogTest` (23), `Catalog/ProductTest` (24).
- **Result: `php artisan test` → 269 tests / 886 assertions PASS**.
- Aik purana test update karna para: Phase 2 ka `test_a_code_with_no_registered_counter_reports_zero_usage` `limits.products` ko "abhi ginti nahi hoti" ki misaal ke taur pe use karta tha — ab wo asal mein ginta hai, to misaal `limits.invoices_per_month` (Phase 7) pe move kar di. Test ka maqsad wahi hai, misaal badli hai.

**8) Seeder + build + browser verification**
- Seeder ab **demo catalogue** bhi banata hai (asli services ke through, insert se nahi): 3 categories, 1 brand, 4 products — standard × 2 (generated EAN-13 barcodes ke saath), **variable T-Shirt (3 variants)**, aur aik service.
- `npm run build` — app.css 102.85 → **103.58 kB** (gzip 16.63).
- ✅ Browser verified: Products list (Cola 45.50 → 70.00, **margin 35%**; T-Shirt ka **price range 1,200–1,250**; service 100%), naya product form (type switch karte hi "Codes & pricing" card **Variants card se replace** ho jata hai), Categories (nested + greyed delete), Units (Piece = base unit). Zero console errors, zero failed requests.

**⬜ BAQI (Phase 4 complete karne ke liye — agli session):**
- ⬜ **Inventory ka poora hissa (#28–#34, #136, #142, #185):** `stock` (per branch #136) + `stock_movements` ledger, `InventoryService` (`getAvailableStock()` / `createMovement()` #185), stock adjustment (+reason), branch-to-branch transfer (draft/sent/received), low-stock alerts, expiry/batch, aur **negative stock setting (default No, #142)**.
- ⬜ **Barcode label printing** (custom size, name+price) — #27. Generate ho gaya, print layout baqi.
- ⬜ **Product images upload** — #149. Column aur placeholder ready hain, upload UI baqi (secure upload rules #101 ke saath aayega).
- ⬜ **Bulk import/export (CSV/Excel)** — #150, #151. `catalog.import` feature aur `products.import` permission dono maujood hain.
- ⬜ **Opening stock** — #152 (inventory ke saath).

➡️ **Next: Phase 4 Session 2** — Inventory engine (`InventoryService` + stock + movements), phir barcode printing aur import/export.


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
- [x] Categories / Subcategories / Brands / Units — #26 *(self-referencing categories; units bilkul unlimited — quota sirf categories/brands pe)*
- [x] Product management (full form) — #24 *(`ProductService` + filters/search/pagination wali list; SKU khali chhoro to generate)*
- [x] Product types: Standard / Service / Variable — #25 *(`ProductType` enum — `tracksStock()` yahin, service ka stock kabhi nahi)*
- [x] Product variations (size/color, per-variation SKU/price) — #25 *(`product_variants` + `catalog.variants` feature gate; per-variation **stock** inventory ke saath aayega)*
- [x] Product images + placeholder — #149 *(secure upload #101: `mimes` asli content parhta hai, `image` decode karwata hai, naam **random**; replace pe purani file delete, SVG allowed nahi)*
- [x] Product status (Active/Inactive) — #105 *(inactive product bik nahi sakta magar poori history rakhta hai)*

### Barcode (plan-based)
- [x] Auto-generate + manual barcode — #27 *(EAN-13 check digit ke saath, GS1 ka in-store prefix `2`; manual code diya to wahi, aur products+variants dono mein unique)*
- [x] Barcode label printing (custom size, name+price) — #27 *(`Support/Ean13.php` — khud likha SVG renderer; **pehla digit parity mein hota hai, bars mein nahi**; printable sheet app layout ke bahar, label width mm mein)*

### Inventory (`InventoryService` — #185)
- [x] Inventory listing (stock, value, status badges) — #28 *(per-shelf table + 4 summary cards; stock value sirf `products.view_cost` walon ko #52)*
- [x] Automatic stock movement (purchase↑ sale↓ returns/adjust) — #29 *(engine tayyar: `StockMovementType` sign khud decide karta hai. Purchase/sale **callers** apne modules ke saath aayenge — Phase 6/7)*
- [x] Inventory ledger (full history) — #30 *(append-only, koi `updated_at` nahi; running balance har line pe stamped; screen pe newest-first + kis ne kiya)*
- [x] Stock adjustment (add/remove + reason) — #31 *(reason **required**; audit log; stock take alag se **farq** post karta hai, total nahi)*
- [x] Stock transfer (Branch A→B, draft/sent/received) — #32 *(teen steps kyunke safar ke teen lamhe hain; in-transit stock kisi shelf pe nahi hota; **shortfall reconcile nahi hota**; cost maal ke saath jati hai; sent cancel karne pe stock wapas source pe post hota hai)*
- [x] Low stock alerts — #33 *(threshold: variant → product → config, SQL mein resolve; inventory screen pe count + filter. Email/push notifications notification system ke saath aayenge)*
- [x] Expiry management (batch + expiry, reports) — #34 *(`stock_batches` + **FEFO** consumption; aik movement kabhi do batches mein nahi phailti; per-product opt-in; expired vs expiring screens; `sellableStock()` expired ko chhorta hai)*
- [x] Multi-branch inventory (per-branch stock) — #136 *(aik row per (branch, product, variant); branch scope se cashier ko sirf apni branch ka stock)*
- [x] Negative stock setting (default No) — #142 *(`config/inventory.php`, env-driven; refuse hone pe `InsufficientStockException` asli numbers batati hai. Phase 11 ise per-business setting bana dega)*
- [x] `getAvailableStock() / createMovement()` centralized — #185 *(`InventoryService` — stock badalne ka waahid raasta; `stocks.quantity` fillable hi nahi. `recalculate()` ledger se rebuild karta hai)*

### Data Migration Helpers
- [x] Opening stock support — #152 *(`recordOpeningStock()` — movement ki tarah post hoti hai, per shelf sirf aik dafa; seeder bhi isi se demo stock deta hai)*
- [x] Bulk import (CSV/Excel, plan feature) — #150 *(**all-or-nothing** — aik kharab row poori file rollback karti hai, line number ke saath; SKU match pe update; quota likhne se **pehle** check)*
- [x] Bulk export (Excel/CSV) — #151 *(streamed, UTF-8 BOM (Excel ke liye); cost sirf `products.view_cost` walon ko #52; gate `reports.export` pe)*
- [x] Unit conversion future-ready structure — #158 *(base unit + `conversion_factor`; stock hamesha base unit mein, `toBase()`/`fromBase()` tested. Multi-unit **selling** POS ke saath)*

---

## PHASE 5 — Customers + Suppliers (+ Ledgers)
*(Spec ref: #38–42, #137, #183)*

### Customers
- [x] Customer management (full form) — #39 *(`CustomerService` — quota, central code allocation, archive-not-delete)*
- [x] Customer profile (purchases, paid, due, history) — #39 *(figures **ledger se** aati hain, sales se nahi — to summary neeche wali statement se foot karti hai)*
- [x] Customer credit sales (plan/permission based) — #40 *(NULL = no ceiling · 0 = **cash only (default)** · n = itna hi; gate **service mein** hai to har raasta usi limit se guzarta hai; plan ka `sales.credit_sales` bhi check hota hai)*
- [x] Customer ledger (Debit/Credit/Balance) — #41 *(append-only, koi `updated_at` nahi; running balance har line pe stamped; filtered statement **brought forward** carry karta hai)*
- [x] `CustomerLedgerService` — #183 *(shared `PartyLedgerService` pe — locked transaction, `recalculate()` ledger se rebuild karta hai)*
- [x] Global business-level customers (multi-branch) — #137 *(branch-scoped **nahi**: High Street pe udhaar, Retail Park pe payment — test isi ko pin karta hai)*
- [x] Customer status (Active/Blocked) — #105 *(blocked ≠ deleted ≠ hidden: record, statement aur balance waise ke waise; wajah record hoti hai; payment phir bhi li ja sakti hai)*

### Suppliers
- [x] Supplier management (full form) — #38 *(`SupplierService`; payment terms — blank matlab "koi agreed terms nahi", "abhi due" nahi)*
- [x] Supplier profile (purchases, paid, due, returns, history) — #38 *(customer profile ka mirror; over-payment **advance** ban jati hai)*
- [x] Supplier ledger (accounting format) — #42 *(wahi statement component — sirf heading badalti hai: "You owe" vs "Owes")*
- [x] `SupplierLedgerService` — #183 *(`PartyLedgerService` ka doosra bacha — **aik arithmetic, do heading**)*

---

## PHASE 6 — Purchases + Supplier Ledger
*(Spec ref: #35–37, #119, #183)*

- [x] Purchase management (full form) — #35 *(draft → order → receive; lines pe cost/discount/tax, aur batch-tracked products pe lot + expiry)*
- [x] Purchase auto stock-increase — #35 *(`InventoryService` se, **line ki apni cost** pe — catalogue ki purani cost pe nahi; service lines bill hoti hain magar shelf pe nahi jatin)*
- [x] Purchase status (Draft/Ordered/Received/Partial/Cancelled) — #36 *(order karne se kuch post nahi hota; `Partial` apni jagah aik state hai kyunke kam maal aana aam baat hai; partial cancel jo aaya wo rakhta hai)*
- [x] Purchase return (stock↓ + supplier ledger + report) — #37 *(alag document, negative purchase nahi; har line us purchase line ko point karti hai jise reverse kar rahi hai — **jo aaya us se zyada wapas nahi ja sakta**)*
- [x] **Purchase DB transaction flow** (validate→create→stock→ledger→payment→commit) — #119 *(bilkul isi tarteeb mein, aik `DB::transaction` mein; over-delivery refuse hone pe kuch bhi aadha apply nahi hota)*
- [x] `PurchaseService` — #183 *(+ `PurchaseReturnService`; dono khud koi stock ya balance nahi likhte — `InventoryService` aur `SupplierLedgerService` ko call karte hain)*
- [x] Purchase reports link — #54 *(purchases list pe headline figures: open orders, unpaid bills, is mahine ka received value + status/supplier/unpaid filters. Poori purchase report Phase 10 mein)*

---

## PHASE 7 — POS + Sales + Payments + Customer Ledger
*(Spec ref: #14–23, #46, #70, #89–91, #118, #122, #139, #143–148, #184)*

### POS Screen (⚡ main module — fast honi chahiye)
- [x] POS layout (Left categories / Center grid / Right cart) — #14, #122 *(cart **browser mein** rehta hai — har action instant, server sirf do sawal ke jawab deta hai)*
- [x] Product search (name/SKU/barcode/category/brand, instant) — #15 *(server-side + 150ms debounce; poora catalogue preload **nahi** — wo aik dafa ki sustii ko hamesha ki bana deta)*
- [x] Barcode scan → cart add — #15 *(Enter pe **pehle barcode** try hota hai phir top match; variant ka apna barcode wahi variant chunta hai)*
- [x] Category filtering — #148 *(parent pe uske children ke products bhi — "Drinks" dabane wala yehi expect karta hai)*
- [x] Product favourites — #147 *(`products.is_favourite` — per-shop, per-user nahi: har till aik jaisi khulni chahiye; grid inhi se bhara hota hai)*
- [x] Customer selection + quick add + Walk-in — #16, #146 *(quick-add mein **aik field** — counter pe khare shakhs se tax number nahi poocha jata; credit available sath dikhta hai)*
- [x] Cart operations (qty +/-, discount, tax, remove) — #14 *(narrow width pe lines **wrap** karti hain — kinare se bahar khiski line cashier theek nahi kar sakta)*
- [x] Hold / suspended sales — #20 *(kuch post nahi hota aur **invoice number kharch nahi hota**; resume cart wapas bhar deta hai)*
- [x] Keyboard shortcuts (F2/F4/F6/F8/F9/Esc) — #89 *(function keys is liye ke haath counter pe hote hain aur ye product ka naam type karne se nahi takraate)*
- [x] AJAX live UX (no full reload) — #90 *(search, scan, checkout, hold — sab JSON; screen kabhi reload nahi hoti)*
- [x] Loading states + double-submit prevention — #91 *(button disable **shaistagi** hai; asli ilaj per-cart **idempotency key** + unique index hai — wahi cart chahe jitni dafa aaye, aik hi sale)*

### Payments (`PaymentService`)
- [x] Default methods (Cash/Card/Bank/QR/Credit/Split) — #17 *(`config/pos.php` se — shop apna JazzCash/EasyPaisa bina deploy ke add karti hai; `credit` wo method hai jo paisa nahi leta, khaata charge karta hai)*
- [x] Custom payment methods (JazzCash, EasyPaisa...) — #17 *(env-driven list; unknown method service level pe refuse hota hai)*
- [x] QR payment (plan-based, image + reference) — #18 *(`qr` aik payment method hai jo reference (transaction id) leta hai; QR **image** dikhana Phase 11 ke settings ke saath aayega)*
- [x] Split payment (multi-method, must match total) — #19 *(`sale_payments` aik **table** hai, column nahi — har method alag reconcile hota hai; kam para hissa khaate pe jata hai, aur walk-in udhaar nahi le sakta)*

### Sales (`SaleService` — #184) ✅ *service mukammal — + `CashSessionService`; dono khud stock ya balance nahi likhte, `InventoryService` aur `CustomerLedgerService` ko call karte hain*
- [x] Sales listing + filters + actions — #21 *(`sales.view` = apni sales, `sales.view_all` = sab ki — narrowing **query mein**, view mein rows chhupa kar nahi; held sales book mein nahi aatin; cards usi filtered query se ginte hain)*
- [x] Configurable invoice number format — #22 *(`{PREFIX}-{YYYY}{MM}-{SEQ:5}` tokens; scope business/branch/monthly; **held sale invoice number kharch nahi karti** — gap wo cheez hai jo inspector poochta hai)*
- [x] Invoice/receipt (80mm/58mm/A4, customizable) — #23 *(teenon widths ke liye **aik template** — farq jagah ka hai, matn ka nahi; app layout se bahar; rounding alag line pe taake kaghaz pe jama ho)*
- [x] **POS Sale DB transaction flow (16 steps, rollback on error)** — #118 *(solah steps `SaleService::complete()` ke docblock mein likhe hain; test sabit karta hai ke step 14 fail hone pe 9–13 bhi roll back hote hain)*
- [x] Concurrency / stock race protection (locking) — #70 *(stock **transaction ke andar** `InventoryService` se jata hai jo shelf row pe `lockForUpdate` karta hai — wahi lock poori protection hai)*
- [x] Print UX (Print/New Sale/View, auto-print option) — #145 *(receipt **apne tab** mein khulti hai to till navigate away nahi hoti; auto-print **opt-in**)*
- [x] Invoice reprint (+ audit) — #143 *(pehli print reprint nahi hai; uske baad har copy pe **"REPRINT · copy N"** aur audit log — "hamein nahi pata kitni copies hain" koi jawab nahi)*
- [x] Custom receipt footer — #144 *(`config/pos.php` se; Phase 11 mein per-business settings mein jayega)*

### Cash Register
- [x] POS session link (open/close register) — #139 *(aik counter pe aik hi open session — MySQL mein partial unique index nahi hota, is liye check transaction mein lock ke saath)*
- [x] Cash management (opening/expected/actual/difference) — #46 *(cash sales session mein live jama hoti hain; `difference` close pe **stamp** hoti hai — agle hafte ki void pichle hafte ka shortfall na badle; `recalculate()` payments se rebuild karta hai)*

---

## PHASE 8 — Returns + Stock Adjustments + Transfers
*(Spec ref: #53, #29, #31, #32, #140)*

- [x] Sales return (full/partial, qty ≤ sold) — #53
- [x] Return effects (stock↑ + customer balance + payment adj + reports + profit) — #53
- [x] Returns permission gate — #140
- [x] Stock adjustment finalize + audit — #31 *(Phase 4 mein bana — `InventoryService::adjust()` + audit log)*
- [x] Stock transfer finalize (receive confirm) — #32 *(Phase 4 mein bana — send → receive-with-count)*

---

## PHASE 9 — Expenses + Profit & Loss
*(Spec ref: #43–45, #135, #183)*

- [x] Expense categories (dynamic) — #43 *(per-tenant, provisioning pe 5 default headings)*
- [x] Expense management (full form + attachment) — #43 *(receipt photo/PDF, cash till se nikalta hai)*
- [x] Other income recording — #44 *(gross profit line ke **neeche**, alag table)*
- [x] Profit & Loss (Revenue − COGS = Gross; − Expenses + Income = Net) — #45
- [x] Cost method: Weighted Average Cost — #135 *(statement khud apna method likhta hai)*
- [x] `ProfitService` (consistent cost method) — #183 *(har profit figure ka **waahid** source)*

---

## PHASE 10 — Reports
*(Spec ref: #54–56, #134, #183)*

- [x] Sales reports (daily/monthly/yearly/product/category/customer/employee/branch/POS/payment) — #54 *(8 reports; daily/monthly/yearly aik `interval` filter se)*
- [x] Profit reports (daily/product/category/branch/monthly/P&L) — #54 *(4 + Phase 9 ka P&L statement)*
- [x] Inventory reports (stock/value/low/out/movement/adjustment/expiry/transfer) — #54 *(8)*
- [x] Purchase reports (purchase/supplier/return/outstanding) — #54 *(4)*
- [x] Customer reports (purchases/outstanding/ledger) — #54 *(3)*
- [x] Expense reports (daily/category/branch/monthly) — #54 *(3)*
- [x] Report filters (date presets + branch/employee/customer/etc.) — #55 *(har report khud batati hai kaun se filters uske liye maayne rakhte hain)*
- [x] Export system (PDF/Excel/CSV/Print, plan-based) — #56 *(CSV muft, .xlsx **khud likha**, PDF dompdf)*
- [x] Reports accuracy (cancelled excluded, returns adjusted) — #134 *(aik jagah, `salesQuery()` mein)*
- [x] `ReportService` (optimized queries) — #183 *(sab kuch SQL aggregate)*

---

## PHASE 11 — Settings + Receipt + QR + Barcode
*(Spec ref: #57–60, #76–77, #110–111, #154–160)*

- [x] Business settings — General — #57 *(business record khud: naam, pata, logo, timezone)*
- [x] Business settings — Sales — #57 *(invoice numbering, cash rounding, hold expiry, discounts)*
- [x] Business settings — Inventory — #57
- [x] Business settings — Receipt — #57 *(header, footer, tax number, logo, tax breakdown, **QR**, auto-print)*
- [x] Business settings — Payment (custom methods) — #57 *(methods + kaun se daraz mein jate hain)*
- [x] Currency management — #58 *(code, symbol, position, decimals, separators)*
- [x] Taxes (multiple rates, product/invoice level) — #59 *(`tax_rates` — naam wali list; product number snapshot karta hai)*
- [x] Discounts (fixed/percentage, product/invoice, permission) — #60 *(3 layers: plan → dukaan ki chhat → shakhs ka cap)*
- [x] Timezone (store UTC, display local) — #154, #153
- [x] Date formats + currency formatting + decimals — #155, #156, #157 *(`Format` + `money()`/`qty()`/`localDateTime()` helpers)*
- [x] Shop ka apna payment QR image (wallet/bank) — #57 *(upload; till pe full-screen)*
- [x] SaaS branding (dynamic app name/logo/favicon) — #111 *(`PlatformSettingRegistry`; logo upload drawn mark ko replace karta hai)*
- [x] Super Admin settings (SaaS name, trial, registration toggle, maintenance...) — #110
- [x] In-app notifications (bell) — #76 *(alerts = conditions, announcements = messages)*
- [x] Super Admin announcements — #77 *(dates ke saath; per-person dismissal)*
- [x] Maintenance mode — #160 *(`/admin` khula rehta hai — `artisan down` nahi)*

---

## PHASE 12 — Public Website + Pricing + Trial Registration
*(Spec ref: #106–109, #172)*

- [x] Public marketing website (Home/Features/POS/Inventory/Reports/Pricing/FAQ/Contact) — #106 *(alfaz `MarketingContent` mein, shakl aik template mein)*
- [x] Home page (hero + sections + CTA + footer) — #107
- [x] Pricing page (auto from active public plans) — #108 *(cycle toggle + comparison, sab `plans` se)*
- [x] Registration (public signup + trial assign + admin ON/OFF) — #109 *(wohi rasta jo admin console ka hai)*

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
- [x] Secure file uploads (MIME/size validate, random names) — #101 *(product images: `config/uploads.php` + content-based MIME check + decode check + random names + dimension cap; SVG allowed nahi)*
- [ ] Error handling (friendly messages, no technical leak in prod) — #93
- [ ] Logging (critical exceptions + failed financial txns) — #94
- [ ] Financial record integrity (edit/void/return, not delete) — #133, #198

### Testing
- [x] Feature tests: Tenant Isolation — #116, #117 *(`TenantIsolationTest` — 16 tests: scoped reads/aggregates, cross-tenant `find()` → null, creating-hook force, mass-assignment, escape hatches, HTTP isolation)*
- [x] Feature tests: Login + Permissions *(`Auth/AuthenticationTest` 22 + `Auth/PasswordResetTest` 11 + `Organization/RolePermissionTest` 31 — dono guards, cross-guard denial, throttle, enumeration safety, aur poora 3-layer permission check)* — #116
- [x] Feature tests: Plan Limits, Plan Features — #116 *(`Subscription/PlanLimitTest` 29 + `Subscription/PlanFeatureTest` 21 — resolution order, unlimited, enforcement, cache invalidation, `CheckFeature` middleware)*
- [x] Feature tests: POS Sale — #116 *(`Sales/SaleEngineTest` 28 — solah steps ka rollback, race protection, split payments, credit limit, void)* · [x] **Stock Update** *(`Inventory/InventoryTest` 31 — ledger vs cache, negative stock, costing, per-branch isolation)*
- [x] Feature tests: Purchase, Returns — #116 *(`Purchasing/PurchaseTest` 26 + `Purchasing/PurchaseAccessTest` 12 — receipt ka transaction flow, partial/double-count, over-delivery, returns ki limit, paanchon authorities)*
- [x] Feature tests: Customer/Supplier Balance — #116 *(`Parties/PartyLedgerTest` 26 + `Parties/PartyAccessTest` 15 — dono taraf ka debit/credit, credit limits, blocking, statement ka foot karna, drift detection)*
- [x] Feature tests: Subscription Expiry — #116 *(`Subscription/SubscriptionExpiryTest` 26 + `Subscription/SubscriptionGateTest` 22 — trial/grace/expiry, lock vs read-only vs pos-off, stale status column dono taraf se)*
- [x] ⚠️ Tenant leak test (Business A → Business B URL = 403/404) — #117 *(cross-tenant PK `find()` → null; dashboard HTTP test dono tenants pe; request input se tenant switch block)*

> **Ab tak ka test status:** `php artisan test` → **502 tests / 1,607 assertions PASS** (MySQL `pos_saas_test`) — Auth 22 · PasswordReset 11 · TenantIsolation 20 · PlanLimit 29 · PlanFeature 21 · SubscriptionExpiry 26 · SubscriptionGate 22 · RolePermission 31 · BranchAccess 19 · Employee 20 · Catalog 23 · Product 24 · Inventory 31 · StockTransfer 22 · BatchExpiry 17 · CatalogTools 22 · PartyLedger 26 · PartyAccess 15 · Purchase 26 · PurchaseAccess 12 · SaleEngine 28 · PosScreen 19 · SalesBook 15 · Unit 1. Har naye phase ke saath yahan tests barhte rahenge.

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
