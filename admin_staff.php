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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ACTION: Create Staff Account
    if ($_POST['action'] === 'create_staff') {
        $name     = trim($_POST['staff_name'] ?? '');
        $email    = trim($_POST['staff_email'] ?? '');
        $password = trim($_POST['staff_password'] ?? '');
        $plot_id  = !empty($_POST['assigned_plot_id']) ? intval($_POST['assigned_plot_id']) : null;

        if (empty($name) || empty($email) || empty($password)) {
            $error = "Please fill in all staff fields.";
        } else {
            // Supabase Admin API endpoint for creating users
            $admin_auth_url = rtrim($supabase_url, '/') . '/auth/v1/admin/users';
            $payload = json_encode([
                'email' => $email,
                'password' => $password,
                'email_confirm' => true,
                'user_metadata' => [
                    'full_name' => $name,
                    'role' => 'staff'
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
                        INSERT INTO public.profiles (id, first_name, email)
                        VALUES (?, ?, ?)
                        ON CONFLICT (id) DO UPDATE 
                        SET first_name = EXCLUDED.first_name, email = EXCLUDED.email
                    ");
                    $profile_stmt->execute([$supabase_user_id, $name, $email]);

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
    SELECT p.id, p.first_name as name, p.email, sa.record_id, dr.plot_code, dr.deceased_name
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - PlotBox GIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-950 text-slate-100 h-screen flex font-sans overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="bg-slate-900 border-b border-slate-800 px-6 py-3 flex items-center justify-between shrink-0">
            <h1 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-users text-purple-400"></i> Staff Account Management
            </h1>
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
                            <div class="p-3 bg-slate-950 border border-slate-800 rounded-lg flex justify-between items-center">
                                <div>
                                    <h5 class="font-bold text-xs text-white"><?= htmlspecialchars($st['name'] ?? '') ?></h5>
                                    <span class="text-[10px] text-slate-400"><?= htmlspecialchars($st['email'] ?? '') ?></span>
                                    <?php if (!empty($st['plot_code'])): ?>
                                        <span class="text-[9px] font-mono text-blue-400 bg-blue-950 border border-blue-800 px-1 rounded mt-1 inline-block">Plot: <?= htmlspecialchars($st['plot_code']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" onsubmit="return confirm('Delete staff member?');">
                                    <input type="hidden" name="action" value="delete_staff">
                                    <input type="hidden" name="staff_id" value="<?= htmlspecialchars($st['id']) ?>">
                                    <button type="submit" class="text-red-400 hover:text-red-300 text-xs px-2 py-1"><i class="fa-solid fa-trash"></i></button>
                                </form>
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