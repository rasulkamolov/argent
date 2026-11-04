<?php
session_start();
include 'db.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: index.php"); exit(); }

date_default_timezone_set('Asia/Tashkent');
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');

// --- HELPER FUNCTIONS ---
function getExpenseByCat($conn, $methodCol, $start, $end) {
    $sql = "SELECT c.name, SUM(t.payment) as total 
            FROM transactions t 
            JOIN categories c ON t.category_id = c.id 
            WHERE t.cash_out = 1 AND t.$methodCol = 1 AND t.date BETWEEN ? AND ? 
            GROUP BY c.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getIncomeByCat($conn, $start, $end) {
    $sql = "SELECT c.name, SUM(t.payment) as total 
            FROM transactions t 
            JOIN categories c ON t.category_id = c.id 
            WHERE t.cash_in = 1 AND t.date BETWEEN ? AND ? 
            GROUP BY c.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 1. DATA FOR PIES
$naqd_expenses = getExpenseByCat($conn, 'cash', $start_date, $end_date);
$click_expenses = getExpenseByCat($conn, 'click', $start_date, $end_date);
$bank_expenses = getExpenseByCat($conn, 'bank', $start_date, $end_date);
$income_cats = getIncomeByCat($conn, $start_date, $end_date);

// 2. TREND DATA
$trend_sql = "SELECT date, 
    SUM(CASE WHEN cash_in=1 THEN payment ELSE 0 END) as inc,
    SUM(CASE WHEN cash_out=1 THEN payment ELSE 0 END) as exp
    FROM transactions WHERE date BETWEEN ? AND ? GROUP BY date ORDER BY date";
$stmt = $conn->prepare($trend_sql);
$stmt->execute([$start_date, $end_date]);
$trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dates = []; $daily_inc = []; $daily_exp = [];
foreach($trend_data as $d) {
    $dates[] = $d['date'];
    $daily_inc[] = $d['inc'];
    $daily_exp[] = $d['exp'];
}

// 3. STAFF PERFORMANCE (New Feature)
// Who collected the most money?
$staff_sql = "SELECT u.username, SUM(t.payment) as total, COUNT(t.id) as count 
              FROM transactions t 
              JOIN users u ON t.user_id = u.id 
              WHERE t.cash_in = 1 AND t.date BETWEEN ? AND ? 
              GROUP BY u.username ORDER BY total DESC";
$staff_stmt = $conn->prepare($staff_sql);
$staff_stmt->execute([$start_date, $end_date]);
$staff_stats = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. KPI STATS
$sum_sql = "SELECT 
    SUM(CASE WHEN cash_in=1 THEN payment ELSE 0 END) as total_in,
    SUM(CASE WHEN cash_out=1 THEN payment ELSE 0 END) as total_out,
    COUNT(DISTINCT date) as days_count
    FROM transactions WHERE date BETWEEN ? AND ?";
$stmt = $conn->prepare($sum_sql); $stmt->execute([$start_date, $end_date]);
$sums = $stmt->fetch(PDO::FETCH_ASSOC);

$total_in = $sums['total_in'] ?: 0;
$total_out = $sums['total_out'] ?: 0;
$days = $sums['days_count'] ?: 1;
$margin = ($total_in > 0) ? (($total_in - $total_out) / $total_in) * 100 : 0;
$avg_daily_in = $total_in / $days;

// 5. PAYMENT METHOD PREFERENCE
$method_sql = "SELECT 
    SUM(CASE WHEN cash=1 THEN payment ELSE 0 END) as cash_total,
    SUM(CASE WHEN click=1 THEN payment ELSE 0 END) as click_total,
    SUM(CASE WHEN bank=1 THEN payment ELSE 0 END) as bank_total
    FROM transactions WHERE cash_in=1 AND date BETWEEN ? AND ?";
$stmt = $conn->prepare($method_sql); $stmt->execute([$start_date, $end_date]);
$methods = $stmt->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <title>Analitika — OXFORD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #fafafa; }
        @media print {
            .no-print { display: none !important; }
            body { background-color: white; }
            .shadow-sm, .shadow-md, .shadow-xl { box-shadow: none !important; border: 1px solid #eee; }
        }
    </style>
</head>
<body class="text-zinc-800 pb-20">

    <nav class="sticky top-0 z-30 bg-white/80 backdrop-blur-md border-b border-zinc-200/60 no-print">
        <div class="max-w-7xl mx-auto px-6 h-16 flex justify-between items-center">
            <h1 class="font-bold text-lg text-zinc-900">Batafsil Analitika — OXFORD</h1>
            <div class="flex gap-4">
                <button onclick="window.print()" class="flex items-center gap-2 text-sm font-bold text-indigo-600 bg-indigo-50 px-4 py-2 rounded-xl hover:bg-indigo-100 transition">
                    <i data-lucide="printer" class="w-4 h-4"></i> Chop etish
                </button>
                <a href="hisobot.php" class="text-sm font-semibold text-zinc-500 hover:text-zinc-900 flex items-center gap-2 transition-colors">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i> Orqaga
                </a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-6 py-8 space-y-8">

        <!-- STATS PAGE SUB-NAV -->
        <div class="flex gap-2 bg-white rounded-2xl p-1.5 shadow-sm border border-zinc-100 w-fit">
            <a href="hisobot.php" class="px-5 py-2 rounded-xl text-sm font-bold text-zinc-500 hover:bg-zinc-50 hover:text-zinc-800 transition flex items-center gap-1.5">
                <i data-lucide="file-bar-chart" class="w-3.5 h-3.5"></i> Hisobot
            </a>
            <span class="px-5 py-2 rounded-xl text-sm font-bold bg-indigo-600 text-white shadow flex items-center gap-1.5">
                <i data-lucide="line-chart" class="w-3.5 h-3.5"></i> Grafik Analitika
            </span>
        </div>

        <div class="bg-white p-4 rounded-2xl shadow-sm border border-zinc-100 flex justify-between items-center no-print">
            <h2 class="font-bold text-zinc-700">Davrni tanlang</h2>
            <form class="flex gap-2 items-center">
                <input type="date" name="start" value="<?= $start_date ?>" class="bg-zinc-50 border border-zinc-200 rounded-xl p-2 text-sm font-bold text-zinc-600 outline-none">
                <span class="text-zinc-300">-</span>
                <input type="date" name="end" value="<?= $end_date ?>" class="bg-zinc-50 border border-zinc-200 rounded-xl p-2 text-sm font-bold text-zinc-600 outline-none">
                <button class="bg-zinc-900 text-white p-2 rounded-xl shadow hover:bg-zinc-800 transition"><i data-lucide="search" class="w-4 h-4"></i></button>
            </form>
        </div>

        <div class="hidden print:block text-center mb-8">
            <h1 class="text-2xl font-bold">Moliyaviy Hisobot</h1>
            <p class="text-zinc-500"><?= $start_date ?> dan <?= $end_date ?> gacha</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="bg-white p-6 rounded-3xl border border-emerald-100 shadow-sm">
                <div class="flex justify-between items-start mb-2">
                    <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">Foyda Marjasi</p>
                    <div class="p-2 bg-emerald-50 rounded-lg text-emerald-600"><i data-lucide="percent" class="w-4 h-4"></i></div>
                </div>
                <h3 class="text-2xl font-bold text-zinc-800"><?= number_format($margin, 1) ?>%</h3>
                <p class="text-xs text-zinc-400 mt-1">Sof foyda ulushi</p>
            </div>

            <div class="bg-white p-6 rounded-3xl border border-indigo-100 shadow-sm">
                <div class="flex justify-between items-start mb-2">
                    <p class="text-[10px] font-bold text-indigo-600 uppercase tracking-widest">O'rtacha Kunlik Kirim</p>
                    <div class="p-2 bg-indigo-50 rounded-lg text-indigo-600"><i data-lucide="calendar" class="w-4 h-4"></i></div>
                </div>
                <h3 class="text-2xl font-bold text-zinc-800"><?= number_format($avg_daily_in, 0, '.', ' ') ?></h3>
            </div>

            <div class="bg-white p-6 rounded-3xl border border-blue-100 shadow-sm col-span-2">
                <h4 class="text-xs font-bold text-blue-600 uppercase mb-4">To'lov Turlari (Kirim)</h4>
                <div class="flex items-end gap-4 h-16">
                    <?php 
                        $total_m = $methods['cash_total'] + $methods['click_total'] + $methods['bank_total'];
                        $cash_p = $total_m > 0 ? ($methods['cash_total']/$total_m)*100 : 0;
                        $click_p = $total_m > 0 ? ($methods['click_total']/$total_m)*100 : 0;
                        $bank_p = $total_m > 0 ? ($methods['bank_total']/$total_m)*100 : 0;
                    ?>
                    <div class="flex-1 flex flex-col justify-end gap-1">
                        <div class="bg-emerald-400 rounded-t-lg w-full" style="height: <?= max($cash_p, 5) ?>%"></div>
                        <span class="text-[10px] font-bold text-center">Naqd (<?= round($cash_p) ?>%)</span>
                    </div>
                    <div class="flex-1 flex flex-col justify-end gap-1">
                        <div class="bg-blue-400 rounded-t-lg w-full" style="height: <?= max($click_p, 5) ?>%"></div>
                        <span class="text-[10px] font-bold text-center">Click (<?= round($click_p) ?>%)</span>
                    </div>
                    <div class="flex-1 flex flex-col justify-end gap-1">
                        <div class="bg-purple-400 rounded-t-lg w-full" style="height: <?= max($bank_p, 5) ?>%"></div>
                        <span class="text-[10px] font-bold text-center">Bank (<?= round($bank_p) ?>%)</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-3xl border border-zinc-200 shadow-sm break-inside-avoid">
            <h3 class="font-bold text-zinc-700 mb-6 flex items-center gap-2"><i data-lucide="activity" class="w-5 h-5 text-indigo-500"></i> Kunlik Dinamika (Kirim vs Chiqim)</h3>
            <div class="h-72 w-full">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <div class="bg-white p-6 rounded-3xl border border-zinc-200 shadow-sm break-inside-avoid">
            <h3 class="font-bold text-zinc-700 mb-4 flex items-center gap-2"><i data-lucide="users" class="w-5 h-5 text-indigo-500"></i> Staff Faolligi (Kirim bo'yicha)</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 font-bold">
                        <tr>
                            <th class="px-4 py-3 rounded-l-xl">Staff</th>
                            <th class="px-4 py-3">Tranzaksiyalar soni</th>
                            <th class="px-4 py-3 text-right rounded-r-xl">Jami Kirim Summasi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        <?php foreach($staff_stats as $s): ?>
                        <tr>
                            <td class="px-4 py-3 font-bold text-zinc-700"><?= $s['username'] ?></td>
                            <td class="px-4 py-3 text-zinc-500"><?= $s['count'] ?> ta</td>
                            <td class="px-4 py-3 text-right font-bold text-emerald-600"><?= number_format($s['total'], 0, '.', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 break-inside-avoid">
            
            <div class="bg-white p-6 rounded-3xl border border-zinc-200 shadow-sm flex flex-col">
                <h3 class="font-bold text-zinc-700 mb-4 text-center">Naqd Xarajatlar</h3>
                <div class="flex-grow flex items-center justify-center relative h-64">
                    <?php if(empty($naqd_expenses)): ?>
                        <p class="text-zinc-400 text-sm">Ma'lumot yo'q</p>
                    <?php else: ?>
                        <canvas id="naqdChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white p-6 rounded-3xl border border-zinc-200 shadow-sm flex flex-col">
                <h3 class="font-bold text-zinc-700 mb-4 text-center">Click Xarajatlar</h3>
                <div class="flex-grow flex items-center justify-center relative h-64">
                    <?php if(empty($click_expenses)): ?>
                        <p class="text-zinc-400 text-sm">Ma'lumot yo'q</p>
                    <?php else: ?>
                        <canvas id="clickChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white p-6 rounded-3xl border border-zinc-200 shadow-sm flex flex-col">
                <h3 class="font-bold text-zinc-700 mb-4 text-center">Bank Xarajatlar</h3>
                <div class="flex-grow flex items-center justify-center relative h-64">
                    <?php if(empty($bank_expenses)): ?>
                        <p class="text-zinc-400 text-sm">Ma'lumot yo'q</p>
                    <?php else: ?>
                        <canvas id="bankChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="bg-white p-6 rounded-3xl border border-zinc-200 shadow-sm break-inside-avoid">
            <h3 class="font-bold text-zinc-700 mb-4 text-center">Kirim Manbalari (Kategoriyalar)</h3>
            <div class="flex items-center justify-center relative h-72">
                <?php if(empty($income_cats)): ?>
                    <p class="text-zinc-400 text-sm">Ma'lumot yo'q</p>
                <?php else: ?>
                    <canvas id="incomeChart"></canvas>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();

        const colors = ['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#3b82f6', '#8b5cf6', '#ef4444', '#14b8a6'];

        // Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($dates) ?>,
                datasets: [
                    { label: 'Kirim', data: <?= json_encode($daily_inc) ?>, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.3 },
                    { label: 'Chiqim', data: <?= json_encode($daily_exp) ?>, borderColor: '#f43f5e', backgroundColor: 'rgba(244, 63, 94, 0.1)', fill: true, tension: 0.3 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
        });

        // Pie Charts
        function createPie(id, data) {
            if(!document.getElementById(id)) return;
            const ctx = document.getElementById(id).getContext('2d');
            const labels = data.map(i => i.name);
            const values = data.map(i => i.total);
            new Chart(ctx, {
                type: 'doughnut',
                data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } }
            });
        }

        createPie('naqdChart', <?= json_encode($naqd_expenses) ?>);
        createPie('clickChart', <?= json_encode($click_expenses) ?>);
        createPie('bankChart', <?= json_encode($bank_expenses) ?>);
        createPie('incomeChart', <?= json_encode($income_cats) ?>);
    </script>
</body>
</html>