<?php
// user_sidebar.php
$currentPage = basename($_SERVER['PHP_SELF']);

$navItems = [
    ['label' => 'Dashboard',           'file' => 'user_dashboard.php',    'icon' => 'fa-chart-pie'],
    ['label' => 'My Reservations',     'file' => 'user_reservations.php', 'icon' => 'fa-clipboard-check'],
    ['label' => 'Announcements',       'file' => 'user_announcements.php','icon' => 'fa-bullhorn'],
    ['label' => 'Interactive Map',      'file' => 'user_map.php',          'icon' => 'fa-map-location-dot'],
    ['label' => 'Check Availability',   'file' => 'user_availability.php', 'icon' => 'fa-calendar-check'],
    ['label' => 'Maintenance Request', 'file' => 'user_maintenance.php',  'icon' => 'fa-screwdriver-wrench'],
];
?>
<?php if (!empty($_SESSION['user_id'])): ?>
<script>
    try {
        if (!sessionStorage.getItem('cemeterynav_logged_in')) window.location.replace('logout.php');
    } catch (e) {}
</script>
<?php endif; ?>

<style>
    /* Modern Glassmorphism Variables */
    :root {
        --sb-main-bg: rgba(255, 255, 255, 0.88);
        --sb-card-bg: rgba(248, 250, 252, 0.65);
        --sb-card-border: rgba(226, 232, 240, 0.65);
        --sb-text-main: #0f172a;
        --sb-text-sub: #64748b;
        --sb-active-bg: #9333ea;
        --sb-active-text: #ffffff;
        --sb-active-subtle: rgba(147, 51, 234, 0.12);
    }

    html.dark {
        --sb-main-bg: rgba(15, 23, 42, 0.88);
        --sb-card-bg: rgba(255, 255, 255, 0.04);
        --sb-card-border: rgba(255, 255, 255, 0.08);
        --sb-text-main: #f8fafc;
        --sb-text-sub: #94a3b8;
        --sb-active-bg: #a855f7;
        --sb-active-text: #ffffff;
        --sb-active-subtle: rgba(168, 85, 247, 0.18);
    }

    .sb-container {
        background-color: var(--sb-main-bg) !important;
        border-color: var(--sb-card-border) !important;
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    .sb-text-bright { color: var(--sb-text-main) !important; }
    .sb-text-subtle { color: var(--sb-text-sub) !important; }

    .sb-card-element {
        background-color: var(--sb-card-bg) !important;
        border: 1px solid var(--sb-card-border) !important;
    }

    .sb-nav-link {
        color: var(--sb-text-sub) !important;
        transition: all 0.2s ease;
    }

    .sb-nav-link:hover {
        color: var(--sb-text-main) !important;
        background-color: var(--sb-card-bg) !important;
    }

    .sb-nav-link.sb-active {
        background: linear-gradient(90deg, var(--sb-active-subtle), transparent);
        color: var(--sb-active-bg) !important;
        border-left: 3px solid var(--sb-active-bg);
        font-weight: 700;
    }

    .sb-nav-link.sb-active * {
        color: var(--sb-active-bg) !important;
    }

    .custom-scrollbar::-webkit-scrollbar { width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(168, 85, 247, 0.2); border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #a855f7; }

    /* Sidebar collapsed (icon-only) state on desktop */
    @media (min-width: 1024px) {
        aside#sidebar { overflow: visible !important; }

        aside.sidebar-collapsed {
            width: 5rem !important;
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }

        aside.sidebar-collapsed .sb-collapse-hidden {
            display: none !important;
        }

        aside.sidebar-collapsed .sb-nav-link,
        aside.sidebar-collapsed .sb-card-element,
        aside.sidebar-collapsed .p-5 > div,
        aside.sidebar-collapsed .p-3\.5 > a,
        aside.sidebar-collapsed .p-4 > a {
            justify-content: center !important;
            gap: 0.5rem !important;
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }
    }


</style>

<aside id="sidebar"
       class="sb-container fixed lg:relative inset-y-0 left-0 z-40 w-72 lg:w-80 flex flex-col h-full shrink-0 border-r -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out overflow-hidden">

    <!-- USER PROFILE SECTION -->
    <div class="relative p-5 border-b border-slate-200/60 dark:border-white/10">
        <div class="flex items-center gap-3">
            <div class="relative shrink-0">
                <div class="w-11 h-11 rounded-2xl flex items-center justify-center text-white text-lg shadow-sm bg-purple-600">
                    <i class="fa-solid fa-circle-user"></i>
                </div>
                <div class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-emerald-500 border-2 border-white dark:border-slate-900 rounded-full"></div>
            </div>

            <div class="flex-1 min-w-0 sb-collapse-hidden">
                <h3 class="text-xs font-bold sb-text-bright truncate leading-tight">
                    <?= htmlspecialchars($_SESSION['name'] ?? 'Visitor') ?>
                </h3>
                <p class="text-[10px] font-medium sb-text-subtle mt-0.5 truncate">Portal Access</p>
                <span class="inline-flex items-center gap-1.5 mt-1 px-2 py-0.5 rounded-full text-[9px] font-bold bg-purple-500/10 text-purple-600 dark:text-purple-300 border border-purple-500/20">
                    <span class="w-1.5 h-1.5 rounded-full bg-purple-500 animate-pulse"></span>
                    Active Session
                </span>
            </div>

            <!-- Mobile Close Button -->
            <button onclick="if(typeof toggleSidebar==='function') toggleSidebar();"
                    class="lg:hidden p-2.5 rounded-xl sb-card-element sb-text-bright">
                <i class="fa-solid fa-xmark text-xs"></i>
            </button>
        </div>
    </div>

    <!-- MAIN NAVIGATION AREA -->
    <div class="p-4 space-y-5 overflow-y-auto flex-1 custom-scrollbar">

        <!-- Navigation Links -->
        <nav class="space-y-1" aria-label="Main navigation">
            <span class="text-[10px] font-bold sb-text-subtle uppercase tracking-wider block mb-2 px-2 sb-collapse-hidden">
                Navigation
            </span>

            <?php foreach ($navItems as $item):
                $isActive = ($currentPage == $item['file']);
            ?>
                <a href="<?= $item['file'] ?>"
                   class="sb-nav-link group flex items-center gap-3 px-3 py-2.5 rounded-r-xl font-medium text-[13px] relative <?= $isActive ? 'sb-active' : '' ?>">

                    <span class="w-7 h-7 rounded-lg flex items-center justify-center transition-transform group-hover:scale-110">
                        <i class="fa-solid <?= $item['icon'] ?> text-sm"></i>
                    </span>

                    <span class="truncate sb-collapse-hidden"><?= $item['label'] ?></span>

                    <?php if ($isActive): ?>
                        <i class="fa-solid fa-chevron-right text-[10px] ml-auto opacity-80 sb-collapse-hidden"></i>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- QUICK ACTIONS -->
        <div class="space-y-2 sb-collapse-hidden">
            <span class="text-[10px] font-bold sb-text-subtle uppercase tracking-wider block mb-2 px-2">
                Quick Actions
            </span>

            <a href="reservation.php" class="sb-card-element group flex items-center gap-3 p-2.5 rounded-xl hover:border-purple-500/50 transition-all">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center bg-purple-500/10 text-purple-600 dark:text-purple-400 font-bold shrink-0">
                    <i class="fa-solid fa-bookmark text-xs"></i>
                </span>
                <div class="flex-1 min-w-0">
                    <span class="text-[12px] font-bold block leading-tight sb-text-bright">Reserve Plot</span>
                    <span class="text-[10px] sb-text-subtle block truncate">Secure your memorial</span>
                </div>
                <i class="fa-solid fa-chevron-right text-[10px] sb-text-subtle group-hover:text-purple-500 group-hover:translate-x-0.5 transition-transform"></i>
            </a>

            <a href="payment.php" class="sb-card-element group flex items-center gap-3 p-2.5 rounded-xl hover:border-indigo-500/50 transition-all">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 font-bold shrink-0">
                    <i class="fa-solid fa-credit-card text-xs"></i>
                </span>
                <div class="flex-1 min-w-0">
                    <span class="text-[12px] font-bold block leading-tight sb-text-bright">Make Payment</span>
                    <span class="text-[10px] sb-text-subtle block truncate">Complete transactions</span>
                </div>
                <i class="fa-solid fa-chevron-right text-[10px] sb-text-subtle group-hover:text-indigo-500 group-hover:translate-x-0.5 transition-transform"></i>
            </a>

            <a href="payment_history.php" class="sb-card-element group flex items-center gap-3 p-2.5 rounded-xl hover:border-fuchsia-500/50 transition-all">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400 font-bold shrink-0">
                    <i class="fa-solid fa-receipt text-xs"></i>
                </span>
                <div class="flex-1 min-w-0">
                    <span class="text-[12px] font-bold block leading-tight sb-text-bright">Payment History</span>
                    <span class="text-[10px] sb-text-subtle block truncate">View past transactions</span>
                </div>
                <i class="fa-solid fa-chevron-right text-[10px] sb-text-subtle group-hover:text-fuchsia-500 group-hover:translate-x-0.5 transition-transform"></i>
            </a>
        </div>

        <!-- CEMETERY INFO CARD -->
        <div class="sb-card-element rounded-xl p-3.5 space-y-2.5 sb-collapse-hidden">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-circle-info text-xs text-purple-500"></i>
                <span class="text-xs font-bold sb-text-bright">Cemetery Info</span>
            </div>

            <div class="space-y-2 text-[11px]">
                <div class="flex items-start gap-2">
                    <i class="fa-regular fa-clock sb-text-subtle text-xs mt-0.5 shrink-0"></i>
                    <div>
                        <span class="font-semibold sb-text-bright block">Operating Hours</span>
                        <span class="text-[10px] sb-text-subtle">Daily: 6:00 AM - 6:00 PM</span>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <i class="fa-solid fa-phone sb-text-subtle text-xs mt-0.5 shrink-0"></i>
                    <div>
                        <span class="font-semibold sb-text-bright block">Contact Hotline</span>
                        <span class="text-[10px] sb-text-subtle">(083) 123-4567</span>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <i class="fa-solid fa-location-dot sb-text-subtle text-xs mt-0.5 shrink-0"></i>
                    <div>
                        <span class="font-semibold sb-text-bright block">Location</span>
                        <span class="text-[10px] sb-text-subtle">Matutum Memorial Park</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- EMERGENCY HOTLINES -->
        <div class="rounded-xl p-3.5 border border-rose-500/20 bg-rose-500/5 space-y-2.5 sb-collapse-hidden">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-xs text-rose-500"></i>
                <span class="text-xs font-bold text-rose-600 dark:text-rose-400">Emergency Hotlines</span>
            </div>

            <div class="space-y-1.5">
                <div class="flex items-center justify-between p-2 rounded-lg sb-card-element">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-shield-halved text-rose-500 text-xs"></i>
                        <span class="text-[11px] font-bold sb-text-bright">Security</span>
                    </div>
                    <span class="text-xs font-mono font-bold text-rose-600 dark:text-rose-400">911</span>
                </div>
                <div class="flex items-center justify-between p-2 rounded-lg sb-card-element">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-truck-medical text-rose-500 text-xs"></i>
                        <span class="text-[11px] font-bold sb-text-bright">Medical</span>
                    </div>
                    <span class="text-xs font-mono font-bold text-rose-600 dark:text-rose-400">166</span>
                </div>
            </div>
        </div>

        <!-- HELP & FAQ LINK -->
        <a href="help.php" class="sb-nav-link group flex items-center gap-3 px-3 py-2 rounded-xl text-xs font-medium">
            <span class="w-7 h-7 rounded-lg flex items-center justify-center text-purple-500 group-hover:scale-110">
                <i class="fa-solid fa-circle-question text-sm"></i>
            </span>
            <span class="text-[13px] sb-collapse-hidden">Help & FAQ</span>
            <i class="fa-solid fa-chevron-right text-xs sb-text-subtle group-hover:translate-x-0.5 transition-transform ml-auto sb-collapse-hidden"></i>
        </a>
    </div>

    <!-- LOGOUT FOOTER -->
    <div class="p-3.5 border-t border-slate-200/60 dark:border-white/10">
        <a href="logout.php" class="group flex items-center gap-3 px-3 py-2 rounded-xl text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 transition-colors">
            <span class="w-7 h-7 rounded-lg flex items-center justify-center text-rose-500">
                <i class="fa-solid fa-right-from-bracket text-sm"></i>
            </span>
            <span class="text-[13px] font-bold sb-collapse-hidden">Logout</span>
        </a>
    </div>

    <!-- Sidebar Toggle -->
    <button id="sidebarToggleBtn" type="button"
            onclick="if(typeof toggleSidebarVisibility==='function') toggleSidebarVisibility();"
            class="hidden lg:flex group absolute right-0 top-1/2 -translate-y-1/2 translate-x-1/2 z-40 w-11 h-11 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-indigo-600 text-white shadow-lg shadow-purple-500/30 ring-2 ring-white dark:ring-slate-900 hover:scale-110 hover:shadow-xl hover:shadow-purple-500/50 active:scale-95 transition-all duration-200"
            title="Toggle sidebar"
            aria-label="Toggle sidebar"
            aria-expanded="true">
        <i id="sidebarToggleIcon" class="fa-solid fa-angles-left text-base transition-transform duration-200 group-hover:-translate-x-0.5"></i>
    </button>
</aside>


<script>
    function toggleSidebarVisibility() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) sidebar.classList.toggle('sidebar-collapsed');

        const isCollapsed = sidebar && sidebar.classList.contains('sidebar-collapsed');

        const toggleBtn = document.getElementById('sidebarToggleBtn');
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', String(!isCollapsed));

        const icon = document.getElementById('sidebarToggleIcon');
        if (icon) {
            icon.className = isCollapsed
                ? 'fa-solid fa-angles-right text-base transition-transform duration-200 group-hover:translate-x-0.5'
                : 'fa-solid fa-angles-left text-base transition-transform duration-200 group-hover:-translate-x-0.5';
        }
    }
</script>
