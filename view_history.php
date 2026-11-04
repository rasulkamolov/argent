<?php
require_once 'db.php';
require_once 'layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$tx = $conn->prepare("SELECT t.*, c.name AS category_name, u.username AS author FROM transactions t LEFT JOIN categories c ON t.category_id=c.id LEFT JOIN users u ON t.user_id=u.id WHERE t.id=?");
$tx->execute([$id]);
$trans = $tx->fetch(PDO::FETCH_ASSOC);

$hist = $conn->prepare("SELECT h.*, u.username FROM transaction_history h LEFT JOIN users u ON h.user_id=u.id WHERE transaction_id=? ORDER BY changed_at DESC, h.id DESC");
$hist->execute([$id]);
$logs = $hist->fetchAll(PDO::FETCH_ASSOC);
$back = $trans ? 'index.php?date=' . urlencode($trans['date']) : 'index.php';

ui_head("O'zgarishlar tarixi");
ui_start('daily');
?>
<div class="page page-narrow">
  <a class="btn btn-ghost btn-sm" href="<?= e($back) ?>" style="margin-left:-8px;margin-bottom:8px"><?= icon('arrow-left', 'i-sm') ?> Orqaga</a>
  <div class="page-head"><div><h1 class="page-title">O'zgarishlar tarixi</h1><p class="page-sub">Tranzaksiya #<?= $id ?></p></div></div>

  <?php if ($trans): $in = $trans['cash_in'] == 1; $mm = method_meta(method_of($trans)); ?>
  <div class="card card-pad row" style="margin-bottom:16px">
    <span class="icon-tile <?= $in ? 'in' : 'out' ?>"><?= icon($in ? 'arrow-down-left' : 'arrow-up-right') ?></span>
    <div class="grow">
      <div class="fw-7"><?= e($trans['category_name'] ?: ($in ? 'Kirim' : 'Chiqim')) ?></div>
      <div class="fs-xs text-3"><?= e(uz_date($trans['date'])) ?> · <?= e($mm['label']) ?> · <?= e($trans['author'] ?? '') ?></div>
    </div>
    <div class="fw-8 num <?= $in ? 'text-in' : 'text-out' ?>"><?= $in ? '+' : '−' ?><?= fmt($trans['payment']) ?></div>
  </div>
  <?php endif; ?>

  <?php if (count($logs) > 0): ?>
  <ol class="card clip list" style="list-style:none;margin:0;padding:0">
    <?php foreach ($logs as $l): ?>
    <li class="card-pad">
      <div class="row between" style="flex-wrap:wrap;gap:4px 12px">
        <span class="row gap-2"><span class="avatar avatar-sm"><?= e(mb_substr((string)$l['username'], 0, 1)) ?></span><b><?= e($l['username'] ?: 'Noma\'lum') ?></b></span>
        <time class="fs-xs text-3 num"><?= e($l['changed_at']) ?></time>
      </div>
      <div class="fs-sm text-2 mt-2"><?= e($l['field_name']) ?> o'zgartirildi</div>
      <div class="row gap-2 row-wrap mt-2">
        <span class="badge badge-out" style="text-decoration:line-through;white-space:normal;max-width:100%"><?= e($l['old_value'] === '' ? '—' : $l['old_value']) ?></span>
        <?= icon('arrow-right', 'i-sm text-3') ?>
        <span class="badge badge-in" style="white-space:normal;max-width:100%"><?= e($l['new_value'] === '' ? '—' : $l['new_value']) ?></span>
      </div>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php else: ?>
  <div class="card empty">
    <div class="icon-tile"><?= icon('history', 'i-lg') ?></div>
    <b>Hali o'zgarish yo'q</b><span class="fs-sm">Bu tranzaksiya tahrirlanmagan.</span>
  </div>
  <?php endif; ?>
</div>
<?php ui_end(['active' => 'daily']);
