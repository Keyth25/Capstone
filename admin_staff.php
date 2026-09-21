<?php
// Session check is handled inside config.php — no separate session_start() needed here
require 'config.php';

// Access Control
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$error = '';

// Job roles the admin can assign to staff members
$staff_roles = ['Grave Digger', 'Grounds Keeper', 'Mausoleum Caretaker', 'Equipment Operator'];

// Ensure the profiles table can store the assigned job role
try {
    $pdo->exec("ALTER TABLE public.profiles ADD COLUMN IF NOT EXISTS job_role TEXT");
} catch (PDOException $e) {
    // Ignore if the column already exists or the table is not yet created
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ACTION: Create Staff Account
    if ($_POST['action'] === 'create_staff') {
        $name     = trim($_POST['staff_name'] ?? '');
        $email    = trim($_POST['staff_email'] ?? '');
        $password = trim($_POST['staff_password'] ?? '');
        $job_role = trim($_POST['job_role'] ?? '');
        $plot_id  = !empty($_POST['assigned_plot_id']) ? intval($_POST['assigned_plot_id']) : null;

        if (empty($name) || empty($email) || empty($password) || empty($job_role)) {
            $error = "Please fill in all staff fields.";
        } elseif (!in_array($job_role, $staff_roles, true)) {
            $error = "Please select a valid job role.";
        } else {
            // Supabase Admin API endpoint for creating users
            $admin_auth_url = rtrim($supabase_url, '/') . '/auth/v1/admin/users';
            $payload = json_encode([
                'email' => $email,
                'password' => $password,
                'email_confirm' => true,
                'user_metadata' => [
                    'full_name' => $name,
                    'role' => 'staff',
                    'job_role' => $job_role
                ]
            ]);

            $ch = curl_init($admin_auth_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $supabase_service_role_key,
                'Authorization: Bearer ' . $supabase_service_role_key,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $res_data = json_decode($response, true);

            if ($httpcode == 200 || $httpcode == 201) {
                $supabase_user_id = $res_data['id'];

                try {
                    $pdo->beginTransaction();

                    // Upsert profile record
                    $profile_stmt = $pdo->prepare("
                        INSERT INTO public.profiles (id, first_name, email, job_role)
                        VALUES (?, ?, ?, ?)
                        ON CONFLICT (id) DO UPDATE 
                        SET first_name = EXCLUDED.first_name, email = EXCLUDED.email, job_role = EXCLUDED.job_role
                    ");
                    $profile_stmt->execute([$supabase_user_id, $name, $email, $job_role]);

                    // Assign plot if provided
                    if ($plot_id) {
                        $assignStmt = $pdo->prepare("
                            INSERT INTO public.staff_assignments (staff_id, record_id) VALUES (?, ?)
                        ");
                        $assignStmt->execute([$supabase_user_id, $plot_id]);
                    }

                    $pdo->commit();
                    $message = "Staff account created successfully!";
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Profile creation error: " . $e->getMessage();
                }
            } else {
                $error = $res_data['msg'] ?? $res_data['message'] ?? $res_data['error_description'] ?? "Failed to create staff user via Supabase Auth.";
            }
        }
    }

    // ACTION: Update Staff Job Role
    if ($_POST['action'] === 'update_role') {
        $staff_id = trim($_POST['staff_id'] ?? '');
        $job_role = trim($_POST['job_role'] ?? '');

        if (empty($staff_id) || !in_array($job_role, $staff_roles, true)) {
            $error = "Please select a valid job role.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE public.profiles SET job_role = ? WHERE id::text = ?");
                $stmt->execute([$job_role, $staff_id]);
                $message = "Staff role updated!";
            } catch (PDOException $e) {
                $error = "Role update error: " . $e->getMessage();
            }
        }
    }

    // ACTION: Delete Staff Account
    if ($_POST['action'] === 'delete_staff') {
        $staff_id = trim($_POST['staff_id'] ?? '');

        if (!empty($staff_id)) {
            // Delete from Supabase Auth
            $delete_auth_url = rtrim($supabase_url, '/') . '/auth/v1/admin/users/' . $staff_id;
            $ch = curl_init($delete_auth_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $supabase_service_role_key,
                'Authorization: Bearer ' . $supabase_service_role_key,
            ]);
            curl_exec($ch);
            curl_close($ch);

            try {
                $pdo->beginTransaction();

                // Clear assignments first to avoid Foreign Key constraints
                $stmtAssign = $pdo->prepare("DELETE FROM public.staff_assignments WHERE staff_id = ?");
                $stmtAssign->execute([$staff_id]);

                // Clear profile
                $stmtProfile = $pdo->prepare("DELETE FROM public.profiles WHERE id = ?");
                $stmtProfile->execute([$staff_id]);

                $pdo->commit();
                $message = "Staff account removed!";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Staff Delete Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch registered staff and optional assigned plots
// Explicit type casting (sa.record_id::text = dr.id::text) prevents PostgreSQL type mismatch errors
$staff_members = $pdo->query("
    SELECT p.id, p.first_name as name, p.email, p.job_role, sa.record_id, dr.plot_code, dr.deceased_name
    FROM public.profiles p
    JOIN auth.users au ON au.id = p.id
    LEFT JOIN public.staff_assignments sa ON p.id = sa.staff_id
    LEFT JOIN public.deceased_records dr ON sa.record_id::text = dr.id::text
    WHERE au.raw_user_meta_data->>'role' = 'staff'
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Staff Management - PlotBox GIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <i class="fa-solid fa-users text-purple-400"></i> Staff Account Management
                </h1>
            </div>
        </header>

        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6 overflow-y-auto">
            <!-- Form Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-xl space-y-3">
                <span class="text-xs font-bold text-purple-400 uppercase tracking-wider block">Add Staff Account</span>
                
                <?php if ($message): ?>
                    <div class="p-2 bg-emerald-950 border border-emerald-800 text-emerald-300 text-xs rounded"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="p-2 bg-red-950 border border-red-800 text-red-300 text-xs rounded"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="space-y-3">
                    <input type="hidden" name="action" value="create_staff">
                    <div>
                        <label class="text-[10px] text-slate-400 block mb-1">Full Name</label>
                        <input type="text" name="staff_name" required class="w-full bg-slate-950 border border-slate-700 rounded p-2 text-xs text-white focus:outline-none focus:border-purple-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-400 block mb-1">Email Address</label>
                        <input type="email" name="staff_email" required class="w-full bg-slate-950 border border-slate-700 rounded p-2 text-xs text-white focus:outline-none focus:border-purple-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-400 block mb-1">Password</label>
                        <input type="password" name="staff_password" required minlength="6" class="w-full bg-slate-950 border border-slate-700 rounded p-2 text-xs text-white focus:outline-none focus:border-purple-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-400 block mb-1">Job Role</label>
                        <select name="job_role" required class="w-full bg-slate-950 border border-slate-700 rounded p-2 text-xs text-white focus:outline-none focus:border-purple-500">
                            <option value="">-- Select Role --</option>
                            <?php foreach ($staff_roles as $role): ?>
                                <option value="<?= htmlspecialchars($role) ?>"><?= htmlspecialchars($role) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="w-full py-2 bg-purple-600 hover:bg-purple-500 text-white font-bold text-xs rounded transition">Create Staff User</button>
                </form>
            </div>

            <!-- List Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-xl flex flex-col">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-3">Registered Staff Accounts</span>
                <div class="space-y-2 overflow-y-auto flex-1 pr-1">
                    <?php if (empty($staff_members)): ?>
                        <div class="text-xs text-slate-500 italic py-4 text-center">No staff accounts found.</div>
                    <?php else: ?>
                        <?php foreach ($staff_members as $st): ?>
                            <div class="p-3 bg-slate-950 border border-slate-800 rounded-lg flex justify-between items-center gap-3">
                                <div class="min-w-0">
                                    <h5 class="font-bold text-xs text-white"><?= htmlspecialchars($st['name'] ?? '') ?></h5>
                                    <span class="text-[10px] text-slate-400"><?= htmlspecialchars($st['email'] ?? '') ?></span>
                                    <div class="flex flex-wrap items-center gap-1 mt-1">
                                        <?php if (!empty($st['job_role'])): ?>
                                            <span class="text-[9px] font-semibold text-purple-300 bg-purple-950 border border-purple-800 px-1.5 py-0.5 rounded inline-flex items-center gap-1">
                                                <i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($st['job_role']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[9px] font-semibold text-slate-500 bg-slate-900 border border-slate-700 px-1.5 py-0.5 rounded">No role assigned</span>
                                        <?php endif; ?>
                                        <?php if (!empty($st['plot_code'])): ?>
                                            <span class="text-[9px] font-mono text-blue-400 bg-blue-950 border border-blue-800 px-1 rounded">Plot: <?= htmlspecialchars($st['plot_code']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="flex items-center gap-1">
                                        <input type="hidden" name="action" value="update_role">
                                        <input type="hidden" name="staff_id" value="<?= htmlspecialchars($st['id']) ?>">
                                        <select name="job_role" class="bg-slate-900 border border-slate-700 rounded px-1.5 py-1 text-[10px] text-slate-300 focus:outline-none focus:border-purple-500">
                                            <option value="" disabled <?= empty($st['job_role']) ? 'selected' : '' ?>>Role...</option>
                                            <?php foreach ($staff_roles as $role): ?>
                                                <option value="<?= htmlspecialchars($role) ?>" <?= ($st['job_role'] ?? '') === $role ? 'selected' : '' ?>><?= htmlspecialchars($role) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" title="Save role" class="text-purple-400 hover:text-purple-300 text-xs px-1.5 py-1"><i class="fa-solid fa-floppy-disk"></i></button>
                                    </form>
                                    <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" onsubmit="return confirm('Delete staff member?');">
                                        <input type="hidden" name="action" value="delete_staff">
                                        <input type="hidden" name="staff_id" value="<?= htmlspecialchars($st['id']) ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs px-2 py-1"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle function for mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        }
    </script>
</body>
</html>