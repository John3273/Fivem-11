<?php
// public/_layout.php
// Outputs the HTML shell + top navigation. Pages include this file after they set $u.

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '/') $basePath = '';

if (!empty($u) && (user_has_role('POLICE') || user_has_role('LEADER'))) {
  $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
  if (!in_array($currentPage, ['profile_setup.php', 'logout.php', 'callback.php', 'login.php'], true)) {
    $db = db();
    try { $db->query("SELECT police_display_name FROM users LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE users ADD COLUMN police_display_name TEXT"); }
    try { $db->query("SELECT police_badge_number FROM users LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE users ADD COLUMN police_badge_number TEXT"); }

    $stmt = $db->prepare("SELECT police_display_name, police_badge_number FROM users WHERE discord_id=?");
    $stmt->execute([$u['discord_id']]);
    $profile = $stmt->fetch() ?: [];

    if (trim((string)($profile['police_display_name'] ?? '')) === '' || trim((string)($profile['police_badge_number'] ?? '')) === '') {
      header('Location: profile_setup.php');
      exit;
    }

    $_SESSION['user']['name'] = $profile['police_display_name'];
    $_SESSION['user']['badge_number'] = $profile['police_badge_number'];
    $u = $_SESSION['user'];
  }
}
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>POLITI – POLINTRA</title>

  <link rel="stylesheet" href="<?= $basePath ?>/assets/style.css?v=4">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <div>
        POLITI | POLINTRA
        <small>REDLINE POLITIDISTRIKT</small>
      </div>
    </div>

    <div class="topbar-right">
      <?php if (!empty($u)): ?>
        <div class="userbox">
          <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
          <div class="user-role">Betjent</div>
        </div>
        <a class="btn btn-ghost" href="logout.php">Log ud</a>
      <?php else: ?>
        <a class="btn btn-primary" href="login.php">Login med Discord</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- SUBBAR -->
<div class="subbar">
  <div class="subbar-inner">
    <div class="nav-left">
      <a href="index.php">Forside</a>
    </div>
    <div class="nav-right">
      <?php if (!empty($u) && user_has_role('POLICE')): ?>
        <a class="btn btn-ghost" href="police.php">Politisiden</a>
        <a class="btn btn-ghost" href="sager.php">Sager</a>
        <a class="btn btn-ghost" href="retningslinjer.php">Retningslinjer</a>
        <a class="btn btn-ghost" href="department_overview.php">Afdelingsoversigt</a>
      <?php endif; ?>
      <?php if (!empty($u) && user_has_role('LEADER')): ?>
        <a class="btn btn-ghost" href="leader.php">Ledersiden</a>
      <?php endif; ?>
    </div>
  </div>
</div>