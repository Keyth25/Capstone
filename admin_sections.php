<?php
require 'config.php';

// Ensure public.sections has a section_type column for Apartment / Land classification
try {
    $pdo->exec("ALTER TABLE public.sections ADD COLUMN IF NOT EXISTS section_type VARCHAR(50) DEFAULT 'Land'");
} catch (PDOException $e) {
    error_log("Schema note: " . $e->getMessage());
}

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Handle AJAX Request: Save or Update Section Boundary/GeoJSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_section') {
    header('Content-Type: application/json');
    $section_name = trim($_POST['section_name'] ?? '');
    $section_code = strtoupper(trim($_POST['section_code'] ?? ''));
    $section_type = in_array($_POST['section_type'] ?? '', ['Land', 'Apartment', 'Mausoleum'], true) ? $_POST['section_type'] : 'Land';
    $color = trim($_POST['color'] ?? '#38bdf8');
    $geojson = trim($_POST['geojson'] ?? '');
    $section_id = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;

    if (empty($section_name) || empty($section_code) || empty($geojson)) {
        echo json_encode(['success' => false, 'error' => 'Please fill in all required fields and draw a boundary.']);
        exit();
    }

    try {
        if ($section_id) {
            $stmt = $pdo->prepare("UPDATE public.sections SET section_name = ?, section_code = ?, section_type = ?, color = ?, geojson = ? WHERE id = ?");
            $stmt->execute([$section_name, $section_code, $section_type, $color, $geojson, $section_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO public.sections (section_name, section_code, section_type, color, geojson) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$section_name, $section_code, $section_type, $color, $geojson]);
        }
        echo json_encode(['success' => true]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// Handle POST Request: Delete Section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_section') {
    $section_id = (int)($_POST['section_id'] ?? 0);
    if ($section_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM public.sections WHERE id = ?");
            $stmt->execute([$section_id]);
            $success_msg = "Section successfully deleted.";
        } catch (PDOException $e) {
            $error_msg = "Failed to delete section: " . $e->getMessage();
        }
    }
}

// Fetch all existing sections from DB
$sections = [];
try {
    $stmt = $pdo->query("SELECT id, section_name, section_code, section_type, color, geojson, created_at FROM public.sections ORDER BY created_at DESC");
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_msg = "Error loading sections: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Manage Sections - Holy Gardens Admin</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Leaflet & Leaflet Draw CSS/JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>

    <style>
        #map {
            height: 580px;
            width: 100%;
            background-color: #0b0f19;
            border-radius: 12px;
        }

        /* REMOVE BLACK FOCUS BOX OUTLINE WHEN CLICKING SECTIONS OR PATHS */
        .leaflet-interactive:focus,
        path.leaflet-interactive:focus,
        svg:focus,
        .leaflet-container:focus {
            outline: none !important;
            box-shadow: none !important;
        }

        .plotbox-popup .leaflet-popup-content-wrapper {
            background: #0f172a;
            color: #f8fafc;
            border-radius: 8px;
            padding: 0;
            overflow: hidden;
            border: 1px solid #334155;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.6);
        }
        .plotbox-popup .leaflet-popup-content {
            margin: 0;
            width: 220px !important;
        }
        .plotbox-popup .leaflet-popup-tip {
            background: #0f172a;
        }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <script src="mobile.js" defer></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col font-sans">

    <!-- HEADER BAR -->
    <header class="sticky top-0 bg-slate-900 border-b border-slate-800 px-6 py-3 flex items-center justify-between z-30 shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 bg-sky-600 rounded flex items-center justify-center font-black text-white text-base">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <div>
                <span class="font-extrabold text-white tracking-wider text-lg">SECTION<span class="text-sky-500">MANAGER</span></span>
                <span class="bg-sky-950/80 text-sky-400 text-[10px] font-bold px-2 py-0.5 rounded border border-sky-800 ml-2 uppercase">Admin Map Control</span>
            </div>
        </div>

        <div class="flex items-center gap-3 text-xs">
            <a href="admin_dashboard.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 px-3.5 py-1.5 rounded font-bold transition flex items-center gap-1.5">
                <i class="fa-solid fa-arrow-left text-sky-400"></i> Admin Panel
            </a>
            <span class="text-slate-400 hidden sm:inline"><i class="fa-solid fa-user-shield mr-1 text-slate-300"></i> <?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></span>
            <button id="themeToggle" type="button" onclick="if(typeof toggleTheme==='function'){toggleTheme(); const i=this.querySelector('i'); i.className=document.documentElement.classList.contains('dark')?'fa-solid fa-moon text-cyan-400 text-sm':'fa-solid fa-sun text-amber-400 text-sm';}" class="p-2 rounded bg-slate-800 border border-slate-700 text-slate-400 hover:text-white transition" title="Toggle Theme">
                <i class="fa-solid fa-moon text-sm"></i>
            </button>
            <script>document.getElementById('themeToggle').querySelector('i').className=document.documentElement.classList.contains('dark')?'fa-solid fa-moon text-cyan-400 text-sm':'fa-solid fa-sun text-amber-400 text-sm';</script>
            <a href="logout.php" class="bg-slate-800 hover:bg-red-600 text-slate-300 hover:text-white px-3 py-1.5 rounded transition font-semibold">
                <i class="fa-solid fa-right-from-bracket mr-1"></i> Logout
            </a>
        </div>
    </header>

    <!-- CONTENT BODY -->
    <main class="flex-1 p-4 md:p-6 max-w-[1500px] w-full mx-auto space-y-4">

        <?php if ($success_msg): ?>
            <div class="p-3 bg-emerald-950/90 border border-emerald-800 text-emerald-300 text-xs rounded-lg flex justify-between items-center shadow-lg">
                <span><i class="fa-solid fa-circle-check mr-1.5 text-emerald-400"></i> <?= htmlspecialchars($success_msg) ?></span>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white text-base">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="p-3 bg-red-950/90 border border-red-800 text-red-300 text-xs rounded-lg flex justify-between items-center shadow-lg">
                <span><i class="fa-solid fa-circle-exclamation mr-1.5 text-red-400"></i> <?= htmlspecialchars($error_msg) ?></span>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white text-base">&times;</button>
            </div>
        <?php endif; ?>

        <!-- MAIN LAYOUT GRID -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">

            <!-- LEFT: INTERACTIVE MAP EDITOR (7 COLS) -->
            <div class="lg:col-span-7 flex flex-col space-y-4">
                <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl shadow-lg space-y-3">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                        <div>
                            <h2 class="text-base font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-draw-polygon text-sky-400"></i> Draw & Define Section Boundaries
                            </h2>
                            <p class="text-xs text-slate-400">Use the Leaflet toolbar to draw polygon overlays for cemetery sections.</p>
                        </div>
                        <button onclick="resetMapOverview()" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs px-3 py-1.5 rounded-lg font-bold text-slate-200 transition flex items-center gap-1.5 shrink-0">
                            <i class="fa-solid fa-compress text-sky-400"></i> Reset Zoom
                        </button>
                    </div>

                    <div id="map" class="shadow-inner border border-slate-800 relative z-10"></div>
                </div>
            </div>

            <!-- RIGHT: SECTION FORM & MANAGEMENT LIST (5 COLS) -->
            <div class="lg:col-span-5 space-y-4">

                <!-- SECTION EDIT / CREATE FORM -->
                <div class="bg-slate-900 border border-slate-800 p-5 rounded-xl shadow-lg space-y-4">
                    <div class="border-b border-slate-800 pb-3 flex justify-between items-center">
                        <div>
                            <h3 id="formTitle" class="text-sm font-bold text-sky-400 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-plus-circle"></i> Add New Section
                            </h3>
                            <p class="text-xs text-slate-400 mt-0.5">Draw a polygon on the map to automatically generate GeoJSON coordinates.</p>
                        </div>
                        <button type="button" id="resetFormBtn" onclick="resetForm()" class="hidden text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 px-2.5 py-1 rounded border border-slate-700 font-semibold transition">
                            Cancel Edit
                        </button>
                    </div>

                    <form id="sectionForm" onsubmit="handleFormSubmit(event)" class="space-y-3.5">
                        <input type="hidden" id="section_id" name="section_id" value="">

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Section Name</label>
                            <input type="text" id="section_name" name="section_name" required placeholder="e.g. Section A, North Lawn..." class="w-full bg-slate-950 border border-slate-700 rounded-lg p-2.5 text-xs text-slate-100 focus:outline-none focus:border-sky-500">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Section Type</label>
                            <select id="section_type" name="section_type" required class="w-full bg-slate-950 border border-slate-700 rounded-lg p-2.5 text-xs text-slate-100 focus:outline-none focus:border-sky-500">
                                <option value="Land" selected>Land Section</option>
                                <option value="Apartment">Apartment Section</option>
                                <option value="Mausoleum">Mausoleum Section</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Section Prefix/Code</label>
                                <input type="text" id="section_code" name="section_code" required placeholder="e.g. A, B, SEC-1" class="w-full bg-slate-950 border border-slate-700 rounded-lg p-2.5 text-xs text-sky-400 font-mono font-bold uppercase focus:outline-none focus:border-sky-500">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Boundary Color</label>
                                <input type="color" id="color" name="color" value="#38bdf8" class="w-full h-9 bg-slate-950 border border-slate-700 rounded-lg p-1 cursor-pointer">
                            </div>
                        </div>

                        <input type="hidden" id="geojson" name="geojson">

                        <button type="submit" id="saveBtn" class="w-full py-2.5 bg-sky-600 hover:bg-sky-500 text-white font-bold text-xs rounded-lg shadow-md transition flex items-center justify-center gap-1.5">
                            <i class="fa-solid fa-floppy-disk"></i> Save Section Boundary
                        </button>
                    </form>
                </div>

                <!-- EXISTING SECTIONS LIST -->
                <div class="bg-slate-900 border border-slate-800 p-5 rounded-xl shadow-lg space-y-3">
                    <h3 class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center gap-2 border-b border-slate-800 pb-2">
                        <i class="fa-solid fa-list text-sky-400"></i> Configured Sections (<?= count($sections) ?>)
                    </h3>

                    <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
                        <?php if (empty($sections)): ?>
                            <div class="text-center py-6 text-slate-500 text-xs">No sections configured yet.</div>
                        <?php else: ?>
                            <?php foreach ($sections as $sec): ?>
                                <div class="bg-slate-950 border border-slate-800 p-3 rounded-lg flex items-center justify-between hover:border-slate-700 transition">
                                    <div class="flex items-center gap-3">
                                        <div class="w-4 h-4 rounded-full border shrink-0" style="background-color: <?= htmlspecialchars($sec['color']) ?>; border-color: <?= htmlspecialchars($sec['color']) ?>;"></div>
                                        <div>
                                            <div class="text-xs font-bold text-white"><?= htmlspecialchars($sec['section_name']) ?></div>
                                            <div class="text-[10px] text-sky-400 font-mono">Code: <?= htmlspecialchars($sec['section_code']) ?></div>
                                            <?php $secTypeLower = strtolower($sec['section_type'] ?? 'Land'); ?>
                                            <div class="text-[10px] font-bold <?= $secTypeLower === 'apartment' ? 'text-violet-400' : ($secTypeLower === 'mausoleum' ? 'text-amber-400' : 'text-emerald-400') ?>">
                                                <?= htmlspecialchars($sec['section_type'] ?? 'Land') ?> Section
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <button type="button" onclick='editSection(<?= json_encode($sec) ?>)' class="px-2 py-1 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sky-400 text-[10px] font-bold rounded transition">
                                            Edit
                                        </button>
                                        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this section?');" class="inline">
                                            <input type="hidden" name="action" value="delete_section">
                                            <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                                            <button type="submit" class="px-2 py-1 bg-slate-800 hover:bg-red-900 border border-slate-700 hover:border-red-700 text-red-400 text-[10px] font-bold rounded transition">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>

    </main>

    <!-- MAP & INTERACTIVITY SCRIPTS -->
    <script>
        const savedSections = <?= json_encode($sections) ?>;
        let map, drawnItems, drawControl;
        let activePoly = null;

        document.addEventListener('DOMContentLoaded', function () {
            const mapCenter = [6.2201, 125.0645];
            map = L.map('map', { zoomControl: false }).setView(mapCenter, 18);

            L.control.zoom({ position: 'bottomright' }).addTo(map);

            const googleSatTiles = L.tileLayer('https://mt1.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}', {
                maxZoom: 22,
                subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                attribution: '&copy; Google Maps'
            }).addTo(map);

            const googleStreetTiles = L.tileLayer('https://mt1.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
                maxZoom: 20,
                subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                attribution: '&copy; Google Maps'
            });

            L.control.layers({
                "Google Satellite": googleSatTiles,
                "Google Streets": googleStreetTiles
            }, null, { position: 'topright' }).addTo(map);

            // Leaflet Draw Layer
            drawnItems = new L.FeatureGroup();
            map.addLayer(drawnItems);

            drawControl = new L.Control.Draw({
                draw: {
                    polygon: {
                        allowIntersection: false,
                        showArea: true,
                        shapeOptions: {
                            color: '#38bdf8',
                            fillOpacity: 0
                        }
                    },
                    polyline: false,
                    circle: false,
                    rectangle: false,
                    marker: false,
                    circlemarker: false
                },
                edit: {
                    featureGroup: drawnItems,
                    remove: true
                }
            });
            map.addControl(drawControl);

            // Event listener when a new polygon is drawn
            map.on(L.Draw.Event.CREATED, function (e) {
                const layer = e.layer;
                drawnItems.clearLayers();
                drawnItems.addLayer(layer);

                const geojsonData = layer.toGeoJSON();
                document.getElementById('geojson').value = JSON.stringify(geojsonData.geometry);
            });

            map.on(L.Draw.Event.EDITED, function (e) {
                const layers = e.layers;
                layers.eachLayer(function (layer) {
                    const geojsonData = layer.toGeoJSON();
                    document.getElementById('geojson').value = JSON.stringify(geojsonData.geometry);
                });
            });

            map.on(L.Draw.Event.DELETED, function () {
                document.getElementById('geojson').value = '';
            });

            // Display existing sections from DB
            const layoutGroup = L.featureGroup().addTo(map);

            savedSections.forEach(item => {
                try {
                    if (!item.geojson) return;
                    let geojson = typeof item.geojson === 'string' ? JSON.parse(item.geojson) : item.geojson;
                    const strokeColor = item.color || '#38bdf8';

                    // NO BLUE HIGHLIGHT: fillOpacity is set to 0 and fillColor is transparent
                    const sectionLayer = L.geoJSON(geojson, {
                        interactive: true,
                        style: {
                            color: strokeColor,
                            weight: 2,
                            opacity: 0.9,
                            fillColor: 'transparent',
                            fillOpacity: 0
                        }
                    });

                    sectionLayer.eachLayer(subLayer => {
                        subLayer.bindTooltip(`<b>${item.section_name}</b><br>Code: ${item.section_code}`, {
                            permanent: false,
                            sticky: true,
                            direction: 'top',
                            className: 'bg-slate-900 border border-slate-700 text-sky-300 text-xs font-bold px-2 py-1 rounded shadow-xl'
                        });

                        subLayer.on('click', (e) => {
                            if (e && e.originalEvent) L.DomEvent.stopPropagation(e);

                            // Keep fill transparent upon clicking (no blue fill highlight)
                            if (activePoly) activePoly.setStyle({ fillOpacity: 0, weight: 2 });
                            activePoly = subLayer;
                            subLayer.setStyle({ fillOpacity: 0, weight: 3 });

                            editSection(item);
                        });

                        layoutGroup.addLayer(subLayer);
                    });
                } catch (e) {
                    console.error("GIS parse error:", e);
                }
            });

            if (layoutGroup.getLayers().length > 0) {
                map.fitBounds(layoutGroup.getBounds(), { padding: [30, 30] });
            }

            window.resetMapOverview = function () {
                if (activePoly) activePoly.setStyle({ fillOpacity: 0, weight: 2 });
                if (layoutGroup.getLayers().length > 0) {
                    map.flyToBounds(layoutGroup.getBounds(), { padding: [30, 30] });
                } else {
                    map.flyTo(mapCenter, 18, { duration: 0.8 });
                }
            };
        });

        function editSection(sec) {
            document.getElementById('formTitle').innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Edit Section: ${sec.section_name}`;
            document.getElementById('section_id').value = sec.id;
            document.getElementById('section_name').value = sec.section_name;
            document.getElementById('section_code').value = sec.section_code;
            document.getElementById('section_type').value = ['Land', 'Apartment', 'Mausoleum'].includes(sec.section_type) ? sec.section_type : 'Land';
            document.getElementById('color').value = sec.color || '#38bdf8';

            let geojsonStr = typeof sec.geojson === 'object' ? JSON.stringify(sec.geojson) : sec.geojson;
            document.getElementById('geojson').value = geojsonStr;

            document.getElementById('resetFormBtn').classList.remove('hidden');

            // Draw section polygon on edit layer
            try {
                drawnItems.clearLayers();
                let geoObj = typeof sec.geojson === 'string' ? JSON.parse(sec.geojson) : sec.geojson;
                let layer = L.geoJSON(geoObj, {
                    style: {
                        color: sec.color || '#38bdf8',
                        fillOpacity: 0
                    }
                });
                layer.eachLayer(l => drawnItems.addLayer(l));
                map.flyToBounds(layer.getBounds(), { duration: 0.8, padding: [40, 40] });
            } catch (e) {
                console.error("Error drawing edit section layer:", e);
            }
        }

        function resetForm() {
            document.getElementById('formTitle').innerHTML = `<i class="fa-solid fa-plus-circle"></i> Add New Section`;
            document.getElementById('section_id').value = '';
            document.getElementById('sectionForm').reset();
            document.getElementById('resetFormBtn').classList.add('hidden');
            drawnItems.clearLayers();
        }

        function handleFormSubmit(e) {
            e.preventDefault();
            const form = document.getElementById('sectionForm');
            if (!document.getElementById('geojson').value) {
                alert('Please draw a polygon on the map first.');
                return;
            }
            const formData = new FormData(form);
            formData.append('action', 'save_section');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Failed to save section.');
                }
            })
            .catch(() => alert('An error occurred while saving section.'));
        }

        // Sidebar toggle function for mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        }
    </script>
</body>
</html>