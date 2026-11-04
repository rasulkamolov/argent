<?php
session_start();
include 'db.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: index.php"); exit(); }

date_default_timezone_set('Asia/Tashkent');
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date   = $_GET['end']   ?? date('Y-m-t');
$search     = trim($_GET['q'] ?? '');
$tab        = $_GET['tab'] ?? 'all';

if (isset($_GET['range'])) {
    if ($_GET['range'] == 'last')    { $start_date = date('Y-m-01', strtotime('last month')); $end_date = date('Y-m-t', strtotime('last month')); }
    elseif ($_GET['range'] == 'current') { $start_date = date('Y-m-01'); $end_date = date('Y-m-t'); }
    elseif ($_GET['range'] == 'today')   { $start_date = $end_date = date('Y-m-d'); }
}

// --- KPI STATS ---
$kpi = $conn->prepare("SELECT
    SUM(CASE WHEN cash_in=1 THEN payment ELSE 0 END) as total_in,
    SUM(CASE WHEN cash_out=1 THEN payment ELSE 0 END) as total_out,
    SUM(CASE WHEN cash=1 AND cash_in=1 THEN payment WHEN cash=1 AND cash_out=1 THEN -payment ELSE 0 END) as cash_bal,
    SUM(CASE WHEN click=1 AND cash_in=1 THEN payment WHEN click=1 AND cash_out=1 THEN -payment ELSE 0 END) as click_bal,
    SUM(CASE WHEN bank=1 AND cash_in=1 THEN payment WHEN bank=1 AND cash_out=1 THEN -payment ELSE 0 END) as bank_bal,
    SUM(CASE WHEN cash=1 AND cash_in=1 THEN payment ELSE 0 END) as cash_in,
    SUM(CASE WHEN click=1 AND cash_in=1 THEN payment ELSE 0 END) as click_in,
    SUM(CASE WHEN bank=1 AND cash_in=1 THEN payment ELSE 0 END) as bank_in
    FROM transactions WHERE date BETWEEN ? AND ?");
$kpi->execute([$start_date, $end_date]);
$stats = $kpi->fetch(PDO::FETCH_ASSOC);
$in  = $stats['total_in']  ?: 0;
$out = $stats['total_out'] ?: 0;
$net = $in - $out;

// --- TRANSACTIONS LIST ---
$where = "date BETWEEN ? AND ?";
$params = [$start_date, $end_date];
if ($search !== '') { $where .= " AND t.comment LIKE ?"; $params[] = "%$search%"; }
$list = $conn->prepare("SELECT t.*, c.name as cat, u.username as author FROM transactions t LEFT JOIN categories c ON t.category_id=c.id LEFT JOIN users u ON t.user_id=u.id WHERE $where ORDER BY t.date DESC, t.id DESC");
$list->execute($params);
$rows     = $list->fetchAll(PDO::FETCH_ASSOC);
$incomes  = array_filter($rows, fn($r) => $r['cash_in'] == 1);
$expenses = array_filter($rows, fn($r) => $r['cash_out'] == 1);

// --- CATEGORY BREAKDOWN ---
$cat_income = $conn->prepare("SELECT c.name, SUM(t.payment) as total, COUNT(*) as cnt FROM transactions t JOIN categories c ON t.category_id=c.id WHERE t.cash_in=1 AND t.date BETWEEN ? AND ? GROUP BY c.name ORDER BY total DESC");
$cat_income->execute([$start_date, $end_date]);
$cat_in_data = $cat_income->fetchAll(PDO::FETCH_ASSOC);

$cat_expense = $conn->prepare("SELECT c.name, SUM(t.payment) as total, COUNT(*) as cnt FROM transactions t JOIN categories c ON t.category_id=c.id WHERE t.cash_out=1 AND t.date BETWEEN ? AND ? GROUP BY c.name ORDER BY total DESC");
$cat_expense->execute([$start_date, $end_date]);
$cat_out_data = $cat_expense->fetchAll(PDO::FETCH_ASSOC);

// --- DAILY TOTALS ---
$daily = $conn->prepare("SELECT date, SUM(CASE WHEN cash_in=1 THEN payment ELSE 0 END) as inc, SUM(CASE WHEN cash_out=1 THEN payment ELSE 0 END) as exp FROM transactions WHERE date BETWEEN ? AND ? GROUP BY date ORDER BY date DESC");
$daily->execute([$start_date, $end_date]);
$daily_rows = $daily->fetchAll(PDO::FETCH_ASSOC);

function fmt($n) { return number_format($n ?: 0, 0, '.', ' '); }
$display_rows = ($tab === 'income') ? $incomes : (($tab === 'expense') ? $expenses : $rows);
$display_rows = array_values($display_rows); // re-index
$limit = in_array((int)($_GET['limit'] ?? 10), [10, 20, 50, 100]) ? (int)$_GET['limit'] : 10;
$total_rows = count($display_rows);
$display_rows_paged = array_slice($display_rows, 0, $limit);
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hisobotlar — OXFORD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f5f5f7; }
        .tab-active { background: white; color: #4f46e5; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        .kpi-card { background: white; border-radius: 1.5rem; padding: 1.75rem; border: 1px solid #f1f1f1; }
        .row-in  { border-left: 3px solid #10b981; }
        .row-out { border-left: 3px solid #f43f5e; }
        @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        .fade-up { animation: fadeUp .3s ease forwards; }
    </style>
</head>
<body class="text-zinc-800 pb-24">

<nav class="sticky top-0 z-30 bg-white/90 backdrop-blur-md border-b border-zinc-200/60">
    <div class="max-w-7xl mx-auto px-6 h-16 flex justify-between items-center">
        <div class="flex items-center gap-3">
            <span class="font-bold text-lg text-zinc-900">Guliston<span class="text-indigo-600">AvtoMaktab</span></span>
            <span class="text-zinc-300">|</span>
            <span class="font-semibold text-zinc-500 text-sm">Moliyaviy Hisobot</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="export_excel.php?start=<?= urlencode($start_date) ?>&end=<?= urlencode($end_date) ?>&tab=<?= $tab ?>&q=<?= urlencode($search) ?>"
               class="flex items-center gap-2 bg-emerald-600 text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-emerald-700 transition shadow-sm">
                <i data-lucide="download" class="w-4 h-4"></i> Excel
            </a>
            <a href="analitika.php" class="flex items-center gap-2 text-sm font-semibold text-indigo-600 bg-indigo-50 px-4 py-2 rounded-xl hover:bg-indigo-100 transition">
                <i data-lucide="bar-chart-2" class="w-4 h-4"></i> Analitika
            </a>
            <a href="admin.php" class="text-sm font-semibold text-zinc-500 hover:text-zinc-900 flex items-center gap-1.5 transition-colors">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Orqaga
            </a>
        </div>
    </div>
</nav>

<div class="max-w-7xl mx-auto px-6 py-8 space-y-8">

    <!-- STATS PAGE SUB-NAV -->
    <div class="flex gap-2 bg-white rounded-2xl p-1.5 shadow-sm border border-zinc-100 w-fit">
        <span class="px-5 py-2 rounded-xl text-sm font-bold bg-indigo-600 text-white shadow">
            <i class="inline-block w-3 h-3 mr-1" data-lucide="file-bar-chart"></i> Hisobot
        </span>
        <a href="analitika.php" class="px-5 py-2 rounded-xl text-sm font-bold text-zinc-500 hover:bg-zinc-50 hover:text-zinc-800 transition flex items-center gap-1.5">
            <i data-lucide="line-chart" class="w-3.5 h-3.5"></i> Grafik Analitika
        </a>
    </div>

    <!-- FILTER BAR -->
    <div class="bg-white rounded-2xl shadow-sm border border-zinc-100 p-4">
        <form method="get" class="flex flex-wrap gap-3 items-center">
            <div class="flex gap-2">
                <a href="?range=today&tab=<?=$tab?>" class="px-3 py-2 rounded-xl text-xs font-bold bg-zinc-100 hover:bg-zinc-200 text-zinc-600 transition">Bugun</a>
                <a href="?range=current&tab=<?=$tab?>" class="px-3 py-2 rounded-xl text-xs font-bold bg-zinc-100 hover:bg-zinc-200 text-zinc-600 transition">Joriy Oy</a>
                <a href="?range=last&tab=<?=$tab?>" class="px-3 py-2 rounded-xl text-xs font-bold bg-zinc-100 hover:bg-zinc-200 text-zinc-600 transition">O'tgan Oy</a>
            </div>
            <div class="flex gap-2 items-center flex-1 min-w-0">
                <input type="date" name="start" value="<?= $start_date ?>" class="bg-zinc-50 border border-zinc-200 rounded-xl px-3 py-2 text-sm font-bold text-zinc-600 outline-none focus:border-indigo-400">
                <span class="text-zinc-300 font-bold">—</span>
                <input type="date" name="end" value="<?= $end_date ?>" class="bg-zinc-50 border border-zinc-200 rounded-xl px-3 py-2 text-sm font-bold text-zinc-600 outline-none focus:border-indigo-400">
            </div>
            <div class="flex gap-2 flex-1 min-w-[200px]">
                <div class="relative flex-1">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Izoh bo'yicha qidirish..." class="w-full pl-9 pr-4 py-2 bg-zinc-50 border border-zinc-200 rounded-xl text-sm outline-none focus:border-indigo-400">
                </div>
                <input type="hidden" name="tab" value="<?= $tab ?>">
                <button class="bg-indigo-600 text-white px-4 py-2 rounded-xl shadow hover:bg-indigo-700 transition text-sm font-bold">Qidirish</button>
            </div>
        </form>
    </div>

    <!-- KPI CARDS -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div class="kpi-card border-emerald-100 shadow-[0_4px_20px_-4px_rgba(16,185,129,0.12)] fade-up">
            <div class="flex justify-between items-start mb-3">
                <p class="text-[11px] font-bold text-emerald-600 uppercase tracking-widest">Jami Kirim</p>
                <div class="w-9 h-9 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600"><i data-lucide="trending-up" class="w-5 h-5"></i></div>
            </div>
            <h3 class="text-3xl font-extrabold text-zinc-800 tracking-tight"><?= fmt($in) ?></h3>
            <p class="text-xs text-zinc-400 mt-2"><?= count($incomes) ?> ta tranzaksiya</p>
        </div>
        <div class="kpi-card border-rose-100 shadow-[0_4px_20px_-4px_rgba(244,63,94,0.12)] fade-up" style="animation-delay:.05s">
            <div class="flex justify-between items-start mb-3">
                <p class="text-[11px] font-bold text-rose-600 uppercase tracking-widest">Jami Chiqim</p>
                <div class="w-9 h-9 rounded-xl bg-rose-50 flex items-center justify-center text-rose-600"><i data-lucide="trending-down" class="w-5 h-5"></i></div>
            </div>
            <h3 class="text-3xl font-extrabold text-zinc-800 tracking-tight"><?= fmt($out) ?></h3>
            <p class="text-xs text-zinc-400 mt-2"><?= count($expenses) ?> ta tranzaksiya</p>
        </div>
        <div class="kpi-card border-indigo-100 shadow-[0_4px_20px_-4px_rgba(79,70,229,0.12)] fade-up" style="animation-delay:.1s">
            <div class="flex justify-between items-start mb-3">
                <p class="text-[11px] font-bold text-indigo-600 uppercase tracking-widest">Sof Foyda</p>
                <div class="w-9 h-9 rounded-xl bg-indigo-50 flex items-center justify-center text-indigo-600"><i data-lucide="wallet" class="w-5 h-5"></i></div>
            </div>
            <h3 class="text-3xl font-extrabold <?= $net >= 0 ? 'text-zinc-800' : 'text-rose-600' ?> tracking-tight"><?= ($net < 0 ? '-' : '+') . fmt(abs($net)) ?></h3>
            <p class="text-xs text-zinc-400 mt-2"><?= $in > 0 ? number_format(($net/$in)*100,1) : 0 ?>% foyda marjasi</p>
        </div>
    </div>

    <!-- PAYMENT METHOD SPLIT -->
    <div class="bg-white rounded-2xl border border-zinc-100 shadow-sm p-6">
        <h3 class="font-bold text-zinc-700 text-sm uppercase tracking-wide mb-5 flex items-center gap-2">
            <i data-lucide="credit-card" class="w-4 h-4 text-indigo-500"></i> To'lov Usullari bo'yicha Kirim
        </h3>
        <div class="grid grid-cols-3 gap-4">
            <?php
            $total_m = $stats['cash_in'] + $stats['click_in'] + $stats['bank_in'];
            $methods_data = [
                ['label'=>'Naqd','color'=>'emerald','val'=>$stats['cash_in']],
                ['label'=>'Click','color'=>'blue','val'=>$stats['click_in']],
                ['label'=>'Bank','color'=>'purple','val'=>$stats['bank_in']],
            ];
            foreach($methods_data as $m):
                $pct = $total_m > 0 ? ($m['val']/$total_m)*100 : 0;
                $c = $m['color'];
            ?>
            <div class="space-y-2">
                <div class="flex justify-between text-xs font-bold"><span class="text-<?=$c?>-600"><?=$m['label']?></span><span class="text-zinc-500"><?=round($pct)?>%</span></div>
                <div class="h-2 bg-zinc-100 rounded-full overflow-hidden"><div class="h-full bg-<?=$c?>-500 rounded-full transition-all" style="width:<?=max($pct,0)?>%"></div></div>
                <div class="text-sm font-bold text-zinc-700"><?=fmt($m['val'])?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Cash balances row -->
        <div class="grid grid-cols-3 gap-4 mt-6 pt-5 border-t border-zinc-100">
            <?php
            $bals = [['Naqd Qoldiq','cash_bal'],['Click Qoldiq','click_bal'],['Bank Qoldiq','bank_bal']];
            foreach($bals as [$label,$key]):
                $v = $stats[$key] ?: 0;
            ?>
            <div class="text-center p-3 bg-zinc-50 rounded-xl">
                <p class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide mb-1"><?=$label?></p>
                <p class="text-lg font-bold <?=$v>=0?'text-zinc-800':'text-rose-600'?>"><?=($v<0?'-':'')?><?=fmt(abs($v))?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- CATEGORY BREAKDOWN -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Income by category -->
        <div class="bg-white rounded-2xl border border-zinc-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-zinc-50 bg-emerald-50/40 flex justify-between items-center">
                <h3 class="font-bold text-emerald-700 flex items-center gap-2"><i data-lucide="layers" class="w-4 h-4"></i> Kirim — Kategoriyalar</h3>
                <span class="text-xs font-bold bg-white text-emerald-600 border border-emerald-100 px-2 py-1 rounded-lg"><?=count($cat_in_data)?> tur</span>
            </div>
            <div class="p-4 space-y-2">
                <?php foreach($cat_in_data as $ci): $pct = $in > 0 ? ($ci['total']/$in)*100 : 0; ?>
                <div class="p-3 rounded-xl hover:bg-zinc-50 transition-colors">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-sm font-semibold text-zinc-700"><?=htmlspecialchars($ci['name'])?></span>
                        <span class="font-bold text-emerald-600 text-sm"><?=fmt($ci['total'])?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex-1 h-1.5 bg-zinc-100 rounded-full"><div class="h-full bg-emerald-400 rounded-full" style="width:<?=$pct?>%"></div></div>
                        <span class="text-[10px] text-zinc-400 font-bold w-8 text-right"><?=round($pct)?>%</span>
                        <span class="text-[10px] text-zinc-400"><?=$ci['cnt']?> ta</span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(empty($cat_in_data)): ?><p class="text-zinc-400 text-sm text-center py-6">Ma'lumot yo'q</p><?php endif; ?>
            </div>
        </div>
        <!-- Expense by category -->
        <div class="bg-white rounded-2xl border border-zinc-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-zinc-50 bg-rose-50/40 flex justify-between items-center">
                <h3 class="font-bold text-rose-700 flex items-center gap-2"><i data-lucide="layers" class="w-4 h-4"></i> Chiqim — Kategoriyalar</h3>
                <span class="text-xs font-bold bg-white text-rose-600 border border-rose-100 px-2 py-1 rounded-lg"><?=count($cat_out_data)?> tur</span>
            </div>
            <div class="p-4 space-y-2">
                <?php foreach($cat_out_data as $co): $pct = $out > 0 ? ($co['total']/$out)*100 : 0; ?>
                <div class="p-3 rounded-xl hover:bg-zinc-50 transition-colors">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-sm font-semibold text-zinc-700"><?=htmlspecialchars($co['name'])?></span>
                        <span class="font-bold text-rose-600 text-sm"><?=fmt($co['total'])?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex-1 h-1.5 bg-zinc-100 rounded-full"><div class="h-full bg-rose-400 rounded-full" style="width:<?=$pct?>%"></div></div>
                        <span class="text-[10px] text-zinc-400 font-bold w-8 text-right"><?=round($pct)?>%</span>
                        <span class="text-[10px] text-zinc-400"><?=$co['cnt']?> ta</span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(empty($cat_out_data)): ?><p class="text-zinc-400 text-sm text-center py-6">Ma'lumot yo'q</p><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- DAILY TOTALS -->
    <?php if(!empty($daily_rows)): ?>
    <div class="bg-white rounded-2xl border border-zinc-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-zinc-100 flex justify-between items-center">
            <h3 class="font-bold text-zinc-700 flex items-center gap-2"><i data-lucide="calendar-days" class="w-4 h-4 text-indigo-500"></i> Kunlik Jami</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-xs font-bold uppercase text-zinc-400">
                    <tr>
                        <th class="px-6 py-3 text-left">Sana</th>
                        <th class="px-6 py-3 text-right text-emerald-600">Kirim</th>
                        <th class="px-6 py-3 text-right text-rose-600">Chiqim</th>
                        <th class="px-6 py-3 text-right text-indigo-600">Balans</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50">
                    <?php foreach($daily_rows as $dr): $bal = $dr['inc'] - $dr['exp']; ?>
                    <tr class="hover:bg-zinc-50/60 transition-colors">
                        <td class="px-6 py-3 font-semibold text-zinc-700"><?=date('d.m.Y', strtotime($dr['date']))?></td>
                        <td class="px-6 py-3 text-right font-bold text-emerald-600"><?=$dr['inc']>0?'+'.fmt($dr['inc']):'-'?></td>
                        <td class="px-6 py-3 text-right font-bold text-rose-600"><?=$dr['exp']>0?'-'.fmt($dr['exp']):'-'?></td>
                        <td class="px-6 py-3 text-right font-bold <?=$bal>=0?'text-zinc-800':'text-rose-600'?>"><?=($bal<0?'-':'').fmt(abs($bal))?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- TRANSACTION LIST WITH TABS -->
    <div class="bg-white rounded-2xl border border-zinc-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-zinc-100 flex flex-wrap justify-between items-center gap-3">
            <h3 class="font-bold text-zinc-800 flex items-center gap-2"><i data-lucide="list" class="w-4 h-4 text-zinc-400"></i> Tranzaksiyalar
                <span class="text-xs font-normal text-zinc-400 ml-1">(<?=$total_rows?> ta)</span>
            </h3>
            <div class="flex items-center gap-3 flex-wrap">
                <!-- Per-page selector -->
                <div class="flex items-center gap-1 bg-zinc-100 rounded-xl p-1">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase px-2">Ko'rsat:</span>
                    <?php foreach([10,20,50,100] as $lim): ?>
                    <a href="?start=<?=$start_date?>&end=<?=$end_date?>&tab=<?=$tab?>&q=<?=urlencode($search)?>&limit=<?=$lim?>"
                       class="px-3 py-1.5 rounded-lg text-xs font-bold transition <?=$limit===$lim?'bg-white text-indigo-600 shadow':'text-zinc-500 hover:text-zinc-700'?>"><?=$lim?></a>
                    <?php endforeach; ?>
                </div>
                <!-- Tab filter -->
                <div class="flex bg-zinc-100 rounded-xl p-1 gap-0.5">
                    <?php foreach([['all','Barchasi'],['income','Kirimlar'],['expense','Chiqimlar']] as [$t,$lbl]): ?>
                    <a href="?start=<?=$start_date?>&end=<?=$end_date?>&tab=<?=$t?>&q=<?=urlencode($search)?>&limit=<?=$limit?>"
                       class="px-4 py-1.5 rounded-lg text-xs font-bold transition <?=$tab===$t?'tab-active':'text-zinc-500 hover:text-zinc-700'?>">
                        <?=$lbl?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="divide-y divide-zinc-50">
            <?php foreach($display_rows_paged as $row):
                $is_in = $row['cash_in'] == 1;
                $method = $row['bank'] ? 'Bank' : ($row['click'] ? 'Click' : 'Naqd');
                $mcolor = $row['bank'] ? 'purple' : ($row['click'] ? 'blue' : 'emerald');
            ?>
            <div class="flex items-center justify-between px-6 py-3.5 hover:bg-zinc-50/70 transition-colors <?=$is_in?'row-in':'row-out'?>">
                <div class="flex items-center gap-4 min-w-0">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 <?=$is_in?'bg-emerald-50 text-emerald-600':'bg-rose-50 text-rose-600'?>">
                        <i data-lucide="<?=$is_in?'arrow-down-left':'arrow-up-right'?>" class="w-4 h-4"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-semibold text-sm text-zinc-800"><?=htmlspecialchars($row['cat'] ?: 'Umumiy')?></span>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-lg bg-<?=$mcolor?>-50 text-<?=$mcolor?>-600 border border-<?=$mcolor?>-100"><?=$method?></span>
                        </div>
                        <div class="text-xs text-zinc-400 mt-0.5 flex items-center gap-2">
                            <span><?=date('d.m.Y', strtotime($row['date']))?></span>
                            <?php if($row['author']): ?><span class="font-medium text-zinc-500"><?=htmlspecialchars($row['author'])?></span><?php endif; ?>
                            <?php if($row['comment']): ?><span class="truncate max-w-[200px] italic"><?=htmlspecialchars($row['comment'])?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="font-bold text-base flex-shrink-0 ml-4 <?=$is_in?'text-emerald-600':'text-zinc-800'?>">
                    <?=($is_in?'+':'-').fmt($row['payment'])?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($display_rows)): ?>
            <div class="text-center py-16 text-zinc-400">
                <i data-lucide="inbox" class="w-10 h-10 mx-auto mb-3 opacity-40"></i>
                <p class="text-sm font-medium">Ma'lumot topilmadi</p>
            </div>
            <?php endif; ?>
        </div>
        <?php if($total_rows > 0): ?>
        <div class="px-6 py-3 bg-zinc-50 border-t border-zinc-100 flex justify-between items-center flex-wrap gap-2">
            <span class="text-xs text-zinc-400 font-medium">
                <?=min($limit,$total_rows)?> / <?=$total_rows?> ta tranzaksiya ko'rsatilmoqda
            </span>
            <?php if($total_rows > $limit): ?>
            <a href="?start=<?=$start_date?>&end=<?=$end_date?>&tab=<?=$tab?>&q=<?=urlencode($search)?>&limit=<?=min($limit*2,100)?>"
               class="text-xs font-bold text-indigo-600 hover:underline flex items-center gap-1">
                Ko'proq ko'rish <i data-lucide="chevron-down" class="w-3 h-3"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>
<script>lucide.createIcons();</script>
</body>
</html>