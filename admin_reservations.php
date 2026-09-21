<?php
require 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['reservation_id'])) {
    $reservation_id = intval($_POST['reservation_id']);
    $action = $_POST['action'];

    try {
        $pdo->beginTransaction();

        $resStmt = $pdo->prepare("SELECT id, plot_id, status, user_id, reservation_id FROM public.reservations WHERE id = ?");
        $resStmt->execute([$reservation_id]);
        $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);

        if (!$reservation) {
            throw new Exception("Reservation not found.");
        }

        if ($action === 'accept_reservation') {
            $stmt = $pdo->prepare("UPDATE public.reservations SET status = 'Approved' WHERE id = ?");
            $stmt->execute([$reservation_id]);

            $pStmt = $pdo->prepare("UPDATE public.plots SET status = 'Reserved' WHERE id = ?");
            $pStmt->execute([$reservation['plot_id']]);

            if (!empty($reservation['user_id'])) {
                create_user_notification($pdo, $reservation['user_id'], 'Reservation Approved', "Your reservation " . (!empty($reservation['reservation_id']) ? '#' . $reservation['reservation_id'] : '#' . $reservation_id) . " has been approved. The plot is now reserved for you.", 'reservation', $reservation_id);
            }

            $message = "Reservation #{$reservation_id} accepted and plot reserved.";
        } elseif ($action === 'reject_reservation') {
            $stmt = $pdo->prepare("UPDATE public.reservations SET status = 'Rejected' WHERE id = ?");
            $stmt->execute([$reservation_id]);

            $pStmt = $pdo->prepare("UPDATE public.plots SET status = 'Available' WHERE id = ?");
            $pStmt->execute([$reservation['plot_id']]);

            if (!empty($reservation['user_id'])) {
                create_user_notification($pdo, $reservation['user_id'], 'Reservation Rejected', "Your reservation " . (!empty($reservation['reservation_id']) ? '#' . $reservation['reservation_id'] : '#' . $reservation_id) . " has been rejected. Please contact the admin for more information.", 'reservation', $reservation_id);
            }

            $message = "Reservation #{$reservation_id} rejected.";
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Update failed: " . $e->getMessage();
    }
}

$allReservations = [];
try {
    $allReservations = $pdo->query("
        SELECT r.id, r.reservation_id, r.reservation_type, r.status, r.reservation_date, r.created_at,
               a.first_name, a.last_name, a.contact_number, a.email_address,
               p.plot_number,
               s.section_name, s.section_code
        FROM public.reservations r
        LEFT JOIN public.applicants a ON r.applicant_id = a.id
        LEFT JOIN public.plots p ON r.plot_id = p.id
        LEFT JOIN public.sections s ON p.section_id = s.id
        ORDER BY r.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to fetch reservations: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Reservations - PlotBox GIS</title>
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
                    <i class="fa-solid fa-clipboard-check text-cyan-500"></i> Reservation Requests
                </h1>
            </div>
            <button id="themeToggle" onclick="toggleTheme()" class="p-2 rounded-xl bg-slate-200/80 dark:bg-slate-800/80">
                <i id="themeToggleIcon" class="fa-solid fa-moon text-sm"></i>
            </button>
        </header>

        <div class="flex-1 overflow-y-auto p-6">
            <?php if ($message): ?>
                <div class="p-3 mb-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 text-xs rounded-xl flex items-center gap-2">
                    <i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="p-3 mb-4 bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-xs rounded-xl flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="glass-panel rounded-2xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">All Reservations</h2>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400"><?= count($allReservations) ?> total</span>
                </div>

                <?php if (empty($allReservations)): ?>
                    <div class="text-center py-12 text-slate-500 dark:text-slate-400 text-xs">
                        <i class="fa-solid fa-inbox text-2xl mb-2"></i>
                        <p>No reservations found.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400">
                                    <th class="pb-3 font-bold">Ref. ID</th>
                                    <th class="pb-3 font-bold">Applicant</th>
                                    <th class="pb-3 font-bold">Plot</th>
                                    <th class="pb-3 font-bold">Type</th>
                                    <th class="pb-3 font-bold">Date</th>
                                    <th class="pb-3 font-bold">Status</th>
                                    <th class="pb-3 font-bold text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php foreach ($allReservations as $row): ?>
                                    <?php
                                        $rawStatus = strtolower($row['status'] ?? '');
                                        if ($rawStatus === 'approved') {
                                            $badge = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20';
                                        } elseif ($rawStatus === 'rejected') {
                                            $badge = 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20';
                                        } else {
                                            $badge = 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20';
                                        }
                                    ?>
                                    <tr class="group hover:bg-slate-100 dark:hover:bg-slate-800/50 transition">
                                        <td class="py-3 font-mono text-cyan-600 dark:text-cyan-400"><?= htmlspecialchars($row['reservation_id'] ?? $row['id']) ?></td>
                                        <td class="py-3">
                                            <div class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?></div>
                                            <div class="text-[10px] text-slate-500"><?= htmlspecialchars($row['email_address'] ?? '') ?></div>
                                        </td>
                                        <td class="py-3">
                                            <div class="font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($row['section_code'] ?? '-') ?> - <?= htmlspecialchars($row['plot_number'] ?? '-') ?></div>
                                        </td>
                                        <td class="py-3 text-slate-600 dark:text-slate-400"><?= htmlspecialchars($row['reservation_type'] ?? '-') ?></td>
                                        <td class="py-3 text-slate-500 dark:text-slate-400"><?= !empty($row['reservation_date']) ? date('M d, Y', strtotime($row['reservation_date'])) : '-' ?></td>
                                        <td class="py-3">
                                            <span class="text-[10px] uppercase font-bold px-2 py-1 rounded-lg <?= $badge ?>">
                                                <?= htmlspecialchars($row['status'] ?? 'Pending Approval') ?>
                                            </span>
                                        </td>
                                        <td class="py-3 text-right">
                                            <a href="admin_reservation_view.php?id=<?= $row['id'] ?>" class="inline-flex items-center justify-center px-3 py-1.5 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 border border-cyan-500/20 text-[10px] font-bold transition" title="View Details">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <?php if ($rawStatus === 'pending approval' || $rawStatus === 'pending'): ?>
                                                <form method="POST" action="admin_reservations.php" class="inline-flex items-center gap-2 ml-2">
                                                    <input type="hidden" name="reservation_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="action" value="accept_reservation" class="px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 border border-emerald-500/20 text-[10px] font-bold transition" title="Accept">
                                                        <i class="fa-solid fa-check"></i>
                                                    </button>
                                                    <button type="submit" name="action" value="reject_reservation" class="px-3 py-1.5 rounded-lg bg-red-500/10 text-red-600 dark:text-red-400 hover:bg-red-500/20 border border-red-500/20 text-[10px] font-bold transition" title="Reject">
                                                        <i class="fa-solid fa-xmark"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-[10px] text-slate-500 dark:text-slate-400">Reviewed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
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
