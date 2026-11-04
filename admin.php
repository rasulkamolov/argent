<?php
session_start();
include 'db.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: index.php"); exit(); }

$staff = $conn->query("SELECT * FROM users WHERE role='staff'")->fetchAll(PDO::FETCH_ASSOC);
$employees = $conn->query("SELECT * FROM employees")->fetchAll(PDO::FETCH_ASSOC);
$categories = $conn->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC);
$staff_can_edit = $conn->query("SELECT value FROM settings WHERE key='staff_can_edit'")->fetchColumn();
$staff_can_delete = $conn->query("SELECT value FROM settings WHERE key='staff_can_delete'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel — OXFORD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Plus Jakarta Sans',sans-serif; background-color: #fafafa;}</style>
</head>
<body class="text-zinc-800 pb-20">

    <nav class="sticky top-0 z-30 bg-white/80 backdrop-blur-md border-b border-zinc-200/60">
        <div class="max-w-6xl mx-auto px-6 h-16 flex justify-between items-center">
            <h1 class="font-bold text-lg text-zinc-900">OXFORD — Admin</h1>
            <a href="index.php" class="text-sm font-semibold text-zinc-500 hover:text-indigo-600 flex items-center gap-2 transition-colors"><i data-lucide="arrow-left" class="w-4 h-4"></i> Dashboard</a>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto px-6 py-10 space-y-8">

        <?php if(isset($_GET['cred_ok'])): ?>
        <div class="bg-emerald-50 text-emerald-700 border border-emerald-200 px-6 py-3 rounded-2xl text-sm font-semibold flex items-center gap-2">
            <i data-lucide="check-circle" class="w-4 h-4"></i> Login yoki parol muvaffaqiyatli yangilandi.
        </div>
        <?php elseif(isset($_GET['cred_error'])): ?>
        <div class="bg-rose-50 text-rose-700 border border-rose-200 px-6 py-3 rounded-2xl text-sm font-semibold flex items-center gap-2">
            <i data-lucide="alert-circle" class="w-4 h-4"></i> <?= htmlspecialchars($_GET['cred_error']) ?>
        </div>
        <?php elseif(isset($_GET['sample_ok'])): ?>
        <div class="bg-blue-50 text-blue-700 border border-blue-200 px-6 py-3 rounded-2xl text-sm font-semibold flex items-center gap-2">
            <i data-lucide="database" class="w-4 h-4"></i> Namunaviy ma'lumotlar muvaffaqiyatli yuklandi (30 kunlik).
        </div>
        <?php elseif(isset($_GET['sample_del'])): ?>
        <div class="bg-amber-50 text-amber-700 border border-amber-200 px-6 py-3 rounded-2xl text-sm font-semibold flex items-center gap-2">
            <i data-lucide="trash-2" class="w-4 h-4"></i> Barcha namunaviy ma'lumotlar o'chirildi.
        </div>
        <?php endif; ?>

        <!-- Top banner -->
        <div class="bg-gradient-to-r from-indigo-900 to-indigo-800 rounded-3xl p-7 text-white flex justify-between items-center shadow-2xl shadow-indigo-900/20">
            <div>
                <h2 class="text-xl font-bold mb-1">Analitika &amp; Hisobotlar</h2>
                <p class="text-indigo-200 text-sm">Foyda marjasi, Staff faolligi, Xarajatlar bo'linmasi.</p>
            </div>
            <div class="flex gap-3">
                <a href="hisobot.php" class="bg-white/20 text-white px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-white/30 transition">Hisobotlar</a>
                <a href="analitika.php" class="bg-white text-indigo-900 px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-indigo-50 transition shadow-lg">Analitika</a>
            </div>
        </div>

        <!-- Admin credentials + permissions grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Admin Credentials -->
            <div class="bg-white rounded-3xl p-7 border border-zinc-100 shadow-sm">
                <div class="flex items-center gap-2 mb-4">
                    <div class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center"><i data-lucide="key-round" class="w-4 h-4"></i></div>
                    <div><h2 class="text-base font-bold text-zinc-800 leading-none">Admin Hisob</h2><p class="text-zinc-400 text-xs mt-0.5">Login va parolni yangilang</p></div>
                </div>
                <form action="process.php" method="post" class="space-y-3">
                    <input type="hidden" name="action" value="change_admin_credentials">
                    <input type="password" name="current_password" placeholder="Joriy parol *" required
                           class="w-full px-4 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm outline-none focus:border-indigo-400 transition">
                    <input type="text" name="new_username" placeholder="Yangi login (<?= htmlspecialchars($_SESSION['username']) ?>)"
                           class="w-full px-4 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm outline-none focus:border-indigo-400 transition">
                    <input type="password" name="new_password" placeholder="Yangi parol (ixtiyoriy)"
                           class="w-full px-4 py-2.5 bg-zinc-50 border border-zinc-200 rounded-xl text-sm outline-none focus:border-indigo-400 transition">
                    <button type="submit" class="w-full bg-indigo-600 text-white py-2.5 rounded-xl font-bold text-sm hover:bg-indigo-700 transition shadow-sm">Saqlash</button>
                </form>
            </div>


            <div class="bg-white rounded-3xl p-8 border border-zinc-100 shadow-sm">
                <h2 class="text-lg font-bold text-zinc-800 mb-5">Ruxsatlar (Staff)</h2>
                <div class="space-y-4">
                    <!-- Edit permission -->
                    <div class="flex items-center justify-between p-4 bg-zinc-50 rounded-2xl border border-zinc-100">
                        <div>
                            <p class="font-semibold text-zinc-700 text-sm">Tahrirlash ruxsati</p>
                            <p class="text-zinc-400 text-xs mt-0.5">Staff tranzaksiyalarni tahrirlay oladimi?</p>
                        </div>
                        <form action="process.php" method="post">
                            <input type="hidden" name="action" value="update_setting">
                            <input type="hidden" name="key" value="staff_can_edit">
                            <div class="flex bg-zinc-200 rounded-lg p-1">
                                <button type="submit" name="value" value="1" class="px-4 py-1.5 rounded-md text-xs font-bold transition <?= $staff_can_edit == '1' ? 'bg-indigo-600 text-white shadow' : 'text-zinc-500 hover:bg-white' ?>">HA</button>
                                <button type="submit" name="value" value="0" class="px-4 py-1.5 rounded-md text-xs font-bold transition <?= $staff_can_edit == '0' ? 'bg-rose-500 text-white shadow' : 'text-zinc-500 hover:bg-white' ?>">YO'Q</button>
                            </div>
                        </form>
                    </div>
                    <!-- Delete permission -->
                    <div class="flex items-center justify-between p-4 bg-zinc-50 rounded-2xl border border-zinc-100">
                        <div>
                            <p class="font-semibold text-zinc-700 text-sm">O'chirish ruxsati</p>
                            <p class="text-zinc-400 text-xs mt-0.5">Staff tranzaksiyalarni o'chira oladimi?</p>
                        </div>
                        <form action="process.php" method="post">
                            <input type="hidden" name="action" value="update_setting">
                            <input type="hidden" name="key" value="staff_can_delete">
                            <div class="flex bg-zinc-200 rounded-lg p-1">
                                <button type="submit" name="value" value="1" class="px-4 py-1.5 rounded-md text-xs font-bold transition <?= $staff_can_delete == '1' ? 'bg-indigo-600 text-white shadow' : 'text-zinc-500 hover:bg-white' ?>">HA</button>
                                <button type="submit" name="value" value="0" class="px-4 py-1.5 rounded-md text-xs font-bold transition <?= $staff_can_delete == '0' || $staff_can_delete === false ? 'bg-rose-500 text-white shadow' : 'text-zinc-500 hover:bg-white' ?>">YO'Q</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="animate-fade-in-up">
            <div class="flex justify-between items-end mb-5">
                <h2 class="text-xl font-bold text-zinc-800">Staff Boshqaruvi <span class="text-zinc-400 font-medium text-sm ml-2">(Login)</span></h2>
                <button onclick="openStaffModal()" class="text-xs font-bold text-indigo-700 bg-indigo-50 border border-indigo-100 px-4 py-2.5 rounded-xl hover:bg-indigo-100 transition">+ Yangi Staff</button>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach($staff as $u): ?>
                <div class="bg-white p-6 rounded-3xl border border-zinc-100 shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-10 h-10 rounded-full bg-zinc-100 flex items-center justify-center text-zinc-500 font-bold uppercase"><?= substr($u['username'],0,1) ?></div>
                        <div class="flex gap-2">
                             <button onclick="editStaff(<?= $u['id'] ?>, '<?= $u['username'] ?>', '<?= $u['password'] ?>')" class="text-zinc-300 hover:text-indigo-500"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                             <?php if($u['is_active']): ?>
                                <a href="process.php?action=toggle_staff&id=<?= $u['id'] ?>&status=0" class="text-[10px] font-bold text-rose-600 bg-rose-50 px-2 py-1 rounded-lg hover:bg-rose-100">Bloklash</a>
                            <?php else: ?>
                                <a href="process.php?action=toggle_staff&id=<?= $u['id'] ?>&status=1" class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-lg hover:bg-emerald-100">Yoqish</a>
                            <?php endif; ?>
                            <a href="process.php?action=delete_staff&id=<?= $u['id'] ?>" onclick="return confirm('O\'chirilsinmi?')" class="text-zinc-300 hover:text-rose-500"><i data-lucide="trash-2" class="w-4 h-4"></i></a>
                        </div>
                    </div>
                    <div class="font-bold text-lg text-zinc-800"><?= $u['username'] ?></div>
                    <div class="text-xs text-zinc-400 mt-1 flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full <?= $u['is_active']?'bg-emerald-500':'bg-rose-500' ?>"></span>
                        <?= $u['is_active'] ? 'Faol' : 'Bloklangan' ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div>
            <div class="flex justify-between items-end mb-5">
                <h2 class="text-xl font-bold text-zinc-800">Xodimlar Boshqaruvi <span class="text-zinc-400 font-medium text-sm ml-2">(Payroll)</span></h2>
                <button onclick="openEmpModal()" class="text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-100 px-4 py-2.5 rounded-xl hover:bg-emerald-100 transition">+ Yangi Xodim</button>
            </div>
            <div class="bg-white rounded-3xl shadow-sm border border-zinc-100 overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50/50 text-xs uppercase text-zinc-400 font-bold border-b border-zinc-100">
                        <tr>
                            <th class="px-8 py-5">Ism</th>
                            <th class="px-8 py-5">Lavozim</th>
                            <th class="px-8 py-5">Oylik</th>
                            <th class="px-8 py-5 text-right">Amallar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50">
                        <?php foreach($employees as $emp): ?>
                        <tr class="hover:bg-zinc-50/50 transition-colors">
                            <td class="px-8 py-5 font-bold text-zinc-700"><?= $emp['name'] ?></td>
                            <td class="px-8 py-5 text-zinc-500"><?= $emp['position'] ?></td>
                            <td class="px-8 py-5 font-bold text-emerald-600"><?= number_format($emp['salary'], 0, '.', ' ') ?></td>
                            <td class="px-8 py-5 text-right flex justify-end gap-3">
                                <button onclick="editEmp(<?= $emp['id'] ?>, '<?= $emp['name'] ?>', '<?= $emp['position'] ?>', '<?= $emp['salary'] ?>')" class="text-zinc-300 hover:text-indigo-500"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                                <a href="process.php?action=delete_employee&id=<?= $emp['id'] ?>" onclick="return confirm('O\'chirilsinmi?')" class="text-zinc-300 hover:text-rose-500"><i data-lucide="trash-2" class="w-4 h-4"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Kategoriyalar -->
        <div>
            <h2 class="text-xl font-bold text-zinc-800 mb-5">Kategoriyalar</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white p-6 rounded-3xl border border-zinc-100 shadow-sm">
                    <p class="text-xs font-bold text-zinc-400 uppercase mb-3">Yangi qo'shish</p>
                    <form action="process.php" method="post" class="flex gap-2">
                        <input type="hidden" name="action" value="add_category">
                        <input type="text" name="name" placeholder="Kategoriya nomi" class="flex-1 p-3 bg-zinc-50 border border-zinc-200 rounded-xl outline-none focus:border-indigo-500 text-sm" required>
                        <select name="type" class="p-3 bg-zinc-50 border border-zinc-200 rounded-xl outline-none text-sm font-semibold">
                            <option value="income">Kirim</option>
                            <option value="expense">Chiqim</option>
                        </select>
                        <button class="bg-indigo-600 text-white px-4 rounded-xl hover:bg-indigo-700 transition"><i data-lucide="plus" class="w-5 h-5"></i></button>
                    </form>
                </div>
                <div class="bg-white p-5 rounded-3xl border border-zinc-100 shadow-sm max-h-72 overflow-y-auto">
                    <p class="text-xs font-bold text-zinc-400 uppercase mb-3">Mavjud kategoriyalar</p>
                    <ul class="space-y-1.5">
                        <?php foreach($categories as $cat): ?>
                        <li class="flex justify-between items-center px-3 py-2 bg-zinc-50 rounded-xl text-sm border border-zinc-100 hover:border-zinc-200 transition">
                            <span class="font-medium text-zinc-700"><?= htmlspecialchars($cat['name']) ?></span>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-lg <?= $cat['type']=='income'?'bg-emerald-50 text-emerald-600':'bg-rose-50 text-rose-600' ?>"><?= $cat['type']=='income'?'Kirim':'Chiqim' ?></span>
                                <a href="process.php?action=delete_category&id=<?= $cat['id'] ?>" onclick="return confirm('Kategoriya o\'chirilsinmi?')" class="text-zinc-300 hover:text-rose-500 transition"><i data-lucide="x" class="w-3.5 h-3.5"></i></a>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Sample Data -->
        <div class="bg-white rounded-3xl border border-zinc-100 shadow-sm overflow-hidden">
            <div class="px-7 py-5 border-b border-zinc-100 flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center flex-shrink-0"><i data-lucide="flask-conical" class="w-4 h-4"></i></div>
                <div>
                    <h2 class="font-bold text-zinc-800 text-base leading-none">Namunaviy Ma'lumotlar</h2>
                    <p class="text-zinc-400 text-xs mt-0.5">Tizimni sinab ko'rish uchun 30 kunlik tranzaksiyalar yuklang</p>
                </div>
            </div>
            <div class="px-7 py-5 flex flex-wrap gap-3">
                <button onclick="confirmSample('load')" class="flex items-center gap-2 bg-violet-600 text-white px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-violet-700 transition shadow-sm">
                    <i data-lucide="upload" class="w-4 h-4"></i> Namuna yuklash
                </button>
                <button onclick="confirmSample('delete')" class="flex items-center gap-2 bg-rose-50 text-rose-600 border border-rose-200 px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-rose-100 transition">
                    <i data-lucide="trash-2" class="w-4 h-4"></i> Namunalarni o'chirish
                </button>
                <p class="w-full text-xs text-zinc-400 mt-1">Namunalar <code class="bg-zinc-100 px-1 rounded">[NAMUNA]</code> prefiksi bilan belgilanadi va alohida o'chiriladi.</p>
            </div>
        </div>
    </div>

    <div id="staffModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-zinc-900/40 backdrop-blur-sm" onclick="closeModals()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white w-full max-w-md p-8 rounded-3xl shadow-2xl">
                <h3 class="font-bold text-xl mb-6" id="staffModalTitle">Yangi Staff</h3>
                <form action="process.php" method="post" class="space-y-4">
                    <input type="hidden" name="action" id="staffAction" value="create_staff">
                    <input type="hidden" name="id" id="staffId">
                    <input type="text" name="username" id="staffUsername" placeholder="Login" class="w-full p-3 bg-zinc-50 border rounded-xl outline-none" required>
                    <input type="text" name="password" id="staffPassword" placeholder="Parol" class="w-full p-3 bg-zinc-50 border rounded-xl outline-none" required>
                    <button type="submit" class="w-full bg-zinc-900 text-white py-3 rounded-xl font-bold">Saqlash</button>
                </form>
            </div>
        </div>
    </div>

    <div id="empModal" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-zinc-900/40 backdrop-blur-sm" onclick="closeModals()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-4">
            <div class="bg-white w-full max-w-md p-8 rounded-3xl shadow-2xl">
                <h3 class="font-bold text-xl mb-6" id="empModalTitle">Yangi Xodim</h3>
                <form action="process.php" method="post" class="space-y-4">
                    <input type="hidden" name="action" id="empAction" value="create_employee">
                    <input type="hidden" name="id" id="empId">
                    <input type="text" name="name" id="empName" placeholder="F.I.SH" class="w-full p-3 bg-zinc-50 border rounded-xl outline-none" required>
                    <input type="text" name="position" id="empPos" placeholder="Lavozim" class="w-full p-3 bg-zinc-50 border rounded-xl outline-none" required>
                    <input type="text" name="salary" id="empSalary" placeholder="Oylik Summasi" class="w-full p-3 bg-zinc-50 border rounded-xl outline-none" required>
                    <button type="submit" class="w-full bg-zinc-900 text-white py-3 rounded-xl font-bold">Saqlash</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        function confirmSample(type) {
            if (type === 'load') {
                if (confirm("30 kunlik namunaviy tranzaksiyalar yuklansinmi?\n\nUlar [NAMUNA] belgisi bilan saqlanadi va istalgan vaqt o'chirilishi mumkin.")) {
                    window.location.href = 'process.php?action=load_sample_data';
                }
            } else {
                if (confirm("Barcha [NAMUNA] tranzaksiyalari o'chirilsinmi?\n\nBu amalni qaytarib bo'lmaydi!")) {
                    window.location.href = 'process.php?action=delete_sample_data';
                }
            }
        }

        function closeModals() {
            document.getElementById('staffModal').classList.add('hidden');
            document.getElementById('empModal').classList.add('hidden');
        }

        // Staff Logic
        function openStaffModal() {
            document.getElementById('staffModalTitle').innerText = "Yangi Staff";
            document.getElementById('staffAction').value = "create_staff";
            document.getElementById('staffUsername').value = "";
            document.getElementById('staffPassword').value = "";
            document.getElementById('staffModal').classList.remove('hidden');
        }
        function editStaff(id, username, password) {
            document.getElementById('staffModalTitle').innerText = "Staffni Tahrirlash";
            document.getElementById('staffAction').value = "update_staff";
            document.getElementById('staffId').value = id;
            document.getElementById('staffUsername').value = username;
            document.getElementById('staffPassword').value = password;
            document.getElementById('staffModal').classList.remove('hidden');
        }

        // Employee Logic
        function openEmpModal() {
            document.getElementById('empModalTitle').innerText = "Yangi Xodim";
            document.getElementById('empAction').value = "create_employee";
            document.getElementById('empName').value = "";
            document.getElementById('empPos').value = "";
            document.getElementById('empSalary').value = "";
            document.getElementById('empModal').classList.remove('hidden');
        }
        function editEmp(id, name, pos, salary) {
            document.getElementById('empModalTitle').innerText = "Xodimni Tahrirlash";
            document.getElementById('empAction').value = "update_employee";
            document.getElementById('empId').value = id;
            document.getElementById('empName').value = name;
            document.getElementById('empPos').value = pos;
            document.getElementById('empSalary').value = parseInt(salary).toLocaleString('ru-RU').replace(/,/g, ' ');
            document.getElementById('empModal').classList.remove('hidden');
        }

        document.getElementById('empSalary').addEventListener('input', function() {
            let val = this.value.replace(/\D/g, '');
            this.value = val ? parseInt(val).toLocaleString('ru-RU').replace(/,/g, ' ') : '';
        });

        document.addEventListener('keydown', e => { if(e.key === "Escape") closeModals(); });
    </script>
</body>
</html>