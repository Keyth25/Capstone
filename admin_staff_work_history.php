<?php
// Session check is handled inside config.php — no separate session_start() needed here
require 'config.php';

// Access Control
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$filter_staff = trim($_GET['staff'] ?? '');

// Fetch staff work assignments with resolved staff names
$assignments = [];
try {
    $assignments = $pdo->query("
        SELECT
            ma.id AS assignment_id,
            ma.personnel_id,
            ma.status AS assignment_status,
            ma.assigned_at,
            mr.id AS request_id,
            mr.request_number,
            mr.status AS request_status,
            mr.priority,
            mr.description,
            mr.scheduled_date,
            mr.updated_at AS request_updated_at,
            st.name AS service_name,
            d.plot_code,
            COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ', sp.first_name, sp.last_name)), ''),
                NULLIF(sau.raw_user_meta_data->>'full_name', ''),
                su.name,
                'Staff'
            ) AS staff_name
        FROM public.maintenance_assignments ma
        JOIN public.maintenance_requests mr ON ma.maintenance_request_id = mr.id
        LEFT JOIN public.maintenance_service_types st ON mr.service_type_id::text = st.id::text
        LEFT JOIN public.deceased_records d ON mr.plot_id::text = d.id::text
        LEFT JOIN public.profiles sp ON ma.personnel_id = sp.id
        LEFT JOIN auth.users sau ON ma.personnel_id = sau.id
        LEFT JOIN public.users su ON ma.personnel_id::text = su.id::text
        ORDER BY ma.assigned_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Staff work history fetch error: " . $e->getMessage());
}

// Fetch activity log entries performed by staff members
$activity_log = [];
try {
    $activity_log = $pdo->query("
        SELECT
            h.maintenance_request_id,
            h.action,
            h.old_status,
            h.new_status,
            h.notes,
            h.created_at,
            mr.request_number,
            COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ', hp.first_name, hp.last_name)), ''),
                NULLIF(hau.raw_user_meta_data->>'full_name', ''),
                hu.name,
                'Staff'
            ) AS staff_name,
            h.user_id AS personnel_id
        FROM public.maintenance_history h
        LEFT JOIN public.maintenance_requests mr ON h.maintenance_request_id = mr.id
        LEFT JOIN public.profiles hp ON h.user_id = hp.id
        LEFT JOIN auth.users hau ON h.user_id = hau.id
        LEFT JOIN public.users hu ON h.user_id::text = hu.id::text
        WHERE COALESCE(hau.raw_user_meta_data->>'role', hu.role, '') = 'staff'
        ORDER BY h.created_at DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Staff activity log fetch error: " . $e->getMessage());
}

// Fetch before/after work photos per request (keyed by request + uploader)
$request_photos = [];
$staff_photos = [];
try {
    foreach ($pdo->query("SELECT maintenance_request_id, uploaded_by, file_path, photo_type FROM public.maintenance_photos ORDER BY id ASC") as $ph) {
        $rid = (int) $ph['maintenance_request_id'];
        $request_photos[$rid][] = $ph;
        $staff_photos[$rid . '|' . (string) $ph['uploaded_by']][] = $ph;
    }
} catch (PDOException $e) {
    error_log("Maintenance photos fetch error: " . $e->getMessage());
}

// Fetch staff progress notes (keyed by request + uploader)
$progress_notes = [];
try {
    foreach ($pdo->query("SELECT maintenance_request_id, personnel_id, progress_note, created_at FROM public.maintenance_progress ORDER BY created_at ASC") as $pn) {
        $progress_notes[(int) $pn['maintenance_request_id'] . '|' . (string) $pn['personnel_id']][] = $pn;
    }
} catch (PDOException $e) {
    error_log("Maintenance progress fetch error: " . $e->getMessage());
}

// Build staff summary stats
$staff_stats = [];
foreach ($assignments as $a) {
    $key = (string) $a['personnel_id'];
    if (!isset($staff_stats[$key])) {
        $staff_stats[$key] = ['name' => $a['staff_name'], 'total' => 0, 'in_progress' => 0, 'completed' => 0];
    }
    $staff_stats[$key]['total']++;
    if ($a['assignment_status'] === 'Completed') {
        $staff_stats[$key]['completed']++;
    } elseif (in_array($a['assignment_status'], ['Assigned', 'In Progress'], true)) {
        $staff_stats[$key]['in_progress']++;
    }
}
uasort($staff_stats, fn($x, $y) => strcmp($x['name'], $y['name']));

// Apply staff filter
if ($filter_staff !== '') {
    $assignments = array_values(array_filter($assignments, fn($a) => (string) $a['personnel_id'] === $filter_staff));
    $activity_log = array_values(array_filter($activity_log, fn($l) => (string) $l['personnel_id'] === $filter_staff));
}

// Build a details map used by the View modal
$assignment_details = [];
foreach ($assignments as $a) {
    $rid = (int) $a['request_id'];
    $uid = (string) $a['personnel_id'];
    $rowPhotos = $staff_photos[$rid . '|' . $uid] ?? ($request_photos[$rid] ?? []);
    $assignment_details[(int) $a['assignment_id']] = [
        'staff_name'         => $a['staff_name'],
        'request_number'     => $a['request_number'],
        'service_name'       => $a['service_name'],
        'plot_code'          => $a['plot_code'],
        'priority'           => $a['priority'],
        'assignment_status'  => $a['assignment_status'],
        'request_status'     => $a['request_status'],
        'description'        => $a['description'],
        'scheduled_date'     => $a['scheduled_date'],
        'assigned_at'        => $a['assigned_at'],
        'before'             => array_map(fn($p) => $p['file_path'], array_values(array_filter($rowPhotos, fn($p) => stripos($p['photo_type'] ?? '', 'before') !== false))),
        'after'              => array_map(fn($p) => $p['file_path'], array_values(array_filter($rowPhotos, fn($p) => stripos($p['photo_type'] ?? '', 'before') === false))),
        'progress'           => array_values($progress_notes[$rid . '|' . $uid] ?? []),
    ];
}

// Overview stats (respects the active staff filter)
$total_assignments        = count($assignments);
$active_assignments       = count(array_filter($assignments, fn($a) => in_array($a['assignment_status'], ['Assigned', 'In Progress'], true)));
$verification_assignments = count(array_filter($assignments, fn($a) => ($a['assignment_status'] ?? '') === 'For Verification'));
$completed_assignments    = count(array_filter($assignments, fn($a) => ($a['assignment_status'] ?? '') === 'Completed'));

// Unread admin notifications for the header bell
$admin_unread_count = 0;
try {
    init_admin_notifications_table($pdo);
    $admin_unread_count = (int) $pdo->query("SELECT COUNT(*) FROM public.admin_notifications WHERE is_read = FALSE")->fetchColumn();
} catch (PDOException $e) {
    $admin_unread_count = 0;
}

function statusBadgeClass($status) {
    return match ($status) {
        'Completed' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20',
        'In Progress', 'Approved' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
        'For Verification' => 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-500/20',
        'Assigned' => 'bg-violet-500/10 text-violet-600 dark:text-violet-300 border border-violet-500/20',
        'Declined', 'Cancelled' => 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20',
        default => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20',
    };
}

function priorityBadgeClass($priority) {
    return match (strtolower((string) $priority)) {
        'urgent' => 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20',
        'high' => 'bg-orange-500/10 text-orange-600 dark:text-orange-400 border border-orange-500/20',
        'medium' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
        default => 'bg-slate-500/10 text-slate-500 dark:text-slate-400 border border-slate-500/20',
    };
}

function staffInitials($name) {
    $parts = preg_split('/\s+/', trim((string) $name));
    return strtoupper(substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? '', 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Work History - Admin</title>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.35); border-radius: 9999px; }

        .mr-card { background: #ffffff; border: 1px solid #e8eaf4; }
        .dark .mr-card { background: #0f172a; border-color: #1e293b; }
        .mr-inset { background: #f7f8fc; border: 1px solid #eceef6; }
        .dark .mr-inset { background: #020617; border-color: #1e293b; }
        .mr-title { color: #0f172a; }
        .dark .mr-title { color: #f8fafc; }
        .mr-text { color: #334155; }
        .dark .mr-text { color: #cbd5e1; }
        .mr-muted { color: #64748b; }
        .dark .mr-muted { color: #94a3b8; }
        .mr-input { background: #f8f9fd; border: 1px solid #e3e6f1; color: #0f172a; }
        .mr-input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.12); }
        .dark .mr-input { background: #020617; border-color: #1e293b; color: #e2e8f0; }
        .mr-row:hover { background: #f8f8fe; }
        .dark .mr-row:hover { background: rgba(30, 41, 59, 0.4); }
        .mr-divider { border-color: #eef0f7; }
        .dark .mr-divider { border-color: #1e293b; }

        #workPanel { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .staff-card { transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .staff-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-[#eef0f8] dark:bg-slate-950 text-slate-800 dark:text-slate-100 h-screen flex font-sans overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <div class="flex-1 overflow-y-auto p-5 sm:p-6 space-y-5">

            <!-- PAGE HEADER -->
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3.5">
                    <button onclick="toggleSidebar()" class="md:hidden p-2.5 rounded-xl mr-card mr-muted shadow-sm">
                        <i class="fa-solid fa-bars text-sm"></i>
                    </button>
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-600/30 shrink-0">
                        <i class="fa-solid fa-clock-rotate-left text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-extrabold mr-title tracking-tight">Staff Work History</h1>
                        <p class="text-xs mr-muted">Track assignments, progress updates, and before/after photos for every staff member.</p>
                    </div>
                </div>
                <div class="flex items-center gap-2.5">
                    <a href="admin_dashboard.php" title="Notifications" class="relative w-10 h-10 mr-card rounded-xl shadow-sm mr-muted hover:text-emerald-500 transition flex items-center justify-center">
                        <i class="fa-regular fa-bell text-sm"></i>
                        <?php if ($admin_unread_count > 0): ?>
                            <span class="absolute top-2 right-2.5 w-2 h-2 rounded-full bg-rose-500 ring-2 ring-white dark:ring-slate-900"></span>
                        <?php endif; ?>
                    </a>
                    <div class="mr-card rounded-xl shadow-sm pl-1.5 pr-3.5 py-1.5 flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-user text-xs"></i>
                        </div>
                        <div class="leading-tight">
                            <div class="text-[11px] font-bold mr-title whitespace-nowrap"><?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></div>
                            <div class="text-[9px] mr-muted">System Administrator</div>
                        </div>
                        <i class="fa-solid fa-chevron-down text-[9px] mr-muted ml-1"></i>
                    </div>
                </div>
            </div>

            <?php if ($filter_staff !== ''): ?>
                <div class="p-3 bg-emerald-500/10 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-xl shadow-sm flex items-center gap-2">
                    <i class="fa-solid fa-filter"></i>
                    <span>Filtered by staff: <b><?= htmlspecialchars($staff_stats[$filter_staff]['name'] ?? 'Selected staff member') ?></b></span>
                    <a href="admin_staff_work_history.php" class="ml-auto font-bold hover:underline flex items-center gap-1"><i class="fa-solid fa-xmark"></i> Clear</a>
                </div>
            <?php endif; ?>

            <!-- STAT CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <button onclick="setStatusFilter('')" class="mr-card rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition text-left group">
                    <div class="w-11 h-11 rounded-full bg-emerald-500/10 text-emerald-500 flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-clipboard-list"></i></div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-bold mr-muted uppercase tracking-wider block">Total Assignments</span>
                        <span class="text-2xl font-extrabold mr-title block leading-tight"><?= $total_assignments ?></span>
                        <span class="text-[10px] mr-muted block">All recorded work</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-[#cbd5e1] dark:text-slate-600 text-xs group-hover:text-emerald-500 transition"></i>
                </button>

                <button onclick="setStatusFilter('__active__')" class="mr-card rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition text-left group">
                    <div class="w-11 h-11 rounded-full bg-blue-500/10 text-blue-500 flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-wrench"></i></div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-bold mr-muted uppercase tracking-wider block">Active</span>
                        <span class="text-2xl font-extrabold mr-title block leading-tight"><?= $active_assignments ?></span>
                        <span class="text-[10px] mr-muted block">Assigned or in progress</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-[#cbd5e1] dark:text-slate-600 text-xs group-hover:text-blue-500 transition"></i>
                </button>

                <button onclick="setStatusFilter('For Verification')" class="mr-card rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition text-left group">
                    <div class="w-11 h-11 rounded-full bg-amber-500/10 text-amber-500 flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-bold mr-muted uppercase tracking-wider block">For Verification</span>
                        <span class="text-2xl font-extrabold mr-title block leading-tight"><?= $verification_assignments ?></span>
                        <span class="text-[10px] mr-muted block">Awaiting admin review</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-[#cbd5e1] dark:text-slate-600 text-xs group-hover:text-amber-500 transition"></i>
                </button>

                <button onclick="setStatusFilter('Completed')" class="mr-card rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition text-left group">
                    <div class="w-11 h-11 rounded-full bg-green-500/10 text-green-500 flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-bold mr-muted uppercase tracking-wider block">Completed</span>
                        <span class="text-2xl font-extrabold mr-title block leading-tight"><?= $completed_assignments ?></span>
                        <span class="text-[10px] mr-muted block">Verified finished work</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-[#cbd5e1] dark:text-slate-600 text-xs group-hover:text-green-500 transition"></i>
                </button>
            </div>
            <!-- TEAM OVERVIEW -->
            <div class="mr-card rounded-2xl shadow-sm p-4">
                <div class="flex items-center justify-between mb-3.5">
                    <span class="text-[11px] font-bold mr-muted uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-users-gear text-emerald-500"></i> Team Overview
                    </span>
                    <span class="text-[10px] mr-muted"><?= count($staff_stats) ?> staff member<?= count($staff_stats) === 1 ? '' : 's' ?> · click a card to filter</span>
                </div>
                <?php if (empty($staff_stats)): ?>
                    <div class="text-xs mr-muted italic py-8 text-center mr-inset rounded-xl">No staff work records found.</div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                        <?php foreach ($staff_stats as $sid => $s):
                            $pct = $s['total'] > 0 ? round(($s['completed'] / $s['total']) * 100) : 0;
                            $isActive = $filter_staff === (string) $sid;
                        ?>
                            <a href="<?= $isActive ? 'admin_staff_work_history.php' : '?staff=' . urlencode($sid) ?>"
                               class="staff-card mr-inset rounded-xl p-3.5 block <?= $isActive ? '!border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:!border-emerald-500/50' ?>">
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="relative shrink-0">
                                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-xs font-extrabold shadow-md shadow-emerald-600/20"><?= htmlspecialchars(staffInitials($s['name'])) ?></div>
                                        <?php if ($s['in_progress'] > 0): ?>
                                            <span class="absolute -top-0.5 -right-0.5 w-3 h-3 rounded-full bg-blue-500 border-2 border-white dark:border-slate-900" title="Has active tasks"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-xs font-bold mr-title truncate"><?= htmlspecialchars($s['name']) ?></h3>
                                        <span class="text-[9px] mr-muted"><?= $s['total'] ?> assignment<?= $s['total'] === 1 ? '' : 's' ?></span>
                                    </div>
                                    <?php if ($isActive): ?>
                                        <i class="fa-solid fa-circle-check text-emerald-500 text-sm shrink-0"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex justify-between text-[10px] mr-muted mb-1.5">
                                    <span>Active <b class="text-blue-500"><?= $s['in_progress'] ?></b></span>
                                    <span>Done <b class="text-emerald-500"><?= $s['completed'] ?></b></span>
                                    <span><b class="mr-title"><?= $pct ?>%</b></span>
                                </div>
                                <div class="h-1.5 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-400" style="width: <?= $pct ?>%"></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- FILTER BAR -->
            <div class="mr-card rounded-2xl p-3 shadow-sm flex flex-wrap items-center gap-2.5">
                <div class="relative flex-1 min-w-[220px]">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 mr-muted text-xs"></i>
                    <input id="filterSearch" type="text" placeholder="Search by staff, request #, service, or plot..." class="mr-input w-full rounded-xl pl-9 pr-3 py-2.5 text-xs placeholder:text-[#9aa3b8]">
                </div>
                <select id="filterStatus" class="mr-input rounded-xl px-3 py-2.5 text-xs font-medium cursor-pointer">
                    <option value="">All Statuses</option>
                    <option value="__active__">All Active</option>
                    <option>Assigned</option>
                    <option>In Progress</option>
                    <option>For Verification</option>
                    <option>Completed</option>
                    <option>Declined</option>
                    <option>Cancelled</option>
                </select>
                <form method="GET" class="contents">
                    <select name="staff" onchange="this.form.submit()" class="mr-input rounded-xl px-3 py-2.5 text-xs font-medium cursor-pointer">
                        <option value="">All Staff</option>
                        <?php foreach ($staff_stats as $sid => $s): ?>
                            <option value="<?= htmlspecialchars($sid) ?>" <?= $filter_staff === (string) $sid ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <button onclick="resetFilters()" class="rounded-xl px-4 py-2.5 text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-500 shadow-md shadow-emerald-600/25 transition flex items-center gap-2">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
            </div>

            <!-- ASSIGNMENTS TABLE -->
            <div class="mr-card rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-4 flex items-center justify-between border-b mr-divider">
                    <span class="text-[11px] font-bold mr-muted uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-emerald-500"></i> Maintenance Assignments
                    </span>
                    <span id="assignmentCount" class="text-[10px] mr-muted"><?= count($assignments) ?> record<?= count($assignments) === 1 ? '' : 's' ?></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs" id="assignmentTable">
                        <thead>
                            <tr class="mr-muted text-[10px] uppercase tracking-wider border-b mr-divider">
                                <th class="py-3.5 pl-5 pr-2 font-bold">Staff</th>
                                <th class="py-3.5 px-2 font-bold">Request #</th>
                                <th class="py-3.5 px-2 font-bold">Service</th>
                                <th class="py-3.5 px-2 font-bold">Plot</th>
                                <th class="py-3.5 px-2 font-bold">Priority</th>
                                <th class="py-3.5 px-2 font-bold">Assignment</th>
                                <th class="py-3.5 px-2 font-bold">Request</th>
                                <th class="py-3.5 px-2 font-bold">Assigned At</th>
                                <th class="py-3.5 px-2 pr-5 font-bold text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#eef0f7] dark:divide-white/10" id="assignmentTbody">
                            <?php if (empty($assignments)): ?>
                                <tr><td colspan="9" class="py-10 text-center mr-muted italic">No assignments found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($assignments as $a):
                                    $svc = strtolower($a['service_name'] ?? '');
                                    if (str_contains($svc, 'grass') || str_contains($svc, 'lawn')) {
                                        $svc_icon = 'fa-seedling'; $svc_color = 'text-emerald-500';
                                    } elseif (str_contains($svc, 'clean')) {
                                        $svc_icon = 'fa-broom'; $svc_color = 'text-violet-500';
                                    } elseif (str_contains($svc, 'flower')) {
                                        $svc_icon = 'fa-spa'; $svc_color = 'text-pink-500';
                                    } elseif (str_contains($svc, 'repair') || str_contains($svc, 'headstone')) {
                                        $svc_icon = 'fa-hammer'; $svc_color = 'text-amber-500';
                                    } else {
                                        $svc_icon = 'fa-screwdriver-wrench'; $svc_color = 'text-emerald-500';
                                    }

                                    $assigned_ts = !empty($a['assigned_at']) ? strtotime($a['assigned_at']) : null;
                                    $search_hay = strtolower(implode(' ', array_filter([
                                        $a['staff_name'] ?? '', $a['request_number'] ?? '',
                                        $a['service_name'] ?? '', $a['plot_code'] ?? ''
                                    ])));
                                ?>
                                    <tr class="mr-row transition"
                                        data-id="<?= (int) $a['assignment_id'] ?>"
                                        data-status="<?= htmlspecialchars($a['assignment_status'] ?? '') ?>"
                                        data-search="<?= htmlspecialchars($search_hay) ?>">
                                        <td class="py-3.5 pl-5 pr-2">
                                            <div class="flex items-center gap-2.5">
                                                <div class="w-7 h-7 rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-[9px] font-bold shrink-0"><?= htmlspecialchars(staffInitials($a['staff_name'])) ?></div>
                                                <span class="font-semibold mr-title whitespace-nowrap"><?= htmlspecialchars($a['staff_name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-2 font-mono font-bold text-emerald-600 dark:text-emerald-300 whitespace-nowrap"><?= htmlspecialchars($a['request_number'] ?? 'N/A') ?></td>
                                        <td class="py-3.5 px-2">
                                            <div class="flex items-center gap-2 whitespace-nowrap">
                                                <i class="fa-solid <?= $svc_icon ?> <?= $svc_color ?> text-sm"></i>
                                                <span class="mr-text font-medium"><?= htmlspecialchars($a['service_name'] ?? 'General Service') ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-2">
                                            <div class="flex items-center gap-1.5 whitespace-nowrap">
                                                <i class="fa-solid fa-location-dot text-emerald-500 text-xs"></i>
                                                <span class="font-mono mr-text"><?= htmlspecialchars($a['plot_code'] ?? 'N/A') ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-2">
                                            <span class="px-2.5 py-1 rounded-full text-[9px] font-bold uppercase <?= priorityBadgeClass($a['priority']) ?>"><?= htmlspecialchars(ucfirst($a['priority'] ?? 'Low')) ?></span>
                                        </td>
                                        <td class="py-3.5 px-2">
                                            <span class="px-2.5 py-1 rounded-full text-[9px] font-bold uppercase <?= statusBadgeClass($a['assignment_status']) ?>"><?= htmlspecialchars($a['assignment_status'] ?? '—') ?></span>
                                        </td>
                                        <td class="py-3.5 px-2">
                                            <span class="px-2.5 py-1 rounded-full text-[9px] font-bold uppercase <?= statusBadgeClass($a['request_status']) ?>"><?= htmlspecialchars($a['request_status'] ?? '—') ?></span>
                                        </td>
                                        <td class="py-3.5 px-2 whitespace-nowrap">
                                            <?php if ($assigned_ts): ?>
                                                <div class="mr-text font-medium"><?= date('M j, Y', $assigned_ts) ?></div>
                                                <div class="text-[9px] mr-muted"><?= date('g:i A', $assigned_ts) ?></div>
                                            <?php else: ?>
                                                <span class="mr-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-2 pr-5 text-right whitespace-nowrap">
                                            <button type="button" onclick="openWorkPanel(<?= (int) $a['assignment_id'] ?>)" class="px-2.5 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 border border-emerald-500/20 text-[10px] font-bold hover:bg-emerald-500/20 transition">
                                                <i class="fa-solid fa-eye mr-1"></i>View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="noResults" class="hidden py-10 text-center mr-muted text-xs italic border-t mr-divider">No assignments match the current filters.</div>
            </div>

            <!-- ACTIVITY LOG TIMELINE -->
            <div class="mr-card rounded-2xl shadow-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-[11px] font-bold mr-muted uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-bolt text-emerald-500"></i> Staff Activity Log
                    </span>
                    <span class="text-[10px] mr-muted"><?= count($activity_log) ?> entr<?= count($activity_log) === 1 ? 'y' : 'ies' ?></span>
                </div>
                <?php if (empty($activity_log)): ?>
                    <div class="text-xs mr-muted italic py-8 text-center mr-inset rounded-xl">No staff activity recorded.</div>
                <?php else: ?>
                    <div class="relative">
                        <div class="absolute left-[15px] top-3 bottom-3 w-px bg-slate-200 dark:bg-slate-800"></div>
                        <div class="space-y-3">
                            <?php foreach ($activity_log as $log):
                                $act = strtolower((string) ($log['action'] ?? ''));
                                if (str_contains($act, 'assign')) {
                                    $log_icon = 'fa-user-plus'; $log_bg = 'bg-violet-500/10 text-violet-500';
                                } elseif (str_contains($act, 'photo') || str_contains($act, 'upload')) {
                                    $log_icon = 'fa-camera'; $log_bg = 'bg-cyan-500/10 text-cyan-500';
                                } elseif (str_contains($act, 'complet') || str_contains($act, 'verif') || str_contains($act, 'finish')) {
                                    $log_icon = 'fa-circle-check'; $log_bg = 'bg-emerald-500/10 text-emerald-500';
                                } elseif (str_contains($act, 'declin') || str_contains($act, 'cancel')) {
                                    $log_icon = 'fa-xmark'; $log_bg = 'bg-red-500/10 text-red-500';
                                } elseif (str_contains($act, 'progress') || str_contains($act, 'start') || str_contains($act, 'work')) {
                                    $log_icon = 'fa-wrench'; $log_bg = 'bg-blue-500/10 text-blue-500';
                                } else {
                                    $log_icon = 'fa-bolt'; $log_bg = 'bg-emerald-500/10 text-emerald-500';
                                }
                            ?>
                                <div class="relative flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-full <?= $log_bg ?> flex items-center justify-center shrink-0 relative z-10 ring-4 ring-white dark:ring-slate-900">
                                        <i class="fa-solid <?= $log_icon ?> text-[10px]"></i>
                                    </div>
                                    <div class="mr-inset rounded-xl p-3 min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2 flex-wrap">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <div class="w-5 h-5 rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-[7px] font-bold shrink-0"><?= htmlspecialchars(staffInitials($log['staff_name'])) ?></div>
                                                <span class="text-xs font-bold mr-title truncate"><?= htmlspecialchars($log['staff_name']) ?></span>
                                            </div>
                                            <span class="text-[9px] mr-muted whitespace-nowrap"><?= !empty($log['created_at']) ? date('M j, Y · g:i A', strtotime($log['created_at'])) : '' ?></span>
                                        </div>
                                        <p class="text-[11px] mr-text mt-1">
                                            <?= htmlspecialchars($log['action']) ?>
                                            <?php if (!empty($log['request_number'])): ?>
                                                on <span class="font-mono font-bold text-emerald-600 dark:text-emerald-300"><?= htmlspecialchars($log['request_number']) ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if (!empty($log['old_status']) && !empty($log['new_status'])): ?>
                                            <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                                                <span class="px-2 py-0.5 rounded-md text-[9px] font-semibold <?= statusBadgeClass($log['old_status']) ?>"><?= htmlspecialchars($log['old_status']) ?></span>
                                                <i class="fa-solid fa-arrow-right text-[8px] mr-muted"></i>
                                                <span class="px-2 py-0.5 rounded-md text-[9px] font-semibold <?= statusBadgeClass($log['new_status']) ?>"><?= htmlspecialchars($log['new_status']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($log['notes'])): ?>
                                            <p class="text-[10px] mr-muted mt-1.5 italic border-l-2 border-slate-300 dark:border-slate-700 pl-2"><?= htmlspecialchars($log['notes']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- WORK DETAILS SLIDE-OVER PANEL -->
    <div id="workBackdrop" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[90] hidden" onclick="closeWorkPanel()"></div>
    <aside id="workPanel" class="fixed top-0 right-0 h-full w-full sm:w-[460px] z-[95] translate-x-full">
        <div class="h-full mr-card sm:rounded-l-2xl shadow-2xl flex flex-col overflow-hidden">
            <div class="px-5 py-4 flex items-center justify-between border-b mr-divider shrink-0">
                <h3 class="text-sm font-extrabold mr-title flex items-center gap-2">
                    <i class="fa-solid fa-briefcase text-emerald-500"></i> Work Details
                </h3>
                <div class="flex items-center gap-2">
                    <span id="panelReqNum" class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-300 text-[10px] font-mono font-bold"></span>
                    <button onclick="closeWorkPanel()" class="w-7 h-7 rounded-lg mr-muted hover:bg-slate-100 dark:hover:bg-white/10 transition"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>
            <div id="panelBody" class="flex-1 overflow-y-auto p-5 space-y-4"></div>
        </div>
    </aside>

    <!-- PHOTO VIEWER -->
    <div id="photoViewer" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[110] hidden flex items-center justify-center p-6" onclick="closePhotoViewer()">
        <img id="photoViewerImg" src="" class="max-w-full max-h-full rounded-xl shadow-2xl border border-white/10" alt="Work photo">
    </div>

    <script>
        const workDetails = <?= json_encode($assignment_details, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const ACTIVE_STATUSES = ['Assigned', 'In Progress'];

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        function fmtDate(s) {
            if (!s) return 'N/A';
            const t = new Date(String(s).length === 10 ? s + 'T00:00:00' : s);
            return isNaN(t) ? s : t.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function fmtDateTime(s) {
            if (!s) return 'N/A';
            const t = new Date(s);
            return isNaN(t) ? s : t.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' · ' + t.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }

        function nameInitials(name) {
            const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            return (((parts[0] || 'S')[0] || 'S') + (parts[1] ? parts[1][0] : '')).toUpperCase();
        }

        function statusPillClass(s) {
            return ({
                'Completed':        'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20',
                'In Progress':      'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
                'Approved':         'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
                'For Verification': 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-500/20',
                'Assigned':         'bg-violet-500/10 text-violet-600 dark:text-violet-300 border border-violet-500/20',
                'Declined':         'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20',
                'Cancelled':        'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20',
            })[s] || 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20';
        }

        function priorityPillClass(p) {
            return ({
                'urgent': 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20',
                'high':   'bg-orange-500/10 text-orange-600 dark:text-orange-400 border border-orange-500/20',
                'medium': 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
            })[String(p || '').toLowerCase()] || 'bg-slate-500/10 text-slate-500 dark:text-slate-400 border border-slate-500/20';
        }

        /* ---------- FILTERS ---------- */
        function applyFilters() {
            const q  = document.getElementById('filterSearch').value.trim().toLowerCase();
            const st = document.getElementById('filterStatus').value;
            const rows = document.querySelectorAll('#assignmentTbody tr[data-id]');
            let visible = 0;
            rows.forEach(tr => {
                const ok = (!q || tr.dataset.search.includes(q))
                    && (!st || (st === '__active__' ? ACTIVE_STATUSES.includes(tr.dataset.status) : tr.dataset.status === st));
                tr.classList.toggle('hidden', !ok);
                if (ok) visible++;
            });
            document.getElementById('noResults').classList.toggle('hidden', !(rows.length && visible === 0));
            const counter = document.getElementById('assignmentCount');
            if (counter) counter.textContent = `${visible} of ${rows.length} record${rows.length === 1 ? '' : 's'}`;
        }

        function setStatusFilter(v) {
            document.getElementById('filterStatus').value = v;
            applyFilters();
        }

        function resetFilters() {
            document.getElementById('filterSearch').value = '';
            document.getElementById('filterStatus').value = '';
            applyFilters();
        }

        document.getElementById('filterSearch').addEventListener('input', applyFilters);
        document.getElementById('filterStatus').addEventListener('change', applyFilters);

        /* ---------- WORK DETAILS PANEL ---------- */
        function infoCard(label, valueHtml) {
            return `<div class="mr-inset rounded-xl p-3">
                <div class="text-[9px] font-bold mr-muted uppercase tracking-wider mb-1">${label}</div>
                <div class="text-xs font-semibold mr-title">${valueHtml}</div>
            </div>`;
        }

        function photoGrid(photos, emptyText) {
            if (!photos || !photos.length) {
                return `<div class="text-[10px] mr-muted italic py-4 text-center">${emptyText}</div>`;
            }
            return '<div class="grid grid-cols-3 gap-2">' + photos.map(src =>
                `<button type="button" data-src="${escapeHtml(src)}" onclick="openPhotoViewer(this.dataset.src)" class="block group relative overflow-hidden rounded-lg">
                    <img src="${escapeHtml(src)}" alt="Work photo" class="w-full h-24 object-cover rounded-lg border border-slate-200 dark:border-slate-700 group-hover:border-emerald-500 group-hover:scale-105 transition duration-200">
                    <span class="absolute inset-0 bg-black/0 group-hover:bg-black/25 transition flex items-center justify-center rounded-lg">
                        <i class="fa-solid fa-magnifying-glass-plus text-white opacity-0 group-hover:opacity-100 transition text-sm"></i>
                    </span>
                </button>`
            ).join('') + '</div>';
        }

        function openWorkPanel(assignmentId) {
            const d = workDetails[assignmentId];
            if (!d) return;

            document.getElementById('panelReqNum').textContent = d.request_number || 'N/A';

            let progressHtml = '<div class="text-[10px] mr-muted italic py-3 text-center">No progress notes submitted.</div>';
            if (d.progress && d.progress.length) {
                progressHtml = '<div class="space-y-2.5 max-h-44 overflow-y-auto pr-1">' + d.progress.map(p => `
                    <div class="flex gap-2.5 items-start">
                        <div class="w-5 h-5 rounded-full bg-emerald-500/10 text-emerald-500 flex items-center justify-center shrink-0 mt-0.5">
                            <i class="fa-solid fa-note-sticky text-[8px]"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[11px] mr-text leading-relaxed">${escapeHtml(p.progress_note)}</p>
                            <p class="text-[9px] mr-muted mt-0.5">${p.created_at ? fmtDateTime(p.created_at) : ''}</p>
                        </div>
                    </div>`
                ).join('') + '</div>';
            }

            document.getElementById('panelBody').innerHTML = `
                <div class="mr-inset rounded-xl p-3.5 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-xs font-extrabold shrink-0 shadow-md shadow-emerald-600/20">${escapeHtml(nameInitials(d.staff_name))}</div>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-bold mr-title truncate">${escapeHtml(d.staff_name)}</div>
                        <div class="text-[10px] mr-muted truncate">${escapeHtml(d.service_name || 'General Service')}</div>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-[9px] font-bold uppercase whitespace-nowrap ${statusPillClass(d.assignment_status)}">${escapeHtml(d.assignment_status || 'N/A')}</span>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    ${infoCard('Request #', `<span class="font-mono font-bold text-emerald-600 dark:text-emerald-300">${escapeHtml(d.request_number || 'N/A')}</span>`)}
                    ${infoCard('Plot', `<span class="font-mono">${escapeHtml(d.plot_code || 'N/A')}</span>`)}
                    ${infoCard('Priority', `<span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase ${priorityPillClass(d.priority)}">${escapeHtml(d.priority || 'Low')}</span>`)}
                    ${infoCard('Request Status', `<span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase ${statusPillClass(d.request_status)}">${escapeHtml(d.request_status || 'N/A')}</span>`)}
                    ${infoCard('Scheduled', escapeHtml(fmtDate(d.scheduled_date)))}
                    ${infoCard('Assigned At', escapeHtml(fmtDateTime(d.assigned_at)))}
                </div>

                <div class="mr-inset rounded-xl p-3.5">
                    <div class="text-[9px] font-bold mr-muted uppercase tracking-wider mb-1.5 flex items-center gap-1.5"><i class="fa-solid fa-align-left"></i> Description / Instructions</div>
                    <div class="text-xs mr-text leading-relaxed whitespace-pre-wrap">${escapeHtml(d.description || 'No description provided.')}</div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div class="mr-inset rounded-xl p-3">
                        <div class="text-[9px] font-bold mr-muted uppercase tracking-wider mb-2 flex items-center gap-1.5"><i class="fa-solid fa-camera text-slate-400"></i> Before <span class="normal-case font-normal">(${d.before ? d.before.length : 0})</span></div>
                        ${photoGrid(d.before, 'No before photos uploaded.')}
                    </div>
                    <div class="mr-inset rounded-xl p-3">
                        <div class="text-[9px] font-bold mr-muted uppercase tracking-wider mb-2 flex items-center gap-1.5"><i class="fa-solid fa-camera text-emerald-500"></i> After <span class="normal-case font-normal">(${d.after ? d.after.length : 0})</span></div>
                        ${photoGrid(d.after, 'No after photos uploaded.')}
                    </div>
                </div>

                <div class="mr-inset rounded-xl p-3.5">
                    <div class="text-[9px] font-bold mr-muted uppercase tracking-wider mb-2 flex items-center gap-1.5"><i class="fa-solid fa-list-check text-emerald-500"></i> Progress Notes</div>
                    ${progressHtml}
                </div>
            `;

            document.getElementById('workBackdrop').classList.remove('hidden');
            document.getElementById('workPanel').classList.remove('translate-x-full');
        }

        function closeWorkPanel() {
            document.getElementById('workPanel').classList.add('translate-x-full');
            document.getElementById('workBackdrop').classList.add('hidden');
        }

        /* ---------- PHOTO VIEWER ---------- */
        function openPhotoViewer(src) {
            document.getElementById('photoViewerImg').src = src;
            document.getElementById('photoViewer').classList.remove('hidden');
        }

        function closePhotoViewer() {
            document.getElementById('photoViewer').classList.add('hidden');
            document.getElementById('photoViewerImg').src = '';
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (!document.getElementById('photoViewer').classList.contains('hidden')) {
                closePhotoViewer();
            } else {
                closeWorkPanel();
            }
        });

        // Sidebar toggle function for mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        }
    </script>
</body>
</html>
