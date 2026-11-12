<?php
/**
 * Solishtirish: LC-UP payments (by the time the payment was CREATED) against the Argent "To'lov" entries of the same day,
 * matched on the LC-UP student id in the Argent comment. Administrator only.
 */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lcup_lib.php';
require_admin();

$B = branches_all(); $mapIds = lcup_branch_ids();
$view = ($_GET['view'] ?? 'day') === 'month' ? 'month' : 'day';
$date = valid_date($_GET['date'] ?? null) ? $_GET['date'] : date('Y-m-d'); if ($date > date('Y-m-d')) $date = date('Y-m-d');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : substr($date, 0, 7);
$fb = (int)($_GET['branch'] ?? 0); $ids = $fb && in_array($fb, $mapIds, true) ? [$fb] : $mapIds;
$fstat = in_array($_GET['status'] ?? '', ['problem', 'missing', 'extra', 'diff', 'method', 'nolink', 'ok'], true) ? $_GET['status'] : '';
$baseUrl = rtrim((string)cfg('LCUP_BASE_URL', 'https://oxfordlc.lc-up.com'), '/');
$cov = lcup_coverage_start($ids);
$S = ['ok' => ['✅', 'Mos'], 'missing' => ['🔴', "LC-UP da bor, Argent'da yo'q"], 'extra' => ['🟠', "Argent'da bor, LC-UP da yo'q"], 'diff' => ['🟡', 'Summa farq qiladi'], 'method' => ['🟡', "To'lov usuli farq qiladi"], 'nolink' => ['🟠', 'LC-UP havolasi yo\'q']];

$safe = function ($v) { $v = (string)$v; return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; };
$csvRow = function (array $r) use ($safe) { return implode(';', array_map(function ($v) use ($safe) { return '"' . str_replace('"', '""', $safe($v)) . '"'; }, $r)) . "\r\n"; };

if ($view === 'day') { $res = lcup_reconcile($date, $ids); $rows = $res['rows']; $T = $res['totals'];
    if ($fstat === 'problem') $rows = array_values(array_filter($rows, function ($r) { return $r['status'] !== 'ok'; })); elseif ($fstat) $rows = array_values(array_filter($rows, function ($r) use ($fstat) { return $r['status'] === $fstat; }));
} else { $days = lcup_reconcile_month($month, $ids); }

if (!empty($_GET['export'])) {
    audit('lcup.export', 'report', null, ['view' => $view, 'date' => $date, 'month' => $month], $fb ?: null);
    header('Content-Type: text/csv; charset=UTF-8'); header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="solishtirish_' . ($view === 'day' ? $date : $month) . '.csv"');
    echo "\xEF\xBB\xBF";
    if ($view === 'day') {
        echo $csvRow(['Sana', 'Filial', 'Talaba', 'LC-UP ID', 'LC-UP summa (hozir)', 'LC-UP summa (yaratilganda)', 'LC-UP naqd', 'LC-UP boshqa/noma\'lum', 'Argent summa', 'Argent naqd', 'Argent Click/Bank', 'Farq (Argent-LC-UP)', 'Holat']);
        foreach ($rows as $r) echo $csvRow([$date, $B[$r['branch_id']]['name'] ?? '', $r['name'], $r['student_id'], round($r['l_total']), round($r['l_orig']), round($r['l_cash']), round($r['l_non'] + $r['l_unk']), round($r['a_total']), round($r['a_cash']), round($r['a_non']), round($r['diff']), $S[$r['status']][1]]);
        echo "\r\n" . $csvRow(['JAMI', '', '', '', round($T['lcup']), '', '', '', round($T['argent']), '', '', round($T['diff']), $T['problems'] . ' ta muammo']);
    } else {
        echo $csvRow(['Sana', 'LC-UP jami', 'Argent jami', 'Farq', 'Muammolar', "LC-UP da bor, Argent'da yo'q", "Argent'da bor, LC-UP da yo'q", 'Summa farq', 'Usul farq', 'Havolasiz']);
        foreach ($days as $d) echo $csvRow([$d['date'], round($d['lcup']), round($d['argent']), round($d['diff']), $d['problems'], $d['by']['missing'], $d['by']['extra'], $d['by']['diff'], $d['by']['method'], $d['by']['nolink']]);
    }
    exit;
}

$q = function (array $o) use ($view, $date, $month, $fb, $fstat) { return 'solishtirish.php?' . http_build_query(array_filter(array_merge(['view' => $view === 'month' ? 'month' : null, 'date' => $date, 'month' => $month, 'branch' => $fb ?: null, 'status' => $fstat ?: null], $o), function ($v) { return $v !== null && $v !== ''; })); };
$prev = date('Y-m-d', strtotime($date . ' -1 day')); $next = date('Y-m-d', strtotime($date . ' +1 day'));
ui_head('Solishtirish'); ui_start('recon');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Solishtirish</h1><p class="page-sub">LC-UP to'lovlari (yaratilgan vaqti bo'yicha) va Argent «To'lov» yozuvlari — talaba ID bo'yicha</p></div>
    <div class="row gap-2 no-print row-wrap"><a class="btn btn-success" href="<?= e($q(['export' => 1])) ?>"><?= icon('download', 'i-sm') ?> Excel (CSV)</a></div>
  </div>
  <?php ui_lcup_subnav('recon'); ?>

  <form method="get" action="solishtirish.php" class="card card-pad no-print" style="margin-bottom:14px">
    <div class="row row-wrap" style="gap:8px;align-items:end">
      <div class="seg" role="tablist" aria-label="Ko'rinish" style="display:flex;gap:6px"><a class="chip" href="<?= e($q(['view' => null])) ?>" <?= $view === 'day' ? 'aria-current="true"' : '' ?>>Kun</a><a class="chip" href="<?= e($q(['view' => 'month'])) ?>" <?= $view === 'month' ? 'aria-current="true"' : '' ?>>Oy</a></div>
      <input type="hidden" name="view" value="<?= $view === 'month' ? 'month' : '' ?>">
      <?php if ($view === 'day'): ?><a class="btn btn-sm btn-icon" href="<?= e($q(['date' => $prev])) ?>" aria-label="Oldingi kun"><?= icon('chevron-left', 'i-sm') ?></a><input class="input input-sm" type="date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" style="max-width:170px"><?php if ($next <= date('Y-m-d')): ?><a class="btn btn-sm btn-icon" href="<?= e($q(['date' => $next])) ?>" aria-label="Keyingi kun"><?= icon('chevron-right', 'i-sm') ?></a><?php endif; ?>
      <?php else: ?><input class="input input-sm" type="month" name="month" value="<?= e($month) ?>" max="<?= e(date('Y-m')) ?>" style="max-width:170px"><?php endif; ?>
      <select class="select input-sm" name="branch" style="max-width:220px" aria-label="Filial"><option value="0">Ikkala filial</option><?php foreach ($mapIds as $id): ?><option value="<?= (int)$id ?>" <?= $fb === (int)$id ? 'selected' : '' ?>><?= e($B[$id]['name'] ?? $id) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-primary" type="submit"><?= icon('filter', 'i-sm') ?> Ko'rsatish</button>
    </div>
  </form>

  <?php if (!$mapIds): ?><div class="alert error"><?= icon('triangle-alert') ?><div class="grow">Filial moslash sozlanmagan. <a href="admin.php#lcup" style="text-decoration:underline">Admin → LC-UP</a></div></div>
  <?php else: ?>
  <?php if ($cov === null || (($view === 'day' ? $date : $month . '-01') < $cov)): ?><div class="alert info" style="margin-bottom:14px;background:var(--warn-soft);color:var(--warn-text)"><?= icon('circle-alert') ?><div class="grow">LC-UP ma'lumotlari <?= $cov ? e(date('d.m.Y', strtotime($cov))) . " dan boshlab" : "hali yig'ilmagan" ?> (monitoring jurnali LC-UP da oxirgi ~7 kunni ko'rsatadi). Undan oldingi sanalar uchun natija to'liq bo'lmasligi mumkin.</div></div><?php endif; ?>

  <?php if ($view === 'day'): ?>
  <section class="grid g-2 lg-g-4" aria-label="Jami">
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">LC-UP jami</span><span class="icon-tile primary" style="width:32px;height:32px;border-radius:10px"><?= icon('radar', 'i-sm') ?></span></div><div class="kpi-value num"><?= fmt($T['lcup']) ?></div><div class="kpi-foot"><?= e(uz_date($date)) ?></div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Argent jami</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('wallet', 'i-sm') ?></span></div><div class="kpi-value num"><?= fmt($T['argent']) ?></div><div class="kpi-foot">«To'lov» kirimlari</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Farq</span></div><div class="kpi-value num <?= abs($T['diff']) >= 1 ? 'text-out' : 'text-in' ?>"><?= e(fmt_signed($T['diff'])) ?></div><div class="kpi-foot">Argent − LC-UP</div></div>
    <div class="card kpi <?= $T['problems'] ? '' : '' ?>"><div class="kpi-top"><span class="kpi-label">Muammolar</span></div><div class="kpi-value num <?= $T['problems'] ? 'text-out' : 'text-in' ?>"><?= (int)$T['problems'] ?></div><div class="kpi-foot"><?= $T['problems'] ? implode(' · ', array_filter([$T['by']['missing'] ? "🔴 {$T['by']['missing']}" : '', $T['by']['extra'] ? "🟠 {$T['by']['extra']}" : '', $T['by']['diff'] ? "🟡 {$T['by']['diff']} summa" : '', $T['by']['method'] ? "🟡 {$T['by']['method']} usul" : '', $T['by']['nolink'] ? "🟠 {$T['by']['nolink']} havolasiz" : ''])) : "Hammasi mos ✅" ?></div></div>
  </section>
  <div class="row row-wrap no-print" style="gap:6px;margin:14px 0 10px">
    <?php foreach ([['', 'Hammasi'], ['problem', 'Faqat muammolar'], ['missing', '🔴 Topshirilmagan'], ['extra', '🟠 LC-UP da yo\'q'], ['diff', '🟡 Summa'], ['method', '🟡 Usul'], ['nolink', '🟠 Havolasiz'], ['ok', '✅ Mos']] as [$k, $l]): ?><a class="chip" href="<?= e($q(['status' => $k])) ?>" <?= $fstat === $k ? 'aria-current="true"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
  </div>
  <section class="card clip">
    <div class="table-wrap"><table class="table table-stack">
      <thead><tr><th>Talaba</th><th>Filial</th><th class="r">LC-UP</th><th class="r">Argent</th><th class="r">Farq</th><th>Holat</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): [$em, $lbl] = $S[$r['status']]; $bb = $B[$r['branch_id']] ?? null; ?>
        <tr>
          <td class="cell-title"><div><?php if ($r['student_id']): ?><a href="<?= e($baseUrl . '/admin/students/' . $r['student_id']) ?>" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;text-decoration-color:var(--border-strong);text-underline-offset:3px"><?= e($r['name'] ?: '#' . $r['student_id']) ?></a> <span class="text-3 fs-xs">#<?= e($r['student_id']) ?></span><?php else: ?><b>LC-UP havolasiz yozuv</b><?php endif; ?></div>
            <details class="lc-details"><summary>Tafsilot</summary><div class="lc-sub">
              <?php foreach ($r['l_items'] as $it): ?><span>LC-UP <?= e(substr($it['act_time'], 11, 5)) ?> · <?= e($it['staff']) ?> · <?= e(lcup_method_label($it['pmethod'])) ?: '?' ?> · <b><?= fmt($it['cur']) ?></b><?= abs($it['cur'] - $it['amount']) >= 1 ? ' <span class="text-out">(yaratilganda ' . fmt($it['amount']) . ')</span>' : '' ?></span><?php endforeach; ?>
              <?php foreach ($r['a_items'] as $it): ?><span>Argent <?= e(local_time($it['created_at'])) ?> · <?= e($it['author']) ?> · <?= e(method_meta(method_of($it))['label']) ?> · <b><?= fmt($it['payment']) ?></b><?= $it['comment'] ? ' · ' . e(mb_substr($it['comment'], 0, 60)) : '' ?></span><?php endforeach; ?>
            </div></details></td>
          <td data-label="Filial"><span class="row" style="gap:6px"><?= branch_avatar($bb, 'xs') ?><span class="fs-sm"><?= e($bb['name'] ?? '—') ?></span></span></td>
          <td class="r num fw-6" data-label="LC-UP"><?= $r['l_total'] > 0 ? fmt($r['l_total']) : '—' ?></td>
          <td class="r num fw-6" data-label="Argent"><?= $r['a_total'] > 0 ? fmt($r['a_total']) : '—' ?></td>
          <td class="r num fw-8 <?= abs($r['diff']) >= 1 ? 'text-out' : '' ?>" data-label="Farq"><?= abs($r['diff']) >= 1 ? e(fmt_signed($r['diff'])) : '0' ?></td>
          <td data-label="Holat"><span class="lc-st <?= e($r['status']) ?>"><?= $em ?> <?= e($lbl) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><span class="icon-tile in"><?= icon('circle-check') ?></span><b>Yozuv yo'q</b><span class="fs-sm">Bu kun uchun filtrga mos qator topilmadi.</span></div></td></tr><?php endif; ?>
      </tbody>
      <?php if ($rows): ?><tfoot><tr><td class="cell-title">Jami</td><td class="hide-stack"></td><td class="r num" data-label="LC-UP"><?= fmt($T['lcup']) ?></td><td class="r num" data-label="Argent"><?= fmt($T['argent']) ?></td><td class="r num <?= abs($T['diff']) >= 1 ? 'text-out' : '' ?>" data-label="Farq"><?= e(fmt_signed($T['diff'])) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table></div>
  </section>
  <p class="hint" style="margin-top:10px">LC-UP summasi — to'lov hozir LC-UP da qancha turgani (o'chirilganlar hisobga olinmaydi). Argent tomoni: izohida <code>…/admin/students/ID</code> havolasi bor kirimlar, hamda havolasiz «To'lov» kategoriyasidagi kirimlar.</p>

  <?php else: ?>
  <section class="card clip">
    <div class="table-wrap"><table class="table table-stack">
      <thead><tr><th>Sana</th><th class="r">LC-UP</th><th class="r">Argent</th><th class="r">Farq</th><th>Muammolar</th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($days) as $d): ?>
        <tr>
          <td class="cell-title"><a href="<?= e($q(['view' => null, 'date' => $d['date']])) ?>" style="text-decoration:underline;text-decoration-color:var(--border-strong);text-underline-offset:3px"><?= e(date('d.m.Y', strtotime($d['date']))) ?></a> <span class="text-3 fs-xs fw-5" style="margin-left:6px"><?= e(uz_weekday($d['date'], true)) ?></span></td>
          <td class="r num fw-6" data-label="LC-UP"><?= $d['lcup'] > 0 ? fmt($d['lcup']) : '—' ?></td>
          <td class="r num fw-6" data-label="Argent"><?= $d['argent'] > 0 ? fmt($d['argent']) : '—' ?></td>
          <td class="r num fw-8 <?= abs($d['diff']) >= 1 ? 'text-out' : '' ?>" data-label="Farq"><?= abs($d['diff']) >= 1 ? e(fmt_signed($d['diff'])) : '0' ?></td>
          <td data-label="Muammolar"><?php if ($d['problems']): ?><span class="lc-flags" style="margin:0"><?= $d['by']['missing'] ? '<span class="badge badge-danger">🔴 ' . $d['by']['missing'] . '</span>' : '' ?><?= $d['by']['extra'] ? '<span class="badge badge-warn">🟠 ' . $d['by']['extra'] . '</span>' : '' ?><?= $d['by']['diff'] ? '<span class="badge badge-warn">🟡 ' . $d['by']['diff'] . ' summa</span>' : '' ?><?= $d['by']['method'] ? '<span class="badge badge-warn">🟡 ' . $d['by']['method'] . ' usul</span>' : '' ?><?= $d['by']['nolink'] ? '<span class="badge badge-warn">🟠 ' . $d['by']['nolink'] . ' havolasiz</span>' : '' ?></span><?php else: ?><span class="lc-st ok">✅ <?= $d['lcup'] > 0 || $d['argent'] > 0 ? 'Mos' : "Yozuv yo'q" ?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td class="cell-title">Jami</td><td class="r num" data-label="LC-UP"><?= fmt(array_sum(array_column($days, 'lcup'))) ?></td><td class="r num" data-label="Argent"><?= fmt(array_sum(array_column($days, 'argent'))) ?></td><td class="r num" data-label="Farq"><?= e(fmt_signed(array_sum(array_column($days, 'diff')))) ?></td><td><?= (int)array_sum(array_column($days, 'problems')) ?> ta muammo</td></tr></tfoot>
    </table></div>
  </section>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php ui_end(['active' => 'recon']);
