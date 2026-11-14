<?php
/** Aniqlik tekshiruvi: every day 10 random incidents re-checked against the student's Tarix (actor, branch, amount). Administrator only. */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lcup_report.php';
require_once __DIR__ . '/lcup_accuracy.php';
require_admin();

$day = valid_date($_GET['day'] ?? null) ? $_GET['day'] : date('Y-m-d');
$d = lcup_accuracy_day($day); $want = count(lcup_accuracy_pick_peek($day));
$hist = db()->query("SELECT day, COUNT(*) AS n, SUM(actor_ok AND branch_ok AND amount_ok) AS ok, SUM(actor_ok) AS a, SUM(branch_ok) AS b, SUM(amount_ok) AS m FROM lcup_accuracy GROUP BY day ORDER BY day DESC LIMIT 30")->fetchAll();
function lcup_accuracy_pick_peek(string $day): array { $v = lcup_state_get("acc_pick_$day"); return $v ? (json_decode($v, true) ?: []) : []; }
$mark = function ($ok) { return $ok ? '<span class="lc-st ok">✓</span>' : '<span class="lc-st missing">✗</span>'; };
ui_head('Aniqlik tekshiruvi'); ui_start('lcup');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Aniqlik tekshiruvi</h1><p class="page-sub">Har kuni so'nggi 7 kundagi 10 ta tasodifiy hodisa talabaning «Tarix» idan qayta o'qiladi: xodim, filial va summa to'g'rimi?</p></div>
    <div class="row gap-2 no-print row-wrap">
      <form action="process.php" method="post" data-loading><?= csrf_field() ?><input type="hidden" name="action" value="lcup_accuracy_now"><button class="btn btn-primary" type="submit"><?= icon('target', 'i-sm') ?> Hozir tekshirish</button></form>
      <a class="btn" href="lcup.php"><?= icon('radar', 'i-sm') ?> Hodisalar</a>
    </div>
  </div>
  <?php ui_lcup_subnav('acc'); ?>

  <section class="grid g-2 lg-g-4" style="margin-top:14px" aria-label="Natija">
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Natija · <?= e(date('d.m.Y', strtotime($day))) ?></span></div><div class="kpi-value num <?= $d['checked'] && $d['ok'] === $d['checked'] ? 'text-in' : ($d['checked'] ? 'text-out' : '') ?>"><?= $d['checked'] ? $d['ok'] . '/' . $d['checked'] . ($d['ok'] === $d['checked'] ? ' ✅' : ' ⚠️') : '—' ?></div><div class="kpi-foot"><?= $want ? "tanlangan: $want ta" : "bugun hali tanlanmagan (" . e(lcup_setting('accuracy_time')) . " dan keyin)" ?></div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Xodim to'g'ri</span></div><div class="kpi-value num"><?= $d['checked'] ? $d['actor'] . '/' . $d['checked'] : '—' ?></div><div class="kpi-foot">jurnal/Tarix bo'yicha</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Filial to'g'ri</span></div><div class="kpi-value num"><?= $d['checked'] ? $d['branch'] . '/' . $d['checked'] : '—' ?></div><div class="kpi-foot">guruh → filial xaritasi</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Summa to'g'ri</span></div><div class="kpi-value num"><?= $d['checked'] ? $d['amount'] . '/' . $d['checked'] : '—' ?></div><div class="kpi-foot">eski → yangi</div></div>
  </section>

  <section class="card clip" style="margin-top:14px"><div class="card-head"><h2 class="card-title"><?= icon('target', 'i-sm') ?> Tekshirilgan hodisalar</h2></div>
    <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Hodisa</th><th>Argent ko'rsatgan</th><th>Tarixda</th><th>Xodim</th><th>Filial</th><th>Summa</th><th>Izoh</th></tr></thead><tbody>
    <?php foreach ($d['rows'] as $r): $x = json_decode((string)$r['expected'], true) ?: []; $f = json_decode((string)$r['found'], true) ?: []; ?>
      <tr class="<?= ($r['actor_ok'] && $r['branch_ok'] && $r['amount_ok']) ? '' : 'lc-ev is-red' ?>"><td class="cell-title"><a href="lcup.php?id=<?= (int)$r['incident_id'] ?>&amp;leader=all#ev<?= (int)$r['incident_id'] ?>" style="text-decoration:underline">#<?= (int)$r['incident_id'] ?></a> <?= e(lcup_kind_label((string)($x['kind'] ?? $r['kind'] ?? ''))) ?><div class="fs-xs text-3"><?= e($r['student_name'] ?? '') ?></div></td>
        <td data-label="Argent" class="fs-sm"><?= e(($x['actor'] ?? null) ?: "Noma'lum") ?> · <?= e(lcup_branch_name(isset($x['branch']) ? (int)$x['branch'] : null)) ?> · <?= ($x['old'] ?? null) !== null ? e(fmt($x['old'])) . ' → ' : '' ?><?= e(fmt($x['new'] ?? $x['amount'] ?? 0)) ?></td>
        <td data-label="Tarix" class="fs-sm"><?php foreach ($f as $y): ?><div><?= e($y['staff']) ?> · <?= e(substr((string)$y['at'], 11, 5)) ?> · <?= e($y['group']) ?> · <?= $y['old'] !== null ? e(fmt($y['old'])) . ' → ' : '' ?><?= e(fmt($y['new'])) ?></div><?php endforeach; ?><?= $f ? '' : '<span class="text-3">topilmadi</span>' ?></td>
        <td data-label="Xodim"><?= $mark($r['actor_ok']) ?></td><td data-label="Filial"><?= $mark($r['branch_ok']) ?></td><td data-label="Summa"><?= $mark($r['amount_ok']) ?></td><td data-label="Izoh" class="fs-sm"><?= e($r['note']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$d['rows']): ?><tr><td colspan="7"><div class="empty"><span class="icon-tile primary"><?= icon('target') ?></span><b>Bu kun uchun tekshiruv yo'q</b><span class="fs-sm">Tekshiruv har kuni avtomatik ishlaydi yoki «Hozir tekshirish» ni bosing.</span></div></td></tr><?php endif; ?>
    </tbody></table></div></section>

  <section class="card clip" style="margin-top:14px"><div class="card-head"><h2 class="card-title"><?= icon('history', 'i-sm') ?> Kunlar bo'yicha</h2></div>
    <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Sana</th><th class="r">Natija</th><th class="r">Xodim</th><th class="r">Filial</th><th class="r">Summa</th></tr></thead><tbody>
    <?php foreach ($hist as $h): ?><tr><td class="cell-title"><a href="aniqlik.php?day=<?= e($h['day']) ?>" style="text-decoration:underline"><?= e(date('d.m.Y', strtotime($h['day']))) ?></a></td><td class="r num fw-7 <?= (int)$h['ok'] === (int)$h['n'] ? 'text-in' : 'text-out' ?>" data-label="Natija"><?= (int)$h['ok'] ?>/<?= (int)$h['n'] ?></td><td class="r num" data-label="Xodim"><?= (int)$h['a'] ?></td><td class="r num" data-label="Filial"><?= (int)$h['b'] ?></td><td class="r num" data-label="Summa"><?= (int)$h['m'] ?></td></tr><?php endforeach; ?>
    <?php if (!$hist): ?><tr><td colspan="5" class="text-3">Hali tekshiruv bo'lmagan.</td></tr><?php endif; ?>
    </tbody></table></div></section>
  <p class="hint" style="margin-top:10px">Noto'g'ri chiqqan hodisa Tarixdagi to'g'ri ma'lumot bilan avtomatik tuzatiladi va kunlik xulosada «tuzatildi» bo'lib chiqadi. 100% dan past natija — Telegram xabar.</p>
</div>
<?php ui_end(['active' => 'lcup']);
