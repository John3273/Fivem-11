<?php
require_once __DIR__ . '/../app/discord.php';
require_once __DIR__ . '/../app/db.php';

session_start();

if (!isset($_GET['code'])) {
  echo "Missing code";
  exit;
}

try {
  $token = discord_exchange_code($_GET['code']);
  $access = $token['access_token'];

  $user = discord_get_user($access);
  $discordId = $user['id'];
  $name = $user['username'] . (isset($user['discriminator']) ? "#".$user['discriminator'] : "");
  $avatar = isset($user['avatar']) ? "https://cdn.discordapp.com/avatars/{$discordId}/{$user['avatar']}.png" : null;

  $roles = discord_get_member_roles($discordId);

  $db = db();
  try { $db->query("SELECT police_display_name FROM users LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE users ADD COLUMN police_display_name TEXT"); }
  try { $db->query("SELECT police_badge_number FROM users LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE users ADD COLUMN police_badge_number TEXT"); }

  $stmt = $db->prepare("INSERT INTO users(discord_id, discord_name, avatar_url, roles_json)
    VALUES(?,?,?,?)
    ON CONFLICT(discord_id) DO UPDATE SET discord_name=excluded.discord_name, avatar_url=excluded.avatar_url, roles_json=excluded.roles_json");
  $stmt->execute([$discordId, $name, $avatar, json_encode($roles)]);

  $stmt = $db->prepare("SELECT police_display_name, police_badge_number FROM users WHERE discord_id=?");
  $stmt->execute([$discordId]);
  $profile = $stmt->fetch() ?: [];

  $_SESSION['user'] = [
    'discord_id' => $discordId,
    'name' => $name,
    'name' => ($profile['police_display_name'] ?? $name),
    'avatar' => $avatar,
    'roles' => $roles,
    'badge_number' => ($profile['police_badge_number'] ?? null),
  ];

  header("Location: index.php");
  $isPolice = in_array('POLICE', $roles, true) || in_array('LEADER', $roles, true);
  $needsProfile = trim((string)($profile['police_display_name'] ?? '')) === '' || trim((string)($profile['police_badge_number'] ?? '')) === '';

  header("Location: " . ($isPolice && $needsProfile ? "profile_setup.php" : "index.php"));
  exit;

} catch (Exception $e) {
  http_response_code(500);
  echo "Login error: " . htmlspecialchars($e->getMessage());
}