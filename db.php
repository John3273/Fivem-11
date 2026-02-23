<?php
// app/db.php
function config() {
  static $cfg = null;
  if ($cfg === null) $cfg = require __DIR__ . '/config.php';
  return $cfg;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $cfg = config()['db'];

  if ($cfg['driver'] === 'sqlite') {
    $pdo = new PDO('sqlite:' . $cfg['sqlite_path']);
  } else {
    $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  return $pdo;
}
