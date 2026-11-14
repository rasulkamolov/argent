<?php
/** Admin → Do'kon tab: connection to the Do'konPro database (read-only), branch mapping, rules, protection of bookshop.db. Included by admin.php. */
if (!defined('ADMIN_PAGE')) exit;
$sv = function ($k) { return e(shop_setting($k)); };
$spath = shop_db_path(); $scfg = trim((string)cfg('SHOP_DB_PATH', ''));
$sprob = []; $sro = null; $sbr = []; $sconn = ''; $sexp = ['state' => 'unknown', 'msg' => '', 'url' => ''];
if ($spath) {
    try { $sbr = shop_branches(); $sprob = shop_schema_problems(); $sro = shop_assert_readonly(); shop_resolve_map(); } catch (Throwable $e) { $sconn = lcup_redact($e->getMessage()); }
    $sexp = ['state' => path_inside_webroot($spath) ? 'inside' : 'private', 'msg' => '', 'url' => ''];
}
$smap = []; foreach (shop_map_rows(false) as $m) $smap[(int)$m['branch_id']] = $m;
$sLast = shop_state_get('last_success'); $sMin = shop_minutes_since_run(); $sFails = (int)shop_state_get('fail_count', 0);
$self = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
?>
<section class="card card-pad stack" aria-labelledby="shConn">
  <div class="row"><span class="icon-tile primary"><?= icon('receipt') ?></span><div class="grow"><h2 class="card-title" id="shConn">Do'konPro bazasiga ulanish</h2><p class="fs-sm text-3">Argent do'kon bazasini <b>faqat o'qish</b> rejimida ochadi (SQLite <code>READONLY</code> + <code>query_only</code>); hech narsa yozmaydi va do'kon fayllarini o'zgartirmaydi.</p></div></div>
  <div class="lc-check">
    <div class="<?= $spath ? 'ok' : 'bad' ?>">Baza fayli: <?= $spath ? e($spath) : "topilmadi" ?></div>
    <?php if ($spath): ?>
      <div class="<?= $sconn ? 'bad' : 'ok' ?>">Ulanish: <?= $sconn ? e($sconn) : "ochildi ✓ · filiallar: " . e(implode(', ', array_column($sbr, 'name'))) ?></div>
      <div class="<?= $sro ? 'ok' : 'bad' ?>">Faqat o'qish: <?= $sro ? "yozishga urinish rad etildi ✓" : "OGOHLANTIRISH: yozishga ruxsat bor (tekshirib bo'lmadi)" ?></div>
      <div class="<?= $sprob ? 'bad' : 'ok' ?>">Jadvallar tuzilishi: <?= $sprob ? e(implode('; ', array_slice($sprob, 0, 6))) : "kutilganidek ✓" ?></div>
    <?php endif; ?>
    <div class="<?= ($sMin !== null && $sMin < 10 && !$sFails) ? 'ok' : 'warn' ?>">Oxirgi tekshiruv: <?= $sLast ? e(date('d.m.Y H:i', strtotime($sLast))) . ' (' . (int)floor((time() - strtotime($sLast)) / 60) . ' daq. oldin)' : "hali bo'lmagan" ?><?= $sFails ? ' · ketma-ket xato: ' . $sFails : '' ?></div>
  </div>
  <form action="process.php" method="post" class="stack" data-loading autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="shop_config_save">
    <label class="field"><span class="label">Bazaning to'liq yo'li <span class="text-3 fw-5">(SHOP_DB_PATH — bo'sh qoldirsangiz standart joylar qidiriladi)</span></span>
      <input class="input" name="shop_db_path" value="<?= e($scfg) ?>" placeholder="/home/u180105715/domains/oxfordlc.uz/public_html/shop/db/bookshop.db" style="font-family:ui-monospace,monospace;font-size:13px"></label>
    <p class="hint">Yo'l <code><?= e(private_config_file() ?: private_dir_target() . '/config.php') ?></code> ga yoziladi. Do'konning o'z sozlamasidagi <code>DB_PATH</code> (shop/includes/config.php) dagi yo'l bilan bir xil bo'lsin.</p>
    <div class="row gap-2 row-wrap"><button class="btn btn-primary" type="submit"><?= icon('check', 'i-sm') ?> Saqlash</button></div></form>
  <div class="row gap-2 row-wrap"><form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="shop_run_now"><button class="btn btn-soft" type="submit"><?= icon('refresh-cw', 'i-sm') ?> Hozir tekshirish</button></form></div>
</section>

<section class="card card-pad stack" aria-labelledby="shMap">
  <div class="row"><span class="icon-tile primary"><?= icon('store') ?></span><div><h2 class="card-title" id="shMap">Filiallarni moslash</h2><p class="fs-sm text-3">Argent filiali ↔ Do'konPro filiali (nomi bo'yicha). Bo'sh qoldirilsa, filial do'kon nazoratidan chiqariladi.</p></div></div>
  <form action="process.php" method="post" class="stack" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="shop_save_map">
    <?php foreach ($B as $b): $m = $smap[(int)$b['id']] ?? null; ?>
    <div class="grid g-2" style="grid-template-columns:minmax(0,1fr) minmax(0,1.3fr) auto;gap:8px;align-items:end">
      <div class="field"><span class="label"><?= e($b['name']) ?></span><span class="hint">Argent filiali</span></div>
      <label class="field"><span class="hint">Do'konPro filial nomi<?= $m && $m['shop_branch_id'] ? ' (id ' . (int)$m['shop_branch_id'] . ')' : '' ?></span><input class="input input-sm" name="name[<?= (int)$b['id'] ?>]" value="<?= e($m['shop_name'] ?? '') ?>" list="shBranches" placeholder="Asosiy filial"></label>
      <label class="switch" title="Kuzatish"><input type="checkbox" name="on[<?= (int)$b['id'] ?>]" value="1" <?= $m && $m['is_active'] ? 'checked' : '' ?>><i></i></label>
    </div><?php endforeach; ?>
    <datalist id="shBranches"><?php foreach ($sbr as $x): ?><option value="<?= e($x['name']) ?>"><?php endforeach; ?></datalist>
    <button class="btn btn-primary" type="submit"><?= icon('check', 'i-sm') ?> Saqlash</button></form>
</section>

<section class="card card-pad stack" aria-labelledby="shSet">
  <div class="row"><span class="icon-tile primary"><?= icon('sliders-horizontal') ?></span><div><h2 class="card-title" id="shSet">Qoidalar va chegaralar</h2><p class="fs-sm text-3">Kategoriya nomlari va qizil/sariq belgilar chegaralari</p></div></div>
  <form action="process.php" method="post" class="stack" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="shop_save_settings">
    <div class="grid g-2" style="gap:8px">
      <label class="field"><span class="hint">Do'konPro kitob kategoriyasi (aniq nom)</span><input class="input input-sm" name="kitob_category" value="<?= $sv('kitob_category') ?>"></label>
      <label class="field"><span class="hint">Argent kategoriyasi: do'kon</span><input class="input input-sm" name="argent_shop_cat" value="<?= $sv('argent_shop_cat') ?>"></label>
      <label class="field"><span class="hint">Argent kategoriyasi: kitob</span><input class="input input-sm" name="argent_kitob_cat" value="<?= $sv('argent_kitob_cat') ?>"></label>
      <label class="field"><span class="hint">Argent «to'lov» kategoriyasi (xato tanlanishi mumkin)</span><input class="input input-sm" name="argent_pay_cat" value="<?= $sv('argent_pay_cat') ?>"></label>
      <label class="field" style="grid-column:1/-1"><span class="hint">Izohdagi kalit so'zlar (vergul bilan; «To'lov» kategoriyasida LC-UP havolasiz yozuv shu so'zlardan birini o'z ichiga olsa do'kon/kitob deb sanaladi)</span><input class="input input-sm" name="keywords" value="<?= $sv('keywords') ?>"></label>
      <label class="field"><span class="hint">Ish vaqti: boshlanishi</span><input class="input input-sm" type="time" name="work_from" value="<?= $sv('work_from') ?>"></label>
      <label class="field"><span class="hint">Ish vaqti: tugashi (undan keyingi sotuv — qizil)</span><input class="input input-sm" type="time" name="work_to" value="<?= $sv('work_to') ?>"></label>
      <label class="field"><span class="hint">Kun yakuni tekshiruvi (bugungi solishtirish)</span><input class="input input-sm" type="time" name="eod_time" value="<?= $sv('eod_time') ?>"></label>
      <label class="field"><span class="hint">Kechagi kunni tekshirish vaqti (ertalab)</span><input class="input input-sm" type="time" name="recon_next_time" value="<?= $sv('recon_next_time') ?>"></label>
      <label class="field"><span class="hint">Solishtirishda ruxsat etilgan farq (so'm)</span><input class="input input-sm" name="tolerance" value="<?= $sv('tolerance') ?>" inputmode="numeric"></label>
      <label class="field"><span class="hint">«Keyin kiritilgan» — necha kundan boshlab</span><input class="input input-sm" name="late_entry_days" value="<?= $sv('late_entry_days') ?>" inputmode="numeric"></label>
      <label class="field"><span class="hint">Qaytarish «kech» — sotuvdan necha daqiqadan keyin</span><input class="input input-sm" name="return_late_min" value="<?= $sv('return_late_min') ?>" inputmode="numeric"></label>
      <label class="field"><span class="hint">Kichik qarz chegarasi (so'm, sariq)</span><input class="input input-sm" name="small_debt" value="<?= $sv('small_debt') ?>" inputmode="numeric"></label>
      <label class="field"><span class="hint">Kirim narxi odatdagidan necha % baland bo'lsa qizil</span><input class="input input-sm" name="cost_jump_pct" value="<?= $sv('cost_jump_pct') ?>" inputmode="numeric"></label>
      <label class="field"><span class="hint">Ko'chirish «kirdi» kutish (soat)</span><input class="input input-sm" name="transfer_wait_h" value="<?= $sv('transfer_wait_h') ?>" inputmode="numeric"></label>
      <label class="field"><span class="hint">Kuzatiladigan chek oynasi (kun)</span><input class="input input-sm" name="window_days" value="<?= $sv('window_days') ?>" inputmode="numeric"></label>
      <label class="field"><span class="hint">Inventarizatsiya: tovarlar soni (5–10)</span><input class="input input-sm" name="inv_count" value="<?= $sv('inv_count') ?>" inputmode="numeric"></label>
      <label class="field"><span class="hint">Eng ko'p sotiladiganlar: necha kun</span><input class="input input-sm" name="inv_top_days" value="<?= $sv('inv_top_days') ?>" inputmode="numeric"></label>
      <label class="field"><span class="hint">Sotuvchi «past» — boshqalarning ulushi (0.7 = 70%)</span><input class="input input-sm" name="pat_low_ratio" value="<?= $sv('pat_low_ratio') ?>" inputmode="decimal"></label>
      <label class="field"><span class="hint">Qaytarish/o'chirish «yuqori» — necha baravar</span><input class="input input-sm" name="pat_high_ratio" value="<?= $sv('pat_high_ratio') ?>" inputmode="decimal"></label>
      <label class="field"><span class="hint">Haftalik tahlilda eng kamida hodisalar soni</span><input class="input input-sm" name="pat_min_events" value="<?= $sv('pat_min_events') ?>" inputmode="numeric"></label>
    </div>
    <label class="row" style="gap:10px;cursor:pointer"><span class="switch"><input type="checkbox" name="enabled" value="1" <?= shop_setting('enabled') === '1' ? 'checked' : '' ?>><i></i></span><b class="fs-md">Do'kon monitoringi yoqilgan</b></label>
    <label class="row" style="gap:10px;cursor:pointer"><span class="switch"><input type="checkbox" name="alerts_on" value="1" <?= shop_setting('alerts_on') === '1' ? 'checked' : '' ?>><i></i></span><b class="fs-md">Telegram ogohlantirishlari yoqilgan</b></label>
    <button class="btn btn-primary" type="submit"><?= icon('check', 'i-sm') ?> Saqlash</button></form>
  <p class="hint">Telegram token/chat va kunlik (21:00) / haftalik hisobot vaqti «Sozlamalar → LC-UP monitoring» dagi bilan umumiy: Do'kon bo'limi shu xabarlarga qo'shiladi. Cron o'zgarmaydi — <code>cron.php</code> LC-UP dan keyin do'konni ham tekshiradi.</p>
</section>

<section class="card card-pad stack" aria-labelledby="shSec" style="border-color:<?= $sexp['state'] === 'inside' ? 'color-mix(in srgb, var(--warn) 40%, var(--border))' : 'var(--border)' ?>">
  <div class="row"><span class="icon-tile <?= $sexp['state'] === 'inside' ? 'out' : 'in' ?>"><?= icon('shield-check') ?></span><div class="grow"><h2 class="card-title" id="shSec">Xavfsizlik: bookshop.db internetdan yuklanmasligi kerak</h2><p class="fs-sm text-3">Bazada mijozlar, xodimlar va parol xeshlari bor</p></div></div>
  <?php if ($spath): ?><div class="lc-check"><div class="<?= $sexp['state'] === 'private' ? 'ok' : 'warn' ?>">Joylashuvi: <?= e($spath) ?> — <?= $sexp['state'] === 'private' ? "public_html dan tashqarida ✓" : "public_html ICHIDA (faqat server qoidasi himoya qilishi mumkin)" ?></div></div>
  <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="shop_http_check"><button class="btn" type="submit"><?= icon('shield-check', 'i-sm') ?> Internetdan ochilishini tekshirish (HEAD)</button></form><?php endif; ?>
  <details class="lc-details"><summary>Qanday himoyalash kerak (do'kon ishlashiga zarar bermasdan)</summary><div class="stack" style="margin-top:8px">
    <p class="fs-sm"><b>Eng yaxshi usul — bazani public_html dan tashqariga ko'chirish.</b> 1) <code>public_html</code> bilan yonma-yon <code>shop_private/</code> papkasini yarating. 2) <code>shop/db/bookshop.db</code> faylini (va agar bor bo'lsa <code>-wal</code>, <code>-shm</code> fayllarini) shu papkaga ko'chiring. 3) <code>shop/includes/config.php</code> da <code>DB_PATH</code> ni yangi yo'lga o'zgartiring (masalan <code>/home/u…/domains/oxfordlc.uz/shop_private/bookshop.db</code>). 4) Shu sahifada «Bazaning to'liq yo'li» ni yangilang. Papka va fayl egasi sayt foydalanuvchisi bo'lsin (huquq 700/600) — do'kon ham, Argent ham o'qiy oladi.</p>
    <p class="fs-sm"><b>Yoki</b> hozirgi joyida bloklang: <code>shop/db/.htaccess</code> faylida <code>Require all denied</code> (eski Apache uchun <code>Deny from all</code>) bo'lsin va <code>*.db</code>, <code>*.sqlite</code>, <code>*-wal</code>, <code>*-shm</code> fayllari alohida yopilsin. Keyin yuqoridagi «Tekshirish» tugmasi 403/404 qaytarishini ko'ring.</p>
    <p class="fs-sm text-3">Argent bazani yo'li bo'yicha to'g'ridan-to'g'ri (fayl tizimi orqali) o'qiydi — HTTP orqali emas, shuning uchun yo'lni bloklash yoki ko'chirish Argent ishiga ta'sir qilmaydi.</p></div></details>
</section>

<section class="card card-pad stack"><div class="row"><span class="icon-tile primary"><?= icon('clock') ?></span><div><h2 class="card-title">Avtomatik ishga tushirish</h2><p class="fs-sm text-3">LC-UP bilan bir xil cron (har 1 daqiqa) — qo'shimcha cron kerak emas</p></div></div>
  <div class="pre-box">/usr/bin/php <?= e(dirname(__DIR__) . '/cron.php') ?></div>
  <p class="hint">Javobda <code>shop=ok shop_new_events=N</code> ko'rinadi. Birinchi ishga tushishda do'kondagi mavjud ma'lumotlar «asos» sifatida saqlanadi (ogohlantirishsiz); keyingi o'zgarishlar kuzatiladi.</p></section>
