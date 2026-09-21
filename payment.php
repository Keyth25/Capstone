<?php
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$payment_success = '';
$payment_error = '';

// Pre-selected plot code from URL query (e.g. payment.php?plot=ROSE-12)
$preselected_plot = trim($_GET['plot'] ?? '');

// Ensure payments table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public.payments (
            id SERIAL PRIMARY KEY,
            reservation_id INT NOT NULL,
            user_id INT NOT NULL,
            plot_code VARCHAR(100) NOT NULL,
            amount NUMERIC(10,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            reference_number VARCHAR(100) NOT NULL,
            status VARCHAR(30) DEFAULT 'pending',
            receipt_path VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // Table already exists or handle exception silently
}

// -----------------------------------------------------------------------------
// POST HANDLER: Submit Payment
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_payment') {
    $reservation_id = (int)($_POST['reservation_id'] ?? 0);
    $plot_code = trim($_POST['plot_code'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'GCash');
    $reference_number = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Handle Receipt Upload
    $receipt_path = null;
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['receipt']['tmp_name'];
        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', $_FILES['receipt']['name']);
        $upload_dir = 'uploads/receipts/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $target_file = $upload_dir . $file_name;
        if (move_uploaded_file($file_tmp, $target_file)) {
            $receipt_path = $target_file;
        }
    }

    if (empty($plot_code) || $amount <= 0 || empty($reference_number)) {
        $payment_error = "Please fill in all required payment details.";
    } else {
        try {
            // Verify this reservation is a down payment and the amount matches
            $stmt = $pdo->prepare("
                SELECT reservation_fee, amount_paid, payment_option
                FROM public.reservations
                WHERE id = ? AND user_id = ? AND payment_option = 'Down Payment'
            ");
            $stmt->execute([$reservation_id, $user_id]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reservation) {
                $payment_error = "Only down payment reservations can be paid through this portal.";
            } else {
                $reservation_fee = (float)$reservation['reservation_fee'];
                $amount_paid = (float)$reservation['amount_paid'];
                $down_payment = $amount_paid > 0 ? $amount_paid : $reservation_fee * 0.30;
                $remaining = max(0, $reservation_fee - $down_payment);
                $monthly = $remaining > 0 ? $remaining / 12 : 0;

                if ($amount < $monthly - 0.01 || $amount > $remaining + 0.01) {
                    $payment_error = "Payment amount must be at least the monthly payment of ₱" . number_format($monthly, 2) . " and not exceed the remaining balance of ₱" . number_format($remaining, 2) . ".";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO public.payments 
                        (reservation_id, user_id, plot_code, amount, payment_method, reference_number, receipt_path, notes, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([
                        $reservation_id,
                        $user_id,
                        $plot_code,
                        $amount,
                        $payment_method,
                        $reference_number,
                        $receipt_path,
                        $notes
                    ]);

                    $payment_success = "Payment submission received! Your reference #{$reference_number} is pending administrator verification.";
                }
            }
        } catch (PDOException $e) {
            $payment_error = "Payment recording failed: " . $e->getMessage();
        }
    }
}

// -----------------------------------------------------------------------------
// DATA FETCHING
// -----------------------------------------------------------------------------
$user_reservations = [];
$user_payments = [];

try {
    // Fetch User's Reservations for Payment Selection (down payment only)
    $stmt_res = $pdo->prepare("
        SELECT id, plot_code, status, created_at, reservation_fee, amount_paid, payment_option
        FROM public.reservations
        WHERE user_id = ? AND payment_option = 'Down Payment'
        ORDER BY created_at DESC
    ");
    $stmt_res->execute([$user_id]);
    $user_reservations = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

    // Fetch User's Payment History
    $stmt_pay = $pdo->prepare("
        SELECT * FROM public.payments 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt_pay->execute([$user_id]);
    $user_payments = $stmt_pay->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Handle error
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Payment Portal - Mount Matutum Memorial Park</title>

    <!-- Dynamic Theme System Header Script -->
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
    <script src="mobile.js" defer></script>
    <script src="announcement_live.js" defer></script>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col transition-colors duration-200">

    <!-- HEADER BAR -->
    <header class="sticky top-0 z-40 w-full backdrop-blur-md bg-white/80 dark:bg-slate-900/80 border-b border-slate-200 dark:border-slate-800 transition-colors">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-emerald-500/10 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 rounded-xl flex items-center justify-center font-black text-lg border border-emerald-500/20">
                    <i class="fa-solid fa-credit-card"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-black text-slate-900 dark:text-white tracking-tight text-lg">PLOT<span class="text-emerald-600 dark:text-emerald-400">PAY</span></span>
                        <span class="bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold px-2 py-0.5 rounded-full border border-emerald-200 dark:border-emerald-800 uppercase tracking-wide">Portal</span>
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
                    <i class="fa-solid fa-map-location-dot text-emerald-500"></i>
                    <span>GIS Map</span>
                </a>

                <a href="reservation.php" class="inline-flex items-center gap-2 text-xs font-semibold px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white shadow-sm transition-all">
                    <i class="fa-solid fa-bookmark"></i>
                    <span>Reservations</span>
                </a>

                <div class="h-4 w-px bg-slate-200 dark:bg-slate-800 mx-1 hidden sm:block"></div>

                <a href="logout.php" class="p-2 sm:px-3 sm:py-2 rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 hover:bg-red-500 hover:text-white dark:hover:bg-red-600 transition-all text-xs font-semibold flex items-center gap-1.5">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span class="hidden sm:inline">Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- CONTENT BODY -->
    <main class="flex-1 p-4 md:p-6 max-w-7xl w-full mx-auto space-y-6">

        <?php if ($payment_success): ?>
            <div class="p-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-800 dark:text-emerald-300 text-xs rounded-2xl flex justify-between items-center shadow-sm backdrop-blur-sm">
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-circle-check text-emerald-500 text-base"></i> 
                    <?= htmlspecialchars($payment_success) ?>
                </span>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white text-base">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($payment_error): ?>
            <div class="p-4 bg-rose-500/10 border border-rose-500/20 text-rose-800 dark:text-rose-300 text-xs rounded-2xl flex justify-between items-center shadow-sm backdrop-blur-sm">
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation text-rose-500 text-base"></i> 
                    <?= htmlspecialchars($payment_error) ?>
                </span>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white text-base">&times;</button>
            </div>
        <?php endif; ?>

        <!-- MAIN LAYOUT GRID -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- LEFT FORM: SUBMIT PAYMENT (5 COLS) -->
            <div class="lg:col-span-5 space-y-4">
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 rounded-2xl shadow-sm dark:shadow-2xl space-y-5">
                    <div class="border-b border-slate-200 dark:border-slate-800 pb-4">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-cash-register text-emerald-500"></i> Submit New Payment
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Select a reserved plot and upload your transaction details.</p>
                    </div>

                    <form method="POST" action="payment.php" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="submit_payment">

                        <!-- Select Reservation -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Target Plot Reservation *</label>
                            <div class="relative">
                                <select name="reservation_id" id="reservation_select" required onchange="updatePlotCode(this)" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-3 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 font-medium transition-all appearance-none">
                                    <option value="">-- Select Reserved Plot --</option>
                                    <?php
                                    foreach ($user_reservations as $res):
                                        $reservation_fee = (float)$res['reservation_fee'];
                                        $amount_paid = (float)$res['amount_paid'];
                                        $down_payment = $amount_paid > 0 ? $amount_paid : $reservation_fee * 0.30;
                                        $remaining = max(0, $reservation_fee - $down_payment);
                                        $monthly_payment = $remaining > 0 ? $remaining / 12 : 0;
                                    ?>
                                        <option value="<?= $res['id'] ?>" data-plot="<?= htmlspecialchars($res['plot_code']) ?>" data-amount="<?= number_format($down_payment, 2, '.', '') ?>" data-monthly="<?= number_format($monthly_payment, 2, '.', '') ?>" data-remaining="<?= number_format($remaining, 2, '.', '') ?>" <?= strcasecmp($preselected_plot, $res['plot_code']) === 0 ? 'selected' : '' ?>>
                                            Plot: <?= htmlspecialchars($res['plot_code']) ?> (<?= ucfirst($res['status']) ?>) — Down Payment: ₱<?= number_format($down_payment, 2) ?> — Monthly: ₱<?= number_format($monthly_payment, 2) ?>/mo
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
                            </div>
                            <input type="hidden" name="plot_code" id="hidden_plot_code" value="<?= htmlspecialchars($preselected_plot) ?>">
                        </div>

                        <!-- Amount & Method -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Down Payment Amount (PHP) *</label>
                                <div class="relative">
                                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-xs">₱</span>
                                    <input type="number" step="0.01" name="amount" id="payment_amount" required placeholder="0.00" class="w-full pl-7 pr-3 py-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-all">
                                </div>
                                <div class="mt-2 p-2.5 bg-slate-100 dark:bg-slate-950/50 border border-slate-200 dark:border-slate-800 rounded-xl space-y-1">
                                    <p class="text-[10px] text-slate-500 dark:text-slate-400 flex justify-between">
                                        <span>Down payment paid:</span>
                                        <span id="display-dp" class="font-bold text-slate-700 dark:text-slate-200">₱0.00</span>
                                    </p>
                                    <p class="text-[10px] text-slate-500 dark:text-slate-400 flex justify-between">
                                        <span>Monthly for 12 months:</span>
                                        <span id="display-monthly" class="font-bold text-cyan-600 dark:text-cyan-400">₱0.00</span>
                                    </p>
                                </div>
                            </div>

                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Payment Method *</label>
                                <div class="relative">
                                    <select name="payment_method" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 font-medium transition-all appearance-none">
                                        <option value="GCash">GCash</option>
                                        <option value="Maya">Maya</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                        <option value="Over-the-Counter">Over-the-Counter</option>
                                    </select>
                                    <i class="fa-solid fa-chevron-down absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Reference Number -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Reference / Transaction # *</label>
                            <input type="text" name="reference_number" required placeholder="e.g. 10029384812" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2.5 text-xs font-mono font-bold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-all">
                        </div>

                        <!-- Optional Receipt Upload -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Attach Proof of Payment</label>
                            <input type="file" name="receipt" accept="image/*,.pdf" class="w-full text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-slate-100 dark:file:bg-slate-800 file:text-slate-700 dark:file:text-slate-200 hover:file:bg-slate-200 dark:hover:file:bg-slate-700 transition-all">
                        </div>

                        <!-- Notes -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Remarks / Notes</label>
                            <textarea name="notes" rows="2" placeholder="Additional payment details..." class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl p-2.5 text-xs text-slate-900 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-all"></textarea>
                        </div>

                        <button type="submit" class="w-full py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs rounded-xl shadow-md hover:shadow-lg transition-all flex items-center justify-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i>
                            <span>Submit Payment Receipt</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- RIGHT TABLE: PAYMENT HISTORY (7 COLS) -->
            <div class="lg:col-span-7 space-y-4">
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 rounded-2xl shadow-sm dark:shadow-2xl space-y-5">
                    <div class="border-b border-slate-200 dark:border-slate-800 pb-4 flex justify-between items-center">
                        <div>
                            <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-receipt text-emerald-500"></i> Payment History
                            </h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Track status and verification of submitted receipts.</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800 text-[10px] font-bold uppercase text-slate-400 bg-slate-50 dark:bg-slate-950/50">
                                    <th class="p-3 rounded-l-xl">Date</th>
                                    <th class="p-3">Plot</th>
                                    <th class="p-3">Amount</th>
                                    <th class="p-3">Method / Ref</th>
                                    <th class="p-3 text-center rounded-r-xl">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                                <?php if (empty($user_payments)): ?>
                                    <tr>
                                        <td colspan="5" class="py-8 text-center text-slate-400 italic">No payment records submitted yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($user_payments as $pay): ?>
                                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-950/40 transition-colors">
                                            <td class="p-3 text-slate-500 dark:text-slate-400 whitespace-nowrap"><?= date('d M Y', strtotime($pay['created_at'])) ?></td>
                                            <td class="p-3 font-mono font-bold text-emerald-600 dark:text-emerald-400"><?= htmlspecialchars($pay['plot_code']) ?></td>
                                            <td class="p-3 font-mono font-bold text-slate-900 dark:text-white">₱<?= number_format($pay['amount'], 2) ?></td>
                                            <td class="p-3 text-slate-700 dark:text-slate-300">
                                                <div class="font-bold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($pay['payment_method']) ?></div>
                                                <div class="text-[10px] font-mono text-slate-400 dark:text-slate-500"><?= htmlspecialchars($pay['reference_number']) ?></div>
                                            </td>
                                            <td class="p-3 text-center">
                                                <?php
                                                    $status = strtolower($pay['status']);
                                                    $badgeClass = 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20';
                                                    if ($status === 'verified' || $status === 'approved') {
                                                        $badgeClass = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20';
                                                    } elseif ($status === 'rejected' || $status === 'failed') {
                                                        $badgeClass = 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20';
                                                    }
                                                ?>
                                                <span class="px-2.5 py-1 rounded-full border text-[10px] font-bold uppercase tracking-wide <?= $badgeClass ?>">
                                                    <?= htmlspecialchars($pay['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

    </main>

    <script>
        // --- Dark/Light Mode Theme Toggle ---
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

        // --- Plot Code & Down Payment Auto-selection ---
        function updatePlotCode(selectEl) {
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            const plotCode = selectedOption.getAttribute('data-plot') || '';
            const amount = selectedOption.getAttribute('data-amount') || '';
            const monthly = selectedOption.getAttribute('data-monthly') || '';
            const remaining = selectedOption.getAttribute('data-remaining') || '';
            document.getElementById('hidden_plot_code').value = plotCode;
            const amountInput = document.getElementById('payment_amount');
            if (amountInput) {
                amountInput.value = monthly;
                amountInput.min = monthly;
                amountInput.max = remaining;
            }
            const dpEl = document.getElementById('display-dp');
            const monthlyEl = document.getElementById('display-monthly');
            if (dpEl) dpEl.innerText = '₱' + (parseFloat(amount) || 0).toFixed(2);
            if (monthlyEl) monthlyEl.innerText = '₱' + (parseFloat(monthly) || 0).toFixed(2);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const selectEl = document.getElementById('reservation_select');
            if (selectEl && selectEl.value) {
                updatePlotCode(selectEl);
            }
        });
    </script>
</body>
</html>