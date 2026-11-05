<?php
require_once __DIR__ . '/layout.php';
require_login();
$me = auth_user();
$b = $me['branch_id'] ? branch_get((int)$me['branch_id']) : null;

// recent activity of this user
$st = db()->prepare("SELECT action, details, created_at FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 6");
$st->execute([$me['id']]);
$recent = $st->fetchAll();
$cnt = db()->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN cash_in=1 THEN payment ELSE 0 END),0) FROM transactions WHERE user_id = ?");
$cnt->execute([$me['id']]); [$n, $sum] = $cnt->fetch(PDO::FETCH_NUM);

ui_head('Profil');
ui_start('profile');
?>
<div class="page page-narrow">
  <div class="page-head"><div><h1 class="page-title">Profil</h1><p class="page-sub">Shaxsiy ma'lumotlar va xavfsizlik</p></div></div>

  <section class="card card-pad row" style="margin-bottom:16px;gap:16px;flex-wrap:wrap">
    <span class="avatar lg" style="width:64px;height:64px;font-size:24px"><?= e(mb_substr($me['username'], 0, 1)) ?></span>
    <div class="grow" style="min-width:180px">
      <div class="fw-8" style="font-size:20px;letter-spacing:-.02em"><?= e($me['full_name'] ?: $me['username']) ?></div>
      <div class="text-3 fs-sm">@<?= e($me['username']) ?></div>
      <div class="row row-wrap mt-2" style="gap:8px">
        <span class="badge <?= $me['role'] === 'admin' ? 'badge-primary' : ($me['role'] === 'manager' ? 'badge-warn' : 'badge-in') ?>"><?= e(role_label($me['role'])) ?></span>
        <?php if ($b): ?><span class="scope-chip"><?= branch_avatar($b, 'xs') ?><?= e($b['name']) ?></span><?php else: ?><span class="scope-chip"><?= branch_avatar(null, 'xs') ?>Barcha filiallar</span><?php endif; ?>
      </div>
    </div>
    <div class="stat-row">
      <span class="stat-pill"><?= icon('receipt', 'i-xs') ?> <?= fmt($n) ?> ta yozuv</span>
      <?php if ($me['last_login_at']): ?><span class="stat-pill"><?= icon('clock', 'i-xs') ?> Kirish: <?= e(local_datetime($me['last_login_at'])) ?></span><?php endif; ?>
    </div>
  </section>

  <div class="grid lg-g-2">
    <section class="card card-pad stack" aria-labelledby="pTitle">
      <div class="row"><span class="icon-tile primary"><?= icon('id-card') ?></span><div><h2 class="card-title" id="pTitle">Ma'lumotlarim</h2><p class="fs-sm text-3">Ism va telefon</p></div></div>
      <form action="process.php" method="post" class="stack" data-loading>
        <?= csrf_field() ?><input type="hidden" name="action" value="update_profile">
        <?php if (is_admin()): ?><div class="field"><label class="label" for="pu">Login</label><input class="input" id="pu" name="username" value="<?= e($me['username']) ?>" autocapitalize="none" required></div><?php endif; ?>
        <div class="field"><label class="label" for="pf">To'liq ism</label><input class="input" id="pf" name="full_name" value="<?= e($me['full_name']) ?>" placeholder="Masalan: Madina Karimova"></div>
        <div class="field"><label class="label" for="pp">Telefon</label><input class="input" id="pp" name="phone" type="tel" inputmode="tel" value="<?= e($me['phone']) ?>" placeholder="+998 90 123 45 67"></div>
        <button class="btn btn-primary" type="submit">Saqlash</button>
      </form>
    </section>

    <section class="card card-pad stack" aria-labelledby="kTitle">
      <div class="row"><span class="icon-tile primary"><?= icon('key-round') ?></span><div><h2 class="card-title" id="kTitle">Parolni o'zgartirish</h2><p class="fs-sm text-3">Kamida 6 ta belgi</p></div></div>
      <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
        <div class="field"><label class="label" for="cp">Joriy parol</label>
          <div class="input-icon"><?= icon('lock') ?><input class="input" id="cp" type="password" name="current_password" autocomplete="current-password" required>
          <button type="button" class="btn btn-ghost btn-icon btn-sm eye" data-toggle-pass="#cp" aria-label="Parolni ko'rsatish"><?= icon('eye') ?></button></div></div>
        <div class="field"><label class="label" for="np">Yangi parol</label>
          <div class="input-icon"><?= icon('lock') ?><input class="input" id="np" type="password" name="new_password" autocomplete="new-password" minlength="6" required>
          <button type="button" class="btn btn-ghost btn-icon btn-sm eye" data-toggle-pass="#np" aria-label="Parolni ko'rsatish"><?= icon('eye') ?></button></div></div>
        <button class="btn btn-primary" type="submit">Parolni yangilash</button>
      </form>
    </section>
  </div>

  <?php if ($recent): ?>
  <section class="card clip" style="margin-top:16px">
    <div class="card-head"><h2 class="card-title"><?= icon('history', 'i-sm') ?> So'nggi faoliyatim</h2></div>
    <ul class="tl">
      <?php foreach ($recent as $r): ?>
      <li><span class="tl-ic primary"><?= icon('clock', 'i-sm') ?></span><div><div class="tl-t"><?= e($r['action']) ?></div></div><time class="fs-xs text-3 num"><?= e(local_datetime($r['created_at'])) ?></time></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <div class="mt-6"><a class="btn btn-danger-soft" href="process.php?action=logout" data-post><?= icon('log-out', 'i-sm') ?> Tizimdan chiqish</a></div>
</div>
<?php ui_end(['active' => 'profile']);
