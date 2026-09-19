<?php
require 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
init_user_notifications_table($pdo);

$action = $_REQUEST['action'] ?? 'get';

if ($action === 'get') {
    $stmt = $pdo->prepare("
        SELECT id, title, message, type, related_id, is_read, created_at
        FROM public.user_notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$user_id]);
    echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
    exit;
}

if ($action === 'mark_read') {
    $id = intval($_REQUEST['id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE public.user_notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);
    echo json_encode(['status' => 'success', 'affected' => $stmt->rowCount()]);
    exit;
}

if ($action === 'mark_all') {
    $stmt = $pdo->prepare("UPDATE public.user_notifications SET is_read = TRUE WHERE user_id = ?");
    $stmt->execute([$user_id]);
    echo json_encode(['status' => 'success', 'affected' => $stmt->rowCount()]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
