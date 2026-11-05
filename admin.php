<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/report_lib.php';
require_once __DIR__ . '/proofs.php';
require_admin();
define('ADMIN_PAGE', 1);

$today = date('Y-m-d'); $month_start = date('Y-m-01'); $month_end = date('Y-m-t');
$B = branches_all();
$ov = network_overview($today);

/* users */
$users = db()->query("SELECT u.*, b.name AS branch_name, b.color AS branch_color,
        (SELECT COUNT(*) FROM transactions t WHERE t.user_id = u.id) AS tx_count,
        (SELECT MAX(t.date) FROM transactions t WHERE t.user_id = u.id) AS last_tx
        FROM users u LEFT JOIN branches b ON b.id = u.branch_id
        ORDER BY (u.role = 'admin') DESC, u.is_active DESC, b.sort_order, u.username")->fetchAll();

/* employees (+ paid this month) */
$st = db()->prepare("SELECT e.*, b.name AS branch_name, b.color AS branch_color,
        COALESCE((SELECT SUM(t.payment) FROM transactions t WHERE t.employee_id = e.id AND t.cash_out = 1 AND t.date BETWEEN ? AND ?), 0) AS paid
        FROM employees e LEFT JOIN branches b ON b.id = e.branch_id ORDER BY b.sort_order, e.name");
$st->execute([$month_start, $month_end]);
$employees = $st->fetchAll();

/* categories */
$categories = db()->query("SELECT c.*, b.name AS branch_name,
        (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.id) AS uses
        FROM categories c LEFT JOIN branches b ON b.id = c.branch_id ORDER BY c.type DESC, c.name")->fetchAll();

/* journal (audit log) with filters + paging */
$jp = max(1, (int)($_GET['jp'] ?? 1)); $jlimit = 25;
$jb = (int)($_GET['jb'] ?? 0); $ja = preg_replace('/[^a-z_.]/i', '', (string)($_GET['ja'] ?? ''));
$jw = ['1=1']; $jparams = [];
if ($jb) { $jw[] = 'a.branch_id = ?'; $jparams[] = $jb; }
if ($ja !== '') { $jw[] = 'a.action LIKE ?'; $jparams[] = $ja . '%'; }
$jc = db()->prepare("SELECT COUNT(*) FROM audit_log a WHERE " . implode(' AND ', $jw)); $jc->execute($jparams); $jtotal = (int)$jc->fetchColumn();
$jpages = max(1, (int)ceil($jtotal / $jlimit)); $jp = min($jp, $jpages);
$js = db()->prepare("SELECT a.*, b.name AS branch_name FROM audit_log a LEFT JOIN branches b ON b.id = a.branch_id WHERE " . implode(' AND ', $jw) . " ORDER BY a.id DESC LIMIT $jlimit OFFSET " . (($jp - 1) * $jlimit));
$js->execute($jparams); $journal = $js->fetchAll();

/* alerts */
$alerts = [];
$ap = db()->prepare("SELECT password FROM users WHERE id = ?"); $ap->execute([auth_user()['id']]);
if (pw_verify('admin0007', (string)$ap->fetchColumn())) $alerts[] = ['danger', "Admin paroli hali standart (admin0007). Xavfsizlik uchun hoziroq almashtiring.", 'profile.php', "Parolni o'zgartirish"];
foreach ($B as $b) { if ($b['is_active'] && empty($ov[$b['id']]['cashiers']) && empty($ov[$b['id']]['managers'])) $alerts[] = ['warn', "«" . $b['name'] . "» filialiga kassir biriktirilmagan.", '#users', "Kassir qo'shish"]; }
foreach ($B as $b) { if ($b['is_active'] && empty($ov[$b['id']]['tracked'])) { $alerts[] = ['warn', "«" . $b['name'] . "» filialida kassa qoldig'i sozlanmagan: kassadagi pul va «Kassani sanash» ishlamaydi.", '#branches', "Sozlash"]; } }
$expo = web_exposure_check();
if (!empty($expo)) $alerts[] = ['danger', "XAVFLI: internetdan ochiq yuklab olish mumkin: " . implode('; ', $expo) . ". Hostingda .htaccess fayli ishlayotganini tekshiring (yoki faylni public_html dan tashqariga ko'chiring).", 'admin.php#settings', "Batafsil"];
$np30 = db()->prepare("SELECT COUNT(*) FROM transactions t WHERE t.cash_in = 1 AND " . proof_missing_sql('t') . " AND t.date >= ?"); $np30->execute([date('Y-m-d', strtotime('-30 days'))]); $np30 = (int)$np30->fetchColumn();
if ($np30 > 0) $alerts[] = ['warn', "Oxirgi 30 kunda $np30 ta Click/Bank kirim uchun to'lov skrinshoti yo'q.", 'hisobot.php?proof=missing&branch=0&start=' . date('Y-m-d', strtotime('-30 days')) . '&end=' . date('Y-m-d'), "Ko'rish"];
$bk = glob(__DIR__ . '/data/backups/backup_*.sqlite') ?: []; sort($bk);
$lastBk = $bk ? filemtime(end($bk)) : 0;
if (!$lastBk || $lastBk < strtotime('-7 days')) $alerts[] = ['warn', $lastBk ? "Oxirgi zaxira nusxa " . floor((time() - $lastBk) / 86400) . " kun oldin olingan." : "Hali zaxira nusxa olinmagan.", '#settings', "Zaxira olish"];

$jcounts = ['users' => count($users), 'branches' => count($B)];

ui_head('Admin panel');
ui_start('admin');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Admin panel</h1><p class="page-sub">Filiallar, kassirlar, xodimlar, kategoriyalar, jurnal va sozlamalar</p></div>
    <div class="row gap-2 no-print row-wrap">
      <a class="btn" href="filiallar.php"><?= icon('store', 'i-sm') ?> Filiallar</a>
      <a class="btn" href="hisobot.php"><?= icon('file-text', 'i-sm') ?> Hisobotlar</a>
      <a class="btn btn-primary" href="import.php"><?= icon('upload', 'i-sm') ?> Import</a>
    </div>
  </div>

  <div data-tabs>
    <div class="tabs" role="tablist" aria-label="Admin bo'limlari">
      <button class="tab" role="tab" data-tab="overview" id="t-overview" aria-controls="p-overview"><?= icon('layout-dashboard', 'i-sm') ?> Umumiy</button>
      <button class="tab" role="tab" data-tab="branches" id="t-branches" aria-controls="p-branches"><?= icon('store', 'i-sm') ?> Filiallar <span class="tab-count"><?= count($B) ?></span></button>
      <button class="tab" role="tab" data-tab="users" id="t-users" aria-controls="p-users"><?= icon('users', 'i-sm') ?> Foydalanuvchilar <span class="tab-count"><?= count($users) ?></span></button>
      <button class="tab" role="tab" data-tab="employees" id="t-employees" aria-controls="p-employees"><?= icon('briefcase', 'i-sm') ?> Xodimlar <span class="tab-count"><?= count($employees) ?></span></button>
      <button class="tab" role="tab" data-tab="categories" id="t-categories" aria-controls="p-categories"><?= icon('tags', 'i-sm') ?> Kategoriyalar <span class="tab-count"><?= count($categories) ?></span></button>
      <button class="tab" role="tab" data-tab="journal" id="t-journal" aria-controls="p-journal"><?= icon('history', 'i-sm') ?> Jurnal <span class="tab-count"><?= fmt($jtotal) ?></span></button>
      <button class="tab" role="tab" data-tab="settings" id="t-settings" aria-controls="p-settings"><?= icon('settings', 'i-sm') ?> Sozlamalar</button>
    </div>

    <div class="tabpanel stack" role="tabpanel" id="p-overview" aria-labelledby="t-overview"><?php include __DIR__ . '/admin_parts/overview.php'; ?></div>
    <div class="tabpanel stack" role="tabpanel" id="p-branches" aria-labelledby="t-branches" hidden><?php include __DIR__ . '/admin_parts/branches.php'; ?></div>
    <div class="tabpanel stack" role="tabpanel" id="p-users" aria-labelledby="t-users" hidden><?php include __DIR__ . '/admin_parts/users.php'; ?></div>
    <div class="tabpanel stack" role="tabpanel" id="p-employees" aria-labelledby="t-employees" hidden><?php include __DIR__ . '/admin_parts/employees.php'; ?></div>
    <div class="tabpanel stack" role="tabpanel" id="p-categories" aria-labelledby="t-categories" hidden><?php include __DIR__ . '/admin_parts/categories.php'; ?></div>
    <div class="tabpanel stack" role="tabpanel" id="p-journal" aria-labelledby="t-journal" hidden><?php include __DIR__ . '/admin_parts/journal.php'; ?></div>
    <div class="tabpanel stack" role="tabpanel" id="p-settings" aria-labelledby="t-settings" hidden><?php include __DIR__ . '/admin_parts/settings.php'; ?></div>
  </div>
</div>
<?php
ob_start(); include __DIR__ . '/admin_parts/dialogs.php'; $dialogs = ob_get_clean();

$js = '<script>' . file_get_contents(__DIR__ . '/admin_parts/admin.js') . '</script>';
ui_end(['active' => 'admin', 'after' => $dialogs, 'scripts' => $js]);
