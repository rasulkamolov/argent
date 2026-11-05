<?php
/**
 * Shared layout + formatting helpers.
 *   require 'layout.php'; ui_head('Title'); ui_start('daily'); ... ui_end(['active'=>'daily']);
 * (layout.php loads db.php + auth.php, so pages only need to require this file.)
 */
require_once __DIR__ . '/auth.php';

const APP_NAME = 'OXFORD';
const APP_TAGLINE = 'Filiallar moliyasi';

/* ---------- Escaping & formatting ---------- */
function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt($n): string { return number_format((float)($n ?: 0), 0, '.', ' '); }
function fmt_signed($n): string { $n = (float)$n; return ($n < 0 ? '−' : ($n > 0 ? '+' : '')) . fmt(abs($n)); }
/** Compact money for tight spaces: 1 250 000 -> 1,25 mln */
function fmt_short($n): string {
    $n = (float)$n; $a = abs($n); $s = $n < 0 ? '−' : '';
    if ($a >= 1e9) return $s . rtrim(rtrim(number_format($a / 1e9, 2, ',', ''), '0'), ',') . ' mlrd';
    if ($a >= 1e6) return $s . rtrim(rtrim(number_format($a / 1e6, 2, ',', ''), '0'), ',') . ' mln';
    if ($a >= 1e4) return $s . rtrim(rtrim(number_format($a / 1e3, 1, ',', ''), '0'), ',') . ' ming';
    return $s . fmt($a);
}
function uz_month(int $m, bool $short = false): string {
    $l = ['yanvar','fevral','mart','aprel','may','iyun','iyul','avgust','sentabr','oktabr','noyabr','dekabr'];
    $s = ['yan','fev','mar','apr','may','iyn','iyl','avg','sen','okt','noy','dek'];
    return ($short ? $s : $l)[$m - 1];
}
function uz_weekday(string $ymd, bool $short = false): string {
    $l = ['Yakshanba','Dushanba','Seshanba','Chorshanba','Payshanba','Juma','Shanba'];
    $s = ['Yak','Dush','Sesh','Chor','Pay','Juma','Shan'];
    $w = (int)date('w', strtotime($ymd));
    return $short ? $s[$w] : $l[$w];
}
/** "4-noyabr 2026" */
function uz_date(string $ymd, bool $withYear = true): string {
    $t = strtotime($ymd);
    return (int)date('j', $t) . '-' . uz_month((int)date('n', $t)) . ($withYear ? ' ' . date('Y', $t) : '');
}
/** created_at is stored in UTC by SQLite CURRENT_TIMESTAMP -> local HH:MM */
function local_time(?string $utc): string {
    if (!$utc) return '';
    $t = strtotime($utc . ' UTC');
    return $t ? date('H:i', $t) : '';
}
function local_datetime(?string $utc): string {
    if (!$utc) return '';
    $t = strtotime($utc . ' UTC');
    return $t ? date('d.m.Y H:i', $t) : '';
}
function valid_date(?string $d): bool {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

/* ---------- Domain helpers ---------- */
function method_of(array $r): string { return !empty($r['bank']) ? 'bank' : (!empty($r['click']) ? 'click' : 'cash'); }
function method_meta(string $m): array {
    $all = [
        'cash'  => ['label' => 'Naqd',  'icon' => 'banknote',   'cls' => 'cash'],
        'click' => ['label' => 'Click', 'icon' => 'smartphone', 'cls' => 'click'],
        'bank'  => ['label' => 'Bank',  'icon' => 'building-2', 'cls' => 'bank'],
    ];
    return $all[$m] ?? $all['cash'];
}
function linkify(?string $text): string {
    $t = e($text);
    return preg_replace('~(https?://[^\s<>\)&]+)~i', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $t);
}
function is_salary_category(string $name): bool {
    $n = mb_strtolower($name);
    return str_contains($n, 'salary') || str_contains($n, 'oylik') || str_contains($n, 'maosh');
}

/* ---------- Assets & icons ---------- */
function asset(string $path): string {
    $f = __DIR__ . '/' . $path;
    return $path . (is_file($f) ? '?v=' . filemtime($f) : '');
}
function icon(string $name, string $cls = ''): string {
    return '<svg class="i ' . e($cls) . '" aria-hidden="true"><use href="#' . e($name) . '"/></svg>';
}
/** The whole icon sheet is embedded in every page (no separate file => nothing to cache wrongly). */
function icon_sprite(): string {
    static $svg = null;
    if ($svg === null) {
        $f = __DIR__ . '/assets/icons.svg';
        $raw = is_file($f) ? (string)file_get_contents($f) : '';
        $svg = str_replace('<svg xmlns="http://www.w3.org/2000/svg" style="display:none">', '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">', $raw);
    }
    return $svg;
}
/** Coloured rounded-square avatar with the branch initials. $b = null -> "all branches". */
function branch_avatar(?array $b, string $size = ''): string {
    if (!$b) return '<span class="avatar-branch all ' . e($size) . '">' . icon('layers', $size === 'xs' ? 'i-xs' : 'i-sm') . '</span>';
    return '<span class="avatar-branch ' . e($size) . '" style="--bc:' . e(branch_color($b)) . '">' . e(branch_initials($b['name'])) . '</span>';
}
function branch_label(?array $b): string { return $b ? $b['name'] : 'Barcha filiallar'; }

/* ---------- Page chrome ---------- */
function ui_head(string $title, array $o = []): void {
    $full = $title . ' — ' . APP_NAME;
    ?><!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($full) ?></title>
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#ffffff">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" href="assets/logo-64.png">
<link rel="apple-touch-icon" href="assets/logo-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
<?= $o['head'] ?? '' ?>
</head>
<?php
}

/** [key, href, short label, icon, long label, in bottom bar?, also-active keys] */
function ui_nav_items(): array {
    $u = auth_user(); $role = $u['role'] ?? 'staff';
    if ($role === 'admin') return [
        ['daily', 'index.php', 'Kunlik', 'calendar-days', 'Kunlik hisob', true, []],
        ['branches', 'filiallar.php', 'Filiallar', 'store', 'Filiallar', true, []],
        ['reports', 'hisobot.php', 'Hisobot', 'file-text', 'Hisobotlar', true, ['analytics', 'cashbook']],
        ['analytics', 'analitika.php', 'Analitika', 'chart-line', 'Analitika', false, []],
        ['cashbook', 'kassa.php', 'Kassa', 'wallet', 'Kassa kitobi', false, []],
        ['admin', 'admin.php', 'Admin', 'settings', 'Admin panel', true, []],
    ];
    if ($role === 'manager') return [
        ['daily', 'index.php', 'Kunlik', 'calendar-days', 'Kunlik hisob', true, []],
        ['reports', 'hisobot.php', 'Hisobot', 'file-text', 'Hisobotlar', true, ['analytics']],
        ['analytics', 'analitika.php', 'Analitika', 'chart-line', 'Analitika', false, []],
        ['cashbook', 'kassa.php', 'Kassa', 'wallet', 'Kassa kitobi', true, []],
        ['profile', 'profile.php', 'Profil', 'user-round', 'Profil', true, []],
    ];
    return [
        ['daily', 'index.php', 'Kunlik', 'calendar-days', 'Kunlik hisob', true, []],
        ['cashbook', 'kassa.php', 'Kassa', 'wallet', 'Kassa kitobi', true, []],
        ['profile', 'profile.php', 'Profil', 'user-round', 'Profil', true, []],
    ];
}

function ui_brand(): string {
    return '<a class="brand" href="index.php" aria-label="' . e(APP_NAME) . '"><span class="brand-mark"><img src="assets/logo-64.png" alt="" width="30" height="30"></span>'
        . '<span><span class="brand-name">' . e(APP_NAME) . '</span><span class="brand-sub">' . e(APP_TAGLINE) . '</span></span></a>';
}

function ui_back_param(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? 'index.php';
    $file = basename(parse_url($uri, PHP_URL_PATH) ?: 'index.php');
    $qs = parse_url($uri, PHP_URL_QUERY);
    return rawurlencode($file . ($qs ? '?' . $qs : ''));
}

/** Drop / paste / pick zone for a payment screenshot. */
function ui_proof_drop(bool $required = false, string $name = 'proof', string $id = ''): string {
    return '<div class="proof-drop" data-proof' . ($required ? ' data-required' : '') . ($id ? ' id="' . e($id) . '"' : '') . ' tabindex="0" role="button" aria-label="Skrinshot biriktirish">'
        . '<input type="file" name="' . e($name) . '" accept="image/*,application/pdf,.pdf,.doc,.docx,.xls,.xlsx,.odt,.rtf,.txt,.csv" tabindex="-1">'
        . '<div class="pd-empty"><span class="pd-ic">' . icon('image-plus') . '</span><b>Skrinshot yoki PDF ni shu yerga tashlang</b><small>yoki bosib fayl tanlang · rasmni Ctrl+V bilan qo\'ying</small></div>'
        . '<div class="pd-preview" hidden><img alt=""><span class="pd-filetile" hidden>' . icon('file-text', 'i-lg') . '</span><div class="pd-meta"></div><button type="button" class="btn btn-ghost btn-icon btn-sm" data-proof-clear aria-label="Faylni olib tashlash">' . icon('x', 'i-sm') . '</button></div>'
        . '</div>';
}

/** Branch badge. Admin: opens the switch dialog. Others: static label. */
function ui_scope_switcher(string $variant): string {
    $b = scope_branch_row(); $admin = is_admin();
    $name = branch_label($b);
    if ($variant === 'pill') {
        $inner = branch_avatar($b, 'sm') . '<span class="nm">' . e($name) . '</span>' . ($admin ? icon('chevrons-up-down', 'i-sm') : '');
        return $admin ? '<button type="button" class="pill-switch" data-open="#branchDialog" aria-label="Filialni almashtirish">' . $inner . '</button>'
                      : '<span class="pill-switch">' . $inner . '</span>';
    }
    $inner = branch_avatar($b) . '<span class="sw-txt"><span class="sw-label">' . ($admin ? 'Joriy filial' : 'Filial') . '</span><span class="sw-name d-block" style="display:block">' . e($name) . '</span></span>'
           . ($admin ? icon('chevrons-up-down', 'i-sm text-3') : '');
    return $admin ? '<button type="button" class="switcher" data-open="#branchDialog">' . $inner . '</button>' : '<div class="switcher">' . $inner . '</div>';
}

/** Opens <body>, sidebar (desktop), topbar (mobile) and <main>. */
function ui_start(string $active, array $o = []): void {
    $u = auth_user();
    $user = $u['username'] ?? ''; $role = $u['role'] ?? 'staff';
    $items = ui_nav_items();
    ?><body class="has-bnav">
<div class="app">
  <aside class="sidebar no-print" aria-label="Asosiy navigatsiya">
    <?= ui_brand() ?>
    <?= ui_scope_switcher('card') ?>
    <nav class="nav" aria-label="Bo'limlar">
      <div class="eyebrow nav-label">Bo'limlar</div>
      <?php foreach ($items as [$key, $href, $short, $ic, $long]): ?>
        <a href="<?= e($href) ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e($long) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a class="user-chip" href="profile.php" aria-label="Profil" style="text-decoration:none">
        <span class="avatar avatar-sm"><?= e(mb_substr($user, 0, 1)) ?></span>
        <span class="grow"><span class="fw-7 fs-md truncate" style="display:block"><?= e($u['full_name'] ?: $user) ?></span><span class="badge <?= $role === 'admin' ? 'badge-primary' : ($role === 'manager' ? 'badge-warn' : 'badge-in') ?>"><?= e(role_label($role)) ?></span></span>
        <?= icon('chevron-right', 'i-sm text-3') ?>
      </a>
      <a class="btn btn-sm btn-danger-soft" href="process.php?action=logout" data-post aria-label="Chiqish"><?= icon('log-out', 'i-sm') ?> Chiqish</a>
    </div>
  </aside>

  <div class="grow" style="min-width:0">
    <header class="topbar no-print">
      <a class="brand-mark" href="index.php" aria-label="Bosh sahifa"><img src="assets/logo-64.png" alt="" width="30" height="30"></a>
      <?= ui_scope_switcher('pill') ?>
      <a class="btn btn-ghost btn-icon" href="process.php?action=logout" data-post aria-label="Chiqish"><?= icon('log-out') ?></a>
    </header>
    <main class="main" id="main">
<?php
}

function ui_end(array $o = []): void {
    $active = $o['active'] ?? '';
    $items = array_values(array_filter(ui_nav_items(), function ($i) { return $i[5]; }));
    ?>
    </main>
  </div>
</div>
<nav class="bnav no-print" aria-label="Pastki navigatsiya">
  <?php foreach ($items as [$key, $href, $short, $ic, $long, $b, $also]): ?>
    <a href="<?= e($href) ?>" <?= ($key === $active || in_array($active, $also, true)) ? 'aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($short) ?></span></a>
  <?php endforeach; ?>
</nav>
<?= $o['after'] ?? '' ?>
<?php ui_footer($o); ?>
<?php
}

/** Branch dialog (admin), confirm dialog, toasts, scripts. Also used by pages without the shell (login). */
function ui_footer(array $o = []): void {
    $flash = flash_get();
    ?>
<?php if (is_admin()): $cur = scope_branch(); $back = ui_back_param(); ?>
<dialog class="modal" id="branchDialog" aria-labelledby="branchDlgTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="branchDlgTitle">Filialni tanlang</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <div class="branch-list">
      <a class="branch-opt" href="process.php?action=switch_branch&id=0&back=<?= $back ?>" <?= $cur === 0 ? 'aria-current="true"' : '' ?>>
        <?= branch_avatar(null) ?><span><span class="nm d-block" style="display:block">Barcha filiallar</span><span class="sub"><?= count(branches_active()) ?> ta faol filial · umumiy ko'rinish</span></span><?= $cur === 0 ? icon('circle-check', 'i-check') : '' ?>
      </a>
      <?php foreach (branches_all() as $b): ?>
      <a class="branch-opt" href="process.php?action=switch_branch&id=<?= (int)$b['id'] ?>&back=<?= $back ?>" <?= $cur === (int)$b['id'] ? 'aria-current="true"' : '' ?>>
        <?= branch_avatar($b) ?><span class="grow"><span class="nm d-block" style="display:block"><?= e($b['name']) ?></span><span class="sub"><?= $b['is_active'] ? e($b['address'] ?: 'Faol filial') : 'Arxivlangan' ?></span></span><?= $cur === (int)$b['id'] ? icon('circle-check', 'i-check') : '' ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</dialog>
<?php endif; ?>
<dialog class="modal modal-sm" id="confirmDialog" aria-labelledby="confirmTitle">
  <div class="modal-box">
    <div class="row" style="align-items:flex-start;gap:14px">
      <span class="icon-tile out" style="flex:none"><?= icon('triangle-alert') ?></span>
      <div class="grow">
        <h3 class="modal-title" id="confirmTitle">Tasdiqlang</h3>
        <p class="text-2 mt-1" id="confirmMsg"></p>
      </div>
    </div>
    <div class="row gap-2 mt-4" style="justify-content:flex-end">
      <button type="button" class="btn" data-close>Bekor qilish</button>
      <button type="button" class="btn btn-danger" id="confirmOk">Ha</button>
    </div>
  </div>
</dialog>
<?php if (auth_user()): ?>
<dialog class="modal" id="proofDialog" aria-labelledby="pvTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="pvTitle">To'lov isboti</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <img alt="To'lov isboti" id="pvImg">
    <div class="pv-file" id="pvFile" hidden><span class="icon-tile primary" style="width:56px;height:56px;border-radius:18px"><?= icon('file-text', 'i-lg') ?></span><div class="grow" style="min-width:0"><b id="pvName" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">fayl</b><span class="fs-sm text-3" id="pvKind">PDF hujjat</span></div></div>
    <div class="row gap-2 mt-3 row-wrap" style="justify-content:flex-end">
      <a class="btn btn-sm" id="proofDl" href="#"><?= icon('download', 'i-sm') ?> Yuklab olish</a>
      <a class="btn btn-sm btn-primary" id="proofOpen" href="#" target="_blank" rel="noopener"><?= icon('external-link', 'i-sm') ?> Yangi oynada ochish</a>
    </div>
  </div>
</dialog>
<?php endif; ?>
<div class="toasts" id="toasts" aria-live="polite"></div>
<?= icon_sprite() ?>
<script><?= $flash ? 'window.__flash=' . json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';' : '' ?></script>
<script src="<?= e(asset('assets/app.js')) ?>"></script>
<?= $o['scripts'] ?? '' ?>
</body>
</html>
<?php
}

/** Reports sub navigation (Hisobot / Analitika / Kassa kitobi), keeping the active filter query string. */
function ui_report_subnav(string $active, string $qs = ''): void {
    $q = $qs !== '' ? '?' . $qs : '';
    ?>
<div class="subnav no-print" role="navigation" aria-label="Hisobot turlari">
  <a href="hisobot.php<?= e($q) ?>" <?= $active === 'reports' ? 'aria-current="page"' : '' ?>><?= icon('file-text', 'i-sm') ?> Hisobot</a>
  <a href="analitika.php<?= e($q) ?>" <?= $active === 'analytics' ? 'aria-current="page"' : '' ?>><?= icon('chart-line', 'i-sm') ?> Analitika</a>
  <a href="kassa.php<?= e($q) ?>" <?= $active === 'cashbook' ? 'aria-current="page"' : '' ?>><?= icon('wallet', 'i-sm') ?> Kassa kitobi</a>
</div>
<?php
}

/** "Branch · period" chip used under page titles. */
function ui_scope_chip(?int $branchId = null): string {
    $b = $branchId === null ? scope_branch_row() : ($branchId ? branch_get($branchId) : null);
    return '<span class="scope-chip">' . branch_avatar($b, 'xs') . e(branch_label($b)) . '</span>';
}

/** [label, icon, tone] for an audit-log action. */
function journal_meta(string $a): array {
    $m = [
        'login' => ['Tizimga kirdi', 'log-in', 'primary'], 'logout' => ['Tizimdan chiqdi', 'log-out', ''],
        'tx.create' => ["Tranzaksiya qo'shildi", 'plus', 'in'],
        'tx.proof' => ['Isbot biriktirildi', 'image-plus', 'primary'], 'tx.proof_remove' => ["Isbot o'chirildi", 'image-off', 'warn'], 'tx.update' => ['Tranzaksiya tahrirlandi', 'pencil', 'warn'],
        'tx.delete' => ["Tranzaksiya o'chirildi", 'trash-2', 'danger'], 'tx.delete_restored' => ["Tranzaksiya o'chirilgan (qaytarilgan)", 'undo-2', ''],
        'tx.restore' => ['Tranzaksiya qaytarildi', 'undo-2', 'in'],
        'branch.create' => ['Filial yaratildi', 'store', 'in'], 'branch.update' => ['Filial tahrirlandi', 'store', 'warn'],
        'branch.archive' => ['Filial arxivlandi', 'archive', 'warn'], 'branch.activate' => ['Filial faollashtirildi', 'store', 'in'],
        'branch.delete' => ["Filial o'chirildi", 'trash-2', 'danger'], 'branch.setting' => ['Filial ruxsati o\'zgardi', 'shield-check', 'primary'],
        'user.create' => ["Foydalanuvchi qo'shildi", 'user-plus', 'in'], 'user.update' => ['Foydalanuvchi tahrirlandi', 'user-cog', 'warn'],
        'user.block' => ['Foydalanuvchi bloklandi', 'ban', 'danger'], 'user.activate' => ['Foydalanuvchi faollashtirildi', 'circle-check', 'in'],
        'user.delete' => ["Foydalanuvchi o'chirildi", 'trash-2', 'danger'], 'user.password' => ["Parol o'zgartirildi", 'key-round', 'primary'],
        'employee.create' => ["Xodim qo'shildi", 'briefcase', 'in'], 'employee.update' => ['Xodim tahrirlandi', 'briefcase', 'warn'], 'employee.delete' => ["Xodim o'chirildi", 'trash-2', 'danger'],
        'category.create' => ["Kategoriya qo'shildi", 'tag', 'in'], 'category.update' => ['Kategoriya tahrirlandi', 'tag', 'warn'], 'category.delete' => ["Kategoriya o'chirildi", 'trash-2', 'danger'],
        'setting.update' => ['Sozlama o\'zgardi', 'settings', 'primary'], 'backup.create' => ['Zaxira nusxa olindi', 'database-backup', 'primary'], 'backup.download' => ['Zaxira yuklab olindi', 'download', 'primary'],
        'sample.load' => ["Namuna ma'lumot yuklandi", 'flask-conical', 'warn'], 'sample.delete' => ["Namuna ma'lumot o'chirildi", 'flask-conical', 'warn'],
        'cash.count' => ['Kassa sanaldi', 'calculator', 'primary'], 'export.csv' => ['CSV yuklab olindi', 'download', 'primary'], 'import.commit' => ["Ma'lumot import qilindi", 'upload', 'in'], 'denied' => ['Ruxsat rad etildi', 'shield-alert', 'danger'],
    ];
    return $m[$a] ?? [$a, 'info', ''];
}
