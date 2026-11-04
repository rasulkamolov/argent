<?php
session_start();
include 'db.php';
if (!isset($_SESSION['user_id'])) die("Kirish taqiqlangan");

if ($_SESSION['role'] !== 'admin') {
    $can_edit = $conn->query("SELECT value FROM settings WHERE key='staff_can_edit'")->fetchColumn();
    if ($can_edit === '0') die("<h2>Ruxsat yo'q</h2><p>Admin tahrirlashni o'chirib qo'ygan.</p><a href='index.php'>Orqaga</a>");
}

$id = $_GET['id'];
$t = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
$t->execute([$id]);
$trans = $t->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Clean number manually here or include the helper function
    $raw_pay = $_POST['payment'];
    $pay = floatval(preg_replace('/[^\d.]/', '', $raw_pay)); // Same fix as process.php
    
    $com = $_POST['comment'];
    $method = $_POST['method'];

    $cash = ($method=='cash')?1:0; 
    $click = ($method=='click')?1:0; 
    $bank = ($method=='bank')?1:0;

    // Log history
    if($pay != $trans['payment']) {
         $conn->prepare("INSERT INTO transaction_history (transaction_id, user_id, field_name, old_value, new_value, changed_at) VALUES (?, ?, 'Summa', ?, ?, datetime('now','localtime'))")->execute([$id, $_SESSION['user_id'], $trans['payment'], $pay]);
    }

    $sql = "UPDATE transactions SET payment=?, comment=?, cash=?, click=?, bank=?, is_modified=1 WHERE id=?";
    $conn->prepare($sql)->execute([$pay, $com, $cash, $click, $bank, $id]);
    
    header("Location: index.php?date=".$trans['date']);
}

$curMethod = 'cash';
if($trans['click']) $curMethod = 'click';
if($trans['bank']) $curMethod = 'bank';
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <title>Tahrirlash</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Plus Jakarta Sans',sans-serif; background-color: #fafafa;}</style>
</head>
<body class="flex items-center justify-center min-h-screen text-zinc-800">
    <form method="post" class="w-full max-w-sm p-8 bg-white rounded-3xl shadow-xl border border-zinc-100">
        <h2 class="font-bold text-xl mb-6 text-zinc-900">Tahrirlash</h2>
        <div class="space-y-4">
            <div>
                <label class="text-xs font-bold text-zinc-400 uppercase">Summa</label>
                <input type="text" id="payInput" name="payment" value="<?= number_format($trans['payment'], 0, '.', ' ') ?>" class="w-full p-4 bg-zinc-50 rounded-xl font-bold text-xl outline-none border border-zinc-200 focus:border-indigo-500 text-zinc-900">
            </div>
            <div>
                <label class="text-xs font-bold text-zinc-400 uppercase">Usul</label>
                <select name="method" class="w-full p-3 bg-zinc-50 border border-zinc-200 rounded-xl font-bold text-sm text-zinc-700 outline-none focus:border-indigo-500">
                    <option value="cash" <?= $curMethod=='cash'?'selected':'' ?>>Naqd</option>
                    <option value="click" <?= $curMethod=='click'?'selected':'' ?>>Click</option>
                    <option value="bank" <?= $curMethod=='bank'?'selected':'' ?>>Bank</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-zinc-400 uppercase">Izoh</label>
                <textarea name="comment" class="w-full p-4 bg-zinc-50 rounded-xl outline-none border border-zinc-200 focus:border-indigo-500 text-sm text-zinc-700"><?= $trans['comment'] ?></textarea>
            </div>
            <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-3 rounded-xl font-bold shadow-lg shadow-indigo-200 transition-all">Saqlash</button>
            <a href="index.php?date=<?= $trans['date'] ?>" class="block text-center text-zinc-400 text-sm mt-3 hover:text-zinc-600 transition">Bekor qilish</a>
        </div>
    </form>
    <script>
        document.getElementById('payInput').addEventListener('input', function() {
            // Allow numbers and spaces, filter out others
            let val = this.value.replace(/\D/g, '');
            this.value = val ? parseInt(val).toLocaleString('ru-RU').replace(/,/g, ' ') : '';
        });
    </script>
</body>
</html>