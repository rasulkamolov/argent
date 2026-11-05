<?php
require_once __DIR__ . '/layout.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$trans = tx_authorized($id);
if (!$trans) { flash_set('error', "Tranzaksiya topilmadi yoki sizga ruxsat yo'q."); header("Location: index.php"); exit(); }
if (!can_edit_tx((int)$trans['branch_id'])) {
    flash_set('error', "Tahrirlash uchun ruxsat yo'q. Administrator tahrirlashni o'chirib qo'ygan.");
    header("Location: index.php?date=" . urlencode($trans['date'])); exit();
}

$is_in = $trans['cash_in'] == 1;
$cats = array_values(array_filter(categories_for_branch((int)$trans['branch_id']), function ($c) use ($is_in) { return $c['type'] === ($is_in ? 'income' : 'expense'); }));
$curMethod = method_of($trans);
$back = 'index.php?date=' . urlencode($trans['date']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrf_valid()) { flash_set('error', "Sessiya muddati tugadi, qayta urinib ko'ring."); header("Location: edit_transaction.php?id=$id"); exit(); }
    $pay = floatval(preg_replace('/[^\d.]/', '', (string)($_POST['payment'] ?? '')));
    $com = trim($_POST['comment'] ?? '');
    $method = in_array($_POST['method'] ?? '', ['cash', 'click', 'bank'], true) ? $_POST['method'] : 'cash';
    $cat = (int)($_POST['category_id'] ?? 0) ?: null;
    if ($cat && !in_array($cat, array_map('intval', array_column($cats, 'id')), true)) $cat = null;
    if ($pay <= 0) { flash_set('error', "Summani kiriting"); header("Location: edit_transaction.php?id=$id"); exit(); }

    $log = function ($field, $old, $new) use ($conn, $id) {
        $conn->prepare("INSERT INTO transaction_history (transaction_id, user_id, field_name, old_value, new_value, changed_at) VALUES (?, ?, ?, ?, ?, datetime('now','localtime'))")
             ->execute([$id, $_SESSION['user_id'], $field, $old, $new]);
    };
    $changed = false;
    if ($pay != $trans['payment']) { $log('Summa', fmt($trans['payment']), fmt($pay)); $changed = true; }
    if ($method !== $curMethod) { $log('To\'lov usuli', method_meta($curMethod)['label'], method_meta($method)['label']); $changed = true; }
    if ($cat !== ($trans['category_id'] ? (int)$trans['category_id'] : null)) {
        $nn = $cat ? $conn->query("SELECT name FROM categories WHERE id=" . (int)$cat)->fetchColumn() : '—';
        $log('Kategoriya', $trans['category_name'] ?: '—', $nn ?: '—'); $changed = true;
    }
    if ($com !== trim((string)$trans['comment'])) { $log('Izoh', (string)$trans['comment'], $com); $changed = true; }

    if ($changed) {
        $conn->prepare("UPDATE transactions SET payment=?, comment=?, cash=?, click=?, bank=?, category_id=?, is_modified=1 WHERE id=?")
             ->execute([$pay, $com, $method == 'cash' ? 1 : 0, $method == 'click' ? 1 : 0, $method == 'bank' ? 1 : 0, $cat, $id]);
        audit('tx.update', 'transaction', $id, ['amount' => $pay, 'method' => $method], (int)$trans['branch_id']);
        flash_set('ok', "O'zgarishlar saqlandi");
    }
    header("Location: $back"); exit();
}

ui_head('Tahrirlash');
ui_start('daily');
?>
<div class="page page-narrow">
  <a class="btn btn-ghost btn-sm" href="<?= e($back) ?>" style="margin-left:-8px;margin-bottom:8px"><?= icon('arrow-left', 'i-sm') ?> Orqaga</a>
  <div class="page-head">
    <div>
      <h1 class="page-title">Tranzaksiyani tahrirlash</h1>
      <p class="page-sub row row-wrap" style="gap:8px"><?= ui_scope_chip((int)$trans['branch_id']) ?><?= e(uz_weekday($trans['date'])) ?>, <?= e(uz_date($trans['date'])) ?> · <span class="badge <?= $is_in ? 'badge-in' : 'badge-out' ?>"><?= $is_in ? 'Kirim' : 'Chiqim' ?></span></p>
    </div>
    <a class="btn btn-sm" href="view_history.php?id=<?= $id ?>"><?= icon('history', 'i-sm') ?> Tarix</a>
  </div>

  <form method="post" class="card card-pad stack" data-loading>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="field">
      <label class="label" for="payInput">Summa</label>
      <div class="money-field">
        <input class="input" type="text" inputmode="numeric" id="payInput" name="payment" value="<?= e(fmt($trans['payment'])) ?>" data-money required autocomplete="off">
        <span class="unit">so'm</span>
      </div>
    </div>
    <div class="field">
      <span class="label">To'lov usuli</span>
      <div class="method-pick" role="radiogroup">
        <?php foreach (['cash','click','bank'] as $k): $mm = method_meta($k); ?>
        <label><input type="radio" name="method" value="<?= $k ?>" <?= $curMethod === $k ? 'checked' : '' ?>><?= icon($mm['icon']) ?><?= e($mm['label']) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="field">
      <label class="label" for="catSel">Kategoriya</label>
      <select class="select" name="category_id" id="catSel">
        <option value="">— Kategoriyasiz —</option>
        <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$trans['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="label" for="comm">Izoh</label>
      <textarea class="textarea" id="comm" name="comment" rows="3" data-autogrow><?= e($trans['comment']) ?></textarea>
    </div>
    <div class="row gap-2" style="justify-content:flex-end;flex-wrap:wrap">
      <a class="btn" href="<?= e($back) ?>">Bekor qilish</a>
      <button class="btn btn-primary" type="submit"><?= icon('check', 'i-sm') ?> Saqlash</button>
    </div>
    <p class="hint">Barcha o'zgarishlar «Tarix» bo'limida saqlanadi.</p>
  </form>

  <?php if ($trans['click'] || $trans['bank']): $hasProof = !empty($trans['proof']); $mayReplace = is_admin() || is_manager(); ?>
  <section class="card card-pad stack" style="margin-top:16px" aria-labelledby="prTitle">
    <div class="row"><span class="icon-tile <?= $hasProof ? 'in' : 'out' ?>"><?= icon($hasProof ? 'image' : 'image-off') ?></span>
      <div class="grow"><h2 class="card-title" id="prTitle">To'lov skrinshoti</h2><p class="fs-sm text-3"><?= $hasProof ? "Biriktirilgan — to'lov isboti sifatida saqlanadi" : "Hali biriktirilmagan" ?></p></div></div>
    <?php if ($hasProof): ?>
      <button type="button" class="proof-thumb" data-proof-view="<?= (int)$trans['id'] ?>" style="padding:0;cursor:zoom-in" aria-label="Skrinshotni kattalashtirish"><img src="proof.php?id=<?= (int)$trans['id'] ?>" alt="To'lov skrinshoti" loading="lazy"></button>
    <?php endif; ?>
    <?php if (!$hasProof || $mayReplace): ?>
    <form action="process.php" method="post" enctype="multipart/form-data" class="stack" data-loading>
      <?= csrf_field() ?><input type="hidden" name="action" value="attach_proof"><input type="hidden" name="id" value="<?= (int)$trans['id'] ?>">
      <?= ui_proof_drop(true, 'proof') ?>
      <button class="btn btn-primary" type="submit"><?= icon('image-plus', 'i-sm') ?> <?= $hasProof ? 'Almashtirish' : 'Biriktirish' ?></button>
    </form>
    <?php endif; ?>
    <?php if ($hasProof && $mayReplace): ?>
    <a class="btn btn-danger-soft btn-sm" data-post href="process.php?action=remove_proof&id=<?= (int)$trans['id'] ?>" data-confirm="Skrinshot o'chiriladi." data-confirm-title="Skrinshotni o'chirasizmi?" data-confirm-ok="O'chirish"><?= icon('trash-2', 'i-sm') ?> Skrinshotni o'chirish</a>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php ui_end(['active' => 'daily']);
