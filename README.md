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
`openHistory`, `setPreset`, `setCat`, `loadMore`, `$refresh` (xodimlar faoliyati) va o'quvchi «To'lovlar» tabi (`studentTolovlarOchilsin`). Saqlash/tahrirlash/o'chirish va to'lovni tiklash (`refresh`) kodda taqiqlangan;
har so'rov (va rad etilgani) `lcup_reqlog` ga yoziladi (Admin → LC-UP da ko'rinadi).

**Darajalar**: 🔴 to'lov o'chirilishi, summa kamayishi/0 ga tushishi, qarzning to'lovsiz kamayishi, yangi/o'zgargan/o'chirilgan chegirma, oylik to'lov summasi o'zgarishi, 21:00 da Argent'ga kiritilmagan naqd, sinxronizatsiya to'xtashi, sozlamalar o'zgarishi.
🟡 summa oshishi, sana/usul/guruh o'zgarishi, yaratilgandan 10 daqiqa ichidagi tuzatish, muzlatishda 0 dan farqli summa. Uzilishdan keyin 7 kunlik jurnal avtomatik to'ldiriladi va uzilish davri belgilanadi.

## Zaxira
- Admin kirganda har kuni **avtomatik** zaxira nusxa olinadi (`data/backups/`, oxirgi 30 ta).
- Har bir filial kartasida ⬇ tugmasi: faqat shu filial ma'lumotini alohida `.sqlite` fayl qilib yuklab oladi.

## Fayllar
- `index.php` kunlik hisob · `filiallar.php` filiallar taqqoslash · `hisobot.php`, `analitika.php`, `kassa.php` hisobotlar
- `admin.php` + `admin_parts/` boshqaruv · `import.php` + `import_lib.php` SQL import · `backup.php` zaxira
- `proofs.php`, `proof.php` isbot fayllari · `db.php` (baza va migratsiya) · `auth.php` (rollar, filial doirasi, CSRF) · `process.php` (barcha amallar)
- `tools/import_cli.php` — buyruq satridan import: `php tools/import_cli.php dump.sql "Filial" --create-branch`
