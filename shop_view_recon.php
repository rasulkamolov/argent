<?php
/** "Do'kon" tab of Solishtirish: Do'konPro cash/card/transfer per branch and category against what was typed into Argent (shop / kitob). Included by solishtirish.php (administrator only). */
require_once __DIR__ . '/shop_report.php';
require_admin();

$B = branches_all(); $mapIds = shop_branch_ids();
$view = ($_GET['view'] ?? 'day') === 'month' ? 'month' : 'day';
$date = valid_date($_GET['date'] ?? null) ? $_GET['date'] : date('Y-m-d'); if ($date > date('Y-m-d')) $date = date('Y-m-d');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : substr($date, 0, 7);
$fb = (int)($_GET['branch'] ?? 0); $ids = $fb && in_array($fb, $mapIds, true) ? [$fb] : $mapIds;
$fstat = in_array($_GET['status'] ?? '', array_merge(['problem'], array_keys(SHOP_ST)), true) ? $_GET['status'] : '';
$err = ''; $rows = []; $T = null; $days = [];
try {
    if (!shop_configured()) throw new ShopError("Do'konPro bazasi topilmadi. Admin → Do'kon da yo'lni kiriting.", 'config');
    if ($view === 'day') { $res = shop_recon_day($date, $ids); $rows = $res['rows']; $T = $res['totals'];
        if ($fstat === 'problem') $rows = array_values(array_filter($rows, function ($r) { return $r['status'] !== 'ok'; })); elseif ($fstat) $rows = array_values(array_filter($rows, function ($r) use ($fstat) { return $r['status'] === $fstat; })); }
    else $days = shop_recon_month($month, $ids);
} catch (Throwable $e) { $err = lcup_redact($e->getMessage()); }

$safe = function ($v) { $v = (string)$v; return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; };
$csvRow = function (array $r) use ($safe) { return implode(';', array_map(function ($v) use ($safe) { return '"' . str_replace('"', '""', $safe($v)) . '"'; }, $r)) . "\r\n"; };
if (!empty($_GET['export']) && !$err) {
    audit('shop.export', 'report', null, ['view' => $view, 'date' => $date, 'month' => $month], $fb ?: null);
    header('Content-Type: text/csv; charset=UTF-8'); header('Cache-Control: no-store'); header('Content-Disposition: attachment; filename="dokon_solishtirish_' . ($view === 'day' ? $date : $month) . '.csv"'); echo "\xEF\xBB\xBF";
    if ($view === 'day') {
        echo $csvRow(['Sana', 'Filial', 'Kategoriya', "Do'kon naqd", "Do'kon karta+o'tkazma", "Do'kon jami", 'Argent naqd', 'Argent Click+Bank', 'Argent jami', 'Farq (Argent-Do\'kon)', 'Holat', 'Keyin kiritilgan (kun)']);
        foreach ($rows as $r) echo $csvRow([$date, $B[$r['branch_id']]['name'] ?? '', shop_cat_label($r['cat']), round($r['s_cash']), round($r['s_non']), round($r['s_total']), round($r['a_cash']), round($r['a_non']), round($r['a_total']), round($r['diff']), SHOP_ST[$r['status']][1], $r['late_days']]);
        echo "\r\n" . $csvRow(['JAMI', '', '', '', '', round($T['shop']), '', '', round($T['argent']), round($T['diff']), $T['problems'] . ' ta muammo', '']);
    } else {
        echo $csvRow(['Sana', "Do'kon jami", 'Argent jami', 'Farq', 'Muammolar']);
        foreach ($days as $d) echo $csvRow([$d['date'], round($d['shop']), round($d['argent']), round($d['diff']), $d['problems']]);
    }
    exit;
}
$q = function (array $o) use ($view, $date, $month, $fb, $fstat) { return 'solishtirish.php?' . http_build_query(array_filter(array_merge(['tab' => 'shop', 'view' => $view === 'month' ? 'month' : null, 'date' => $date, 'month' => $month, 'branch' => $fb ?: null, 'status' => $fstat ?: null], $o), function ($v) { return $v !== null && $v !== ''; })); };
$prev = date('Y-m-d', strtotime($date . ' -1 day')); $next = date('Y-m-d', strtotime($date . ' +1 day'));
ui_head("Solishtirish — Do'kon"); ui_start('shop');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Solishtirish</h1><p class="page-sub">Do'konPro kassasi (naqd, karta+o'tkazma) va Argentga kiritilgan «shop» / «kitob» kirimlari</p></div>
    <div class="row gap-2 no-print row-wrap"><?php if (!$err): ?><a class="btn btn-success" href="<?= e($q(['export' => 1])) ?>"><?= icon('download', 'i-sm') ?> Excel (CSV)</a><?php endif; ?></div>
  </div>
  <?php ui_shop_subnav('recon'); ?>
  <div style="height:12px"></div>

  <form method="get" action="solishtirish.php" class="card card-pad no-print" style="margin-bottom:14px">
    <input type="hidden" name="tab" value="shop">
    <div class="row row-wrap" style="gap:8px;align-items:end">
      <div class="seg" role="tablist" aria-label="Ko'rinish" style="display:flex;gap:6px"><a class="chip" href="<?= e($q(['view' => null])) ?>" <?= $view === 'day' ? 'aria-current="true"' : '' ?>>Kun</a><a class="chip" href="<?= e($q(['view' => 'month'])) ?>" <?= $view === 'month' ? 'aria-current="true"' : '' ?>>Oy</a></div>
      <input type="hidden" name="view" value="<?= $view === 'month' ? 'month' : '' ?>">
      <?php if ($view === 'day'): ?><a class="btn btn-sm btn-icon" href="<?= e($q(['date' => $prev])) ?>" aria-label="Oldingi kun"><?= icon('chevron-left', 'i-sm') ?></a><input class="input input-sm" type="date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" style="max-width:170px"><?php if ($next <= date('Y-m-d')): ?><a class="btn btn-sm btn-icon" href="<?= e($q(['date' => $next])) ?>" aria-label="Keyingi kun"><?= icon('chevron-right', 'i-sm') ?></a><?php endif; ?>
      <?php else: ?><input class="input input-sm" type="month" name="month" value="<?= e($month) ?>" max="<?= e(date('Y-m')) ?>" style="max-width:170px"><?php endif; ?>
      <select class="select input-sm" name="branch" style="max-width:220px" aria-label="Filial"><option value="0">Barcha filiallar</option><?php foreach ($mapIds as $id): ?><option value="<?= (int)$id ?>" <?= $fb === (int)$id ? 'selected' : '' ?>><?= e($B[$id]['name'] ?? $id) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-primary" type="submit"><?= icon('filter', 'i-sm') ?> Ko'rsatish</button>
    </div>
  </form>

  <?php if ($err): ?><div class="alert error"><?= icon('triangle-alert') ?><div class="grow"><?= e($err) ?> <a href="admin.php#shop" style="text-decoration:underline">Admin → Do'kon</a></div></div>
  <?php elseif (!$mapIds): ?><div class="alert error"><?= icon('triangle-alert') ?><div class="grow">Filial moslash sozlanmagan. <a href="admin.php#shop" style="text-decoration:underline">Admin → Do'kon</a></div></div>
  <?php elseif ($view === 'day'): ?>
  <section class="grid g-2 lg-g-4" aria-label="Jami">
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Do'konPro jami</span><span class="icon-tile primary" style="width:32px;height:32px;border-radius:10px"><?= icon('receipt', 'i-sm') ?></span></div><div class="kpi-value num"><?= fmt($T['shop']) ?></div><div class="kpi-foot"><?= e(uz_date($date)) ?> · naqd + karta + qarz yig'ilishi − qaytarish</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Argent jami</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('wallet', 'i-sm') ?></span></div><div class="kpi-value num"><?= fmt($T['argent']) ?></div><div class="kpi-foot">«shop» + «kitob» kirimlari</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Farq</span></div><div class="kpi-value num <?= abs($T['diff']) >= 1 ? 'text-out' : 'text-in' ?>"><?= e(fmt_signed($T['diff'])) ?></div><div class="kpi-foot">Argent − Do'konPro</div></div>
    <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Muammolar</span></div><div class="kpi-value num <?= $T['problems'] ? 'text-out' : 'text-in' ?>"><?= (int)$T['problems'] ?></div><div class="kpi-foot"><?php $bits = []; foreach ($T['by'] as $k => $n) if ($n && $k !== 'ok') $bits[] = SHOP_ST[$k][0] . ' ' . $n; echo $T['problems'] ? e(implode(' · ', $bits)) : 'Hammasi mos ✅'; ?></div></div>
  </section>
  <div class="row row-wrap no-print" style="gap:6px;margin:14px 0 10px">
    <?php foreach ([['', 'Hammasi'], ['problem', 'Faqat muammolar'], ['missing', '🔴 Kiritilmagan'], ['short', "🔴 Do'konda ko'p"], ['nokitob', "🔴 Kitob do'konda yo'q"], ['extra', "🟠 Argentda ko'p"], ['method', '🟡 Usul'], ['late', '🟡 Kech kiritilgan'], ['wrongcat', "🟡 Noto'g'ri kategoriya"], ['ok', '✅ Mos']] as [$k, $l]): ?><a class="chip" href="<?= e($q(['status' => $k])) ?>" <?= $fstat === $k ? 'aria-current="true"' : '' ?>><?= e($l) ?></a><?php endforeach; ?>
  </div>
  <section class="card clip"><div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Filial · kategoriya</th><th class="r">Naqd: do'kon / Argent</th><th class="r">Karta+o'tkazma: do'kon / Click+Bank</th><th class="r">Farq</th><th>Holat</th></tr></thead><tbody>
    <?php foreach ($rows as $r): [$em, $lbl] = SHOP_ST[$r['status']]; $bb = $B[$r['branch_id']] ?? null; ?>
      <tr>
        <td class="cell-title"><div class="row" style="gap:6px"><?= branch_avatar($bb, 'xs') ?><b><?= e($bb['name'] ?? '—') ?></b></div><div class="fs-sm text-2"><?= e(shop_cat_label($r['cat'])) ?> · <?= (int)$r['sales'] ?> ta chek</div>
          <details class="lc-details"><summary>Tafsilot</summary><div class="lc-sub">
            <span><?= e(shop_recon_text($r)) ?></span>
            <?php foreach ($r['items'] as $it): ?><span>Argent #<?= (int)$it['id'] ?> · <?= e(local_time($it['created_at'])) ?> · <?= e($it['author']) ?> · <?= e(method_meta(method_of($it))['label']) ?> · <b><?= fmt($it['payment']) ?></b> · «<?= e($it['cat_name']) ?>»<?= $it['comment'] ? ' · ' . e(mb_substr($it['comment'], 0, 60)) : '' ?><?= $it['late'] >= 1 ? ' <span class="text-out">· ' . (int)$it['late'] . ' kun keyin kiritilgan (' . e(date('d.m', strtotime($it['entered']))) . ')</span>' : '' ?><?= $it['wrong'] ? " <span class=\"text-out\">· noto'g'ri kategoriya</span>" : '' ?></span><?php endforeach; ?>
          </div></details></td>
        <td class="r num" data-label="Naqd"><?= fmt($r['s_cash']) ?> / <b><?= fmt($r['a_cash']) ?></b></td>
        <td class="r num" data-label="Karta+o'tkazma"><?= fmt($r['s_non']) ?> / <b><?= fmt($r['a_non']) ?></b></td>
        <td class="r num fw-8 <?= abs($r['diff']) >= 1 ? 'text-out' : '' ?>" data-label="Farq"><?= abs($r['diff']) >= 1 ? e(fmt_signed($r['diff'])) : '0' ?></td>
        <td data-label="Holat"><span class="lc-st <?= $r['status'] === 'ok' ? 'ok' : (in_array($r['status'], ['short', 'missing', 'nokitob'], true) ? 'missing' : ($r['status'] === 'extra' ? 'extra' : 'diff')) ?>"><?= $em ?> <?= e($lbl) ?><?= $r['status'] === 'late' ? ' (' . (int)$r['late_days'] . ' kun kech)' : '' ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5"><div class="empty"><span class="icon-tile in"><?= icon('circle-check') ?></span><b>Yozuv yo'q</b><span class="fs-sm">Bu kun uchun do'kon savdosi ham, Argent kirimi ham topilmadi.</span></div></td></tr><?php endif; ?>
    </tbody></table></div></section>
  <p class="hint" style="margin-top:10px">Kutilgan summa = shu kuni do'konda kassaga tushgan pul: chek vaqtida to'langan qism + qarz to'lovlari − qaytarilgan pul. Qarz (to'lanmagan) qismi hisobga olinmaydi. Do'kon «Karta» + «O'tkazma» Argentdagi Click + Bank bilan <b>birga</b> solishtiriladi. Kuni 00:00 da almashadi. «Kitob» — Do'konPro dagi «<?= e(shop_setting('kitob_category')) ?>» kategoriyasi.</p>
  <?php else: ?>
  <section class="card clip"><div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Sana</th><th class="r">Do'konPro</th><th class="r">Argent</th><th class="r">Farq</th><th>Muammolar</th></tr></thead><tbody>
    <?php foreach (array_reverse($days) as $d): ?>
      <tr><td class="cell-title"><a href="<?= e($q(['view' => null, 'date' => $d['date']])) ?>" style="text-decoration:underline;text-decoration-color:var(--border-strong);text-underline-offset:3px"><?= e(date('d.m.Y', strtotime($d['date']))) ?></a> <span class="text-3 fs-xs fw-5" style="margin-left:6px"><?= e(uz_weekday($d['date'], true)) ?></span></td>
        <td class="r num fw-6" data-label="Do'konPro"><?= $d['shop'] > 0 ? fmt($d['shop']) : '—' ?></td><td class="r num fw-6" data-label="Argent"><?= $d['argent'] > 0 ? fmt($d['argent']) : '—' ?></td>
        <td class="r num fw-8 <?= abs($d['diff']) >= 1 ? 'text-out' : '' ?>" data-label="Farq"><?= abs($d['diff']) >= 1 ? e(fmt_signed($d['diff'])) : '0' ?></td>
        <td data-label="Muammolar"><?php if ($d['problems']): ?><span class="lc-flags" style="margin:0"><?php foreach ($d['by'] as $k => $n) if ($n && $k !== 'ok'): ?><span class="badge <?= in_array($k, ['short', 'missing', 'nokitob'], true) ? 'badge-danger' : 'badge-warn' ?>"><?= SHOP_ST[$k][0] ?> <?= (int)$n ?></span><?php endif; ?></span><?php else: ?><span class="lc-st ok">✅ <?= $d['shop'] > 0 || $d['argent'] > 0 ? 'Mos' : "Yozuv yo'q" ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody><tfoot><tr><td class="cell-title">Jami</td><td class="r num"><?= fmt(array_sum(array_column($days, 'shop'))) ?></td><td class="r num"><?= fmt(array_sum(array_column($days, 'argent'))) ?></td><td class="r num"><?= e(fmt_signed(array_sum(array_column($days, 'diff')))) ?></td><td><?= (int)array_sum(array_column($days, 'problems')) ?> ta muammo</td></tr></tfoot></table></div></section>
  <?php endif; ?>
</div>
<?php ui_end(['active' => 'shop']);
