<?php
/**
 * Prints the STRUCTURE of the Do'konPro database so the Argent shop module can be written against the real schema.
 * STRICTLY READ-ONLY (SQLite opened with mode=ro, MySQL only SELECT/SHOW). Prints no row data and no personal data:
 * only table definitions, row counts, and the distinct values of columns that look like enums (type/status/role/method/category/...).
 *
 *   SQLite:  php tools/shop_schema_dump.php /home/u180105715/domains/oxfordlc.uz/public_html/shop/db/yourfile.db
 *   MySQL :  SHOP_DB_PASS='...' php tools/shop_schema_dump.php "mysql:host=localhost;dbname=NAME;charset=utf8mb4" DBUSER
 *
 * Send the output (it is safe to paste) — nothing in it identifies a customer or a user.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dsn = $argv[1] ?? null; if (!$dsn) { fwrite(STDERR, "Usage: see the comment at the top of this file\n"); exit(1); }
$enumLike = '/(type|status|role|kind|method|category|reason|action|unit|state|source|payment|branch)(_?id)?$/i'; $skip = '/(pass|hash|token|phone|email|address|name|comment|note|desc)/i';
try {
    if (strpos($dsn, 'mysql:') === 0) { $pdo = new PDO($dsn, $argv[2] ?? '', getenv('SHOP_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $my = true; }
    else { if (!is_file($dsn)) throw new RuntimeException("File not found: $dsn"); $pdo = new PDO('sqlite:file:' . $dsn . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $my = false; }
} catch (Throwable $e) { fwrite(STDERR, "Cannot open the database read-only: " . $e->getMessage() . "\n"); exit(2); }
echo "-- Do'konPro schema dump " . date('c') . " (" . ($my ? 'MySQL' : 'SQLite') . ", read-only)\n\n";
$tables = $my ? $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) : $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $q = '`' . str_replace('`', '', $t) . '`';
    echo "=== $t  (" . $pdo->query("SELECT COUNT(*) FROM $q")->fetchColumn() . " rows)\n";
    echo ($my ? $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1] : $pdo->query("SELECT sql FROM sqlite_master WHERE name = " . $pdo->quote($t))->fetchColumn()) . "\n";
    $cols = $my ? array_column($pdo->query("SHOW COLUMNS FROM $q")->fetchAll(PDO::FETCH_ASSOC), 'Field') : array_column($pdo->query("PRAGMA table_info($q)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ($cols as $c) {
        if (!preg_match($enumLike, $c) || preg_match($skip, $c)) continue;
        $cq = '`' . str_replace('`', '', $c) . '`';
        $v = $pdo->query("SELECT $cq AS v, COUNT(*) AS n FROM $q GROUP BY $cq ORDER BY n DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
        if (count($v) <= 24) echo "   values of $c: " . implode(', ', array_map(function ($x) { return ($x['v'] === null ? 'NULL' : $x['v']) . '×' . $x['n']; }, $v)) . "\n";
    }
    foreach ($cols as $c) if (preg_match('/(created|updated|deleted|date|time|_at)$/i', $c)) { $cq = '`' . str_replace('`', '', $c) . '`'; $r = $pdo->query("SELECT MIN($cq), MAX($cq) FROM $q")->fetch(PDO::FETCH_NUM); echo "   range of $c: {$r[0]} … {$r[1]}\n"; }
    echo "\n";
}
if (!$my) { echo "-- journal_mode: " . $pdo->query('PRAGMA journal_mode')->fetchColumn() . "\n"; foreach (['triggers' => "SELECT name FROM sqlite_master WHERE type = 'trigger'", 'views' => "SELECT name FROM sqlite_master WHERE type = 'view'"] as $k => $s) echo "-- $k: " . (implode(', ', $pdo->query($s)->fetchAll(PDO::FETCH_COLUMN)) ?: 'none') . "\n"; }
