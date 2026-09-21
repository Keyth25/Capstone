<?php
require 'config.php';

// Access Control
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
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

// Ensure notifications table exists for maintenance alerts
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
} catch (PDOException $e) {
    error_log("Notifications table check: " . $e->getMessage());
}

// ================== INTELLIGENT SCHEDULING HELPERS ==================

function parseDurationMinutes(?string $duration): int {
    $duration = strtolower(trim($duration ?? ''));
    if (preg_match('/(\d+)\s*-\s*\d+/', $duration, $m) || preg_match('/(\d+)/', $duration, $m)) {
        $value = (int) $m[1];
        return (strpos($duration, 'min') !== false) ? $value : $value * 60;
    }
    return 60;
}

function getTimeWindow(string $preferredTime): array {
    $t = trim(strtolower($preferredTime));
    if ($t === 'afternoon') {
        return ['13:00:00', '17:00:00'];
    }
    return ['08:00:00', '12:00:00'];
}

function calculatePriorityScore(array $req, ?DateTimeImmutable $now = null): int {
    if (!$now) $now = new DateTimeImmutable();
    $priority = strtolower($req['priority'] ?? '');
    $base = match ($priority) {
        'urgent' => 100,
        'high' => 75,
        'medium' => 50,
        default => 25,
    };
    $created = new DateTimeImmutable($req['created_at'] ?? 'now');
    $ageDays = $now->diff($created)->days;
    $ageBonus = min($ageDays * 3, 30);
    $preferred = DateTimeImmutable::createFromFormat('Y-m-d', $req['preferred_date'] ?? 'today') ?: $now;
    $prefDiff = $now->diff($preferred);
    $prefBonus = 0;
    if ($prefDiff->invert === 0 && $prefDiff->days > 0) {
        $prefBonus = 50; // overdue
    } elseif ($prefDiff->invert === 1 && $prefDiff->days <= 2) {
        $prefBonus = 20;
    } elseif ($prefDiff->invert === 1 && $prefDiff->days <= 7) {
        $prefBonus = 10;
    }
    return $base + $ageBonus + $prefBonus;
}

function autoAssignAndSchedule(PDO $pdo, array $request, string $admin_id): ?array {
    $request_id = (int) ($request['id'] ?? 0);
    $service_type_id = (int) ($request['service_type_id'] ?? 0);
    $preferred_date = $request['preferred_date'] ?? null;
    $preferred_time = $request['preferred_time'] ?? 'Morning';

    if (!$service_type_id || !$request_id) {
        return null;
    }

    $durStmt = $pdo->prepare("SELECT estimated_duration FROM public.maintenance_service_types WHERE id = ?");
    $durStmt->execute([$service_type_id]);
    $service = $durStmt->fetch(PDO::FETCH_ASSOC);
    $duration_minutes = parseDurationMinutes($service['estimated_duration'] ?? null);
    if ($duration_minutes <= 0) {
        $duration_minutes = 60;
    }

    [$window_open, $window_close] = getTimeWindow($preferred_time);
    $today = new DateTimeImmutable('today');
    $preferred = DateTimeImmutable::createFromFormat('Y-m-d', $preferred_date ?: $today->format('Y-m-d')) ?: $today;
    $start_day = $preferred < $today ? $today : $preferred;
    $max_days = 14;

    $staffStmt = $pdo->query("
        SELECT s.id, s.name,
            (SELECT COUNT(*)
             FROM public.maintenance_assignments ma
             JOIN public.maintenance_requests mr ON ma.maintenance_request_id = mr.id
             WHERE ma.personnel_id::text = s.id::text
               AND mr.status NOT IN ('Completed', 'Declined', 'For Verification')) AS active_tasks
        FROM (
            SELECT au.id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), ''), NULLIF(au.raw_user_meta_data->>'full_name', ''), u.name, au.email) AS name
            FROM auth.users au
            LEFT JOIN public.profiles p ON p.id = au.id
            LEFT JOIN public.users u ON u.id = au.id
            WHERE au.raw_user_meta_data->>'role' = 'staff'
            UNION
            SELECT u2.id, u2.name FROM public.users u2 WHERE u2.role = 'staff'
        ) s
        ORDER BY active_tasks ASC, s.name ASC
    ");
    $staff_list = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($staff_list)) {
        return null;
    }

    $interval = new DateInterval('PT' . $duration_minutes . 'M');

    for ($i = 0; $i <= $max_days; $i++) {
        $date = $start_day->modify("+{$i} days");
        $date_str = $date->format('Y-m-d');
        $winOpen = new DateTime($date_str . ' ' . $window_open);
        $winClose = new DateTime($date_str . ' ' . $window_close);

        foreach ($staff_list as $staff) {
            $schedStmt = $pdo->prepare("
                SELECT mr.scheduled_start_time, mr.scheduled_end_time
                FROM public.maintenance_requests mr
                JOIN public.maintenance_assignments ma ON mr.id = ma.maintenance_request_id
                WHERE ma.personnel_id::text = ?
                  AND mr.scheduled_date = ?
                  AND mr.scheduled_start_time IS NOT NULL
                  AND mr.scheduled_end_time IS NOT NULL
                  AND mr.status NOT IN ('Completed', 'Declined', 'For Verification')
                ORDER BY mr.scheduled_start_time ASC
            ");
            $schedStmt->execute([$staff['id'], $date_str]);
            $intervals = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

            $candidate = clone $winOpen;
            $placed = false;

            foreach ($intervals as $int) {
                $s = new DateTime($date_str . ' ' . $int['scheduled_start_time']);
                $e = new DateTime($date_str . ' ' . $int['scheduled_end_time']);
                $end_candidate = clone $candidate;
                $end_candidate->add($interval);

                if ($end_candidate <= $s && $end_candidate <= $winClose) {
                    $placed = true;
                    break;
                }
                if ($e > $candidate) {
                    $candidate = clone $e;
                }
            }

            if (!$placed) {
                $end_candidate = clone $candidate;
                $end_candidate->add($interval);
                if ($end_candidate <= $winClose) {
                    $placed = true;
                }
            }

            if ($placed) {
                $start_time = $candidate->format('H:i:s');
                $end_time = $candidate->add($interval)->format('H:i:s');

                $upd = $pdo->prepare("
                    UPDATE public.maintenance_requests
                    SET scheduled_date = ?, scheduled_start_time = ?, scheduled_end_time = ?, updated_at = NOW()
                    WHERE id = ?::integer
                ");
                $upd->execute([$date_str, $start_time, $end_time, $request_id]);

                $assign = $pdo->prepare("
                    INSERT INTO public.maintenance_assignments (maintenance_request_id, personnel_id, assigned_by, status, assigned_at)
                    VALUES (?, ?::uuid, ?::uuid, 'Assigned', NOW())
                ");
                $assign->execute([$request_id, $staff['id'], $admin_id]);

                return [
                    'staff_id' => $staff['id'],
                    'staff_name' => $staff['name'],
                    'scheduled_date' => $date_str,
                    'scheduled_start_time' => $start_time,
                    'scheduled_end_time' => $end_time,
                ];
            }
        }
    }

    return null;
}

// Handle Maintenance Request Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. Accept Request (intelligent approve + schedule + auto-assign)
    if ($_POST['action'] === 'accept_request') {
        $request_id = trim($_POST['request_id'] ?? '');

        try {
            $pdo->beginTransaction();

            $req_stmt = $pdo->prepare("
                SELECT mr.*, st.estimated_duration
                FROM public.maintenance_requests mr
                LEFT JOIN public.maintenance_service_types st ON mr.service_type_id = st.id
                WHERE mr.id::text = ?
            ");
            $req_stmt->execute([$request_id]);
            $request = $req_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception("Request not found.");
            }

            $score = calculatePriorityScore($request);
            $schedule = autoAssignAndSchedule($pdo, $request, $user_id);

            if ($schedule) {
                $new_status = 'Assigned';
                $notes = "Approved (score: {$score}). Auto-assigned to {$schedule['staff_name']} on {$schedule['scheduled_date']} at {$schedule['scheduled_start_time']}-{$schedule['scheduled_end_time']}.";
            } else {
                $new_status = 'Approved';
                $notes = "Approved (score: {$score}). Auto-scheduling unavailable; awaiting manual staff assignment.";
            }

            $stmt = $pdo->prepare("UPDATE public.maintenance_requests SET status = ?, updated_at = NOW() WHERE id::text = ?");
            $stmt->execute([$new_status, $request_id]);

            $log = $pdo->prepare("
                INSERT INTO public.maintenance_history (maintenance_request_id, user_id, action, old_status, new_status, notes)
                VALUES (?, ?::uuid, 'Request Approved', 'Pending Review', ?, ?)
            ");
            $log->execute([$request_id, $user_id, $new_status, $notes]);

            // Notify requester
            if (!empty($request['requester_id'])) {
                $notif_msg = $schedule
                    ? "Your maintenance request {$request['request_number']} has been approved and assigned to {$schedule['staff_name']} on " . date('M j, Y', strtotime($schedule['scheduled_date'])) . " at " . date('g:i A', strtotime($schedule['scheduled_start_time'])) . "."
                    : "Your maintenance request {$request['request_number']} has been approved. Staff assignment coming soon.";

                $notif = $pdo->prepare("INSERT INTO public.notifications (user_id, title, message, link) VALUES (?::uuid, 'Maintenance Request Approved', ?, ?)");
                $notif->execute([
                    $request['requester_id'],
                    $notif_msg,
                    "user_maintenance.php"
                ]);
            }

            // Notify assigned staff
            if ($schedule && !empty($schedule['staff_id'])) {
                $notif = $pdo->prepare("
                    INSERT INTO public.notifications (user_id, title, message, link)
                    VALUES (?::uuid, 'New Maintenance Assignment', ?, ?)
                ");
                $notif->execute([
                    $schedule['staff_id'],
                    "You have been assigned to maintenance request {$request['request_number']} on {$schedule['scheduled_date']} at " . date('g:i A', strtotime($schedule['scheduled_start_time'])) . ".",
                    "staff.php"
                ]);
            }

            $pdo->commit();
            $message = $schedule
                ? "Request approved and scheduled on {$schedule['scheduled_date']} at " . date('g:i A', strtotime($schedule['scheduled_start_time'])) . " with {$schedule['staff_name']}."
                : "Request approved! Auto-scheduling was not possible, please assign staff manually.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to approve request: " . $e->getMessage();
        }
    }
    
    // 2. Decline Request
    if ($_POST['action'] === 'decline_request') {
        $request_id = trim($_POST['request_id'] ?? '');
        $decline_reason = trim($_POST['decline_reason'] ?? '');
        
        try {
            $pdo->beginTransaction();
            
            // Update request status
            $stmt = $pdo->prepare("UPDATE public.maintenance_requests SET status = 'Declined', updated_at = NOW() WHERE id::text = ?");
            $stmt->execute([$request_id]);
            
            // Log history
            $log = $pdo->prepare("INSERT INTO public.maintenance_history (maintenance_request_id, user_id, action, old_status, new_status, notes) VALUES (?, ?::uuid, 'Request Declined', 'Pending Review', 'Declined', ?)");
            $log->execute([$request_id, $user_id, $decline_reason]);
            
            // Notify requester
            $req_stmt = $pdo->prepare("SELECT requester_id, request_number FROM public.maintenance_requests WHERE id::text = ?");
            $req_stmt->execute([$request_id]);
            $req_data = $req_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($req_data && !empty($req_data['requester_id'])) {
                $notif = $pdo->prepare("INSERT INTO public.notifications (user_id, title, message, link) VALUES (?::uuid, 'Maintenance Request Declined', ?, ?)");
                $notif->execute([
                    $req_data['requester_id'],
                    "Your maintenance request {$req_data['request_number']} has been declined. Reason: {$decline_reason}",
                    "user_maintenance.php"
                ]);
            }
            
            $pdo->commit();
            $message = "Maintenance request declined successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Failed to decline request: " . $e->getMessage();
        }
    }
    
    // 3. Assign / Reassign Staff
    if ($_POST['action'] === 'assign_staff') {
        $request_id = trim($_POST['request_id'] ?? '');
        $staff_id = trim($_POST['staff_id'] ?? '');

        try {
            $pdo->beginTransaction();

            $req_stmt = $pdo->prepare("
                SELECT id, status, requester_id, request_number
                FROM public.maintenance_requests
                WHERE id::text = ?
                FOR UPDATE
            ");
            $req_stmt->execute([$request_id]);
            $req_data = $req_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req_data) {
                throw new Exception("Request not found.");
            }
            if (!in_array($req_data['status'], ['Pending Review', 'Approved', 'Assigned'], true)) {
                throw new Exception("Cannot assign staff to a request with status '{$req_data['status']}'.");
            }

            // Resolve staff display name across auth.users, profiles, and legacy users
            $staff_name_stmt = $pdo->prepare("
                SELECT COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), ''),
                    NULLIF(au.raw_user_meta_data->>'full_name', ''),
                    u.name,
                    'Staff'
                )
                FROM (SELECT ?::uuid AS id) x
                LEFT JOIN public.profiles p ON p.id = x.id
                LEFT JOIN auth.users au ON au.id = x.id
                LEFT JOIN public.users u ON u.id::text = x.id::text
            ");
            $staff_name_stmt->execute([$staff_id]);
            $staff_name = $staff_name_stmt->fetchColumn() ?: 'Staff';

            // Check for existing assignment
            $check = $pdo->prepare("SELECT COUNT(*) FROM public.maintenance_assignments WHERE maintenance_request_id = ?");
            $check->execute([$request_id]);
            $has_assignment = (int) $check->fetchColumn() > 0;

            if ($has_assignment) {
                // Reassign: point existing assignment(s) at the newly chosen staff member
                $upd_assign = $pdo->prepare("
                    UPDATE public.maintenance_assignments
                    SET personnel_id = ?::uuid, assigned_by = ?::uuid, status = 'Assigned', assigned_at = NOW()
                    WHERE maintenance_request_id = ?
                ");
                $upd_assign->execute([$staff_id, $user_id, $request_id]);
                $action_label = 'Staff Reassigned';
            } else {
                $assign_stmt = $pdo->prepare("
                    INSERT INTO public.maintenance_assignments (maintenance_request_id, personnel_id, assigned_by, status, assigned_at)
                    VALUES (?, ?::uuid, ?::uuid, 'Assigned', NOW())
                ");
                $assign_stmt->execute([$request_id, $staff_id, $user_id]);
                $action_label = 'Staff Assigned';
            }

            // Update request status (approves the request if it was still pending)
            $old_status = $req_data['status'];
            $req_stmt = $pdo->prepare("UPDATE public.maintenance_requests SET status = 'Assigned', updated_at = NOW() WHERE id::text = ?");
            $req_stmt->execute([$request_id]);

            // Log history
            $log = $pdo->prepare("INSERT INTO public.maintenance_history (maintenance_request_id, user_id, action, old_status, new_status, notes) VALUES (?, ?::uuid, ?, ?, 'Assigned', ?)");
            $log->execute([$request_id, $user_id, $action_label, $old_status, "Assigned to {$staff_name}"]);

            // Notify assigned staff
            $notif = $pdo->prepare("INSERT INTO public.notifications (user_id, title, message, link) VALUES (?::uuid, 'New Maintenance Assignment', ?, ?)");
            $notif->execute([
                $staff_id,
                "You have been assigned to maintenance request {$req_data['request_number']}. Please check your task board.",
                "staff.php"
            ]);

            // Notify requester
            if (!empty($req_data['requester_id'])) {
                $req_notif = $pdo->prepare("INSERT INTO public.notifications (user_id, title, message, link) VALUES (?::uuid, 'Staff Assigned', ?, ?)");
                $req_notif->execute([
                    $req_data['requester_id'],
                    "Staff ({$staff_name}) has been assigned to your maintenance request {$req_data['request_number']}.",
                    "user_maintenance.php"
                ]);
            }

            $pdo->commit();
            $message = $has_assignment
                ? "Request reassigned to {$staff_name} successfully!"
                : "{$staff_name} assigned to request {$req_data['request_number']} successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to assign staff: " . $e->getMessage();
        }
    }

    // 4. Verify Completed Work
    if ($_POST['action'] === 'verify_request') {
        $request_id = trim($_POST['request_id'] ?? '');

        try {
            $pdo->beginTransaction();

            $req_stmt = $pdo->prepare("
                SELECT requester_id, request_number
                FROM public.maintenance_requests
                WHERE id::text = ?
            ");
            $req_stmt->execute([$request_id]);
            $request = $req_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception("Request not found.");
            }

            $stmt = $pdo->prepare("
                UPDATE public.maintenance_requests
                SET status = 'Completed', updated_at = NOW()
                WHERE id::text = ?
            ");
            $stmt->execute([$request_id]);

            $log = $pdo->prepare("
                INSERT INTO public.maintenance_history (maintenance_request_id, user_id, action, old_status, new_status, notes)
                VALUES (?, ?::uuid, 'Request Verified', 'For Verification', 'Completed', 'Work verified by administrator and marked complete.')
            ");
            $log->execute([$request_id, $user_id]);

            if (!empty($request['requester_id'])) {
                $notif = $pdo->prepare("
                    INSERT INTO public.notifications (user_id, title, message, link)
                    VALUES (?::uuid, 'Maintenance Request Verified', ?, 'user_maintenance.php')
                ");
                $notif->execute([
                    $request['requester_id'],
                    "Your maintenance request {$request['request_number']} has been verified and completed by the administrator."
                ]);
            }

            $pdo->commit();
            $message = "Request verified and marked as completed!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to verify request: " . $e->getMessage();
        }
    }
}

// Fetch Maintenance Requests Safely
$requests = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            mr.id,
            mr.request_number,
            mr.description,
            mr.priority,
            mr.status,
            mr.preferred_date,
            mr.preferred_time,
            mr.created_at,
            mr.scheduled_date,
            mr.scheduled_start_time,
            mr.scheduled_end_time,
            COALESCE(st.name, 'General Service') as service_name,
            st.default_fee,
            COALESCE(d.plot_code, mr.plot_id::text, 'N/A') as plot_number,
            d.deceased_name,
            d.date_of_birth,
            d.date_of_death,
            d.latitude,
            d.longitude,
            COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ', rp.first_name, rp.last_name)), ''),
                NULLIF(rau.raw_user_meta_data->>'full_name', ''),
                u.name,
                'Unknown Requester'
            ) as requester_name,
            COALESCE(rp.email, rau.email, u.email) as requester_email,
            rp.phone_number as requester_phone,
            ma.assignment_id,
            ma.staff_names,
            ma.assignment_count
        FROM public.maintenance_requests mr
        LEFT JOIN public.maintenance_service_types st ON mr.service_type_id::text = st.id::text
        LEFT JOIN public.deceased_records d ON mr.plot_id::text = d.id::text
        LEFT JOIN public.profiles rp ON mr.requester_id = rp.id
        LEFT JOIN auth.users rau ON mr.requester_id = rau.id
        LEFT JOIN public.users u ON mr.requester_id::text = u.id::text
        LEFT JOIN (
            SELECT
                ma2.maintenance_request_id,
                MAX(ma2.id) AS assignment_id,
                COUNT(*) AS assignment_count,
                STRING_AGG(
                    COALESCE(
                        NULLIF(TRIM(CONCAT_WS(' ', sp.first_name, sp.last_name)), ''),
                        NULLIF(sau.raw_user_meta_data->>'full_name', ''),
                        su.name,
                        'Staff'
                    ),
                    ', ' ORDER BY ma2.id
                ) AS staff_names
            FROM public.maintenance_assignments ma2
            LEFT JOIN public.users su ON ma2.personnel_id::text = su.id::text
            LEFT JOIN auth.users sau ON ma2.personnel_id = sau.id
            LEFT JOIN public.profiles sp ON ma2.personnel_id = sp.id
            GROUP BY ma2.maintenance_request_id
        ) ma ON mr.id = ma.maintenance_request_id
        ORDER BY mr.created_at DESC
    ");
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $now = new DateTimeImmutable();
    foreach ($requests as $k => $r) {
        $requests[$k]['priority_score'] = calculatePriorityScore($r, $now);
        if (!empty($r['scheduled_date']) && !empty($r['scheduled_start_time']) && !empty($r['scheduled_end_time'])) {
            $start = new DateTimeImmutable($r['scheduled_date'] . ' ' . $r['scheduled_start_time']);
            $end = new DateTimeImmutable($r['scheduled_date'] . ' ' . $r['scheduled_end_time']);
            $requests[$k]['scheduled_display'] = $start->format('M j, Y g:i A') . ' - ' . $end->format('g:i A');
        } else {
            $requests[$k]['scheduled_display'] = 'Not scheduled';
        }
    }
    usort($requests, fn($a, $b) => strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? ''));
} catch (PDOException $e) {
    error_log("Error fetching maintenance requests: " . $e->getMessage());
    $requests = [];
}

// Fetch history per request for the details view
$request_history = [];
try {
    $hist_stmt = $pdo->query("
        SELECT h.maintenance_request_id, h.action, h.old_status, h.new_status, h.notes, h.created_at,
               COALESCE(
                   NULLIF(TRIM(CONCAT_WS(' ', hp.first_name, hp.last_name)), ''),
                   hu.name,
                   'System'
               ) AS actor_name
        FROM public.maintenance_history h
        LEFT JOIN public.profiles hp ON h.user_id = hp.id
        LEFT JOIN public.users hu ON h.user_id::text = hu.id::text
        ORDER BY h.created_at ASC
    ");
    foreach ($hist_stmt as $h) {
        $request_history[(int) $h['maintenance_request_id']][] = $h;
    }
} catch (PDOException $e) {
    error_log("Maintenance details fetch error: " . $e->getMessage());
}

// Fetch photos per request for the details view
$request_photos = [];
try {
    foreach ($pdo->query("SELECT maintenance_request_id, file_path, photo_type FROM public.maintenance_photos ORDER BY id ASC") as $ph) {
        $request_photos[(int) $ph['maintenance_request_id']][] = $ph;
    }
} catch (PDOException $e) {
    error_log("Maintenance photos fetch error: " . $e->getMessage());
}

// Build a details map used by the View Details modal
$request_details = [];
foreach ($requests as $r) {
    $rid = (int) $r['id'];
    $request_details[$rid] = [
        'request_number'    => $r['request_number'],
        'status'            => $r['status'],
        'priority'          => $r['priority'],
        'priority_score'    => $r['priority_score'],
        'service_name'      => $r['service_name'],
        'default_fee'       => $r['default_fee'],
        'description'       => $r['description'],
        'plot_number'       => $r['plot_number'],
        'deceased_name'     => $r['deceased_name'],
        'preferred_date'    => $r['preferred_date'],
        'preferred_time'    => $r['preferred_time'],
        'scheduled_display' => $r['scheduled_display'],
        'created_at'        => $r['created_at'],
        'requester_name'    => $r['requester_name'],
        'requester_email'   => $r['requester_email'],
        'requester_phone'   => $r['requester_phone'],
        'date_of_birth'     => $r['date_of_birth'],
        'date_of_death'     => $r['date_of_death'],
        'latitude'          => $r['latitude'],
        'longitude'         => $r['longitude'],
        'photos'            => array_values($request_photos[$rid] ?? []),
        'staff_names'       => $r['staff_names'],
        'history'           => array_values($request_history[$rid] ?? []),
    ];
}

// Fetch Available Staff (auth.users staff + legacy public.users staff)
$staff_list = [];
try {
    try {
        $staff_stmt = $pdo->query("
            SELECT s.id, s.name, s.job_role FROM (
                SELECT au.id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), ''), NULLIF(au.raw_user_meta_data->>'full_name', ''), u.name, au.email) AS name, p.job_role
                FROM auth.users au
                LEFT JOIN public.profiles p ON p.id = au.id
                LEFT JOIN public.users u ON u.id = au.id
                WHERE au.raw_user_meta_data->>'role' = 'staff'
                UNION
                SELECT u2.id, u2.name, NULL AS job_role FROM public.users u2 WHERE u2.role = 'staff'
            ) s
            ORDER BY s.name ASC
        ");
        $staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // job_role column may not exist yet — fall back to the plain staff list
        $staff_stmt = $pdo->query("
            SELECT s.id, s.name, NULL AS job_role FROM (
                SELECT au.id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), ''), NULLIF(au.raw_user_meta_data->>'full_name', ''), u.name, au.email) AS name
                FROM auth.users au
                LEFT JOIN public.profiles p ON p.id = au.id
                LEFT JOIN public.users u ON u.id = au.id
                WHERE au.raw_user_meta_data->>'role' = 'staff'
                UNION
                SELECT u2.id, u2.name FROM public.users u2 WHERE u2.role = 'staff'
            ) s
            ORDER BY s.name ASC
        ");
        $staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $staff_list = [];
}

// Fetch sections for the filter bar
$sections = [];
try {
    $sections = $pdo->query("SELECT section_name, section_code FROM public.sections ORDER BY section_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sections = [];
}

// Unread admin notifications for the header bell
$admin_unread_count = 0;
try {
    init_admin_notifications_table($pdo);
    $admin_unread_count = (int) $pdo->query("SELECT COUNT(*) FROM public.admin_notifications WHERE is_read = FALSE")->fetchColumn();
} catch (PDOException $e) {
    $admin_unread_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Maintenance Management - Admin</title>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
        .mr-input:focus { outline: none; border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12); }
        .dark .mr-input { background: #020617; border-color: #1e293b; color: #e2e8f0; }
        .mr-row:hover { background: #f8f8fe; }
        .dark .mr-row:hover { background: rgba(30, 41, 59, 0.4); }
        .mr-divider { border-color: #eef0f7; }
        .dark .mr-divider { border-color: #1e293b; }

        #requestPanel { transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        #panelMap { height: 170px; width: 100%; }
        .leaflet-container { font-family: 'Plus Jakarta Sans', sans-serif; background: #e8eaf4; }
        .leaflet-tooltip.mr-map-label { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 10px; font-weight: 700; color: #7c3aed; padding: 2px 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .leaflet-tooltip.mr-map-label::before { display: none; }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <script src="mobile.js" defer></script>
</head>
<body class="bg-[#eef0f8] dark:bg-slate-950 text-slate-800 dark:text-slate-100 h-screen flex font-sans overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <!-- DASHBOARD CONTENT -->
        <div class="flex-1 overflow-y-auto p-5 sm:p-6 space-y-5">

            <!-- PAGE HEADER -->
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3.5">
                    <button onclick="toggleSidebar()" class="lg:hidden p-2.5 rounded-xl mr-card mr-muted shadow-sm">
                        <i class="fa-solid fa-bars text-sm"></i>
                    </button>
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center shadow-lg shadow-violet-600/30 shrink-0">
                        <i class="fa-solid fa-screwdriver-wrench text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-xl font-extrabold mr-title tracking-tight">Maintenance Request Management</h1>
                        <p class="text-xs mr-muted">Manage and track maintenance requests for cemetery plots and facilities.</p>
                    </div>
                </div>
                <div class="flex items-center gap-2.5">
                    <a href="admin_dashboard.php" title="Notifications" class="relative w-10 h-10 mr-card rounded-xl shadow-sm mr-muted hover:text-violet-500 transition flex items-center justify-center">
                        <i class="fa-regular fa-bell text-sm"></i>
                        <?php if ($admin_unread_count > 0): ?>
                            <span class="absolute top-2 right-2.5 w-2 h-2 rounded-full bg-rose-500 ring-2 ring-white dark:ring-slate-900"></span>
                        <?php endif; ?>
                    </a>
                    <div class="mr-card rounded-xl shadow-sm pl-1.5 pr-3.5 py-1.5 flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300 flex items-center justify-center shrink-0">
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

            <?php if ($message): ?>
                <div class="p-3 bg-emerald-500/10 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-xl shadow-sm flex items-center gap-2">
                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="p-3 bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs rounded-xl shadow-sm flex items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php
                $total_requests   = count($requests);
                $pending_count    = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'Pending Review'));
                $inprogress_count = count(array_filter($requests, fn($r) => in_array($r['status'] ?? '', ['Approved', 'Assigned', 'In Progress', 'For Verification'], true)));
                $completed_count  = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'Completed'));
            ?>
            <!-- STAT CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <button onclick="setStatusFilter('')" class="mr-card rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition text-left group">
                    <div class="w-11 h-11 rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300 flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-clipboard-list"></i></div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-bold mr-muted uppercase tracking-wider block">Total Requests</span>
                        <span class="text-2xl font-extrabold mr-title block leading-tight"><?= $total_requests ?></span>
                        <span class="text-[10px] mr-muted block">All maintenance requests</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-[#cbd5e1] dark:text-slate-600 text-xs group-hover:text-violet-500 transition"></i>
                </button>

                <button onclick="setStatusFilter('Pending Review')" class="mr-card rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition text-left group">
                    <div class="w-11 h-11 rounded-full bg-amber-500/10 text-amber-500 flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-clock"></i></div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-bold mr-muted uppercase tracking-wider block">Pending</span>
                        <span class="text-2xl font-extrabold mr-title block leading-tight"><?= $pending_count ?></span>
                        <span class="text-[10px] mr-muted block">Awaiting approval</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-[#cbd5e1] dark:text-slate-600 text-xs group-hover:text-amber-500 transition"></i>
                </button>

                <button onclick="setStatusFilter('__active__')" class="mr-card rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition text-left group">
                    <div class="w-11 h-11 rounded-full bg-blue-500/10 text-blue-500 flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-wrench"></i></div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-bold mr-muted uppercase tracking-wider block">In Progress</span>
                        <span class="text-2xl font-extrabold mr-title block leading-tight"><?= $inprogress_count ?></span>
                        <span class="text-[10px] mr-muted block">Currently being serviced</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-[#cbd5e1] dark:text-slate-600 text-xs group-hover:text-blue-500 transition"></i>
                </button>

                <button onclick="setStatusFilter('Completed')" class="mr-card rounded-2xl p-4 flex items-center gap-4 shadow-sm hover:shadow-md transition text-left group">
                    <div class="w-11 h-11 rounded-full bg-emerald-500/10 text-emerald-500 flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[10px] font-bold mr-muted uppercase tracking-wider block">Completed</span>
                        <span class="text-2xl font-extrabold mr-title block leading-tight"><?= $completed_count ?></span>
                        <span class="text-[10px] mr-muted block">Maintenance finished</span>
                    </div>
                    <i class="fa-solid fa-chevron-right text-[#cbd5e1] dark:text-slate-600 text-xs group-hover:text-emerald-500 transition"></i>
                </button>
            </div>

            <!-- FILTER BAR -->
            <div class="mr-card rounded-2xl p-3 shadow-sm flex flex-wrap items-center gap-2.5">
                <div class="relative flex-1 min-w-[220px]">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 mr-muted text-xs"></i>
                    <input id="filterSearch" type="text" placeholder="Search by plot, request ID, name, or service..." class="mr-input w-full rounded-xl pl-9 pr-3 py-2.5 text-xs placeholder:text-[#9aa3b8]">
                </div>
                <select id="filterStatus" class="mr-input rounded-xl px-3 py-2.5 text-xs font-medium cursor-pointer">
                    <option value="">All Status</option>
                    <option value="__active__">All Active</option>
                    <option value="Pending Review">Pending</option>
                    <option value="Approved">Approved</option>
                    <option value="Assigned">Assigned</option>
                    <option value="In Progress">In Progress</option>
                    <option value="For Verification">For Verification</option>
                    <option value="Completed">Completed</option>
                    <option value="Declined">Declined</option>
                </select>
                <select id="filterPriority" class="mr-input rounded-xl px-3 py-2.5 text-xs font-medium cursor-pointer">
                    <option value="">All Priority</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
                <select id="filterSection" class="mr-input rounded-xl px-3 py-2.5 text-xs font-medium cursor-pointer">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $sec):
                        $sec_val = trim((string)($sec['section_code'] ?? '')) !== '' ? $sec['section_code'] : $sec['section_name'];
                    ?>
                        <option value="<?= htmlspecialchars($sec_val) ?>"><?= htmlspecialchars($sec['section_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input id="filterDate" type="date" title="Filter by date requested" class="mr-input rounded-xl px-3 py-2 text-xs mr-muted cursor-pointer">
                <button onclick="resetFilters()" class="rounded-xl px-4 py-2.5 text-xs font-bold bg-violet-600 text-white hover:bg-violet-500 shadow-md shadow-violet-600/25 transition flex items-center gap-2">
                    <i class="fa-solid fa-filter"></i> Reset
                </button>
            </div>

            <!-- MAINTENANCE REQUESTS TABLE -->
            <div class="mr-card rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs" id="requestTable">
                        <thead>
                            <tr class="mr-muted text-[10px] uppercase tracking-wider border-b mr-divider">
                                <th class="py-3.5 pl-5 pr-2 font-bold">#</th>
                                <th class="py-3.5 px-2 font-bold">Request ID</th>
                                <th class="py-3.5 px-2 font-bold">Plot / Location</th>
                                <th class="py-3.5 px-2 font-bold">Service</th>
                                <th class="py-3.5 px-2 font-bold">Requester</th>
                                <th class="py-3.5 px-2 font-bold">Priority</th>
                                <th class="py-3.5 px-2 font-bold">Status</th>
                                <th class="py-3.5 px-2 font-bold">Assigned Staff</th>
                                <th class="py-3.5 px-2 font-bold">Date Requested</th>
                                <th class="py-3.5 px-2 pr-5 font-bold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#eef0f7] dark:divide-white/10" id="requestTbody">
                            <?php if (empty($requests)): ?>
                                <tr>
                                    <td colspan="10" class="py-10 text-center mr-muted italic">No maintenance requests found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $i => $req):
                                    $priority_lower = strtolower($req['priority'] ?? '');
                                    $pin = 'text-violet-500';

                                    $svc = strtolower($req['service_name'] ?? '');
                                    if (str_contains($svc, 'grass') || str_contains($svc, 'lawn')) {
                                        $svc_icon = 'fa-seedling'; $svc_color = 'text-emerald-500';
                                    } elseif (str_contains($svc, 'clean')) {
                                        $svc_icon = 'fa-broom'; $svc_color = 'text-violet-500';
                                    } elseif (str_contains($svc, 'flower')) {
                                        $svc_icon = 'fa-spa'; $svc_color = 'text-pink-500';
                                    } elseif (str_contains($svc, 'repair') || str_contains($svc, 'headstone')) {
                                        $svc_icon = 'fa-hammer'; $svc_color = 'text-amber-500';
                                    } else {
                                        $svc_icon = 'fa-screwdriver-wrench'; $svc_color = 'text-violet-500';
                                    }

                                    $row_section = '';
                                    $plot_code_str = (string) ($req['plot_number'] ?? '');
                                    foreach ($sections as $sec) {
                                        $sec_code = trim((string) ($sec['section_code'] ?? ''));
                                        $sec_name = trim((string) ($sec['section_name'] ?? ''));
                                        $sec_val  = $sec_code !== '' ? $sec_code : $sec_name;
                                        if (($sec_code !== '' && stripos($plot_code_str, $sec_code) === 0)
                                            || ($sec_name !== '' && stripos($plot_code_str, $sec_name) !== false)) {
                                            $row_section = $sec_val;
                                            break;
                                        }
                                    }

                                    $req_parts = preg_split('/\s+/', trim((string) ($req['requester_name'] ?? 'U')));
                                    $req_initials = strtoupper(substr($req_parts[0] ?? 'U', 0, 1) . substr($req_parts[1] ?? '', 0, 1));
                                    $stf_parts = preg_split('/\s+/', trim((string) ($req['staff_names'] ?? '')));
                                    $stf_initials = strtoupper(substr($stf_parts[0] ?? '', 0, 1) . substr($stf_parts[1] ?? '', 0, 1));

                                    $created_ts = !empty($req['created_at']) ? strtotime($req['created_at']) : null;
                                    $search_hay = strtolower(implode(' ', array_filter([
                                        $req['request_number'] ?? '', $req['plot_number'] ?? '', $req['deceased_name'] ?? '',
                                        $req['service_name'] ?? '', $req['requester_name'] ?? '', $req['staff_names'] ?? ''
                                    ])));

                                    $prioClass = match ($priority_lower) {
                                        'urgent' => 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20',
                                        'high' => 'bg-orange-500/10 text-orange-600 dark:text-orange-400 border border-orange-500/20',
                                        'medium' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
                                        default => 'bg-slate-500/10 text-slate-500 dark:text-slate-400 border border-slate-500/20',
                                    };

                                    $statusMap = [
                                        'Completed'        => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20',
                                        'Approved'         => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
                                        'In Progress'      => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
                                        'Assigned'         => 'bg-violet-500/10 text-violet-600 dark:text-violet-300 border border-violet-500/20',
                                        'For Verification' => 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-500/20',
                                        'Declined'         => 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20',
                                    ];
                                    $statusClass = $statusMap[$req['status'] ?? ''] ?? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20';
                                    $statusLabel = (($req['status'] ?? '') === 'Pending Review') ? 'Pending' : ($req['status'] ?? 'Unknown');
                                ?>
                                    <tr class="mr-row transition"
                                        data-id="<?= (int) $req['id'] ?>"
                                        data-status="<?= htmlspecialchars($req['status'] ?? '') ?>"
                                        data-priority="<?= htmlspecialchars($priority_lower) ?>"
                                        data-section="<?= htmlspecialchars($row_section) ?>"
                                        data-date="<?= $created_ts ? date('Y-m-d', $created_ts) : '' ?>"
                                        data-assigned="<?= !empty($req['assignment_id']) ? '1' : '0' ?>"
                                        data-search="<?= htmlspecialchars($search_hay) ?>">
                                        <td class="py-3.5 pl-5 pr-2 mr-muted font-semibold"><?= $i + 1 ?></td>
                                        <td class="py-3.5 px-2 font-mono font-bold text-violet-600 dark:text-violet-300 whitespace-nowrap"><?= htmlspecialchars($req['request_number'] ?? 'N/A') ?></td>
                                        <td class="py-3.5 px-2">
                                            <div class="flex items-start gap-2">
                                                <i class="fa-solid fa-location-dot <?= $pin ?> text-sm mt-0.5"></i>
                                                <div class="min-w-0">
                                                    <div class="font-bold mr-title whitespace-nowrap"><?= htmlspecialchars($req['plot_number'] ?? 'N/A') ?></div>
                                                    <div class="text-[10px] mr-muted"><?= htmlspecialchars($req['deceased_name'] ?? 'N/A') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-2">
                                            <div class="flex items-center gap-2 whitespace-nowrap">
                                                <i class="fa-solid <?= $svc_icon ?> <?= $svc_color ?> text-sm"></i>
                                                <span class="mr-text font-medium"><?= htmlspecialchars($req['service_name'] ?? 'General Service') ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-2">
                                            <div class="flex items-center gap-2">
                                                <div class="w-7 h-7 rounded-full bg-gradient-to-br from-violet-500 to-purple-600 text-white flex items-center justify-center text-[9px] font-bold shrink-0"><?= htmlspecialchars($req_initials) ?></div>
                                                <div class="min-w-0">
                                                    <div class="font-semibold mr-title whitespace-nowrap"><?= htmlspecialchars($req['requester_name'] ?? 'Unknown User') ?></div>
                                                    <div class="text-[9px] mr-muted">Plot Owner</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-2">
                                            <span class="px-2.5 py-1 rounded-full text-[9px] font-bold uppercase <?= $prioClass ?>"><?= htmlspecialchars(ucfirst($req['priority'] ?? 'Low')) ?></span>
                                            <span class="block text-[9px] mr-muted mt-1">Score <?= number_format(($req['priority_score'] ?? 0) / 10, 1) ?></span>
                                        </td>
                                        <td class="py-3.5 px-2">
                                            <span class="px-2.5 py-1 rounded-full text-[9px] font-bold uppercase <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                        </td>
                                        <td class="py-3.5 px-2">
                                            <?php if (!empty($req['staff_names'])): ?>
                                                <div class="flex items-center gap-2">
                                                    <div class="w-7 h-7 rounded-full bg-slate-200 dark:bg-slate-700 mr-text flex items-center justify-center text-[9px] font-bold shrink-0"><?= htmlspecialchars($stf_initials) ?></div>
                                                    <span class="text-[11px] font-medium mr-text whitespace-nowrap"><?= htmlspecialchars($req['staff_names']) ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="mr-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-2 whitespace-nowrap">
                                            <?php if ($created_ts): ?>
                                                <div class="mr-text font-medium"><?= date('M j, Y', $created_ts) ?></div>
                                                <div class="text-[9px] mr-muted"><?= date('g:i A', $created_ts) ?></div>
                                            <?php else: ?>
                                                <span class="mr-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-2 pr-5 text-right whitespace-nowrap">
                                            <button onclick="openViewPanel(<?= (int) $req['id'] ?>)" class="px-2.5 py-1.5 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-300 border border-violet-500/20 text-[10px] font-bold hover:bg-violet-500/20 transition">
                                                <i class="fa-solid fa-eye mr-1"></i>View
                                            </button>
                                            <button onclick="toggleRowMenu(this, event)"
                                                data-id="<?= (int) $req['id'] ?>"
                                                data-status="<?= htmlspecialchars($req['status'] ?? '') ?>"
                                                data-assigned="<?= !empty($req['assignment_id']) ? '1' : '0' ?>"
                                                class="w-7 h-7 rounded-lg mr-muted hover:bg-slate-100 dark:hover:bg-white/10 transition ml-1">
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="noResults" class="hidden py-10 text-center mr-muted text-xs italic border-t mr-divider">No requests match the current filters.</div>
            </div>

        </div>
    </div>

    <!-- ROW ACTIONS MENU -->
    <div id="rowMenu" class="hidden fixed z-[80] w-48 mr-card rounded-xl shadow-2xl py-1.5"></div>

    <!-- SHARED ACTION FORM -->
    <form id="rowActionForm" method="POST" action="">
        <input type="hidden" name="action" id="rowActionName">
        <input type="hidden" name="request_id" id="rowActionRequestId">
    </form>

    <!-- REQUEST DETAILS SLIDE-OVER PANEL -->
    <div id="panelBackdrop" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[90] hidden" onclick="closeViewPanel()"></div>
    <aside id="requestPanel" class="fixed top-0 right-0 h-full w-full sm:w-[430px] z-[95] translate-x-full">
        <div class="h-full mr-card sm:rounded-l-2xl shadow-2xl flex flex-col overflow-hidden">
            <div class="px-5 py-4 flex items-center justify-between border-b mr-divider shrink-0">
                <h3 class="text-sm font-extrabold mr-title">Request Details</h3>
                <div class="flex items-center gap-2">
                    <span id="panelReqNum" class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-300 text-[10px] font-mono font-bold"></span>
                    <button onclick="closeViewPanel()" class="w-7 h-7 rounded-lg mr-muted hover:bg-slate-100 dark:hover:bg-white/10 transition"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>
            <div id="panelBody" class="flex-1 overflow-y-auto p-5 space-y-5"></div>
        </div>
    </aside>

    <!-- DECLINE MODAL -->
    <div id="declineModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[100] hidden flex items-center justify-center p-4">
        <div class="mr-card rounded-2xl max-w-md w-full p-5 space-y-4 shadow-2xl">
            <h3 class="text-sm font-extrabold mr-title flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-red-500"></i> Decline Maintenance Request
            </h3>
            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="decline_request">
                <input type="hidden" name="request_id" id="decline_request_id">

                <div>
                    <label class="text-[10px] font-bold mr-muted uppercase tracking-wider block mb-1.5">Reason for Decline</label>
                    <textarea name="decline_reason" rows="3" required placeholder="Please provide a reason for declining this request..." class="mr-input w-full rounded-xl p-2.5 text-xs"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" onclick="closeDeclineModal()" class="px-3.5 py-2 mr-inset mr-text text-xs font-semibold rounded-xl">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white text-xs font-bold rounded-xl transition">Decline Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ASSIGN STAFF MODAL -->
    <div id="assignModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[100] hidden flex items-center justify-center p-4">
        <div class="mr-card rounded-2xl max-w-md w-full p-5 space-y-4 shadow-2xl">
            <h3 class="text-sm font-extrabold mr-title flex items-center gap-2">
                <i class="fa-solid fa-user-plus text-violet-500"></i> Assign Staff Member
            </h3>
            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="assign_staff">
                <input type="hidden" name="request_id" id="assign_request_id">

                <div>
                    <label class="text-[10px] font-bold mr-muted uppercase tracking-wider block mb-1.5">Request</label>
                    <div id="assign_request_number" class="text-xs text-violet-600 dark:text-violet-300 font-mono font-bold"></div>
                </div>

                <div>
                    <label class="text-[10px] font-bold mr-muted uppercase tracking-wider block mb-1.5">Select Staff Member</label>
                    <select name="staff_id" required class="mr-input w-full rounded-xl p-2.5 text-xs">
                        <option value="">-- Select Staff --</option>
                        <?php foreach ($staff_list as $staff): ?>
                            <option value="<?= htmlspecialchars($staff['id']) ?>"><?= htmlspecialchars($staff['name']) ?><?= !empty($staff['job_role']) ? ' — ' . htmlspecialchars($staff['job_role']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" onclick="closeAssignModal()" class="px-3.5 py-2 mr-inset mr-text text-xs font-semibold rounded-xl">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-violet-600 hover:bg-violet-500 text-white text-xs font-bold rounded-xl transition">Assign Staff</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PHOTO VIEWER -->
    <div id="photoViewer" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[110] hidden flex items-center justify-center p-6" onclick="closePhotoViewer()">
        <img id="photoViewerImg" src="" class="max-w-full max-h-full rounded-xl shadow-2xl border border-white/10" alt="Attachment">
    </div>

    <script>
        const requestDetails = <?= json_encode($request_details, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const ACTIVE_STATUSES = ['Approved', 'Assigned', 'In Progress', 'For Verification'];
        let panelMapObj = null;

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        function fmtDate(s) {
            if (!s) return 'N/A';
            const t = new Date(s);
            return isNaN(t) ? s : t.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function fmtDateTime(s) {
            if (!s) return 'N/A';
            const t = new Date(s);
            return isNaN(t) ? s : t.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' · ' + t.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }

        function nameInitials(name) {
            const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            return (((parts[0] || 'U')[0] || 'U') + (parts[1] ? parts[1][0] : '')).toUpperCase();
        }

        /* ---------- FILTERS ---------- */
        function applyFilters() {
            const q   = document.getElementById('filterSearch').value.trim().toLowerCase();
            const st  = document.getElementById('filterStatus').value;
            const pr  = document.getElementById('filterPriority').value;
            const sec = document.getElementById('filterSection').value;
            const dt  = document.getElementById('filterDate').value;
            const rows = document.querySelectorAll('#requestTbody tr[data-id]');
            let visible = 0;
            rows.forEach(tr => {
                const ok = (!q || tr.dataset.search.includes(q))
                    && (!st || (st === '__active__' ? ACTIVE_STATUSES.includes(tr.dataset.status) : tr.dataset.status === st))
                    && (!pr || tr.dataset.priority === pr)
                    && (!sec || tr.dataset.section === sec)
                    && (!dt || tr.dataset.date === dt);
                tr.classList.toggle('hidden', !ok);
                if (ok) visible++;
            });
            document.getElementById('noResults').classList.toggle('hidden', !(rows.length && visible === 0));
        }

        function setStatusFilter(v) {
            document.getElementById('filterStatus').value = v;
            applyFilters();
        }

        function resetFilters() {
            document.getElementById('filterSearch').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterPriority').value = '';
            document.getElementById('filterSection').value = '';
            document.getElementById('filterDate').value = '';
            applyFilters();
        }

        ['filterSearch', 'filterStatus', 'filterPriority', 'filterSection', 'filterDate'].forEach(id => {
            const el = document.getElementById(id);
            el.addEventListener(el.tagName === 'INPUT' && el.type === 'text' ? 'input' : 'change', applyFilters);
        });

        /* ---------- ROW ACTIONS MENU ---------- */
        function submitRowAction(id, action) {
            document.getElementById('rowActionRequestId').value = id;
            document.getElementById('rowActionName').value = action;
            document.getElementById('rowActionForm').submit();
        }

        function toggleRowMenu(btn, e) {
            e.stopPropagation();
            const menu = document.getElementById('rowMenu');
            if (!menu.classList.contains('hidden') && menu.dataset.for === btn.dataset.id) {
                menu.classList.add('hidden');
                return;
            }
            const id = btn.dataset.id, status = btn.dataset.status, assigned = btn.dataset.assigned === '1';
            const item = (label, icon, cls, fn) =>
                `<button onclick="${fn}" class="w-full flex items-center gap-2.5 px-3.5 py-2 text-left text-xs font-medium ${cls} hover:bg-violet-50 dark:hover:bg-white/10 transition"><i class="fa-solid ${icon} w-3.5 text-center"></i>${label}</button>`;

            let html = item('View Details', 'fa-eye', 'mr-text', `openViewPanel('${id}')`);
            if (status === 'Pending Review') {
                html += item('Accept Request', 'fa-check', 'text-emerald-600 dark:text-emerald-400', `submitRowAction('${id}','accept_request')`);
                html += item('Assign Staff', 'fa-user-plus', 'text-blue-600 dark:text-blue-400', `openAssignModal('${id}')`);
                html += item('Decline Request', 'fa-xmark', 'text-red-600 dark:text-red-400', `openDeclineModal('${id}')`);
            } else if (status === 'Approved' && !assigned) {
                html += item('Assign Staff', 'fa-user-plus', 'text-blue-600 dark:text-blue-400', `openAssignModal('${id}')`);
            } else if (status === 'Assigned') {
                html += item('Reassign Staff', 'fa-user-pen', 'text-amber-600 dark:text-amber-400', `openAssignModal('${id}')`);
            } else if (status === 'For Verification') {
                html += item('Verify & Complete', 'fa-clipboard-check', 'text-cyan-600 dark:text-cyan-400', `submitRowAction('${id}','verify_request')`);
            }
            menu.innerHTML = html;
            menu.dataset.for = id;
            menu.classList.remove('hidden');
            const r = btn.getBoundingClientRect();
            let top = r.bottom + 6;
            if (top + menu.offsetHeight > window.innerHeight - 8) top = r.top - menu.offsetHeight - 6;
            let left = r.right - menu.offsetWidth;
            if (left < 8) left = 8;
            menu.style.top = top + 'px';
            menu.style.left = left + 'px';
        }

        document.addEventListener('click', () => document.getElementById('rowMenu').classList.add('hidden'));

        /* ---------- DETAILS PANEL ---------- */
        function openViewPanel(requestId) {
            const d = requestDetails[requestId];
            if (!d) return;
            document.getElementById('rowMenu').classList.add('hidden');
            document.getElementById('panelReqNum').textContent = d.request_number;

            const prioLower = (d.priority || '').toLowerCase();
            const prioClass = prioLower === 'urgent'
                ? 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20'
                : prioLower === 'high'
                    ? 'bg-orange-500/10 text-orange-600 dark:text-orange-400 border border-orange-500/20'
                    : prioLower === 'medium'
                        ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20'
                        : 'bg-slate-500/10 text-slate-500 dark:text-slate-400 border border-slate-500/20';

            const statusMap = {
                'Completed': 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20',
                'Approved': 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
                'In Progress': 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20',
                'Assigned': 'bg-violet-500/10 text-violet-600 dark:text-violet-300 border border-violet-500/20',
                'For Verification': 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-500/20',
                'Declined': 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20'
            };
            const statusClass = statusMap[d.status] || 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20';
            const statusLabel = d.status === 'Pending Review' ? 'Pending' : d.status;
            const canAssign = ['Pending Review', 'Approved', 'Assigned'].includes(d.status);

            const dRow = (icon, label, value) => `
                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded-lg bg-violet-500/10 text-violet-500 flex items-center justify-center text-[11px] shrink-0 mt-0.5"><i class="fa-solid ${icon}"></i></div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[9px] font-bold mr-muted uppercase tracking-wider">${label}</div>
                        <div class="mt-0.5">${value}</div>
                    </div>
                </div>`;

            const thumb = (d.photos && d.photos.length)
                ? `<img src="${escapeHtml(d.photos[0].file_path)}" class="w-16 h-16 rounded-xl object-cover border mr-divider cursor-pointer" onclick="openPhotoViewer('${escapeHtml(d.photos[0].file_path)}')" alt="Request photo">`
                : `<div class="w-16 h-16 rounded-xl bg-violet-500/10 flex items-center justify-center shrink-0"><i class="fa-solid fa-location-dot text-violet-500 text-xl"></i></div>`;

            const dobLine = (d.date_of_birth || d.date_of_death)
                ? `<div class="text-[9px] mr-muted mt-0.5">Birth: ${fmtDate(d.date_of_birth)} &nbsp;—&nbsp; Death: ${fmtDate(d.date_of_death)}</div>` : '';

            const requesterHtml = `
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-gradient-to-br from-violet-500 to-purple-600 text-white flex items-center justify-center text-[8px] font-bold shrink-0">${nameInitials(d.requester_name)}</div>
                    <div class="min-w-0">
                        <div class="text-[11px] font-semibold mr-text truncate">${escapeHtml(d.requester_name)}</div>
                        <div class="text-[9px] mr-muted truncate">${escapeHtml(d.requester_email || 'No email')}${d.requester_phone ? ' · ' + escapeHtml(d.requester_phone) : ''}</div>
                    </div>
                </div>`;

            const staffRowHtml = `
                <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] mr-text font-medium">${escapeHtml(d.staff_names || '—')}</span>
                    ${canAssign ? `<button onclick="openAssignModal('${requestId}')" class="px-2.5 py-1 rounded-lg border border-violet-500/30 text-violet-600 dark:text-violet-300 text-[9px] font-bold hover:bg-violet-500/10 transition shrink-0">Assign Staff</button>` : ''}
                </div>`;

            let trackerHtml;
            if (d.status === 'Declined') {
                trackerHtml = `<div class="rounded-xl bg-red-500/10 border border-red-500/20 p-3 text-[11px] font-semibold text-red-600 dark:text-red-400 flex items-center gap-2"><i class="fa-solid fa-circle-xmark"></i>This request was declined.</div>`;
            } else {
                const idx = d.status === 'Completed' ? 2 : (ACTIVE_STATUSES.includes(d.status) ? 1 : 0);
                const steps = [
                    { icon: 'fa-clock', label: 'Pending' },
                    { icon: 'fa-wrench', label: 'In Progress' },
                    { icon: 'fa-flag-checkered', label: 'Completed' }
                ];
                trackerHtml = `<div><div class="text-[10px] font-bold mr-muted uppercase tracking-wider mb-3">Request Status</div><div class="flex items-start">` +
                    steps.map((s, i) => {
                        const state = i < idx ? 'done' : (i === idx ? 'current' : 'todo');
                        const circle = state === 'done'
                            ? 'bg-violet-600 text-white'
                            : state === 'current'
                                ? 'bg-amber-500 text-white ring-4 ring-amber-500/20'
                                : 'bg-slate-200 dark:bg-slate-700 mr-muted';
                        const connector = i < steps.length - 1
                            ? `<div class="flex-1 h-0.5 mx-2 mt-[15px] rounded ${i < idx ? 'bg-violet-600' : 'bg-slate-200 dark:bg-slate-700'}"></div>` : '';
                        return `<div class="flex flex-col items-center gap-1.5 shrink-0"><div class="w-8 h-8 rounded-full ${circle} flex items-center justify-center text-[11px]"><i class="fa-solid ${s.icon}"></i></div><span class="text-[9px] font-semibold ${state === 'todo' ? 'mr-muted' : 'mr-text'}">${s.label}</span></div>${connector}`;
                    }).join('') + `</div></div>`;
            }

            const photoGroups = { 'Request Photos': [], 'Before': [], 'Completion': [] };
            (d.photos || []).forEach(p => {
                const t = (p.photo_type || '').toLowerCase();
                if (t.includes('before')) photoGroups['Before'].push(p);
                else if (t.includes('completion') || t.includes('after')) photoGroups['Completion'].push(p);
                else photoGroups['Request Photos'].push(p);
            });
            let photosHtml = '';
            const photoEntries = Object.entries(photoGroups).filter(([, v]) => v.length);
            if (photoEntries.length) {
                photosHtml = `<div><div class="text-[10px] font-bold mr-muted uppercase tracking-wider mb-2">Attachments</div><div class="space-y-2">` +
                    photoEntries.map(([label, photos]) => `
                        <div>
                            <div class="text-[9px] font-semibold mr-muted mb-1">${label}</div>
                            <div class="flex flex-wrap gap-2">${photos.map(p => `<img src="${escapeHtml(p.file_path)}" onclick="openPhotoViewer('${escapeHtml(p.file_path)}')" class="w-14 h-14 rounded-lg object-cover border mr-divider cursor-pointer hover:opacity-80 transition" alt="${label}">`).join('')}</div>
                        </div>`).join('') + `</div></div>`;
            }

            let historyHtml = '';
            if (d.history && d.history.length) {
                historyHtml = `<div><div class="text-[10px] font-bold mr-muted uppercase tracking-wider mb-2">Request History</div><div class="space-y-2.5 max-h-44 overflow-y-auto pr-1">` +
                    d.history.map(h => `
                        <div class="flex gap-2 items-start">
                            <div class="w-1.5 h-1.5 rounded-full bg-violet-500 mt-1.5 shrink-0"></div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[11px] mr-text font-semibold">${escapeHtml(h.action)}
                                    <span class="text-[9px] mr-muted font-normal">${h.old_status ? escapeHtml(h.old_status) + ' → ' : ''}${escapeHtml(h.new_status || '')}</span>
                                </p>
                                ${h.notes ? `<p class="text-[10px] mr-muted">${escapeHtml(h.notes)}</p>` : ''}
                                <p class="text-[9px] mr-muted">${escapeHtml(h.actor_name)} · ${fmtDateTime(h.created_at)}</p>
                            </div>
                        </div>`).join('') + `</div></div>`;
            }

            const btnSoft = 'w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl mr-inset mr-text text-xs font-bold hover:border-violet-400 transition';
            let actionsHtml = `<a href="admin_plots.php" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-violet-600 hover:bg-violet-500 text-white text-xs font-bold shadow-lg shadow-violet-600/25 transition"><i class="fa-solid fa-map-location-dot"></i>View Plot Details</a>`;
            if (d.status === 'Pending Review') {
                actionsHtml += `<button onclick="submitRowAction('${requestId}','accept_request')" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition"><i class="fa-solid fa-check"></i>Accept Request</button>`;
                actionsHtml += `<button onclick="openAssignModal('${requestId}')" class="${btnSoft}"><i class="fa-solid fa-user-plus text-violet-500"></i>Assign Staff</button>`;
                actionsHtml += `<button onclick="openDeclineModal('${requestId}')" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-xs font-bold hover:bg-red-500/20 transition"><i class="fa-solid fa-xmark"></i>Decline Request</button>`;
            } else if (d.status === 'Approved' && !d.staff_names) {
                actionsHtml += `<button onclick="openAssignModal('${requestId}')" class="${btnSoft}"><i class="fa-solid fa-user-plus text-violet-500"></i>Assign Staff</button>`;
            } else if (d.status === 'Assigned') {
                actionsHtml += `<button onclick="openAssignModal('${requestId}')" class="${btnSoft}"><i class="fa-solid fa-user-pen text-amber-500"></i>Reassign Staff</button>`;
            } else if (d.status === 'For Verification') {
                actionsHtml += `<button onclick="submitRowAction('${requestId}','verify_request')" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold transition"><i class="fa-solid fa-clipboard-check"></i>Verify &amp; Mark Complete</button>`;
            }

            document.getElementById('panelBody').innerHTML = `
                <div class="flex items-start gap-3.5">
                    ${thumb}
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-extrabold mr-title">${escapeHtml(d.plot_number)}</div>
                        <div class="text-[11px] font-semibold mr-text">${escapeHtml(d.deceased_name || 'No deceased record')}</div>
                        ${dobLine}
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase shrink-0 ${statusClass}">${escapeHtml(statusLabel)}</span>
                </div>

                <div class="mr-inset rounded-xl px-3.5 py-2.5 flex items-center justify-between gap-2">
                    <span class="text-[11px] mr-text flex items-center gap-2 min-w-0"><i class="fa-solid fa-location-dot text-violet-500 shrink-0"></i><span class="truncate">${escapeHtml(d.plot_number)}</span></span>
                    <a href="admin_plots.php" class="text-[10px] font-bold text-violet-600 dark:text-violet-300 whitespace-nowrap hover:underline"><i class="fa-regular fa-map mr-1"></i>View on Map</a>
                </div>

                <div class="space-y-3.5">
                    ${dRow('fa-broom', 'Service Type', `<span class="text-[11px] font-semibold mr-text">${escapeHtml(d.service_name)}</span>${d.default_fee !== null && d.default_fee !== undefined ? ` <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold">₱${Number(d.default_fee).toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>` : ''}`)}
                    ${dRow('fa-flag', 'Priority', `<span class="px-2 py-0.5 rounded-md text-[9px] font-bold uppercase ${prioClass}">${escapeHtml(d.priority)}</span> <span class="text-[9px] mr-muted">score ${(Number(d.priority_score) / 10).toFixed(1)}</span>`)}
                    ${dRow('fa-user', 'Requester', requesterHtml)}
                    ${dRow('fa-calendar-plus', 'Date Requested', `<span class="text-[11px] mr-text">${fmtDateTime(d.created_at)}</span>`)}
                    ${dRow('fa-calendar-check', 'Preferred Date', `<span class="text-[11px] mr-text">${escapeHtml(d.preferred_date || 'N/A')} <span class="mr-muted">(${escapeHtml(d.preferred_time || 'Flexible')})</span></span>`)}
                    ${dRow('fa-clock', 'Scheduled', `<span class="text-[11px] mr-text">${escapeHtml(d.scheduled_display)}</span>`)}
                    ${dRow('fa-user-gear', 'Assigned Staff', staffRowHtml)}
                </div>

                ${trackerHtml}

                <div>
                    <div class="text-[10px] font-bold mr-muted uppercase tracking-wider mb-2">Location Preview</div>
                    <div class="rounded-xl overflow-hidden border mr-divider relative">
                        <div id="panelMap"></div>
                        <a href="admin_plots.php" class="absolute bottom-2.5 right-2.5 z-[500] px-3 py-1.5 rounded-lg bg-violet-600 text-white text-[10px] font-bold shadow-lg hover:bg-violet-500 transition">Open Full Map</a>
                    </div>
                </div>

                <div>
                    <div class="text-[10px] font-bold mr-muted uppercase tracking-wider mb-1.5">Description / Instructions</div>
                    <div class="mr-inset rounded-xl p-3 text-[11px] mr-text leading-relaxed whitespace-pre-wrap">${escapeHtml(d.description || 'No description provided.')}</div>
                </div>

                ${photosHtml}
                ${historyHtml}
                <div class="space-y-2 pt-1">${actionsHtml}</div>
            `;

            document.getElementById('panelBackdrop').classList.remove('hidden');
            document.getElementById('requestPanel').classList.remove('translate-x-full');

            const lat = parseFloat(d.latitude), lng = parseFloat(d.longitude);
            const mapEl = document.getElementById('panelMap');
            if (isFinite(lat) && isFinite(lng)) {
                if (panelMapObj) { panelMapObj.remove(); panelMapObj = null; }
                panelMapObj = L.map('panelMap', { zoomControl: false, attributionControl: false }).setView([lat, lng], 18);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 22 }).addTo(panelMapObj);
                const pin = L.divIcon({
                    className: '',
                    html: '<div style="width:24px;height:24px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:#7c3aed;border:3px solid #fff;box-shadow:0 4px 10px rgba(0,0,0,0.3);"></div>',
                    iconSize: [24, 24], iconAnchor: [12, 24]
                });
                L.marker([lat, lng], { icon: pin }).addTo(panelMapObj)
                    .bindTooltip(escapeHtml(d.plot_number), { permanent: true, direction: 'top', offset: [0, -24], className: 'mr-map-label' });
                setTimeout(() => panelMapObj && panelMapObj.invalidateSize(), 300);
            } else {
                mapEl.innerHTML = '<div class="h-full w-full flex items-center justify-center text-[10px] mr-muted italic bg-slate-100 dark:bg-slate-800">No location data for this plot.</div>';
            }
        }

        function closeViewPanel() {
            document.getElementById('requestPanel').classList.add('translate-x-full');
            document.getElementById('panelBackdrop').classList.add('hidden');
        }

        function openDeclineModal(requestId) {
            document.getElementById('rowMenu').classList.add('hidden');
            document.getElementById('decline_request_id').value = requestId;
            document.getElementById('declineModal').classList.remove('hidden');
        }

        function closeDeclineModal() {
            document.getElementById('declineModal').classList.add('hidden');
        }

        function openAssignModal(requestId) {
            document.getElementById('rowMenu').classList.add('hidden');
            const d = requestDetails[requestId];
            document.getElementById('assign_request_id').value = requestId;
            document.getElementById('assign_request_number').textContent = d ? d.request_number : '';
            document.getElementById('assignModal').classList.remove('hidden');
        }

        function closeAssignModal() {
            document.getElementById('assignModal').classList.add('hidden');
        }

        function openPhotoViewer(src) {
            document.getElementById('photoViewerImg').src = src;
            document.getElementById('photoViewer').classList.remove('hidden');
        }

        function closePhotoViewer() {
            document.getElementById('photoViewer').classList.add('hidden');
            document.getElementById('photoViewerImg').src = '';
        }

        ['declineModal', 'assignModal'].forEach(id => {
            document.getElementById(id).addEventListener('click', function (e) {
                if (e.target === this) this.classList.add('hidden');
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (!document.getElementById('photoViewer').classList.contains('hidden')) { closePhotoViewer(); return; }
            if (!document.getElementById('declineModal').classList.contains('hidden')) { closeDeclineModal(); return; }
            if (!document.getElementById('assignModal').classList.contains('hidden')) { closeAssignModal(); return; }
            closeViewPanel();
        });

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        }
    </script>
</body>
</html>