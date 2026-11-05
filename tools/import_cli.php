<?php
/**
 * Command-line import:  php tools/import_cli.php dump.sql "Branch name" [--create-branch] [--tz=5] [--dry]
 * Creates the branch if --create-branch is given and it doesn't exist. Prints new cashier passwords once.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../import_lib.php';
$args = array_values(array_filter($argv, function ($a) { return $a[0] !== '-' || $a === '-'; }));
$flags = array_filter($argv, function ($a) { return substr($a, 0, 2) === '--'; });
if (count($args) < 3) { fwrite(STDERR, "Usage: php tools/import_cli.php dump.sql \"Branch name\" [--create-branch] [--tz=5] [--dry]\n"); exit(1); }
[$_, $file, $bname] = $args;
$tz = 5; foreach ($flags as $f) if (preg_match('/^--tz=(-?\d+)$/', $f, $m)) $tz = (int)$m[1];
$dry = in_array('--dry', $flags, true); $create = in_array('--create-branch', $flags, true);
$bid = 0; foreach (branches_all() as $b) if (mb_strtolower($b['name']) === mb_strtolower($bname)) $bid = (int)$b['id'];
if (!$bid && $create && !$dry) {
    db()->prepare("INSERT INTO branches (name, color, sort_order) VALUES (?,?,?)")->execute([$bname, next_branch_color(), count(branches_all()) + 1]);
    $bid = (int)db()->lastInsertId(); branches_all(true); echo "Branch created: #$bid $bname\n";
}
if (!$bid && !$dry) { fwrite(STDERR, "Branch not found: $bname (use --create-branch)\n"); exit(1); }
$rows = sqlimp_parse(file_get_contents($file));
$plan = sqlimp_plan($rows, $bid, ['tz_offset' => $tz]);
printf("Source rows: %d | to import: %d (income %d = %s, expense %d = %s) | empty skipped: %d | already imported: %d | %s .. %s\n",
    $plan['source_rows'], count($plan['items']), $plan['income_n'], number_format($plan['income_sum'], 0, '.', ' '), $plan['expense_n'], number_format($plan['expense_sum'], 0, '.', ' '),
    $plan['skipped_empty'], $plan['duplicates'], $plan['date_min'], $plan['date_max']);
echo "Users: " . json_encode($plan['users'], JSON_UNESCAPED_UNICODE) . "\n";
if ($dry) { echo "(dry run - nothing written)\n"; exit(0); }
$map = sqlimp_default_user_map($plan['users'], $bid);
$res = sqlimp_commit($plan, $bid, $map, 'cli');
echo "Inserted: {$res['inserted']} | ignored(dupes): {$res['ignored']}\n";
foreach ($res['created_users'] as $u) echo "NEW CASHIER  {$u['username']}  password: {$u['password']}\n";
