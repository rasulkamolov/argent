<?php
require_once 'db.php';
require_once 'layout.php';
require_once 'report_lib.php';
require_admin();

$f = rl_filters();
$tab = in_array($_GET['tab'] ?? 'all', ['all', 'income', 'expense'], true) ? ($_GET['tab'] ?? 'all') : 'all';
$limit = in_array((int)($_GET['limit'] ?? 25), [10, 25, 50, 100], true) ? (int)($_GET['limit'] ?? 25) : 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$cur = rl_totals($conn, $f);
[$ps, $pe, $days] = rl_prev_range($f);
$prev = rl_totals($conn, $f, $ps, $pe);
$cat_in = rl_by_category($conn, $f, 'in');
$cat_out = rl_by_category($conn, $f, 'out');
$daily = array_reverse(rl_daily($conn, $f));

// transactions list (paged in SQL)
[$w, $p] = rl_where($f);
$wl = $w . ($tab === 'income' ? ' AND t.cash_in=1' : ($tab === 'expense' ? ' AND t.cash_in<>1' : ''));
$cnt = $conn->prepare("SELECT COUNT(*) FROM transactions t WHERE $wl"); $cnt->execute($p);
$total_rows = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total_rows / $limit));
$page = min($page, $pages);
$list = $conn->prepare("SELECT t.*, c.name AS cat, u.username AS author FROM transactions t LEFT JOIN categories c ON t.category_id=c.id LEFT JOIN users u ON t.user_id=u.id WHERE $wl ORDER BY t.date DESC, t.id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit));
$list->execute($p);
$rows = $list->fetchAll(PDO::FETCH_ASSOC);

$in = $cur['in']; $out = $cur['out']; $net = $cur['net'];
$margin = $in > 0 ? $net / $in * 100 : 0;
$qs_base = rl_qs($f);
$export = 'export_excel.php?' . rl_qs($f, ['tab' => $tab]);
$self = function (array $over = []) use ($f, $tab, $limit) { return 'hisobot.php?' . rl_qs($f, array_merge(['tab' => $tab !== 'all' ? $tab : null, 'limit' => $limit !== 25 ? $limit : null], $over)) . '#tx'; };
$range_label = $f['start'] === $f['end'] ? uz_date($f['start']) : uz_date($f['start']) . ' — ' . uz_date($f['end']);

ui_head('Hisobotlar');
ui_start('reports');
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">Hisobotlar</h1>
      <p class="page-sub"><?= e($range_label) ?> · <?= $days ?> kun</p>
    </div>
    <div class="row gap-2 no-print row-wrap">
      <button class="btn" type="button" onclick="window.print()"><?= icon('printer', 'i-sm') ?> <span class="hide-sm">Chop etish</span></button>
      <a class="btn btn-success" href="<?= e($export) ?>"><?= icon('download', 'i-sm') ?> Excel (CSV)</a>
    </div>
  </div>

  <div class="no-print" style="margin-bottom:16px"><?php ui_report_subnav('reports', $qs_base); ?></div>
  <?php rl_render_filters($conn, $f, 'hisobot.php', array_filter(['tab' => $tab !== 'all' ? $tab : null, 'limit' => $limit !== 25 ? $limit : null])); ?>

  <!-- KPI -->
  <section class="grid g-2 lg-g-4" style="margin-top:16px" aria-label="Asosiy ko'rsatkichlar">
    <div class="card kpi">
      <div class="kpi-top"><span class="kpi-label">Jami kirim</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('trending-up', 'i-sm') ?></span></div>
      <div class="kpi-value num text-in">+<?= fmt($in) ?></div>
      <div class="kpi-foot"><?= rl_delta_html(rl_delta($in, $prev['in'], true), 'oldingi davrga nisbatan') ?></div>
    </div>
    <div class="card kpi">
      <div class="kpi-top"><span class="kpi-label">Jami chiqim</span><span class="icon-tile out" style="width:32px;height:32px;border-radius:10px"><?= icon('trending-down', 'i-sm') ?></span></div>
      <div class="kpi-value num text-out">−<?= fmt($out) ?></div>
      <div class="kpi-foot"><?= rl_delta_html(rl_delta($out, $prev['out'], false), 'oldingi davrga nisbatan') ?></div>
    </div>
    <div class="card kpi hero span-full-m">
      <div class="kpi-top"><span class="kpi-label">Sof foyda</span><?= icon('wallet') ?></div>
      <div class="kpi-value num"><?= e(fmt_signed($net)) ?><small>so'm</small></div>
      <div class="kpi-foot"><?= rl_delta_html(rl_delta($net, $prev['net'], true)) ?> <span>marja <?= number_format($margin, 1, ',', '') ?>%</span></div>
    </div>
    <div class="card kpi span-full-m">
      <div class="kpi-top"><span class="kpi-label">Tranzaksiyalar</span><span class="icon-tile primary" style="width:32px;height:32px;border-radius:10px"><?= icon('receipt', 'i-sm') ?></span></div>
      <div class="kpi-value num"><?= fmt($cur['n']) ?></div>
      <div class="kpi-foot"><?= $cur['n_in'] ?> kirim · <?= $cur['n_out'] ?> chiqim · o'rtacha kirim <?= $cur['n_in'] ? fmt_short($in / $cur['n_in']) : '0' ?></div>
    </div>
  </section>

  <!-- Payment methods -->
  <section class="card clip" style="margin-top:16px" aria-labelledby="mTitle">
    <div class="card-head"><h2 class="card-title" id="mTitle"><?= icon('credit-card', 'i-sm') ?> To'lov usullari bo'yicha</h2></div>
    <div class="table-wrap">
      <table class="table table-stack">
        <thead><tr><th>Usul</th><th class="r">Kirim</th><th class="r">Chiqim</th><th class="r">Qoldiq</th><th style="min-width:160px">Kirimdagi ulush</th></tr></thead>
        <tbody>
        <?php foreach (['cash', 'click', 'bank'] as $m): $mm = method_meta($m); $d = $cur['m'][$m]; $bal = $d['in'] - $d['out']; $share = $in > 0 ? $d['in'] / $in * 100 : 0; ?>
          <tr>
            <td class="cell-title"><span class="row gap-2"><span class="dot dot-<?= $m ?>"></span><b><?= e($mm['label']) ?></b></span></td>
            <td class="r text-in num fw-6" data-label="Kirim">+<?= fmt($d['in']) ?></td>
            <td class="r text-out num fw-6" data-label="Chiqim">−<?= fmt($d['out']) ?></td>
            <td class="r num fw-8 <?= $bal < 0 ? 'text-out' : '' ?>" data-label="Qoldiq"><?= e(fmt_signed($bal)) ?></td>
            <td data-label="Ulush"><div class="row gap-2" style="justify-content:flex-end"><div class="bar bar-thin <?= $m ?>" style="width:110px;flex:none"><i style="width:<?= round($share, 1) ?>%"></i></div><span class="fs-xs fw-7 num" style="width:38px;text-align:right"><?= round($share) ?>%</span></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td class="cell-title">Jami</td><td class="r text-in num" data-label="Kirim">+<?= fmt($in) ?></td><td class="r text-out num" data-label="Chiqim">−<?= fmt($out) ?></td><td class="r num" data-label="Qoldiq"><?= e(fmt_signed($net)) ?></td><td class="hide-stack"></td></tr></tfoot>
      </table>
    </div>
  </section>

  <!-- Categories -->
  <div class="grid lg-g-2" style="margin-top:16px">
    <?php foreach ([['Kirim — kategoriyalar', 'in', $cat_in, $in, 'badge-in'], ['Chiqim — kategoriyalar', 'out', $cat_out, $out, 'badge-out']] as [$title, $k, $data, $sum, $bcls]): ?>
    <section class="card clip" aria-label="<?= e($title) ?>">
      <div class="card-head"><h2 class="card-title"><?= icon('layers', 'i-sm') ?> <?= e($title) ?></h2><span class="badge <?= $bcls ?>"><?= count($data) ?> tur</span></div>
      <div class="card-pad rank" style="max-height:420px;overflow:auto">
        <?php foreach ($data as $c): $pct = $sum > 0 ? $c['total'] / $sum * 100 : 0; ?>
        <div class="rank-item">
          <div class="rank-top"><b><?= e($c['name']) ?></b><span class="num <?= $k === 'in' ? 'text-in' : 'text-out' ?>"><?= fmt($c['total']) ?></span></div>
          <div class="bar <?= $k ?>"><i style="width:<?= round($pct, 1) ?>%"></i></div>
          <div class="rank-sub"><span><?= $c['cnt'] ?> ta</span><span class="fw-7 num"><?= number_format($pct, 1, ',', '') ?>%</span></div>
        </div>
        <?php endforeach; ?>
        <?php if (!$data): ?><div class="empty" style="padding:24px"><span class="fs-sm">Ma'lumot yo'q</span></div><?php endif; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

  <!-- Daily -->
  <?php if ($daily): ?>
  <section class="card clip" style="margin-top:16px" aria-labelledby="dTitle">
    <div class="card-head"><h2 class="card-title" id="dTitle"><?= icon('calendar-days', 'i-sm') ?> Kunlik jami</h2><span class="badge"><?= count($daily) ?> kun</span></div>
    <div class="table-scroll">
      <table class="table table-stack">
        <thead><tr><th>Sana</th><th class="r">Kirim</th><th class="r">Chiqim</th><th class="r">Balans</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($daily) as $d): $bal = $d['inc'] - $d['exp']; ?>
          <tr>
            <td class="cell-title"><a href="index.php?date=<?= e($d['date']) ?>" style="text-decoration:underline;text-underline-offset:3px;text-decoration-color:var(--border-strong)"><?= e(date('d.m.Y', strtotime($d['date']))) ?></a> <span class="text-3 fs-xs fw-5" style="margin-left:6px"><?= e(uz_weekday($d['date'], true)) ?></span></td>
            <td class="r num fw-6 <?= $d['inc'] > 0 ? 'text-in' : 'text-3' ?>" data-label="Kirim"><?= $d['inc'] > 0 ? '+' . fmt($d['inc']) : '—' ?></td>
            <td class="r num fw-6 <?= $d['exp'] > 0 ? 'text-out' : 'text-3' ?>" data-label="Chiqim"><?= $d['exp'] > 0 ? '−' . fmt($d['exp']) : '—' ?></td>
            <td class="r num fw-8 <?= $bal < 0 ? 'text-out' : '' ?>" data-label="Balans"><?= e(fmt_signed($bal)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td class="cell-title">Jami</td><td class="r text-in num" data-label="Kirim">+<?= fmt($in) ?></td><td class="r text-out num" data-label="Chiqim">−<?= fmt($out) ?></td><td class="r num" data-label="Balans"><?= e(fmt_signed($net)) ?></td></tr></tfoot>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <!-- Transactions -->
  <section class="card clip" style="margin-top:16px" id="tx" aria-labelledby="tTitle">
    <div class="card-head" style="flex-wrap:wrap">
      <h2 class="card-title" id="tTitle"><?= icon('list', 'i-sm') ?> Tranzaksiyalar <span class="badge"><?= fmt($total_rows) ?></span></h2>
      <div class="row gap-2 row-wrap no-print">
        <div class="seg" role="group" aria-label="Turi">
          <?php foreach ([['all', 'Hammasi'], ['income', 'Kirim'], ['expense', 'Chiqim']] as [$k, $lbl]): ?>
            <a href="<?= e($self(['tab' => $k !== 'all' ? $k : null, 'page' => null])) ?>" class="<?= $tab === $k ? 'on' : '' ?>" <?= $tab === $k ? 'aria-current="true"' : '' ?>><?= e($lbl) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php if ($rows): ?>
    <div class="table-wrap">
      <table class="table table-tx">
        <thead><tr><th>Sana</th><th>Kategoriya</th><th>Izoh</th><th>Usul</th><th>Kiritdi</th><th class="r">Summa</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $is_in = $r['cash_in'] == 1; $m = method_of($r); $mm = method_meta($m); ?>
          <tr>
            <td class="c-date nowrap num"><?= e(date('d.m.Y', strtotime($r['date']))) ?></td>
            <td class="c-cat"><span class="cat-cell"><span class="icon-tile <?= $is_in ? 'in' : 'out' ?>"><?= icon($is_in ? 'arrow-down-left' : 'arrow-up-right') ?></span><span><?= e($r['cat'] ?: ($is_in ? 'Kirim' : 'Chiqim')) ?></span></span></td>
            <td class="c-break"></td>
            <td class="c-note"><?= linkify($r['comment']) ?></td>
            <td class="c-method"><span class="badge badge-<?= $m ?>"><?= icon($mm['icon'], 'i-xs') ?><?= e($mm['label']) ?></span></td>
            <td class="c-author text-2"><?= e($r['author']) ?></td>
            <td class="c-amt r num fw-8 nowrap <?= $is_in ? 'text-in' : 'text-out' ?>"><?= $is_in ? '+' : '−' ?><?= fmt($r['payment']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-foot pager no-print">
      <div class="row gap-2 row-wrap">
        <span class="fs-sm text-3"><?= (($page - 1) * $limit) + 1 ?>–<?= (($page - 1) * $limit) + count($rows) ?> / <?= fmt($total_rows) ?></span>
        <div class="seg" role="group" aria-label="Sahifadagi soni">
          <?php foreach ([10, 25, 50, 100] as $n): ?><a class="<?= $limit === $n ? 'on' : '' ?>" style="min-height:32px;padding:0 10px" href="<?= e($self(['limit' => $n !== 25 ? $n : null, 'page' => null])) ?>"><?= $n ?></a><?php endforeach; ?>
        </div>
      </div>
      <?php if ($pages > 1): ?>
      <nav class="pager-nav" aria-label="Sahifalar">
        <?php if ($page > 1): ?><a href="<?= e($self(['page' => $page - 1])) ?>" aria-label="Oldingi"><?= icon('chevron-left', 'i-sm') ?></a><?php endif; ?>
        <?php
        $show = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], fn($n) => $n >= 1 && $n <= $pages)); sort($show); $last = 0;
        foreach ($show as $n): if ($n - $last > 1): ?><span class="gap">…</span><?php endif; $last = $n;
          if ($n === $page): ?><span class="cur" aria-current="page"><?= $n ?></span><?php else: ?><a href="<?= e($self(['page' => $n])) ?>"><?= $n ?></a><?php endif;
        endforeach; ?>
        <?php if ($page < $pages): ?><a href="<?= e($self(['page' => $page + 1])) ?>" aria-label="Keyingi"><?= icon('chevron-right', 'i-sm') ?></a><?php endif; ?>
      </nav>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="empty"><div class="icon-tile"><?= icon('inbox', 'i-lg') ?></div><b>Ma'lumot topilmadi</b><span class="fs-sm">Davr yoki filtrlarni o'zgartirib ko'ring.</span></div>
    <?php endif; ?>
  </section>
</div>
<?php ui_end(['active' => 'reports']);
