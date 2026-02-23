<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

require_role('POLICE','LEADER');

$db = db();
$u  = current_user();

// Ensure columns exist
try { $db->query("SELECT police_display_name FROM users LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE users ADD COLUMN police_display_name TEXT"); }
try { $db->query("SELECT police_badge_number FROM users LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE users ADD COLUMN police_badge_number TEXT"); }

$stmt = $db->prepare("SELECT police_display_name, police_badge_number FROM users WHERE discord_id=?");
$stmt->execute([$u['discord_id']]);
$row = $stmt->fetch() ?: [];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $displayName = trim($_POST['police_display_name'] ?? '');
    $badgeNumber = trim($_POST['police_badge_number'] ?? '');

    if (!preg_match('/^\S+\s+\S+/', $displayName)) {
        $error = 'Navnet skal være fornavn + efternavn (fx John Kaj).';
    } elseif (!preg_match('/^[0-9]{1,3}-[0-9]{1,3}$/', $badgeNumber)) {
        $error = 'Badge nummer skal være i formatet fx 10-52.';
    } else {
        $stmt = $db->prepare("UPDATE users SET police_display_name=?, police_badge_number=? WHERE discord_id=?");
        $stmt->execute([$displayName, $badgeNumber, $u['discord_id']]);

        // Keep session in sync (used for posts/topbar)
        $_SESSION['user']['name'] = $displayName;
        $_SESSION['user']['badge_number'] = $badgeNumber;

        redirect('police.php');
    }
}

include __DIR__ . '/_layout.php';
?>

<div class="container" style="max-width:760px;">
  <div class="card">
    <h2>Første login – udfyld politiprofil</h2>
    <p class="muted">Dit navn og badge bliver vist i opslag og interne visninger. Udfyld fx <b>John Kaj</b> og <b>10-52</b>.</p>

    <?php if ($error): ?>
      <div class="badge" style="background:#7a1f1f; margin-bottom:12px;"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <label>Visningsnavn (fornavn + efternavn)</label>
      <input name="police_display_name" required value="<?= h($row['police_display_name'] ?? '') ?>" placeholder="John Kaj"/>

      <label>Badge nummer</label>
      <input name="police_badge_number" required value="<?= h($row['police_badge_number'] ?? '') ?>" placeholder="10-52"/>

      <div style="margin-top:12px;">
        <button type="submit">Gem profil</button>
      </div>
    </form>
  </div>
</div>

<footer class="footer">
  © 2026 Redline Politidistrikt — FiveM Roleplay Server
</footer>
</body></html>
