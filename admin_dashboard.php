<?php
require 'config.php';

// Access Control
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$error = '';

// Handle Reservation Actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_reservation_status') {
        $reservation_id = intval($_POST['reservation_id']);
        $new_status     = trim($_POST['status']);

        try {
            $infoStmt = $pdo->prepare("SELECT user_id, reservation_id AS res_ref FROM public.reservations WHERE id = ?");
            $infoStmt->execute([$reservation_id]);
            $resInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE public.reservations SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $reservation_id]);

            if ($resInfo && in_array(strtolower($new_status), ['approved', 'rejected']) && !empty($resInfo['user_id'])) {
                $title = ($new_status === 'Approved') ? 'Reservation Approved' : 'Reservation Rejected';
                $msg = "Your reservation " . (!empty($resInfo['res_ref']) ? '#' . $resInfo['res_ref'] : '#' . $reservation_id) . " has been " . strtolower($new_status) . ".";
                create_user_notification($pdo, $resInfo['user_id'], $title, $msg, 'reservation', $reservation_id);
            }

            $message = "Reservation #{$reservation_id} marked as " . ucfirst($new_status) . ".";
        } catch (PDOException $e) {
            $error = "Failed to update status: " . $e->getMessage();
        }
    }

    if ($_POST['action'] === 'mark_admin_notif' || $_POST['action'] === 'mark_all_admin_notif') {
        header('Content-Type: application/json');
        init_admin_notifications_table($pdo);

        try {
            if ($_POST['action'] === 'mark_all_admin_notif') {
                $pdo->prepare("UPDATE public.admin_notifications SET is_read = TRUE WHERE is_read = FALSE")->execute();
                echo json_encode(['status' => 'success', 'affected' => 'all']);
            } else {
                $notif_id = intval($_POST['notif_id'] ?? 0);
                $pdo->prepare("UPDATE public.admin_notifications SET is_read = TRUE WHERE id = ?")->execute([$notif_id]);
                echo json_encode(['status' => 'success', 'affected' => $notif_id]);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Quick Metrics
$total_plots = $pdo->query("SELECT COUNT(*) FROM public.deceased_records")->fetchColumn();
$total_sections = $pdo->query("SELECT COUNT(*) FROM public.cemetery_sections")->fetchColumn();
$total_shapes = $pdo->query("SELECT COUNT(*) FROM public.cemetery_layouts")->fetchColumn();

try {
    $total_staff = $pdo->query("
        SELECT COUNT(*)
        FROM public.profiles p
        JOIN auth.users au ON au.id = p.id
        WHERE au.raw_user_meta_data->>'role' = 'staff'
    ")->fetchColumn();
} catch (PDOException $e) { $total_staff = 0; }

// Reservation Metrics & Feed
try {
    $pending_reservations_count = $pdo->query("SELECT COUNT(*) FROM public.reservations WHERE status = 'pending'")->fetchColumn();
    $recent_reservations = $pdo->query("
        SELECT r.*, TRIM(COALESCE(p.first_name, '') || ' ' || COALESCE(p.last_name, '')) as full_name, p.email
        FROM public.reservations r
        LEFT JOIN public.profiles p ON r.user_id = p.id::text
        ORDER BY r.created_at DESC LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) {
    $pending_reservations_count = 0;
    $recent_reservations = [];
}

// Reservation status breakdown
try {
    $status_rows = $pdo->query("SELECT status, COUNT(*) AS count FROM public.reservations GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $reservationStatusData = [
        'pending'  => (int)($status_rows['pending'] ?? 0),
        'approved' => (int)($status_rows['approved'] ?? 0),
        'rejected' => (int)($status_rows['rejected'] ?? 0),
    ];
} catch (PDOException $e) {
    $reservationStatusData = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
}

// Section Capacity Data
$section_overview = $pdo->query("
    SELECT 
        cs.section_code, 
        cs.section_name, 
        cs.total_plots,
        COUNT(dr.id) AS occupied_plots
    FROM public.cemetery_sections cs
    LEFT JOIN public.deceased_records dr ON dr.plot_code LIKE CONCAT(cs.section_code, '%')
    GROUP BY cs.id, cs.section_code, cs.section_name, cs.total_plots
    ORDER BY cs.section_code ASC
")->fetchAll();

$chart_labels = [];
$chart_occupied = [];
$chart_available = [];

foreach ($section_overview as $sec) {
    $chart_labels[] = $sec['section_code'];
    $chart_occupied[] = (int)$sec['occupied_plots'];
    $avail = max(0, (int)$sec['total_plots'] - (int)$sec['occupied_plots']);
    $chart_available[] = $avail;
}

// PREDICTIVE ENGINE
$total_capacity = (int)$pdo->query("SELECT COALESCE(SUM(total_plots), 0) FROM public.cemetery_sections")->fetchColumn();
$total_reserved = (int)$pdo->query("SELECT COUNT(*) FROM public.reservations WHERE status IN ('pending', 'approved')")->fetchColumn();
$total_occupied = (int)$total_plots;
$remaining_plots = max(0, $total_capacity - ($total_occupied + $total_reserved));

// Monthly Historical Data
$historical_trend = $pdo->query("
    SELECT 
        TO_CHAR(DATE_TRUNC('month', created_at), 'Mon YYYY') as month_label,
        DATE_TRUNC('month', created_at) as month_start,
        COUNT(*) as count
    FROM public.deceased_records
    WHERE created_at >= NOW() - INTERVAL '6 months'
    GROUP BY month_start, month_label
    ORDER BY month_start ASC
")->fetchAll(PDO::FETCH_ASSOC);

$trend_labels = [];
$trend_actual = [];

foreach ($historical_trend as $row) {
    $trend_labels[] = $row['month_label'];
    $trend_actual[] = (int)$row['count'];
}

// Calculate Average Absorption Rate
$data_points_count = count($trend_actual);
$monthly_rate = $data_points_count > 0 ? (array_sum($trend_actual) / $data_points_count) : 1;
$monthly_rate = max(0.5, round($monthly_rate, 2));

$months_until_full = $remaining_plots > 0 ? ceil($remaining_plots / $monthly_rate) : 0;
$estimated_exhaustion_date = $remaining_plots > 0 ? date('M Y', strtotime("+{$months_until_full} months")) : 'Fully Occupied';

// Clean Forecast Label Generation
$forecast_labels = $trend_labels;
$forecast_historical = $trend_actual;
$forecast_projected = array_fill(0, count($trend_actual), null);

if (!empty($trend_actual)) {
    $forecast_projected[count($trend_actual) - 1] = end($trend_actual);
}

$running_occupied = $total_occupied;
for ($i = 1; $i <= 6; $i++) {
    $future_time = strtotime("+{$i} month");
    $forecast_labels[] = date('Mon YYYY', $future_time);
    $forecast_historical[] = null;
    $running_occupied += round($monthly_rate);
    $forecast_projected[] = $running_occupied;
}

// Active notifications for admin
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
          AND (audience IN ('all', 'admin'))
          AND (start_date IS NULL OR start_date <= CURRENT_DATE)
          AND (end_date IS NULL OR end_date >= CURRENT_DATE)
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $active_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Admin notifications fetch: " . $e->getMessage());
    $active_announcements = [];
}

// Reservation notifications for admin bell
$admin_notifications = [];
$admin_unread_count = 0;
try {
    init_admin_notifications_table($pdo);
    $stmt = $pdo->query("
        SELECT id, title, message, type, related_id, is_read, created_at
        FROM public.admin_notifications
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $admin_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($admin_notifications as $n) {
        if (empty($n['is_read'])) $admin_unread_count++;
    }
} catch (PDOException $e) {
    error_log("Admin bell fetch: " . $e->getMessage());
    $admin_notifications = [];
    $admin_unread_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - PlotBox GIS System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 850: '#111827', 950: '#030712' },
                        brand: { 50: '#ecfeff', 500: '#06b6d4', 600: '#0891b2' }
                    }
                }
            }
        }
    </script>
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- Lucide Icons Bundle -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card { 
            background: rgba(255, 255, 255, 0.65); 
            backdrop-filter: blur(20px); 
            border: 1px solid rgba(255, 255, 255, 0.6); 
        }
        .dark .glass-card { 
            background: rgba(17, 24, 39, 0.55); 
            backdrop-filter: blur(20px); 
            border: 1px solid rgba(255, 255, 255, 0.05); 
        }
        .glass-header { 
            background: rgba(255, 255, 255, 0.8); 
            backdrop-filter: blur(20px); 
            border-bottom: 1px solid rgba(226, 232, 240, 0.8); 
        }
        .dark .glass-header { 
            background: rgba(3, 7, 18, 0.7); 
            backdrop-filter: blur(20px); 
            border-bottom: 1px solid rgba(255, 255, 255, 0.06); 
        }
        .orb { position: fixed; border-radius: 50%; filter: blur(120px); opacity: 0.15; z-index: 0; pointer-events: none; }
        .dark .orb { opacity: 0.25; }
        .orb-1 { width: 600px; height: 600px; background: radial-gradient(circle, #3b82f6, #8b5cf6); top: -200px; right: -200px; }
        .orb-2 { width: 500px; height: 500px; background: radial-gradient(circle, #06b6d4, #10b981); bottom: -150px; left: -150px; }
        .chart-container { position: relative; height: 320px; width: 100%; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.25); border-radius: 9999px; }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 h-screen flex font-sans overflow-hidden transition-colors duration-300 antialiased selection:bg-cyan-500 selection:text-white">

    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <?php 
    if (file_exists('sidebar.php')) {
        include 'sidebar.php'; 
    } else { ?>
        <!-- INLINE SIDEBAR FALLBACK -->
        <aside id="sidebar" class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col shrink-0 z-20 transition-transform duration-300">
            <div class="p-5 border-b border-slate-800 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-cyan-500/20 text-cyan-400 flex items-center justify-center font-bold">
                    <i data-lucide="user" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-white">james</h3>
                    <p class="text-xs text-slate-400">Administrator</p>
                </div>
            </div>
            <nav class="p-4 space-y-1.5 flex-1 overflow-y-auto">
                <div class="text-[10px] font-bold text-slate-500 uppercase px-3 mb-2">Admin Navigation</div>
                <a href="admin_dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-cyan-500/10 text-cyan-400 font-semibold text-xs border border-cyan-500/20">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard
                </a>
                <a href="admin_plots.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800/60 text-xs transition">
                    <i data-lucide="map-pin" class="w-4 h-4"></i> Plot Management
                </a>
                <a href="admin_sections.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800/60 text-xs transition">
                    <i data-lucide="layers" class="w-4 h-4"></i> Sections
                </a>
                <a href="admin_perimeter.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800/60 text-xs transition">
                    <i data-lucide="square-dashed" class="w-4 h-4"></i> Perimeter
                </a>
                <a href="admin_shapes.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800/60 text-xs transition">
                    <i data-lucide="shapes" class="w-4 h-4"></i> Saved Shapes
                </a>
                <a href="admin_staff.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800/60 text-xs transition">
                    <i data-lucide="users" class="w-4 h-4"></i> Staff Management
                </a>
                <a href="admin_maintenance.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800/60 text-xs transition">
                    <i data-lucide="wrench" class="w-4 h-4"></i> Maintenance
                </a>
                <a href="admin_payments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800/60 text-xs transition">
                    <i data-lucide="credit-card" class="w-4 h-4"></i> Payment Verifications
                </a>
            </nav>
            <div class="p-4 border-t border-slate-800">
                <a href="logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-red-400 hover:bg-red-500/10 text-xs transition">
                    <i data-lucide="log-out" class="w-4 h-4"></i> Logout
                </a>
            </div>
        </aside>
    <?php } ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden relative z-10">

        <!-- HEADER -->
        <header class="glass-header px-8 py-4 flex items-center justify-between shrink-0 shadow-sm transition-all duration-300 z-30">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="lg:hidden p-2.5 rounded-xl bg-slate-200/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 hover:text-cyan-500 transition">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900 dark:text-white flex items-center gap-2.5 tracking-tight">
                        <span class="p-1.5 rounded-lg bg-cyan-500/10 text-cyan-500 border border-cyan-500/20 inline-flex">
                            <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                        </span>
                        System Analytics & Overview
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 font-medium">Real-time GIS statistics, capacity tracking, and predictive forecasting.</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button id="themeToggle" onclick="toggleTheme()" class="p-2.5 rounded-xl bg-slate-200/60 dark:bg-slate-800/60 text-slate-600 dark:text-slate-300 hover:text-cyan-500 dark:hover:text-cyan-400 border border-slate-300/50 dark:border-slate-700/50 transition duration-200">
                    <i id="themeToggleIcon" data-lucide="moon" class="w-4 h-4"></i>
                </button>

                <!-- Notification Bell -->
                <div class="relative" id="notificationWrapper">
                    <button id="notificationBtn" type="button"
                            onclick="toggleNotifications()"
                            class="relative p-2.5 rounded-xl bg-slate-200/60 dark:bg-slate-800/60 border border-slate-300/50 dark:border-slate-700/50 text-slate-600 dark:text-slate-300 hover:text-cyan-500 dark:hover:text-cyan-400 transition duration-200"
                            title="Notifications">
                        <i data-lucide="bell" class="w-4 h-4"></i>
                        <?php if ($admin_unread_count > 0): ?>
                            <span id="adminNotifBadge" class="absolute -top-1 -right-1 h-4 w-4 bg-rose-500 text-white text-[9px] font-bold flex items-center justify-center rounded-full ring-2 ring-white dark:ring-slate-900"><?= $admin_unread_count ?></span>
                        <?php else: ?>
                            <span id="adminNotifBadge" class="absolute -top-1 -right-1 h-4 w-4 bg-rose-500 text-white text-[9px] font-bold flex items-center justify-center rounded-full ring-2 ring-white dark:ring-slate-900 hidden"></span>
                        <?php endif; ?>
                    </button>
                    <div id="notificationPanel" class="hidden absolute right-0 mt-2 w-80 glass-card rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl p-0 z-50 overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-slate-700/60 bg-slate-50/50 dark:bg-slate-900/50">
                            <h3 class="text-xs font-bold text-slate-900 dark:text-white">Notifications</h3>
                            <?php if (!empty($admin_notifications)): ?>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="markAllAdminNotifications()" class="text-[10px] font-semibold text-cyan-600 dark:text-cyan-400 hover:underline">Mark all as read</button>
                                    <span id="adminNotifNewCount" class="text-[10px] px-2 py-0.5 rounded-full bg-rose-500/10 text-rose-600 dark:text-rose-400 font-bold"><?= $admin_unread_count ?> new</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="max-h-72 overflow-y-auto">
                            <?php if (empty($admin_notifications)): ?>
                                <div class="px-4 py-6 text-center text-xs text-slate-500 dark:text-slate-400">No new notifications</div>
                            <?php else: ?>
                                <ul class="divide-y divide-slate-200 dark:divide-slate-700/60" id="adminNotifList">
                                    <?php foreach ($admin_notifications as $n): ?>
                                        <li class="px-4 py-3 hover:bg-slate-100 dark:hover:bg-slate-800/50 transition cursor-pointer <?= $n['is_read'] ? 'opacity-60' : 'border-l-2 border-cyan-500 bg-cyan-50 dark:bg-cyan-900/20' ?>"
                                            data-id="<?= $n['id'] ?>"
                                            data-read="<?= $n['is_read'] ? '1' : '0' ?>"
                                            onclick="openAdminNotification(this)">
                                            <p class="text-xs font-bold text-slate-900 dark:text-white truncate pointer-events-none"><?= htmlspecialchars($n['title']) ?></p>
                                            <p class="text-[11px] text-slate-600 dark:text-slate-400 line-clamp-2 mt-0.5 pointer-events-none"><?= htmlspecialchars($n['message']) ?></p>
                                            <p class="text-[9px] text-slate-400 dark:text-slate-500 mt-1 pointer-events-none"><?= !empty($n['created_at']) ? date('M d, Y h:i A', strtotime($n['created_at'])) : '' ?></p>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <a href="admin_reservations.php" class="block px-4 py-2.5 text-center text-[11px] font-bold text-cyan-600 dark:text-cyan-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 border-t border-slate-200 dark:border-slate-700/60 transition">View reservations</a>
                    </div>
                </div>

                <a href="admin_plots.php" class="bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white font-bold text-xs px-4 py-2.5 rounded-xl transition duration-200 shadow-lg shadow-cyan-500/20 active:scale-95 flex items-center gap-2">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span>Add Plot Record</span>
                </a>
            </div>
        </header>

        <!-- MAIN CONTENT AREA -->
        <div class="flex-1 overflow-y-auto p-6 md:p-8 space-y-6">

            <?php if ($message): ?>
                <div class="p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs rounded-2xl shadow-sm flex items-center gap-2.5">
                    <i data-lucide="check-circle" class="w-4 h-4 text-emerald-500 shrink-0"></i>
                    <span class="font-medium"><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="p-4 bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-xs rounded-2xl shadow-sm flex items-center gap-2.5">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-red-500 shrink-0"></i>
                    <span class="font-medium"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- NEW FEATURES ANNOUNCEMENT -->
            <div class="glass-card rounded-2xl p-5 border border-cyan-500/20 bg-gradient-to-r from-cyan-500/5 to-blue-500/5 dark:from-cyan-900/10 dark:to-blue-900/10">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-xl bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 flex items-center justify-center shrink-0">
                        <i data-lucide="megaphone" class="w-5 h-5"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-2">What's New</h3>
                        <ul class="space-y-1.5 text-xs text-slate-600 dark:text-slate-400">
                            <li class="flex items-start gap-2">
                                <i data-lucide="check-circle" class="w-3.5 h-3.5 text-emerald-500 mt-0.5 shrink-0"></i>
                                <span><strong>Deceased Record Management</strong> — manage records directly from Plot Management.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i data-lucide="trending-up" class="w-3.5 h-3.5 text-amber-500 mt-0.5 shrink-0"></i>
                                <span><strong>Predictive Plot Availability</strong> — plan section capacity and reservation trends.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i data-lucide="check-circle" class="w-3.5 h-3.5 text-emerald-500 mt-0.5 shrink-0"></i>
                                <span><strong>Intelligent Maintenance Scheduling</strong> — auto-prioritized and staff-aware scheduling.</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i data-lucide="sparkles" class="w-3.5 h-3.5 text-purple-500 mt-0.5 shrink-0"></i>
                                <span><strong>Memorial Recommendation</strong> — help users find suitable plots based on preferences.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- METRIC CARDS GRID -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                
                <div class="glass-card rounded-2xl p-4.5 flex flex-col justify-between shadow-sm dark:shadow-2xl hover:translate-y-[-2px] transition duration-300">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Occupied Plots</span>
                        <div class="w-9 h-9 bg-blue-500/10 text-blue-500 border border-blue-500/20 rounded-xl flex items-center justify-center">
                            <i data-lucide="map-pin" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-2xl font-black text-slate-900 dark:text-white tracking-tight block"><?= number_format($total_plots) ?></span>
                        <span class="text-[10px] text-slate-400 dark:text-slate-500 font-medium mt-0.5 block">Recorded graves</span>
                    </div>
                </div>

                <div class="glass-card rounded-2xl p-4.5 flex flex-col justify-between shadow-sm dark:shadow-2xl hover:translate-y-[-2px] transition duration-300">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Pending Requests</span>
                        <div class="w-9 h-9 bg-amber-500/10 text-amber-500 border border-amber-500/20 rounded-xl flex items-center justify-center">
                            <i data-lucide="clock" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-2xl font-black text-amber-500 dark:text-amber-400 tracking-tight block"><?= number_format($pending_reservations_count) ?></span>
                        <span class="text-[10px] text-slate-400 dark:text-slate-500 font-medium mt-0.5 block">Awaiting approval</span>
                    </div>
                </div>

                <div class="glass-card rounded-2xl p-4.5 flex flex-col justify-between shadow-sm dark:shadow-2xl hover:translate-y-[-2px] transition duration-300">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Sections</span>
                        <div class="w-9 h-9 bg-cyan-500/10 text-cyan-500 border border-cyan-500/20 rounded-xl flex items-center justify-center">
                            <i data-lucide="layers" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-2xl font-black text-slate-900 dark:text-white tracking-tight block"><?= number_format($total_sections) ?></span>
                        <span class="text-[10px] text-slate-400 dark:text-slate-500 font-medium mt-0.5 block">Active grounds zones</span>
                    </div>
                </div>

                <div class="glass-card rounded-2xl p-4.5 flex flex-col justify-between shadow-sm dark:shadow-2xl hover:translate-y-[-2px] transition duration-300">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Avg. Absorption</span>
                        <div class="w-9 h-9 bg-purple-500/10 text-purple-500 border border-purple-500/20 rounded-xl flex items-center justify-center">
                            <i data-lucide="zap" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-2xl font-black text-purple-500 dark:text-purple-400 tracking-tight block"><?= $monthly_rate ?></span>
                        <span class="text-[10px] text-slate-400 dark:text-slate-500 font-medium mt-0.5 block">Plots reserved/month</span>
                    </div>
                </div>

                <div class="glass-card rounded-2xl p-4.5 flex flex-col justify-between shadow-sm dark:shadow-2xl hover:translate-y-[-2px] transition duration-300">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Full Depletion</span>
                        <div class="w-9 h-9 bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 rounded-xl flex items-center justify-center">
                            <i data-lucide="hourglass" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="text-lg font-extrabold text-emerald-500 dark:text-emerald-400 tracking-tight block leading-snug"><?= $estimated_exhaustion_date ?></span>
                        <span class="text-[10px] text-slate-400 dark:text-slate-500 font-medium mt-0.5 block">Projected capacity date</span>
                    </div>
                </div>

            </div>

            <!-- ANALYTICS CHARTS - ROW 1 -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="lg:col-span-2 glass-card rounded-2xl p-6 shadow-sm dark:shadow-2xl flex flex-col">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider flex items-center gap-2">
                            <i data-lucide="bar-chart-2" class="w-4 h-4 text-cyan-500"></i> Section Occupancy vs Availability
                        </h2>
                        <span class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 bg-slate-100 dark:bg-slate-800/80 px-2.5 py-1 rounded-lg">Real-Time</span>
                    </div>
                    <div class="chart-container">
                        <canvas id="sectionChart"></canvas>
                    </div>
                </div>

                <div class="glass-card rounded-2xl p-6 shadow-sm dark:shadow-2xl flex flex-col">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider flex items-center gap-2">
                            <i data-lucide="pie-chart" class="w-4 h-4 text-cyan-500"></i> Total Capacity Ratio
                        </h2>
                    </div>
                    <div class="chart-container flex items-center justify-center">
                        <canvas id="ratioChart"></canvas>
                    </div>
                </div>

            </div>

            <!-- ANALYTICS CHARTS - ROW 2 -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                
                <div class="glass-card rounded-2xl p-6 shadow-sm dark:shadow-2xl flex flex-col md:col-span-1">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider flex items-center gap-2">
                            <i data-lucide="circle-dot" class="w-4 h-4 text-purple-500"></i> Reservation Status
                        </h2>
                    </div>
                    <div class="chart-container flex items-center justify-center">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>

                <div class="glass-card rounded-2xl p-6 shadow-sm dark:shadow-2xl flex flex-col md:col-span-2">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
                        <div>
                            <h2 class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider flex items-center gap-2">
                                <i data-lucide="activity" class="w-4 h-4 text-emerald-500"></i> Predictive Occupancy Wave Forecast
                            </h2>
                            <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">Smooth continuous wave projection across historical & projected timelines</p>
                        </div>
                        <span class="self-start sm:self-auto text-[10px] font-mono font-semibold bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 px-3 py-1.5 rounded-xl flex items-center gap-1.5">
                            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Dynamic Wave Engine
                        </span>
                    </div>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <script>
        const chartLabels = <?= json_encode($chart_labels) ?>;
        const chartOccupied = <?= json_encode($chart_occupied) ?>;
        const chartAvailable = <?= json_encode($chart_available) ?>;
        const reservationStatusData = <?= json_encode(array_values($reservationStatusData)) ?>;
        
        const forecastLabels = <?= json_encode($forecast_labels) ?>;
        const forecastHistorical = <?= json_encode($forecast_historical) ?>;
        const forecastProjected = <?= json_encode($forecast_projected) ?>;

        let sectionChart, ratioChart, statusChart, trendChart;

        function renderIcons() {
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
        }

        function initTheme() {
            const isDark = localStorage.getItem('theme') === 'dark' || 
                (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.documentElement.classList.add('dark');
                updateThemeIcon('sun');
            } else {
                document.documentElement.classList.remove('dark');
                updateThemeIcon('moon');
            }
        }

        function toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon(isDark ? 'sun' : 'moon');
            renderCharts();
        }

        function updateThemeIcon(iconName) {
            const iconEl = document.getElementById('themeToggleIcon');
            if (iconEl) {
                iconEl.setAttribute('data-lucide', iconName);
                renderIcons();
            }
        }

        function renderCharts() {
            const isDark = document.documentElement.classList.contains('dark');
            const textColor = isDark ? '#94a3b8' : '#64748b';
            const gridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(226, 232, 240, 0.8)';
            const bgTooltip = isDark ? 'rgba(15, 23, 42, 0.95)' : 'rgba(255, 255, 255, 0.95)';
            const titleTooltip = isDark ? '#f8fafc' : '#0f172a';

            if(sectionChart) sectionChart.destroy();
            if(ratioChart) ratioChart.destroy();
            if(statusChart) statusChart.destroy();
            if(trendChart) trendChart.destroy();

            // 1. Stacked Bar Chart
            const ctxBar = document.getElementById('sectionChart').getContext('2d');
            const gradOccupied = ctxBar.createLinearGradient(0, 0, 0, 250);
            gradOccupied.addColorStop(0, '#3b82f6');
            gradOccupied.addColorStop(1, '#1d4ed8');

            const gradAvailable = ctxBar.createLinearGradient(0, 0, 0, 250);
            gradAvailable.addColorStop(0, '#06b6d4');
            gradAvailable.addColorStop(1, '#0e7490');

            sectionChart = new Chart(ctxBar, {
                type: 'bar',
                data: {
                    labels: chartLabels.length ? chartLabels : ['No Sections'],
                    datasets: [
                        { label: 'Occupied Plots', data: chartOccupied, backgroundColor: gradOccupied, borderRadius: 8, barPercentage: 0.45 },
                        { label: 'Available Slots', data: chartAvailable, backgroundColor: gradAvailable, borderRadius: 8, barPercentage: 0.45 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { color: textColor, font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' }, usePointStyle: true, boxWidth: 8 } },
                        tooltip: { backgroundColor: bgTooltip, titleColor: titleTooltip, bodyColor: textColor, borderRadius: 12, padding: 12, borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)', borderWidth: 1 }
                    },
                    scales: {
                        x: { stacked: true, ticks: { color: textColor, font: { family: 'Plus Jakarta Sans', size: 11 } }, grid: { display: false } },
                        y: { stacked: true, ticks: { color: textColor, font: { family: 'Plus Jakarta Sans', size: 11 } }, grid: { color: gridColor } }
                    }
                }
            });

            // 2. Capacity Donut Chart
            const totalOccupiedSum = chartOccupied.reduce((a, b) => a + b, 0);
            const totalAvailableSum = chartAvailable.reduce((a, b) => a + b, 0);

            const ctxDonut = document.getElementById('ratioChart').getContext('2d');
            ratioChart = new Chart(ctxDonut, {
                type: 'doughnut',
                data: {
                    labels: ['Occupied', 'Available'],
                    datasets: [{ data: [totalOccupiedSum, totalAvailableSum], backgroundColor: ['#3b82f6', '#06b6d4'], borderWidth: 0, hoverOffset: 8 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '76%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: textColor, font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' }, usePointStyle: true, boxWidth: 8 } },
                        tooltip: { backgroundColor: bgTooltip, bodyColor: textColor, borderRadius: 12, padding: 12 }
                    }
                }
            });

            // 3. Status Breakdown Chart
            const ctxStatus = document.getElementById('statusChart').getContext('2d');
            statusChart = new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Approved', 'Rejected'],
                    datasets: [{ data: reservationStatusData, backgroundColor: ['#f59e0b', '#10b981', '#ef4444'], borderWidth: 0, hoverOffset: 8 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: textColor, font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' }, usePointStyle: true, boxWidth: 8 } },
                        tooltip: { backgroundColor: bgTooltip, bodyColor: textColor, borderRadius: 12, padding: 12 }
                    }
                }
            });

            // 4. Predictive Forecast Wave Chart
            const ctxTrend = document.getElementById('trendChart').getContext('2d');
            
            const waveGradHistorical = ctxTrend.createLinearGradient(0, 0, 0, 280);
            waveGradHistorical.addColorStop(0, 'rgba(6, 182, 212, 0.35)');
            waveGradHistorical.addColorStop(1, 'rgba(6, 182, 212, 0.0)');

            const waveGradForecast = ctxTrend.createLinearGradient(0, 0, 0, 280);
            waveGradForecast.addColorStop(0, 'rgba(16, 185, 129, 0.3)');
            waveGradForecast.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

            trendChart = new Chart(ctxTrend, {
                type: 'line',
                data: {
                    labels: forecastLabels,
                    datasets: [
                        {
                            label: 'Historical Burials',
                            data: forecastHistorical,
                            borderColor: '#06b6d4',
                            borderWidth: 2.5,
                            backgroundColor: waveGradHistorical,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#06b6d4',
                            pointBorderColor: isDark ? '#0f172a' : '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Forecasted Expansion',
                            data: forecastProjected,
                            borderColor: '#10b981',
                            borderWidth: 2.5,
                            borderDash: [6, 6],
                            backgroundColor: waveGradForecast,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#10b981',
                            pointBorderColor: isDark ? '#0f172a' : '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: { 
                            position: 'top', 
                            align: 'end',
                            labels: { 
                                color: textColor, 
                                font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' }, 
                                usePointStyle: true, 
                                boxWidth: 8 
                            } 
                        },
                        tooltip: { 
                            backgroundColor: bgTooltip, 
                            titleColor: titleTooltip, 
                            bodyColor: textColor, 
                            borderRadius: 12, 
                            padding: 10,
                            borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)',
                            borderWidth: 1,
                            callbacks: {
                                title: function(context) {
                                    let label = context[0].label;
                                    let datasetIndex = context[0].datasetIndex;
                                    return datasetIndex === 1 ? label + ' (Forecasted)' : label + ' (Actual)';
                                }
                            }
                        }
                    },
                    scales: {
                        x: { 
                            ticks: { 
                                color: textColor, 
                                font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' },
                                maxRotation: 0,
                                minRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 12
                            }, 
                            grid: { display: false } 
                        },
                        y: { 
                            ticks: { 
                                color: textColor, 
                                font: { family: 'Plus Jakarta Sans', size: 10 } 
                            }, 
                            grid: { color: gridColor } 
                        }
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            renderCharts();
            renderIcons();
        });

        window.addEventListener('load', renderIcons);

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if(sidebar) sidebar.classList.toggle('-translate-x-full');
        }
    </script>
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

        function openAdminNotification(el) {
            if (el.dataset.read !== '1') {
                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({ action: 'mark_admin_notif', notif_id: el.dataset.id })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.status === 'success') {
                        el.dataset.read = '1';
                        updateAdminNotificationUI();
                    }
                })
                .catch(function (err) { console.error(err); });
            }
        }

        function markAllAdminNotifications() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ action: 'mark_all_admin_notif' })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status === 'success') {
                    document.querySelectorAll('#adminNotifList li[data-id]').forEach(function (li) { li.dataset.read = '1'; });
                    updateAdminNotificationUI();
                }
            })
            .catch(function (err) { console.error(err); });
        }

        function updateAdminNotificationUI() {
            const items = document.querySelectorAll('#adminNotifList li[data-id]');
            let unread = 0;
            items.forEach(function (li) {
                const isRead = li.dataset.read === '1';
                li.classList.toggle('opacity-60', isRead);
                li.classList.toggle('border-l-2', !isRead);
                li.classList.toggle('border-cyan-500', !isRead);
                li.classList.toggle('bg-cyan-50', !isRead);
                li.classList.toggle('dark:bg-cyan-900/20', !isRead);
                if (!isRead) unread++;
            });

            const badge = document.getElementById('adminNotifBadge');
            const newCount = document.getElementById('adminNotifNewCount');
            if (badge) {
                badge.textContent = unread || '';
                badge.classList.toggle('hidden', unread === 0);
            }
            if (newCount) newCount.textContent = unread + ' new';
        }
    </script>
</body>
</html>