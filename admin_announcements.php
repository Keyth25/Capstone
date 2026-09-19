<?php
require 'config.php';

// Access Control
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$error = '';

// Ensure announcements table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS public.announcements (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message TEXT,
            audience VARCHAR(20) DEFAULT 'all',
            start_date DATE,
            end_date DATE,
            is_active BOOLEAN DEFAULT true,
            created_by VARCHAR(255),
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");
} catch (PDOException $e) {
    $error = "Table setup error: " . $e->getMessage();
}

// Helper for PostgreSQL booleans
function isAnnActive($v) {
    return filter_var($v ?? true, FILTER_VALIDATE_BOOLEAN);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && empty($error)) {

    // Save (create or update)
    if ($_POST['action'] === 'save_announcement') {
        $id = trim($_POST['announcement_id'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $msg = trim($_POST['message'] ?? '');
        $audience = trim($_POST['audience'] ?? 'all');
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $is_active = isset($_POST['is_active']) ? true : false;
        $created_by = $_SESSION['name'] ?? 'Admin';

        if (empty($title) || empty($msg)) {
            $error = "Title and message are required.";
        } else {
            try {
                if ($id !== '') {
                    $stmt = $pdo->prepare("
                        UPDATE public.announcements 
                        SET title = ?, message = ?, audience = ?, start_date = ?, end_date = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$title, $msg, $audience, $start_date, $end_date, $is_active, $id]);
                    $message = "Announcement updated!";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO public.announcements (title, message, audience, start_date, end_date, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$title, $msg, $audience, $start_date, $end_date, $is_active, $created_by]);
                    $message = "Announcement published!";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    // Delete
    if ($_POST['action'] === 'delete_announcement') {
        $id = trim($_POST['announcement_id'] ?? '');
        if ($id !== '') {
            try {
                $stmt = $pdo->prepare("DELETE FROM public.announcements WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Announcement deleted!";
            } catch (PDOException $e) {
                $error = "Delete error: " . $e->getMessage();
            }
        }
    }
}

// Fetch all announcements
$announcements = [];
try {
    $announcements = $pdo->query("SELECT * FROM public.announcements ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $announcements = [];
}

// Load for edit
$edit = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM public.announcements WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $edit = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - PlotBox GIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-950 text-slate-100 h-screen flex font-sans overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="bg-slate-900 border-b border-slate-800 px-6 py-3 flex items-center justify-between shrink-0">
            <h1 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-bullhorn text-cyan-400"></i> Announcement Management
            </h1>
        </header>

        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6 overflow-y-auto">

            <!-- Form Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-xl space-y-3">
                <span class="text-xs font-bold text-cyan-400 uppercase tracking-wider block"><?= $edit ? 'Edit' : 'New' ?> Announcement</span>

                <?php if ($message): ?>
                    <div class="p-2 bg-emerald-950 border border-emerald-800 text-emerald-300 text-xs rounded"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="p-2 bg-red-950 border border-red-800 text-red-300 text-xs rounded"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="space-y-3">
                    <input type="hidden" name="action" value="save_announcement">
                    <input type="hidden" name="announcement_id" value="<?= htmlspecialchars($edit['id'] ?? '') ?>">

                    <div>
                        <label class="text-[10px] text-slate-400 block mb-1">Title</label>
                        <input type="text" name="title" required value="<?= htmlspecialchars($edit['title'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-700 rounded p-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                    </div>

                    <div>
                        <label class="text-[10px] text-slate-400 block mb-1">Message</label>
                        <textarea name="message" required rows="4" class="w-full bg-slate-950 border border-slate-700 rounded p-2 text-xs text-white focus:outline-none focus:border-cyan-500"><?= htmlspecialchars($edit['message'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label class="text-[10px] text-slate-400 block mb-1">Audience</label>
                        <select name="audience" class="w-full bg-slate-950 border border-slate-700 rounded p-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                            <option value="all" <?= (($edit['audience'] ?? 'all') === 'all') ? 'selected' : '' ?>>All Users</option>
                            <option value="user" <?= (($edit['audience'] ?? 'all') === 'user') ? 'selected' : '' ?>>Users Only</option>
                            <option value="admin" <?= (($edit['audience'] ?? 'all') === 'admin') ? 'selected' : '' ?>>Admins Only</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[10px] text-slate-400 block mb-1">Start Date</label>
                            <input type="date" name="start_date" value="<?= htmlspecialchars($edit['start_date'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-700 rounded p-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                            <p class="text-[9px] text-slate-500 mt-1">Leave blank to show the announcement immediately.</p>
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-400 block mb-1">End Date</label>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($edit['end_date'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-700 rounded p-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                            <p class="text-[9px] text-slate-500 mt-1">Leave blank to keep it active forever.</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="is_active" id="is_active" value="1" <?= (isAnnActive($edit['is_active'] ?? true) ? 'checked' : '') ?> class="rounded bg-slate-950 border-slate-700 text-cyan-500">
                        <label for="is_active" class="text-[10px] text-slate-400">Active</label>
                    </div>

                    <button type="submit" class="w-full py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-bold text-xs rounded transition">
                        <?= $edit ? 'Update' : 'Publish' ?> Announcement
                    </button>
                </form>
            </div>

            <!-- List Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-xl flex flex-col">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-3">Current Announcements</span>
                <div class="space-y-2 overflow-y-auto flex-1 pr-1">
                    <?php if (empty($announcements)): ?>
                        <div class="text-xs text-slate-500 italic py-4 text-center">No announcements yet.</div>
                    <?php else: ?>
                        <?php foreach ($announcements as $a): ?>
                            <div class="p-3 bg-slate-950 border border-slate-800 rounded-lg flex justify-between items-start">
                                <div class="flex-1 min-w-0">
                                    <h5 class="font-bold text-xs text-white"><?= htmlspecialchars($a['title']) ?></h5>
                                    <p class="text-[10px] text-slate-400 mt-1"><?= htmlspecialchars($a['message']) ?></p>
                                    <div class="flex flex-wrap gap-2 mt-2">
                                        <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-800 text-slate-400"><?= htmlspecialchars($a['audience']) ?></span>
                                        <span class="text-[9px] px-1.5 py-0.5 rounded <?= isAnnActive($a['is_active']) ? 'bg-emerald-950 text-emerald-300 border border-emerald-800' : 'bg-slate-800 text-slate-400' ?>">
                                            <?= isAnnActive($a['is_active']) ? 'Active' : 'Inactive' ?>
                                        </span>
                                        <?php if (!empty($a['end_date']) && strtotime($a['end_date']) < strtotime('today')): ?>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-red-950 text-red-300 border border-red-800">Expired</span>
                                        <?php endif; ?>
                                        <?php if (!empty($a['start_date']) && strtotime($a['start_date']) > strtotime('today')): ?>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-amber-950 text-amber-300 border border-amber-800">Scheduled</span>
                                        <?php endif; ?>
                                        <?php if (!empty($a['start_date'])): ?>
                                            <span class="text-[9px] text-slate-500"><?= htmlspecialchars($a['start_date']) ?> to <?= htmlspecialchars($a['end_date'] ?? 'no end') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex gap-1 ml-2">
                                    <a href="?edit=<?= htmlspecialchars($a['id']) ?>" class="text-cyan-400 hover:text-cyan-300 text-xs px-2 py-1"><i class="fa-solid fa-pen"></i></a>
                                    <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" onsubmit="return confirm('Delete this announcement?');" class="inline">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="announcement_id" value="<?= htmlspecialchars($a['id']) ?>">
                                        <button type="submit" class="text-red-400 hover:text-red-300 text-xs px-2 py-1"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        }
    </script>
</body>
</html>
