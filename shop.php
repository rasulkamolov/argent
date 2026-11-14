<?php
/** Do'kon nazorat: append-only feed of everything suspicious found in Do'konPro, health of the monitoring, weekly report (kamomad + sotuvchilar). Administrator only. */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/shop_report.php';
require_admin();
lcup_remember_app_url();

$view = ($_GET['view'] ?? '') === 'report' ? 'report' : 'feed';
$B = branches_all(); $mapIds = shop_branch_ids();
$labels = shop_flag_labels(); $typeL = shop_type_labels();
$redBadge = array_merge(SHOP_RED_FLAGS, ['late_edit']);

if ($view === 'report') {
    [$wf, $wt] = shop_week_range();
    if (valid_date($_GET['from'] ?? null)) { $wf = date('Y-m-d', strtotime('monday this week', strtotime($_GET['from']))); $wt = date('Y-m-d', strtotime($wf . ' +6 days')); }
    $sh = null; $pat = null; $err = '';
    try { $sh = shop_shrinkage($wf, $wt); $pat = shop_patterns($wf, $wt); } catch (Throwable $e) { $err = lcup_redact($e->getMessage()); }
    $prevW = date('Y-m-d', strtotime($wf . ' -7 days')); $nextW = date('Y-m-d', strtotime($wf . ' +7 days'));
    ui_head("Do'kon — haftalik hisobot"); ui_start('shop');
    ?>
<div class="page">
  <div class="page-head"><div><h1 class="page-title">Haftalik hisobot</h1><p class="page-sub">Kamomad (hisobdan chiqarish, minus tuzatish, qaytarish, o'chirilgan cheklar) va sotuvchilar bo'yicha qiyosiy ko'rsatkichlar</p></div></div>
  <?php ui_shop_subnav('report'); ?>
  <div class="row row-wrap no-print" style="gap:8px;margin:14px 0"><a class="btn btn-sm btn-icon" href="shop.php?view=report&from=<?= e($prevW) ?>" aria-label="Oldingi hafta"><?= icon('chevron-left', 'i-sm') ?></a>
    <b><?= e(date('d.m.Y', strtotime($wf))) ?> – <?= e(date('d.m.Y', strtotime($wt))) ?></b>
    <?php if ($nextW <= date('Y-m-d')): ?><a class="btn btn-sm btn-icon" href="shop.php?view=report&from=<?= e($nextW) ?>" aria-label="Keyingi hafta"><?= icon('chevron-right', 'i-sm') ?></a><?php endif; ?></div>
  <?php if ($err): ?><div class="alert error"><?= icon('triangle-alert') ?><div class="grow"><?= e($err) ?></div></div><?php endif; ?>
  <?php if ($sh): foreach ($mapIds as $bid): $b = $sh['branch'][$bid] ?? null; $st = array_filter($sh['staff'], function ($k) use ($bid) { return strpos($k, $bid . '|') === 0; }, ARRAY_FILTER_USE_KEY); ?>
  <section class="card card-pad stack" style="margin-bottom:14px">
    <h2 class="card-title"><?= branch_avatar($B[$bid] ?? null, 'xs') ?> <?= e($B[$bid]['name'] ?? '#' . $bid) ?> — kamomad</h2>
    <?php if ($b && shop_shrink_total($b) > 0): ?>
      <div class="kpi-value num text-out"><?= e(fmt(shop_shrink_total($b))) ?> <span class="fs-sm text-3">so'm jami</span></div>
      <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Tur</th><th class="r">Soni</th><th class="r">Qiymati (so'm)</th></tr></thead><tbody>
        <?php foreach (SHOP_SHRINK_LABEL as $k => $l): if (!$b[$k][0]) continue; ?><tr><td class="cell-title"><?= e($l) ?></td><td class="r num" data-label="Soni"><?= (int)$b[$k][0] ?></td><td class="r num fw-7" data-label="Qiymati"><?= e(fmt($b[$k][1])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Xodim bo'yicha</th><th class="r">Jami (so'm)</th><th>Tarkibi</th></tr></thead><tbody>
        <?php foreach ($st as $key => $x): if (shop_shrink_total($x) <= 0) continue; $nm = explode('|', $key, 2)[1]; ?><tr><td class="cell-title"><?= e($nm) ?></td><td class="r num fw-7" data-label="Jami"><?= e(fmt(shop_shrink_total($x))) ?></td><td class="fs-sm" data-label="Tarkibi"><?php foreach (SHOP_SHRINK_LABEL as $k => $l) if ($x[$k][0]) echo e($l . ' ' . $x[$k][0] . ' ta · ' . fmt($x[$k][1])) . '<br>'; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <p class="hint">Hisobdan chiqarish/minus tuzatish — tannarx bo'yicha, qaytarish va o'chirilgan cheklar — sotuv summasi bo'yicha.</p>
    <?php else: ?><p class="text-3">Bu hafta kamomad hodisasi qayd etilmagan.</p><?php endif; ?>
    <?php $ic = db()->prepare("SELECT * FROM shop_inv_checks WHERE branch_id = ? AND status = 'done' AND substr(completed_at,1,10) BETWEEN ? AND ? ORDER BY id"); $ic->execute([$bid, $wf, $wt]);
      foreach ($ic->fetchAll() as $c): $t = shop_inv_totals((int)$c['id']); ?><div class="lc-check"><div class="<?= $t['short_cost'] > 0 ? 'bad' : 'ok' ?>">Inventarizatsiya #<?= (int)$c['id'] ?> (<?= e(date('d.m', strtotime($c['completed_at']))) ?>): <?= (int)$t['items'] ?> tovar, kamomad <?= e(fmt($t['short_cost'])) ?> (tannarx) / <?= e(fmt($t['short_sale'])) ?> (sotuv narxi)</div></div><?php endforeach; ?>
    <?php $p = $pat[$bid] ?? null; if ($p && $p['rows']): ?>
      <h3 class="card-title" style="margin-top:6px">Sotuvchilar <span class="text-3 fw-5 fs-sm">· filial kuniga o'rtacha <?= e(fmt($p['avg_day'])) ?> (<?= (int)$p['days'] ?> kun)</span></h3>
      <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Sotuvchi</th><th class="r">Cheklar</th><th class="r">O'rtacha chek</th><th class="r">Naqd ulushi</th><th class="r">Qaytarish</th><th class="r">O'chirilgan</th><th class="r">Qarz</th><th class="r">Tuzatish</th><th class="r">Smena kunlari</th></tr></thead><tbody>
        <?php foreach ($p['rows'] as $r): ?><tr class="<?= $r['flags'] ? 'lc-ev is-yellow' : '' ?>"><td class="cell-title"><b><?= e($r['name']) ?></b><?php foreach ($r['flags'] as $f): ?><div class="fs-sm text-out">⚠️ <?= e($f) ?></div><?php endforeach; ?></td>
          <td class="r num" data-label="Cheklar"><?= (int)$r['n'] ?></td><td class="r num" data-label="O'rtacha"><?= e(fmt($r['avg'])) ?></td><td class="r num" data-label="Naqd"><?= round($r['cash_share'] * 100) ?>%</td><td class="r num" data-label="Qaytarish"><?= (int)$r['returns'] ?></td><td class="r num" data-label="O'chirilgan"><?= (int)$r['deleted'] ?></td><td class="r num" data-label="Qarz"><?= (int)$r['debts'] ?> · <?= e(fmt($r['debt_sum'])) ?></td><td class="r num" data-label="Tuzatish"><?= (int)$r['adjust'] ?></td>
          <td class="r num" data-label="Smena"><?= (int)$r['shift_days'] ?> kun<?= $r['shift_vs_avg'] !== null ? ' · ' . round($r['shift_vs_avg'] * 100) . '%' : '' ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <p class="hint">«Smena kunlari» ustuni: shu xodim sotgan kunlarda filialning kunlik savdosi umumiy o'rtachaga nisbatan necha foiz. 🔺 — boshqalardan sezilarli farq qilgan (chegaralar Admin → Do'kon da).</p>
    <?php else: ?><p class="text-3">Bu hafta sotuv yo'q.</p><?php endif; ?>
  </section>
  <?php endforeach; endif; ?>
</div>
<?php ui_end(['active' => 'shop']); exit; }

/* ---------------- feed ---------------- */
$fb = (int)($_GET['branch'] ?? 0); $fsev = in_array($_GET['sev'] ?? '', ['red', 'yellow', 'info'], true) ? $_GET['sev'] : '';
$fst = in_array($_GET['status'] ?? '', ['unread', 'reviewed'], true) ? $_GET['status'] : '';
$ftype = preg_replace('/[^a-z_]/', '', (string)($_GET['type'] ?? '')); $fq = trim((string)($_GET['q'] ?? ''));
$from = valid_date($_GET['from'] ?? null) ? $_GET['from'] : ''; $to = valid_date($_GET['to'] ?? null) ? $_GET['to'] : '';
$hist = !empty($_GET['hist']); $pickId = (int)($_GET['id'] ?? 0);
$w = ['1=1']; $p = [];
if ($fb) { $w[] = 'e.branch_id = ?'; $p[] = $fb; }
if ($fsev) { $w[] = 'e.severity = ?'; $p[] = $fsev; }
if ($fst === 'unread') $w[] = "e.severity = 'red' AND e.reviewed_at IS NULL"; elseif ($fst === 'reviewed') $w[] = 'e.reviewed_at IS NOT NULL';
if ($ftype !== '') { $w[] = 'e.type = ?'; $p[] = $ftype; }
if ($from) { $w[] = 'e.event_date >= ?'; $p[] = $from; } if ($to) { $w[] = 'e.event_date <= ?'; $p[] = $to; }
if ($fq !== '') { $w[] = '(e.staff LIKE ? OR e.details LIKE ? OR e.ref LIKE ?)'; $p = array_merge($p, ['%' . $fq . '%', '%' . $fq . '%', '%' . $fq . '%']); }
if (!$hist && $pickId === 0) $w[] = 'e.backfill = 0';
$wh = implode(' AND ', $w); $pg = max(1, (int)($_GET['p'] ?? 1)); $lim = 25;
$cs = db()->prepare("SELECT COUNT(*) FROM shop_events e WHERE $wh"); $cs->execute($p); $total = (int)$cs->fetchColumn(); $pages = max(1, (int)ceil($total / $lim)); $pg = min($pg, $pages);
$order = $pickId ? "(e.id = $pickId) DESC, e.id DESC" : 'e.id DESC';
$st = db()->prepare("SELECT e.*, b.name AS branch_name, u.username AS reviewer FROM shop_events e LEFT JOIN branches b ON b.id = e.branch_id LEFT JOIN users u ON u.id = e.reviewed_by WHERE $wh ORDER BY $order LIMIT $lim OFFSET " . (($pg - 1) * $lim));
$st->execute($p); $events = $st->fetchAll();
$qs = function (array $o) use ($fb, $fsev, $fst, $ftype, $fq, $from, $to, $hist, $pg) { return 'shop.php?' . http_build_query(array_filter(array_merge(['branch' => $fb ?: null, 'sev' => $fsev ?: null, 'status' => $fst ?: null, 'type' => $ftype ?: null, 'q' => $fq ?: null, 'from' => $from ?: null, 'to' => $to ?: null, 'hist' => $hist ? 1 : null, 'p' => $pg > 1 ? $pg : null], $o), function ($v) { return $v !== null && $v !== ''; })); };

$last = shop_state_get('last_success'); $lastRun = shop_state_get('last_run'); $fails = (int)shop_state_get('fail_count', 0); $lastErr = (string)shop_state_get('last_error', '');
$minRun = shop_minutes_since_run(); $stale = $minRun !== null && $minRun >= 5; $never = $lastRun === null; $unread = shop_unread_count();
$warn = json_decode((string)shop_state_get('last_warnings', '[]'), true) ?: [];
ui_head("Do'kon nazorat"); ui_start('shop');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Do'kon nazorat</h1><p class="page-sub">Do'konPro dagi shubhali o'zgarishlar — o'chirib ham, o'zgartirib ham bo'lmaydi, faqat «ko'rildi» deb belgilanadi</p></div>
    <div class="row gap-2 no-print row-wrap"><a class="btn" href="solishtirish.php?tab=shop"><?= icon('scale', 'i-sm') ?> Solishtirish</a><a class="btn" href="admin.php#shop"><?= icon('settings', 'i-sm') ?> Sozlamalar</a></div>
  </div>
  <?php ui_shop_subnav('shop'); ?>

  <?php if (!shop_configured()): ?><div class="alert error" style="margin-bottom:14px"><?= icon('triangle-alert') ?><div class="grow">Do'konPro bazasi topilmadi. <a href="admin.php#shop" style="text-decoration:underline">Admin → Do'kon</a> da bazaning yo'lini kiriting.</div></div>
  <?php elseif ($never): ?><div class="alert error" style="margin-bottom:14px"><?= icon('triangle-alert') ?><div class="grow">Monitoring hali bir marta ham ishlamagan. Cron ishlayaptimi? <a href="admin.php#shop" style="text-decoration:underline">Admin → Do'kon</a></div></div>
  <?php elseif ($stale): ?><div class="alert error" style="margin-bottom:14px"><?= icon('clock-alert') ?><div class="grow"><b>Do'kon monitoringi <?= (int)$minRun ?> daqiqadan beri ishlamayapti.</b> Cron ishlayaptimi?</div></div>
  <?php elseif ($fails > 0): ?><div class="alert error" style="margin-bottom:14px"><?= icon('triangle-alert') ?><div class="grow"><b>Oxirgi <?= $fails ?> urinish muvaffaqiyatsiz.</b> <?= e($lastErr) ?></div></div><?php endif; ?>

  <section class="card card-pad" style="margin-bottom:16px" aria-label="Holat"><div class="lc-health">
    <div class="kv"><div class="k">Oxirgi muvaffaqiyatli tekshiruv</div><div class="v"><?= $last ? e(date('d.m.Y H:i', strtotime($last))) . ' <span class="text-3 fs-sm">(' . (int)floor((time() - strtotime($last)) / 60) . ' daq. oldin)</span>' : '—' ?></div></div>
    <div class="kv"><div class="k">Ketma-ket xatolar</div><div class="v <?= $fails ? 'text-out' : '' ?>"><?= $fails ?></div></div>
    <div class="kv"><div class="k">Ko'rilmagan qizil</div><div class="v <?= $unread ? 'text-out' : '' ?>"><?= $unread ?></div></div>
    <div class="kv"><div class="k">Do'konPro bazasi</div><div class="v fs-sm"><?= shop_configured() ? "ulangan · faqat o'qish ✓" : "<span class='text-out'>topilmadi</span>" ?></div></div>
    <div class="kv"><div class="k">Telegram</div><div class="v"><?= lcup_tg_ready() ? (shop_state_get('tg_last_error') ? '<span class="text-out">xato: ' . e(shop_state_get('tg_last_error')) . '</span>' : 'ulangan ✓') : "<span class='text-out'>sozlanmagan</span>" ?></div></div>
  </div>
  <?php if ($warn): ?><div class="lc-check" style="margin-top:12px"><?php foreach ($warn as $x): ?><div class="warn"><?= e($x) ?></div><?php endforeach; ?></div><?php endif; ?></section>

  <form method="get" action="shop.php" class="card card-pad no-print" style="margin-bottom:14px"><div class="row row-wrap" style="gap:8px">
    <select class="select input-sm" name="branch" style="max-width:200px" aria-label="Filial"><option value="0">Barcha filiallar</option><?php foreach ($mapIds as $id): ?><option value="<?= (int)$id ?>" <?= $fb === (int)$id ? 'selected' : '' ?>><?= e($B[$id]['name'] ?? $id) ?></option><?php endforeach; ?></select>
    <select class="select input-sm" name="status" style="max-width:190px" aria-label="Holat"><option value="">Barchasi</option><option value="unread" <?= $fst === 'unread' ? 'selected' : '' ?>>Ko'rilmagan qizil</option><option value="reviewed" <?= $fst === 'reviewed' ? 'selected' : '' ?>>Ko'rilgan</option></select>
    <select class="select input-sm" name="sev" style="max-width:150px" aria-label="Daraja"><option value="">🔴 🟡 va oddiy</option><option value="red" <?= $fsev === 'red' ? 'selected' : '' ?>>🔴 Faqat qizil</option><option value="yellow" <?= $fsev === 'yellow' ? 'selected' : '' ?>>🟡 Faqat sariq</option><option value="info" <?= $fsev === 'info' ? 'selected' : '' ?>>Faqat oddiy</option></select>
    <select class="select input-sm" name="type" style="max-width:230px" aria-label="Turi"><option value="">Barcha turlar</option><?php foreach ($typeL as $k => $l): ?><option value="<?= e($k) ?>" <?= $ftype === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <input class="input input-sm" type="date" name="from" value="<?= e($from) ?>" style="max-width:160px" aria-label="Boshlanish"><input class="input input-sm" type="date" name="to" value="<?= e($to) ?>" style="max-width:160px" aria-label="Tugash">
    <input class="input input-sm" type="search" name="q" value="<?= e($fq) ?>" placeholder="Xodim, chek, tovar…" style="max-width:200px">
    <label class="row gap-2 fs-sm text-2" style="cursor:pointer"><input type="checkbox" name="hist" value="1" <?= $hist ? 'checked' : '' ?>> tarixiy yozuvlar</label>
    <button class="btn btn-sm btn-primary" type="submit"><?= icon('filter', 'i-sm') ?> Qo'llash</button></div></form>

  <section class="card clip" aria-label="Hodisalar">
    <div class="card-head"><h2 class="card-title"><?= icon('receipt', 'i-sm') ?> Hodisalar</h2>
      <div class="row gap-2"><span class="badge"><?= fmt($total) ?> ta</span>
      <?php $unr = array_filter($events, function ($e) { return $e['severity'] === 'red' && !$e['reviewed_at']; }); if ($unr): ?><button type="button" class="btn btn-sm btn-soft no-print" data-review-all="<?= e(implode(',', array_column($unr, 'id'))) ?>"><?= icon('check-check', 'i-sm') ?> Sahifadagilarni ko'rildi</button><?php endif; ?></div></div>
    <div class="table-wrap"><table class="table table-stack">
      <thead><tr><th>Vaqt</th><th>Filial</th><th>Hodisa</th><th class="r">Summa</th><th>Tafsilot</th><th></th></tr></thead><tbody>
      <?php foreach ($events as $ev): $red = $ev['severity'] === 'red'; $fl = array_filter(explode(',', (string)$ev['flags'])); $bb = $ev['branch_id'] ? ($B[(int)$ev['branch_id']] ?? null) : null; ?>
        <tr class="lc-ev <?= $red ? 'is-red' : ($ev['severity'] === 'yellow' ? 'is-yellow' : '') ?> <?= $ev['backfill'] ? 'is-hist' : '' ?> <?= $pickId === (int)$ev['id'] ? 'is-pick' : '' ?>" id="ev<?= (int)$ev['id'] ?>">
          <td class="cell-title"><div><b class="num"><?= e(date('d.m H:i', strtotime($ev['event_time'] ?: $ev['detected_at']))) ?></b></div><div class="fs-xs text-3 fw-5">aniqlandi <?= e(date('d.m H:i', strtotime($ev['detected_at']))) ?></div></td>
          <td data-label="Filial"><?= $bb ? '<span class="lc-br">' . branch_avatar($bb, 'xs') . '<span class="fs-sm">' . e($bb['name']) . '</span></span>' : '<span class="text-3">—</span>' ?></td>
          <td data-label="Hodisa"><span class="badge <?= $red ? 'badge-danger' : ($ev['severity'] === 'yellow' ? 'badge-warn' : '') ?>"><?= $red ? '🔴 ' : ($ev['severity'] === 'yellow' ? '🟡 ' : '') ?><?= e($typeL[$ev['type']] ?? $ev['type']) ?></span><?php if ($ev['ref']): ?><div class="fs-sm text-2" style="margin-top:4px"><?= icon('receipt', 'i-xs') ?> <?= e($ev['ref']) ?></div><?php endif; ?><?php if ($ev['staff']): ?><div class="fs-sm text-2" style="margin-top:2px"><?= icon('user-round', 'i-xs') ?> <?= e($ev['staff']) ?></div><?php endif; ?>
            <?php if ($fl): ?><div class="lc-flags"><?php foreach ($fl as $f): ?><span class="badge <?= in_array($f, $redBadge, true) ? 'badge-danger' : 'badge-warn' ?>"><?= e($labels[$f] ?? $f) ?></span><?php endforeach; ?></div><?php endif; ?></td>
          <td class="r num fw-7" data-label="Summa"><?= $ev['amount'] !== null ? e(fmt($ev['amount'])) : '—' ?></td>
          <td data-label="Tafsilot"><div class="lc-chg"><?= e($ev['details']) ?></div>
            <?php if ($ev['reviewed_at']): ?><div class="lc-note"><?= icon('check-check', 'i-xs') ?> Ko'rildi: <?= e($ev['reviewer'] ?: '—') ?>, <?= e(date('d.m H:i', strtotime($ev['reviewed_at']))) ?><?= $ev['review_note'] !== null && $ev['review_note'] !== '' ? ' — «' . e($ev['review_note']) . '»' : '' ?></div><?php endif; ?></td>
          <td class="cell-actions no-print"><?php if (!$ev['reviewed_at'] && $red): ?><button type="button" class="btn btn-sm btn-soft" data-review="<?= (int)$ev['id'] ?>"><?= icon('eye', 'i-sm') ?> Ko'rildi</button><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$events): ?><tr><td colspan="6"><div class="empty"><span class="icon-tile in"><?= icon('circle-check') ?></span><b>Hodisalar yo'q</b><span class="fs-sm">Hozircha filtrga mos hodisa topilmadi.</span></div></td></tr><?php endif; ?>
      </tbody></table></div>
    <?php if ($pages > 1): ?><div class="card-foot pager"><span class="fs-sm text-3"><?= fmt($total) ?> ta · <?= $pg ?>/<?= $pages ?></span><nav class="pager-nav"><?php if ($pg > 1): ?><a href="<?= e($qs(['p' => $pg - 1])) ?>" aria-label="Oldingi"><?= icon('chevron-left', 'i-sm') ?></a><?php endif; ?><?php if ($pg < $pages): ?><a href="<?= e($qs(['p' => $pg + 1])) ?>" aria-label="Keyingi"><?= icon('chevron-right', 'i-sm') ?></a><?php endif; ?></nav></div><?php endif; ?>
  </section>
  <p class="hint" style="margin-top:10px">Hodisalar bazada o'zgartirib ham, o'chirib ham bo'lmaydigan qilib saqlanadi (administrator ham). Faqat «ko'rildi» belgisi va izoh bir marta yoziladi. Do'konPro bazasiga Argent hech narsa yozmaydi.</p>
</div>

<dialog class="modal" id="reviewDialog" aria-labelledby="rvTitle"><div class="modal-box">
  <div class="modal-head"><h2 class="modal-title" id="rvTitle">Ko'rildi deb belgilash</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
  <form action="process.php" method="post" class="stack" data-loading>
    <?= csrf_field() ?><input type="hidden" name="action" value="shop_review"><input type="hidden" name="ids" id="rvIds"><input type="hidden" name="back" value="<?= e(urldecode(ui_back_param()) ?: 'shop.php') ?>">
    <div class="field"><label class="label" for="rvNote">Izoh <span class="text-3 fw-5">(nima aniqlandi?)</span></label><textarea class="textarea" id="rvNote" name="note" rows="3" maxlength="500" placeholder="Masalan: o'zim sotgan edim / sotuvchi tushuntirdi"></textarea><span class="hint">Belgi va izoh keyin o'zgartirilmaydi.</span></div>
    <button type="submit" class="btn btn-primary btn-lg btn-block"><?= icon('check-check', 'i-sm') ?> Ko'rildi</button></form></div></dialog>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-review],[data-review-all]'); if (!b) return;
  document.getElementById('rvIds').value = b.getAttribute('data-review') || b.getAttribute('data-review-all');
  var d = document.getElementById('reviewDialog'); if (d.showModal) d.showModal(); else d.setAttribute('open', '');
  setTimeout(function () { document.getElementById('rvNote').focus(); }, 50);
});
(function () { var p = document.querySelector('.is-pick'); if (p) p.scrollIntoView({ block: 'center' }); })();
</script>
<?php ui_end(['active' => 'shop']);
