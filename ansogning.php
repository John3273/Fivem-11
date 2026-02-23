<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

$db = db();
$u = current_user();

$db->exec("CREATE TABLE IF NOT EXISTS applications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  applicant_discord_id TEXT,
  applicant_name TEXT NOT NULL,
  q_presentation TEXT NOT NULL,
  q_activity TEXT NOT NULL,
  q_experience TEXT NOT NULL,
  q_why_me TEXT NOT NULL,
  q_conflict_handling TEXT NOT NULL,
  q_structure_communication TEXT NOT NULL,
  q_accept_rules TEXT NOT NULL,
  review_status TEXT NOT NULL DEFAULT 'Afventer',
  created_at TEXT DEFAULT (datetime('now'))
)");

try { $db->query("SELECT applicant_discord_id FROM applications LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE applications ADD COLUMN applicant_discord_id TEXT"); }
try { $db->query("SELECT review_status FROM applications LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE applications ADD COLUMN review_status TEXT NOT NULL DEFAULT 'Afventer'"); }

$db->exec("CREATE TABLE IF NOT EXISTS portal_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)");
$db->exec("INSERT OR IGNORE INTO portal_settings(setting_key, setting_value) VALUES('applications_open','1')");
$applicationsOpen = (string)$db->query("SELECT setting_value FROM portal_settings WHERE setting_key='applications_open'")->fetchColumn() === '1';

$submitted = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$applicationsOpen) {
    $error = 'Ansøgninger er midlertidigt lukkede.';
  } else {
  $name = trim($_POST['name'] ?? '');
  $presentation = trim($_POST['presentation'] ?? '');
  $activity = trim($_POST['activity'] ?? '');
  $experience = trim($_POST['experience'] ?? '');
  $whyMe = trim($_POST['why_me'] ?? '');
  $conflict = trim($_POST['conflict'] ?? '');
  $structure = trim($_POST['structure'] ?? '');
  $acceptRules = ($_POST['accept_rules'] ?? '') === 'Ja' ? 'Ja' : 'Nej';

  if ($name && $presentation && $activity && $experience && $whyMe && $conflict && $structure) {
    $stmt = $db->prepare("INSERT INTO applications(applicant_discord_id, applicant_name, q_presentation, q_activity, q_experience, q_why_me, q_conflict_handling, q_structure_communication, q_accept_rules) VALUES(?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$u['discord_id'] ?? null, $name, $presentation, $activity, $experience, $whyMe, $conflict, $structure, $acceptRules]);
    $submitted = true;
  } else {
    $error = 'Udfyld venligst alle felter.';
  }
  }
}

include __DIR__ . '/_layout.php';
?>

<div class="container">
  <div class="card">
    <h2>Ansøgning til Politiet</h2>
    <p class="muted">Udfyld formularen grundigt for at blive vurderet af ledelsen.</p>

    <?php if ($submitted): ?>
      <div class="card" style="background:#f0f7ff;border-color:#bfd7ff;">
        <b>Tak for din ansøgning.</b> Vi gennemgår den hurtigst muligt.
      </div>
      <?php elseif (!$applicationsOpen): ?>
      <div class="card" style="background:#fff4e5;border-color:#ffd190;">
        <b>Ansøgning Closed.</b> Ansøgninger er lukket lige nu.
      </div>
    <?php else: ?>
      <?php if ($error): ?><p style="color:#a40000;font-weight:700;"><?= h($error) ?></p><?php endif; ?>
      <form method="post">
        <label>Navn</label>
        <input name="name" required>

        <label>Kort præsentation af dig selv (irl-alder, fritidsaktivitet, spilletid i FiveM - hvem er du?)</label>
        <textarea name="presentation" rows="4" required></textarea>

        <label>Hvor mange timer forventer du at kunne være aktiv om ugen- og hvilke dage/tidspunkter typisk?</label>
        <textarea name="activity" rows="3" required></textarea>

        <label>Har du tidligere erfaring med politi-RP? Hvis ja, hvilke servere, afdelinger og roller?</label>
        <textarea name="experience" rows="3" required></textarea>

        <label>Hvorfor skal vi vælge dig som betjent?</label>
        <textarea name="why_me" rows="3" required></textarea>

        <label>Hvordan håndterer du konflikter i RP – specielt når tingene bliver intense?</label>
        <textarea name="conflict" rows="3" required></textarea>

        <label>Hvad ved du om politiets struktur og kommunikation?</label>
        <textarea name="structure" rows="3" required></textarea>

        <label>Er du bekendt med og accepterer du serverens regler?</label>
        <select name="accept_rules" required>
          <option value="Ja">Ja</option>
          <option value="Nej">Nej</option>
        </select>

        <div style="margin-top:12px;">
          <button type="submit">Indsend ansøgning</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
</body></html>