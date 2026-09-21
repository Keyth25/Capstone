<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$my_reservations = [];
$my_payments = [];

try {
    // Fetch User's Reservations
    $stmt_res = $pdo->prepare("SELECT * FROM public.reservations WHERE user_id = ? ORDER BY created_at DESC");
    $stmt_res->execute([$user_id]);
    $my_reservations = $stmt_res->fetchAll();

    // Fetch User's Payment History
    try {
        $stmt_pay = $pdo->prepare("
            SELECT p.*, r.plot_code 
            FROM public.payments p
            LEFT JOIN public.reservations r ON p.reservation_id = r.id
            WHERE p.user_id = ? 
            ORDER BY p.created_at DESC
        ");
        $stmt_pay->execute([$user_id]);
        $my_payments = $stmt_pay->fetchAll();
    } catch (PDOException $e) {
        $stmt_pay = $pdo->prepare("SELECT * FROM public.payments WHERE user_id = ? ORDER BY created_at DESC");
        $stmt_pay->execute([$user_id]);
        $my_payments = $stmt_pay->fetchAll();
    }

    $all_reservations = $pdo->query("SELECT plot_code, status FROM public.reservations WHERE status IN ('pending', 'approved')")->fetchAll();
    $records = $pdo->query("SELECT id FROM public.deceased_records")->fetchAll();

} catch (PDOException $e) {}

// Ensure announcements table exists and load active ones
$active_announcements = [];
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public.announcements (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message TEXT,
            audience VARCHAR(20) DEFAULT 'all',
            start_date DATE,
            end_date DATE,
            is_active BOOLEAN DEFAULT true,
            created_by VARCHAR(255),
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $stmt = $pdo->prepare("
        SELECT id, title, message, created_at
        FROM public.announcements
        WHERE is_active = true
          AND (audience IN ('all', 'user'))
          AND (start_date IS NULL OR start_date <= CURRENT_DATE)
          AND (end_date IS NULL OR end_date >= CURRENT_DATE)
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $active_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Announcements fetch: " . $e->getMessage());
    $active_announcements = [];
}

$user_notifications = [];
$unread_count = 0;
try {
    init_user_notifications_table($pdo);
    $stmt = $pdo->prepare("
        SELECT id, title, message, type, related_id, is_read, created_at
        FROM public.user_notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$user_id]);
    $user_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($user_notifications as $n) {
        if (empty($n['is_read'])) $unread_count++;
    }
} catch (PDOException $e) {
    error_log("User notifications fetch: " . $e->getMessage());
    $user_notifications = [];
    $unread_count = 0;
}

// Calculate Plot Metrics
$occupied_count = count($records);
$reserved_count = count($all_reservations);
$total_plots_estimate = max(240, $occupied_count + $reserved_count + 120);
$available_count = $total_plots_estimate - $occupied_count - $reserved_count;
$occupancy_rate = round(($occupied_count / $total_plots_estimate) * 100);

// Dynamic trends array for the wave graph
$monthly_labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
$occupancy_wave_data = [12, 24, 38, 45, 52, $occupancy_rate];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>User Dashboard - Matutum PlotNav</title>
    <?php if (function_exists('theme_head_script')) { theme_head_script(); } ?>
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
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .metric-card { transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
        .metric-card:hover { 
            transform: translateY(-4px); 
            border-color: rgba(6, 182, 212, 0.4);
            box-shadow: 0 12px 24px -8px rgba(6, 182, 212, 0.15);
        }
        .dark .metric-card:hover {
            box-shadow: 0 16px 32px -12px rgba(6, 182, 212, 0.25);
        }

        .orb { position: fixed; border-radius: 50%; filter: blur(120px); opacity: 0.12; pointer-events: none; z-index: 0; }
        .dark .orb { opacity: 0.25; }
        .orb-1 { width: 500px; height: 500px; background: radial-gradient(circle, #06b6d4, #3b82f6); top: -150px; right: -150px; }
        .orb-2 { width: 450px; height: 450px; background: radial-gradient(circle, #10b981, #06b6d4); bottom: -150px; left: -150px; }

        .dashboard-scroll::-webkit-scrollbar { width: 5px; }
        .dashboard-scroll::-webkit-scrollbar-track { background: transparent; }
        .dashboard-scroll::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.3); border-radius: 9999px; }
        .dark .dashboard-scroll::-webkit-scrollbar-thumb { background: rgba(51, 65, 85, 0.4); }
        .dashboard-scroll::-webkit-scrollbar-thumb:hover { background: rgba(6, 182, 212, 0.6); }

        @keyframes pulseGlow {
            0%, 100% { opacity: 0.8; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.05); }
        }
        .glow-pulse { animation: pulseGlow 3s infinite ease-in-out; }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#06b6d4">
    <script src="pwa.js" defer></script>
    <script src="mobile.js" defer></script>
</head>
<body class="bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 h-screen flex flex-col font-sans overflow-hidden antialiased transition-colors duration-200 selection:bg-cyan-500 selection:text-white">

    <!-- Ambient Glowing Orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <!-- HEADER BAR -->
    <header class="glass-card border-b border-slate-200 dark:border-slate-800/80 px-5 py-3.5 flex items-center justify-between z-30 shrink-0">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl bg-slate-200/60 dark:bg-slate-900 border border-slate-300/60 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 transition">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-tr from-cyan-600 to-emerald-500 rounded-xl flex items-center justify-center text-white shadow-lg shadow-cyan-500/20 ring-1 ring-white/20">
                    <i data-lucide="map-pin" class="w-5 h-5"></i>
                </div>
                <div>
                    <span class="font-black text-slate-900 dark:text-white tracking-wider text-base block leading-tight">MATUTUM <span class="text-cyan-600 dark:text-cyan-400">PLOTNAV</span></span>
                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-bold tracking-wider uppercase">Visitor Portal</span>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button id="themeToggleBtn" type="button" 
                    onclick="toggleTheme()"
                    class="p-2.5 rounded-xl bg-white dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/60 text-slate-700 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 transition shadow-sm" 
                    title="Toggle Theme">
                <i id="themeToggleIcon" data-lucide="sun" class="w-4 h-4 text-amber-500"></i>
            </button>

            <!-- Notification Bell -->
            <div class="relative" id="notificationWrapper">
                <button id="notificationBtn" type="button"
                        onclick="toggleNotifications()"
                        class="relative p-2.5 rounded-xl bg-white dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/60 text-slate-700 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 transition shadow-sm"
                        title="Notifications">
                    <i data-lucide="bell" class="w-4 h-4"></i>
                    <?php if ($unread_count > 0): ?>
                        <span id="notifBadge" class="absolute -top-1 -right-1 h-4 w-4 bg-rose-500 text-white text-[9px] font-bold flex items-center justify-center rounded-full ring-2 ring-white dark:ring-slate-900"><?= $unread_count ?></span>
                    <?php else: ?>
                        <span id="notifBadge" class="absolute -top-1 -right-1 h-4 w-4 bg-rose-500 text-white text-[9px] font-bold flex items-center justify-center rounded-full ring-2 ring-white dark:ring-slate-900 hidden"></span>
                    <?php endif; ?>
                </button>
                <div id="notificationPanel" class="hidden absolute right-0 mt-2 w-80 glass-card rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl p-0 z-50 overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-slate-700/60 bg-slate-50/50 dark:bg-slate-900/50">
                        <h3 class="text-xs font-bold text-slate-900 dark:text-white">Notifications</h3>
                        <?php if (!empty($user_notifications)): ?>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="markAllNotificationsRead()" class="text-[10px] font-semibold text-cyan-600 dark:text-cyan-400 hover:underline">Mark all as read</button>
                                <span id="notifNewCount" class="text-[10px] px-2 py-0.5 rounded-full bg-rose-500/10 text-rose-600 dark:text-rose-400 font-bold"><?= $unread_count ?> new</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="max-h-72 overflow-y-auto">
                        <?php if (empty($user_notifications)): ?>
                            <div class="px-4 py-6 text-center text-xs text-slate-500 dark:text-slate-400">No new notifications</div>
                        <?php else: ?>
                            <ul class="divide-y divide-slate-200 dark:divide-slate-700/60">
                                <?php foreach ($user_notifications as $n): ?>
                                    <li class="px-4 py-3 hover:bg-slate-100 dark:hover:bg-slate-800/50 transition cursor-pointer <?= $n['is_read'] ? 'opacity-60' : 'border-l-2 border-cyan-500 bg-cyan-50 dark:bg-cyan-900/20' ?>"
                                        data-id="<?= $n['id'] ?>"
                                        data-read="<?= $n['is_read'] ? '1' : '0' ?>"
                                        data-title="<?= htmlspecialchars($n['title'], ENT_QUOTES) ?>"
                                        data-message="<?= htmlspecialchars($n['message'], ENT_QUOTES) ?>"
                                        data-date="<?= !empty($n['created_at']) ? date('M d, Y h:i A', strtotime($n['created_at'])) : '' ?>"
                                        onclick="openNotificationDialog(this)">
                                        <p class="text-xs font-bold text-slate-900 dark:text-white truncate pointer-events-none"><?= htmlspecialchars($n['title']) ?></p>
                                        <p class="text-[11px] text-slate-600 dark:text-slate-400 line-clamp-2 mt-0.5 pointer-events-none"><?= htmlspecialchars($n['message']) ?></p>
                                        <p class="text-[9px] text-slate-400 dark:text-slate-500 mt-1 pointer-events-none"><?= !empty($n['created_at']) ? date('M d, Y h:i A', strtotime($n['created_at'])) : '' ?></p>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <a href="user_reservations.php" class="block px-4 py-2.5 text-center text-[11px] font-bold text-cyan-600 dark:text-cyan-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 border-t border-slate-200 dark:border-slate-700/60 transition">View my reservations</a>
                </div>
            </div>

            <a href="logout.php" class="p-2.5 rounded-xl bg-white dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/60 text-slate-700 dark:text-slate-300 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition shadow-sm" title="Logout">
                <i data-lucide="log-out" class="w-4 h-4"></i>
            </a>
        </div>
    </header>

    <!-- MAIN BODY -->
    <div class="flex-1 flex relative overflow-hidden z-10">

        <?php if (file_exists('user_sidebar.php')) { include 'user_sidebar.php'; } ?>

        <!-- DASHBOARD CONTENT AREA -->
        <main class="flex-1 p-4 md:p-6 overflow-y-auto dashboard-scroll space-y-6">

            <!-- WELCOME CARD -->
            <div class="glass-card rounded-3xl p-6 md:p-7 relative overflow-hidden border border-slate-200 dark:border-slate-800 shadow-sm">
                <div class="absolute -right-10 -bottom-10 w-56 h-56 bg-cyan-500/10 dark:bg-cyan-500/15 rounded-full blur-2xl pointer-events-none"></div>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 relative z-10">
                    <div>
                        <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-cyan-500/10 text-cyan-700 dark:text-cyan-400 border border-cyan-500/20 text-[11px] font-bold tracking-wide uppercase mb-3">
                            <span class="w-2 h-2 rounded-full bg-cyan-500 animate-ping"></span>
                            Overview Console
                        </div>
                        <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            Welcome back, <?= htmlspecialchars($_SESSION['name'] ?? 'Visitor') ?>!
                        </h2>
                        <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 mt-1 font-medium">
                            Here is your live cemetery navigation, booking statistics, and announcements overview.
                        </p>
                    </div>
                    <div class="w-14 h-14 bg-gradient-to-tr from-cyan-500 via-blue-600 to-indigo-600 rounded-2xl flex items-center justify-center text-white shadow-xl shadow-cyan-500/25 ring-2 ring-white/20 shrink-0 self-start sm:self-auto">
                        <i data-lucide="sparkles" class="w-7 h-7"></i>
                    </div>
                </div>
            </div>

            <!-- ENHANCED MODERN ANNOUNCEMENT CARD -->
            <div id="announcementCard" 
                 class="glass-card rounded-3xl p-6 border border-cyan-500/30 dark:border-cyan-500/25 bg-gradient-to-r from-cyan-500/10 via-blue-500/5 to-emerald-500/10 dark:from-cyan-950/40 dark:via-slate-900/60 dark:to-emerald-950/30 shadow-sm relative overflow-hidden transition-all duration-300" 
                 <?= !empty($active_announcements) ? "data-ids='" . json_encode(array_column($active_announcements, 'id')) . "'" : '' ?>>
                
                <div class="flex items-start gap-4 sm:gap-5 relative z-10">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-cyan-500 to-blue-600 text-white flex items-center justify-center shrink-0 shadow-lg shadow-cyan-500/30 ring-2 ring-white/20 glow-pulse">
                        <i data-lucide="megaphone" class="w-6 h-6"></i>
                    </div>
                    
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <div class="flex items-center gap-2.5">
                                <h3 class="text-base font-extrabold text-slate-900 dark:text-white tracking-tight flex items-center gap-2">
                                    System Bulletin
                                </h3>
                                <span id="announcementCountBadge" class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-700 dark:text-amber-400 border border-amber-500/30 <?= empty($active_announcements) ? 'hidden' : '' ?>">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                    <span id="announcementCountText"><?= count($active_announcements) ?> Active</span>
                                </span>
                            </div>

                            <div id="announcementControls" class="flex items-center gap-2 <?= empty($active_announcements) ? 'hidden' : '' ?>">
                                <span class="text-[10px] text-slate-500 dark:text-slate-400 font-medium hidden sm:inline-block">Auto-dismisses in 1 min</span>
                                <button onclick="dismissAnnouncements()" class="p-1.5 rounded-xl text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-200/50 dark:hover:bg-slate-800/60 transition">
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <div id="announcementList">
                            <?php if (empty($active_announcements)): ?>
                                <div class="p-4 rounded-2xl bg-white/60 dark:bg-slate-900/40 border border-slate-200/80 dark:border-slate-800/60 flex items-center gap-3">
                                    <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-500 shrink-0"></i>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 font-medium">All clear! No active broadcast announcements at this moment.</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($active_announcements as $a): ?>
                                        <div class="p-4 rounded-2xl bg-white/80 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800/80 shadow-sm backdrop-blur-md hover:border-cyan-500/30 transition duration-200">
                                            <div class="flex items-center justify-between gap-2 mb-1">
                                                <h4 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                                    <i data-lucide="bell" class="w-3.5 h-3.5 text-cyan-600 dark:text-cyan-400"></i>
                                                    <?= htmlspecialchars($a['title']) ?>
                                                </h4>
                                                <span class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-lg border border-slate-200 dark:border-slate-700/60 shrink-0">
                                                    <?= date('M j, Y', strtotime($a['created_at'])) ?>
                                                </span>
                                            </div>
                                            <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed pl-5">
                                                <?= nl2br(htmlspecialchars($a['message'])) ?>
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- METRICS CARDS GRID -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <a href="reservation.php" class="metric-card glass-card rounded-2xl p-5 block shadow-sm border border-slate-200 dark:border-slate-800">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">My Reservations</span>
                        <div class="w-10 h-10 bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 rounded-xl border border-cyan-500/20 flex items-center justify-center">
                            <i data-lucide="bookmark" class="w-5 h-5"></i>
                        </div>
                    </div>
                    <div class="text-3xl font-black text-slate-900 dark:text-white mb-1 tracking-tight"><?= count($my_reservations) ?></div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1.5 font-medium">
                        <i data-lucide="clock" class="w-3.5 h-3.5 text-cyan-600 dark:text-cyan-400"></i> Active applications
                    </div>
                </a>

                <a href="payment_history.php" class="metric-card glass-card rounded-2xl p-5 block shadow-sm border border-slate-200 dark:border-slate-800">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Paid</span>
                        <div class="w-10 h-10 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 rounded-xl border border-emerald-500/20 flex items-center justify-center">
                            <i data-lucide="credit-card" class="w-5 h-5"></i>
                        </div>
                    </div>
                    <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mb-1 tracking-tight">₱<?= number_format(array_sum(array_column($my_payments, 'amount') ?? [0]), 0) ?></div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1.5 font-medium">
                        <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400"></i> Lifetime transactions
                    </div>
                </a>

                <div class="metric-card glass-card rounded-2xl p-5 shadow-sm border border-slate-200 dark:border-slate-800">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Occupancy Rate</span>
                        <div class="w-10 h-10 bg-blue-500/10 text-blue-600 dark:text-blue-400 rounded-xl border border-blue-500/20 flex items-center justify-center">
                            <i data-lucide="pie-chart" class="w-5 h-5"></i>
                        </div>
                    </div>
                    <div class="text-3xl font-black text-slate-900 dark:text-white mb-1 tracking-tight"><?= $occupancy_rate ?>%</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1.5 font-medium">
                        <i data-lucide="layers" class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400"></i> Cemetery total capacity
                    </div>
                </div>

                <a href="reservation.php" class="metric-card glass-card rounded-2xl p-5 block shadow-sm border border-slate-200 dark:border-slate-800">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Available Plots</span>
                        <div class="w-10 h-10 bg-amber-500/10 text-amber-600 dark:text-amber-400 rounded-xl border border-amber-500/20 flex items-center justify-center">
                            <i data-lucide="layout-grid" class="w-5 h-5"></i>
                        </div>
                    </div>
                    <div class="text-3xl font-black text-slate-900 dark:text-white mb-1 tracking-tight"><?= $available_count ?></div>
                    <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1.5 font-medium">
                        <i data-lucide="plus-circle" class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400"></i> Ready for booking
                    </div>
                </a>

            </div>

            <!-- WAVE GRAPHS & CAPACITY BREAKDOWN -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- WAVE GRAPH: RESERVATION & OCCUPANCY TRENDS -->
                <div class="lg:col-span-2 glass-card rounded-3xl p-6 flex flex-col justify-between shadow-sm border border-slate-200 dark:border-slate-800">
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <h3 class="text-xs font-bold text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="activity" class="w-4 h-4 text-cyan-600 dark:text-cyan-400"></i> Activity & Occupancy Wave Trend
                            </h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Smooth continuous wave representation of plot utilization</p>
                        </div>
                        <span class="text-[10px] font-mono font-bold bg-cyan-500/10 text-cyan-700 dark:text-cyan-400 border border-cyan-500/20 px-3 py-1 rounded-xl flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-cyan-500 animate-ping"></span> Live Wave
                        </span>
                    </div>
                    <div class="relative h-64 w-full">
                        <canvas id="waveTrendChart"></canvas>
                    </div>
                </div>

                <!-- CAPACITY BREAKDOWN & DOUGHNUT -->
                <div class="glass-card rounded-3xl p-6 flex flex-col justify-between shadow-sm border border-slate-200 dark:border-slate-800">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xs font-bold text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="pie-chart" class="w-4 h-4 text-cyan-600 dark:text-cyan-400"></i> Capacity Distribution
                            </h3>
                        </div>
                        <div class="relative h-44 w-full flex items-center justify-center">
                            <canvas id="occupancyChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="space-y-3 mt-4 pt-4 border-t border-slate-200 dark:border-slate-800">
                        <div>
                            <div class="flex justify-between text-xs mb-1 font-medium">
                                <span class="text-slate-600 dark:text-slate-400">Occupied</span>
                                <span class="text-cyan-600 dark:text-cyan-400 font-bold"><?= $occupied_count ?></span>
                            </div>
                            <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-cyan-500 to-blue-600 rounded-full" style="width: <?= round(($occupied_count / $total_plots_estimate) * 100) ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs mb-1 font-medium">
                                <span class="text-slate-600 dark:text-slate-400">Reserved</span>
                                <span class="text-amber-600 dark:text-amber-400 font-bold"><?= $reserved_count ?></span>
                            </div>
                            <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                                <div class="h-full bg-amber-500 rounded-full" style="width: <?= round(($reserved_count / $total_plots_estimate) * 100) ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs mb-1 font-medium">
                                <span class="text-slate-600 dark:text-slate-400">Available</span>
                                <span class="text-emerald-600 dark:text-emerald-400 font-bold"><?= $available_count ?></span>
                            </div>
                            <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                                <div class="h-full bg-emerald-500 rounded-full" style="width: <?= round(($available_count / $total_plots_estimate) * 100) ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RECENT ACTIVITY FEED -->
            <div class="glass-card rounded-3xl p-6 shadow-sm border border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-xs font-bold text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-2">
                        <i data-lucide="history" class="w-4 h-4 text-cyan-600 dark:text-cyan-400"></i> Recent Activity Feed
                    </h3>
                    <span class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 bg-slate-200/60 dark:bg-slate-800 px-2.5 py-1 rounded-lg">Realtime</span>
                </div>

                <div class="space-y-3">
                    <?php if (empty($my_reservations) && empty($my_payments)): ?>
                        <div class="text-center py-10">
                            <i data-lucide="inbox" class="w-10 h-10 text-slate-400 dark:text-slate-600 mx-auto mb-2"></i>
                            <p class="text-xs text-slate-500 font-medium">No recent activity found</p>
                        </div>
                    <?php else: ?>
                        <?php 
                            $activities = [];
                            foreach ($my_reservations as $res) {
                                $activities[] = [
                                    'title' => 'Plot Reservation',
                                    'subtitle' => 'Plot Code: ' . $res['plot_code'],
                                    'status' => $res['status'],
                                    'date' => $res['created_at'],
                                    'icon' => 'bookmark',
                                    'color' => 'cyan',
                                    'link' => 'reservation.php'
                                ];
                            }
                            foreach ($my_payments as $pay) {
                                $activities[] = [
                                    'title' => 'Payment Submitted',
                                    'subtitle' => 'Amount: ₱' . number_format($pay['amount'] ?? 0, 2),
                                    'status' => $pay['status'] ?? 'pending',
                                    'date' => $pay['created_at'],
                                    'icon' => 'credit-card',
                                    'color' => 'emerald',
                                    'link' => 'payment_history.php'
                                ];
                            }
                            usort($activities, function($a, $b) {
                                return strtotime($b['date']) - strtotime($a['date']);
                            });
                            $activities = array_slice($activities, 0, 5);
                        ?>
                        <?php foreach ($activities as $activity): ?>
                            <?php 
                                $statusBadge = 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700';
                                if (in_array($activity['status'], ['approved', 'completed', 'verified'])) {
                                    $statusBadge = 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/30';
                                } elseif ($activity['status'] === 'pending') {
                                    $statusBadge = 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/30';
                                } elseif ($activity['status'] === 'rejected') {
                                    $statusBadge = 'bg-red-500/10 text-red-700 dark:text-red-400 border-red-500/30';
                                }
                            ?>
                            <a href="<?= $activity['link'] ?>" class="flex items-center justify-between p-3.5 bg-white/70 dark:bg-slate-900/50 hover:bg-white dark:hover:bg-slate-800/80 rounded-2xl border border-slate-200 dark:border-slate-800 transition duration-200 group shadow-sm">
                                <div class="flex items-center gap-3.5 min-w-0">
                                    <div class="w-10 h-10 bg-slate-100 dark:bg-slate-800 text-cyan-600 dark:text-cyan-400 rounded-xl flex items-center justify-center shrink-0 border border-slate-200 dark:border-slate-700/60 group-hover:border-cyan-500/40 transition shadow-sm">
                                        <i data-lucide="<?= $activity['icon'] ?>" class="w-5 h-5"></i>
                                    </div>
                                    <div class="truncate">
                                        <p class="text-xs font-bold text-slate-900 dark:text-white truncate"><?= htmlspecialchars($activity['title']) ?></p>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5"><?= htmlspecialchars($activity['subtitle']) ?></p>
                                    </div>
                                </div>
                                <div class="text-right shrink-0 flex items-center gap-3">
                                    <span class="text-[10px] uppercase font-bold px-2.5 py-1 rounded-lg border <?= $statusBadge ?>">
                                        <?= htmlspecialchars(ucfirst($activity['status'])) ?>
                                    </span>
                                    <span class="text-[11px] text-slate-500 dark:text-slate-400 font-medium hidden sm:inline-block"><?= date('M d, Y', strtotime($activity['date'])) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function renderIcons() {
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
        }

        const occupancyData = <?= json_encode([$occupied_count, $reserved_count, $available_count]) ?>;
        const waveLabels = <?= json_encode($monthly_labels) ?>;
        const waveData = <?= json_encode($occupancy_wave_data) ?>;

        let waveChart, occupancyChart;

        function getChartColors() {
            const isDark = document.documentElement.classList.contains('dark');
            return {
                gridColor: isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(203, 213, 225, 0.6)',
                tickColor: isDark ? '#94a3b8' : '#64748b',
                tooltipBg: isDark ? 'rgba(15, 23, 42, 0.95)' : 'rgba(255, 255, 255, 0.95)',
                tooltipText: isDark ? '#f8fafc' : '#0f172a'
            };
        }

        function initCharts() {
            const colors = getChartColors();

            // 1. WAVE GRAPH
            const waveCtx = document.getElementById('waveTrendChart').getContext('2d');
            const gradientCyan = waveCtx.createLinearGradient(0, 0, 0, 250);
            gradientCyan.addColorStop(0, 'rgba(6, 182, 212, 0.35)');
            gradientCyan.addColorStop(1, 'rgba(6, 182, 212, 0.0)');

            if (waveChart) waveChart.destroy();
            waveChart = new Chart(waveCtx, {
                type: 'line',
                data: {
                    labels: waveLabels,
                    datasets: [{
                        label: 'Occupancy Rate (%)',
                        data: waveData,
                        borderColor: '#06b6d4',
                        borderWidth: 3,
                        fill: true,
                        backgroundColor: gradientCyan,
                        tension: 0.45,
                        pointBackgroundColor: '#0891b2',
                        pointBorderColor: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: colors.tickColor, font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' } }
                        },
                        y: {
                            grid: { color: colors.gridColor },
                            ticks: { color: colors.tickColor, font: { family: 'Plus Jakarta Sans', size: 11 } }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: colors.tooltipBg,
                            titleColor: colors.tooltipText,
                            bodyColor: '#06b6d4',
                            borderColor: colors.gridColor,
                            borderWidth: 1,
                            padding: 12,
                            cornerRadius: 12
                        }
                    }
                }
            });

            // 2. DOUGHNUT CHART
            const ctx = document.getElementById('occupancyChart').getContext('2d');
            if (occupancyChart) occupancyChart.destroy();
            occupancyChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Occupied', 'Reserved', 'Available'],
                    datasets: [{
                        data: occupancyData,
                        backgroundColor: ['#06b6d4', '#f59e0b', '#10b981'],
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '76%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: colors.tooltipBg,
                            bodyColor: colors.tooltipText,
                            borderColor: colors.gridColor,
                            borderWidth: 1,
                            padding: 12,
                            cornerRadius: 12
                        }
                    }
                }
            });
        }

        function toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon(isDark ? 'sun' : 'moon');
            initCharts();
        }

        function updateThemeIcon(iconName) {
            const iconEl = document.getElementById('themeToggleIcon');
            if (iconEl) {
                iconEl.setAttribute('data-lucide', iconName);
                renderIcons();
            }
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if(sidebar) sidebar.classList.toggle('-translate-x-full');
        }

        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
                updateThemeIcon('sun');
            } else {
                document.documentElement.classList.remove('dark');
                updateThemeIcon('moon');
            }
            
            renderIcons();
            initCharts();
        });

        window.addEventListener('load', renderIcons);
    </script>

    <script>
        let annDismissTimer = null;

        function safeParse(str, fallback) {
            try { return JSON.parse(str); } catch (e) { return fallback; }
        }

        function dismissAnnouncements() {
            const card = document.getElementById('announcementCard');
            if (!card) return;
            if (card.dataset.ids) {
                try {
                    const ids = safeParse(card.dataset.ids, []);
                    let dismissed = safeParse(localStorage.getItem('dismissedAnnouncements'), []);
                    if (!Array.isArray(dismissed)) dismissed = [];
                    const list = Array.isArray(ids) ? ids : [ids];
                    list.forEach(function (id) {
                        const s = String(id);
                        if (!dismissed.includes(s)) dismissed.push(s);
                    });
                    localStorage.setItem('dismissedAnnouncements', JSON.stringify(dismissed));
                } catch (e) {}
            }
            card.classList.add('hidden');
        }

        (function () {
            const card = document.getElementById('announcementCard');
            if (!card) return;
            if (card.dataset.ids) {
                try {
                    const activeIds = safeParse(card.dataset.ids, []);
                    let dismissed = safeParse(localStorage.getItem('dismissedAnnouncements'), []);
                    if (!Array.isArray(dismissed)) dismissed = [];
                    const activeStrings = (Array.isArray(activeIds) ? activeIds : [activeIds]).map(String);
                    const hasNew = activeStrings.some(function (id) { return !dismissed.includes(id); });
                    if (!hasNew) {
                        card.classList.add('hidden');
                    } else {
                        annDismissTimer = setTimeout(dismissAnnouncements, 60000);
                    }
                } catch (e) {
                    annDismissTimer = setTimeout(dismissAnnouncements, 60000);
                }
            }
        })();

        // Live-update the System Bulletin card when announcements change
        document.addEventListener('announcements:updated', function (e) {
            const anns = (e.detail && e.detail.active) || [];
            const card = document.getElementById('announcementCard');
            const list = document.getElementById('announcementList');
            const badge = document.getElementById('announcementCountBadge');
            const badgeText = document.getElementById('announcementCountText');
            const controls = document.getElementById('announcementControls');
            if (!card || !list) return;

            const L = window.AnnouncementLive || {};
            const esc = L.escapeHtml || function (s) { return String(s || ''); };
            const br = L.nl2br || esc;
            const fmt = L.formatDate || function (s) { return s || ''; };

            card.dataset.ids = JSON.stringify(anns.map(function (a) { return a.id; }));

            if (badge && badgeText) {
                badgeText.textContent = anns.length + ' Active';
                badge.classList.toggle('hidden', !anns.length);
            }
            if (controls) controls.classList.toggle('hidden', !anns.length);

            if (!anns.length) {
                list.innerHTML = '<div class="p-4 rounded-2xl bg-white/60 dark:bg-slate-900/40 border border-slate-200/80 dark:border-slate-800/60 flex items-center gap-3">' +
                    '<i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-500 shrink-0"></i>' +
                    '<p class="text-xs text-slate-600 dark:text-slate-400 font-medium">All clear! No active broadcast announcements at this moment.</p></div>';
                card.classList.remove('hidden');
                if (window.lucide) lucide.createIcons();
                return;
            }

            list.innerHTML = '<div class="space-y-3">' + anns.map(function (a) {
                return '<div class="p-4 rounded-2xl bg-white/80 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800/80 shadow-sm backdrop-blur-md hover:border-cyan-500/30 transition duration-200">' +
                    '<div class="flex items-center justify-between gap-2 mb-1">' +
                        '<h4 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white flex items-center gap-1.5">' +
                            '<i data-lucide="bell" class="w-3.5 h-3.5 text-cyan-600 dark:text-cyan-400"></i>' + esc(a.title) +
                        '</h4>' +
                        '<span class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-lg border border-slate-200 dark:border-slate-700/60 shrink-0">' + fmt(a.created_at) + '</span>' +
                    '</div>' +
                    '<p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed pl-5">' + br(a.message) + '</p>' +
                '</div>';
            }).join('') + '</div>';

            let dismissed = safeParse(localStorage.getItem('dismissedAnnouncements'), []);
            if (!Array.isArray(dismissed)) dismissed = [];
            const hasNew = anns.some(function (a) { return dismissed.indexOf(String(a.id)) === -1; });
            if (hasNew) {
                card.classList.remove('hidden');
                clearTimeout(annDismissTimer);
                annDismissTimer = setTimeout(dismissAnnouncements, 60000);
            } else {
                card.classList.add('hidden');
            }

            if (window.lucide) lucide.createIcons();
        });
    </script>
    <div id="notificationDialog" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/40 backdrop-blur-sm p-4" onclick="if (event.target === this) closeNotificationDialog()">
        <div class="glass-card w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl p-5 relative">
            <button type="button" onclick="closeNotificationDialog()" class="absolute top-3 right-3 p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
            <h3 id="notifDialogTitle" class="text-sm font-bold text-slate-900 dark:text-white mb-2 pr-6"></h3>
            <p id="notifDialogDate" class="text-[10px] text-slate-500 dark:text-slate-400 mb-3"></p>
            <div id="notifDialogBody" class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed max-h-72 overflow-y-auto whitespace-pre-wrap"></div>
        </div>
    </div>

    <script>
        function toggleNotifications() {
            const panel = document.getElementById('notificationPanel');
            if (panel) panel.classList.toggle('hidden');
        }
        document.addEventListener('click', function (e) {
            const btn = document.getElementById('notificationBtn');
            const panel = document.getElementById('notificationPanel');
            if (!btn || !panel) return;
            if (!btn.contains(e.target) && !panel.contains(e.target)) {
                panel.classList.add('hidden');
            }
        });

        function openNotificationDialog(el) {
            const panel = document.getElementById('notificationPanel');
            if (panel) panel.classList.add('hidden');

            document.getElementById('notifDialogTitle').textContent = el.dataset.title || '';
            document.getElementById('notifDialogDate').textContent = el.dataset.date || '';
            document.getElementById('notifDialogBody').textContent = el.dataset.message || '';
            document.getElementById('notificationDialog').classList.remove('hidden');

            if (el.dataset.id && el.dataset.read !== '1') {
                fetch('notifications.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({ action: 'mark_read', id: el.dataset.id })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.status === 'success') {
                        el.dataset.read = '1';
                        updateNotificationUI();
                    }
                })
                .catch(function (err) { console.error(err); });
            }
        }

        function closeNotificationDialog() {
            document.getElementById('notificationDialog').classList.add('hidden');
        }

        function markAllNotificationsRead() {
            const items = document.querySelectorAll('#notificationPanel li[data-id]');
            if (!items.length) return;

            fetch('notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ action: 'mark_all' })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status === 'success') {
                    items.forEach(function (li) { li.dataset.read = '1'; });
                    updateNotificationUI();
                }
            })
            .catch(function (err) { console.error(err); });
        }

        function updateNotificationUI() {
            const items = document.querySelectorAll('#notificationPanel li[data-id]');
            let unread = 0;
            items.forEach(function (li) {
                const isRead = li.dataset.read === '1';
                li.classList.toggle('opacity-60', isRead);
                if (isRead) {
                    li.classList.remove('border-l-2', 'border-cyan-500', 'bg-cyan-50', 'dark:bg-cyan-900/20');
                } else {
                    unread++;
                    li.classList.add('border-l-2', 'border-cyan-500', 'bg-cyan-50', 'dark:bg-cyan-900/20');
                }
            });

            const badge = document.getElementById('notifBadge');
            if (badge) {
                badge.style.display = unread ? 'flex' : 'none';
                badge.textContent = unread;
            }

            const count = document.getElementById('notifNewCount');
            if (count) {
                count.textContent = unread ? (unread + ' new') : '0 new';
            }
        }

        document.addEventListener('DOMContentLoaded', updateNotificationUI);
    </script>
</body>
</html>