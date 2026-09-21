<?php
require 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

try {
    $pdo->exec("
        ALTER TABLE public.payments
        ADD COLUMN IF NOT EXISTS verified_by VARCHAR(255),
        ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP
    ");
} catch (PDOException $e) {
    error_log("Payments table alter error: " . $e->getMessage());
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'accept_payment') {
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    $admin_id = $_SESSION['user_id'];

    try {
        $stmt = $pdo->prepare("
            UPDATE public.payments
            SET status = 'verified', verified_by = ?, verified_at = NOW()
            WHERE id = ? AND status != 'verified'
        ");
        $stmt->execute([$admin_id, $payment_id]);
        if ($stmt->rowCount() > 0) {
            $message = "Payment #{$payment_id} has been accepted.";
        } else {
            $error = "Payment was already accepted or not found.";
        }
    } catch (PDOException $e) {
        $error = "Failed to accept payment: " . $e->getMessage();
    }
}

$payments = [];
try {
    $stmt = $pdo->query("
        SELECT p.*,
               COALESCE(TRIM(COALESCE(pf.first_name, '') || ' ' || COALESCE(pf.last_name, '')), u.name) AS user_name,
               COALESCE(pf.email, u.email) AS user_email
        FROM public.payments p
        LEFT JOIN public.profiles pf ON p.user_id = pf.id::text
        LEFT JOIN public.users u ON p.user_id = u.id::text
        ORDER BY p.created_at DESC
    ");
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to load payments: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Payment Verifications - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .receipt-thumb { max-height: 60px; max-width: 80px; object-fit: cover; cursor: pointer; border-radius: 6px; border: 1px solid #334155; }
    </style>
    <link rel="stylesheet" href="mobile.css">
    <script src="mobile.js" defer></script>
</head>
<body class="bg-slate-950 text-slate-100 h-screen flex font-sans overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="bg-slate-900 border-b border-slate-800 px-6 py-4 flex items-center justify-between shrink-0 shadow-md">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg bg-slate-800 text-slate-300 hover:text-white transition" aria-label="Open menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <h1 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-money-bill-wave text-emerald-500"></i> Payment Verifications
                    </h1>
                    <p class="text-xs text-slate-400">Review uploaded payment receipts and accept verified submissions.</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs font-medium text-slate-300">Admin: <?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?></span>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-6 space-y-6">

            <?php if ($message): ?>
                <div class="p-3 bg-emerald-950 border border-emerald-800 text-emerald-300 text-xs rounded-lg shadow flex items-center gap-2">
                    <i class="fa-solid fa-circle-check text-emerald-400"></i> <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="p-3 bg-red-950 border border-red-800 text-red-300 text-xs rounded-lg shadow flex items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation text-red-400"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center justify-between shadow-lg">
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Pending</span>
                        <span class="text-2xl font-black text-amber-400 mt-1 block"><?= count(array_filter($payments, fn($p) => strtolower($p['status'] ?? '') === 'pending')) ?></span>
                    </div>
                    <div class="w-10 h-10 bg-amber-500/10 border border-amber-500/20 text-amber-400 rounded-lg flex items-center justify-center text-lg"><i class="fa-solid fa-clock"></i></div>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center justify-between shadow-lg">
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Verified</span>
                        <span class="text-2xl font-black text-emerald-400 mt-1 block"><?= count(array_filter($payments, fn($p) => strtolower($p['status'] ?? '') === 'verified')) ?></span>
                    </div>
                    <div class="w-10 h-10 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-lg flex items-center justify-center text-lg"><i class="fa-solid fa-check"></i></div>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center justify-between shadow-lg">
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Total Payments</span>
                        <span class="text-2xl font-black text-white mt-1 block"><?= count($payments) ?></span>
                    </div>
                    <div class="w-10 h-10 bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 rounded-lg flex items-center justify-center text-lg"><i class="fa-solid fa-receipt"></i></div>
                </div>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-emerald-400"></i> Payment Submissions
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-slate-800 text-slate-400 text-[10px] uppercase">
                                <th class="py-3 px-3">Reference</th>
                                <th class="py-3 px-3">User</th>
                                <th class="py-3 px-3">Plot</th>
                                <th class="py-3 px-3">Amount</th>
                                <th class="py-3 px-3">Method</th>
                                <th class="py-3 px-3">Receipt</th>
                                <th class="py-3 px-3">Status</th>
                                <th class="py-3 px-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="8" class="py-8 text-center text-slate-500 italic">No payment submissions found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $pay):
                                    $status = strtolower($pay['status'] ?? 'pending');
                                    $statusClass = 'bg-amber-950 text-amber-400 border border-amber-800';
                                    if ($status === 'verified' || $status === 'approved' || $status === 'completed') {
                                        $statusClass = 'bg-emerald-950 text-emerald-400 border border-emerald-800';
                                    } elseif ($status === 'rejected' || $status === 'failed') {
                                        $statusClass = 'bg-red-950 text-red-400 border border-red-800';
                                    }
                                    $receipt = $pay['receipt_path'] ?? $pay['receipt_image'] ?? null;
                                    $isImage = false;
                                    if ($receipt) {
                                        $ext = strtolower(pathinfo($receipt, PATHINFO_EXTENSION));
                                        $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']);
                                    }
                                ?>
                                    <tr class="hover:bg-slate-800/40 transition">
                                        <td class="py-3 px-3">
                                            <div class="font-mono font-semibold text-cyan-400">#<?= htmlspecialchars($pay['reference_number']) ?></div>
                                            <div class="text-[10px] text-slate-500 font-mono"><?= !empty($pay['created_at']) ? date('d M Y', strtotime($pay['created_at'])) : 'N/A' ?></div>
                                        </td>
                                        <td class="py-3 px-3">
                                            <div class="font-bold text-white"><?= htmlspecialchars($pay['user_name'] ?? 'Unknown') ?></div>
                                            <div class="text-[10px] text-slate-400"><?= htmlspecialchars($pay['user_email'] ?? '') ?></div>
                                        </td>
                                        <td class="py-3 px-3 font-mono text-white"><?= htmlspecialchars($pay['plot_code'] ?? $pay['plot_identifier'] ?? 'N/A') ?></td>
                                        <td class="py-3 px-3 font-mono font-bold text-white">₱<?= number_format((float)($pay['amount'] ?? 0), 2) ?></td>
                                        <td class="py-3 px-3 text-slate-300 uppercase"><?= htmlspecialchars($pay['payment_method']) ?></td>
                                        <td class="py-3 px-3">
                                            <?php if ($receipt): ?>
                                                <?php if ($isImage): ?>
                                                    <img src="<?= htmlspecialchars($receipt) ?>" alt="Receipt" class="receipt-thumb" onclick="openReceiptModal('<?= htmlspecialchars($receipt) ?>')">
                                                <?php else: ?>
                                                    <a href="<?= htmlspecialchars($receipt) ?>" target="_blank" class="text-[10px] text-cyan-400 hover:underline flex items-center gap-1">
                                                        <i class="fa-solid fa-file-invoice"></i> View
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-[10px] text-slate-500 italic">No upload</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase <?= $statusClass ?>">
                                                <?= htmlspecialchars($pay['status'] ?? 'Pending') ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3 text-right">
                                            <?php if ($status !== 'verified'): ?>
                                                <button type="button" onclick="openReviewModal(<?= (int)$pay['id'] ?>)" class="bg-amber-600 hover:bg-amber-500 text-white text-[10px] font-bold px-2.5 py-1.5 rounded transition">
                                                    <i class="fa-solid fa-eye"></i> Review
                                                </button>
                                            <?php else: ?>
                                                <span class="text-[10px] text-slate-500">
                                                    Accepted<?= !empty($pay['verified_at']) ? ' ' . date('d M Y', strtotime($pay['verified_at'])) : '' ?>
                                                </span>
                                            <?php endif; ?>
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

    <div id="receiptModal" class="fixed inset-0 bg-slate-950/90 backdrop-blur-sm z-[10000] hidden flex items-center justify-center p-4">
        <div class="relative max-w-3xl w-full">
            <button onclick="closeReceiptModal()" class="absolute -top-8 right-0 text-slate-400 hover:text-white text-xs">
                <i class="fa-solid fa-xmark"></i> Close
            </button>
            <img id="receiptFullImage" src="" alt="Full receipt" class="w-full max-h-[80vh] object-contain rounded-lg border border-slate-700 shadow-2xl">
        </div>
    </div>

    <div id="reviewModal" class="fixed inset-0 bg-slate-950/90 backdrop-blur-sm z-[10001] hidden flex items-center justify-center p-4">
        <div class="relative bg-slate-900 border border-slate-700 rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6">
            <button type="button" onclick="closeReviewModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white text-sm transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <h2 class="text-sm font-bold text-white uppercase tracking-wider mb-4 flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass text-amber-400"></i> Review Payment
            </h2>
            <div class="space-y-3 text-xs">
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-slate-950 border border-slate-800 p-3 rounded">
                        <div class="text-slate-500 uppercase text-[10px] font-bold">Reference</div>
                        <div id="reviewReference" class="font-mono text-cyan-400 font-bold text-sm"></div>
                    </div>
                    <div class="bg-slate-950 border border-slate-800 p-3 rounded">
                        <div class="text-slate-500 uppercase text-[10px] font-bold">Status</div>
                        <div id="reviewStatus"></div>
                    </div>
                    <div class="bg-slate-950 border border-slate-800 p-3 rounded">
                        <div class="text-slate-500 uppercase text-[10px] font-bold">Full Name</div>
                        <div id="reviewFullName" class="font-bold text-white text-sm"></div>
                    </div>
                    <div class="bg-slate-950 border border-slate-800 p-3 rounded">
                        <div class="text-slate-500 uppercase text-[10px] font-bold">User Account</div>
                        <div id="reviewUserAccount" class="font-mono text-slate-300 text-sm"></div>
                    </div>
                    <div class="bg-slate-950 border border-slate-800 p-3 rounded">
                        <div class="text-slate-500 uppercase text-[10px] font-bold">Plot</div>
                        <div id="reviewPlot" class="font-mono text-white text-sm"></div>
                    </div>
                    <div class="bg-slate-950 border border-slate-800 p-3 rounded">
                        <div class="text-slate-500 uppercase text-[10px] font-bold">Amount</div>
                        <div id="reviewAmount" class="font-mono font-bold text-emerald-400 text-sm"></div>
                    </div>
                    <div class="bg-slate-950 border border-slate-800 p-3 rounded col-span-2">
                        <div class="text-slate-500 uppercase text-[10px] font-bold">Method</div>
                        <div id="reviewMethod" class="uppercase text-slate-300 text-sm"></div>
                    </div>
                </div>
                <div class="bg-slate-950 border border-slate-800 p-3 rounded">
                    <div class="text-slate-500 uppercase text-[10px] font-bold mb-1">Notes</div>
                    <div id="reviewNotes" class="text-slate-300 leading-relaxed"></div>
                </div>
                <div class="bg-slate-950 border border-slate-800 p-3 rounded">
                    <div class="text-slate-500 uppercase text-[10px] font-bold mb-2">Receipt / Proof</div>
                    <div id="reviewReceiptContainer" class="flex items-center justify-center min-h-[120px]"></div>
                </div>
            </div>
            <div class="mt-5 flex items-center justify-end gap-2">
                <button type="button" onclick="closeReviewModal()" class="px-4 py-2 rounded text-xs font-bold bg-slate-800 hover:bg-slate-700 text-slate-300 transition">Cancel</button>
                <form method="POST" action="" class="inline" onsubmit="closeReviewModal()">
                    <input type="hidden" name="action" value="accept_payment">
                    <input type="hidden" name="payment_id" id="reviewPaymentId" value="">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold px-4 py-2 rounded transition">
                        <i class="fa-solid fa-check"></i> Accept Payment
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const paymentsData = <?= json_encode($payments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        function openReceiptModal(src) {
            document.getElementById('receiptFullImage').src = src;
            document.getElementById('receiptModal').classList.remove('hidden');
        }
        function closeReceiptModal() {
            document.getElementById('receiptModal').classList.add('hidden');
        }

        function openReviewModal(paymentId) {
            const payment = paymentsData.find(p => parseInt(p.id) === paymentId);
            if (!payment) return;

            document.getElementById('reviewPaymentId').value = payment.id;
            document.getElementById('reviewReference').textContent = '#' + (payment.reference_number || '');
            document.getElementById('reviewFullName').textContent = payment.user_name || 'Unknown';
            document.getElementById('reviewUserAccount').textContent = payment.user_email || 'N/A';
            document.getElementById('reviewPlot').textContent = payment.plot_code || payment.plot_identifier || 'N/A';
            document.getElementById('reviewAmount').textContent = '₱' + parseFloat(payment.amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('reviewMethod').textContent = payment.payment_method || 'N/A';
            document.getElementById('reviewNotes').textContent = payment.notes || 'No additional notes.';

            const status = (payment.status || 'pending').toLowerCase();
            let statusHtml = '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-amber-950 text-amber-400 border border-amber-800">Pending Review</span>';
            if (status === 'verified' || status === 'approved' || status === 'completed') {
                statusHtml = '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-950 text-emerald-400 border border-emerald-800">Verified</span>';
            } else if (status === 'rejected' || status === 'failed') {
                statusHtml = '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-red-950 text-red-400 border border-red-800">Rejected</span>';
            }
            document.getElementById('reviewStatus').innerHTML = statusHtml;

            const receipt = payment.receipt_path || payment.receipt_image || null;
            const container = document.getElementById('reviewReceiptContainer');
            if (receipt) {
                const ext = receipt.split('.').pop().toLowerCase();
                const isImage = ['jpg','jpeg','png','gif','webp','bmp'].includes(ext);
                if (isImage) {
                    container.innerHTML = `<img src="${receipt}" alt="Receipt" class="max-h-[40vh] object-contain rounded border border-slate-700 cursor-pointer" onclick="openReceiptModal('${receipt}')">`;
                } else {
                    container.innerHTML = `<a href="${receipt}" target="_blank" class="text-cyan-400 hover:underline text-sm flex items-center gap-2"><i class="fa-solid fa-file-invoice"></i> View attached receipt</a>`;
                }
            } else {
                container.innerHTML = '<span class="text-slate-500 italic text-sm">No receipt uploaded.</span>';
            }

            document.getElementById('reviewModal').classList.remove('hidden');
        }

        function closeReviewModal() {
            document.getElementById('reviewModal').classList.add('hidden');
        }
    </script>
</body>
</html>
