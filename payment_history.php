<?php
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$payments = [];

try {
    // Fetch payment history for the logged-in user with associated reservation plot details
    $stmt = $pdo->prepare("
        SELECT 
            p.*, 
            r.plot_code, 
            r.notes AS reservation_notes 
        FROM public.payments p
        LEFT JOIN public.reservations r ON p.reservation_id = r.id
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback query if reservations foreign key structure differs
    try {
        $stmt = $pdo->prepare("SELECT * FROM public.payments WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$user_id]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        $error_msg = $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <?php if (!empty($_SESSION['user_id'])): ?>
    <script>
        try {
            if (!sessionStorage.getItem('cemeterynav_logged_in')) window.location.replace('logout.php');
        } catch (e) {}
    </script>
    <?php endif; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - Mount Matutum Memorial Park</title>

    <!-- Dynamic Theme Header Script -->
    <?php if (function_exists('theme_head_script')) { theme_head_script(); } ?>

    <!-- Tailwind CSS v3 -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: {
                            850: '#0f172a',
                            950: '#020617'
                        }
                    }
                }
            }
        }
    </script>

    <!-- Google Fonts & FontAwesome 6 -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#7c3aed">
    <script src="pwa.js" defer></script>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col transition-colors duration-200">

    <!-- HEADER BAR -->
    <header class="sticky top-0 z-40 w-full backdrop-blur-md bg-white/80 dark:bg-slate-900/80 border-b border-slate-200 dark:border-slate-800 transition-colors">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-emerald-500/10 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 rounded-xl flex items-center justify-center font-black text-lg border border-emerald-500/20">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-black text-slate-900 dark:text-white tracking-tight text-lg">PLOT<span class="text-emerald-600 dark:text-emerald-400">NAV</span></span>
                        <span class="bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold px-2 py-0.5 rounded-full border border-emerald-200 dark:border-emerald-800 uppercase tracking-wide">Transactions</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2.5">
                <!-- Theme Mode Toggle -->
                <button id="themeToggle" class="p-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors" title="Toggle Theme">
                    <i class="fa-solid fa-moon dark:hidden"></i>
                    <i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>

                <a href="user_dashboard.php" class="hidden sm:inline-flex items-center gap-2 text-xs font-semibold px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                    <i class="fa-solid fa-arrow-left text-emerald-500"></i>
                    <span>Dashboard</span>
                </a>

                <a href="payment.php" class="inline-flex items-center gap-2 text-xs font-semibold px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white shadow-sm transition-all">
                    <i class="fa-solid fa-credit-card"></i>
                    <span>Make Payment</span>
                </a>

                <div class="h-4 w-px bg-slate-200 dark:bg-slate-800 mx-1 hidden sm:block"></div>

                <a href="logout.php" class="p-2 sm:px-3 sm:py-2 rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 hover:bg-red-500 hover:text-white dark:hover:bg-red-600 transition-all text-xs font-semibold flex items-center gap-1.5">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span class="hidden sm:inline">Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- MAIN BODY -->
    <main class="flex-1 p-4 md:p-6 max-w-7xl w-full mx-auto space-y-6">

        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-slate-200 dark:border-slate-800 pb-5">
            <div>
                <h1 class="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                    <i class="fa-solid fa-clock-rotate-left text-emerald-500"></i> Payment History & Receipts
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Review all past plot transactions, payment methods, and verification status details.</p>
            </div>
            <a href="payment.php" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold px-4 py-2.5 rounded-xl transition shadow-md flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> Submit New Payment
            </a>
        </div>

        <!-- PAYMENT HISTORY TABLE CARD -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm dark:shadow-2xl space-y-4">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-950/60 text-slate-500 dark:text-slate-400 uppercase font-semibold text-[10px] tracking-wider border-b border-slate-200 dark:border-slate-800">
                            <th class="p-3.5 rounded-l-xl">Reference #</th>
                            <th class="p-3.5">Plot Code</th>
                            <th class="p-3.5">Amount Paid</th>
                            <th class="p-3.5">Payment Method</th>
                            <th class="p-3.5">Status</th>
                            <th class="p-3.5">Receipt</th>
                            <th class="p-3.5 text-right rounded-r-xl">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 text-slate-800 dark:text-slate-200">
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    <div class="w-12 h-12 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mx-auto mb-3">
                                        <i class="fa-solid fa-receipt text-xl"></i>
                                    </div>
                                    <p class="font-semibold text-slate-700 dark:text-slate-300 text-sm">No payment transactions found</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-500 mt-1">Submitted payments will appear here once registered.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $pay): ?>
                                <?php 
                                    $status = strtolower($pay['status'] ?? 'pending');
                                    $statusClass = 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20';
                                    if ($status === 'completed' || $status === 'verified' || $status === 'approved') {
                                        $statusClass = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20';
                                    } elseif ($status === 'failed' || $status === 'rejected') {
                                        $statusClass = 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20';
                                    }
                                ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-950/40 transition-colors">
                                    <td class="p-3.5 font-mono text-emerald-600 dark:text-emerald-400 font-bold">
                                        #<?= htmlspecialchars($pay['reference_number'] ?? $pay['id'] ?? 'N/A') ?>
                                    </td>
                                    <td class="p-3.5 font-mono font-bold text-slate-900 dark:text-white">
                                        <?= htmlspecialchars($pay['plot_code'] ?? $pay['plot_identifier'] ?? 'N/A') ?>
                                    </td>
                                    <td class="p-3.5 font-mono font-bold text-slate-900 dark:text-white">
                                        ₱<?= number_format((float)($pay['amount'] ?? 0), 2) ?>
                                    </td>
                                    <td class="p-3.5 font-medium text-slate-700 dark:text-slate-300">
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-wallet text-slate-400 text-xs"></i>
                                            <span><?= htmlspecialchars($pay['payment_method'] ?? 'Online') ?></span>
                                        </div>
                                    </td>
                                    <td class="p-3.5">
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border inline-block <?= $statusClass ?>">
                                            <?= htmlspecialchars(ucfirst($status)) ?>
                                        </span>
                                    </td>
                                    <td class="p-3.5">
                                        <?php if (!empty($pay['receipt_path']) && file_exists($pay['receipt_path'])): ?>
                                            <button onclick="openReceiptModal('<?= htmlspecialchars($pay['receipt_path']) ?>')" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-[11px] font-medium transition-colors">
                                                <i class="fa-solid fa-file-invoice text-emerald-500"></i> View Proof
                                            </button>
                                        <?php else: ?>
                                            <span class="text-slate-400 dark:text-slate-600 text-[11px] italic">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 text-right text-slate-500 dark:text-slate-400 font-mono text-[11px] whitespace-nowrap">
                                        <?= !empty($pay['created_at']) ? date('d M Y, h:i A', strtotime($pay['created_at'])) : 'N/A' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- RECEIPT PREVIEW MODAL -->
    <div id="receiptModal" class="fixed inset-0 z-50 hidden bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl max-w-lg w-full p-5 shadow-2xl space-y-4">
            <div class="flex justify-between items-center border-b border-slate-200 dark:border-slate-800 pb-3">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <i class="fa-solid fa-image text-emerald-500"></i> Proof of Payment
                </h3>
                <button onclick="closeReceiptModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white text-lg">&times;</button>
            </div>
            <div class="flex justify-center bg-slate-100 dark:bg-slate-950 rounded-xl overflow-hidden p-2 min-h-[250px] items-center">
                <img id="receiptImage" src="" alt="Receipt Preview" class="max-h-[70vh] object-contain rounded-lg">
            </div>
        </div>
    </div>

    <script>
        // --- Dynamic Dark / Light Theme Switching ---
        const themeToggleBtn = document.getElementById('themeToggle');
        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', () => {
                const html = document.documentElement;
                if (html.classList.contains('dark')) {
                    html.classList.remove('dark');
                    localStorage.setItem('theme', 'light');
                } else {
                    html.classList.add('dark');
                    localStorage.setItem('theme', 'dark');
                }
            });
        }

        // --- Modal Control Functions ---
        function openReceiptModal(imagePath) {
            document.getElementById('receiptImage').src = imagePath;
            document.getElementById('receiptModal').classList.remove('hidden');
        }

        function closeReceiptModal() {
            document.getElementById('receiptModal').classList.add('hidden');
            document.getElementById('receiptImage').src = '';
        }
    </script>
</body>
</html>