<?php defined('ADMIN_PAGE') || exit; ?>
<div class="row between row-wrap">
  <div class="row row-wrap grow" style="gap:8px;max-width:620px">
    <div class="input-icon grow" style="min-width:200px"><?= icon('search') ?><input class="input input-sm" style="padding-left:42px" type="search" placeholder="Foydalanuvchini qidirish…" aria-label="Qidirish" data-filter-list="#userBody" data-filter-empty="#userNone"></div>
    <select class="select input-sm" id="userBranchFilter" style="max-width:220px" aria-label="Filial bo'yicha">
      <option value="">Barcha filiallar</option>
      <?php foreach ($B as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary" type="button" id="newUser"><?= icon('user-plus', 'i-sm') ?> Yangi foydalanuvchi</button>
</div>
<section class="card clip">
  <div class="table-wrap">
    <table class="table table-stack">
      <thead><tr><th>Foydalanuvchi</th><th>Rol</th><th>Filial</th><th>Holati</th><th>Oxirgi kirish</th><th class="r">Amallar</th></tr></thead>
      <tbody id="userBody">
      <?php foreach ($users as $u): $self = (int)$u['id'] === (int)auth_user()['id']; ?>
        <tr data-search="<?= e(mb_strtolower($u['username'] . ' ' . $u['full_name'] . ' ' . $u['branch_name'])) ?>" data-branch="<?= (int)$u['branch_id'] ?>">
          <td class="cell-title"><span class="row gap-2"><span class="avatar"><?= e(mb_substr($u['username'], 0, 1)) ?></span><span><b><?= e($u['username']) ?></b><?= $self ? ' <span class="badge">Siz</span>' : '' ?><?php if ($u['full_name']): ?><br><span class="fs-xs text-3 fw-5"><?= e($u['full_name']) ?></span><?php endif; ?></span></span></td>
          <td data-label="Rol"><span class="badge <?= $u['role'] === 'admin' ? 'badge-primary' : ($u['role'] === 'manager' ? 'badge-warn' : 'badge-in') ?>"><?= e(role_label($u['role'])) ?></span></td>
          <td data-label="Filial"><?php if ($u['branch_id']): ?><span class="row gap-2" style="gap:8px;justify-content:flex-end"><?= branch_avatar(branch_get((int)$u['branch_id']), 'xs') ?><?= e($u['branch_name']) ?></span><?php else: ?><span class="text-3">Barcha filiallar</span><?php endif; ?></td>
          <td data-label="Holati"><?= $u['is_active'] ? '<span class="badge badge-in">● Faol</span>' : '<span class="badge badge-danger">● Bloklangan</span>' ?></td>
          <td class="text-2 fs-sm" data-label="Oxirgi kirish"><?= $u['last_login_at'] ? e(local_datetime($u['last_login_at'])) : '—' ?></td>
          <td class="r cell-actions">
            <div class="row gap-1" style="justify-content:flex-end">
              <button type="button" class="btn btn-sm btn-icon" data-edit-user data-id="<?= (int)$u['id'] ?>" data-username="<?= e($u['username']) ?>" data-full="<?= e($u['full_name']) ?>" data-phone="<?= e($u['phone']) ?>" data-role="<?= e($u['role']) ?>" data-branch="<?= (int)$u['branch_id'] ?>" aria-label="Tahrirlash"><?= icon('pencil', 'i-sm') ?></button>
              <?php if (!$self): ?>
                <a class="btn btn-sm btn-icon" data-post href="process.php?action=toggle_user&id=<?= (int)$u['id'] ?>" <?= $u['is_active'] ? 'data-confirm="' . e($u['username']) . ' tizimga kira olmaydi." data-confirm-title="Bloklaysizmi?" data-confirm-ok="Bloklash"' : '' ?> aria-label="<?= $u['is_active'] ? 'Bloklash' : 'Faollashtirish' ?>"><?= icon($u['is_active'] ? 'ban' : 'lock-open', 'i-sm') ?></a>
                <a class="btn btn-sm btn-icon btn-danger-soft" data-post href="process.php?action=delete_user&id=<?= (int)$u['id'] ?>" data-confirm="<?= e($u['username']) ?> o'chiriladi. Kiritgan tranzaksiyalari saqlanib qoladi." data-confirm-title="Foydalanuvchini o'chirasizmi?" data-confirm-ok="O'chirish" aria-label="O'chirish"><?= icon('trash-2', 'i-sm') ?></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="empty" id="userNone" hidden><div class="icon-tile"><?= icon('users', 'i-lg') ?></div><b>Topilmadi</b></div>
</section>
