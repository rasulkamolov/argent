<?php
session_start();
include 'db.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: index.php"); exit(); }

date_default_timezone_set('Asia/Tashkent');
$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end']   ?? date('Y-m-t');
$tab   = $_GET['tab']   ?? 'all';
$q     = trim($_GET['q'] ?? '');

$where = "t.date BETWEEN ? AND ?";
$params = [$start, $end];
if ($q !== '') { $where .= " AND t.comment LIKE ?"; $params[] = "%$q%"; }
if ($tab === 'income')  $where .= " AND t.cash_in=1";
if ($tab === 'expense') $where .= " AND t.cash_out=1";

$stmt = $conn->prepare("SELECT t.date, c.name as cat, t.comment, t.payment, t.cash_in, t.cash_out, t.cash, t.click, t.bank, u.username as author FROM transactions t LEFT JOIN categories c ON t.category_id=c.id LEFT JOIN users u ON t.user_id=u.id WHERE $where ORDER BY t.date DESC, t.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "hisobot_{$start}_{$end}.csv";
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: no-cache, no-store, must-revalidate');

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

// Header row
$cols = ['Sana','Kategoriya','Izoh','Summa','Tur','To\'lov usuli','Kiritdi'];
echo implode(';', $cols) . "\r\n";

foreach ($rows as $r) {
    $tur    = $r['cash_in'] ? 'Kirim' : 'Chiqim';
    $method = $r['bank'] ? 'Bank' : ($r['click'] ? 'Click' : 'Naqd');
    $row = [
        $r['date'],
        $r['cat'] ?? '',
        str_replace(["\r","\n",';'], ' ', $r['comment'] ?? ''),
        number_format($r['payment'], 0, '.', ''),
        $tur,
        $method,
        $r['author'] ?? '',
    ];
    echo implode(';', array_map(fn($v) => '"'.str_replace('"','""',$v).'"', $row)) . "\r\n";
}
exit();
