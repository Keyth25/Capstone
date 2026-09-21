<?php
require 'config.php';

// Safe dynamic column and schema migrations
try {
    $pdo->exec("ALTER TABLE public.cemetery_layouts ADD COLUMN IF NOT EXISTS total_plots INTEGER DEFAULT 50");
    $pdo->exec("ALTER TABLE public.cemetery_layouts ADD COLUMN IF NOT EXISTS section_id VARCHAR(50)");
    $pdo->exec("ALTER TABLE public.sections ADD COLUMN IF NOT EXISTS tier_name VARCHAR(50) DEFAULT 'Standard'");
    $pdo->exec("ALTER TABLE public.sections ADD COLUMN IF NOT EXISTS price DECIMAL(10, 2) DEFAULT 1000.00");
    $pdo->exec("ALTER TABLE public.sections ADD COLUMN IF NOT EXISTS section_type VARCHAR(50) DEFAULT 'Land'");
    $pdo->exec("ALTER TABLE public.sections ADD COLUMN IF NOT EXISTS apt_levels INTEGER");
    $pdo->exec("ALTER TABLE public.sections ADD COLUMN IF NOT EXISTS apt_per_level INTEGER");
    
    // Ensure reservation_id and payment_method columns exist in reservations table
    $pdo->exec("ALTER TABLE public.reservations ADD COLUMN IF NOT EXISTS reservation_id VARCHAR(50)");
    $pdo->exec("ALTER TABLE public.reservations ADD COLUMN IF NOT EXISTS preferred_payment_method VARCHAR(50)");
} catch (PDOException $e) {
    error_log("Schema alteration note: " . $e->getMessage());
}

// Ensure base tables exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public.plots (
            id SERIAL PRIMARY KEY,
            section_code VARCHAR(50) NOT NULL,
            plot_number VARCHAR(50) NOT NULL,
            block_number VARCHAR(50) DEFAULT 'Block A',
            plot_type VARCHAR(50) DEFAULT 'Single Depth',
            plot_size VARCHAR(50) DEFAULT '1.0m x 2.4m',
            status VARCHAR(30) DEFAULT 'Available',
            price DECIMAL(10, 2) DEFAULT 1000.00,
            deceased_name VARCHAR(255),
            reservation_date DATE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW(),
            CONSTRAINT unique_section_plot UNIQUE (section_code, plot_number)
        )
    ");
} catch (PDOException $e) {
    error_log("Error creating plots table: " . $e->getMessage());
}

// Sync admin plot schema and backfill derived fields (section_code, price)
try {
    $pdo->exec("ALTER TABLE public.plots
        ADD COLUMN IF NOT EXISTS section_code VARCHAR(50),
        ADD COLUMN IF NOT EXISTS section_id INTEGER,
        ADD COLUMN IF NOT EXISTS block_number VARCHAR(50) DEFAULT 'Block A',
        ADD COLUMN IF NOT EXISTS plot_type VARCHAR(50) DEFAULT 'Single Depth',
        ADD COLUMN IF NOT EXISTS plot_tier VARCHAR(50) DEFAULT 'Standard',
        ADD COLUMN IF NOT EXISTS plot_size VARCHAR(50) DEFAULT '1.0m x 2.4m',
        ADD COLUMN IF NOT EXISTS price DECIMAL(10, 2) DEFAULT 1000.00,
        ADD COLUMN IF NOT EXISTS deceased_name VARCHAR(255),
        ADD COLUMN IF NOT EXISTS reservation_date DATE,
        ADD COLUMN IF NOT EXISTS latitude NUMERIC,
        ADD COLUMN IF NOT EXISTS longitude NUMERIC,
        ADD COLUMN IF NOT EXISTS geojson_shape TEXT,
        ADD COLUMN IF NOT EXISTS recommendations TEXT,
        ADD COLUMN IF NOT EXISTS recommendation_note TEXT,
        ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW()");
    $pdo->exec("UPDATE public.plots SET status = INITCAP(status) WHERE status IS NOT NULL");
    $pdo->exec("UPDATE public.plots SET plot_tier = 'Standard' WHERE plot_tier IS NULL OR plot_tier NOT IN ('Standard', 'Premium', 'Gold')");
    $pdo->exec("UPDATE public.plots p
        SET section_code = s.section_code,
            section_id = s.id,
            price = COALESCE(s.price, 1000.00)
        FROM public.sections s
        WHERE p.section_id = s.id
          AND (p.section_code IS NULL OR p.price IS NULL)");
} catch (PDOException $e) {
    error_log("Schema sync note: " . $e->getMessage());
}

// --- HANDLE AJAX REQUEST FOR PLOTS ---
if (isset($_GET['action']) && $_GET['action'] === 'get_plots') {
    header('Content-Type: application/json');
    $section_code = $_GET['section_code'] ?? '';

    if (empty($section_code)) {
        echo json_encode(['error' => 'No section code provided']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT p.id, p.section_id, p.section_code, COALESCE(s.section_code, (SELECT section_code FROM public.sections WHERE section_code = p.section_code LIMIT 1)) AS section_section_code, COALESCE(s.section_name, (SELECT section_name FROM public.sections WHERE section_code = p.section_code LIMIT 1)) AS section_name, p.plot_number, p.status, p.plot_type, p.plot_size, p.deceased_name, p.reservation_date, p.price, p.latitude, p.longitude, p.geojson_shape, p.plot_tier, p.recommendations, p.recommendation_note FROM public.plots p LEFT JOIN public.sections s ON s.id = p.section_id WHERE p.section_code = ? OR s.section_code = ? ORDER BY length(p.plot_number), p.plot_number");
        $stmt->execute([$section_code, $section_code]);

        // Deduplicate by section_code + plot_number (guard against duplicate plot rows)
        $seen = [];
        $plots = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), function ($p) use (&$seen) {
            $k = ($p['section_code'] ?? '') . '|' . ($p['plot_number'] ?? '');
            if (isset($seen[$k])) return false;
            $seen[$k] = true;
            return true;
        }));

        echo json_encode(['status' => 'success', 'data' => $plots]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// --- HANDLE AJAX REQUEST FOR RECOMMENDATIONS ---
// Builds the recommendation payload — shared by the AJAX endpoint and the
// page-load preload so the default showcase needs no extra round trip.
function get_recommended_plots($pdo, $max_budget, $preferred_section, $preferred_type, $limit = 6) {
    // Tier showcase applies whenever no budget cap is set — scoped to the chosen
    // section / plot type when the user has picked preferences.
    $is_showcase = $max_budget === null;

    try {
        if ($is_showcase) {
            // A balanced showcase across all five classifications — Mausoleum
            // (most exclusive), Gold, Premium, Standard and Apartment (most
            // affordable) — so visitors see the full range right away.
            $query = "
                SELECT
                    p.id,
                    p.section_code,
                    s.section_name AS title,
                    s.section_type,
                    p.plot_number,
                    p.plot_type,
                    p.plot_size,
                    p.plot_tier,
                    p.latitude,
                    p.longitude,
                    p.recommendations,
                    p.recommendation_note,
                    COALESCE(p.price, s.price, 1000.00) AS price
                FROM public.plots p
                JOIN public.sections s ON s.id = p.section_id
                WHERE p.status = 'Available'
            ";
            $params = [];
            if (!empty($preferred_section)) {
                $query .= " AND (p.section_code = ? OR s.section_code = ?)";
                $params[] = $preferred_section;
                $params[] = $preferred_section;
            }
            if (!empty($preferred_type)) {
                $query .= " AND p.plot_type = ?";
                $params[] = $preferred_type;
            }
            $query .= " ORDER BY (CASE WHEN p.recommendations IS NOT NULL AND p.recommendations <> '' THEN 0 ELSE 1 END),
                            price DESC, p.section_code ASC, length(p.plot_number), p.plot_number
                       LIMIT 200";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Bucket each plot into its showcase classification: Mausoleum and
            // Apartment come from the plot/section type, the rest from the tier.
            $byClass = ['Mausoleum' => [], 'Gold' => [], 'Premium' => [], 'Standard' => [], 'Apartment' => []];
            foreach ($rows as $row) {
                $ptype = strtolower((string)($row['plot_type'] ?? ''));
                $stype = strtolower((string)($row['section_type'] ?? ''));
                if ($ptype === 'mausoleum') {
                    $cls = 'Mausoleum';
                } elseif ($ptype === 'apartment' || $stype === 'apartment') {
                    $cls = 'Apartment';
                } else {
                    $cls = in_array($row['plot_tier'] ?? '', ['Gold', 'Premium', 'Standard'], true) ? $row['plot_tier'] : 'Standard';
                }
                $byClass[$cls][] = $row;
            }
            // Standard and Apartment picks lead with the cheapest option to highlight affordability
            usort($byClass['Standard'], fn($a, $b) => (float)$a['price'] <=> (float)$b['price']);
            usort($byClass['Apartment'], fn($a, $b) => (float)$a['price'] <=> (float)$b['price']);

            // One representative plot per classification — the cards showcase
            // the class itself, not individual sections or plot numbers. Classes
            // with no available plots still render as unavailable placeholders.
            $recommendations = [];
            foreach (['Gold', 'Premium', 'Standard', 'Apartment', 'Mausoleum'] as $cls) {
                if (!empty($byClass[$cls])) {
                    $recommendations[] = $byClass[$cls][0];
                    continue;
                }
                // Fall back to the section's base price when no plots exist yet
                $secStmt = $pdo->prepare("SELECT MIN(price) FROM public.sections WHERE LOWER(COALESCE(section_type, '')) = ?");
                $secStmt->execute([strtolower($cls)]);
                $secPrice = $secStmt->fetchColumn();
                $recommendations[] = [
                    'id' => null,
                    'class_placeholder' => $cls,
                    'price' => $secPrice !== false ? $secPrice : null,
                ];
            }
            $recommendations = array_slice($recommendations, 0, $limit);

            return ['status' => 'success', 'data' => $recommendations, 'showcase' => true];
        }

        $query = "
            SELECT 
                p.id,
                p.section_code, 
                s.section_name AS title,
                s.section_type,
                p.plot_number,
                p.plot_type,
                p.plot_size,
                p.plot_tier,
                p.latitude,
                p.longitude,
                p.recommendations,
                p.recommendation_note,
                COALESCE(p.price, s.price, 1000.00) AS price
            FROM public.plots p
            JOIN public.sections s ON s.id = p.section_id
            WHERE p.status = 'Available'
        ";

        $params = [];
        if (!empty($preferred_section)) {
            $query .= " AND (p.section_code = ? OR s.section_code = ?)";
            $params[] = $preferred_section;
            $params[] = $preferred_section;
        }
        if (!empty($preferred_type)) {
            $query .= " AND p.plot_type = ?";
            $params[] = $preferred_type;
        }

        // Budget is a target, not a hard cut-off: pull every available plot
        // cheapest-first, then split into within-budget and just-above-budget.
        $query .= " ORDER BY COALESCE(p.price, s.price, 1000.00) ASC, p.section_code ASC, length(p.plot_number), p.plot_number LIMIT 200";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Only consider plots inside a band around the budget:
        // up to 15k below it (e.g. 45000 down to 30000) and up to 10k above it
        // (e.g. 45000 up to 50000). Anything outside is a fallback.
        $lowerBound = $max_budget - 15000;
        $upperBound = $max_budget + 10000;

        $within = [];
        $over = [];
        $rest = [];
        foreach ($rows as $row) {
            $p = (float)$row['price'];
            if ($p <= $max_budget && $p >= $lowerBound) $within[] = $row;
            elseif ($p > $max_budget && $p <= $upperBound) $over[] = $row;
            else $rest[] = $row;
        }

        // Lead with the plots closest to the budget on each side: within-budget
        // is flipped to highest-first, over-budget stays cheapest-first, and
        // out-of-band fallbacks are ordered by distance from the budget.
        $within = array_reverse($within);
        usort($rest, fn($a, $b) => abs((float)$a['price'] - $max_budget) <=> abs((float)$b['price'] - $max_budget));

        // Reserve a couple of slots for the closest options above the budget
        $overSlots = min(count($over), max(1, (int)floor($limit / 3)));
        $recommendations = array_merge(
            array_slice($within, 0, $limit - $overSlots),
            array_slice($over, 0, $overSlots)
        );
        // Backfill remaining slots: in-band plots first, then nearest out-of-band
        if (count($recommendations) < $limit) {
            $picked = array_map('strval', array_column($recommendations, 'id'));
            foreach (array_merge(array_slice($within, $limit - $overSlots), array_slice($over, $overSlots), $rest) as $row) {
                if (in_array((string)$row['id'], $picked, true)) continue;
                $recommendations[] = $row;
                if (count($recommendations) >= $limit) break;
            }
        }
        foreach ($recommendations as &$rec) {
            $rec['over_budget'] = (float)$rec['price'] > $max_budget;
        }
        unset($rec);

        return ['status' => 'success', 'data' => $recommendations, 'budget' => $max_budget];
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'recommend_plots') {
    header('Content-Type: application/json');
    $max_budget = isset($_GET['max_budget']) && $_GET['max_budget'] !== '' ? (float)$_GET['max_budget'] : null;
    echo json_encode(get_recommended_plots(
        $pdo,
        $max_budget,
        $_GET['section_code'] ?? null,
        $_GET['plot_type'] ?? null,
        isset($_GET['limit']) ? (int)$_GET['limit'] : 6
    ));
    exit;
}

// --- HANDLE AJAX RESERVATION SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reserve_plot') {
    header('Content-Type: application/json');
    
    $section_code = $_POST['section_code'] ?? '';
    $plot_number = $_POST['plot_number'] ?? '';
    $plot_id = $_POST['plot_id'] ?? '';
    $reservation_type = $_POST['reservation_type'] ?? 'Deceased Relative';
    
    if (empty($section_code) || empty($plot_number)) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a valid plot before submitting.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Check existing plot availability
        if (!empty($plot_id)) {
            $stmt = $pdo->prepare("SELECT id, status, section_code FROM public.plots WHERE id = ? FOR UPDATE");
            $stmt->execute([$plot_id]);
            $existingPlot = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("SELECT id, status, section_code FROM public.plots WHERE section_code = ? AND plot_number = ? FOR UPDATE");
            $stmt->execute([$section_code, $plot_number]);
            $existingPlot = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($existingPlot && in_array(strtolower($existingPlot['status']), ['reserved', 'pending approval', 'occupied'])) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'This plot is no longer available. Please select another plot.']);
            exit;
        }

        // 2. Create or Update Plot Status
        $sectionRow = null;
        try {
            $secStmt = $pdo->prepare("SELECT id, price FROM public.sections WHERE section_code = ? LIMIT 1");
            $secStmt->execute([$section_code]);
            $sectionRow = $secStmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { }
        $sectionDbId = $sectionRow['id'] ?? null;
        $plotPrice = (float)($_POST['plot_price'] ?? $sectionRow['price'] ?? 1000.00);

        if ($existingPlot) {
            $plot_id = $existingPlot['id'];
            $stmt = $pdo->prepare("
                UPDATE public.plots
                SET status = 'Pending Approval',
                    price = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$plotPrice, $plot_id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO public.plots (section_id, section_code, plot_number, status, price)
                VALUES (?, ?, ?, 'Pending Approval', ?)
                RETURNING id
            ");
            $stmt->execute([$sectionDbId, $section_code, $plot_number, $plotPrice]);
            $plot_id = $stmt->fetchColumn();
        }

        // 3. Create Applicant
        $stmt = $pdo->prepare("
            INSERT INTO public.applicants (first_name, middle_name, last_name, suffix, date_of_birth, gender, civil_status, contact_number, email_address, complete_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
        ");
        $stmt->execute([
            $_POST['app_first_name'] ?? '',
            $_POST['app_middle_name'] ?? '',
            $_POST['app_last_name'] ?? '',
            $_POST['app_suffix'] ?? '',
            !empty($_POST['app_dob']) ? $_POST['app_dob'] : null,
            $_POST['app_gender'] ?? null,
            $_POST['app_civil_status'] ?? null,
            $_POST['app_contact'] ?? '',
            $_POST['app_email'] ?? '',
            $_POST['app_address'] ?? ''
        ]);
        $applicant_id = $stmt->fetchColumn();

        // 4. Resolve Payment Option and Payment Method
        $payment_option = $_POST['payment_option'] ?? 'Full Payment';
        $preferred_method = 'N/A';
        $amount_paid = 0.00;
        $price = (float)($_POST['plot_price'] ?? 1000.00);

        if ($payment_option === 'Full Payment' || $payment_option === 'Down Payment') {
            $preferred_method = $_POST['payment_method'] ?? 'GCash';
            $amount_paid = (float)($_POST['amount_paid'] ?? 0);

            if ($payment_option === 'Down Payment' && $amount_paid < ($price * 0.30) - 0.01) {
                throw new Exception('Down payment must be at least 30% of the plot price.');
            }
            if ($amount_paid > $price) {
                throw new Exception('Amount paid cannot exceed the total plot price.');
            }
        }

        // 5. Create Reservation
        $res_id_code = 'RES-' . strtoupper(substr(uniqid(), -8));
        $plot_code = $section_code . '-' . $plot_number;
        $stmt = $pdo->prepare("
            INSERT INTO public.reservations (
                reservation_id, applicant_id, plot_id, reservation_type, status, reservation_date,
                intended_burial_date, expected_future_use_date, payment_option, preferred_payment_method, reservation_fee, amount_paid, payment_due_date, additional_notes, terms_confirmed,
                user_id, plot_code
            ) VALUES (?, ?, ?, ?, 'Pending Approval', ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, ?) RETURNING id
        ");

        $res_date = date('Y-m-d');
        $burial_date = ($reservation_type === 'Deceased Relative' && !empty($_POST['intended_burial_date'])) ? $_POST['intended_burial_date'] : null;
        $future_date = ($reservation_type === 'Future Use' && !empty($_POST['expected_future_use_date'])) ? $_POST['expected_future_use_date'] : null;

        $stmt->execute([
            $res_id_code, $applicant_id, $plot_id, $reservation_type, $res_date,
            $burial_date, $future_date, $payment_option, $preferred_method,
            $price, $amount_paid, date('Y-m-d', strtotime('+7 days')), $_POST['additional_notes'] ?? '',
            $_SESSION['user_id'] ?? null, $plot_code
        ]);
        $reservation_db_id = $stmt->fetchColumn();

        // 6. Store Type Specific Info
        if ($reservation_type === 'Deceased Relative') {
            $stmt = $pdo->prepare("
                INSERT INTO public.deceased_information (reservation_id, first_name, middle_name, last_name, suffix, date_of_birth, date_of_death, age_at_death, gender, relationship_to_applicant)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $reservation_db_id, $_POST['dec_first_name'] ?? '', $_POST['dec_middle_name'] ?? '', $_POST['dec_last_name'] ?? '',
                $_POST['dec_suffix'] ?? '', $_POST['dec_dob'] ?? date('Y-m-d'), $_POST['dec_dod'] ?? date('Y-m-d'),
                (int)($_POST['dec_age'] ?? 0), $_POST['dec_gender'] ?? 'Other', $_POST['dec_relationship'] ?? ''
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO public.future_use_information (reservation_id, reserved_for, intended_first_name, intended_middle_name, intended_last_name, relationship_to_applicant)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $reservedFor = $_POST['future_reserved_for'] ?? 'Myself';
            $fName = ($reservedFor === 'Myself') ? ($_POST['app_first_name'] ?? '') : ($_POST['future_first_name'] ?? '');
            $mName = ($reservedFor === 'Myself') ? ($_POST['app_middle_name'] ?? '') : ($_POST['future_middle_name'] ?? '');
            $lName = ($reservedFor === 'Myself') ? ($_POST['app_last_name'] ?? '') : ($_POST['future_last_name'] ?? '');

            $stmt->execute([
                $reservation_db_id, $reservedFor, $fName, $mName, $lName, $_POST['future_relationship'] ?? ''
            ]);
        }

        $pdo->commit();

        if (function_exists('create_admin_notification')) {
            create_admin_notification(
                $pdo,
                'New Reservation Submitted',
                "Reservation {$res_id_code} for plot {$plot_code} is pending approval.",
                'reservation',
                $reservation_db_id
            );
        }

        echo json_encode([
            'status' => 'success', 
            'message' => 'Reservation submitted successfully!', 
            'reservation_id' => $res_id_code,
            'section_code' => $section_code,
            'plot_number' => $plot_number,
            'reservation_type' => $reservation_type,
            'db_id' => $reservation_db_id
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch layouts and map sections
$layouts = [];
$sections = [];

try {
    $stmt = $pdo->query("SELECT id, section_name AS title, section_code, section_type, apt_levels, apt_per_level, color, geojson, 50 AS total_plots, id::text AS section_id, COALESCE(price, 1000.00) as price FROM public.sections ORDER BY id DESC");
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $perimeter = [];
    try {
        $stmt = $pdo->query("SELECT * FROM public.cemetery_layouts WHERE section_id IS NULL ORDER BY id DESC LIMIT 1");
        $perimeter = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { }

    $layouts = array_merge($perimeter, $sections);
} catch (PDOException $e) {
    $layouts = [];
}

$adminPlots = [];
try {
    $stmt = $pdo->query("
        SELECT p.*, COALESCE(s.section_name, (SELECT section_name FROM public.sections WHERE section_code = p.section_code LIMIT 1)) AS section_name, COALESCE(s.section_code, (SELECT section_code FROM public.sections WHERE section_code = p.section_code LIMIT 1)) AS section_section_code, COALESCE(s.section_type, (SELECT section_type FROM public.sections WHERE section_code = p.section_code LIMIT 1)) AS section_type
        FROM public.plots p
        LEFT JOIN public.sections s ON p.section_id = s.id
        ORDER BY p.created_at DESC
    ");
    $seenPlots = [];
    $adminPlots = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), function ($p) use (&$seenPlots) {
        $k = ($p['section_code'] ?? '') . '|' . ($p['plot_number'] ?? '');
        if (isset($seenPlots[$k])) return false;
        $seenPlots[$k] = true;
        return true;
    }));
} catch (PDOException $e) {
    $adminPlots = [];
}

// Preload the default recommendation showcase so the cards render instantly
// without waiting for a second request.
$preloaded_recs = get_recommended_plots($pdo, null, null, null, 6);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Cemetery Plot Booking & Reservation Portal</title>
    
    <!-- Tailwind CSS with custom slate palette -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 850: '#0f172a', 900: '#0f172a', 950: '#020617' },
                        cyan: { 400: '#22d3ee', 500: '#06b6d4', 600: '#0891b2' }
                    }
                }
            }
        }
    </script>
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Three.js for 3D Modern Apartment Renderings -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">

    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        #map { height: 100%; min-height: 520px; width: 100%; background: #e2e8f0; }
        html.dark #map { background: #0f172a; }
        
        .leaflet-control-layers, .leaflet-control-zoom {
            border-radius: 0.75rem !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3) !important;
            transition: background 0.2s, border-color 0.2s, color 0.2s;
        }

        html.dark .leaflet-control-layers,
        html.dark .leaflet-control-zoom a {
            background: rgba(15, 23, 42, 0.85) !important;
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #f8fafc !important;
        }

        html:not(.dark) .leaflet-control-layers,
        html:not(.dark) .leaflet-control-zoom a {
            background: rgba(255, 255, 255, 0.9) !important;
            backdrop-filter: blur(12px);
            border: 1px solid rgba(0, 0, 0, 0.1) !important;
            color: #0f172a !important;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.2); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.4); }

        /* ---- Custom basemap pill toggle & recenter button ---- */
        .basemap-toggle { display: flex; gap: 2px; padding: 3px; border-radius: 999px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3); }
        html.dark .basemap-toggle { background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
        html:not(.dark) .basemap-toggle { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(12px); border: 1px solid rgba(0, 0, 0, 0.1); }
        .basemap-toggle .bm-btn { padding: 5px 14px; font-size: 11px; font-weight: 700; border-radius: 999px; font-family: 'Inter', sans-serif; cursor: pointer; border: none; background: transparent; transition: all 0.15s ease; }
        html.dark .basemap-toggle .bm-btn { color: #94a3b8; }
        html:not(.dark) .basemap-toggle .bm-btn { color: #475569; }
        .basemap-toggle .bm-btn.active { background: #22d3ee; color: #020617; }

        .recenter-btn { width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 10px; cursor: pointer; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3); transition: all 0.15s ease; }
        html.dark .recenter-btn { background: rgba(15, 23, 42, 0.85); border: 1px solid rgba(255, 255, 255, 0.1); color: #e2e8f0; backdrop-filter: blur(12px); }
        html:not(.dark) .recenter-btn { background: rgba(255, 255, 255, 0.9); border: 1px solid rgba(0, 0, 0, 0.1); color: #0f172a; backdrop-filter: blur(12px); }
        .recenter-btn:hover { color: #22d3ee !important; }

        /* ---- Plot info popup card ---- */
        .plot-popup .leaflet-popup-content-wrapper {
            background: #0f1a2e;
            color: #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 24px 50px -12px rgba(2, 6, 23, 0.55);
            border: 1px solid rgba(148, 163, 184, 0.15);
            padding: 0;
            overflow: hidden;
        }
        .plot-popup .leaflet-popup-content {
            margin: 0;
            width: 300px !important;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.4;
        }
        .plot-popup .leaflet-popup-tip { background: #0f1a2e; }
        .plot-popup .leaflet-popup-close-button {
            width: 22px; height: 22px;
            font-size: 16px; line-height: 20px;
            color: #64748b !important;
            margin: 12px 12px 0 0;
            z-index: 10;
        }
        .plot-popup .leaflet-popup-close-button:hover { color: #f1f5f9 !important; }

        .plot-card { padding: 16px; }
        .plot-card-head { display: flex; gap: 12px; align-items: flex-start; }
        .plot-thumb { width: 54px; height: 54px; border-radius: 12px; overflow: hidden; flex-shrink: 0; box-shadow: inset 0 0 0 1px rgba(148,163,184,.15); }
        .plot-head-text { flex: 1; min-width: 0; padding-top: 2px; }
        .plot-title-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-right: 18px; }
        .plot-title { font-size: 16px; font-weight: 800; letter-spacing: -0.01em; color: #f1f5f9; }
        .plot-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
        .plot-badge::before { content: ''; width: 6px; height: 6px; border-radius: 999px; background: currentColor; }
        .plot-sub { display: flex; align-items: center; gap: 5px; margin-top: 7px; font-size: 11.5px; font-weight: 500; color: #94a3b8; }
        .plot-sub svg { width: 13px; height: 13px; color: #22d3ee; flex-shrink: 0; }

        .plot-details { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 15px; }
        .plot-detail { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .plot-detail-ic { width: 30px; height: 30px; border-radius: 8px; background: #16233a; border: 1px solid #233248; display: flex; align-items: center; justify-content: center; color: #7dd3fc; flex-shrink: 0; }
        .plot-detail-ic svg { width: 14px; height: 14px; }
        .plot-detail-label { display: block; font-size: 9.5px; font-weight: 500; color: #64748b; }
        .plot-detail-value { display: block; font-size: 12px; font-weight: 700; color: #e2e8f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .plot-tier { display: flex; align-items: center; gap: 10px; margin-top: 15px; padding: 9px 12px; border-radius: 12px; }
        .plot-tier-ic { width: 28px; height: 28px; border-radius: 999px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .plot-tier-ic svg { width: 14px; height: 14px; }
        .plot-tier-name { font-size: 12.5px; font-weight: 800; }
        .plot-tier-badge { font-size: 9px; font-weight: 800; letter-spacing: 0.03em; padding: 2.5px 8px; border-radius: 999px; }
        .plot-tier-info { margin-left: auto; width: 14px; height: 14px; opacity: 0.55; flex-shrink: 0; }
        .plot-tier.gold { background: rgba(234, 179, 8, 0.08); border: 1px solid rgba(234, 179, 8, 0.3); }
        .plot-tier.gold .plot-tier-ic { background: #f59e0b; color: #fff; }
        .plot-tier.gold .plot-tier-name { color: #fbbf24; }
        .plot-tier.gold .plot-tier-badge { background: #fbbf24; color: #78350f; }
        .plot-tier.gold .plot-tier-info { color: #f59e0b; }
        .plot-tier.premium { background: rgba(139, 92, 246, 0.1); border: 1px solid rgba(139, 92, 246, 0.3); }
        .plot-tier.premium .plot-tier-ic { background: #8b5cf6; color: #fff; }
        .plot-tier.premium .plot-tier-name { color: #c4b5fd; }
        .plot-tier.premium .plot-tier-badge { background: #a78bfa; color: #fff; }
        .plot-tier.premium .plot-tier-info { color: #a78bfa; }
        .plot-tier.standard { background: rgba(148, 163, 184, 0.07); border: 1px solid rgba(148, 163, 184, 0.2); }
        .plot-tier.standard .plot-tier-ic { background: #64748b; color: #fff; }
        .plot-tier.standard .plot-tier-name { color: #cbd5e1; }
        .plot-tier.standard .plot-tier-badge { background: #475569; color: #cbd5e1; }
        .plot-tier.standard .plot-tier-info { color: #94a3b8; }

        .plot-rec { margin-top: 12px; padding: 9px 12px; border-radius: 12px; background: rgba(251, 191, 36, 0.07); border: 1px solid rgba(251, 191, 36, 0.25); }
        .plot-rec-title { font-size: 9.5px; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #fbbf24; display: flex; align-items: center; gap: 6px; }
        .plot-rec-title svg { width: 11px; height: 11px; }
        .plot-rec-tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 7px; }
        .plot-rec-tag { display: inline-flex; align-items: center; gap: 4px; font-size: 9.5px; font-weight: 700; color: #fcd34d; background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.25); padding: 2px 7px; border-radius: 999px; }
        .plot-rec-tag svg { width: 10px; height: 10px; }
        .plot-rec-note { margin-top: 6px; font-size: 10.5px; font-style: italic; color: #94a3b8; }

        .plot-actions { display: flex; gap: 10px; margin-top: 15px; }
        .plot-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 7px; height: 40px; border-radius: 12px; font-size: 12px; font-weight: 700; font-family: 'Inter', sans-serif; cursor: pointer; border: none; transition: all 0.15s ease; }
        .plot-btn svg { width: 15px; height: 15px; }
        .plot-btn-primary { background: linear-gradient(135deg, #0891b2, #22d3ee); color: #020617; box-shadow: 0 8px 18px -6px rgba(34, 211, 238, 0.4); }
        .plot-btn-primary:hover { background: linear-gradient(135deg, #06b6d4, #67e8f9); }
        .plot-btn-primary:disabled { background: #1e293b; color: #475569; box-shadow: none; cursor: not-allowed; }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <script src="mobile.js" defer></script>
    <script src="announcement_live.js" defer></script>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col transition-colors duration-200 selection:bg-cyan-500 selection:text-slate-950">

    <!-- Header Navigation -->
    <header class="sticky top-0 z-40 w-full backdrop-blur-xl bg-white/80 dark:bg-slate-900/80 border-b border-slate-200 dark:border-slate-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="user_dashboard.php" onclick="handleHeaderBack(event)" class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-white transition-all text-xs font-semibold" title="Back to dashboard">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    <span class="hidden sm:inline">Back</span>
                </a>
                <div class="flex items-center gap-3 cursor-pointer group" onclick="goToLanding()">
                    <div class="p-2.5 rounded-xl bg-gradient-to-br from-cyan-500/20 to-blue-500/10 border border-cyan-500/30 text-cyan-500 dark:text-cyan-400 group-hover:scale-105 transition-transform">
                        <i data-lucide="landmark" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h1 class="text-base font-bold tracking-tight text-slate-900 dark:text-white flex items-center gap-1.5">
                            Cemetery <span class="text-cyan-600 dark:text-cyan-400">Portal</span>
                        </h1>
                        <p class="text-[10px] text-slate-500 dark:text-slate-400 font-medium">Find, Reserve and Manage Plots</p>
                    </div>
                </div>
            </div>

            <div id="hold-timer-banner" class="hidden items-center gap-2.5 bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 px-3.5 py-1.5 rounded-xl text-xs font-semibold backdrop-blur-md animate-pulse">
                <i data-lucide="timer" class="w-4 h-4"></i>
                <span>Plot Held: <span id="timer-display" class="font-mono font-bold">10:00</span></span>
            </div>

            <div class="flex items-center gap-3">
                <button id="themeToggle" class="p-2.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-900 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all">
                    <i data-lucide="sun" class="w-4 h-4 text-amber-400"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- STEP 0: LANDING PAGE -->
    <section id="step-landing" class="flex-1 flex flex-col items-center justify-center p-6 text-center max-w-5xl mx-auto w-full my-auto">
        <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 dark:text-cyan-400 font-medium text-xs mb-6 shadow-inner">
            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Interactive Map & Reservation Engine
        </div>
        
        <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight mb-4 text-slate-900 dark:text-white">
            Reserve a <span class="bg-gradient-to-r from-cyan-500 to-blue-600 dark:from-cyan-400 dark:to-blue-500 bg-clip-text text-transparent">Cemetery Plot</span>
        </h1>
        <p class="text-slate-600 dark:text-slate-400 max-w-lg text-sm sm:text-base mb-10 leading-relaxed">
            Find and reserve available plots visually using our real-time interactive mapping platform or modern vertical 3D structures.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 w-full max-w-4xl">
            <!-- Card 1: Deceased Relative -->
            <div onclick="selectPurpose('Deceased Relative')" class="group relative p-6 bg-white dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800/80 rounded-2xl hover:border-cyan-500/50 transition-all text-left cursor-pointer hover:shadow-2xl hover:shadow-cyan-500/10 hover:-translate-y-1">
                <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 dark:text-cyan-400 flex items-center justify-center mb-5 group-hover:bg-cyan-500 group-hover:text-slate-950 transition-all">
                    <i data-lucide="heart" class="w-6 h-6"></i>
                </div>
                <h2 class="text-base font-bold text-slate-900 dark:text-white mb-2 flex items-center justify-between">
                    Deceased Relative
                    <i data-lucide="arrow-right" class="w-4 h-4 text-slate-400 dark:text-slate-600 group-hover:text-cyan-500 dark:group-hover:text-cyan-400 group-hover:translate-x-1 transition-all"></i>
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mb-4">
                    Immediate burial arrangements required? Browse and secure active plots right away.
                </p>
                <div class="text-xs font-semibold text-cyan-600 dark:text-cyan-400 flex items-center gap-1.5">
                    Select Immediate Reservation
                </div>
            </div>

            <!-- Card 2: Future Use -->
            <div onclick="selectPurpose('Future Use')" class="group relative p-6 bg-white dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800/80 rounded-2xl hover:border-emerald-500/50 transition-all text-left cursor-pointer hover:shadow-2xl hover:shadow-emerald-500/10 hover:-translate-y-1">
                <div class="w-12 h-12 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-5 group-hover:bg-emerald-500 group-hover:text-slate-950 transition-all">
                    <i data-lucide="shield-check" class="w-6 h-6"></i>
                </div>
                <h2 class="text-base font-bold text-slate-900 dark:text-white mb-2 flex items-center justify-between">
                    For Future Use
                    <i data-lucide="arrow-right" class="w-4 h-4 text-slate-400 dark:text-slate-600 group-hover:text-emerald-500 dark:group-hover:text-emerald-400 group-hover:translate-x-1 transition-all"></i>
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mb-4">
                    Plan ahead for complete peace of mind. Pre-select locations for yourself or family members.
                </p>
                <div class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                    Select Pre-Need Plan
                </div>
            </div>

            <!-- Card 3: Apartment / Modern 3D Niche -->
            <div onclick="selectPurpose('Apartment Niche')" class="group relative p-6 bg-white dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800/80 rounded-2xl hover:border-violet-500/50 transition-all text-left cursor-pointer hover:shadow-2xl hover:shadow-violet-500/10 hover:-translate-y-1">
                <div class="w-12 h-12 rounded-xl bg-violet-500/10 border border-violet-500/20 text-violet-600 dark:text-violet-400 flex items-center justify-center mb-5 group-hover:bg-violet-500 group-hover:text-slate-950 transition-all">
                    <i data-lucide="box" class="w-6 h-6"></i>
                </div>
                <h2 class="text-base font-bold text-slate-900 dark:text-white mb-2 flex items-center justify-between">
                    Apartment Niche
                    <i data-lucide="arrow-right" class="w-4 h-4 text-slate-400 dark:text-slate-600 group-hover:text-violet-500 dark:group-hover:text-violet-400 group-hover:translate-x-1 transition-all"></i>
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mb-4">
                    Select space in multi-level vertical mausoleums with interactive 3D visualizations.
                </p>
                <div class="flex items-center justify-between">
                    <div class="text-xs font-semibold text-violet-600 dark:text-violet-400 flex items-center gap-1.5">
                        Select Vertical Niche
                    </div>
                    <button type="button" onclick="event.stopPropagation(); open3DVisualizationModal();" class="text-[10px] px-2 py-1 rounded bg-violet-500/20 text-violet-400 hover:bg-violet-500 hover:text-white transition-colors">
                        Preview 3D
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- MAIN WIZARD CONTAINER -->
    <main id="wizard-container" class="hidden flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        
        <!-- Modern 4-Stage Stepper -->
        <div class="mb-6 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-4 shadow-xl">
            <div class="flex items-center justify-between max-w-3xl mx-auto text-xs font-semibold">
                <div id="stage-ind-1" class="flex items-center gap-2.5 text-cyan-600 dark:text-cyan-400">
                    <span class="w-8 h-8 rounded-full bg-cyan-500 border border-cyan-400 text-white dark:text-slate-950 flex items-center justify-center font-bold shadow-[0_0_14px_rgba(34,211,238,0.45)]">1</span>
                    <span class="hidden sm:inline">Find Plot</span>
                </div>
                <div id="stage-line-1" class="h-0.5 flex-1 mx-3 bg-cyan-500/60"></div>
                <div id="stage-ind-2" class="flex items-center gap-2.5 text-slate-400 dark:text-slate-500">
                    <span class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 flex items-center justify-center font-bold">2</span>
                    <span class="hidden sm:inline">Applicant Details</span>
                </div>
                <div id="stage-line-2" class="h-0.5 flex-1 mx-3 bg-slate-200 dark:bg-slate-800"></div>
                <div id="stage-ind-3" class="flex items-center gap-2.5 text-slate-400 dark:text-slate-500">
                    <span class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 flex items-center justify-center font-bold">3</span>
                    <span class="hidden sm:inline">Docs & Payment</span>
                </div>
                <div id="stage-line-3" class="h-0.5 flex-1 mx-3 bg-slate-200 dark:bg-slate-800"></div>
                <div id="stage-ind-4" class="flex items-center gap-2.5 text-slate-400 dark:text-slate-500">
                    <span class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 flex items-center justify-center font-bold">4</span>
                    <span class="hidden sm:inline">Review</span>
                </div>
            </div>
        </div>

        <form id="booking-form" enctype="multipart/form-data">
            <input type="hidden" name="reservation_type" id="input-reservation-type" value="Deceased Relative">
            <input type="hidden" name="section_code" id="input-section-code">
            <input type="hidden" name="plot_id" id="input-plot-id">
            <input type="hidden" name="plot_number" id="input-plot-number">
            <input type="hidden" name="plot_price" id="input-plot-price" value="1000.00">

            <!-- STAGE 1: MAP & RECOMMENDATIONS -->
            <div id="stage-1" class="stage-section space-y-4">
                
                <!-- Preferences Panel (toggleable via "Change preferences") -->
                <div id="preferences-panel" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-4 flex flex-wrap items-center justify-between gap-4 shadow-xl">
                    <div class="flex items-center gap-3 flex-1 min-w-[220px] flex-wrap">
                        <div class="p-2 rounded-lg bg-slate-100 dark:bg-slate-800 text-cyan-600 dark:text-cyan-400">
                            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i>
                        </div>
                        <select id="filter-section" onchange="applyFilters()" class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs rounded-xl px-3 py-2.5 flex-1 min-w-[160px] focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                            <option value="">All Cemetery Sections</option>
                            <?php foreach ($sections as $s): ?>
                                <option value="<?= htmlspecialchars($s['section_code']) ?>"><?= htmlspecialchars($s['title']) ?> (<?= htmlspecialchars($s['section_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filter-type" onchange="applyFilters()" class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs rounded-xl px-3 py-2.5 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                            <option value="">Any Plot Type</option>
                            <option value="Single Depth">Single Depth</option>
                            <option value="Double Depth">Double Depth</option>
                            <option value="Apartment">Apartment</option>
                            <option value="Lawn Lot">Lawn Lot</option>
                            <option value="Mausoleum">Mausoleum</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Budget Limit:</span>
                        <div class="relative">
                            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-xs pointer-events-none">₱</span>
                            <input type="number" id="filter-budget" min="0" step="any" inputmode="decimal" placeholder="Any Budget" title="Shows plots closest to your budget — just below it, plus the nearest options just above it" oninput="applyFilters()" class="bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs rounded-xl pl-7 pr-3 py-2.5 w-40 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                        </div>
                    </div>
                </div>

                <!-- Prompt shown until the user sets at least one preference -->
                <div id="recommendations-prompt" class="bg-white dark:bg-slate-900 border border-dashed border-slate-300 dark:border-slate-700 rounded-2xl px-5 py-4 shadow-xl flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-2.5">
                        <i data-lucide="sparkles" class="w-4 h-4 text-cyan-500 dark:text-cyan-400"></i>
                        <p class="text-xs text-slate-500 dark:text-slate-400">No plots are currently available. Try adjusting your <strong class="text-slate-700 dark:text-slate-200">section, plot type, or budget</strong> preferences above.</p>
                    </div>
                </div>

                <!-- Recommended For You -->
                <div id="recommendations-container" class="hidden bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-5 shadow-xl">
                    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                        <div class="flex items-start gap-2.5">
                            <i data-lucide="sparkles" class="w-4 h-4 mt-0.5 text-cyan-500 dark:text-cyan-400"></i>
                            <div>
                                <h3 class="text-sm font-bold text-slate-900 dark:text-white">Recommended <span class="text-cyan-600 dark:text-cyan-400">for you</span></h3>
                                <p id="recommendations-subtitle" class="text-[11px] text-slate-500 dark:text-slate-400">Based on your preferences and popular choices.</p>
                            </div>
                        </div>
                        <button type="button" onclick="togglePreferences()" class="px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 text-slate-600 dark:text-slate-300 hover:border-cyan-500 hover:text-cyan-600 dark:hover:text-cyan-400 transition-all text-[11px] font-bold flex items-center gap-2">
                            <i data-lucide="sliders-horizontal" class="w-3.5 h-3.5"></i> Change preferences
                        </button>
                    </div>
                    <div id="recommendations-list" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3"></div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    
                    <div class="lg:col-span-8 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-4 shadow-xl">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 flex items-center gap-2">
                                <i data-lucide="map-pin" class="w-4 h-4 text-cyan-500 dark:text-cyan-400"></i> <span id="map-layer-label">Interactive Map Layer</span>
                                <button type="button" id="btn-clear-class-filter" onclick="clearClassMapFilter()" class="hidden items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-[9px] font-bold normal-case tracking-normal text-slate-500 dark:text-slate-400 hover:text-cyan-600 dark:hover:text-cyan-400 hover:border-cyan-500 transition-all"><i data-lucide="x" class="w-3 h-3"></i> Show all plots</button>
                            </span>

                            <button type="button" id="btn-toggle-3d" onclick="toggleMap3DView()" class="hidden px-3 py-1.5 rounded-lg bg-violet-500/10 border border-violet-500/30 text-violet-600 dark:text-violet-400 hover:bg-violet-500 hover:text-white transition-all text-[10px] font-bold items-center gap-1.5">
                                <i data-lucide="box" class="w-3.5 h-3.5"></i> <span id="btn-toggle-3d-text">Switch to 3D View</span>
                            </button>

                            <div class="flex items-center gap-3.5 text-[11px] font-medium text-slate-500 dark:text-slate-400 flex-wrap">
                                <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-cyan-400"></span> Recommended</span>
                                <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Available</span>
                                <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> Pending</span>
                                <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-violet-500"></span> Reserved</span>
                                <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-slate-500"></span> Occupied</span>
                            </div>
                        </div>

                        <div id="map" class="rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800"></div>
                        <div id="apartment-3d-inline" class="hidden rounded-xl overflow-hidden border border-violet-500/30 bg-slate-950 relative" style="min-height:520px; width:100%;"></div>
                    </div>

                    <div class="lg:col-span-4 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-5 shadow-xl flex flex-col gap-4 min-h-[520px]">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 flex items-center gap-2">
                            <i data-lucide="check-circle-2" class="w-4 h-4 text-cyan-500 dark:text-cyan-400"></i> Selected Plot
                        </h3>

                        <!-- Empty state -->
                        <div id="selected-plot-empty" class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 px-4 py-8 text-center">
                            <i data-lucide="mouse-pointer-click" class="w-6 h-6 mx-auto mb-2 text-slate-400 dark:text-slate-600"></i>
                            <p class="text-[11px] text-slate-400 dark:text-slate-500 leading-relaxed">No plot selected yet.<br>Pick a recommendation above or click a plot on the map.</p>
                        </div>

                        <!-- Selected plot details -->
                        <div id="selected-plot-body" class="hidden space-y-4">
                            <div class="flex items-start gap-3">
                                <div id="sp-thumb" class="w-14 h-14 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 flex-shrink-0"></div>
                                <div class="flex-1 min-w-0 pt-0.5">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span id="summary-plot-num" class="font-extrabold text-sm text-slate-900 dark:text-white font-mono">—</span>
                                        <span id="sp-badge" class="hidden px-2 py-0.5 rounded-full bg-cyan-500 text-slate-950 text-[9px] font-extrabold uppercase tracking-wide">Recommended</span>
                                    </div>
                                    <p id="sp-subtitle" class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">—</p>
                                </div>
                            </div>

                            <div class="border-y border-slate-200 dark:border-slate-800 divide-y divide-slate-200 dark:divide-slate-800 text-xs">
                                <div class="flex items-center justify-between py-2.5">
                                    <span class="flex items-center gap-2 text-slate-500 dark:text-slate-400"><i data-lucide="map-pin" class="w-3.5 h-3.5"></i> Section</span>
                                    <strong id="summary-section" class="font-bold text-slate-900 dark:text-white">Select on Map</strong>
                                </div>
                                <div class="flex items-center justify-between py-2.5">
                                    <span class="flex items-center gap-2 text-slate-500 dark:text-slate-400"><i data-lucide="layout-grid" class="w-3.5 h-3.5"></i> Plot Type</span>
                                    <strong id="summary-plot-type" class="font-bold text-slate-900 dark:text-white">—</strong>
                                </div>
                                <div class="flex items-center justify-between py-2.5">
                                    <span class="flex items-center gap-2 text-slate-500 dark:text-slate-400"><i data-lucide="ruler" class="w-3.5 h-3.5"></i> Dimensions</span>
                                    <span id="summary-plot-size" class="font-medium text-slate-700 dark:text-slate-300">1.0m x 2.4m</span>
                                </div>
                                <div class="flex items-center justify-between py-2.5">
                                    <span class="flex items-center gap-2 text-slate-500 dark:text-slate-400"><i data-lucide="tag" class="w-3.5 h-3.5"></i> Price</span>
                                    <strong id="summary-plot-price" class="font-extrabold text-cyan-600 dark:text-cyan-400 text-sm">₱1,000.00</strong>
                                </div>
                            </div>

                            <div class="p-3 rounded-xl bg-cyan-500/5 border border-cyan-500/20">
                                <div class="text-[11px] font-bold text-cyan-600 dark:text-cyan-400 flex items-center gap-1.5 mb-1">
                                    <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Why this plot?
                                </div>
                                <p id="sp-why" class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">—</p>
                            </div>
                        </div>

                        <!-- Section plots grid -->
                        <div class="space-y-2">
                            <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Section Plots</span>
                            <div id="plot-grid" class="grid grid-cols-5 gap-2 max-h-48 overflow-y-auto p-2.5 bg-slate-50 dark:bg-slate-950 rounded-xl border border-slate-200 dark:border-slate-800">
                                <div class="col-span-5 text-center py-6 text-[11px] text-slate-400 dark:text-slate-500">
                                    Click a colored layout section on the map to display individual plots
                                </div>
                            </div>
                        </div>

                        <div class="mt-auto pt-4 border-t border-slate-200 dark:border-slate-800 space-y-3">
                            <button type="button" id="btn-lock-plot" onclick="nextReservationPhase()" disabled class="w-full py-3.5 bg-cyan-500 disabled:opacity-30 disabled:cursor-not-allowed hover:bg-cyan-400 text-white dark:text-slate-950 font-bold rounded-xl text-xs transition-all flex items-center justify-center gap-2 shadow-lg shadow-cyan-500/20">
                                <i data-lucide="check" class="w-4 h-4"></i> Continue with this plot <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </button>
                            <button type="button" onclick="togglePreferences()" class="w-full flex items-center gap-2.5 text-left group">
                                <i data-lucide="settings-2" class="w-4 h-4 text-slate-400 group-hover:text-cyan-400 transition-colors flex-shrink-0"></i>
                                <span>
                                    <span class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 group-hover:text-cyan-500 dark:group-hover:text-cyan-400 transition-colors">Change preferences</span>
                                    <span class="block text-[10px] text-slate-400 dark:text-slate-500">Adjust budget, section or type and get new recommendations.</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STAGE 2: FORM DETAILS -->
            <div id="stage-2" class="stage-section hidden max-w-3xl mx-auto space-y-6">
                
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-6 shadow-xl space-y-5">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-4">
                        <i data-lucide="user" class="w-4 h-4 text-cyan-500 dark:text-cyan-400"></i> Primary Applicant Information
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">First Name *</label>
                            <input type="text" name="app_first_name" required class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Middle Name</label>
                            <input type="text" name="app_middle_name" class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Last Name *</label>
                            <input type="text" name="app_last_name" required class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Suffix</label>
                            <input type="text" name="app_suffix" placeholder="e.g. Jr." class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Mobile Contact *</label>
                            <input type="tel" name="app_contact" required class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Email Address *</label>
                            <input type="email" name="app_email" value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>" required class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Residential Address *</label>
                        <textarea name="app_address" rows="2" required class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200"></textarea>
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-6 shadow-xl space-y-5">
                    
                    <div id="fields-deceased" class="space-y-4">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-4">
                            <i data-lucide="file-text" class="w-4 h-4 text-cyan-500 dark:text-cyan-400"></i> Deceased Individual Information
                        </h2>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">First Name *</label>
                                <input type="text" name="dec_first_name" class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Last Name *</label>
                                <input type="text" name="dec_last_name" class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Date of Death *</label>
                                <input type="date" name="dec_dod" class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Relationship to Applicant *</label>
                                <input type="text" name="dec_relationship" placeholder="e.g. Parent, Spouse" class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Intended Burial Date *</label>
                                <input type="date" name="intended_burial_date" class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                            </div>
                        </div>
                    </div>

                    <div id="fields-future" class="space-y-4 hidden">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-4">
                            <i data-lucide="shield-check" class="w-4 h-4 text-emerald-500 dark:text-emerald-400"></i> Pre-Need Allocation Target
                        </h2>
                        
                        <div>
                            <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-2">Reserved For:</label>
                            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                                <label class="p-3 border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 rounded-xl text-xs font-medium cursor-pointer flex items-center gap-2 hover:border-cyan-500">
                                    <input type="radio" name="future_reserved_for" value="Myself" checked onchange="toggleFutureTarget('Myself')">
                                    <span>Myself</span>
                                </label>
                                <label class="p-3 border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 rounded-xl text-xs font-medium cursor-pointer flex items-center gap-2 hover:border-cyan-500">
                                    <input type="radio" name="future_reserved_for" value="Spouse" onchange="toggleFutureTarget('Spouse')">
                                    <span>Spouse</span>
                                </label>
                                <label class="p-3 border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 rounded-xl text-xs font-medium cursor-pointer flex items-center gap-2 hover:border-cyan-500">
                                    <input type="radio" name="future_reserved_for" value="Parent" onchange="toggleFutureTarget('Parent')">
                                    <span>Parent</span>
                                </label>
                                <label class="p-3 border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 rounded-xl text-xs font-medium cursor-pointer flex items-center gap-2 hover:border-cyan-500">
                                    <input type="radio" name="future_reserved_for" value="Child" onchange="toggleFutureTarget('Child')">
                                    <span>Child</span>
                                </label>
                                <label class="p-3 border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 rounded-xl text-xs font-medium cursor-pointer flex items-center gap-2 hover:border-cyan-500">
                                    <input type="radio" name="future_reserved_for" value="Other" onchange="toggleFutureTarget('Other')">
                                    <span>Other</span>
                                </label>
                            </div>
                        </div>

                        <div id="future-relative-inputs" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                            <div>
                                <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Intended First Name</label>
                                <input type="text" name="future_first_name" class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mb-1.5">Intended Last Name</label>
                                <input type="text" name="future_last_name" class="w-full px-3 py-2.5 text-xs rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 focus:border-cyan-500 outline-none text-slate-800 dark:text-slate-200">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <button type="button" onclick="goToStage(1)" class="px-5 py-2.5 border border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 bg-white dark:bg-slate-900 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Back to Map
                    </button>
                    <button type="button" onclick="goToStage(3)" class="px-6 py-2.5 bg-cyan-500 hover:bg-cyan-400 text-white dark:text-slate-950 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5">
                        Continue to Documents <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            <!-- STAGE 3: DOCUMENTS & PAYMENT -->
            <div id="stage-3" class="stage-section hidden max-w-3xl mx-auto space-y-6">
                
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-6 shadow-xl space-y-4">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-4">
                        <i data-lucide="upload" class="w-4 h-4 text-cyan-500 dark:text-cyan-400"></i> Supporting Verification Documents
                    </h2>

                    <div class="space-y-3">
                        <div class="p-4 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs">
                            <div>
                                <div class="font-bold text-slate-800 dark:text-slate-200">Valid Government Identification *</div>
                                <div class="text-slate-500 dark:text-slate-400 text-[11px]">Passport, Driver's License, or National ID</div>
                            </div>
                            <input type="file" name="gov_id" required accept=".pdf,.png,.jpg,.jpeg" class="text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-200 dark:file:bg-slate-800 file:text-cyan-600 dark:file:text-cyan-400 hover:file:bg-slate-300 dark:hover:file:bg-slate-700">
                        </div>

                        <div id="doc-death-cert" class="p-4 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs">
                            <div>
                                <div class="font-bold text-slate-800 dark:text-slate-200">Official Death Certificate *</div>
                                <div class="text-slate-500 dark:text-slate-400 text-[11px]">Certified registrar document copy</div>
                            </div>
                            <input type="file" name="death_cert" accept=".pdf,.png,.jpg,.jpeg" class="text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-200 dark:file:bg-slate-800 file:text-cyan-600 dark:file:text-cyan-400 hover:file:bg-slate-300 dark:hover:file:bg-slate-700">
                        </div>
                    </div>
                </div>

                <!-- Payment Selection -->
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-6 shadow-xl space-y-4">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-4">
                        <i data-lucide="credit-card" class="w-4 h-4 text-emerald-500 dark:text-emerald-400"></i> Preferred Payment Option
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="payment-options-container">
                        <!-- Option 1: Full Payment -->
                        <label onclick="selectPaymentOption(this)" class="payment-card p-4 border-2 border-cyan-500 bg-cyan-500/10 rounded-xl cursor-pointer text-xs flex flex-col justify-between transition-all space-y-3">
                            <div class="flex items-center justify-between">
                                <input type="radio" name="payment_option" value="Full Payment" checked class="sr-only">
                                <span class="font-bold text-cyan-600 dark:text-cyan-400">Full Payment</span>
                            </div>
                            <span class="text-slate-500 dark:text-slate-400 text-[11px]">Settle total balance online</span>
                            
                            <div class="pt-2 border-t border-slate-200 dark:border-slate-800/60">
                                <span class="text-[10px] text-slate-400 italic">Click to enter payment details</span>
                            </div>
                        </label>

                        <!-- Option 2: Down Payment -->
                        <label onclick="selectPaymentOption(this)" class="payment-card p-4 border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 rounded-xl cursor-pointer text-xs flex flex-col justify-between hover:border-cyan-500 transition-all space-y-3">
                            <div class="flex items-center justify-between">
                                <input type="radio" name="payment_option" value="Down Payment" class="sr-only">
                                <span class="font-bold text-slate-800 dark:text-slate-200">Down Payment</span>
                            </div>
                            <span class="text-slate-500 dark:text-slate-400 text-[11px]">Lock plot with deposit</span>
                            
                            <div class="pt-2 border-t border-slate-200 dark:border-slate-800/60">
                                <span class="text-[10px] text-slate-400 italic">Click to enter payment details</span>
                            </div>
                        </label>

                        <!-- Option 3: On-Site Settlement -->
                        <label onclick="selectPaymentOption(this)" class="payment-card p-4 border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 rounded-xl cursor-pointer text-xs flex flex-col justify-between hover:border-cyan-500 transition-all space-y-3">
                            <div class="flex items-center justify-between">
                                <input type="radio" name="payment_option" value="Pay Later" class="sr-only">
                                <span class="font-bold text-slate-800 dark:text-slate-200">On-Site Settlement</span>
                            </div>
                            <span class="text-slate-500 dark:text-slate-400 text-[11px]">Pay upon administrative visit</span>
                            
                            <div class="pt-2 border-t border-slate-200 dark:border-slate-800/60">
                                <span class="text-[10px] text-slate-400 italic">No online method required</span>
                            </div>
                        </label>
                    </div>

                    <div id="payment-details-panel" class="hidden mt-4 p-4 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl space-y-3">
                        <h3 class="text-xs font-bold text-slate-700 dark:text-slate-300">Payment Details</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-1">Payment Method</label>
                                <select name="payment_method" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-2 text-xs text-slate-800 dark:text-slate-200 focus:outline-none focus:border-cyan-500">
                                    <option value="GCash">GCash</option>
                                    <option value="Maya">Maya</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Over-the-Counter">Over-the-Counter</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-1">Amount Paid</label>
                                <input type="number" step="0.01" name="amount_paid" id="amount_paid" placeholder="0.00" min="0" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-2 text-xs text-slate-800 dark:text-slate-200 focus:outline-none focus:border-cyan-500">
                            </div>
                            <div class="sm:col-span-2 p-3 bg-slate-100 dark:bg-slate-950/50 border border-slate-200 dark:border-slate-800 rounded-xl space-y-1.5">
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 flex justify-between">
                                    <span>Minimum down payment (30%):</span>
                                    <span id="min-dp" class="font-bold text-slate-700 dark:text-slate-200">₱0.00</span>
                                </p>
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 flex justify-between">
                                    <span>Balance payable over 12 months:</span>
                                    <span id="monthly-dp" class="font-bold text-cyan-600 dark:text-cyan-400">₱0.00</span>
                                </p>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-1">Reference Number</label>
                                <input type="text" name="reference_number" placeholder="e.g. 1234567890" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-2 text-xs text-slate-800 dark:text-slate-200 focus:outline-none focus:border-cyan-500">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-1">Attach Proof of Payment</label>
                                <input type="file" name="proof_of_payment" accept="image/*,.pdf" class="w-full text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-200 dark:file:bg-slate-800 file:text-cyan-600 dark:file:text-cyan-400 hover:file:bg-slate-300 dark:hover:file:bg-slate-700">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-1">Note (Optional)</label>
                            <textarea name="payment_note" rows="2" placeholder="Optional note..." class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg p-2 text-xs text-slate-800 dark:text-slate-200 focus:outline-none focus:border-cyan-500 resize-none"></textarea>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <button type="button" onclick="goToStage(2)" class="px-5 py-2.5 border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Back to Details
                    </button>
                    <button type="button" onclick="goToStage(4)" class="px-6 py-2.5 bg-cyan-500 hover:bg-cyan-400 text-white dark:text-slate-950 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5">
                        Review Reservation <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            <!-- STAGE 4: REVIEW -->
            <div id="stage-4" class="stage-section hidden max-w-3xl mx-auto space-y-6">
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800/80 rounded-2xl p-6 shadow-xl space-y-5">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white border-b border-slate-200 dark:border-slate-800 pb-4 flex justify-between items-center">
                        <span class="flex items-center gap-2"><i data-lucide="check-circle-2" class="w-4 h-4 text-cyan-500 dark:text-cyan-400"></i> Reservation Summary</span>
                        <span class="text-xs font-normal text-slate-500 dark:text-slate-400">Final Verification</span>
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="p-4 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-xs space-y-2">
                            <div class="flex justify-between font-bold text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800 pb-2">
                                <span>Purpose & Applicant</span>
                                <button type="button" onclick="goToStage(2)" class="text-cyan-600 dark:text-cyan-400 hover:underline text-[11px]">Edit</button>
                            </div>
                            <p class="text-slate-700 dark:text-slate-300"><strong class="text-slate-500 dark:text-slate-400">Type:</strong> <span id="rev-type">Deceased Relative</span></p>
                            <p class="text-slate-700 dark:text-slate-300"><strong class="text-slate-500 dark:text-slate-400">Applicant:</strong> <span id="rev-applicant">N/A</span></p>
                            <p class="text-slate-700 dark:text-slate-300"><strong class="text-slate-500 dark:text-slate-400">Contact:</strong> <span id="rev-contact">N/A</span></p>
                        </div>

                        <div class="p-4 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-xs space-y-2">
                            <div class="flex justify-between font-bold text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800 pb-2">
                                <span>Selected Plot & Payment</span>
                                <button type="button" onclick="goToStage(1)" class="text-cyan-600 dark:text-cyan-400 hover:underline text-[11px]">Edit</button>
                            </div>
                            <p class="text-slate-700 dark:text-slate-300"><strong class="text-slate-500 dark:text-slate-400">Section:</strong> <span id="rev-section">N/A</span></p>
                            <p class="text-slate-700 dark:text-slate-300"><strong class="text-slate-500 dark:text-slate-400">Plot Number:</strong> <span id="rev-plot" class="text-cyan-600 dark:text-cyan-400 font-bold">N/A</span></p>
                            <p class="text-slate-700 dark:text-slate-300"><strong class="text-slate-500 dark:text-slate-400">Total Price:</strong> <span id="rev-price" class="text-emerald-600 dark:text-emerald-400 font-bold">$1,000.00</span></p>
                            <p class="text-slate-700 dark:text-slate-300"><strong class="text-slate-500 dark:text-slate-400">Payment Plan:</strong> <span id="rev-payment" class="font-bold text-slate-800 dark:text-slate-200">Full Payment (GCash)</span></p>
                            <p id="rev-dp-row" class="hidden text-slate-700 dark:text-slate-300"><strong class="text-slate-500 dark:text-slate-400">Down Payment:</strong> <span id="rev-dp" class="font-bold text-emerald-600 dark:text-emerald-400">₱0.00</span></p>
                            <p id="rev-monthly-row" class="hidden text-slate-700 dark:text-slate-300"><strong class="text-slate-500 dark:text-slate-400">Monthly (12 mo):</strong> <span id="rev-monthly" class="font-bold text-cyan-600 dark:text-cyan-400">₱0.00</span></p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 pt-2">
                        <input type="checkbox" id="terms" required class="mt-0.5 rounded border-slate-300 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 text-cyan-500 focus:ring-cyan-500">
                        <label for="terms" class="text-xs text-slate-500 dark:text-slate-400 leading-snug">
                            I certify that all information submitted is true and accurate. I understand this hold is pending official administrative validation.
                        </label>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <button type="button" onclick="goToStage(3)" class="px-5 py-2.5 border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-300">
                        Back to Payment
                    </button>
                    <button type="submit" class="px-8 py-3 bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-400 hover:to-teal-400 text-white dark:text-slate-950 font-bold rounded-xl text-xs transition-all shadow-lg shadow-emerald-500/20">
                        Submit Reservation
                    </button>
                </div>
            </div>
        </form>
    </main>

    <!-- 3D APARTMENT VISUALIZATION MODAL -->
    <div id="apartment-3d-modal" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-xl flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl max-w-4xl w-full p-6 space-y-4 shadow-2xl relative flex flex-col">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-3">
                <div class="flex items-center gap-2">
                    <i data-lucide="box" class="w-5 h-5 text-violet-500"></i>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Modern Apartment Mausoleum 3D View</h2>
                </div>
                <button type="button" onclick="close3DVisualizationModal()" class="text-slate-400 hover:text-white p-1 rounded-lg">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            
            <p class="text-xs text-slate-400">Interactive 3D preview of multi-tier vertical cemetery apartment niches. Click or drag to rotate.</p>
            
            <div id="apartment-3d-canvas-container" class="w-full h-80 rounded-xl bg-slate-950 overflow-hidden relative border border-slate-800"></div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="close3DVisualizationModal()" class="px-4 py-2 bg-slate-800 text-slate-300 rounded-xl text-xs font-semibold hover:bg-slate-700">Close Preview</button>
                <button type="button" onclick="close3DVisualizationModal(); selectPurpose('Apartment Niche');" class="px-5 py-2 bg-violet-600 hover:bg-violet-500 text-white font-bold rounded-xl text-xs">Proceed with Apartment Niche</button>
            </div>
        </div>
    </div>

    <!-- SUCCESS MODAL -->
    <div id="success-modal" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-xl flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl max-w-md w-full p-6 text-center space-y-4 shadow-2xl">
            <div class="w-12 h-12 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-500 dark:text-emerald-400 flex items-center justify-center mx-auto text-2xl">
                <i data-lucide="check-circle" class="w-6 h-6"></i>
            </div>

            <h2 class="text-xl font-bold text-slate-900 dark:text-white tracking-tight">Reservation Confirmed</h2>
            <div id="modal-ref-id" class="inline-block px-3 py-1 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-600 dark:text-cyan-400 text-xs font-mono font-bold">
                RES-00000000
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                Your reservation request was successfully logged and flagged as Pending Approval.
            </p>

            <button onclick="window.location.reload()" class="w-full py-3 bg-cyan-500 hover:bg-cyan-400 text-white dark:text-slate-950 rounded-xl text-xs font-bold transition-all">
                Return to Dashboard
            </button>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        lucide.createIcons();

        let selectedPurpose = 'Deceased Relative';
        let holdTimer = null;
        let timeLeft = 600;
        let mapInitialized = false;
        let selectedPlotBtn = null;
        let currentHighlightedPlotLayer = null;
        let activeClassFilter = null;
        let selectedRecCard = null;

        const satelliteTileUrl = 'https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}';
        const osmTileUrl = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

        const layoutsData = <?= json_encode($layouts) ?>;
        let _preloadedRecs = <?= json_encode($preloaded_recs) ?>;
        const adminPlotsData = <?= json_encode($adminPlots) ?>;

        let wizardHistoryPushed = false;

        function goToLanding() {
            document.getElementById('step-landing').classList.remove('hidden');
            document.getElementById('wizard-container').classList.add('hidden');
            window.scrollTo({ top: 0 });
        }

        // Header Back: while inside the wizard, return to the purpose selection
        // screen first; on the selection screen it proceeds to the dashboard.
        function handleHeaderBack(e) {
            if (!document.getElementById('wizard-container').classList.contains('hidden')) {
                e.preventDefault();
                if (wizardHistoryPushed) {
                    history.back();
                } else {
                    goToLanding();
                }
            }
        }

        // Browser Back also returns to the selection screen instead of leaving
        // the page, since the wizard is a pushed history state.
        window.addEventListener('popstate', () => {
            wizardHistoryPushed = false;
            if (!document.getElementById('wizard-container').classList.contains('hidden')) {
                goToLanding();
            }
        });

        function selectPurpose(type) {
            selectedPurpose = type;
            document.getElementById('input-reservation-type').value = type;

            document.getElementById('step-landing').classList.add('hidden');
            document.getElementById('wizard-container').classList.remove('hidden');

            if (!wizardHistoryPushed) {
                history.pushState({ resWizard: true }, '');
                wizardHistoryPushed = true;
            }

            const fieldsDec = document.getElementById('fields-deceased');
            const fieldsFut = document.getElementById('fields-future');
            const docDeath = document.getElementById('doc-death-cert');

            if (type === 'Deceased Relative') {
                fieldsDec.classList.remove('hidden');
                fieldsFut.classList.add('hidden');
                docDeath.classList.remove('hidden');
            } else {
                fieldsFut.classList.remove('hidden');
                fieldsDec.classList.add('hidden');
                docDeath.classList.add('hidden');
            }

            goToStage(1);
            if (!mapInitialized) {
                initMap();
                mapInitialized = true;
            }
            // Show the default tier showcase (expensive, moderate, standard) right away
            loadRecommendations();

            const toggle3dBtn = document.getElementById('btn-toggle-3d');
            if (type === 'Apartment Niche') {
                toggle3dBtn.classList.remove('hidden');
                toggle3dBtn.classList.add('flex');
                showApartment3DView();
            } else {
                toggle3dBtn.classList.add('hidden');
                toggle3dBtn.classList.remove('flex');
                if (apartment3DInlineActive) showMapView();
            }
        }

        function goToStage(stage) {
            document.querySelectorAll('.stage-section').forEach(el => el.classList.add('hidden'));
            document.getElementById(`stage-${stage}`).classList.remove('hidden');

            for (let i = 1; i <= 4; i++) {
                const ind = document.getElementById(`stage-ind-${i}`);
                if (i <= stage) {
                    ind.className = 'flex items-center gap-2.5 text-cyan-600 dark:text-cyan-400 font-bold';
                    ind.querySelector('span').className = 'w-8 h-8 rounded-full bg-cyan-500 border border-cyan-400 text-white dark:text-slate-950 flex items-center justify-center font-bold shadow-[0_0_14px_rgba(34,211,238,0.45)]';
                } else {
                    ind.className = 'flex items-center gap-2.5 text-slate-400 dark:text-slate-500';
                    ind.querySelector('span').className = 'w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 flex items-center justify-center font-bold';
                }
                const line = document.getElementById(`stage-line-${i}`);
                if (line) line.className = `h-0.5 flex-1 mx-3 ${i <= stage ? 'bg-cyan-500/60' : 'bg-slate-200 dark:bg-slate-800'}`;
            }

            if (stage === 1 && window.mapInstance && !apartment3DInlineActive) {
                setTimeout(() => window.mapInstance.invalidateSize(), 200);
            }

            if (stage === 4) {
                document.getElementById('rev-type').innerText = selectedPurpose;
                const fName = document.querySelector('[name="app_first_name"]').value;
                const lName = document.querySelector('[name="app_last_name"]').value;
                document.getElementById('rev-applicant').innerText = (fName || lName) ? `${fName} ${lName}` : 'N/A';
                document.getElementById('rev-contact').innerText = document.querySelector('[name="app_contact"]').value || 'N/A';
                document.getElementById('rev-section').innerText = document.getElementById('summary-section').innerText;
                document.getElementById('rev-plot').innerText = document.getElementById('input-plot-number').value || 'None';
                document.getElementById('rev-price').innerText = document.getElementById('summary-plot-price').innerText;

                // Resolve Payment details display
                const selectedOpt = document.querySelector('input[name="payment_option"]:checked')?.value || 'Full Payment';
                let methodText = '';
                if (selectedOpt === 'Full Payment' || selectedOpt === 'Down Payment') {
                    methodText = ` (${document.querySelector('select[name="payment_method"]').value})`;
                }
                document.getElementById('rev-payment').innerText = selectedOpt + methodText;

                const revDpRow = document.getElementById('rev-dp-row');
                const revMonthlyRow = document.getElementById('rev-monthly-row');
                if (selectedOpt === 'Down Payment') {
                    revDpRow?.classList.remove('hidden');
                    revMonthlyRow?.classList.remove('hidden');
                    const price = getPlotPrice();
                    const dp = parseFloat(document.getElementById('amount_paid')?.value) || (price * 0.30);
                    const monthly = Math.max(0, (price - dp) / 12);
                    document.getElementById('rev-dp').innerText = formatCurrency(dp);
                    document.getElementById('rev-monthly').innerText = formatCurrency(monthly);
                } else {
                    revDpRow?.classList.add('hidden');
                    revMonthlyRow?.classList.add('hidden');
                }
            }
        }

        function nextReservationPhase() {
            startHoldTimer();
            goToStage(2);
        }

        function startHoldTimer() {
            if (holdTimer) clearInterval(holdTimer);
            document.getElementById('hold-timer-banner').classList.remove('hidden');
            document.getElementById('hold-timer-banner').classList.add('flex');
            
            timeLeft = 600;
            holdTimer = setInterval(() => {
                timeLeft--;
                const mins = Math.floor(timeLeft / 60);
                const secs = timeLeft % 60;
                document.getElementById('timer-display').innerText = `${mins}:${secs < 10 ? '0' : ''}${secs}`;

                if (timeLeft <= 0) {
                    clearInterval(holdTimer);
                    alert("Plot reservation timer expired. Please re-select your desired location.");
                    window.location.reload();
                }
            }, 1000);
        }

        // Interactive Payment Card Selection Function
        function selectPaymentOption(element) {
            document.querySelectorAll('#payment-options-container .payment-card').forEach(card => {
                card.className = 'payment-card p-4 border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 rounded-xl cursor-pointer text-xs flex flex-col justify-between hover:border-cyan-500 transition-all space-y-3';
                const title = card.querySelector('span.font-bold');
                if (title) {
                    title.className = 'font-bold text-slate-800 dark:text-slate-200';
                }
            });

            element.className = 'payment-card p-4 border-2 border-cyan-500 bg-cyan-500/10 rounded-xl cursor-pointer text-xs flex flex-col justify-between transition-all space-y-3';
            const activeTitle = element.querySelector('span.font-bold');
            if (activeTitle) {
                activeTitle.className = 'font-bold text-cyan-600 dark:text-cyan-400';
            }

            const radio = element.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
            }

            const panel = document.getElementById('payment-details-panel');
            if (panel) {
                if (radio && radio.value === 'Pay Later') {
                    panel.classList.add('hidden');
                } else {
                    panel.classList.remove('hidden');
                }
            }

            updatePaymentCalculations();
        }

        function getPlotPrice() {
            return parseFloat(document.getElementById('input-plot-price')?.value || 1000);
        }

        function formatCurrency(num) {
            return '₱' + num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function updatePaymentCalculations() {
            const price = getPlotPrice();
            const selectedOpt = document.querySelector('input[name="payment_option"]:checked')?.value || 'Full Payment';
            const amountPaidInput = document.getElementById('amount_paid');
            const minDpEl = document.getElementById('min-dp');
            const monthlyEl = document.getElementById('monthly-dp');

            const minDp = price * 0.30;

            if (minDpEl) minDpEl.innerText = formatCurrency(minDp);

            if (selectedOpt === 'Full Payment') {
                if (amountPaidInput) {
                    amountPaidInput.value = price.toFixed(2);
                    amountPaidInput.min = price.toFixed(2);
                }
                if (monthlyEl) monthlyEl.innerText = '₱0.00';
            } else if (selectedOpt === 'Down Payment') {
                if (amountPaidInput) {
                    const current = parseFloat(amountPaidInput.value) || 0;
                    amountPaidInput.min = minDp.toFixed(2);
                    if (current <= 0 || current < minDp) {
                        amountPaidInput.value = minDp.toFixed(2);
                    }
                    const dp = parseFloat(amountPaidInput.value) || minDp;
                    const monthly = Math.max(0, (price - dp) / 12);
                    if (monthlyEl) monthlyEl.innerText = formatCurrency(monthly);
                }
            } else {
                if (amountPaidInput) {
                    amountPaidInput.value = '';
                    amountPaidInput.min = '0';
                }
                if (monthlyEl) monthlyEl.innerText = '₱0.00';
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            updatePaymentCalculations();
            const amountPaidInput = document.getElementById('amount_paid');
            if (amountPaidInput) {
                amountPaidInput.addEventListener('input', () => {
                    const price = getPlotPrice();
                    const selectedOpt = document.querySelector('input[name="payment_option"]:checked')?.value || 'Full Payment';
                    const monthlyEl = document.getElementById('monthly-dp');
                    if (selectedOpt === 'Down Payment') {
                        const dp = parseFloat(amountPaidInput.value) || 0;
                        const monthly = Math.max(0, (price - dp) / 12);
                        if (monthlyEl) monthlyEl.innerText = formatCurrency(monthly);
                    }
                });
            }
        });

        const PLOT_THUMB_SVG = `<svg viewBox="0 0 56 56" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;display:block;"><defs><linearGradient id="pct-sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#7dd3fc"/><stop offset="1" stop-color="#e0f2fe"/></linearGradient></defs><rect width="56" height="56" fill="url(#pct-sky)"/><ellipse cx="28" cy="52" rx="36" ry="15" fill="#4ade80"/><rect x="25" y="9" width="6" height="32" rx="2.5" fill="#94a3b8"/><rect x="16" y="17" width="24" height="6" rx="2.5" fill="#94a3b8"/><rect x="18" y="38" width="20" height="5" rx="2" fill="#64748b"/></svg>`;

        function escHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        const REC_THUMB_SVG = `<svg viewBox="0 0 96 64" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice" style="width:100%;height:100%;display:block;"><rect width="96" height="64" fill="#14301e"/><rect x="0" y="0" width="40" height="26" fill="#1a4028"/><rect x="52" y="30" width="44" height="34" fill="#173a24"/><path d="M0 44 C 30 40, 60 30, 96 34" stroke="#8a6f4d" stroke-width="4" fill="none"/><path d="M34 64 C 38 40, 40 20, 46 0" stroke="#6d5a3f" stroke-width="2.5" fill="none"/><circle cx="14" cy="12" r="2.5" fill="#0f2417"/><circle cx="22" cy="18" r="2" fill="#0f2417"/><circle cx="70" cy="48" r="2.5" fill="#0f2417"/><circle cx="84" cy="52" r="2" fill="#0f2417"/><rect x="52" y="8" width="18" height="12" fill="#22d3ee" fill-opacity="0.18" stroke="#22d3ee" stroke-width="1.6"/></svg>`;

        const REC_BADGE_CLASS = {
            'Recommended':         'bg-cyan-400 text-slate-950',
            'Admin Pick':          'bg-amber-400 text-slate-950',
            'Lowest price':        'bg-blue-500 text-white',
            'Closest to entrance': 'bg-violet-500 text-white',
            'Near main road':      'bg-teal-400 text-slate-950',
            'Mausoleum':           'bg-rose-400 text-slate-950',
            'Gold lot':            'bg-amber-400 text-slate-950',
            'Premium lot':         'bg-violet-500 text-white',
            'Standard lot':        'bg-slate-500 text-white',
            'Apartment niche':     'bg-emerald-500 text-white',
            'Available pick':      'bg-slate-600 text-white',
            'Best availability':   'bg-orange-500 text-white'
        };

        const REC_BADGE_ICON = {
            'Recommended': 'sparkles', 'Admin Pick': 'award', 'Lowest price': 'trophy',
            'Closest to entrance': 'door-open', 'Near main road': 'route',
            'Mausoleum': 'landmark', 'Apartment niche': 'building-2',
            'Gold lot': 'crown', 'Premium lot': 'crown', 'Standard lot': 'tag',
            'Available pick': 'check-circle-2', 'Best availability': 'flame'
        };

        // Admin-curated recommendation tags (set per-plot in the admin panel)
        const ADMIN_TAG_ICONS = {
            'Near Entrance': 'door-open',
            'Beside Main Road': 'route',
            'Near Parking': 'square-parking',
            'Near Chapel': 'church',
            'Shaded Area': 'tree-pine',
            'Quiet & Private': 'leaf',
            'Scenic View': 'mountain-sun',
            'Easy Access': 'accessibility'
        };

        function parseAdminTags(raw) {
            if (!raw) return [];
            try {
                const t = JSON.parse(raw);
                return Array.isArray(t) ? t.map(String).filter(Boolean) : [];
            } catch (e) {
                return [];
            }
        }

        // Classification of a recommendation card: mausoleum and apartment come
        // from the plot/section type; everything else falls back to its tier.
        function plotClassOf(item) {
            if (item.class_placeholder) return String(item.class_placeholder).toLowerCase();
            const ptype = String(item.plot_type || '').toLowerCase();
            const stype = String(item.section_type || '').toLowerCase();
            if (ptype === 'mausoleum') return 'mausoleum';
            if (ptype === 'apartment' || stype === 'apartment') return 'apartment';
            const tier = String(item.plot_tier || 'standard').toLowerCase();
            return ['gold', 'premium', 'standard'].includes(tier) ? tier : 'standard';
        }
        const CLASS_LABEL = { mausoleum: 'Mausoleum', apartment: 'Apartment', gold: 'Gold', premium: 'Premium', standard: 'Standard' };
        const CLASS_BADGE = { mausoleum: 'Mausoleum', apartment: 'Apartment niche', gold: 'Gold lot', premium: 'Premium lot', standard: 'Standard lot' };

        // Feature chip styling for the recommendation cards
        const FEATURE_CHIP_STYLE = {
            'Near Entrance':    { icon: 'door-open',      cls: 'bg-cyan-500/10 border-cyan-500/25 text-cyan-600 dark:text-cyan-300' },
            'Easy Access':      { icon: 'accessibility',  cls: 'bg-cyan-500/10 border-cyan-500/25 text-cyan-600 dark:text-cyan-300' },
            'Beside Main Road': { icon: 'route',          cls: 'bg-teal-500/10 border-teal-500/25 text-teal-600 dark:text-teal-300' },
            'Near Parking':     { icon: 'square-parking', cls: 'bg-amber-500/10 border-amber-500/25 text-amber-600 dark:text-amber-300' },
            'Near Chapel':      { icon: 'church',         cls: 'bg-violet-500/10 border-violet-500/25 text-violet-600 dark:text-violet-300' },
            'Shaded Area':      { icon: 'tree-pine',      cls: 'bg-emerald-500/10 border-emerald-500/25 text-emerald-600 dark:text-emerald-300' },
            'Quiet & Private':  { icon: 'leaf',           cls: 'bg-emerald-500/10 border-emerald-500/25 text-emerald-600 dark:text-emerald-300' },
            'Scenic View':      { icon: 'mountain-sun',   cls: 'bg-violet-500/10 border-violet-500/25 text-violet-600 dark:text-violet-300' },
            'Premium Location': { icon: 'gem',            cls: 'bg-rose-500/10 border-rose-500/25 text-rose-600 dark:text-rose-300' },
            'Apartment':        { icon: 'building-2',     cls: 'bg-blue-500/10 border-blue-500/25 text-blue-600 dark:text-blue-300' },
            'Affordable':       { icon: 'tag',            cls: 'bg-blue-500/10 border-blue-500/25 text-blue-600 dark:text-blue-300' },
            'Well-Maintained':  { icon: 'shield-check',   cls: 'bg-slate-500/10 border-slate-500/30 text-slate-600 dark:text-slate-300' }
        };

        // Up to two feature chips per card: admin-curated tags first, then
        // features derived from proximity, plot type and price.
        function featureChipsFor(item) {
            const chips = [];
            const push = (label) => { if (label && !chips.includes(label)) chips.push(label); };
            (item._adminTags || []).forEach(push);
            if (item._entranceDist != null && item._entranceDist <= 150) push('Near Entrance');
            if (item._roadDist != null && item._roadDist <= 80) push('Easy Access');
            const cls = plotClassOf(item);
            if (cls === 'mausoleum') { push('Premium Location'); push('Quiet & Private'); }
            if (cls === 'apartment') push('Apartment');
            if (cls === 'apartment' || cls === 'standard' || item._recBadge === 'Lowest price') push('Affordable');
            if (!chips.length) push('Well-Maintained');
            return chips.slice(0, 2);
        }

        function featureChipHtml(label) {
            const st = FEATURE_CHIP_STYLE[label] || { icon: ADMIN_TAG_ICONS[label] || 'sparkles', cls: 'bg-cyan-500/10 border-cyan-500/25 text-cyan-600 dark:text-cyan-300' };
            return `<span class="inline-flex items-center gap-1 px-2 py-1 rounded-md border text-[9px] font-bold ${st.cls}"><i data-lucide="${st.icon}" class="w-2.5 h-2.5"></i>${escHtml(label)}</span>`;
        }

        // Auto-generate a readable description from the admin's selected tags
        const ADMIN_TAG_PHRASES = {
            'Near Entrance': 'conveniently located near the entrance, making visits quick and easy',
            'Beside Main Road': 'positioned beside the main road for effortless access',
            'Near Parking': 'close to the parking area for hassle-free visits',
            'Near Chapel': 'just a short walk from the chapel, ideal for services and prayers',
            'Shaded Area': 'set in a shaded area that stays cool throughout the day',
            'Quiet & Private': 'in a quiet, private spot away from foot traffic',
            'Scenic View': 'offering a scenic view of the memorial grounds',
            'Easy Access': 'easy to reach, suitable for elderly visitors and persons with disabilities'
        };

        const ADMIN_TIER_VALUE = {
            Mausoleum: 'As a Mausoleum-classified space, it is the most exclusive option, offering a permanent private memorial structure.',
            Gold: 'As a Gold-classified lot in a prime location, it is the most expensive option for this level of convenience.',
            Premium: 'As a Premium-classified lot, it is moderately priced for the convenience it offers.',
            Standard: 'As a Standard-classified lot, it is a budget-friendly option that keeps costs low.',
            Apartment: 'As an Apartment-classified niche, it is the most affordable option while remaining dignified and accessible.'
        };

        function adminRecDescription(tags, note, tier) {
            const custom = String(note || '').trim();
            if (custom) return custom;
            const phrases = (tags || []).map(t => ADMIN_TAG_PHRASES[t]).filter(Boolean);
            if (!phrases.length) return '';
            const joined = phrases.length === 1 ? phrases[0]
                : phrases.slice(0, -1).join(', ') + ', and ' + phrases[phrases.length - 1];
            return `This plot is ${joined}. ${ADMIN_TIER_VALUE[tier] || ADMIN_TIER_VALUE.Standard}`;
        }

        // Persuasive per-classification descriptions for the default recommendation showcase
        const TIER_PITCH = {
            mausoleum: 'The Mausoleum offers a premium and secure resting place with elegant, climate-controlled chambers and a serene indoor environment — our most exclusive option, designed for those who seek the highest level of comfort, privacy, and honor.',
            gold: 'The Gold category offers a perfect balance of quality, comfort, and value — a peaceful setting and well-maintained surroundings that provide a respectful and beautiful resting place.',
            premium: 'Our Premium plots are located in the most desirable sections of the cemetery, offering spacious grounds, beautiful landscaping, and a serene environment for eternal rest.',
            standard: 'Our Standard plots provide a clean, peaceful, and dignified resting place — a practical and affordable option for families who want a respectful final home in a well-maintained cemetery.',
            apartment: 'The Apartment offers a respectful and space-efficient option for families — a clean, well-maintained indoor resting place at our most affordable price, making it the most budget-friendly choice.'
        };

        // Best available description for a plot card: admin note > admin tags > tier pitch
        function plotPitch(item) {
            const note = String(item.recommendation_note || '').trim();
            if (note) return note;
            const tagDesc = adminRecDescription(item._adminTags, null, CLASS_LABEL[plotClassOf(item)]);
            if (tagDesc) return tagDesc;
            return TIER_PITCH[plotClassOf(item)] || TIER_PITCH.standard;
        }

        // Accent palette per classification card
        const CLASS_ACCENT = {
            amber:  { title: 'text-amber-500',  iconBg: 'bg-amber-500',  chip: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',    bar: 'bg-gradient-to-r from-amber-500 to-amber-400' },
            blue:   { title: 'text-blue-500',   iconBg: 'bg-blue-500',   chip: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',       bar: 'bg-gradient-to-r from-blue-500 to-blue-400' },
            slate:  { title: 'text-slate-500 dark:text-slate-300', iconBg: 'bg-slate-500', chip: 'bg-slate-500/10 text-slate-600 dark:text-slate-300', bar: 'bg-gradient-to-r from-slate-500 to-slate-400' },
            violet: { title: 'text-violet-500', iconBg: 'bg-violet-500', chip: 'bg-violet-500/10 text-violet-600 dark:text-violet-400', bar: 'bg-gradient-to-r from-violet-500 to-violet-400' },
            teal:   { title: 'text-teal-500',   iconBg: 'bg-teal-500',   chip: 'bg-teal-500/10 text-teal-600 dark:text-teal-400',       bar: 'bg-gradient-to-r from-teal-500 to-teal-400' }
        };

        // Visual identity per classification: header image/art, icon, headline,
        // feature bullets and the closing tagline bar.
        const CLASS_CARD = {
            mausoleum: {
                name: 'MAUSOLEUM', headline: 'The Ultimate in Elegance and Privacy',
                icon: 'landmark', accent: 'violet', img: 'assets/carousel/mausoleum.jpg',
                features: [
                    { icon: 'snowflake',    text: 'Indoor, climate-controlled chambers' },
                    { icon: 'gem',          text: 'Elegant and modern design' },
                    { icon: 'shield-check', text: 'Maximum security' },
                    { icon: 'key-round',    text: 'Exclusive and private access' }
                ],
                tagline: 'The most prestigious and refined choice.'
            },
            gold: {
                name: 'GOLD', headline: 'Balance of Value & Beauty',
                icon: 'star', accent: 'blue', img: 'assets/carousel/cemetery%201.jpg',
                features: [
                    { icon: 'map-pin',      text: 'Convenient location' },
                    { icon: 'ruler',        text: 'Standard plot size' },
                    { icon: 'mountain-sun', text: 'Beautiful surroundings' },
                    { icon: 'lock',         text: 'Well-kept and secure' }
                ],
                tagline: 'Quality and comfort for a lasting tribute.'
            },
            premium: {
                name: 'PREMIUM', headline: 'Prestige & Prime Location',
                icon: 'crown', accent: 'amber', img: 'assets/carousel/cemetery%202.jpg',
                features: [
                    { icon: 'map-pin',    text: 'Prime location near the main garden' },
                    { icon: 'maximize-2', text: 'Spacious plot size' },
                    { icon: 'flower-2',   text: 'Well-maintained landscaping' },
                    { icon: 'shield',     text: 'Peaceful and exclusive section' }
                ],
                tagline: 'A distinguished choice for a meaningful farewell.'
            },
            standard: {
                name: 'STANDARD', headline: 'Simple & Dignified',
                icon: 'leaf', accent: 'slate', img: 'assets/carousel/cemetery%203.jpg',
                features: [
                    { icon: 'tag',           text: 'Affordable pricing' },
                    { icon: 'ruler',         text: 'Standard plot size' },
                    { icon: 'sparkles',      text: 'Clean and organized section' },
                    { icon: 'accessibility', text: 'Easy access within the cemetery' }
                ],
                tagline: 'A simple choice with deep respect.'
            },
            apartment: {
                name: 'APARTMENT', headline: 'Affordable & Practical',
                icon: 'building-2', accent: 'teal', img: 'assets/carousel/apartment.png',
                features: [
                    { icon: 'tag',        text: 'Most affordable option' },
                    { icon: 'building-2', text: 'Indoor, secure facility' },
                    { icon: 'sparkles',   text: 'Clean and well-maintained' },
                    { icon: 'flame',      text: 'Ideal for cremated remains' }
                ],
                tagline: 'A simple, respectful, and affordable resting place.'
            }
        };

        // --- Proximity engine: auto-detects the cemetery entrance & distance to the road ---
        let _proximityCtxPromise = null;

        function extractOuterRingCoords(geo) {
            const rings = [];
            const collect = (g) => {
                if (!g) return;
                if (g.type === 'FeatureCollection') (g.features || []).forEach(f => collect(f.geometry));
                else if (g.type === 'Feature') collect(g.geometry);
                else if (g.type === 'Polygon') rings.push(g.coordinates[0] || []);
                else if (g.type === 'MultiPolygon') g.coordinates.forEach(poly => rings.push(poly[0] || []));
            };
            collect(geo);
            return rings.flat();
        }

        function perimeterVertices() {
            const perimRows = (layoutsData || []).filter(l => !l.section_id && l.geojson);
            const srcRows = perimRows.length ? perimRows : (layoutsData || []).filter(l => l.geojson);
            let verts = [];
            srcRows.forEach(l => {
                try {
                    const g = typeof l.geojson === 'string' ? JSON.parse(l.geojson) : l.geojson;
                    verts = verts.concat(extractOuterRingCoords(g));
                } catch (e) {}
            });
            if (verts.length > 60) {
                const step = verts.length / 60;
                verts = Array.from({ length: 60 }, (_, i) => verts[Math.floor(i * step)]);
            }
            return verts;
        }

        async function osrmNearest(points) {
            if (!points.length) return [];
            const coordStr = points.map(p => `${p[0]},${p[1]}`).join(';');
            const ctl = new AbortController();
            const timer = setTimeout(() => ctl.abort(), 5000);
            try {
                const res = await fetch(`https://router.project-osrm.org/nearest/v1/driving/${coordStr}?number=1`, { signal: ctl.signal });
                const json = await res.json();
                return json.code === 'Ok' ? (json.waypoints || []) : [];
            } catch (e) {
                return [];
            } finally {
                clearTimeout(timer);
            }
        }

        async function getProximityContext() {
            if (!_proximityCtxPromise) {
                _proximityCtxPromise = (async () => {
                    const ctx = { entrance: null };
                    const verts = perimeterVertices();
                    const snaps = await osrmNearest(verts);
                    let best = null;
                    verts.forEach((v, i) => {
                        const w = snaps[i];
                        if (w && (best === null || w.distance < best.distance)) best = w;
                    });
                    if (best && best.location) ctx.entrance = { lat: best.location[1], lng: best.location[0] };
                    return ctx;
                })();
            }
            return _proximityCtxPromise;
        }

        function showEntranceMarker(entrance) {
            if (!window.mapInstance || window._entranceMarker || !entrance) return;
            const icon = L.divIcon({
                className: '',
                html: `<div style="display:flex;flex-direction:column;align-items:center;"><div style="background:#22d3ee;color:#020617;font:800 8px Inter,sans-serif;letter-spacing:.05em;padding:2px 7px;border-radius:999px;box-shadow:0 4px 12px rgba(0,0,0,.45);white-space:nowrap;">ENTRANCE</div><div style="width:2px;height:8px;background:#22d3ee;"></div><div style="width:9px;height:9px;border-radius:50%;background:#22d3ee;border:2px solid #020617;box-shadow:0 0 0 3px rgba(34,211,238,.35);"></div></div>`,
                iconSize: [90, 42],
                iconAnchor: [45, 42]
            });
            window._entranceMarker = L.marker([entrance.lat, entrance.lng], { icon, interactive: false }).addTo(window.mapInstance);
        }

        async function annotateProximity(items) {
            try {
                const ctx = await getProximityContext();
                if (ctx.entrance) showEntranceMarker(ctx.entrance);
                const pts = items.map(it => (it.latitude && it.longitude) ? [parseFloat(it.longitude), parseFloat(it.latitude)] : null);
                const validPts = pts.filter(Boolean);
                const snaps = await osrmNearest(validPts);
                let k = 0;
                items.forEach((it, i) => {
                    it._entranceDist = null;
                    it._roadDist = null;
                    if (!pts[i]) return;
                    const w = snaps[k++];
                    if (w && typeof w.distance === 'number') it._roadDist = w.distance;
                    if (ctx.entrance) it._entranceDist = L.latLng(pts[i][1], pts[i][0]).distanceTo(L.latLng(ctx.entrance.lat, ctx.entrance.lng));
                });
            } catch (e) { /* proximity metrics unavailable */ }
        }

        function computeMatchScores(items) {
            // Absolute-scale scoring: each factor contributes real points so identical
            // plots (e.g. apartment niches sharing one location) don't all cap at 98%.
            const prices = items.map(i => parseFloat(i.price) || 0);
            const maxPrice = Math.max(...prices, 1);
            const n = items.length;
            items.forEach((it, idx) => {
                let score = 60; // baseline: verified available plot matching your filters
                // Price: cheaper plots earn up to 12 pts (scaled against the priciest option)
                score += 12 * (1 - (parseFloat(it.price) || 0) / maxPrice);
                // Entrance: full 12 pts within ~15m, fading to 0 by 200m
                if (it._entranceDist != null) score += 12 * Math.max(0, 1 - it._entranceDist / 200);
                // Road access: full 8 pts within ~10m, fading to 0 by 100m
                if (it._roadDist != null) score += 8 * Math.max(0, 1 - it._roadDist / 100);
                // Admin-curated highlights: 3 pts each, up to 9 pts
                if (it._adminTags && it._adminTags.length) score += Math.min(9, it._adminTags.length * 3);
                // Ordering bonus keeps identical plots ranked predictably (cheapest/first wins)
                if (n > 1) score += 3 * (1 - idx / (n - 1));
                it._recMatch = Math.max(55, Math.min(99, Math.round(score)));
            });
        }

        function assignRecBadges(items, showcase = false) {
            // Showcase cards each carry their own classification badge so all
            // five options stay visually distinct.
            if (showcase) {
                items.forEach(it => {
                    it._recBadge = CLASS_BADGE[plotClassOf(it)];
                    it._recBadgeCls = REC_BADGE_CLASS[it._recBadge];
                });
                return;
            }
            const labels = new Array(items.length).fill(null);
            if (items.length) labels[0] = 'Recommended';
            // Each badge marks the best still-unlabeled plot for that metric —
            // skipped entirely when every plot is identical on that metric.
            const assign = (getVal, label) => {
                const vals = items.map(getVal).filter(v => v !== null && v !== undefined && isFinite(v));
                if (vals.length < 2 || Math.min(...vals) === Math.max(...vals)) return;
                let bi = -1, bv = Infinity;
                items.forEach((it, i) => {
                    if (labels[i]) return;
                    const v = getVal(it);
                    if (v !== null && v !== undefined && isFinite(v) && v < bv) { bv = v; bi = i; }
                });
                if (bi >= 0) labels[bi] = label;
            };
            assign(it => it._entranceDist, 'Closest to entrance');
            assign(it => it._roadDist, 'Near main road');
            assign(it => parseFloat(it.price), 'Lowest price');
            // Admin-curated plots get an 'Admin Pick' badge when no metric badge was earned
            items.forEach((it, i) => {
                if (!labels[i] && it._adminTags && it._adminTags.length) labels[i] = 'Admin Pick';
            });
            items.forEach((it, i) => {
                it._recBadge = labels[i] || CLASS_BADGE[plotClassOf(it)];
                it._recBadgeCls = REC_BADGE_CLASS[it._recBadge];
            });
        }
        const REC_CARD_BASE = 'rec-card group relative rounded-2xl border overflow-hidden transition-all cursor-pointer hover:shadow-xl hover:shadow-cyan-500/10 hover:-translate-y-0.5 flex flex-col';
        const REC_CARD_IDLE = 'border-slate-200 dark:border-[#22334f] bg-white dark:bg-[#0d1a2f] hover:border-cyan-500/60';
        const REC_BTN_IDLE = 'rec-select-btn px-3 py-1.5 rounded-lg bg-slate-200 dark:bg-[#1a2c47] border border-slate-300 dark:border-[#2a3f5f] text-slate-700 dark:text-slate-200 text-[10px] font-bold flex items-center gap-1.5 transition-all hover:bg-slate-300 dark:hover:bg-[#22385a]';
        const REC_BTN_ACTIVE = 'rec-select-btn px-3 py-1.5 rounded-lg bg-cyan-400 text-slate-950 text-[10px] font-bold flex items-center gap-1.5 transition-all shadow-md shadow-cyan-500/30 hover:bg-cyan-300';

        function togglePreferences() {
            document.getElementById('preferences-panel').classList.toggle('hidden');
        }

        function updateSelectedPlotPanel() {
            const hasPlot = !!document.getElementById('input-plot-number').value;
            document.getElementById('selected-plot-empty')?.classList.toggle('hidden', hasPlot);
            document.getElementById('selected-plot-body')?.classList.toggle('hidden', !hasPlot);
        }

        function clearRecCardSelection() {
            selectedRecCard = null;
            document.querySelectorAll('#recommendations-list .rec-card').forEach(c => {
                c.className = `${REC_CARD_BASE} ${REC_CARD_IDLE}`;
                const b = c.querySelector('.rec-select-btn');
                if (b) b.className = REC_BTN_IDLE;
            });
        }

        // Re-clicking the active card toggles back to the initial state:
        // every plot visible on the map again and nothing selected.
        function deselectRecommendedPlot() {
            clearRecCardSelection();
            document.getElementById('input-plot-number').value = '';
            document.getElementById('input-plot-id').value = '';
            document.getElementById('btn-lock-plot').disabled = true;
            window.selectedRecMeta = null;
            if (currentHighlightedPlotLayer && window.mapInstance) {
                window.mapInstance.removeLayer(currentHighlightedPlotLayer);
                currentHighlightedPlotLayer = null;
            }
            updateSelectedPlotPanel();
            applyClassMapFilter(null);
        }

        function selectRecommendedPlot(item, card) {
            if (card === selectedRecCard) {
                deselectRecommendedPlot();
                return;
            }
            clearRecCardSelection();
            selectedRecCard = card;
            card.className = `${REC_CARD_BASE} border-cyan-400 dark:border-cyan-400 bg-cyan-500/5 dark:bg-cyan-500/5 ring-1 ring-cyan-400/40`;
            const btn = card.querySelector('.rec-select-btn');
            if (btn) btn.className = REC_BTN_ACTIVE;

            const typeLabel = item.plot_type || ((item.section_type || '') === 'Apartment' ? 'Apartment' : 'Standard Lot');

            document.getElementById('summary-section').innerText = item.title || item.section_code;
            document.getElementById('input-section-code').value = item.section_code;
            document.getElementById('summary-plot-num').innerText = item.plot_number;
            document.getElementById('input-plot-number').value = item.plot_number;
            document.getElementById('input-plot-id').value = item.id;
            document.getElementById('summary-plot-price').innerText = formatCurrency(parseFloat(item.price) || 0);
            document.getElementById('input-plot-price').value = item.price;
            updatePaymentCalculations();
            document.getElementById('btn-lock-plot').disabled = false;

            document.getElementById('summary-plot-type').innerText = typeLabel;
            document.getElementById('summary-plot-size').innerText = item.plot_size || '1.0m x 2.4m';
            document.getElementById('sp-subtitle').innerText = `${item.title || item.section_code} • ${typeLabel}`;
            document.getElementById('sp-thumb').innerHTML = REC_THUMB_SVG;
            const badgeEl = document.getElementById('sp-badge');
            badgeEl.innerText = item._recBadge || 'Recommended';
            badgeEl.className = `px-2 py-0.5 rounded-full ${item._recBadge === 'Admin Pick' ? 'bg-amber-400' : 'bg-cyan-500'} text-slate-950 text-[9px] font-extrabold uppercase tracking-wide`;
            let whyText = item.over_budget
                ? `Closest available option just above your budget (${item._recMatch}% match).`
                : `Best match for your section, budget and plot type (${item._recMatch}% match).`;
            const whyBits = [];
            if (item._entranceDist != null) whyBits.push(`~${Math.round(item._entranceDist)} m from the entrance`);
            if (item._roadDist != null) whyBits.push(`~${Math.round(item._roadDist)} m from the road`);
            if (whyBits.length) whyText += ' ' + whyBits.join(' • ') + '.';
            if (item._adminTags && item._adminTags.length) whyText += ` Admin highlights: ${item._adminTags.join(' • ')}.`;
            const pitch = item._pitch || adminRecDescription(item._adminTags, item.recommendation_note, CLASS_LABEL[plotClassOf(item)]);
            if (pitch) whyText += ` "${pitch}"`;
            document.getElementById('sp-why').innerText = whyText;
            window.selectedRecMeta = { badge: item._recBadge, match: item._recMatch };
            updateSelectedPlotPanel();

            if (selectedPlotBtn) {
                selectedPlotBtn.className = 'h-10 w-full rounded-lg text-[10px] leading-none break-words px-1 flex items-center justify-center bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 font-bold';
                selectedPlotBtn = null;
            }
            // Show every plot in this card's classification on the map and
            // hide the rest (e.g. clicking GOLD leaves only gold plots).
            applyClassMapFilter(plotClassOf(item));

            if (window.mapInstance && item.latitude && item.longitude) {
                if (currentHighlightedPlotLayer) window.mapInstance.removeLayer(currentHighlightedPlotLayer);
                currentHighlightedPlotLayer = L.circleMarker([parseFloat(item.latitude), parseFloat(item.longitude)], {
                    radius: 12, color: '#00ffff', fillColor: '#22d3ee', fillOpacity: 0.8, weight: 3
                }).addTo(window.mapInstance);
            }
        }

        function buildPlotPopupHtml(rec) {
            const statusRaw = (rec.status || 'Available').toLowerCase();
            const isOccupied = statusRaw === 'occupied' || (rec.deceased_name && String(rec.deceased_name).trim() !== '');
            const statusLabel = isOccupied ? 'Occupied' : (statusRaw === 'reserved' ? 'Reserved' : (statusRaw === 'pending approval' ? 'Pending' : 'Available'));
            const badgeColor = isOccupied ? '#64748b' : (statusRaw === 'reserved' ? '#8b5cf6' : (statusRaw === 'pending approval' ? '#f59e0b' : '#10b981'));

            const key = String(rec.id || ((rec.section_code || 'sec') + '_' + (rec.plot_number || 'x'))).replace(/'/g, '');
            window.popupPlotsMap = window.popupPlotsMap || {};
            window.popupPlotsMap[key] = rec;

            const secCode = rec.section_code || rec.section_section_code || 'PLOT';
            const plotNum = rec.plot_number || '?';
            const code = escHtml(String(plotNum).includes(secCode) ? plotNum : `${secCode}-${plotNum}`);
            const sectionLabel = escHtml(`${rec.section_name || 'Cemetery Section'} - Plot ${rec.plot_number || ''}`);
            const plotType = escHtml(rec.plot_type || 'Single');
            const plotSize = escHtml(rec.plot_size || '2.5m x 2.5m');
            const owner = escHtml(isOccupied && rec.deceased_name ? rec.deceased_name : 'N/A');
            const burial = escHtml(rec.reservation_date || 'N/A');

            const tierLower = (rec.plot_tier || 'Standard').toLowerCase();
            const tierClass = tierLower === 'gold' ? 'gold' : (tierLower === 'premium' ? 'premium' : 'standard');
            const tierIcon = tierLower === 'gold' ? 'crown' : (tierLower === 'premium' ? 'gem' : 'shield');
            const tierName = tierLower === 'gold' ? 'Gold Lot' : (tierLower === 'premium' ? 'Premium Lot' : 'Standard Lot');
            const tierBadge = tierLower === 'gold' ? 'Premium' : (tierLower === 'premium' ? 'Elite' : 'Standard');

            const canSelect = !isOccupied && statusRaw === 'available';

            const popupAdminTags = parseAdminTags(rec.recommendations);
            const popupAdminDesc = adminRecDescription(popupAdminTags, rec.recommendation_note, CLASS_LABEL[plotClassOf(rec)]);
            const adminRecHtml = (popupAdminTags.length || popupAdminDesc) ? `
                <div class="plot-rec">
                    <div class="plot-rec-title"><i data-lucide="sparkles"></i>Admin Recommended</div>
                    ${popupAdminTags.length ? `<div class="plot-rec-tags">${popupAdminTags.map(t => `<span class="plot-rec-tag"><i data-lucide="${ADMIN_TAG_ICONS[t] || 'sparkles'}"></i>${escHtml(t)}</span>`).join('')}</div>` : ''}
                    ${popupAdminDesc ? `<div class="plot-rec-note">&ldquo;${escHtml(popupAdminDesc)}&rdquo;</div>` : ''}
                </div>` : '';

            return `
            <div class="plot-card">
                <div class="plot-card-head">
                    <div class="plot-thumb">${PLOT_THUMB_SVG}</div>
                    <div class="plot-head-text">
                        <div class="plot-title-row">
                            <span class="plot-title">${code}</span>
                            <span class="plot-badge" style="background:${badgeColor}22;color:${badgeColor};">${statusLabel}</span>
                        </div>
                        <div class="plot-sub"><i data-lucide="map-pin"></i>${sectionLabel}</div>
                    </div>
                </div>
                <div class="plot-details">
                    <div class="plot-detail">
                        <span class="plot-detail-ic"><i data-lucide="layout-grid"></i></span>
                        <span><span class="plot-detail-label">Plot Type</span><span class="plot-detail-value">${plotType}</span></span>
                    </div>
                    <div class="plot-detail">
                        <span class="plot-detail-ic"><i data-lucide="ruler"></i></span>
                        <span><span class="plot-detail-label">Size</span><span class="plot-detail-value">${plotSize}</span></span>
                    </div>
                    <div class="plot-detail">
                        <span class="plot-detail-ic"><i data-lucide="user"></i></span>
                        <span><span class="plot-detail-label">Owner</span><span class="plot-detail-value">${owner}</span></span>
                    </div>
                    <div class="plot-detail">
                        <span class="plot-detail-ic"><i data-lucide="calendar"></i></span>
                        <span><span class="plot-detail-label">Burial Date</span><span class="plot-detail-value">${burial}</span></span>
                    </div>
                </div>
                <div class="plot-tier ${tierClass}">
                    <span class="plot-tier-ic"><i data-lucide="${tierIcon}"></i></span>
                    <span class="plot-tier-name">${tierName}</span>
                    <span class="plot-tier-badge">${tierBadge}</span>
                    <i data-lucide="info" class="plot-tier-info"></i>
                </div>
                ${adminRecHtml}
                <div class="plot-actions">
                    <button type="button" class="plot-btn plot-btn-primary" ${canSelect ? `onclick="popupSelectPlot('${key}')"` : 'disabled'}>
                        <i data-lucide="${canSelect ? 'check-circle-2' : 'lock'}"></i>${canSelect ? 'Select Plot' : statusLabel}
                    </button>
                </div>
            </div>`;
        }

        function popupSelectPlot(key) {
            const rec = (window.popupPlotsMap || {})[key];
            if (!rec) return;
            if (window.mapInstance) window.mapInstance.closePopup();
            openPlotFromMap(rec);
        }

        function renderPlots(plots) {
            if (!window.plotsLayer) return;
            window.plotsLayer.clearLayers();
            if (!plots || plots.length === 0) return;

            const popupOpts = { className: 'plot-popup', maxWidth: 320, autoPanPadding: L.point(30, 30) };

            plots.forEach(rec => {
                const statusRaw = (rec.status || 'Available').toLowerCase();
                const isOccupied = statusRaw === 'occupied' || (rec.deceased_name && rec.deceased_name.trim() !== '');

                const tierRaw = (rec.plot_tier || 'standard').toLowerCase();
                const statusColor = tierRaw === 'gold' ? '#eab308' : (tierRaw === 'premium' ? '#94a3b8' : (isOccupied ? '#64748b' : (statusRaw === 'reserved' ? '#8b5cf6' : (statusRaw === 'pending approval' ? '#f59e0b' : '#10b981'))));

                const popupHtml = buildPlotPopupHtml(rec);

                if (rec.geojson_shape) {
                    try {
                        const plotGeo = typeof rec.geojson_shape === 'string' ? JSON.parse(rec.geojson_shape) : rec.geojson_shape;
                        const plotLayer = L.geoJSON(plotGeo, {
                            style: {
                                color: statusColor,
                                fillColor: statusColor,
                                fillOpacity: 0.6,
                                weight: 2
                            }
                        });
                        plotLayer.bindPopup(popupHtml, popupOpts);
                        plotLayer.on('click', (e) => {
                            L.DomEvent.stopPropagation(e);
                        });
                        window.plotsLayer.addLayer(plotLayer);
                    } catch (e) { console.error('Plot GeoJSON parse error:', e); }
                }

                if (rec.latitude && rec.longitude) {
                    const dot = L.circleMarker([rec.latitude, rec.longitude], {
                        radius: 7,
                        color: '#ffffff',
                        weight: 2.5,
                        fillColor: 'rgba(2, 6, 23, 0.35)',
                        fillOpacity: 1
                    });
                    dot.bindPopup(popupHtml, popupOpts);
                    dot.on('click', (e) => {
                        if (e.originalEvent) e.originalEvent.stopPropagation();
                    });
                    window.plotsLayer.addLayer(dot);
                }
            });
        }

        // Restrict the map to a single recommendation classification
        // (gold / premium / standard / apartment / mausoleum); pass null to
        // restore every plot.
        function applyClassMapFilter(cls) {
            activeClassFilter = cls || null;
            const label = document.getElementById('map-layer-label');
            const clearBtn = document.getElementById('btn-clear-class-filter');

            const plots = activeClassFilter
                ? (adminPlotsData || []).filter(p => plotClassOf(p) === activeClassFilter)
                : (adminPlotsData || []);
            renderPlots(plots);

            if (label) label.innerText = activeClassFilter ? `${CLASS_LABEL[activeClassFilter] || 'Recommended'} Plots` : 'Interactive Map Layer';
            if (clearBtn) {
                clearBtn.classList.toggle('hidden', !activeClassFilter);
                clearBtn.classList.toggle('inline-flex', !!activeClassFilter);
            }

            if (window.mapInstance && window.plotsLayer && window.plotsLayer.getLayers().length) {
                try {
                    const bounds = L.featureGroup(window.plotsLayer.getLayers()).getBounds();
                    window.mapInstance.flyToBounds(bounds, { padding: [50, 50], maxZoom: 19, duration: 1.0 });
                } catch (e) {}
            }
        }

        function clearClassMapFilter() {
            applyClassMapFilter(null);
        }

        function initMap() {
            const map = L.map('map').setView([14.5985, 120.9830], 18);
            window.mapInstance = map;

            const satelliteTile = L.tileLayer(satelliteTileUrl, { maxNativeZoom: 20, maxZoom: 22, attribution: '&copy; Google Maps' }).addTo(map);
            const streetTile = L.tileLayer(osmTileUrl, { maxNativeZoom: 19, maxZoom: 22, attribution: '&copy; OpenStreetMap contributors' });

            const mapFeatures = L.featureGroup().addTo(map);
            window.plotsLayer = L.layerGroup().addTo(map);

            // Custom basemap pill toggle (bottom-left)
            const basemapCtl = L.control({ position: 'bottomleft' });
            basemapCtl.onAdd = function () {
                const div = L.DomUtil.create('div', 'basemap-toggle');
                [['Map', 'map'], ['Satellite', 'sat']].forEach(([label, base]) => {
                    const b = L.DomUtil.create('button', 'bm-btn' + (base === 'sat' ? ' active' : ''), div);
                    b.type = 'button';
                    b.innerText = label;
                    L.DomEvent.on(b, 'click', (e) => {
                        L.DomEvent.stop(e);
                        div.querySelectorAll('.bm-btn').forEach(x => x.classList.remove('active'));
                        b.classList.add('active');
                        if (base === 'sat') { map.removeLayer(streetTile); satelliteTile.addTo(map); }
                        else { map.removeLayer(satelliteTile); streetTile.addTo(map); }
                    });
                });
                L.DomEvent.disableClickPropagation(div);
                L.DomEvent.disableScrollPropagation(div);
                return div;
            };
            basemapCtl.addTo(map);

            // Re-center button (bottom-right)
            const recenterCtl = L.control({ position: 'bottomright' });
            recenterCtl.onAdd = function () {
                const div = L.DomUtil.create('div', 'recenter-btn');
                div.innerHTML = '<i data-lucide="locate-fixed" class="w-4 h-4"></i>';
                div.title = 'Re-center map';
                L.DomEvent.on(div, 'click', (e) => {
                    L.DomEvent.stop(e);
                    if (mapFeatures.getLayers().length > 0) map.fitBounds(mapFeatures.getBounds(), { padding: [40, 40] });
                });
                L.DomEvent.disableClickPropagation(div);
                return div;
            };
            recenterCtl.addTo(map);
            if (window.lucide) lucide.createIcons();

            map.on('popupopen', () => { if (window.lucide) lucide.createIcons(); });

            if (layoutsData && layoutsData.length > 0) {
                layoutsData.forEach(layout => {
                    if (!layout.geojson) return;
                    let geojsonObj = typeof layout.geojson === 'string' ? JSON.parse(layout.geojson) : layout.geojson;
                    let layer = L.geoJSON(geojsonObj, {
                        style: { color: layout.color || '#22d3ee', fillColor: layout.color || '#22d3ee', fillOpacity: 0.35, weight: 2 }
                    });

                    if (layout.section_id && layout.section_code) {
                        layer.on('click', (e) => {
                            L.DomEvent.stopPropagation(e);

                            map.fitBounds(e.target.getBounds(), {
                                padding: [20, 20],
                                maxZoom: 20,
                                animate: true
                            });

                            const isApartment = (layout.section_type || '').toLowerCase() === 'apartment';
                            if (isApartment) {
                                // Wait for the zoom animation to finish before revealing the 3D view
                                map.once('moveend', () => {
                                    setTimeout(() => loadPlotsForSection(layout), 400);
                                });
                            } else {
                                loadPlotsForSection(layout);
                            }
                        });
                    }

                    mapFeatures.addLayer(layer);
                });
            }

            renderPlots(adminPlotsData);

            if (mapFeatures.getLayers().length > 0) {
                map.fitBounds(mapFeatures.getBounds(), { padding: [40, 40] });
            }
        }

        function loadPlotsForSection(layout, targetPlotNumber = null) {
            window.currentSection = layout;
            window.currentSectionPlots = [];
            window.plotGridButtons = {};

            // Clicking a section takes over the map — drop any active
            // recommendation-class filter and restore the default label.
            activeClassFilter = null;
            const clearFilterBtn = document.getElementById('btn-clear-class-filter');
            if (clearFilterBtn) { clearFilterBtn.classList.add('hidden'); clearFilterBtn.classList.remove('inline-flex'); }
            document.getElementById('map-layer-label').innerText = 'Interactive Map Layer';

            if ((layout.section_type || '').toLowerCase() === 'apartment') {
                showApartment3DView();
            } else if (apartment3DInlineActive) {
                showMapView();
                if (selectedPurpose !== 'Apartment Niche') {
                    const toggle3dBtn = document.getElementById('btn-toggle-3d');
                    toggle3dBtn.classList.add('hidden');
                    toggle3dBtn.classList.remove('flex');
                }
            }
            document.getElementById('summary-section').innerText = layout.title || layout.section_code;
            document.getElementById('input-section-code').value = layout.section_code;
            document.getElementById('summary-plot-price').innerText = `$${parseFloat(layout.price || 1000).toFixed(2)}`;
            document.getElementById('input-plot-price').value = layout.price || 1000.00;
            updatePaymentCalculations();

            document.getElementById('summary-plot-num').innerText = '—';
            document.getElementById('input-plot-number').value = '';
            document.getElementById('input-plot-id').value = '';
            document.getElementById('btn-lock-plot').disabled = true;

            const sectionTypeGuess = (layout.section_type || '').toLowerCase() === 'apartment' ? 'Apartment' : '—';
            document.getElementById('summary-plot-type').innerText = sectionTypeGuess;
            document.getElementById('sp-subtitle').innerText = `${layout.title || layout.section_code} • Select a plot`;
            document.getElementById('sp-badge').className = 'hidden px-2 py-0.5 rounded-full bg-cyan-500 text-slate-950 text-[9px] font-extrabold uppercase tracking-wide';
            document.getElementById('sp-why').innerText = '—';
            document.getElementById('sp-thumb').innerHTML = '';
            window.selectedRecMeta = null;
            clearRecCardSelection();
            updateSelectedPlotPanel();

            if (selectedPlotBtn) {
                selectedPlotBtn.className = 'h-10 w-full rounded-lg text-[10px] leading-none break-words px-1 flex items-center justify-center bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 font-bold';
                selectedPlotBtn = null;
            }
            if (currentHighlightedPlotLayer && window.mapInstance) {
                window.mapInstance.removeLayer(currentHighlightedPlotLayer);
                currentHighlightedPlotLayer = null;
            }

            fetch(`reservation.php?action=get_plots&section_code=${encodeURIComponent(layout.section_code)}`)
                .then(res => res.json())
                .then(res => {
                    const grid = document.getElementById('plot-grid');
                    grid.innerHTML = '';
                    renderPlots(res.data);

                    // Apartment grids mirror the niches-per-level the admin configured
                    const aptPerLevel = parseInt(layout.apt_per_level) || 0;
                    if ((layout.section_type || '').toLowerCase() === 'apartment' && aptPerLevel > 0) {
                        grid.style.gridTemplateColumns = `repeat(${Math.min(aptPerLevel, 12)}, minmax(0, 1fr))`;
                    } else {
                        grid.style.gridTemplateColumns = '';
                    }

                    // Keep current section's plots for the 3D apartment visualizer
                    window.currentSectionPlots = res.data || [];
                    window.plotGridButtons = {};
                    if ((layout.section_type || '').toLowerCase() === 'apartment' && apartment3DInlineActive) {
                        init3DApartmentScene('apartment-3d-inline');
                    }

                    if (!res.data || res.data.length === 0) {
                        grid.innerHTML = '<div class="col-span-5 text-center py-8 text-xs text-slate-400 dark:text-slate-500">No plots configured for this section.</div>';
                        return;
                    }

                    res.data.forEach(p => {
                        const plotNum = p.plot_number;
                        const statusRaw = p.status || 'Available';
                        const status = statusRaw.toLowerCase();
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.innerText = plotNum;
                        btn.dataset.plotnum = plotNum;
                        const plotTier = (p.plot_tier || 'standard').toLowerCase();
                        const plotAdminTags = parseAdminTags(p.recommendations);
                        const isAdminPick = plotAdminTags.length > 0 || (p.recommendation_note || '').trim() !== '';
                        btn.title = plotNum + ' — ' + (p.plot_tier || 'Standard') + ' Lot' + (isAdminPick ? ' — Admin Pick: ' + (plotAdminTags.join(', ') || 'Recommended by admin') : '');
                        window.plotGridButtons[plotNum] = btn;

                        if (status === 'occupied') {
                            btn.className = 'h-10 w-full rounded-lg text-[10px] leading-none break-words px-1 flex items-center justify-center bg-slate-500/10 text-slate-500 dark:text-slate-400 border border-slate-500/20 cursor-not-allowed';
                        } else if (status === 'reserved') {
                            btn.className = 'h-10 w-full rounded-lg text-[10px] leading-none break-words px-1 flex items-center justify-center bg-violet-500/10 text-violet-500 border border-violet-500/20 cursor-not-allowed';
                        } else if (status === 'pending approval') {
                            btn.className = 'h-10 w-full rounded-lg text-[10px] leading-none break-words px-1 flex items-center justify-center bg-amber-500/10 text-amber-500 border border-amber-500/20 cursor-not-allowed';
                        } else {
                            btn.className = 'h-10 w-full rounded-lg text-[10px] leading-none break-words px-1 flex items-center justify-center bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500 hover:text-white dark:hover:text-slate-950 font-bold transition-all';
                            if (plotTier === 'gold') {
                                btn.style.borderColor = '#eab308';
                                btn.style.color = '#ca8a04';
                                btn.style.backgroundColor = 'rgba(234, 179, 8, 0.1)';
                            } else if (plotTier === 'premium') {
                                btn.style.borderColor = '#94a3b8';
                                btn.style.color = '#64748b';
                                btn.style.backgroundColor = 'rgba(148, 163, 184, 0.1)';
                            }
                            if (isAdminPick) {
                                const starCls = plotTier === 'gold' ? 'text-amber-400 fill-amber-400' : (plotTier === 'premium' ? 'text-slate-300 fill-slate-300' : 'text-sky-300 fill-sky-300');
                                const ringCls = plotTier === 'gold' ? 'ring-amber-400/70' : (plotTier === 'premium' ? 'ring-slate-300/70' : 'ring-sky-300/70');
                                btn.classList.add('ring-1', ringCls, 'relative');
                                btn.innerHTML = escHtml(plotNum) + `<i data-lucide="star" class="w-2.5 h-2.5 absolute -top-1 -right-1 ${starCls}"></i>`;
                            }

                            btn.onclick = () => {
                                if (selectedPlotBtn) selectedPlotBtn.className = 'h-10 w-full rounded-lg text-[10px] leading-none break-words px-1 flex items-center justify-center bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 font-bold';
                                btn.className = 'h-10 w-full rounded-lg text-[10px] leading-none break-words px-1 flex items-center justify-center bg-cyan-500 dark:bg-cyan-400 text-white dark:text-slate-950 font-extrabold ring-2 ring-cyan-300';
                                selectedPlotBtn = btn;

                                document.getElementById('summary-plot-num').innerText = plotNum;
                                document.getElementById('input-plot-number').value = plotNum;
                                document.getElementById('input-plot-id').value = p.id;
                                document.getElementById('summary-plot-price').innerText = `$${parseFloat(p.price || layout.price || 1000).toFixed(2)}`;
                                document.getElementById('input-plot-price').value = p.price || layout.price || 1000.00;
                                updatePaymentCalculations();
                                document.getElementById('btn-lock-plot').disabled = false;

                                const gridTypeLabel = p.plot_type || ((layout.section_type || '').toLowerCase() === 'apartment' ? 'Apartment' : 'Standard Lot');
                                document.getElementById('summary-plot-type').innerText = gridTypeLabel;
                                document.getElementById('summary-plot-size').innerText = p.plot_size || '1.0m x 2.4m';
                                document.getElementById('sp-subtitle').innerText = `${layout.title || layout.section_code} • ${gridTypeLabel}`;
                                document.getElementById('sp-thumb').innerHTML = REC_THUMB_SVG;
                                const spBadgeEl = document.getElementById('sp-badge');
                                let gridWhy = 'Selected from this section. Availability and pricing verified in real time.';
                                if (plotAdminTags.length) gridWhy = `Admin highlights: ${plotAdminTags.join(' • ')}. ` + gridWhy;
                                const gridAdminDesc = adminRecDescription(plotAdminTags, p.recommendation_note, CLASS_LABEL[plotClassOf(p)]);
                                if (gridAdminDesc) gridWhy += ` "${gridAdminDesc}"`;
                                document.getElementById('sp-why').innerText = gridWhy;
                                if (isAdminPick) {
                                    spBadgeEl.innerText = 'Admin Pick';
                                    spBadgeEl.className = 'px-2 py-0.5 rounded-full bg-amber-400 text-slate-950 text-[9px] font-extrabold uppercase tracking-wide';
                                } else {
                                    spBadgeEl.className = 'hidden px-2 py-0.5 rounded-full bg-cyan-500 text-slate-950 text-[9px] font-extrabold uppercase tracking-wide';
                                }
                                window.selectedRecMeta = null;
                                clearRecCardSelection();
                                updateSelectedPlotPanel();

                                if (window.mapInstance) {
                                    if (currentHighlightedPlotLayer) {
                                        window.mapInstance.removeLayer(currentHighlightedPlotLayer);
                                    }

                                    if (p.geojson_shape) {
                                        try {
                                            const plotGeo = typeof p.geojson_shape === 'string' ? JSON.parse(p.geojson_shape) : p.geojson_shape;
                                            currentHighlightedPlotLayer = L.geoJSON(plotGeo, {
                                                style: { color: '#00ffff', fillColor: '#00ffff', fillOpacity: 0.6, weight: 3 }
                                            }).addTo(window.mapInstance);

                                            window.mapInstance.fitBounds(currentHighlightedPlotLayer.getBounds(), {
                                                padding: [50, 50],
                                                maxZoom: 21,
                                                animate: true
                                            });
                                        } catch (e) { console.error('GeoJSON parse error:', e); }
                                    } 
                                    else if (p.latitude && p.longitude) {
                                        const lat = parseFloat(p.latitude);
                                        const lng = parseFloat(p.longitude);

                                        currentHighlightedPlotLayer = L.circleMarker([lat, lng], {
                                            radius: 12,
                                            color: '#00ffff',
                                            fillColor: '#22d3ee',
                                            fillOpacity: 0.8,
                                            weight: 3
                                        }).addTo(window.mapInstance);

                                        window.mapInstance.flyTo([lat, lng], 21, { animate: true, duration: 1.2 });
                                    }
                                }

                                if ((layout.section_type || '').toLowerCase() === 'apartment') {
                                    highlightApartmentNiche(plotNum);
                                }
                            };
                        }
                        grid.appendChild(btn);

                        if (targetPlotNumber && plotNum === targetPlotNumber && btn.onclick) {
                            btn.click();
                            btn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    });
                    if (window.lucide) lucide.createIcons();
                });
        }

        function openPlotFromMap(plot) {
            const sectionCode = plot.section_code || plot.section_section_code;
            const sectionId = plot.section_id;
            const plotNum = plot.plot_number;
            if ((!sectionCode && !sectionId) || !plotNum) return;

            const layout = (layoutsData || []).find(l => l.section_id && (
                (sectionCode && l.section_code === sectionCode) ||
                (sectionId && String(l.section_id) === String(sectionId))
            ));
            if (!layout) {
                console.warn('No section layout found for plot', plot);
                return;
            }

            if (window.currentSection && window.currentSection.section_code === sectionCode) {
                const grid = document.getElementById('plot-grid');
                const btn = Array.from(grid.children).find(b => (b.dataset.plotnum || b.innerText) === plotNum);
                if (btn && btn.onclick) {
                    btn.click();
                    btn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    return;
                }
            }

            loadPlotsForSection(layout, plotNum);
        }

        async function loadRecommendations() {
            // Changing preferences produces a new recommendation set — drop
            // any classification filter a previous card click applied.
            if (activeClassFilter) applyClassMapFilter(null);

            const section = document.getElementById('filter-section').value;
            const budgetRaw = parseFloat(document.getElementById('filter-budget').value);
            const budget = (isNaN(budgetRaw) || budgetRaw < 0) ? '' : budgetRaw;
            const ptype = document.getElementById('filter-type')?.value || '';

            const container = document.getElementById('recommendations-container');
            const list = document.getElementById('recommendations-list');
            const prompt = document.getElementById('recommendations-prompt');
            const subtitle = document.getElementById('recommendations-subtitle');

            // Warm up the entrance lookup so it runs in parallel with the
            // recommendation fetch instead of after it.
            getProximityContext();

            // Use the server-preloaded showcase for the default view so the
            // cards render instantly; any filter change fetches fresh data.
            const res = (_preloadedRecs && !section && budget === '' && !ptype)
                ? _preloadedRecs
                : await fetch(`reservation.php?action=recommend_plots&section_code=${encodeURIComponent(section)}&max_budget=${encodeURIComponent(budget)}&plot_type=${encodeURIComponent(ptype)}`).then(r => r.json());
            _preloadedRecs = null;

            if (res.status === 'success' && res.data.length > 0) {
                res.data.forEach(it => {
                    it._adminTags = parseAdminTags(it.recommendations);
                    it._pitch = plotPitch(it);
                });
                // Render right away — proximity lookups finish in the background
                // and only refine ordering, badges and the details panel.
                const proximityDone = annotateProximity(res.data);
                computeMatchScores(res.data);
                // Showcase order is curated (Gold → Premium → Standard → Apartment →
                // Mausoleum); budget mode is already ordered lowest → budget →
                // closest above budget. Keep both as-is.
                if (!res.showcase && res.budget == null) res.data.sort((a, b) => b._recMatch - a._recMatch);
                assignRecBadges(res.data, res.showcase);
                const hasOverBudget = res.data.some(it => it.over_budget);
                if (subtitle) subtitle.innerText = res.showcase
                    ? 'Based on your preferences and popular choices.'
                    : (hasOverBudget
                        ? 'Closest to your budget, plus the nearest options just above it.'
                        : 'Based on your section, budget, and plot type');

                list.innerHTML = '';
                container.classList.remove('hidden');
                prompt?.classList.add('hidden');

                const firstPickable = res.data.findIndex(it => !it.class_placeholder);
                res.data.forEach((item, idx) => {
                    const cls = plotClassOf(item);
                    const st = CLASS_CARD[cls] || CLASS_CARD.standard;
                    const ac = CLASS_ACCENT[st.accent];
                    const isPlaceholder = !!item.class_placeholder;
                    const isTop = idx === firstPickable;
                    const headerHtml = st.img
                        ? `<img src="${st.img}" alt="${st.name}" class="w-full h-full object-cover">`
                        : st.svg;
                    const featuresHtml = st.features.map(f =>
                        `<li class="flex items-center gap-1.5"><span class="w-4 h-4 rounded-full ${ac.chip} flex items-center justify-center flex-shrink-0"><i data-lucide="${f.icon}" class="w-2.5 h-2.5"></i></span><span class="text-[9px] font-medium text-slate-500 dark:text-slate-400 leading-tight">${escHtml(f.text)}</span></li>`
                    ).join('');

                    const card = document.createElement('div');
                    const baseCls = isPlaceholder ? REC_CARD_BASE.replace('cursor-pointer', 'cursor-default') : REC_CARD_BASE;
                    card.className = `${baseCls} ${isTop ? 'border-cyan-400 dark:border-cyan-400 bg-cyan-500/5 ring-1 ring-cyan-400/40' : (item.over_budget ? 'border-amber-300/70 dark:border-amber-500/30 bg-white dark:bg-[#0d1a2f] hover:border-amber-400' : REC_CARD_IDLE)}`;
                    card.innerHTML = `
                        <div class="relative h-28 flex-shrink-0">
                            ${headerHtml}
                            <div class="absolute inset-x-0 bottom-0 h-12 bg-gradient-to-t from-black/40 to-transparent"></div>
                            <div class="absolute left-1/2 bottom-0 -translate-x-1/2 translate-y-1/2 w-10 h-10 rounded-full ${ac.iconBg} border-[3px] border-white dark:border-slate-900 shadow-lg flex items-center justify-center">
                                <i data-lucide="${st.icon}" class="w-4 h-4 text-white"></i>
                            </div>
                        </div>
                        <div class="px-3.5 pt-6 pb-3.5 flex flex-col flex-1">
                            <div class="text-center text-sm font-extrabold tracking-widest ${ac.title}">${st.name}</div>
                            <div class="text-center text-[8px] font-extrabold uppercase tracking-[0.15em] text-slate-400 dark:text-slate-500 mt-0.5">${escHtml(st.headline)}</div>
                            <p class="mt-2 text-[10px] leading-snug text-slate-500 dark:text-slate-400">${escHtml(item._pitch || '')}</p>
                            <ul class="mt-2.5 space-y-1.5">${featuresHtml}</ul>
                            <div class="mt-3 rounded-lg ${ac.bar} text-white text-center text-[9px] font-bold italic px-2 py-1.5 shadow-sm">${escHtml(st.tagline)}</div>
                            <div class="mt-auto pt-2.5 flex items-end justify-between gap-2">
                                <div>
                                    <div class="text-sm font-extrabold text-slate-900 dark:text-white">${item.price != null ? (isPlaceholder ? 'From ' : '') + formatCurrency(parseFloat(item.price) || 0) : '—'}</div>
                                    ${isPlaceholder
                                        ? `<div class="flex items-center gap-1 text-[10px] font-semibold text-amber-500 mt-0.5"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> No plots available</div>`
                                        : `<div class="flex items-center gap-1 text-[10px] font-semibold text-emerald-500 mt-0.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Available</div>`}
                                    ${item.over_budget ? `<div class="flex items-center gap-1 text-[9px] font-bold text-amber-500 mt-0.5"><i data-lucide="trending-up" class="w-2.5 h-2.5"></i> Above budget</div>` : ''}
                                </div>
                                ${isPlaceholder
                                    ? `<button type="button" disabled class="${REC_BTN_IDLE} opacity-50 cursor-not-allowed">View Details <i data-lucide="arrow-right" class="w-3 h-3"></i></button>`
                                    : `<button type="button" class="${isTop ? REC_BTN_ACTIVE : REC_BTN_IDLE}">View Details <i data-lucide="arrow-right" class="w-3 h-3"></i></button>`}
                            </div>
                        </div>
                    `;
                    if (!isPlaceholder) card.onclick = () => selectRecommendedPlot(item, card);
                    item._cardEl = card;
                    list.appendChild(card);
                });
                if (window.lucide) lucide.createIcons();

                // When proximity metrics arrive, refresh match scores/badges and
                // re-order the cards — skipped once the user has picked a plot.
                proximityDone.then(() => {
                    computeMatchScores(res.data);
                    assignRecBadges(res.data, res.showcase);
                    if (res.showcase || res.budget != null || document.getElementById('input-plot-number').value) return;
                    res.data.sort((a, b) => b._recMatch - a._recMatch);
                    const topIdx = res.data.findIndex(it => !it.class_placeholder);
                    res.data.forEach((item, i) => {
                        if (!item._cardEl) return;
                        list.appendChild(item._cardEl);
                        item._cardEl.className = `${item.class_placeholder ? REC_CARD_BASE.replace('cursor-pointer', 'cursor-default') : REC_CARD_BASE} ${i === topIdx ? 'border-cyan-400 dark:border-cyan-400 bg-cyan-500/5 ring-1 ring-cyan-400/40' : (item.over_budget ? 'border-amber-300/70 dark:border-amber-500/30 bg-white dark:bg-[#0d1a2f] hover:border-amber-400' : REC_CARD_IDLE)}`;
                        const b = item._cardEl.querySelector('.rec-select-btn');
                        if (b && !item.class_placeholder) b.className = i === topIdx ? REC_BTN_ACTIVE : REC_BTN_IDLE;
                    });
                });
            } else {
                container.classList.add('hidden');
                list.innerHTML = '';
                prompt?.classList.remove('hidden');
            }
        }

        let applyFiltersTimer = null;
        function applyFilters() {
            clearTimeout(applyFiltersTimer);
            applyFiltersTimer = setTimeout(loadRecommendations, 400);
        }

        function toggleFutureTarget(target) {
            const inputs = document.getElementById('future-relative-inputs');
            if (target === 'Myself') {
                inputs.classList.add('hidden');
            } else {
                inputs.classList.remove('hidden');
            }
        }

        // --- 3D MODERN APARTMENT VISUALIZATION ENGINE ---
        let threeScene, threeCamera, threeRenderer, animationFrameId;
        let apartment3DInlineActive = false;
        let mausoleumMeshes = [];
        let highlightedMesh = null;

        function highlightApartmentNiche(plotNumber) {
            if (!mausoleumMeshes || mausoleumMeshes.length === 0) return;
            if (highlightedMesh) {
                highlightedMesh.material.color.setHex(highlightedMesh.userData.baseColor);
                highlightedMesh.material.emissive.setHex(0x000000);
                highlightedMesh = null;
            }
            const mesh = mausoleumMeshes.find(m => m.userData.plotNumber === plotNumber);
            if (mesh) {
                mesh.material.color.setHex(0xfacc15);
                mesh.material.emissive.setHex(0xca8a04);
                highlightedMesh = mesh;
            }
        }

        function showApartment3DView() {
            const toggle3dBtn = document.getElementById('btn-toggle-3d');
            toggle3dBtn.classList.remove('hidden');
            toggle3dBtn.classList.add('flex');
            document.getElementById('map').classList.add('hidden');
            document.getElementById('apartment-3d-inline').classList.remove('hidden');
            document.getElementById('map-layer-label').innerText = '3D Apartment Visualizer';
            document.getElementById('btn-toggle-3d-text').innerText = 'Switch to Map';
            apartment3DInlineActive = true;
            init3DApartmentScene('apartment-3d-inline');
        }

        function showMapView() {
            document.getElementById('apartment-3d-inline').classList.add('hidden');
            document.getElementById('map').classList.remove('hidden');
            document.getElementById('map-layer-label').innerText = 'Interactive Map Layer';
            document.getElementById('btn-toggle-3d-text').innerText = 'Switch to 3D View';
            apartment3DInlineActive = false;
            if (animationFrameId) cancelAnimationFrame(animationFrameId);
            if (window.mapInstance) setTimeout(() => window.mapInstance.invalidateSize(), 200);
        }

        function toggleMap3DView() {
            if (apartment3DInlineActive) showMapView(); else showApartment3DView();
        }

        function open3DVisualizationModal() {
            document.getElementById('apartment-3d-modal').classList.remove('hidden');
            init3DApartmentScene('apartment-3d-canvas-container');
        }

        function close3DVisualizationModal() {
            document.getElementById('apartment-3d-modal').classList.add('hidden');
            if (animationFrameId) cancelAnimationFrame(animationFrameId);
            if (apartment3DInlineActive) init3DApartmentScene('apartment-3d-inline');
        }

        function init3DApartmentScene(containerId = 'apartment-3d-canvas-container') {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = '';
            if (animationFrameId) cancelAnimationFrame(animationFrameId);

            const width = container.clientWidth || 600;
            const height = container.clientHeight || 400;

            threeScene = new THREE.Scene();
            threeScene.background = new THREE.Color(0x020617);

            threeCamera = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
            threeCamera.position.set(8, 6, 12);
            threeCamera.lookAt(0, 2, 0);

            threeRenderer = new THREE.WebGLRenderer({ antialias: true });
            threeRenderer.setSize(width, height);
            container.appendChild(threeRenderer.domElement);

            // Lighting
            const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
            threeScene.add(ambientLight);

            const dirLight = new THREE.DirectionalLight(0x22d3ee, 0.8);
            dirLight.position.set(0, 8, 15);
            threeScene.add(dirLight);

            // 3D Multi-Tier Apartment Mausoleum Grid Simulation
            const mausoleumGroup = new THREE.Group();
            const sizeX = 1.2;
            const sizeY = 0.8;
            const sizeZ = 2.0;

            // Build niches from the section's real plot data (ordered: level-major)
            const sectionPlots = window.currentSectionPlots || [];
            const plotCount = sectionPlots.length;
            const perLevel = Math.max(1, parseInt(window.currentSection?.apt_per_level) || 0);
            const levelCount = Math.max(1, parseInt(window.currentSection?.apt_levels) || 0);

            // Match the number of niches the admin actually created
            let cols, rows;
            if (plotCount > 0) {
                cols = perLevel > 0 ? Math.min(perLevel, plotCount) : Math.ceil(Math.sqrt(plotCount));
                rows = Math.ceil(plotCount / cols);
            } else {
                cols = perLevel || 5;
                rows = levelCount || 4;
            }
            const nicheColors = {
                available: 0x06b6d4,
                reserved: 0x8b5cf6,
                'pending approval': 0xf59e0b,
                occupied: 0x475569
            };
            mausoleumMeshes = [];
            highlightedMesh = null;

            let nicheIndex = 0;
            for (let r = 0; r < rows; r++) {
                for (let c = 0; c < cols; c++) {
                    const plot = sectionPlots[nicheIndex];
                    if (plotCount > 0 && !plot) { nicheIndex++; continue; }
                    const status = (plot && plot.status ? plot.status : 'available').toLowerCase();
                    const plotTier = (plot && plot.plot_tier ? plot.plot_tier : 'standard').toLowerCase();
                    const baseColor = plotTier === 'gold' ? 0xeab308 : (plotTier === 'premium' ? 0x94a3b8 : (nicheColors[status] !== undefined ? nicheColors[status] : 0x475569));
                    const geometry = new THREE.BoxGeometry(sizeX - 0.22, sizeY - 0.22, sizeZ);
                    const material = new THREE.MeshStandardMaterial({
                        color: baseColor,
                        roughness: 0.3,
                        metalness: 0.2
                    });
                    const mesh = new THREE.Mesh(geometry, material);
                    mesh.position.set((c - cols / 2) * sizeX, r * sizeY + 0.5, 0);
                    mesh.userData = { plotNumber: plot ? plot.plot_number : null, baseColor: baseColor, status: status };
                    mausoleumMeshes.push(mesh);
                    mausoleumGroup.add(mesh);
                    nicheIndex++;
                }
            }

            threeScene.add(mausoleumGroup);

            // Click a 3D niche to select the matching plot in the grid
            const raycaster = new THREE.Raycaster();
            const pointer = new THREE.Vector2();
            threeRenderer.domElement.style.cursor = 'pointer';
            threeRenderer.domElement.addEventListener('click', (ev) => {
                const rect = threeRenderer.domElement.getBoundingClientRect();
                pointer.x = ((ev.clientX - rect.left) / rect.width) * 2 - 1;
                pointer.y = -((ev.clientY - rect.top) / rect.height) * 2 + 1;
                raycaster.setFromCamera(pointer, threeCamera);
                const hits = raycaster.intersectObjects(mausoleumMeshes);
                if (hits.length > 0) {
                    const plotNum = hits[0].object.userData.plotNumber;
                    const btn = window.plotGridButtons ? window.plotGridButtons[plotNum] : null;
                    if (btn && btn.onclick) {
                        btn.click();
                        btn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }
            });

            // Center the camera directly in front of the niche wall
            const centerX = -sizeX / 2;
            const centerY = (rows * sizeY) / 2;
            const fitDist = Math.max(cols * sizeX, rows * sizeY) * 1.3 + 3;
            threeCamera.position.set(centerX, centerY, fitDist);
            threeCamera.lookAt(centerX, centerY, 0);

            function animate() {
                animationFrameId = requestAnimationFrame(animate);
                threeRenderer.render(threeScene, threeCamera);
            }
            animate();
        }

        const bookingForm = document.getElementById('booking-form');

        // If a required field fails validation while its stage is hidden, the
        // browser can't focus it and the submit appears to do nothing. Reveal
        // the stage containing the invalid control so the message is shown.
        bookingForm.addEventListener('invalid', function(e) {
            const stageEl = e.target.closest('.stage-section');
            if (stageEl && stageEl.classList.contains('hidden')) {
                const stageNum = parseInt(stageEl.id.replace('stage-', ''), 10);
                if (stageNum) goToStage(stageNum);
            }
        }, true);

        bookingForm.onsubmit = function(e) {
            e.preventDefault();
            const selectedOpt = document.querySelector('input[name="payment_option"]:checked')?.value || 'Full Payment';
            if (selectedOpt === 'Down Payment') {
                const price = getPlotPrice();
                const minDp = price * 0.30;
                const dp = parseFloat(document.getElementById('amount_paid')?.value) || 0;
                if (dp < minDp - 0.01) {
                    alert(`Down payment must be at least ${formatCurrency(minDp)} (30% of plot price).`);
                    return;
                }
                if (dp > price) {
                    alert('Down payment cannot exceed the total plot price.');
                    return;
                }
            }

            const submitBtn = bookingForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.origText = submitBtn.innerText;
                submitBtn.innerText = 'Submitting...';
            }

            const formData = new FormData(this);
            formData.append('action', 'reserve_plot');

            fetch('reservation.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('modal-ref-id').innerText = data.reservation_id;
                        document.getElementById('success-modal').classList.remove('hidden');
                    } else {
                        alert(data.message || 'Reservation failed. Please try again.');
                    }
                })
                .catch(() => {
                    alert('Could not submit your reservation. Please check your connection and try again.');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerText = submitBtn.dataset.origText || 'Submit Reservation';
                    }
                });
        };

        document.getElementById('themeToggle').onclick = () => {
            if (typeof toggleTheme === 'function') toggleTheme();
            const icon = document.querySelector('#themeToggle i');
            const isDark = document.documentElement.classList.contains('dark');
            if (icon) {
                icon.setAttribute('data-lucide', isDark ? 'sun' : 'moon');
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        };
    </script>
</body>
</html>