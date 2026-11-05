<?php defined('ADMIN_PAGE') || exit; ?>
<div class="row between row-wrap">
  <p class="text-3 fs-sm">«Barcha filiallar» kategoriyalari hamma joyda ko'rinadi; filialga biriktirilganlari faqat o'sha filialda.</p>
  <button class="btn btn-primary" type="button" id="newCat"><?= icon('plus', 'i-sm') ?> Yangi kategoriya</button>
</div>
<div class="grid lg-g-2">
  <?php foreach ([['Kirim kategoriyalari', 'income', 'in'], ['Chiqim kategoriyalari', 'expense', 'out']] as [$ttl, $type, $k]): $list = array_values(array_filter($categories, function ($c) use ($type) { return $c['type'] === $type; })); ?>
  <section class="card clip">
    <div class="card-head"><h2 class="card-title"><span class="dot dot-<?= $k ?>"></span> <?= e($ttl) ?></h2><span class="badge badge-<?= $k ?>"><?= count($list) ?></span></div>
    <div class="list">
      <?php foreach ($list as $c): ?>
      <div class="tx" style="padding-top:10px;padding-bottom:10px;align-items:center">
        <div class="tx-main"><div class="fw-6"><?= e($c['name']) ?></div>
          <div class="fs-xs text-3"><?= $c['branch_id'] ? icon('store', 'i-xs') . ' ' . e($c['branch_name']) : 'Barcha filiallar' ?> · <?= (int)$c['uses'] ?> ta yozuv</div></div>
        <button type="button" class="btn btn-ghost btn-sm btn-icon" data-edit-cat data-id="<?= (int)$c['id'] ?>" data-name="<?= e($c['name']) ?>" data-type="<?= e($c['type']) ?>" data-branch="<?= (int)$c['branch_id'] ?>" aria-label="Tahrirlash"><?= icon('pencil', 'i-sm') ?></button>
        <a class="btn btn-ghost btn-sm btn-icon" data-post href="process.php?action=delete_category&id=<?= (int)$c['id'] ?>" data-confirm="«<?= e($c['name']) ?>» o'chiriladi.<?= $c['uses'] ? ' Unga bog\'langan ' . (int)$c['uses'] . " ta yozuv «Kategoriyasiz» bo'lib qoladi." : '' ?>" data-confirm-title="Kategoriyani o'chirasizmi?" data-confirm-ok="O'chirish" aria-label="O'chirish"><?= icon('trash-2', 'i-sm') ?></a>
      </div>
      <?php endforeach; ?>
      <?php if (!$list): ?><div class="empty" style="padding:28px"><span class="fs-sm">Kategoriya yo'q</span></div><?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
