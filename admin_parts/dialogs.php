<?php defined('ADMIN_PAGE') || exit; ?>
<!-- Branch create / edit -->
<dialog class="modal" id="branchFormDialog" aria-labelledby="bfTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="bfTitle">Yangi filial</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" id="bfAction" value="create_branch"><input type="hidden" name="id" id="bfId">
      <div class="field"><label class="label" for="bfName">Filial nomi</label><input class="input" id="bfName" name="name" required placeholder="Masalan: Yangibozor Markaz"></div>
      <div class="field"><label class="label" for="bfAddr">Manzil</label><input class="input" id="bfAddr" name="address" placeholder="Shahar, ko'cha"></div>
      <div class="field"><label class="label" for="bfPhone">Telefon</label><input class="input" id="bfPhone" name="phone" type="tel" inputmode="tel" placeholder="+998 …"></div>
      <div class="field"><span class="label">Rang (grafiklarda)</span>
        <div class="swatches" role="radiogroup" aria-label="Filial rangi">
          <?php foreach (BRANCH_PALETTE as $i => [$hex, $nm]): ?><label class="swatch" title="<?= e($nm) ?>" style="--c:<?= e($hex) ?>"><input type="radio" name="color" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>><span></span></label><?php endforeach; ?>
        </div></div>
      <div class="card card-pad" style="background:var(--surface-2);box-shadow:none;border-radius:16px">
        <label class="row" style="align-items:flex-start;gap:12px;cursor:pointer"><span class="switch"><input type="checkbox" name="track_balance" value="1" id="bfTrack"><i></i></span>
          <span><b class="fs-md">Kassa qoldig'ini yuritish</b><br><span class="hint">Yoqilsa: kassada qancha pul borligi, kun boshi/oxiri qoldig'i va «Kassani sanash» ishlaydi.</span></span></label>
        <div id="bfBalBox" hidden style="margin-top:14px">
          <span class="label">Hozir kassada haqiqatda qancha pul bor?</span>
          <div class="grid g-3" style="gap:8px;margin-top:6px">
            <label class="field"><span class="hint">Naqd</span><input class="input input-sm" name="bal_cash" id="bfCash" inputmode="numeric" data-money placeholder="0"></label>
            <label class="field"><span class="hint">Click</span><input class="input input-sm" name="bal_click" id="bfClick" inputmode="numeric" data-money placeholder="0"></label>
            <label class="field"><span class="hint">Bank</span><input class="input input-sm" name="bal_bank" id="bfBank" inputmode="numeric" data-money placeholder="0"></label>
          </div>
          <span class="hint" style="display:block;margin-top:8px">Tizim shu summalardan boshlab hisoblaydi (avvalgi yozuvlar hisobga olingan holda).</span>
        </div>
      </div>
      <div class="field"><label class="label" for="bfNote">Izoh</label><textarea class="textarea" id="bfNote" name="note" rows="2" style="min-height:64px"></textarea></div>
      <button type="submit" class="btn btn-primary btn-lg btn-block">Saqlash</button>
    </form>
  </div>
</dialog>

<!-- Branch delete (typed confirmation) -->
<dialog class="modal modal-sm" id="branchDelDialog" aria-labelledby="bdTitle">
  <div class="modal-box">
    <div class="row" style="align-items:flex-start;gap:14px"><span class="icon-tile out"><?= icon('triangle-alert') ?></span>
      <div class="grow"><h2 class="modal-title" id="bdTitle">Filialni o'chirish</h2>
        <p class="text-2 mt-1" id="bdMsg"></p></div></div>
    <form action="process.php" method="post" class="stack mt-4" data-loading autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_branch"><input type="hidden" name="id" id="bdId">
      <div class="field"><label class="label" for="bdName">Tasdiqlash uchun filial nomini yozing</label><input class="input" id="bdName" name="confirm_name" required autocomplete="off"></div>
      <p class="hint">O'chirishdan oldin serverda avtomatik zaxira nusxa saqlanadi. Ma'lumotlarni saqlab qolmoqchi bo'lsangiz «Arxivlash» dan foydalaning.</p>
      <div class="row gap-2" style="justify-content:flex-end"><button type="button" class="btn" data-close>Bekor qilish</button><button type="submit" class="btn btn-danger">Butunlay o'chirish</button></div>
    </form>
  </div>
</dialog>

<!-- User -->
<dialog class="modal" id="userDialog" aria-labelledby="uTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="uTitle">Yangi foydalanuvchi</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" id="uAction" value="create_user"><input type="hidden" name="id" id="uId">
      <div class="field"><span class="label">Rol</span>
        <div class="seg seg-block" role="radiogroup" id="uRoleSeg">
          <label><input type="radio" name="role" value="staff" checked> Kassir</label>
          <label><input type="radio" name="role" value="manager"> Menejer</label>
          <label><input type="radio" name="role" value="admin"> Admin</label>
        </div>
        <span class="hint" id="uRoleHint">Kassir: faqat o'z filialida kirim-chiqim kiritadi.</span></div>
      <div class="field" id="uBranchField"><label class="label" for="uBranch">Filial</label>
        <select class="select" name="branch_id" id="uBranch"><option value="">— Tanlang —</option><?php foreach ($B as $b): if (!$b['is_active']) continue; ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label class="label" for="uName">Login</label><input class="input" id="uName" name="username" autocapitalize="none" autocorrect="off" required></div>
      <div class="field"><label class="label" for="uFull">To'liq ism</label><input class="input" id="uFull" name="full_name" placeholder="Ixtiyoriy"></div>
      <div class="field"><label class="label" for="uPhone">Telefon</label><input class="input" id="uPhone" name="phone" type="tel" inputmode="tel" placeholder="Ixtiyoriy"></div>
      <div class="field"><label class="label" for="uPass">Parol</label>
        <div class="input-icon"><?= icon('lock') ?><input class="input" id="uPass" type="password" name="password" autocomplete="new-password" style="padding-right:96px">
          <span class="row gap-1" style="position:absolute;right:4px;top:50%;transform:translateY(-50%);gap:0">
            <button type="button" class="btn btn-ghost btn-icon btn-sm" id="uGen" aria-label="Parol yaratish" title="Parol yaratish"><?= icon('wand-sparkles', 'i-sm') ?></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" data-toggle-pass="#uPass" aria-label="Parolni ko'rsatish"><?= icon('eye', 'i-sm') ?></button></span></div>
        <span class="hint" id="uPassHint">Kamida 6 ta belgi. Parolni xodimga o'zingiz yetkazasiz.</span></div>
      <button type="submit" class="btn btn-primary btn-lg btn-block">Saqlash</button>
    </form>
  </div>
</dialog>

<!-- Employee -->
<dialog class="modal" id="empDialog" aria-labelledby="eTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="eTitle">Yangi xodim</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" id="eAction" value="create_employee"><input type="hidden" name="id" id="eId">
      <div class="field"><label class="label" for="eBranch">Filial</label>
        <select class="select" name="branch_id" id="eBranch" required><?php foreach ($B as $b): if (!$b['is_active']) continue; ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label class="label" for="eName">F.I.SH</label><input class="input" id="eName" name="name" required></div>
      <div class="field"><label class="label" for="ePos">Lavozim</label><input class="input" id="ePos" name="position"></div>
      <div class="field"><label class="label" for="ePhone">Telefon</label><input class="input" id="ePhone" name="phone" type="tel" inputmode="tel"></div>
      <div class="field"><label class="label" for="eSal">Oylik summasi</label><div class="money-field"><input class="input" style="font-size:24px;min-height:60px" id="eSal" name="salary" inputmode="numeric" data-money placeholder="0" required><span class="unit">so'm</span></div></div>
      <button type="submit" class="btn btn-primary btn-lg btn-block">Saqlash</button>
    </form>
  </div>
</dialog>

<!-- Category -->
<dialog class="modal" id="catDialog" aria-labelledby="cTitle">
  <div class="modal-box">
    <div class="modal-head"><h2 class="modal-title" id="cTitle">Yangi kategoriya</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Yopish"><?= icon('x') ?></button></div>
    <form action="process.php" method="post" class="stack" data-loading autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" id="cAction" value="add_category"><input type="hidden" name="id" id="cId">
      <div class="seg seg-block" role="radiogroup" aria-label="Turi"><label class="is-in"><input type="radio" name="type" value="income" checked> Kirim</label><label class="is-out"><input type="radio" name="type" value="expense"> Chiqim</label></div>
      <div class="field"><label class="label" for="cName">Nomi</label><input class="input" id="cName" name="name" required placeholder="Masalan: Kommunal"></div>
      <div class="field"><label class="label" for="cBranch">Qaysi filialda</label>
        <select class="select" name="branch_id" id="cBranch"><option value="0">Barcha filiallar</option><?php foreach ($B as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
      <button type="submit" class="btn btn-primary btn-lg btn-block">Saqlash</button>
    </form>
  </div>
</dialog>
