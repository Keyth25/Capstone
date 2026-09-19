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
