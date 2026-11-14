<?php
/** Qarzdorlik o'zgarishi: why the branch's total debt (Moliya → Qarzdorlar, "Jami") went up or down on a day. Administrator only. */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lcup_report.php';
require_admin();

$B = branches_all(); $mapIds = lcup_branch_ids();
$day = valid_date($_GET['day'] ?? null) ? $_GET['day'] : date('Y-m-d'); if ($day > date('Y-m-d')) $day = date('Y-m-d');
$fb = (int)($_GET['branch'] ?? 0); if (!in_array($fb, $mapIds, true)) $fb = $mapIds[0] ?? 0;
$r = $fb ? lcup_debt_day($fb, $day) : null; $pick = (string)($_GET['s'] ?? '');
$prev = date('Y-m-d', strtotime($day . ' -1 day')); $next = date('Y-m-d', strtotime($day . ' +1 day'));
$q = function (array $o) use ($day, $fb) { return 'qarzdorlik.php?' . http_build_query(array_merge(['day' => $day, 'branch' => $fb], $o)); };
$baseUrl = rtrim((string)cfg('LCUP_BASE_URL', 'https://oxfordlc.lc-up.com'), '/');
ui_head("Qarzdorlik o'zgarishi"); ui_start('lcup');
?>
<div class="page">
  <div class="page-head"><div><h1 class="page-title">Qarzdorlik o'zgarishi</h1><p class="page-sub">Moliya → Qarzdorlar «Jami» kun boshidan kun oxirigacha nega o'zgardi — har bir talaba bo'yicha, jurnal va Tarix yozuvlari bilan</p></div></div>
  <?php ui_lcup_subnav('debt'); ?>
  <form method="get" action="qarzdorlik.php" class="card card-pad no-print" style="margin:14px 0">
    <div class="row row-wrap" style="gap:8px;align-items:end">
      <a class="btn btn-sm btn-icon" href="<?= e($q(['day' => $prev])) ?>" aria-label="Oldingi kun"><?= icon('chevron-left', 'i-sm') ?></a><input class="input input-sm" type="date" name="day" value="<?= e($day) ?>" max="<?= e(date('Y-m-d')) ?>" style="max-width:170px"><?php if ($next <= date('Y-m-d')): ?><a class="btn btn-sm btn-icon" href="<?= e($q(['day' => $next])) ?>" aria-label="Keyingi kun"><?= icon('chevron-right', 'i-sm') ?></a><?php endif; ?>
      <select class="select input-sm" name="branch" style="max-width:220px" aria-label="Filial"><?php foreach ($mapIds as $id): ?><option value="<?= (int)$id ?>" <?= $fb === (int)$id ? 'selected' : '' ?>><?= e($B[$id]['name'] ?? $id) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-primary" type="submit"><?= icon('filter', 'i-sm') ?> Ko'rsatish</button>
    </div>
  </form>
  <?php if (!$r || !$r['start'] || !$r['end']): ?>
    <div class="alert info" style="background:var(--warn-soft);color:var(--warn-text)"><?= icon('circle-alert') ?><div class="grow">Bu kun uchun qarzdorlar snapshoti yo'q: <?= !$r || !$r['start'] ? "kun boshi (00:05) olinmagan" : '' ?><?= $r && $r['start'] && !$r['end'] ? "kun oxiri (23:55) olinmagan" : '' ?>. Snapshotlar cron har daqiqa ishlaganda avtomatik olinadi.</div></div>
  <?php else: $ch = (float)$r['change']; ?>
  <section class="grid g-2 lg-g-4" aria-label="Jami">
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Boshlanish (<?= e($r['start']['label']) ?>)</span></div><div class="kpi-value num"><?= $r['start']['jami'] !== null ? e(fmt_signed($r['start']['jami'])) : '—' ?></div><div class="kpi-foot"><?= e(date('d.m H:i', strtotime($r['start']['at']))) ?> · <?= (int)$r['start']['cnt'] ?> ta</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Oxiri (<?= e($r['end']['label']) ?>)</span></div><div class="kpi-value num"><?= $r['end']['jami'] !== null ? e(fmt_signed($r['end']['jami'])) : '—' ?></div><div class="kpi-foot"><?= e(date('d.m H:i', strtotime($r['end']['at']))) ?> · <?= (int)$r['end']['cnt'] ?> ta</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Qarz o'zgarishi</span></div><div class="kpi-value num <?= $ch < -0.5 ? 'text-in' : ($ch > 0.5 ? 'text-out' : '') ?>"><?= e(fmt_signed($ch)) ?></div><div class="kpi-foot"><?= $ch < -0.5 ? 'qarz kamaydi' : ($ch > 0.5 ? 'qarz oshdi' : "o'zgarmadi") ?><?= $r['check'] !== null && abs($r['check']) >= 1 ? ' · hisob farqi ' . e(fmt_signed($r['check'])) : '' ?></div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">❓ Sababsiz</span></div><div class="kpi-value num <?= abs($r['unexplained']) >= 1 ? 'text-out' : 'text-in' ?>"><?= e(fmt_signed($r['unexplained'])) ?></div><div class="kpi-foot"><?= abs($r['unexplained']) >= 1 ? 'hech bir yozuv bilan tushuntirilmagan' : 'hammasi tushuntirildi ✅' ?></div></div>
  </section>
  <section class="card clip" style="margin-top:14px"><div class="card-head"><h2 class="card-title"><?= icon('chart-column', 'i-sm') ?> Sabablar bo'yicha</h2></div>
    <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Sabab</th><th class="r">Qarzga ta'siri (so'm)</th><th class="r">Talabalar</th></tr></thead><tbody>
    <?php foreach ($r['by'] as $k => $b): ?><tr class="<?= $k === 'sababsiz' ? 'lc-ev is-red' : '' ?>"><td class="cell-title"><?= e(LCUP_DEBT_REASON[$k] ?? $k) ?></td><td class="r num fw-7 <?= $k === 'sababsiz' ? 'text-out' : '' ?>" data-label="Ta'sir"><?= e(fmt_signed($b['amount'])) ?></td><td class="r num" data-label="Talabalar"><?= (int)$b['n'] ?></td></tr><?php endforeach; ?>
    <?php if (!$r['by']): ?><tr><td colspan="3" class="text-3">Bu kun qarzdorlik o'zgarmadi.</td></tr><?php endif; ?>
    </tbody><tfoot><tr><td class="cell-title">Jami</td><td class="r num"><?= e(fmt_signed($ch)) ?></td><td></td></tr></tfoot></table></div></section>
  <section class="card clip" style="margin-top:14px"><div class="card-head"><h2 class="card-title"><?= icon('users', 'i-sm') ?> Talabalar (<?= count($r['students']) ?>)</h2></div>
    <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Talaba</th><th class="r">Boshida</th><th class="r">Oxirida</th><th class="r">Qarz o'zgarishi</th><th>Sabablar</th></tr></thead><tbody>
    <?php foreach ($r['students'] as $s): $bad = abs($s['unexplained_debt']) >= 1; ?>
      <tr class="<?= $bad ? 'lc-ev is-red' : '' ?>" id="s<?= e(md5($s['key'])) ?>">
        <td class="cell-title"><?php if ($s['student_id']): ?><a href="<?= e($baseUrl . '/admin/students/' . rawurlencode($s['student_id'])) ?>" target="_blank" rel="noopener noreferrer" style="text-decoration:underline"><?= e($s['name'] ?: '#' . $s['student_id']) ?></a><?php else: ?><?= e($s['name']) ?><?php endif; ?>
          <div class="fs-xs text-3"><?= e($s['groups']) ?><?= $s['joined'] ? ' · yangi qarzdor' : '' ?><?= $s['left'] ? " · ro'yxatdan chiqdi" : '' ?></div></td>
        <td class="r num" data-label="Boshida"><?= $s['start'] !== null ? e(fmt_signed($s['start'])) : '—' ?></td><td class="r num" data-label="Oxirida"><?= $s['end'] !== null ? e(fmt_signed($s['end'])) : '—' ?></td>
        <td class="r num fw-7" data-label="O'zgarish"><?= e(fmt_signed($s['delta_debt'])) ?></td>
        <td data-label="Sabablar"><div class="lc-sub"><?php foreach ($s['items'] as $it): ?><span class="<?= $it['reason'] === 'sababsiz' ? 'text-out fw-7' : '' ?>"><?= e(LCUP_DEBT_REASON[$it['reason']] ?? $it['reason']) ?>: <b><?= $it['effect'] !== null ? e(fmt_signed(-$it['effect'])) : '?' ?></b><?= !empty($it['estimated']) ? ' (taxminiy)' : '' ?><?= $it['at'] ? ' · ' . e(substr($it['at'], 11, 5)) : '' ?><?= $it['actor'] ? ' · ' . e($it['actor']) : '' ?><?= $it['src'] ? ' · ' . e($it['src']) : '' ?><?= $it['label'] ? ' — ' . e(mb_substr($it['label'], 0, 70)) : '' ?></span><?php endforeach; ?></div></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$r['students']): ?><tr><td colspan="5" class="text-3">O'zgargan talaba yo'q.</td></tr><?php endif; ?>
    </tbody></table></div></section>
  <p class="hint" style="margin-top:10px">Qarz ta'siri: to'lov qarzni kamaytiradi (−), oylik to'lov / o'chirilgan to'lov oshiradi (+). Oyning 1-kuni LC-UP oylik to'lovlarni avtomatik hisoblaydi — «Oylik to'lov hisoblandi». Summasi ko'rinmaydigan yozuvlar (chegirma, aktivlashtirish) qoldiqni «taxminiy» o'ziga oladi. Snapshotlar o'zgartirib bo'lmaydigan qilib saqlanadi.</p>
  <?php endif; ?>
</div>
<?php ui_end(['active' => 'lcup']);
