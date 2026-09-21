<?php
// Copy this file to config.php and fill in your own credentials.
session_start();

// 1. Database Connection (PostgreSQL / Supabase pooler)
$host = 'your-db-host';
$port = '5432';
$db   = 'postgres';
$user = 'your-db-user';
$pass = 'your-db-password';

$dsn = "pgsql:host=$host;port=$port;dbname=$db";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// 2. Supabase API credentials
$supabase_url = 'https://your-project.supabase.co';
$supabase_anon_key = 'your-anon-key';
$supabase_service_role_key = 'your-service-role-key';

function theme_head_script() {
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    echo <<<THEME
<script>
(function () {
    const stored = localStorage.getItem('theme');
    const sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const dark = stored === 'dark' || (stored !== 'light' && sysDark);
    document.documentElement.classList.toggle('dark', dark);

    window.toggleTheme = function () {
        const nowDark = !document.documentElement.classList.contains('dark');
        document.documentElement.classList.toggle('dark', nowDark);
        localStorage.setItem('theme', nowDark ? 'dark' : 'light');
    };
})();
</script>
<style id="theme-overrides">
html:not(.dark) [class*="bg-slate-950"] { background-color: #faf5ff !important; }
html:not(.dark) [class*="bg-slate-900"] { background-color: #f3eaff !important; }
html:not(.dark) [class*="bg-slate-800"] { background-color: #e9d5ff !important; }
html:not(.dark) [class*="bg-slate-700"] { background-color: #ddd6fe !important; }
html:not(.dark) [class*="bg-slate-950/"] { background-color: rgba(250, 245, 255, 0.8) !important; }
html:not(.dark) [class*="bg-slate-900/"] { background-color: rgba(243, 234, 255, 0.8) !important; }
html:not(.dark) [class*="bg-slate-800/"] { background-color: rgba(233, 213, 255, 0.8) !important; }
html:not(.dark) [class*="bg-slate-700/"] { background-color: rgba(221, 214, 254, 0.8) !important; }

html:not(.dark) [class*="text-slate-100"] { color: #2e1065 !important; }
html:not(.dark) [class*="text-slate-200"] { color: #4c1d95 !important; }
html:not(.dark) [class*="text-slate-300"] { color: #5b21b6 !important; }
html:not(.dark) [class*="text-slate-400"] { color: #6d28d9 !important; }
html:not(.dark) [class*="text-slate-500"] { color: #7c3aed !important; }

html:not(.dark) [class*="bg-slate-"] [class*="text-white"],
html:not(.dark) [class*="bg-slate-"] [class*="text-slate-100"],
html:not(.dark) [class*="bg-slate-"] [class*="text-slate-200"] { color: #2e1065 !important; }

html:not(.dark) [class*="border-slate-800"] { border-color: #e9d5ff !important; }
html:not(.dark) [class*="border-slate-700"] { border-color: #c4b5fd !important; }
html:not(.dark) [class*="border-slate-900"] { border-color: #f3eaff !important; }
html:not(.dark) [class*="border-slate-"][class*="/"] { border-color: rgba(196, 181, 253, 0.6) !important; }

html:not(.dark) [class*="text-violet-300"] { color: #6d28d9 !important; }
html:not(.dark) [class*="text-violet-400"] { color: #7c3aed !important; }
html:not(.dark) [class*="text-emerald-400"] { color: #047857 !important; }
html:not(.dark) [class*="text-red-400"] { color: #dc2626 !important; }

html:not(.dark) #sidebar { background: linear-gradient(180deg, #faf5ff 0%, #f3eaff 100%) !important; border-color: rgba(139, 92, 246, 0.12) !important; }
html:not(.dark) .glass { background: rgba(250, 245, 255, 0.85) !important; border-color: rgba(139, 92, 246, 0.12) !important; }
html:not(.dark) .orb-1 { background: linear-gradient(135deg, #c4b5fd, #f0abfc) !important; opacity: 0.4 !important; }
html:not(.dark) .orb-2 { background: linear-gradient(135deg, #ddd6fe, #c4b5fd) !important; opacity: 0.4 !important; }
html:not(.dark) .activity-item:hover { background-color: rgba(196, 181, 253, 0.5) !important; }

html:not(.dark) [class*="hover:bg-slate-700"]:hover { background-color: #c4b5fd !important; }
html:not(.dark) [class*="hover:bg-slate-800"]:hover { background-color: #e9d5ff !important; }
html:not(.dark) [class*="hover:bg-slate-900"]:hover { background-color: #f3eaff !important; }
html:not(.dark) a[href="logout.php"][class*="text-red-400"] { color: #7c3aed !important; }
html:not(.dark) a[href="logout.php"][class*="text-red-400"]:hover { color: #6d28d9 !important; background-color: rgba(139, 92, 246, 0.08) !important; }
html:not(.dark) a[href="logout.php"][class*="text-red-400"] .w-9 { background-color: rgba(139, 92, 246, 0.15) !important; color: #7c3aed !important; }
html:not(.dark) a[href="logout.php"][class*="text-red-400"]:hover .w-9 { background-color: rgba(139, 92, 246, 0.25) !important; }
</style>
THEME;
}
