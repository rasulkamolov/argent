<?php
/**
 * Import of phpMyAdmin/MySQL dumps (INSERT INTO `transactions` ...) into a branch.
 * Used by import.php (admin UI) and tools/import_cli.php.
 *
 *   rows  = sqlimp_parse($sqlText)                 -> list of assoc rows
 *   plan  = sqlimp_plan($rows, $opt)                -> normalised transactions + summary (nothing written)
 *   res   = sqlimp_commit($plan, $branchId, $map)   -> writes inside one DB transaction (backup taken first)
 */
require_once __DIR__ . '/auth.php';

const SQLIMP_MAX_BYTES = 40 * 1024 * 1024;

/** Parse every `INSERT INTO <table> (cols) VALUES (...),(...);` into assoc rows. MySQL escapes (\' \\ \n \r) are honoured. */
function sqlimp_parse(string $src, string $table = 'transactions'): array {
    $rows = [];
    $n = strlen($src);
    if (!preg_match_all('/INSERT\s+INTO\s+`?' . preg_quote($table, '/') . '`?\s*\(([^)]*)\)\s*VALUES\s*/i', $src, $m, PREG_OFFSET_CAPTURE)) return [];
    foreach ($m[0] as $k => $hit) {
        $cols = array_map(function ($c) { return trim(trim($c), "` "); }, explode(',', $m[1][$k][0]));
        $i = $hit[1] + strlen($hit[0]);
        while ($i < $n) {
            while ($i < $n && strpos(" \n\r\t,", $src[$i]) !== false) $i++;
            if ($i >= $n || $src[$i] === ';') break;
            if ($src[$i] !== '(') throw new RuntimeException('Dump formati noto\'g\'ri (pozitsiya ' . $i . ')');
            $i++; $vals = [];
            while (true) {
                while ($i < $n && $src[$i] === ' ') $i++;
                if ($src[$i] === "'") {
                    $i++; $buf = '';
                    while (true) {
                        $c = $src[$i];
                        if ($c === '\\') { $d = $src[$i + 1]; $buf .= ($d === 'n' ? "\n" : ($d === 'r' ? "\r" : ($d === 't' ? "\t" : ($d === '0' ? "\0" : $d)))); $i += 2; }
                        elseif ($c === "'") { if (($src[$i + 1] ?? '') === "'") { $buf .= "'"; $i += 2; } else { $i++; break; } }
                        else { $j = $i; while ($j < $n && $src[$j] !== '\\' && $src[$j] !== "'") $j++; $buf .= substr($src, $i, $j - $i); $i = $j; }
                    }
                    $vals[] = $buf;
                } else {
                    $j = $i; while ($j < $n && $src[$j] !== ',' && $src[$j] !== ')') $j++;
                    $tok = trim(substr($src, $i, $j - $i)); $i = $j;
                    $vals[] = (strcasecmp($tok, 'NULL') === 0) ? null : (is_numeric($tok) ? $tok + 0 : $tok);
                }
                if ($src[$i] === ',') { $i++; continue; }
                if ($src[$i] === ')') { $i++; break; }
                throw new RuntimeException('Dump formati noto\'g\'ri (qiymatlar)');
            }
            $rows[] = array_combine($cols, array_pad(array_slice($vals, 0, count($cols)), count($cols), null));
        }
    }
    return $rows;
}

function sqlimp_category_for(string $type, string $comment): string {
    $t = mb_strtolower(trim($comment));
    if ($type === 'income') {
        if ($t === 'book' || str_contains($t, 'kitob')) return 'kitob';
        if ($t === 'shop' || $t === 'shoop' || str_starts_with($t, 'shop ')) return 'shop';
        return "To'lov";
    }
    if (preg_match('/oylik|oylig|maosh|avans|ish haqi|zarplat/u', $t)) return 'Oylik Maosh';
    if (str_contains($t, 'ijara')) return 'Ijara';
    return 'Boshqa xarajat';
}

/**
 * @param array $opt  tz_offset (hours, default 5), skip_empty (default true)
 * @return array plan: items[], summary numbers, users[name=>count]
 */
function sqlimp_plan(array $rows, int $branchId, array $opt = []): array {
    $tz = (int)($opt['tz_offset'] ?? 5); $skipEmpty = $opt['skip_empty'] ?? true;
    $items = []; $users = []; $skipped = 0; $unknown = 0; $dup = 0;
    $sum = ['income' => 0.0, 'expense' => 0.0]; $cnt = ['income' => 0, 'expense' => 0]; $dmin = null; $dmax = null;
    $have = [];
    $st = db()->prepare("SELECT ext_ref FROM transactions WHERE branch_id = ? AND ext_ref IS NOT NULL"); $st->execute([$branchId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) $have[$r] = true;
    foreach ($rows as $r) {
        $pay = (float)($r['payment'] ?? 0); $comment = str_replace("\r\n", "\n", trim((string)($r['comment'] ?? '')));
        $in = !empty($r['cash_in']); $out = !empty($r['cash_out']) || !empty($r['xarajat']);
        if (!$in && !$out) { if ($pay == 0 && $comment === '' && $skipEmpty) { $skipped++; continue; } $unknown++; continue; }
        $type = $in ? 'income' : 'expense';
        $method = !empty($r['bank']) ? 'bank' : (!empty($r['click']) ? 'click' : 'cash');
        $ref = isset($r['id']) ? 'sql:' . $r['id'] : null;
        if ($ref && isset($have[$ref])) { $dup++; continue; }
        $created = null;
        if (!empty($r['created_at'])) { $t = strtotime($r['created_at'] . ' UTC'); if ($t) $created = gmdate('Y-m-d H:i:s', $t - $tz * 3600); }
        $by = isset($r['entered_by']) && $r['entered_by'] !== '' ? (string)$r['entered_by'] : '';
        $users[$by] = ($users[$by] ?? 0) + 1;
        $items[] = ['ref' => $ref, 'date' => substr((string)$r['date'], 0, 10), 'payment' => $pay, 'type' => $type, 'method' => $method,
                    'comment' => $comment, 'by' => $by, 'created_at' => $created, 'category' => sqlimp_category_for($type, $comment)];
        $sum[$type] += $pay; $cnt[$type]++;
        $d = substr((string)$r['date'], 0, 10); if ($dmin === null || $d < $dmin) $dmin = $d; if ($dmax === null || $d > $dmax) $dmax = $d;
    }
    ksort($users);
    return ['items' => $items, 'users' => $users, 'skipped_empty' => $skipped, 'unknown' => $unknown, 'duplicates' => $dup, 'source_rows' => count($rows),
            'income_n' => $cnt['income'], 'income_sum' => $sum['income'], 'expense_n' => $cnt['expense'], 'expense_sum' => $sum['expense'], 'date_min' => $dmin, 'date_max' => $dmax];
}

/** Sensible default for each distinct "entered_by" value: existing user of this branch, an admin, or a new cashier. */
function sqlimp_default_user_map(array $names, int $branchId): array {
    $map = [];
    foreach (array_keys($names) as $name) {
        if ($name === '' || in_array(strtolower($name), ['unknown', 'null', 'none'], true)) { $map[$name] = ['mode' => 'none']; continue; }
        $st = db()->prepare("SELECT id, role, branch_id FROM users WHERE lower(username) = lower(?)"); $st->execute([$name]); $u = $st->fetch();
        if ($u && ($u['role'] === 'admin' || (int)$u['branch_id'] === $branchId)) $map[$name] = ['mode' => 'existing', 'user_id' => (int)$u['id']];
        elseif ($u) $map[$name] = ['mode' => 'none'];
        else $map[$name] = ['mode' => 'new'];
    }
    return $map;
}

function sqlimp_category_id(string $name, string $type, int $branchId, array &$cache): int {
    $key = $type . '|' . mb_strtolower($name);
    if (isset($cache[$key])) return $cache[$key];
    $st = db()->prepare("SELECT id FROM categories WHERE lower(name) = lower(?) AND type = ? AND (branch_id IS NULL OR branch_id = ?) ORDER BY branch_id IS NULL DESC LIMIT 1");
    $st->execute([$name, $type, $branchId]); $id = $st->fetchColumn();
    if (!$id) { db()->prepare("INSERT INTO categories (name, type, branch_id) VALUES (?,?,NULL)")->execute([$name, $type]); $id = db()->lastInsertId(); }
    return $cache[$key] = (int)$id;
}

/**
 * @param array $map  entered_by => ['mode'=>'new'|'existing'|'none', 'user_id'=>int]
 * @return array counts + created user credentials (shown once)
 */
function sqlimp_commit(array $plan, int $branchId, array $map, string $source = 'sql'): array {
    $b = branch_get($branchId); if (!$b) throw new RuntimeException('Filial topilmadi');
    $backup = db_backup('before_import');
    $created = []; $uid = []; $catCache = []; $inserted = 0; $ignored = 0;
    db()->beginTransaction();
    try {
        foreach (array_keys($plan['users']) as $name) {
            $m = $map[$name] ?? ['mode' => 'none'];
            if ($m['mode'] === 'existing' && !empty($m['user_id'])) { $uid[$name] = (int)$m['user_id']; }
            elseif ($m['mode'] === 'new' && $name !== '') {
                $st = db()->prepare("SELECT id FROM users WHERE lower(username) = lower(?)"); $st->execute([$name]); $ex = $st->fetchColumn();
                if ($ex) { $uid[$name] = (int)$ex; continue; }
                $pw = gen_password(10);
                db()->prepare("INSERT INTO users (username, password, role, branch_id, full_name, is_active, created_at) VALUES (?,?,?,?,?,1,CURRENT_TIMESTAMP)")
                    ->execute([strtolower($name), pw_hash($pw), 'staff', $branchId, ucfirst($name)]);
                $uid[$name] = (int)db()->lastInsertId(); $created[] = ['username' => strtolower($name), 'password' => $pw];
            }
        }
        $ins = db()->prepare("INSERT OR IGNORE INTO transactions (branch_id, user_id, category_id, payment, cash, click, bank, cash_in, cash_out, comment, date, created_at, ext_ref)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($plan['items'] as $it) {
            $ins->execute([$branchId, $uid[$it['by']] ?? null, sqlimp_category_id($it['category'], $it['type'], $branchId, $catCache), $it['payment'],
                $it['method'] === 'cash' ? 1 : 0, $it['method'] === 'click' ? 1 : 0, $it['method'] === 'bank' ? 1 : 0,
                $it['type'] === 'income' ? 1 : 0, $it['type'] === 'expense' ? 1 : 0, $it['comment'], $it['date'], $it['created_at'], $it['ref']]);
            if ($ins->rowCount()) $inserted++; else $ignored++;
        }
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    audit('import.commit', 'branch', $branchId, ['source' => $source, 'inserted' => $inserted, 'ignored' => $ignored, 'users_created' => count($created), 'backup' => $backup ? basename($backup) : null], $branchId);
    return ['inserted' => $inserted, 'ignored' => $ignored, 'created_users' => $created, 'backup' => $backup ? basename($backup) : null, 'branch' => $b['name']];
}
