<?php defined('ADMIN_PAGE') || exit;
$ge = setting('staff_can_edit', '1') === '1'; $gd = setting('staff_can_delete', '0') === '1'; $gp = setting('require_proof', '1') === '1';
$backups = array_reverse($bk); ?>
<div class="grid lg-g-2">
  <section class="card card-pad stack" aria-labelledby="permTitle">
    <div class="row"><span class="icon-tile primary"><?= icon('shield-check') ?></span><div><h2 class="card-title" id="permTitle">Standart ruxsatlar (kassirlar)</h2><p class="fs-sm text-3">Filialda alohida belgilanmagan bo'lsa shu qo'llanadi</p></div></div>
    <?php foreach ([['staff_can_edit', 'Tahrirlash', 'Kassir tranzaksiyani tahrirlay oladi', $ge], ['staff_can_delete', "O'chirish", "Kassir tranzaksiyani o'chira oladi", $gd], ['require_proof', 'Isbot majburiy', "Kassir Click/Bank kiritganda to'lov isbotini (skrinshot yoki PDF) biriktirishi shart", $gp]] as [$key, $ttl, $desc, $on]): ?>
    <form action="process.php" method="post" class="row card-pad" style="border:1px solid var(--border);border-radius:14px;background:var(--surface-2)">
      <?= csrf_field() ?><input type="hidden" name="action" value="update_setting"><input type="hidden" name="key" value="<?= $key ?>"><input type="hidden" name="value" value="<?= $on ? 1 : 0 ?>">
      <div class="grow"><div class="fw-7"><?= e($ttl) ?><?= $key === 'require_proof' ? '' : ' ruxsati' ?></div><div class="fs-sm text-3"><?= e($desc) ?></div></div>
      <label class="switch"><input type="checkbox" role="switch" <?= $on ? 'checked' : '' ?> data-autosubmit aria-label="<?= e($ttl) ?>"><i></i></label>
    </form>
    <?php endforeach; ?>
    <p class="hint">Menejer va administrator har doim tahrirlay va o'chira oladi. Har bir filial uchun alohida qoida «Filiallar» bo'limida.</p>
  </section>

  <section class="card card-pad stack" aria-labelledby="bkTitle">
    <div class="row"><span class="icon-tile primary"><?= icon('database-backup') ?></span><div><h2 class="card-title" id="bkTitle">Zaxira nusxa</h2><p class="fs-sm text-3">Barcha filiallar ma'lumotlari bitta faylda</p></div></div>
    <div class="row gap-2 row-wrap">
      <form action="process.php" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup_now"><button class="btn btn-primary" type="submit"><?= icon('database-backup', 'i-sm') ?> Hozir saqlash</button></form>
      <a class="btn" href="backup.php"><?= icon('download', 'i-sm') ?> Yuklab olish (.sqlite)</a>
    </div>
    <div class="fs-sm text-3"><?= $backups ? 'Serverdagi nusxalar: ' . count($backups) . ' ta. Oxirgisi: ' . e(date('d.m.Y H:i', filemtime($backups[0]))) : "Serverda hali nusxa yo'q." ?></div>
    <p class="hint">Muhim o'zgarishlardan (filialni o'chirish, import) oldin nusxa avtomatik olinadi. Haftada bir marta yuklab olib saqlang.</p>
  </section>
</div>

<div class="grid lg-g-2">
  <section class="card card-pad stack">
    <div class="row"><span class="icon-tile primary"><?= icon('upload') ?></span><div><h2 class="card-title">Ma'lumot import</h2><p class="fs-sm text-3">phpMyAdmin (.sql) dump faylidan tranzaksiyalarni filialga yuklash</p></div></div>
    <a class="btn btn-soft" href="import.php"><?= icon('upload', 'i-sm') ?> Import sahifasiga o'tish</a>
  </section>
  <section class="card card-pad" aria-labelledby="smpTitle" style="border-color:color-mix(in srgb, var(--danger) 30%, var(--border))">
    <div class="row row-wrap">
      <span class="icon-tile out"><?= icon('flask-conical') ?></span>
      <div class="grow" style="min-width:200px"><h2 class="card-title" id="smpTitle">Namunaviy ma'lumotlar</h2>
        <p class="fs-sm text-3">Joriy filialga 30 kunlik sinov yozuvlari («[NAMUNA]» belgisi bilan). Avval yuqoridan bitta filialni tanlang.</p></div>
    </div>
    <div class="row gap-2 row-wrap mt-3">
      <a class="btn" data-post href="process.php?action=load_sample_data" data-confirm="Tanlangan filialga 30 kunlik namunaviy tranzaksiyalar yuklanadi." data-confirm-title="Namuna yuklansinmi?" data-confirm-ok="Yuklash" data-confirm-tone="primary"><?= icon('upload', 'i-sm') ?> Namuna yuklash</a>
      <a class="btn btn-danger-soft" data-post href="process.php?action=delete_sample_data" data-confirm="Barcha filiallardagi [NAMUNA] yozuvlari o'chiriladi." data-confirm-title="Namunalar o'chirilsinmi?" data-confirm-ok="O'chirish"><?= icon('trash-2', 'i-sm') ?> Namunalarni o'chirish</a>
    </div>
  </section>
</div>

<?php
[$cfgVals, $cfgInfo] = private_config_read(); $hasPhone = ($cfgVals['LCUP_PHONE'] ?? '') !== ''; $hasPass = ($cfgVals['LCUP_PASSWORD'] ?? '') !== ''; $hasTgT = ($cfgVals['TELEGRAM_BOT_TOKEN'] ?? '') !== ''; $hasTgC = ($cfgVals['TELEGRAM_CHAT_ID'] ?? '') !== '';
$hasCron = ($cfgVals['CRON_TOKEN'] ?? '') !== ''; $hasWh = ($cfgVals['LCUP_WEBHOOK_SECRET'] ?? '') !== '';
$appUrl = lcup_current_url() ?: (lcup_app_url() ?: 'https://SAYT/kirimchiqim/argent');
$cronUrl = $appUrl . '/cron.php?token=' . ($cfgVals['CRON_TOKEN'] ?? '…'); $secret = function ($has) { return $has ? '•••••• saqlangan' : ''; }; ?>
<section class="card card-pad stack" id="lcCfg" aria-labelledby="lcCfgT">
  <div class="row"><span class="icon-tile primary"><?= icon('radar') ?></span><div class="grow"><h2 class="card-title" id="lcCfgT">LC-UP monitoring</h2><p class="fs-sm text-3">Sozlamalar <code><?= e(private_config_file() ?: private_dir_target() . '/config.php') ?></code> ga yoziladi (public_html dan tashqarida, huquq 600) — bazaga emas</p></div></div>
  <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="lcup_config_save">
    <div class="grid g-2" style="gap:10px">
      <label class="field"><span class="label">LC-UP telefon</span><input class="input" name="lc_phone" placeholder="<?= $hasPhone ? '•••••• saqlangan' : '+998 90 123 45 67' ?>" autocomplete="off" inputmode="tel"></label>
      <label class="field"><span class="label">LC-UP parol</span><input class="input" type="password" name="lc_password" placeholder="<?= $secret($hasPass) ?: 'parol' ?>" autocomplete="new-password"></label>
      <label class="field"><span class="label">Telegram bot token</span><input class="input" type="password" name="tg_token" placeholder="<?= $secret($hasTgT) ?: '123456:ABC…' ?>" autocomplete="off"></label>
      <label class="field"><span class="label">Telegram chat ID</span><input class="input" name="tg_chat" placeholder="<?= $hasTgC ? '•••••• saqlangan' : '123456789' ?>" autocomplete="off"></label>
      <label class="field" style="grid-column:1/-1"><span class="label">CRON_TOKEN</span>
        <span class="row gap-2"><input class="input grow" id="lcCronTok" name="cron_token" placeholder="<?= $hasCron ? "saqlangan (almashtirish uchun yangisini yozing yoki «Yangi token yaratish»)" : 'bo\'sh — «Yangi token yaratish» ni bosing' ?>" autocomplete="off" style="font-family:ui-monospace,monospace"><button type="button" class="btn" id="lcGenTok"><?= icon('key-round', 'i-sm') ?> Yangi token yaratish</button></span></label>
      <label class="field" style="grid-column:1/-1"><span class="label">LCUP_WEBHOOK_SECRET <span class="text-3 fw-5">(ixtiyoriy)</span></span>
        <span class="row gap-2 row-wrap"><input class="input grow" type="password" name="webhook_secret" placeholder="<?= $secret($hasWh) ?: "bo'sh qolishi mumkin (webhook o'chiq)" ?>" autocomplete="off"><?php if ($hasWh): ?><label class="row gap-2 fs-sm text-2" style="cursor:pointer"><input type="checkbox" name="webhook_clear" value="1"> o'chirish</label><?php endif; ?></span></label>
      <label class="field" style="grid-column:1/-1"><span class="label">Monitorchi akkaunt nomi <span class="text-3 fw-5">(xodimlar faoliyatida o'tkazib yuboriladi)</span></span><input class="input" name="monitor_name" value="<?= e(setting('lcup.monitor_name', '')) ?>" placeholder="LC-UP dagi xodim nomi, masalan: Monitor"></label>
    </div>
    <p class="hint">Parol va tokenlar faqat yoziladi, qayta ko'rsatilmaydi. Maydon bo'sh qolsa eski qiymat o'zgarmaydi. Har o'zgarish jurnalga (maydon nomi, qiymatsiz) yoziladi va Telegram'ga darhol xabar ketadi.</p>
    <div class="row gap-2 row-wrap"><button class="btn btn-primary" type="submit"><?= icon('check', 'i-sm') ?> Saqlash</button></div>
  </form>

  <div class="field"><span class="label">Cron havolasi <span class="text-3 fw-5">(cron-job.org, har 1 daqiqa; faqat administratorga ko'rinadi)</span></span>
    <div class="row gap-2"><div class="pre-box grow" id="lcCronUrl" data-base="<?= e($appUrl) ?>/cron.php?token="><?= $hasCron ? e($cronUrl) : 'CRON_TOKEN hali sozlanmagan' ?></div><button type="button" class="btn" id="lcCopyUrl" <?= $hasCron ? '' : 'disabled' ?>><?= icon('link', 'i-sm') ?> Nusxa</button></div>
    <span class="hint">Hostinger cron (CLI): <code>/usr/bin/php <?= e(realpath(__DIR__ . '/..') ?: dirname(__DIR__)) ?>/cron.php</code></span></div>

  <div class="lc-check">
    <div class="<?= $cfgInfo['exists'] ? 'ok' : 'bad' ?>">config.php: <?= $cfgInfo['exists'] ? e($cfgInfo['file']) . ' · format: ' . e($cfgInfo['mode']) . ' · o\'zgargan: ' . e($cfgInfo['mtime'] ? date('d.m.Y H:i', $cfgInfo['mtime']) : '?') : "hali yo'q — «Saqlash» uni yaratadi" ?></div>
    <?php if ($cfgInfo['error']): ?><div class="bad"><?= e($cfgInfo['error']) ?></div><?php endif; ?>
    <div class="<?= $cfgInfo['keys'] ? 'ok' : 'warn' ?>">Topilgan kalitlar: <?= $cfgInfo['keys'] ? e(implode(', ', $cfgInfo['keys'])) : 'hech biri' ?></div>
  </div>
  <div class="row gap-2 row-wrap">
    <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_selftest"><input type="hidden" name="back" value="admin.php#settings"><button class="btn btn-soft" type="submit"><?= icon('check-check', 'i-sm') ?> Ulanishni tekshirish</button></form>
    <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_test_telegram"><input type="hidden" name="back" value="admin.php#settings"><button class="btn" type="submit"><?= icon('send', 'i-sm') ?> Test xabar</button></form>
  </div>
  <?php if ($lcTest): ?><div class="lc-check"><?php foreach ($lcTest as [$tok, $t]): ?><div class="<?= $tok === true ? 'ok' : ($tok === false ? 'bad' : 'warn') ?>"><?= e($t) ?></div><?php endforeach; ?></div><?php endif; ?>
</section>
<script>
(function () {
  var g = document.getElementById('lcGenTok'), t = document.getElementById('lcCronTok'), u = document.getElementById('lcCronUrl'), c = document.getElementById('lcCopyUrl');
  function upd() { if (u && t && t.value) { u.textContent = u.getAttribute('data-base') + t.value + '  (saqlanmagan — «Saqlash» ni bosing)'; if (c) c.disabled = false; } }
  if (g) g.addEventListener('click', function () { var a = new Uint8Array(24); crypto.getRandomValues(a); t.value = Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); upd(); t.focus(); });
  if (t) t.addEventListener('input', upd);
  if (c) c.addEventListener('click', function () { var s = (u.textContent || '').replace(/\s+\(saqlanmagan.*$/, ''); (navigator.clipboard ? navigator.clipboard.writeText(s) : Promise.reject()).then(function () { c.textContent = 'Nusxalandi ✓'; }, function () { var r = document.createRange(); r.selectNodeContents(u); var sel = getSelection(); sel.removeAllRanges(); sel.addRange(r); }); });
})();
</script>
