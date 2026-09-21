<?php
require 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php?mode=login");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_layout') {
        try {
            $stmt = $pdo->prepare("DELETE FROM public.cemetery_layouts WHERE id = ?");
            $stmt->execute([$_POST['layout_id']]);
            $message = "Saved shape overlay deleted!";
        } catch (PDOException $e) { 
            $error = "Delete Error: " . $e->getMessage(); 
        }
    }
}

$layouts = [];
try {
    $layouts = $pdo->query("
        SELECT cl.*, cs.section_code, cs.section_name 
        FROM public.cemetery_layouts cl
        LEFT JOIN public.cemetery_sections cs ON cl.section_id = cs.id
        ORDER BY cl.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $layouts = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php if (function_exists('theme_head_script')) theme_head_script(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Saved Shapes - PlotBox GIS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="mobile.css">
    <script src="mobile.js" defer></script>
</head>
<body class="bg-slate-950 text-slate-100 h-screen flex font-sans overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="bg-slate-900 border-b border-slate-800 px-6 py-3 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-lg bg-slate-800 text-slate-300 hover:text-white transition" aria-label="Open menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h1 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-map text-amber-400"></i> Saved Shapes List
                </h1>
            </div>
        </header>

        <div class="p-6">
            <?php if ($message): ?><div class="mb-4 p-2 bg-emerald-950 border border-emerald-800 text-emerald-300 text-xs rounded"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="mb-4 p-2 bg-red-950 border border-red-800 text-red-300 text-xs rounded"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 shadow-xl">
                <span class="text-xs font-bold text-amber-400 uppercase tracking-wider block mb-3">Saved Boundaries & Section Shapes</span>
                <div class="space-y-2">
                    <?php foreach ($layouts as $lay): ?>
                        <div class="p-3 bg-slate-950 border border-slate-800 rounded flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <span class="w-4 h-4 rounded-full shrink-0" style="background-color: <?= htmlspecialchars($lay['color']) ?>"></span>
                                <div>
                                    <h5 class="font-bold text-sm text-white"><?= htmlspecialchars($lay['title']) ?></h5>
                                    <?php if (!empty($lay['section_code'])): ?>
                                        <span class="text-[10px] font-mono text-cyan-400 bg-cyan-950 border border-cyan-800 px-2 py-0.5 rounded">Sec: <?= htmlspecialchars($lay['section_code']) ?></span>
                                    <?php else: ?>
                                        <span class="text-[10px] font-mono text-red-400 bg-red-950 border border-red-800 px-2 py-0.5 rounded">Main Boundary</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form method="POST" action="admin_saved_shapes.php" onsubmit="return confirm('Delete layout shape?');">
                                <input type="hidden" name="action" value="delete_layout">
                                <input type="hidden" name="layout_id" value="<?= $lay['id'] ?>">
                                <button type="submit" class="text-red-400 hover:text-red-300 text-xs px-2 py-1"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle function for mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        }
    </script>
</body>
</html>