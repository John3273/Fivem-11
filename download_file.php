<?php
require_once __DIR__ . '/../app/auth.php';

$path = (string)($_GET['path'] ?? '');
if ($path === '' || strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false) {
  http_response_code(400);
  exit('Ugyldig filsti.');
}

$fullPath = dirname(__DIR__) . '/' . ltrim($path, '/');
if (!is_file($fullPath)) {
  http_response_code(404);
  exit('Fil ikke fundet.');
}

$filename = trim((string)($_GET['filename'] ?? ''));
if ($filename === '') $filename = basename($fullPath);
$filename = str_replace(["\r","\n",'"'], '', $filename);
header('Content-Description: File Transfer');
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
  $fi = finfo_open(FILEINFO_MIME_TYPE);
  if ($fi) {
    $detected = finfo_file($fi, $fullPath);
    if (is_string($detected) && $detected !== '') $mime = $detected;
    finfo_close($fi);
  }
}
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($fullPath);
exit;