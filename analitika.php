<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_lib.php';
require_reports();

$f = rl_filters();
$cur = rl_totals($conn, $f);
[$ps, $pe, $days] = rl_prev_range($f);
$prev = rl_totals($conn, $f, $ps, $pe);
$daily = rl_daily($conn, $f);
$monthly = rl_monthly($conn, $f, 12);
$in = $cur['in']; $out = $cur['out']; $net = $cur['net'];
$active_days = max(1, count($daily));
$margin = $in > 0 ? $net / $in * 100 : 0;
$pmargin = $prev['in'] > 0 ? $prev['net'] / $prev['in'] * 100 : 0;

// peak day
$peak = null; foreach ($daily as $d) { if (!$peak || $d['inc'] > $peak['inc']) $peak = $d; }

// weekday averages (per calendar occurrence of that weekday in the range)
$wd_in = array_fill(0, 7, 0.0); $wd_out = array_fill(0, 7, 0.0); $wd_n = array_fill(0, 7, 0);
foreach ($daily as $d) { $w = (int)date('w', strtotime($d['date'])); $wd_in[$w] += $d['inc']; $wd_out[$w] += $d['exp']; }
for ($t = strtotime($f['start']), $e = strtotime($f['end']); $t <= $e; $t += 86400) { $wd_n[(int)date('w', $t)]++; }
$order = [1, 2, 3, 4, 5, 6, 0]; $wd_labels = []; $wd_avg_in = []; $wd_avg_out = [];
foreach ($order as $w) { $wd_labels[] = ['Yak','Dush','Sesh','Chor','Pay','Juma','Shan'][$w]; $n = max(1, $wd_n[$w]); $wd_avg_in[] = round($wd_in[$w] / $n); $wd_avg_out[] = round($wd_out[$w] / $n); }

// expense by category x method (top 8 + rest)
[$w, $p] = rl_where($f);
$st = $conn->prepare("SELECT COALESCE(c.name,'Kategoriyasiz') AS name, " . RL_METHOD_SQL . " AS m, SUM(t.payment) AS s FROM transactions t LEFT JOIN categories c ON t.category_id=c.id WHERE $w AND t.cash_in<>1 GROUP BY COALESCE(c.name,'Kategoriyasiz'), m");
$st->execute($p);
$xc = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $xc[$r['name']][$r['m']] = ($xc[$r['name']][$r['m']] ?? 0) + (float)$r['s']; }
uasort($xc, fn($a, $b) => array_sum($b) <=> array_sum($a));
$rest = array_slice($xc, 8, null, true); $xc = array_slice($xc, 0, 8, true);
if ($rest) { $o = ['cash' => 0, 'click' => 0, 'bank' => 0]; foreach ($rest as $v) foreach ($v as $m => $s) $o[$m] += $s; $xc['Boshqa (' . count($rest) . ' ta)'] = $o; }
$xc_labels = array_keys($xc);
$xc_series = []; foreach (['cash', 'click', 'bank'] as $m) { $xc_series[$m] = array_map(fn($v) => round($v[$m] ?? 0), array_values($xc)); }

$cat_in = rl_by_category($conn, $f, 'in');

// staff performance
$st = $conn->prepare("SELECT u.username, SUM(CASE WHEN t.cash_in=1 THEN t.payment ELSE 0 END) AS inc, SUM(CASE WHEN t.cash_in<>1 THEN t.payment ELSE 0 END) AS exp, COUNT(*) AS n, MAX(t.date) AS last_date FROM transactions t JOIN users u ON t.user_id=u.id WHERE $w GROUP BY u.id ORDER BY inc DESC");
$st->execute($p); $staff = $st->fetchAll(PDO::FETCH_ASSOC);
$staff_max = max(array_merge([1], array_column($staff, 'inc')));

// largest transactions
$top = function (bool $income) use ($conn, $w, $p) {
    $st = $conn->prepare("SELECT t.date, t.payment, t.comment, " . RL_METHOD_SQL . " AS m, COALESCE(c.name,'Kategoriyasiz') AS cat FROM transactions t LEFT JOIN categories c ON t.category_id=c.id WHERE $w AND t.cash_in" . ($income ? '=1' : '<>1') . " ORDER BY t.payment DESC LIMIT 5");
    $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC);
};
$top_in = $top(true); $top_out = $top(false);

// chart payload
$labels = array_map(fn($d) => date('d.m', strtotime($d['date'])), $daily);
$dinc = array_map(fn($d) => (float)$d['inc'], $daily);
$dexp = array_map(fn($d) => (float)$d['exp'], $daily);
$cum = []; $run = 0; foreach ($daily as $d) { $run += $d['inc'] - $d['exp']; $cum[] = $run; }
$payload = ['month' => ['labels' => array_map(function ($m) { return uz_month((int)substr($m['ym'], 5, 2), true) . ' ' . substr($m['ym'], 2, 2); }, $monthly), 'inc' => array_map(function ($m) { return round($m['inc']); }, $monthly), 'exp' => array_map(function ($m) { return round($m['exp']); }, $monthly), 'net' => array_map(function ($m) { return round($m['inc'] - $m['exp']); }, $monthly)],
    'labels' => $labels, 'inc' => $dinc, 'exp' => $dexp, 'cum' => $cum,
    'wd' => ['labels' => $wd_labels, 'inc' => $wd_avg_in, 'exp' => $wd_avg_out],
    'xc' => ['labels' => $xc_labels, 'series' => $xc_series]];

$qs = rl_qs($f);
$range_label = $f['start'] === $f['end'] ? uz_date($f['start']) : uz_date($f['start']) . ' — ' . uz_date($f['end']);
$head = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';

ui_head('Analitika', ['head' => $head]);
ui_start('analytics');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Grafik analitika</h1><p class="page-sub row row-wrap" style="gap:8px"><?= ui_scope_chip((int)$f['branch']) ?><span><?= e($range_label) ?> · <?= $days ?> kun</span></p></div>
    <div class="row gap-2 no-print">
      <button class="btn" type="button" onclick="window.print()"><?= icon('printer', 'i-sm') ?> <span class="hide-sm">Chop etish</span></button>
      <a class="btn btn-success" href="export_excel.php?<?= e($qs) ?>"><?= icon('download', 'i-sm') ?> Excel (CSV)</a>
    </div>
  </div>
  <div class="no-print" style="margin-bottom:16px"><?php ui_report_subnav('analytics', $qs); ?></div>
  <?php rl_render_filters($conn, $f, 'analitika.php', [], false); ?>

  <?php if ($cur['n'] === 0): ?>
  <div class="card empty" style="margin-top:16px"><div class="icon-tile"><?= icon('chart-line', 'i-lg') ?></div><b>Bu davrda ma'lumot yo'q</b><span class="fs-sm">Boshqa davr tanlang.</span></div>
  <?php else: ?>

  <section class="grid g-2 lg-g-4" style="margin-top:16px" aria-label="Ko'rsatkichlar">
    <div class="card kpi">
      <div class="kpi-top"><span class="kpi-label">Foyda marjasi</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('percent', 'i-sm') ?></span></div>
      <div class="kpi-value num <?= $margin < 0 ? 'text-out' : '' ?>"><?= number_format($margin, 1, ',', '') ?>%</div>
      <div class="kpi-foot"><?= rl_delta_html(rl_delta($margin, $pmargin, true)) ?><span>oldingi: <?= number_format($pmargin, 1, ',', '') ?>%</span></div>
    </div>
    <div class="card kpi">
      <div class="kpi-top"><span class="kpi-label">O'rtacha kunlik kirim</span><span class="icon-tile primary" style="width:32px;height:32px;border-radius:10px"><?= icon('calendar', 'i-sm') ?></span></div>
      <div class="kpi-value num"><?= fmt($in / $active_days) ?></div>
      <div class="kpi-foot"><?= $active_days ?> ta faol kun bo'yicha</div>
    </div>
    <div class="card kpi">
      <div class="kpi-top"><span class="kpi-label">O'rtacha kunlik chiqim</span><span class="icon-tile out" style="width:32px;height:32px;border-radius:10px"><?= icon('trending-down', 'i-sm') ?></span></div>
      <div class="kpi-value num"><?= fmt($out / $active_days) ?></div>
      <div class="kpi-foot">Jami chiqim: <?= fmt_short($out) ?></div>
    </div>
    <div class="card kpi">
      <div class="kpi-top"><span class="kpi-label">Eng yaxshi kun</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('trending-up', 'i-sm') ?></span></div>
      <div class="kpi-value num"><?= $peak ? '+' . fmt($peak['inc']) : '—' ?></div>
      <div class="kpi-foot"><?= $peak ? e(uz_date($peak['date'], false)) . ', ' . e(uz_weekday($peak['date'])) : '' ?></div>
    </div>
  </section>

  <!-- Trend -->
  <section class="card" style="margin-top:16px" aria-labelledby="trTitle">
    <div class="card-head" style="flex-wrap:wrap">
      <h2 class="card-title" id="trTitle"><?= icon('chart-line', 'i-sm') ?> Kunlik dinamika</h2>
      <div class="seg no-print" role="group" aria-label="Ko'rinish" id="trendMode">
        <button type="button" class="on" data-mode="daily" aria-pressed="true">Kunlik</button>
        <button type="button" data-mode="cum" aria-pressed="false">Jamlanma foyda</button>
      </div>
    </div>
    <div class="card-pad">
      <div class="chart-box tall"><canvas id="trendChart" role="img" aria-label="Kunlik kirim va chiqim grafigi. Ma'lumotlar quyidagi jadvalda."></canvas></div>
      <details class="data-table mt-3 no-print"><summary><?= icon('list', 'i-sm') ?> Jadval ko'rinishi</summary>
        <div class="table-scroll" style="margin-top:8px;border:1px solid var(--border);border-radius:12px">
          <table class="table"><thead><tr><th>Sana</th><th class="r">Kirim</th><th class="r">Chiqim</th><th class="r">Jamlanma</th></tr></thead><tbody>
          <?php foreach ($daily as $i => $d): ?><tr><td><?= e(date('d.m.Y', strtotime($d['date']))) ?></td><td class="r num"><?= fmt($d['inc']) ?></td><td class="r num"><?= fmt($d['exp']) ?></td><td class="r num"><?= e(fmt_signed($cum[$i])) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
      </details>
    </div>
  </section>

  <!-- Monthly -->
  <section class="card" style="margin-top:16px" aria-labelledby="moTitle">
    <div class="card-head"><h2 class="card-title" id="moTitle"><?= icon('calendar-range', 'i-sm') ?> Oylik dinamika (12 oy)</h2><span class="fs-sm text-3 hide-sm">tanlangan davr oxirigacha</span></div>
    <div class="card-pad">
      <div class="chart-box"><canvas id="monthChart" role="img" aria-label="Oylik kirim, chiqim va sof foyda grafigi. Ma'lumotlar quyidagi jadvalda."></canvas></div>
      <details class="data-table mt-3 no-print"><summary><?= icon('list', 'i-sm') ?> Jadval ko'rinishi</summary>
        <div class="table-scroll" style="margin-top:8px;border:1px solid var(--border);border-radius:12px"><table class="table"><thead><tr><th>Oy</th><th class="r">Kirim</th><th class="r">Chiqim</th><th class="r">Foyda</th><th class="r">Yozuvlar</th></tr></thead><tbody>
        <?php foreach (array_reverse($monthly) as $m): ?><tr><td><?= e(uz_month((int)substr($m['ym'], 5, 2)) . ' ' . substr($m['ym'], 0, 4)) ?></td><td class="r num"><?= fmt($m['inc']) ?></td><td class="r num"><?= fmt($m['exp']) ?></td><td class="r num"><?= e(fmt_signed($m['inc'] - $m['exp'])) ?></td><td class="r num"><?= fmt($m['n']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></details>
    </div>
  </section>

  <div class="grid lg-g-2" style="margin-top:16px">
    <!-- Methods split -->
    <section class="card" aria-labelledby="msTitle">
      <div class="card-head"><h2 class="card-title" id="msTitle"><?= icon('credit-card', 'i-sm') ?> Kirim: to'lov usullari</h2></div>
      <div class="card-pad stack">
        <?php if ($in > 0): ?>
        <div class="segbar" role="img" aria-label="Kirimning usullar bo'yicha taqsimoti">
          <?php foreach (['cash', 'click', 'bank'] as $m): if ($cur['m'][$m]['in'] <= 0) continue; ?><i class="<?= $m ?>" style="flex:<?= $cur['m'][$m]['in'] ?>"></i><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php foreach (['cash', 'click', 'bank'] as $m): $mm = method_meta($m); $v = $cur['m'][$m]['in']; $pc = $in > 0 ? $v / $in * 100 : 0; ?>
        <div class="row">
          <span class="dot dot-<?= $m ?>"></span>
          <span class="grow fw-6"><?= e($mm['label']) ?></span>
          <span class="num fw-8"><?= fmt($v) ?></span>
          <span class="num fs-sm text-3" style="width:44px;text-align:right"><?= round($pc) ?>%</span>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Weekday -->
    <section class="card" aria-labelledby="wdTitle">
      <div class="card-head"><h2 class="card-title" id="wdTitle"><?= icon('calendar-days', 'i-sm') ?> Hafta kunlari bo'yicha o'rtacha</h2></div>
      <div class="card-pad"><div class="chart-box" style="height:230px"><canvas id="wdChart" role="img" aria-label="Hafta kunlari bo'yicha o'rtacha kirim va chiqim"></canvas></div></div>
    </section>
  </div>

  <div class="grid lg-g-2" style="margin-top:16px">
    <!-- Expense by cat x method -->
    <section class="card" aria-labelledby="xcTitle">
      <div class="card-head"><h2 class="card-title" id="xcTitle"><?= icon('layers', 'i-sm') ?> Chiqim: kategoriya va usul</h2></div>
      <div class="card-pad">
        <?php if ($xc): ?>
        <div class="chart-box" style="height:<?= 70 + count($xc) * 40 ?>px"><canvas id="xcChart" role="img" aria-label="Chiqim kategoriyalari, to'lov usullari bo'yicha"></canvas></div>
        <?php else: ?><div class="empty" style="padding:24px"><span class="fs-sm">Chiqim yo'q</span></div><?php endif; ?>
      </div>
    </section>

    <!-- Income sources -->
    <section class="card" aria-labelledby="isTitle">
      <div class="card-head"><h2 class="card-title" id="isTitle"><?= icon('trending-up', 'i-sm') ?> Kirim manbalari</h2></div>
      <div class="card-pad rank">
        <?php foreach ($cat_in as $c): $pc = $in > 0 ? $c['total'] / $in * 100 : 0; ?>
        <div class="rank-item">
          <div class="rank-top"><b><?= e($c['name']) ?></b><span class="num text-in"><?= fmt($c['total']) ?></span></div>
          <div class="bar in"><i style="width:<?= round($pc, 1) ?>%"></i></div>
          <div class="rank-sub"><span><?= $c['cnt'] ?> ta</span><span class="fw-7 num"><?= number_format($pc, 1, ',', '') ?>%</span></div>
        </div>
        <?php endforeach; ?>
        <?php if (!$cat_in): ?><div class="empty" style="padding:24px"><span class="fs-sm">Kirim yo'q</span></div><?php endif; ?>
      </div>
    </section>
  </div>

  <!-- Staff -->
  <section class="card clip" style="margin-top:16px" aria-labelledby="stTitle">
    <div class="card-head"><h2 class="card-title" id="stTitle"><?= icon('users', 'i-sm') ?> Xodimlar faolligi</h2></div>
    <div class="table-wrap">
      <table class="table table-stack">
        <thead><tr><th>Foydalanuvchi</th><th class="r">Tranzaksiya</th><th class="r">Kirim</th><th class="r">Chiqim</th><th>Oxirgi faollik</th><th style="min-width:140px">Ulush</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
          <tr>
            <td class="cell-title"><span class="row gap-2"><span class="avatar avatar-sm"><?= e(mb_substr($s['username'], 0, 1)) ?></span><b><?= e($s['username']) ?></b></span></td>
            <td class="r num" data-label="Tranzaksiya"><?= $s['n'] ?> ta</td>
            <td class="r num fw-7 text-in" data-label="Kirim">+<?= fmt($s['inc']) ?></td>
            <td class="r num fw-7 text-out" data-label="Chiqim">−<?= fmt($s['exp']) ?></td>
            <td class="text-2" data-label="Oxirgi"><?= e(date('d.m.Y', strtotime($s['last_date']))) ?></td>
            <td data-label="Ulush"><div class="bar bar-thin in" style="min-width:110px"><i style="width:<?= round($s['inc'] / $staff_max * 100) ?>%"></i></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Top transactions -->
  <div class="grid lg-g-2" style="margin-top:16px">
    <?php foreach ([['Eng katta kirimlar', $top_in, true], ['Eng katta chiqimlar', $top_out, false]] as [$ttl, $rows, $isin]): ?>
    <section class="card clip" aria-label="<?= e($ttl) ?>">
      <div class="card-head"><h2 class="card-title"><?= icon($isin ? 'arrow-down-left' : 'arrow-up-right', 'i-sm') ?> <?= e($ttl) ?></h2></div>
      <div class="list">
        <?php foreach ($rows as $r): $mm = method_meta($r['m']); ?>
        <div class="tx" style="padding-top:12px;padding-bottom:12px">
          <div class="tx-main">
            <div class="row between" style="gap:8px"><span class="tx-title"><?= e($r['cat']) ?></span><span class="tx-amt num <?= $isin ? 'text-in' : 'text-out' ?>"><?= $isin ? '+' : '−' ?><?= fmt($r['payment']) ?></span></div>
            <div class="tx-meta"><span class="badge badge-<?= $r['m'] ?>"><?= e($mm['label']) ?></span><span><?= e(date('d.m.Y', strtotime($r['date']))) ?></span></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="empty" style="padding:24px"><span class="fs-sm">Ma'lumot yo'q</span></div><?php endif; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php
$js = '<script>
(function(){
  if(!window.Chart || !document.getElementById("trendChart")) return;
  var D = ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
  var PAL = { light: { inc:"#1baf7a", exp:"#eb6834", line:"#2a78d6", cash:"#eda100", click:"#2a78d6", bank:"#e87ba4" } };
  function theme(){ return "light"; }
  function css(v){ return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }
  function nf(n){ return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g," "); }
  function short(n){ var a=Math.abs(n), s=n<0?"−":""; if(a>=1e9) return s+(a/1e9).toFixed(1).replace(/\.0$/,"")+" mlrd"; if(a>=1e6) return s+(a/1e6).toFixed(1).replace(/\.0$/,"")+" mln"; if(a>=1e3) return s+(a/1e3).toFixed(0)+" ming"; return s+nf(a); }
  var charts = [], mode = "daily";
  function base(c){
    return { responsive:true, maintainAspectRatio:false, animation:{duration:350},
      interaction:{ mode:"index", intersect:false },
      plugins:{ legend:{ position:"top", align:"start", labels:{ usePointStyle:true, pointStyle:"rectRounded", boxWidth:8, boxHeight:8, color:css("--text-2"), font:{family:"Plus Jakarta Sans",size:12,weight:"600"}, padding:14 } },
        tooltip:{ padding:10, cornerRadius:10, titleFont:{family:"Plus Jakarta Sans",weight:"700"}, bodyFont:{family:"Plus Jakarta Sans"}, usePointStyle:true, boxPadding:4,
          callbacks:{ label:function(ctx){ var v = ctx.parsed.x!==undefined && ctx.chart.options.indexAxis==="y" ? ctx.parsed.x : ctx.parsed.y; return " "+ctx.dataset.label+": "+nf(v)+" so‘m"; } } } },
      scales:{ x:{ grid:{display:false}, border:{color:css("--border")}, ticks:{ color:css("--text-3"), font:{family:"Plus Jakarta Sans",size:11}, maxRotation:0, autoSkip:true, maxTicksLimit:12 } },
               y:{ beginAtZero:true, grid:{ color:css("--border") }, border:{display:false}, ticks:{ color:css("--text-3"), font:{family:"Plus Jakarta Sans",size:11}, callback:function(v){ return short(v); } } } } };
  }
  function build(){
    charts.forEach(function(c){ c.destroy(); }); charts = [];
    var P = PAL[theme()], surface = css("--surface");

    // trend
    var o = base(); var many = D.labels.length > 62, ds;
    if(mode === "daily"){
      ds = many ? [
        { label:"Kirim", data:D.inc, borderColor:P.inc, backgroundColor:P.inc, borderWidth:2, pointRadius:0, pointHoverRadius:5, pointHoverBorderWidth:2, pointHoverBorderColor:surface, tension:.25 },
        { label:"Chiqim", data:D.exp, borderColor:P.exp, backgroundColor:P.exp, borderWidth:2, pointRadius:0, pointHoverRadius:5, pointHoverBorderWidth:2, pointHoverBorderColor:surface, tension:.25 } ] : [
        { label:"Kirim", data:D.inc, backgroundColor:P.inc, borderRadius:{topLeft:4,topRight:4}, maxBarThickness:22, borderColor:surface, borderWidth:{top:0,left:1,right:1,bottom:0} },
        { label:"Chiqim", data:D.exp, backgroundColor:P.exp, borderRadius:{topLeft:4,topRight:4}, maxBarThickness:22, borderColor:surface, borderWidth:{top:0,left:1,right:1,bottom:0} } ];
      charts.push(new Chart(document.getElementById("trendChart"), { type: many?"line":"bar", data:{ labels:D.labels, datasets:ds }, options:o }));
    } else {
      o.scales.y.beginAtZero = false;
      charts.push(new Chart(document.getElementById("trendChart"), { type:"line", data:{ labels:D.labels, datasets:[
        { label:"Jamlanma sof foyda", data:D.cum, borderColor:P.line, backgroundColor:P.line, borderWidth:2.5, pointRadius:D.labels.length>40?0:3, pointHoverRadius:6, pointBackgroundColor:P.line, pointBorderColor:surface, pointBorderWidth:2, tension:.25 } ] }, options:o }));
    }

    // monthly (bars in/out) + net line on the same axis
    var mo = base(); mo.scales.y.beginAtZero = false;
    charts.push(new Chart(document.getElementById("monthChart"), { data:{ labels:D.month.labels, datasets:[
      { type:"bar", label:"Kirim", data:D.month.inc, backgroundColor:P.inc, borderRadius:{topLeft:4,topRight:4}, maxBarThickness:26, borderColor:surface, borderWidth:{left:1,right:1} },
      { type:"bar", label:"Chiqim", data:D.month.exp, backgroundColor:P.exp, borderRadius:{topLeft:4,topRight:4}, maxBarThickness:26, borderColor:surface, borderWidth:{left:1,right:1} },
      { type:"line", label:"Sof foyda", data:D.month.net, borderColor:P.line, backgroundColor:P.line, borderWidth:2.5, pointRadius:3.5, pointHoverRadius:6, pointBackgroundColor:P.line, pointBorderColor:surface, pointBorderWidth:2, tension:.25 } ] }, options:mo }));

    // weekday
    var w = base(); w.interaction = { mode:"index", intersect:false };
    charts.push(new Chart(document.getElementById("wdChart"), { type:"bar", data:{ labels:D.wd.labels, datasets:[
      { label:"Kirim", data:D.wd.inc, backgroundColor:P.inc, borderRadius:{topLeft:4,topRight:4}, maxBarThickness:20 },
      { label:"Chiqim", data:D.wd.exp, backgroundColor:P.exp, borderRadius:{topLeft:4,topRight:4}, maxBarThickness:20 } ] }, options:w }));

    // expense: category x method (stacked, horizontal)
    var xc = document.getElementById("xcChart");
    if(xc){
      var x = base(); x.indexAxis = "y"; x.scales.x.stacked = true; x.scales.y.stacked = true;
      x.scales.x.grid = { color:css("--border") }; x.scales.x.ticks.callback = function(v){ return short(v); }; x.scales.x.beginAtZero = true; x.scales.x.border = {display:false};
      x.scales.y.grid = { display:false }; x.scales.y.beginAtZero = undefined; x.scales.y.ticks.callback = undefined; x.scales.y.border = {color:css("--border")};
      x.scales.y.ticks.font = {family:"Plus Jakarta Sans",size:12,weight:"600"}; x.scales.y.ticks.color = css("--text-2");
      var seg = function(k,l){ return { label:l, data:D.xc.series[k], backgroundColor:P[k], borderColor:surface, borderWidth:2, borderRadius:3 }; };
      charts.push(new Chart(xc, { type:"bar", data:{ labels:D.xc.labels, datasets:[ seg("cash","Naqd"), seg("click","Click"), seg("bank","Bank") ] }, options:x }));
    }
  }
  document.getElementById("trendMode").addEventListener("click", function(e){
    var b = e.target.closest("[data-mode]"); if(!b) return; mode = b.dataset.mode;
    this.querySelectorAll("[data-mode]").forEach(function(x){ x.classList.toggle("on", x===b); x.setAttribute("aria-pressed", x===b); });
    document.getElementById("trendChart").setAttribute("aria-label", mode==="daily" ? "Kunlik kirim va chiqim grafigi" : "Jamlanma sof foyda grafigi");
    build();
  });
  window.addEventListener("beforeprint", function(){ charts.forEach(function(c){ c.resize(); }); });
  build();
})();
</script>';
ui_end(['active' => 'analytics', 'scripts' => $js]);
