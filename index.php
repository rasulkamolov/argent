<?php
require_once 'db.php';
require_once 'layout.php';
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit(); }

/* =========================================================================
   LOGIN
   ========================================================================= */
if (!isset($_SESSION['user_id'])) {
    $err = $_SESSION['login_error'] ?? ''; unset($_SESSION['login_error']);
    $lastUser = $_SESSION['login_user'] ?? ''; unset($_SESSION['login_user']);
    ui_head('Kirish');
    ?>
<body>
<div class="auth">
  <section class="auth-panel" aria-hidden="true">
    <div class="brand" style="color:#fff">
      <span class="brand-mark"><img src="logo.png" alt="" width="30" height="30"></span>
      <span class="brand-name" style="font-size:18px"><?= e(APP_NAME) ?> <span style="color:#c7caff"><?= e(APP_SUB) ?></span></span>
    </div>
    <div>
      <h2>Kunlik kirim-chiqim, bir joyda va tushunarli.</h2>
      <p>Naqd, Click va bank o'tkazmalarini yuriting, hisobotlar va grafik analitikani real vaqtda kuzating.</p>
      <div class="auth-feats">
        <div><span class="icon-tile"><?= icon('wallet', 'i-sm') ?></span> Naqd, Click va Bank bo'yicha qoldiq</div>
        <div><span class="icon-tile"><?= icon('file-text', 'i-sm') ?></span> Davr bo'yicha hisobot va Excel eksport</div>
        <div><span class="icon-tile"><?= icon('smartphone', 'i-sm') ?></span> Telefon va kompyuterda qulay</div>
      </div>
    </div>
    <div class="fs-sm" style="color:rgba(255,255,255,.6);position:relative;z-index:1">© <?= date('Y') ?> <?= e(APP_NAME) ?></div>
  </section>

  <section class="auth-form-wrap">
    <div class="auth-card">
      <div class="center" style="margin-bottom:24px">
        <div class="auth-logo"><img src="logo.png" alt="<?= e(APP_NAME) ?> logotipi" width="58" height="58"></div>
        <h1 class="page-title"><?= e(APP_NAME) ?> <span class="text-primary"><?= e(APP_SUB) ?></span></h1>
        <p class="text-3 mt-1">Tizimga kirish uchun ma'lumotlaringizni kiriting</p>
      </div>

      <?php if ($err): ?>
        <div class="alert error" role="alert" style="margin-bottom:16px"><?= icon('circle-alert') ?> <span><?= e($err) ?></span></div>
      <?php endif; ?>

      <form action="process.php" method="post" class="stack" data-loading>
        <input type="hidden" name="action" value="login">
        <div class="field">
          <label class="label" for="username">Login</label>
          <div class="input-icon">
            <?= icon('user') ?>
            <input class="input" id="username" type="text" name="username" value="<?= e($lastUser) ?>" placeholder="Loginingiz" autocomplete="username" autocapitalize="none" autocorrect="off" required <?= $lastUser === '' ? 'autofocus' : '' ?>>
          </div>
        </div>
        <div class="field">
          <label class="label" for="password">Parol</label>
          <div class="input-icon">
            <?= icon('lock') ?>
            <input class="input" id="password" type="password" name="password" placeholder="Parolingiz" autocomplete="current-password" required <?= $lastUser !== '' ? 'autofocus' : '' ?>>
            <button type="button" class="btn btn-ghost btn-icon btn-sm eye" data-toggle-pass="#password" aria-label="Parolni ko'rsatish"><?= icon('eye') ?></button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg btn-block">Kirish <?= icon('arrow-right') ?></button>
      </form>

      <div class="center mt-6">
        <button type="button" class="btn btn-ghost btn-sm" data-theme-toggle><?= icon('moon', 'i-sm') ?> Mavzuni almashtirish</button>
      </div>
    </div>
  </section>
</div>
<?php ui_footer(); exit(); }

/* =========================================================================
   DASHBOARD
   ========================================================================= */
$role = $_SESSION['role'];
$today = date('Y-m-d');
$current_date = (isset($_GET['date']) && valid_date($_GET['date'])) ? $_GET['date'] : $today;
$prev_date = date('Y-m-d', strtotime("$current_date -1 day"));
$next_date = date('Y-m-d', strtotime("$current_date +1 day"));

$can_edit = true; $can_delete = false;
if ($role == 'staff') {
    $se = $conn->query("SELECT value FROM settings WHERE key='staff_can_edit'")->fetchColumn();
    $can_edit = ($se === false || $se === '1');
    $can_delete = ($conn->query("SELECT value FROM settings WHERE key='staff_can_delete'")->fetchColumn() === '1');
} elseif ($role === 'admin') { $can_delete = true; }

$stmt = $conn->prepare("SELECT t.*, c.name as category_name, u.username as author FROM transactions t LEFT JOIN categories c ON t.category_id = c.id LEFT JOIN users u ON t.user_id = u.id WHERE t.date = :date ORDER BY t.id DESC");
$stmt->execute([':date' => $current_date]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cats = $conn->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$employees = $conn->query("SELECT * FROM employees ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$stats = [];
foreach (['cash', 'click', 'bank', 'total'] as $k) $stats[$k] = ['in' => 0, 'out' => 0, 'bal' => 0];
$n_in = $n_out = 0;
foreach ($transactions as $t) {
    $amt = (float)$t['payment'];
    $m = method_of($t);
    if ($t['cash_in'] == 1) { $stats[$m]['in'] += $amt; $stats['total']['in'] += $amt; $n_in++; }
    else { $stats[$m]['out'] += $amt; $stats['total']['out'] += $amt; $n_out++; }
}
foreach ($stats as $k => &$s) $s['bal'] = $s['in'] - $s['out'];
unset($s);

$cats_js = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'type' => $c['type'], 'salary' => is_salary_category($c['name'])], $cats);
$is_today = $current_date === $today;

ui_head('Kunlik hisob');
ui_start('daily');
?>
<div class="page">
  <div class="page-head">
    <div>
      <h1 class="page-title">Kunlik hisob</h1>
      <p class="page-sub">
        <?= e(uz_weekday($current_date)) ?>, <?= e(uz_date($current_date)) ?>
        <?php if ($is_today): ?><span class="badge badge-primary" style="margin-left:6px">Bugun</span><?php endif; ?>
      </p>
    </div>
    <div class="row gap-2 no-print">
      <form method="get" class="row gap-1" id="dateForm" aria-label="Sanani tanlash">
        <a class="btn btn-icon" href="?date=<?= e($prev_date) ?>" aria-label="Oldingi kun"><?= icon('chevron-left') ?></a>
        <input class="input" style="width:auto;min-width:150px;text-align:center;font-weight:700" type="date" name="date" value="<?= e($current_date) ?>" aria-label="Sana" onchange="this.form.submit()">
        <a class="btn btn-icon" href="?date=<?= e($next_date) ?>" aria-label="Keyingi kun"><?= icon('chevron-right') ?></a>
      </form>
      <?php if (!$is_today): ?><a class="btn btn-soft" href="index.php">Bugun</a><?php endif; ?>
      <button class="btn btn-primary hide-sm" type="button" data-open="#addDialog"><?= icon('plus') ?> Yangi o'tkazma</button>
    </div>
  </div>

  <!-- KPI -->
  <section class="grid g-2 md-g-3" aria-label="Kunlik xulosa">
    <div class="card kpi hero span-full-m">
      <div class="kpi-top"><span class="kpi-label">Kun qoldig'i</span><?= icon('wallet') ?></div>
      <div class="kpi-value num"><?= e(fmt_signed($stats['total']['bal'])) ?><small>so'm</small></div>
      <div class="kpi-foot"><?= count($transactions) ?> ta o'tkazma</div>
    </div>
    <div class="card kpi">
      <div class="kpi-top"><span class="kpi-label">Kirim</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('arrow-down-left', 'i-sm') ?></span></div>
      <div class="kpi-value num text-in">+<?= fmt($stats['total']['in']) ?></div>
      <div class="kpi-foot"><?= $n_in ?> ta</div>
    </div>
    <div class="card kpi">
      <div class="kpi-top"><span class="kpi-label">Chiqim</span><span class="icon-tile out" style="width:32px;height:32px;border-radius:10px"><?= icon('arrow-up-right', 'i-sm') ?></span></div>
      <div class="kpi-value num text-out">−<?= fmt($stats['total']['out']) ?></div>
      <div class="kpi-foot"><?= $n_out ?> ta</div>
    </div>
  </section>

  <section class="grid g-3 only-mobile" style="margin-top:12px" aria-label="To'lov usullari bo'yicha qoldiq">
    <?php foreach (['cash','click','bank'] as $k): $mm = method_meta($k); ?>
    <div class="card mtile">
      <span class="mt-top"><span class="dot dot-<?= $k ?>"></span><?= e($mm['label']) ?></span>
      <span class="mt-val num <?= $stats[$k]['bal'] < 0 ? 'text-out' : '' ?>"><?= e(fmt_signed($stats[$k]['bal'])) ?></span>
    </div>
    <?php endforeach; ?>
  </section>

  <div class="grid lg-split mt-4" style="margin-top:16px">
    <!-- Methods -->
    <aside class="lg-aside stack only-desktop">
      <section class="card" aria-labelledby="mTitle">
        <div class="card-head"><h2 class="card-title" id="mTitle"><?= icon('chart-pie', 'i-sm') ?> To'lov usullari</h2></div>
        <div class="card-pad stack">
          <?php
          $turn = 0; foreach (['cash','click','bank'] as $k) $turn += $stats[$k]['in'] + $stats[$k]['out'];
          if ($turn > 0): ?>
          <div class="segbar" role="img" aria-label="Aylanma bo'yicha ulush">
            <?php foreach (['cash','click','bank'] as $k): $v = $stats[$k]['in'] + $stats[$k]['out']; if ($v <= 0) continue; ?>
              <i class="<?= $k ?>" style="flex:<?= $v ?>"></i>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php foreach (['cash','click','bank'] as $k): $mm = method_meta($k); $s = $stats[$k]; ?>
          <div class="row">
            <span class="icon-tile badge-<?= $k ?>" style="width:38px;height:38px"><?= icon($mm['icon'], 'i-sm') ?></span>
            <div class="grow">
              <div class="fw-7"><?= e($mm['label']) ?></div>
              <div class="fs-xs text-3 num">+<?= fmt($s['in']) ?> · −<?= fmt($s['out']) ?></div>
            </div>
            <div class="fw-8 num <?= $s['bal'] < 0 ? 'text-out' : '' ?>"><?= e(fmt_signed($s['bal'])) ?></div>
          </div>
          <?php endforeach; ?>
          <div class="row between" style="border-top:1px solid var(--border);padding-top:14px">
            <span class="fw-7">Jami qoldiq</span>
            <span class="fw-8 num text-primary" style="font-size:18px"><?= e(fmt_signed($stats['total']['bal'])) ?></span>
          </div>
        </div>
      </section>
    </aside>

    <!-- Transactions -->
    <section class="card clip" aria-labelledby="lTitle">
      <div class="card-head" style="flex-wrap:wrap">
        <h2 class="card-title" id="lTitle"><?= icon('list', 'i-sm') ?> O'tkazmalar <span class="badge"><?= count($transactions) ?></span></h2>
        <?php if (count($transactions) > 0): ?>
        <div class="seg no-print" role="group" aria-label="Turi bo'yicha filtr" data-filter-type="#txList">
          <button type="button" class="on" data-type="all" aria-pressed="true">Hammasi</button>
          <button type="button" data-type="in" aria-pressed="false">Kirim</button>
          <button type="button" data-type="out" aria-pressed="false">Chiqim</button>
        </div>
        <?php endif; ?>
      </div>
      <?php if (count($transactions) > 3): ?>
      <div class="card-pad no-print" style="padding-bottom:12px">
        <div class="input-icon">
          <?= icon('search') ?>
          <input class="input input-sm" style="padding-left:42px" type="search" placeholder="Qidirish…" aria-label="Qidirish" data-filter-list="#txList" data-filter-empty="#txNone">
        </div>
      </div>
      <?php endif; ?>

      <div class="list" id="txList" data-type="all">
        <?php foreach ($transactions as $row):
            $is_in = $row['cash_in'] == 1;
            $m = method_of($row); $mm = method_meta($m);
            $title = $row['category_name'] ?: ($is_in ? 'Kirim' : 'Chiqim');
            $time = local_time($row['created_at']);
            $search = mb_strtolower($title . ' ' . $row['comment'] . ' ' . $row['author'] . ' ' . $mm['label'] . ' ' . (int)$row['payment']);
        ?>
        <article class="tx" data-search="<?= e($search) ?>" data-type="<?= $is_in ? 'in' : 'out' ?>">
          <span class="icon-tile <?= $is_in ? 'in' : 'out' ?>"><?= icon($is_in ? 'arrow-down-left' : 'arrow-up-right') ?></span>
          <div class="tx-main">
            <div class="row between" style="align-items:flex-start;gap:8px">
              <h3 class="tx-title"><?= e($title) ?></h3>
              <div class="tx-amt num <?= $is_in ? 'text-in' : 'text-out' ?>"><?= $is_in ? '+' : '−' ?><?= fmt($row['payment']) ?></div>
            </div>
            <div class="tx-meta">
              <span class="badge badge-<?= $m ?>"><?= icon($mm['icon'], 'i-xs') ?><?= e($mm['label']) ?></span>
              <?php if ($row['is_modified']): ?><span class="badge badge-warn">Tahrirlangan</span><?php endif; ?>
              <?php if ($row['author']): ?><span><?= e($row['author']) ?></span><?php endif; ?>
              <?php if ($time): ?><span class="num"><?= e($time) ?></span><?php endif; ?>
            </div>
            <?php if (trim((string)$row['comment']) !== ''): ?><p class="tx-note"><?= linkify($row['comment']) ?></p><?php endif; ?>
          </div>
          <div class="tx-side no-print">
            <button type="button" class="btn btn-ghost btn-icon btn-sm" data-menu="#menu<?= (int)$row['id'] ?>" aria-haspopup="menu" aria-expanded="false" aria-label="Amallar"><?= icon('ellipsis-vertical') ?></button>
            <template id="menu<?= (int)$row['id'] ?>">
              <a href="view_history.php?id=<?= (int)$row['id'] ?>" role="menuitem"><?= icon('history', 'i-sm') ?> O'zgarishlar tarixi</a>
              <?php if ($can_edit): ?><a href="edit_transaction.php?id=<?= (int)$row['id'] ?>" role="menuitem"><?= icon('pencil', 'i-sm') ?> Tahrirlash</a><?php endif; ?>
              <?php if ($can_delete): ?>
                <hr>
                <a class="danger" role="menuitem" href="process.php?action=delete_transaction&id=<?= (int)$row['id'] ?>" data-confirm="<?= e(($is_in ? '+' : '−') . fmt($row['payment']) . " so'mlik tranzaksiya o'chiriladi. Bu amalni qaytarib bo'lmaydi.") ?>" data-confirm-title="Tranzaksiyani o'chirasizmi?" data-confirm-ok="O'chirish"><?= icon('trash-2', 'i-sm') ?> O'chirish</a>
              <?php endif; ?>
            </template>
          </div>
        </article>
        <?php endforeach; ?>
      </div>

      <div class="empty" id="txNone" <?= count($transactions) > 0 ? 'hidden' : '' ?>>
        <div class="icon-tile"><?= icon('inbox', 'i-lg') ?></div>
        <b><?= count($transactions) > 0 ? 'Hech narsa topilmadi' : ($is_today ? "Bugun hali o'tkazma yo'q" : "Bu kunda o'tkazma yo'q") ?></b>
        <span class="fs-sm"><?= count($transactions) > 0 ? "Qidiruv yoki filtrni o'zgartirib ko'ring." : "Yangi o'tkazma qo'shish uchun «+» tugmasini bosing." ?></span>
        <?php if (count($transactions) === 0): ?>
          <div class="mt-4"><button class="btn btn-primary" type="button" data-open="#addDialog"><?= icon('plus') ?> Yangi o'tkazma</button></div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php
$fab = '<button class="fab no-print" type="button" data-open="#addDialog" aria-label="Yangi o\'tkazma qo\'shish">' . icon('plus') . '</button>';

ob_start(); ?>
<dialog class="modal" id="addDialog" aria-labelledby="addTitle">
  <div class="modal-box">
    <div class="modal-head">
      <h2 class="modal-title" id="addTitle">Yangi o'tkazma</h2>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button>
    </div>
    <form action="process.php" method="post" class="stack" id="addForm" data-loading>
      <input type="hidden" name="action" value="add_transaction">

      <div class="seg seg-block" role="radiogroup" aria-label="Turi">
        <label class="is-in"><input type="radio" name="type" value="income" checked> <?= icon('arrow-down-left', 'i-sm') ?> Kirim</label>
        <label class="is-out"><input type="radio" name="type" value="expense"> <?= icon('arrow-up-right', 'i-sm') ?> Chiqim</label>
      </div>

      <div class="field">
        <label class="label sr-only" for="paymentInput">Summa</label>
        <div class="money-field">
          <input class="input" type="text" inputmode="numeric" name="payment" id="paymentInput" placeholder="0" autocomplete="off" data-money required>
          <span class="unit">so'm</span>
        </div>
      </div>

      <div class="field">
        <span class="label">To'lov usuli</span>
        <div class="method-pick" role="radiogroup" aria-label="To'lov usuli">
          <?php foreach (['cash','click','bank'] as $k): $mm = method_meta($k); ?>
          <label><input type="radio" name="method" value="<?= $k ?>" <?= $k === 'cash' ? 'checked' : '' ?>><?= icon($mm['icon']) ?><?= e($mm['label']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field">
        <label class="label" for="catSelect">Kategoriya</label>
        <select class="select" name="category_id" id="catSelect"></select>
      </div>

      <div class="field" id="empField" hidden>
        <label class="label" for="empSelect">Xodim</label>
        <select class="select" name="employee_id" id="empSelect">
          <option value="">— Tanlang —</option>
          <?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id'] ?>"><?= e($emp['name']) ?></option><?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label class="label" for="commentInput">Izoh <span class="text-3 fw-5">(ixtiyoriy)</span></label>
        <textarea class="textarea" name="comment" id="commentInput" rows="2" placeholder="Izoh yozing… (havola qo'shsangiz, bosiladigan bo'ladi)" data-autogrow></textarea>
      </div>

      <button type="submit" class="btn btn-primary btn-lg btn-block">Saqlash</button>
      <p class="hint center" style="margin-top:-4px">Bugungi sana (<?= e(uz_date($today, false)) ?>) bilan saqlanadi</p>
    </form>
  </div>
</dialog>
<?php
$dialog = ob_get_clean();

$script = '<script>
(function(){
  var CATS = ' . json_encode($cats_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
  var form = document.getElementById("addForm"), sel = document.getElementById("catSelect"), emp = document.getElementById("empField");
  function curType(){ return form.querySelector("input[name=type]:checked").value; }
  function fill(){
    var t = curType(); sel.innerHTML = "";
    var list = CATS.filter(function(c){ return c.type === t; });
    if(!list.length){ var o = document.createElement("option"); o.value = ""; o.textContent = "Kategoriya yo‘q (Admin → Kategoriyalar)"; sel.appendChild(o); }
    list.forEach(function(c){ var o = document.createElement("option"); o.value = c.id; o.textContent = c.name; o.dataset.salary = c.salary ? "1" : ""; sel.appendChild(o); });
    check();
  }
  function check(){ var o = sel.options[sel.selectedIndex]; emp.hidden = !(o && o.dataset.salary); }
  form.querySelectorAll("input[name=type]").forEach(function(r){ r.addEventListener("change", fill); });
  sel.addEventListener("change", check);
  form.addEventListener("submit", function(e){
    var v = document.getElementById("paymentInput").value.replace(/\D/g,"");
    if(!v || +v <= 0){ e.preventDefault(); toast("Summani kiriting","error"); document.getElementById("paymentInput").focus(); form.querySelector(".btn.loading") && form.querySelector(".btn.loading").classList.remove("loading"); }
  });
  document.getElementById("addDialog").addEventListener("close", function(){ form.reset(); fill(); });
  document.addEventListener("keydown", function(e){
    if((e.key==="n"||e.key==="N") && !e.ctrlKey && !e.metaKey && !e.altKey && !/INPUT|TEXTAREA|SELECT/.test((document.activeElement||{}).tagName||"") && !document.querySelector("dialog[open]")){ e.preventDefault(); openDialog(document.getElementById("addDialog")); }
  });
  fill();
})();
</script>';

ui_end(['active' => 'daily', 'after' => $fab . $dialog, 'scripts' => $script]);
