<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// --- HANDLE AJAX REQUEST: live search suggestions ---
if (isset($_GET['suggest'])) {
    header('Content-Type: application/json');
    $suggest_query = trim((string)$_GET['suggest']);
    $suggestions = [];
    if ($suggest_query !== '') {
        try {
            $suggest_stmt = $pdo->prepare("
                SELECT id, deceased_name, plot_code, date_of_birth, date_of_death, latitude, longitude
                FROM public.deceased_records
                WHERE deceased_name ILIKE ? OR plot_code ILIKE ?
                ORDER BY deceased_name ASC
                LIMIT 8
            ");
            $suggest_stmt->execute(["%{$suggest_query}%", "%{$suggest_query}%"]);
            $suggestions = $suggest_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $suggestions = [];
        }
    }
    echo json_encode($suggestions);
    exit();
}

$search_query = trim($_GET['search'] ?? '');
$death_date = trim($_GET['death_date'] ?? '');
if ($death_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $death_date)) {
    $death_date = '';
}
$user_plot_codes = [];
$records = [];
$main_perimeter = null;
$sections = [];

try {
    $stmt_plots = $pdo->prepare("SELECT plot_code FROM public.reservations WHERE user_id = ?");
    $stmt_plots->execute([$user_id]);
    $user_plot_codes = $stmt_plots->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $user_plot_codes = [];
}

try {
    $conditions = [];
    $params = [];
    if ($search_query !== '') {
        $conditions[] = "(deceased_name ILIKE ? OR plot_code ILIKE ?)";
        $params[] = "%{$search_query}%";
        $params[] = "%{$search_query}%";
    }
    if ($death_date !== '') {
        $conditions[] = "CAST(date_of_death AS date) = ?";
        $params[] = $death_date;
    }

    if (!empty($conditions)) {
        $stmt = $pdo->prepare("
            SELECT * FROM public.deceased_records 
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY deceased_name ASC
            LIMIT 100
        ");
        $stmt->execute($params);
        $records = $stmt->fetchAll();
    } elseif (!empty($user_plot_codes)) {
        $plot_placeholders = implode(',', array_fill(0, count($user_plot_codes), '?'));
        $stmt = $pdo->prepare("
            SELECT * FROM public.deceased_records 
            WHERE plot_code IN ($plot_placeholders) 
            ORDER BY deceased_name ASC LIMIT 50
        ");
        $stmt->execute($user_plot_codes);
        $records = $stmt->fetchAll();
    }

    try {
        $stmt_perim = $pdo->query("SELECT * FROM public.cemetery_layouts WHERE section_id IS NULL ORDER BY id DESC LIMIT 1");
        if ($stmt_perim) $main_perimeter = $stmt_perim->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    try {
        $stmt_sections = $pdo->query("SELECT * FROM public.sections ORDER BY id DESC");
        if ($stmt_sections) $sections = $stmt_sections->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Interactive Map - Matutum PlotNav</title>
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
    
    <!-- Lucide & Font Awesome Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Leaflet CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .leaflet-routing-container { display: none !important; }
        #userMap { height: 100%; width: 100%; z-index: 1; }
        #sidebar { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        
        .glass-panel {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(226, 232, 240, 0.8);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05);
        }
        .dark .glass-panel {
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5);
        }

        /* Leaflet Controls Reset & Style */
        .leaflet-control-zoom {
            border: none !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1) !important;
            margin-bottom: 24px !important;
            margin-right: 16px !important;
        }
        .leaflet-control-zoom-in, .leaflet-control-zoom-out {
            background-color: rgba(255, 255, 255, 0.9) !important;
            color: #0f172a !important;
            border: 1px solid rgba(226, 232, 240, 0.8) !important;
            width: 38px !important;
            height: 38px !important;
            line-height: 38px !important;
            border-radius: 12px !important;
            font-weight: bold !important;
            transition: all 0.2s ease !important;
        }
        .dark .leaflet-control-zoom-in, .dark .leaflet-control-zoom-out {
            background-color: rgba(15, 23, 42, 0.85) !important;
            color: #f8fafc !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
        }
        .leaflet-control-zoom-in:hover, .leaflet-control-zoom-out:hover {
            background-color: #06b6d4 !important;
            color: #ffffff !important;
        }

        /* Leaflet Popup Styling */
        .plotbox-popup .leaflet-popup-content-wrapper {
            background: transparent;
            box-shadow: none;
            padding: 0;
        }
        .plotbox-popup .leaflet-popup-tip-container { display: none; }
        .plotbox-popup .leaflet-popup-content { margin: 0; width: 400px !important; max-width: calc(100vw - 40px) !important; }
        .plotbox-popup .leaflet-popup-close-button { display: none; }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#06b6d4">
    <script src="pwa.js" defer></script>
    <script src="mobile.js" defer></script>
</head>
<body class="bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 h-screen flex flex-col font-sans overflow-hidden antialiased transition-colors duration-200">

    <!-- HEADER BAR -->
    <header class="glass-panel border-b border-slate-200 dark:border-slate-800/80 px-5 py-3.5 flex items-center justify-between z-30 shrink-0">
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
                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-bold tracking-wider uppercase">Interactive GIS Navigation</span>
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
            <a href="logout.php" class="p-2.5 rounded-xl bg-white dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/60 text-slate-700 dark:text-slate-300 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition shadow-sm" title="Logout">
                <i data-lucide="log-out" class="w-4 h-4"></i>
            </a>
        </div>
    </header>

    <!-- MAIN BODY -->
    <div class="flex-1 flex relative overflow-hidden z-10">
        
        <?php if (file_exists('user_sidebar.php')) { include 'user_sidebar.php'; } ?>

        <!-- MAIN GIS MAP -->
        <main class="map-viewport flex-1 relative bg-slate-200 dark:bg-slate-950">
            <div id="userMap"></div>

            <!-- MAP FLOATING TOP CONTROLS CONTAINER -->
            <div class="absolute top-4 left-4 right-4 z-[1000] flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pointer-events-none">
                
                <!-- MAP SEARCH BAR -->
                <div class="pointer-events-auto flex flex-col gap-2 w-full sm:max-w-md">
                    <form method="GET" action="user_map.php" class="glass-panel border border-slate-200/80 dark:border-slate-800/80 rounded-2xl p-2 flex items-center gap-2 w-full shadow-lg">
                        <div class="relative flex-1 min-w-0 flex items-center bg-slate-100/80 dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800 rounded-xl transition focus-within:ring-2 focus-within:ring-cyan-500/50">
                            <i data-lucide="search" class="w-4 h-4 ml-3.5 shrink-0 text-slate-400"></i>
                            <input type="text" id="mapSearchInput" name="search" value="<?= htmlspecialchars($search_query) ?>" autocomplete="off" placeholder="Search relative name or plot code..." class="flex-1 min-w-0 bg-transparent pl-2.5 pr-2 py-2 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none font-medium">
                            <div class="h-4 w-[1px] shrink-0 bg-slate-300 dark:bg-slate-700"></div>
                            <div class="relative shrink-0 flex items-center">
                                <button type="button" id="deathDateToggle" class="p-1.5 mx-1 rounded-lg text-slate-400 hover:text-cyan-600 dark:hover:text-cyan-400 hover:bg-slate-200/60 dark:hover:bg-slate-800 transition" title="Show everyone who died on a specific date">
                                    <i data-lucide="calendar" class="w-4 h-4"></i>
                                </button>
                                <input type="date" id="deathDateInput" name="death_date" value="<?= htmlspecialchars($death_date) ?>" onchange="this.form.submit()" class="hidden w-[7.5rem] bg-transparent pr-1 py-2 text-xs text-slate-900 dark:text-white focus:outline-none font-medium [color-scheme:light] dark:[color-scheme:dark]">
                            </div>
                            <div id="searchSuggestions" class="absolute top-full left-0 right-0 mt-1.5 hidden max-h-72 overflow-y-auto rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl z-[1100]"></div>
                        </div>
                        <button type="submit" class="bg-gradient-to-r from-cyan-600 to-emerald-600 hover:from-cyan-500 hover:to-emerald-500 text-white px-4 py-2 rounded-xl text-xs font-bold transition shadow-md shadow-cyan-500/20 flex items-center justify-center shrink-0 gap-1.5">
                            <span>Search</span>
                            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                    <?php if ($death_date !== ''): ?>
                    <div class="glass-panel border border-slate-200/80 dark:border-slate-800/80 rounded-xl px-3 py-1.5 flex items-center gap-2 self-start shadow-md text-[11px] font-semibold text-slate-700 dark:text-slate-200">
                        <i data-lucide="calendar-check" class="w-3.5 h-3.5 text-cyan-600 dark:text-cyan-400"></i>
                        <span><?= count($records) ?> record<?= count($records) === 1 ? '' : 's' ?> died on <?= date('M j, Y', strtotime($death_date)) ?></span>
                        <a href="user_map.php<?= $search_query !== '' ? '?search=' . urlencode($search_query) : '' ?>" class="ml-1 text-slate-400 hover:text-rose-500 transition" title="Clear date filter">
                            <i data-lucide="x" class="w-3.5 h-3.5"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                
            </div>

            <!-- FLOATING LAYER SWITCHER & CONTROLS (bottom-center on phones, top-right on larger screens) -->
            <div class="absolute z-[1000] bottom-[max(1rem,env(safe-area-inset-bottom))] left-1/2 -translate-x-1/2 scale-90 origin-bottom sm:scale-100 sm:bottom-auto sm:left-auto sm:translate-x-0 sm:top-4 sm:right-4 pointer-events-auto glass-panel border border-slate-200/80 dark:border-slate-800 rounded-2xl p-1.5 flex items-center gap-1 shadow-lg">
                <button onclick="setUserBaseMap('satellite')" id="btnSatUser" class="px-3 py-1.5 rounded-xl bg-cyan-600 text-white font-bold text-xs flex items-center gap-1.5 shadow-sm transition">
                    <i data-lucide="layers" class="w-3.5 h-3.5"></i> Satellite
                </button>
                <button onclick="setUserBaseMap('street')" id="btnStreetUser" class="px-3 py-1.5 rounded-xl text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white font-semibold text-xs flex items-center gap-1.5 transition">
                    <i data-lucide="map" class="w-3.5 h-3.5"></i> Street Map
                </button>
                <div class="h-4 w-[1px] bg-slate-300 dark:bg-slate-700 mx-1"></div>
                <button onclick="resetUserMapView()" class="p-1.5 rounded-xl text-slate-600 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 hover:bg-slate-200/50 dark:hover:bg-slate-800 transition" title="Recenter Map">
                    <i data-lucide="locate-fixed" class="w-4 h-4"></i>
                </button>
            </div>
        </main>
    </div>

    <!-- MAP ENGINE SCRIPTS -->
    <script>
        function renderIcons() {
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
        }

        function toggleSidebar() { 
            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.toggle('-translate-x-full');
        }

        function toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon(isDark ? 'sun' : 'moon');
        }

        function updateThemeIcon(iconName) {
            const iconEl = document.getElementById('themeToggleIcon');
            if (iconEl) {
                iconEl.setAttribute('data-lucide', iconName);
                renderIcons();
            }
        }

        const map = L.map('userMap', { zoomControl: false }).setView([14.5985, 120.9830], 18);
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        const satelliteTile = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', { maxNativeZoom: 20, maxZoom: 22 }).addTo(map);
        const streetTile = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxNativeZoom: 19, maxZoom: 22 });

        function setUserBaseMap(type) {
            if (type === 'satellite') {
                map.removeLayer(streetTile); map.addLayer(satelliteTile);
                document.getElementById('btnSatUser').className = 'px-3 py-1.5 rounded-xl bg-cyan-600 text-white font-bold text-xs flex items-center gap-1.5 shadow-sm transition';
                document.getElementById('btnStreetUser').className = 'px-3 py-1.5 rounded-xl text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white font-semibold text-xs flex items-center gap-1.5 transition';
            } else {
                map.removeLayer(satelliteTile); map.addLayer(streetTile);
                document.getElementById('btnStreetUser').className = 'px-3 py-1.5 rounded-xl bg-cyan-600 text-white font-bold text-xs flex items-center gap-1.5 shadow-sm transition';
                document.getElementById('btnSatUser').className = 'px-3 py-1.5 rounded-xl text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white font-semibold text-xs flex items-center gap-1.5 transition';
            }
        }

        const layoutLayerGroup = L.featureGroup().addTo(map);
        const dbPerimeter = <?= json_encode($main_perimeter) ?>;
        if (dbPerimeter && dbPerimeter.geojson) {
            try {
                let perimGeoJson = typeof dbPerimeter.geojson === 'string' ? JSON.parse(dbPerimeter.geojson) : dbPerimeter.geojson;
                L.geoJSON(perimGeoJson, { style: { color: dbPerimeter.color || '#06b6d4', weight: 2, fill: false } }).addTo(layoutLayerGroup);
            } catch (e) {}
        }

        const graveMarkerGroup = L.featureGroup().addTo(map);
        const deceasedRecords = <?= json_encode($records) ?>;

        function createPlotboxPopupHTML(name, plotCode, dob, dod, lat, lng) {
            const rawCode = String(plotCode || 'N/A');
            const pcParts = rawCode.split('-');
            const plotNum = pcParts.length > 1 ? pcParts[pcParts.length - 1] : rawCode;
            const sectionName = pcParts.length > 1 ? pcParts.slice(0, -1).join('-') : 'N/A';
            return `
                <div class="relative rounded-2xl overflow-hidden shadow-2xl border border-violet-500/30 bg-[#0d1322] text-slate-100 font-sans">
                    <button onclick="map.closePopup()" class="absolute top-3 right-3 z-10 w-7 h-7 rounded-full text-slate-400 hover:text-white hover:bg-white/10 flex items-center justify-center transition" title="Close">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                    <div class="p-5">
                        <div class="flex items-center gap-4 mb-4">
                            <div class="w-20 h-20 rounded-xl overflow-hidden shrink-0 border border-slate-600/50 bg-gradient-to-br from-slate-700 to-slate-900 flex items-center justify-center">
                                <i class="fa-solid fa-cross text-3xl text-slate-400"></i>
                            </div>
                            <div class="min-w-0">
                                <h4 class="font-extrabold text-lg text-white leading-tight truncate">${escapeHtml(name)}</h4>
                                <span class="inline-flex items-center gap-1.5 mt-1.5 px-3 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/40 text-emerald-400 text-[10px] font-bold">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Occupied
                                </span>
                                <div class="flex items-center gap-1.5 mt-1.5 text-slate-400 text-[11px] font-medium">
                                    <i class="fa-regular fa-user"></i> Deceased
                                </div>
                            </div>
                            <div class="ml-auto text-center shrink-0 pr-1">
                                <i class="fa-solid fa-spa text-3xl text-violet-400"></i>
                                <div class="text-[9px] italic text-slate-400 mt-1 tracking-wide whitespace-nowrap">&mdash; Rest in Peace &mdash;</div>
                            </div>
                        </div>
                        <div class="rounded-xl border border-slate-700/70 bg-slate-800/40 flex overflow-hidden mb-3">
                            <div class="flex-1 flex items-center gap-3 px-4 py-3">
                                <div class="w-10 h-10 rounded-full bg-violet-600 flex items-center justify-center shrink-0">
                                    <i class="fa-regular fa-calendar-plus text-white text-sm"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[9px] font-bold tracking-[0.15em] text-slate-400 uppercase">Born</div>
                                    <div class="text-sm font-extrabold text-white whitespace-nowrap">${escapeHtml(dob)}</div>
                                </div>
                            </div>
                            <div class="w-px bg-slate-700/70"></div>
                            <div class="flex-1 flex items-center gap-3 px-4 py-3">
                                <div class="w-10 h-10 rounded-full bg-violet-600 flex items-center justify-center shrink-0">
                                    <i class="fa-regular fa-calendar-check text-white text-sm"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[9px] font-bold tracking-[0.15em] text-slate-400 uppercase">Died</div>
                                    <div class="text-sm font-extrabold text-white whitespace-nowrap">${escapeHtml(dod)}</div>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-xl border border-slate-700/70 bg-slate-800/40 grid grid-cols-[1fr_0.95fr_1.05fr] overflow-hidden mb-4">
                            <div class="flex items-center gap-2 px-2.5 py-3">
                                <div class="w-8 h-8 rounded-full bg-violet-600 flex items-center justify-center shrink-0">
                                    <i class="fa-solid fa-location-dot text-white text-[11px]"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[9px] font-semibold text-slate-400 whitespace-nowrap">Section</div>
                                    <div class="text-xs font-bold text-white truncate">${escapeHtml(sectionName)}</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 px-2.5 py-3 border-l border-slate-700/70">
                                <div class="w-8 h-8 rounded-full bg-violet-600 flex items-center justify-center shrink-0">
                                    <i class="fa-solid fa-grip text-white text-[11px]"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[9px] font-semibold text-slate-400 whitespace-nowrap">Plot Number</div>
                                    <div class="text-xs font-bold text-white truncate">${escapeHtml(plotNum)}</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 px-2.5 py-3 border-l border-slate-700/70">
                                <div class="w-8 h-8 rounded-full bg-violet-600 flex items-center justify-center shrink-0">
                                    <i class="fa-solid fa-book-open text-white text-[11px]"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-[9px] font-semibold text-slate-400 whitespace-nowrap">Status</div>
                                    <span class="inline-flex items-center gap-1 mt-0.5 px-1.5 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/40 text-emerald-400 text-[8px] font-bold whitespace-nowrap">
                                        <span class="w-1 h-1 rounded-full bg-emerald-400 shrink-0"></span> Occupied
                                    </span>
                                </div>
                            </div>
                        </div>
                        <button onclick="navigateToPlotWrapper(${lat}, ${lng}, '${name.replace(/'/g, "\\'").replace(/"/g, '&quot;')}', '${rawCode.replace(/'/g, "\\'").replace(/"/g, '&quot;')}', '${dod}')" class="relative w-full py-3 bg-gradient-to-r from-violet-600 to-purple-500 hover:from-violet-500 hover:to-purple-400 text-white text-sm font-bold rounded-xl shadow-lg shadow-violet-600/30 flex items-center justify-center gap-2 transition">
                            <i class="fa-solid fa-location-arrow text-sm"></i> Start Navigation
                            <i class="fa-solid fa-chevron-right absolute right-4 top-1/2 -translate-y-1/2 text-xs"></i>
                        </button>
                    </div>
                </div>
            `;
        }

        deceasedRecords.forEach(rec => {
            if (rec.latitude && rec.longitude) {
                const dobFormatted = rec.date_of_birth ? new Date(rec.date_of_birth).toLocaleDateString('en-GB') : 'N/A';
                const dodFormatted = rec.date_of_death ? new Date(rec.date_of_death).toLocaleDateString('en-GB') : 'N/A';
                L.marker([rec.latitude, rec.longitude]).bindPopup(createPlotboxPopupHTML(rec.deceased_name, rec.plot_code || 'N/A', dobFormatted, dodFormatted, rec.latitude, rec.longitude), { className: 'plotbox-popup' }).addTo(graveMarkerGroup);
            }
        });

        const allMapFeatures = L.featureGroup([layoutLayerGroup, graveMarkerGroup]);
        if (allMapFeatures.getLayers().length > 0) map.fitBounds(allMapFeatures.getBounds(), { padding: [40, 40] });

        function resetUserMapView() {
            if (allMapFeatures.getLayers().length > 0) map.fitBounds(allMapFeatures.getBounds(), { padding: [40, 40] });
        }

        // LIVE SEARCH SUGGESTIONS
        const mapSearchInput = document.getElementById('mapSearchInput');
        const suggestionsBox = document.getElementById('searchSuggestions');
        let suggestTimer = null;
        let searchSelectionMarker = null;

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        function formatRecDate(d) {
            return d ? new Date(d).toLocaleDateString('en-GB') : 'N/A';
        }

        function renderSuggestions(items) {
            suggestionsBox.innerHTML = '';
            if (!items.length) {
                suggestionsBox.innerHTML = '<div class="px-4 py-3 text-[11px] font-medium text-slate-400 dark:text-slate-500">No matching records found.</div>';
                suggestionsBox.classList.remove('hidden');
                return;
            }
            items.forEach(rec => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'w-full text-left px-3.5 py-2.5 hover:bg-slate-100 dark:hover:bg-slate-800/70 border-b border-slate-100 dark:border-slate-800 last:border-0 transition';
                item.innerHTML = `
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-cross text-cyan-600 dark:text-cyan-400 text-[10px] shrink-0"></i>
                        <span class="font-bold text-xs text-slate-900 dark:text-white truncate">${escapeHtml(rec.deceased_name)}</span>
                    </div>
                    <div class="mt-1 ml-[18px] flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10px] text-slate-500 dark:text-slate-400">
                        <span class="font-mono font-semibold text-cyan-700 dark:text-cyan-400"><i class="fa-solid fa-location-dot mr-1"></i>${escapeHtml(rec.plot_code || 'N/A')}</span>
                        <span><i class="fa-regular fa-calendar mr-1"></i>Born: ${formatRecDate(rec.date_of_birth)} · Died: ${formatRecDate(rec.date_of_death)}</span>
                    </div>
                `;
                item.addEventListener('click', () => selectSuggestion(rec));
                suggestionsBox.appendChild(item);
            });
            suggestionsBox.classList.remove('hidden');
        }

        function selectSuggestion(rec) {
            mapSearchInput.value = rec.deceased_name;
            suggestionsBox.classList.add('hidden');

            const lat = parseFloat(rec.latitude), lng = parseFloat(rec.longitude);
            if (!isNaN(lat) && !isNaN(lng)) {
                if (searchSelectionMarker) map.removeLayer(searchSelectionMarker);
                searchSelectionMarker = L.marker([lat, lng])
                    .addTo(map)
                    .bindPopup(createPlotboxPopupHTML(rec.deceased_name, rec.plot_code || 'N/A', formatRecDate(rec.date_of_birth), formatRecDate(rec.date_of_death), lat, lng), { className: 'plotbox-popup' });
                map.flyTo([lat, lng], 19, { animate: true });
                map.once('moveend', () => { if (searchSelectionMarker) searchSelectionMarker.openPopup(); });
            } else {
                mapSearchInput.form.submit();
            }
        }

        mapSearchInput.addEventListener('input', function () {
            const q = this.value.trim();
            clearTimeout(suggestTimer);
            if (q.length < 2) {
                suggestionsBox.classList.add('hidden');
                suggestionsBox.innerHTML = '';
                return;
            }
            suggestTimer = setTimeout(async () => {
                try {
                    const res = await fetch(`user_map.php?suggest=${encodeURIComponent(q)}`);
                    renderSuggestions(await res.json());
                } catch (e) {
                    suggestionsBox.classList.add('hidden');
                }
            }, 250);
        });

        mapSearchInput.addEventListener('keydown', e => {
            if (e.key === 'Escape') suggestionsBox.classList.add('hidden');
        });

        mapSearchInput.addEventListener('focus', () => {
            if (suggestionsBox.innerHTML.trim() !== '' && mapSearchInput.value.trim().length >= 2) {
                suggestionsBox.classList.remove('hidden');
            }
        });

        document.addEventListener('click', e => {
            if (!mapSearchInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
                suggestionsBox.classList.add('hidden');
            }
        });

        // DATE-OF-DEATH FILTER TOGGLE
        const deathDateToggle = document.getElementById('deathDateToggle');
        const deathDateInput = document.getElementById('deathDateInput');
        if (deathDateToggle && deathDateInput) {
            const openDeathDatePicker = () => {
                requestAnimationFrame(() => {
                    void deathDateInput.offsetWidth;
                    if (typeof deathDateInput.showPicker === 'function') {
                        try { deathDateInput.showPicker(); } catch (e) { deathDateInput.focus(); }
                    } else {
                        deathDateInput.focus();
                    }
                });
            };
            const toggleDeathDate = () => {
                if (deathDateInput.classList.contains('hidden')) {
                    deathDateInput.classList.remove('hidden');
                    openDeathDatePicker();
                } else {
                    deathDateInput.classList.add('hidden');
                }
            };
            deathDateToggle.addEventListener('pointerdown', e => {
                e.preventDefault();
                toggleDeathDate();
            });
            deathDateToggle.addEventListener('click', e => {
                if (e.detail === 0) toggleDeathDate();
            });
            const collapseDeathDate = () => deathDateInput.classList.add('hidden');
            deathDateInput.addEventListener('blur', collapseDeathDate);
            deathDateInput.addEventListener('cancel', collapseDeathDate);
        }

        // NAVIGATION ENGINE
        let navigationLine = null, userLocationMarker = null, destinationMarker = null;

        // VOICE GUIDANCE (Web Speech API)
        function speakGuidance(text) {
            if (!('speechSynthesis' in window)) {
                console.warn('Voice guidance is not supported in this browser.');
                return;
            }
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.rate = 1;
            utterance.pitch = 1;
            utterance.volume = 1;
            utterance.lang = 'en-US';
            const voices = window.speechSynthesis.getVoices();
            const preferredVoice = voices.find(v => v.lang.startsWith('en') && v.localService) || voices.find(v => v.lang.startsWith('en')) || voices[0];
            if (preferredVoice) utterance.voice = preferredVoice;
            window.speechSynthesis.speak(utterance);
        }

        // Warm up the voice list (some browsers load voices asynchronously)
        if ('speechSynthesis' in window) {
            window.speechSynthesis.getVoices();
            window.speechSynthesis.onvoiceschanged = () => window.speechSynthesis.getVoices();
        }

        function formatDistance(meters) {
            if (meters >= 1000) {
                const km = (meters / 1000).toFixed(1).replace(/\.0$/, '');
                return `${km} kilometer${km === '1' ? '' : 's'}`;
            }
            return `${meters} meter${meters === 1 ? '' : 's'}`;
        }

        function navigateToPlotWrapper(lat, lng, name, plotCode, dod) {
            map.flyTo([lat, lng], 19, { animate: true });
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(position => {
                    const userLat = position.coords.latitude, userLng = position.coords.longitude;
                    if (userLocationMarker) map.removeLayer(userLocationMarker);
                    userLocationMarker = L.circleMarker([userLat, userLng], { radius: 8, fillColor: '#06b6d4', color: '#ffffff', weight: 3, fillOpacity: 1 }).addTo(map);

                    // Road routing via OSRM, but the drawn path always ends exactly at the plot
                    if (navigationLine) map.removeLayer(navigationLine);
                    if (destinationMarker) map.removeLayer(destinationMarker);

                    const userLatLng = L.latLng(userLat, userLng);
                    const plotLatLng = L.latLng(lat, lng);

                    destinationMarker = L.circleMarker(plotLatLng, {
                        radius: 10, fillColor: '#10b981', color: '#ffffff', weight: 3, fillOpacity: 1
                    }).addTo(map);

                    const straightDist = userLatLng.distanceTo(plotLatLng);
                    const MAX_VOICE_RANGE = 1000000;
                    const ARRIVED_THRESHOLD = 30;

                    if (straightDist <= ARRIVED_THRESHOLD) {
                        speakGuidance(`You have already arrived at ${name}, plot ${plotCode}.`);
                        return;
                    }

                    const router = L.Routing.osrmv1({ serviceUrl: 'https://router.project-osrm.org/route/v1' });
                    router.route(
                        [L.Routing.waypoint(userLatLng), L.Routing.waypoint(plotLatLng)],
                        (err, routes) => {
                            if (err || !routes || !routes.length) {
                                // Fallback: straight walking path if the road route fails
                                navigationLine = L.polyline([userLatLng, plotLatLng], {
                                    color: '#06b6d4', weight: 5, opacity: 0.85, dashArray: '10, 10'
                                }).addTo(map);
                                map.fitBounds(L.latLngBounds([userLatLng, plotLatLng]), { padding: [60, 60] });
                                if (straightDist <= MAX_VOICE_RANGE) {
                                    const fallbackDist = formatDistance(Math.round(straightDist));
                                    speakGuidance(`Navigating to ${name}, plot ${plotCode}. The grave is approximately ${fallbackDist} away. Please follow the highlighted path on the map.`);
                                }
                                return;
                            }

                            const route = routes[0];
                            // Trim the road geometry at the point closest to the plot, then
                            // connect straight to the exact grave location. This prevents the
                            // path from overshooting down the road when OSRM snaps the
                            // destination to a street point past the cemetery entrance.
                            const coords = route.coordinates;
                            let closestIdx = coords.length - 1;
                            let closestDist = Infinity;
                            coords.forEach((c, i) => {
                                const d = plotLatLng.distanceTo(L.latLng(c.lat, c.lng));
                                if (d < closestDist) { closestDist = d; closestIdx = i; }
                            });
                            const pathCoords = coords.slice(0, closestIdx + 1).map(c => L.latLng(c.lat, c.lng));
                            pathCoords.push(plotLatLng);

                            navigationLine = L.polyline(pathCoords, {
                                color: '#06b6d4', weight: 5, opacity: 0.85
                            }).addTo(map);

                            map.fitBounds(navigationLine.getBounds(), { padding: [60, 60] });

                            if (straightDist > MAX_VOICE_RANGE) {
                                return;
                            }

                            const distMeters = Math.round(route.summary.totalDistance);
                            const formattedDist = formatDistance(distMeters);
                            const turns = (route.instructions || [])
                                .filter(i => i.text && (i.text.toLowerCase().includes('left') || i.text.toLowerCase().includes('right')))
                                .map(i => i.text);
                            const turnMessage = turns.length ? ` Then, ${turns.join('. Then ')}.` : '';
                            speakGuidance(`Navigating to ${name}, plot ${plotCode}. The grave is approximately ${formattedDist} away by road.${turnMessage} Please follow the highlighted path on the map.`);
                        }
                    );
                }, () => {
                    speakGuidance('Unable to detect your current location. Please enable location services to use voice navigation.');
                });
            } else {
                speakGuidance('Geolocation is not supported on this device.');
            }
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
        });

        window.addEventListener('load', renderIcons);
    </script>
</body>
</html>