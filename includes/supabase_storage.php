<?php
// Uploads a file to Supabase Storage and returns its public URL, or null on failure.
// Uses $supabase_url and $supabase_service_role_key from config.php.
// Files uploaded from the phone are handled by the Render deployment, so storing
// them on the local filesystem leaves broken links everywhere else — Supabase
// Storage is reachable from localhost, Render, and the app alike.

function supabase_storage_upload(string $tmp_path, string $object_name, string $bucket = 'maintenance-photos'): ?string
{
    global $supabase_url, $supabase_service_role_key;
    if (empty($supabase_url) || empty($supabase_service_role_key) || !is_file($tmp_path)) {
        return null;
    }

    $base = rtrim($supabase_url, '/');
    $auth_headers = [
        'apikey: ' . $supabase_service_role_key,
        'Authorization: Bearer ' . $supabase_service_role_key,
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = $finfo ? finfo_file($finfo, $tmp_path) : false;
    if ($finfo) {
        finfo_close($finfo);
    }

    $send = function () use ($base, $bucket, $object_name, $auth_headers, $mime_type, $tmp_path) {
        $ch = curl_init($base . '/storage/v1/object/' . $bucket . '/' . $object_name);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge($auth_headers, [
                'Content-Type: ' . ($mime_type ?: 'application/octet-stream'),
                'x-upsert: true',
            ]),
            CURLOPT_POSTFIELDS => file_get_contents($tmp_path),
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, $body];
    };

    [$code, $body] = $send();

    // Auto-create the bucket as public on first use, then retry once.
    if ($code === 400 || $code === 404) {
        $ch = curl_init($base . '/storage/v1/bucket');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge($auth_headers, ['Content-Type: application/json']),
            CURLOPT_POSTFIELDS => json_encode(['id' => $bucket, 'name' => $bucket, 'public' => true]),
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($ch);
        curl_close($ch);
        [$code, $body] = $send();
    }

    if ($code >= 200 && $code < 300) {
        return $base . '/storage/v1/object/public/' . $bucket . '/' . $object_name;
    }

    error_log('Supabase storage upload failed (HTTP ' . $code . '): ' . $body);
    return null;
}
