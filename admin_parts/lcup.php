<?php defined('ADMIN_PAGE') || exit;
$mapRows = lcup_map_rows(false); $mapBy = []; foreach ($mapRows as $r) $mapBy[(int)$r['branch_id']] = $r;
$cfgOk = (string)cfg('LCUP_PHONE', '') !== '' && (string)cfg('LCUP_PASSWORD', '') !== ''; $pd = private_dir();
$self = lcup_app_url() ?: ((!empty($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') : 'https://SAYT/argent'));
$lastOk = lcup_state_get('last_success'); $test = $_SESSION['lcup_selftest'] ?? null; unset($_SESSION['lcup_selftest']);
$v = function ($k) { return e(lcup_setting($k)); };
?>
<div class="alert info" style="background:var(--primary-soft);color:var(--primary-text)"><?= icon('radar') ?><div class="grow">
  <b>LC-UP monitoring.</b> Oxirgi muvaffaqiyatli sinxronizatsiya: <b><?= $lastOk ? e(date('d.m.Y H:i', strtotime($lastOk))) : 'hali yo\'q' ?></b>.
  <a href="lcup.php" style="text-decoration:underline">Hodisalar →</a> · <a href="solishtirish.php" style="text-decoration:underline">Solishtirish →</a></div></div>

<div class="grid lg-g-2">
  <section class="card card-pad stack" aria-labelledby="lcConn">
    <div class="row"><span class="icon-tile primary"><?= icon('plug') ?></span><div><h2 class="card-title" id="lcConn">Ulanish va tekshiruv</h2><p class="fs-sm text-3">LC-UP ga faqat o'qish uchun kiriladi</p></div></div>
    <div class="lc-check">
      <div class="<?= $pd && is_file($pd . '/config.php') ? 'ok' : 'bad' ?>">config.php: <?= $pd && is_file($pd . '/config.php') ? e($pd . '/config.php') : "topilmadi — " . e(private_dir_target()) . "/config.php yarating" ?></div>
      <div class="<?= $cfgOk ? 'ok' : 'bad' ?>">LCUP_PHONE / LCUP_PASSWORD: <?= $cfgOk ? "to'ldirilgan (ko'rsatilmaydi)" : "to'ldirilmagan" ?></div>
      <div class="<?= (string)cfg('CRON_TOKEN', '') !== '' ? 'ok' : 'warn' ?>">CRON_TOKEN: <?= (string)cfg('CRON_TOKEN', '') !== '' ? 'sozlangan' : "sozlanmagan (faqat Hostinger cron CLI ishlaydi, cron-job.org uchun kerak)" ?></div>
      <div class="<?= (string)cfg('LCUP_WEBHOOK_SECRET', '') !== '' ? 'ok' : 'warn' ?>">LCUP_WEBHOOK_SECRET: <?= (string)cfg('LCUP_WEBHOOK_SECRET', '') !== '' ? 'sozlangan' : "sozlanmagan (webhook o'chiq)" ?></div>
      <?php if ($pd && path_inside_webroot($pd)): ?><div class="bad">Maxfiy papka public_html ICHIDA! Uni public_html dan tashqariga ko'chiring.</div><?php endif; ?>
    </div>
    <div class="row gap-2 row-wrap">
      <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_selftest"><button class="btn btn-primary" type="submit"><?= icon('check-check', 'i-sm') ?> Ulanishni tekshirish</button></form>
      <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_diagnostics"><button class="btn" type="submit"><?= icon('folder-lock', 'i-sm') ?> Diagnostika</button></form>
      <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_run_now"><button class="btn" type="submit"><?= icon('refresh-cw', 'i-sm') ?> Hozir sinxronlash</button></form>
    </div>
    <?php if ($test): ?><div class="lc-check" style="margin-top:6px"><?php foreach ($test as [$ok, $t]): ?><div class="<?= $ok === true ? 'ok' : ($ok === false ? 'bad' : 'warn') ?>"><?= e($t) ?></div><?php endforeach; ?></div><?php endif; ?>
    <p class="hint">«Diagnostika» LC-UP sahifalarining xom HTML'ini (token va parollarsiz) saqlaydi va ZIP qiladi — parser ishlamay qolsa menga yuborasiz.</p>
  </section>

  <section class="card card-pad stack" aria-labelledby="lcRun">
    <div class="row"><span class="icon-tile primary"><?= icon('clock') ?></span><div><h2 class="card-title" id="lcRun">Avtomatik ishga tushirish (har 1 daqiqa)</h2><p class="fs-sm text-3">Cron + webhook</p></div></div>
    <div class="field"><span class="label">Hostinger → Cron Jobs (har daqiqa: * * * * *)</span><div class="pre-box">/usr/bin/php <?= e(__DIR__ . '/../cron.php') ?></div></div>
    <div class="field"><span class="label">yoki cron-job.org (HTTP, har 1 daqiqa)</span><div class="pre-box"><?= e($self) ?>/cron.php?token=<i>CRON_TOKEN</i></div></div>
    <div class="field"><span class="label">Webhook (LC-UP ga kiriting, POST + X-LC-UP-Signature)</span><div class="pre-box"><?= e($self) ?>/webhook.php</div></div>
    <p class="hint">Tokenni va webhook sirini <code>argent_private/config.php</code> ga o'zingiz yozasiz; bu yerda ko'rsatilmaydi.</p>
  </section>
</div>

<section class="card card-pad stack" aria-labelledby="lcMap">
  <div class="row"><span class="icon-tile primary"><?= icon('store') ?></span><div><h2 class="card-title" id="lcMap">Filiallarni moslash</h2><p class="fs-sm text-3">Argent filiali ↔ LC-UP filiali (tepadagi dropdown). ID bo'sh qolsa, nom bo'yicha avtomatik topiladi.</p></div></div>
  <form action="process.php" method="post" class="stack" data-loading>
    <?= csrf_field() ?><input type="hidden" name="action" value="lcup_save_map">
    <?php foreach ($B as $b): $m = $mapBy[(int)$b['id']] ?? null; ?>
    <div class="grid g-2" style="grid-template-columns:minmax(0,1.3fr) minmax(0,.7fr) minmax(0,1.3fr) auto;gap:8px;align-items:end">
      <div class="field"><span class="label"><?= e($b['name']) ?></span><span class="hint">Argent filiali</span></div>
      <label class="field"><span class="hint">LC-UP filial ID</span><input class="input input-sm" name="key[<?= (int)$b['id'] ?>]" value="<?= e($m['lcup_key'] ?? '') ?>" placeholder="414" inputmode="numeric"></label>
      <label class="field"><span class="hint">LC-UP filial nomi</span><input class="input input-sm" name="name[<?= (int)$b['id'] ?>]" value="<?= e($m['lcup_name'] ?? '') ?>" placeholder="Oxford LC"></label>
      <label class="switch" title="Kuzatish"><input type="checkbox" name="on[<?= (int)$b['id'] ?>]" value="1" <?= !$m || $m['is_active'] ? ($m ? 'checked' : '') : '' ?>><i></i></label>
    </div>
    <?php endforeach; ?>
    <button class="btn btn-primary" type="submit"><?= icon('check', 'i-sm') ?> Saqlash</button>
  </form>
</section>

<div class="grid lg-g-2">
  <section class="card card-pad stack" aria-labelledby="lcTg">
    <div class="row"><span class="icon-tile primary"><?= icon('send') ?></span><div><h2 class="card-title" id="lcTg">Telegram ogohlantirish</h2><p class="fs-sm text-3">Har bir qizil hodisa uchun xabar. Token <code>argent_private/</code> da saqlanadi.</p></div></div>
    <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="lcup_save_telegram">
      <label class="field"><span class="label">Bot token</span><input class="input" type="password" name="token" placeholder="<?= lcup_tg_ready() ? "••••••• (saqlangan — o'zgartirish uchun yangisini yozing)" : '123456:ABC…' ?>" autocomplete="off"></label>
      <label class="field"><span class="label">Chat ID</span><input class="input" name="chat" value="<?= e((string)private_get('telegram_chat', '')) ?>" placeholder="123456789" inputmode="numeric"></label>
      <div class="row gap-2 row-wrap"><button class="btn btn-primary" type="submit"><?= icon('check', 'i-sm') ?> Saqlash</button>
        <button class="btn" type="submit" formaction="process.php" name="test" value="1"><?= icon('send', 'i-sm') ?> Saqlab, sinov xabari yuborish</button></div>
    </form>
  </section>

  <section class="card card-pad stack" aria-labelledby="lcSet">
    <div class="row"><span class="icon-tile primary"><?= icon('sliders-horizontal') ?></span><div><h2 class="card-title" id="lcSet">Qoidalar va chegaralar</h2><p class="fs-sm text-3">Qizil belgilar shu qiymatlarga qarab qo'yiladi</p></div></div>
    <form action="process.php" method="post" class="stack" data-loading>
      <?= csrf_field() ?><input type="hidden" name="action" value="lcup_save_settings">
      <div class="grid g-2" style="gap:8px">
        <label class="field"><span class="hint">Kichik to'lov chegarasi (so'm)</span><input class="input input-sm" name="small_amount" value="<?= $v('small_amount') ?>" inputmode="numeric"></label>
        <label class="field"><span class="hint">«Kech» — daqiqadan keyin</span><input class="input input-sm" name="late_minutes" value="<?= $v('late_minutes') ?>" inputmode="numeric"></label>
        <label class="field"><span class="hint">Ish vaqti: boshlanishi</span><input class="input input-sm" type="time" name="work_from" value="<?= $v('work_from') ?>"></label>
        <label class="field"><span class="hint">Ish vaqti: tugashi</span><input class="input input-sm" type="time" name="work_to" value="<?= $v('work_to') ?>"></label>
        <label class="field"><span class="hint">Kun yakuni tekshiruvi (naqd)</span><input class="input input-sm" type="time" name="eod_time" value="<?= $v('eod_time') ?>"></label>
        <label class="field"><span class="hint">Tungi to'liq tekshiruv boshlanishi</span><input class="input input-sm" type="time" name="night_time" value="<?= $v('night_time') ?>"></label>
        <label class="field"><span class="hint">Har daqiqa: yangi sahifalar soni</span><input class="input input-sm" name="hot_pages" value="<?= $v('hot_pages') ?>" inputmode="numeric"></label>
        <label class="field"><span class="hint">Har daqiqa: aylanma sahifalar soni</span><input class="input input-sm" name="warm_pages" value="<?= $v('warm_pages') ?>" inputmode="numeric"></label>
        <label class="field"><span class="hint">So'rovlar orasida pauza (ms)</span><input class="input input-sm" name="delay_ms" value="<?= $v('delay_ms') ?>" inputmode="numeric"></label>
        <label class="field"><span class="hint">Tunda har ishga tushishda sahifa</span><input class="input input-sm" name="night_pages_per_run" value="<?= $v('night_pages_per_run') ?>" inputmode="numeric"></label>
      </div>
      <label class="row card-pad" style="border:1px solid var(--border);border-radius:14px;background:var(--surface-2);align-items:flex-start;gap:12px;cursor:pointer"><span class="switch"><input type="checkbox" name="allow_switch_post" value="1" <?= lcup_setting('allow_switch_post') === '1' ? 'checked' : '' ?>><i></i></span>
        <span><b class="fs-md">Filialni almashtirishga ruxsat</b><br><span class="hint">LC-UP da filial tanlagich faqat so'rov (POST) bilan ishlaydi. Yoqilsa, monitoring FAQAT shu bitta chaqiruvni yuboradi (joriy filialni tanlaydi, ma'lumotni o'zgartirmaydi). O'chiq bo'lsa, faqat LC-UP akkaunti hozir turgan filial o'qiladi. <b>Tavsiya:</b> monitoring uchun alohida LC-UP xodim akkaunti oching — filial xodim yozuvida saqlansa, sizning o'z ish sessiyangizdagi filial almashib ketmasligi uchun.</span></span></label>
      <label class="row" style="gap:10px;cursor:pointer"><span class="switch"><input type="checkbox" name="alerts_on" value="1" <?= lcup_setting('alerts_on') === '1' ? 'checked' : '' ?>><i></i></span><b class="fs-md">Telegram ogohlantirishlari yoqilgan</b></label>
      <details class="lc-details"><summary>LC-UP sahifa manzillari (odatda o'zgartirish shart emas)</summary><div class="stack" style="margin-top:8px">
        <?php foreach (['worker_url' => 'Xodimlar faoliyati', 'archive_url' => "O'chirilgan to'lovlar", 'payments_url' => "To'lovlar ro'yxati", 'discount_url' => 'Chegirma hisoboti', 'debtors_url' => 'Qarzdorlar'] as $k => $l): ?>
        <label class="field"><span class="hint"><?= e($l) ?></span><input class="input input-sm" name="<?= $k ?>" value="<?= $v($k) ?>"></label><?php endforeach; ?>
      </div></details>
      <button class="btn btn-primary" type="submit"><?= icon('check', 'i-sm') ?> Saqlash</button>
    </form>
  </section>
</div>

<section class="card card-pad stack" aria-labelledby="lcSec" style="border-color:<?= db_is_private() ? 'var(--border)' : 'color-mix(in srgb, var(--danger) 35%, var(--border))' ?>">
  <div class="row"><span class="icon-tile <?= db_is_private() ? 'in' : 'out' ?>"><?= icon('shield-check') ?></span><div class="grow"><h2 class="card-title" id="lcSec">Xavfsizlik: baza va maxfiy fayllar</h2><p class="fs-sm text-3">Baza fayli internetdan yuklab olinmasligi kerak</p></div></div>
  <div class="lc-check">
    <div class="<?= db_is_private() ? 'ok' : 'bad' ?>">Baza: <?= e(DB_FILE) ?> — <?= db_is_private() ? "public_html dan tashqarida" : "public_html ICHIDA (faqat .htaccess himoya qiladi)" ?></div>
    <div class="<?= !path_inside_webroot(data_root()) ? 'ok' : 'bad' ?>">data/ (zaxira, isbotlar, sessiyalar): <?= e(data_root()) ?> — <?= !path_inside_webroot(data_root()) ? 'tashqarida' : 'public_html ichida' ?></div>
    <div class="<?= $pd && !path_inside_webroot($pd) ? 'ok' : 'warn' ?>">Maxfiy papka: <?= $pd ? e($pd) : 'yo\'q' ?></div>
  </div>
  <?php if (!db_is_private() || path_inside_webroot(data_root())): ?>
  <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="move_to_private">
    <button class="btn btn-danger-soft" type="submit" data-confirm="Baza va data/ papkasi <?= e(private_dir_target()) ?> ga ko'chiriladi, nusxa tekshiriladi va faqat shundan keyin eski fayllar o'chiriladi. Oldin zaxira nusxa avtomatik olinadi." data-confirm-title="Xavfsiz joyga ko'chirilsinmi?" data-confirm-ok="Ko'chirish" data-confirm-tone="primary"><?= icon('folder-lock', 'i-sm') ?> Bazani xavfsiz joyga ko'chirish</button></form>
  <p class="hint">Ko'chirgandan keyin FTP orqali yangi versiyani yuklaganda <code>harvards_finance.sqlite</code> faylini qayta yuklamang — ilova bazani <code>argent_private/</code> dan oladi.</p>
  <?php endif; ?>
</section>
