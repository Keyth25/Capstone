<?php
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $layouts = $pdo->query("
        SELECT cl.*, cs.section_code, cs.section_name 
        FROM public.cemetery_layouts cl
        LEFT JOIN public.cemetery_sections cs ON cl.section_id = cs.id
        ORDER BY cl.created_at DESC
    ")->fetchAll();
    
    echo json_encode($layouts);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}