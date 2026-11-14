<?php defined('ADMIN_PAGE') || exit;
$jq = function (array $o) use ($jb, $ja, $jp) { return 'admin.php?' . http_build_query(array_filter(array_merge(['jb' => $jb ?: null, 'ja' => $ja ?: null, 'jp' => $jp > 1 ? $jp : null], $o), function ($v) { return $v !== null && $v !== ''; })) . '#journal'; };
$groups = ['' => 'Barcha harakatlar', 'tx' => 'Tranzaksiyalar', 'branch' => 'Filiallar', 'user' => 'Foydalanuvchilar', 'login' => 'Kirishlar', 'employee' => 'Xodimlar', 'category' => 'Kategoriyalar', 'cash' => 'Kassa sanash', 'import' => 'Import', 'denied' => 'Rad etilganlar']; ?>
<form method="get" action="admin.php#journal" class="row row-wrap" style="gap:8px">
  <select class="select input-sm" name="jb" style="max-width:220px" aria-label="Filial"><option value="0">Barcha filiallar</option><?php foreach ($B as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $jb === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
  <select class="select input-sm" name="ja" style="max-width:220px" aria-label="Harakat turi"><?php foreach ($groups as $k => $l): ?><option value="<?= e($k) ?>" <?= $ja === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm btn-primary" type="submit"><?= icon('filter', 'i-sm') ?> Qo'llash</button>
</form>
<section class="card clip">
  <ul class="tl">
    <?php foreach ($journal as $j): [$jl, $ji, $jt] = journal_meta($j['action']);
          $d = $j['details'] ? json_decode($j['details'], true) : null;
          $desc = '';
          if (is_array($d)) {
              if ($j['action'] === 'tx.delete') $desc = fmt($d['payment'] ?? 0) . " so'm · " . ($d['date'] ?? '') . ' · ' . mb_substr((string)($d['comment'] ?? ''), 0, 70);
              else $desc = implode(' · ', array_map(function ($k, $v) { return $k . ': ' . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v); }, array_keys($d), $d));
          } elseif ($j['details']) $desc = (string)$j['details']; ?>
    <li>
      <span class="tl-ic <?= e($jt) ?>"><?= icon($ji, 'i-sm') ?></span>
      <div>
        <div class="tl-t"><?= e($jl) ?></div>
        <div class="tl-d"><b class="text-2"><?= e($j['username'] ?: '—') ?></b> · <?= e($j['branch_name'] ?: 'Umumiy') ?><?= $desc !== '' ? ' · ' . e(mb_substr($desc, 0, 160)) : '' ?></div>
        <?php if ($j['action'] === 'tx.delete'): ?><form action="process.php" method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="restore_transaction"><input type="hidden" name="id" value="<?= (int)$j['id'] ?>"><button class="btn btn-sm btn-soft" type="submit"><?= icon('undo-2', 'i-sm') ?> Qaytarish</button></form><?php endif; ?>
      </div>
      <time class="fs-xs text-3 num" style="white-space:nowrap"><?= e(local_datetime($j['created_at'])) ?></time>
    </li>
    <?php endforeach; ?>
    <?php if (!$journal): ?><li><div></div><div class="text-3">Hozircha yozuv yo'q.</div><div></div></li><?php endif; ?>
  </ul>
  <?php if ($jpages > 1): ?>
  <div class="card-foot pager"><span class="fs-sm text-3"><?= fmt($jtotal) ?> ta yozuv · <?= $jp ?>/<?= $jpages ?></span>
    <nav class="pager-nav"><?php if ($jp > 1): ?><a href="<?= e($jq(['jp' => $jp - 1])) ?>" aria-label="Oldingi"><?= icon('chevron-left', 'i-sm') ?></a><?php endif; ?><?php if ($jp < $jpages): ?><a href="<?= e($jq(['jp' => $jp + 1])) ?>" aria-label="Keyingi"><?= icon('chevron-right', 'i-sm') ?></a><?php endif; ?></nav></div>
  <?php endif; ?>
</section>
