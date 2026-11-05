<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/import_lib.php';
require_admin();

$dir = __DIR__ . '/data/imports';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
foreach (glob($dir . '/imp_*.json') ?: [] as $old) if (filemtime($old) < time() - 7200) @unlink($old);   // stale previews
function imp_fail(string $msg) { flash_set('error', $msg); header('Location: import.php'); exit; }

$step = 'upload'; $plan = null; $meta = null; $result = $_SESSION['import_result'] ?? null; unset($_SESSION['import_result']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) imp_fail("Sessiya muddati tugadi. Qayta urinib ko'ring.");
    $act = $_POST['step'] ?? '';

    if ($act === 'preview') {
        $f = $_FILES['dump'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) imp_fail($f && $f['error'] === UPLOAD_ERR_INI_SIZE ? "Fayl serverda ruxsat etilgan hajmdan katta (upload_max_filesize)." : "Faylni tanlang (.sql).");
        if ($f['size'] > SQLIMP_MAX_BYTES) imp_fail("Fayl juda katta (maks. 40 MB).");
        $newName = trim((string)($_POST['new_branch'] ?? ''));
        $bid = (int)($_POST['branch_id'] ?? 0);
        if ($bid === 0 && $newName === '') imp_fail("Filialni tanlang yoki yangi filial nomini yozing.");
        if ($bid && !branch_get($bid)) imp_fail("Filial topilmadi.");
        try { $rows = sqlimp_parse((string)file_get_contents($f['tmp_name'])); } catch (Throwable $e) { imp_fail("Faylni o'qib bo'lmadi: " . $e->getMessage()); }
        if (!$rows) imp_fail("Faylda «transactions» jadvaliga INSERT topilmadi.");
        $tz = max(-12, min(14, (int)($_POST['tz'] ?? 5)));
        $plan = sqlimp_plan($rows, $bid, ['tz_offset' => $tz, 'skip_empty' => !empty($_POST['skip_empty'])]);
        $token = bin2hex(random_bytes(12));
        $meta = ['branch_id' => $bid, 'new_branch' => $bid ? '' : $newName, 'tz' => $tz, 'file' => basename((string)$f['name'])];
        file_put_contents($dir . "/imp_$token.json", json_encode(['plan' => $plan, 'meta' => $meta], JSON_UNESCAPED_UNICODE));
        $meta['token'] = $token; $step = 'preview';
    }
    elseif ($act === 'commit') {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? ''));
        $file = $dir . "/imp_$token.json";
        if (!$token || !is_file($file)) imp_fail("Oldindan ko'rish muddati tugagan. Faylni qayta yuklang.");
        $d = json_decode((string)file_get_contents($file), true); $plan = $d['plan']; $meta = $d['meta'];
        $bid = (int)$meta['branch_id'];
        if (!$bid) {
            $nm = $meta['new_branch'];
            db()->prepare("INSERT INTO branches (name, color, sort_order) VALUES (?,?,?)")->execute([$nm, next_branch_color(), count(branches_all()) + 1]);
            $bid = (int)db()->lastInsertId(); branches_all(true);
            audit('branch.create', 'branch', $bid, ['name' => $nm, 'via' => 'import'], $bid);
            // duplicates can't exist in a brand-new branch
        }
        $map = [];
        foreach (array_keys($plan['users']) as $i => $name) {
            $v = (string)(($_POST['map'] ?? [])[$i] ?? 'none');
            if ($v === 'new') $map[$name] = ['mode' => 'new'];
            elseif (strpos($v, 'u:') === 0) $map[$name] = ['mode' => 'existing', 'user_id' => (int)substr($v, 2)];
            else $map[$name] = ['mode' => 'none'];
        }
        try { $res = sqlimp_commit($plan, $bid, $map, $meta['file']); }
        catch (Throwable $e) { imp_fail("Import xatosi: " . $e->getMessage()); }
        @unlink($file);
        $_SESSION['import_result'] = $res + ['branch_id' => $bid];
        header('Location: import.php'); exit;
    }
}

$branches = branches_all();
ui_head('Import');
ui_start('admin');
?>
<div class="page page-narrow">
  <a class="btn btn-ghost btn-sm" href="admin.php" style="margin-left:-8px;margin-bottom:8px"><?= icon('arrow-left', 'i-sm') ?> Admin panel</a>
  <div class="page-head"><div><h1 class="page-title">Ma'lumot import</h1><p class="page-sub">phpMyAdmin (.sql) dump faylidan tranzaksiyalarni filialga yuklash</p></div></div>
  <div class="steps" style="margin-bottom:16px">
    <span class="step <?= $step === 'upload' && !$result ? 'on' : 'done' ?>"><span class="n">1</span> Fayl</span>
    <span class="step <?= $step === 'preview' ? 'on' : ($result ? 'done' : '') ?>"><span class="n">2</span> Tekshirish</span>
    <span class="step <?= $result ? 'on' : '' ?>"><span class="n">3</span> Tayyor</span>
  </div>

<?php if ($result): ?>
  <section class="card card-pad stack">
    <div class="row"><span class="icon-tile in"><?= icon('circle-check') ?></span><div><h2 class="card-title">Import yakunlandi</h2><p class="fs-sm text-3"><?= e($result['branch']) ?> filialiga</p></div></div>
    <div class="stat-row"><span class="stat-pill"><?= icon('receipt', 'i-xs') ?> <?= fmt($result['inserted']) ?> ta yozuv qo'shildi</span><?php if ($result['ignored']): ?><span class="stat-pill"><?= fmt($result['ignored']) ?> ta takror o'tkazildi</span><?php endif; ?><?php if ($result['backup']): ?><span class="stat-pill"><?= icon('database-backup', 'i-xs') ?> Zaxira: <?= e($result['backup']) ?></span><?php endif; ?></div>
    <?php if (!empty($result['created_users'])): ?>
    <div class="alert info"><?= icon('key-round') ?><div class="grow">Yangi kassirlar yaratildi. <b>Parollarni hozir saqlang</b> — ular qayta ko'rsatilmaydi (admin keyinroq yangisini o'rnata oladi).</div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Login</th><th>Parol</th></tr></thead><tbody>
      <?php foreach ($result['created_users'] as $u): ?><tr><td><b><?= e($u['username']) ?></b></td><td><code style="font-size:15px;font-weight:700"><?= e($u['password']) ?></code></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <div class="row gap-2 row-wrap"><a class="btn btn-primary" href="process.php?action=switch_branch&id=<?= (int)$result['branch_id'] ?>&back=index.php"><?= icon('arrow-right', 'i-sm') ?> Filialni ochish</a><a class="btn" href="filiallar.php">Filiallar</a><a class="btn" href="import.php">Yana import</a></div>
  </section>

<?php elseif ($step === 'preview'): $users = $plan['users']; $b = $meta['branch_id'] ? branch_get($meta['branch_id']) : null;
      $defaults = sqlimp_default_user_map($users, (int)$meta['branch_id']);
      $existing = db()->query("SELECT id, username, role, branch_id FROM users WHERE is_active = 1 ORDER BY username")->fetchAll(); ?>
  <section class="card card-pad stack">
    <div class="row"><span class="icon-tile primary"><?= icon('clipboard-check') ?></span><div><h2 class="card-title">Tekshirib ko'ring</h2><p class="fs-sm text-3"><?= e($meta['file']) ?> → <b><?= e($b ? $b['name'] : $meta['new_branch'] . ' (yangi filial)') ?></b></p></div></div>
    <div class="grid g-2 md-g-4" style="gap:10px">
      <div class="card kpi" style="box-shadow:none"><span class="kpi-label">Kirim</span><div class="kpi-value num text-in" style="font-size:20px">+<?= e(fmt_short($plan['income_sum'])) ?></div><div class="kpi-foot"><?= fmt($plan['income_n']) ?> ta</div></div>
      <div class="card kpi" style="box-shadow:none"><span class="kpi-label">Chiqim</span><div class="kpi-value num text-out" style="font-size:20px">−<?= e(fmt_short($plan['expense_sum'])) ?></div><div class="kpi-foot"><?= fmt($plan['expense_n']) ?> ta</div></div>
      <div class="card kpi" style="box-shadow:none"><span class="kpi-label">Davr</span><div class="kpi-value" style="font-size:15px;line-height:1.4"><?= $plan['date_min'] ? e(date('d.m.Y', strtotime($plan['date_min']))) . '<br>' . e(date('d.m.Y', strtotime($plan['date_max']))) : '—' ?></div></div>
      <div class="card kpi" style="box-shadow:none"><span class="kpi-label">O'tkazib yuborildi</span><div class="kpi-value num" style="font-size:20px"><?= fmt($plan['skipped_empty'] + $plan['duplicates'] + $plan['unknown']) ?></div><div class="kpi-foot"><?= fmt($plan['skipped_empty']) ?> bo'sh · <?= fmt($plan['duplicates']) ?> takror<?= $plan['unknown'] ? ' · ' . fmt($plan['unknown']) . ' noaniq' : '' ?></div></div>
    </div>
    <?php if (!$plan['items']): ?><div class="alert error"><?= icon('circle-alert') ?><div>Import qilinadigan yangi yozuv yo'q (hammasi oldin yuklangan yoki bo'sh).</div></div><?php endif; ?>

    <?php if ($plan['items']): ?>
    <form action="import.php" method="post" class="stack" data-loading>
      <?= csrf_field() ?><input type="hidden" name="step" value="commit"><input type="hidden" name="token" value="<?= e($meta['token']) ?>">
      <div>
        <h3 class="fw-7" style="margin-bottom:4px">Kim kiritgan?</h3>
        <p class="fs-sm text-3" style="margin-bottom:10px">Eski tizimdagi «entered_by» qiymatini yangi tizim foydalanuvchisiga bog'lang.</p>
        <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Eski qiymat</th><th class="r">Yozuvlar</th><th>Yangi tizimda</th></tr></thead><tbody>
        <?php $i = 0; foreach ($users as $name => $n): $d = $defaults[$name] ?? ['mode' => 'none']; ?>
          <tr>
            <td class="cell-title"><b><?= $name === '' ? '(bo\'sh)' : e($name) ?></b></td>
            <td class="r num" data-label="Yozuvlar"><?= fmt($n) ?></td>
            <td data-label="Bog'lash"><select class="select input-sm" name="map[<?= $i ?>]" style="min-width:230px">
              <?php if ($name !== ''): ?><option value="new" <?= $d['mode'] === 'new' ? 'selected' : '' ?>>➕ Yangi kassir yaratish (<?= e(strtolower($name)) ?>)</option><?php endif; ?>
              <option value="none" <?= $d['mode'] === 'none' ? 'selected' : '' ?>>Kimligi ko'rsatilmasin</option>
              <?php foreach ($existing as $u): ?><option value="u:<?= (int)$u['id'] ?>" <?= ($d['mode'] === 'existing' && (int)$d['user_id'] === (int)$u['id']) ? 'selected' : '' ?>><?= e($u['username']) ?> (<?= e(role_label($u['role'])) ?>)</option><?php endforeach; ?>
            </select></td>
          </tr>
        <?php $i++; endforeach; ?>
        </tbody></table></div>
      </div>
      <div class="alert info"><?= icon('database-backup') ?><div class="grow">Import oldidan serverda avtomatik <b>zaxira nusxa</b> olinadi. Kategoriyalar izohga qarab avtomatik belgilanadi (To'lov, kitob, shop, Oylik Maosh, Ijara, Boshqa xarajat).</div></div>
      <div class="row gap-2" style="justify-content:flex-end;flex-wrap:wrap"><a class="btn" href="import.php">Bekor qilish</a><button class="btn btn-primary btn-lg" type="submit"><?= icon('upload', 'i-sm') ?> <?= fmt(count($plan['items'])) ?> ta yozuvni import qilish</button></div>
    </form>
    <?php endif; ?>
  </section>

<?php else: ?>
  <form action="import.php" method="post" enctype="multipart/form-data" class="card card-pad stack" data-loading>
    <?= csrf_field() ?><input type="hidden" name="step" value="preview">
    <label class="drop" id="drop">
      <input type="file" name="dump" id="dumpFile" accept=".sql,text/plain,application/sql" required>
      <span class="icon-tile primary" style="margin:0 auto 10px;width:52px;height:52px;border-radius:16px"><?= icon('upload', 'i-lg') ?></span>
      <b id="dropName" style="display:block">.sql faylni tanlang yoki shu yerga tashlang</b>
      <span class="fs-sm text-3">phpMyAdmin → Export → SQL. «transactions» jadvali (id, date, payment, cash, click, cash_in, cash_out, comment, entered_by, created_at).</span>
    </label>
    <div class="field"><label class="label" for="ib">Qaysi filialga</label>
      <select class="select" name="branch_id" id="ib"><option value="0">➕ Yangi filial yaratish…</option><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= scope_branch() === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field" id="newBranchField"><label class="label" for="inb">Yangi filial nomi</label><input class="input" name="new_branch" id="inb" value="Yangibozor Markaz" placeholder="Filial nomi"></div>
    <div class="grid g-2" style="gap:12px">
      <div class="field"><label class="label" for="itz">Eski tizim vaqti (UTC+)</label><input class="input" type="number" name="tz" id="itz" value="5" min="-12" max="14"><span class="hint">Toshkent = 5. «created_at» shu vaqt zonasida deb olinadi.</span></div>
      <label class="row" style="align-items:flex-start;gap:10px;padding-top:22px"><span class="switch"><input type="checkbox" name="skip_empty" value="1" checked><i></i></span><span><b class="fs-md">Bo'sh yozuvlarni o'tkazib yuborish</b><br><span class="hint">Summa 0, yo'nalish va izoh yo'q</span></span></label>
    </div>
    <button class="btn btn-primary btn-lg" type="submit"><?= icon('search', 'i-sm') ?> Tekshirish</button>
    <p class="hint">Takroriy yuklash xavfsiz: har bir yozuv «sql:ID» belgisi bilan saqlanadi va ikkinchi marta qo'shilmaydi.</p>
  </form>
<?php endif; ?>
</div>
<?php
$js = '<script>(function(){var s=document.getElementById("ib"),f=document.getElementById("newBranchField"),i=document.getElementById("dumpFile"),n=document.getElementById("dropName"),d=document.getElementById("drop");
if(!s)return; function t(){f.hidden=s.value!=="0";} s.addEventListener("change",t); t();
if(i){ i.addEventListener("change",function(){ if(i.files[0]) n.textContent=i.files[0].name+" ("+Math.round(i.files[0].size/1024)+" KB)"; });
 ["dragenter","dragover"].forEach(function(e){d.addEventListener(e,function(ev){ev.preventDefault();d.classList.add("over");});});
 ["dragleave","drop"].forEach(function(e){d.addEventListener(e,function(ev){ev.preventDefault();d.classList.remove("over");});});
 d.addEventListener("drop",function(ev){ if(ev.dataTransfer.files.length){ i.files=ev.dataTransfer.files; i.dispatchEvent(new Event("change")); } }); }})();</script>';
ui_end(['active' => 'admin', 'scripts' => $js]);
