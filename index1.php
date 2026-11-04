<?php
session_start();
include 'db.php';
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit(); }

// --- LOGIN ---
if (!isset($_SESSION['user_id'])) {
?>
<!DOCTYPE html>
<html lang="uz">
<head><meta charset="utf-8">
    
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kirish - OXFORD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Plus Jakarta Sans',sans-serif;}</style>
</head>
<body class="bg-zinc-50 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-sm p-8 bg-white rounded-2xl shadow-xl border border-zinc-100">
        <div class="text-center mb-8">
    <img 
        src="logo.png" 
        alt="Logo" 
        class="mx-auto h-14 w-14 rounded-full object-cover mb-4"
    >
    <h1 class="text-2xl font-bold text-zinc-900 tracking-tight">OXFORD</h1>
    <p class="text-zinc-500 text-sm mt-2">Tizimga kirish</p>
</div>
        <?php if(isset($_GET['error'])): ?>
            <div class="bg-rose-50 text-rose-600 p-3 rounded-lg text-sm mb-4 text-center border border-rose-100"><?= $_GET['error'] ?></div>
        <?php endif; ?>
        <form action="process.php" method="post" class="space-y-4">
            <input type="hidden" name="action" value="login">
            <input type="text" name="username" placeholder="Login" class="w-full px-4 py-3 bg-zinc-50 border border-zinc-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition-all" required>
            <input type="password" name="password" placeholder="Parol" class="w-full px-4 py-3 bg-zinc-50 border border-zinc-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition-all" required>
            <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200">Kirish</button>
        </form>
    </div>
</body>
</html>
<?php exit(); } 

// --- DASHBOARD ---
date_default_timezone_set('Asia/Tashkent');
$current_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$role = $_SESSION['role'];

$can_edit = true;
$can_delete = false;
if ($role == 'staff') {
    $setting_edit = $conn->query("SELECT value FROM settings WHERE key='staff_can_edit'")->fetchColumn();
    $can_edit = ($setting_edit === false || $setting_edit === '1');
    $setting_del = $conn->query("SELECT value FROM settings WHERE key='staff_can_delete'")->fetchColumn();
    $can_delete = ($setting_del === '1');
} elseif ($role === 'admin') {
    $can_delete = true;
}

$sql = "SELECT t.*, c.name as category_name, u.username as author FROM transactions t LEFT JOIN categories c ON t.category_id = c.id LEFT JOIN users u ON t.user_id = u.id WHERE t.date = :date ORDER BY t.id DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([':date' => $current_date]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cats = $conn->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC);
$employees = $conn->query("SELECT * FROM employees")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'cash' => ['in'=>0, 'out'=>0, 'bal'=>0],
    'click' => ['in'=>0, 'out'=>0, 'bal'=>0],
    'bank' => ['in'=>0, 'out'=>0, 'bal'=>0],
    'total' => ['in'=>0, 'out'=>0, 'bal'=>0]
];

foreach($transactions as $t) {
    $amt = $t['payment'];
    $isIn = $t['cash_in'] == 1;
    $type = 'cash';
    if($t['click']) $type = 'click';
    if($t['bank']) $type = 'bank';

    if($isIn) {
        $stats[$type]['in'] += $amt;
        $stats['total']['in'] += $amt;
        $stats[$type]['bal'] += $amt;
        $stats['total']['bal'] += $amt;
    } else {
        $stats[$type]['out'] += $amt;
        $stats['total']['out'] += $amt;
        $stats[$type]['bal'] -= $amt;
        $stats['total']['bal'] -= $amt;
    }
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    
    <title>Dashboard — OXFORD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Plus Jakarta Sans',sans-serif; background-color: #fafafa;}</style>
</head>
<body class="text-zinc-800 pb-28">

    <nav class="sticky top-0 z-30 bg-white/80 backdrop-blur-md border-b border-zinc-200/60">
        <div class="max-w-5xl mx-auto px-6 h-16 flex justify-between items-center">
            <div class="flex items-center gap-3">
    <img 
        src="logo.png" 
        alt="Kaktus Finance Logo" 
        class="h-8 w-8 rounded-full object-cover border border-zinc-200"
    >
    <span class="font-bold text-lg tracking-tight text-zinc-900">OXFORD<span class="text-indigo-600">Bo'zqal'a</span></span>
    
    <span class="px-2 py-0.5 rounded-full text-[10px] uppercase font-bold tracking-wide <?= $role === 'admin' ? 'bg-indigo-50 text-indigo-700 border border-indigo-100' : 'bg-emerald-50 text-emerald-700 border border-emerald-100' ?>">
        <?= $role ?>
    </span>
</div>
            <div class="flex items-center gap-5">
                <?php if($role === 'admin'): ?>
                    <a href="hisobot.php" class="text-sm font-medium text-zinc-500 hover:text-indigo-600 flex items-center gap-1.5 transition-colors">
                        <i data-lucide="bar-chart-2" class="w-4 h-4"></i> Hisobotlar
                    </a>
                    <a href="admin.php" class="text-sm font-medium text-zinc-500 hover:text-indigo-600 flex items-center gap-1.5 transition-colors">
                        <i data-lucide="settings" class="w-4 h-4"></i> Admin
                    </a>
                <?php endif; ?>
                <a href="?logout=true" class="text-zinc-400 hover:text-rose-500 transition-colors"><i data-lucide="log-out" class="w-5 h-5"></i></a>
            </div>
        </div>
    </nav>

    <div class="max-w-5xl mx-auto px-6 py-8 space-y-8">
        
        <div class="flex justify-center">
            <div class="inline-flex items-center gap-4 bg-white p-1.5 rounded-2xl shadow-sm border border-zinc-200">
                <button onclick="changeDate(-1)" class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-zinc-50 text-zinc-500 transition"><i data-lucide="chevron-left" class="w-5 h-5"></i></button>
                <input type="date" id="datePicker" value="<?= $current_date ?>" class="bg-transparent border-none font-bold text-lg text-zinc-800 outline-none cursor-pointer text-center" onchange="window.location.href='?date='+this.value">
                <button onclick="changeDate(1)" class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-zinc-50 text-zinc-500 transition"><i data-lucide="chevron-right" class="w-5 h-5"></i></button>
            </div>
        </div>

        <div class="bg-white rounded-3xl shadow-[0_2px_15px_-3px_rgba(0,0,0,0.07),0_10px_20px_-2px_rgba(0,0,0,0.04)] border border-zinc-100 overflow-hidden">
            <div class="px-8 py-5 border-b border-zinc-100 flex justify-between items-center bg-zinc-50/30">
                <h3 class="font-bold text-zinc-800 flex items-center gap-2"><i data-lucide="list" class="w-4 h-4 text-zinc-400"></i> Kunlik O'tkazmalar</h3>
                <span class="text-xs font-semibold bg-white border border-zinc-200 text-zinc-500 px-2.5 py-1 rounded-lg"><?= count($transactions) ?> ta</span>
            </div>
            
            <div class="divide-y divide-zinc-50 min-h-[300px]">
                <?php if(count($transactions) > 0): ?>
                    <?php foreach($transactions as $row):
                        $is_in = $row['cash_in'];
                        $color = $is_in ? 'text-emerald-600' : 'text-rose-600';
                        $sign  = $is_in ? '+' : '-';
                        // Payment method badge styles
                        if ($row['bank'])       { $m_label='Bank';  $m_bg='bg-purple-100'; $m_text='text-purple-700'; $m_border='border-purple-200'; $m_icon='building-2'; }
                        elseif ($row['click'])  { $m_label='Click'; $m_bg='bg-blue-100';   $m_text='text-blue-700';   $m_border='border-blue-200';   $m_icon='smartphone'; }
                        else                    { $m_label='Naqd';  $m_bg='bg-amber-100';  $m_text='text-amber-700';  $m_border='border-amber-200';  $m_icon='banknote'; }
                    ?>
                    <div class="flex items-center justify-between px-6 py-4 hover:bg-zinc-50/80 transition-colors group border-l-4 <?= $is_in ? 'border-l-emerald-400' : 'border-l-rose-300' ?>">
                        <div class="flex items-center gap-4 min-w-0">
                            <!-- Type icon -->
                            <div class="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0 <?= $is_in ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-rose-50 text-rose-500 border border-rose-100' ?>">
                                <i data-lucide="<?= $is_in ? 'arrow-down-left' : 'arrow-up-right' ?>" class="w-5 h-5"></i>
                            </div>
                            <!-- Info -->
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold <?= $m_bg ?> <?= $m_text ?> border <?= $m_border ?>">
                                        <i data-lucide="<?= $m_icon ?>" class="w-3 h-3"></i><?= $m_label ?>
                                    </span>
                                    <?php if($row['is_modified']): ?>
                                        <span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded-lg border border-amber-200">Tahrirlangan</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                    <?php if($row['author']): ?><span class="text-[11px] font-medium text-zinc-500"><?= htmlspecialchars($row['author']) ?></span><span class="text-zinc-300 text-[10px]">•</span><?php endif; ?>
                                    <?php if($row['comment']): ?><span class="text-[11px] text-zinc-400 italic truncate max-w-[220px]"><?= htmlspecialchars($row['comment']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0 ml-4">
                            <div class="font-bold <?= $color ?> text-base tracking-tight"><?= $sign . number_format($row['payment'], 0, '.', ' ') ?></div>
                            <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                <a href="view_history.php?id=<?= $row['id'] ?>" class="text-zinc-300 hover:text-indigo-500 transition-colors"><i data-lucide="history" class="w-4 h-4"></i></a>
                                <?php if($can_edit): ?>
                                    <a href="edit_transaction.php?id=<?= $row['id'] ?>" class="text-zinc-300 hover:text-blue-500 transition-colors"><i data-lucide="pencil" class="w-4 h-4"></i></a>
                                <?php endif; ?>
                                <?php if($can_delete): ?>
                                    <a href="process.php?action=delete_transaction&id=<?= $row['id'] ?>" onclick="return confirm('O\'chirilsinmi?')" class="text-zinc-300 hover:text-rose-500 transition-colors"><i data-lucide="trash-2" class="w-4 h-4"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-20">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-zinc-50 text-zinc-300 mb-4"><i data-lucide="inbox" class="w-8 h-8"></i></div>
                        <p class="text-zinc-400 font-medium text-sm">Bugun uchun o'tkazmalar mavjud emas.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-zinc-50/80 border-t border-zinc-100 p-8">
                <div class="flex items-center gap-2 mb-6">
                    <div class="p-1.5 bg-indigo-100 text-indigo-700 rounded-lg"><i data-lucide="pie-chart" class="w-4 h-4"></i></div>
                    <h4 class="text-sm font-bold text-zinc-700 uppercase tracking-wide">Moliyaviy Statistika (<?= $current_date ?>)</h4>
                </div>

                <div class="overflow-hidden rounded-xl border border-zinc-200">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-zinc-100 text-xs font-bold uppercase text-zinc-500">
                            <tr>
                                <th class="px-6 py-3">Usul</th>
                                <th class="px-6 py-3 text-emerald-600">Kirim</th>
                                <th class="px-6 py-3 text-rose-600">Chiqim</th>
                                <th class="px-6 py-3 text-indigo-600">Qoldiq</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white">
                            <tr>
                                <td class="px-6 py-3 font-bold text-zinc-700">Naqd</td>
                                <td class="px-6 py-3 text-emerald-600 font-medium"><?= number_format($stats['cash']['in'], 0, '.', ' ') ?></td>
                                <td class="px-6 py-3 text-rose-600 font-medium"><?= number_format($stats['cash']['out'], 0, '.', ' ') ?></td>
                                <td class="px-6 py-3 font-bold text-zinc-800"><?= number_format($stats['cash']['bal'], 0, '.', ' ') ?></td>
                            </tr>
                            <tr>
                                <td class="px-6 py-3 font-bold text-zinc-700">Click</td>
                                <td class="px-6 py-3 text-emerald-600 font-medium"><?= number_format($stats['click']['in'], 0, '.', ' ') ?></td>
                                <td class="px-6 py-3 text-rose-600 font-medium"><?= number_format($stats['click']['out'], 0, '.', ' ') ?></td>
                                <td class="px-6 py-3 font-bold text-zinc-800"><?= number_format($stats['click']['bal'], 0, '.', ' ') ?></td>
                            </tr>
                            <tr>
                                <td class="px-6 py-3 font-bold text-zinc-700">Bank</td>
                                <td class="px-6 py-3 text-emerald-600 font-medium"><?= number_format($stats['bank']['in'], 0, '.', ' ') ?></td>
                                <td class="px-6 py-3 text-rose-600 font-medium"><?= number_format($stats['bank']['out'], 0, '.', ' ') ?></td>
                                <td class="px-6 py-3 font-bold text-zinc-800"><?= number_format($stats['bank']['bal'], 0, '.', ' ') ?></td>
                            </tr>
                            <tr class="bg-zinc-50 border-t border-zinc-200">
                                <td class="px-6 py-4 font-bold text-zinc-900 uppercase text-xs">Jami</td>
                                <td class="px-6 py-4 font-bold text-emerald-700 text-lg"><?= number_format($stats['total']['in'], 0, '.', ' ') ?></td>
                                <td class="px-6 py-4 font-bold text-rose-700 text-lg"><?= number_format($stats['total']['out'], 0, '.', ' ') ?></td>
                                <td class="px-6 py-4 font-bold text-indigo-700 text-lg"><?= number_format($stats['total']['bal'], 0, '.', ' ') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <button onclick="openModal()" class="fixed bottom-10 right-10 w-16 h-16 bg-indigo-600 text-white rounded-2xl shadow-[0_8px_30px_rgb(79,70,229,0.3)] flex items-center justify-center hover:scale-105 transition-transform hover:bg-indigo-700"><i data-lucide="plus" class="w-8 h-8"></i></button>

    <div id="addModal" class="fixed inset-0 z-50 hidden transition-all duration-200">
        <div class="fixed inset-0 bg-zinc-900/40 backdrop-blur-sm" onclick="closeModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white w-full max-w-md p-8 rounded-3xl shadow-2xl border border-zinc-100 transform scale-100 transition-all">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-bold text-xl text-zinc-800">Yangi O'tkazma</h3>
                    <button onclick="closeModal()" class="text-zinc-400 hover:text-zinc-600 transition-colors"><i data-lucide="x" class="w-6 h-6"></i></button>
                </div>
                <form action="process.php" method="post" class="space-y-5">
                    <input type="hidden" name="action" value="add_transaction">
                    
                    <div>
                        <input type="text" name="payment" id="paymentInput" placeholder="0" class="w-full p-4 bg-zinc-50 border border-zinc-200 rounded-2xl font-bold text-2xl text-zinc-800 outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all text-center" required autocomplete="off">
                        <p class="text-center text-xs text-zinc-400 mt-2 font-medium uppercase tracking-wide">Summani kiriting</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <select name="type" class="p-3 bg-white border border-zinc-200 rounded-xl text-sm font-semibold text-zinc-600 outline-none focus:border-indigo-500" onchange="filterCats(this.value)">
                            <option value="income">Kirim (+)</option>
                            <option value="expense">Chiqim (-)</option>
                        </select>
                        <select name="method" class="p-3 bg-white border border-zinc-200 rounded-xl text-sm font-semibold text-zinc-600 outline-none focus:border-indigo-500">
                            <option value="cash">Naqd</option>
                            <option value="click">Click</option>
                            <option value="bank">Bank</option>
                        </select>
                    </div>

                    <div class="space-y-2">
                        <label class="text-xs font-bold text-zinc-400 uppercase ml-1">Kategoriya</label>
                        <select name="category_id" id="catSelect" class="w-full p-3.5 bg-white border border-zinc-200 rounded-xl text-sm font-medium outline-none focus:border-indigo-500" onchange="checkSalary(this)">
                            <?php foreach($cats as $c): ?>
                                <option value="<?= $c['id'] ?>" data-type="<?= $c['type'] ?>" data-name="<?= strtolower($c['name']) ?>"><?= $c['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="empSelectDiv" class="hidden space-y-2 animate-fade-in">
                        <label class="text-xs font-bold text-zinc-400 uppercase ml-1">Xodimni tanlang</label>
                        <select name="employee_id" class="w-full p-3.5 bg-white border border-zinc-200 rounded-xl text-sm font-medium outline-none focus:border-indigo-500">
                            <option value="">-- Tanlang --</option>
                            <?php foreach($employees as $e): ?>
                                <option value="<?= $e['id'] ?>"><?= $e['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <textarea name="comment" rows="2" placeholder="Izoh..." class="w-full p-4 bg-zinc-50 border border-zinc-200 rounded-2xl text-sm outline-none focus:border-indigo-500 transition-all resize-none"></textarea>
                    
                    <button type="submit" class="w-full py-4 bg-indigo-600 text-white rounded-2xl font-bold text-lg hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition-all">Saqlash</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        function changeDate(d) { let date = new Date(document.getElementById('datePicker').value); date.setDate(date.getDate()+d); window.location.href='?date='+date.toISOString().split('T')[0]; }
        function openModal() { document.getElementById('addModal').classList.remove('hidden'); document.getElementById('paymentInput').focus(); }
        function closeModal() { document.getElementById('addModal').classList.add('hidden'); }
        document.addEventListener('keydown', e => { if(e.key === "Escape") closeModal(); });
        
        const payInput = document.getElementById('paymentInput');
        payInput.addEventListener('input', function(e) {
            let val = this.value.replace(/\D/g, '');
            this.value = val ? parseInt(val).toLocaleString('ru-RU').replace(/,/g, ' ') : '';
        });

        function filterCats(type) {
            const opts = document.querySelectorAll('#catSelect option');
            opts.forEach(o => o.style.display = (o.dataset.type === type) ? 'block' : 'none');
            const first = document.querySelector(`#catSelect option[data-type="${type}"]`);
            if(first) { first.selected = true; checkSalary(first.parentNode); }
        }
        
        function checkSalary(select) {
            const opt = select.options[select.selectedIndex];
            const name = opt ? opt.getAttribute('data-name') : '';
            const div = document.getElementById('empSelectDiv');
            if(name && (name.includes('salary') || name.includes('oylik') || name.includes('maosh'))) {
                div.classList.remove('hidden');
            } else {
                div.classList.add('hidden');
            }
        }
        filterCats('income');
    </script>
</body>
</html>