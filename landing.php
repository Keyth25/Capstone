<?php
header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$isLoggedIn = !empty($_SESSION['user_id']) && !empty($_SESSION['role']);

// Mobile app download details (shown in the Download section below).
$apkPath = __DIR__ . '/apk/MatutumPlotNav.apk';
$apkAvailable = is_file($apkPath);
$apkSizeMb = $apkAvailable ? round(filesize($apkPath) / 1048576, 1) : null;

// Stylized cemetery map used inside the product mockups (purely decorative).
$plotRects = '';
foreach ([[24, 24, 5], [236, 24, 5], [24, 178, 4], [236, 178, 4]] as [$ox, $oy, $rows]) {
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < 4; $c++) {
            $x = $ox + $c * 38;
            $y = $oy + $r * 24;
            $plotRects .= "<rect x='$x' y='$y' width='30' height='16' rx='3'/>";
        }
    }
}
$mapSvg = '<svg viewBox="0 0 420 300" class="h-full w-full" preserveAspectRatio="xMidYMid slice" aria-hidden="true">'
    . '<rect width="420" height="300" fill="#e9f1e2"/>'
    . '<path d="M0 155 C 110 138 300 175 420 148" stroke="#f7f3e6" stroke-width="20" fill="none"/>'
    . '<path d="M212 0 C 202 95 224 205 214 300" stroke="#f7f3e6" stroke-width="18" fill="none"/>'
    . '<path d="M0 155 C 110 138 300 175 420 148" stroke="#e3dbc4" stroke-width="1.5" fill="none" stroke-dasharray="8 7"/>'
    . '<path d="M212 0 C 202 95 224 205 214 300" stroke="#e3dbc4" stroke-width="1.5" fill="none" stroke-dasharray="8 7"/>'
    . '<g fill="#d6e0c7" stroke="#bccca9" stroke-width="1">' . $plotRects . '</g>'
    . '<g fill="#b3c79e">'
    . '<circle cx="196" cy="52" r="7"/><circle cx="232" cy="112" r="6"/><circle cx="196" cy="236" r="7"/>'
    . '<circle cx="14" cy="170" r="6"/><circle cx="404" cy="168" r="6"/><circle cx="226" cy="288" r="6"/>'
    . '<circle cx="110" cy="150" r="5"/><circle cx="320" cy="166" r="5"/></g>'
    . '</svg>';

$plotCard = '<div class="absolute bottom-4 right-4 z-10 flex items-center gap-3 rounded-2xl bg-white/95 dark:bg-slate-900/95 backdrop-blur px-3.5 py-3 shadow-xl border border-slate-200/70 dark:border-slate-700/70">'
    . '<div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-200 to-emerald-400 flex items-center justify-center text-emerald-800 text-lg shrink-0"><i class="fa-solid fa-tree"></i></div>'
    . '<div class="min-w-0">'
    . '<p class="text-[13px] font-extrabold text-slate-900 dark:text-white leading-tight">Plot A-045</p>'
    . '<p class="text-[10px] font-medium text-slate-500 dark:text-slate-400">Section A · Row 04 · Lot 05</p>'
    . '<p class="text-[10px] font-bold text-emerald-600 flex items-center gap-1 mt-0.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Available</p>'
    . '</div>'
    . '<button onclick="openAuthModal(\'login\')" class="ml-1 shrink-0 rounded-lg bg-brand-600 hover:bg-brand-500 text-white text-[10px] font-bold px-3 py-2 transition flex items-center gap-1.5">View Details <i class="fa-solid fa-arrow-right text-[8px]"></i></button>'
    . '</div>';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Farewells and Sympathy - Cemetery Navigator</title>
    <?php require __DIR__ . '/includes/tab_guard.php'; ?>

    <!-- Google Fonts & Font Awesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Anti-flicker script to instantly apply theme state -->
    <script>
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <!-- Tailwind CSS with Custom Purple Palette Configuration -->
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
                    },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'float-slow': 'float 9s ease-in-out infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-14px)' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        :root { color-scheme: light; }
        html.dark { color-scheme: dark; }

        .glass-header {
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        html.dark .glass-header {
            background: rgba(9, 13, 22, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .purple-glow {
            box-shadow: 0 0 60px -12px rgba(124, 58, 237, 0.4);
        }

        html.dark body {
            background-color: #090d16;
            color: #f3f4f6;
        }

        /* Mesh gradient background for hero */
        .hero-mesh {
            background-image:
                radial-gradient(at 75% 20%, rgba(124, 58, 237, 0.10) 0px, transparent 45%),
                radial-gradient(at 15% 80%, rgba(139, 92, 246, 0.08) 0px, transparent 45%);
        }
        html.dark .hero-mesh {
            background-image:
                radial-gradient(at 75% 20%, rgba(124, 58, 237, 0.20) 0px, transparent 45%),
                radial-gradient(at 15% 80%, rgba(139, 92, 246, 0.14) 0px, transparent 45%);
        }

        /* Animated gradient text */
        .gradient-text {
            background: linear-gradient(120deg, #7c3aed, #8b5cf6, #6366f1, #7c3aed);
            background-size: 300% 300%;
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            animation: gradientShift 8s ease infinite;
        }
        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        /* Scroll reveal animations */
        .reveal {
            opacity: 0;
            transform: translateY(28px);
            transition: opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1), transform 0.7s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .reveal-delay-1 { transition-delay: 0.1s; }
        .reveal-delay-2 { transition-delay: 0.2s; }
        .reveal-delay-3 { transition-delay: 0.3s; }

        /* Card hover lift */
        .card-lift {
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.35s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.35s;
        }
        .card-lift:hover {
            transform: translateY(-6px);
        }

        /* Handwritten accent font */
        .handwritten {
            font-family: 'Caveat', cursive;
        }

        /* Voice waveform bars on the phone mockup */
        @keyframes eq {
            0%, 100% { transform: scaleY(0.35); }
            50% { transform: scaleY(1); }
        }
        .eq-bar {
            animation: eq 1.15s ease-in-out infinite;
            transform-origin: center;
        }

        /* Vertical connector line behind "How it Works" steps on mobile */
        .step-line::before {
            content: '';
            position: absolute;
            left: 50%;
            top: 3.5rem;
            bottom: -2rem;
            width: 2px;
            transform: translateX(-50%);
            background: linear-gradient(to bottom, rgba(124, 58, 237, 0.5), transparent);
        }
        @media (min-width: 768px) {
            .step-line::before { display: none; }
        }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#7c3aed">
    <script src="pwa.js" defer></script>
    <script src="mobile.js" defer></script>
</head>
<body class="bg-white text-slate-800 font-sans antialiased selection:bg-brand-600 selection:text-white transition-colors duration-200 overflow-x-hidden">

    <!-- NAVIGATION BAR -->
    <nav class="glass-header border-b border-slate-200/70 dark:border-slate-800/70 sticky top-0 z-50 transition-colors">
        <div class="w-full px-6 h-[76px] flex items-center gap-6">
            <a href="#" class="flex items-center gap-3 group shrink-0">
                <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-brand-700 to-brand-500 flex items-center justify-center text-white text-lg shadow-md shadow-brand-500/25 group-hover:scale-105 group-hover:rotate-3 transition-transform">
                    <i class="fa-solid fa-location-dot"></i>
                </div>
                <span class="leading-tight">
                    <span class="block text-lg font-extrabold tracking-tight text-slate-900 dark:text-white">Cemetery<span class="text-brand-600 dark:text-brand-400">Nav</span></span>
                    <span class="block text-[10px] font-semibold tracking-wide text-slate-400 dark:text-slate-500">Find &middot; Navigate &middot; Remember</span>
                </span>
            </a>

            <div class="hidden lg:flex flex-1 items-center justify-evenly gap-8 text-sm font-semibold text-slate-600 dark:text-slate-300">
                <a href="#" class="relative text-brand-600 dark:text-brand-400 py-2">Home<span class="absolute left-1/2 -translate-x-1/2 -bottom-0.5 w-6 h-0.5 rounded-full bg-brand-600 dark:bg-brand-400"></span></a>
                <a href="#features" class="py-2 hover:text-brand-600 dark:hover:text-brand-400 transition">Features</a>
                <a href="#how-it-works" class="py-2 hover:text-brand-600 dark:hover:text-brand-400 transition">How It Works</a>
                <a href="#benefits" class="py-2 hover:text-brand-600 dark:hover:text-brand-400 transition">Benefits</a>
                <a href="#about" class="py-2 hover:text-brand-600 dark:hover:text-brand-400 transition">About</a>
                <a href="#download" class="py-2 hover:text-brand-600 dark:hover:text-brand-400 transition">Get the App</a>
            </div>

            <div class="flex items-center gap-3 sm:gap-4 ml-auto lg:ml-0 shrink-0">
                <button id="theme-toggle" aria-label="Toggle dark mode" class="w-10 h-10 rounded-full text-slate-500 dark:text-amber-400 flex items-center justify-center hover:bg-slate-100 dark:hover:bg-slate-800 transition" title="Toggle theme">
                    <i class="fa-solid fa-sun text-base" id="theme-icon"></i>
                </button>

                <?php if ($isLoggedIn): ?>
                    <?php
                    $dashboard_url = match ($_SESSION['role'] ?? '') {
                        'admin' => 'admin_dashboard.php',
                        'staff' => 'staff.php',
                        default => 'user_dashboard.php',
                    };
                    ?>
                    <div id="nav-user" class="flex items-center gap-3">
                        <a href="<?= htmlspecialchars($dashboard_url) ?>" class="rounded-full bg-gradient-to-r from-brand-600 to-brand-700 px-6 py-2.5 text-sm font-bold text-white hover:from-brand-500 hover:to-brand-600 transition shadow-lg shadow-brand-600/25 flex items-center gap-2">
                            Dashboard <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <div id="nav-guest" class="flex items-center gap-4">
                        <a href="login.php?mode=login" onclick="openAuthModal('login'); return false;" class="hidden sm:flex items-center gap-2 text-sm font-bold text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400 transition">
                            <i class="fa-solid fa-user text-brand-600 dark:text-brand-400"></i> Sign In
                        </a>
                        <a href="login.php?mode=login" onclick="openAuthModal('login'); return false;" class="rounded-full bg-gradient-to-r from-brand-600 to-brand-700 px-6 py-2.5 text-sm font-bold text-white hover:from-brand-500 hover:to-brand-600 transition shadow-lg shadow-brand-600/25 flex items-center gap-2">
                            Get Started <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <header class="relative overflow-hidden hero-mesh pt-14 pb-20 lg:pt-20 lg:pb-28">
        <div class="absolute top-10 right-0 w-[42rem] h-[42rem] bg-gradient-to-bl from-brand-100/80 via-brand-50/40 to-transparent dark:from-brand-900/30 dark:via-brand-950/20 rounded-full blur-3xl pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-6 relative z-10 grid grid-cols-1 lg:grid-cols-2 gap-16 lg:gap-10 items-center">

            <!-- Left: copy -->
            <div class="max-w-xl">
                <span class="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full text-xs font-bold bg-brand-50 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800/50 shadow-sm">
                    <span class="w-5 h-5 rounded-full bg-brand-600 flex items-center justify-center text-white text-[9px]"><i class="fa-solid fa-location-dot"></i></span>
                    Cemetery Management System
                </span>

                <h1 class="mt-6 text-4xl sm:text-5xl lg:text-[3.4rem] font-extrabold text-slate-900 dark:text-white tracking-tight leading-[1.12]">
                    Cemetery Management with <span class="gradient-text">Voice Navigation</span> and Interactive Mapping
                </h1>

                <p class="mt-5 text-base text-slate-600 dark:text-slate-400 leading-relaxed font-medium">
                    Easily find, locate, and manage cemetery plots with an interactive map and voice-guided navigation. A simpler, smarter way to honor and remember.
                </p>

                <!-- Feature chips -->
                <div class="mt-8 grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-brand-50 dark:bg-brand-950/70 border border-brand-100 dark:border-brand-800/50 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm shrink-0"><i class="fa-solid fa-location-dot"></i></span>
                        <span class="text-[11px] font-bold text-slate-700 dark:text-slate-300 leading-snug">Interactive<br>GIS Mapping</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-brand-50 dark:bg-brand-950/70 border border-brand-100 dark:border-brand-800/50 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm shrink-0"><i class="fa-solid fa-microphone"></i></span>
                        <span class="text-[11px] font-bold text-slate-700 dark:text-slate-300 leading-snug">Voice<br>Navigation</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-brand-50 dark:bg-brand-950/70 border border-brand-100 dark:border-brand-800/50 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm shrink-0"><i class="fa-solid fa-calendar-check"></i></span>
                        <span class="text-[11px] font-bold text-slate-700 dark:text-slate-300 leading-snug">Plot Reservation<br>&amp; Management</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-brand-50 dark:bg-brand-950/70 border border-brand-100 dark:border-brand-800/50 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm shrink-0"><i class="fa-solid fa-shield-halved"></i></span>
                        <span class="text-[11px] font-bold text-slate-700 dark:text-slate-300 leading-snug">Secure<br>&amp; Reliable</span>
                    </div>
                </div>

                <div class="mt-9 flex flex-col sm:flex-row items-center gap-4">
                    <a href="login.php?mode=login" onclick="openAuthModal('login'); return false;" class="w-full sm:w-auto px-7 py-3.5 rounded-full bg-gradient-to-r from-brand-600 to-brand-700 hover:from-brand-500 hover:to-brand-600 font-bold text-sm text-white transition-all duration-300 shadow-xl shadow-brand-600/30 hover:shadow-2xl hover:shadow-brand-600/40 hover:-translate-y-0.5 flex items-center justify-center gap-2.5">
                        Get Started Free <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                    <a href="#features" class="w-full sm:w-auto px-7 py-3.5 rounded-full bg-white dark:bg-slate-900 font-bold text-sm text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 border border-slate-200 dark:border-slate-700 transition-all duration-300 shadow-sm hover:border-brand-300 dark:hover:border-brand-600 hover:-translate-y-0.5 flex items-center justify-center gap-2.5">
                        <i class="fa-solid fa-circle-play text-brand-500"></i> Watch Demo
                    </a>
                </div>
            </div>

            <!-- Right: product mockup -->
            <div class="relative mx-auto w-full max-w-lg lg:max-w-none px-2 sm:px-8 lg:px-0 pb-10">
                <!-- Soft blob backdrop -->
                <div class="absolute -inset-6 bg-gradient-to-tr from-brand-100/70 via-indigo-50/50 to-brand-200/40 dark:from-brand-900/30 dark:via-indigo-950/20 dark:to-brand-800/20 rounded-[3rem] blur-2xl pointer-events-none"></div>

                <!-- Handwritten annotation -->
                <div class="hidden md:block absolute -top-14 right-40 lg:right-48 z-20 -rotate-6 pointer-events-none">
                    <p class="handwritten text-3xl text-brand-600 dark:text-brand-400 leading-none">Find peace<br>with ease.</p>
                    <svg class="absolute -right-9 top-8 w-9 h-12 text-brand-500 -scale-x-100" viewBox="0 0 40 52" fill="none" aria-hidden="true">
                        <path d="M36 4 C 22 12, 10 24, 8 44" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                        <path d="M3 38 L 8 46 L 16 42" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>

                <!-- Browser window mockup -->
                <div class="relative rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-700/60 shadow-2xl shadow-brand-900/10 overflow-hidden">
                    <div class="flex h-[21rem] sm:h-[23rem]">
                        <!-- App sidebar -->
                        <div class="hidden sm:flex w-40 shrink-0 flex-col bg-slate-50/90 dark:bg-slate-950/60 border-r border-slate-200/70 dark:border-slate-800/70 p-3.5">
                            <div class="flex items-center gap-2 px-1 pb-4">
                                <span class="w-6 h-6 rounded-lg bg-gradient-to-tr from-brand-700 to-brand-500 flex items-center justify-center text-white text-[10px]"><i class="fa-solid fa-location-dot"></i></span>
                                <span class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200">Cemetery<span class="text-brand-600">Nav</span></span>
                            </div>
                            <div class="space-y-1 text-[11px] font-semibold text-slate-500 dark:text-slate-400">
                                <span class="flex items-center gap-2.5 px-3 py-2 rounded-lg bg-brand-600 text-white shadow-sm"><i class="fa-solid fa-map w-3.5 text-center"></i>Map</span>
                                <span class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800/70"><i class="fa-solid fa-border-all w-3.5 text-center"></i>Plots</span>
                                <span class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800/70"><i class="fa-solid fa-calendar w-3.5 text-center"></i>Reservations</span>
                                <span class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800/70"><i class="fa-solid fa-bullhorn w-3.5 text-center"></i>Announcements</span>
                                <span class="flex items-center gap-2.5 px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800/70"><i class="fa-solid fa-gear w-3.5 text-center"></i>Settings</span>
                            </div>
                        </div>
                        <!-- Map area -->
                        <div class="relative flex-1">
                            <?= $mapSvg ?>
                            <!-- Pulsing pin -->
                            <div class="absolute left-[58%] top-[22%] -translate-x-1/2 -translate-y-full">
                                <span class="absolute left-1/2 -translate-x-1/2 bottom-0 w-6 h-6 rounded-full bg-brand-500/40 animate-ping"></span>
                                <i class="fa-solid fa-location-dot relative text-3xl text-brand-600 drop-shadow-md"></i>
                            </div>
                            <!-- Zoom controls -->
                            <div class="absolute bottom-4 left-4 z-10 flex flex-col rounded-xl bg-white dark:bg-slate-800 shadow-lg border border-slate-200/70 dark:border-slate-700/70 overflow-hidden text-slate-600 dark:text-slate-300 text-xs font-bold">
                                <span class="px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-700 border-b border-slate-200/70 dark:border-slate-700/70"><i class="fa-solid fa-plus"></i></span>
                                <span class="px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-700"><i class="fa-solid fa-minus"></i></span>
                            </div>
                            <?= $plotCard ?>
                        </div>
                    </div>
                </div>

                <!-- Phone mockup -->
                <div class="hidden sm:block absolute -right-2 lg:-right-6 -top-8 w-36 lg:w-44 rotate-2 z-20 animate-float-slow">
                    <div class="rounded-[2.1rem] bg-slate-900 p-1.5 shadow-2xl shadow-brand-900/40 border border-slate-700/50">
                        <div class="rounded-[1.7rem] overflow-hidden bg-gradient-to-b from-[#3b1470] via-[#2a1152] to-[#150b30] aspect-[9/18.5] flex flex-col items-center px-3 py-4">
                            <div class="w-10 h-1.5 rounded-full bg-white/20 mb-4"></div>
                            <p class="text-[9px] font-bold text-brand-200 tracking-wide flex items-center gap-1.5"><i class="fa-solid fa-chevron-left text-[7px]"></i> Voice Navigation</p>
                            <div class="mt-5 w-14 h-14 rounded-full bg-gradient-to-tr from-brand-500 to-brand-400 flex items-center justify-center text-white text-xl shadow-[0_0_36px_-4px_rgba(168,85,247,0.9)] ring-4 ring-brand-400/25">
                                <i class="fa-solid fa-microphone"></i>
                            </div>
                            <p class="mt-4 text-[9px] font-semibold text-brand-100 text-center leading-relaxed">Turn right in 50 meters</p>
                            <div class="mt-3 flex items-center gap-[3px] h-7">
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:8px"></span>
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:16px; animation-delay:.12s"></span>
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:22px; animation-delay:.24s"></span>
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:12px; animation-delay:.36s"></span>
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:24px; animation-delay:.48s"></span>
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:14px; animation-delay:.6s"></span>
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:20px; animation-delay:.72s"></span>
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:10px; animation-delay:.84s"></span>
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:18px; animation-delay:.96s"></span>
                                <span class="eq-bar w-[3px] rounded-full bg-brand-300/90" style="height:8px; animation-delay:1.08s"></span>
                            </div>
                            <div class="mt-auto w-9 h-9 rounded-full bg-white/10 border border-white/15 flex items-center justify-center text-white text-xs">
                                <i class="fa-solid fa-chevron-up"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Decorative leaf sprig -->
                <svg class="hidden md:block absolute -right-8 lg:-right-14 -bottom-8 w-20 lg:w-24 text-brand-500/80 dark:text-brand-600/60 pointer-events-none" viewBox="0 0 140 220" fill="none" aria-hidden="true">
                    <path d="M70 215 C 66 150 74 80 70 15" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
                    <g fill="currentColor" opacity="0.85">
                        <ellipse cx="44" cy="62" rx="26" ry="11" transform="rotate(-32 44 62)"/>
                        <ellipse cx="96" cy="62" rx="26" ry="11" transform="rotate(32 96 62)"/>
                        <ellipse cx="42" cy="108" rx="26" ry="11" transform="rotate(-32 42 108)"/>
                        <ellipse cx="98" cy="108" rx="26" ry="11" transform="rotate(32 98 108)"/>
                        <ellipse cx="46" cy="154" rx="24" ry="10" transform="rotate(-30 46 154)"/>
                        <ellipse cx="94" cy="154" rx="24" ry="10" transform="rotate(30 94 154)"/>
                        <ellipse cx="70" cy="28" rx="10" ry="20"/>
                    </g>
                </svg>
            </div>
        </div>
    </header>

    <!-- BENEFITS STRIP -->
    <section id="benefits" class="py-14 scroll-mt-24">
        <div class="max-w-7xl mx-auto px-6">
            <div class="reveal rounded-3xl bg-brand-50/60 dark:bg-slate-900/60 border border-brand-100/80 dark:border-slate-800 shadow-sm flex flex-col md:flex-row md:flex-wrap lg:flex-nowrap divide-y md:divide-y-0 lg:divide-x divide-brand-100 dark:divide-slate-800">
                <div class="flex-1 p-8 flex flex-col items-start gap-4 min-w-[15rem]">
                    <span class="w-12 h-12 rounded-2xl bg-white dark:bg-slate-800 border border-brand-100 dark:border-slate-700 text-brand-600 dark:text-brand-400 flex items-center justify-center text-xl shadow-sm"><i class="fa-solid fa-map"></i></span>
                    <div>
                        <h3 class="font-extrabold text-slate-900 dark:text-white">Interactive Map</h3>
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Explore sections, blocks and plots and get real-time availability.</p>
                    </div>
                </div>
                <div class="flex-1 p-8 flex flex-col items-start gap-4 min-w-[15rem]">
                    <span class="w-12 h-12 rounded-2xl bg-white dark:bg-slate-800 border border-brand-100 dark:border-slate-700 text-brand-600 dark:text-brand-400 flex items-center justify-center text-xl shadow-sm"><i class="fa-solid fa-microphone"></i></span>
                    <div>
                        <h3 class="font-extrabold text-slate-900 dark:text-white">Voice Navigation</h3>
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Get step-by-step directions to any plot.</p>
                    </div>
                </div>
                <div class="flex-1 p-8 flex flex-col items-start gap-4 min-w-[15rem]">
                    <span class="w-12 h-12 rounded-2xl bg-white dark:bg-slate-800 border border-brand-100 dark:border-slate-700 text-brand-600 dark:text-brand-400 flex items-center justify-center text-xl shadow-sm"><i class="fa-solid fa-calendar-check"></i></span>
                    <div>
                        <h3 class="font-extrabold text-slate-900 dark:text-white">Easy Reservation</h3>
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Reserve plots and schedule burials with ease.</p>
                    </div>
                </div>
                <div class="flex-1 p-8 flex flex-col items-start gap-4 min-w-[15rem]">
                    <span class="w-12 h-12 rounded-2xl bg-white dark:bg-slate-800 border border-brand-100 dark:border-slate-700 text-brand-600 dark:text-brand-400 flex items-center justify-center text-xl shadow-sm"><i class="fa-solid fa-shield-halved"></i></span>
                    <div>
                        <h3 class="font-extrabold text-slate-900 dark:text-white">Data Security</h3>
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Keep your records safe and accessible.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS SECTION -->
    <section id="how-it-works" class="py-24 relative overflow-hidden scroll-mt-20">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[36rem] h-[36rem] bg-brand-500/5 dark:bg-brand-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="max-w-7xl mx-auto px-6 relative z-10">
            <div class="text-center mb-16 reveal">
                <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-bold bg-brand-50 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800/50 mb-5">
                    <i class="fa-solid fa-diagram-project text-[10px]"></i> Simple Process
                </span>
                <h2 class="text-3xl font-extrabold text-slate-900 dark:text-white sm:text-4xl tracking-tight">How It Works</h2>
                <p class="mt-4 text-slate-600 dark:text-slate-400 max-w-xl mx-auto">Three simple steps from search to graveside — no maps to read, no guesswork.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-10 md:gap-8 relative">
                <!-- Connector line (desktop) -->
                <div class="hidden md:block absolute top-10 left-[16%] right-[16%] h-0.5 bg-gradient-to-r from-brand-300 via-brand-500 to-brand-300 dark:from-brand-800 dark:via-brand-500 dark:to-brand-800 opacity-50"></div>

                <!-- Step 1 -->
                <div class="reveal relative text-center step-line">
                    <div class="relative inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-white dark:bg-slate-900 border-2 border-brand-200 dark:border-brand-800 shadow-lg shadow-brand-500/10 text-brand-600 dark:text-brand-400 text-3xl mb-6 z-10">
                        <i class="fa-solid fa-keyboard"></i>
                        <span class="absolute -top-2.5 -right-2.5 w-8 h-8 rounded-xl bg-gradient-to-tr from-brand-600 to-brand-500 text-white text-sm font-extrabold flex items-center justify-center shadow-md">1</span>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-3">Search a Name</h3>
                    <p class="text-slate-600 dark:text-slate-400 text-sm leading-relaxed max-w-xs mx-auto">
                        Type the name of your loved one or a plot code. The system instantly finds the matching burial record.
                    </p>
                </div>

                <!-- Step 2 -->
                <div class="reveal reveal-delay-1 relative text-center step-line">
                    <div class="relative inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-white dark:bg-slate-900 border-2 border-brand-200 dark:border-brand-800 shadow-lg shadow-brand-500/10 text-brand-600 dark:text-brand-400 text-3xl mb-6 z-10">
                        <i class="fa-solid fa-map-location-dot"></i>
                        <span class="absolute -top-2.5 -right-2.5 w-8 h-8 rounded-xl bg-gradient-to-tr from-brand-600 to-brand-500 text-white text-sm font-extrabold flex items-center justify-center shadow-md">2</span>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-3">View the Route</h3>
                    <p class="text-slate-600 dark:text-slate-400 text-sm leading-relaxed max-w-xs mx-auto">
                        The interactive GIS map highlights the plot and draws the walking path from the cemetery entrance.
                    </p>
                </div>

                <!-- Step 3 -->
                <div class="reveal reveal-delay-2 relative text-center">
                    <div class="relative inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-white dark:bg-slate-900 border-2 border-brand-200 dark:border-brand-800 shadow-lg shadow-brand-500/10 text-brand-600 dark:text-brand-400 text-3xl mb-6 z-10">
                        <i class="fa-solid fa-ear-listen"></i>
                        <span class="absolute -top-2.5 -right-2.5 w-8 h-8 rounded-xl bg-gradient-to-tr from-brand-600 to-brand-500 text-white text-sm font-extrabold flex items-center justify-center shadow-md">3</span>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-3">Follow the Voice</h3>
                    <p class="text-slate-600 dark:text-slate-400 text-sm leading-relaxed max-w-xs mx-auto">
                        Turn-by-turn audio guidance speaks each direction aloud, leading you gently to the exact graveside.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- FEATURES SECTION -->
    <section id="features" class="py-24 bg-slate-50/70 dark:bg-slate-900/40 border-y border-slate-200/70 dark:border-slate-800/70 scroll-mt-20">
        <div class="max-w-7xl mx-auto px-6 grid grid-cols-1 lg:grid-cols-2 gap-14 lg:gap-16 items-center">

            <!-- Left: map mockup -->
            <div class="reveal relative">
                <div class="relative rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-700/60 shadow-2xl shadow-brand-900/10 overflow-hidden">
                    <div class="relative h-80 sm:h-96">
                        <?= $mapSvg ?>
                        <!-- Park label -->
                        <div class="absolute top-4 left-4 z-10 flex items-center gap-2.5 rounded-full bg-white/95 dark:bg-slate-900/95 backdrop-blur pl-2 pr-4 py-1.5 shadow-lg border border-slate-200/60 dark:border-slate-700/60">
                            <span class="w-6 h-6 rounded-full bg-brand-600 flex items-center justify-center text-white text-[10px]"><i class="fa-solid fa-location-dot"></i></span>
                            <span class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200">Holy Gardens Matutum<br class="sm:hidden"> Memorial Park</span>
                        </div>
                        <!-- Pin -->
                        <div class="absolute left-[38%] top-[38%] -translate-x-1/2 -translate-y-full">
                            <span class="absolute left-1/2 -translate-x-1/2 bottom-0 w-7 h-7 rounded-full bg-brand-500/40 animate-ping"></span>
                            <i class="fa-solid fa-location-dot relative text-4xl text-brand-600 drop-shadow-md"></i>
                        </div>
                        <?= $plotCard ?>
                        <!-- Search pill -->
                        <div class="absolute bottom-4 left-4 z-10 flex items-center gap-3 w-56 sm:w-64 rounded-full bg-white/95 dark:bg-slate-900/95 backdrop-blur px-4 py-2.5 shadow-lg border border-slate-200/60 dark:border-slate-700/60">
                            <i class="fa-solid fa-magnifying-glass text-brand-500 text-xs"></i>
                            <span class="text-[11px] font-medium text-slate-400 dark:text-slate-500 truncate">Search plot, section, or name...</span>
                            <i class="fa-solid fa-crosshairs ml-auto text-brand-500 text-xs"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: feature list -->
            <div class="reveal reveal-delay-1">
                <p class="text-xs font-extrabold uppercase tracking-[0.2em] text-brand-600 dark:text-brand-400">Our Features</p>
                <h2 class="mt-3 text-3xl sm:text-4xl font-extrabold text-slate-900 dark:text-white tracking-tight leading-tight">Everything You Need<br>in One System</h2>
                <p class="mt-4 text-sm sm:text-base text-slate-600 dark:text-slate-400 leading-relaxed max-w-lg">
                    Our Cemetery Management System combines powerful mapping, voice navigation, and easy management tools to provide a seamless experience for visitors, families, and staff.
                </p>

                <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-6">
                    <div class="flex items-start gap-4">
                        <span class="w-11 h-11 rounded-xl bg-brand-100/80 dark:bg-brand-950/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-base shrink-0"><i class="fa-solid fa-map"></i></span>
                        <div>
                            <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">Interactive GIS Map</h3>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">View cemetery sections, plots, and landmarks.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="w-11 h-11 rounded-xl bg-brand-100/80 dark:bg-brand-950/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-base shrink-0"><i class="fa-solid fa-calendar-check"></i></span>
                        <div>
                            <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">Reservation &amp; Scheduling</h3>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Reserve plots and manage burial schedules.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="w-11 h-11 rounded-xl bg-brand-100/80 dark:bg-brand-950/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-base shrink-0"><i class="fa-solid fa-microphone"></i></span>
                        <div>
                            <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">Voice Navigation</h3>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Get turn-by-turn directions to your destination.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="w-11 h-11 rounded-xl bg-brand-100/80 dark:bg-brand-950/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-base shrink-0"><i class="fa-solid fa-screwdriver-wrench"></i></span>
                        <div>
                            <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">Maintenance Requests</h3>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Request plot cleaning and maintenance.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="w-11 h-11 rounded-xl bg-brand-100/80 dark:bg-brand-950/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-base shrink-0"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <div>
                            <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">Plot Search &amp; Details</h3>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Find plot information instantly with real-time status.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="w-11 h-11 rounded-xl bg-brand-100/80 dark:bg-brand-950/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-base shrink-0"><i class="fa-solid fa-bell"></i></span>
                        <div>
                            <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">Announcements</h3>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Stay updated with the latest notices and events.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- DOWNLOAD APP SECTION -->
    <section id="download" class="py-24 scroll-mt-20">
        <div class="max-w-7xl mx-auto px-6">
            <div class="reveal relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-800 via-brand-700 to-indigo-800 dark:from-brand-950 dark:via-brand-900 dark:to-indigo-950 px-8 py-12 sm:px-14 sm:py-16 shadow-2xl shadow-brand-900/30">
                <!-- Decorative glows -->
                <div class="absolute -top-24 -right-24 w-80 h-80 bg-brand-400/30 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-28 -left-20 w-80 h-80 bg-indigo-400/20 rounded-full blur-3xl pointer-events-none"></div>

                <div class="relative z-10 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                    <!-- Left: copy + button -->
                    <div>
                        <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-bold bg-white/10 text-brand-100 border border-white/20">
                            <i class="fa-brands fa-android text-[11px]"></i> Android Mobile App
                        </span>
                        <h2 class="mt-5 text-3xl sm:text-4xl font-extrabold text-white tracking-tight leading-tight">Take CemeteryNav<br>With You</h2>
                        <p class="mt-4 text-sm sm:text-base text-brand-100/90 leading-relaxed max-w-md">
                            Download the Matutum PlotNav mobile app to search plots, follow voice-guided directions, and manage reservations right from the cemetery grounds.
                        </p>

                        <div class="mt-8 flex flex-col sm:flex-row items-start sm:items-center gap-4">
                            <?php if ($apkAvailable): ?>
                                <a href="download_apk.php" class="w-full sm:w-auto px-8 py-3.5 rounded-full bg-white text-brand-800 font-bold text-sm hover:bg-brand-50 transition-all duration-300 shadow-xl shadow-black/20 hover:-translate-y-0.5 flex items-center justify-center gap-2.5">
                                    <i class="fa-solid fa-download"></i> Download for Android
                                    <span class="text-[10px] font-semibold text-brand-500">APK &middot; <?= $apkSizeMb ?> MB</span>
                                </a>
                            <?php else: ?>
                                <span class="w-full sm:w-auto px-8 py-3.5 rounded-full bg-white/15 text-white/80 font-bold text-sm border border-white/25 flex items-center justify-center gap-2.5 cursor-not-allowed">
                                    <i class="fa-brands fa-android"></i> Android App Coming Soon
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="mt-4 text-[11px] text-brand-200/80 flex items-center gap-2">
                            <i class="fa-solid fa-circle-info"></i> Free download &middot; Requires Android 8.0 or later
                        </p>
                    </div>

                    <!-- Right: install steps -->
                    <div class="space-y-3.5">
                        <div class="flex items-center gap-4 rounded-2xl bg-white/10 backdrop-blur border border-white/15 px-5 py-4">
                            <span class="w-10 h-10 rounded-xl bg-white/15 text-white flex items-center justify-center text-sm font-extrabold shrink-0">1</span>
                            <div>
                                <p class="text-sm font-extrabold text-white">Download the APK</p>
                                <p class="text-[11px] text-brand-100/80 mt-0.5">Tap the download button to save the installer on your phone.</p>
                            </div>
                            <i class="fa-solid fa-file-arrow-down ml-auto text-brand-200/70 text-lg shrink-0 hidden sm:block"></i>
                        </div>
                        <div class="flex items-center gap-4 rounded-2xl bg-white/10 backdrop-blur border border-white/15 px-5 py-4">
                            <span class="w-10 h-10 rounded-xl bg-white/15 text-white flex items-center justify-center text-sm font-extrabold shrink-0">2</span>
                            <div>
                                <p class="text-sm font-extrabold text-white">Allow installation</p>
                                <p class="text-[11px] text-brand-100/80 mt-0.5">If prompted, enable &ldquo;Install from unknown sources&rdquo; for your browser.</p>
                            </div>
                            <i class="fa-solid fa-shield-halved ml-auto text-brand-200/70 text-lg shrink-0 hidden sm:block"></i>
                        </div>
                        <div class="flex items-center gap-4 rounded-2xl bg-white/10 backdrop-blur border border-white/15 px-5 py-4">
                            <span class="w-10 h-10 rounded-xl bg-white/15 text-white flex items-center justify-center text-sm font-extrabold shrink-0">3</span>
                            <div>
                                <p class="text-sm font-extrabold text-white">Open &amp; sign in</p>
                                <p class="text-[11px] text-brand-100/80 mt-0.5">Launch Matutum PlotNav and log in with your existing account.</p>
                            </div>
                            <i class="fa-solid fa-mobile-screen-button ml-auto text-brand-200/70 text-lg shrink-0 hidden sm:block"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA SECTION -->
    <section class="relative pt-24 overflow-hidden">
        <div class="max-w-5xl mx-auto px-6 relative z-10">
            <div class="reveal text-center">
                <p class="text-xs font-extrabold uppercase tracking-[0.2em] text-brand-600 dark:text-brand-400">A Smarter Way to Manage Your Cemetery</p>
                <h2 class="mt-3 text-3xl sm:text-4xl font-extrabold text-slate-900 dark:text-white tracking-tight">Get Started Today</h2>
                <p class="mt-4 text-sm sm:text-base text-slate-600 dark:text-slate-400 max-w-xl mx-auto leading-relaxed">
                    Join CemeteryNav and bring comfort, accessibility, and efficiency to your cemetery management.
                </p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="login.php?mode=login" onclick="openAuthModal('login'); return false;" class="w-full sm:w-auto px-8 py-3.5 rounded-full bg-gradient-to-r from-brand-600 to-brand-700 hover:from-brand-500 hover:to-brand-600 font-bold text-sm text-white transition-all duration-300 shadow-xl shadow-brand-600/30 hover:shadow-2xl hover:-translate-y-0.5 flex items-center justify-center gap-2.5">
                        Get Started Free <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                    <a href="#features" class="w-full sm:w-auto px-8 py-3.5 rounded-full bg-white dark:bg-slate-900 font-bold text-sm text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 border border-slate-200 dark:border-slate-700 transition-all duration-300 shadow-sm hover:border-brand-300 dark:hover:border-brand-600 hover:-translate-y-0.5 flex items-center justify-center gap-2.5">
                        Learn More
                    </a>
                </div>
            </div>

            <!-- Available On -->
            <div class="reveal reveal-delay-1 mt-14 flex justify-center lg:justify-end">
                <div class="rounded-2xl border border-slate-200/80 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur px-6 py-4 shadow-sm">
                    <p class="text-[10px] font-extrabold uppercase tracking-[0.18em] text-slate-400 dark:text-slate-500 text-center mb-3">Available On</p>
                    <div class="flex items-center gap-5 sm:gap-6 text-slate-500 dark:text-slate-400">
                        <span class="flex flex-col items-center gap-1.5 text-[9px] font-bold"><i class="fa-solid fa-globe text-base"></i>Web</span>
                        <span class="flex flex-col items-center gap-1.5 text-[9px] font-bold"><i class="fa-brands fa-apple text-base"></i>iOS</span>
                        <a href="download_apk.php" class="flex flex-col items-center gap-1.5 text-[9px] font-bold hover:text-brand-600 dark:hover:text-brand-400 transition"><i class="fa-brands fa-android text-base"></i>Android</a>
                        <span class="flex flex-col items-center gap-1.5 text-[9px] font-bold"><i class="fa-brands fa-windows text-base"></i>Windows</span>
                        <span class="flex flex-col items-center gap-1.5 text-[9px] font-bold"><i class="fa-brands fa-apple text-base"></i>macOS</span>
                        <span class="flex flex-col items-center gap-1.5 text-[9px] font-bold"><i class="fa-brands fa-linux text-base"></i>Linux</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Purple silhouette strip -->
        <svg class="mt-10 w-full h-28 sm:h-40 block text-brand-900 dark:text-brand-950" viewBox="0 0 1440 200" preserveAspectRatio="xMidYMax slice" aria-hidden="true">
            <path d="M0 200 V148 C 220 116 460 162 720 140 C 980 118 1220 156 1440 132 V200 Z" fill="#ede9fe" opacity="0.6"/>
            <g fill="currentColor" opacity="0.9">
                <!-- pine -->
                <polygon points="80,72 52,132 108,132"/>
                <polygon points="80,96 44,166 116,166"/>
                <rect x="76" y="166" width="8" height="20"/>
                <!-- bush -->
                <ellipse cx="170" cy="186" rx="44" ry="16"/>
                <!-- cross gravestone -->
                <rect x="252" y="132" width="8" height="26"/>
                <rect x="244" y="140" width="24" height="8" rx="2"/>
                <rect x="248" y="158" width="16" height="30" rx="4"/>
                <!-- pine -->
                <polygon points="348,96 326,142 370,142"/>
                <polygon points="348,116 320,172 376,172"/>
                <rect x="345" y="172" width="7" height="16"/>
                <!-- rounded gravestone -->
                <path d="M470 200 v-38 a14 14 0 0 1 28 0 v38 z"/>
                <ellipse cx="545" cy="188" rx="38" ry="14"/>
                <!-- leafy tree -->
                <circle cx="640" cy="146" r="26"/>
                <circle cx="662" cy="158" r="16"/>
                <rect x="637" y="164" width="7" height="26"/>
                <!-- cross gravestone -->
                <rect x="770" y="128" width="8" height="26"/>
                <rect x="762" y="136" width="24" height="8" rx="2"/>
                <rect x="766" y="154" width="16" height="34" rx="4"/>
                <ellipse cx="850" cy="190" rx="34" ry="12"/>
                <!-- pine -->
                <polygon points="930,88 906,148 954,148"/>
                <polygon points="930,112 898,178 962,178"/>
                <rect x="926" y="178" width="8" height="14"/>
                <!-- rounded gravestone -->
                <path d="M1040 200 v-34 a13 13 0 0 1 26 0 v34 z"/>
                <!-- pine -->
                <polygon points="1180,70 1150,136 1210,136"/>
                <polygon points="1180,98 1142,174 1218,174"/>
                <rect x="1176" y="174" width="9" height="18"/>
                <ellipse cx="1290" cy="188" rx="40" ry="14"/>
                <!-- small gravestone -->
                <path d="M1370 200 v-26 a11 11 0 0 1 22 0 v26 z"/>
            </g>
        </svg>
    </section>

    <!-- FOOTER -->
    <footer id="about" class="border-t border-slate-200 dark:border-slate-800/80 bg-slate-900 text-slate-400 pt-14 pb-8 text-sm">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-10 pb-10 border-b border-slate-800">
                <div class="md:col-span-2">
                    <div class="flex items-center gap-2.5 text-white font-bold text-base mb-4">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-700 to-brand-500 flex items-center justify-center text-sm text-white shadow-md shadow-brand-600/20">
                            <i class="fa-solid fa-location-dot"></i>
                        </div>
                        Farewells and Sympathy
                    </div>
                    <p class="leading-relaxed max-w-sm">
                        Interactive cemetery mapping and voice navigation for a seamless, dignified visiting experience.
                    </p>
                </div>
                <div>
                    <h4 class="text-white font-bold uppercase tracking-wider text-xs mb-4">Explore</h4>
                    <ul class="space-y-2.5">
                        <li><a href="#features" class="hover:text-brand-400 transition flex items-center gap-2"><i class="fa-solid fa-angle-right text-[10px] text-brand-500"></i> Features</a></li>
                        <li><a href="#how-it-works" class="hover:text-brand-400 transition flex items-center gap-2"><i class="fa-solid fa-angle-right text-[10px] text-brand-500"></i> How it Works</a></li>
                        <li><a href="#benefits" class="hover:text-brand-400 transition flex items-center gap-2"><i class="fa-solid fa-angle-right text-[10px] text-brand-500"></i> Benefits</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-bold uppercase tracking-wider text-xs mb-4">Account</h4>
                    <ul class="space-y-2.5">
                        <li><a href="login.php?mode=login" onclick="openAuthModal('login'); return false;" class="hover:text-brand-400 transition flex items-center gap-2"><i class="fa-solid fa-right-to-bracket text-[10px] text-brand-500"></i> Login</a></li>
                        <li><a href="login.php?mode=register" onclick="openAuthModal('register'); return false;" class="hover:text-brand-400 transition flex items-center gap-2"><i class="fa-solid fa-user-plus text-[10px] text-brand-500"></i> Register</a></li>
                        <li><a href="download_apk.php" class="hover:text-brand-400 transition flex items-center gap-2"><i class="fa-brands fa-android text-[10px] text-brand-500"></i> Android App</a></li>
                    </ul>
                </div>
            </div>
            <div class="pt-8 flex flex-col md:flex-row items-center justify-between gap-4">
                <p>© <?= date('Y') ?> Capstone Project. Powered by PHP, Supabase & Web Speech API.</p>
                <a href="#" class="inline-flex items-center gap-2 text-xs font-semibold hover:text-brand-400 transition">
                    <i class="fa-solid fa-arrow-up"></i> Back to top
                </a>
            </div>
        </div>
    </footer>

    <!-- Auth Modal -->
    <div id="authModal" class="fixed inset-0 z-[100] hidden" role="dialog" aria-modal="true" aria-label="Sign in to CemeteryNav">
        <iframe id="authIframe" src="about:blank" allowtransparency="true" frameborder="0" class="w-full h-full border-none bg-transparent" style="background: transparent none; color-scheme: normal;" title="CemeteryNav Login"></iframe>
    </div>

    <script>
    function openAuthModal(mode = 'login') {
        const iframe = document.getElementById('authIframe');
        iframe.src = 'login.php?mode=' + encodeURIComponent(mode) + '&embed=1';
        document.getElementById('authModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeAuthModal() {
        document.getElementById('authModal').classList.add('hidden');
        document.getElementById('authIframe').src = 'about:blank';
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAuthModal();
    });

    window.addEventListener('message', (e) => {
        if (e.data && e.data.type === 'cemeterynav-redirect' && e.data.url) {
            try { sessionStorage.setItem('cn_auth', <?= json_encode(session_id()) ?>); } catch (err) {}
            window.location.href = e.data.url;
        }
    });

    </script>

    <script>
        const html = document.documentElement;
        const toggle = document.getElementById('theme-toggle');
        const icon = document.getElementById('theme-icon');

        function setTheme(isDark) {
            if (isDark) {
                html.classList.add('dark');
                icon.className = 'fa-solid fa-moon';
                toggle.setAttribute('aria-label', 'Switch to Light mode');
            } else {
                html.classList.remove('dark');
                icon.className = 'fa-solid fa-sun';
                toggle.setAttribute('aria-label', 'Switch to Dark mode');
            }
        }

        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            setTheme(true);
        } else {
            setTheme(false);
        }

        toggle.addEventListener('click', () => {
            const isDark = !html.classList.contains('dark');
            setTheme(isDark);
            localStorage.theme = isDark ? 'dark' : 'light';
        });

        window.addEventListener('storage', (e) => {
            if (e.key === 'theme' && e.newValue) {
                setTheme(e.newValue === 'dark');
            }
        });

        // Scroll reveal animations
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });

        document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));

        // Smooth "Back to top" link
        document.querySelectorAll('a[href="#"]').forEach(a => {
            a.addEventListener('click', (e) => {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    </script>
</body>
</html>
