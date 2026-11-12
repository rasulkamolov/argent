<?php /** Result table of a counted inventory check (included by inventory_check.php; $rows = shop_inv_items(..., true) rows). Only counted rows carry system numbers. */ ?>
<div class="table-wrap"><table class="table table-stack"><thead><tr><th>Tovar</th><th>Sabab</th><th class="r">Tizimda</th><th class="r">Sanalgan</th><th class="r">Farq (dona)</th><th class="r">Tannarx bo'yicha</th><th class="r">Sotuv narxi bo'yicha</th></tr></thead><tbody>
<?php foreach ($rows as $it): if (!$it['counted']) continue; $neg = $it['diff_qty'] < -0.0005; $pos = $it['diff_qty'] > 0.0005; ?>
  <tr class="<?= $neg ? 'lc-ev is-red' : '' ?>"><td class="cell-title"><b><?= e($it['product_name']) ?></b></td><td data-label="Sabab"><?= e($it['reason']) ?></td>
    <td class="r num" data-label="Tizimda"><?= e(shop_qty($it['sys_qty'])) ?></td><td class="r num" data-label="Sanalgan"><b><?= e(shop_qty($it['counted_qty'])) ?></b></td>
    <td class="r num fw-8 <?= $neg ? 'text-out' : ($pos ? 'text-in' : '') ?>" data-label="Farq"><?= ($neg || $pos) ? e(($pos ? '+' : '−') . shop_qty(abs($it['diff_qty']))) : '0 ✅' ?></td>
    <td class="r num <?= $neg ? 'text-out' : '' ?>" data-label="Tannarx"><?= ($neg || $pos) ? e(fmt_signed($it['diff_cost'])) : '0' ?></td><td class="r num <?= $neg ? 'text-out' : '' ?>" data-label="Sotuv narxi"><?= ($neg || $pos) ? e(fmt_signed($it['diff_sale'])) : '0' ?></td></tr>
<?php endforeach; ?></tbody></table></div>
