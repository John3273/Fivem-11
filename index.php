<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

$db = db();
$u  = current_user();

// Create report (citizen)
try { $db->query("SELECT report_kind FROM reports LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE reports ADD COLUMN report_kind TEXT NOT NULL DEFAULT 'Rapport/Klage'"); }
$db->exec("CREATE TABLE IF NOT EXISTS report_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id INTEGER NOT NULL,
  author_role TEXT NOT NULL,
  author_name TEXT NOT NULL,
  message TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_report') {
  $fullName = trim($_POST['full_name'] ?? '');
  $details  = trim($_POST['details'] ?? '');
  $reportKind = trim($_POST['report_kind'] ?? 'Rapport/Klage');
  if (!in_array($reportKind, ['Rapport/Klage','Andet'], true)) $reportKind = 'Rapport/Klage';

  if ($fullName && $details) {
    try { $evidencePath = save_upload('evidence'); } catch (Throwable $e) { die("Upload fejl: " . h($e->getMessage())); }

    $reportUid = make_report_uid('R');

    $creatorId   = $u['discord_id'] ?? 'guest';
    
    $stmt = $db->prepare("INSERT INTO reports(report_uid, created_by_discord_id, created_by_name, page_origin, report_kind, details, evidence_path)
    VALUES(?,?,?,?,?,?,?)");
    $stmt->execute([$reportUid, $creatorId, $fullName, 'citizen', $reportKind, $details, $evidencePath]);

    $reportId = (int)$db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO report_messages(report_id, author_role, author_name, message) VALUES(?,?,?,?)");
    $stmt->execute([$reportId, 'citizen', $fullName, $details]);

    redirect('index.php?submitted=' . urlencode($reportUid));
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply_report' && $u) {
  $reportId = (int)($_POST['report_id'] ?? 0);
  $message = trim($_POST['message'] ?? '');
  if ($reportId > 0 && $message !== '') {
    $stmt = $db->prepare("SELECT id, created_by_discord_id, created_by_name, archived, status FROM reports WHERE id=?");
    $stmt->execute([$reportId]);
    $r = $stmt->fetch();
    if ($r && $r['created_by_discord_id'] === $u['discord_id'] && (int)$r['archived'] === 0 && !in_array($r['status'], ['Closed','Lukket'], true)) {
      $stmt = $db->prepare("INSERT INTO report_messages(report_id, author_role, author_name, message) VALUES(?,?,?,?)");
      $stmt->execute([$reportId, 'citizen', $r['created_by_name'], $message]);
    }
  }
  redirect('index.php?open_report=' . $reportId);
}

// load posts (public only)
$stmt = $db->prepare("SELECT * FROM posts WHERE COALESCE(audience,'frontpage') IN ('frontpage','public') ORDER BY id DESC LIMIT 10");
$stmt->execute();
$posts = $stmt->fetchAll();

$db->exec("CREATE TABLE IF NOT EXISTS portal_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)");
$db->exec("INSERT OR IGNORE INTO portal_settings(setting_key, setting_value) VALUES('applications_open','1')");
$applicationsOpen = (string)$db->query("SELECT setting_value FROM portal_settings WHERE setting_key='applications_open'")->fetchColumn() === '1';
$appCardTitle = (string)($db->query("SELECT setting_value FROM portal_settings WHERE setting_key='applications_card_title'")->fetchColumn() ?: 'Bliv en del af politiet');
$appCardBody = (string)($db->query("SELECT setting_value FROM portal_settings WHERE setting_key='applications_card_body'")->fetchColumn() ?: 'Har du lyst til seriøs politi-RP? Send en ansøgning og fortæl os hvorfor du passer ind i Redline Politidistrikt.');

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

$slides = [];
try {
  $stmt = $db->prepare("SELECT title,image_path,link_url FROM citizen_slides WHERE is_active=1 ORDER BY sort_order, id");
  $stmt->execute();
  $slides = $stmt->fetchAll();
} catch (Throwable $e) {
  $slides = [];
}

if (!$slides) {
  $fallbackDir = __DIR__ . '/uploads/slideshow';
  if (is_dir($fallbackDir)) {
    $files = glob($fallbackDir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
    foreach ($files as $fp) {
      $slides[] = [
        'title' => basename($fp),
        'image_path' => 'uploads/slideshow/' . basename($fp),
        'link_url' => null,
      ];
    }
  }
}

// my reports (only when logged in)
$myReports = [];
$openedReport = null;
$openedMessages = [];
$myApplication = null;
$openApplication = (int)($_GET['open_application'] ?? 0) === 1;

if ($u) {
  $stmt = $db->prepare("SELECT * FROM applications WHERE applicant_discord_id = ? ORDER BY id DESC LIMIT 1");
  $stmt->execute([$u['discord_id']]);
  $myApplication = $stmt->fetch() ?: null;
}

if (!$myApplication && !empty($_SESSION['last_application_id'])) {
  $stmt = $db->prepare("SELECT * FROM applications WHERE id = ? LIMIT 1");
  $stmt->execute([(int)$_SESSION['last_application_id']]);
  $myApplication = $stmt->fetch() ?: null;
}
if ($u) {
  $stmt = $db->prepare("SELECT * FROM applications WHERE applicant_discord_id = ? ORDER BY id DESC LIMIT 1");
  $stmt->execute([$u['discord_id']]);
  $myApplication = $stmt->fetch() ?: null;

  $stmt = $db->prepare("SELECT id,report_uid,report_kind,status,archived,created_at FROM reports WHERE created_by_discord_id = ? ORDER BY id DESC LIMIT 25");
  $stmt->execute([$u['discord_id']]);
  $myReports = $stmt->fetchAll();

  $openId = (int)($_GET['open_report'] ?? 0);
  if ($openId > 0) {
    $stmt = $db->prepare("SELECT * FROM reports WHERE id=? AND created_by_discord_id=?");
    $stmt->execute([$openId, $u['discord_id']]);
    $openedReport = $stmt->fetch() ?: null;
    if ($openedReport) {
      $stmt = $db->prepare("SELECT * FROM report_messages WHERE report_id=? ORDER BY id ASC");
      $stmt->execute([$openId]);
      $openedMessages = $stmt->fetchAll();
    }
  }
}

include __DIR__ . '/_layout.php';
?>

<div class="container">
<?php if (isset($_GET['submitted'])): ?><div class="card"><b>Indsendt!</b> Dit sagsnummer: <span class="badge"><?= h($_GET['submitted']) ?></span></div><div style="height:12px"></div><?php endif; ?>

  <div class="grid main-grid"><div class="stack">
  <?php if ($slides): ?>
      <div class="card" style="margin-bottom:0;">
        <div class="slideshow-container" id="citizenSlideshow">
          <?php foreach ($slides as $i => $s): ?>
            <div class="slide" style="display:<?= $i === 0 ? 'block' : 'none' ?>;">
              <?php if (!empty($s['link_url'])): ?><a href="<?= h($s['link_url']) ?>" target="_blank" rel="noopener noreferrer"><?php endif; ?>
                <img src="<?= h($s['image_path']) ?>" alt="<?= h($s['title'] ?? 'Slide') ?>" style="display:block;width:100%;height:260px;object-fit:cover;">
              <?php if (!empty($s['link_url'])): ?></a><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <div class="card"><h2>Nyheder & Opslag</h2><?php foreach ($posts as $i => $p): ?><div class="post"><?php if ($i===0): ?><div class="pin">📌 Fastgjort opslag</div><?php endif; ?><div class="body"><h3><?= h($p['title']) ?></h3><p class="muted"><?= nl2br(h($p['body'])) ?></p><div class="meta"><span>🕒 <?= h($p['created_at']) ?></span><span>•</span><span>👤 <?= h($p['author_discord_id']) ?></span></div></div></div><?php endforeach; ?></div>
  </div>
  <div class="stack right-rail">
    <div class="card application-card">
    <h2><?= h($appCardTitle) ?></h2>
      <p class="muted"><?= nl2br(h($appCardBody)) ?></p>
      <div style="margin-bottom:10px;"><span class="badge" style="background:rgba(255,255,255,.2);border-color:rgba(255,255,255,.35);color:#fff;">Ansøgninger - <?= $applicationsOpen ? 'Åben' : 'Lukket' ?></span></div>
      <?php if ($applicationsOpen): ?>
        <a class="btn btn-solid" href="ansogning.php">Gå til ansøgning</a>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Mine Sager / Min Ansøgning</h2>
      <?php if (!$u): ?>
        <div class="emptybox"><div class="emptyicon">📄</div><div>Log ind for at se dine sager og ansøgning</div></div>
      <?php else: ?>
        <div style="display:flex;gap:8px;margin-bottom:10px;">
          <button type="button" class="btn btn-solid" id="switchCasesBtn" onclick="switchMineKort('cases')">Mine sager</button>
          <button type="button" class="btn" id="switchApplicationBtn" onclick="switchMineKort('application')">Min ansøgning</button>
        </div>
        <div id="mineCasesPane" style="max-height:260px;overflow:auto;">
          <table class="table">
            <thead><tr><th>ID</th><th>Type</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach (array_slice($myReports, 0, 3) as $r): ?>
                <tr><td><?= h($r['report_uid']) ?></td><td><?= h($r['report_kind'] ?? 'Rapport/Klage') ?></td><td><?= h($r['archived'] ? 'Lukket (Arkiv)' : $r['status']) ?></td><td><a class="btn" href="index.php?open_report=<?= (int)$r['id'] ?>">Åbn</a></td></tr>
              <?php endforeach; ?>
              <?php if (!$myReports): ?><tr><td colspan="4">Ingen sager endnu.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <div id="mineApplicationPane" style="display:none;">
          <?php if ($myApplication): ?>
            <p><b>Navn:</b> <?= h($myApplication['applicant_name']) ?></p>
            <p><b>Status:</b> <?= h($myApplication['review_status'] ?? 'Afventer') ?></p>
            <a class="btn" href="index.php?open_application=1">Åbn</a>
          <?php else: ?>
            <div class="muted">Ingen ansøgning fundet.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

      <?php if ($myApplication && $openApplication): ?>
        <div id="application-content" style="display:none;">
          <h3>Min Ansøgning</h3>
          <p><b>Navn:</b> <?= h($myApplication['applicant_name']) ?></p>
          <p><b>Indsendt:</b> <?= h($myApplication['created_at']) ?></p>
          <p><b>Status:</b> <?= h($myApplication['review_status'] ?? 'Afventer') ?></p>
          <p><b>Kort præsentation:</b><br><?= nl2br(h($myApplication['q_presentation'])) ?></p>
        </div>
      <?php endif; ?>
      <?php if ($openedReport): ?>
        <div id="report-content" style="display:none;">
      <h3>Sag <?= h($openedReport['report_uid']) ?> (<?= h($openedReport['report_kind'] ?? 'Rapport/Klage') ?>)</h3>
        <p class="muted">Status: <?= h($openedReport['status']) ?><?= (int)$openedReport['archived']===1 ? ' • Arkiveret' : '' ?></p>
        <?php foreach ($openedMessages as $m): ?><div style="padding:8px;border:1px solid rgba(255,255,255,.08);border-radius:8px;margin-bottom:8px;"><b><?= h($m['author_name']) ?></b> <span class="muted">(<?= h($m['author_role']) ?>) • <?= h($m['created_at']) ?></span><div><?= nl2br(h($m['message'])) ?></div></div><?php endforeach; ?>
         <?php if ((int)$openedReport['archived']===0 && !in_array($openedReport['status'], ['Closed','Lukket'], true)): ?>
          <form method="post"><input type="hidden" name="action" value="reply_report"><input type="hidden" name="report_id" value="<?= (int)$openedReport['id'] ?>"><label>Svar</label><textarea name="message" rows="3" required></textarea><div style="margin-top:8px;"><button type="submit">Send svar</button></div></form>
        <?php else: ?><p class="muted">Sagen er lukket/arkiveret. Du kan ikke skrive flere svar.</p><?php endif; ?>
  </div>
  <?php endif; ?>
  </div></div>

  <div class="card"><h2>Kontakt Politiet</h2><details style="margin-top:12px"><summary class="btn btn-solid">+ Opret henvendelse</summary><form method="post" enctype="multipart/form-data" style="margin-top:12px"><input type="hidden" name="action" value="create_report"/><label>Type</label><select name="report_kind"><option value="Rapport/Klage">Rapport/Klage</option><option value="Andet">Andet</option></select><label>Fulde navn</label><input name="full_name" required /><label>Detaljer</label><textarea name="details" rows="6" required></textarea><div style="margin-top:12px"><button type="submit">Indsend</button></div></form></details></div>
</div>

<footer class="footer">
  © 2026 Redline Politidistrikt — FiveM Roleplay Server
</footer>

<div id="applicationModal" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;">
  <div style="max-width:760px;margin:7vh auto;background:#fff;padding:18px;border-radius:8px;max-height:85vh;overflow:auto;position:relative;">
    <button type="button" class="btn" onclick="closeApplicationModal()" style="position:absolute;right:12px;top:12px;">Luk</button>
    <div id="applicationModalContent"></div>
  </div>
</div>

<div id="reportModal" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;">
  <div style="max-width:760px;margin:7vh auto;background:#fff;padding:18px;border-radius:8px;max-height:85vh;overflow:auto;position:relative;">
    <button type="button" class="btn" onclick="closeReportModal()" style="position:absolute;right:12px;top:12px;">Luk</button>
    <div id="reportModalContent"></div>
  </div>
</div>

<script>
function closeReportModal() {
  const modal = document.getElementById('reportModal');
  if (modal) modal.style.display = 'none';
}
function closeApplicationModal() {
  const modal = document.getElementById('applicationModal');
  if (modal) modal.style.display = 'none';
}
function switchMineKort(mode) {
  const casesPane = document.getElementById('mineCasesPane');
  const appPane = document.getElementById('mineApplicationPane');
  const casesBtn = document.getElementById('switchCasesBtn');
  const appBtn = document.getElementById('switchApplicationBtn');
  const showCases = mode !== 'application';
  if (casesPane) casesPane.style.display = showCases ? '' : 'none';
  if (appPane) appPane.style.display = showCases ? 'none' : '';
  if (casesBtn) casesBtn.classList.toggle('btn-solid', showCases);
  if (appBtn) appBtn.classList.toggle('btn-solid', !showCases);
}
(function(){
  const appSrc = document.getElementById('application-content');
  const appModal = document.getElementById('applicationModal');
  const appContent = document.getElementById('applicationModalContent');
  if (appSrc && appModal && appContent) {
    appContent.innerHTML = appSrc.innerHTML;
    appModal.style.display = 'block';
  }
  const src = document.getElementById('report-content');
  const modal = document.getElementById('reportModal');
  const content = document.getElementById('reportModalContent');
  if (src && modal && content) {
    content.innerHTML = src.innerHTML;
    modal.style.display = 'block';
  }
  switchMineKort(<?= $openApplication ? json_encode('application') : json_encode('cases') ?>);
  window.addEventListener('click', function(e){
    if (e.target === modal) closeReportModal();
    if (e.target === appModal) closeApplicationModal();
  });
})();
const slides = Array.from(document.querySelectorAll('#citizenSlideshow .slide'));
  if (slides.length > 1) {
    let idx = 0;
    setInterval(() => {
      slides[idx].style.display = 'none';
      idx = (idx + 1) % slides.length;
      slides[idx].style.display = 'block';
    }, 4000);
  }
</script>

</body></html>
