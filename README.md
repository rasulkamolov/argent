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

## To'lov skrinshotlari (Click / Bank)
- Kassir «Click» yoki «Bank» tanlasa, skrinshot maydoni chiqadi: rasmni **tashlash**, **tanlash/kameradan olish** yoki **Ctrl+V** bilan qo'yish mumkin. Katta rasmlar avtomatik kichraytiriladi.
- Kassirlar uchun majburiy (Admin → Sozlamalar yoki har bir filial kartasida o'chirish mumkin). Admin/menejer uchun majburiy emas.
- Rasmlar `data/proofs/` da saqlanadi va faqat tizimga kirgan, **o'sha filialga huquqi bor** foydalanuvchiga ko'rsatiladi. Ro'yxatda «Skrinshot» belgisi; yo'qlari «Skrinshotsiz» deb belgilanadi. Hisobotda «Skrinshotsiz» filtri va CSV da «Skrinshot» ustuni bor.
- `data/` papkasini ham (FTP orqali) zaxiralab turing — `Yuklab olish (.sqlite)` faqat bazani oladi.

## Zaxira
- Admin kirganda har kuni **avtomatik** zaxira nusxa olinadi (`data/backups/`, oxirgi 30 ta).
- Har bir filial kartasida ⬇ tugmasi: faqat shu filial ma'lumotini alohida `.sqlite` fayl qilib yuklab oladi.

## Fayllar
- `index.php` kunlik hisob · `filiallar.php` filiallar taqqoslash · `hisobot.php`, `analitika.php`, `kassa.php` hisobotlar
- `admin.php` + `admin_parts/` boshqaruv · `import.php` + `import_lib.php` SQL import · `backup.php` zaxira
- `proofs.php`, `proof.php` skrinshotlar · `db.php` (baza va migratsiya) · `auth.php` (rollar, filial doirasi, CSRF) · `process.php` (barcha amallar)
- `tools/import_cli.php` — buyruq satridan import: `php tools/import_cli.php dump.sql "Filial" --create-branch`
