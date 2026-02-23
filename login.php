<?php
require_once __DIR__ . '/../app/db.php';
session_start();

$cfg = config()['discord'];
$params = http_build_query([
  'client_id' => $cfg['client_id'],
  'redirect_uri' => $cfg['redirect_uri'],
  'response_type' => 'code',
  'scope' => $cfg['scopes'],
]);

header("Location: https://discord.com/api/oauth2/authorize?$params");
exit;
