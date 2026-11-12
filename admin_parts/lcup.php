<?php defined('ADMIN_PAGE') || exit;
$mapRows = lcup_map_rows(false); $mapBy = []; foreach ($mapRows as $r) $mapBy[(int)$r['branch_id']] = $r;
$cfgOk = (string)cfg('LCUP_PHONE', '') !== '' && (string)cfg('LCUP_PASSWORD', '') !== ''; $pd = private_dir();
$self = lcup_current_url() ?: (lcup_app_url() ?: 'https://SAYT/kirimchiqim/argent');
$lastOk = lcup_state_get('last_success');
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
      <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_selftest"><input type="hidden" name="back" value="admin.php#lcup"><button class="btn btn-primary" type="submit"><?= icon('check-check', 'i-sm') ?> Ulanishni tekshirish</button></form>
      <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_diagnostics"><button class="btn" type="submit"><?= icon('folder-lock', 'i-sm') ?> Diagnostika</button></form>
      <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_run_now"><button class="btn" type="submit"><?= icon('refresh-cw', 'i-sm') ?> Hozir sinxronlash</button></form>
    </div>
    <?php if ($lcTest): ?><div class="lc-check" style="margin-top:6px"><?php foreach ($lcTest as [$ok, $t]): ?><div class="<?= $ok === true ? 'ok' : ($ok === false ? 'bad' : 'warn') ?>"><?= e($t) ?></div><?php endforeach; ?></div><?php endif; ?>
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
    <div class="row"><span class="icon-tile primary"><?= icon('send', 'i-sm') ?></span><div><h2 class="card-title" id="lcTg">Telegram va hisobotlar</h2><p class="fs-sm text-3">Token, chat ID va parollar endi <a href="admin.php#settings" style="text-decoration:underline">Sozlamalar → LC-UP monitoring</a> da.</p></div></div>
    <p class="fs-sm text-2">Telegram: <?= lcup_tg_ready() ? "ulangan ✓" : "<b class='text-out'>sozlanmagan</b>" ?> · kunlik xulosa <b><?= $v('summary_time') ?></b> da, haftalik hisobot dushanba <b><?= $v('weekly_time') ?></b> da.</p>
    <div class="row gap-2 row-wrap">
      <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_send_report"><input type="hidden" name="kind" value="daily"><button class="btn" type="submit"><?= icon('send', 'i-sm') ?> Kunlik xulosani hozir yuborish</button></form>
      <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_send_report"><input type="hidden" name="kind" value="weekly"><button class="btn" type="submit"><?= icon('send', 'i-sm') ?> Haftalik hisobotni hozir yuborish</button></form>
    </div>
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
        <label class="field"><span class="hint">Kunlik xulosa vaqti</span><input class="input input-sm" type="time" name="summary_time" value="<?= $v('summary_time') ?>"></label>
        <label class="field"><span class="hint">Haftalik hisobot (dushanba) vaqti</span><input class="input input-sm" type="time" name="weekly_time" value="<?= $v('weekly_time') ?>"></label>
        <label class="field"><span class="hint">Tezkor tuzatish oynasi (daqiqa)</span><input class="input input-sm" name="quick_fix_min" value="<?= $v('quick_fix_min') ?>" inputmode="numeric"></label>
        <label class="field"><span class="hint">So'rovlar orasida pauza (ms)</span><input class="input input-sm" name="delay_ms" value="<?= $v('delay_ms') ?>" inputmode="numeric"></label>
        <label class="field"><span class="hint">Tunda har ishga tushishda sahifa</span><input class="input input-sm" name="night_pages_per_run" value="<?= $v('night_pages_per_run') ?>" inputmode="numeric"></label>
      </div>
      <label class="row card-pad" style="border:1px solid var(--border);border-radius:14px;background:var(--surface-2);align-items:flex-start;gap:12px;cursor:pointer"><span class="switch"><input type="checkbox" name="allow_switch_post" value="1" <?= lcup_setting('allow_switch_post') === '1' ? 'checked' : '' ?>><i></i></span>
        <span><b class="fs-md">Livewire o'qish amallari (oq ro'yxat)</b><br><span class="hint">Yoqilsa, monitoring faqat <code>lcup_policy.php</code> dagi ro'yxatni yuboradi: filial tanlagich (<code>filial_id</code>, <code>change_filial</code>), xodim tanlash/davr/bo'lim/«yana ko'rsatish» va o'quvchi «To'lovlar» tabi. Saqlash, tahrirlash, o'chirish, to'lovni tiklash kabi hamma narsa kodda taqiqlangan. O'chiq bo'lsa faqat akkaunt turgan filial va birinchi xodim o'qiladi. Har so'rov pastdagi jurnalga yoziladi.</span></span></label>
      <label class="row card-pad" style="border:1px solid var(--border);border-radius:14px;background:var(--surface-2);align-items:flex-start;gap:12px;cursor:pointer"><span class="switch"><input type="checkbox" name="history_via_livewire" value="1" <?= lcup_setting('history_via_livewire') === '1' ? 'checked' : '' ?>><i></i></span>
        <span><b class="fs-md">Talaba «Tarix» tabini yuklash (students.history-livewire)</b><br><span class="hint">«Minus to'lov tahrirlandi» yozuvidagi talabani aniqlash uchun talaba «Tarix» tabi o'qiladi. Tab LC-UP da Livewire (POST) bilan yuklanadi: faqat <code>students.history-livewire</code> komponenti va faqat yuklash/ko'rsatish usullari ruxsat etiladi; <code>students.edit-livewire</code>, to'lov qo'shish/tahrirlash/o'chirish va boshqa hamma komponentlar yopiq. Boshqa usul nomi so'ralsa, yuborilmaydi va jurnalga REFUSED deb yoziladi.</span></span></span></label>
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

<?php $reqs = db()->query("SELECT * FROM lcup_reqlog ORDER BY id DESC LIMIT 40")->fetchAll(); $gaps = db()->query("SELECT * FROM lcup_gaps ORDER BY id DESC LIMIT 8")->fetchAll(); ?>
<div class="grid lg-g-2">
  <section class="card card-pad stack"><div class="row"><span class="icon-tile primary"><?= icon('shield-check') ?></span><div><h2 class="card-title">LC-UP ga yuborilgan so'rovlar</h2><p class="fs-sm text-3">Oxirgi 40 ta. «REFUSED» = oq ro'yxat rad etgan so'rov (yuborilmagan).</p></div></div>
    <div class="pre-box" style="max-height:340px"><?php foreach ($reqs as $q): ?><?= e(date('H:i:s', strtotime($q['at'])) . ' ' . str_pad($q['method'], 7) . ' ' . ($q['purpose'] ?: '') . ' ' . $q['path'] . ' ' . $q['detail'] . ' ' . ($q['status'] ?: '')) ?>
<?php endforeach; if (!$reqs): ?>Hali so'rov yo'q.<?php endif; ?></div></section>
  <section class="card card-pad stack"><div class="row"><span class="icon-tile out"><?= icon('clock-alert') ?></span><div><h2 class="card-title">Uzilishlar</h2><p class="fs-sm text-3"><?= (int)lcup_num('catchup_gap_min') ?> daqiqadan ko'p ishlamagan davrlar avtomatik to'ldiriladi</p></div></div>
    <?php if ($gaps): ?><div class="lc-check"><?php foreach ($gaps as $g): ?><div class="<?= $g['filled'] ? 'ok' : 'warn' ?>"><?= e(lcup_gap_text($g)) ?><?= $g['note'] ? ' — ' . e($g['note']) : '' ?></div><?php endforeach; ?></div><?php else: ?><p class="fs-sm text-3">Uzilish qayd etilmagan.</p><?php endif; ?></section>
</div>
