<?php
// sidebar.php - Admin sidebar
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<?php if (!empty($_SESSION['user_id'])): ?>
<script>
    try {
        if (!sessionStorage.getItem('cemeterynav_logged_in')) window.location.replace('logout.php');
    } catch (e) {}
</script>
<?php endif; ?>
<?php if (function_exists('theme_head_script')) theme_head_script(); ?>
<aside id="sidebar" class="absolute md:relative z-20 w-80 md:w-96 h-full bg-gradient-to-b from-slate-900 via-slate-900 to-slate-950 border-r border-slate-800/50 flex flex-col shadow-2xl transition-transform duration-300 ease-in-out -translate-x-full md:translate-x-0 shrink-0 backdrop-blur-xl">
    
    <!-- ADMIN PROFILE SECTION -->
    <div class="p-4 border-b border-slate-800/50 bg-gradient-to-r from-blue-900/20 to-transparent">
        <div class="flex items-center gap-3">
            <div class="relative">
                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-cyan-500 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/20">
                    <i class="fa-solid fa-user-shield text-white text-lg"></i>
                </div>
                <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 rounded-full border-2 border-slate-900"></div>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="text-sm font-bold text-white truncate"><?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></h3>
                <p class="text-xs text-slate-400">Administrator</p>
            </div>
            <button id="themeToggle" type="button" onclick="if(typeof toggleTheme==='function'){toggleTheme(); const i=this.querySelector('i'); i.className=document.documentElement.classList.contains('dark')?'fa-solid fa-moon text-cyan-400':'fa-solid fa-sun text-amber-400';}" class="p-2 rounded-lg bg-slate-800/50 text-slate-400 hover:text-white hover:bg-slate-700 transition" title="Toggle Theme">
                <i class="fa-solid fa-moon text-sm"></i>
            </button>
            <button onclick="toggleSidebar()" class="md:hidden p-2 rounded-lg bg-slate-800/50 text-slate-400 hover:text-white hover:bg-slate-700 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>

    <!-- NAVIGATION MENU -->
    <div class="p-3 space-y-1 overflow-y-auto flex-1 custom-scrollbar">
        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block mb-2 px-2">Admin Navigation</span>
        
        <a href="admin_dashboard.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= ($currentPage == 'admin_dashboard.php') ? 'bg-gradient-to-r from-blue-600/20 to-blue-600/5 border border-blue-500/30 text-blue-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= ($currentPage == 'admin_dashboard.php') ? 'bg-blue-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                <i class="fa-solid fa-gauge-high text-sm"></i>
            </div>
            <span class="text-xs font-medium">Dashboard</span>
            <?php if ($currentPage == 'admin_dashboard.php'): ?>
                <div class="ml-auto w-1.5 h-1.5 rounded-full bg-blue-400 shadow-lg shadow-blue-400/50"></div>
            <?php endif; ?>
        </a>

        <a href="admin_reservations.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= ($currentPage == 'admin_reservations.php') ? 'bg-gradient-to-r from-cyan-600/20 to-cyan-600/5 border border-cyan-500/30 text-cyan-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= ($currentPage == 'admin_reservations.php') ? 'bg-cyan-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                <i class="fa-solid fa-clipboard-check text-sm"></i>
            </div>
            <span class="text-xs font-medium">Reservations</span>
            <?php if ($currentPage == 'admin_reservations.php'): ?>
                <div class="ml-auto w-1.5 h-1.5 rounded-full bg-cyan-400 shadow-lg shadow-cyan-400/50"></div>
            <?php endif; ?>
        </a>

        <a href="admin_plots.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= ($currentPage == 'admin_plots.php') ? 'bg-gradient-to-r from-emerald-600/20 to-emerald-600/5 border border-emerald-500/30 text-emerald-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= ($currentPage == 'admin_plots.php') ? 'bg-emerald-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                <i class="fa-solid fa-location-dot text-sm"></i>
            </div>
            <span class="text-xs font-medium">Plot Management</span>
            <?php if ($currentPage == 'admin_plots.php'): ?>
                <div class="ml-auto w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-lg shadow-emerald-400/50"></div>
            <?php endif; ?>
        </a>

        <a href="admin_sections.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= ($currentPage == 'admin_sections.php') ? 'bg-gradient-to-r from-cyan-600/20 to-cyan-600/5 border border-cyan-500/30 text-cyan-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= ($currentPage == 'admin_sections.php') ? 'bg-cyan-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                <i class="fa-solid fa-layer-group text-sm"></i>
            </div>
            <span class="text-xs font-medium">Sections</span>
            <?php if ($currentPage == 'admin_sections.php'): ?>
                <div class="ml-auto w-1.5 h-1.5 rounded-full bg-cyan-400 shadow-lg shadow-cyan-400/50"></div>
            <?php endif; ?>
        </a>

        <a href="admin_perimeter.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= ($currentPage == 'admin_perimeter.php') ? 'bg-gradient-to-r from-purple-600/20 to-purple-600/5 border border-purple-500/30 text-purple-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= ($currentPage == 'admin_perimeter.php') ? 'bg-purple-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                <i class="fa-solid fa-draw-polygon text-sm"></i>
            </div>
            <span class="text-xs font-medium">Perimeter</span>
            <?php if ($currentPage == 'admin_perimeter.php'): ?>
                <div class="ml-auto w-1.5 h-1.5 rounded-full bg-purple-400 shadow-lg shadow-purple-400/50"></div>
            <?php endif; ?>
        </a>

        <a href="admin_saved_shapes.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= ($currentPage == 'admin_saved_shapes.php') ? 'bg-gradient-to-r from-amber-600/20 to-amber-600/5 border border-amber-500/30 text-amber-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= ($currentPage == 'admin_saved_shapes.php') ? 'bg-amber-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                <i class="fa-solid fa-shapes text-sm"></i>
            </div>
            <span class="text-xs font-medium">Saved Shapes</span>
            <?php if ($currentPage == 'admin_saved_shapes.php'): ?>
                <div class="ml-auto w-1.5 h-1.5 rounded-full bg-amber-400 shadow-lg shadow-amber-400/50"></div>
            <?php endif; ?>
        </a>

        <a href="admin_staff.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= ($currentPage == 'admin_staff.php') ? 'bg-gradient-to-r from-rose-600/20 to-rose-600/5 border border-rose-500/30 text-rose-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= ($currentPage == 'admin_staff.php') ? 'bg-rose-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                <i class="fa-solid fa-users-gear text-sm"></i>
            </div>
            <span class="text-xs font-medium">Staff Management</span>
            <?php if ($currentPage == 'admin_staff.php'): ?>
                <div class="ml-auto w-1.5 h-1.5 rounded-full bg-rose-400 shadow-lg shadow-rose-400/50"></div>
            <?php endif; ?>
        </a>

        <?php $maintenanceActive = in_array($currentPage, ['admin_maintenance.php', 'admin_staff_work_history.php']); ?>
        <div>
            <button type="button" onclick="toggleMaintenanceMenu()" class="nav-item w-full flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= $maintenanceActive ? 'bg-gradient-to-r from-emerald-600/20 to-emerald-600/5 border border-emerald-500/30 text-emerald-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= $maintenanceActive ? 'bg-emerald-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                    <i class="fa-solid fa-screwdriver-wrench text-sm"></i>
                </div>
                <span class="text-xs font-medium">Maintenance</span>
                <i id="maintenanceChevron" class="fa-solid fa-chevron-down ml-auto text-[10px] transition-transform duration-200 <?= $maintenanceActive ? 'rotate-180' : '' ?>"></i>
            </button>
            <div id="maintenanceSubmenu" class="mt-1 ml-4 pl-4 border-l border-slate-800 space-y-1 <?= $maintenanceActive ? '' : 'hidden' ?>">
                <a href="admin_maintenance.php" class="nav-item flex items-center gap-2.5 px-3 py-2 rounded-xl transition-all duration-200 <?= ($currentPage == 'admin_maintenance.php') ? 'bg-emerald-600/15 text-emerald-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
                    <i class="fa-solid fa-list-check text-[10px] w-4 text-center"></i>
                    <span class="text-xs font-medium">Maintenance Requests</span>
                    <?php if ($currentPage == 'admin_maintenance.php'): ?>
                        <div class="ml-auto w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-lg shadow-emerald-400/50"></div>
                    <?php endif; ?>
                </a>
                <a href="admin_staff_work_history.php" class="nav-item flex items-center gap-2.5 px-3 py-2 rounded-xl transition-all duration-200 <?= ($currentPage == 'admin_staff_work_history.php') ? 'bg-emerald-600/15 text-emerald-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
                    <i class="fa-solid fa-clock-rotate-left text-[10px] w-4 text-center"></i>
                    <span class="text-xs font-medium">Staff Work History</span>
                    <?php if ($currentPage == 'admin_staff_work_history.php'): ?>
                        <div class="ml-auto w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-lg shadow-emerald-400/50"></div>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <a href="admin_payment.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= ($currentPage == 'admin_payment.php') ? 'bg-gradient-to-r from-cyan-600/20 to-cyan-600/5 border border-cyan-500/30 text-cyan-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= ($currentPage == 'admin_payment.php') ? 'bg-cyan-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                <i class="fa-solid fa-money-bill-wave text-sm"></i>
            </div>
            <span class="text-xs font-medium">Payment Verifications</span>
            <?php if ($currentPage == 'admin_payment.php'): ?>
                <div class="ml-auto w-1.5 h-1.5 rounded-full bg-cyan-400 shadow-lg shadow-cyan-400/50"></div>
            <?php endif; ?>
        </a>

        <a href="admin_announcements.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-200 group <?= ($currentPage == 'admin_announcements.php') ? 'bg-gradient-to-r from-cyan-600/20 to-cyan-600/5 border border-cyan-500/30 text-cyan-300' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' ?>">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200 <?= ($currentPage == 'admin_announcements.php') ? 'bg-cyan-600/30' : 'bg-slate-800/50 group-hover:bg-slate-700/50' ?>">
                <i class="fa-solid fa-bullhorn text-sm"></i>
            </div>
            <span class="text-xs font-medium">Announcements</span>
            <?php if ($currentPage == 'admin_announcements.php'): ?>
                <div class="ml-auto w-1.5 h-1.5 rounded-full bg-cyan-400 shadow-lg shadow-cyan-400/50"></div>
            <?php endif; ?>
        </a>

        <!-- INFORMATION SECTIONS -->
        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block mb-2 mt-4 px-2">Information</span>

        <!-- CEMETERY INFO -->
        <div class="bg-slate-800/30 rounded-xl p-3 border border-slate-700/50 backdrop-blur-sm">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-6 h-6 rounded-lg bg-cyan-600/20 flex items-center justify-center">
                    <i class="fa-solid fa-info-circle text-cyan-400 text-xs"></i>
                </div>
                <span class="text-xs font-bold text-cyan-400">Cemetery Information</span>
            </div>
            <div class="space-y-2">
                <div class="flex items-start gap-2">
                    <i class="fa-solid fa-clock text-emerald-400 text-xs mt-0.5"></i>
                    <div>
                        <span class="text-[10px] font-medium text-slate-300 block">Operating Hours</span>
                        <span class="text-[9px] text-slate-500">Daily: 6:00 AM - 6:00 PM</span>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <i class="fa-solid fa-phone text-emerald-400 text-xs mt-0.5"></i>
                    <div>
                        <span class="text-[10px] font-medium text-slate-300 block">Contact</span>
                        <span class="text-[9px] text-slate-500">(083) 123-4567</span>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <i class="fa-solid fa-location-dot text-emerald-400 text-xs mt-0.5"></i>
                    <div>
                        <span class="text-[10px] font-medium text-slate-300 block">Location</span>
                        <span class="text-[9px] text-slate-500">Matutum Memorial Park</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- EMERGENCY CONTACTS -->
        <div class="bg-gradient-to-br from-red-950/40 to-red-900/20 rounded-xl p-3 border border-red-800/50 backdrop-blur-sm">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-6 h-6 rounded-lg bg-red-600/20 flex items-center justify-center">
                    <i class="fa-solid fa-truck-medical text-red-400 text-xs"></i>
                </div>
                <span class="text-xs font-bold text-red-400">Emergency Contacts</span>
            </div>
            <div class="space-y-2">
                <div class="flex items-center justify-between p-2 bg-red-950/50 rounded-lg border border-red-800/50">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-shield-halved text-red-400 text-xs"></i>
                        <span class="text-[10px] font-medium text-slate-300">Security</span>
                    </div>
                    <span class="text-xs font-mono text-red-300 font-bold">911</span>
                </div>
                <div class="flex items-center justify-between p-2 bg-red-950/50 rounded-lg border border-red-800/50">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-kit-medical text-red-400 text-xs"></i>
                        <span class="text-[10px] font-medium text-slate-300">Medical</span>
                    </div>
                    <span class="text-xs font-mono text-red-300 font-bold">166</span>
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="p-3 border-t border-slate-800/50 bg-slate-900/50">
        <a href="logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-red-400 hover:bg-red-950/30 hover:text-red-300 transition-all duration-200 group">
            <div class="w-8 h-8 rounded-lg bg-red-950/30 flex items-center justify-center group-hover:bg-red-950/50 transition-all duration-200">
                <i class="fa-solid fa-right-from-bracket text-sm"></i>
            </div>
            <span class="text-xs font-medium">Logout</span>
        </a>
    </div>

    <style>
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 2px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }
        .nav-item:hover {
            transform: translateX(4px);
        }
    </style>

    <script>
    function toggleMaintenanceMenu() {
        const menu = document.getElementById('maintenanceSubmenu');
        const chevron = document.getElementById('maintenanceChevron');
        if (!menu) return;
        menu.classList.toggle('hidden');
        if (chevron) chevron.classList.toggle('rotate-180');
    }
    (function () {
        const btn = document.getElementById('themeToggle');
        if (!btn) return;
        const i = btn.querySelector('i');
        if (i) {
            i.className = document.documentElement.classList.contains('dark') ? 'fa-solid fa-moon text-cyan-400' : 'fa-solid fa-sun text-amber-400';
        }
    })();
    </script>
</aside>