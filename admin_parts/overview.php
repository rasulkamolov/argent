<?php defined('ADMIN_PAGE') || exit;
$tot = ['d_in' => 0, 'd_out' => 0, 'm_in' => 0, 'm_out' => 0, 'bal' => 0];
foreach ($ov as $r) { $tot['d_in'] += $r['d_in']; $tot['d_out'] += $r['d_out']; $tot['m_in'] += $r['m_in']; $tot['m_out'] += $r['m_out']; if ($r['tracked']) $tot['bal'] += $r['bal']['total']; }
$untracked = count(array_filter($ov, function ($r) { return !$r['tracked'] && $r['b']['is_active']; }));
?>
<?php foreach ($alerts as [$type, $msg, $href, $label]): ?>
<div class="alert <?= $type === 'danger' ? 'error' : 'info' ?>" role="alert" style="<?= $type === 'warn' ? 'background:var(--warn-soft);color:var(--warn-text)' : '' ?>"><?= icon($type === 'danger' ? 'shield-alert' : 'triangle-alert') ?>
  <div class="grow"><?= e($msg) ?></div>
  <a class="btn btn-sm" href="<?= e($href) ?>" <?= $href[0] === '#' ? 'onclick="document.getElementById(\'t-' . e(substr($href, 1)) . '\').click();return false"' : '' ?>><?= e($label) ?></a>
</div>
<?php endforeach; ?>

<section class="grid g-2 lg-g-4" aria-label="Tarmoq ko'rsatkichlari">
  <div class="card kpi hero2 span-full-m" style="grid-column:span 1"><div class="kpi-top"><span class="kpi-label">Jami kassada</span><?= icon('wallet') ?></div>
    <div class="kpi-value num"><?= e(fmt_short($tot['bal'])) ?></div><div class="kpi-foot"><?= $untracked ? $untracked . " ta filialda qoldiq sozlanmagan" : count($B) . " ta filial bo'yicha" ?></div></div>
  <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Bugun kirim</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('trending-up', 'i-sm') ?></span></div>
    <div class="kpi-value num text-in">+<?= fmt_short($tot['d_in']) ?></div><div class="kpi-foot">Chiqim: −<?= fmt_short($tot['d_out']) ?></div></div>
  <div class="card kpi"><div class="kpi-top"><span class="kpi-label"><?= e(mb_convert_case(uz_month((int)date('n')), MB_CASE_TITLE)) ?> kirimi</span><span class="icon-tile in" style="width:32px;height:32px;border-radius:10px"><?= icon('arrow-down-left', 'i-sm') ?></span></div>
    <div class="kpi-value num text-in">+<?= fmt_short($tot['m_in']) ?></div><div class="kpi-foot">Chiqim: −<?= fmt_short($tot['m_out']) ?></div></div>
  <div class="card kpi"><div class="kpi-top"><span class="kpi-label">Oy foydasi</span><span class="icon-tile primary" style="width:32px;height:32px;border-radius:10px"><?= icon('badge-dollar-sign', 'i-sm') ?></span></div>
    <div class="kpi-value num <?= ($tot['m_in'] - $tot['m_out']) < 0 ? 'text-out' : '' ?>"><?= e(fmt_short($tot['m_in'] - $tot['m_out'])) ?></div><div class="kpi-foot">marja <?= $tot['m_in'] > 0 ? number_format(($tot['m_in'] - $tot['m_out']) / $tot['m_in'] * 100, 1, ',', '') : '0' ?>%</div></div>
</section>

<section class="card clip" aria-labelledby="ovT">
  <div class="card-head"><h2 class="card-title" id="ovT"><?= icon('store', 'i-sm') ?> Filiallar holati</h2><a class="btn btn-ghost btn-sm" href="filiallar.php">Batafsil <?= icon('arrow-right', 'i-xs') ?></a></div>
  <div class="table-wrap">
    <table class="table table-stack">
      <thead><tr><th>Filial</th><th class="r">Bugun kirim</th><th class="r">Bugun chiqim</th><th class="r">Oy kirimi</th><th class="r">Kassada</th><th>Kassirlar</th></tr></thead>
      <tbody>
      <?php foreach ($ov as $r): $b = $r['b']; ?>
        <tr>
          <td class="cell-title"><a class="row gap-2" href="process.php?action=switch_branch&id=<?= (int)$b['id'] ?>&back=index.php"><?= branch_avatar($b, 'sm') ?><b><?= e($b['name']) ?></b><?= $b['is_active'] ? '' : '<span class="badge">Arxiv</span>' ?></a></td>
          <td class="r num text-in" data-label="Bugun kirim">+<?= fmt($r['d_in']) ?></td>
          <td class="r num text-out" data-label="Bugun chiqim">−<?= fmt($r['d_out']) ?></td>
          <td class="r num fw-7" data-label="Oy kirimi"><?= fmt($r['m_in']) ?></td>
          <td class="r num fw-8" data-label="Kassada"><?= $r['tracked'] ? fmt($r['bal']['total']) : '<span class="text-3">—</span>' ?></td>
          <td data-label="Kassirlar"><?= (int)$r['cashiers'] + (int)$r['managers'] ?> ta</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="grid lg-g-2">
  <section class="card clip" aria-labelledby="qa">
    <div class="card-head"><h2 class="card-title" id="qa"><?= icon('sparkles', 'i-sm') ?> Tezkor amallar</h2></div>
    <div class="card-pad grid g-2">
      <button class="btn btn-soft" type="button" onclick="document.getElementById('t-branches').click();setTimeout(function(){document.getElementById('newBranch').click()},50)"><?= icon('store', 'i-sm') ?> Yangi filial</button>
      <button class="btn btn-soft" type="button" onclick="document.getElementById('t-users').click();setTimeout(function(){document.getElementById('newUser').click()},50)"><?= icon('user-plus', 'i-sm') ?> Kassir qo'shish</button>
      <a class="btn btn-soft" href="import.php"><?= icon('upload', 'i-sm') ?> Ma'lumot import</a>
      <form action="process.php" method="post" style="display:contents"><?= csrf_field() ?><input type="hidden" name="action" value="backup_now"><button class="btn btn-soft" type="submit"><?= icon('database-backup', 'i-sm') ?> Zaxira nusxa</button></form>
    </div>
  </section>
  <section class="card clip" aria-labelledby="rj">
    <div class="card-head"><h2 class="card-title" id="rj"><?= icon('history', 'i-sm') ?> So'nggi harakatlar</h2><button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('t-journal').click()">Jurnal <?= icon('arrow-right', 'i-xs') ?></button></div>
    <ul class="tl">
      <?php foreach (array_slice($journal, 0, 5) as $j): [$jl, $ji, $jc2] = journal_meta($j['action']); ?>
      <li><span class="tl-ic <?= $jc2 ?>"><?= icon($ji, 'i-sm') ?></span><div><div class="tl-t"><?= e($jl) ?> <span class="text-3 fw-5">· <?= e($j['username'] ?: '—') ?></span></div><div class="tl-d"><?= e($j['branch_name'] ?: 'Umumiy') ?></div></div><time class="fs-xs text-3 num"><?= e(local_datetime($j['created_at'])) ?></time></li>
      <?php endforeach; ?>
      <?php if (!$journal): ?><li><div></div><div class="text-3">Hali harakat yo'q</div><div></div></li><?php endif; ?>
    </ul>
  </section>
</div>
