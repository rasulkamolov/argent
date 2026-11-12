# OXFORD — Filiallar moliyasi (PHP + SQLite)

Ko'p filialli kirim-chiqim, kassa, hisobot va analitika tizimi. Hostingda: `oxfordlc.uz/argent`.
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

LC-UP dagi to'lovlarni o'qiydi va har bir to'lovga oid o'zgarishni o'chirib bo'lmaydigan «LC-UP nazorat» ro'yxatiga yozadi.
Qizil (🔴) hodisalar darhol Telegram'ga ketadi; sariq (🟡) hodisalar kunlik xulosada; kunda 21:00 da xulosa, dushanba kuni haftalik hisobot yuboriladi.

**O'rnatish (config.php ni qo'lda yaratish shart emas)**
1. Fayllarni yuklang (`harvards_finance.sqlite` ni mavjud bazaning ustiga YOZMANG).
2. Admin → **Sozlamalar → LC-UP monitoring**: LC-UP telefon va parol, Telegram bot token va chat ID, «Yangi token yaratish» (CRON_TOKEN), ixtiyoriy webhook siri → **Saqlash**.
   Hammasi `…/domains/oxfordlc.uz/argent_private/config.php` ga (public_html dan tashqarida, huquq 600) yoziladi; bazaga emas.
   Parol va tokenlar qayta ko'rsatilmaydi; bo'sh maydon eski qiymatni saqlaydi. Har o'zgarish jurnalga (maydon nomi) yoziladi va Telegram'ga xabar ketadi.
3. Admin → LC-UP → «Xavfsizlik» → **Bazani xavfsiz joyga ko'chirish**.
4. LC-UP da monitoring uchun **alohida xodim akkaunti** oching va uning nomini «Monitorchi akkaunt nomi» ga yozing (faoliyat ro'yxatida o'tkazib yuboriladi).
5. Admin → LC-UP → «Filiallarni moslash»: Oxford LC = `414` ↔ Yangibozor Markaz; Oxford Bo'zqal'a ↔ OXFORD Bo'zqal'a (ID bo'sh qolsa nom bo'yicha topiladi).
6. **Ulanishni tekshirish** (LC-UP ga kirib ikkala filialni, xodimlarni va o'quvchi tabini sinaydi) → xato bo'lsa **Diagnostika** ZIP ni yuboring. **Test xabar** — Telegram sinovi.
7. Cron (har daqiqa): Hostinger → Cron Jobs → `/usr/bin/php /home/uXXXX/domains/oxfordlc.uz/public_html/argent/cron.php`, yoki cron-job.org: Sozlamalardagi «Cron havolasi» (nusxa tugmasi bor).

**Faqat-o'qish va oq ro'yxat**: monitoring faqat GET so'rovlarini va `lcup_policy.php` dagi ro'yxatni yuboradi: login; filial tanlagich (`filial_id`, `renderFilials`, `change_filial`);
`openHistory`, `setPreset`, `setCat`, `loadMore`, `$refresh` (xodimlar faoliyati); o'quvchi «To'lovlar» tabi (`studentTolovlarOchilsin`; `edit/delete/sms/...` nomli komponentlarga hech qachon yuborilmaydi);
va o'quvchi «Tarix» tabi: **faqat** `students.history-livewire` komponenti, **faqat** yuklash/ko'rsatish (`studentHistoryOchilsin`, `$refresh`, `render`, `init`, `load`, `loadMore`).
Boshqa usul nomi so'ralsa yuborilmaydi va jurnalga REFUSED deb yoziladi; `students.edit-livewire`, to'lov qo'shish/tahrirlash/o'chirish va to'lovni tiklash (`refresh`) kodda taqiqlangan.
Har so'rov (va rad etilgani/o'tkazib yuborilgani) `lcup_reqlog` ga yoziladi (Admin → LC-UP da ko'rinadi). «Tarix» uchun Livewire Admin → LC-UP da bitta tugma bilan o'chiriladi.

**«Minus to'lov tahrirlandi» → talaba**: jurnalda talaba ham, summa ham yo'q, shuning uchun talaba sahifasining «Tarix» qatorlari (guruh, YANGI summa, xodim, vaqt) o'qiladi. Nomzodlar: qarzi o'zgargan talabalar, shu xodim ±15 daqiqa ishlagan talabalar, shu vaqtda to'lovi yaratilgan/o'zgargan talabalar (vaqtga eng yaqini birinchi).
Xodim va vaqt (±1 daqiqa) mos kelsa, yozuv talabaga biriktiriladi va «eski → yangi» ko'rsatiladi (eski summa saqlangan manfiy yozuvlar snapshotidan). Topilmasa «aniqlanmoqda»: har ishga tushishda yana 20 faol talaba (avval qarzdorlar) tekshiriladi; tunda barcha talabalar tarixi saqlanadi.
Summa moduli kamaysa 🔴 «Oylik to'lov kamaytirildi», oshsa 🟡; 24 soat aniqlanmasa 🔴 «Minus to'lov tahriri aniqlanmadi». Topilganda Telegram'ga yangilangan xabar ketadi. Bitta tarix qatori faqat bitta jurnal yozuviga biriktiriladi.

**Darajalar**: 🔴 to'lov o'chirilishi, summa kamayishi/0 ga tushishi, qarzning to'lovsiz kamayishi, yangi/o'zgargan/o'chirilgan chegirma, oylik to'lov summasi o'zgarishi, 21:00 da Argent'ga kiritilmagan naqd, sinxronizatsiya to'xtashi, sozlamalar o'zgarishi.
🟡 summa oshishi, sana/usul/guruh o'zgarishi, yaratilgandan 10 daqiqa ichidagi tuzatish, muzlatishda 0 dan farqli summa. Uzilishdan keyin 7 kunlik jurnal avtomatik to'ldiriladi va uzilish davri belgilanadi.

## Do'konPro (do'kon) nazorati va Argent bilan solishtirish (administrator uchun)

Do'konPro (`https://oxfordlc.uz/shop/`, SQLite `bookshop.db`) bazasini **faqat o'qish** rejimida ochadi (`SQLITE_OPEN_READONLY` + `PRAGMA query_only`; yozishga urinish rad etiladi — Admin → Do'kon da isbot ko'rinadi),
do'kon fayllariga tegmaydi. Do'konda audit jurnali yo'q va qatorlar jismonan o'chiriladi, shuning uchun har daqiqada do'kon holati Argent ichidagi **snapshot** bilan solishtiriladi
va farq `shop_events` ga (o'chirib/o'zgartirib bo'lmaydigan, faqat «ko'rildi» + izoh) yoziladi. Birinchi ishga tushishda mavjud ma'lumotlar «asos» sifatida saqlanadi (ogohlantirishsiz).

**O'rnatish**
1. Yangi fayllarni yuklang (`shop*.php`, `inventory_check.php`, `shop_*`, `admin_parts/shop.php`, yangilangan `db.php`, `cron.php`, `layout.php`, `process.php`, `admin.php`, `solishtirish.php`, `lcup_report.php`, `paths.php`, `.htaccess`, `assets/app.css`). `harvards_finance.sqlite` ni YUKLAMANG. Baza o'zi v9 ga o'tadi.
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
- Do'kon moduli: `shop_db.php` (faqat-o'qish adapteri — do'kon jadvallarini bilgan yagona fayl), `shop_lib.php`, `shop_sync.php`, `shop_recon.php`, `shop_report.php`, `shop_inv.php`; sahifalar `shop.php`, `shop_view_recon.php`, `inventory_check.php`; `tools/shop_schema_dump.php` — do'kon bazasi tuzilishini (ma'lumotsiz) chiqaradi; `tests/` — sinovlar
- `tools/import_cli.php` — buyruq satridan import: `php tools/import_cli.php dump.sql "Filial" --create-branch`
