<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Database Counts
$records = $pdo->query("SELECT plot_code FROM public.deceased_records")->fetchAll();
$all_reservations = $pdo->query("SELECT plot_code, status FROM public.reservations WHERE status IN ('pending', 'approved')")->fetchAll();

$stmt_res = $pdo->prepare("SELECT * FROM public.reservations WHERE user_id = ? ORDER BY created_at DESC");
$stmt_res->execute([$user_id]);
$my_reservations = $stmt_res->fetchAll();

// Dynamic Total Capacity calculation from defined cemetery sections
$defined_capacity = (int)$pdo->query("SELECT SUM(total_plots) FROM public.cemetery_sections")->fetchColumn();
$occupied_count = count($records);
$reserved_count = count($all_reservations);

$total_plots_estimate = max(100, $defined_capacity > 0 ? $defined_capacity : ($occupied_count + $reserved_count + 50));
$available_count = max(0, $total_plots_estimate - $occupied_count - $reserved_count);
$occupancy_rate = round((($occupied_count + $reserved_count) / $total_plots_estimate) * 100);

// Historical monthly rate for dynamic exhaustion forecast
$monthly_burials = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM public.deceased_records 
    WHERE created_at >= NOW() - INTERVAL '30 days'
")->fetchColumn();

$monthly_rate = max(1, $monthly_burials); // Floor at 1 plot/month
$months_remaining = ceil($available_count / $monthly_rate);
$forecast_exhaustion_date = date('F Y', strtotime("+{$months_remaining} months"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Plot Availability - Matutum PlotNav</title>
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-panel { background: rgba(255, 255, 255, 0.85); border: 1px solid rgba(226, 232, 240, 0.8); backdrop-filter: blur(12px); }
        .dark .glass-panel { background: rgba(15, 23, 42, 0.75); border: 1px solid rgba(255, 255, 255, 0.08); }
        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.4); border-radius: 9999px; }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 h-screen flex flex-col font-sans overflow-hidden transition-colors duration-200">

    <header class="glass-panel border-b border-slate-200 dark:border-slate-800/80 px-5 py-3 flex items-center justify-between z-30 shrink-0">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300">
                <i class="fa-solid fa-bars text-base"></i>
            </button>
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 bg-gradient-to-tr from-cyan-600 to-emerald-500 rounded-xl flex items-center justify-center text-white shadow-lg shadow-cyan-500/20">
                    <i class="fa-solid fa-chart-pie text-base"></i>
                </div>
                <div>
                    <span class="font-extrabold text-slate-900 dark:text-white tracking-wider text-sm block leading-tight">MATUTUM <span class="text-cyan-600 dark:text-cyan-400">PLOTNAV</span></span>
                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-semibold tracking-wide uppercase">Real-Time Plot Availability</span>
                </div>
            </div>
        </div>
    </header>

    <div class="flex-1 flex relative overflow-hidden z-10">
        
        <?php include 'user_sidebar.php'; ?>

        <main class="flex-1 p-4 md:p-8 overflow-y-auto custom-scroll space-y-6">
            
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-200 dark:border-slate-800/80 pb-4">
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900 dark:text-white tracking-tight flex items-center gap-2">
                        <i class="fa-solid fa-compass-drafting text-cyan-600 dark:text-cyan-400"></i> Plot Availability & Forecasting Engine
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Check real-time plot availability and examine cemetery land depletion projections.</p>
                </div>
            </div>

            <!-- GROUND STATS & FORECAST PANEL -->
            <div class="glass-panel rounded-2xl p-5 border border-slate-200 dark:border-slate-800/80 shadow-xl space-y-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-xl bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 flex items-center justify-center border border-cyan-500/20">
                            <i class="fa-solid fa-chart-simple text-xs"></i>
                        </div>
                        <div>
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider block">Grounds Capacity Ratio</span>
                            <span class="text-[10px] text-slate-500 dark:text-slate-400">Total Defined Capacity: <?= $total_plots_estimate ?> Plots</span>
                        </div>
                    </div>
                    <span class="text-lg font-black text-cyan-600 dark:text-cyan-400 bg-cyan-500/10 border border-cyan-500/20 px-3 py-1 rounded-xl"><?= $occupancy_rate ?>%</span>
                </div>

                <div class="w-full bg-slate-200 dark:bg-slate-900 h-2.5 rounded-full overflow-hidden flex p-0.5 border border-slate-300 dark:border-slate-800">
                    <div class="bg-cyan-500 h-full rounded-full transition-all duration-500" style="width: <?= $occupancy_rate ?>%"></div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 pt-2">
                    <div class="bg-white/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-800/80 p-3.5 rounded-xl flex items-center justify-between">
                        <div>
                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block">Occupied</span>
                            <span class="text-base font-extrabold text-slate-900 dark:text-white"><?= $occupied_count ?></span>
                        </div>
                        <div class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-cross"></i>
                        </div>
                    </div>

                    <div class="bg-white/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-800/80 p-3.5 rounded-xl flex items-center justify-between">
                        <div>
                            <span class="text-[10px] font-bold text-amber-600 dark:text-amber-500/80 uppercase tracking-wider block">Reserved</span>
                            <span class="text-base font-extrabold text-amber-600 dark:text-amber-400"><?= $reserved_count ?></span>
                        </div>
                        <div class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                    </div>

                    <div class="bg-white/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-800/80 p-3.5 rounded-xl flex items-center justify-between">
                        <div>
                            <span class="text-[10px] font-bold text-emerald-600 dark:text-emerald-500/80 uppercase tracking-wider block">Available</span>
                            <span class="text-base font-extrabold text-emerald-600 dark:text-emerald-400"><?= $available_count ?></span>
                        </div>
                        <div class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-purple-900/30 to-indigo-900/30 border border-purple-500/30 p-3.5 rounded-xl flex items-center justify-between">
                        <div>
                            <span class="text-[10px] font-bold text-purple-400 uppercase tracking-wider block">Est. Exhaustion Date</span>
                            <span class="text-sm font-extrabold text-purple-300"><?= $forecast_exhaustion_date ?></span>
                        </div>
                        <div class="w-8 h-8 rounded-lg bg-purple-500/20 text-purple-300 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CHECK SPECIFIC PLOT SEARCH -->
            <div class="glass-panel rounded-2xl p-5 border border-slate-200 dark:border-slate-800/80 shadow-xl space-y-3">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 flex items-center justify-center border border-cyan-500/20">
                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Check Specific Plot Status</span>
                </div>

                <div class="flex flex-col sm:flex-row gap-2 pt-1">
                    <div class="relative flex-1">
                        <i class="fa-solid fa-barcode absolute left-3.5 top-3 text-xs text-slate-400 dark:text-slate-500"></i>
                        <input type="text" id="checkPlotInput" placeholder="Enter Plot Code (e.g. A-1-9)" class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl pl-9 pr-3 py-2 text-xs text-slate-900 dark:text-white uppercase font-mono placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:border-cyan-500 transition shadow-inner">
                    </div>
                    <button onclick="checkPlotAvailability()" class="bg-gradient-to-r from-cyan-600 to-cyan-500 hover:from-cyan-500 hover:to-cyan-400 text-white px-5 py-2 rounded-xl text-xs font-bold transition shadow-md shadow-cyan-500/20 flex items-center justify-center gap-1.5 shrink-0">
                        <i class="fa-solid fa-magnifying-glass text-xs"></i> Check Status
                    </button>
                </div>

                <div id="availabilityResult" class="hidden rounded-xl border p-4 text-xs transition-all duration-300"></div>
            </div>

            <!-- ACTIVE APPLICATIONS LIST -->
            <div class="glass-panel rounded-2xl p-5 border border-slate-200 dark:border-slate-800/80 shadow-xl space-y-4">
                <div class="flex justify-between items-center border-b border-slate-200 dark:border-slate-800/80 pb-3">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center border border-purple-500/20">
                            <i class="fa-solid fa-file-signature text-xs"></i>
                        </div>
                        <span class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">My Active Applications</span>
                    </div>
                    <span class="text-[10px] text-slate-500 font-mono">Total: <?= count($my_reservations) ?></span>
                </div>

                <div class="space-y-2.5">
                    <?php if (empty($my_reservations)): ?>
                        <div class="text-center py-10">
                            <i class="fa-solid fa-folder-open text-3xl text-slate-300 dark:text-slate-700 mb-2"></i>
                            <p class="text-xs text-slate-500">No reservations submitted yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($my_reservations as $res): ?>
                            <div class="p-3 bg-white/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-800/80 rounded-xl flex justify-between items-center transition shadow-sm">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <i class="fa-solid fa-map-pin text-cyan-600 dark:text-cyan-400 text-xs"></i>
                                        <p class="text-xs font-extrabold text-slate-900 dark:text-white font-mono">Plot: <?= htmlspecialchars($res['plot_code']) ?></p>
                                    </div>
                                    <span class="text-[10px] text-slate-500 dark:text-slate-400 flex items-center gap-1">
                                        <i class="fa-regular fa-calendar text-[10px]"></i> <?= date('d M Y', strtotime($res['created_at'])) ?>
                                    </span>
                                </div>
                                <div>
                                    <?php if ($res['status'] === 'approved'): ?>
                                        <span class="text-[10px] uppercase font-extrabold px-3 py-1 rounded-lg border bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20 flex items-center gap-1">
                                            <i class="fa-solid fa-circle-check text-[10px]"></i> Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[10px] uppercase font-extrabold px-3 py-1 rounded-lg border bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20 flex items-center gap-1">
                                            <i class="fa-solid fa-clock text-[10px]"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <script>
        function toggleSidebar() { 
            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.toggle('-translate-x-full');
        }

        const occupiedRecords = <?= json_encode($records) ?>;
        const activeReservations = <?= json_encode($all_reservations) ?>;

        function checkPlotAvailability() {
            const inputVal = document.getElementById('checkPlotInput').value.trim().toUpperCase();
            const resDiv = document.getElementById('availabilityResult');
            if (!inputVal) return;

            const isOccupied = occupiedRecords.some(r => r.plot_code && r.plot_code.toUpperCase() === inputVal);
            const isReserved = activeReservations.some(r => r.plot_code && r.plot_code.toUpperCase() === inputVal);

            resDiv.classList.remove('hidden');
            if (isOccupied) {
                resDiv.className = "rounded-xl border border-red-500/30 dark:border-red-500/20 bg-red-500/10 p-4 text-red-700 dark:text-red-300 text-xs flex items-center gap-3";
                resDiv.innerHTML = `
                    <div class="w-8 h-8 rounded-lg bg-red-500/20 text-red-600 dark:text-red-400 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-ban text-sm"></i>
                    </div>
                    <div>
                        <strong class="font-extrabold text-slate-900 dark:text-white block">Plot ${inputVal} is Occupied</strong>
                        <span class="text-[11px] text-red-600/80 dark:text-red-300/80">This plot contains a record and is unavailable for booking.</span>
                    </div>
                `;
            } else if (isReserved) {
                resDiv.className = "rounded-xl border border-amber-500/30 dark:border-amber-500/20 bg-amber-500/10 p-4 text-amber-700 dark:text-amber-200 text-xs flex items-center gap-3";
                resDiv.innerHTML = `
                    <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-clock text-sm"></i>
                    </div>
                    <div>
                        <strong class="font-extrabold text-slate-900 dark:text-white block">Plot ${inputVal} is Reserved</strong>
                        <span class="text-[11px] text-amber-600/80 dark:text-amber-300/80">An active application is currently pending or approved for this plot.</span>
                    </div>
                `;
            } else {
                resDiv.className = "rounded-xl border border-emerald-500/30 dark:border-emerald-500/20 bg-emerald-500/10 p-4 text-emerald-800 dark:text-emerald-200 text-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3";
                resDiv.innerHTML = `
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-circle-check text-sm"></i>
                        </div>
                        <div>
                            <strong class="font-extrabold text-slate-900 dark:text-white block">Plot ${inputVal} is Available!</strong>
                            <span class="text-[11px] text-emerald-700/80 dark:text-emerald-300/80">This plot is ready for application booking.</span>
                        </div>
                    </div>
                    <a href="reservation.php?plot=${encodeURIComponent(inputVal)}" class="w-full sm:w-auto px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white rounded-xl font-bold flex items-center justify-center gap-1.5 shadow-md shadow-emerald-500/20 transition shrink-0">
                        <i class="fa-solid fa-paper-plane text-xs"></i> Reserve Now
                    </a>
                `;
            }
        }
    </script>
</body>
</html>