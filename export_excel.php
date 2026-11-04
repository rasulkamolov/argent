<?php
require_once 'db.php';
require_once 'layout.php';
require_once 'report_lib.php';
require_admin();

$f = rl_filters();
$tab = $_GET['tab'] ?? 'all';
[$w, $p] = rl_where($f);
if ($tab === 'income')  $w .= " AND t.cash_in=1";
if ($tab === 'expense') $w .= " AND t.cash_in<>1";

$stmt = $conn->prepare("SELECT t.date, c.name as cat, t.comment, t.payment, t.cash_in, t.cash, t.click, t.bank, u.username as author FROM transactions t LEFT JOIN categories c ON t.category_id=c.id LEFT JOIN users u ON t.user_id=u.id WHERE $w ORDER BY t.date DESC, t.id DESC");
$stmt->execute($p);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "hisobot_{$f['start']}_{$f['end']}.csv";
header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: no-cache, no-store, must-revalidate');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
echo implode(';', ['Sana', 'Kategoriya', 'Izoh', 'Summa', 'Tur', "To'lov usuli", 'Kiritdi']) . "\r\n";

// guard against CSV/Excel formula injection
$safe = function ($v) { $v = (string)$v; return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; };
$tin = $tout = 0;
foreach ($rows as $r) {
    $is_in = $r['cash_in'] == 1;
    $is_in ? $tin += $r['payment'] : $tout += $r['payment'];
    $row = [
        $r['date'], $r['cat'] ?? '', str_replace(["\r", "\n"], ' ', $safe($r['comment'] ?? '')),
        number_format($r['payment'], 0, '.', ''), $is_in ? 'Kirim' : 'Chiqim', method_meta(method_of($r))['label'], $r['author'] ?? '',
    ];
    echo implode(';', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $row)) . "\r\n";
}
echo "\r\n";
echo '"";"JAMI KIRIM";"";"' . number_format($tin, 0, '.', '') . "\";\"\";\"\";\"\"\r\n";
echo '"";"JAMI CHIQIM";"";"' . number_format($tout, 0, '.', '') . "\";\"\";\"\";\"\"\r\n";
echo '"";"SOF FOYDA";"";"' . number_format($tin - $tout, 0, '.', '') . "\";\"\";\"\";\"\"\r\n";
exit();
