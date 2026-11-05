<?php
/**
 * Shared filter parsing, SQL helpers and filter-bar UI for
 * hisobot.php, analitika.php and export_excel.php.
 * Requires layout.php to be loaded first.
 */

require_once __DIR__ . '/proofs.php';
const RL_METHOD_SQL = SQL_METHOD;

function rl_presets(): array {
    $today = date('Y-m-d');
    return [
        'today'     => ['Bugun',        $today, $today],
        'yesterday' => ['Kecha',        date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
        '7d'        => ['7 kun',        date('Y-m-d', strtotime('-6 days')), $today],
        '30d'       => ['30 kun',       date('Y-m-d', strtotime('-29 days')), $today],
        'month'     => ['Shu oy',       date('Y-m-01'), date('Y-m-t')],
        'last'      => ["O'tgan oy",    date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        'year'      => ['Shu yil',      date('Y-01-01'), date('Y-12-31')],
    ];
}

/** Parse + validate GET filters. */
function rl_filters(): array {
    $start = valid_date($_GET['start'] ?? null) ? $_GET['start'] : date('Y-m-01');
    $end   = valid_date($_GET['end'] ?? null)   ? $_GET['end']   : date('Y-m-t');
    if ($start > $end) { [$start, $end] = [$end, $start]; }
    if (strtotime($end) - strtotime($start) > 366 * 5 * 86400) { $start = date('Y-m-d', strtotime("$end -5 years")); }
    $method = in_array($_GET['method'] ?? '', ['cash', 'click', 'bank'], true) ? $_GET['method'] : '';
    // branch: admin may pick any (0 = all); everyone else is locked to their own branch
    if (is_admin()) {
        $branch = isset($_GET['branch']) ? (int)$_GET['branch'] : scope_branch();
        if ($branch && !branch_get($branch)) $branch = 0;
    } else {
        $branch = scope_branch();
    }
    return [
        'start' => $start, 'end' => $end, 'method' => $method, 'branch' => $branch,
        'cat' => (int)($_GET['cat'] ?? 0), 'user' => (int)($_GET['user'] ?? 0),
        'q' => trim($_GET['q'] ?? ''),
        'proof' => in_array($_GET['proof'] ?? '', ['missing', 'has'], true) ? $_GET['proof'] : '',
    ];
}

/** WHERE fragment (aliased table `t`) + params for the given range (defaults to the filter's own). */
function rl_where(array $f, ?string $start = null, ?string $end = null): array {
    $w = ["t.date BETWEEN ? AND ?"]; $p = [$start ?? $f['start'], $end ?? $f['end']];
    $ids = !empty($f['branch']) ? [(int)$f['branch']] : visible_branch_ids();
    if (!is_admin() && !empty($f['branch']) && !in_array((int)$f['branch'], visible_branch_ids(), true)) $ids = visible_branch_ids();
    $w[] = $ids ? "t.branch_id IN (" . implode(',', array_map('intval', $ids)) . ")" : "0";
    if ($f['method'] === 'bank')  $w[] = "t.bank = 1";
    if ($f['method'] === 'click') $w[] = "t.click = 1 AND t.bank = 0";
    if ($f['method'] === 'cash')  $w[] = "t.bank = 0 AND t.click = 0";
    if ($f['cat'])  { $w[] = "t.category_id = ?"; $p[] = $f['cat']; }
    if ($f['user']) { $w[] = "t.user_id = ?";     $p[] = $f['user']; }
    if ($f['q'] !== '') { $w[] = "t.comment LIKE ?"; $p[] = '%' . $f['q'] . '%'; }
    if (($f['proof'] ?? '') === 'missing') $w[] = proof_missing_sql('t');
    if (($f['proof'] ?? '') === 'has') $w[] = "(t.click = 1 OR t.bank = 1) AND t.proof IS NOT NULL AND t.proof <> ''";
    return [implode(' AND ', $w), $p];
}

/** Query string for links, keeping all filters; $over replaces / removes (null) keys. */
function rl_qs(array $f, array $over = []): string {
    $q = array_merge(array_filter([
        'start' => $f['start'], 'end' => $f['end'], 'method' => $f['method'], 'branch' => is_admin() ? (int)$f['branch'] : null,
        'cat' => $f['cat'] ?: null, 'user' => $f['user'] ?: null, 'q' => $f['q'] !== '' ? $f['q'] : null, 'proof' => $f['proof'] ?: null,
    ], fn($v) => $v !== null && $v !== ''), $over);
    return http_build_query(array_filter($q, fn($v) => $v !== null && $v !== ''));
}

function rl_prev_range(array $f): array {
    $days = (int)round((strtotime($f['end']) - strtotime($f['start'])) / 86400) + 1;
    $pe = date('Y-m-d', strtotime($f['start'] . ' -1 day'));
    $ps = date('Y-m-d', strtotime("$pe -" . ($days - 1) . ' days'));
    return [$ps, $pe, $days];
}

/** Totals for a range: in/out/counts + per-method in/out. */
function rl_totals(PDO $conn, array $f, ?string $start = null, ?string $end = null): array {
    [$w, $p] = rl_where($f, $start, $end);
    $st = $conn->prepare("SELECT " . RL_METHOD_SQL . " AS m, CASE WHEN t.cash_in=1 THEN 'in' ELSE 'out' END AS k, SUM(t.payment) AS s, COUNT(*) AS n FROM transactions t WHERE $w GROUP BY m, k");
    $st->execute($p);
    $r = ['in' => 0.0, 'out' => 0.0, 'n_in' => 0, 'n_out' => 0, 'm' => []];
    foreach (['cash', 'click', 'bank'] as $m) $r['m'][$m] = ['in' => 0.0, 'out' => 0.0, 'n' => 0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $r[$row['k']] += (float)$row['s']; $r['n_' . $row['k']] += (int)$row['n'];
        $r['m'][$row['m']][$row['k']] += (float)$row['s']; $r['m'][$row['m']]['n'] += (int)$row['n'];
    }
    $r['net'] = $r['in'] - $r['out']; $r['n'] = $r['n_in'] + $r['n_out'];
    return $r;
}

/** Category breakdown: [name, total, cnt] per type. */
function rl_by_category(PDO $conn, array $f, string $kind): array {
    [$w, $p] = rl_where($f);
    $cond = $kind === 'in' ? 't.cash_in=1' : 't.cash_in<>1';
    $st = $conn->prepare("SELECT COALESCE(c.name, 'Kategoriyasiz') AS name, SUM(t.payment) AS total, COUNT(*) AS cnt FROM transactions t LEFT JOIN categories c ON t.category_id=c.id WHERE $w AND $cond GROUP BY COALESCE(c.name, 'Kategoriyasiz') ORDER BY total DESC");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function rl_daily(PDO $conn, array $f): array {
    [$w, $p] = rl_where($f);
    $st = $conn->prepare("SELECT t.date, SUM(CASE WHEN t.cash_in=1 THEN t.payment ELSE 0 END) AS inc, SUM(CASE WHEN t.cash_in<>1 THEN t.payment ELSE 0 END) AS exp, COUNT(*) AS n FROM transactions t WHERE $w GROUP BY t.date ORDER BY t.date");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Returns ['pct'=>float|null, 'cls'=>good|bad|flat, 'arrow'=>...]; $upIsGood=false for expenses. */
function rl_delta(float $cur, float $prev, bool $upIsGood = true): array {
    if ($prev == 0 && $cur == 0) return ['pct' => 0.0, 'cls' => 'flat', 'txt' => '0%', 'icon' => 'minus'];
    if ($prev == 0) return ['pct' => null, 'cls' => 'flat', 'txt' => 'yangi', 'icon' => 'arrow-up'];
    $pct = ($cur - $prev) / abs($prev) * 100;
    if (abs($pct) < 0.05) return ['pct' => 0.0, 'cls' => 'flat', 'txt' => '0%', 'icon' => 'minus'];
    $up = $pct > 0;
    $txt = abs($pct) > 999 ? '>999%' : number_format(abs($pct), 1, ',', '') . '%';
    return ['pct' => $pct, 'cls' => ($up === $upIsGood) ? 'good' : 'bad', 'txt' => ($up ? '+' : '−') . $txt, 'icon' => $up ? 'arrow-up' : 'arrow-down'];
}
function rl_delta_html(array $d, string $label = ''): string {
    return '<span class="delta ' . $d['cls'] . '">' . icon($d['icon'], 'i-xs') . e($d['txt']) . '</span>' . ($label !== '' ? '<span>' . e($label) . '</span>' : '');
}

/** Filter bar (presets + date range + advanced filters). */
function rl_render_filters(PDO $conn, array $f, string $action, array $hidden = [], bool $withSearch = true, bool $withBranch = true, bool $withAdv = true): void {
    $presets = rl_presets();
    $active = 'custom';
    foreach ($presets as $k => [$lbl, $s, $e]) { if ($s === $f['start'] && $e === $f['end']) { $active = $k; break; } }
    $cats  = categories_for_branch((int)$f['branch']);
    $ucond = $f['branch'] ? "WHERE branch_id = " . (int)$f['branch'] . " OR role = 'admin'" : (is_admin() ? "" : "WHERE branch_id = " . (int)scope_branch());
    $users = $conn->query("SELECT id, username FROM users $ucond ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    $adv = $f['method'] !== '' || $f['cat'] || $f['user'] || $f['proof'] !== '' || ($withSearch && $f['q'] !== '');
    $advCount = (int)($f['method'] !== '') + (int)(bool)$f['cat'] + (int)(bool)$f['user'] + (int)($f['proof'] !== '') + (int)($withSearch && $f['q'] !== '');
    ?>
<section class="card card-pad filters no-print" aria-label="Filtrlar">
  <div class="chips" role="group" aria-label="Tezkor davrlar">
    <?php foreach ($presets as $k => [$lbl, $s, $e]): ?>
      <a class="chip" href="<?= e($action . '?' . rl_qs($f, ['start' => $s, 'end' => $e, 'page' => null])) ?>" <?= $k === $active ? 'aria-current="true"' : '' ?>><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" action="<?= e($action) ?>" data-range class="stack" style="margin-top:14px">
    <?php foreach ($hidden as $hk => $hv): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>"><?php endforeach; ?>
    <div class="row row-wrap" style="align-items:flex-end;gap:12px">
      <?php if (is_admin() && $withBranch): ?>
      <div class="field" style="flex:1 1 180px;max-width:260px"><label class="label" for="fBranch">Filial</label>
        <select class="select input-sm" id="fBranch" name="branch">
          <option value="0" <?= !$f['branch'] ? 'selected' : '' ?>>Barcha filiallar</option>
          <?php foreach (branches_all() as $bb): ?><option value="<?= (int)$bb['id'] ?>" <?= (int)$f['branch'] === (int)$bb['id'] ? 'selected' : '' ?>><?= e($bb['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="row-dates grid" style="grid-template-columns:1fr 1fr;flex:1 1 280px;max-width:440px">
        <div class="field"><label class="label" for="fStart">Boshlanish</label><input class="input input-sm" id="fStart" type="date" name="start" value="<?= e($f['start']) ?>"></div>
        <div class="field"><label class="label" for="fEnd">Tugash</label><input class="input input-sm" id="fEnd" type="date" name="end" value="<?= e($f['end']) ?>"></div>
      </div>
      <button class="btn btn-primary" type="submit"><?= icon('filter', 'i-sm') ?> Qo'llash</button>
      <?php if ($adv): ?><a class="btn btn-ghost" href="<?= e($action . '?' . http_build_query(['start' => $f['start'], 'end' => $f['end']] + (is_admin() ? ['branch' => (int)$f['branch']] : []) + array_filter($hidden))) ?>">Tozalash</a><?php endif; ?>
    </div>
    <?php if ($withAdv): ?>
    <details <?= $adv ? 'open' : '' ?>>
      <summary><?= icon('sliders-horizontal', 'i-sm') ?> Qo'shimcha filtrlar <?php if ($advCount): ?><span class="badge badge-primary"><?= $advCount ?></span><?php endif; ?> <?= icon('chevron-down', 'i-sm i-chev') ?></summary>
      <div class="adv-grid">
        <div class="field"><label class="label" for="fMethod">To'lov usuli</label>
          <select class="select input-sm" id="fMethod" name="method">
            <option value="">Hammasi</option>
            <?php foreach (['cash','click','bank'] as $m): ?><option value="<?= $m ?>" <?= $f['method'] === $m ? 'selected' : '' ?>><?= e(method_meta($m)['label']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label class="label" for="fCat">Kategoriya</label>
          <select class="select input-sm" id="fCat" name="cat">
            <option value="0">Hammasi</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['cat'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= $c['type'] === 'income' ? 'kirim' : 'chiqim' ?>)</option><?php endforeach; ?>
          </select></div>
        <div class="field"><label class="label" for="fUser">Kiritgan</label>
          <select class="select input-sm" id="fUser" name="user">
            <option value="0">Hammasi</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $f['user'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label class="label" for="fProof">Isbot (Click/Bank)</label>
          <select class="select input-sm" id="fProof" name="proof">
            <option value="">Hammasi</option>
            <option value="missing" <?= $f['proof'] === 'missing' ? 'selected' : '' ?>>Isbotsiz</option>
            <option value="has" <?= $f['proof'] === 'has' ? 'selected' : '' ?>>Isbotli</option>
          </select></div>
        <?php if ($withSearch): ?>
        <div class="field"><label class="label" for="fQ">Izoh bo'yicha qidirish</label>
          <div class="input-icon"><?= icon('search') ?><input class="input input-sm" style="padding-left:42px" id="fQ" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Qidirish…"></div></div>
        <?php endif; ?>
      </div>
    </details>
    <?php endif; ?>
  </form>
</section>
<?php
}


/* ---------------------------------------------------------------------------
   Branch-aware aggregates
   --------------------------------------------------------------------------- */

/** Income/expense/count per branch for the filter's range (method/cat/user/q filters respected). */
function rl_by_branch(PDO $conn, array $f, ?string $start = null, ?string $end = null): array {
    $f2 = $f; $f2['branch'] = 0;                      // all branches the user may see
    [$w, $p] = rl_where($f2, $start, $end);
    $st = $conn->prepare("SELECT t.branch_id, SUM(CASE WHEN t.cash_in=1 THEN t.payment ELSE 0 END) AS inc, SUM(CASE WHEN t.cash_in<>1 THEN t.payment ELSE 0 END) AS exp,
                                 SUM(CASE WHEN t.cash_in=1 THEN 1 ELSE 0 END) AS n_in, SUM(CASE WHEN t.cash_in<>1 THEN 1 ELSE 0 END) AS n_out, MAX(t.date) AS last_date
                          FROM transactions t WHERE $w GROUP BY t.branch_id");
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['branch_id']] = $r;
    return $out;
}

/** Month-by-month income/expense (last $months months ending in $f['end']'s month), ignoring the start date. */
function rl_monthly(PDO $conn, array $f, int $months = 12): array {
    $endM = date('Y-m-01', strtotime($f['end']));
    $startM = date('Y-m-01', strtotime("$endM -" . ($months - 1) . " months"));
    $f2 = $f; $f2['start'] = $startM; $f2['end'] = date('Y-m-t', strtotime($endM));
    [$w, $p] = rl_where($f2);
    $st = $conn->prepare("SELECT substr(t.date,1,7) AS ym, SUM(CASE WHEN t.cash_in=1 THEN t.payment ELSE 0 END) AS inc, SUM(CASE WHEN t.cash_in<>1 THEN t.payment ELSE 0 END) AS exp, COUNT(*) AS n
                          FROM transactions t WHERE $w GROUP BY ym ORDER BY ym");
    $st->execute($p);
    $have = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['ym']] = $r;
    $out = [];
    for ($i = 0; $i < $months; $i++) {
        $ym = date('Y-m', strtotime("$startM +$i months"));
        $out[] = ['ym' => $ym, 'inc' => (float)($have[$ym]['inc'] ?? 0), 'exp' => (float)($have[$ym]['exp'] ?? 0), 'n' => (int)($have[$ym]['n'] ?? 0)];
    }
    return $out;
}

/** Tiny inline-SVG sparkline from a list of numbers. */
function sparkline(array $vals, string $color = '#4f46e5', int $w = 160, int $h = 44): string {
    $n = count($vals); if ($n < 2) return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true"></svg>';
    $min = min($vals); $max = max($vals); $rng = ($max - $min) ?: 1; $pad = 4;
    $pts = [];
    foreach (array_values($vals) as $i => $v) $pts[] = [round($pad + $i * (($w - 2 * $pad) / ($n - 1)), 1), round($h - $pad - (($v - $min) / $rng) * ($h - 2 * $pad), 1)];
    $d = 'M' . implode(' L', array_map(function ($p) { return $p[0] . ' ' . $p[1]; }, $pts));
    $area = $d . ' L' . $pts[$n - 1][0] . ' ' . ($h - 1) . ' L' . $pts[0][0] . ' ' . ($h - 1) . ' Z';
    $id = 'g' . substr(md5($color . implode(',', $vals)), 0, 6);
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="' . $id . '" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="' . $color . '" stop-opacity=".22"/><stop offset="1" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>'
         . '<path d="' . $area . '" fill="url(#' . $id . ')"/><path class="line" d="' . $d . '" stroke="' . $color . '"/>'
         . '<circle cx="' . $pts[$n - 1][0] . '" cy="' . $pts[$n - 1][1] . '" r="3" fill="' . $color . '" stroke="#fff" stroke-width="1.5"/></svg>';
}

/**
 * Live per-branch snapshot for the admin: today, this month, money on hand, activity sparkline, headcount.
 * @return array<int,array>  keyed by branch id (only branches visible to the user)
 */
function network_overview(?string $today = null, int $sparkDays = 14): array {
    $today = $today ?: date('Y-m-d');
    $ms = date('Y-m-01', strtotime($today)); $me = date('Y-m-t', strtotime($today));
    $from = date('Y-m-d', strtotime("$today -" . ($sparkDays - 1) . " days"));
    $ids = visible_branch_ids(); if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $out = [];
    foreach ($ids as $id) $out[$id] = ['b' => branch_get($id), 'd_in' => 0.0, 'd_out' => 0.0, 'd_n' => 0, 'm_in' => 0.0, 'm_out' => 0.0, 'm_n' => 0,
                                       'tracked' => branch_tracked($id), 'tx' => 0, 'last' => null, 'cashiers' => 0, 'managers' => 0, 'emps' => 0, 'spark' => [], 'bal' => ['cash' => 0, 'click' => 0, 'bank' => 0, 'total' => 0]];
    $q = function ($sql, $p = []) { $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); };
    foreach ($q("SELECT branch_id, SUM(CASE WHEN cash_in=1 THEN payment ELSE 0 END) i, SUM(CASE WHEN cash_in<>1 THEN payment ELSE 0 END) o, COUNT(*) n FROM transactions WHERE date = ? AND branch_id IN ($in) GROUP BY branch_id", [$today]) as $r)
        { $x =& $out[(int)$r['branch_id']]; $x['d_in'] = (float)$r['i']; $x['d_out'] = (float)$r['o']; $x['d_n'] = (int)$r['n']; unset($x); }
    foreach ($q("SELECT branch_id, SUM(CASE WHEN cash_in=1 THEN payment ELSE 0 END) i, SUM(CASE WHEN cash_in<>1 THEN payment ELSE 0 END) o, COUNT(*) n FROM transactions WHERE date BETWEEN ? AND ? AND branch_id IN ($in) GROUP BY branch_id", [$ms, $me]) as $r)
        { $x =& $out[(int)$r['branch_id']]; $x['m_in'] = (float)$r['i']; $x['m_out'] = (float)$r['o']; $x['m_n'] = (int)$r['n']; unset($x); }
    foreach ($q("SELECT branch_id, COUNT(*) n, MAX(date) d FROM transactions WHERE branch_id IN ($in) GROUP BY branch_id") as $r)
        { $out[(int)$r['branch_id']]['tx'] = (int)$r['n']; $out[(int)$r['branch_id']]['last'] = $r['d']; }
    foreach ($q("SELECT branch_id, role, COUNT(*) n FROM users WHERE is_active=1 AND role<>'admin' AND branch_id IN ($in) GROUP BY branch_id, role") as $r)
        { if ($r['role'] === 'manager') $out[(int)$r['branch_id']]['managers'] = (int)$r['n']; else $out[(int)$r['branch_id']]['cashiers'] = (int)$r['n']; }
    foreach ($q("SELECT branch_id, COUNT(*) n FROM employees WHERE branch_id IN ($in) GROUP BY branch_id") as $r) $out[(int)$r['branch_id']]['emps'] = (int)$r['n'];
    $daily = [];
    foreach ($q("SELECT branch_id, date, SUM(CASE WHEN cash_in=1 THEN payment ELSE 0 END) i FROM transactions WHERE date BETWEEN ? AND ? AND branch_id IN ($in) GROUP BY branch_id, date", [$from, $today]) as $r) $daily[(int)$r['branch_id']][$r['date']] = (float)$r['i'];
    foreach ($ids as $id) {
        for ($i = $sparkDays - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("$today -$i days")); $out[$id]['spark'][] = $daily[$id][$d] ?? 0; }
        $out[$id]['bal'] = branch_balances($id);
    }
    return $out;
}
