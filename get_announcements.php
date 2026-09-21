<?php
require 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// Admins can see admin-targeted announcements, everyone else sees user-targeted ones
$role = $_SESSION['role'] ?? 'user';
$audience = ($role === 'admin') ? 'admin' : 'user';

try {
    $stmt = $pdo->prepare("
        SELECT id, title, message, audience, start_date, end_date, is_active, created_at, updated_at
        FROM public.announcements
        WHERE audience IN ('all', ?)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$audience]);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as &$a) {
        $a['id'] = (int) $a['id'];
        $a['is_active'] = filter_var($a['is_active'], FILTER_VALIDATE_BOOLEAN);
    }
    unset($a);

    echo json_encode(['status' => 'success', 'data' => $announcements]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Fetch failed']);
}
