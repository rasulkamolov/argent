<?php defined('ADMIN_PAGE') || exit; ?>
<div class="row between row-wrap">
  <p class="text-3 fs-sm">Har bir filial alohida kassa, kassirlar va hisobotga ega. Kassir faqat o'z filialini ko'radi.</p>
  <button class="btn btn-primary" type="button" id="newBranch"><?= icon('plus', 'i-sm') ?> Yangi filial</button>
</div>
<div class="grid g-auto" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr))">
  <?php foreach ($ov as $r): $b = $r['b']; $id = (int)$b['id'];
     $pe = setting('staff_can_edit', 'default', $id); $pd = setting('staff_can_delete', 'default', $id);
     $ge = setting('staff_can_edit', '1') === '1'; $gd = setting('staff_can_delete', '0') === '1';
     $own = array_key_exists("b$id.staff_can_edit", settings_all()) ? setting('staff_can_edit', '1', $id) : 'default';
     $own2 = array_key_exists("b$id.staff_can_delete", settings_all()) ? setting('staff_can_delete', '0', $id) : 'default'; ?>
  <article class="card bcard" style="--bc:<?= e(branch_color($b)) ?>;<?= $b['is_active'] ? '' : 'opacity:.78' ?>">
    <div class="row" style="align-items:flex-start">
      <?= branch_avatar($b, 'lg') ?>
      <div class="grow">
        <div class="row between" style="gap:8px"><h3 class="fw-8" style="font-size:17px;letter-spacing:-.02em"><?= e($b['name']) ?></h3><?= $b['is_active'] ? '<span class="badge badge-in">● Faol</span>' : '<span class="badge">Arxiv</span>' ?></div>
        <div class="fs-sm text-3"><?= $b['address'] ? icon('map-pin', 'i-xs') . ' ' . e($b['address']) : '&nbsp;' ?></div>
        <?php if ($b['phone']): ?><div class="fs-sm text-3"><?= icon('phone', 'i-xs') ?> <?= e($b['phone']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="mini-stats">
      <div><div class="k">Kassada</div><div class="v num"><?= $r['tracked'] ? e(fmt_short($r['bal']['total'])) : '<span class="text-3" title="Qoldiq sozlanmagan">—</span>' ?></div></div>
      <div><div class="k">Oy kirimi</div><div class="v num text-in"><?= e(fmt_short($r['m_in'])) ?></div></div>
      <div><div class="k">Yozuvlar</div><div class="v num"><?= fmt($r['tx']) ?></div></div>
    </div>
    <div class="row row-wrap fs-sm text-2" style="gap:6px 14px">
      <span><?= icon('users', 'i-xs') ?> <b><?= (int)$r['cashiers'] ?></b> kassir</span>
      <?php if ($r['managers']): ?><span><?= icon('user-cog', 'i-xs') ?> <b><?= (int)$r['managers'] ?></b> menejer</span><?php endif; ?>
      <span><?= icon('briefcase', 'i-xs') ?> <b><?= (int)$r['emps'] ?></b> xodim</span>
      <?php if ($r['last']): ?><span><?= icon('clock', 'i-xs') ?> <?= e(date('d.m.Y', strtotime($r['last']))) ?></span><?php endif; ?>
    </div>
    <form action="process.php" method="post" class="grid g-2" style="gap:8px">
      <?= csrf_field() ?><input type="hidden" name="action" value="set_branch_setting"><input type="hidden" name="branch_id" value="<?= $id ?>"><input type="hidden" name="key" value="">
      <label class="field"><span class="label">Kassir tahrirlashi</span>
        <select class="select input-sm" data-bset="staff_can_edit">
          <option value="default" <?= $own === 'default' ? 'selected' : '' ?>>Standart (<?= $ge ? 'Ha' : "Yo'q" ?>)</option><option value="1" <?= $own === '1' ? 'selected' : '' ?>>Ha</option><option value="0" <?= $own === '0' ? 'selected' : '' ?>>Yo'q</option></select></label>
      <label class="field"><span class="label">Kassir o'chirishi</span>
        <select class="select input-sm" data-bset="staff_can_delete">
          <option value="default" <?= $own2 === 'default' ? 'selected' : '' ?>>Standart (<?= $gd ? 'Ha' : "Yo'q" ?>)</option><option value="1" <?= $own2 === '1' ? 'selected' : '' ?>>Ha</option><option value="0" <?= $own2 === '0' ? 'selected' : '' ?>>Yo'q</option></select></label>
      <label class="field" style="grid-column:1/-1"><span class="label">Click/Bank isboti (fayl) majburiy</span>
        <select class="select input-sm" data-bset="require_proof">
          <?php $own3 = array_key_exists("b$id.require_proof", settings_all()) ? setting('require_proof', '1', $id) : 'default'; $gp3 = setting('require_proof', '1') === '1'; ?>
          <option value="default" <?= $own3 === 'default' ? 'selected' : '' ?>>Standart (<?= $gp3 ? 'Ha' : "Yo'q" ?>)</option><option value="1" <?= $own3 === '1' ? 'selected' : '' ?>>Ha</option><option value="0" <?= $own3 === '0' ? 'selected' : '' ?>>Yo'q</option></select></label>
      <input type="hidden" name="value" value="">
    </form>
    <div class="row gap-2 row-wrap" style="margin-top:auto">
      <a class="btn btn-sm btn-soft" href="process.php?action=switch_branch&id=<?= $id ?>&back=index.php"><?= icon('arrow-right', 'i-sm') ?> Ochish</a>
      <button type="button" class="btn btn-sm" data-edit-branch
        data-id="<?= $id ?>" data-name="<?= e($b['name']) ?>" data-address="<?= e($b['address']) ?>" data-phone="<?= e($b['phone']) ?>" data-note="<?= e($b['note']) ?>"
        data-color="<?= (int)$b['color'] ?>" data-tracked="<?= $r['tracked'] ? 1 : 0 ?>" data-cash="<?= (int)round($r['bal']['cash']) ?>" data-click="<?= (int)round($r['bal']['click']) ?>" data-bank="<?= (int)round($r['bal']['bank']) ?>"><?= icon('pencil', 'i-sm') ?> Tahrirlash</button>
      <a class="btn btn-sm btn-icon" href="backup.php?branch=<?= $id ?>" aria-label="Filial ma'lumotini yuklab olish" title="Faqat shu filial ma'lumotini yuklab olish (.sqlite)"><?= icon('download', 'i-sm') ?></a>
      <a class="btn btn-sm btn-icon" href="process.php?action=toggle_branch&id=<?= $id ?>" data-post
         <?php if ($b['is_active']): ?>data-confirm="«<?= e($b['name']) ?>» arxivlanadi: kassirlar kira olmaydi, ma'lumotlar saqlanadi." data-confirm-title="Filialni arxivlaysizmi?" data-confirm-ok="Arxivlash"<?php endif; ?>
         aria-label="<?= $b['is_active'] ? 'Arxivlash' : 'Faollashtirish' ?>" title="<?= $b['is_active'] ? 'Arxivlash' : 'Faollashtirish' ?>"><?= icon($b['is_active'] ? 'archive' : 'circle-check', 'i-sm') ?></a>
      <button type="button" class="btn btn-sm btn-icon btn-danger-soft" data-del-branch data-id="<?= $id ?>" data-name="<?= e($b['name']) ?>" data-tx="<?= (int)$r['tx'] ?>" aria-label="O'chirish" title="O'chirish"><?= icon('trash-2', 'i-sm') ?></button>
    </div>
  </article>
  <?php endforeach; ?>
</div>
