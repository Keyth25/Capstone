<?php
// Serve the latest debug APK with a friendly filename for direct download
$apkPath = __DIR__ . '/apk/MatutumPlotNav.apk';

if (!file_exists($apkPath)) {
    http_response_code(404);
    echo 'APK not found. Build it with: npm run build-apk, then copy it to apk/MatutumPlotNav.apk';
    exit;
}

$filename = 'MatutumPlotNav.apk';
$mime = 'application/vnd.android.package-archive';
$size = filesize($apkPath);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $size);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($apkPath);
exit;
