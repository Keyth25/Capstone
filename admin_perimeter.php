<?php
require 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$error = '';

// 1. Handle Saving Main Boundary
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_main_boundary') {
    $title   = trim($_POST['boundary_title'] ?? 'Main Cemetery Perimeter Boundary');
    $geojson = trim($_POST['geojson_data']);
    $color   = trim($_POST['boundary_color'] ?? '#ef4444');

    try {
        $pdo->exec("ALTER TABLE public.cemetery_layouts ADD COLUMN IF NOT EXISTS section_id INT NULL;");
        $pdo->exec("DELETE FROM public.cemetery_layouts WHERE section_id IS NULL");

        $stmt = $pdo->prepare("INSERT INTO public.cemetery_layouts (title, shape_type, geojson, color, section_id) VALUES (?, 'polygon', ?, ?, NULL)");
        $stmt->execute([$title, $geojson, $color]);
        $message = "Perimeter Boundary '{$title}' saved successfully to database! Now visible on all user and staff maps.";
    } catch (PDOException $e) { 
        $error = "Boundary Error: " . $e->getMessage(); 
    }
}

// 2. Fetch Existing Main Perimeter Boundary on Load
$existing_boundary = null;
try {
    $stmt = $pdo->query("SELECT * FROM public.cemetery_layouts WHERE section_id IS NULL ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $existing_boundary = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $existing_boundary = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Perimeter - PlotBox GIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>
    <style>#adminMap { height: 100%; width: 100%; }</style>
    <link rel="stylesheet" href="mobile.css">
    <script src="mobile.js" defer></script>
</head>
<body class="bg-slate-950 text-slate-100 h-screen flex font-sans overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="bg-slate-900 border-b border-slate-800 px-6 py-3 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg bg-slate-800 text-slate-300 hover:text-white transition" aria-label="Open menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h1 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-vector-square text-red-400"></i> Cemetery Perimeter Tool
                </h1>
            </div>
        </header>

        <div class="flex-1 flex flex-col lg:flex-row overflow-hidden">
            <div class="w-full lg:w-96 max-h-[45vh] lg:max-h-none p-4 bg-slate-900/60 border-b lg:border-b-0 lg:border-r border-slate-800 flex flex-col space-y-3 shrink-0 overflow-y-auto">
                <?php if ($message): ?>
                    <div class="p-3 bg-emerald-950 border border-emerald-800 text-emerald-300 text-xs rounded-lg flex items-center gap-2">
                        <i class="fa-solid fa-circle-check text-emerald-400"></i>
                        <div>
                            <strong class="text-emerald-200">Success!</strong>
                            <div class="text-emerald-300"><?= htmlspecialchars($message) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="p-3 bg-red-950 border border-red-800 text-red-300 text-xs rounded-lg flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-red-400"></i>
                        <div>
                            <strong class="text-red-200">Error!</strong>
                            <div class="text-red-300"><?= htmlspecialchars($error) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="bg-slate-950 border border-slate-800 rounded-lg p-3 space-y-3">
                    <span class="text-[10px] font-bold text-red-400 uppercase tracking-wider block"><i class="fa-solid fa-draw-polygon mr-1"></i> Outer Perimeter Mode</span>
                    <p class="text-[11px] text-slate-400">Use the Leaflet draw controls on the map to define the primary cemetery boundary. <b>Double-click</b> or click the start point to finish drawing.</p>

                    <form method="POST" action="admin_perimeter.php" class="space-y-3">
                        <input type="hidden" name="action" value="save_main_boundary">
                        <input type="hidden" name="geojson_data" id="main_geojson_data" value='<?= htmlspecialchars($existing_boundary['geojson'] ?? '', ENT_QUOTES) ?>' required>

                        <div>
                            <label class="text-[10px] text-slate-400 block mb-1">Perimeter Boundary Title</label>
                            <input type="text" name="boundary_title" value="<?= htmlspecialchars($existing_boundary['title'] ?? 'Main Cemetery Perimeter Boundary') ?>" required class="w-full bg-slate-950 border border-slate-700 rounded p-1.5 text-xs text-white focus:outline-none">
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-400 block mb-1">Line / Border Color</label>
                            <input type="color" name="boundary_color" value="<?= htmlspecialchars($existing_boundary['color'] ?? '#ef4444') ?>" class="w-full h-8 bg-slate-950 border border-slate-700 rounded p-1 cursor-pointer">
                        </div>

                        <button type="submit" id="saveBoundaryBtn" class="w-full py-1.5 bg-red-600 hover:bg-red-500 text-white font-bold text-xs rounded transition flex items-center justify-center gap-1">
                            <i class="fa-solid fa-save"></i> <span id="saveBoundaryText">Save Boundary</span>
                            <span id="saveBoundarySpinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
                        </button>
                        <div class="text-center mt-1">
                            <small class="text-slate-500"><i class="fa-solid fa-database me-1"></i> Data will be saved to PostgreSQL and synced to all user views</small>
                        </div>
                    </form>
                </div>
            </div>

            <div class="flex-1 relative min-h-[320px]">
                <div id="adminMap"></div>

                <!-- Basemap Switcher Controls -->
                <div class="absolute top-3 right-3 z-[1000] bg-slate-900/90 border border-slate-700 rounded p-1 flex gap-1 text-[11px] shadow-lg">
                    <button onclick="setBaseMap('satellite')" id="btnSat" class="px-2.5 py-1 rounded bg-red-600 text-white font-bold transition">
                        <i class="fa-solid fa-earth-americas mr-1"></i> Satellite
                    </button>
                    <button onclick="setBaseMap('street')" id="btnStreet" class="px-2.5 py-1 rounded text-slate-300 hover:bg-slate-800 transition">
                        <i class="fa-solid fa-map mr-1"></i> Street Map
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const map = L.map('adminMap').setView([6.2201, 125.0647], 18);

        // Tile Layers
        const satelliteTile = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
            maxNativeZoom: 20,
            maxZoom: 22,
            attribution: 'Google Maps Satellite'
        }).addTo(map);

        const streetTile = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxNativeZoom: 19,
            maxZoom: 22,
            attribution: 'OpenStreetMap'
        });

        // Basemap Switcher Handler
        function setBaseMap(type) {
            if (type === 'satellite') {
                map.removeLayer(streetTile);
                map.addLayer(satelliteTile);
                document.getElementById('btnSat').className = 'px-2.5 py-1 rounded bg-red-600 text-white font-bold transition';
                document.getElementById('btnStreet').className = 'px-2.5 py-1 rounded text-slate-300 hover:bg-slate-800 transition';
            } else {
                map.removeLayer(satelliteTile);
                map.addLayer(streetTile);
                document.getElementById('btnStreet').className = 'px-2.5 py-1 rounded bg-red-600 text-white font-bold transition';
                document.getElementById('btnSat').className = 'px-2.5 py-1 rounded text-slate-300 hover:bg-slate-800 transition';
            }
        }

        const drawnItems = new L.FeatureGroup().addTo(map);

        // Render existing boundary with NO fill highlight (outline only)
        const existingBoundaryData = <?= json_encode($existing_boundary) ?>;
        if (existingBoundaryData && existingBoundaryData.geojson) {
            try {
                const geoJsonObj = typeof existingBoundaryData.geojson === 'string' ? JSON.parse(existingBoundaryData.geojson) : existingBoundaryData.geojson;
                const strokeColor = existingBoundaryData.color || '#ef4444';

                const loadedLayer = L.geoJSON(geoJsonObj, {
                    style: {
                        color: strokeColor,
                        weight: 3,
                        opacity: 1,
                        fill: false,
                        fillOpacity: 0
                    }
                });

                loadedLayer.eachLayer(layer => {
                    drawnItems.addLayer(layer);
                });

                // Auto-fit map to boundary
                map.fitBounds(drawnItems.getBounds(), { padding: [30, 30] });
            } catch (e) {
                console.error("Error rendering boundary:", e);
            }
        }

        // Draw Options Configuration - Line shape with no fill
        const drawControl = new L.Control.Draw({
            draw: {
                polygon: {
                    allowIntersection: false,
                    showArea: true,
                    snapDistance: 25,
                    shapeOptions: {
                        color: '#ef4444',
                        weight: 3,
                        fill: false,
                        fillOpacity: 0
                    },
                    drawError: {
                        color: '#e11d48',
                        message: '<strong>Error:</strong> Lines cannot cross!'
                    }
                },
                rectangle: true,
                polyline: false,
                circle: false,
                marker: false,
                circlemarker: false
            },
            edit: {
                featureGroup: drawnItems
            }
        });
        map.addControl(drawControl);

        // Capture new polygon creation and enforce line-only styling
        map.on(L.Draw.Event.CREATED, function (e) {
            drawnItems.clearLayers();
            
            const layer = e.layer;
            if (layer.setStyle) {
                layer.setStyle({
                    fill: false,
                    fillOpacity: 0
                });
            }

            drawnItems.addLayer(layer);
            document.getElementById('main_geojson_data').value = JSON.stringify(layer.toGeoJSON().geometry);
        });

        // Update GeoJSON payload when existing polygon is edited or deleted
        map.on(L.Draw.Event.EDITED, function (e) {
            e.layers.eachLayer(function (layer) {
                document.getElementById('main_geojson_data').value = JSON.stringify(layer.toGeoJSON().geometry);
            });
        });

        map.on(L.Draw.Event.DELETED, function () {
            document.getElementById('main_geojson_data').value = '';
        });

        // Add form submission handler with loading state
        document.querySelector('form[action="admin_perimeter.php"]').addEventListener('submit', function(e) {
            const saveBtn = document.getElementById('saveBoundaryBtn');
            const saveBtnText = document.getElementById('saveBoundaryText');
            const saveSpinner = document.getElementById('saveBoundarySpinner');

            // Show loading state
            saveBtn.disabled = true;
            saveBtnText.textContent = 'Saving to Database...';
            saveSpinner.classList.remove('d-none');
        });

        // Sidebar toggle function for mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        }
    </script>
</body>
</html>