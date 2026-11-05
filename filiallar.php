<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_lib.php';
require_admin();

$_GET['branch'] = 0;                       // this page always compares every branch
$f = rl_filters();
[$ps, $pe, $days] = rl_prev_range($f);
$cur = rl_by_branch($conn, $f);
$prv = rl_by_branch($conn, $f, $ps, $pe);
$ov = network_overview(date('Y-m-d'));
$B = branches_all();

// payment-method mix per branch (income) + 12 month series per branch
[$w, $p] = rl_where($f);
$st = $conn->prepare("SELECT t.branch_id, " . SQL_METHOD . " AS m, SUM(CASE WHEN t.cash_in=1 THEN t.payment ELSE 0 END) AS inc FROM transactions t WHERE $w GROUP BY t.branch_id, m");
$st->execute($p); $mix = [];
foreach ($st->fetchAll() as $r) $mix[(int)$r['branch_id']][$r['m']] = (float)$r['inc'];

$months = 12; $endM = date('Y-m-01', strtotime($f['end'])); $startM = date('Y-m-01', strtotime("$endM -" . ($months - 1) . " months"));
$f12 = $f; $f12['start'] = $startM; $f12['end'] = date('Y-m-t', strtotime($endM));
[$w12, $p12] = rl_where($f12);
$st = $conn->prepare("SELECT t.branch_id, substr(t.date,1,7) AS ym, SUM(CASE WHEN t.cash_in=1 THEN t.payment ELSE 0 END) AS inc FROM transactions t WHERE $w12 GROUP BY t.branch_id, ym");
$st->execute($p12); $series = [];
foreach ($st->fetchAll() as $r) $series[(int)$r['branch_id']][$r['ym']] = (float)$r['inc'];
$mlabels = []; for ($i = 0; $i < $months; $i++) $mlabels[] = date('Y-m', strtotime("$startM +$i months"));

// totals
$T = ['in' => 0, 'out' => 0, 'pin' => 0, 'pout' => 0, 'bal' => 0, 'n' => 0];
foreach ($B as $id => $b) { $c = $cur[$id] ?? null; $q = $prv[$id] ?? null;
    $T['in'] += $c['inc'] ?? 0; $T['out'] += $c['exp'] ?? 0; $T['pin'] += $q['inc'] ?? 0; $T['pout'] += $q['exp'] ?? 0; $T['n'] += ($c['n_in'] ?? 0) + ($c['n_out'] ?? 0); if (!empty($ov[$id]['tracked'])) $T['bal'] += $ov[$id]['bal']['total']; }
$T['net'] = $T['in'] - $T['out']; $T['pnet'] = $T['pin'] - $T['pout']; $tr = tracking_info(0);

// ranking helpers
$rows = [];
foreach ($B as $id => $b) { $c = $cur[$id] ?? ['inc' => 0, 'exp' => 0, 'n_in' => 0, 'n_out' => 0]; $q = $prv[$id] ?? ['inc' => 0, 'exp' => 0];
    $rows[$id] = ['b' => $b, 'inc' => (float)$c['inc'], 'exp' => (float)$c['exp'], 'net' => $c['inc'] - $c['exp'], 'n' => $c['n_in'] + $c['n_out'], 'pinc' => (float)$q['inc'], 'margin' => $c['inc'] > 0 ? ($c['inc'] - $c['exp']) / $c['inc'] * 100 : 0]; }
$bestIn = null; $bestMargin = null;
foreach ($rows as $r) { if ($r['inc'] > 0 && (!$bestIn || $r['inc'] > $bestIn['inc'])) $bestIn = $r; if ($r['inc'] > 0 && (!$bestMargin || $r['margin'] > $bestMargin['margin'])) $bestMargin = $r; }
$maxInc = max(1, max(array_column($rows, 'inc') ?: [1]));

$qs = rl_qs($f);
$range_label = $f['start'] === $f['end'] ? uz_date($f['start']) : uz_date($f['start']) . ' — ' . uz_date($f['end']);
$payload = [
  'labels' => array_map(function ($r) { return $r['b']['name']; }, array_values($rows)),
  'inc' => array_map(function ($r) { return round($r['inc']); }, array_values($rows)),
  'exp' => array_map(function ($r) { return round($r['exp']); }, array_values($rows)),
  'months' => array_map(function ($m) { return uz_month((int)substr($m, 5, 2), true) . ' ' . substr($m, 2, 2); }, $mlabels),
  'series' => array_values(array_map(function ($b) use ($series, $mlabels) { return ['name' => $b['name'], 'color' => branch_color($b), 'data' => array_map(function ($m) use ($series, $b) { return round($series[(int)$b['id']][$m] ?? 0); }, $mlabels)]; }, array_filter($B, function ($b) use ($series) { return !empty($series[(int)$b['id']]); }))),
];

ui_head('Filiallar', ['head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>']);
ui_start('branches');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Filiallar</h1><p class="page-sub row row-wrap" style="gap:8px"><?= ui_scope_chip(0) ?><span><?= e($range_label) ?> · <?= $days ?> kun</span></p></div>
    <div class="row gap-2 no-print row-wrap">
      <a class="btn" href="admin.php#branches"><?= icon('settings', 'i-sm') ?> Boshqarish</a>
      <a class="btn btn-success" href="export_excel.php?<?= e($qs) ?>"><?= icon('download', 'i-sm') ?> Excel (CSV)</a>
    </div>
  </div>
  <?php rl_render_filters($conn, $f, 'filiallar.php', [], false, false); ?>

  <section class="grid g-2 lg-g-4" style="margin-top:16px" aria-label="Tarmoq ko'rsatkichlari">
    <div class="card kpi hero2 span-full-m"><div class="kpi-top"><span class="kpi-label"><?= $tr['any'] ? 'Kassada (jami)' : 'Sof foyda' ?></span><?= icon('wallet') ?></div>
      <div class="kpi-value num"><?= e(fmt_short($tr['any'] ? $T['bal'] : $T['net'])) ?></div><div class="kpi-foot"><?= $tr['any'] ? ($tr['untracked'] ? $tr['untracked'] . " filial hisobga olinmagan" : count(branches_active()) . ' ta faol filial') : "Kassa qoldig'i sozlanmagan" ?></div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Davr kirimi</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('trending-up', 'i-sm') ?></span></div>
      <div class="kpi-value num text-in">+<?= e(fmt_short($T['in'])) ?></div><div class="kpi-foot"><?= rl_delta_html(rl_delta($T['in'], $T['pin'], true), 'oldingiga nisbatan') ?></div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Davr chiqimi</span><span class="icon-tile out" style="width:32px;height:32px;border-radius:10px"><?= icon('trending-down', 'i-sm') ?></span></div>
      <div class="kpi-value num text-out">−<?= e(fmt_short($T['out'])) ?></div><div class="kpi-foot"><?= rl_delta_html(rl_delta($T['out'], $T['pout'], false), 'oldingiga nisbatan') ?></div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Sof foyda</span><span class="icon-tile primary" style="width:32px;height:32px;border-radius:10px"><?= icon('badge-dollar-sign', 'i-sm') ?></span></div>
      <div class="kpi-value num <?= $T['net'] < 0 ? 'text-out' : '' ?>"><?= e(fmt_short($T['net'])) ?></div><div class="kpi-foot"><?= rl_delta_html(rl_delta($T['net'], $T['pnet'], true), 'oldingiga nisbatan') ?></div></div>
  </section>

  <?php if ($bestIn || $bestMargin): ?>
  <div class="stat-row" style="margin-top:12px">
    <?php if ($bestIn): ?><span class="stat-pill"><?= icon('trophy', 'i-xs') ?> Eng ko'p kirim: <b><?= e($bestIn['b']['name']) ?></b> (<?= e(fmt_short($bestIn['inc'])) ?>)</span><?php endif; ?>
    <?php if ($bestMargin): ?><span class="stat-pill"><?= icon('target', 'i-xs') ?> Eng yuqori marja: <b><?= e($bestMargin['b']['name']) ?></b> (<?= number_format($bestMargin['margin'], 1, ',', '') ?>%)</span><?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Branch cards -->
  <div class="grid" style="margin-top:16px;grid-template-columns:repeat(auto-fill,minmax(min(100%,330px),1fr))">
    <?php foreach ($rows as $id => $r): $b = $r['b']; $o = $ov[$id] ?? null; if (!$o) continue; $mx = $mix[$id] ?? []; $mt = array_sum($mx) ?: 1; ?>
    <a class="card bcard" style="--bc:<?= e(branch_color($b)) ?>;<?= $b['is_active'] ? '' : 'opacity:.7' ?>" href="process.php?action=switch_branch&id=<?= (int)$id ?>&back=index.php">
      <div class="row" style="align-items:flex-start">
        <?= branch_avatar($b, 'lg') ?>
        <div class="grow"><div class="row between" style="gap:8px"><h2 class="fw-8" style="font-size:17px;letter-spacing:-.02em"><?= e($b['name']) ?></h2><?= $b['is_active'] ? '<span class="status-dot" title="Faol"></span>' : '<span class="badge">Arxiv</span>' ?></div>
          <div class="fs-sm text-3"><?= $b['address'] ? e($b['address']) : '&nbsp;' ?></div></div>
      </div>
      <?php if ($o['tracked']): ?><div><div class="eyebrow">Kassada</div><div class="big num"><?= e(fmt($o['bal']['total'])) ?> <small class="text-3 fs-sm fw-6">so'm</small></div></div>
      <?php else: ?><div><div class="eyebrow">Davr natijasi</div><div class="big num <?= $r['net'] < 0 ? 'text-out' : '' ?>"><?= e(fmt_signed($r['net'])) ?> <small class="text-3 fs-sm fw-6">so'm</small></div></div><?php endif; ?>
      <?= sparkline($o['spark'], branch_color($b)) ?>
      <div class="mini-stats">
        <div><div class="k">Bugun +</div><div class="v num text-in"><?= e(fmt_short($o['d_in'])) ?></div></div>
        <div><div class="k">Bugun −</div><div class="v num text-out"><?= e(fmt_short($o['d_out'])) ?></div></div>
        <div><div class="k">Davr foyda</div><div class="v num <?= $r['net'] < 0 ? 'text-out' : '' ?>"><?= e(fmt_short($r['net'])) ?></div></div>
      </div>
      <div class="segbar" role="img" aria-label="To'lov usullari ulushi"><?php foreach (['cash', 'click', 'bank'] as $m): if (empty($mx[$m])) continue; ?><i class="<?= $m ?>" style="flex:<?= $mx[$m] ?>"></i><?php endforeach; ?></div>
      <div class="row between fs-xs text-3"><span><?= icon('users', 'i-xs') ?> <?= (int)$o['cashiers'] + (int)$o['managers'] ?> kassir/menejer</span><span><?= $o['last'] ? 'Oxirgi: ' . e(date('d.m.Y', strtotime($o['last']))) : "Yozuv yo'q" ?></span></div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Comparison -->
  <div class="grid lg-g-2" style="margin-top:16px">
    <section class="card clip" aria-labelledby="cmpT">
      <div class="card-head"><h2 class="card-title" id="cmpT"><?= icon('chart-column', 'i-sm') ?> Kirim va chiqim taqqoslash</h2></div>
      <div class="card-pad"><div class="chart-box" style="height:300px"><canvas id="cmpChart" role="img" aria-label="Filiallar bo'yicha kirim va chiqim. Ma'lumotlar jadvalda."></canvas></div></div>
    </section>
    <section class="card clip" aria-labelledby="rkT">
      <div class="card-head"><h2 class="card-title" id="rkT"><?= icon('trophy', 'i-sm') ?> Reyting (kirim bo'yicha)</h2></div>
      <div class="card-pad rank">
        <?php $rk = $rows; uasort($rk, function ($a, $b) { return $b['inc'] <=> $a['inc']; }); $i = 0; foreach ($rk as $r): $i++; $d = rl_delta($r['inc'], $r['pinc'], true); ?>
        <div class="rank-item">
          <div class="rank-top"><b class="row gap-2" style="gap:8px"><span class="text-3 fw-7" style="width:14px"><?= $i ?></span><?= branch_avatar($r['b'], 'xs') ?><?= e($r['b']['name']) ?></b><span class="num"><?= fmt($r['inc']) ?></span></div>
          <div class="bar"><i style="width:<?= round($r['inc'] / $maxInc * 100, 1) ?>%;background:<?= e(branch_color($r['b'])) ?>"></i></div>
          <div class="rank-sub"><span><?= $r['n'] ?> ta yozuv · marja <?= number_format($r['margin'], 1, ',', '') ?>%</span><span><?= rl_delta_html($d) ?></span></div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <section class="card clip" style="margin-top:16px" aria-labelledby="trT">
    <div class="card-head"><h2 class="card-title" id="trT"><?= icon('chart-line', 'i-sm') ?> Oylik kirim dinamikasi (12 oy)</h2></div>
    <div class="card-pad"><div class="chart-box tall"><canvas id="trendChart" role="img" aria-label="Filiallar bo'yicha oylik kirim"></canvas></div></div>
  </section>

  <section class="card clip" style="margin-top:16px" aria-labelledby="tbT">
    <div class="card-head"><h2 class="card-title" id="tbT"><?= icon('list', 'i-sm') ?> Jadval</h2></div>
    <div class="table-wrap">
      <table class="table table-stack">
        <thead><tr><th>Filial</th><th class="r">Kirim</th><th class="r">Chiqim</th><th class="r">Foyda</th><th class="r">Marja</th><th class="r">Yozuvlar</th><th class="r">Kassada</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $id => $r): ?>
          <tr>
            <td class="cell-title"><span class="row gap-2"><?= branch_avatar($r['b'], 'sm') ?><b><?= e($r['b']['name']) ?></b></span></td>
            <td class="r text-in num fw-6" data-label="Kirim">+<?= fmt($r['inc']) ?></td>
            <td class="r text-out num fw-6" data-label="Chiqim">−<?= fmt($r['exp']) ?></td>
            <td class="r num fw-8 <?= $r['net'] < 0 ? 'text-out' : '' ?>" data-label="Foyda"><?= e(fmt_signed($r['net'])) ?></td>
            <td class="r num" data-label="Marja"><?= number_format($r['margin'], 1, ',', '') ?>%</td>
            <td class="r num" data-label="Yozuvlar"><?= fmt($r['n']) ?></td>
            <td class="r num fw-7" data-label="Kassada"><?= !empty($ov[$id]['tracked']) ? fmt($ov[$id]['bal']['total']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td class="cell-title">Jami</td><td class="r text-in num" data-label="Kirim">+<?= fmt($T['in']) ?></td><td class="r text-out num" data-label="Chiqim">−<?= fmt($T['out']) ?></td><td class="r num" data-label="Foyda"><?= e(fmt_signed($T['net'])) ?></td><td class="r num" data-label="Marja"><?= $T['in'] > 0 ? number_format($T['net'] / $T['in'] * 100, 1, ',', '') : '0' ?>%</td><td class="r num" data-label="Yozuvlar"><?= fmt($T['n']) ?></td><td class="r num" data-label="Kassada"><?= $tr['any'] ? fmt($T['bal']) : '—' ?></td></tr></tfoot>
      </table>
    </div>
  </section>
</div>
<?php
$js = '<script>
(function(){
  if(!window.Chart) return;
  var D = ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
  function css(v){ return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }
  function nf(n){ return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g," "); }
  function short(n){ var a=Math.abs(n); if(a>=1e9) return (n/1e9).toFixed(1).replace(/\.0$/,"")+" mlrd"; if(a>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,"")+" mln"; if(a>=1e3) return (n/1e3).toFixed(0)+" ming"; return nf(n); }
  var font = {family:"Plus Jakarta Sans",size:11};
  function base(){ return { responsive:true, maintainAspectRatio:false, animation:{duration:350}, interaction:{mode:"index",intersect:false},
    plugins:{ legend:{position:"top",align:"start",labels:{usePointStyle:true,pointStyle:"rectRounded",boxWidth:8,boxHeight:8,color:css("--text-2"),font:{family:"Plus Jakarta Sans",size:12,weight:"600"},padding:14}},
      tooltip:{padding:10,cornerRadius:10,usePointStyle:true,boxPadding:4,callbacks:{label:function(c){return " "+c.dataset.label+": "+nf(c.parsed.y)+" so‘m";}}}},
    scales:{ x:{grid:{display:false},border:{color:css("--border")},ticks:{color:css("--text-3"),font:font,maxRotation:0,autoSkip:true}}, y:{beginAtZero:true,grid:{color:css("--border")},border:{display:false},ticks:{color:css("--text-3"),font:font,callback:short}} } }; }
  var surface = css("--surface");
  new Chart(document.getElementById("cmpChart"), { type:"bar", data:{ labels:D.labels, datasets:[
    {label:"Kirim",data:D.inc,backgroundColor:"#1baf7a",borderRadius:{topLeft:4,topRight:4},maxBarThickness:36,borderColor:surface,borderWidth:{left:1,right:1}},
    {label:"Chiqim",data:D.exp,backgroundColor:"#eb6834",borderRadius:{topLeft:4,topRight:4},maxBarThickness:36,borderColor:surface,borderWidth:{left:1,right:1}} ]}, options:base() });
  new Chart(document.getElementById("trendChart"), { type:"line", data:{ labels:D.months, datasets:D.series.map(function(s){ return {label:s.name,data:s.data,borderColor:s.color,backgroundColor:s.color,borderWidth:2.5,pointRadius:3,pointHoverRadius:6,pointBorderColor:surface,pointBorderWidth:2,tension:.25}; }) }, options:base() });
})();
</script>';
ui_end(['active' => 'branches', 'scripts' => $js]);
