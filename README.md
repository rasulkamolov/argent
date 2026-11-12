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

LC-UP dagi to'lovlarni **faqat o'qiydi** (kirish + GET so'rovlari; filial almashtirish faqat siz ruxsat bersangiz) va har bir to'lovga oid o'zgarishni
o'chirib bo'lmaydigan «LC-UP nazorat» ro'yxatiga yozadi; shubhali (qizil) hodisalar Telegram'ga yuboriladi.

**O'rnatish (qadam-baqadam)**
1. Fayllarni yuklang (`harvards_finance.sqlite` ni mavjud bazaning ustiga YOZMANG).
2. `public_html` bilan **yonma-yon** `argent_private` papkasini yarating
   (`/home/uXXXX/domains/oxfordlc.uz/argent_private`). `argent_private.example/config.php` ni shu yerga nusxalang va to'ldiring:
   `LCUP_PHONE`, `LCUP_PASSWORD`, `CRON_TOKEN` (`openssl rand -hex 24`), kerak bo'lsa `LCUP_WEBHOOK_SECRET`.
3. Admin → **LC-UP** → «Xavfsizlik» → **Bazani xavfsiz joyga ko'chirish** (baza va `data/` public_html dan tashqariga o'tadi).
4. LC-UP da monitoring uchun **alohida xodim akkaunti** oching (filial tanlash xodim yozuvida saqlansa, sizning ish sessiyangiz almashib ketmasligi uchun).
5. Admin → LC-UP → «Filiallarni moslash»: Oxford LC = `414`; ikkinchi filial ID si bo'sh qolsa nom bo'yicha topiladi.
   LC-UP dagi filial tanlagich faqat so'rov (POST) bilan ishlaydi, shuning uchun «Filialni almashtirishga ruxsat» ni yoqing (yoki har filial uchun alohida akkaunt).
6. **Ulanishni tekshirish**; muammo bo'lsa **Diagnostika** → ZIP ni yuboring.
7. Telegram: @BotFather → `/newbot` → token. Botga biror xabar yozing, so'ng brauzerda
   `https://api.telegram.org/bot<TOKEN>/getUpdates` → `chat":{"id":…}`. Token va chat ID ni Admin → LC-UP → Telegram ga kiriting («sinov xabari»).
8. Cron (har daqiqa): Hostinger → Advanced → Cron Jobs → `* * * * *` →
   `/usr/bin/php /home/uXXXX/domains/oxfordlc.uz/public_html/argent/cron.php`
   (yoki cron-job.org: `https://oxfordlc.uz/argent/cron.php?token=CRON_TOKEN`, xato bo'lsa u sizga e-mail yuboradi).
9. Webhook (ixtiyoriy): `https://oxfordlc.uz/argent/webhook.php` (POST, `X-LC-UP-Signature` = HMAC-SHA256).

**Qanday ishlaydi**: har daqiqada «Xodimlar faoliyati» (yaratilgan to'lov va ASL summa), o'chirilganlar arxivi va `/admin/tolovlars`
ning yangi sahifalari o'qiladi; oxirgi 40 kun aylanma sahifalar orqali, **tunda (03:00 dan)** barcha to'lovlar va butun arxiv bo'laklab tekshiriladi.
Yaratilganda LC-UP jurnalidagi summa hozirgi summa bilan solishtiriladi — farq bo'lsa «To'lov summasi o'zgartirilgan» (asl → hozirgi).

## Zaxira
- Admin kirganda har kuni **avtomatik** zaxira nusxa olinadi (`data/backups/`, oxirgi 30 ta).
- Har bir filial kartasida ⬇ tugmasi: faqat shu filial ma'lumotini alohida `.sqlite` fayl qilib yuklab oladi.

## Fayllar
- `index.php` kunlik hisob · `filiallar.php` filiallar taqqoslash · `hisobot.php`, `analitika.php`, `kassa.php` hisobotlar
- `admin.php` + `admin_parts/` boshqaruv · `import.php` + `import_lib.php` SQL import · `backup.php` zaxira
- `proofs.php`, `proof.php` isbot fayllari · `db.php` (baza va migratsiya) · `auth.php` (rollar, filial doirasi, CSRF) · `process.php` (barcha amallar)
- `tools/import_cli.php` — buyruq satridan import: `php tools/import_cli.php dump.sql "Filial" --create-branch`
