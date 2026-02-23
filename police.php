<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

require_role('POLICE','LEADER');

$db = db();
$u  = current_user();

$rankOrderExpr = "COALESCE((SELECT sort_order FROM ranks r WHERE r.name = employees.rank),9999)";

$db->exec("CREATE TABLE IF NOT EXISTS cases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_uid TEXT UNIQUE NOT NULL,
  title TEXT NOT NULL,
  description TEXT NOT NULL,
  created_by_discord_id TEXT NOT NULL,
  assigned_type TEXT NOT NULL,
  assigned_department TEXT,
  assigned_discord_id TEXT,
  assigned_name TEXT,
  assigned_badge TEXT,
  status TEXT NOT NULL DEFAULT 'Aktiv',
  archived INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS case_visibility (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id INTEGER NOT NULL,
  target_type TEXT NOT NULL,
  target_value TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(case_id, target_type, target_value)
)");


// --- Lightweight "migrations" (safe to run on every request) ---
$db->exec("CREATE TABLE IF NOT EXISTS ranks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS departments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS department_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  department_id INTEGER NOT NULL,
  link_key TEXT NOT NULL,
  url TEXT NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(department_id, link_key)
)");
try { $db->exec("ALTER TABLE employees ADD COLUMN photo_url TEXT"); } catch (Throwable $e) {}

// Resource buttons (must match leader.php Administration)
$RESOURCE_BUTTONS = [
  ['key' => 'handbook',  'icon' => '📘', 'title' => 'Håndbog',           'desc' => 'Politiets officielle procedurer'],
  ['key' => 'shiftplan', 'icon' => '🗓️', 'title' => 'Skifteplan',        'desc' => 'Se aktuelle vagtplaner'],
  ['key' => 'forms',     'icon' => '🧾', 'title' => 'Formular Portalen', 'desc' => 'Dokumenter og formularer'],
  ['key' => 'discord',   'icon' => '💬', 'title' => 'Discord Server',    'desc' => 'Interne kanaler'],
];


// Posts: support "archive/hide" for all.
// Prefer a real `posts.archived` column when it exists, but fall back to encoding in `audience` as `archived|...`
$postsHasArchivedCol = false;
try {
  $db->query("SELECT archived FROM posts LIMIT 1");
  $postsHasArchivedCol = true;
} catch (Throwable $e) {
  $postsHasArchivedCol = false;
}
function _post_audience_norm($aud) {
  $aud = (string)$aud;
  return (strpos($aud, 'archived|') === 0) ? substr($aud, 9) : $aud;
}

// Departments for sidebar: prefer departments table, fall back to employees.
$departments = [];
try {
  $departments = $db->query("
  SELECT d.name AS department, COALESCE(ec.cnt,0) AS cnt
  FROM departments d
  LEFT JOIN (
    SELECT department, COUNT(*) AS cnt
    FROM (
      -- Primary department
      SELECT COALESCE(NULLIF(TRIM(department),''),'Ukendt') AS department
      FROM employees
      
      UNION ALL
      
      -- Secondary department
      SELECT COALESCE(NULLIF(TRIM(secondary_department),''),'Ukendt') AS department
      FROM employees
      WHERE secondary_department IS NOT NULL 
        AND TRIM(secondary_department) != ''
    ) all_depts
    GROUP BY department
  ) ec ON ec.department = d.name
  ORDER BY d.sort_order, d.name
")->fetchAll();
} catch (Throwable $e) {
  $departments = [];
}
if (!$departments) {
  $departments = $db->query("
    SELECT COALESCE(NULLIF(TRIM(department),''),'Ukendt') AS department, COUNT(*) AS cnt
    FROM employees
    GROUP BY COALESCE(NULLIF(TRIM(department),''),'Ukendt')
    ORDER BY department
  ")->fetchAll();
}

$viewParam = $_GET['view'] ?? '';
$dashboard = isset($_GET['dashboard']) || ($viewParam === 'dashboard') || !isset($_GET['dept']);
$selectedDept = $_GET['dept'] ?? ($departments[0]['department'] ?? 'Ukendt');
$view = $dashboard ? 'dashboard' : 'dept';

// Links for "Ressourcer & Links" (per department)
$selectedDeptId = 0;
try {
  $stmt = $db->prepare("SELECT id FROM departments WHERE name=?");
  $stmt->execute([$selectedDept]);
  $selectedDeptId = (int)($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $selectedDeptId = 0;
}

$deptLinks = [];
if ($selectedDeptId > 0) {
  $stmt = $db->prepare("SELECT link_key, url FROM department_links WHERE department_id=?");
  $stmt->execute([$selectedDeptId]);
  foreach ($stmt->fetchAll() as $r) {
    $deptLinks[(string)$r['link_key']] = (string)$r['url'];
  }
}
$stmt = $db->prepare("
  SELECT DISTINCT * FROM employees
  WHERE 
    COALESCE(NULLIF(TRIM(department),''),'Ukendt') = :dept
    OR
    COALESCE(NULLIF(TRIM(secondary_department),''),'') = :dept
    ORDER BY COALESCE((SELECT sort_order FROM ranks r WHERE r.name = employees.rank),9999), name
");

$stmt->execute([
  'dept' => $selectedDept
]);

$deptEmployees = $stmt->fetchAll();

// Dashboard: top leadership
$leadershipDept = 'Øverste Ledelse';
$stmt = $db->prepare("
  SELECT * FROM employees
  WHERE COALESCE(NULLIF(TRIM(department),''),'Ukendt') = ?
    ORDER BY COALESCE((SELECT sort_order FROM ranks r WHERE r.name = employees.rank),9999), name
");
$stmt->execute([$leadershipDept]);
$leadershipEmployees = $stmt->fetchAll();


// Latest updates (public + police posts + current department posts), excluding archived
$deptAudience = 'dept:' . $selectedDept;

if ($postsHasArchivedCol) {
  $stmt = $db->prepare("SELECT * FROM posts WHERE COALESCE(archived,0)=0 AND COALESCE(audience,'frontpage') IN ('frontpage','public','police', ?) ORDER BY id DESC LIMIT 3");
  $stmt->execute([$deptAudience]);
} else {
  $stmt = $db->prepare("SELECT * FROM posts WHERE COALESCE(audience,'frontpage') IN ('frontpage','public','police', ?) AND COALESCE(audience,'frontpage') NOT LIKE 'archived|%' ORDER BY id DESC LIMIT 3");
  $stmt->execute([$deptAudience]);
}
$posts = $stmt->fetchAll();
// My case overview for sidebar
$myCaseRows = [];
try { $db->query("SELECT police_display_name FROM users LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE users ADD COLUMN police_display_name TEXT"); }
try { $db->query("SELECT police_badge_number FROM users LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE users ADD COLUMN police_badge_number TEXT"); }
$stmt = $db->prepare("SELECT police_display_name, police_badge_number FROM users WHERE discord_id=?");
$stmt->execute([$u['discord_id']]);
$pf = $stmt->fetch() ?: [];
$myName = trim((string)($pf['police_display_name'] ?? ''));
$myBadge = trim((string)($pf['police_badge_number'] ?? ''));
$myDiscords = [];
$myDepartments = [];
if ($myName !== '' && $myBadge !== '') {
  $stmt = $db->prepare("SELECT discord_id, department, secondary_department FROM employees WHERE name=? AND badge_number=?");
  $stmt->execute([$myName, $myBadge]);
  foreach ($stmt->fetchAll() as $er) {
    if (!empty($er['discord_id'])) $myDiscords[] = (string)$er['discord_id'];
    if (!empty($er['department'])) $myDepartments[] = $er['department'];
    if (!empty($er['secondary_department'])) $myDepartments[] = $er['secondary_department'];
  }
}
$stmt = $db->prepare("SELECT discord_id, department, secondary_department FROM employees WHERE discord_id=?");
$stmt->execute([(string)($u['discord_id'] ?? '')]);
foreach ($stmt->fetchAll() as $er) {
  if (!empty($er['discord_id'])) $myDiscords[] = (string)$er['discord_id'];
  if (!empty($er['department'])) $myDepartments[] = $er['department'];
  if (!empty($er['secondary_department'])) $myDepartments[] = $er['secondary_department'];
}
$myDiscords[] = (string)($u['discord_id'] ?? '');
$myDepartments = array_values(array_unique(array_filter($myDepartments)));
$myDiscords = array_values(array_unique(array_filter($myDiscords)));
$q = "SELECT id, case_uid, assigned_type, assigned_department, status FROM cases WHERE archived=0 AND (created_by_discord_id=? OR (assigned_type='person' AND assigned_discord_id=?)";
$params = [$u['discord_id'], $u['discord_id']];
if ($myDepartments) {
  $q .= " OR (assigned_type='department' AND assigned_department IN (" . implode(',', array_fill(0, count($myDepartments), '?')) . "))";
  $params = array_merge($params, $myDepartments);
}
if ($myDiscords || $myDepartments) {
  $cvParts = [];
  if ($myDiscords) {
    $cvParts[] = "(cv.target_type='employee' AND cv.target_value IN (" . implode(',', array_fill(0, count($myDiscords), '?')) . "))";
    $params = array_merge($params, $myDiscords);
  }
  if ($myDepartments) {
    $cvParts[] = "(cv.target_type='department' AND cv.target_value IN (" . implode(',', array_fill(0, count($myDepartments), '?')) . "))";
    $params = array_merge($params, $myDepartments);
  }
  $q .= " OR EXISTS (SELECT 1 FROM case_visibility cv WHERE cv.case_id=cases.id AND (" . implode(' OR ', $cvParts) . "))";
}
$q .= ") ORDER BY id DESC LIMIT 8";
$stmt = $db->prepare($q);
$stmt->execute($params);
$myCaseRows = $stmt->fetchAll();

include __DIR__ . '/_layout.php';
?>

<div class="container">

  <div class="grid" style="grid-template-columns: .55fr 1.45fr;">
    <!-- SIDEBAR -->
    <div class="sidebar">
      <div class="side-title">MENU</div>

      <!-- Dashboard (default landing) -->
      <a href="police.php?dashboard" class="<?= ($view === 'dashboard') ? 'active' : '' ?>">
        <span>Dashboard</span>
      </a>

      <div class="side-title" style="margin-top:10px;">AFDELINGER</div>

      <?php foreach ($departments as $d): ?>
        <?php $dept = $d['department']; ?>
        <a href="police.php?dept=<?= urlencode($dept) ?>" class="<?= ($view !== 'dashboard' && $dept === $selectedDept) ? 'active' : '' ?>">
          <span><?= h($dept) ?></span>
          <span class="pill"><?= (int)$d['cnt'] ?></span>
        </a>
      <?php endforeach; ?>

      <div class="side-title" style="margin-top:10px;">SAGER</div>
      <?php foreach ($myCaseRows as $cs): ?>
        <a href="sager.php">
          <span><?= h($cs['case_uid']) ?></span>
          <span class="pill"><?= h($cs['status']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$myCaseRows): ?><div style="padding:8px 12px;color:#c8d8ff;">Ingen aktive sager</div><?php endif; ?>

      <?php if (!$departments): ?>
        <div style="padding:12px;color:#e7efff">Ingen afdelinger endnu.</div>
      <?php endif; ?>
    </div>

<!-- MAIN -->
    <div class="stack" style="gap:18px;">
      <?php if ($view === 'dashboard'): ?>
        <style>
          .leader-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;}
          .leader-card{display:flex;gap:12px;align-items:center;padding:14px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(255,255,255,.03);}
          .avatar{width:54px;height:54px;border-radius:14px;overflow:hidden;display:flex;align-items:center;justify-content:center;font-weight:800;}
          .avatar img{width:100%;height:100%;object-fit:cover;}
          .leader-meta{line-height:1.25}
          .leader-meta .name{font-weight:800}
          .leader-meta .sub{opacity:.85;font-size:.92rem}
        </style>

        <div class="card">
          <div class="section-head">
            <div>
              <div class="section-kicker">DASHBOARD</div>
              <h2 style="margin:0;">Øverste Ledelse</h2>
            </div>
            <div class="muted"><?= count($leadershipEmployees) ?> personer</div>
          </div>

          <div class="leader-grid">
            <?php foreach ($leadershipEmployees as $e): ?>
              <?php
                $name = trim((string)($e['name'] ?? ''));
                $initials = '';
                foreach (preg_split('/[\s\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                  if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                  if (mb_strlen($initials) >= 3) break;
                }
                $photo = $e['photo_url'] ?? ($e['image_url'] ?? ($e['avatar_url'] ?? ''));
              ?>
              <div class="leader-card">
                <div class="avatar">
                  <?php if (!empty($photo)): ?>
                    <img src="<?= h($photo) ?>" alt="<?= h($name) ?>">
                  <?php else: ?>
                    <?= h($initials ?: '👮') ?>
                  <?php endif; ?>
                </div>
                <div class="leader-meta">
                  <div class="name"><?= h($e['name'] ?? '') ?></div>
                  <div class="sub"><?= h($e['rank'] ?? '') ?> • P-Nr <?= h($e['badge_number'] ?? '') ?></div>
                </div>
              </div>
            <?php endforeach; ?>

            <?php if (!$leadershipEmployees): ?>
              <div class="muted">Ingen medarbejdere fundet i afdelingen "Øverste Ledelse".</div>
            <?php endif; ?>
          </div>
          <div class="card">
  <div class="section-head">
    <div>
      <div class="section-kicker">NYHEDER</div>
      <h2 style="margin:0;">Seneste opslag</h2>
    </div>
  </div>

  <?php foreach ($posts as $i => $p): ?>
    <div class="post">
      <?php if ($i === 0): ?>
        <div class="pin">📌 Fastgjort opslag</div>
      <?php endif; ?>

      <div class="body">
        <h3 style="display:flex; align-items:center; gap:8px;">
          <?= h($p['title']) ?>

          <?php $audNorm = _post_audience_norm($p['audience'] ?? 'public'); ?>

          <?php if ($audNorm === 'police'): ?>
            <span class="badge">Kun politi</span>
          <?php elseif (strpos($audNorm, 'dept:') === 0): ?>
            <span class="badge"><?= h(substr($audNorm, 5)) ?></span>
          <?php endif; ?>
        </h3>

        <p class="muted"><?= nl2br(h($p['body'])) ?></p>

        <div class="meta">
          <span>🕒 <?= h($p['created_at']) ?></span>
          <span>•</span>
          <span>👤 <?= h($p['author_discord_id']) ?></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (!$posts): ?>
    <p class="muted">Ingen interne opslag endnu.</p>
  <?php endif; ?>
</div>
        </div>
      <?php else: ?>

      <div class="card">
        <div class="section-head">
          <div>
            <div class="section-kicker">AFDELING</div>
            <h2 style="margin:0;"><?= h($selectedDept) ?></h2>
          </div>
          <div class="muted"><?= count($deptEmployees) ?> medarbejdere</div>
        </div>

        <div class="table-wrapper">
  <table class="table">
    <thead>
      <tr>
        <th>Badge</th>
        <th>Navn</th>
        <th>Rang</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($deptEmployees as $e): ?>
        <tr>
          <td><?= h($e['badge_number'] ?? '') ?></td>
          <td><?= h($e['name'] ?? '') ?></td>
          <td><span class="chip"><?= h($e['rank'] ?? '') ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$deptEmployees): ?>
        <tr><td colspan="4">Ingen medarbejdere i denne afdeling endnu.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

      <div class="card">
        <h2>Ressourcer & Links</h2>
        <style>
          .linkcard.disabled{opacity:.55;pointer-events:none;}
        </style>
        <div class="linkgrid">
          <?php foreach ($RESOURCE_BUTTONS as $btn): ?>
            <?php
              $k = $btn['key'];
              $url = trim((string)($deptLinks[$k] ?? ''));
              $href = $url !== '' ? $url : '#';
              $disabled = ($href === '#');
            ?>
            <a class="linkcard <?= $disabled ? 'disabled' : '' ?>" href="<?= h($href) ?>" <?= $disabled ? 'aria-disabled="true"' : 'target="_blank" rel="noopener"' ?>>
              <div class="icon"><?= h($btn['icon']) ?></div>
              <div>
                <b><?= h($btn['title']) ?></b>
                <div class="muted"><?= h($btn['desc']) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <h2>Seneste opdatering</h2>

        <?php foreach ($posts as $i => $p): ?>
          <div class="post">
            <?php if ($i === 0): ?>
              <div class="pin">📌 Fastgjort opslag</div>
            <?php endif; ?>
            <div class="body">
              <h3 style="display:flex; align-items:center; gap:8px;">
                <?= h($p['title']) ?>
                <?php $audNorm = _post_audience_norm($p['audience'] ?? 'public'); ?>
                <?php if ($audNorm === 'police'): ?>
                  <span class="badge">Kun politi</span>
                <?php elseif (strpos($audNorm, 'dept:') === 0): ?>
                  <span class="badge"><?= h(substr($audNorm, 5)) ?></span>
                <?php endif; ?>
              </h3>
              <p class="muted"><?= nl2br(h($p['body'])) ?></p>
              <div class="meta">
                <span>🕒 <?= h($p['created_at']) ?></span>
                <span>•</span>
                <span>👤 <?= h($p['author_discord_id']) ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if (!$posts): ?>
          <p class="muted">Ingen interne opslag endnu.</p>
        <?php endif; ?>
      </div>
          <?php endif; ?>
    </div>
  </div>
</div>

<footer class="footer">
  © 2026 Redline Politidistrikt — FiveM Roleplay Server
</footer>
</body></html>
