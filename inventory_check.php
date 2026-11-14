<?php
/**
 * Inventarizatsiya tekshiruvi (Administrator only). Argent picks 5–10 products per branch (every Kitob product and the best sellers always),
 * somebody counts them on the shelf and types the numbers. The system quantity is NOT shown (not even in the page source) until the count of that product is saved.
 */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/shop_inv.php';
require_admin();

$B = branches_all(); $mapIds = shop_branch_ids(); $err = ''; $openChecks = []; $hist = [];
try {
    if (!shop_configured()) throw new ShopError("Do'konPro bazasi topilmadi. Admin → Do'kon da yo'lni kiriting.", 'config');
    shop_resolve_map();
} catch (Throwable $e) { $err = lcup_redact($e->getMessage()); }
$st = db()->query("SELECT * FROM shop_inv_checks WHERE status = 'open' ORDER BY id"); $openChecks = $st->fetchAll();
$hist = db()->query("SELECT c.*, u.username FROM shop_inv_checks c LEFT JOIN users u ON u.id = c.created_by WHERE c.status <> 'open' ORDER BY c.id DESC LIMIT 30")->fetchAll();
$show = (int)($_GET['check'] ?? 0);
ui_head('Inventarizatsiya tekshiruvi'); ui_start('shopinv');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Inventarizatsiya tekshiruvi</h1><p class="page-sub">Tovarni sanab, sonini kiriting — tizimdagi qoldiq sanab bo'lingandan keyingina ko'rsatiladi</p></div>
  </div>
  <?php ui_shop_subnav('inv'); ?>
  <?php if ($err): ?><div class="alert error" style="margin-top:12px"><?= icon('triangle-alert') ?><div class="grow"><?= e($err) ?></div></div><?php endif; ?>

  <?php if (!$err): ?>
  <section class="card card-pad stack" style="margin:14px 0">
    <div class="row"><span class="icon-tile primary"><?= icon('clipboard-check') ?></span><div class="grow"><h2 class="card-title">Yangi tekshiruv</h2><p class="fs-sm text-3">Tanlangan filialdan <?= (int)max(5, min(10, shop_num('inv_count'))) ?> ta tovar tanlanadi: barcha «<?= e(shop_setting('kitob_category')) ?>» tovarlari va eng ko'p sotiladiganlar doim kiradi, qolgani tasodifiy.</p></div></div>
    <form action="process.php" method="post" class="row row-wrap" style="gap:8px" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="shop_inv_start">
      <select class="select" name="branch" style="max-width:260px" aria-label="Filial"><?php foreach ($mapIds as $id): ?><option value="<?= (int)$id ?>"><?= e($B[$id]['name'] ?? $id) ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary" type="submit"><?= icon('plus', 'i-sm') ?> Tekshiruvni boshlash</button></form>
  </section>

  <?php foreach ($openChecks as $c): $items = shop_inv_items((int)$c['id'], false); $left = count(array_filter($items, function ($i) { return !$i['counted']; })); ?>
  <section class="card card-pad stack" style="margin-bottom:14px" id="check<?= (int)$c['id'] ?>">
    <div class="row row-wrap"><span class="icon-tile out"><?= icon('clipboard-check') ?></span><div class="grow"><h2 class="card-title">Tekshiruv #<?= (int)$c['id'] ?> · <?= e($B[(int)$c['branch_id']]['name'] ?? '') ?></h2><p class="fs-sm text-3">Boshlangan: <?= e(date('d.m.Y H:i', strtotime($c['created_at']))) ?> · sanalmagan: <?= $left ?> ta</p></div>
      <button class="btn btn-sm no-print" type="button" onclick="window.print()"><?= icon('printer', 'i-sm') ?> Varaqni chop etish</button></div>
    <form action="process.php" method="post" class="stack" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="shop_inv_save"><input type="hidden" name="check" value="<?= (int)$c['id'] ?>">
      <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Tovar</th><th>Kategoriya</th><th class="r" style="width:190px">Sanalgan soni</th></tr></thead><tbody>
        <?php foreach ($items as $it): ?><tr><td class="cell-title"><b><?= e($it['product_name']) ?></b></td><td data-label="Kategoriya"><?= e($it['category'] ?: '—') ?></td>
          <td class="r" data-label="Sanalgan soni"><?php if ($it['counted']): ?><span class="badge badge-in">✓ saqlangan</span><?php else: ?><input class="input input-sm" style="max-width:150px;text-align:right" name="count[<?= (int)$it['id'] ?>]" inputmode="decimal" autocomplete="off" placeholder="0" aria-label="<?= e($it['product_name']) ?> soni"><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <p class="hint">Soningni kiritib «Saqlash» bosilgach, har bir tovarning tizimdagi qoldig'i bilan farqi hisoblanadi va qayta o'zgartirib bo'lmaydi. Hisoblash vaqtida sotuv bo'lsa — qoldiq saqlash paytidagi holat bo'yicha olinadi, shuning uchun sanash va saqlash orasini qisqa tuting.</p>
      <div class="row gap-2 row-wrap"><button class="btn btn-primary" type="submit"><?= icon('check', 'i-sm') ?> Saqlash va solishtirish</button></div>
    </form>
    <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="shop_inv_cancel"><input type="hidden" name="check" value="<?= (int)$c['id'] ?>"><button class="btn btn-danger-soft btn-sm" type="submit" data-confirm="Tekshiruv bekor qilinadi (tarixda qoladi)." data-confirm-title="Bekor qilinsinmi?" data-confirm-ok="Bekor qilish"><?= icon('x', 'i-sm') ?> Bekor qilish</button></form>
    <?php $done = array_filter(shop_inv_items((int)$c['id'], true), function ($i) { return $i['counted']; }); if ($done): ?>
    <h3 class="card-title">Natija (sanalganlar)</h3><?php $rows = $done; include __DIR__ . '/shop_inv_table.php'; ?><?php endif; ?>
  </section>
  <?php endforeach; ?>

  <section class="card clip" aria-label="Tarix"><div class="card-head"><h2 class="card-title"><?= icon('history', 'i-sm') ?> Tarix</h2></div>
    <div class="table-wrap"><table class="table table-stack"><thead><tr><th>#</th><th>Filial</th><th>Sana</th><th>Holat</th><th class="r">Kamomad (dona)</th><th class="r">Tannarx bo'yicha</th><th class="r">Sotuv narxi bo'yicha</th><th class="r">Ortiqcha (tannarx)</th></tr></thead><tbody>
      <?php foreach ($hist as $c): $t = shop_inv_totals((int)$c['id']); ?>
      <tr><td class="cell-title"><a href="inventory_check.php?check=<?= (int)$c['id'] ?>#h<?= (int)$c['id'] ?>" style="text-decoration:underline">#<?= (int)$c['id'] ?></a><div class="fs-xs text-3"><?= e($c['username'] ?: '') ?></div></td><td data-label="Filial"><?= e($B[(int)$c['branch_id']]['name'] ?? '') ?></td>
        <td data-label="Sana"><?= e(date('d.m.Y H:i', strtotime($c['completed_at'] ?: $c['created_at']))) ?></td><td data-label="Holat"><?= $c['status'] === 'done' ? '<span class="badge badge-in">Tugagan</span>' : '<span class="badge">Bekor qilingan</span>' ?></td>
        <td class="r num <?= $t['short_qty'] > 0 ? 'text-out fw-7' : '' ?>" data-label="Kamomad"><?= e(shop_qty($t['short_qty'])) ?></td><td class="r num" data-label="Tannarx"><?= e(fmt($t['short_cost'])) ?></td><td class="r num" data-label="Sotuv narxi"><?= e(fmt($t['short_sale'])) ?></td><td class="r num" data-label="Ortiqcha"><?= e(fmt($t['over_cost'])) ?></td></tr>
      <?php if ($show === (int)$c['id']): ?><tr id="h<?= (int)$c['id'] ?>"><td colspan="8"><?php $rows = shop_inv_items((int)$c['id'], true); include __DIR__ . '/shop_inv_table.php'; ?></td></tr><?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$hist): ?><tr><td colspan="8"><div class="empty"><span class="icon-tile in"><?= icon('clipboard-check') ?></span><b>Tarix bo'sh</b><span class="fs-sm">Birinchi tekshiruvni yuqoridan boshlang.</span></div></td></tr><?php endif; ?>
    </tbody></table></div></section>
  <?php endif; ?>
</div>
<?php ui_end(['active' => 'shopinv']);
