<?php
require 'config.php';
require_once __DIR__ . '/includes/supabase_storage.php';

// Check if user is logged in and has 'staff' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: login.php");
    exit();
}

$staff_id = trim((string)$_SESSION['user_id']);

// Verify user existence in public.users to prevent FK constraint violations
if (!ensure_public_user($pdo, $staff_id)) {
    session_unset();
    session_destroy();
    header("Location: login.php?error=invalid_session");
    exit();
}

// Fetch the staff member's assigned job role (set by the admin)
$staff_job_role = null;
try {
    $role_stmt = $pdo->prepare("SELECT job_role FROM public.profiles WHERE id::text = ?");
    $role_stmt->execute([$staff_id]);
    $staff_job_role = $role_stmt->fetchColumn() ?: null;
} catch (PDOException $e) {
    $staff_job_role = null;
}

$message = '';
$error = '';

// File Upload Validation Settings
$allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
$allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp'];
$max_file_size = 5 * 1024 * 1024; // 5MB

// ==========================================
// HANDLE TASK PROGRESS & WORKFLOW UPDATES
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. Start Work & Upload "Before" Photos
    if ($_POST['action'] === 'start_work') {
        $assignment_id = intval($_POST['assignment_id'] ?? 0);
        $request_id = intval($_POST['request_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        try {
            $pdo->beginTransaction();

            // Handle "Before" Photos
            if (!empty($_FILES['before_photos']['name'][0])) {
                foreach ($_FILES['before_photos']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['before_photos']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_ext = strtolower(pathinfo($_FILES['before_photos']['name'][$key], PATHINFO_EXTENSION));
                        if ($_FILES['before_photos']['size'][$key] <= $max_file_size && in_array($file_ext, $allowed_extensions)) {
                            $filename = 'before_' . $request_id . '_' . bin2hex(random_bytes(6)) . '.' . $file_ext;
                            $stored_path = supabase_storage_upload($tmp_name, $filename);
                            if ($stored_path === null) {
                                $upload_dir = __DIR__ . '/uploads/maintenance/';
                                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                                if (move_uploaded_file($tmp_name, $upload_dir . $filename)) {
                                    $stored_path = 'uploads/maintenance/' . $filename;
                                }
                            }
                            if ($stored_path !== null) {
                                $img_stmt = $pdo->prepare("INSERT INTO public.maintenance_photos (maintenance_request_id, uploaded_by, photo_type, file_path) VALUES (?, ?, 'Before', ?)");
                                $img_stmt->execute([$request_id, $staff_id, $stored_path]);
                            }
                        }
                    }
                }
            }

            // Update Statuses
            $stmt = $pdo->prepare("UPDATE public.maintenance_assignments SET status = 'In Progress' WHERE id = ? AND personnel_id = ?");
            $stmt->execute([$assignment_id, $staff_id]);

            $stmt_req = $pdo->prepare("UPDATE public.maintenance_requests SET status = 'In Progress', updated_at = NOW() WHERE id = ?");
            $stmt_req->execute([$request_id]);

            // History & Progress Log
            $log = $pdo->prepare("INSERT INTO public.maintenance_history (maintenance_request_id, user_id, action, old_status, new_status, notes) VALUES (?, ?::uuid, 'Started Maintenance', 'Assigned', 'In Progress', ?)");
            $log->execute([$request_id, $staff_id, $notes]);

            $pdo->commit();
            $message = "Maintenance work started successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to start maintenance: " . $e->getMessage();
        }
    }

    // 2. Complete Task & Upload "Completion" Photos
    if ($_POST['action'] === 'complete_work') {
        $assignment_id = intval($_POST['assignment_id'] ?? 0);
        $request_id = intval($_POST['request_id'] ?? 0);
        $summary_notes = trim($_POST['summary_notes'] ?? '');

        try {
            $pdo->beginTransaction();

            // Handle "Completion" Photos
            if (!empty($_FILES['after_photos']['name'][0])) {
                foreach ($_FILES['after_photos']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['after_photos']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_ext = strtolower(pathinfo($_FILES['after_photos']['name'][$key], PATHINFO_EXTENSION));
                        if ($_FILES['after_photos']['size'][$key] <= $max_file_size && in_array($file_ext, $allowed_extensions)) {
                            $filename = 'after_' . $request_id . '_' . bin2hex(random_bytes(6)) . '.' . $file_ext;
                            $stored_path = supabase_storage_upload($tmp_name, $filename);
                            if ($stored_path === null) {
                                $upload_dir = __DIR__ . '/uploads/maintenance/';
                                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                                if (move_uploaded_file($tmp_name, $upload_dir . $filename)) {
                                    $stored_path = 'uploads/maintenance/' . $filename;
                                }
                            }
                            if ($stored_path !== null) {
                                $img_stmt = $pdo->prepare("INSERT INTO public.maintenance_photos (maintenance_request_id, uploaded_by, photo_type, file_path) VALUES (?, ?, 'Completion', ?)");
                                $img_stmt->execute([$request_id, $staff_id, $stored_path]);
                            }
                        }
                    }
                }
            }

            // Update Assignment and Request Statuses
            $stmt = $pdo->prepare("UPDATE public.maintenance_assignments SET status = 'Completed' WHERE id = ? AND personnel_id = ?");
            $stmt->execute([$assignment_id, $staff_id]);

            $stmt_req = $pdo->prepare("UPDATE public.maintenance_requests SET status = 'For Verification', updated_at = NOW() WHERE id = ?");
            $stmt_req->execute([$request_id]);

            // Save Progress Summary
            $prog_stmt = $pdo->prepare("INSERT INTO public.maintenance_progress (maintenance_request_id, personnel_id, progress_note) VALUES (?, ?, ?)");
            $prog_stmt->execute([$request_id, $staff_id, $summary_notes]);

            // History Log
            $log = $pdo->prepare("INSERT INTO public.maintenance_history (maintenance_request_id, user_id, action, old_status, new_status, notes) VALUES (?, ?::uuid, 'Submitted for Verification', 'In Progress', 'For Verification', ?)");
            $log->execute([$request_id, $staff_id, $summary_notes]);

            $pdo->commit();
            $message = "Task submitted for administrator verification.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to submit completion: " . $e->getMessage();
        }
    }
}

// ==========================================
// FETCH ASSIGNED MAINTENANCE TASKS
// ==========================================
$assignments = [];
$layouts = [];
$sections = [];

try {
    $assign_stmt = $pdo->prepare("
        SELECT 
            ma.id AS assignment_id, ma.status AS assignment_status, ma.assigned_at,
            mr.id AS request_id, mr.request_number, mr.description, mr.priority, mr.status AS request_status, mr.preferred_date,
            st.name AS service_name,
            d.plot_code as plot_number, d.latitude, d.longitude,
            u.name AS requester_name
        FROM public.maintenance_assignments ma
        JOIN public.maintenance_requests mr ON ma.maintenance_request_id = mr.id
        JOIN public.maintenance_service_types st ON mr.service_type_id = st.id
        LEFT JOIN public.deceased_records d ON mr.plot_id = d.id
        JOIN public.users u ON mr.requester_id = u.id
        WHERE ma.personnel_id = ?
        ORDER BY ma.assigned_at DESC
    ");
    $assign_stmt->execute([$staff_id]);
    $assignments = $assign_stmt->fetchAll(PDO::FETCH_ASSOC);

    $admin_layouts = $pdo->query("SELECT * FROM public.cemetery_layouts ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $admin_sections = $pdo->query("SELECT * FROM public.sections ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch cemetery boundary (main perimeter)
    $admin_cemetery_boundary = null;
    try {
        $boundary_stmt = $pdo->query("SELECT * FROM public.cemetery_layouts WHERE section_id IS NULL ORDER BY id DESC LIMIT 1");
        if ($boundary_stmt) {
            $admin_cemetery_boundary = $boundary_stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $admin_cemetery_boundary = null;
    }

    // Fetch All Plots from Admin
    $admin_plots = [];
    try {
        $admin_plots_stmt = $pdo->query("SELECT id, plot_code as plot_number, latitude, longitude FROM public.deceased_records WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY plot_code");
        if ($admin_plots_stmt) {
            $admin_plots = $admin_plots_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $admin_plots = [];
    }

    // Fetch Deceased Records (Plot Shapes) from Admin
    $admin_deceased_records = [];
    try {
        $deceased_stmt = $pdo->query("SELECT * FROM public.deceased_records ORDER BY created_at DESC");
        if ($deceased_stmt) {
            $admin_deceased_records = $deceased_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $admin_deceased_records = [];
    }
} catch (PDOException $e) {
    // Suppress if tables are pending initial creation
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <?php require __DIR__ . '/includes/tab_guard.php'; ?>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Staff Portal - Maintenance Task Board</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: {
                            750: '#1e293b',
                            850: '#0f172a',
                            900: '#0b0f19',
                            950: '#030712',
                        },
                        cyan: {
                            400: '#22d3ee',
                            500: '#06b6d4',
                            600: '#0891b2',
                            950: '#083344',
                        }
                    }
                }
            }
        }
    </script>
    
    <!-- FontAwesome & Leaflet -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        #staffMap { height: 100%; width: 100%; z-index: 10; }
        
        .dashboard-scroll::-webkit-scrollbar { width: 5px; }
        .dashboard-scroll::-webkit-scrollbar-track { background: #0f172a; }
        .dashboard-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        .dashboard-scroll::-webkit-scrollbar-thumb:hover { background: #06b6d4; }

        /* Leaflet Dark UI Overrides */
        .leaflet-container { background: #0b0f19 !important; }
        .leaflet-popup-content-wrapper, .leaflet-popup-tip {
            background: #1e293b !important;
            color: #f1f5f9 !important;
            border: 1px solid #334155;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
        }
        .leaflet-control-layers {
            background: #1e293b !important;
            color: #f1f5f9 !important;
            border: 1px solid #334155 !important;
            border-radius: 0.75rem !important;
            padding: 6px 10px !important;
        }
        .leaflet-control-layers-toggle {
            filter: invert(1) hue-rotate(180deg);
        }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0891b2">
    <script src="pwa.js" defer></script>
    <script src="mobile.js" defer></script>
</head>
<body class="bg-slate-950 min-h-screen text-slate-100 font-sans antialiased selection:bg-cyan-500 selection:text-slate-950">

    <!-- HEADER -->
    <header class="sticky top-0 z-40 bg-slate-900/80 backdrop-blur-md border-b border-slate-800 px-4 lg:px-8 py-3 transition-all">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-600 to-cyan-400 flex items-center justify-center text-slate-950 font-black shadow-lg shadow-cyan-500/20">
                    <i class="fa-solid fa-screwdriver-wrench text-lg"></i>
                </div>
                <div>
                    <h2 class="text-base font-bold text-white tracking-wide flex items-center gap-2">
                        Matutum PlotNav
                        <span class="text-[10px] font-semibold bg-cyan-950 text-cyan-400 border border-cyan-800/60 px-2 py-0.5 rounded-full">v2.4</span>
                    </h2>
                    <p class="text-xs text-slate-400">Field Maintenance Dashboard</p>
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <div class="hidden sm:flex items-center gap-2.5 px-3 py-1.5 rounded-xl bg-slate-800/60 border border-slate-700/50">
                    <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></div>
                    <span class="text-xs font-medium text-slate-300">Staff: <strong class="text-white"><?= htmlspecialchars($_SESSION['name'] ?? 'Staff') ?></strong></span>
                    <?php if ($staff_job_role): ?>
                        <span class="text-[10px] font-semibold bg-cyan-950 text-cyan-400 border border-cyan-800/60 px-2 py-0.5 rounded-full"><?= htmlspecialchars($staff_job_role) ?></span>
                    <?php endif; ?>
                </div>
                <button id="themeToggle" type="button" onclick="if(typeof toggleTheme==='function'){toggleTheme(); const i=this.querySelector('i'); i.className=document.documentElement.classList.contains('dark')?'fa-solid fa-moon text-cyan-400 text-sm':'fa-solid fa-sun text-amber-400 text-sm';}" class="p-2 rounded-xl bg-slate-800 border border-slate-700 text-slate-400 hover:text-white transition" title="Toggle Theme">
                    <i class="fa-solid fa-moon text-sm"></i>
                </button>
                <script>document.getElementById('themeToggle').querySelector('i').className=document.documentElement.classList.contains('dark')?'fa-solid fa-moon text-cyan-400 text-sm':'fa-solid fa-sun text-amber-400 text-sm';</script>
                <a href="logout.php" class="inline-flex items-center gap-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 hover:border-slate-600 px-3.5 py-2 text-xs font-semibold text-slate-300 hover:text-white transition shadow-sm">
                    <i class="fa-solid fa-right-from-bracket text-cyan-400"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- MAIN DASHBOARD -->
    <main class="p-4 lg:p-8 max-w-7xl mx-auto space-y-5">

        <!-- ALERTS -->
        <?php if ($message): ?>
            <div class="p-4 bg-emerald-950/80 border border-emerald-500/40 text-emerald-200 text-xs rounded-2xl flex items-center gap-3 shadow-lg backdrop-blur-md">
                <i class="fa-solid fa-circle-check text-base text-emerald-400"></i>
                <span class="font-medium"><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="p-4 bg-rose-950/80 border border-rose-500/40 text-rose-200 text-xs rounded-2xl flex items-center gap-3 shadow-lg backdrop-blur-md">
                <i class="fa-solid fa-triangle-exclamation text-base text-rose-400"></i>
                <span class="font-medium"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- LEFT COLUMN: TASK CARDS LIST (5 cols) -->
            <div class="lg:col-span-5 bg-slate-900/90 rounded-2xl border border-slate-800 shadow-2xl p-4 lg:p-5 flex flex-col h-[480px] sm:h-[560px] lg:h-[680px]">
                <div class="flex items-center justify-between pb-4 mb-4 border-b border-slate-800">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-cyan-950 text-cyan-400 border border-cyan-800/40">
                            <i class="fa-solid fa-list-check text-sm"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-white text-sm">Assigned Tasks</h3>
                            <p class="text-[11px] text-slate-400">Active maintenance assignments</p>
                        </div>
                    </div>
                    <span class="text-xs font-bold bg-cyan-500/10 text-cyan-400 border border-cyan-500/30 px-3 py-1 rounded-full shadow-inner">
                        <?= count($assignments) ?> Active
                    </span>
                </div>

                <div class="space-y-3.5 overflow-y-auto dashboard-scroll flex-1 pr-1.5">
                    <?php if (empty($assignments)): ?>
                        <div class="text-center py-24 text-slate-500 space-y-3">
                            <i class="fa-solid fa-clipboard-check text-5xl text-slate-700"></i>
                            <p class="text-xs font-medium">No active maintenance tasks assigned.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($assignments as $task): ?>
                            <?php 
                                $lat = $task['latitude'] ?? 14.5995;
                                $lng = $task['longitude'] ?? 120.9842;
                                $status = $task['assignment_status'];
                            ?>
                            <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/60 hover:bg-slate-800/50 hover:border-slate-700 transition duration-200 space-y-3 group">
                                <div class="flex justify-between items-start gap-2">
                                    <div>
                                        <span class="text-[10px] font-mono font-bold text-cyan-400 tracking-wider">#<?= htmlspecialchars($task['request_number']) ?></span>
                                        <h4 class="font-bold text-xs text-slate-100 group-hover:text-cyan-300 transition-colors"><?= htmlspecialchars($task['service_name']) ?></h4>
                                    </div>
                                    <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full border shadow-sm
                                        <?= $status === 'Completed' ? 'bg-emerald-950/80 text-emerald-400 border-emerald-800/60' : ($status === 'In Progress' ? 'bg-amber-950/80 text-amber-400 border-amber-800/60' : 'bg-cyan-950/80 text-cyan-400 border-cyan-800/60') ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </div>

                                <p class="text-xs text-slate-400 line-clamp-2 leading-relaxed"><?= htmlspecialchars($task['description']) ?></p>

                                <div class="grid grid-cols-2 gap-2 text-[11px] text-slate-400 font-medium pt-1">
                                    <div class="flex items-center gap-1.5 bg-slate-900 px-2.5 py-1 rounded-lg border border-slate-800">
                                        <i class="fa-solid fa-location-dot text-cyan-400"></i> 
                                        <span class="truncate">Plot #<?= htmlspecialchars($task['plot_number']) ?></span>
                                    </div>
                                    <div class="flex items-center gap-1.5 bg-slate-900 px-2.5 py-1 rounded-lg border border-slate-800">
                                        <i class="fa-solid fa-user text-slate-400"></i> 
                                        <span class="truncate"><?= htmlspecialchars($task['requester_name']) ?></span>
                                    </div>
                                </div>

                                <!-- ACTIONS & CONTROLS -->
                                <div class="pt-2 flex items-center justify-between gap-2 border-t border-slate-800/80">
                                    <button type="button" onclick="locateAssignment(<?= $lat ?>, <?= $lng ?>, <?= $task['assignment_id'] ?>)" class="px-3 py-1.5 text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-lg border border-slate-700 transition flex items-center gap-1.5">
                                        <i class="fa-solid fa-crosshairs text-cyan-400"></i> Locate Map
                                    </button>

                                    <?php if ($status === 'Assigned'): ?>
                                        <button onclick="openStartModal(<?= $task['assignment_id'] ?>, <?= $task['request_id'] ?>)" class="px-3.5 py-1.5 text-xs font-bold bg-amber-600 hover:bg-amber-500 text-white rounded-lg shadow-lg shadow-amber-600/20 transition flex items-center gap-1.5">
                                            <i class="fa-solid fa-play text-[10px]"></i> Start Task
                                        </button>
                                    <?php elseif ($status === 'In Progress'): ?>
                                        <button onclick="openCompleteModal(<?= $task['assignment_id'] ?>, <?= $task['request_id'] ?>)" class="px-3.5 py-1.5 text-xs font-bold bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg shadow-lg shadow-emerald-600/20 transition flex items-center gap-1.5">
                                            <i class="fa-solid fa-check text-[10px]"></i> Complete Task
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT COLUMN: INTERACTIVE GIS MAP (7 cols) -->
            <div class="lg:col-span-7 bg-slate-900/90 rounded-2xl border border-slate-800 shadow-2xl p-4 lg:p-5 flex flex-col h-[380px] sm:h-[460px] lg:h-[680px]">
                <div class="flex items-center justify-between pb-4 mb-4 border-b border-slate-800">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-cyan-950 text-cyan-400 border border-cyan-800/40">
                            <i class="fa-solid fa-map-location-dot text-sm"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-white text-sm">GIS Target Map</h3>
                            <p class="text-[11px] text-slate-400">Interactive sector map view</p>
                        </div>
                    </div>
                    <span class="text-[11px] text-slate-400 flex items-center gap-1.5">
                        <i class="fa-solid fa-layer-group text-cyan-400"></i> Tile layers available
                    </span>
                </div>

                <div class="rounded-xl overflow-hidden border border-slate-800 flex-1 relative shadow-inner">
                    <div id="staffMap"></div>
                </div>
            </div>

        </div>
    </main>

    <!-- START WORK MODAL -->
    <div id="startModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-5 animate-in fade-in zoom-in duration-200">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="font-bold text-white text-sm flex items-center gap-2.5">
                    <i class="fa-solid fa-camera text-amber-500"></i> Start Task - "Before" Conditions
                </h3>
                <button onclick="closeModal('startModal')" class="text-slate-400 hover:text-white transition"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="start_work">
                <input type="hidden" name="assignment_id" id="start_assignment_id">
                <input type="hidden" name="request_id" id="start_request_id">

                <div>
                    <label class="text-[11px] font-semibold text-slate-300 uppercase tracking-wider block mb-1.5">Upload "Before" Photos</label>
                    <input type="file" name="before_photos[]" multiple accept="image/*" required class="w-full text-xs text-slate-400 bg-slate-950 border border-slate-800 rounded-xl p-2.5 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-800 file:text-cyan-400 hover:file:bg-slate-700 transition">
                </div>

                <div>
                    <label class="text-[11px] font-semibold text-slate-300 uppercase tracking-wider block mb-1.5">Initial Condition Notes</label>
                    <textarea name="notes" rows="3" placeholder="e.g., Heavy weed growth and fallen debris around plot perimeter..." class="w-full text-xs text-slate-200 bg-slate-950 border border-slate-800 rounded-xl p-3 focus:outline-none focus:border-cyan-500 transition placeholder:text-slate-600"></textarea>
                </div>

                <div class="flex justify-end gap-2.5 pt-2 border-t border-slate-800">
                    <button type="button" onclick="closeModal('startModal')" class="px-4 py-2 text-xs font-semibold text-slate-400 hover:text-white bg-slate-800 rounded-xl transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 text-xs font-bold bg-amber-600 hover:bg-amber-500 text-white rounded-xl shadow-lg shadow-amber-600/20 transition">Begin Work</button>
                </div>
            </form>
        </div>
    </div>

    <!-- COMPLETE WORK MODAL -->
    <div id="completeModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-5 animate-in fade-in zoom-in duration-200">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="font-bold text-white text-sm flex items-center gap-2.5">
                    <i class="fa-solid fa-circle-check text-emerald-500"></i> Complete Maintenance Task
                </h3>
                <button onclick="closeModal('completeModal')" class="text-slate-400 hover:text-white transition"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="complete_work">
                <input type="hidden" name="assignment_id" id="complete_assignment_id">
                <input type="hidden" name="request_id" id="complete_request_id">

                <div>
                    <label class="text-[11px] font-semibold text-slate-300 uppercase tracking-wider block mb-1.5">Upload "Completion" Photos</label>
                    <input type="file" name="after_photos[]" multiple accept="image/*" required class="w-full text-xs text-slate-400 bg-slate-950 border border-slate-800 rounded-xl p-2.5 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-800 file:text-cyan-400 hover:file:bg-slate-700 transition">
                </div>

                <div>
                    <label class="text-[11px] font-semibold text-slate-300 uppercase tracking-wider block mb-1.5">Work Summary Notes</label>
                    <textarea name="summary_notes" rows="3" required placeholder="Describe work completed (e.g., Cleared weeds, trimmed grass, washed monument surface)..." class="w-full text-xs text-slate-200 bg-slate-950 border border-slate-800 rounded-xl p-3 focus:outline-none focus:border-cyan-500 transition placeholder:text-slate-600"></textarea>
                </div>

                <div class="flex justify-end gap-2.5 pt-2 border-t border-slate-800">
                    <button type="button" onclick="closeModal('completeModal')" class="px-4 py-2 text-xs font-semibold text-slate-400 hover:text-white bg-slate-800 rounded-xl transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 text-xs font-bold bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl shadow-lg shadow-emerald-600/20 transition">Submit Work</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MAP INTERACTION SCRIPT -->
    <script>
        // Initialize Leaflet Map
        let map = L.map('staffMap').setView([14.5985, 120.9830], 17);

        // Define Base Map Tile Layers
        const esriSatellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
        });

        const googleStreets = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
            attribution: '&copy; Google Maps'
        });

        const googleHybrid = L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
            attribution: '&copy; Google Maps'
        });

        const openStreetMap = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        });

        // Set Default Base Map Layer
        esriSatellite.addTo(map);

        // Add Layer Controls Switcher
        const baseMaps = {
            "Esri World Imagery": esriSatellite,
            "Google Hybrid": googleHybrid,
            "Google Street View": googleStreets,
            "OpenStreetMap": openStreetMap
        };
        L.control.layers(baseMaps, null, { position: 'topright' }).addTo(map);

        let taskMarkers = {};

        // Load Sections from Database
        const adminSectionsData = <?= json_encode($admin_sections) ?>;
        const sectionGroup = L.featureGroup().addTo(map);

        adminSectionsData.forEach(section => {
            if (section.geojson) {
                try {
                    const geojson = typeof section.geojson === 'string' ? JSON.parse(section.geojson) : section.geojson;
                    const sectionLayer = L.geoJSON(geojson, {
                        style: {
                            color: section.color || '#22d3ee',
                            weight: 2,
                            opacity: 0.8,
                            fillColor: section.color || '#22d3ee',
                            fillOpacity: 0.15
                        }
                    });
                    sectionGroup.addLayer(sectionLayer);
                } catch (e) {
                    console.error('Error loading section:', e);
                }
            }
        });

        // Load Cemetery Boundary from Database
        const adminCemeteryBoundaryData = <?= json_encode($admin_cemetery_boundary) ?>;
        if (adminCemeteryBoundaryData && adminCemeteryBoundaryData.geojson) {
            try {
                const boundaryGeojson = typeof adminCemeteryBoundaryData.geojson === 'string' ? JSON.parse(adminCemeteryBoundaryData.geojson) : adminCemeteryBoundaryData.geojson;
                const boundaryLayer = L.geoJSON(boundaryGeojson, {
                    style: {
                        color: '#f43f5e',
                        weight: 3,
                        opacity: 0.9,
                        fill: false
                    }
                });
                boundaryLayer.addTo(map);
            } catch (e) {
                console.error('Error loading cemetery boundary:', e);
            }
        }

        // Load Plot Markers from Database
        const adminPlotsData = <?= json_encode($admin_plots) ?>;
        const plotsGroup = L.featureGroup().addTo(map);

        adminPlotsData.forEach(plot => {
            if (plot.latitude && plot.longitude) {
                const plotMarker = L.circleMarker([plot.latitude, plot.longitude], {
                    radius: 5,
                    fillColor: '#06b6d4',
                    color: '#ffffff',
                    weight: 1.5,
                    opacity: 0.9,
                    fillOpacity: 0.8
                }).bindPopup(`<div class="text-xs font-semibold">Plot #${plot.plot_number}</div>`);
                plotsGroup.addLayer(plotMarker);
            }
        });

        // Load Deceased Record Shapes from Database
        const adminDeceasedRecordsData = <?= json_encode($admin_deceased_records) ?>;
        const shapesGroup = L.featureGroup().addTo(map);

        adminDeceasedRecordsData.forEach(record => {
            if (record.geojson_shape) {
                try {
                    const shapeGeojson = typeof record.geojson_shape === 'string' ? JSON.parse(record.geojson_shape) : record.geojson_shape;
                    const shapeLayer = L.geoJSON(shapeGeojson, {
                        style: {
                            color: '#38bdf8',
                            weight: 2,
                            opacity: 0.8,
                            fillColor: '#0284c7',
                            fillOpacity: 0.3
                        }
                    }).bindPopup(`<div class="text-xs"><b>${record.deceased_name}</b><br>Plot Code: ${record.plot_code}</div>`);
                    shapesGroup.addLayer(shapeLayer);
                } catch (e) {
                    console.error('Error loading plot shape:', e);
                }
            }
        });

        // Load Assigned Task Markers
        const assignedTasks = <?= json_encode($assignments) ?>;

        assignedTasks.forEach(task => {
            const lat = task.latitude || 14.5995;
            const lng = task.longitude || 120.9842;
            
            const marker = L.marker([lat, lng]).addTo(map)
                .bindPopup(`
                    <div class="text-xs space-y-1">
                        <strong class="text-cyan-400 text-sm block">${task.service_name}</strong>
                        <div><b>Plot:</b> #${task.plot_number}</div>
                        <div><b>Status:</b> ${task.assignment_status}</div>
                        <div><b>Client:</b> ${task.requester_name}</div>
                    </div>
                `);

            taskMarkers[task.assignment_id] = marker;
        });

        function locateAssignment(lat, lng, assignmentId) {
            map.flyTo([lat, lng], 19, { animate: true, duration: 1.2 });
            if (taskMarkers[assignmentId]) {
                taskMarkers[assignmentId].openPopup();
            }
        }

        function openStartModal(assignmentId, requestId) {
            document.getElementById('start_assignment_id').value = assignmentId;
            document.getElementById('start_request_id').value = requestId;
            document.getElementById('startModal').classList.remove('hidden');
        }

        function openCompleteModal(assignmentId, requestId) {
            document.getElementById('complete_assignment_id').value = assignmentId;
            document.getElementById('complete_request_id').value = requestId;
            document.getElementById('completeModal').classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }
    </script>
</body>
</html>