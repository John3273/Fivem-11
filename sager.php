<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

require_role('POLICE','LEADER');

$db = db();
$u  = current_user();

// Migrations
$db->exec("CREATE TABLE IF NOT EXISTS cases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_uid TEXT UNIQUE NOT NULL,
  title TEXT NOT NULL,
  description TEXT NOT NULL,
  created_by_discord_id TEXT NOT NULL,
  created_by_name TEXT,
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
$db->exec("CREATE TRIGGER IF NOT EXISTS cases_updated_at
AFTER UPDATE ON cases
BEGIN
  UPDATE cases SET updated_at = datetime('now') WHERE id = NEW.id;
END");
$db->exec("CREATE TABLE IF NOT EXISTS case_visibility (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id INTEGER NOT NULL,
  target_type TEXT NOT NULL,
  target_value TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(case_id, target_type, target_value)
)");

$db->exec("CREATE TABLE IF NOT EXISTS case_evidence (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  file_path TEXT NOT NULL,
  original_name TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$ensureColumn = static function (PDO $db, string $table, string $column, string $definition): void {
  try {
    $db->query("SELECT {$column} FROM {$table} LIMIT 1");
  } catch (Throwable $e) {
    try { $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}"); } catch (Throwable $_) {}
  }
};
$ensureColumn($db, 'cases', 'leader_notes', 'TEXT');
$ensureColumn($db, 'cases', 'created_by_name', 'TEXT');
$ensureColumn($db, 'cases', 'evidence_path', 'TEXT');
$ensureColumn($db, 'users', 'police_display_name', 'TEXT');
$ensureColumn($db, 'users', 'police_badge_number', 'TEXT');
$ensureColumn($db, 'employees', 'secondary_department', 'TEXT');

$employees = $db->query("SELECT id,discord_id,name,badge_number,department,secondary_department FROM employees ORDER BY name")->fetchAll();
$departments = [];
try {
  $departments = $db->query("SELECT name AS department FROM departments ORDER BY sort_order, name")->fetchAll();
} catch (Throwable $e) {
  $departments = [];
}
if (!$departments) {
  $departments = $db->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(department),''),'Ukendt') AS department FROM employees ORDER BY department")->fetchAll();
}

$employeeById = [];
$employeeNameByDiscord = [];
foreach ($employees as $e) {
  $employeeById[(int)$e['id']] = $e;
  $did = trim((string)($e['discord_id'] ?? ''));
  if ($did !== '') {
    $name = trim((string)($e['name'] ?? ''));
    $employeeNameByDiscord[$did] = ($name !== '' ? $name : $did);
  }
}

$stmt = $db->prepare("SELECT police_display_name, police_badge_number FROM users WHERE discord_id=?");
$stmt->execute([$u['discord_id']]);
$profile = $stmt->fetch() ?: [];
$myName = trim((string)($profile['police_display_name'] ?? ''));
$myBadge = trim((string)($profile['police_badge_number'] ?? ''));
$myDisplayName = ($myName !== '' ? $myName : ($u['username'] ?? $u['discord_id'] ?? 'Ukendt'));

$stmt = $db->prepare("SELECT discord_id,department,secondary_department FROM employees WHERE name=? AND badge_number=?");
$stmt->execute([$myName, $myBadge]);
$myEmployeeRows = $stmt->fetchAll();

$stmt = $db->prepare("SELECT discord_id,department,secondary_department FROM employees WHERE discord_id=?");
$stmt->execute([(string)($u['discord_id'] ?? '')]);
$myEmployeeRows = array_merge($myEmployeeRows, $stmt->fetchAll());

$myDiscordIds = [];
$myDepartments = [];
foreach ($myEmployeeRows as $er) {
  if (!empty($er['discord_id'])) $myDiscordIds[] = $er['discord_id'];
  if (!empty($er['department'])) $myDepartments[] = $er['department'];
  if (!empty($er['secondary_department'])) $myDepartments[] = $er['secondary_department'];
}
if (!empty($u['discord_id'])) $myDiscordIds[] = $u['discord_id'];

$myDiscordIds = array_values(array_unique(array_filter($myDiscordIds)));
$myDepartments = array_values(array_unique(array_filter($myDepartments)));

$normalizeUploadPath = static function (?string $path): string {
  $p = trim((string)$path);
  if ($p === '') return '';
  $p = str_replace('\\', '/', $p);
  $p = ltrim($p, '/');
  if (strpos($p, './') === 0) $p = substr($p, 2);
  $uPos = strpos($p, 'uploads/');
  if ($uPos !== false) $p = substr($p, $uPos);
  return $p;
};

$redirectToCurrentView = static function (): void {
  $viewRaw = (string)($_POST['view'] ?? $_GET['view'] ?? 'mine');
  $allowed = ['mine','all','archive','global_archive'];
  $viewParam = in_array($viewRaw, $allowed, true) ? $viewRaw : 'mine';
  $qs = ['view=' . urlencode($viewParam)];
  $qVal = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
  if ($qVal !== '') $qs[] = 'q=' . urlencode($qVal);
  redirect('sager.php?' . implode('&', $qs));
};

$canAccessCase = static function (array $case) use ($db, $myDiscordIds, $myDepartments, $myName, $myBadge, $u): bool {
  if ((string)($case['created_by_discord_id'] ?? '') === (string)($u['discord_id'] ?? '')) return true;

  if (($case['assigned_type'] ?? '') === 'person') {
    $assignedDiscord = (string)($case['assigned_discord_id'] ?? '');
    if ($assignedDiscord !== '' && in_array($assignedDiscord, array_map('strval', $myDiscordIds), true)) return true;
    if ($myName !== '' && $myBadge !== ''
      && (string)($case['assigned_name'] ?? '') === $myName
      && (string)($case['assigned_badge'] ?? '') === $myBadge) return true;
  }

  if (($case['assigned_type'] ?? '') === 'department' && in_array((string)($case['assigned_department'] ?? ''), array_map('strval', $myDepartments), true)) {
    return true;
  }

  $caseId = (int)($case['id'] ?? 0);
  if ($caseId <= 0) return false;

  $parts = [];
  $params = [];
  if ($myDiscordIds) {
    $ph = implode(',', array_fill(0, count($myDiscordIds), '?'));
    $parts[] = "(target_type='employee' AND target_value IN ($ph))";
    $params = array_merge($params, $myDiscordIds);
  }
  if ($myDepartments) {
    $ph = implode(',', array_fill(0, count($myDepartments), '?'));
    $parts[] = "(target_type='department' AND target_value IN ($ph))";
    $params = array_merge($params, $myDepartments);
  }
  if (!$parts) return false;

  $stmt = $db->prepare("SELECT 1 FROM case_visibility WHERE case_id=? AND (" . implode(' OR ', $parts) . ") LIMIT 1");
  $stmt->execute(array_merge([$caseId], $params));
  return (bool)$stmt->fetchColumn();
};

$saveVisibility = static function (int $caseId, array $employeeIds, array $departmentNames) use ($db, $employeeById): void {
  $db->prepare("DELETE FROM case_visibility WHERE case_id=?")->execute([$caseId]);

  $ins = $db->prepare("INSERT OR IGNORE INTO case_visibility(case_id,target_type,target_value) VALUES(?,?,?)");
  foreach ($employeeIds as $eidRaw) {
    $eid = (int)$eidRaw;
    if ($eid > 0 && isset($employeeById[$eid])) {
      $did = trim((string)($employeeById[$eid]['discord_id'] ?? ''));
      if ($did !== '') $ins->execute([$caseId, 'employee', $did]);
    }
  }
  foreach ($departmentNames as $depRaw) {
    $dep = trim((string)$depRaw);
    if ($dep !== '') $ins->execute([$caseId, 'department', $dep]);
  }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'create_case') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $employeeIds = array_values(array_filter(array_map('intval', (array)($_POST['employee_ids'] ?? []))));
    $departmentNames = array_values(array_filter(array_map('trim', (array)($_POST['assigned_departments'] ?? []))));

    if ($title !== '' && $description !== '') {
      $uid = 'S-' . date('ymd') . '-' . substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ0123456789'), 0, 4);
      $stmt = $db->prepare("INSERT INTO cases(case_uid,title,description,created_by_discord_id,created_by_name,assigned_type,assigned_discord_id,assigned_name,assigned_badge,evidence_path)
                            VALUES(?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([
        $uid,
        $title,
        $description,
        $u['discord_id'],
        $myDisplayName,
        'person',
        $u['discord_id'],
        $myDisplayName,
        $myBadge,
        null
      ]);
      $newCaseId = (int)$db->lastInsertId();
      $saveVisibility($newCaseId, $employeeIds, $departmentNames);

      $evidenceTitle = trim((string)($_POST['evidence_title'] ?? ''));
      try {
        $evidencePath = save_upload('evidence');
        if ($evidencePath) {
          $safePath = $normalizeUploadPath($evidencePath);
          if ($safePath !== '') {
            $stmt = $db->prepare("INSERT INTO case_evidence(case_id, title, file_path, original_name) VALUES(?,?,?,?)");
            $stmt->execute([$newCaseId, ($evidenceTitle !== '' ? $evidenceTitle : 'Bevismateriale'), $safePath, basename((string)($_FILES['evidence']['name'] ?? $safePath))]);
          }
        }
      } catch (Throwable $e) { die("Upload fejl: " . h($e->getMessage())); }
    }
    redirect('sager.php');
  }

  if ($action === 'save_case_changes') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $employeeIds = array_values(array_filter(array_map('intval', (array)($_POST['employee_ids'] ?? []))));
    $departmentNames = array_values(array_filter(array_map('trim', (array)($_POST['assigned_departments'] ?? []))));
    $note = trim((string)($_POST['leader_notes'] ?? ''));
    $newEvidenceTitle = trim((string)($_POST['new_evidence_title'] ?? ''));

    if ($caseId > 0) {
      $stmt = $db->prepare("SELECT * FROM cases WHERE id=?");
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case) && (int)($case['archived'] ?? 0) === 0) {
        try {
          $up = save_upload('evidence');
          if ($up) {
            $safePath = $normalizeUploadPath($up);
            if ($safePath !== '') {
              $stmt = $db->prepare("INSERT INTO case_evidence(case_id, title, file_path, original_name) VALUES(?,?,?,?)");
              $stmt->execute([$caseId, ($newEvidenceTitle !== '' ? $newEvidenceTitle : 'Bevismateriale'), $safePath, basename((string)($_FILES['evidence']['name'] ?? $safePath))]);
            }
          }
        } catch (Throwable $e) {
          die("Upload fejl: " . h($e->getMessage()));
        }
        $saveVisibility($caseId, $employeeIds, $departmentNames);
        $stmt = $db->prepare("UPDATE cases SET leader_notes=?, status='Påbegyndt' WHERE id=?");
        $stmt->execute([$note, $caseId]);
      }
    }
    $redirectToCurrentView();
  }


  if ($action === 'delete_evidence') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $evidenceId = (int)($_POST['evidence_id'] ?? 0);
    if ($caseId > 0 && $evidenceId > 0) {
      $stmt = $db->prepare("SELECT * FROM cases WHERE id=?");
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case)) {
        $stmt = $db->prepare("SELECT file_path FROM case_evidence WHERE id=? AND case_id=?");
        $stmt->execute([$evidenceId, $caseId]);
        $path = (string)$stmt->fetchColumn();
        if ($path !== '') {
          $abs = __DIR__ . '/' . ltrim($path, '/');
          if (is_file($abs)) { try { unlink($abs); } catch (Throwable $_) {} }
        }
        $db->prepare("DELETE FROM case_evidence WHERE id=? AND case_id=?")->execute([$evidenceId, $caseId]);
      }
    }
    $redirectToCurrentView();
  }

  if ($action === 'archive_case') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    if ($caseId > 0) {
      $stmt = $db->prepare("SELECT file_path FROM case_evidence WHERE case_id=?");
      $stmt->execute([$caseId]);
      foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ev) {
        $abs = __DIR__ . '/' . ltrim((string)$ev, '/');
        if (is_file($abs)) { try { unlink($abs); } catch (Throwable $_) {} }
      }
      $db->prepare("DELETE FROM case_evidence WHERE case_id=?")->execute([$caseId]);
      $stmt = $db->prepare("UPDATE cases SET archived=1, status='Lukket', evidence_path=NULL WHERE id=?");
      $stmt->execute([$caseId]);
    }
    redirect('sager.php');
  }

  if ($action === 'delete_case') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    if ($caseId > 0) {
      $stmt = $db->prepare("SELECT * FROM cases WHERE id=?");
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case) && (int)($case['archived'] ?? 0) === 1) {
        $stmt = $db->prepare("SELECT file_path FROM case_evidence WHERE case_id=?");
        $stmt->execute([$caseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ev) {
          $abs = __DIR__ . '/' . ltrim((string)$ev, '/');
          if (is_file($abs)) { try { unlink($abs); } catch (Throwable $_) {} }
        }
        $db->prepare("DELETE FROM case_evidence WHERE case_id=?")->execute([$caseId]);
        $db->prepare("DELETE FROM case_visibility WHERE case_id=?")->execute([$caseId]);
        $db->prepare("DELETE FROM cases WHERE id=?")->execute([$caseId]);
      }
    }
    $redirectToCurrentView();
  }
}

$viewRaw = (string)($_GET['view'] ?? 'mine');
$view = in_array($viewRaw, ['mine','all','archive','global_archive'], true) ? $viewRaw : 'mine';
$search = trim($_GET['q'] ?? '');
$isGlobalArchiveView = $view === 'global_archive';
$isPersonalArchiveView = $view === 'archive';
$isArchivedView = $isGlobalArchiveView || $isPersonalArchiveView;

$visibleCaseIds = [];
$visParams = [];
$visParts = [];
if ($myDiscordIds) {
  $ph = implode(',', array_fill(0, count($myDiscordIds), '?'));
  $visParts[] = "(target_type='employee' AND target_value IN ($ph))";
  $visParams = array_merge($visParams, $myDiscordIds);
}
if ($myDepartments) {
  $ph = implode(',', array_fill(0, count($myDepartments), '?'));
  $visParts[] = "(target_type='department' AND target_value IN ($ph))";
  $visParams = array_merge($visParams, $myDepartments);
}
if ($visParts) {
  $stmt = $db->prepare("SELECT DISTINCT case_id FROM case_visibility WHERE " . implode(' OR ', $visParts));
  $stmt->execute($visParams);
  $visibleCaseIds = array_map(static fn($r) => (int)$r['case_id'], $stmt->fetchAll());
}

$where = ["archived=" . ($isArchivedView ? '1' : '0')];
$params = [];

if ($view === 'mine' || $view === 'archive') {
  $accessParts = ["created_by_discord_id=?", "(assigned_type='person' AND assigned_discord_id=?)"];
  $params[] = (string)$u['discord_id'];
  $params[] = (string)$u['discord_id'];

  if ($myDepartments) {
    $ph = implode(',', array_fill(0, count($myDepartments), '?'));
    $accessParts[] = "(assigned_type='department' AND assigned_department IN ($ph))";
    $params = array_merge($params, $myDepartments);
  }
  if ($visibleCaseIds) {
    $ph = implode(',', array_fill(0, count($visibleCaseIds), '?'));
    $accessParts[] = "id IN ($ph)";
    $params = array_merge($params, $visibleCaseIds);
  }
  $where[] = '(' . implode(' OR ', $accessParts) . ')';
}

if ($search !== '') {
  $where[] = "(case_uid LIKE ? OR title LIKE ? OR description LIKE ? OR COALESCE(created_by_name,'') LIKE ? OR COALESCE(created_by_discord_id,'') LIKE ?)";
  $like = '%' . $search . '%';
  array_push($params, $like, $like, $like, $like, $like);
}

$stmt = $db->prepare("SELECT * FROM cases WHERE " . implode(' AND ', $where) . " ORDER BY id DESC");
$stmt->execute($params);
$visibleCases = $stmt->fetchAll();

$caseVisibility = [];
if ($visibleCases) {
  $ids = array_map(static fn($c) => (int)$c['id'], $visibleCases);
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $db->prepare("SELECT case_id,target_type,target_value FROM case_visibility WHERE case_id IN ($ph) ORDER BY id ASC");
  $stmt->execute($ids);
  foreach ($stmt->fetchAll() as $row) {
    $cid = (int)$row['case_id'];
    if (!isset($caseVisibility[$cid])) $caseVisibility[$cid] = ['employees' => [], 'departments' => []];
    if ($row['target_type'] === 'employee') $caseVisibility[$cid]['employees'][] = (string)$row['target_value'];
    if ($row['target_type'] === 'department') $caseVisibility[$cid]['departments'][] = (string)$row['target_value'];
  }
}

$caseEvidence = [];
if ($visibleCases) {
  $ids = array_map(static fn($c) => (int)$c['id'], $visibleCases);
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $db->prepare("SELECT id, case_id, title, file_path, original_name, created_at FROM case_evidence WHERE case_id IN ($ph) ORDER BY created_at DESC, id DESC");
  $stmt->execute($ids);
  foreach ($stmt->fetchAll() as $ev) {
    $caseEvidence[(int)$ev['case_id']][] = $ev;
  }
}

include __DIR__ . '/_layout.php';
?>
<div class="container">
  <div class="card">
    <h2>Opret sag</h2>
    <details style="margin-top:12px">
      <summary class="btn btn-solid">+ Opret sag</summary>
    <form method="post" enctype="multipart/form-data" style="margin-top:12px">
      <input type="hidden" name="action" value="create_case"/>
      <label>Titel</label>
      <input name="title" required>
      <label>Beskrivelse</label>
      <textarea name="description" rows="4" required></textarea>
      <label>Bevis titel (valgfri)</label>
      <input name="evidence_title" placeholder="Fx. Bodycam klip fra 12/04">
      <label>Bevismateriale (valgfri)</label>
      <input type="file" name="evidence" accept="image/*,.pdf,.txt,.zip,.rar,.7z,.doc,.docx">

      <label>Synlig for afdelinger</label>
      <input type="text" class="assign-filter" placeholder="Søg afdeling...">
      <div data-filter-list style="border:1px solid var(--border);border-radius:6px;max-height:170px;overflow:auto;padding:6px;">
        <table class="table"><tbody>
        <?php foreach ($departments as $d): ?>
          <tr class="assign-row"><td style="width:36px;"><input style="width:auto;" type="checkbox" name="assigned_departments[]" value="<?= h($d['department']) ?>"></td><td><?= h($d['department']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>

      <label>Synlig for medarbejdere</label>
      <input type="text" class="assign-filter" placeholder="Søg medarbejder...">
      <div data-filter-list style="border:1px solid var(--border);border-radius:6px;max-height:220px;overflow:auto;padding:6px;">
        <table class="table"><tbody>
        <?php foreach ($employees as $e): ?>
          <tr class="assign-row"><td style="width:36px;"><input style="width:auto;" type="checkbox" name="employee_ids[]" value="<?= (int)$e['id'] ?>"></td><td><?= h($e['name']) ?> (<?= h($e['badge_number']) ?>)</td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>

      <div style="margin-top:12px;"><button type="submit">Opret sag</button></div>
    </form>
    </details>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:end;">
      <div>
        <a class="btn <?= $view==='mine' ? 'btn-solid' : '' ?>" href="sager.php?view=mine">Mine Sager</a>
        <a class="btn <?= $view==='all' ? 'btn-solid' : '' ?>" href="sager.php?view=all">Alle Sager</a>
        <a class="btn <?= $view==='archive' ? 'btn-solid' : '' ?>" href="sager.php?view=archive">Mit arkiv</a>
        <a class="btn <?= $view==='global_archive' ? 'btn-solid' : '' ?>" href="sager.php?view=global_archive">Globalt arkiv</a>
      </div>
      <form method="get" style="display:flex;gap:8px;align-items:end;">
        <input type="hidden" name="view" value="<?= h($view) ?>"/>
        <div>
          <label style="margin:0 0 4px 0;">Søg sager</label>
          <input name="q" value="<?= h($search) ?>" placeholder="Søg på ID, titel, tekst..."/>
        </div>
        <button type="submit">Søg</button>
      </form>
    </div>
  </div>

  <div class="card">
    <h2>
      <?php if ($view==='all'): ?>Alle Sager<?php elseif ($view==='global_archive'): ?>Globalt arkiv<?php elseif ($view==='archive'): ?>Mit arkiv<?php else: ?>Mine Sager<?php endif; ?>
    </h2>
    <table class="table"><thead><tr><th>ID</th><th>Titel</th><th>Lavet af</th><th>Tildelt</th><th>Status</th><th>Handling</th></tr></thead><tbody>
      <?php foreach ($visibleCases as $c): ?>
        <tr>
          <?php
            $cv = $caseVisibility[(int)$c['id']] ?? ['employees' => [], 'departments' => []];
            $empNames = [];
            foreach (($cv['employees'] ?? []) as $did) $empNames[] = $employeeNameByDiscord[$did] ?? $did;
            $depNames = $cv['departments'] ?? [];
            $assignedParts = [];
            if ($depNames) $assignedParts[] = 'Afdelinger: ' . implode(', ', $depNames);
            if ($empNames) $assignedParts[] = 'Medarbejdere: ' . implode(', ', $empNames);
            $createdByLabel = trim((string)($c['created_by_name'] ?? ''));
            if ($createdByLabel === '') $createdByLabel = (string)($c['created_by_discord_id'] ?? 'Ukendt');
          ?>
          <td><?= h($c['case_uid']) ?></td>
          <td><?= h($c['title']) ?></td>
          <td><?= h($createdByLabel) ?></td>
          <td><?= $assignedParts ? h(implode(' | ', $assignedParts)) : '<span class="muted">Ingen</span>' ?></td>
          <td><?= h($c['status']) ?></td>
          <td>
            <button class="btn" type="button" onclick="openCaseModal(<?= (int)$c['id'] ?>)">Åbn</button>
            <?php if (!$isArchivedView): ?>
              <form method="post" style="display:inline-block;">
                <input type="hidden" name="action" value="archive_case"/>
                <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
                <button type="submit">Arkivér</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$visibleCases): ?><tr><td colspan="6">Ingen sager.</td></tr><?php endif; ?>
    </tbody></table>
  </div>

  <?php foreach ($visibleCases as $c): ?>
    <div id="case-content-<?= (int)$c['id'] ?>" style="display:none;">
      <?php
        $cvm = $caseVisibility[(int)$c['id']] ?? ['employees' => [], 'departments' => []];
        $empNamesM = [];
        foreach (($cvm['employees'] ?? []) as $did) $empNamesM[] = $employeeNameByDiscord[$did] ?? $did;
        $assignedLabel = implode(', ', array_filter(array_merge(($cvm['departments'] ?? []), $empNamesM)));
        $createdByModal = trim((string)($c['created_by_name'] ?? ''));
        if ($createdByModal === '') $createdByModal = (string)($c['created_by_discord_id'] ?? 'Ukendt');
      ?>
      <h3 style="margin-top:0;"><?= h($c['title']) ?></h3>
      <p><b>Rapport ID:</b> <?= h($c['case_uid']) ?></p>
      <p><b>Lavet af:</b> <?= h($createdByModal) ?></p>
      <p><b>Beskrivelse:</b><br><?= nl2br(h($c['description'])) ?></p>
      <h4 style="margin:12px 0 8px 0;">Bevismateriale</h4>
      <?php if (!$isArchivedView): ?>
      <form method="post" enctype="multipart/form-data" style="margin:0 0 10px 0;padding:10px;border:1px solid rgba(255,255,255,.08);border-radius:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="action" value="save_case_changes"/>
        <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
        <input type="hidden" name="view" value="<?= h($view) ?>"/>
        <input type="hidden" name="q" value="<?= h($search) ?>"/>
        <input type="text" name="new_evidence_title" placeholder="Titel på bilag" style="min-width:220px;">
        <input type="file" name="evidence" accept="image/*,.pdf,.txt,.zip,.rar,.7z,.doc,.docx" required>
        <button type="submit" class="btn btn-solid">Tilføj bilag</button>
      </form>
      <?php endif; ?>
      <table class="table">
        <thead><tr><th>Bevis titel</th><th>Tilføjet dato</th><th>Fil</th><?php if (!$isArchivedView): ?><th>Handling</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach (($caseEvidence[(int)$c['id']] ?? []) as $ev): ?>
          <tr>
            <td><?= h($ev['title']) ?></td>
            <td><?= h($ev['created_at']) ?></td>
                        <?php
              $normalizedPath = $normalizeUploadPath((string)($ev['file_path'] ?? ''));
              $downloadName = (string)($ev['original_name'] ?: basename($normalizedPath));
            ?>
            <td><a href="download_file.php?path=<?= urlencode($normalizedPath) ?>&filename=<?= urlencode($downloadName) ?>" download><?= h($downloadName) ?></a></td>
            <?php if (!$isArchivedView): ?>
            <td>
              <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="delete_evidence"/>
                <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
                <input type="hidden" name="evidence_id" value="<?= (int)$ev['id'] ?>"/>
                <input type="hidden" name="view" value="<?= h($view) ?>"/>
                <input type="hidden" name="q" value="<?= h($search) ?>"/>
                <button type="submit" class="btn" onclick="return confirm('Fjern bevis?');">Fjern</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($caseEvidence[(int)$c['id']] ?? [])): ?>
          <tr><td colspan="4"><span class="muted">Ingen bevismateriale.</span></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      <p><b>Tildelt til:</b> <?= $assignedLabel !== '' ? h($assignedLabel) : '<span class="muted">Ingen</span>' ?></p>

      <?php if (!$isArchivedView): ?>
      <?php $currentVisibility = $caseVisibility[(int)$c['id']] ?? ['employees' => [], 'departments' => []]; ?>
      <form method="post" enctype="multipart/form-data" style="margin-top:12px;">
        <input type="hidden" name="action" value="save_case_changes"/>
        <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
        <input type="hidden" name="view" value="<?= h($view) ?>"/>
        <input type="hidden" name="q" value="<?= h($search) ?>"/>

        <label>Synlig for afdelinger</label>
        <input type="text" class="assign-filter" placeholder="Søg afdeling...">
        <div data-filter-list style="border:1px solid var(--border);border-radius:6px;max-height:170px;overflow:auto;padding:6px;">
          <table class="table"><tbody>
          <?php foreach ($departments as $d): ?>
            <tr class="assign-row"><td style="width:36px;"><input style="width:auto;" type="checkbox" name="assigned_departments[]" value="<?= h($d['department']) ?>" <?= in_array((string)$d['department'], $currentVisibility['departments'], true) ? 'checked' : '' ?>></td><td><?= h($d['department']) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>

        <label>Synlig for medarbejdere</label>
        <input type="text" class="assign-filter" placeholder="Søg medarbejder...">
        <div data-filter-list style="border:1px solid var(--border);border-radius:6px;max-height:220px;overflow:auto;padding:6px;">
          <table class="table"><tbody>
          <?php foreach ($employees as $e): ?>
            <tr class="assign-row"><td style="width:36px;"><input style="width:auto;" type="checkbox" name="employee_ids[]" value="<?= (int)$e['id'] ?>" <?= in_array((string)$e['discord_id'], $currentVisibility['employees'], true) ? 'checked' : '' ?>></td><td><?= h($e['name']) ?> (<?= h($e['badge_number']) ?>)</td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>

        <label>Note</label>
        <textarea name="leader_notes" rows="4" placeholder="Interne noter om sagen..."><?= h($c['leader_notes'] ?? '') ?></textarea>

        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
          <button type="submit">Gem Ændringer</button>
        </div>
      </form>
      <?php else: ?>
      <h4 style="margin:12px 0 8px 0;">Note</h4>
      <div style="padding:8px;border:1px solid rgba(255,255,255,.08);border-radius:8px;white-space:pre-wrap;"><?= h((string)($c['leader_notes'] ?? '')) !== '' ? nl2br(h($c['leader_notes'])) : '<span class="muted">Ingen note.</span>' ?></div>
      <form method="post" style="margin-top:12px;">
        <input type="hidden" name="action" value="delete_case"/>
        <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
        <input type="hidden" name="view" value="<?= h($view) ?>"/>
        <input type="hidden" name="q" value="<?= h($search) ?>"/>
        <button type="submit" style="background:#a00;">Slet</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div id="caseModal" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;">
  <div style="max-width:900px;margin:7vh auto;background:#fff;padding:18px;border-radius:8px;max-height:85vh;overflow:auto;position:relative;">
    <button type="button" class="btn" onclick="closeCaseModal()" style="position:absolute;right:12px;top:12px;">Luk</button>
    <div id="caseModalContent"></div>
  </div>
</div>

<script>
function setupAssignmentFilters(root) {
  const scope = root || document;
  const filters = scope.querySelectorAll('.assign-filter');
  filters.forEach(function(input){
    if (input.dataset.bound === '1') return;
    input.dataset.bound = '1';
    const listWrap = input.nextElementSibling;
    if (!listWrap || !listWrap.hasAttribute('data-filter-list')) return;
    input.addEventListener('input', function(){
      const q = input.value.toLowerCase().trim();
      listWrap.querySelectorAll('.assign-row').forEach(function(row){
        const txt = row.textContent.toLowerCase();
        row.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  });
}

function openCaseModal(id) {
  const src = document.getElementById('case-content-' + id);
  const modal = document.getElementById('caseModal');
  const content = document.getElementById('caseModalContent');
  if (!src || !modal || !content) return;
  content.innerHTML = src.innerHTML;
  modal.style.display = 'block';
  setupAssignmentFilters(content);
}
function closeCaseModal() {
  const modal = document.getElementById('caseModal');
  if (modal) modal.style.display = 'none';
}
window.addEventListener('click', function(e){
  const modal = document.getElementById('caseModal');
  if (e.target === modal) closeCaseModal();
});
setupAssignmentFilters(document);
</script>

<footer class="footer">© 2026 Redline Politidistrikt — FiveM Roleplay Server</footer>
</body></html>