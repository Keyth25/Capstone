<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'] ?? '';
$user_email = $_SESSION['email'] ?? '';
$my_reservations = [];
$error = '';

try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.reservation_id, r.reservation_type, r.status, r.reservation_date, r.created_at,
               a.first_name, a.last_name, a.contact_number, a.email_address,
               p.plot_number, p.section_code AS plot_section_code,
               s.section_name, s.section_code
        FROM public.reservations r
        LEFT JOIN public.applicants a ON r.applicant_id = a.id
        LEFT JOIN public.plots p ON r.plot_id = p.id
        LEFT JOIN public.sections s ON p.section_id = s.id
        WHERE r.user_id = ? OR (r.user_id IS NULL AND a.email_address = ?)
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$user_id, $user_email]);
    $my_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to load reservations: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations - Matutum PlotNav</title>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 850: '#0d1322', 950: '#030712' },
                        cyan: { 400: '#22d3ee', 500: '#06b6d4', 600: '#0891b2' }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(226, 232, 240, 0.9);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.03);
        }
        .dark .glass-card {
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.07);
            box-shadow: none;
        }
        .orb { position: fixed; border-radius: 50%; filter: blur(120px); opacity: 0.12; pointer-events: none; z-index: 0; }
        .dark .orb { opacity: 0.25; }
        .orb-1 { width: 500px; height: 500px; background: radial-gradient(circle, #06b6d4, #3b82f6); top: -150px; right: -150px; }
        .orb-2 { width: 450px; height: 450px; background: radial-gradient(circle, #10b981, #06b6d4); bottom: -150px; left: -150px; }
        .dashboard-scroll::-webkit-scrollbar { width: 5px; }
        .dashboard-scroll::-webkit-scrollbar-track { background: transparent; }
        .dashboard-scroll::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.3); border-radius: 9999px; }
        .dark .dashboard-scroll::-webkit-scrollbar-thumb { background: rgba(51, 65, 85, 0.4); }
    </style>
    <link rel="stylesheet" href="mobile.css">
</head>
<body class="bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 h-screen flex flex-col overflow-hidden antialiased transition-colors duration-200">

    <!-- Ambient Glowing Orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <!-- HEADER BAR -->
    <header class="glass-card border-b border-slate-200 dark:border-slate-800/80 px-5 py-3.5 flex items-center justify-between z-30 shrink-0">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl bg-slate-200/60 dark:bg-slate-900 border border-slate-300/60 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 transition">
                <i class="fa-solid fa-bars text-base"></i>
            </button>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-tr from-cyan-600 to-emerald-500 rounded-xl flex items-center justify-center text-white shadow-lg shadow-cyan-500/20 ring-1 ring-white/20">
                    <i class="fa-solid fa-map-pin text-lg"></i>
                </div>
                <div>
                    <span class="font-black text-slate-900 dark:text-white tracking-wider text-base block leading-tight">MATUTUM <span class="text-cyan-600 dark:text-cyan-400">PLOTNAV</span></span>
                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-bold tracking-wider uppercase">Visitor Portal</span>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button id="themeToggleBtn" type="button" onclick="toggleTheme()" class="p-2.5 rounded-xl bg-white dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/60 text-slate-700 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 transition shadow-sm" title="Toggle Theme">
                <i id="themeToggleIcon" class="fa-solid fa-moon text-sm"></i>
            </button>
            <a href="logout.php" class="p-2.5 rounded-xl bg-white dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/60 text-slate-700 dark:text-slate-300 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition shadow-sm" title="Logout">
                <i class="fa-solid fa-right-from-bracket text-sm"></i>
            </a>
        </div>
    </header>

    <!-- MAIN BODY -->
    <div class="flex-1 flex relative overflow-hidden z-10">

        <?php if (file_exists('user_sidebar.php')) { include 'user_sidebar.php'; } ?>

        <!-- CONTENT AREA -->
        <main class="flex-1 p-4 md:p-6 overflow-y-auto dashboard-scroll">

            <!-- WELCOME / INTRO -->
            <div class="glass-card rounded-3xl p-6 md:p-7 relative overflow-hidden border border-slate-200 dark:border-slate-800 shadow-sm mb-6">
                <div class="absolute -right-10 -bottom-10 w-56 h-56 bg-cyan-500/10 dark:bg-cyan-500/15 rounded-full blur-2xl pointer-events-none"></div>
                <div class="relative z-10">
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-cyan-500/10 text-cyan-700 dark:text-cyan-400 border border-cyan-500/20 text-[11px] font-bold tracking-wide uppercase mb-3">
                        <span class="w-2 h-2 rounded-full bg-cyan-500"></span>
                        Reservation Tracking
                    </div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">My Reservations</h2>
                    <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 mt-1 font-medium">
                        View the status of all your plot reservations — pending, approved, or rejected.
                    </p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="p-4 mb-5 bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs rounded-xl flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- STATUS CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <?php
                    $pending_count = 0;
                    $approved_count = 0;
                    $rejected_count = 0;
                    foreach ($my_reservations as $res) {
                        $status = strtolower($res['status'] ?? '');
                        if ($status === 'pending' || $status === 'pending approval') $pending_count++;
                        elseif ($status === 'approved') $approved_count++;
                        elseif ($status === 'rejected') $rejected_count++;
                    }
                ?>
                <div class="glass-card rounded-2xl p-4 border border-amber-500/20 bg-amber-500/5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center text-amber-600 dark:text-amber-400">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Pending</p>
                            <p class="text-xl font-black text-slate-900 dark:text-white"><?= $pending_count ?></p>
                        </div>
                    </div>
                </div>
                <div class="glass-card rounded-2xl p-4 border border-emerald-500/20 bg-emerald-500/5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Approved</p>
                            <p class="text-xl font-black text-slate-900 dark:text-white"><?= $approved_count ?></p>
                        </div>
                    </div>
                </div>
                <div class="glass-card rounded-2xl p-4 border border-red-500/20 bg-red-500/5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-red-500/10 flex items-center justify-center text-red-600 dark:text-red-400">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Rejected</p>
                            <p class="text-xl font-black text-slate-900 dark:text-white"><?= $rejected_count ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RESERVATIONS TABLE -->
            <div class="glass-card rounded-3xl p-5 md:p-6 border border-slate-200 dark:border-slate-800 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <i class="fa-solid fa-clipboard-list text-cyan-500"></i>
                        Reservation List
                    </h3>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400"><?= count($my_reservations) ?> total</span>
                </div>

                <?php if (empty($my_reservations)): ?>
                    <div class="text-center py-12 text-slate-500 dark:text-slate-400 text-xs">
                        <i class="fa-solid fa-inbox text-2xl mb-2"></i>
                        <p>No reservations found.</p>
                        <a href="reservation.php" class="inline-block mt-3 px-4 py-2 rounded-xl bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 border border-cyan-500/20 text-[11px] font-bold transition">
                            Make a Reservation
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400">
                                    <th class="pb-3 font-bold">Ref. ID</th>
                                    <th class="pb-3 font-bold">Plot</th>
                                    <th class="pb-3 font-bold">Type</th>
                                    <th class="pb-3 font-bold">Reserved On</th>
                                    <th class="pb-3 font-bold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php foreach ($my_reservations as $row): ?>
                                    <?php
                                        $rawStatus = strtolower($row['status'] ?? '');
                                        if ($rawStatus === 'approved') {
                                            $badge = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20';
                                            $icon = 'fa-circle-check';
                                        } elseif ($rawStatus === 'rejected') {
                                            $badge = 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20';
                                            $icon = 'fa-circle-xmark';
                                        } else {
                                            $badge = 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20';
                                            $icon = 'fa-hourglass-half';
                                        }
                                    ?>
                                    <tr class="group hover:bg-slate-100 dark:hover:bg-slate-800/50 transition">
                                        <td class="py-3 font-mono text-cyan-600 dark:text-cyan-400"><?= htmlspecialchars($row['reservation_id'] ?? $row['id']) ?></td>
                                        <td class="py-3">
                                            <div class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($row['section_code'] ?? $row['plot_section_code'] ?? '-') ?> - <?= htmlspecialchars($row['plot_number'] ?? '-') ?></div>
                                            <div class="text-[10px] text-slate-500"><?= htmlspecialchars($row['section_name'] ?? '') ?></div>
                                        </td>
                                        <td class="py-3 text-slate-600 dark:text-slate-400"><?= htmlspecialchars($row['reservation_type'] ?? '-') ?></td>
                                        <td class="py-3 text-slate-500 dark:text-slate-400"><?= !empty($row['reservation_date']) ? date('M d, Y', strtotime($row['reservation_date'])) : '-' ?></td>
                                        <td class="py-3">
                                            <span class="text-[10px] uppercase font-bold px-2.5 py-1 rounded-lg inline-flex items-center gap-1.5 <?= $badge ?>">
                                                <i class="fa-solid <?= $icon ?>"></i>
                                                <?= htmlspecialchars($row['status'] ?? 'Pending Approval') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <script>
        document.getElementById('themeToggleBtn').onclick = function () {
            toggleTheme();
            const icon = document.getElementById('themeToggleIcon');
            icon.className = document.documentElement.classList.contains('dark')
                ? 'fa-solid fa-moon text-sm'
                : 'fa-solid fa-sun text-sm';
        };
    </script>
</body>
</html>
