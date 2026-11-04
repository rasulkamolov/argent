<?php
/**
 * Shared filter parsing, SQL helpers and filter-bar UI for
 * hisobot.php, analitika.php and export_excel.php.
 * Requires layout.php to be loaded first.
 */

const RL_METHOD_SQL = "CASE WHEN t.bank=1 THEN 'bank' WHEN t.click=1 THEN 'click' ELSE 'cash' END";

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
    return [
        'start' => $start, 'end' => $end, 'method' => $method,
        'cat' => (int)($_GET['cat'] ?? 0), 'user' => (int)($_GET['user'] ?? 0),
        'q' => trim($_GET['q'] ?? ''),
    ];
}

/** WHERE fragment (aliased table `t`) + params for the given range (defaults to the filter's own). */
function rl_where(array $f, ?string $start = null, ?string $end = null): array {
    $w = ["t.date BETWEEN ? AND ?"]; $p = [$start ?? $f['start'], $end ?? $f['end']];
    if ($f['method'] === 'bank')  $w[] = "t.bank = 1";
    if ($f['method'] === 'click') $w[] = "t.click = 1 AND t.bank = 0";
    if ($f['method'] === 'cash')  $w[] = "t.bank = 0 AND t.click = 0";
    if ($f['cat'])  { $w[] = "t.category_id = ?"; $p[] = $f['cat']; }
    if ($f['user']) { $w[] = "t.user_id = ?";     $p[] = $f['user']; }
    if ($f['q'] !== '') { $w[] = "t.comment LIKE ?"; $p[] = '%' . $f['q'] . '%'; }
    return [implode(' AND ', $w), $p];
}

/** Query string for links, keeping all filters; $over replaces / removes (null) keys. */
function rl_qs(array $f, array $over = []): string {
    $q = array_merge(array_filter([
        'start' => $f['start'], 'end' => $f['end'], 'method' => $f['method'],
        'cat' => $f['cat'] ?: null, 'user' => $f['user'] ?: null, 'q' => $f['q'] !== '' ? $f['q'] : null,
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
function rl_render_filters(PDO $conn, array $f, string $action, array $hidden = [], bool $withSearch = true): void {
    $presets = rl_presets();
    $active = 'custom';
    foreach ($presets as $k => [$lbl, $s, $e]) { if ($s === $f['start'] && $e === $f['end']) { $active = $k; break; } }
    $cats  = $conn->query("SELECT id, name, type FROM categories ORDER BY type DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    $users = $conn->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    $adv = $f['method'] !== '' || $f['cat'] || $f['user'] || ($withSearch && $f['q'] !== '');
    $advCount = (int)($f['method'] !== '') + (int)(bool)$f['cat'] + (int)(bool)$f['user'] + (int)($withSearch && $f['q'] !== '');
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
      <div class="row-dates grid" style="grid-template-columns:1fr 1fr;flex:1 1 280px;max-width:440px">
        <div class="field"><label class="label" for="fStart">Boshlanish</label><input class="input input-sm" id="fStart" type="date" name="start" value="<?= e($f['start']) ?>"></div>
        <div class="field"><label class="label" for="fEnd">Tugash</label><input class="input input-sm" id="fEnd" type="date" name="end" value="<?= e($f['end']) ?>"></div>
      </div>
      <button class="btn btn-primary" type="submit"><?= icon('filter', 'i-sm') ?> Qo'llash</button>
      <?php if ($adv): ?><a class="btn btn-ghost" href="<?= e($action . '?' . http_build_query(['start' => $f['start'], 'end' => $f['end']] + array_filter($hidden))) ?>">Tozalash</a><?php endif; ?>
    </div>
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
        <?php if ($withSearch): ?>
        <div class="field"><label class="label" for="fQ">Izoh bo'yicha qidirish</label>
          <div class="input-icon"><?= icon('search') ?><input class="input input-sm" style="padding-left:42px" id="fQ" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Qidirish…"></div></div>
        <?php endif; ?>
      </div>
    </details>
  </form>
</section>
<?php
}
