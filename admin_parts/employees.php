<?php defined('ADMIN_PAGE') || exit;
$fund = 0; $paidT = 0; foreach ($employees as $e_) { $fund += $e_['salary']; $paidT += min((float)$e_['paid'], (float)$e_['salary']); } ?>
<section class="grid g-3" aria-label="Oylik xulosa">
  <div class="card kpi"><span class="kpi-label">Oylik fondi</span><div class="kpi-value num" style="font-size:20px"><?= fmt_short($fund) ?></div></div>
  <div class="card kpi"><span class="kpi-label">To'langan</span><div class="kpi-value num text-in" style="font-size:20px"><?= fmt_short($paidT) ?></div></div>
  <div class="card kpi"><span class="kpi-label">Qoldi</span><div class="kpi-value num text-out" style="font-size:20px"><?= fmt_short(max(0, $fund - $paidT)) ?></div></div>
</section>
<div class="row between row-wrap">
  <p class="text-3 fs-sm"><?= e(mb_convert_case(uz_month((int)date('n')), MB_CASE_TITLE)) ?> oyi to'lovlari «Oylik» kategoriyasida xodim tanlab kiritilgan chiqimlardan hisoblanadi.</p>
  <button class="btn btn-primary" type="button" id="newEmp"><?= icon('plus', 'i-sm') ?> Yangi xodim</button>
</div>
<section class="card clip">
  <div class="table-wrap">
    <table class="table table-stack">
      <thead><tr><th>Xodim</th><th>Filial</th><th>Lavozim</th><th class="r">Oylik</th><th style="min-width:190px"><?= e(uz_month((int)date('n'))) ?> to'lovi</th><th class="r">Amallar</th></tr></thead>
      <tbody>
      <?php foreach ($employees as $emp): $paid = (float)$emp['paid']; $sal = (float)$emp['salary']; $pc = $sal > 0 ? min(100, $paid / $sal * 100) : 0;
            $status = $paid <= 0 ? ['badge', "To'lanmagan"] : ($paid >= $sal ? ['badge-in', "To'langan"] : ['badge-warn', 'Qisman']); ?>
        <tr>
          <td class="cell-title"><span class="row gap-2"><span class="avatar"><?= e(mb_substr($emp['name'], 0, 1)) ?></span><b><?= e($emp['name']) ?></b></span></td>
          <td data-label="Filial"><?php if ($emp['branch_id']): ?><span class="row gap-2" style="gap:8px;justify-content:flex-end"><?= branch_avatar(branch_get((int)$emp['branch_id']), 'xs') ?><?= e($emp['branch_name']) ?></span><?php endif; ?></td>
          <td class="text-2" data-label="Lavozim"><?= e($emp['position']) ?></td>
          <td class="r num fw-7" data-label="Oylik"><?= fmt($emp['salary']) ?></td>
          <td data-label="To'lov"><div style="min-width:150px;width:100%"><div class="row between fs-xs" style="margin-bottom:4px"><span class="badge <?= $status[0] ?>"><?= $status[1] ?></span><span class="num text-3"><?= fmt($paid) ?></span></div><div class="bar bar-thin in"><i style="width:<?= round($pc) ?>%"></i></div></div></td>
          <td class="r cell-actions"><div class="row gap-1" style="justify-content:flex-end">
            <button type="button" class="btn btn-sm btn-icon" data-edit-emp data-id="<?= (int)$emp['id'] ?>" data-name="<?= e($emp['name']) ?>" data-position="<?= e($emp['position']) ?>" data-salary="<?= (int)$emp['salary'] ?>" data-branch="<?= (int)$emp['branch_id'] ?>" data-phone="<?= e($emp['phone']) ?>" aria-label="Tahrirlash"><?= icon('pencil', 'i-sm') ?></button>
            <a class="btn btn-sm btn-icon btn-danger-soft" data-post href="process.php?action=delete_employee&id=<?= (int)$emp['id'] ?>" data-confirm="<?= e($emp['name']) ?> ro'yxatdan o'chiriladi." data-confirm-title="Xodimni o'chirasizmi?" data-confirm-ok="O'chirish" aria-label="O'chirish"><?= icon('trash-2', 'i-sm') ?></a>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$employees): ?><div class="empty"><div class="icon-tile"><?= icon('briefcase', 'i-lg') ?></div><b>Hali xodim yo'q</b><span class="fs-sm">Oylik hisoblash uchun xodim qo'shing.</span></div><?php endif; ?>
</section>
