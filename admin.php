<?php
require_once 'db.php';
require_once 'layout.php';
require_admin();

$month_start = date('Y-m-01'); $month_end = date('Y-m-t');

$staff = $conn->query("SELECT u.*, (SELECT COUNT(*) FROM transactions t WHERE t.user_id=u.id) AS tx_count, (SELECT MAX(t.date) FROM transactions t WHERE t.user_id=u.id) AS last_date FROM users u WHERE u.role='staff' ORDER BY u.is_active DESC, u.username")->fetchAll(PDO::FETCH_ASSOC);
$st = $conn->prepare("SELECT e.*, COALESCE((SELECT SUM(t.payment) FROM transactions t WHERE t.employee_id=e.id AND t.cash_out=1 AND t.date BETWEEN ? AND ?),0) AS paid FROM employees e ORDER BY e.name");
$st->execute([$month_start, $month_end]);
$employees = $st->fetchAll(PDO::FETCH_ASSOC);
$categories = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM transactions t WHERE t.category_id=c.id) AS uses FROM categories c ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);

$se = $conn->query("SELECT value FROM settings WHERE key='staff_can_edit'")->fetchColumn();
$staff_can_edit = ($se === false || $se === '1');
$staff_can_delete = ($conn->query("SELECT value FROM settings WHERE key='staff_can_delete'")->fetchColumn() === '1');

$m = $conn->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN cash_in=1 THEN payment END),0) AS inc, COALESCE(SUM(CASE WHEN cash_in<>1 THEN payment END),0) AS exp FROM transactions WHERE date BETWEEN ? AND ?");
$m->execute([$month_start, $month_end]); $mon = $m->fetch(PDO::FETCH_ASSOC);

$recent = $conn->query("SELECT t.*, c.name AS cat, u.username AS author FROM transactions t LEFT JOIN categories c ON t.category_id=c.id LEFT JOIN users u ON t.user_id=u.id ORDER BY t.id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$edits = $conn->query("SELECT h.*, u.username FROM transaction_history h LEFT JOIN users u ON h.user_id=u.id ORDER BY h.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$active_staff = count(array_filter($staff, fn($s) => $s['is_active']));
$fund = array_sum(array_column($employees, 'salary'));
$paid_total = 0; foreach ($employees as $e_) $paid_total += min((float)$e_['paid'], (float)$e_['salary']);
$cat_in = array_values(array_filter($categories, fn($c) => $c['type'] === 'income'));
$cat_out = array_values(array_filter($categories, fn($c) => $c['type'] === 'expense'));
$default_pw = $conn->prepare("SELECT COUNT(*) FROM users WHERE id=? AND password='admin0007'"); $default_pw->execute([$_SESSION['user_id']]);
$default_pw = (bool)$default_pw->fetchColumn();
$sample_count = (int)$conn->query("SELECT COUNT(*) FROM transactions WHERE comment LIKE '[NAMUNA]%'")->fetchColumn();

ui_head('Admin panel');
ui_start('admin');
?>
<div class="page">
  <div class="page-head">
    <div><h1 class="page-title">Admin panel</h1><p class="page-sub">Foydalanuvchilar, xodimlar, kategoriyalar va sozlamalar</p></div>
    <div class="row gap-2 no-print row-wrap">
      <a class="btn" href="hisobot.php"><?= icon('file-text', 'i-sm') ?> Hisobotlar</a>
      <a class="btn btn-soft" href="analitika.php"><?= icon('chart-line', 'i-sm') ?> Analitika</a>
    </div>
  </div>

  <div data-tabs>
    <div class="tabs" role="tablist" aria-label="Admin bo'limlari">
      <button class="tab" role="tab" data-tab="overview" id="t-overview" aria-controls="p-overview"><?= icon('layout-dashboard', 'i-sm') ?> Umumiy</button>
      <button class="tab" role="tab" data-tab="staff" id="t-staff" aria-controls="p-staff"><?= icon('users', 'i-sm') ?> Foydalanuvchilar <span class="tab-count"><?= count($staff) ?></span></button>
      <button class="tab" role="tab" data-tab="employees" id="t-employees" aria-controls="p-employees"><?= icon('briefcase', 'i-sm') ?> Xodimlar <span class="tab-count"><?= count($employees) ?></span></button>
      <button class="tab" role="tab" data-tab="categories" id="t-categories" aria-controls="p-categories"><?= icon('tags', 'i-sm') ?> Kategoriyalar <span class="tab-count"><?= count($categories) ?></span></button>
      <button class="tab" role="tab" data-tab="settings" id="t-settings" aria-controls="p-settings"><?= icon('settings', 'i-sm') ?> Sozlamalar</button>
    </div>

    <!-- ================= OVERVIEW ================= -->
    <div class="tabpanel stack" role="tabpanel" id="p-overview" aria-labelledby="t-overview">
      <?php if ($default_pw): ?>
      <div class="alert error" role="alert"><?= icon('triangle-alert') ?>
        <div class="grow">Admin paroli hali standart holatda. Xavfsizlik uchun uni hoziroq almashtiring.
          <div class="mt-2"><a class="btn btn-sm btn-danger" href="#settings" onclick="document.getElementById('t-settings').click();return false">Parolni o'zgartirish</a></div></div>
      </div>
      <?php endif; ?>

      <section class="grid g-2 lg-g-4" aria-label="Umumiy ko'rsatkichlar">
        <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Foydalanuvchilar</span><span class="icon-tile primary" style="width:32px;height:32px;border-radius:10px"><?= icon('users', 'i-sm') ?></span></div>
          <div class="kpi-value num"><?= count($staff) ?></div><div class="kpi-foot"><?= $active_staff ?> faol · <?= count($staff) - $active_staff ?> bloklangan</div></div>
        <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Oylik fondi</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('hand-coins', 'i-sm') ?></span></div>
          <div class="kpi-value num"><?= fmt_short($fund) ?></div><div class="kpi-foot"><?= count($employees) ?> ta xodim · to'langan <?= fmt_short($paid_total) ?></div></div>
        <div class="card kpi"><div class="kpi-top"><span class="kpi-label"><?= e(uz_month((int)date('n'))) ?> kirimi</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('trending-up', 'i-sm') ?></span></div>
          <div class="kpi-value num text-in">+<?= fmt_short($mon['inc']) ?></div><div class="kpi-foot"><?= $mon['n'] ?> ta tranzaksiya</div></div>
        <div class="card kpi"><div class="kpi-top"><span class="kpi-label"><?= e(uz_month((int)date('n'))) ?> chiqimi</span><span class="icon-tile out" style="width:32px;height:32px;border-radius:10px"><?= icon('trending-down', 'i-sm') ?></span></div>
          <div class="kpi-value num text-out">−<?= fmt_short($mon['exp']) ?></div><div class="kpi-foot">Foyda: <?= e(fmt_short($mon['inc'] - $mon['exp'])) ?></div></div>
      </section>

      <div class="grid lg-g-2">
        <section class="card clip" aria-labelledby="raTitle">
          <div class="card-head"><h2 class="card-title" id="raTitle"><?= icon('clock', 'i-sm') ?> So'nggi tranzaksiyalar</h2><a class="btn btn-ghost btn-sm" href="hisobot.php">Barchasi <?= icon('arrow-right', 'i-xs') ?></a></div>
          <div class="list">
            <?php foreach ($recent as $r): $in = $r['cash_in'] == 1; ?>
            <div class="tx" style="padding-top:12px;padding-bottom:12px">
              <span class="icon-tile <?= $in ? 'in' : 'out' ?>" style="width:36px;height:36px"><?= icon($in ? 'arrow-down-left' : 'arrow-up-right', 'i-sm') ?></span>
              <div class="tx-main">
                <div class="row between" style="gap:8px"><span class="tx-title" style="font-size:14px"><?= e($r['cat'] ?: ($in ? 'Kirim' : 'Chiqim')) ?></span><span class="tx-amt num <?= $in ? 'text-in' : 'text-out' ?>" style="font-size:15px"><?= $in ? '+' : '−' ?><?= fmt($r['payment']) ?></span></div>
                <div class="tx-meta"><span><?= e($r['author']) ?></span><span><?= e(date('d.m.Y', strtotime($r['date']))) ?></span></div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$recent): ?><div class="empty" style="padding:28px"><span class="fs-sm">Hali tranzaksiya yo'q</span></div><?php endif; ?>
          </div>
        </section>
        <section class="card clip" aria-labelledby="edTitle">
          <div class="card-head"><h2 class="card-title" id="edTitle"><?= icon('history', 'i-sm') ?> So'nggi tahrirlar</h2></div>
          <div class="list">
            <?php foreach ($edits as $h): ?>
            <a class="tx" href="view_history.php?id=<?= (int)$h['transaction_id'] ?>" style="padding-top:12px;padding-bottom:12px">
              <span class="avatar avatar-sm"><?= e(mb_substr((string)$h['username'], 0, 1)) ?></span>
              <div class="tx-main">
                <div class="fw-6 fs-md"><?= e($h['username']) ?> · <?= e($h['field_name']) ?></div>
                <div class="tx-meta"><span style="text-decoration:line-through"><?= e($h['old_value']) ?></span><?= icon('arrow-right', 'i-xs') ?><b class="text-2"><?= e($h['new_value']) ?></b></div>
              </div>
              <time class="fs-xs text-3 num"><?= e(substr($h['changed_at'], 5, 11)) ?></time>
            </a>
            <?php endforeach; ?>
            <?php if (!$edits): ?><div class="empty" style="padding:28px"><span class="fs-sm">Tahrirlar yo'q</span></div><?php endif; ?>
          </div>
        </section>
      </div>
    </div>

    <!-- ================= STAFF ================= -->
    <div class="tabpanel stack" role="tabpanel" id="p-staff" aria-labelledby="t-staff" hidden>
      <div class="row between row-wrap">
        <div class="input-icon grow" style="max-width:360px"><?= icon('search') ?><input class="input input-sm" style="padding-left:42px" type="search" placeholder="Foydalanuvchini qidirish…" aria-label="Qidirish" data-filter-list="#staffBody" data-filter-empty="#staffNone"></div>
        <button class="btn btn-primary" type="button" id="newStaff"><?= icon('user-plus', 'i-sm') ?> Yangi foydalanuvchi</button>
      </div>
      <section class="card clip">
        <div class="table-wrap">
          <table class="table table-stack">
            <thead><tr><th>Foydalanuvchi</th><th>Holati</th><th class="r">Tranzaksiya</th><th>Oxirgi faollik</th><th class="r">Amallar</th></tr></thead>
            <tbody id="staffBody">
            <?php foreach ($staff as $u): ?>
              <tr data-search="<?= e(mb_strtolower($u['username'])) ?>">
                <td class="cell-title"><span class="row gap-2"><span class="avatar"><?= e(mb_substr($u['username'], 0, 1)) ?></span><b><?= e($u['username']) ?></b></span></td>
                <td data-label="Holati"><?= $u['is_active'] ? '<span class="badge badge-in">● Faol</span>' : '<span class="badge badge-danger">● Bloklangan</span>' ?></td>
                <td class="r num" data-label="Tranzaksiya"><?= (int)$u['tx_count'] ?> ta</td>
                <td class="text-2" data-label="Oxirgi faollik"><?= $u['last_date'] ? e(date('d.m.Y', strtotime($u['last_date']))) : '—' ?></td>
                <td class="r cell-actions">
                  <div class="row gap-1" style="justify-content:flex-end">
                    <button type="button" class="btn btn-sm btn-icon" data-edit-staff data-id="<?= (int)$u['id'] ?>" data-username="<?= e($u['username']) ?>" aria-label="Tahrirlash"><?= icon('pencil', 'i-sm') ?></button>
                    <?php if ($u['is_active']): ?>
                      <a class="btn btn-sm btn-icon" href="process.php?action=toggle_staff&id=<?= (int)$u['id'] ?>&status=0" data-confirm="<?= e($u['username']) ?> tizimga kira olmaydi." data-confirm-title="Bloklaysizmi?" data-confirm-ok="Bloklash" aria-label="Bloklash"><?= icon('ban', 'i-sm') ?></a>
                    <?php else: ?>
                      <a class="btn btn-sm btn-icon btn-success" href="process.php?action=toggle_staff&id=<?= (int)$u['id'] ?>&status=1" aria-label="Faollashtirish"><?= icon('lock-open', 'i-sm') ?></a>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-icon btn-danger-soft" href="process.php?action=delete_staff&id=<?= (int)$u['id'] ?>" data-confirm="<?= e($u['username']) ?> o'chiriladi. Uning kiritgan tranzaksiyalari saqlanib qoladi." data-confirm-title="Foydalanuvchini o'chirasizmi?" data-confirm-ok="O'chirish" aria-label="O'chirish"><?= icon('trash-2', 'i-sm') ?></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="empty" id="staffNone" <?= $staff ? 'hidden' : '' ?>><div class="icon-tile"><?= icon('users', 'i-lg') ?></div><b><?= $staff ? 'Topilmadi' : "Hali foydalanuvchi yo'q" ?></b><span class="fs-sm"><?= $staff ? '' : "«Yangi foydalanuvchi» tugmasi orqali kassir qo'shing." ?></span></div>
      </section>
    </div>

    <!-- ================= EMPLOYEES ================= -->
    <div class="tabpanel stack" role="tabpanel" id="p-employees" aria-labelledby="t-employees" hidden>
      <section class="grid g-3" aria-label="Oylik xulosa">
        <div class="card kpi"><span class="kpi-label">Oylik fondi</span><div class="kpi-value num" style="font-size:20px"><?= fmt_short($fund) ?></div></div>
        <div class="card kpi"><span class="kpi-label">To'langan</span><div class="kpi-value num text-in" style="font-size:20px"><?= fmt_short($paid_total) ?></div></div>
        <div class="card kpi"><span class="kpi-label">Qoldi</span><div class="kpi-value num text-out" style="font-size:20px"><?= fmt_short(max(0, $fund - $paid_total)) ?></div></div>
      </section>
      <div class="row between row-wrap">
        <p class="text-3 fs-sm"><?= e(mb_convert_case(uz_month((int)date('n')), MB_CASE_TITLE)) ?> oyi bo'yicha to'lovlar «Oylik» kategoriyasidagi chiqimlardan hisoblanadi.</p>
        <button class="btn btn-primary" type="button" id="newEmp"><?= icon('plus', 'i-sm') ?> Yangi xodim</button>
      </div>
      <section class="card clip">
        <div class="table-wrap">
          <table class="table table-stack">
            <thead><tr><th>Xodim</th><th>Lavozim</th><th class="r">Oylik</th><th style="min-width:190px"><?= e(uz_month((int)date('n'))) ?> to'lovi</th><th class="r">Amallar</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $emp): $paid = (float)$emp['paid']; $sal = (float)$emp['salary']; $pc = $sal > 0 ? min(100, $paid / $sal * 100) : 0;
                $status = $paid <= 0 ? ['badge', "To'lanmagan"] : ($paid >= $sal ? ['badge-in', "To'langan"] : ['badge-warn', 'Qisman']); ?>
              <tr>
                <td class="cell-title"><span class="row gap-2"><span class="avatar"><?= e(mb_substr($emp['name'], 0, 1)) ?></span><b><?= e($emp['name']) ?></b></span></td>
                <td class="text-2" data-label="Lavozim"><?= e($emp['position']) ?></td>
                <td class="r num fw-7" data-label="Oylik"><?= fmt($emp['salary']) ?></td>
                <td data-label="To'lov"><div style="min-width:150px;width:100%"><div class="row between fs-xs" style="margin-bottom:4px"><span class="badge <?= $status[0] ?>"><?= $status[1] ?></span><span class="num text-3"><?= fmt($paid) ?></span></div><div class="bar bar-thin in"><i style="width:<?= round($pc) ?>%"></i></div></div></td>
                <td class="r cell-actions">
                  <div class="row gap-1" style="justify-content:flex-end">
                    <button type="button" class="btn btn-sm btn-icon" data-edit-emp data-id="<?= (int)$emp['id'] ?>" data-name="<?= e($emp['name']) ?>" data-position="<?= e($emp['position']) ?>" data-salary="<?= (int)$emp['salary'] ?>" aria-label="Tahrirlash"><?= icon('pencil', 'i-sm') ?></button>
                    <a class="btn btn-sm btn-icon btn-danger-soft" href="process.php?action=delete_employee&id=<?= (int)$emp['id'] ?>" data-confirm="<?= e($emp['name']) ?> ro'yxatdan o'chiriladi." data-confirm-title="Xodimni o'chirasizmi?" data-confirm-ok="O'chirish" aria-label="O'chirish"><?= icon('trash-2', 'i-sm') ?></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (!$employees): ?><div class="empty"><div class="icon-tile"><?= icon('briefcase', 'i-lg') ?></div><b>Hali xodim yo'q</b><span class="fs-sm">Oylik hisoblash uchun xodim qo'shing.</span></div><?php endif; ?>
      </section>
    </div>

    <!-- ================= CATEGORIES ================= -->
    <div class="tabpanel stack" role="tabpanel" id="p-categories" aria-labelledby="t-categories" hidden>
      <section class="card card-pad">
        <h2 class="card-title" style="margin-bottom:12px"><?= icon('plus', 'i-sm') ?> Yangi kategoriya</h2>
        <form action="process.php" method="post" class="row row-wrap" style="align-items:flex-end" data-loading>
          <input type="hidden" name="action" value="add_category">
          <div class="field grow" style="min-width:200px"><label class="label" for="catName">Nomi</label><input class="input" id="catName" type="text" name="name" placeholder="Masalan: Kommunal" required></div>
          <div class="field"><span class="label">Turi</span>
            <div class="seg" role="radiogroup"><label class="is-in"><input type="radio" name="type" value="income" checked>Kirim</label><label class="is-out"><input type="radio" name="type" value="expense">Chiqim</label></div></div>
          <button class="btn btn-primary" type="submit" style="min-height:46px"><?= icon('plus', 'i-sm') ?> Qo'shish</button>
        </form>
      </section>
      <div class="grid lg-g-2">
        <?php foreach ([['Kirim kategoriyalari', $cat_in, 'in'], ['Chiqim kategoriyalari', $cat_out, 'out']] as [$ttl, $list, $k]): ?>
        <section class="card clip">
          <div class="card-head"><h2 class="card-title"><span class="dot dot-<?= $k ?>"></span> <?= e($ttl) ?></h2><span class="badge badge-<?= $k ?>"><?= count($list) ?></span></div>
          <div class="list">
            <?php foreach ($list as $c): ?>
            <div class="tx" style="padding-top:10px;padding-bottom:10px;align-items:center">
              <div class="tx-main"><div class="fw-6"><?= e($c['name']) ?></div><div class="fs-xs text-3"><?= (int)$c['uses'] ?> ta tranzaksiyada ishlatilgan</div></div>
              <a class="btn btn-ghost btn-sm btn-icon" href="process.php?action=delete_category&id=<?= (int)$c['id'] ?>" data-confirm="«<?= e($c['name']) ?>» o'chiriladi.<?= $c['uses'] ? ' Unga bog\'langan ' . (int)$c['uses'] . " ta tranzaksiya «Kategoriyasiz» bo'lib qoladi." : '' ?>" data-confirm-title="Kategoriyani o'chirasizmi?" data-confirm-ok="O'chirish" aria-label="<?= e($c['name']) ?> o'chirish"><?= icon('trash-2', 'i-sm') ?></a>
            </div>
            <?php endforeach; ?>
            <?php if (!$list): ?><div class="empty" style="padding:28px"><span class="fs-sm">Kategoriya yo'q</span></div><?php endif; ?>
          </div>
        </section>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ================= SETTINGS ================= -->
    <div class="tabpanel stack" role="tabpanel" id="p-settings" aria-labelledby="t-settings" hidden>
      <div class="grid lg-g-2">
        <section class="card card-pad stack" aria-labelledby="permTitle">
          <div class="row"><span class="icon-tile primary"><?= icon('shield-check') ?></span><div><h2 class="card-title" id="permTitle">Foydalanuvchi ruxsatlari</h2><p class="fs-sm text-3">Staff nimalar qila olishini belgilang</p></div></div>
          <?php foreach ([['staff_can_edit', 'Tahrirlash', 'Tranzaksiyalarni tahrirlay oladi', $staff_can_edit], ['staff_can_delete', "O'chirish", "Tranzaksiyalarni o'chira oladi", $staff_can_delete]] as [$key, $ttl, $desc, $on]): ?>
          <form action="process.php" method="post" class="row card-pad" style="border:1px solid var(--border);border-radius:14px;background:var(--surface-2)">
            <input type="hidden" name="action" value="update_setting"><input type="hidden" name="key" value="<?= $key ?>"><input type="hidden" name="value" value="<?= $on ? 1 : 0 ?>">
            <div class="grow"><div class="fw-7"><?= e($ttl) ?> ruxsati</div><div class="fs-sm text-3"><?= e($desc) ?></div></div>
            <label class="switch"><input type="checkbox" role="switch" <?= $on ? 'checked' : '' ?> data-autosubmit aria-label="<?= e($ttl) ?> ruxsati"><i></i></label>
          </form>
          <?php endforeach; ?>
        </section>

        <section class="card card-pad stack" aria-labelledby="credTitle">
          <div class="row"><span class="icon-tile primary"><?= icon('key-round') ?></span><div><h2 class="card-title" id="credTitle">Admin hisobi</h2><p class="fs-sm text-3">Login va parolni yangilang</p></div></div>
          <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
            <input type="hidden" name="action" value="change_admin_credentials">
            <div class="field"><label class="label" for="curPw">Joriy parol *</label>
              <div class="input-icon"><?= icon('lock') ?><input class="input" id="curPw" type="password" name="current_password" autocomplete="current-password" required>
              <button type="button" class="btn btn-ghost btn-icon btn-sm eye" data-toggle-pass="#curPw" aria-label="Parolni ko'rsatish"><?= icon('eye') ?></button></div></div>
            <div class="field"><label class="label" for="newUser">Yangi login</label><input class="input" id="newUser" type="text" name="new_username" placeholder="<?= e($_SESSION['username']) ?>" autocomplete="username" autocapitalize="none"></div>
            <div class="field"><label class="label" for="newPw">Yangi parol</label>
              <div class="input-icon"><?= icon('lock') ?><input class="input" id="newPw" type="password" name="new_password" placeholder="Ixtiyoriy" autocomplete="new-password">
              <button type="button" class="btn btn-ghost btn-icon btn-sm eye" data-toggle-pass="#newPw" aria-label="Parolni ko'rsatish"><?= icon('eye') ?></button></div></div>
            <button class="btn btn-primary" type="submit">Saqlash</button>
          </form>
        </section>
      </div>

      <section class="card card-pad" aria-labelledby="smpTitle" style="border-color:color-mix(in srgb, var(--danger) 30%, var(--border))">
        <div class="row row-wrap">
          <span class="icon-tile out"><?= icon('flask-conical') ?></span>
          <div class="grow" style="min-width:220px"><h2 class="card-title" id="smpTitle">Namunaviy ma'lumotlar</h2>
            <p class="fs-sm text-3">Tizimni sinash uchun 30 kunlik tranzaksiyalar. Ular «[NAMUNA]» belgisi bilan saqlanadi.<?= $sample_count ? ' Hozir <b>' . $sample_count . ' ta</b> namuna bor.' : '' ?></p></div>
          <div class="row gap-2 row-wrap">
            <a class="btn" href="process.php?action=load_sample_data" data-confirm="30 kunlik namunaviy tranzaksiyalar yuklanadi. Ularni istalgan vaqt o'chirish mumkin." data-confirm-title="Namuna yuklansinmi?" data-confirm-ok="Yuklash" data-confirm-tone="primary"><?= icon('upload', 'i-sm') ?> Namuna yuklash</a>
            <a class="btn btn-danger-soft" href="process.php?action=delete_sample_data" data-confirm="Barcha [NAMUNA] tranzaksiyalari o'chiriladi. Bu amalni qaytarib bo'lmaydi." data-confirm-title="Namunalar o'chirilsinmi?" data-confirm-ok="O'chirish"><?= icon('trash-2', 'i-sm') ?> Namunalarni o'chirish</a>
          </div>
        </div>
      </section>
    </div>
  </div>
</div>

<?php
ob_start(); ?>
<dialog class="modal" id="staffDialog" aria-labelledby="staffTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="staffTitle">Yangi foydalanuvchi</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
      <input type="hidden" name="action" id="staffAction" value="create_staff"><input type="hidden" name="id" id="staffId">
      <div class="field"><label class="label" for="staffUsername">Login</label><input class="input" type="text" name="username" id="staffUsername" autocapitalize="none" autocorrect="off" required></div>
      <div class="field"><label class="label" for="staffPassword">Parol</label>
        <div class="input-icon"><?= icon('lock') ?><input class="input" type="password" name="password" id="staffPassword" autocomplete="new-password" required>
        <button type="button" class="btn btn-ghost btn-icon btn-sm eye" data-toggle-pass="#staffPassword" aria-label="Parolni ko'rsatish"><?= icon('eye') ?></button></div>
        <span class="hint" id="staffPwHint" hidden>Bo'sh qoldirsangiz, parol o'zgarmaydi.</span></div>
      <button type="submit" class="btn btn-primary btn-lg btn-block">Saqlash</button>
    </form>
  </div>
</dialog>
<dialog class="modal" id="empDialog" aria-labelledby="empTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="empTitle">Yangi xodim</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
      <input type="hidden" name="action" id="empAction" value="create_employee"><input type="hidden" name="id" id="empId">
      <div class="field"><label class="label" for="empName">F.I.SH</label><input class="input" type="text" name="name" id="empName" required></div>
      <div class="field"><label class="label" for="empPos">Lavozim</label><input class="input" type="text" name="position" id="empPos" required></div>
      <div class="field"><label class="label" for="empSalary">Oylik summasi</label><div class="money-field"><input class="input" style="font-size:24px;min-height:60px" type="text" inputmode="numeric" name="salary" id="empSalary" data-money placeholder="0" required><span class="unit">so'm</span></div></div>
      <button type="submit" class="btn btn-primary btn-lg btn-block">Saqlash</button>
    </form>
  </div>
</dialog>
<?php
$dialogs = ob_get_clean();
$js = '<script>
(function(){
  var $ = function(id){ return document.getElementById(id); };
  function fmt(n){ return String(n).replace(/\B(?=(\d{3})+(?!\d))/g," "); }
  $("newStaff").addEventListener("click", function(){
    $("staffTitle").textContent = "Yangi foydalanuvchi"; $("staffAction").value = "create_staff"; $("staffId").value = "";
    $("staffUsername").value = ""; $("staffPassword").value = ""; $("staffPassword").required = true; $("staffPwHint").hidden = true; openDialog($("staffDialog"));
  });
  document.addEventListener("click", function(e){
    var b = e.target.closest("[data-edit-staff]");
    if(b){ $("staffTitle").textContent = "Foydalanuvchini tahrirlash"; $("staffAction").value = "update_staff"; $("staffId").value = b.dataset.id;
      $("staffUsername").value = b.dataset.username; $("staffPassword").value = ""; $("staffPassword").required = false; $("staffPwHint").hidden = false; openDialog($("staffDialog")); return; }
    b = e.target.closest("[data-edit-emp]");
    if(b){ $("empTitle").textContent = "Xodimni tahrirlash"; $("empAction").value = "update_employee"; $("empId").value = b.dataset.id;
      $("empName").value = b.dataset.name; $("empPos").value = b.dataset.position; $("empSalary").value = fmt(b.dataset.salary); openDialog($("empDialog")); }
  });
  $("newEmp").addEventListener("click", function(){
    $("empTitle").textContent = "Yangi xodim"; $("empAction").value = "create_employee"; $("empId").value = "";
    $("empName").value = ""; $("empPos").value = ""; $("empSalary").value = ""; openDialog($("empDialog"));
  });
})();
</script>';
ui_end(['active' => 'admin', 'after' => $dialogs, 'scripts' => $js]);
