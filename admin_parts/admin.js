(function(){
  var $ = function(id){ return document.getElementById(id); };
  function fmt(n){ return String(n || 0).replace(/\B(?=(\d{3})+(?!\d))/g, " "); }
  function setRadio(name, v, scope){ var r = (scope||document).querySelector('input[name="'+name+'"][value="'+v+'"]'); if (r) r.checked = true; }
  function show(id){ openDialog($(id)); }

  /* ----- branches ----- */
  $("newBranch").addEventListener("click", function(){
    $("bfTitle").textContent = "Yangi filial"; $("bfAction").value = "create_branch"; $("bfId").value = "";
    ["bfName","bfAddr","bfPhone","bfNote","bfCash","bfClick","bfBank"].forEach(function(i){ $(i).value = ""; }); setRadio("color", 0); $("bfTrack").checked = false; trackToggle(); show("branchFormDialog");
  });
  function trackToggle(){ $("bfBalBox").hidden = !$("bfTrack").checked; }
  $("bfTrack").addEventListener("change", trackToggle);
  document.addEventListener("click", function(e){
    var b = e.target.closest("[data-edit-branch]");
    if (b) { var d = b.dataset; $("bfTitle").textContent = "Filialni tahrirlash"; $("bfAction").value = "update_branch"; $("bfId").value = d.id;
      $("bfName").value = d.name; $("bfAddr").value = d.address; $("bfPhone").value = d.phone; $("bfNote").value = d.note;
      var tr = d.tracked === "1"; $("bfTrack").checked = tr; trackToggle();
      $("bfCash").value = tr ? fmt(d.cash) : ""; $("bfClick").value = tr ? fmt(d.click) : ""; $("bfBank").value = tr ? fmt(d.bank) : ""; setRadio("color", d.color); show("branchFormDialog"); return; }
    b = e.target.closest("[data-del-branch]");
    if (b) { var d = b.dataset; $("bdId").value = d.id; $("bdName").value = "";
      $("bdMsg").textContent = "«" + d.name + "» filiali" + (+d.tx ? " va unga tegishli " + fmt(d.tx) + " ta tranzaksiya" : "") + ", xodimlar va kassirlar o'chiriladi. Bu amalni qaytarib bo'lmaydi."; show("branchDelDialog"); return; }
    b = e.target.closest("[data-edit-user]");
    if (b) { var d = b.dataset; $("uTitle").textContent = "Foydalanuvchini tahrirlash"; $("uAction").value = "update_user"; $("uId").value = d.id;
      $("uName").value = d.username; $("uFull").value = d.full; $("uPhone").value = d.phone; $("uBranch").value = d.branch || ""; setRadio("role", d.role, $("uRoleSeg"));
      $("uPass").value = ""; $("uPass").required = false; $("uPassHint").textContent = "Bo'sh qoldirsangiz, parol o'zgarmaydi."; roleChange(); show("userDialog"); return; }
    b = e.target.closest("[data-edit-emp]");
    if (b) { var d = b.dataset; $("eTitle").textContent = "Xodimni tahrirlash"; $("eAction").value = "update_employee"; $("eId").value = d.id;
      $("eName").value = d.name; $("ePos").value = d.position; $("ePhone").value = d.phone; $("eSal").value = fmt(d.salary); if (d.branch) $("eBranch").value = d.branch; show("empDialog"); return; }
    b = e.target.closest("[data-edit-cat]");
    if (b) { var d = b.dataset; $("cTitle").textContent = "Kategoriyani tahrirlash"; $("cAction").value = "update_category"; $("cId").value = d.id;
      $("cName").value = d.name; setRadio("type", d.type, $("catDialog")); $("cBranch").value = d.branch || 0; show("catDialog"); }
  });

  /* ----- users ----- */
  var hints = { staff: "Kassir: faqat o'z filialida kirim-chiqim kiritadi.", manager: "Menejer: o'z filialida operatsiyalar + hisobotlar.", admin: "Administrator: barcha filiallar va barcha sozlamalar." };
  function roleChange(){
    var r = document.querySelector('#uRoleSeg input:checked').value;
    $("uBranchField").hidden = (r === "admin"); $("uBranch").required = (r !== "admin"); $("uRoleHint").textContent = hints[r];
  }
  document.querySelectorAll('#uRoleSeg input').forEach(function(i){ i.addEventListener("change", roleChange); });
  $("newUser").addEventListener("click", function(){
    $("uTitle").textContent = "Yangi foydalanuvchi"; $("uAction").value = "create_user"; $("uId").value = "";
    ["uName","uFull","uPhone","uPass"].forEach(function(i){ $(i).value = ""; }); $("uPass").required = true; $("uPassHint").textContent = "Kamida 6 ta belgi. Parolni xodimga o'zingiz yetkazasiz.";
    setRadio("role", "staff", $("uRoleSeg")); $("uBranch").value = ""; roleChange(); show("userDialog");
  });
  $("uGen").addEventListener("click", function(){
    var chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789", a = new Uint32Array(10), p = "";
    (window.crypto || window.msCrypto).getRandomValues(a); for (var i = 0; i < a.length; i++) p += chars[a[i] % chars.length];
    $("uPass").value = p; $("uPass").type = "text"; toast("Parol: " + p + " — nusxa oling"); try { navigator.clipboard && navigator.clipboard.writeText(p); } catch (e) {}
  });
  $("userBranchFilter").addEventListener("change", function(){
    var v = this.value; document.querySelectorAll("#userBody tr").forEach(function(tr){ tr.dataset.bhide = (v && tr.dataset.branch !== v) ? "1" : ""; });
    var inp = document.querySelector('[data-filter-list="#userBody"]'); if (inp && inp._run) inp._run();
    document.querySelectorAll("#userBody tr").forEach(function(tr){ if (tr.dataset.bhide) tr.hidden = true; });
  });

  /* ----- employees / categories ----- */
  $("newEmp").addEventListener("click", function(){
    $("eTitle").textContent = "Yangi xodim"; $("eAction").value = "create_employee"; $("eId").value = "";
    ["eName","ePos","ePhone","eSal"].forEach(function(i){ $(i).value = ""; }); show("empDialog");
  });
  $("newCat").addEventListener("click", function(){
    $("cTitle").textContent = "Yangi kategoriya"; $("cAction").value = "add_category"; $("cId").value = ""; $("cName").value = ""; setRadio("type", "income", $("catDialog")); $("cBranch").value = 0; show("catDialog");
  });

  /* ----- per-branch permission selects: submit on change ----- */
  document.addEventListener("change", function(e){
    var s = e.target.closest("select[data-bset]"); if (!s) return;
    var f = s.form; f.elements.key.value = s.dataset.bset; f.elements.value.value = s.value; f.submit();
  });
})();
