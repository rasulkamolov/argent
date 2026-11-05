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
