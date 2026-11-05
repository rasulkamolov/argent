/* OXFORD multi-branch — shared UI behaviour (no dependencies) */
(function () {
  'use strict';
  var doc = document;
  var $ = function (s, r) { return (r || doc).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || doc).querySelectorAll(s)); };

  function icon(name, cls) {
    return '<svg class="i ' + (cls || '') + '" aria-hidden="true"><use href="#' + name + '"/></svg>';
  }

  /* ---------- Toast ---------- */
  function toast(msg, type) {
    var box = $('#toasts');
    if (!box) return;
    var t = doc.createElement('div');
    t.className = 'toast ' + (type === 'error' ? 'error' : '');
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    t.innerHTML = icon(type === 'error' ? 'circle-alert' : 'circle-check') + '<span></span>';
    t.lastChild.textContent = msg;
    box.appendChild(t);
    setTimeout(function () { t.classList.add('leave'); setTimeout(function () { t.remove(); }, 300); }, type === 'error' ? 6000 : 3500);
  }
  window.toast = toast;
  if (window.__flash) toast(window.__flash.msg, window.__flash.type);

  /* ---------- Dialog (native <dialog>) ---------- */
  function openDialog(d) {
    if (!d) return;
    if (typeof d.showModal === 'function') { if (!d.open) d.showModal(); } else { d.setAttribute('open', ''); }
    var f = $('[autofocus], [data-autofocus]', d);
    if (f) setTimeout(function () { f.focus(); }, 60);
    d.dispatchEvent(new CustomEvent('dialogopen'));
  }
  function closeDialog(d) { if (d && d.open) { d.close ? d.close() : d.removeAttribute('open'); } }
  window.openDialog = openDialog; window.closeDialog = closeDialog;

  doc.addEventListener('click', function (e) {
    var o = e.target.closest('[data-open]');
    if (o) { e.preventDefault(); openDialog($(o.getAttribute('data-open'))); return; }
    var c = e.target.closest('[data-close]');
    if (c) { closeDialog(c.closest('dialog')); return; }
    if (e.target.matches && e.target.matches('dialog.modal')) closeDialog(e.target); // backdrop
  });

  /* ---------- Confirm dialog ---------- */
  var pendingConfirm = null;
  doc.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (!el || el.dataset.confirmed) { if (el) delete el.dataset.confirmed; return; }
    e.preventDefault(); e.stopPropagation(); closeMenu();
    var dlg = $('#confirmDialog'); if (!dlg) { if (window.confirm(el.dataset.confirm)) go(el); return; }
    $('#confirmTitle').textContent = el.dataset.confirmTitle || 'Tasdiqlang';
    $('#confirmMsg').textContent = el.dataset.confirm;
    var ok = $('#confirmOk'); ok.textContent = el.dataset.confirmOk || 'Ha, davom etish';
    ok.className = 'btn ' + (el.dataset.confirmTone === 'primary' ? 'btn-primary' : 'btn-danger');
    pendingConfirm = el; openDialog(dlg);
  }, true);
  /* ---------- POST links (CSRF-protected): <a data-post href="process.php?action=x&id=1"> ---------- */
  function csrf() { var m = $('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
  function postTo(href) {
    var u = new URL(href, window.location.href), f = doc.createElement('form');
    f.method = 'post'; f.action = u.origin + u.pathname; f.style.display = 'none';
    function add(k, v) { var i = doc.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); }
    u.searchParams.forEach(function (v, k) { add(k, v); });
    add('_csrf', csrf());
    doc.body.appendChild(f); f.submit();
  }
  window.postTo = postTo;
  doc.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-post]');
    if (!a || a.hasAttribute('data-confirm')) return;      // confirm flow handles those
    e.preventDefault(); postTo(a.href);
  });

  function go(el) {
    el.dataset.confirmed = '1';
    if (el.tagName === 'A' && el.hasAttribute('data-post')) { postTo(el.href); }
    else if (el.tagName === 'A') { window.location.href = el.href; }
    else if (el.form) { el.form.requestSubmit ? el.form.requestSubmit(el.type === 'submit' ? el : undefined) : el.form.submit(); }
    else el.click();
  }
  doc.addEventListener('click', function (e) {
    if (e.target.closest('#confirmOk') && pendingConfirm) { var el = pendingConfirm; pendingConfirm = null; closeDialog($('#confirmDialog')); go(el); }
  });

  /* ---------- Money input ---------- */
  function fmtDigits(d) { return d.replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
  function bindMoney(inp) {
    inp.addEventListener('input', function () {
      var pos = inp.selectionStart, before = inp.value.slice(0, pos).replace(/\D/g, '').length;
      var digits = inp.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
      inp.value = digits ? fmtDigits(digits) : '';
      var n = 0, np = inp.value.length;
      for (var i = 0; i < inp.value.length; i++) { if (/\d/.test(inp.value[i])) n++; if (n === before) { np = i + 1; break; } }
      if (before === 0) np = 0;
      try { inp.setSelectionRange(np, np); } catch (e) {}
    });
    if (inp.value) inp.value = fmtDigits(inp.value.replace(/\D/g, ''));
  }
  $$('[data-money]').forEach(bindMoney);

  /* ---------- Popover menus ---------- */
  var openMenu = null;
  function closeMenu() { if (openMenu) { openMenu.pop.remove(); openMenu.btn.setAttribute('aria-expanded', 'false'); openMenu = null; } }
  doc.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-menu]');
    if (!btn) { if (!e.target.closest('.menu-pop')) closeMenu(); else if (e.target.closest('a,button')) setTimeout(closeMenu, 0); return; }
    e.preventDefault();
    if (openMenu && openMenu.btn === btn) { closeMenu(); return; }
    closeMenu();
    var tpl = $(btn.getAttribute('data-menu')); if (!tpl) return;
    var pop = doc.createElement('div'); pop.className = 'menu-pop'; pop.setAttribute('role', 'menu');
    pop.innerHTML = tpl.innerHTML; doc.body.appendChild(pop);
    var r = btn.getBoundingClientRect(), ph = pop.offsetHeight, pw = pop.offsetWidth;
    var top = r.bottom + 6; if (top + ph > innerHeight - 8) top = Math.max(8, r.top - ph - 6);
    var left = Math.min(Math.max(8, r.right - pw), innerWidth - pw - 8);
    pop.style.top = top + 'px'; pop.style.left = left + 'px';
    btn.setAttribute('aria-expanded', 'true'); openMenu = { btn: btn, pop: pop }; menuY = window.scrollY; menuW = window.innerWidth;
    var first = $('a,button', pop); if (first && e.detail === 0) first.focus();
  });
  doc.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
  // close on a real scroll (not on the tiny layout shifts mobile browsers fire), or when the width changes
  var menuY = 0, menuW = 0;
  window.addEventListener('scroll', function () { if (openMenu && Math.abs(window.scrollY - menuY) > 24) closeMenu(); }, { passive: true });
  window.addEventListener('resize', function () { if (openMenu && window.innerWidth !== menuW) closeMenu(); });

  /* ---------- Tabs (with #hash) ---------- */
  $$('[data-tabs]').forEach(function (wrap) {
    var tabs = $$('[role="tab"]', wrap);
    function show(id, push) {
      var found = false;
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-tab') === id; if (on) found = true;
        t.setAttribute('aria-selected', on);
        var p = $('#' + t.getAttribute('aria-controls')); if (p) p.hidden = !on;
        if (on && t.scrollIntoView) { try { t.scrollIntoView({ inline: 'center', block: 'nearest' }); } catch (e) {} }
      });
      if (found && push) { history.replaceState(null, '', '#' + id); }
      return found;
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab'), true); window.scrollTo({ top: 0 }); }); });
    var h = location.hash.slice(1);
    if (!h || !show(h, false)) show(tabs[0].getAttribute('data-tab'), false);
  });

  /* ---------- Password reveal ---------- */
  doc.addEventListener('click', function (e) {
    var b = e.target.closest('[data-toggle-pass]'); if (!b) return;
    var inp = $(b.getAttribute('data-toggle-pass')); if (!inp) return;
    var show = inp.type === 'password'; inp.type = show ? 'text' : 'password';
    b.innerHTML = icon(show ? 'eye-off' : 'eye'); b.setAttribute('aria-label', show ? 'Parolni yashirish' : 'Parolni ko‘rsatish');
  });

  /* ---------- Submit loading state ---------- */
  doc.addEventListener('submit', function (e) {
    var f = e.target; if (e.defaultPrevented || !f.matches('form[data-loading]')) return;
    var btn = $('button[type="submit"]', f); if (btn) setTimeout(function () { btn.classList.add('loading'); }, 0);
  });
  window.addEventListener('pageshow', function () { $$('.btn.loading').forEach(function (b) { b.classList.remove('loading'); }); });

  /* ---------- Auto-submit switches ---------- */
  doc.addEventListener('change', function (e) {
    var sw = e.target.closest('[data-autosubmit]'); if (!sw) return;
    var f = sw.form; var v = $('input[name="value"]', f); if (v) v.value = sw.checked ? '1' : '0'; f.submit();
  });

  /* ---------- Live list filter ---------- */
  $$('[data-filter-list]').forEach(function (inp) {
    var list = $(inp.getAttribute('data-filter-list')); if (!list) return;
    var empty = $(inp.getAttribute('data-filter-empty') || '#none');
    function run() {
      var q = inp.value.trim().toLowerCase(), n = 0;
      $$('[data-search]', list).forEach(function (el) {
        var typeOk = !list.dataset.type || list.dataset.type === 'all' || el.dataset.type === list.dataset.type;
        var ok = typeOk && (!q || el.dataset.search.indexOf(q) !== -1);
        el.hidden = !ok; if (ok) n++;
      });
      if (empty) empty.hidden = n !== 0;
    }
    inp.addEventListener('input', run); inp._run = run;
  });
  $$('[data-filter-type]').forEach(function (grp) {
    var list = $(grp.getAttribute('data-filter-type'));
    grp.addEventListener('click', function (e) {
      var b = e.target.closest('[data-type]'); if (!b) return;
      list.dataset.type = b.dataset.type;
      $$('[data-type]', grp).forEach(function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b); });
      var inp = $('[data-filter-list="#' + list.id + '"]'); if (inp && inp._run) inp._run(); else {
        $$('[data-search]', list).forEach(function (el) { el.hidden = !(b.dataset.type === 'all' || el.dataset.type === b.dataset.type); });
      }
    });
  });

  /* ---------- Misc ---------- */
  /* ---------- Payment proof drop zone: <div class="proof-drop" data-proof [data-required]> ---------- */
  function fmtSize(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  // downscale big phone screenshots/photos before uploading (saves mobile data); falls back to the original file
  function compressImage(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return resolve(file);
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, sc = Math.min(1, 1600 / Math.max(w, h));
        if (sc === 1 && file.size < 700 * 1024) { URL.revokeObjectURL(url); return resolve(file); }
        var c = doc.createElement('canvas'); c.width = Math.round(w * sc); c.height = Math.round(h * sc);
        var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(img, 0, 0, c.width, c.height);
        c.toBlob(function (b) {
          URL.revokeObjectURL(url);
          if (!b || (sc === 1 && b.size >= file.size)) return resolve(file);
          resolve(new File([b], (file.name || 'skrinshot').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.86);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  function initProof(box) {
    if (box._ready) return; box._ready = true;
    var inp = $('input[type="file"]', box), empty = $('.pd-empty', box), prev = $('.pd-preview', box), img = $('img', prev), meta = $('.pd-meta', prev), clearBtn = $('[data-proof-clear]', box);
    var tile = $('.pd-filetile', prev);
    function show(f) {
      if (!f) { prev.hidden = true; empty.hidden = false; if (img.src) URL.revokeObjectURL(img.src); img.removeAttribute('src'); img.hidden = false; if (tile) tile.hidden = true; box.classList.remove('need'); return; }
      var isImg = /^image\//.test(f.type);
      if (isImg) { img.src = URL.createObjectURL(f); } else { img.removeAttribute('src'); }
      img.hidden = !isImg; if (tile) tile.hidden = isImg;
      meta.innerHTML = '<b></b><span></span>'; meta.firstChild.textContent = f.name || 'fayl'; meta.lastChild.textContent = fmtSize(f.size) + (isImg ? ' · tayyor' : ' · ' + (f.name || '').split('.').pop().toUpperCase());
      empty.hidden = true; prev.hidden = false; box.classList.remove('need');
    }
    var OK_EXT = /\.(pdf|docx?|xlsx?|odt|rtf|txt|csv)$/i;
    function setFile(f) {
      var okType = f && (/^image\//.test(f.type) || f.type === 'application/pdf' || OK_EXT.test(f.name || ''));
      if (!okType) { toast('Ruxsat etilgan: rasm, PDF, Word, Excel, TXT/CSV', 'error'); return; }
      if (f.size > 15 * 1024 * 1024) { toast('Fayl juda katta (maks. 15 MB)', 'error'); return; }
      compressImage(f).then(function (g) {
        try { var dt = new DataTransfer(); dt.items.add(g); inp.files = dt.files; } catch (e) { /* old browser: original file stays in the input */ }
        show(g);
      });
    }
    box.setFile = setFile;
    box.addEventListener('click', function (e) { if (e.target.closest('[data-proof-clear]')) return; inp.click(); });
    box.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } });
    inp.addEventListener('click', function (e) { e.stopPropagation(); });
    inp.addEventListener('change', function () { if (inp.files && inp.files[0]) setFile(inp.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) { box.addEventListener(ev, function (e) { e.preventDefault(); box.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { box.addEventListener(ev, function (e) { e.preventDefault(); box.classList.remove('over'); }); });
    box.addEventListener('drop', function (e) { if (e.dataTransfer.files && e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); });
    if (clearBtn) clearBtn.addEventListener('click', function (e) { e.stopPropagation(); try { inp.value = ''; } catch (x) {} show(null); });
    box.reset = function () { try { inp.value = ''; } catch (x) {} show(null); };
  }
  window.initProof = initProof;
  $$('[data-proof]').forEach(initProof);
  // Ctrl+V / Cmd+V a screenshot straight into the visible drop zone
  doc.addEventListener('paste', function (e) {
    var items = (e.clipboardData || {}).items || [], file = null;
    for (var i = 0; i < items.length; i++) if (items[i].kind === 'file' && /^image\//.test(items[i].type)) { file = items[i].getAsFile(); break; }
    if (!file) return;
    var box = $$('[data-proof]').filter(function (b) { return b.offsetParent !== null; })[0];
    if (box && box.setFile) { e.preventDefault(); box.setFile(file); }
  });
  // required proof: block submit with a clear message
  doc.addEventListener('submit', function (e) {
    var f = e.target, box = $$('[data-proof][data-required]', f).filter(function (b) { return b.offsetParent !== null; })[0];
    if (!box) return;
    var inp = $('input[type="file"]', box);
    if (!inp.files || !inp.files.length) { e.preventDefault(); box.classList.add('need'); box.scrollIntoView({ block: 'center', behavior: 'smooth' }); toast("To'lov skrinshotini biriktiring", 'error'); }
  }, true);
  // viewer
  doc.addEventListener('click', function (e) {
    var v = e.target.closest('[data-proof-view]'); if (!v) return;
    e.preventDefault(); closeMenu();
    var d = $('#proofDialog'); if (!d) return;
    var src = 'proof.php?id=' + encodeURIComponent(v.getAttribute('data-proof-view')), kind = v.getAttribute('data-proof-kind') || 'image', nm = v.getAttribute('data-proof-name') || '';
    var im = $('#pvImg', d), fl = $('#pvFile', d);
    if (kind === 'image') { im.hidden = false; fl.hidden = true; im.src = src; }
    else { im.hidden = true; im.removeAttribute('src'); fl.hidden = false; $('#pvName', d).textContent = nm || 'fayl'; $('#pvKind', d).textContent = kind === 'pdf' ? 'PDF hujjat' : 'Hujjat (yuklab olinadi)'; }
    $('#proofOpen', d).href = src; $('#proofOpen', d).hidden = (kind === 'file'); $('#proofDl', d).href = src + '&dl=1';
    openDialog(d);
  });
  var pdlg = $('#proofDialog'); if (pdlg) pdlg.addEventListener('close', function () { $('#pvImg', pdlg).removeAttribute('src'); });

  // Auto-grow textareas
  doc.addEventListener('input', function (e) {
    var t = e.target; if (t.matches && t.matches('textarea[data-autogrow]')) { t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight + 2, 260) + 'px'; }
  });
  // Date range sanity (reports): keep end >= start
  $$('form[data-range]').forEach(function (f) {
    var s = $('[name="start"]', f), en = $('[name="end"]', f); if (!s || !en) return;
    s.addEventListener('change', function () { if (en.value && s.value > en.value) en.value = s.value; });
    en.addEventListener('change', function () { if (s.value && en.value < s.value) s.value = en.value; });
  });
})();
