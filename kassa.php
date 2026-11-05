<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_lib.php';
require_manager();                                         // cashiers have no cash book

$f = rl_filters();
$branch = (int)$f['branch'];                               // 0 = all (admin only)
$ids = $branch ? [$branch] : visible_branch_ids();
$in_ids = implode(',', array_map('intval', $ids)) ?: '0';
$start = $f['start']; $end = $f['end'];
if ($end > date('Y-m-d')) $end = date('Y-m-d');            // cash book never looks into the future
if ($start > $end) $start = $end;

$open  = branch_balances($branch, null, $start);           // money before the period
$close = branch_balances($branch, $end);

$st = db()->prepare("SELECT t.date, SUM(CASE WHEN t.cash_in=1 THEN t.payment ELSE 0 END) AS inc, SUM(CASE WHEN t.cash_in<>1 THEN t.payment ELSE 0 END) AS exp, COUNT(*) AS n
                     FROM transactions t WHERE t.branch_id IN ($in_ids) AND t.date BETWEEN ? AND ? GROUP BY t.date ORDER BY t.date");
$st->execute([$start, $end]);
$run = $open['total']; $book = []; $tin = $tout = 0;
foreach ($st->fetchAll() as $r) {
    $o = $run; $run += $r['inc'] - $r['exp'];
    $book[] = ['date' => $r['date'], 'open' => $o, 'in' => (float)$r['inc'], 'out' => (float)$r['exp'], 'close' => $run, 'n' => (int)$r['n']];
    $tin += $r['inc']; $tout += $r['exp'];
}
$book = array_reverse($book);

// method flows in period
$st = db()->prepare("SELECT " . SQL_METHOD . " AS m, SUM(CASE WHEN t.cash_in=1 THEN t.payment ELSE 0 END) AS inc, SUM(CASE WHEN t.cash_in<>1 THEN t.payment ELSE 0 END) AS exp
                     FROM transactions t WHERE t.branch_id IN ($in_ids) AND t.date BETWEEN ? AND ? GROUP BY m");
$st->execute([$start, $end]); $mflow = ['cash' => ['inc' => 0, 'exp' => 0], 'click' => ['inc' => 0, 'exp' => 0], 'bank' => ['inc' => 0, 'exp' => 0]];
foreach ($st->fetchAll() as $r) $mflow[$r['m']] = ['inc' => (float)$r['inc'], 'exp' => (float)$r['exp']];

// cash counts
$st = db()->prepare("SELECT c.*, u.username, b.name AS branch_name FROM cash_counts c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN branches b ON b.id = c.branch_id
                     WHERE c.branch_id IN ($in_ids) ORDER BY c.id DESC LIMIT 25");
$st->execute(); $counts = $st->fetchAll();
$cash_today = $branch ? branch_balances($branch, date('Y-m-d'))['cash'] : null;
$ti = tracking_info($branch);
$can_count = $branch && can_access_branch($branch) && branch_get($branch)['is_active'] && branch_tracked($branch);
$count_total_diff = 0; foreach ($counts as $c) $count_total_diff += $c['diff'];

$qs = rl_qs($f);
$range_label = $start === $end ? uz_date($start) : uz_date($start) . ' — ' . uz_date($end);
ui_head('Kassa kitobi');
ui_start('cashbook');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Kassa kitobi</h1><p class="page-sub row row-wrap" style="gap:8px"><?= ui_scope_chip($branch) ?><span><?= e($range_label) ?></span></p></div>
    <div class="row gap-2 no-print row-wrap">
      <?php if ($can_count): ?><button class="btn btn-primary" type="button" data-open="#countDialog"><?= icon('calculator', 'i-sm') ?> Kassani sanash</button><?php endif; ?>
      <button class="btn" type="button" onclick="window.print()"><?= icon('printer', 'i-sm') ?> <span class="hide-sm">Chop etish</span></button>
    </div>
  </div>
  <?php if (can_view_reports()): ?><div class="no-print" style="margin-bottom:16px"><?php ui_report_subnav('cashbook', $qs); ?></div><?php endif; ?>
  <?php rl_render_filters($conn, $f, 'kassa.php', [], false, true, false); ?>

  <?php if (!$ti['any']): ?>
  <div class="alert info" style="margin-top:16px;background:var(--warn-soft);color:var(--warn-text)"><?= icon('triangle-alert') ?><div class="grow"><b>Kassa qoldig'i sozlanmagan.</b> Kassadagi haqiqiy pul miqdori kiritilmaguncha qoldiq ko'rsatilmaydi — quyida faqat kirim-chiqim harakati.<?= is_admin() ? ' <a href="admin.php#branches" style="text-decoration:underline">Admin → Filiallar → Tahrirlash</a>' : " Administratorga murojaat qiling." ?></div></div>
  <?php elseif ($ti['untracked']): ?>
  <div class="alert info" style="margin-top:16px"><?= icon('info') ?><div class="grow"><?= $ti['untracked'] ?> ta filialda qoldiq sozlanmagan — ular jami qoldiqqa kiritilmagan.</div></div>
  <?php endif; ?>
  <section class="grid g-2 lg-g-4" style="margin-top:16px" aria-label="Kassa xulosasi">
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Davr boshiga</span><span class="icon-tile primary" style="width:32px;height:32px;border-radius:10px"><?= icon('arrow-right', 'i-sm') ?></span></div>
      <div class="kpi-value num"><?= $ti['any'] ? e(fmt_short($open['total'])) : '—' ?></div><div class="kpi-foot">qoldiq</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Kirim</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('arrow-down-left', 'i-sm') ?></span></div>
      <div class="kpi-value num text-in">+<?= e(fmt_short($tin)) ?></div><div class="kpi-foot"><?= count($book) ?> ta faol kun</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Chiqim</span><span class="icon-tile out" style="width:32px;height:32px;border-radius:10px"><?= icon('arrow-up-right', 'i-sm') ?></span></div>
      <div class="kpi-value num text-out">−<?= e(fmt_short($tout)) ?></div><div class="kpi-foot">&nbsp;</div></div>
    <div class="card kpi hero2 span-full-m"><div class="kpi-top"><span class="kpi-label"><?= $ti['any'] ? 'Davr oxiriga' : 'Davr natijasi' ?></span><?= icon('wallet') ?></div>
      <div class="kpi-value num"><?= $ti['any'] ? e(fmt_signed($close['total'])) . "<small>so'm</small>" : e(fmt_signed($tin - $tout)) . "<small>so'm</small>" ?></div><div class="kpi-foot"><?= $ti['any'] ? 'kassadagi jami pul' : 'davr natijasi (kirim − chiqim)' ?></div></div>
  </section>

  <section class="card clip" style="margin-top:16px" aria-labelledby="mTitle">
    <div class="card-head"><h2 class="card-title" id="mTitle"><?= icon('credit-card', 'i-sm') ?> To'lov usullari bo'yicha qoldiq</h2></div>
    <div class="table-wrap">
      <table class="table table-stack">
        <thead><tr><th>Usul</th><?php if ($ti['any']): ?><th class="r">Boshiga</th><?php endif; ?><th class="r">Kirim</th><th class="r">Chiqim</th><th class="r"><?= $ti['any'] ? 'Oxiriga' : 'Natija' ?></th></tr></thead>
        <tbody>
        <?php foreach (['cash', 'click', 'bank'] as $m): $mm = method_meta($m); ?>
          <tr>
            <td class="cell-title"><span class="row gap-2"><span class="dot dot-<?= $m ?>"></span><b><?= e($mm['label']) ?></b></span></td>
            <?php if ($ti['any']): ?><td class="r num" data-label="Boshiga"><?= e(fmt_signed($open[$m])) ?></td><?php endif; ?>
            <td class="r num text-in" data-label="Kirim">+<?= fmt($mflow[$m]['inc']) ?></td>
            <td class="r num text-out" data-label="Chiqim">−<?= fmt($mflow[$m]['exp']) ?></td>
            <?php $mv = $ti['any'] ? $close[$m] : $mflow[$m]['inc'] - $mflow[$m]['exp']; ?><td class="r num fw-8 <?= $mv < 0 ? 'text-out' : '' ?>" data-label="<?= $ti['any'] ? 'Oxiriga' : 'Natija' ?>"><?= e(fmt_signed($mv)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td class="cell-title">Jami</td><?php if ($ti['any']): ?><td class="r num" data-label="Boshiga"><?= e(fmt_signed($open['total'])) ?></td><?php endif; ?><td class="r num text-in" data-label="Kirim">+<?= fmt($tin) ?></td><td class="r num text-out" data-label="Chiqim">−<?= fmt($tout) ?></td><td class="r num" data-label="<?= $ti['any'] ? 'Oxiriga' : 'Natija' ?>"><?= e(fmt_signed($ti['any'] ? $close['total'] : $tin - $tout)) ?></td></tr></tfoot>
      </table>
    </div>
  </section>

  <section class="card clip" style="margin-top:16px" aria-labelledby="bkTitle">
    <div class="card-head"><h2 class="card-title" id="bkTitle"><?= icon('calendar-days', 'i-sm') ?> Kunlar bo'yicha kassa</h2><span class="badge"><?= count($book) ?> kun</span></div>
    <?php if ($book): ?>
    <div class="table-scroll" style="max-height:520px">
      <table class="table table-stack">
        <thead><tr><th>Sana</th><?php if ($ti['any']): ?><th class="r">Kun boshiga</th><?php endif; ?><th class="r">Kirim</th><th class="r">Chiqim</th><th class="r"><?= $ti['any'] ? 'Kun oxiriga' : 'Natija' ?></th></tr></thead>
        <tbody>
        <?php foreach ($book as $d): ?>
          <tr>
            <td class="cell-title"><a href="index.php?date=<?= e($d['date']) ?>" style="text-decoration:underline;text-underline-offset:3px;text-decoration-color:var(--border-strong)"><?= e(date('d.m.Y', strtotime($d['date']))) ?></a> <span class="text-3 fs-xs fw-5" style="margin-left:6px"><?= e(uz_weekday($d['date'], true)) ?> · <?= $d['n'] ?> ta</span></td>
            <?php if ($ti['any']): ?><td class="r num text-2" data-label="Kun boshiga"><?= e(fmt_signed($d['open'])) ?></td><?php endif; ?>
            <td class="r num text-in fw-6" data-label="Kirim"><?= $d['in'] > 0 ? '+' . fmt($d['in']) : '—' ?></td>
            <td class="r num text-out fw-6" data-label="Chiqim"><?= $d['out'] > 0 ? '−' . fmt($d['out']) : '—' ?></td>
            <?php $dv = $ti['any'] ? $d['close'] : $d['in'] - $d['out']; ?><td class="r num fw-8 <?= $dv < 0 ? 'text-out' : '' ?>" data-label="<?= $ti['any'] ? 'Kun oxiriga' : 'Natija' ?>"><?= e(fmt_signed($dv)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><div class="empty"><div class="icon-tile"><?= icon('inbox', 'i-lg') ?></div><b>Bu davrda harakat yo'q</b><span class="fs-sm">Tanlangan davrda kirim-chiqim yo'q.</span></div><?php endif; ?>
  </section>

  <section class="card clip" style="margin-top:16px" aria-labelledby="ccTitle">
    <div class="card-head"><h2 class="card-title" id="ccTitle"><?= icon('calculator', 'i-sm') ?> Kassa sanash tarixi</h2>
      <?php if ($counts): ?><span class="badge <?= abs($count_total_diff) < 1 ? 'badge-in' : ($count_total_diff < 0 ? 'badge-danger' : 'badge-warn') ?>">Jami farq: <?= e(fmt_signed($count_total_diff)) ?></span><?php endif; ?></div>
    <?php if ($counts): ?>
    <div class="table-wrap">
      <table class="table table-stack">
        <thead><tr><th>Vaqt</th><?php if (!$branch): ?><th>Filial</th><?php endif; ?><th>Kim sanadi</th><th class="r">Tizimda</th><th class="r">Sanalgan</th><th class="r">Farq</th><th>Izoh</th></tr></thead>
        <tbody>
        <?php foreach ($counts as $c): $df = (float)$c['diff']; ?>
          <tr>
            <td class="cell-title"><?= e(local_datetime($c['created_at'])) ?></td>
            <?php if (!$branch): ?><td data-label="Filial"><?= e($c['branch_name']) ?></td><?php endif; ?>
            <td data-label="Kim"><?= e($c['username'] ?: '—') ?></td>
            <td class="r num" data-label="Tizimda"><?= fmt($c['expected']) ?></td>
            <td class="r num fw-7" data-label="Sanalgan"><?= fmt($c['counted']) ?></td>
            <td class="r num fw-8 <?= abs($df) < 0.5 ? 'text-in' : ($df < 0 ? 'text-out' : 'diff-pos') ?>" data-label="Farq"><?= abs($df) < 0.5 ? '✓ 0' : e(fmt_signed($df)) ?></td>
            <td class="text-2 fs-sm" data-label="Izoh"><?= e($c['note'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><div class="empty"><div class="icon-tile"><?= icon('calculator', 'i-lg') ?></div><b>Hali sanalmagan</b><span class="fs-sm">Kun oxirida kassadagi naqd pulni sanab, tizim bilan solishtiring.</span></div><?php endif; ?>
  </section>
</div>

<?php if ($can_count): ob_start(); ?>
<dialog class="modal" id="countDialog" aria-labelledby="cdTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="cdTitle">Kassani sanash</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <div class="card card-pad" style="background:var(--surface-2);box-shadow:none;margin-bottom:16px">
      <div class="row between"><span class="text-2 fw-6">Tizimda hozir naqd pul</span><b class="num" style="font-size:20px"><?= fmt($cash_today) ?> <small class="text-3 fs-sm">so'm</small></b></div>
    </div>
    <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_cash_count"><input type="hidden" name="branch_id" value="<?= (int)$branch ?>">
      <div class="field"><label class="label" for="cnt">Kassada haqiqatda necha pul bor?</label>
        <div class="money-field"><input class="input" id="cnt" name="counted" inputmode="numeric" data-money placeholder="0" required><span class="unit">so'm</span></div></div>
      <div class="field"><label class="label" for="cnote">Izoh <span class="text-3 fw-5">(ixtiyoriy)</span></label><input class="input" id="cnote" name="note" placeholder="Masalan: 50 000 bank orqali olib ketildi"></div>
      <button type="submit" class="btn btn-primary btn-lg btn-block">Saqlash va solishtirish</button>
      <p class="hint center">Farq jurnalga yoziladi va administratorga ko'rinadi.</p>
    </form>
  </div>
</dialog>
<?php $dlg = ob_get_clean(); else: $dlg = ''; endif;
ui_end(['active' => 'cashbook', 'after' => $dlg]);
