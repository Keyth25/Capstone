<?php
require 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$reservation = null;
$deceased = null;
$future = null;
$error = '';

try {
    $stmt = $pdo->prepare("
        SELECT r.*,
               a.first_name as app_first, a.middle_name as app_middle, a.last_name as app_last, a.suffix as app_suffix,
               a.date_of_birth as app_dob, a.gender as app_gender, a.civil_status, a.contact_number,
               a.email_address, a.complete_address,
               p.plot_number, p.status as plot_status, p.price as plot_price,
               s.section_name, s.section_code
        FROM public.reservations r
        LEFT JOIN public.applicants a ON r.applicant_id = a.id
        LEFT JOIN public.plots p ON r.plot_id = p.id
        LEFT JOIN public.sections s ON p.section_id = s.id
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($reservation) {
        if ($reservation['reservation_type'] === 'Deceased Relative') {
            $dStmt = $pdo->prepare("SELECT * FROM public.deceased_information WHERE reservation_id = ?");
            $dStmt->execute([$reservation['id']]);
            $deceased = $dStmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $fStmt = $pdo->prepare("SELECT * FROM public.future_use_information WHERE reservation_id = ?");
            $fStmt->execute([$reservation['id']]);
            $future = $fStmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        $error = "Reservation not found.";
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Reservation Details - PlotBox GIS</title>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { brand: { 500: '#06b6d4', 600: '#0891b2' } }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-panel {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        .dark .glass-panel {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.3); border-radius: 9999px; }
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
                    <i class="fa-solid fa-file-invoice text-cyan-500"></i> Reservation Details
                </h1>
            </div>
            <button id="themeToggle" onclick="toggleTheme()" class="p-2 rounded-xl bg-slate-200/80 dark:bg-slate-800/80">
                <i id="themeToggleIcon" class="fa-solid fa-moon text-sm"></i>
            </button>
        </header>

        <div class="flex-1 overflow-y-auto p-6">
            <?php if ($error): ?>
                <div class="p-3 mb-4 bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs rounded-xl flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($error) ?>
                </div>
                <a href="admin_reservations.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-bold hover:bg-slate-300 dark:hover:bg-slate-700 transition">
                    <i class="fa-solid fa-arrow-left"></i> Back to Reservations
                </a>
            <?php elseif ($reservation): ?>
                <div class="mb-4 flex items-center justify-between">
                    <a href="admin_reservations.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-bold hover:bg-slate-300 dark:hover:bg-slate-700 transition">
                        <i class="fa-solid fa-arrow-left"></i> Back to Reservations
                    </a>
                    <?php
                        $rawStatus = strtolower($reservation['status'] ?? '');
                        if ($rawStatus === 'approved') {
                            $badge = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20';
                        } elseif ($rawStatus === 'rejected') {
                            $badge = 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20';
                        } else {
                            $badge = 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20';
                        }
                    ?>
                    <span class="text-[10px] uppercase font-bold px-3 py-1.5 rounded-lg <?= $badge ?>">
                        <?= htmlspecialchars($reservation['status'] ?? 'Pending Approval') ?>
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <!-- Reference & Plot -->
                    <div class="glass-panel rounded-2xl p-5 space-y-4">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-3">
                            <i class="fa-solid fa-bookmark text-cyan-500"></i> Reservation Summary
                        </h2>
                        <div class="grid grid-cols-2 gap-y-3 text-xs">
                            <span class="text-slate-500 dark:text-slate-400">Reference ID</span>
                            <span class="font-mono font-bold text-slate-900 dark:text-white text-right"><?= htmlspecialchars($reservation['reservation_id'] ?? '-') ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Type</span>
                            <span class="font-medium text-slate-900 dark:text-white text-right"><?= htmlspecialchars($reservation['reservation_type'] ?? '-') ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Reservation Date</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right"><?= !empty($reservation['reservation_date']) ? date('M d, Y', strtotime($reservation['reservation_date'])) : '-' ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Payment Option</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right"><?= htmlspecialchars($reservation['payment_option'] ?? '-') ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Reservation Fee</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right">$<?= number_format((float)($reservation['reservation_fee'] ?? 0), 2) ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Amount Paid</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right">$<?= number_format((float)($reservation['amount_paid'] ?? 0), 2) ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Payment Due</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right"><?= !empty($reservation['payment_due_date']) ? date('M d, Y', strtotime($reservation['payment_due_date'])) : '-' ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Notes</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right break-words"><?= nl2br(htmlspecialchars($reservation['additional_notes'] ?? '-')) ?></span>
                        </div>
                    </div>

                    <!-- Plot Details -->
                    <div class="glass-panel rounded-2xl p-5 space-y-4">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-3">
                            <i class="fa-solid fa-map-pin text-emerald-500"></i> Plot Details
                        </h2>
                        <div class="grid grid-cols-2 gap-y-3 text-xs">
                            <span class="text-slate-500 dark:text-slate-400">Section</span>
                            <span class="font-medium text-slate-900 dark:text-white text-right"><?= htmlspecialchars($reservation['section_name'] ?? '-') ?> (<?= htmlspecialchars($reservation['section_code'] ?? '-') ?>)</span>

                            <span class="text-slate-500 dark:text-slate-400">Plot Number</span>
                            <span class="font-mono font-medium text-slate-900 dark:text-white text-right"><?= htmlspecialchars($reservation['plot_number'] ?? '-') ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Plot Price</span>
                            <span class="font-medium text-emerald-600 dark:text-emerald-400 text-right">$<?= number_format((float)($reservation['plot_price'] ?? 0), 2) ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Plot Status</span>
                            <span class="font-medium text-slate-900 dark:text-white text-right"><?= htmlspecialchars($reservation['plot_status'] ?? '-') ?></span>

                            <?php if (!empty($reservation['intended_burial_date'])): ?>
                                <span class="text-slate-500 dark:text-slate-400">Intended Burial</span>
                                <span class="text-slate-700 dark:text-slate-300 text-right"><?= date('M d, Y', strtotime($reservation['intended_burial_date'])) ?></span>
                            <?php endif; ?>

                            <?php if (!empty($reservation['expected_future_use_date'])): ?>
                                <span class="text-slate-500 dark:text-slate-400">Expected Use Date</span>
                                <span class="text-slate-700 dark:text-slate-300 text-right"><?= date('M d, Y', strtotime($reservation['expected_future_use_date'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Applicant Info -->
                    <div class="glass-panel rounded-2xl p-5 space-y-4">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-3">
                            <i class="fa-solid fa-user text-blue-500"></i> Applicant Information
                        </h2>
                        <div class="grid grid-cols-2 gap-y-3 text-xs">
                            <span class="text-slate-500 dark:text-slate-400">Full Name</span>
                            <span class="font-medium text-slate-900 dark:text-white text-right"><?= htmlspecialchars(($reservation['app_first'] ?? '') . ' ' . ($reservation['app_middle'] ?? '') . ' ' . ($reservation['app_last'] ?? '') . ' ' . ($reservation['app_suffix'] ?? '')) ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Gender</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right"><?= htmlspecialchars($reservation['app_gender'] ?? '-') ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Civil Status</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right"><?= htmlspecialchars($reservation['civil_status'] ?? '-') ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Date of Birth</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right"><?= !empty($reservation['app_dob']) ? date('M d, Y', strtotime($reservation['app_dob'])) : '-' ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Contact</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right"><?= htmlspecialchars($reservation['contact_number'] ?? '-') ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Email</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right"><?= htmlspecialchars($reservation['email_address'] ?? '-') ?></span>

                            <span class="text-slate-500 dark:text-slate-400">Address</span>
                            <span class="text-slate-700 dark:text-slate-300 text-right break-words"><?= nl2br(htmlspecialchars($reservation['complete_address'] ?? '-')) ?></span>
                        </div>
                    </div>

                    <!-- Conditional Details -->
                    <div class="glass-panel rounded-2xl p-5 space-y-4">
                        <?php if ($reservation['reservation_type'] === 'Deceased Relative' && $deceased): ?>
                            <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-3">
                                <i class="fa-solid fa-heart text-rose-500"></i> Deceased Information
                            </h2>
                            <div class="grid grid-cols-2 gap-y-3 text-xs">
                                <span class="text-slate-500 dark:text-slate-400">Full Name</span>
                                <span class="font-medium text-slate-900 dark:text-white text-right"><?= htmlspecialchars(($deceased['first_name'] ?? '') . ' ' . ($deceased['middle_name'] ?? '') . ' ' . ($deceased['last_name'] ?? '') . ' ' . ($deceased['suffix'] ?? '')) ?></span>

                                <span class="text-slate-500 dark:text-slate-400">Date of Birth</span>
                                <span class="text-slate-700 dark:text-slate-300 text-right"><?= !empty($deceased['date_of_birth']) ? date('M d, Y', strtotime($deceased['date_of_birth'])) : '-' ?></span>

                                <span class="text-slate-500 dark:text-slate-400">Date of Death</span>
                                <span class="text-slate-700 dark:text-slate-300 text-right"><?= !empty($deceased['date_of_death']) ? date('M d, Y', strtotime($deceased['date_of_death'])) : '-' ?></span>

                                <span class="text-slate-500 dark:text-slate-400">Age at Death</span>
                                <span class="text-slate-700 dark:text-slate-300 text-right"><?= htmlspecialchars($deceased['age_at_death'] ?? '-') ?></span>

                                <span class="text-slate-500 dark:text-slate-400">Gender</span>
                                <span class="text-slate-700 dark:text-slate-300 text-right"><?= htmlspecialchars($deceased['gender'] ?? '-') ?></span>

                                <span class="text-slate-500 dark:text-slate-400">Relationship</span>
                                <span class="text-slate-700 dark:text-slate-300 text-right"><?= htmlspecialchars($deceased['relationship_to_applicant'] ?? '-') ?></span>
                            </div>
                        <?php elseif ($reservation['reservation_type'] === 'Future Use' && $future): ?>
                            <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-3">
                                <i class="fa-solid fa-shield-heart text-emerald-500"></i> Future Use Details
                            </h2>
                            <div class="grid grid-cols-2 gap-y-3 text-xs">
                                <span class="text-slate-500 dark:text-slate-400">Reserved For</span>
                                <span class="font-medium text-slate-900 dark:text-white text-right"><?= htmlspecialchars($future['reserved_for'] ?? '-') ?></span>

                                <span class="text-slate-500 dark:text-slate-400">Intended Name</span>
                                <span class="font-medium text-slate-900 dark:text-white text-right"><?= htmlspecialchars(($future['intended_first_name'] ?? '') . ' ' . ($future['intended_middle_name'] ?? '') . ' ' . ($future['intended_last_name'] ?? '')) ?></span>

                                <span class="text-slate-500 dark:text-slate-400">Relationship</span>
                                <span class="text-slate-700 dark:text-slate-300 text-right"><?= htmlspecialchars($future['relationship_to_applicant'] ?? '-') ?></span>
                            </div>
                        <?php else: ?>
                            <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-3">
                                <i class="fa-solid fa-circle-info text-slate-500"></i> Additional Details
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">No additional details on file.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('themeToggle').onclick = function () {
            toggleTheme();
            const icon = document.getElementById('themeToggleIcon');
            icon.className = document.documentElement.classList.contains('dark') ? 'fa-solid fa-moon text-sm' : 'fa-solid fa-sun text-sm';
        };
    </script>
</body>
</html>
