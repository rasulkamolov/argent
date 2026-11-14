<?php
/** LC-UP nazorat: append-only feed of every payment-related change found in LC-UP, health of the sync, review ("ko'rildi"). Administrator only. */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lcup_report.php';
require_once __DIR__ . '/lcup_incidents.php';
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

/* ---- filters (incidents: one row per real action) ---- */
$B = branches_all(); $mapIds = lcup_branch_ids();
$fb = (int)($_GET['branch'] ?? 0); $fsev = in_array($_GET['sev'] ?? '', ['red', 'yellow', 'info'], true) ? $_GET['sev'] : '';
$fst = in_array($_GET['status'] ?? '', ['unread', 'reviewed', 'corrected'], true) ? $_GET['status'] : '';
$fkind = array_key_exists($_GET['kind'] ?? '', LCUP_KIND_FILTER) ? $_GET['kind'] : ''; $fq = trim((string)($_GET['q'] ?? ''));
$fleader = in_array($_GET['leader'] ?? '', ['1', 'all'], true) ? $_GET['leader'] : '0';
$from = valid_date($_GET['from'] ?? null) ? $_GET['from'] : ''; $to = valid_date($_GET['to'] ?? null) ? $_GET['to'] : '';
$hist = !empty($_GET['hist']); $pickId = (int)($_GET['id'] ?? 0);
$w = ['1=1']; $p = [];
if ($fb > 0) { $w[] = 'i.branch_id = ?'; $p[] = $fb; } elseif ($fb === -1) $w[] = 'i.branch_id IS NULL';
if ($fsev) { $w[] = 'i.severity = ?'; $p[] = $fsev; }
if ($fst === 'unread') $w[] = "i.severity = 'red' AND i.reviewed_at IS NULL"; elseif ($fst === 'reviewed') $w[] = 'i.reviewed_at IS NOT NULL'; elseif ($fst === 'corrected') $w[] = 'EXISTS (SELECT 1 FROM lcup_corrections c JOIN lcup_incident_events l ON l.event_id = c.event_id WHERE l.incident_id = i.id)';
if ($fkind === 'moved') $w[] = "i.kind IN ('moved','moved_other')"; elseif ($fkind === 'qarz') $w[] = "i.kind IN ('balance_edit','debt')"; elseif ($fkind !== '') { $w[] = 'i.kind = ?'; $p[] = $fkind; }
if ($fleader === '0' && $pickId === 0) $w[] = 'i.leader = 0'; elseif ($fleader === '1') $w[] = 'i.leader = 1';
if ($from) { $w[] = 'substr(COALESCE(i.at, i.first_detected),1,10) >= ?'; $p[] = $from; } if ($to) { $w[] = 'substr(COALESCE(i.at, i.first_detected),1,10) <= ?'; $p[] = $to; }
if ($fq !== '') { $w[] = '(i.student_name LIKE ? OR i.actor LIKE ? OR i.creator LIKE ? OR i.details LIKE ? OR i.student_id = ?)'; $p = array_merge($p, ['%' . $fq . '%', '%' . $fq . '%', '%' . $fq . '%', '%' . $fq . '%', $fq]); }
if (!$hist && $pickId === 0) $w[] = 'i.backfill = 0';
$wh = implode(' AND ', $w);
$pg = max(1, (int)($_GET['p'] ?? 1)); $lim = 25;
$cs = db()->prepare("SELECT COUNT(*) FROM lcup_incidents i WHERE $wh"); $cs->execute($p); $total = (int)$cs->fetchColumn(); $pages = max(1, (int)ceil($total / $lim)); $pg = min($pg, $pages);
$order = $pickId ? "(i.id = $pickId) DESC, COALESCE(i.at, i.first_detected) DESC, i.id DESC" : 'COALESCE(i.at, i.first_detected) DESC, i.id DESC';
$st = db()->prepare("SELECT i.*, u.username AS reviewer FROM lcup_incidents i LEFT JOIN users u ON u.id = i.reviewed_by WHERE $wh ORDER BY $order LIMIT $lim OFFSET " . (($pg - 1) * $lim));
$st->execute($p); $events = $st->fetchAll();
$leaderN = (int)db()->query("SELECT COUNT(*) FROM lcup_incidents WHERE leader = 1 AND backfill = 0")->fetchColumn();
$qs = function (array $o) use ($fb, $fsev, $fst, $fkind, $fq, $from, $to, $hist, $pg, $fleader) { return 'lcup.php?' . http_build_query(array_filter(array_merge(['branch' => $fb ?: null, 'sev' => $fsev ?: null, 'status' => $fst ?: null, 'kind' => $fkind ?: null, 'leader' => $fleader !== '0' ? $fleader : null, 'q' => $fq ?: null, 'from' => $from ?: null, 'to' => $to ?: null, 'hist' => $hist ? 1 : null, 'p' => $pg > 1 ? $pg : null], $o), function ($v) { return $v !== null && $v !== ''; })); };

/* ---- health ---- */
$last = lcup_state_get('last_success'); $lastRun = lcup_state_get('last_run'); $fails = (int)lcup_state_get('fail_count', 0); $lastErr = (string)lcup_state_get('last_error', '');
$minRun = lcup_minutes_since_run(); $stale = $minRun !== null && $minRun >= 5; $never = $lastRun === null;
$gaps = db()->query("SELECT * FROM lcup_gaps ORDER BY id DESC LIMIT 5")->fetchAll();
$pendEdits = (int)db()->query("SELECT COUNT(*) FROM lcup_acts WHERE type = 'charge_edited' AND state = 'pending'")->fetchColumn(); $unresEdits = (int)db()->query("SELECT COUNT(*) FROM lcup_acts WHERE type = 'charge_edited' AND state = 'unresolved'")->fetchColumn();
$noBranch = (int)db()->query("SELECT COUNT(*) FROM lcup_incidents WHERE branch_id IS NULL AND backfill = 0 AND kind IN ('created','edited','deleted','moved','moved_other','charge_edit','balance_edit','debt')")->fetchColumn();
$warn = json_decode((string)lcup_state_get('last_warnings', '[]'), true) ?: []; $ign = json_decode((string)lcup_state_get('ignored_texts', '{}'), true) ?: []; arsort($ign);
$baseUrl = rtrim((string)cfg('LCUP_BASE_URL', 'https://oxfordlc.lc-up.com'), '/');
$unread = lcup_unread_count(); $labels = lcup_flag_labels();
$diag = preg_replace('/[^0-9_]/', '', (string)($_GET['diag'] ?? '')); $diagTxt = ($diag && private_dir() && is_file(private_dir() . "/diagnostics/$diag/00_info.txt")) ? (string)file_get_contents(private_dir() . "/diagnostics/$diag/00_info.txt") : '';
$diagZip = ($diag && private_dir() && is_file(private_dir() . "/diagnostics/$diag.zip"));
$redFlags = ['deleted', 'amount_down', 'amount_zero', 'moved_other', 'total_down', 'charge_reduced', 'charge_unresolved', 'balance_big', 'debt_unexplained', 'small_amount', 'off_hours', 'cash_missing', 'no_details', 'moved_in', 'discount'];

ui_head('LC-UP nazorat');
ui_start('lcup');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">LC-UP nazorat</h1><p class="page-sub">Har bir haqiqiy amal bitta hodisa: jurnal, arxiv, snapshot va Tarix birlashtirilgan. Xodim — faqat jurnal yoki Tarix bo'yicha</p></div>
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
      <div class="kv"><div class="k">Filiali aniqlanmoqda</div><div class="v fs-sm"><?= $noBranch ? '<a href="lcup.php?branch=-1" style="text-decoration:underline">' . $noBranch . ' ta</a>' : "yo'q ✓" ?></div></div>
      <?php $ac = lcup_accuracy_day_safe(date('Y-m-d', strtotime('-0 day'))); if (!$ac || !$ac['checked']) $ac = lcup_accuracy_day_safe(date('Y-m-d', strtotime('-1 day'))); ?>
      <div class="kv"><div class="k">Aniqlik tekshiruvi</div><div class="v fs-sm"><a href="aniqlik.php" style="text-decoration:underline"><?= $ac && $ac['checked'] ? $ac['ok'] . '/' . $ac['checked'] . ($ac['ok'] === $ac['checked'] ? ' ✅' : ' ⚠️') : "hali bo'lmagan" ?></a></div></div>
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
      <select class="select input-sm" name="branch" style="max-width:200px" aria-label="Filial"><option value="0">Barcha filiallar</option><?php foreach ($mapIds as $id): ?><option value="<?= (int)$id ?>" <?= $fb === (int)$id ? 'selected' : '' ?>><?= e($B[$id]['name'] ?? $id) ?></option><?php endforeach; ?><option value="-1" <?= $fb === -1 ? 'selected' : '' ?>>Filial aniqlanmoqda</option></select>
      <select class="select input-sm" name="status" style="max-width:190px" aria-label="Holat"><option value="">Barchasi</option><option value="unread" <?= $fst === 'unread' ? 'selected' : '' ?>>Ko'rilmagan qizil</option><option value="reviewed" <?= $fst === 'reviewed' ? 'selected' : '' ?>>Ko'rilgan</option><option value="corrected" <?= $fst === 'corrected' ? 'selected' : '' ?>>Tuzatilganlar</option></select>
      <select class="select input-sm" name="sev" style="max-width:150px" aria-label="Daraja"><option value="">🔴 🟡 va oddiy</option><option value="red" <?= $fsev === 'red' ? 'selected' : '' ?>>🔴 Faqat qizil</option><option value="yellow" <?= $fsev === 'yellow' ? 'selected' : '' ?>>🟡 Faqat sariq</option><option value="info" <?= $fsev === 'info' ? 'selected' : '' ?>>Faqat oddiy</option></select>
      <select class="select input-sm" name="kind" style="max-width:210px" aria-label="Turi"><option value="">Barcha turlar</option><?php foreach (LCUP_KIND_FILTER as $k => $l): ?><option value="<?= e($k) ?>" <?= $fkind === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <select class="select input-sm" name="leader" style="max-width:200px" aria-label="Rahbar akkaunti"><option value="0" <?= $fleader === '0' ? 'selected' : '' ?>>Rahbar akkauntisiz</option><option value="1" <?= $fleader === '1' ? 'selected' : '' ?>>👑 Faqat rahbar akkaunti</option><option value="all" <?= $fleader === 'all' ? 'selected' : '' ?>>Hammasi</option></select>
      <input class="input input-sm" type="date" name="from" value="<?= e($from) ?>" style="max-width:160px" aria-label="Boshlanish"><input class="input input-sm" type="date" name="to" value="<?= e($to) ?>" style="max-width:160px" aria-label="Tugash">
      <input class="input input-sm" type="search" name="q" value="<?= e($fq) ?>" placeholder="Talaba, xodim, ID…" style="max-width:200px">
      <label class="row gap-2 fs-sm text-2" style="cursor:pointer"><input type="checkbox" name="hist" value="1" <?= $hist ? 'checked' : '' ?>> tarixiy yozuvlar</label>
      <button class="btn btn-sm btn-primary" type="submit"><?= icon('filter', 'i-sm') ?> Qo'llash</button>
    </div>
    <?php if ($leaderN && $fleader === '0'): ?><p class="hint" style="margin-top:8px">👑 Rahbar akkaunti amallari alohida: <a href="<?= e($qs(['leader' => '1', 'p' => null])) ?>" style="text-decoration:underline"><?= $leaderN ?> ta</a> (🟡, Telegram'ga faqat ish vaqtidan tashqari ishlatilsa).</p><?php endif; ?>
  </form>

  <section class="card clip" aria-label="Hodisalar">
    <div class="card-head"><h2 class="card-title"><?= icon('radar', 'i-sm') ?> Hodisalar</h2>
      <div class="row gap-2"><span class="badge"><?= fmt($total) ?> ta</span>
      <?php $unr = array_filter($events, function ($e) { return $e['severity'] === 'red' && !$e['reviewed_at']; }); if ($unr): ?><button type="button" class="btn btn-sm btn-soft no-print" data-review-all="<?= e(implode(',', array_column($unr, 'id'))) ?>"><?= icon('check-check', 'i-sm') ?> Sahifadagilarni ko'rildi</button><?php endif; ?></div></div>
    <div class="table-wrap">
      <table class="table table-stack">
        <thead><tr><th>Vaqt</th><th>Filial</th><th>Hodisa</th><th>Kim</th><th>Talaba</th><th class="r">Summa</th><th>Tafsilot</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($events as $ev): $red = $ev['severity'] === 'red'; $fl = array_diff(array_filter(explode(',', (string)$ev['flags'])), ['deleted', 'moved', 'moved_other', 'debt_unexplained', 'cash_missing', 'discount']); $bb = $ev['branch_id'] ? ($B[(int)$ev['branch_id']] ?? null) : null;
              $cor = lcup_inc_corrections((int)$ev['id']); $srcs = array_filter(explode(',', (string)$ev['sources'])); $orig = lcup_inc_events((int)$ev['id']); ?>
          <tr class="lc-ev <?= $red ? 'is-red' : ($ev['severity'] === 'yellow' ? 'is-yellow' : '') ?> <?= $ev['backfill'] ? 'is-hist' : '' ?> <?= $pickId === (int)$ev['id'] ? 'is-pick' : '' ?>" id="ev<?= (int)$ev['id'] ?>">
            <td class="cell-title"><div><b class="num"><?= e($ev['at'] ? date('d.m H:i', strtotime($ev['at'])) : '—') ?></b></div><div class="fs-xs text-3 fw-5">aniqlandi <?= e(date('d.m H:i', strtotime($ev['first_detected']))) ?> · #<?= (int)$ev['id'] ?></div></td>
            <td data-label="Filial"><?= $bb ? '<span class="lc-br">' . branch_avatar($bb, 'xs') . '<span class="fs-sm">' . e($bb['name']) . '</span></span>' : '<span class="badge">⏳ aniqlanmoqda</span>' ?></td>
            <td data-label="Hodisa"><span class="badge <?= $red ? 'badge-danger' : ($ev['severity'] === 'yellow' ? 'badge-warn' : '') ?>"><?= $red ? '🔴 ' : ($ev['severity'] === 'yellow' ? '🟡 ' : '') ?><?= e(lcup_kind_label($ev['kind'])) ?></span>
              <?php if ($cor): ?> <span class="badge badge-primary" title="Asl yozuvlar tuzatilgan — pastda «Asl yozuvlar»">tuzatilgan</span><?php endif; ?>
              <?php if ($fl): ?><div class="lc-flags"><?php foreach ($fl as $f): ?><span class="badge <?= in_array($f, $redFlags, true) ? 'badge-danger' : ($f === 'leader' ? 'badge-primary' : 'badge-warn') ?>"><?= $f === 'leader' ? '👑 ' : '' ?><?= e($labels[$f] ?? $f) ?></span><?php endforeach; ?></div><?php endif; ?>
              <div class="fs-xs text-3" style="margin-top:4px">Manbalar: <?= e(implode(', ', array_map(function ($x) { return LCUP_SRC_LABEL[$x] ?? $x; }, $srcs))) ?> · <?= $ev['confidence'] === 'confirmed' ? '✅ Tasdiqlangan' : '⚠️ Taxminiy' ?></div></td>
            <td data-label="Kim"><?php if (in_array($ev['kind'], ['cash_missing', 'system'], true)): ?><span class="text-3 fs-sm"><?= e($ev['kind'] === 'system' ? 'Argent: ' . ($ev['creator'] ?: '—') : '—') ?></span><?php else: ?><b class="fs-sm"><?= $ev['actor'] ? e($ev['actor']) : "<span class='text-out'>Noma'lum (aniqlanmoqda)</span>" ?></b><?php if ($ev['creator'] && lc_norm((string)$ev['creator']) !== lc_norm((string)$ev['actor'])): ?><div class="fs-xs text-3">Yaratgan: <?= e($ev['creator']) ?></div><?php endif; ?><?php endif; ?></td>
            <td data-label="Talaba"><?php if ($ev['student_name'] || $ev['student_id']): ?><?php if ($ev['student_id']): ?><a href="<?= e($baseUrl . '/admin/students/' . rawurlencode($ev['student_id'])) ?>" target="_blank" rel="noopener noreferrer" style="text-decoration:underline;text-decoration-color:var(--border-strong);text-underline-offset:3px"><?= e($ev['student_name'] ?: '#' . $ev['student_id']) ?></a> <span class="text-3 fs-xs">#<?= e($ev['student_id']) ?></span><?php else: ?><?= e($ev['student_name']) ?><?php endif; ?><?php if ($ev['group_name']): ?><div class="fs-xs text-3"><?= e($ev['group_name']) ?></div><?php endif; ?><?php else: ?><span class="text-3">—</span><?php endif; ?></td>
            <td class="r num fw-7" data-label="Summa"><?php if ($ev['old_amount'] !== null && in_array($ev['kind'], ['edited', 'charge_edit', 'balance_edit'], true)): ?><span class="text-3"><?= e(fmt($ev['old_amount'])) ?> →</span> <?= e(fmt($ev['new_amount'])) ?><?php elseif ($ev['amount'] !== null): ?><?= e(fmt($ev['amount'])) ?><?php else: ?>—<?php endif; ?></td>
            <td data-label="Tafsilot"><div class="lc-chg"><?= e($ev['details']) ?></div>
              <?php if ($ev['corrected_at']): ?><div class="lc-note" style="color:var(--primary-text)">🛠 Xabar yuborilgandan keyin tuzatildi (<?= e(date('d.m H:i', strtotime($ev['corrected_at']))) ?>): <?= e($ev['correction']) ?></div><?php endif; ?>
              <details class="lc-details"><summary>Asl yozuvlar (<?= count($orig) ?>)<?= $cor ? ' · tuzatishlar (' . count($cor) . ')' : '' ?></summary><div class="lc-sub">
                <?php foreach ($orig as $o): $od = lcup_detection($o); ?><span><b><?= e(LCUP_SRC_LABEL[$od['src']] ?? $od['src']) ?></b> · <?= e(lcup_type_label((string)$o['type'])) ?> · <?= e(date('d.m H:i', strtotime($o['lcup_time'] ?: $o['detected_at']))) ?> · xodim: <?= e($o['staff'] ?: '—') ?><?= $o['creator'] ? ' · yaratgan: ' . e($o['creator']) : '' ?> · filial: <?= e($o['branch_id'] ? lcup_branch_name((int)$o['branch_id']) : '—') ?><?= $o['amount'] !== null ? ' · ' . e(fmt($o['amount'])) : '' ?><?= $o['details'] ? ' · ' . e(mb_substr((string)$o['details'], 0, 160)) : '' ?></span><?php endforeach; ?>
                <?php $dupN = 0; foreach ($cor as $c): if ($c['field'] === 'duplicate' || $c['field'] === 'kind') { $dupN += $c['field'] === 'duplicate' ? 1 : 0; continue; } ?><span class="text-out">✎ <?= e(['actor' => 'Xodim', 'branch' => 'Filial'][$c['field']] ?? $c['field']) ?>: «<?= e($c['original'] ?? '—') ?>» → «<?= e($c['corrected']) ?>» — <?= e($c['reason']) ?> (yozuv #<?= (int)$c['event_id'] ?>)</span><?php endforeach; ?>
                <?php if ($dupN): ?><span class="text-out">✎ <?= $dupN + 1 ?> ta yozuv bitta hodisaga birlashtirildi (oldin alohida ko'rsatilgan edi)<?= in_array($ev['kind'], ['moved', 'moved_other'], true) ? " · turi: «" . e(lcup_kind_label($ev['kind'])) . "»" : '' ?></span><?php endif; ?>
              </div></details>
              <?php if ($ev['reviewed_at']): ?><div class="lc-note"><?= icon('check-check', 'i-xs') ?> Ko'rildi: <?= e($ev['reviewer'] ?: '—') ?>, <?= e(date('d.m H:i', strtotime($ev['reviewed_at']))) ?><?= $ev['review_note'] !== null && $ev['review_note'] !== '' ? ' — «' . e($ev['review_note']) . '»' : '' ?></div><?php endif; ?></td>
            <td class="cell-actions no-print"><?php if (!$ev['reviewed_at'] && $red): ?><button type="button" class="btn btn-sm btn-soft" data-review="<?= (int)$ev['id'] ?>"><?= icon('eye', 'i-sm') ?> Ko'rildi</button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$events): ?><tr><td colspan="8"><div class="empty"><span class="icon-tile in"><?= icon('circle-check') ?></span><b>Hodisalar yo'q</b><span class="fs-sm">Hozircha filtrga mos hodisa topilmadi.</span></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?><div class="card-foot pager"><span class="fs-sm text-3"><?= fmt($total) ?> ta · <?= $pg ?>/<?= $pages ?></span><nav class="pager-nav"><?php if ($pg > 1): ?><a href="<?= e($qs(['p' => $pg - 1])) ?>" aria-label="Oldingi"><?= icon('chevron-left', 'i-sm') ?></a><?php endif; ?><?php if ($pg < $pages): ?><a href="<?= e($qs(['p' => $pg + 1])) ?>" aria-label="Keyingi"><?= icon('chevron-right', 'i-sm') ?></a><?php endif; ?></nav></div><?php endif; ?>
  </section>
  <p class="hint" style="margin-top:10px">Asl yozuvlar (jurnal, arxiv, snapshot, Tarix) bazada o'zgartirib ham, o'chirib ham bo'lmaydigan qilib saqlanadi. Tuzatishlar alohida yozuv sifatida qo'shiladi va asl yozuvga bog'lanadi. «Hodim» ustuni — to'lovni yaratgan xodim; kim o'chirgani/tahrirlagani faqat jurnal yoki Tarixdan olinadi.</p>
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
