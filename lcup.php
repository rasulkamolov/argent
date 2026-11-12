<?php
/** LC-UP nazorat: append-only feed of every payment-related change found in LC-UP, health of the sync, review ("ko'rildi"). Administrator only. */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lcup_report.php';
require_admin();
lcup_remember_app_url();
lcup_stale_check();

/* ---- Diagnostika zip download (GET, administrator only) ---- */
if (isset($_GET['download'])) {
    $n = preg_replace('/[^0-9_]/', '', (string)$_GET['download']); $pd = private_dir();
    $f = $pd ? $pd . '/diagnostics/' . $n . '.zip' : '';
    if ($n === '' || !is_file($f)) { http_response_code(404); echo 'Topilmadi'; exit; }
    audit('lcup.diag_download', 'lcup', null, ['file' => $n . '.zip']);
    header('Content-Type: application/zip'); header('Content-Length: ' . filesize($f)); header('Content-Disposition: attachment; filename="argent_lcup_diagnostika_' . $n . '.zip"'); header('Cache-Control: no-store');
    readfile($f); exit;
}

/* ---- filters ---- */
$B = branches_all(); $mapIds = lcup_branch_ids();
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
if ($fq !== '') { $w[] = '(e.student_name LIKE ? OR e.staff LIKE ? OR e.details LIKE ? OR e.student_id = ?)'; $p = array_merge($p, ['%' . $fq . '%', '%' . $fq . '%', '%' . $fq . '%', $fq]); }
if (!$hist && $pickId === 0) $w[] = 'e.backfill = 0';
$wh = implode(' AND ', $w);
$pg = max(1, (int)($_GET['p'] ?? 1)); $lim = 25;
$cs = db()->prepare("SELECT COUNT(*) FROM lcup_events e WHERE $wh"); $cs->execute($p); $total = (int)$cs->fetchColumn(); $pages = max(1, (int)ceil($total / $lim)); $pg = min($pg, $pages);
$order = $pickId ? "(e.id = $pickId) DESC, e.id DESC" : 'e.id DESC';
$st = db()->prepare("SELECT e.*, b.name AS branch_name, b.color AS branch_color, u.username AS reviewer FROM lcup_events e LEFT JOIN branches b ON b.id = e.branch_id LEFT JOIN users u ON u.id = e.reviewed_by WHERE $wh ORDER BY $order LIMIT $lim OFFSET " . (($pg - 1) * $lim));
$st->execute($p); $events = $st->fetchAll();
$qs = function (array $o) use ($fb, $fsev, $fst, $ftype, $fq, $from, $to, $hist, $pg) { return 'lcup.php?' . http_build_query(array_filter(array_merge(['branch' => $fb ?: null, 'sev' => $fsev ?: null, 'status' => $fst ?: null, 'type' => $ftype ?: null, 'q' => $fq ?: null, 'from' => $from ?: null, 'to' => $to ?: null, 'hist' => $hist ? 1 : null, 'p' => $pg > 1 ? $pg : null], $o), function ($v) { return $v !== null && $v !== ''; })); };

/* ---- health ---- */
$last = lcup_state_get('last_success'); $lastRun = lcup_state_get('last_run'); $fails = (int)lcup_state_get('fail_count', 0); $lastErr = (string)lcup_state_get('last_error', '');
$minRun = lcup_minutes_since_run(); $stale = $minRun !== null && $minRun >= 5; $never = $lastRun === null;
$gaps = db()->query("SELECT * FROM lcup_gaps ORDER BY id DESC LIMIT 5")->fetchAll();
$pendEdits = (int)db()->query("SELECT COUNT(*) FROM lcup_acts WHERE type = 'log_edit' AND act_text LIKE '%minus%' AND state = 'pending'")->fetchColumn(); $unresEdits = (int)db()->query("SELECT COUNT(*) FROM lcup_acts WHERE type = 'log_edit' AND act_text LIKE '%minus%' AND state = 'unresolved'")->fetchColumn();
$warn = json_decode((string)lcup_state_get('last_warnings', '[]'), true) ?: []; $ign = json_decode((string)lcup_state_get('ignored_texts', '{}'), true) ?: []; arsort($ign);
$baseUrl = rtrim((string)cfg('LCUP_BASE_URL', 'https://oxfordlc.lc-up.com'), '/');
$unread = lcup_unread_count(); $labels = lcup_flag_labels(); $typeL = lcup_type_labels();
$diag = preg_replace('/[^0-9_]/', '', (string)($_GET['diag'] ?? '')); $diagTxt = ($diag && private_dir() && is_file(private_dir() . "/diagnostics/$diag/00_info.txt")) ? (string)file_get_contents(private_dir() . "/diagnostics/$diag/00_info.txt") : '';
$diagZip = ($diag && private_dir() && is_file(private_dir() . "/diagnostics/$diag.zip"));

ui_head('LC-UP nazorat');
ui_start('lcup');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">LC-UP nazorat</h1><p class="page-sub">LC-UP dagi to'lovga oid har bir o'zgarish — o'chirib ham, o'zgartirib ham bo'lmaydi, faqat «ko'rildi» deb belgilanadi</p></div>
    <div class="row gap-2 no-print row-wrap">
      <a class="btn" href="solishtirish.php"><?= icon('scale', 'i-sm') ?> Solishtirish</a>
      <a class="btn" href="admin.php#lcup"><?= icon('settings', 'i-sm') ?> Sozlamalar</a>
    </div>
  </div>
  <?php ui_lcup_subnav('lcup'); ?>

  <?php if ($never): ?><div class="alert error" style="margin-bottom:14px"><?= icon('triangle-alert') ?><div class="grow">Sinxronizatsiya hali bir marta ham ishlamagan. <a href="admin.php#lcup" style="text-decoration:underline">Sozlash bo'yicha ko'rsatma →</a></div></div>
  <?php elseif ($stale): ?><div class="alert error" style="margin-bottom:14px"><?= icon('clock-alert') ?><div class="grow"><b>Sinxronizatsiya <?= (int)$minRun ?> daqiqadan beri ishlamayapti.</b> Cron ishlayaptimi? (Hostinger → Cron Jobs / cron-job.org)</div></div>
  <?php elseif ($fails > 0): ?><div class="alert error" style="margin-bottom:14px"><?= icon('triangle-alert') ?><div class="grow"><b>Oxirgi <?= $fails ?> urinish muvaffaqiyatsiz.</b> <?= e($lastErr) ?></div></div><?php endif; ?>

  <section class="card card-pad" style="margin-bottom:16px" aria-label="Holat">
    <div class="lc-health">
      <div class="kv"><div class="k">Oxirgi muvaffaqiyatli sinxronizatsiya</div><div class="v"><?= $last ? e(date('d.m.Y H:i', strtotime($last))) . ' <span class="text-3 fs-sm">(' . (int)floor((time() - strtotime($last)) / 60) . ' daq. oldin)</span>' : '—' ?></div></div>
      <div class="kv"><div class="k">Ketma-ket xatolar</div><div class="v <?= $fails ? 'text-out' : '' ?>"><?= $fails ?></div></div>
      <div class="kv"><div class="k">Ko'rilmagan qizil</div><div class="v <?= $unread ? 'text-out' : '' ?>"><?= $unread ?></div></div>
      <div class="kv"><div class="k">Minus to'lov tahrirlari</div><div class="v fs-sm"><?= $pendEdits ? '⏳ aniqlanmoqda: <b>' . $pendEdits . '</b>' : '' ?><?= $unresEdits ? ' <span class="text-out">aniqlanmadi: <b>' . $unresEdits . '</b></span>' : '' ?><?= (!$pendEdits && !$unresEdits) ? 'hammasi bog\'langan' : '' ?></div></div>
      <div class="kv"><div class="k">Telegram</div><div class="v"><?= lcup_tg_ready() ? (lcup_state_get('tg_last_error') ? '<span class="text-out">xato: ' . e(lcup_state_get('tg_last_error')) . '</span>' : 'ulangan ✓') : "<span class='text-out'>sozlanmagan</span>" ?></div></div>
      <?php foreach (lcup_map_rows() as $m): $nl = lcup_state_get('night_last_' . (int)$m['branch_id']); $ns = json_decode((string)lcup_state_get('night_' . (int)$m['branch_id'], ''), true) ?: []; ?>
      <div class="kv"><div class="k">Tungi to'liq tekshiruv · <?= e($m['branch_name']) ?></div><div class="v fs-sm"><?= $nl ? e($nl) : (($ns['date'] ?? '') === date('Y-m-d') ? 'bugun davom etmoqda (' . (int)($ns['pages'] ?? 0) . ' sahifa)' : "hali bo'lmagan") ?></div></div>
      <?php endforeach; ?>
    </div>
    <?php if ($gaps): ?><div class="lc-check" style="margin-top:12px"><?php foreach ($gaps as $g): ?><div class="<?= $g['filled'] ? 'ok' : 'warn' ?>">⏸ Uzilish: <?= e(lcup_gap_text($g)) ?><?= $g['note'] ? ' — ' . e($g['note']) : '' ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($warn): ?><div class="lc-check" style="margin-top:12px"><?php foreach ($warn as $x): ?><div class="warn"><?= e($x) ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($ign): ?><details class="lc-details" style="margin-top:10px"><summary>E'tiborga olinmagan jurnal yozuvlari (<?= count($ign) ?> xil)</summary><div class="lc-sub"><?php foreach (array_slice($ign, 0, 12, true) as $t => $n): ?><span><?= e($t) ?> <b>×<?= (int)$n ?></b></span><?php endforeach; ?><span class="text-3">Agar bu yerda to'lovga aloqador matn bo'lsa, menga yuboring — qoidalarga qo'shaman.</span></div></details><?php endif; ?>
  </section>

  <?php if ($diag): ?>
  <section class="card card-pad stack" id="diag" style="margin-bottom:16px">
    <div class="row"><span class="icon-tile primary"><?= icon('folder-lock') ?></span><div class="grow"><h2 class="card-title">Diagnostika tayyor</h2><p class="fs-sm text-3">Talabalar ismlari bor — faqat menga yuboring. Parol va tokenlar fayllardan olib tashlangan.</p></div>
      <?php if ($diagZip): ?><a class="btn btn-primary" href="lcup.php?download=<?= e($diag) ?>"><?= icon('download', 'i-sm') ?> ZIP yuklab olish</a><?php endif; ?></div>
    <?php if ($diagTxt): ?><div class="pre-box"><?= e($diagTxt) ?></div><?php endif; ?>
  </section>
  <?php endif; ?>

  <form method="get" action="lcup.php" class="card card-pad no-print" style="margin-bottom:14px">
    <div class="row row-wrap" style="gap:8px">
      <select class="select input-sm" name="branch" style="max-width:200px" aria-label="Filial"><option value="0">Barcha filiallar</option><?php foreach ($mapIds as $id): ?><option value="<?= (int)$id ?>" <?= $fb === (int)$id ? 'selected' : '' ?>><?= e($B[$id]['name'] ?? $id) ?></option><?php endforeach; ?></select>
      <select class="select input-sm" name="status" style="max-width:190px" aria-label="Holat"><option value="">Barchasi</option><option value="unread" <?= $fst === 'unread' ? 'selected' : '' ?>>Ko'rilmagan qizil</option><option value="reviewed" <?= $fst === 'reviewed' ? 'selected' : '' ?>>Ko'rilgan</option></select>
      <select class="select input-sm" name="sev" style="max-width:150px" aria-label="Daraja"><option value="">🔴 🟡 va oddiy</option><option value="red" <?= $fsev === 'red' ? 'selected' : '' ?>>🔴 Faqat qizil</option><option value="yellow" <?= $fsev === 'yellow' ? 'selected' : '' ?>>🟡 Faqat sariq</option><option value="info" <?= $fsev === 'info' ? 'selected' : '' ?>>Faqat oddiy</option></select>
      <select class="select input-sm" name="type" style="max-width:230px" aria-label="Turi"><option value="">Barcha turlar</option><?php foreach ($typeL as $k => $l): ?><option value="<?= e($k) ?>" <?= $ftype === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <input class="input input-sm" type="date" name="from" value="<?= e($from) ?>" style="max-width:160px" aria-label="Boshlanish"><input class="input input-sm" type="date" name="to" value="<?= e($to) ?>" style="max-width:160px" aria-label="Tugash">
      <input class="input input-sm" type="search" name="q" value="<?= e($fq) ?>" placeholder="Talaba, xodim, ID…" style="max-width:200px">
      <label class="row gap-2 fs-sm text-2" style="cursor:pointer"><input type="checkbox" name="hist" value="1" <?= $hist ? 'checked' : '' ?>> tarixiy yozuvlar</label>
      <button class="btn btn-sm btn-primary" type="submit"><?= icon('filter', 'i-sm') ?> Qo'llash</button>
    </div>
  </form>

  <section class="card clip" aria-label="Hodisalar">
    <div class="card-head"><h2 class="card-title"><?= icon('radar', 'i-sm') ?> Hodisalar</h2>
      <div class="row gap-2"><span class="badge"><?= fmt($total) ?> ta</span>
      <?php $unr = array_filter($events, function ($e) { return $e['severity'] === 'red' && !$e['reviewed_at']; }); if ($unr): ?><button type="button" class="btn btn-sm btn-soft no-print" data-review-all="<?= e(implode(',', array_column($unr, 'id'))) ?>"><?= icon('check-check', 'i-sm') ?> Sahifadagilarni ko'rildi</button><?php endif; ?></div></div>
    <div class="table-wrap">
      <table class="table table-stack">
        <thead><tr><th>Vaqt</th><th>Filial</th><th>Hodisa</th><th>Talaba</th><th class="r">Summa</th><th>Tafsilot</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($events as $ev): $red = $ev['severity'] === 'red'; $fl = array_filter(explode(',', (string)$ev['flags'])); $bb = $ev['branch_id'] ? ($B[(int)$ev['branch_id']] ?? null) : null; ?>
          <tr class="lc-ev <?= $red ? 'is-red' : ($ev['severity'] === 'yellow' ? 'is-yellow' : '') ?> <?= $ev['backfill'] ? 'is-hist' : '' ?> <?= $pickId === (int)$ev['id'] ? 'is-pick' : '' ?>" id="ev<?= (int)$ev['id'] ?>">
            <td class="cell-title"><div><b class="num"><?= e(date('d.m H:i', strtotime($ev['lcup_time'] ?: $ev['detected_at']))) ?></b></div><div class="fs-xs text-3 fw-5">aniqlandi <?= e(date('d.m H:i', strtotime($ev['detected_at']))) ?><?= $ev['backfill'] ? ' · tarixiy' : '' ?></div></td>
            <td data-label="Filial"><?= $bb ? '<span class="lc-br">' . branch_avatar($bb, 'xs') . '<span class="fs-sm">' . e($bb['name']) . '</span></span>' : '<span class="text-3">—</span>' ?></td>
            <td data-label="Hodisa"><span class="badge <?= $red ? 'badge-danger' : ($ev['severity'] === 'yellow' ? 'badge-warn' : '') ?>"><?= $red ? '🔴 ' : ($ev['severity'] === 'yellow' ? '🟡 ' : '') ?><?= e($typeL[$ev['type']] ?? $ev['type']) ?></span><?php if ($ev['staff']): ?><div class="fs-sm text-2" style="margin-top:4px"><?= icon('user-round', 'i-xs') ?> <?= e($ev['staff']) ?></div><?php endif; ?>
              <?php if ($fl): ?><div class="lc-flags"><?php foreach ($fl as $f): ?><span class="badge <?= in_array($f, LCUP_RED_FLAGS, true) || in_array($f, ['late_edit', 'late_delete', 'no_reason'], true) ? 'badge-danger' : (in_array($f, LCUP_YELLOW_FLAGS, true) ? 'badge-warn' : '') ?>"><?= e($labels[$f] ?? $f) ?></span><?php endforeach; ?></div><?php endif; ?></td>
            <td data-label="Talaba"><?php if ($ev['student_name'] || $ev['student_id']): ?><?php if ($ev['student_id']): ?><a href="<?= e($baseUrl . '/admin/students/' . rawurlencode($ev['student_id'])) ?>" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;text-decoration-color:var(--border-strong);text-underline-offset:3px"><?= e($ev['student_name'] ?: '#' . $ev['student_id']) ?></a> <span class="text-3 fs-xs">#<?= e($ev['student_id']) ?></span><?php else: ?><?= e($ev['student_name']) ?><?php endif; ?><?php if ($ev['group_name']): ?><div class="fs-xs text-3"><?= e($ev['group_name']) ?></div><?php endif; ?><?php else: ?><span class="text-3">—</span><?php endif; ?></td>
            <td class="r num fw-7" data-label="Summa"><?= $ev['amount'] !== null ? e(fmt($ev['amount'])) : '—' ?></td>
            <td data-label="Tafsilot"><div class="lc-chg"><?= e($ev['details']) ?></div>
              <?php if ($ev['raw']): ?><details class="lc-details"><summary>LC-UP matni</summary><div class="pre-box"><?= e($ev['raw']) ?></div></details><?php endif; ?>
              <?php if ($ev['reviewed_at']): ?><div class="lc-note"><?= icon('check-check', 'i-xs') ?> Ko'rildi: <?= e($ev['reviewer'] ?: '—') ?>, <?= e(date('d.m H:i', strtotime($ev['reviewed_at']))) ?><?= $ev['review_note'] !== null && $ev['review_note'] !== '' ? ' — «' . e($ev['review_note']) . '»' : '' ?></div><?php endif; ?></td>
            <td class="cell-actions no-print"><?php if (!$ev['reviewed_at'] && $red): ?><button type="button" class="btn btn-sm btn-soft" data-review="<?= (int)$ev['id'] ?>"><?= icon('eye', 'i-sm') ?> Ko'rildi</button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$events): ?><tr><td colspan="7"><div class="empty"><span class="icon-tile in"><?= icon('circle-check') ?></span><b>Hodisalar yo'q</b><span class="fs-sm">Hozircha filtrga mos shubhali yoki oddiy hodisa topilmadi.</span></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?><div class="card-foot pager"><span class="fs-sm text-3"><?= fmt($total) ?> ta · <?= $pg ?>/<?= $pages ?></span><nav class="pager-nav"><?php if ($pg > 1): ?><a href="<?= e($qs(['p' => $pg - 1])) ?>" aria-label="Oldingi"><?= icon('chevron-left', 'i-sm') ?></a><?php endif; ?><?php if ($pg < $pages): ?><a href="<?= e($qs(['p' => $pg + 1])) ?>" aria-label="Keyingi"><?= icon('chevron-right', 'i-sm') ?></a><?php endif; ?></nav></div><?php endif; ?>
  </section>
  <p class="hint" style="margin-top:10px">Hodisalar bazada o'zgartirib ham, o'chirib ham bo'lmaydigan qilib saqlanadi (administrator ham). Faqat «ko'rildi» belgisi va izoh bir marta yoziladi.</p>
</div>

<dialog class="modal" id="reviewDialog" aria-labelledby="rvTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="rvTitle">Ko'rildi deb belgilash</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <form action="process.php" method="post" class="stack" data-loading>
      <?= csrf_field() ?><input type="hidden" name="action" value="lcup_review"><input type="hidden" name="ids" id="rvIds"><input type="hidden" name="back" value="<?= e(ui_back_param() ? urldecode(ui_back_param()) : 'lcup.php') ?>">
      <div class="field"><label class="label" for="rvNote">Izoh <span class="text-3 fw-5">(nima aniqlandi?)</span></label><textarea class="textarea" id="rvNote" name="note" rows="3" maxlength="500" placeholder="Masalan: kassir tushuntirdi, xato kiritilgan edi"></textarea><span class="hint">Belgi va izoh keyin o'zgartirilmaydi.</span></div>
      <button type="submit" class="btn btn-primary btn-lg btn-block"><?= icon('check-check', 'i-sm') ?> Ko'rildi</button>
    </form>
  </div>
</dialog>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-review],[data-review-all]'); if (!b) return;
  document.getElementById('rvIds').value = b.getAttribute('data-review') || b.getAttribute('data-review-all');
  var d = document.getElementById('reviewDialog'); if (d.showModal) d.showModal(); else d.setAttribute('open', '');
  setTimeout(function () { document.getElementById('rvNote').focus(); }, 50);
});
(function () { var p = document.querySelector('.is-pick'); if (p) p.scrollIntoView({ block: 'center' }); })();
</script>
<?php ui_end(['active' => 'lcup']);
