<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Load announcements intended for users
$announcements = [];
try {
    $stmt = $pdo->prepare("
        SELECT title, message, start_date, end_date, is_active, created_at
        FROM public.announcements
        WHERE audience IN ('all', 'user')
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User announcements fetch: " . $e->getMessage());
    $announcements = [];
}

function annStatus($a) {
    if (!$a['is_active'] || in_array($a['is_active'], ['f', 'false', '0', 0, false], true)) return ['Inactive', 'slate'];
    $today = strtotime('today');
    if (!empty($a['end_date']) && strtotime($a['end_date']) < $today) return ['Expired', 'red'];
    if (!empty($a['start_date']) && strtotime($a['start_date']) > $today) return ['Scheduled', 'amber'];
    return ['Active', 'emerald'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Matutum PlotNav</title>
    <?php theme_head_script(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 850: '#151e2e', 950: '#0b0f19' },
                        cyan: { 400: '#22d3ee', 500: '#06b6d4', 600: '#0891b2' }
                    }
                }
            }
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="mobile.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#7c3aed">
    <script src="pwa.js" defer></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(226, 232, 240, 0.8);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        .dark .glass-card {
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 h-screen flex flex-col font-sans overflow-hidden antialiased transition-colors duration-200">

    <!-- HEADER BAR -->
    <header class="glass-card border-b border-slate-200 dark:border-slate-800/80 px-5 py-3.5 flex items-center justify-between z-30 shrink-0">
        <div class="flex items-center gap-3">
            <button onclick="if(typeof toggleSidebar==='function') toggleSidebar();" class="lg:hidden p-2 rounded-xl bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 transition">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <i data-lucide="megaphone" class="w-5 h-5 text-cyan-600 dark:text-cyan-400"></i>
                    Announcements
                </h1>
                <p class="text-[10px] text-slate-500 dark:text-slate-400">Latest updates and events from the cemetery</p>
            </div>
        </div>
        <a href="user_dashboard.php" class="p-2 rounded-xl bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 transition">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
        </a>
    </header>

    <div class="flex-1 flex overflow-hidden">
        <?php include 'user_sidebar.php'; ?>

        <main class="flex-1 p-4 md:p-6 overflow-y-auto dashboard-scroll space-y-4">

            <?php if (empty($announcements)): ?>
                <div class="glass-card rounded-2xl p-8 text-center">
                    <div class="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center mx-auto mb-3">
                        <i data-lucide="inbox" class="w-6 h-6 text-slate-400"></i>
                    </div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">No announcements yet</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Check back later for cemetery updates and events.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 gap-4">
                    <?php foreach ($announcements as $a):
                        [$status, $color] = annStatus($a);
                        $colorClasses = [
                            'emerald' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
                            'amber'   => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
                            'red'     => 'bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20',
                            'slate'   => 'bg-slate-500/10 text-slate-600 dark:text-slate-400 border-slate-500/20'
                        ][$color];
                    ?>
                        <div class="glass-card rounded-2xl p-5 border border-slate-200 dark:border-slate-800">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1">
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($a['title']) ?></h3>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-2 leading-relaxed"><?= nl2br(htmlspecialchars($a['message'])) ?></p>
                                </div>
                                <span class="text-[10px] font-semibold px-2 py-1 rounded-full border shrink-0 <?= $colorClasses ?>">
                                    <?= $status ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-4 mt-4 text-[10px] text-slate-500 dark:text-slate-400">
                                <span class="flex items-center gap-1">
                                    <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
                                    <?= date('M j, Y', strtotime($a['created_at'])) ?>
                                </span>
                                <?php if (!empty($a['start_date']) || !empty($a['end_date'])): ?>
                                    <span class="flex items-center gap-1">
                                        <i data-lucide="clock" class="w-3.5 h-3.5"></i>
                                        <?= htmlspecialchars($a['start_date'] ?? 'now') ?> to <?= htmlspecialchars($a['end_date'] ?? 'no end') ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
