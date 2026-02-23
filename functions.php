<?php
// app/functions.php
require_once __DIR__ . '/db.php';

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
  header("Location: $path");
  exit;
}

function now_date(): string {
  return date('Y-m-d');
}

function make_report_uid(string $prefix): string {
  return strtoupper($prefix) . "-" . strtoupper(bin2hex(random_bytes(4))) . "-" . time();
}

function save_upload(string $fieldName): ?string {
  // Returns relative path like "uploads/abc123.png" OR null if no file uploaded.
  $cfg = config()['uploads'];

  if (empty($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name'])) {
    return null;
  }

  if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
    throw new Exception("Upload error code: " . $_FILES[$fieldName]['error']);
  }

  if ($_FILES[$fieldName]['size'] > $cfg['max_bytes']) {
    throw new Exception("File too large");
  }

  $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $cfg['allowed_ext'], true)) {
    throw new Exception("File type not allowed");
  }

  $safeName = bin2hex(random_bytes(12)) . "." . $ext;
  $targetAbs = rtrim($cfg['dir'], '/\\') . DIRECTORY_SEPARATOR . $safeName;

  if (!is_dir($cfg['dir'])) {
    mkdir($cfg['dir'], 0775, true);
  }

  if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetAbs)) {
    throw new Exception("Upload failed");
  }

  return "uploads/" . $safeName;
}

function report_visible_to_user(array $reportRow, ?array $user): bool {
  if (!$user) return false;

  // Leaders see everything
  if (in_array('LEADER', $user['roles'] ?? [], true)) return true;

  $vis = json_decode($reportRow['visibility_json'] ?? '[]', true);
  if (!is_array($vis) || count($vis) === 0) return false;

  foreach ($vis as $roleKey) {
    if (in_array($roleKey, $user['roles'] ?? [], true)) return true;
  }
  return false;
}
