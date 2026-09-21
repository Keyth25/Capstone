<?php
require 'config.php';

// Ensure public.plots has all necessary fields including DOB and DOD
try {
    $pdo->exec("ALTER TABLE public.plots
        ADD COLUMN IF NOT EXISTS section_code VARCHAR(50),
        ADD COLUMN IF NOT EXISTS block_number VARCHAR(50) DEFAULT 'Block A',
        ADD COLUMN IF NOT EXISTS plot_type VARCHAR(50) DEFAULT 'Single Depth',
        ADD COLUMN IF NOT EXISTS plot_tier VARCHAR(50) DEFAULT 'Standard',
        ADD COLUMN IF NOT EXISTS plot_size VARCHAR(50) DEFAULT '1.0m x 2.4m',
        ADD COLUMN IF NOT EXISTS price DECIMAL(10, 2) DEFAULT 1000.00,
        ADD COLUMN IF NOT EXISTS deceased_name VARCHAR(255),
        ADD COLUMN IF NOT EXISTS date_of_birth DATE,
        ADD COLUMN IF NOT EXISTS date_of_death DATE,
        ADD COLUMN IF NOT EXISTS reservation_date DATE,
        ADD COLUMN IF NOT EXISTS recommendations TEXT,
        ADD COLUMN IF NOT EXISTS recommendation_note TEXT,
        ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW()");
    $pdo->exec("UPDATE public.plots SET status = INITCAP(status) WHERE status IS NOT NULL");
    $pdo->exec("UPDATE public.plots SET plot_tier = 'Standard' WHERE plot_tier IS NULL OR plot_tier NOT IN ('Standard', 'Premium', 'Gold')");
    $pdo->exec("UPDATE public.plots p
        SET section_code = s.section_code,
            price = COALESCE(s.price, 1000.00)
        FROM public.sections s
        WHERE p.section_id = s.id
          AND (p.section_code IS NULL OR p.price IS NULL)");
    $pdo->exec("ALTER TABLE public.sections
        ADD COLUMN IF NOT EXISTS section_type VARCHAR(50) DEFAULT 'Land', 
        ADD COLUMN IF NOT EXISTS apt_levels INTEGER,
        ADD COLUMN IF NOT EXISTS apt_per_level INTEGER");
    $pdo->exec("ALTER TABLE public.deceased_records
        ADD COLUMN IF NOT EXISTS sex VARCHAR(20),
        ADD COLUMN IF NOT EXISTS civil_status VARCHAR(50),
        ADD COLUMN IF NOT EXISTS nationality VARCHAR(100),
        ADD COLUMN IF NOT EXISTS address TEXT,
        ADD COLUMN IF NOT EXISTS place_of_birth VARCHAR(255),
        ADD COLUMN IF NOT EXISTS place_of_death VARCHAR(255),
        ADD COLUMN IF NOT EXISTS cause_of_death VARCHAR(255),
        ADD COLUMN IF NOT EXISTS death_certificate VARCHAR(255)");
} catch (PDOException $e) {
    error_log("Schema note: " . $e->getMessage());
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. CREATE SINGLE PLOT (MANUAL DRAW)
    if ($_POST['action'] === 'create_plot') {
        $plot_number   = trim($_POST['plot_number'] ?? '');
        $raw_status    = trim($_POST['status'] ?? 'available');
        $status        = ucwords($raw_status);
        $section_id    = !empty($_POST['section_id']) ? intval($_POST['section_id']) : null;
        $latitude      = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
        $longitude     = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
        $geojson_shape = !empty($_POST['geojson_shape']) ? $_POST['geojson_shape'] : null;
        $plot_area     = !empty($_POST['plot_area']) ? floatval($_POST['plot_area']) : null;
        $plot_type     = in_array($_POST['plot_type'] ?? '', ['Single Depth', 'Double Depth', 'Apartment', 'Lawn Lot', 'Mausoleum'], true) ? $_POST['plot_type'] : 'Single Depth';
        $plot_tier     = in_array($_POST['plot_tier'] ?? '', ['Standard', 'Premium', 'Gold'], true) ? $_POST['plot_tier'] : 'Standard';

        if (!$latitude || !$longitude || !$geojson_shape || !$plot_number || !$section_id) {
            $error = "Please draw a plot shape, select a section, and provide a Plot Code.";
        } else {
            try {
                $secStmt = $pdo->prepare("SELECT section_code, price FROM public.sections WHERE id = ?");
                $secStmt->execute([$section_id]);
                $section = $secStmt->fetch(PDO::FETCH_ASSOC);
                $section_code = $section['section_code'] ?? '';
                $price = $section['price'] ?? 1000.00;
                $posted_price = !empty($_POST['price']) ? floatval($_POST['price']) : 0;
                if ($posted_price > 0) {
                    $price = $posted_price;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO public.plots (plot_number, status, section_id, section_code, latitude, longitude, geojson_shape, plot_area, price, plot_type, plot_tier)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$plot_number, $status, $section_id, $section_code, $latitude, $longitude, $geojson_shape, $plot_area, $price, $plot_type, $plot_tier]);
                $message = "Plot created successfully!";
            } catch (PDOException $e) {
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }

    // 2. CREATE GRID PLOTS (ROWS & COLUMNS WITH ROTATION & DRAG POSITIONING)
    if ($_POST['action'] === 'create_grid_plots') {
        $section_id   = !empty($_POST['section_id']) ? intval($_POST['section_id']) : null;
        $prefix       = trim($_POST['prefix'] ?? 'P');
        $rows         = intval($_POST['rows'] ?? 0);
        $cols         = intval($_POST['cols'] ?? 0);
        $grid_json    = $_POST['grid_json'] ?? '';
        $plot_type    = in_array($_POST['plot_type'] ?? '', ['Single Depth', 'Double Depth', 'Apartment', 'Lawn Lot', 'Mausoleum'], true) ? $_POST['plot_type'] : 'Single Depth';
        $plot_tier    = in_array($_POST['plot_tier'] ?? '', ['Standard', 'Premium', 'Gold'], true) ? $_POST['plot_tier'] : 'Standard';

        if (!$section_id || $rows < 1 || $cols < 1 || empty($grid_json)) {
            $error = "Please define bounding area, set rows/columns, and select a section.";
        } else {
            try {
                $secStmt = $pdo->prepare("SELECT section_code, price FROM public.sections WHERE id = ?");
                $secStmt->execute([$section_id]);
                $section = $secStmt->fetch(PDO::FETCH_ASSOC);
                $section_code = $section['section_code'] ?? '';
                $price = $section['price'] ?? 1000.00;
                $posted_price = !empty($_POST['price']) ? floatval($_POST['price']) : 0;
                if ($posted_price > 0) {
                    $price = $posted_price;
                }

                $gridData = json_decode($grid_json, true);

                if (is_array($gridData)) {
                    $pdo->beginTransaction();

                    // Generate continuous plot numbers for this section/prefix
                    $usedStmt = $pdo->prepare("SELECT plot_number FROM public.plots WHERE section_id = ? AND plot_number LIKE ?");
                    $usedStmt->execute([$section_id, $prefix . '-%']);
                    $used_numbers = $usedStmt->fetchAll(PDO::FETCH_COLUMN);

                    $next = 1;
                    $total = count($gridData);
                    $generated = [];
                    while (count($generated) < $total) {
                        $candidate = $prefix . '-P' . $next;
                        if (!in_array($candidate, $used_numbers, true)) {
                            $generated[] = $candidate;
                        }
                        $next++;
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO public.plots (plot_number, status, section_id, section_code, latitude, longitude, geojson_shape, plot_area, price, plot_type, plot_tier)
                        VALUES (?, 'Available', ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($gridData as $i => $cell) {
                        $plot_number = $generated[$i];
                        $stmt->execute([
                            $plot_number,
                            $section_id,
                            $section_code,
                            $cell['lat'],
                            $cell['lng'],
                            json_encode($cell['geojson']),
                            $cell['area'],
                            $price,
                            $plot_type,
                            $plot_tier
                        ]);
                    }
                    $pdo->commit();
                    $message = count($gridData) . " grid plots generated successfully!";
                } else {
                    $error = "Invalid grid data structure.";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }

    // 2.5 CREATE 3D APARTMENT NICHES (VERTICAL LEVELS, NO MAP SHAPE NEEDED)
    if ($_POST['action'] === 'create_apartment') {
        $section_id = !empty($_POST['section_id']) ? intval($_POST['section_id']) : null;
        $prefix     = trim($_POST['prefix'] ?? 'APT');
        $levels     = intval($_POST['levels'] ?? 0);
        $per_level  = intval($_POST['per_level'] ?? 0);
        $plot_tier  = in_array($_POST['plot_tier'] ?? '', ['Standard', 'Premium', 'Gold'], true) ? $_POST['plot_tier'] : 'Standard';

        if (!$section_id || $levels < 1 || $per_level < 1) {
            $error = "Please select a section and set the number of levels and niches per level.";
        } else {
            try {
                $secStmt = $pdo->prepare("SELECT section_code, price, geojson FROM public.sections WHERE id = ?");
                $secStmt->execute([$section_id]);
                $section = $secStmt->fetch(PDO::FETCH_ASSOC);
                if (!$section) {
                    throw new Exception("Selected section was not found.");
                }
                $section_code = $section['section_code'] ?? '';
                $price = $section['price'] ?? 1000.00;
                $posted_price = !empty($_POST['price']) ? floatval($_POST['price']) : 0;
                if ($posted_price > 0) {
                    $price = $posted_price;
                }

                // Anchor apartment niches at the centroid of the section's drawn shape
                $lat = null; $lng = null;
                if (!empty($section['geojson'])) {
                    $geo = json_decode($section['geojson'], true);
                    $pts = [];
                    $walk = function ($node) use (&$walk, &$pts) {
                        if (is_array($node)) {
                            if (isset($node[0], $node[1]) && is_numeric($node[0]) && is_numeric($node[1])) {
                                $pts[] = $node;
                                return;
                            }
                            foreach ($node as $child) $walk($child);
                        }
                    };
                    if ($geo) $walk($geo);
                    if (count($pts) > 0) {
                        $sumLat = 0; $sumLng = 0;
                        foreach ($pts as $p) { $sumLng += $p[0]; $sumLat += $p[1]; }
                        $lat = $sumLat / count($pts);
                        $lng = $sumLng / count($pts);
                    }
                }

                if ($lat === null || $lng === null) {
                    throw new Exception("The selected section has no drawn map shape to anchor the apartment to.");
                }

                // Apartment niches stack vertically, so each one gets a small marker
                // square at the section centroid (geojson_shape is NOT NULL in DB)
                $d = 0.000005; // ~0.5m
                $niche_geojson = json_encode([
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [$lng - $d, $lat - $d],
                        [$lng + $d, $lat - $d],
                        [$lng + $d, $lat + $d],
                        [$lng - $d, $lat + $d],
                        [$lng - $d, $lat - $d]
                    ]]
                ]);

                $pdo->beginTransaction();

                $total = $levels * $per_level;
                $usedStmt = $pdo->prepare("SELECT plot_number FROM public.plots WHERE section_id = ? AND plot_number LIKE ?");
                $usedStmt->execute([$section_id, $prefix . '-%']);
                $used_numbers = $usedStmt->fetchAll(PDO::FETCH_COLUMN);

                $generated = [];
                for ($n = 1; $n <= $total; $n++) {
                    $candidate = $prefix . '-P' . $n;
                    if (!in_array($candidate, $used_numbers, true)) {
                        $generated[] = $candidate;
                    }
                }

                $stmt = $pdo->prepare("
                    INSERT INTO public.plots (plot_number, status, section_id, section_code, latitude, longitude, geojson_shape, price, plot_type, plot_tier)
                    VALUES (?, 'Available', ?, ?, ?, ?, ?, ?, 'Apartment', ?)
                ");
                foreach ($generated as $plot_number) {
                    $stmt->execute([$plot_number, $section_id, $section_code, $lat, $lng, $niche_geojson, $price, $plot_tier]);
                }

                // Mark the section as a 3D Apartment and store its structure
                $pdo->prepare("UPDATE public.sections SET section_type = 'Apartment', apt_levels = ?, apt_per_level = ? WHERE id = ?")
                    ->execute([$levels, $per_level, $section_id]);

                $pdo->commit();
                $message = count($generated) . " apartment niche(s) created successfully (" . $levels . " levels x " . $per_level . " niches)!";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }

    // 3. ADD DECEASED PERSON
    if ($_POST['action'] === 'add_deceased') {
        $plot_id        = intval($_POST['plot_id']);
        $deceased_name  = trim($_POST['deceased_name']);
        $date_of_birth  = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $date_of_death  = !empty($_POST['date_of_death']) ? $_POST['date_of_death'] : null;
        $sex            = in_array($_POST['sex'] ?? '', ['Male', 'Female'], true) ? $_POST['sex'] : null;
        $civil_status   = in_array($_POST['civil_status'] ?? '', ['Single', 'Married', 'Widowed', 'Divorced', 'Separated'], true) ? $_POST['civil_status'] : null;
        $nationality    = trim($_POST['nationality'] ?? '') ?: null;
        $address        = trim($_POST['address'] ?? '') ?: null;
        $place_of_birth = trim($_POST['place_of_birth'] ?? '') ?: null;
        $place_of_death = trim($_POST['place_of_death'] ?? '') ?: null;
        $cause_of_death = trim($_POST['cause_of_death'] ?? '') ?: null;

        if (!$plot_id || !$deceased_name) {
            $error = "Deceased name and plot ID are required.";
        } else {
            try {
                $death_certificate = null;
                $dc_file = null;
                foreach (['death_certificate', 'death_certificate_capture'] as $dc_key) {
                    if (isset($_FILES[$dc_key]) && $_FILES[$dc_key]['error'] === UPLOAD_ERR_OK) {
                        $dc_file = $_FILES[$dc_key];
                        break;
                    }
                }
                if ($dc_file) {
                    $upload_dir = __DIR__ . '/uploads/death_certificates/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $file_ext = strtolower(pathinfo($dc_file['name'], PATHINFO_EXTENSION));
                    if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) && $dc_file['size'] <= 5 * 1024 * 1024) {
                        $filename = 'dc_' . $plot_id . '_' . time() . '.' . $file_ext;
                        if (move_uploaded_file($dc_file['tmp_name'], $upload_dir . $filename)) {
                            $death_certificate = 'uploads/death_certificates/' . $filename;
                        }
                    }
                }

                $pdo->beginTransaction();
                $pStmt = $pdo->prepare("SELECT plot_number, latitude, longitude, geojson_shape, plot_area FROM public.plots WHERE id = ?");
                $pStmt->execute([$plot_id]);
                $plot = $pStmt->fetch(PDO::FETCH_ASSOC);

                if ($plot) {
                    $stmt = $pdo->prepare("
                        INSERT INTO public.deceased_records (deceased_name, plot_code, latitude, longitude, date_of_birth, date_of_death, sex, civil_status, nationality, address, place_of_birth, place_of_death, cause_of_death, death_certificate, geojson_shape, plot_area, plot_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $deceased_name, $plot['plot_number'], $plot['latitude'], $plot['longitude'],
                        $date_of_birth, $date_of_death, $sex, $civil_status, $nationality, $address,
                        $place_of_birth, $place_of_death, $cause_of_death, $death_certificate,
                        $plot['geojson_shape'], $plot['plot_area'], $plot_id
                    ]);

                    $uStmt = $pdo->prepare("
                        UPDATE public.plots
                        SET status = 'Occupied', deceased_name = ?, date_of_birth = ?, date_of_death = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $uStmt->execute([$deceased_name, $date_of_birth, $date_of_death, $plot_id]);

                    $pdo->commit();
                    $message = "Deceased person added successfully!";
                } else {
                    $pdo->rollBack();
                    $error = "Plot not found.";
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }

    // 4. DELETE PLOT
    if ($_POST['action'] === 'delete_plot') {
        try {
            $stmt = $pdo->prepare("DELETE FROM public.plots WHERE id = ?");
            $stmt->execute([$_POST['delete_plot_id']]);
            $message = "Plot deleted successfully!";
        } catch (PDOException $e) { 
            $error = "Delete Error: " . $e->getMessage(); 
        }
    }

    // 5. DELETE MULTIPLE PLOTS
    if ($_POST['action'] === 'delete_plots') {
        if (!empty($_POST['plot_ids']) && is_array($_POST['plot_ids'])) {
            $ids = array_filter(array_map('intval', $_POST['plot_ids']));
            if ($ids) {
                try {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("DELETE FROM public.plots WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $message = count($ids) . " plot(s) deleted successfully!";
                } catch (PDOException $e) {
                    $error = "Delete Error: " . $e->getMessage();
                }
            } else {
                $error = "No valid plots selected.";
            }
        } else {
            $error = "Please select at least one plot.";
        }
    }

    // 6. DELETE ALL PLOTS
    if ($_POST['action'] === 'delete_all_plots') {
        try {
            $stmt = $pdo->prepare("DELETE FROM public.plots");
            $stmt->execute();
            $message = "All plots deleted successfully!";
        } catch (PDOException $e) {
            $error = "Delete Error: " . $e->getMessage();
        }
    }

    // 7. UPDATE PLOT CLASSIFICATION (STANDARD / PREMIUM / GOLD)
    if ($_POST['action'] === 'update_plot_tier') {
        $plot_id = intval($_POST['plot_id'] ?? 0);
        $tier    = $_POST['plot_tier'] ?? '';

        if (!in_array($tier, ['Standard', 'Premium', 'Gold'], true)) {
            $error = "Please select a valid lot classification.";
        } elseif (!$plot_id) {
            $error = "Plot ID is required.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE public.plots SET plot_tier = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$tier, $plot_id]);
                $message = "Plot classification updated to {$tier}.";
            } catch (PDOException $e) {
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }

    // 8. UPDATE PLOT DETAILS (CLASSIFICATION, PRICE & RECOMMENDATION TAGS SHOWN TO USERS AS ADMIN PICKS)
    if ($_POST['action'] === 'update_plot_recommendation') {
        $plot_id = intval($_POST['plot_id'] ?? 0);
        $tags    = $_POST['recommendations'] ?? [];
        if (!is_array($tags)) $tags = [$tags];

        $allowed_tags = ['Near Entrance', 'Beside Main Road', 'Near Parking', 'Near Chapel',
                         'Shaded Area', 'Quiet & Private', 'Scenic View', 'Easy Access'];
        $tags = array_values(array_intersect($allowed_tags, array_map('trim', $tags)));
        $note  = trim($_POST['recommendation_note'] ?? '');
        $tier  = $_POST['plot_tier'] ?? '';
        $price = isset($_POST['price']) && $_POST['price'] !== '' ? floatval($_POST['price']) : null;

        if (!$plot_id) {
            $error = "Plot ID is required.";
        } elseif (!in_array($tier, ['Standard', 'Premium', 'Gold'], true)) {
            $error = "Please select a valid lot classification.";
        } elseif ($price === null || $price < 0) {
            $error = "Please enter a valid price.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE public.plots SET recommendations = ?, recommendation_note = ?, plot_tier = ?, price = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$tags ? json_encode($tags) : null, ($note !== '' ? $note : null), $tier, $price, $plot_id]);
                $message = "Plot details updated.";
            } catch (PDOException $e) {
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// Data Fetching
$plots = [];
try {
    $plots = $pdo->query("
        SELECT p.*, s.section_name, d.id as deceased_id, d.deceased_name, d.date_of_birth, d.date_of_death,
               d.sex, d.civil_status, d.nationality, d.address, d.place_of_birth, d.place_of_death, d.cause_of_death, d.death_certificate
        FROM public.plots p
        LEFT JOIN public.sections s ON p.section_id = s.id
        LEFT JOIN public.deceased_records d ON d.plot_id = p.id
        ORDER BY p.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $plots = []; }

$sections = [];
try {
    $sections = $pdo->query("SELECT id, section_name, section_code, section_type, apt_levels, apt_per_level, color, geojson, price FROM public.sections ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $sections = []; }
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Plots Management - PlotBox GIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { colors: { brand: { 50: '#ecfeff', 500: '#06b6d4', 600: '#0891b2' } } } }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet-geometryutil@0.10.2/src/leaflet.geometryutil.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        #adminMap { height: 100%; width: 100%; }
        .glass-panel {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        .dark .glass-panel {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .map-legend-overlay {
            position: absolute; bottom: 20px; right: 20px; z-index: 1000;
            background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8); border-radius: 12px;
            padding: 8px 16px; display: flex; gap: 16px; font-size: 11px; font-weight: 700;
        }
        .dark .map-legend-overlay { background: rgba(15, 23, 42, 0.88); border: 1px solid #334155; color: #f8fafc; }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.3); border-radius: 9999px; }
        
        .rotate-handle-icon {
            background: #06b6d4;
            border: 2px solid #ffffff;
            border-radius: 50%;
            box-shadow: 0 0 10px rgba(6, 182, 212, 0.8);
            cursor: grab;
        }
        .center-drag-icon {
            background: #0f172a;
            color: #06b6d4;
            border: 2px solid #06b6d4;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            box-shadow: 0 0 10px rgba(6, 182, 212, 0.8);
            cursor: move;
        }
        .width-adjust-icon {
            background: #0f172a;
            color: #06b6d4;
            border: 2px solid #06b6d4;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            box-shadow: 0 0 10px rgba(6, 182, 212, 0.8);
            cursor: ew-resize;
        }
        .length-adjust-icon {
            background: #0f172a;
            color: #06b6d4;
            border: 2px solid #06b6d4;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            box-shadow: 0 0 10px rgba(6, 182, 212, 0.8);
            cursor: ns-resize;
        }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <script src="mobile.js" defer></script>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 h-screen flex font-sans overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden relative z-10">
        <header class="glass-panel border-b border-slate-200 dark:border-slate-800 px-6 py-3.5 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl bg-slate-200 dark:bg-slate-800">
                    <i class="fa-solid fa-bars text-base"></i>
                </button>
                <h1 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                    <i class="fa-solid fa-map-location-dot text-cyan-500"></i> Section Plots & Geometry Management
                </h1>
            </div>
            <button id="themeToggle" onclick="toggleTheme()" class="p-2 rounded-xl bg-slate-200/80 dark:bg-slate-800/80">
                <i id="themeToggleIcon" class="fa-solid fa-moon text-sm"></i>
            </button>
        </header>

        <div class="flex-1 flex flex-col lg:flex-row overflow-hidden">
            <div class="w-full lg:w-96 max-h-[45vh] lg:max-h-none p-4 glass-panel border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-800/80 flex flex-col space-y-4 shrink-0 overflow-y-auto">
                <?php if ($message): ?>
                    <div class="p-3 bg-emerald-500/10 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-xl flex items-center gap-2">
                        <i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="p-3 bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs rounded-xl flex items-center gap-2">
                        <i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <!-- MODE SELECTION TAB -->
                <div class="flex p-1 bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl">
                    <button type="button" onclick="switchMode('manual')" id="tabManual" class="flex-1 py-1.5 text-xs font-bold rounded-lg transition bg-cyan-500 text-white">
                        <i class="fa-solid fa-pen"></i> Manual Draw
                    </button>
                    <button type="button" onclick="switchMode('grid')" id="tabGrid" class="flex-1 py-1.5 text-xs font-bold rounded-lg transition text-slate-400">
                        <i class="fa-solid fa-table-cells"></i> Grid (Row & Col)
                    </button>
                    <button type="button" onclick="switchMode('apartment')" id="tabApartment" class="flex-1 py-1.5 text-xs font-bold rounded-lg transition text-slate-400">
                        <i class="fa-solid fa-cube"></i> 3D Apartment
                    </button>
                </div>

                <!-- MANUAL PLOT FORM -->
                <div id="formManual" class="bg-white dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-3">
                    <span class="text-[11px] font-bold text-cyan-600 dark:text-cyan-400 uppercase tracking-wider block flex items-center gap-1.5">
                        <i class="fa-solid fa-vector-square"></i> Create Single Plot
                    </span>
                    
                    <form method="POST" action="admin_plots.php" class="space-y-3" onsubmit="saveMapViewState()">
                        <input type="hidden" name="action" value="create_plot">
                        <input type="hidden" name="geojson_shape" id="field_geojson_shape">
                        <input type="hidden" name="plot_area" id="field_plot_area">
                        <input type="hidden" name="latitude" id="field_latitude">
                        <input type="hidden" name="longitude" id="field_longitude">

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Plot Code / Name</label>
                            <input type="text" name="plot_number" id="field_plot_number" required placeholder="e.g. SEC-A-P1" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2.5 text-xs font-mono">
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Section</label>
                            <select name="section_id" required class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['section_name'] . ' (' . $s['section_code'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Plot Status</label>
                            <select name="status" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                                <option value="available">Available</option>
                                <option value="occupied">Occupied</option>
                                <option value="reserved">Reserved</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Amount (PHP)</label>
                            <input type="number" step="0.01" name="price" id="field_plot_price" placeholder="0.00" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2.5 text-xs font-mono">
                            <p class="text-[9px] text-slate-400 mt-1">Leave blank to use the section's default price.</p>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Plot Type</label>
                            <select name="plot_type" required class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                                <option value="Single Depth" selected>Single Depth</option>
                                <option value="Double Depth">Double Depth</option>
                                <option value="Apartment">Apartment</option>
                                <option value="Lawn Lot">Lawn Lot</option>
                                <option value="Mausoleum">Mausoleum</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Lot Classification</label>
                            <select name="plot_tier" required class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                                <option value="Standard" selected>Standard</option>
                                <option value="Premium">Premium</option>
                                <option value="Gold">Gold</option>
                            </select>
                        </div>

                        <div class="bg-slate-50 dark:bg-slate-950/60 border border-slate-200 dark:border-slate-800/80 rounded-xl p-3 space-y-2">
                            <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block">Shape Tool</label>
                            <select id="shapeTypeSelect" onchange="currentShapeType = this.value" class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg p-2 text-xs text-cyan-500 font-bold">
                                <option value="rectangle">Rectangle Plot</option>
                                <option value="polygon">Custom Polygon</option>
                            </select>

                            <div class="flex items-center justify-between text-[11px] text-slate-500">
                                <span>Area:</span>
                                <span id="areaDisplay" class="font-mono text-emerald-500 font-bold">0.00 m²</span>
                            </div>

                            <button type="button" onclick="startDrawingShape()" class="w-full py-2 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-600 dark:text-cyan-300 border border-cyan-500/30 text-xs font-semibold rounded-xl flex items-center justify-center gap-1.5 transition">
                                <i class="fa-solid fa-pen-ruler text-xs"></i> Draw Boundary
                            </button>
                        </div>

                        <button type="submit" id="btnSavePlot" disabled class="w-full py-2.5 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 disabled:opacity-40 text-white font-bold text-xs rounded-xl transition">
                            Save Single Plot
                        </button>
                    </form>
                </div>

                <!-- GRID / TABLE GENERATOR FORM -->
                <div id="formGrid" class="hidden bg-white dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-3">
                    <span class="text-[11px] font-bold text-cyan-600 dark:text-cyan-400 uppercase tracking-wider block flex items-center gap-1.5">
                        <i class="fa-solid fa-table-cells"></i> Generate Plot Grid Matrix
                    </span>

                    <form method="POST" action="admin_plots.php" class="space-y-3" onsubmit="saveMapViewState()">
                        <input type="hidden" name="action" value="create_grid_plots">
                        <input type="hidden" name="grid_json" id="grid_json">

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Section</label>
                            <select name="section_id" required class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['section_name'] . ' (' . $s['section_code'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Plot Prefix Code</label>
                            <input type="text" name="prefix" id="grid_prefix" required value="SEC-A" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs font-mono">
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Amount (PHP)</label>
                            <input type="number" step="0.01" name="price" id="grid_plot_price" placeholder="0.00" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs font-mono">
                            <p class="text-[9px] text-slate-400 mt-1">Leave blank to use the section's default price.</p>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Plot Type</label>
                            <select name="plot_type" required class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                                <option value="Single Depth" selected>Single Depth</option>
                                <option value="Double Depth">Double Depth</option>
                                <option value="Apartment">Apartment</option>
                                <option value="Lawn Lot">Lawn Lot</option>
                                <option value="Mausoleum">Mausoleum</option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Lot Classification</label>
                            <select name="plot_tier" required class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                                <option value="Standard" selected>Standard</option>
                                <option value="Premium">Premium</option>
                                <option value="Gold">Gold</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Rows</label>
                                <input type="number" name="rows" id="grid_rows" min="1" max="50" value="6" oninput="updateGridCalculation()" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Columns</label>
                                <input type="number" name="cols" id="grid_cols" min="1" max="50" value="6" oninput="updateGridCalculation()" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                            </div>
                        </div>

                        <!-- ROTATION CONTROL SLIDER -->
                        <div class="space-y-1">
                            <div class="flex justify-between items-center">
                                <label class="text-[10px] font-bold text-slate-400 uppercase">Grid Rotation Angle</label>
                                <span id="angleValueDisplay" class="text-xs font-mono font-bold text-cyan-400">0°</span>
                            </div>
                            <input type="range" id="grid_angle" min="0" max="360" value="0" step="1" oninput="updateGridCalculation()" class="w-full accent-cyan-500 h-1 bg-slate-200 dark:bg-slate-800 rounded-lg cursor-pointer">
                        </div>

                        <!-- PLOT DIMENSION READOUTS -->
                        <div class="grid grid-cols-2 gap-2">
                            <div class="space-y-1">
                                <label class="text-[10px] font-bold text-slate-400 uppercase">Plot Width (m)</label>
                                <input type="number" id="grid_plot_width" readonly class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs font-mono text-cyan-400 font-bold">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[10px] font-bold text-slate-400 uppercase">Plot Length (m)</label>
                                <input type="number" id="grid_plot_length" readonly class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs font-mono text-cyan-400 font-bold">
                            </div>
                        </div>
                        <p class="text-[9px] text-slate-400">Drag the east/south map handles to resize the plot width and length.</p>

                        <!-- AUTOMATIC COUNT BADGE -->
                        <div class="bg-cyan-500/10 border border-cyan-500/30 rounded-xl p-2.5 flex items-center justify-between text-xs">
                            <span class="text-slate-400 font-medium">Calculated Plots:</span>
                            <span id="totalPlotsDisplay" class="font-bold font-mono text-cyan-400 text-sm">36 Plots</span>
                        </div>

                        <div class="bg-slate-50 dark:bg-slate-950/60 border border-slate-200 dark:border-slate-800/80 rounded-xl p-3 space-y-2">
                            <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block">Bounding Region</span>
                            <button type="button" onclick="startDrawingGridBounds()" class="w-full py-2 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-600 dark:text-cyan-300 border border-cyan-500/30 text-xs font-semibold rounded-xl flex items-center justify-center gap-1.5 transition">
                                <i class="fa-solid fa-vector-square text-xs"></i> Draw Region On Map
                            </button>
                            <div class="text-[10px] text-slate-400 italic text-center" id="gridStatusText">No region drawn yet.</div>
                        </div>

                        <button type="submit" id="btnSaveGrid" disabled class="w-full py-2.5 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 disabled:opacity-40 text-white font-bold text-xs rounded-xl transition">
                            Generate & Save Matrix
                        </button>
                    </form>
                </div>

                <!-- 3D APARTMENT GENERATOR FORM -->
                <div id="formApartment" class="hidden bg-white dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 space-y-3">
                    <span class="text-[11px] font-bold text-violet-500 dark:text-violet-400 uppercase tracking-wider block flex items-center gap-1.5">
                        <i class="fa-solid fa-cube"></i> Create 3D Apartment Mausoleum
                    </span>

                    <div id="apt-3d-preview" class="w-full rounded-xl border border-violet-500/30 overflow-hidden relative" style="height:220px;"></div>
                    <p class="text-[9px] text-slate-400 italic text-center">Live 3D preview — rotates automatically.</p>

                    <form method="POST" action="admin_plots.php" class="space-y-3" onsubmit="saveMapViewState()">
                        <input type="hidden" name="action" value="create_apartment">

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Section</label>
                            <select name="section_id" required class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['section_name'] . ' (' . $s['section_code'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-[9px] text-slate-400 mt-1">The selected section will be marked as an Apartment section.</p>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Niche Prefix Code</label>
                            <input type="text" name="prefix" required value="APT" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs font-mono">
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Amount (PHP)</label>
                            <input type="number" step="0.01" name="price" placeholder="0.00" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs font-mono">
                            <p class="text-[9px] text-slate-400 mt-1">Leave blank to use the section's default price.</p>
                        </div>

                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Lot Classification</label>
                            <select name="plot_tier" required class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                                <option value="Standard" selected>Standard</option>
                                <option value="Premium">Premium</option>
                                <option value="Gold">Gold</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Levels (Rows)</label>
                                <input type="number" name="levels" id="apt_levels" min="1" max="20" value="4" oninput="updateApartment3DPreview()" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Niches / Level (Cols)</label>
                                <input type="number" name="per_level" id="apt_per_level" min="1" max="20" value="5" oninput="updateApartment3DPreview()" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                            </div>
                        </div>

                        <div class="bg-violet-500/10 border border-violet-500/30 rounded-xl p-2.5 flex items-center justify-between text-xs">
                            <span class="text-slate-400 font-medium">Total Niches:</span>
                            <span id="aptTotalDisplay" class="font-bold font-mono text-violet-500 dark:text-violet-400 text-sm">20 Niches</span>
                        </div>

                        <button type="submit" class="w-full py-2.5 bg-gradient-to-r from-violet-500 to-purple-600 hover:from-violet-400 hover:to-purple-500 text-white font-bold text-xs rounded-xl transition">
                            Generate & Save 3D Apartment
                        </button>
                    </form>
                </div>

                <!-- PLOTS LIST -->
                <div class="flex-1 flex flex-col min-h-0 bg-white dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800 rounded-2xl p-3 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-[11px] font-bold text-cyan-600 dark:text-cyan-400 uppercase flex items-center gap-1.5">
                            <i class="fa-solid fa-list-ul"></i> Created Plots
                        </h4>
                        <span id="plotsTotalBadge" data-total="<?= count($plots) ?>" class="px-2 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 rounded-lg text-[10px] font-bold">
                            <?= count($plots) ?>
                        </span>
                    </div>

                    <input type="text" id="adminSearchInput" onkeyup="filterAdminRecords()" placeholder="Search Plot Code..." class="w-full mb-2 bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-1.5 text-xs">

                    <div class="flex items-center justify-between mb-2 gap-2">
                        <label class="flex items-center gap-1.5 text-[11px] font-bold text-slate-400 cursor-pointer select-none">
                            <input type="checkbox" id="selectAllPlots" onchange="toggleSelectAllPlots()" class="accent-cyan-500 w-3.5 h-3.5 rounded">
                            Select All
                        </label>
                        <div class="flex gap-1.5">
                            <button type="button" onclick="bulkDeleteSelected()" class="px-2.5 py-1 bg-red-500/10 hover:bg-red-500/20 text-red-600 dark:text-red-400 border border-red-500/30 rounded-lg text-[10px] font-bold">
                                Delete Selected
                            </button>
                            <button type="button" onclick="deleteAllPlots()" class="px-2.5 py-1 bg-red-500 hover:bg-red-600 text-white rounded-lg text-[10px] font-bold">
                                Delete All
                            </button>
                        </div>
                    </div>

                    <form id="bulkDeleteForm" method="POST" action="admin_plots.php" style="display:none;">
                        <input type="hidden" name="action" id="bulkDeleteAction" value="">
                        <div id="bulkDeleteIdsContainer"></div>
                    </form>

                    <div class="flex-1 overflow-y-auto space-y-2 pr-1 min-h-[180px] max-h-[260px] border border-slate-200 dark:border-slate-800 rounded-xl p-2 bg-slate-50/50 dark:bg-slate-950/40" id="adminRecordList">
                        <?php if (empty($plots)): ?>
                            <p class="text-xs text-slate-400 italic text-center py-8">No plots created yet.</p>
                        <?php else: ?>
                            <?php foreach ($plots as $plot): ?>
                                <div class="admin-rec-card p-2.5 bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 rounded-xl flex justify-between items-center cursor-pointer hover:border-cyan-500/50 hover:shadow-sm transition"
                                     data-search="<?= strtolower($plot['plot_number'] . ' ' . $plot['deceased_name'] . ' ' . ($plot['plot_type'] ?? '') . ' ' . ($plot['plot_tier'] ?? '')) ?>"
                                     onclick="openPlotModal(<?= htmlspecialchars(json_encode($plot)) ?>)">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" class="plot-select accent-cyan-500 w-3.5 h-3.5 rounded" value="<?= $plot['id'] ?>" onclick="event.stopPropagation()">
                                        <div>
                                            <h5 class="font-bold text-xs text-slate-900 dark:text-white flex items-center gap-1.5">
                                                <i class="fa-solid fa-border-all text-[10px] text-cyan-500"></i> <?= htmlspecialchars($plot['plot_number']) ?>
                                            </h5>
                                            <span class="text-[10px] font-semibold block mt-0.5 <?= strtolower($plot['status'] ?? '') === 'occupied' ? 'text-red-500' : (strtolower($plot['status'] ?? '') === 'reserved' || strtolower($plot['status'] ?? '') === 'pending approval' ? 'text-amber-500' : 'text-emerald-500') ?>">
                                                <?= strtoupper($plot['status']) ?> <?= $plot['deceased_name'] ? "({$plot['deceased_name']})" : '' ?>
                                            </span>
                                            <span class="text-[9px] block">
                                                <span class="text-slate-400"><?= htmlspecialchars($plot['plot_type'] ?? 'Single Depth') ?></span>
                                                <?php $cardTier = $plot['plot_tier'] ?? 'Standard'; ?>
                                                <span class="font-bold <?= $cardTier === 'Gold' ? 'text-amber-500' : ($cardTier === 'Premium' ? 'text-violet-500' : 'text-slate-400') ?>">· <?= htmlspecialchars($cardTier) ?></span>
                                            </span>
                                        </div>
                                    </div>
                                    <form method="POST" action="admin_plots.php" onsubmit="return confirm('Delete this plot?');" onclick="event.stopPropagation()">
                                        <input type="hidden" name="action" value="delete_plot">
                                        <input type="hidden" name="delete_plot_id" value="<?= $plot['id'] ?>">
                                        <button type="submit" class="text-slate-400 hover:text-red-500 p-1 transition">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- MAP DISPLAY -->
            <div class="flex-1 relative min-h-[320px]">
                <div id="adminMap"></div>

                <div class="absolute top-4 right-4 z-[1000] bg-white/90 dark:bg-slate-900/90 border border-slate-200 dark:border-slate-700 rounded-xl p-1 flex items-center backdrop-blur shadow-lg">
                    <button onclick="setAdminBaseMap('satellite')" id="btnSatAdmin" class="px-3 py-1.5 rounded-lg bg-cyan-500 text-white font-bold text-xs">Satellite</button>
                    <button onclick="setAdminBaseMap('street')" id="btnStreetAdmin" class="px-3 py-1.5 rounded-lg text-slate-600 dark:text-slate-300 font-semibold text-xs">Street Map</button>
                </div>

                <div class="map-legend-overlay">
                    <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span><span>AVAILABLE</span></div>
                    <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span><span>RESERVED</span></div>
                    <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full bg-red-500"></span><span>OCCUPIED</span></div>
                    <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full" style="background:#eab308"></span><span>GOLD</span></div>
                    <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full" style="background:#94a3b8"></span><span>PREMIUM</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- PLOT DETAILS MODAL -->
    <div id="plotDetailsModal" class="hidden fixed inset-0 z-[3000] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 w-full max-w-md rounded-2xl p-5 shadow-2xl relative space-y-4 max-h-[90vh] overflow-y-auto">
            <button onclick="closePlotModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-100">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <form method="POST" action="admin_plots.php" class="absolute top-4 right-10" onsubmit="if(confirm('Delete this plot? This cannot be undone.')){saveMapViewState();return true;}return false;">
                <input type="hidden" name="action" value="delete_plot">
                <input type="hidden" name="delete_plot_id" id="modalDeletePlotId" value="">
                <button type="submit" title="Delete Plot" class="text-slate-400 hover:text-red-500 transition">
                    <i class="fa-solid fa-trash-can text-sm"></i>
                </button>
            </form>
            <div>
                <h3 id="modalPlotTitle" class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2"></h3>
                <p id="modalPlotArea" class="text-xs text-slate-400 font-mono mt-0.5"></p>
            </div>
            <div id="modalStatusBadge" class="p-3 rounded-xl border text-xs font-bold flex items-center justify-between"></div>
            <div id="modalTierSection"></div>
            <div id="modalRecSection"></div>
            <div id="modalDeceasedSection" class="pt-2"></div>
        </div>
    </div>

    <!-- DECEASED DETAILS SLIDE-OVER PANEL -->
    <div id="deceasedPanelBackdrop" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[3500] hidden" onclick="closeDeceasedPanel()"></div>
    <aside id="deceasedPanel" class="fixed top-0 right-0 h-full w-full sm:w-[430px] z-[3600] translate-x-full transition-transform duration-300 ease-in-out">
        <div class="h-full bg-white dark:bg-slate-900 sm:rounded-l-2xl shadow-2xl flex flex-col overflow-hidden border-l border-slate-200 dark:border-slate-800">
            <div class="px-5 py-4 flex items-center justify-between border-b border-slate-200 dark:border-slate-800 shrink-0">
                <h3 id="deceasedPanelTitle" class="text-sm font-extrabold text-slate-900 dark:text-white">Deceased Details</h3>
                <div class="flex items-center gap-2">
                    <span id="deceasedPanelPlot" class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-300 text-[10px] font-mono font-bold"></span>
                    <button onclick="closeDeceasedPanel()" class="w-7 h-7 rounded-lg text-slate-400 hover:bg-slate-100 dark:hover:bg-white/10 transition"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>
            <div id="deceasedPanelBody" class="flex-1 overflow-y-auto p-5 space-y-4"></div>
        </div>
    </aside>

    <!-- 3D APARTMENT SECTION MODAL -->
    <div id="aptSectionModal" class="hidden fixed inset-0 z-[3000] bg-slate-950/30 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white/30 dark:bg-white/5 backdrop-blur-xl border border-violet-500/30 w-full max-w-2xl rounded-2xl p-5 shadow-2xl relative space-y-4">
            <button onclick="closeAptSectionModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-100">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <div>
                <h3 id="aptModalTitle" class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <i class="fa-solid fa-cube text-violet-500"></i> Apartment Section 3D View
                </h3>
                <p id="aptModalSubtitle" class="text-xs text-slate-400 mt-0.5"></p>
            </div>
            <div id="apt-3d-modal-canvas" class="w-full rounded-xl border border-violet-500/30 overflow-hidden" style="height:320px;"></div>
            <div class="flex justify-end">
                <button onclick="closeAptSectionModal()" class="px-4 py-2 bg-slate-800 text-slate-300 rounded-xl text-xs font-semibold hover:bg-slate-700">Close</button>
            </div>
        </div>
    </div>

    <script>
        const dbSections = <?= json_encode($sections) ?>;
        const dbPlots = <?= json_encode($plots) ?>;
        
        let activeDrawnLayer = null;
        let currentMode = 'manual';
        let currentShapeType = 'rectangle';
        let currentGridBounds = null;
        let rotateHandleMarker = null;
        let centerDragMarker = null;
        let widthHandleMarker = null;
        let lengthHandleMarker = null;

        function initTheme() {
            const isDark = localStorage.getItem('theme') === 'dark';
            document.documentElement.classList.toggle('dark', isDark);
        }
        function toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        }
        initTheme();

        const savedLat = sessionStorage.getItem('map_lat') ? parseFloat(sessionStorage.getItem('map_lat')) : 6.2201;
        const savedLng = sessionStorage.getItem('map_lng') ? parseFloat(sessionStorage.getItem('map_lng')) : 125.0647;
        const savedZoom = sessionStorage.getItem('map_zoom') ? parseInt(sessionStorage.getItem('map_zoom')) : 18;

        const map = L.map('adminMap', { zoomControl: false }).setView([savedLat, savedLng], savedZoom);
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        function saveMapViewState() {
            const center = map.getCenter();
            sessionStorage.setItem('map_lat', center.lat);
            sessionStorage.setItem('map_lng', center.lng);
            sessionStorage.setItem('map_zoom', map.getZoom());
        }

        const satelliteTile = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', { maxNativeZoom: 20, maxZoom: 22 }).addTo(map);
        const streetTile = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxNativeZoom: 19, maxZoom: 22 });

        function setAdminBaseMap(type) {
            if (type === 'street') { map.removeLayer(satelliteTile); map.addLayer(streetTile); }
            else { map.removeLayer(streetTile); map.addLayer(satelliteTile); }
        }

        const drawnItems = new L.FeatureGroup().addTo(map);
        const gridPreviewItems = new L.FeatureGroup().addTo(map);
        const plotsGroup = L.featureGroup().addTo(map);
        const layoutGroup = L.featureGroup().addTo(map);

        const drawControl = new L.Control.Draw({ draw: false, edit: { featureGroup: drawnItems, remove: true } });
        map.addControl(drawControl);

        function switchMode(mode) {
            currentMode = mode;
            drawnItems.clearLayers();
            gridPreviewItems.clearLayers();
            clearGridControlMarkers();

            const activeCls = "flex-1 py-1.5 text-xs font-bold rounded-lg transition bg-cyan-500 text-white";
            const activeAptCls = "flex-1 py-1.5 text-xs font-bold rounded-lg transition bg-violet-500 text-white";
            const inactiveCls = "flex-1 py-1.5 text-xs font-bold rounded-lg transition text-slate-400";

            document.getElementById('formManual').classList.toggle('hidden', mode !== 'manual');
            document.getElementById('formGrid').classList.toggle('hidden', mode !== 'grid');
            document.getElementById('formApartment').classList.toggle('hidden', mode !== 'apartment');
            document.getElementById('tabManual').className = mode === 'manual' ? activeCls : inactiveCls;
            document.getElementById('tabGrid').className = mode === 'grid' ? activeCls : inactiveCls;
            document.getElementById('tabApartment').className = mode === 'apartment' ? activeAptCls : inactiveCls;

            if (mode === 'grid') updateGridCalculation();
            if (mode === 'apartment') updateApartment3DPreview();
        }

        // --- 3D APARTMENT PREVIEW ENGINE ---
        let aptScene, aptCamera, aptRenderer, aptAnimId, aptGroup, aptPreviewInit = false;

        function updateApartment3DPreview() {
            const levels = Math.min(20, Math.max(1, parseInt(document.getElementById('apt_levels').value) || 1));
            const perLevel = Math.min(20, Math.max(1, parseInt(document.getElementById('apt_per_level').value) || 1));
            document.getElementById('aptTotalDisplay').innerText = (levels * perLevel) + " Niches";

            const container = document.getElementById('apt-3d-preview');
            if (!aptPreviewInit) {
                const w = container.clientWidth || 300;
                const h = container.clientHeight || 220;
                aptScene = new THREE.Scene();
                aptCamera = new THREE.PerspectiveCamera(45, w / h, 0.1, 1000);
                aptRenderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
                aptRenderer.setClearColor(0x000000, 0);
                aptRenderer.setSize(w, h);
                container.appendChild(aptRenderer.domElement);
                aptScene.add(new THREE.AmbientLight(0xffffff, 0.6));
                const dirLight = new THREE.DirectionalLight(0xa78bfa, 0.9);
                dirLight.position.set(10, 20, 10);
                aptScene.add(dirLight);
                aptGroup = new THREE.Group();
                aptScene.add(aptGroup);
                aptPreviewInit = true;
                (function animate() {
                    aptAnimId = requestAnimationFrame(animate);
                    if (aptGroup) aptGroup.rotation.y += 0.006;
                    aptRenderer.render(aptScene, aptCamera);
                })();
            }

            // Rebuild niche structure
            while (aptGroup.children.length) {
                const m = aptGroup.children.pop();
                m.geometry.dispose();
                m.material.dispose();
            }
            const sizeX = 1.2, sizeY = 0.8, sizeZ = 2.0;
            for (let r = 0; r < levels; r++) {
                for (let c = 0; c < perLevel; c++) {
                    const mesh = new THREE.Mesh(
                        new THREE.BoxGeometry(sizeX - 0.05, sizeY - 0.05, sizeZ),
                        new THREE.MeshStandardMaterial({ color: 0x8b5cf6, roughness: 0.3, metalness: 0.2 })
                    );
                    mesh.position.set((c - perLevel / 2) * sizeX, r * sizeY + 0.5, 0);
                    aptGroup.add(mesh);
                }
            }
            aptCamera.position.set(perLevel * 1.1, levels * 0.9 + 2, perLevel * 1.1 + levels * 0.9 + 6);
            aptCamera.lookAt(0, levels * 0.4, 0);
        }

        // --- APARTMENT SECTION 3D MODAL ---
        let aptModalScene, aptModalCamera, aptModalRenderer, aptModalAnimId, aptModalGroup;

        function openAptSectionModal(sec) {
            const levels = Math.min(20, Math.max(1, parseInt(sec.apt_levels) || 4));
            const perLevel = Math.min(20, Math.max(1, parseInt(sec.apt_per_level) || 5));

            document.getElementById('aptModalTitle').innerHTML =
                `<i class="fa-solid fa-cube text-violet-500"></i> ${sec.section_name || 'Apartment Section'} — 3D View`;
            document.getElementById('aptModalSubtitle').innerText =
                `${sec.section_code || ''} · ${levels} levels × ${perLevel} niches per level (${levels * perLevel} total niches)`;

            document.getElementById('aptSectionModal').classList.remove('hidden');

            const container = document.getElementById('apt-3d-modal-canvas');
            container.innerHTML = '';
            if (aptModalAnimId) cancelAnimationFrame(aptModalAnimId);

            const w = container.clientWidth || 600;
            const h = container.clientHeight || 320;
            aptModalScene = new THREE.Scene();
            aptModalCamera = new THREE.PerspectiveCamera(45, w / h, 0.1, 1000);
            aptModalRenderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
            aptModalRenderer.setClearColor(0x000000, 0);
            aptModalRenderer.setSize(w, h);
            container.appendChild(aptModalRenderer.domElement);

            aptModalScene.add(new THREE.AmbientLight(0xffffff, 0.6));
            const dirLight = new THREE.DirectionalLight(0xa78bfa, 0.9);
            dirLight.position.set(10, 20, 10);
            aptModalScene.add(dirLight);

            aptModalGroup = new THREE.Group();
            const sizeX = 1.2, sizeY = 0.8, sizeZ = 2.0;
            for (let r = 0; r < levels; r++) {
                for (let c = 0; c < perLevel; c++) {
                    const mesh = new THREE.Mesh(
                        new THREE.BoxGeometry(sizeX - 0.05, sizeY - 0.05, sizeZ),
                        new THREE.MeshStandardMaterial({ color: 0x8b5cf6, roughness: 0.3, metalness: 0.2 })
                    );
                    mesh.position.set((c - perLevel / 2) * sizeX, r * sizeY + 0.5, 0);
                    aptModalGroup.add(mesh);
                }
            }
            aptModalScene.add(aptModalGroup);
            aptModalCamera.position.set(perLevel * 1.1, levels * 0.9 + 2, perLevel * 1.1 + levels * 0.9 + 6);
            aptModalCamera.lookAt(0, levels * 0.4, 0);

            (function animate() {
                aptModalAnimId = requestAnimationFrame(animate);
                aptModalGroup.rotation.y += 0.006;
                aptModalRenderer.render(aptModalScene, aptModalCamera);
            })();
        }

        function closeAptSectionModal() {
            document.getElementById('aptSectionModal').classList.add('hidden');
            if (aptModalAnimId) cancelAnimationFrame(aptModalAnimId);
        }

        function clearGridControlMarkers() {
            if (rotateHandleMarker) { map.removeLayer(rotateHandleMarker); rotateHandleMarker = null; }
            if (centerDragMarker) { map.removeLayer(centerDragMarker); centerDragMarker = null; }
            if (widthHandleMarker) { map.removeLayer(widthHandleMarker); widthHandleMarker = null; }
            if (lengthHandleMarker) { map.removeLayer(lengthHandleMarker); lengthHandleMarker = null; }
        }

        // AUTO-CALCULATE PLOT COUNT & UPDATE ANGLE
        function updateGridCalculation() {
            const rows = parseInt(document.getElementById('grid_rows').value) || 0;
            const cols = parseInt(document.getElementById('grid_cols').value) || 0;
            const angle = parseInt(document.getElementById('grid_angle').value) || 0;

            document.getElementById('totalPlotsDisplay').innerText = (rows * cols) + " Plots";
            document.getElementById('angleValueDisplay').innerText = angle + "°";

            if (currentGridBounds) {
                generateGridMatrix();
            }
        }

        function startDrawingShape() {
            if (activeDrawnLayer) drawnItems.removeLayer(activeDrawnLayer);
            const tool = currentShapeType === 'rectangle' ? 
                new L.Draw.Rectangle(map, { shapeOptions: { color: '#06b6d4', weight: 2 } }) :
                new L.Draw.Polygon(map, { shapeOptions: { color: '#06b6d4', weight: 2 } });
            tool.enable();
        }

        function startDrawingGridBounds() {
            drawnItems.clearLayers();
            gridPreviewItems.clearLayers();
            clearGridControlMarkers();
            const tool = new L.Draw.Rectangle(map, { shapeOptions: { color: '#06b6d4', weight: 2, dashArray: '4, 4' } });
            tool.enable();
        }

        map.on(L.Draw.Event.CREATED, function (e) {
            const layer = e.layer;
            drawnItems.clearLayers();
            drawnItems.addLayer(layer);
            activeDrawnLayer = layer;

            if (currentMode === 'manual') {
                const geojson = JSON.stringify(layer.toGeoJSON().geometry);
                const center = layer.getBounds ? layer.getBounds().getCenter() : layer.getLatLngs()[0];
                const area = L.GeometryUtil.geodesicArea(layer.getLatLngs()[0]);

                document.getElementById('field_geojson_shape').value = geojson;
                document.getElementById('field_plot_area').value = area.toFixed(2);
                document.getElementById('areaDisplay').innerText = area.toFixed(2) + ' m²';
                document.getElementById('field_latitude').value = center.lat.toFixed(6);
                document.getElementById('field_longitude').value = center.lng.toFixed(6);
                document.getElementById('btnSavePlot').disabled = false;
            } else if (currentMode === 'grid') {
                currentGridBounds = layer.getBounds();
                generateGridMatrix();
            }
        });

        // ROTATE POINT AROUND CENTER ORIGIN
        function rotatePoint(lat, lng, centerLat, centerLng, angleRad) {
            const cos = Math.cos(angleRad);
            const sin = Math.sin(angleRad);

            const dLat = lat - centerLat;
            const dLng = lng - centerLng;

            const newLat = centerLat + (dLat * cos - dLng * sin);
            const newLng = centerLng + (dLat * sin + dLng * cos);

            return [newLat, newLng];
        }

        // CALCULATE ROTATED MATRIX CELLS AND DRAW ON MAP
        function generateGridMatrix() {
            if (!currentGridBounds) return;
            gridPreviewItems.clearLayers();

            const rows = parseInt(document.getElementById('grid_rows').value) || 1;
            const cols = parseInt(document.getElementById('grid_cols').value) || 1;
            const angleDeg = parseInt(document.getElementById('grid_angle').value) || 0;
            const angleRad = (angleDeg * Math.PI) / 180;

            const center = currentGridBounds.getCenter();
            const south = currentGridBounds.getSouth();
            const north = currentGridBounds.getNorth();
            const west = currentGridBounds.getWest();
            const east = currentGridBounds.getEast();

            const latStep = (north - south) / rows;
            const lngStep = (east - west) / cols;

            const cells = [];

            for (let r = 0; r < rows; r++) {
                for (let c = 0; c < cols; c++) {
                    const cellSouth = north - (r + 1) * latStep;
                    const cellNorth = north - r * latStep;
                    const cellWest = west + c * lngStep;
                    const cellEast = west + (c + 1) * lngStep;

                    // Unrotated Corner Points
                    const unrotatedCorners = [
                        [cellNorth, cellWest],
                        [cellNorth, cellEast],
                        [cellSouth, cellEast],
                        [cellSouth, cellWest]
                    ];

                    // Apply Rotation Math
                    const rotatedCorners = unrotatedCorners.map(pt => 
                        rotatePoint(pt[0], pt[1], center.lat, center.lng, angleRad)
                    );

                    const polygon = L.polygon(rotatedCorners, {
                        color: '#06b6d4',
                        weight: 1,
                        fillColor: '#06b6d4',
                        fillOpacity: 0.25
                    });
                    
                    gridPreviewItems.addLayer(polygon);

                    const latLngs = polygon.getLatLngs()[0];
                    const area = L.GeometryUtil.geodesicArea(latLngs);
                    const cellCenter = polygon.getBounds().getCenter();

                    cells.push({
                        row: r + 1,
                        col: c + 1,
                        lat: cellCenter.lat.toFixed(6),
                        lng: cellCenter.lng.toFixed(6),
                        area: area.toFixed(2),
                        geojson: polygon.toGeoJSON().geometry
                    });
                }
            }

            // 1. DRAGGABLE ROTATION HANDLE (OUTER TOP)
            const topMiddleUnrotated = [north, center.lng];
            const handlePos = rotatePoint(topMiddleUnrotated[0], topMiddleUnrotated[1], center.lat, center.lng, angleRad);

            if (!rotateHandleMarker) {
                const customIcon = L.divIcon({
                    className: 'rotate-handle-icon',
                    iconSize: [16, 16],
                    iconAnchor: [8, 8]
                });

                rotateHandleMarker = L.marker(handlePos, {
                    icon: customIcon,
                    draggable: true,
                    zIndexOffset: 1000
                }).addTo(map);

                rotateHandleMarker.on('drag', function (evt) {
                    const dragPos = evt.target.getLatLng();
                    const currentCenter = currentGridBounds.getCenter();
                    const dLat = dragPos.lat - currentCenter.lat;
                    const dLng = dragPos.lng - currentCenter.lng;

                    let angle = Math.atan2(dLng, dLat) * (180 / Math.PI);
                    if (angle < 0) angle += 360;

                    const roundedAngle = Math.round(angle);
                    document.getElementById('grid_angle').value = roundedAngle;
                    updateGridCalculation();
                });
            } else {
                rotateHandleMarker.setLatLng(handlePos);
            }

            // 2. DRAGGABLE CENTER ANCHOR (TRANSLATE MATRIX POSITION)
            if (!centerDragMarker) {
                const centerIcon = L.divIcon({
                    className: 'center-drag-icon',
                    html: '<i class="fa-solid fa-arrows-up-down-left-right"></i>',
                    iconSize: [22, 22],
                    iconAnchor: [11, 11]
                });

                centerDragMarker = L.marker(center, {
                    icon: centerIcon,
                    draggable: true,
                    zIndexOffset: 1001
                }).addTo(map);

                centerDragMarker.on('drag', function (evt) {
                    const newCenter = evt.target.getLatLng();
                    const oldCenter = currentGridBounds.getCenter();
                    
                    const latShift = newCenter.lat - oldCenter.lat;
                    const lngShift = newCenter.lng - oldCenter.lng;

                    // Shift bounding rectangle coordinates
                    const newSouth = currentGridBounds.getSouth() + latShift;
                    const newNorth = currentGridBounds.getNorth() + latShift;
                    const newWest = currentGridBounds.getWest() + lngShift;
                    const newEast = currentGridBounds.getEast() + lngShift;

                    currentGridBounds = L.latLngBounds(
                        [newSouth, newWest],
                        [newNorth, newEast]
                    );

                    generateGridMatrix();
                });
            } else {
                centerDragMarker.setLatLng(center);
            }

            // 3. DRAGGABLE WIDTH RESIZE HANDLE (EAST MIDDLE)
            if (!widthHandleMarker) {
                const widthIcon = L.divIcon({
                    className: 'width-adjust-icon',
                    html: '<i class="fa-solid fa-arrows-left-right"></i>',
                    iconSize: [22, 22],
                    iconAnchor: [11, 11]
                });

                const widthHandleUnrotated = [center.lat, east];
                const widthHandlePos = rotatePoint(widthHandleUnrotated[0], widthHandleUnrotated[1], center.lat, center.lng, angleRad);

                widthHandleMarker = L.marker(widthHandlePos, {
                    icon: widthIcon,
                    draggable: true,
                    zIndexOffset: 1002
                }).addTo(map);

                widthHandleMarker.on('drag', function (evt) {
                    const dragPos = evt.target.getLatLng();
                    const oldCenter = currentGridBounds.getCenter();
                    const angleDeg = parseInt(document.getElementById('grid_angle').value) || 0;
                    const angleRadDrag = (angleDeg * Math.PI) / 180;
                    const west = currentGridBounds.getWest();
                    const south = currentGridBounds.getSouth();
                    const north = currentGridBounds.getNorth();

                    let unrotated = rotatePoint(dragPos.lat, dragPos.lng, oldCenter.lat, oldCenter.lng, -angleRadDrag);
                    let newEast = unrotated[1];
                    const newCenterLng = (newEast + west) / 2;
                    unrotated = rotatePoint(dragPos.lat, dragPos.lng, oldCenter.lat, newCenterLng, -angleRadDrag);
                    newEast = unrotated[1];

                    newEast = Math.max(newEast, west + 0.000001);

                    currentGridBounds = L.latLngBounds([south, west], [north, newEast]);
                    generateGridMatrix();
                });
            } else {
                const widthHandleUnrotated = [center.lat, east];
                widthHandleMarker.setLatLng(rotatePoint(widthHandleUnrotated[0], widthHandleUnrotated[1], center.lat, center.lng, angleRad));
            }

            // 4. DRAGGABLE LENGTH RESIZE HANDLE (SOUTH MIDDLE)
            if (!lengthHandleMarker) {
                const lengthIcon = L.divIcon({
                    className: 'length-adjust-icon',
                    html: '<i class="fa-solid fa-arrows-up-down"></i>',
                    iconSize: [22, 22],
                    iconAnchor: [11, 11]
                });

                const lengthHandleUnrotated = [south, center.lng];
                const lengthHandlePos = rotatePoint(lengthHandleUnrotated[0], lengthHandleUnrotated[1], center.lat, center.lng, angleRad);

                lengthHandleMarker = L.marker(lengthHandlePos, {
                    icon: lengthIcon,
                    draggable: true,
                    zIndexOffset: 1003
                }).addTo(map);

                lengthHandleMarker.on('drag', function (evt) {
                    const dragPos = evt.target.getLatLng();
                    const oldCenter = currentGridBounds.getCenter();
                    const angleDeg = parseInt(document.getElementById('grid_angle').value) || 0;
                    const angleRadDrag = (angleDeg * Math.PI) / 180;
                    const west = currentGridBounds.getWest();
                    const east = currentGridBounds.getEast();
                    const north = currentGridBounds.getNorth();

                    let unrotated = rotatePoint(dragPos.lat, dragPos.lng, oldCenter.lat, oldCenter.lng, -angleRadDrag);
                    let newSouth = unrotated[0];
                    const newCenterLat = (newSouth + north) / 2;
                    unrotated = rotatePoint(dragPos.lat, dragPos.lng, newCenterLat, oldCenter.lng, -angleRadDrag);
                    newSouth = unrotated[0];

                    newSouth = Math.min(newSouth, north - 0.000001);

                    currentGridBounds = L.latLngBounds([newSouth, west], [north, east]);
                    generateGridMatrix();
                });
            } else {
                const lengthHandleUnrotated = [south, center.lng];
                lengthHandleMarker.setLatLng(rotatePoint(lengthHandleUnrotated[0], lengthHandleUnrotated[1], center.lat, center.lng, angleRad));
            }

            const metersPerLngDegree = 111320 * Math.cos(center.lat * Math.PI / 180);
            const metersPerLatDegree = 111132;
            document.getElementById('grid_plot_width').value = (Math.abs(lngStep) * metersPerLngDegree).toFixed(2);
            document.getElementById('grid_plot_length').value = (Math.abs(latStep) * metersPerLatDegree).toFixed(2);
            document.getElementById('totalPlotsDisplay').innerText = (rows * cols) + ' Plots';

            document.getElementById('grid_json').value = JSON.stringify(cells);
            document.getElementById('gridStatusText').innerText = `Matrix Ready: ${rows}×${cols} at ${angleDeg}° (${cells.length} Total Plots)`;
            document.getElementById('btnSaveGrid').disabled = false;
        }

        if (Array.isArray(dbSections)) {
            dbSections.forEach(item => {
                if (!item.geojson) return;
                let geojson = typeof item.geojson === 'string' ? JSON.parse(item.geojson) : item.geojson;
                const secLayer = L.geoJSON(geojson, { style: { color: item.color || '#06b6d4', weight: 2, fillOpacity: 0.1 } }).addTo(layoutGroup);

                secLayer.on('click', (e) => {
                    L.DomEvent.stopPropagation(e);
                    map.fitBounds(secLayer.getBounds(), { padding: [30, 30], maxZoom: 20, animate: true });

                    if ((item.section_type || '').toLowerCase() === 'apartment') {
                        // Zoom into the section first, then show the 3D dialog
                        map.once('moveend', () => {
                            setTimeout(() => openAptSectionModal(item), 400);
                        });
                    }
                });
            });
        }

        if (Array.isArray(dbPlots)) {
            dbPlots.forEach(plot => {
                if (!plot.geojson_shape) return;

                const status = (plot.status || '').toLowerCase();
                const tier = (plot.plot_tier || 'standard').toLowerCase();
                const color = tier === 'gold' ? '#eab308' : (tier === 'premium' ? '#94a3b8' : (status === 'occupied' ? '#ef4444' : (status === 'reserved' || status === 'pending approval' ? '#f59e0b' : '#10b981')));
                let geojson = typeof plot.geojson_shape === 'string' ? JSON.parse(plot.geojson_shape) : plot.geojson_shape;

                const plotLayer = L.geoJSON(geojson, {
                    style: { color: color, fillColor: color, fillOpacity: 0.4, weight: 2 }
                });

                plotLayer.on('click', () => openPlotModal(plot));
                plotsGroup.addLayer(plotLayer);
            });
        }

        const ADMIN_REC_TAGS = [
            { label: 'Near Entrance', icon: 'fa-door-open' },
            { label: 'Beside Main Road', icon: 'fa-road' },
            { label: 'Near Parking', icon: 'fa-square-parking' },
            { label: 'Near Chapel', icon: 'fa-church' },
            { label: 'Shaded Area', icon: 'fa-tree' },
            { label: 'Quiet & Private', icon: 'fa-leaf' },
            { label: 'Scenic View', icon: 'fa-mountain-sun' },
            { label: 'Easy Access', icon: 'fa-wheelchair' }
        ];

        function openPlotModal(plot) {
            document.getElementById('modalPlotTitle').innerText = `Plot: ${plot.plot_number}`;
            document.getElementById('modalDeletePlotId').value = plot.id;
            document.getElementById('modalPlotArea').innerText = `Type: ${plot.plot_type || 'Single Depth'} · Calculated Area: ${plot.plot_area || '0.00'} m²`;

            const tier = plot.plot_tier || 'Standard';
            const tierActiveCls = {
                Standard: 'peer-checked:border-slate-500 peer-checked:bg-slate-500/15 peer-checked:text-slate-700 dark:peer-checked:text-slate-200',
                Premium: 'peer-checked:border-violet-500 peer-checked:bg-violet-500/15 peer-checked:text-violet-600 dark:peer-checked:text-violet-300',
                Gold: 'peer-checked:border-amber-400 peer-checked:bg-amber-400/15 peer-checked:text-amber-600 dark:peer-checked:text-amber-300'
            };
            const plotPrice = parseFloat(plot.price);
            document.getElementById('modalTierSection').innerHTML = `
                <div class="space-y-2">
                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Lot Classification</span>
                    <div class="grid grid-cols-3 gap-2">
                        ${['Standard', 'Premium', 'Gold'].map(t => `
                            <label class="cursor-pointer">
                                <input type="radio" name="plot_tier" value="${t}" form="plotRecForm" class="peer sr-only" ${t === tier ? 'checked' : ''}>
                                <span class="block text-center py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 text-[11px] font-bold text-slate-500 transition ${tierActiveCls[t]}">
                                    ${t === 'Gold' ? '<i class="fa-solid fa-crown mr-1"></i>' : ''}${t}
                                </span>
                            </label>
                        `).join('')}
                    </div>
                    <span class="text-[10px] font-bold text-slate-400 uppercase flex items-center gap-1.5 pt-1">
                        <i class="fa-solid fa-tag text-emerald-500"></i> Lot Price
                    </span>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">₱</span>
                        <input type="number" name="price" form="plotRecForm" min="0" step="0.01" value="${isNaN(plotPrice) ? '' : plotPrice.toFixed(2)}" placeholder="0.00" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 pl-7 text-xs font-mono">
                    </div>
                </div>
            `;

            let plotRecTags = [];
            try {
                const parsedTags = JSON.parse(plot.recommendations || '[]');
                if (Array.isArray(parsedTags)) plotRecTags = parsedTags.map(String);
            } catch (e) { plotRecTags = []; }
            const recNote = String(plot.recommendation_note || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');

            document.getElementById('modalRecSection').innerHTML = `
                <form method="POST" action="admin_plots.php" id="plotRecForm" class="space-y-2 pt-1" onsubmit="saveMapViewState()">
                    <input type="hidden" name="action" value="update_plot_recommendation">
                    <input type="hidden" name="plot_id" value="${plot.id}">
                    <span class="text-[10px] font-bold text-slate-400 uppercase flex items-center gap-1.5">
                        <i class="fa-solid fa-thumbs-up text-cyan-500"></i> Recommend To Users
                    </span>
                    <div class="grid grid-cols-2 gap-1.5">
                        ${ADMIN_REC_TAGS.map(t => `
                            <label class="cursor-pointer">
                                <input type="checkbox" name="recommendations[]" value="${t.label}" class="peer sr-only" ${plotRecTags.includes(t.label) ? 'checked' : ''}>
                                <span class="flex items-center justify-center gap-1.5 py-1.5 px-1 rounded-lg border border-slate-300 dark:border-slate-700 text-[10px] font-bold text-slate-500 transition peer-checked:border-cyan-500 peer-checked:bg-cyan-500/15 peer-checked:text-cyan-600 dark:peer-checked:text-cyan-300">
                                    <i class="fa-solid ${t.icon}"></i> ${t.label}
                                </span>
                            </label>
                        `).join('')}
                    </div>
                    <textarea name="recommendation_note" rows="2" placeholder="Auto-generated from selected tags — you can edit it freely." class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-[11px] resize-none">${recNote}</textarea>
                    <button type="submit" class="w-full py-2 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-600 dark:text-cyan-300 border border-cyan-500/30 font-bold text-xs rounded-xl transition">
                        <i class="fa-solid fa-floppy-disk mr-1"></i> Save Recommendation
                    </button>
                </form>
            `;

            // Auto-generate the recommendation note from the selected tags
            const recForm = document.getElementById('modalRecSection').querySelector('form');
            const recNoteEl = recForm.querySelector('textarea[name="recommendation_note"]');
            const REC_NOTE_PHRASES = {
                'Near Entrance': 'conveniently located near the entrance, making visits quick and easy',
                'Beside Main Road': 'positioned beside the main road for effortless access',
                'Near Parking': 'close to the parking area for hassle-free visits',
                'Near Chapel': 'just a short walk from the chapel, ideal for services and prayers',
                'Shaded Area': 'set in a shaded area that stays cool throughout the day',
                'Quiet & Private': 'in a quiet, private spot away from foot traffic',
                'Scenic View': 'offering a scenic view of the memorial grounds',
                'Easy Access': 'easy to reach, suitable for elderly visitors and persons with disabilities'
            };
            const REC_NOTE_VALUE = {
                Gold: 'As a Gold-classified lot in a prime location, it is the most expensive option for this level of convenience.',
                Premium: 'As a Premium-classified lot, it is moderately priced for the convenience it offers.',
                Standard: 'As a Standard-classified lot, it is a budget-friendly option that keeps costs low.'
            };
            let lastAutoNote = '';
            const buildAutoNote = () => {
                const phrases = Array.from(recForm.querySelectorAll('input[name="recommendations[]"]:checked'))
                    .map(i => REC_NOTE_PHRASES[i.value]).filter(Boolean);
                if (!phrases.length) return '';
                const joined = phrases.length === 1 ? phrases[0]
                    : phrases.slice(0, -1).join(', ') + ', and ' + phrases[phrases.length - 1];
                const tierKey = document.querySelector('#modalTierSection input[name="plot_tier"]:checked')?.value || plot.plot_tier || 'Standard';
                return `This plot is ${joined}. ${REC_NOTE_VALUE[tierKey] || REC_NOTE_VALUE.Standard}`;
            };
            const maybeAutoFillNote = () => {
                const generated = buildAutoNote();
                // Only auto-fill when the field is empty or still holds the previous generated text
                if (recNoteEl.value.trim() === '' || recNoteEl.value === lastAutoNote) {
                    recNoteEl.value = generated;
                }
                lastAutoNote = generated;
            };
            recForm.querySelectorAll('input[name="recommendations[]"]').forEach(cb => {
                cb.addEventListener('change', maybeAutoFillNote);
            });
            // Regenerate when the lot classification changes too, since it affects the price wording
            document.querySelectorAll('#modalTierSection input[name="plot_tier"]').forEach(radio => {
                radio.addEventListener('change', maybeAutoFillNote);
            });

            const badge = document.getElementById('modalStatusBadge');
            const decSec = document.getElementById('modalDeceasedSection');
            const status = (plot.status || '').toLowerCase();

            if (status === 'occupied') {
                badge.className = "p-3 rounded-xl border bg-red-500/10 border-red-500/30 text-red-500 flex items-center justify-between";
                badge.innerHTML = `<span>Status: OCCUPIED</span><i class="fa-solid fa-user-check"></i>`;

                decSec.innerHTML = `
                    <button type="button" onclick="openDeceasedPanel(${plot.id})" class="w-full py-2.5 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-600 dark:text-cyan-300 border border-cyan-500/30 font-bold text-xs rounded-xl transition flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-user"></i> View Deceased Person
                    </button>
                `;
            } else {
                const colorClass = status === 'reserved' || status === 'pending approval' ? 'amber' : 'emerald';
                badge.className = `p-3 rounded-xl border bg-${colorClass}-500/10 border-${colorClass}-500/30 text-${colorClass}-500 flex items-center justify-between`;
                badge.innerHTML = `<span>Status: ${plot.status ? plot.status.toUpperCase() : 'AVAILABLE'}</span><i class="fa-solid fa-circle-dot"></i>`;

                decSec.innerHTML = `
                    <button type="button" onclick="openDeceasedPanel(${plot.id})" class="w-full py-2.5 bg-cyan-500 hover:bg-cyan-600 text-white font-bold text-xs rounded-xl shadow-md transition flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-user-plus"></i> Add Deceased Person
                    </button>
                `;
            }

            document.getElementById('plotDetailsModal').classList.remove('hidden');
        }

        function closePlotModal() {
            document.getElementById('plotDetailsModal').classList.add('hidden');
        }

        function openDeceasedPanel(plotId) {
            const plot = dbPlots.find(p => String(p.id) === String(plotId));
            if (!plot) return;

            document.getElementById('deceasedPanelPlot').innerText = plot.plot_number;
            const title = document.getElementById('deceasedPanelTitle');
            const body = document.getElementById('deceasedPanelBody');
            const status = (plot.status || '').toLowerCase();

            if (status === 'occupied') {
                title.innerText = 'Deceased Person';
                body.innerHTML = `
                    <div class="bg-slate-100 dark:bg-slate-950 p-4 rounded-xl space-y-2">
                        <span class="text-[10px] font-bold text-slate-400 uppercase block">Deceased Information</span>
                        <p class="text-sm font-bold text-slate-900 dark:text-white">${plot.deceased_name || 'N/A'}</p>
                        ${plot.date_of_birth ? `<p class="text-xs text-slate-400">DOB: ${plot.date_of_birth}</p>` : ''}
                        ${plot.date_of_death ? `<p class="text-xs text-slate-400">DOD: ${plot.date_of_death}</p>` : ''}
                        ${plot.sex ? `<p class="text-xs text-slate-400">Sex: ${plot.sex}</p>` : ''}
                        ${plot.civil_status ? `<p class="text-xs text-slate-400">Civil Status: ${plot.civil_status}</p>` : ''}
                        ${plot.nationality ? `<p class="text-xs text-slate-400">Nationality: ${plot.nationality}</p>` : ''}
                        ${plot.address ? `<p class="text-xs text-slate-400">Address: ${plot.address}</p>` : ''}
                        ${plot.place_of_birth ? `<p class="text-xs text-slate-400">Place of Birth: ${plot.place_of_birth}</p>` : ''}
                        ${plot.place_of_death ? `<p class="text-xs text-slate-400">Place of Death: ${plot.place_of_death}</p>` : ''}
                        ${plot.cause_of_death ? `<p class="text-xs text-slate-400">Cause of Death: ${plot.cause_of_death}</p>` : ''}
                    </div>
                    ${plot.death_certificate ? `
                        <div class="space-y-2">
                            <span class="text-[10px] font-bold text-slate-400 uppercase block">Death Certificate</span>
                            <a href="${plot.death_certificate}" target="_blank">
                                <img src="${plot.death_certificate}" alt="Death Certificate" class="w-full rounded-xl border border-slate-200 dark:border-slate-800 object-cover max-h-64 hover:opacity-90 transition">
                            </a>
                        </div>
                    ` : '<p class="text-[11px] text-slate-400 italic">No death certificate on file.</p>'}
                `;
            } else {
                title.innerText = 'Add Deceased Person';
                body.innerHTML = `
                    <form method="POST" action="admin_plots.php" enctype="multipart/form-data" class="space-y-3" onsubmit="saveMapViewState()">
                        <input type="hidden" name="action" value="add_deceased">
                        <input type="hidden" name="plot_id" value="${plot.id}">

                        <span class="text-xs font-bold text-cyan-500 uppercase block">Deceased Person Details</span>

                        <div>
                            <input type="text" name="deceased_name" required placeholder="Full Name" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[9px] text-slate-400 block mb-1">Date of Birth</label>
                                <input type="date" name="date_of_birth" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-1 text-[11px]">
                            </div>
                            <div>
                                <label class="text-[9px] text-slate-400 block mb-1">Date of Death</label>
                                <input type="date" name="date_of_death" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-1 text-[11px]">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[9px] text-slate-400 block mb-1">Sex</label>
                                <select name="sex" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-1 text-[11px]">
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[9px] text-slate-400 block mb-1">Civil Status</label>
                                <select name="civil_status" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-1 text-[11px]">
                                    <option value="">Select</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Divorced">Divorced</option>
                                    <option value="Separated">Separated</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <input type="text" name="nationality" placeholder="Nationality" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                        </div>
                        <div>
                            <input type="text" name="address" placeholder="Address" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <input type="text" name="place_of_birth" placeholder="Place of Birth" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                            </div>
                            <div>
                                <input type="text" name="place_of_death" placeholder="Place of Death" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                            </div>
                        </div>
                        <div>
                            <input type="text" name="cause_of_death" placeholder="Cause of Death" class="w-full bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2 text-xs">
                        </div>
                        <div class="bg-slate-50 dark:bg-slate-950/60 border border-slate-200 dark:border-slate-800/80 rounded-xl p-2.5 space-y-2">
                            <label class="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase block">Death Certificate (Image)</label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="py-2 bg-slate-100 dark:bg-slate-900 hover:border-cyan-500/50 border border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 text-[10px] font-semibold rounded-lg flex items-center justify-center gap-1.5 cursor-pointer transition">
                                    <i class="fa-solid fa-upload"></i> Upload
                                    <input type="file" name="death_certificate" accept="image/*" class="hidden" onchange="previewDeathCert(this, 'upload')">
                                </label>
                                <label class="py-2 bg-slate-100 dark:bg-slate-900 hover:border-cyan-500/50 border border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 text-[10px] font-semibold rounded-lg flex items-center justify-center gap-1.5 cursor-pointer transition">
                                    <i class="fa-solid fa-camera"></i> Capture
                                    <input type="file" name="death_certificate_capture" accept="image/*" capture="environment" class="hidden" onchange="previewDeathCert(this, 'capture')">
                                </label>
                            </div>
                            <img id="dcPreview" class="hidden w-full h-24 object-cover rounded-lg border border-slate-200 dark:border-slate-800">
                            <p id="dcFileName" class="text-[9px] text-slate-400 italic"></p>
                        </div>
                        <button type="submit" class="w-full py-2 bg-cyan-500 hover:bg-cyan-600 text-white font-bold text-xs rounded-xl shadow-md transition">
                            Assign Deceased Person
                        </button>
                    </form>
                `;
            }

            document.getElementById('deceasedPanelBackdrop').classList.remove('hidden');
            document.getElementById('deceasedPanel').classList.remove('translate-x-full');
        }

        function closeDeceasedPanel() {
            document.getElementById('deceasedPanel').classList.add('translate-x-full');
            document.getElementById('deceasedPanelBackdrop').classList.add('hidden');
        }

        function previewDeathCert(input, source) {
            const preview = document.getElementById('dcPreview');
            const nameEl = document.getElementById('dcFileName');
            const otherName = source === 'upload' ? 'death_certificate_capture' : 'death_certificate';
            const other = input.closest('form').querySelector(`input[name="${otherName}"]`);
            if (other) other.value = '';

            if (input.files && input.files[0]) {
                const file = input.files[0];
                preview.src = URL.createObjectURL(file);
                preview.classList.remove('hidden');
                nameEl.innerText = file.name;
            } else {
                preview.classList.add('hidden');
                nameEl.innerText = '';
            }
        }

        function filterAdminRecords() {
            const q = document.getElementById('adminSearchInput').value.toLowerCase();
            let visible = 0;
            document.querySelectorAll('.admin-rec-card').forEach(card => {
                const text = card.getAttribute('data-search');
                const show = text.includes(q);
                card.style.display = show ? 'flex' : 'none';
                if (show) visible++;
            });
            const badge = document.getElementById('plotsTotalBadge');
            if (badge) {
                const total = parseInt(badge.dataset.total, 10) || 0;
                badge.innerText = q ? `${visible} / ${total}` : total;
            }
        }

        function toggleSelectAllPlots() {
            const checked = document.getElementById('selectAllPlots').checked;
            document.querySelectorAll('.plot-select').forEach(cb => cb.checked = checked);
        }

        function bulkDeleteSelected() {
            const selected = Array.from(document.querySelectorAll('.plot-select:checked')).map(cb => cb.value);
            if (!selected.length) {
                alert('Please select at least one plot.');
                return;
            }
            if (!confirm('Delete ' + selected.length + ' selected plot(s)?')) return;
            const container = document.getElementById('bulkDeleteIdsContainer');
            container.innerHTML = '';
            selected.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'plot_ids[]';
                input.value = id;
                container.appendChild(input);
            });
            document.getElementById('bulkDeleteAction').value = 'delete_plots';
            document.getElementById('bulkDeleteForm').submit();
        }

        function deleteAllPlots() {
            if (!confirm('Are you sure you want to delete ALL plots? This cannot be undone.')) return;
            document.getElementById('bulkDeleteIdsContainer').innerHTML = '';
            document.getElementById('bulkDeleteAction').value = 'delete_all_plots';
            document.getElementById('bulkDeleteForm').submit();
        }

        function toggleSidebar() {
            document.getElementById('sidebar')?.classList.toggle('-translate-x-full');
        }
    </script>
</body>
</html>