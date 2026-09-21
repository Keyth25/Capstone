<?php
require 'config.php';

$error = '';
$success = '';
$mode = $_GET['mode'] ?? 'login'; // 'login', 'register', or 'forgot'
$embed = ($_GET['embed'] ?? '') === '1';
$embedParam = $embed ? '&amp;embed=1' : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? 'login';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // ==========================================
    // 1. REGISTER LOGIC
    // ==========================================
    if ($action === 'register') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $extension = trim($_POST['extension'] ?? '');
        $age = $_POST['age'] ?? '';
        $birthdate = $_POST['birthdate'] ?? '';
        $phone_number = trim($_POST['phone_number'] ?? '');
        $confirm_password = $_POST['confirm_password'] ?? '';
        $role = $_POST['role'] ?? 'user';

        // Build full name for legacy compatibility
        $full_name = trim($first_name . ' ' . $last_name . ' ' . $extension);

        // Server-side validation
        if (!$first_name || !$last_name || !$email || !$phone_number || !$age || !$birthdate || !$password || !$confirm_password) {
            $error = "Please fill in all required fields.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (!ctype_digit($age) || (int)$age < 18) {
            $error = "You must be at least 18 years old to register.";
        } else {
            // Sign up user via Supabase Auth REST API
            $signup_url = $supabase_url . '/auth/v1/signup';
            $payload = json_encode([
                'email' => $email,
                'password' => $password,
                'data' => [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'extension' => $extension,
                    'age' => (int)$age,
                    'birthdate' => $birthdate,
                    'phone_number' => $phone_number,
                    'full_name' => $full_name,
                    'role' => $role
                ]
            ]);

            $ch = curl_init($signup_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $supabase_anon_key,
                'Authorization: Bearer ' . $supabase_anon_key,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $res_data = json_decode($response, true);

            if ($httpcode == 200 || $httpcode == 201) {
                $success = "Registration successful! You can now log in.";
                $mode = 'login';
            } else {
                if (isset($res_data['msg'])) {
                    $error = $res_data['msg'];
                } elseif (isset($res_data['error_description'])) {
                    $error = $res_data['error_description'];
                } elseif (isset($res_data['message'])) {
                    $error = $res_data['message'];
                } else {
                    $error = "Registration failed (HTTP " . $httpcode . "). Please try again.";
                }
            }
        }

    // ==========================================
    // 2. LOGIN LOGIC
    // ==========================================
    } elseif ($action === 'login') {
        $auth_url = $supabase_url . '/auth/v1/token?grant_type=password';
        $payload = json_encode(['email' => $email, 'password' => $password]);

        $ch = curl_init($auth_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $supabase_anon_key,
            'Authorization: Bearer ' . $supabase_anon_key,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode == 200) {
            $auth_data = json_decode($response, true);
            $user_id = $auth_data['user']['id'];

            $auth_user = $auth_data['user'] ?? [];
            $role = $auth_user['user_metadata']['role'] ?? 'user';
            $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM public.profiles WHERE id = ?");
            $stmt->execute([$user_id]);
            $profile = $stmt->fetch();

            if ($profile) {
                $_SESSION['user_id'] = $user_id;
                $_SESSION['role'] = $role;
                $_SESSION['name'] = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: 'User';
                $_SESSION['email'] = $profile['email'];

                ensure_public_user($pdo, $user_id);

                $dashboard = match ($role) {
                    'admin' => 'admin_dashboard.php',
                    'staff' => 'staff.php',
                    default => 'user_dashboard.php',
                };

                if ($embed) {
                    ?>
                    <!DOCTYPE html>
                    <html lang="en" style="background:transparent">
                    <body style="background:transparent">
                        <script>
                            try { sessionStorage.setItem('cemeterynav_logged_in', '1'); } catch (e) {}
                            if (window.parent) {
                                window.parent.postMessage({type: 'cemeterynav-redirect', url: <?= json_encode($dashboard) ?>}, '*');
                            }
                        </script>
                    </body>
                    </html>
                    <?php
                    exit();
                }
                ?>
                <!DOCTYPE html>
                <html lang="en">
                <body>
                    <script>
                        sessionStorage.setItem('cemeterynav_logged_in', '1');
                        window.location.href = <?= json_encode($dashboard) ?>;
                    </script>
                </body>
                </html>
                <?php
                exit();
            } else {
                $error = "Profile record not found in the database.";
            }
        } else {
            $error_data = json_decode($response, true);
            $error = $error_data['error_description'] ?? $error_data['msg'] ?? 'Invalid login credentials.';
        }

    // ==========================================
    // 3. FORGOT PASSWORD RESET REQUEST LOGIC
    // ==========================================
    } elseif ($action === 'forgot_password') {
        $recover_url = $supabase_url . '/auth/v1/recover';
        $payload = json_encode([
            'email' => $email
        ]);

        $ch = curl_init($recover_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $supabase_anon_key,
            'Authorization: Bearer ' . $supabase_anon_key,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode == 200) {
            $success = "If this email is registered, a password reset link has been sent.";
            $mode = 'login';
        } else {
            $error_data = json_decode($response, true);
            $error = $error_data['msg'] ?? $error_data['error_description'] ?? 'Failed to send password reset email.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>CemeteryNav — Portal Access</title>
    
    <!-- Google Fonts & Font Awesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Anti-flicker script to instantly apply dark mode state -->
    <script>
        if (localStorage.getItem('theme') === 'light' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: light)').matches)) {
            document.documentElement.classList.remove('dark');
        } else {
            document.documentElement.classList.add('dark');
        }
    </script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f5f3ff',
                            100: '#ede9fe',
                            200: '#ddd6fe',
                            300: '#c4b5fd',
                            400: '#a78bfa',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                            800: '#5b21b6',
                            900: '#4c1d95',
                            950: '#2e1065',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        /* Dark Theme Settings */
        .dark .mesh-bg {
            background-color: #090d16;
            background-image:
                radial-gradient(at 10% 20%, rgba(124, 58, 237, 0.25) 0px, transparent 40%),
                radial-gradient(at 90% 10%, rgba(167, 139, 250, 0.15) 0px, transparent 35%),
                radial-gradient(at 50% 80%, rgba(109, 40, 217, 0.2) 0px, transparent 45%);
        }

        .dark .glass-card {
            background: rgba(17, 24, 39, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .dark .tab-active {
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.3) 0%, rgba(109, 40, 217, 0.2) 100%);
            color: #ffffff;
            border: 1px solid rgba(167, 139, 250, 0.4);
            box-shadow: 0 4px 12px -2px rgba(124, 58, 237, 0.3);
        }

        /* Light Theme Settings */
        .mesh-bg {
            background-color: #f8fafc;
            background-image:
                radial-gradient(at 10% 20%, rgba(124, 58, 237, 0.12) 0px, transparent 40%),
                radial-gradient(at 90% 10%, rgba(167, 139, 250, 0.1) 0px, transparent 35%),
                radial-gradient(at 50% 80%, rgba(109, 40, 217, 0.08) 0px, transparent 45%);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .tab-active {
            background: #ffffff;
            color: #6d28d9;
            border: 1px solid rgba(124, 58, 237, 0.3);
            box-shadow: 0 4px 12px -2px rgba(124, 58, 237, 0.15);
        }

        .glow-effect {
            box-shadow: 0 0 50px -10px rgba(124, 58, 237, 0.25);
        }

        .input-accent {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .input-accent:focus {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.15);
        }

        .fade-in {
            animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
    <?php if ($embed): ?>
    <style>
        /* Embedded dialog mode: fully transparent so the landing page shows through */
        html, body {
            background: transparent !important;
            background-color: transparent !important;
            color-scheme: normal !important;
        }
    </style>
    <?php endif; ?>
    <link rel="stylesheet" href="mobile.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#7c3aed">
    <script src="pwa.js" defer></script>
    <script src="mobile.js" defer></script>
</head>
<body class="<?= $embed ? 'bg-transparent' : 'mesh-bg' ?> min-h-screen flex items-center justify-center p-4 sm:p-6 relative text-slate-800 dark:text-slate-100 font-sans overflow-x-hidden selection:bg-brand-600 selection:text-white transition-colors duration-300">

    <?php if (!$embed): ?>
    <!-- Ambient Lighting Elements -->
    <div class="absolute -top-32 -left-32 w-96 h-96 bg-brand-600/10 dark:bg-brand-600/20 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-brand-800/10 dark:bg-brand-800/20 rounded-full blur-[120px] pointer-events-none"></div>
    <?php endif; ?>

    <?php if (!$embed): ?>
    <!-- Floating Theme Toggle Button -->
    <button id="theme-toggle" aria-label="Toggle theme" class="fixed top-5 right-5 z-50 w-11 h-11 rounded-2xl glass-card text-slate-700 dark:text-slate-200 flex items-center justify-center shadow-lg hover:scale-110 active:scale-95 transition-all duration-300 cursor-pointer">
        <i id="theme-icon" class="fas fa-moon text-base"></i>
    </button>
    <?php endif; ?>

    <main class="relative z-10 w-full max-w-5xl glass-card glow-effect rounded-3xl overflow-hidden shadow-2xl grid grid-cols-1 lg:grid-cols-12 fade-in">
    <?php if ($embed): ?>
        <button type="button" onclick="if(typeof window.parent.closeAuthModal === 'function') window.parent.closeAuthModal()" aria-label="Close" class="absolute top-3 left-3 z-30 w-7 h-7 rounded-lg bg-white/70 dark:bg-slate-900/70 text-slate-700 dark:text-white flex items-center justify-center transition backdrop-blur-md shadow-sm">
            <i class="fa-solid fa-xmark text-xs"></i>
        </button>
    <?php endif; ?>
        
        <!-- FORM CONTAINER (ORDER FIRST ON DESKTOP) -->
        <section class="lg:col-span-6 w-full p-6 sm:p-10 lg:p-12 flex flex-col justify-between order-2 lg:order-1 border-t lg:border-t-0 lg:border-r border-slate-200 dark:border-slate-800/60">
            <div>
                <!-- Brand Header -->
                <div class="mb-8 text-center lg:text-left flex flex-col items-center lg:items-start">
                    <a href="landing.php" class="inline-flex items-center gap-3 group mb-3">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-brand-700 to-brand-500 flex items-center justify-center text-white font-bold text-2xl shadow-lg shadow-brand-600/30 group-hover:scale-105 transition-transform duration-300">
                            <i class="fa-solid fa-map-location-dot"></i>
                        </div>
                        <span class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">Cemetery<span class="text-brand-600 dark:text-brand-400">Nav</span></span>
                    </a>
                    <p class="text-xs sm:text-sm font-medium text-slate-500 dark:text-slate-400">Interactive Mapping & Voice Navigation System</p>
                </div>

                <!-- Navigation Tabs -->
                <div class="mb-8 flex rounded-xl bg-slate-200/60 dark:bg-slate-950/60 p-1.5 border border-slate-300/60 dark:border-slate-800/80">
                    <a href="login.php?mode=login<?= $embedParam ?>" class="flex-1 py-2.5 text-center text-xs sm:text-sm font-bold rounded-lg transition-all duration-300 flex items-center justify-center gap-2 <?= $mode === 'login' ? 'tab-active' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' ?>">
                        <i class="fas fa-sign-in-alt text-xs"></i> Sign In
                    </a>
                    <a href="login.php?mode=register<?= $embedParam ?>" class="flex-1 py-2.5 text-center text-xs sm:text-sm font-bold rounded-lg transition-all duration-300 flex items-center justify-center gap-2 <?= $mode === 'register' ? 'tab-active' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' ?>">
                        <i class="fas fa-user-plus text-xs"></i> Register
                    </a>
                </div>

                <!-- Notification Alerts -->
                <?php if ($error): ?>
                    <div class="mb-6 rounded-2xl bg-rose-100 dark:bg-rose-950/50 border border-rose-300 dark:border-rose-500/30 p-4 flex items-start gap-3 fade-in shadow-lg shadow-rose-950/10 dark:shadow-rose-950/20" role="alert">
                        <i class="fas fa-circle-exclamation text-rose-600 dark:text-rose-400 text-lg mt-0.5 shrink-0"></i>
                        <p class="text-xs sm:text-sm text-rose-800 dark:text-rose-200 font-medium leading-relaxed"><?= htmlspecialchars($error) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mb-6 rounded-2xl bg-emerald-100 dark:bg-emerald-950/50 border border-emerald-300 dark:border-emerald-500/30 p-4 flex items-start gap-3 fade-in shadow-lg shadow-emerald-950/10 dark:shadow-emerald-950/20" role="alert">
                        <i class="fas fa-circle-check text-emerald-600 dark:text-emerald-400 text-lg mt-0.5 shrink-0"></i>
                        <p class="text-xs sm:text-sm text-emerald-800 dark:text-emerald-200 font-medium leading-relaxed"><?= htmlspecialchars($success) ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($mode === 'login'): ?>
                    <!-- LOGIN FORM -->
                    <form method="POST" action="login.php?mode=login<?= $embedParam ?>" class="space-y-5 fade-in">
                        <input type="hidden" name="action" value="login">
                        <div>
                            <label for="email" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Email Address</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                    <i class="fas fa-envelope text-sm"></i>
                                </span>
                                <input id="email" type="email" name="email" required autocomplete="email" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="you@example.com">
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label for="loginPassword" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300">Password</label>
                                <a href="login.php?mode=forgot<?= $embedParam ?>" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:text-brand-700 dark:hover:text-brand-300 transition">Forgot password?</a>
                            </div>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                    <i class="fas fa-lock text-sm"></i>
                                </span>
                                <input id="loginPassword" type="password" name="password" required autocomplete="current-password" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-11 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="••••••••">
                                <button type="button" onclick="togglePasswordVisibility('loginPassword', 'loginEyeIcon')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 focus:outline-none transition" aria-label="Toggle password visibility">
                                    <i id="loginEyeIcon" class="fa-solid fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="w-full py-3.5 px-6 rounded-xl font-bold text-sm text-white bg-gradient-to-r from-brand-600 to-brand-700 hover:from-brand-500 hover:to-brand-600 shadow-xl shadow-brand-600/30 transition-all duration-300 hover:scale-[1.01] active:scale-[0.99] flex items-center justify-center gap-2">
                            <i class="fas fa-sign-in-alt"></i> Sign In
                        </button>
                    </form>

                <?php elseif ($mode === 'register'): ?>
                    <!-- REGISTER FORM -->
                    <form method="POST" action="login.php?mode=register<?= $embedParam ?>" class="space-y-4 fade-in">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="role" value="user">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="first_name" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">First Name</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                        <i class="fas fa-user text-sm"></i>
                                    </span>
                                    <input id="first_name" type="text" name="first_name" required autocomplete="given-name" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="Juan">
                                </div>
                            </div>
                            <div>
                                <label for="last_name" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Last Name</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                        <i class="fas fa-user text-sm"></i>
                                    </span>
                                    <input id="last_name" type="text" name="last_name" required autocomplete="family-name" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="Dela Cruz">
                                </div>
                            </div>
                        </div>
                        <div>
                            <label for="extension" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Extension <span class="lowercase font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                    <i class="fas fa-user-tag text-sm"></i>
                                </span>
                                <input id="extension" type="text" name="extension" autocomplete="off" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="Jr., III, Sr.">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="age" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Age</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                        <i class="fas fa-hashtag text-sm"></i>
                                    </span>
                                    <input id="age" type="number" name="age" min="18" required class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="18+">
                                </div>
                            </div>
                            <div>
                                <label for="birthdate" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Birthdate</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                        <i class="fas fa-calendar-days text-sm"></i>
                                    </span>
                                    <input id="birthdate" type="date" name="birthdate" required class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium">
                                </div>
                            </div>
                        </div>
                        <div>
                            <label for="phone_number" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Phone Number</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                    <i class="fas fa-phone text-sm"></i>
                                </span>
                                <input id="phone_number" type="tel" name="phone_number" required autocomplete="tel" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="09XX XXX XXXX">
                            </div>
                        </div>
                        <div>
                            <label for="regEmail" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Email Address</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                    <i class="fas fa-envelope text-sm"></i>
                                </span>
                                <input id="regEmail" type="email" name="email" required autocomplete="email" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="you@example.com">
                            </div>
                        </div>
                        <div>
                            <label for="regPassword" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Password</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                    <i class="fas fa-lock text-sm"></i>
                                </span>
                                <input id="regPassword" type="password" name="password" minlength="6" required autocomplete="new-password" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-11 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="••••••••">
                                <button type="button" onclick="togglePasswordVisibility('regPassword', 'regEyeIcon')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 focus:outline-none transition" aria-label="Toggle password visibility">
                                    <i id="regEyeIcon" class="fa-solid fa-eye text-sm"></i>
                                </button>
                            </div>
                            <p class="text-[11px] font-medium text-slate-500 dark:text-slate-500 mt-1">Minimum 6 characters required</p>
                        </div>
                        <div>
                            <label for="confirm_password" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Confirm Password</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                    <i class="fas fa-lock text-sm"></i>
                                </span>
                                <input id="confirm_password" type="password" name="confirm_password" minlength="6" required autocomplete="new-password" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-11 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="••••••••">
                                <button type="button" onclick="togglePasswordVisibility('confirm_password', 'confirmEyeIcon')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 focus:outline-none transition" aria-label="Toggle confirm password visibility">
                                    <i id="confirmEyeIcon" class="fa-solid fa-eye text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="w-full py-3.5 px-6 rounded-xl font-bold text-sm text-white bg-gradient-to-r from-brand-600 to-brand-700 hover:from-brand-500 hover:to-brand-600 shadow-xl shadow-brand-600/30 transition-all duration-300 hover:scale-[1.01] active:scale-[0.99] flex items-center justify-center gap-2 mt-2">
                            <i class="fas fa-user-plus"></i> Create Account
                        </button>
                    </form>

                <?php elseif ($mode === 'forgot'): ?>
                    <!-- FORGOT PASSWORD FORM -->
                    <form method="POST" action="login.php?mode=forgot<?= $embedParam ?>" class="space-y-5 fade-in">
                        <input type="hidden" name="action" value="forgot_password">
                        <div class="text-center py-2">
                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-brand-100 dark:bg-brand-950/80 border border-brand-200 dark:border-brand-800/50 text-brand-600 dark:text-brand-400 mb-3">
                                <i class="fas fa-key text-lg"></i>
                            </div>
                            <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 font-medium leading-relaxed">Enter your email address and we'll send you a link to reset your password.</p>
                        </div>
                        <div>
                            <label for="forgotEmail" class="block text-xs uppercase tracking-wider font-bold text-slate-700 dark:text-slate-300 mb-2">Email Address</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none">
                                    <i class="fas fa-envelope text-sm"></i>
                                </span>
                                <input id="forgotEmail" type="email" name="email" required autocomplete="email" class="input-accent w-full rounded-xl border border-slate-300 dark:border-slate-800 bg-white/70 dark:bg-slate-950/50 pl-11 pr-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none placeholder-slate-400 dark:placeholder-slate-500 font-medium" placeholder="you@example.com">
                            </div>
                        </div>
                        <button type="submit" class="w-full py-3.5 px-6 rounded-xl font-bold text-sm text-white bg-gradient-to-r from-brand-600 to-brand-700 hover:from-brand-500 hover:to-brand-600 shadow-xl shadow-brand-600/30 transition-all duration-300 hover:scale-[1.01] active:scale-[0.99] flex items-center justify-center gap-2">
                            <i class="fas fa-paper-plane"></i> Send Reset Link
                        </button>
                        <div class="text-center pt-2">
                            <a href="login.php?mode=login<?= $embedParam ?>" class="text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-brand-600 dark:hover:text-brand-300 transition-colors inline-flex items-center gap-2">
                                <i class="fas fa-arrow-left"></i> Back to Sign In
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="mt-8 pt-4 border-t border-slate-200 dark:border-slate-800/40 text-center lg:text-left flex items-center justify-between text-[11px] font-medium text-slate-500 dark:text-slate-500">
                <span>&copy; <?= date('Y') ?> CemeteryNav.</span>
                <a href="landing.php" class="hover:text-slate-800 dark:hover:text-slate-300 transition">Return to Home</a>
            </div>
        </section>

        <!-- VISUAL / CAROUSEL CONTAINER (ORDER SECOND ON DESKTOP) -->
        <section id="login-carousel" class="lg:col-span-6 relative h-64 lg:h-auto overflow-hidden group order-1 lg:order-2 bg-slate-950">
            <div id="carousel-track" class="flex h-full transition-transform duration-700 ease-in-out">
                <div class="min-w-full h-full relative">
                    <img src="assets/carousel/cemetery%201.jpg" alt="Cemetery photo 1" class="w-full h-full object-cover">
                </div>
                <div class="min-w-full h-full relative">
                    <img src="assets/carousel/cemetery%2011.jpg" alt="Cemetery photo 11" class="w-full h-full object-cover">
                </div>
            </div>

            <!-- Gradient Overlay -->
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/30 to-transparent"></div>

            <!-- Text Content over Overlay -->
            <div class="absolute bottom-0 left-0 w-full p-6 sm:p-8 lg:p-10 z-10">
                <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[11px] font-bold bg-brand-950/80 text-brand-300 border border-brand-800/50 mb-3 backdrop-blur-md">
                    <i class="fa-solid fa-location-arrow text-[10px]"></i> Smart Navigation
                </span>
                <h2 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight mb-2">Find eternal peace.</h2>
                <p class="text-slate-300 text-xs sm:text-sm max-w-md leading-relaxed font-medium">Interactive cemetery mapping and voice navigation for a seamless, dignified experience.</p>
            </div>

            <!-- Controls -->
            <button id="prev-slide" aria-label="Previous slide" class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-xl bg-slate-950/60 text-white hover:bg-brand-600 flex items-center justify-center transition border border-white/10 backdrop-blur-md z-20 hover:scale-110 active:scale-95">
                <i class="fa-solid fa-chevron-left text-xs"></i>
            </button>
            <button id="next-slide" aria-label="Next slide" class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-xl bg-slate-950/60 text-white hover:bg-brand-600 flex items-center justify-center transition border border-white/10 backdrop-blur-md z-20 hover:scale-110 active:scale-95">
                <i class="fa-solid fa-chevron-right text-xs"></i>
            </button>

            <!-- Slide Indicators -->
            <div id="carousel-dots" class="absolute top-6 right-6 flex items-center gap-2 z-20">
                <button data-index="0" class="dot w-2 h-2 rounded-full bg-white/40 hover:bg-white transition-all duration-300" aria-label="Go to slide 1"></button>
                <button data-index="1" class="dot w-2 h-2 rounded-full bg-white/40 hover:bg-white transition-all duration-300" aria-label="Go to slide 2"></button>
            </div>
        </section>

    </main>

    <!-- Scripts -->
    <script>
        // Theme Toggle Logic
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeIcon = document.getElementById('theme-icon');

        function updateThemeIcon() {
            if (!themeIcon) return;
            if (document.documentElement.classList.contains('dark')) {
                themeIcon.className = 'fas fa-sun text-amber-400 text-base';
            } else {
                themeIcon.className = 'fas fa-moon text-slate-700 text-base';
            }
        }

        updateThemeIcon();

        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', () => {
                if (document.documentElement.classList.contains('dark')) {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('theme', 'light');
                } else {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('theme', 'dark');
                }
                updateThemeIcon();
            });
        }

        window.addEventListener('storage', (e) => {
            if (e.key === 'theme' && e.newValue) {
                if (e.newValue === 'dark') {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
                updateThemeIcon();
            }
        });

        <?php if ($embed): ?>
        // Embedded dialog mode: close the parent modal with the Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && window.parent && typeof window.parent.closeAuthModal === 'function') {
                window.parent.closeAuthModal();
            }
        });
        <?php endif; ?>

        function togglePasswordVisibility(inputId, iconId) {
            const passwordInput = document.getElementById(inputId);
            const eyeIcon = document.getElementById(iconId);

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }

        const track = document.getElementById('carousel-track');
        const dots = document.querySelectorAll('#carousel-dots .dot');
        const prevBtn = document.getElementById('prev-slide');
        const nextBtn = document.getElementById('next-slide');
        const total = track.children.length;
        let current = 0;
        let autoPlay;

        function update(index) {
            current = index;
            track.style.transform = `translateX(-${current * 100}%)`;
            dots.forEach((dot, i) => {
                if (i === current) {
                    dot.classList.add('bg-brand-400', 'w-6');
                    dot.classList.remove('bg-white/40');
                } else {
                    dot.classList.remove('bg-brand-400', 'w-6');
                    dot.classList.add('bg-white/40');
                }
            });
        }

        function next() {
            update((current + 1) % total);
        }

        function prev() {
            update((current - 1 + total) % total);
        }

        function startAutoPlay() {
            autoPlay = setInterval(next, 5000);
        }

        function resetAutoPlay() {
            clearInterval(autoPlay);
            startAutoPlay();
        }

        nextBtn.addEventListener('click', () => { next(); resetAutoPlay(); });
        prevBtn.addEventListener('click', () => { prev(); resetAutoPlay(); });
        dots.forEach(dot => {
            dot.addEventListener('click', () => {
                update(parseInt(dot.dataset.index));
                resetAutoPlay();
            });
        });
        track.addEventListener('mouseenter', () => clearInterval(autoPlay));
        track.addEventListener('mouseleave', startAutoPlay);

        update(0);
        startAutoPlay();
    </script>
</body>
</html>