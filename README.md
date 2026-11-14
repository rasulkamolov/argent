# OXFORD — Filiallar moliyasi (PHP + SQLite)

Ko'p filialli kirim-chiqim, kassa, hisobot va analitika tizimi. Hostingda: `oxfordlc.uz/kirimchiqim/argent`.
Talab: PHP 7.4+ (PDO SQLite), `mbstring` — odatiy Hostinger rejalarida bor.

## Rollar
| Rol | Nimani ko'radi / qiladi |
|---|---|
| **Administrator** | Barcha filiallar; filial, foydalanuvchi, xodim, kategoriya, jurnal, import, zaxira; filiallar taqqoslash |
| **Menejer** | O'z filiali: operatsiyalar + hisobotlar + analitika + kassa kitobi (tahrirlash/o'chirish mumkin) |
| **Kassir** | Faqat o'z filiali: kirim-chiqim kiritish, kassa kitobi, kassani sanash (tahrirlash/o'chirish — filial sozlamasiga ko'ra) |

Kassir boshqa filial ma'lumotini **serverning o'zida** ko'ra olmaydi (har bir so'rov filial bo'yicha tekshiriladi).

## Eski (Oxford) ma'lumotlarni qayta tiklash
Agar hostingdagi bazani yangi fayl bilan almashtirib yuborgan bo'lsangiz:
1. Eski baza faylini toping: o'zingizdagi nusxa (`harvards_finance.sqlite` / `db (1).db`) yoki Hostinger **hPanel → Backups** dan tiklangan nusxa.
2. Saytga admin bo'lib kiring → **Admin → Import** → eski faylni tanlang → filial: **OXFORD Bo'zqal'a** → «Tekshirish» → «Import qilish».
3. Tizim bazadagi mavjud yozuvlarni **takrorlamaydi** (sana+summa+usul+izoh bo'yicha solishtiradi), faqat yetishmaydiganlarini qo'shadi. Yangibozor ma'lumotlariga tegmaydi. Qayta-qayta import qilish xavfsiz.

## Hostingga yuklash — MUHIM
1. **Ikki xil yo'l bor. Birini tanlang:**
   - **A) Hosting bazasi yangi bo'lsa yoki eski ma'lumot kerak bo'lmasa:** hamma fayllarni (shu jumladan `harvards_finance.sqlite`) yuklang.
   - **B) Hostingdagi bazada yangi yozuvlar bor bo'lsa (tavsiya):** `harvards_finance.sqlite` ni **yuklamang** — hostingdagisi qolsin. Boshqa hamma fayl va papkalarni yuklang. Birinchi kirishda baza **avtomatik yangilanadi** (mavjud ma'lumot «OXFORD Bo'zqal'a» filialiga o'tadi). So'ng Admin → Import orqali Yangibozor `.sql` faylini yuklang.
2. `.htaccess` faylini ham yuklang — u bazani va `*.php` yordamchi fayllarni internetdan yopadi.
3. Papka yozishga ochiq bo'lishi kerak (PHP `harvards_finance.sqlite` va `data/` ga yozadi).
4. Kirish: `admin` / `admin0007` → **darhol parolni o'zgartiring** (Profil). Eski parollar birinchi kirishda avtomatik shifrlanadi.
5. Admin → Filiallar → Tahrirlash: «Kassa qoldig'ini yuritish» ni yoqib, kassadagi haqiqiy pulni kiriting (shundan keyingina «Kassada» qoldig'i ko'rsatiladi).

## To'lov isboti (Click / Bank): skrinshot yoki istalgan hujjat
- Kassir «Click» yoki «Bank» tanlasa, fayl maydoni chiqadi: **tashlash**, **tanlash/kameradan olish** yoki rasmni **Ctrl+V** bilan qo'yish mumkin. Katta rasmlar avtomatik kichraytiriladi.
- Ruxsat etilgan fayllar (maks. 15 MB): rasm (JPG, PNG, WEBP, GIF), **PDF**, Word (doc/docx), Excel (xls/xlsx), ODT, RTF, TXT/CSV. Rasm va PDF brauzerda ochiladi, boshqa hujjatlar yuklab olinadi.
- Xavfsizlik: fayl turi nomiga emas, **ichiga qarab** aniqlanadi; `.php`, `.exe`, `.html`, `.svg` va boshqa ijro etiladigan fayllar qabul qilinmaydi, fayl har doim betaraf kengaytma bilan saqlanadi.
- Kassirlar uchun majburiy (Admin → Sozlamalar yoki har bir filial kartasida o'chirish mumkin). Admin/menejer uchun majburiy emas.
- Fayllar `data/proofs/` da saqlanadi va faqat tizimga kirgan, **o'sha filialga huquqi bor** foydalanuvchiga ko'rsatiladi. Ro'yxatda «Skrinshot/PDF/Fayl» belgisi; yo'qlari «Isbotsiz» deb belgilanadi. Hisobotda «Isbotsiz» filtri va CSV da «Isbot» ustuni bor.
- `data/` papkasini ham (FTP orqali) zaxiralab turing — `Yuklab olish (.sqlite)` faqat bazani oladi.

## LC-UP monitoring va «Solishtirish» (administrator uchun)

LC-UP ni **faqat o'qiydi** va har bir haqiqiy amalni **bitta hodisa** qilib ko'rsatadi: jurnal (Xodimlar faoliyati), o'chirilgan to'lovlar arxivi,
to'lovlar ro'yxati (snapshot) va talaba «Tarix»i birlashtiriladi — «Manbalar: jurnal, arxiv, Tarix» va ✅ Tasdiqlangan / ⚠️ Taxminiy.
🔴 hodisa uchun bitta Telegram xabar; keyin manba tuzatsa (xodim/filial/summa) — yangi qizil xabar emas, kunlik xulosada «🛠 Tuzatildi».

**Aniqlik qoidalari**
- **Kim qildi:** faqat jurnal va talaba «Tarix»i (Tarixda «24,617 -> 64,617» kabi eski → yangi summa bor). Arxiv va ro'yxatlardagi «Hodim» ustuni — to'lovni
  **yaratgan** xodim, alohida «Yaratgan: …» deb ko'rsatiladi, hech qachon o'chirgan/tahrirlagan sifatida emas. Faqat snapshotdan topilgan amal — «Noma'lum (aniqlanmoqda)»,
  so'ng talabaning Tarixidan (±2 daqiqa) haqiqiy xodim olinadi (10 daqiqagacha Telegram shuni kutadi). Hech qachon taxmin qilinmaydi.
- **Qaysi filial:** guruh ID → filial xaritasi (har filialning «Guruhlar» ro'yxatidan, har kecha va noma'lum guruh chiqqanda yangilanadi), keyin talaba
  (filialga bog'liq ro'yxatlar). Markaziy foydalanuvchi («Admin», Center Admin) jurnali ikkala filialda bir xil ko'rinadi — LC-UP qator ID si (`xf-row-NNNN`)
  bo'yicha **bitta** marta saqlanadi. Talabasiz qator (masalan, aniqlanmagan «minus to'lov tahrirlandi») — filial «aniqlanmoqda».
- **Bitta amal = bitta hodisa:** bir talaba + guruh + summa, ±3 daqiqa. To'lov o'chirilib, shu daqiqada **shu talabaning** boshqa to'loviga qo'shilsa —
  🟡 «To'lov ko'chirildi» (jami kamaysa 🔴); **boshqa talabaga** qo'shilsa — 🔴 «Boshqa talabaga ko'chirildi».
- **Balans tahrirlandi** (qarzni to'lovsiz o'zgartiradi): ≤10 so'm — 🟡 «Yaxlitlash», katta — 🔴. Talaba nomi bo'yicha qarzdorlar ro'yxatidan topiladi.
- Qarz hech qanday to'lov/tahrir/Tarix yozuvisiz kamaysa (kutish 20 daqiqa) — 🔴 «Qarz sababsiz kamaydi», xodim «Noma'lum».
- **Rahbar akkauntlari** (Admin → LC-UP; standart: «Admin»): amallari yoziladi, lekin 🟡 «👑 Rahbar akkaunti» bo'lib alohida filtrda ko'rinadi. Belgilangan
  vaqtdan tashqari (standart 08:00–22:00) ishlatilsa — har bir shunday davr uchun **bitta** Telegram xabar.

**Eski ma'lumotni tuzatish (bir marta, yangilagandan keyin):** asl yozuvlar o'zgarmaydi; tuzatishlar alohida (o'chirib/o'zgartirib bo'lmaydigan) jadvalga yoziladi
va hodisada «tuzatilgan» belgisi bilan ko'rinadi (asl qiymat — «Asl yozuvlar»ni bosing).
- Admin → LC-UP → **«Qayta ishlash»**, yoki serverda: `php tools/lcup_reprocess.php` (oflayn, LC-UP ga so'rovsiz).
- Noma'lum xodimlar va aniqlanmagan «minus to'lov tahrirlandi» qatorlarini Tarixdan to'ldirish: `php tools/lcup_reprocess.php --tarix --max-minutes=30`
  (cron ham buni har daqiqada bosqichma-bosqich qiladi). Tarix uchun Telegram xabari yuborilmaydi.

**Aniqlik tekshiruvi** (`aniqlik.php`): har kuni (standart 05:00) so'nggi 7 kundagi 10 ta tasodifiy hodisa talabaning Tarixidan qayta o'qiladi — xodim, filial,
summa to'g'rimi, «10/10 ✅». 100% dan past bo'lsa — Telegram; noto'g'risi Tarix bo'yicha avtomatik tuzatiladi. «Hozir tekshirish» tugmasi bor.

**Qarzdorlik o'zgarishi** (`qarzdorlik.php`): har filial uchun Moliya → Qarzdorlar (filtrsiz) 00:05 va 23:55 da snapshot (o'zgartirib bo'lmaydi). Kun
boshidan oxirigacha «Jami» nega o'zgargani: to'lov, to'lov o'chirildi/tahrirlandi, minus to'lov tahriri, chegirma, muzlatish, balans tahriri, aktivlashtirish,
oylik to'lov (oyning 1-kuni — «Oylik to'lov hisoblandi»), ro'yxatdan chiqdi, yangi qarzdor va qizil «❓ Sababsiz». Har talaba bo'yicha tegishli yozuvlar bilan.
Qisqa varianti 21:00 xulosada: «…: qarz −37 755 478 → −37 120 000 (−635 478): to'lov −900 000, …, sababsiz 0».

**O'rnatish**
1. Fayllarni yuklang (`harvards_finance.sqlite` ni mavjud bazaning ustiga YOZMANG). Baza o'zi v10 ga o'tadi.
2. Admin → **Sozlamalar → LC-UP monitoring**: LC-UP telefon va parol, Telegram bot token va chat ID → **Saqlash** (hammasi `argent_private/config.php` ga,
   public_html dan tashqarida yoziladi; config.php ni qo'lda yaratish kerak emas).
3. Admin → LC-UP → «Filiallarni moslash»: Oxford LC = `414` ↔ Yangibozor Markaz; Oxford Bo'zqal'a ↔ OXFORD Bo'zqal'a. «Rahbar akkauntlari» ni tekshiring.
4. **Ulanishni tekshirish** → xato bo'lsa **Diagnostika** ZIP. **Test xabar** — Telegram.
5. Cron (har daqiqa, faqat buyruq satri): Hostinger → Cron Jobs → `/usr/bin/php /home/u180105715/domains/oxfordlc.uz/public_html/kirimchiqim/argent/cron.php`.
6. Bir marta: Admin → LC-UP → **«Qayta ishlash»** (yoki `php tools/lcup_reprocess.php --tarix`).

**Faqat-o'qish va oq ro'yxat** (`lcup_policy.php`, har doim yoqilgan): GET so'rovlar va faqat login; filial tanlagich (`filial_id`, `renderFilials`, `change_filial`);
xodimlar faoliyati (`openHistory`, `setPreset`, `setCat`, `loadMore`, `$refresh`); o'quvchi «To'lovlar» tabi (`studentTolovlarOchilsin`); o'quvchi «Tarix»i —
**faqat** `students.history-livewire`, **faqat** yuklash. Boshqa nom so'ralsa yuborilmaydi (REFUSED, `lcup_reqlog`).

## Do'konPro (do'kon) nazorati va Argent bilan solishtirish (administrator uchun)

Do'konPro (`https://oxfordlc.uz/shop/`, SQLite `bookshop.db`) bazasini **faqat o'qish** rejimida ochadi (`SQLITE_OPEN_READONLY` + `PRAGMA query_only`; yozishga urinish rad etiladi — Admin → Do'kon da isbot ko'rinadi),
do'kon fayllariga tegmaydi. Do'konda audit jurnali yo'q va qatorlar jismonan o'chiriladi, shuning uchun har daqiqada do'kon holati Argent ichidagi **snapshot** bilan solishtiriladi
va farq `shop_events` ga (o'chirib/o'zgartirib bo'lmaydigan, faqat «ko'rildi» + izoh) yoziladi. Birinchi ishga tushishda mavjud ma'lumotlar «asos» sifatida saqlanadi (ogohlantirishsiz).

**O'rnatish**
1. Fayllarni yuklang (`harvards_finance.sqlite` ni YUKLAMANG).
2. Admin → **Do'kon** → «Bazaning to'liq yo'li»: odatda `/home/u180105715/domains/oxfordlc.uz/public_html/shop/db/bookshop.db` (do'konning `includes/config.php` dagi `DB_PATH`). Bo'sh qoldirsangiz `public_html/shop/db/bookshop.db` va boshqa odatiy joylar qidiriladi.
   Sahifada: ulanish ✓, «faqat o'qish» ✓, jadvallar tuzilishi ✓ ko'rinishi kerak.
3. «Filiallarni moslash»: **Asosiy filial ↔ Yangibozor Markaz**, **Oxford Bozqala ↔ OXFORD Bo'zqal'a** (oldindan to'ldirilgan; nom bo'yicha topiladi).
4. «Hozir tekshirish» → xatosiz tugashi kerak. **Cron o'zgarmaydi**: `cron.php` LC-UP dan keyin do'konni ham tekshiradi (javobda `shop=ok shop_new_events=N`). Telegram token/chat va 21:00 / dushanba vaqti LC-UP bilan umumiy.
5. Do'kon bo'limlari: **Do'kon nazorat** (hodisalar), **Solishtirish → Do'kon** (kunlik/oylik), **Inventarizatsiya**, **Haftalik hisobot**. Hammasi faqat administratorga.

**bookshop.db internetdan yuklanadimi?** Tekshirildi (faqat `HEAD`, fayl yuklab olinmadi): `/shop/db/bookshop.db` → 403, `/shop/db/` → 403, `/shop/db/bookshop.db-wal` → 403, `/shop/includes/config.php` → 403, `/shop/bookshop.db` → 404 — **hozir himoyalangan**.
Lekin himoya faqat server qoidasiga tayanadi. Yanada xavfsiz: bazani `public_html` dan tashqariga (`…/domains/oxfordlc.uz/shop_private/bookshop.db`) ko'chiring, do'konning `DB_PATH` ini yangilang va Admin → Do'kon dagi yo'lni o'zgartiring (Argent faylni HTTP orqali emas, fayl tizimi orqali o'qiydi). Tekshirish tugmasi: «Internetdan ochilishini tekshirish».

**Kunlik solishtirish** (filial × kategoriya: Kitob / qolgan do'kon; kun 00:00 da almashadi)
- Do'konPro tomoni: kassaga tushgan pul = chekning to'langan qismi (`initial_paid`; qarz cheklarida boshlang'ich to'lov naqd deb olinadi) + **naqd qarz to'lovlari** − **naqd qaytarilgan pul**. Aralash savatdagi pul kategoriyalarga tovar qiymati ulushi bo'yicha bo'linadi. To'lanmagan (qarz) qism kutilmaydi.
- Naqd ↔ Argent **Naqd**; Do'kon **Karta + O'tkazma** ↔ Argent **Click + Bank (birga)**.
- Argent tomoni: kategoriyasi `shop` / `kitob` bo'lgan kirimlar, **yoki** kategoriyasi «To'lov», LC-UP talaba havolasi yo'q va izohida shop/do'kon/kitob/book bor kirimlar (kichik-katta harf farqsiz) — bular 🟡 «Noto'g'ri kategoriya» deb belgilanadi. Kategoriya nomlari va kalit so'zlar sozlanadi.
- Holatlar: ✅ mos · 🔴 do'konda ko'p (Argentga to'liq kiritilmagan) · 🔴 savdo bor, Argentda yozuv yo'q · 🔴 «Kitob sotuvi Do'konPro'da yo'q» (Argentda kitob kirimi bor, filialda Kitob tovari yo'q) · 🟠 Argentda ko'p (sotuv do'konga kiritilmagan) · 🟡 to'lov usuli farqi · 🟡 keyingi kunda kiritilgan (**1+ kun**, necha kun kechikkani ko'rsatiladi) · 🟡 noto'g'ri kategoriya.
- 21:00 xulosaga qo'shiladi; 🔴 holatlar bugun uchun kun yakuni vaqtida (21:00), kechagi kun uchun ertalab (10:00) hodisa + Telegram bo'ladi (xodimlar ertasiga kiritishi mumkin).

**O'zgarishlar monitoringi (har daqiqa)**
🔴 darhol Telegram: o'chirilgan chek (va chek raqamidagi bo'shliq); yaratilgach tovarlar/summa/to'lov turi/sotuvchi/vaqt o'zgargan chek; **har qanday qaytarish** (sotuvdan >30 daq. keyin, boshqa kishi qaytargan, naqd pul qaytarilgan — belgilar bilan); qarz: mijozsiz, telefonsiz, yangi mijoz, xodimga; qarz to'lovsiz kamaygan yoki yopilgan; o'chirilgan/o'zgargan qarz to'lovi;
qo'lda «Tuzatish», minusli «Inventarizatsiya», «Hisobdan chiqarish» (tovar, soni, tannarx va sotuv qiymati, xodim, izoh — izohsiz holat alohida belgilanadi); ombor qoldig'i harakatsiz o'zgargan; sotuv narxi tushirilgan / tushirilib 24 soatda qaytarilgan / tovar odatdagi narxdan past sotilgan; kirim tahrirlangan/o'chirilgan/bekor qilingan; kirim narxi oldingi kirimlardan >30% baland;
«chiqdi» ga 24 soatda boshqa filialda «kirdi» topilmasa; do'kon xarajati; **Administrator logini bilan har bir sotuv**; bloklangan xodim sotuvi (faqat Argent blokni aniqlagandan keyingilari); ish vaqtidan (08:00–21:00) tashqari sotuv; inventarizatsiya tekshiruvidagi kamomad.
🟡 hisobotda: ombor 0/minus bo'lib sotilgan, narx oshgan, kichik qarz, mijoz/izoh tahrirlari, plus inventarizatsiya, yangi/blokdan chiqqan xodim. Barcha chegaralar Admin → Do'kon da.

**Inventarizatsiya tekshiruvi** (faqat administrator): har filialdan 5–10 tovar (barcha Kitob tovarlari va eng ko'p sotiladigan 3 tasi doim, qolgani tasodifiy); **ko'r sanash** — tizimdagi qoldiq sahifada ham, HTML manbada ham sanalgunga qadar yo'q; sonni kiritib saqlagach shu paytdagi qoldiq bilan solishtiriladi: kamomad dona, tannarx va sotuv narxi bo'yicha; tarix saqlanadi; kamomad 🔴 hodisa bo'ladi.
**Haftalik hisobot** (dushanba, LC-UP hisobotiga qo'shiladi va «Haftalik hisobot» sahifasida): filial va xodim bo'yicha kamomad (hisobdan chiqarish, minus tuzatish, harakatsiz o'zgarish, qaytarish, o'chirilgan cheklar) va sotuvchilar: cheklar soni, o'rtacha chek, naqd ulushi, qaytarish, o'chirish, qarz, tuzatish, smena kunlarida filial savdosi o'rtachaga nisbatan; boshqalardan sezilarli farq qilganlar 🔺.

**Ma'lum cheklar** (do'kon bazasida yo'q narsalar): kim o'chirgani/narxni o'zgartirgani saqlanmaydi (sotuvchi ko'rsatiladi); «Tuzatish / Hisobdan chiqarish / Inventarizatsiya / Ko'chirish» harakat turlarining bazadagi nomi hozircha ma'lum emas (hozirgi ma'lumotda faqat sale/purchase/purchase_cancel/initial) — nomi bo'yicha taniladi, tanilmagan tur 🔴 «Noma'lum ombor harakati» sifatida turi bilan ko'rsatiladi.
Bitta chekda aralash to'lov turi yo'q (`payment_type` bitta qiymat: cash/card/debt), shuning uchun alohida ishlov kerak emas; qarz cheklarida boshlang'ich to'lov (`initial_paid`) naqd deb olinadi.
**Testlar** (haqiqiy ma'lumotsiz — sintetik «Test …» ma'lumotlari): `tests/run_shop_tests.sh` (mantiq 115 ta + veb-interfeys 64 ta tekshiruv). Haqiqiy `bookshop.db` repoga, kodga yoki testlarga kiritilmagan.

## Zaxira
- Admin kirganda har kuni **avtomatik** zaxira nusxa olinadi (`data/backups/`, oxirgi 30 ta).
- Har bir filial kartasida ⬇ tugmasi: faqat shu filial ma'lumotini alohida `.sqlite` fayl qilib yuklab oladi.

## Fayllar
- `index.php` kunlik hisob · `filiallar.php` filiallar taqqoslash · `hisobot.php`, `analitika.php`, `kassa.php` hisobotlar
- `admin.php` + `admin_parts/` boshqaruv · `import.php` + `import_lib.php` SQL import · `backup.php` zaxira
- `proofs.php`, `proof.php` isbot fayllari · `db.php` (baza va migratsiya) · `auth.php` (rollar, filial doirasi, CSRF) · `process.php` (barcha amallar)
- LC-UP: `lcup_sync.php` (o'qish), `lcup_incidents.php` (hodisalar, tuzatishlar, ko'chirishlar), `lcup_edits.php` (Tarix), `lcup_debtrep.php` (qarzdorlik), `lcup_accuracy.php`; sahifalar `lcup.php`, `solishtirish.php`, `qarzdorlik.php`, `aniqlik.php`; `tools/lcup_reprocess.php`
- Do'kon moduli: `shop_db.php` (faqat-o'qish adapteri — do'kon jadvallarini bilgan yagona fayl), `shop_lib.php`, `shop_sync.php`, `shop_recon.php`, `shop_report.php`, `shop_inv.php`; sahifalar `shop.php`, `shop_view_recon.php`, `inventory_check.php`; `tests/` — sinovlar (`php tests/lcup_test.php && php tests/lcup_ui_test.php`, `bash tests/run_shop_tests.sh`)
- `tools/import_cli.php` — buyruq satridan import: `php tools/import_cli.php dump.sql "Filial" --create-branch`
