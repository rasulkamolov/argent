<?php
session_start();
include 'db.php';
if (!isset($_SESSION['user_id'])) die("Kirish taqiqlangan");
$id = $_GET['id'];
$hist = $conn->prepare("SELECT h.*, u.username FROM transaction_history h LEFT JOIN users u ON h.user_id=u.id WHERE transaction_id=? ORDER BY changed_at DESC");
$hist->execute([$id]);
$logs = $hist->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <title>Tarix</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Plus Jakarta Sans',sans-serif; background-color: #fafafa;}</style>
</head>
<body class="text-zinc-800 p-8">
    <div class="max-w-xl mx-auto">
        <button onclick="history.back()" class="inline-flex items-center gap-2 text-sm font-bold text-zinc-500 hover:text-zinc-900 mb-6 transition"><i data-lucide="arrow-left" class="w-4 h-4"></i> Orqaga</button>
        <h3 class="font-bold text-zinc-800 mb-4 text-xl">O'zgarishlar Tarixi</h3>
        <?php if(count($logs)>0): ?>
        <div class="bg-white rounded-3xl shadow-sm border border-zinc-200 overflow-hidden">
            <?php foreach($logs as $l): ?>
            <div class="p-5 border-b border-zinc-50 last:border-0 hover:bg-zinc-50/50 transition">
                <div class="flex justify-between items-center mb-1">
                    <span class="font-bold text-sm text-zinc-900"><?= $l['username'] ?></span>
                    <span class="text-xs text-zinc-400"><?= $l['changed_at'] ?></span>
                </div>
                <div class="text-xs text-zinc-500 mb-2">O'zgartirildi: <span class="font-bold text-zinc-700"><?= $l['field_name'] ?></span></div>
                <div class="flex gap-2 items-center text-sm">
                    <span class="bg-rose-50 text-rose-600 px-2 py-1 rounded-lg line-through decoration-rose-500/50 decoration-2"><?= $l['old_value'] ?></span>
                    <i data-lucide="arrow-right" class="w-4 h-4 text-zinc-300"></i>
                    <span class="bg-emerald-50 text-emerald-600 px-2 py-1 rounded-lg font-bold border border-emerald-100"><?= $l['new_value'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="text-center py-12 bg-white rounded-3xl border border-zinc-200">
                <p class="text-zinc-400">Tarix topilmadi.</p>
            </div>
        <?php endif; ?>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>