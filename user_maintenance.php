<?php
require 'config.php';

// Authorization & Foreign Key Guard Check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = trim((string)$_SESSION['user_id']);

// Verify user existence in public.users to prevent FK constraint violations
if (!ensure_public_user($pdo, $user_id)) {
    session_unset();
    session_destroy();
    header("Location: login.php?error=invalid_session");
    exit();
}

$message = '';
$error = '';

// Handle Map Search
$search_query = trim($_GET['search'] ?? '');
$search_results = [];
if ($search_query !== '') {
    try {
        $search_stmt = $pdo->prepare("
            SELECT id, deceased_name, plot_code, latitude, longitude, date_of_death 
            FROM public.deceased_records 
            WHERE deceased_name ILIKE :query OR plot_code ILIKE :query 
            ORDER BY deceased_name ASC
        ");
        $search_stmt->execute(['query' => "%{$search_query}%"]);
        $search_results = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Search error: " . $e->getMessage());
        $search_results = [];
    }
}

// File Validation Configuration
$allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
$allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp'];
$max_file_size = 5 * 1024 * 1024; // 5 MB limit

// Handle New Maintenance Request Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_request') {
    $plot_id = trim($_POST['plot_id'] ?? '');
    $service_type_id = intval($_POST['service_type_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $preferred_date = $_POST['preferred_date'] ?? '';
    $preferred_time = $_POST['preferred_time'] ?? 'Morning';
    $priority = strtolower(trim($_POST['priority'] ?? 'medium'));
    $notes = trim($_POST['notes'] ?? '');

    // Validate plot_id
    if (empty($plot_id)) {
        $error = "Please select a plot.";
    } else {
        // Get plot information from deceased_records using UUID plot_id
        $plot_stmt = $pdo->prepare("
            SELECT id, plot_code as plot_number, deceased_name 
            FROM public.deceased_records 
            WHERE id = :plot_id::uuid
        ");
        $plot_stmt->execute(['plot_id' => $plot_id]);
        $plot_data = $plot_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plot_data) {
            $error = "Invalid plot selected.";
        } elseif (empty($service_type_id) || empty($description) || empty($preferred_date)) {
            $error = "Please fill in all mandatory request fields.";
        } else {
            try {
                $pdo->beginTransaction();

                // Re-verify user inside transaction
                $vrfy_stmt = $pdo->prepare("SELECT id FROM public.users WHERE id = :user_id::uuid");
                $vrfy_stmt->execute(['user_id' => $user_id]);
                if (!$vrfy_stmt->fetch()) {
                    throw new Exception("Your user account was not found in the database. Please re-login.");
                }

                // Generate Request Tracking ID
                $request_number = 'MR-' . date('Y') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));

                // Insert Request Record
                $ins_stmt = $pdo->prepare("
                    INSERT INTO public.maintenance_requests 
                    (
                        request_number, 
                        plot_id, 
                        requester_id, 
                        service_type_id, 
                        description, 
                        preferred_date, 
                        preferred_time, 
                        priority, 
                        status
                    )
                    VALUES (
                        :request_number, 
                        :plot_id::uuid, 
                        :requester_id::uuid, 
                        :service_type_id, 
                        :description, 
                        :preferred_date, 
                        :preferred_time, 
                        :priority, 
                        'Pending Review'
                    )
                    RETURNING id
                ");
                
                $ins_stmt->execute([
                    'request_number'  => $request_number,
                    'plot_id'         => $plot_id,
                    'requester_id'    => $user_id,
                    'service_type_id' => $service_type_id,
                    'description'     => $description,
                    'preferred_date'  => $preferred_date,
                    'preferred_time'  => $preferred_time,
                    'priority'        => $priority
                ]);
                
                $request_id = $ins_stmt->fetchColumn();

                // Process Uploaded Photos
                if (!empty($_FILES['photos']['name'][0])) {
                    $upload_dir = __DIR__ . '/uploads/maintenance/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                            $file_size = $_FILES['photos']['size'][$key];
                            $file_name = $_FILES['photos']['name'][$key];
                            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                            // Secure MIME validation
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime_type = finfo_file($finfo, $tmp_name);
                            finfo_close($finfo);

                            if ($file_size <= $max_file_size && in_array($file_ext, $allowed_extensions) && in_array($mime_type, $allowed_mime_types)) {
                                $safe_filename = 'req_' . $request_id . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                                $destination = $upload_dir . $safe_filename;
                                $relative_path = 'uploads/maintenance/' . $safe_filename;

                                if (move_uploaded_file($tmp_name, $destination)) {
                                    $img_stmt = $pdo->prepare("
                                        INSERT INTO public.maintenance_photos (maintenance_request_id, uploaded_by, photo_type, file_path)
                                        VALUES (:req_id, :user_id::uuid, 'Request', :file_path)
                                    ");
                                    $img_stmt->execute([
                                        'req_id'    => $request_id,
                                        'user_id'   => $user_id,
                                        'file_path' => $relative_path
                                    ]);
                                }
                            }
                        }
                    }
                }

                // Write Audit Trail Entry
                $audit_stmt = $pdo->prepare("
                    INSERT INTO public.maintenance_history (maintenance_request_id, user_id, action, new_status, notes)
                    VALUES (:req_id, :user_id::uuid, 'Request Created', 'Pending Review', :notes)
                ");
                $audit_stmt->execute([
                    'req_id'  => $request_id,
                    'user_id' => $user_id,
                    'notes'   => 'Plot Owner submitted maintenance request'
                ]);

                // Notify Staff and Administrators
                $staff_members = $pdo->query("SELECT id FROM public.users WHERE role IN ('staff', 'admin')")->fetchAll(PDO::FETCH_COLUMN);
                $notif_stmt = $pdo->prepare("
                    INSERT INTO public.notifications (user_id, title, message, link)
                    VALUES (:staff_id::uuid, 'New Maintenance Request', :msg, :link)
                ");
                $notif_msg = "New request " . $request_number . " submitted for Plot #" . $plot_data['plot_number'];
                $notif_link = "staff.php?request_id=" . $request_id;

                foreach ($staff_members as $s_id) {
                    $notif_stmt->execute([
                        'staff_id' => $s_id,
                        'msg'      => $notif_msg,
                        'link'     => $notif_link
                    ]);
                }

                $pdo->commit();
                $message = "Maintenance request " . $request_number . " successfully submitted!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "System error processing request: " . $e->getMessage();
            }
        }
    }
}

// Fetch All Available Plots
$user_plots = [];
try {
    $plots_stmt = $pdo->query("
        SELECT id, plot_code as plot_number, latitude, longitude, date_of_death, deceased_name
        FROM public.deceased_records 
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        ORDER BY deceased_name ASC
    ");
    $user_plots = $plots_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching plots: " . $e->getMessage());
    $user_plots = [];
}

// Fetch Active Service Types
$services = [];
try {
    $services = $pdo->query("SELECT * FROM public.maintenance_service_types WHERE is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $services = [];
}

// Fetch User's Maintenance History & Requests
$my_requests = [];
try {
    $maintenance_stmt = $pdo->prepare("
        SELECT r.*, s.name as service_name, d.plot_code as plot_number
        FROM public.maintenance_requests r
        JOIN public.maintenance_service_types s ON r.service_type_id = s.id
        JOIN public.deceased_records d ON r.plot_id = d.id
        WHERE r.requester_id = :user_id::uuid
        ORDER BY r.created_at DESC
    ");
    $maintenance_stmt->execute(['user_id' => $user_id]);
    $my_requests = $maintenance_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $my_requests = [];
}

// Fetch Maintenance Notifications for this user
$maintenance_notifications = [];
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public.notifications (
            id SERIAL PRIMARY KEY,
            user_id UUID NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT,
            link VARCHAR(255),
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");
    $notif_stmt = $pdo->prepare("
        SELECT id, title, message, link, created_at
        FROM public.notifications
        WHERE user_id = :user_id::uuid
          AND link = 'user_maintenance.php'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $notif_stmt->execute(['user_id' => $user_id]);
    $maintenance_notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User notifications fetch: " . $e->getMessage());
    $maintenance_notifications = [];
}

// Fetch Sections from Admin
$admin_sections = [];
try {
    $sections_stmt = $pdo->query("SELECT id, section_name, section_code, color, geojson FROM public.sections WHERE geojson IS NOT NULL ORDER BY created_at DESC");
    if ($sections_stmt) {
        $admin_sections = $sections_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching sections: " . $e->getMessage());
    $admin_sections = [];
}

// Fetch Cemetery Boundary
$admin_cemetery_boundary = null;
try {
    $boundary_stmt = $pdo->query("SELECT * FROM public.cemetery_layouts WHERE section_id IS NULL AND geojson IS NOT NULL ORDER BY id DESC LIMIT 1");
    if ($boundary_stmt) {
        $admin_cemetery_boundary = $boundary_stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching cemetery boundary: " . $e->getMessage());
    $admin_cemetery_boundary = null;
}

// Fetch Plots from Admin
$admin_plots = [];
try {
    $admin_plots_stmt = $pdo->query("SELECT id, deceased_name, plot_code, latitude, longitude, date_of_death FROM public.deceased_records WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY deceased_name ASC");
    if ($admin_plots_stmt) {
        $admin_plots = $admin_plots_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching plots: " . $e->getMessage());
    $admin_plots = [];
}

// Fetch Deceased Records (Plot Shapes) from Admin
$admin_deceased_records = [];
try {
    $deceased_stmt = $pdo->query("SELECT id, deceased_name, plot_code, geojson_shape, latitude, longitude FROM public.deceased_records WHERE geojson_shape IS NOT NULL ORDER BY created_at DESC");
    if ($deceased_stmt) {
        $admin_deceased_records = $deceased_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching deceased records: " . $e->getMessage());
    $admin_deceased_records = [];
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Maintenance Management - Visitor Portal</title>
    <?php theme_head_script(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#ecfdf5',
                            100: '#d1fae5',
                            500: '#10b981',
                            600: '#059669',
                            700: '#047857',
                        },
                        accent: {
                            50: '#ecfeff',
                            500: '#06b6d4',
                            600: '#0891b2',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Leaflet CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        :root {
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --bg-sidebar: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --input-bg: #f1f5f9;
            --modal-bg: #ffffff;
        }

        .dark {
            --bg-body: #020617;
            --bg-card: #0f172a;
            --bg-sidebar: #0f172a;
            --border-color: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --input-bg: #020617;
            --modal-bg: #0f172a;
        }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        #sidebar { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-track { background: transparent; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #64748b; border-radius: 9999px; }
        .dark .custom-scroll::-webkit-scrollbar-thumb { background: #334155; }
        
        #visitorMap { height: 100%; width: 100%; min-height: 400px; }
        
        .plotbox-popup .leaflet-popup-content-wrapper {
            background: var(--bg-card);
            color: var(--text-main);
            border-radius: 0.75rem;
            padding: 0;
            overflow: hidden;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
        }
        .plotbox-popup .leaflet-popup-content { margin: 0; width: 280px !important; }
        .plotbox-popup .leaflet-popup-tip { background: var(--bg-card); }
        
        .glass-panel {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
        }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#7c3aed">
    <script src="pwa.js" defer></script>
    <script src="mobile.js" defer></script>
</head>
<body class="h-full flex flex-col font-sans overflow-hidden antialiased">

    <!-- HEADER -->
    <header class="glass-panel border-b px-4 py-3 flex items-center justify-between z-30 shrink-0">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:text-emerald-500 transition">
                <i class="fa-solid fa-bars text-sm"></i>
            </button>
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 bg-gradient-to-tr from-emerald-600 to-teal-500 rounded-xl flex items-center justify-center font-black text-white text-base shadow-md shadow-emerald-500/20">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                </div>
                <div>
                    <span class="font-extrabold tracking-tight text-sm block leading-none">MATUTUM <span class="text-emerald-500">PLOTNAV</span></span>
                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-medium">Plot Maintenance Portal</span>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300 hidden sm:inline-block px-3 py-1 rounded-full bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700">
                <i class="fa-solid fa-circle-user text-emerald-500 mr-1.5"></i><?= htmlspecialchars($_SESSION['name'] ?? 'Visitor') ?>
            </span>
            <a href="logout.php" class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition" title="Logout">
                <i class="fa-solid fa-right-from-bracket text-sm"></i>
            </a>
        </div>
    </header>

    <div class="flex-1 flex relative overflow-hidden">
        <?php include 'user_sidebar.php'; ?>

        <!-- MAP CONTROLS SIDE PANEL -->
        <aside id="mapPanel" class="w-80 glass-panel border-r p-3.5 flex flex-col gap-3 shrink-0 z-10 hidden sm:flex transition-all">
            <!-- PAGE TITLE -->
            <div class="bg-gradient-to-br from-emerald-500/10 via-teal-500/5 to-transparent border border-emerald-500/20 rounded-xl p-3">
                <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 flex items-center gap-2">
                    <i class="fa-solid fa-broom"></i> Maintenance Hub
                </h2>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Manage and submit plot services</p>
            </div>

            <!-- SYSTEM MESSAGES -->
            <?php if ($message): ?>
                <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 p-2.5 rounded-xl text-xs flex items-center gap-2">
                    <i class="fa-solid fa-circle-check shrink-0"></i> <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="bg-rose-500/10 border border-rose-500/30 text-rose-600 dark:text-rose-400 p-2.5 rounded-xl text-xs flex items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation shrink-0"></i> <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- MAINTENANCE NOTIFICATIONS -->
            <?php if (!empty($maintenance_notifications)): ?>
                <div class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-3 space-y-2">
                    <h3 class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-bell text-emerald-500"></i> Maintenance Updates
                    </h3>
                    <div class="space-y-2 max-h-40 overflow-y-auto custom-scroll pr-1">
                        <?php foreach ($maintenance_notifications as $n): ?>
                            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg p-2.5">
                                <p class="text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                                    <i class="fa-solid fa-circle-check text-emerald-500 text-[10px]"></i>
                                    <?= htmlspecialchars($n['title']) ?>
                                </p>
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-1 leading-relaxed"><?= htmlspecialchars($n['message']) ?></p>
                                <p class="text-[9px] text-slate-400 dark:text-slate-500 mt-1"><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- MAP SEARCH FORM -->
            <form method="GET" action="user_maintenance.php" class="flex gap-2">
                <div class="relative flex-1">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-400"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search plot or name..." class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl pl-8 pr-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500 transition font-medium">
                </div>
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 active:scale-95 text-white px-3 py-1.5 rounded-xl text-xs font-semibold transition shadow-md shadow-emerald-600/20">Find</button>
            </form>

            <!-- ACTION BUTTONS -->
            <div class="grid grid-cols-2 gap-2">
                <button onclick="openTicketsModal()" class="bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 p-2.5 rounded-xl text-xs font-bold transition flex flex-col items-center justify-center gap-1 border border-slate-200 dark:border-slate-700">
                    <i class="fa-solid fa-ticket text-emerald-500 text-sm"></i> 
                    <span>Tickets (<?= count($my_requests) ?>)</span>
                </button>

                <button onclick="openRequestModal()" class="bg-emerald-600 hover:bg-emerald-500 active:scale-95 text-white p-2.5 rounded-xl text-xs font-bold transition flex flex-col items-center justify-center gap-1 shadow-lg shadow-emerald-600/20">
                    <i class="fa-solid fa-plus text-sm"></i> 
                    <span>New Request</span>
                </button>
            </div>

            <!-- ALL PLOTS LIST -->
            <div class="flex-1 flex flex-col min-h-0 bg-slate-50 dark:bg-slate-950/50 border border-slate-200 dark:border-slate-800/80 rounded-xl p-2.5 overflow-hidden">
                <div class="flex justify-between items-center mb-2 px-1">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">All Plots (<?= count($user_plots) ?>)</span>
                    <button type="button" class="text-[10px] text-emerald-500 font-semibold hover:underline" onclick="resetMapView()">Reset Map</button>
                </div>
                <div class="overflow-y-auto custom-scroll flex-1 space-y-1.5 pr-1">
                    <?php if (empty($user_plots)): ?>
                        <div class="text-center py-8 text-slate-400 text-xs">
                            <i class="fa-solid fa-folder-open block text-lg mb-1 opacity-50"></i>
                            No plots found.
                        </div>
                    <?php else: ?>
                        <?php foreach ($user_plots as $p): ?>
                            <?php 
                                $lat = $p['latitude'] ?? 14.5985;
                                $lng = $p['longitude'] ?? 120.9830;
                            ?>
                            <div class="p-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg flex items-center justify-between hover:border-emerald-500/50 transition group">
                                <div class="truncate mr-2">
                                    <h4 class="font-bold text-xs text-slate-800 dark:text-slate-200 group-hover:text-emerald-500 transition truncate">Plot #<?= htmlspecialchars($p['plot_number']) ?></h4>
                                    <span class="text-[10px] text-slate-400 block truncate"><?= htmlspecialchars($p['deceased_name'] ?? 'Unoccupied') ?></span>
                                </div>
                                <button onclick="locatePlot(<?= $lat ?>, <?= $lng ?>, '<?= addslashes($p['plot_number']) ?>')" class="bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-600 hover:text-white text-[10px] px-2.5 py-1 rounded-md font-bold transition shrink-0">Locate</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <!-- MAIN GIS MAP -->
        <main class="map-viewport flex-1 relative bg-slate-900">
            <div id="visitorMap" class="h-full w-full"></div>
            
            <!-- Map Layer Switcher -->
            <div class="absolute top-3 right-3 z-[1000] glass-panel rounded-xl p-1 shadow-xl flex gap-1 text-[11px] font-semibold">
                <button onclick="setBaseMap('satellite')" id="btnSat" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white font-bold transition">Satellite</button>
                <button onclick="setBaseMap('street')" id="btnStreet" class="px-3 py-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">Street Map</button>
            </div>

            <!-- Mobile Toggle Map Controls Panel Button -->
            <button onclick="toggleMapPanel()" class="sm:hidden absolute top-3 left-3 z-[1000] glass-panel p-2.5 rounded-xl text-slate-700 dark:text-slate-200 shadow-xl">
                <i class="fa-solid fa-sliders text-xs"></i>
            </button>
        </main>
    </div>

    <!-- NEW REQUEST MODAL -->
    <div id="requestModal" class="fixed inset-0 bg-slate-950/60 backdrop-blur-md z-[10001] hidden flex items-center justify-center p-3 sm:p-4">
        <div class="glass-panel rounded-2xl max-w-2xl w-full p-4 sm:p-6 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto custom-scroll border border-slate-200 dark:border-slate-800">
            <div class="flex justify-between items-center border-b border-slate-200 dark:border-slate-800 pb-3">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <i class="fa-solid fa-file-signature text-emerald-500"></i> New Maintenance Request
                </h3>
                <button onclick="closeRequestModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white p-1 rounded-lg transition"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <form method="POST" action="" enctype="multipart/form-data" class="space-y-4" id="maintenanceForm">
                <input type="hidden" name="action" value="submit_request">

                <div id="formValidation" class="hidden bg-rose-500/10 border border-rose-500/30 text-rose-600 dark:text-rose-400 p-3 rounded-xl text-xs font-medium"></div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Search & Select Plot</label>
                        <div class="relative mb-1">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-400"></i>
                            <input type="text" id="plotSearchInput" placeholder="Search plot or name..." class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl pl-8 pr-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500 transition">
                            <div id="searchResults" class="absolute top-full left-0 right-0 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl mt-1 max-h-48 overflow-y-auto z-50 hidden shadow-2xl custom-scroll"></div>
                        </div>
                        <input type="hidden" name="plot_id" id="plotId" required>
                        <div id="selectedPlotInfo" class="hidden bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-2.5 mt-2">
                            <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold" id="selectedPlotText"></span>
                        </div>
                    </div>

                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Service Type</label>
                        <select name="service_type_id" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500 transition">
                            <option value="">-- Select Service --</option>
                            <?php foreach ($services as $s): ?>
                                <option value="<?= $s['id'] ?>" data-description="<?= htmlspecialchars($s['description'] ?? 'Standard maintenance service') ?>" data-fee="<?= $s['default_fee'] ?>">
                                    <?= htmlspecialchars($s['name']) ?> - ₱<?= number_format($s['default_fee'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="serviceDescription" class="mt-1 text-[10px] text-slate-500 dark:text-slate-400 italic"></div>
                    </div>

                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Priority Level</label>
                        <select name="priority" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500 transition">
                            <option value="low">🟢 Low - Routine maintenance (7-14 days)</option>
                            <option value="medium" selected>🟡 Medium - Standard priority (3-7 days)</option>
                            <option value="high">🟠 High - Important matter (1-3 days) [+20% fee]</option>
                            <option value="urgent">🔴 Urgent - Emergency (24-48 hours) [+50% fee]</option>
                        </select>
                    </div>

                    <div id="costEstimation" class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-3 text-center flex items-center justify-center">
                        <span class="text-[11px] text-slate-500 dark:text-slate-400 font-medium">Select a service to see estimated cost</span>
                    </div>

                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Preferred Date</label>
                        <input type="date" name="preferred_date" required min="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500 transition">
                    </div>

                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Preferred Time</label>
                        <select name="preferred_time" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500 transition">
                            <option value="Morning">Morning (8:00 AM - 12:00 PM)</option>
                            <option value="Afternoon">Afternoon (1:00 PM - 5:00 PM)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Quick Templates</label>
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" onclick="applyTemplate('weeds')" class="px-2.5 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-[10px] font-semibold rounded-lg border border-slate-200 dark:border-slate-700 transition">
                            🌿 Weed Removal
                        </button>
                        <button type="button" onclick="applyTemplate('cleaning')" class="px-2.5 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-[10px] font-semibold rounded-lg border border-slate-200 dark:border-slate-700 transition">
                            🧹 General Cleaning
                        </button>
                        <button type="button" onclick="applyTemplate('repair')" class="px-2.5 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-[10px] font-semibold rounded-lg border border-slate-200 dark:border-slate-700 transition">
                            🔧 Stone Repair
                        </button>
                        <button type="button" onclick="applyTemplate('flowers')" class="px-2.5 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-[10px] font-semibold rounded-lg border border-slate-200 dark:border-slate-700 transition">
                            🌸 Flower Bed
                        </button>
                    </div>
                </div>

                <div>
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Instructions / Specific Work Needed</label>
                    <textarea name="description" rows="3" required id="descriptionField" placeholder="Provide details (e.g., 'Please trim overgrown weeds surrounding the headstone border')" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-3 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-500 transition"></textarea>
                </div>

                <div>
                    <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Upload Plot Photos (Max 5MB each)</label>
                    <input type="file" name="photos[]" multiple accept="image/*" id="photoInput" class="w-full text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 cursor-pointer file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-emerald-500/10 file:text-emerald-600 dark:file:text-emerald-400">
                    <div id="photoPreview" class="mt-2 grid grid-cols-3 sm:grid-cols-4 gap-2"></div>
                </div>

                <div class="pt-3 flex justify-end gap-2 border-t border-slate-200 dark:border-slate-800">
                    <button type="button" onclick="closeRequestModal()" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-semibold rounded-xl hover:bg-slate-200 dark:hover:bg-slate-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-xl transition shadow-lg shadow-emerald-600/20">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TICKETS MODAL -->
    <div id="ticketsModal" class="fixed inset-0 bg-slate-950/60 backdrop-blur-md z-[9999] hidden flex items-center justify-center p-3 sm:p-4">
        <div class="glass-panel rounded-2xl max-w-4xl w-full p-4 sm:p-6 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto custom-scroll border border-slate-200 dark:border-slate-800">
            <div class="flex justify-between items-center border-b border-slate-200 dark:border-slate-800 pb-3">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <i class="fa-solid fa-ticket text-emerald-500"></i> My Maintenance Tickets
                </h3>
                <button onclick="closeTicketsModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white p-1 rounded-lg transition"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-100 dark:bg-slate-950/80 text-slate-500 dark:text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="p-3">Ticket ID</th>
                            <th class="p-3">Plot Location</th>
                            <th class="p-3">Service</th>
                            <th class="p-3">Schedule</th>
                            <th class="p-3">Priority</th>
                            <th class="p-3">Status</th>
                            <th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 bg-white dark:bg-slate-900/40">
                        <?php if (empty($my_requests)): ?>
                            <tr>
                                <td colspan="7" class="p-8 text-center text-slate-400 italic">No maintenance tickets found. Click "New Request" to get started.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($my_requests as $req): ?>
                                <?php $reqPriorityLower = strtolower($req['priority']); ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                                    <td class="p-3 font-mono font-bold text-emerald-600 dark:text-emerald-400"><?= htmlspecialchars($req['request_number']) ?></td>
                                    <td class="p-3 font-semibold">Plot #<?= htmlspecialchars($req['plot_number']) ?></td>
                                    <td class="p-3 font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($req['service_name']) ?></td>
                                    <td class="p-3 text-slate-500 dark:text-slate-400"><?= htmlspecialchars($req['preferred_date']) ?> <span class="text-[10px] opacity-75">(<?= htmlspecialchars($req['preferred_time']) ?>)</span></td>
                                    <td class="p-3">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider inline-block
                                            <?= $reqPriorityLower === 'urgent' ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20' : 
                                               ($reqPriorityLower === 'high' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20' : 
                                               ($reqPriorityLower === 'medium' ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20' : 
                                               'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20')) ?>">
                                            <?= htmlspecialchars(ucfirst($req['priority'])) ?>
                                        </span>
                                    </td>
                                    <td class="p-3">
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider inline-block
                                            <?= $req['status'] === 'Completed' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20' ?>">
                                            <?= htmlspecialchars($req['status']) ?>
                                        </span>
                                    </td>
                                    <td class="p-3 text-right">
                                        <button onclick="viewTicketDetails(<?= htmlspecialchars(json_encode($req)) ?>)" class="px-2.5 py-1 bg-slate-100 dark:bg-slate-800 hover:bg-emerald-600 hover:text-white dark:hover:bg-emerald-600 text-slate-700 dark:text-slate-300 rounded-lg text-[10px] font-semibold transition border border-slate-200 dark:border-slate-700">
                                            <i class="fa-solid fa-eye mr-1"></i> Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TICKET DETAILS MODAL -->
    <div id="ticketDetailsModal" class="fixed inset-0 bg-slate-950/60 backdrop-blur-md z-[10000] hidden flex items-center justify-center p-3 sm:p-4">
        <div class="glass-panel rounded-2xl max-w-2xl w-full p-4 sm:p-6 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto custom-scroll border border-slate-200 dark:border-slate-800">
            <div class="flex justify-between items-center border-b border-slate-200 dark:border-slate-800 pb-3">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <i class="fa-solid fa-ticket text-emerald-500"></i> Maintenance Ticket Details
                </h3>
                <button onclick="closeTicketDetailsModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white p-1 rounded-lg transition"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <div id="ticketDetailsContent" class="space-y-3"></div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        }

        function toggleMapPanel() {
            const panel = document.getElementById('mapPanel');
            panel.classList.toggle('hidden');
        }

        // Modal Functions
        function openRequestModal() {
            const modal = document.getElementById('requestModal');
            if (modal) {
                modal.classList.remove('hidden');
            }
        }

        function closeRequestModal() {
            const modal = document.getElementById('requestModal');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        function openTicketsModal() {
            document.getElementById('ticketsModal').classList.remove('hidden');
        }

        function closeTicketsModal() {
            document.getElementById('ticketsModal').classList.add('hidden');
        }

        function viewTicketDetails(requestData) {
            const ticketContent = document.getElementById('ticketDetailsContent');
            const prioLower = (requestData.priority || '').toLowerCase();
            ticketContent.innerHTML = `
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-slate-50 dark:bg-slate-950 rounded-xl p-3 border border-slate-200 dark:border-slate-800">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Ticket Number</div>
                        <div class="text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400">${requestData.request_number}</div>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-950 rounded-xl p-3 border border-slate-200 dark:border-slate-800">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Status</div>
                        <div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider inline-block
                                ${requestData.status === 'Completed' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20'}">
                                ${requestData.status}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-slate-50 dark:bg-slate-950 rounded-xl p-3 border border-slate-200 dark:border-slate-800">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Plot Location</div>
                        <div class="text-xs font-semibold text-slate-800 dark:text-slate-200">Plot #${requestData.plot_number}</div>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-950 rounded-xl p-3 border border-slate-200 dark:border-slate-800">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Service Type</div>
                        <div class="text-xs font-semibold text-slate-800 dark:text-slate-200">${requestData.service_name}</div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-slate-50 dark:bg-slate-950 rounded-xl p-3 border border-slate-200 dark:border-slate-800">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Priority</div>
                        <div>
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider inline-block
                                ${prioLower === 'urgent' ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20' : 
                                   (prioLower === 'high' ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20' : 
                                   (prioLower === 'medium' ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20' : 
                                   'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20'))}">
                                ${requestData.priority}
                            </span>
                        </div>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-950 rounded-xl p-3 border border-slate-200 dark:border-slate-800">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Preferred Schedule</div>
                        <div class="text-xs font-semibold text-slate-800 dark:text-slate-200">${requestData.preferred_date} (${requestData.preferred_time})</div>
                    </div>
                </div>

                <div class="bg-slate-50 dark:bg-slate-950 rounded-xl p-3 border border-slate-200 dark:border-slate-800">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Description</div>
                    <div class="text-xs text-slate-700 dark:text-slate-300 leading-relaxed">${requestData.description}</div>
                </div>

                <div class="bg-slate-50 dark:bg-slate-950 rounded-xl p-3 border border-slate-200 dark:border-slate-800">
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">Submitted On</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">${new Date(requestData.created_at).toLocaleString()}</div>
                </div>
            `;
            
            closeTicketsModal();
            document.getElementById('ticketDetailsModal').classList.remove('hidden');
        }

        function closeTicketDetailsModal() {
            document.getElementById('ticketDetailsModal').classList.add('hidden');
        }

        // Initialize Map
        const visitorMap = L.map('visitorMap', { zoomControl: false }).setView([14.5985, 120.9830], 18);
        L.control.zoom({ position: 'bottomright' }).addTo(visitorMap);

        const satelliteTile = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', { maxNativeZoom: 20, maxZoom: 22 }).addTo(visitorMap);
        const streetTile = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxNativeZoom: 19, maxZoom: 22 });

        function setBaseMap(type) {
            if (type === 'satellite') {
                visitorMap.removeLayer(streetTile); visitorMap.addLayer(satelliteTile);
                document.getElementById('btnSat').className = 'px-3 py-1.5 rounded-lg bg-emerald-600 text-white font-bold transition';
                document.getElementById('btnStreet').className = 'px-3 py-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition';
            } else {
                visitorMap.removeLayer(satelliteTile); visitorMap.addLayer(streetTile);
                document.getElementById('btnStreet').className = 'px-3 py-1.5 rounded-lg bg-emerald-600 text-white font-bold transition';
                document.getElementById('btnSat').className = 'px-3 py-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition';
            }
        }

        const layoutLayerGroup = L.featureGroup().addTo(visitorMap);
        const plotsGroup = L.featureGroup().addTo(visitorMap);
        const shapesGroup = L.featureGroup().addTo(visitorMap);

        // Load Cemetery Boundary
        const dbPerimeter = <?= json_encode($admin_cemetery_boundary) ?>;
        if (dbPerimeter && dbPerimeter.geojson) {
            try {
                let perimGeoJson = typeof dbPerimeter.geojson === 'string' ? JSON.parse(dbPerimeter.geojson) : dbPerimeter.geojson;
                if (perimGeoJson && perimGeoJson.type) {
                    L.geoJSON(perimGeoJson, { style: { color: dbPerimeter.color || '#ef4444', weight: 2, fill: false } }).addTo(layoutLayerGroup);
                }
            } catch (e) { console.error('Boundary load error:', e); }
        }

        // Load Sections
        const adminSectionsData = <?= json_encode($admin_sections) ?>;
        if (Array.isArray(adminSectionsData)) {
            adminSectionsData.forEach(section => {
                if (section.geojson) {
                    try {
                        const geojson = typeof section.geojson === 'string' ? JSON.parse(section.geojson) : section.geojson;
                        if (geojson && geojson.type) {
                            const sectionLayer = L.geoJSON(geojson, {
                                style: { color: section.color || '#38bdf8', weight: 2, opacity: 0.9, fillColor: 'transparent', fillOpacity: 0 }
                            });
                            layoutLayerGroup.addLayer(sectionLayer);
                        }
                    } catch (e) { console.error('Section load error:', e); }
                }
            });
        }

        // Load All Plots
        const adminPlotsData = <?= json_encode($admin_plots) ?>;
        const searchResultsData = <?= json_encode($search_results) ?>;
        
        function createPlotPopupHTML(rec) {
            const dodFormatted = rec.date_of_death ? new Date(rec.date_of_death).toLocaleDateString('en-GB') : 'N/A';
            
            return `
                <div class="p-3.5 space-y-2">
                    <span class="text-[10px] font-extrabold text-emerald-500 font-mono tracking-wider uppercase bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20">Plot #${rec.plot_code || 'N/A'}</span>
                    <h4 class="font-bold text-xs text-slate-800 dark:text-slate-100 leading-snug">${rec.deceased_name}</h4>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400"><i class="fa-solid fa-calendar-day text-emerald-500 mr-1"></i> DOD: ${dodFormatted}</p>
                    <button onclick="window.openRequestModalForPlot(${rec.latitude}, ${rec.longitude}, '${rec.plot_code || 'N/A'}', '${rec.deceased_name.replace(/'/g, "\\'")}')" 
                            class="w-full mt-2 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-bold rounded-lg transition shadow-md shadow-emerald-600/20 flex items-center justify-center gap-1">
                        <i class="fa-solid fa-screwdriver-wrench"></i> Request Service
                    </button>
                </div>
            `;
        }
        
        function openRequestModalForPlot(lat, lng, plotCode, deceasedName) {
            const plot = userPlotsSearchData.find(p => 
                Math.abs(p.lat - lat) < 0.0001 && Math.abs(p.lng - lng) < 0.0001
            );
            
            openRequestModal();
            
            if (plot) {
                document.getElementById('plotId').value = plot.id;
                const searchInput = document.getElementById('plotSearchInput');
                searchInput.value = `Plot #${plot.plotNumber} - ${deceasedName}`;
                
                const selectedPlotInfo = document.getElementById('selectedPlotInfo');
                const selectedPlotText = document.getElementById('selectedPlotText');
                selectedPlotInfo.classList.remove('hidden');
                selectedPlotText.textContent = `Selected: Plot #${plot.plotNumber} - (${plot.deceasedName})`;
            } else {
                const searchInput = document.getElementById('plotSearchInput');
                searchInput.value = `Plot #${plotCode} - ${deceasedName}`;
            }
            
            if (userPlotMarker) visitorMap.removeLayer(userPlotMarker);
            userPlotMarker = L.marker([lat, lng])
                .addTo(visitorMap)
                .bindPopup(`<b>Plot #${plotCode}</b><br>Deceased: ${deceasedName}`)
                .openPopup();
            visitorMap.flyTo([lat, lng], 19, { animate: true });
        }
        
        window.openRequestModalForPlot = openRequestModalForPlot;
        
        const plotsToLoad = searchResultsData.length > 0 ? searchResultsData : adminPlotsData;
        
        if (Array.isArray(plotsToLoad) && plotsToLoad.length > 0) {
            const searchMarkers = [];
            
            plotsToLoad.forEach(rec => {
                const lat = parseFloat(rec.latitude);
                const lng = parseFloat(rec.longitude);
                
                if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                    const plotMarker = L.marker([lat, lng]).bindPopup(
                        createPlotPopupHTML(rec), 
                        { className: 'plotbox-popup' }
                    );
                    plotsGroup.addLayer(plotMarker);
                    
                    if (searchResultsData.length > 0) {
                        searchMarkers.push(plotMarker);
                    }
                }
            });
            
            if (searchResultsData.length > 0 && searchMarkers.length > 0) {
                const searchBounds = L.featureGroup(searchMarkers);
                visitorMap.fitBounds(searchBounds.getBounds(), { padding: [40, 40] });
            }
        }

        // Load Deceased Records
        const adminDeceasedRecordsData = <?= json_encode($admin_deceased_records) ?>;
        if (Array.isArray(adminDeceasedRecordsData)) {
            adminDeceasedRecordsData.forEach(record => {
                if (record.geojson_shape) {
                    try {
                        const shapeGeojson = typeof record.geojson_shape === 'string' ? JSON.parse(record.geojson_shape) : record.geojson_shape;
                        if (shapeGeojson && shapeGeojson.type) {
                            const shapeLayer = L.geoJSON(shapeGeojson, {
                                style: { color: '#10b981', weight: 2, opacity: 0.8, fillColor: '#10b981', fillOpacity: 0.2 }
                            }).bindPopup(`<b>${record.deceased_name}</b><br>Plot: ${record.plot_code}`);
                            shapesGroup.addLayer(shapeLayer);
                        }
                    } catch (e) { console.error('Deceased record shape load error:', e); }
                }
            });
        }

        const allMapFeatures = L.featureGroup([layoutLayerGroup, plotsGroup, shapesGroup]);
        
        if (allMapFeatures.getLayers().length > 0) {
            try {
                visitorMap.fitBounds(allMapFeatures.getBounds(), { padding: [40, 40] });
            } catch (e) {
                visitorMap.setView([14.5985, 120.9830], 18);
            }
        } else {
            visitorMap.setView([14.5985, 120.9830], 18);
        }

        function resetMapView() {
            if (allMapFeatures.getLayers().length > 0) {
                try {
                    visitorMap.fitBounds(allMapFeatures.getBounds(), { padding: [40, 40] });
                } catch (e) {
                    visitorMap.setView([14.5985, 120.9830], 18);
                }
            } else {
                visitorMap.setView([14.5985, 120.9830], 18);
            }
        }

        let userPlotMarker = null;
        function locatePlot(lat, lng, plotNumber) {
            if (userPlotMarker) visitorMap.removeLayer(userPlotMarker);
            userPlotMarker = L.marker([lat, lng])
                .addTo(visitorMap)
                .bindPopup(`<b>Plot #${plotNumber}</b>`)
                .openPopup();
            visitorMap.flyTo([lat, lng], 19, { animate: true });
        }

        // Live Search Handler for Plot Search
        const plotSearchInput = document.getElementById('plotSearchInput');
        const searchResults = document.getElementById('searchResults');
        
        const userPlotsSearchData = [
            <?php foreach ($user_plots as $p): ?>
                {
                    id: "<?= $p['id'] ?>",
                    plotNumber: "<?= htmlspecialchars($p['plot_number']) ?>",
                    sectionName: "Main",
                    deceasedName: "<?= htmlspecialchars($p['deceased_name'] ?? 'Unoccupied') ?>",
                    lat: <?= !empty($p['latitude']) ? $p['latitude'] : 14.5985 ?>,
                    lng: <?= !empty($p['longitude']) ? $p['longitude'] : 120.9830 ?>
                },
            <?php endforeach; ?>
        ];

        if (Array.isArray(userPlotsSearchData) && userPlotsSearchData.length > 0) {
            userPlotsSearchData.forEach(plot => {
                const lat = parseFloat(plot.lat);
                const lng = parseFloat(plot.lng);
                
                if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                    const userPlotMarker = L.circleMarker([lat, lng], {
                        radius: 7,
                        fillColor: '#10b981',
                        color: '#ffffff',
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.9
                    }).bindPopup(`<b>Plot #${plot.plotNumber}</b><br>Deceased: ${plot.deceasedName || 'Unoccupied'}`);
                    plotsGroup.addLayer(userPlotMarker);
                }
            });
        }

        plotSearchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            searchResults.innerHTML = '';
            
            if (query.length < 2) {
                searchResults.classList.add('hidden');
                return;
            }
            
            const filteredPlots = userPlotsSearchData.filter(plot => 
                plot.plotNumber.toLowerCase().includes(query) ||
                plot.sectionName.toLowerCase().includes(query) ||
                plot.deceasedName.toLowerCase().includes(query)
            );
            
            if (filteredPlots.length > 0) {
                searchResults.classList.remove('hidden');
                filteredPlots.forEach(plot => {
                    const resultItem = document.createElement('div');
                    resultItem.className = 'p-2.5 hover:bg-slate-100 dark:hover:bg-slate-800/60 cursor-pointer border-b border-slate-200 dark:border-slate-800 last:border-0 transition';
                    resultItem.innerHTML = `
                        <div class="font-bold text-xs text-emerald-600 dark:text-emerald-400">Plot #${plot.plotNumber}</div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400">${plot.deceasedName}</div>
                    `;
                    resultItem.addEventListener('click', function() {
                        document.getElementById('plotId').value = plot.id;
                        plotSearchInput.value = `Plot #${plot.plotNumber} - ${plot.deceasedName}`;
                        searchResults.classList.add('hidden');
                        
                        const selectedPlotInfo = document.getElementById('selectedPlotInfo');
                        const selectedPlotText = document.getElementById('selectedPlotText');
                        selectedPlotInfo.classList.remove('hidden');
                        selectedPlotText.textContent = `Selected: Plot #${plot.plotNumber} - (${plot.deceasedName})`;
                        
                        if (userPlotMarker) visitorMap.removeLayer(userPlotMarker);
                        userPlotMarker = L.marker([plot.lat, plot.lng])
                            .addTo(visitorMap)
                            .bindPopup(`<b>Plot #${plot.plotNumber}</b><br>Deceased: ${plot.deceasedName}`)
                            .openPopup();
                        visitorMap.flyTo([plot.lat, plot.lng], 19, { animate: true });
                    });
                    searchResults.appendChild(resultItem);
                });
            } else {
                searchResults.classList.add('hidden');
            }
        });

        document.addEventListener('click', function(e) {
            if (!plotSearchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.add('hidden');
            }
        });

        // Service Type & Cost Handling
        const serviceSelect = document.querySelector('select[name="service_type_id"]');
        const serviceDescription = document.getElementById('serviceDescription');
        const prioritySelect = document.querySelector('select[name="priority"]');
        
        serviceSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const description = selectedOption.getAttribute('data-description');
            
            if (description) {
                serviceDescription.innerHTML = `<i class="fa-solid fa-circle-info text-emerald-500 mr-1"></i> ${description}`;
            } else {
                serviceDescription.innerHTML = '';
            }
            updateCostEstimation();
        });

        prioritySelect.addEventListener('change', updateCostEstimation);

        function updateCostEstimation() {
            const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
            const fee = parseFloat(selectedOption.getAttribute('data-fee')) || 0;
            const priority = prioritySelect.value;
            
            let multiplier = 1;
            switch(priority.toLowerCase()) {
                case 'high': multiplier = 1.2; break;
                case 'urgent': multiplier = 1.5; break;
                default: multiplier = 1;
            }
            
            const estimatedCost = fee * multiplier;
            const costDisplay = document.getElementById('costEstimation');
            if (costDisplay) {
                costDisplay.innerHTML = `<span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">Estimated Cost: ₱${estimatedCost.toFixed(2)} ${multiplier > 1 ? '<span class="text-[10px] font-normal text-amber-500">(includes surcharge)</span>' : ''}</span>`;
            }
        }

        // Templates
        const templates = {
            weeds: "Please remove overgrown weeds and grass around the headstone and plot borders. Ensure the area is clean and well-maintained.",
            cleaning: "General cleaning of the plot area including dusting the headstone, removing debris, and ensuring the surrounding area is tidy.",
            repair: "Repair needed for the headstone/monument. Please inspect for cracks, chips, or structural issues and provide assessment.",
            flowers: "Please maintain the flower bed around the plot - remove dead flowers, water plants, and ensure the area looks well-cared for."
        };

        function applyTemplate(templateName) {
            const descriptionField = document.getElementById('descriptionField');
            if (templates[templateName]) {
                descriptionField.value = templates[templateName];
            }
            validateForm();
        }

        // Photo Upload Handling
        document.getElementById('photoInput').addEventListener('change', function(e) {
            const previewContainer = document.getElementById('photoPreview');
            previewContainer.innerHTML = '';
            
            const files = Array.from(e.target.files);
            files.forEach((file, index) => {
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const previewDiv = document.createElement('div');
                        previewDiv.className = 'relative aspect-square rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 shadow-sm';
                        previewDiv.innerHTML = `
                            <img src="${e.target.result}" class="w-full h-full object-cover">
                            <button type="button" onclick="removePhoto(${index})" class="absolute top-1 right-1 w-5 h-5 bg-rose-500 text-white rounded-full text-xs flex items-center justify-center hover:bg-rose-600 transition shadow">×</button>
                        `;
                        previewContainer.appendChild(previewDiv);
                    };
                    reader.readAsDataURL(file);
                }
            });
        });

        function removePhoto(index) {
            const input = document.getElementById('photoInput');
            const files = Array.from(input.files);
            files.splice(index, 1);
            
            const newFiles = new DataTransfer();
            files.forEach(file => newFiles.items.add(file));
            input.files = newFiles.files;
            input.dispatchEvent(new Event('change'));
        }

        // Form Validation
        const form = document.getElementById('maintenanceForm');
        const validationDiv = document.getElementById('formValidation');
        
        function validateForm() {
            const errors = [];
            const plotId = document.getElementById('plotId');
            const description = document.getElementById('descriptionField');
            const dateInput = document.querySelector('input[name="preferred_date"]');
            
            if (!plotId.value) errors.push('Please search and select a plot');
            if (!serviceSelect.value) errors.push('Please select a service type');
            if (description.value.trim().length < 10) errors.push('Description must be at least 10 characters');
            if (!dateInput.value) errors.push('Please select a preferred date');
            
            if (errors.length > 0) {
                validationDiv.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1.5"></i> ' + errors.join(', ');
                validationDiv.classList.remove('hidden');
                return false;
            } else {
                validationDiv.classList.add('hidden');
                return true;
            }
        }

        document.getElementById('plotId').addEventListener('change', validateForm);
        serviceSelect.addEventListener('change', validateForm);
        document.getElementById('descriptionField').addEventListener('input', validateForm);
        document.querySelector('input[name="preferred_date"]').addEventListener('change', validateForm);
        
        form.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
            }
        });

        // Initialize size calculation on load
        window.addEventListener('load', () => {
            setTimeout(() => {
                visitorMap.invalidateSize();
                resetMapView();
            }, 200);
        });
        window.addEventListener('resize', () => { visitorMap.invalidateSize(); });
    </script>
</body>
</html>