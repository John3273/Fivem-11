<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

require_role('POLICE','LEADER');

$db = db();
$u  = current_user();
$isLeader = in_array('LEADER', $u['roles'] ?? [], true);

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
$db->exec("CREATE TABLE IF NOT EXISTS case_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id INTEGER NOT NULL,
  sender_discord_id TEXT,
  sender_name TEXT NOT NULL,
  sender_label TEXT NOT NULL,
  sender_type TEXT NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS case_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id INTEGER NOT NULL,
  log_text TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$db->exec("CREATE TABLE IF NOT EXISTS case_archive_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id INTEGER NOT NULL,
  target_type TEXT NOT NULL,
  target_value TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(case_id, target_type, target_value)
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
$ensureColumn($db, 'cases', 'responsible_discord_id', 'TEXT');
$ensureColumn($db, 'cases', 'responsible_name', 'TEXT');
$ensureColumn($db, 'cases', 'responsible_badge', 'TEXT');
$ensureColumn($db, 'cases', 'case_type_text', 'TEXT');
$ensureColumn($db, 'cases', 'offense_code', 'TEXT');
$ensureColumn($db, 'cases', 'journal_number', 'TEXT');
$ensureColumn($db, 'cases', 'archive_bucket', "TEXT NOT NULL DEFAULT 'stam'");
$ensureColumn($db, 'cases', 'henlagt_reason', 'TEXT');
$ensureColumn($db, 'users', 'police_display_name', 'TEXT');
$ensureColumn($db, 'users', 'police_badge_number', 'TEXT');
$ensureColumn($db, 'employees', 'secondary_department', 'TEXT');

$typePath = __DIR__ . '/case_types.json';
$caseTypes = [];
if (is_file($typePath)) {
  $decoded = json_decode((string)file_get_contents($typePath), true);
  if (is_array($decoded)) {
    foreach ($decoded as $entry) {
      $txt = trim((string)($entry['GERNINGTXT'] ?? ''));
      $code = trim((string)($entry['GERNINGSKODE'] ?? ''));
      if ($txt !== '' && $code !== '') {
        $caseTypes[] = ['GERNINGTXT' => $txt, 'GERNINGSKODE' => $code];
      }
    }
  }
}

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
$employeeBadgeByDiscord = [];
foreach ($employees as $e) {
  $employeeById[(int)$e['id']] = $e;
  $did = trim((string)($e['discord_id'] ?? ''));
  if ($did !== '') {
    $name = trim((string)($e['name'] ?? ''));
    $badge = trim((string)($e['badge_number'] ?? ''));
    $employeeNameByDiscord[$did] = ($name !== '' ? $name : $did);
    $employeeBadgeByDiscord[$did] = $badge;
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
  $archiveTab = trim((string)($_POST['archive_tab'] ?? $_GET['archive_tab'] ?? 'stam'));
  if (in_array($archiveTab, ['stam', 'henlagt'], true)) $qs[] = 'archive_tab=' . urlencode($archiveTab);
  $qVal = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
  if ($qVal !== '') $qs[] = 'q=' . urlencode($qVal);
  redirect('sager.php?' . implode('&', $qs));
};

$formatDate = static function (string $dbDate): string {
  $ts = strtotime($dbDate);
  return $ts ? date('n/j/Y', $ts) : date('n/j/Y');
};

$appendCaseLog = static function (int $caseId, string $text) use ($db): void {
  $stmt = $db->prepare("INSERT INTO case_logs(case_id, log_text) VALUES(?,?)");
  $stmt->execute([$caseId, $text]);
};

$visibilityPairsForCase = static function (array $visibilityRows): array {
  $pairs = [];
  foreach ($visibilityRows as $row) {
    $type = trim((string)($row['target_type'] ?? ''));
    $value = trim((string)($row['target_value'] ?? ''));
    if ($type === '' || $value === '') continue;
    $pairs[$type . ':' . $value] = ['target_type' => $type, 'target_value' => $value];
  }
  return $pairs;
};

$fetchCaseVisibilityRows = static function (int $caseId) use ($db): array {
  $stmt = $db->prepare('SELECT target_type,target_value FROM case_visibility WHERE case_id=? ORDER BY id ASC');
  $stmt->execute([$caseId]);
  return $stmt->fetchAll();
};

$nextJournalNumber = static function (string $offenseCode) use ($db): string {
  $stmt = $db->prepare("SELECT COUNT(*) FROM cases WHERE offense_code=?");
  $stmt->execute([$offenseCode]);
  $sequence = ((int)$stmt->fetchColumn()) + 1;
  return '1000-' . $offenseCode . '-' . str_pad((string)$sequence, 5, '0', STR_PAD_LEFT) . '-' . date('y');
};

$canAccessCase = static function (array $case) use ($db, $myDiscordIds, $myDepartments, $myName, $myBadge, $u, $isLeader): bool {
  if ($isLeader) return true;
  if ((string)($case['created_by_discord_id'] ?? '') === (string)($u['discord_id'] ?? '')) return true;

  $responsibleDiscord = (string)($case['responsible_discord_id'] ?? $case['assigned_discord_id'] ?? '');
  if ($responsibleDiscord !== '' && in_array($responsibleDiscord, array_map('strval', $myDiscordIds), true)) return true;

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

$isEmployeeCoveredByVisibility = static function (string $discordId, array $visibilityRows) use ($employees): bool {
  $discordId = trim($discordId);
  if ($discordId === '') return false;

  $visibleEmployees = [];
  $visibleDepartments = [];
  foreach ($visibilityRows as $row) {
    $type = trim((string)($row['target_type'] ?? ''));
    $value = trim((string)($row['target_value'] ?? ''));
    if ($type === 'employee' && $value !== '') $visibleEmployees[] = $value;
    if ($type === 'department' && $value !== '') $visibleDepartments[] = $value;
  }

  if (in_array($discordId, $visibleEmployees, true)) return true;
  if (!$visibleDepartments) return false;

  foreach ($employees as $employee) {
    $did = trim((string)($employee['discord_id'] ?? ''));
    if ($did !== $discordId) continue;

    $department = trim((string)($employee['department'] ?? ''));
    $secondaryDepartment = trim((string)($employee['secondary_department'] ?? ''));
    if ($department !== '' && in_array($department, $visibleDepartments, true)) return true;
    if ($secondaryDepartment !== '' && in_array($secondaryDepartment, $visibleDepartments, true)) return true;
  }

  return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'create_case') {
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $selectedType = trim((string)($_POST['case_type_text'] ?? ''));
    $employeeIds = array_values(array_filter(array_map('intval', (array)($_POST['employee_ids'] ?? []))));
    $departmentNames = array_values(array_filter(array_map('trim', (array)($_POST['assigned_departments'] ?? []))));

    $offenseCode = '';
    foreach ($caseTypes as $t) {
      if ($t['GERNINGTXT'] === $selectedType) {
        $offenseCode = $t['GERNINGSKODE'];
        break;
      }
    }

    if ($title === '' && $selectedType !== '') {
      $title = $selectedType;
    }

    if ($title !== '' && $description !== '' && $selectedType !== '' && $offenseCode !== '') {
      $journal = $nextJournalNumber($offenseCode);
      $uid = 'S-' . date('ymd') . '-' . substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ0123456789'), 0, 4);
      $stmt = $db->prepare("INSERT INTO cases(case_uid,title,description,created_by_discord_id,created_by_name,assigned_type,assigned_discord_id,assigned_name,assigned_badge,responsible_discord_id,responsible_name,responsible_badge,case_type_text,offense_code,journal_number,evidence_path)
                            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
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
        $u['discord_id'],
        $myDisplayName,
        $myBadge,
        $selectedType,
        $offenseCode,
        $journal,
        null
      ]);
      $newCaseId = (int)$db->lastInsertId();
      $saveVisibility($newCaseId, $employeeIds, $departmentNames);
      $appendCaseLog($newCaseId, 'Sagen blev oprettet af "' . $myDisplayName . '" - ' . $formatDate(date('Y-m-d H:i:s')));

      $evidenceTitle = trim((string)($_POST['evidence_title'] ?? ''));
      if (!empty($_FILES['evidence']['name']) && is_array($_FILES['evidence']['name'])) {
        $count = count((array)$_FILES['evidence']['name']);
        for ($i = 0; $i < $count; $i++) {
          if ((int)($_FILES['evidence']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
          $single = [
            'name' => $_FILES['evidence']['name'][$i] ?? '',
            'type' => $_FILES['evidence']['type'][$i] ?? '',
            'tmp_name' => $_FILES['evidence']['tmp_name'][$i] ?? '',
            'error' => $_FILES['evidence']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $_FILES['evidence']['size'][$i] ?? 0,
          ];
          $orig = $_FILES['evidence'] ?? null;
          $_FILES['evidence'] = $single;
          try {
            $evidencePath = save_upload('evidence');
            if ($evidencePath) {
              $safePath = $normalizeUploadPath($evidencePath);
              if ($safePath !== '') {
                $stmt = $db->prepare("INSERT INTO case_evidence(case_id, title, file_path, original_name) VALUES(?,?,?,?)");
                $stmt->execute([$newCaseId, ($evidenceTitle !== '' ? $evidenceTitle : 'Bevismateriale'), $safePath, basename((string)$single['name'] ?: $safePath)]);
              }
            }
          } catch (Throwable $e) {
            die('Upload fejl: ' . h($e->getMessage()));
          }
          $_FILES['evidence'] = $orig;
        }
      } else {
        try {
          $evidencePath = save_upload('evidence');
          if ($evidencePath) {
            $safePath = $normalizeUploadPath($evidencePath);
            if ($safePath !== '') {
              $stmt = $db->prepare("INSERT INTO case_evidence(case_id, title, file_path, original_name) VALUES(?,?,?,?)");
              $stmt->execute([$newCaseId, ($evidenceTitle !== '' ? $evidenceTitle : 'Bevismateriale'), $safePath, basename((string)($_FILES['evidence']['name'] ?? $safePath))]);
            }
          }
        } catch (Throwable $e) {
          die('Upload fejl: ' . h($e->getMessage()));
        }
      }
    }
    redirect('sager.php');
  }

  if ($action === 'save_case_changes') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $employeeIds = array_values(array_filter(array_map('intval', (array)($_POST['employee_ids'] ?? []))));
    $departmentNames = array_values(array_filter(array_map('trim', (array)($_POST['assigned_departments'] ?? []))));
    $newEvidenceTitle = trim((string)($_POST['new_evidence_title'] ?? ''));

    if ($caseId > 0) {
      $stmt = $db->prepare('SELECT * FROM cases WHERE id=?');
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case) && (int)($case['archived'] ?? 0) === 0) {
        $responsibleDiscord = trim((string)($case['responsible_discord_id'] ?? $case['assigned_discord_id'] ?? ''));
        $canManageAssignments = $responsibleDiscord !== '' && $responsibleDiscord === (string)($u['discord_id'] ?? '');
        $beforeVisibility = $visibilityPairsForCase($fetchCaseVisibilityRows($caseId));

        try {
          $up = save_upload('evidence');
          if ($up) {
            $safePath = $normalizeUploadPath($up);
            if ($safePath !== '') {
              $stmt = $db->prepare('INSERT INTO case_evidence(case_id, title, file_path, original_name) VALUES(?,?,?,?)');
              $stmt->execute([$caseId, ($newEvidenceTitle !== '' ? $newEvidenceTitle : 'Bevismateriale'), $safePath, basename((string)($_FILES['evidence']['name'] ?? $safePath))]);
            }
          }
        } catch (Throwable $e) {
          die('Upload fejl: ' . h($e->getMessage()));
        }
        if ($canManageAssignments) {
          $saveVisibility($caseId, $employeeIds, $departmentNames);
        }

        $afterVisibility = $visibilityPairsForCase($fetchCaseVisibilityRows($caseId));
        $dateText = $formatDate(date('Y-m-d H:i:s'));

        foreach ($afterVisibility as $key => $entry) {
          if (!isset($beforeVisibility[$key])) {
            $label = $entry['target_type'] === 'employee'
              ? ($employeeNameByDiscord[$entry['target_value']] ?? $entry['target_value'])
              : $entry['target_value'];
            $appendCaseLog($caseId, 'Tildelt af "' . $myDisplayName . '": "' . $label . '" - ' . $dateText);
          }
        }
        foreach ($beforeVisibility as $key => $entry) {
          if (!isset($afterVisibility[$key])) {
            $label = $entry['target_type'] === 'employee'
              ? ($employeeNameByDiscord[$entry['target_value']] ?? $entry['target_value'])
              : $entry['target_value'];
            $appendCaseLog($caseId, 'Fradelt af "' . $myDisplayName . '": "' . $label . '" - ' . $dateText);
          }
        }

        $db->prepare("UPDATE cases SET status='Påbegyndt' WHERE id=?")->execute([$caseId]);
      }
    }
    $redirectToCurrentView();
  }

  if ($action === 'transfer_responsible' && $isLeader) {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $newResponsible = (int)($_POST['responsible_employee_id'] ?? 0);
    if ($caseId > 0 && $newResponsible > 0 && isset($employeeById[$newResponsible])) {
      $stmt = $db->prepare('SELECT * FROM cases WHERE id=?');
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case) && (int)($case['archived'] ?? 0) === 0) {
        $emp = $employeeById[$newResponsible];
        $newDid = trim((string)($emp['discord_id'] ?? ''));
        $newName = trim((string)($emp['name'] ?? ''));
        $newBadge = trim((string)($emp['badge_number'] ?? ''));
        $oldDid = trim((string)($case['responsible_discord_id'] ?? $case['assigned_discord_id'] ?? ''));
        $oldName = trim((string)($case['responsible_name'] ?? $case['assigned_name'] ?? 'Ukendt'));

        $db->prepare("UPDATE cases SET responsible_discord_id=?, responsible_name=?, responsible_badge=?, assigned_discord_id=?, assigned_name=?, assigned_badge=?, status='Påbegyndt' WHERE id=?")
          ->execute([$newDid, $newName, $newBadge, $newDid, $newName, $newBadge, $caseId]);

        $insArchiveAssignment = $db->prepare('INSERT OR IGNORE INTO case_archive_assignments(case_id,target_type,target_value) VALUES(?,?,?)');
        if ($newDid !== '') {
          $insArchiveAssignment->execute([$caseId, 'employee', $newDid]);
          $db->prepare("INSERT OR IGNORE INTO case_visibility(case_id,target_type,target_value) VALUES(?, 'employee', ?)")
            ->execute([$caseId, $newDid]);
        }

        if ($oldDid !== '' && $oldDid !== $newDid) {
          $db->prepare("DELETE FROM case_visibility WHERE case_id=? AND target_type='employee' AND target_value=?")
            ->execute([$caseId, $oldDid]);

          $visibilityRows = $fetchCaseVisibilityRows($caseId);
          $oldStillAssigned = $isEmployeeCoveredByVisibility($oldDid, $visibilityRows);
          if (!$oldStillAssigned) {
            $db->prepare("DELETE FROM case_archive_assignments WHERE case_id=? AND target_type='employee' AND target_value=?")
              ->execute([$caseId, $oldDid]);
          }
        }

        $dateText = $formatDate(date('Y-m-d H:i:s'));
        $appendCaseLog($caseId, '"' . $oldName . '" fik fradelt Sagsansvarlighed "' . $newName . '" fik tildelt Sagsansvarlighed - ' . $dateText);
      }
    }
    $redirectToCurrentView();
  }


  if ($action === 'send_message') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));
    if ($caseId > 0 && $message !== '') {
      $stmt = $db->prepare('SELECT * FROM cases WHERE id=?');
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case)) {
        $isPoliceSender = in_array('POLICE', $u['roles'] ?? [], true) || in_array('LEADER', $u['roles'] ?? [], true);
        $senderType = $isPoliceSender ? 'police' : 'citizen';
        $senderLabel = $isPoliceSender
          ? $myDisplayName . ' (betjent)' . ($myBadge !== '' ? ' - ' . $myBadge : '')
          : $myDisplayName . ' (borger)';

        $stmt = $db->prepare('INSERT INTO case_messages(case_id,sender_discord_id,sender_name,sender_label,sender_type,message) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$caseId, (string)($u['discord_id'] ?? ''), $myDisplayName, $senderLabel, $senderType, $message]);
      }
    }

    $viewRaw = (string)($_POST['view'] ?? $_GET['view'] ?? 'mine');
    $allowed = ['mine','all','archive','global_archive'];
    $viewParam = in_array($viewRaw, $allowed, true) ? $viewRaw : 'mine';
    $qs = ['view=' . urlencode($viewParam), 'open_case=' . urlencode((string)$caseId)];
    $qVal = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
    if ($qVal !== '') $qs[] = 'q=' . urlencode($qVal);
    redirect('sager.php?' . implode('&', $qs));
  }

  if ($action === 'delete_evidence') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $evidenceId = (int)($_POST['evidence_id'] ?? 0);
    if ($caseId > 0 && $evidenceId > 0) {
      $stmt = $db->prepare('SELECT * FROM cases WHERE id=?');
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case)) {
        $stmt = $db->prepare('SELECT file_path FROM case_evidence WHERE id=? AND case_id=?');
        $stmt->execute([$evidenceId, $caseId]);
        $path = (string)$stmt->fetchColumn();
        if ($path !== '') {
          $abs = __DIR__ . '/' . ltrim($path, '/');
          if (is_file($abs)) { try { unlink($abs); } catch (Throwable $_) {} }
        }
        $db->prepare('DELETE FROM case_evidence WHERE id=? AND case_id=?')->execute([$evidenceId, $caseId]);
      }
    }
    $redirectToCurrentView();
  }

  if ($action === 'archive_case') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    if ($caseId > 0) {
      $stmt = $db->prepare('SELECT * FROM cases WHERE id=?');
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case)) {


        $db->prepare('DELETE FROM case_archive_assignments WHERE case_id=?')->execute([$caseId]);
        $visibilityRows = $fetchCaseVisibilityRows($caseId);
        $insArchiveAssignment = $db->prepare('INSERT OR IGNORE INTO case_archive_assignments(case_id,target_type,target_value) VALUES(?,?,?)');
        foreach ($visibilityRows as $row) {
          $type = trim((string)($row['target_type'] ?? ''));
          $value = trim((string)($row['target_value'] ?? ''));
          if ($type !== '' && $value !== '') {
            $insArchiveAssignment->execute([$caseId, $type, $value]);
          }
        }

        $responsibleDiscord = trim((string)($case['responsible_discord_id'] ?? $case['assigned_discord_id'] ?? ''));
        if ($responsibleDiscord !== '') {
          $insArchiveAssignment->execute([$caseId, 'employee', $responsibleDiscord]);
        }

        $db->prepare("UPDATE cases SET archived=1, status='Arkiveret/Lukket', archive_bucket='stam', evidence_path=NULL, henlagt_reason=NULL WHERE id=?")->execute([$caseId]);
      }
    }
    redirect('sager.php');
  }

  if ($action === 'reopen_case') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    if ($caseId > 0) {
      $stmt = $db->prepare('SELECT * FROM cases WHERE id=?');
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case) && (string)($case['archive_bucket'] ?? 'stam') !== 'henlagt') {
        $db->prepare('DELETE FROM case_visibility WHERE case_id=?')->execute([$caseId]);
        $stmt = $db->prepare('SELECT target_type,target_value FROM case_archive_assignments WHERE case_id=? ORDER BY id ASC');
        $stmt->execute([$caseId]);
        $ins = $db->prepare('INSERT OR IGNORE INTO case_visibility(case_id,target_type,target_value) VALUES(?,?,?)');
        $restoredResponsible = trim((string)($case['responsible_discord_id'] ?? $case['assigned_discord_id'] ?? ''));
        foreach ($stmt->fetchAll() as $row) {
          $type = trim((string)($row['target_type'] ?? ''));
          $value = trim((string)($row['target_value'] ?? ''));
          if ($type === '' || $value === '') continue;
          $ins->execute([$caseId, $type, $value]);
        }
        $respName = $employeeNameByDiscord[$restoredResponsible] ?? trim((string)($case['responsible_name'] ?? ''));
        $respBadge = $employeeBadgeByDiscord[$restoredResponsible] ?? trim((string)($case['responsible_badge'] ?? ''));
        $db->prepare("UPDATE cases SET archived=0, archive_bucket='stam', henlagt_reason=NULL, status='Påbegyndt', responsible_discord_id=?, responsible_name=?, responsible_badge=?, assigned_discord_id=?, assigned_name=?, assigned_badge=? WHERE id=?")
          ->execute([$restoredResponsible, $respName, $respBadge, $restoredResponsible, $respName, $respBadge, $caseId]);
      }
    }
    $redirectToCurrentView();
  }

  if ($action === 'henlaeg_case') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $reason = trim((string)($_POST['henlagt_reason'] ?? ''));
    if ($caseId > 0 && $reason !== '') {
      $stmt = $db->prepare('SELECT * FROM cases WHERE id=?');
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case) && (int)($case['archived'] ?? 0) === 1) {
        $db->prepare("UPDATE cases SET archive_bucket='henlagt', status='Henlagt', henlagt_reason=? WHERE id=?")->execute([$reason, $caseId]);
      }
    }
    $redirectToCurrentView();
  }

  if ($action === 'delete_case') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    if ($caseId > 0) {
      $stmt = $db->prepare('SELECT * FROM cases WHERE id=?');
      $stmt->execute([$caseId]);
      $case = $stmt->fetch();
      if ($case && $canAccessCase($case) && (int)($case['archived'] ?? 0) === 1) {
        $stmt = $db->prepare('SELECT file_path FROM case_evidence WHERE case_id=?');
        $stmt->execute([$caseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ev) {
          $abs = __DIR__ . '/' . ltrim((string)$ev, '/');
          if (is_file($abs)) { try { unlink($abs); } catch (Throwable $_) {} }
        }
        $db->prepare('DELETE FROM case_evidence WHERE case_id=?')->execute([$caseId]);
        $db->prepare('DELETE FROM case_visibility WHERE case_id=?')->execute([$caseId]);
        $db->prepare('DELETE FROM case_messages WHERE case_id=?')->execute([$caseId]);
        $db->prepare('DELETE FROM case_logs WHERE case_id=?')->execute([$caseId]);
        $db->prepare('DELETE FROM cases WHERE id=?')->execute([$caseId]);
      }
    }
    $redirectToCurrentView();
  }
}

$viewRaw = (string)($_GET['view'] ?? 'mine');
$view = in_array($viewRaw, ['mine','all','archive','global_archive'], true) ? $viewRaw : 'mine';
$archiveTabRaw = (string)($_GET['archive_tab'] ?? 'stam');
$archiveTab = in_array($archiveTabRaw, ['stam', 'henlagt'], true) ? $archiveTabRaw : 'stam';
$search = trim((string)($_GET['q'] ?? ''));
$isGlobalArchiveView = $view === 'global_archive';
$isPersonalArchiveView = $view === 'archive';
$isArchivedView = $isGlobalArchiveView || $isPersonalArchiveView;
$openCaseId = (int)($_GET['open_case'] ?? 0);

$visibleCaseIds = [];
$archivedVisibleCaseIds = [];
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
  $stmt = $db->prepare('SELECT DISTINCT case_id FROM case_visibility WHERE ' . implode(' OR ', $visParts));
  $stmt->execute($visParams);
  $visibleCaseIds = array_map(static fn($r) => (int)$r['case_id'], $stmt->fetchAll());

  $stmt = $db->prepare('SELECT DISTINCT case_id FROM case_archive_assignments WHERE ' . implode(' OR ', $visParts));
  $stmt->execute($visParams);
  $archivedVisibleCaseIds = array_map(static fn($r) => (int)$r['case_id'], $stmt->fetchAll());
}

$where = ['archived=' . ($isArchivedView ? '1' : '0')];
$params = [];

if ($view === 'mine' && !$isLeader) {
  $accessParts = [
    "(assigned_type='person' AND assigned_discord_id=?)",
    'responsible_discord_id=?'
  ];
  $params[] = (string)$u['discord_id'];
  $params[] = (string)$u['discord_id'];

  if ($myName !== '' && $myBadge !== '') {
    $accessParts[] = "(assigned_type='person' AND assigned_name=? AND assigned_badge=?)";
    $accessParts[] = '(responsible_name=? AND responsible_badge=?)';
    array_push($params, $myName, $myBadge, $myName, $myBadge);
  }

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

if ($view === 'archive' && !$isLeader) {
  $archiveParts = [];
  if ($archivedVisibleCaseIds) {
    $ph = implode(',', array_fill(0, count($archivedVisibleCaseIds), '?'));
    $archiveParts[] = "id IN ($ph)";
    $params = array_merge($params, $archivedVisibleCaseIds);
  }
  if (!$archiveParts) {
    $where[] = '1=0';
  } else {
    $where[] = '(' . implode(' OR ', $archiveParts) . ')';
  }
}

if ($view === 'global_archive' && !$isLeader) {
  $globalArchiveParts = [];
  if ($archivedVisibleCaseIds) {
    $ph = implode(',', array_fill(0, count($archivedVisibleCaseIds), '?'));
    $globalArchiveParts[] = "id IN ($ph)";
    $params = array_merge($params, $archivedVisibleCaseIds);
  }
  if (!$globalArchiveParts) {
    $where[] = '1=0';
  } else {
    $where[] = '(' . implode(' OR ', $globalArchiveParts) . ')';
  }
}

if ($view === 'global_archive') {
  $where[] = 'COALESCE(archive_bucket,\'stam\') = ?';
  $params[] = $archiveTab;
}

if ($search !== '') {
  $where[] = "(journal_number LIKE ? OR case_uid LIKE ? OR title LIKE ? OR description LIKE ? OR COALESCE(created_by_name,'') LIKE ? OR COALESCE(created_by_discord_id,'') LIKE ?)";
  $like = '%' . $search . '%';
  array_push($params, $like, $like, $like, $like, $like, $like);
}

$stmt = $db->prepare('SELECT * FROM cases WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC');
$stmt->execute($params);
$visibleCases = $stmt->fetchAll();
$caseAccessMap = [];
foreach ($visibleCases as $row) {
  $caseAccessMap[(int)$row['id']] = $canAccessCase($row);
}

$caseVisibility = [];
$caseEvidence = [];
$caseMessages = [];
$caseLogs = [];

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

  $stmt = $db->prepare("SELECT id, case_id, title, file_path, original_name, created_at FROM case_evidence WHERE case_id IN ($ph) ORDER BY created_at DESC, id DESC");
  $stmt->execute($ids);
  foreach ($stmt->fetchAll() as $ev) {
    $caseEvidence[(int)$ev['case_id']][] = $ev;
  }

  $stmt = $db->prepare("SELECT id,case_id,sender_label,message,created_at FROM case_messages WHERE case_id IN ($ph) ORDER BY created_at ASC, id ASC");
  $stmt->execute($ids);
  foreach ($stmt->fetchAll() as $msg) {
    $caseMessages[(int)$msg['case_id']][] = $msg;
  }

  $stmt = $db->prepare("SELECT id,case_id,log_text,created_at FROM case_logs WHERE case_id IN ($ph) ORDER BY created_at DESC, id DESC");
  $stmt->execute($ids);
  foreach ($stmt->fetchAll() as $log) {
    $caseLogs[(int)$log['case_id']][] = $log;
  }
}

include __DIR__ . '/_layout.php';
?>
<div class="container cases-page">
  <div class="card">
    <h2>Opret sag</h2>
    <details class="modern-details" style="margin-top:12px">
      <summary class="btn btn-solid">+ Opret sag</summary>
      <form method="post" enctype="multipart/form-data" style="margin-top:12px">
        <input type="hidden" name="action" value="create_case"/>
        <label>Type</label>
        <select name="case_type_text" required data-case-type-select data-title-target="title">
          <option value="">Vælg type...</option>
          <?php foreach ($caseTypes as $t): ?>
            <option value="<?= h($t['GERNINGTXT']) ?>"><?= h($t['GERNINGTXT']) ?> (<?= h($t['GERNINGSKODE']) ?>)</option>
          <?php endforeach; ?>
        </select>
        </select>
        <label>Titel</label>
        <input name="title" readonly placeholder="Bliver sat automatisk ud fra type">
        <label>Beskrivelse</label>
        <textarea name="description" rows="4" required></textarea>
        <label>Bevis titel (valgfri)</label>
        <input name="evidence_title" placeholder="Fx. Bodycam klip fra 12/04">
        <label>Bevismateriale (valgfri)</label>
        <input type="file" name="evidence[]" multiple accept="image/*,.pdf,.txt,.zip,.rar,.7z,.doc,.docx">

        <label>Tildel afdelinger</label>
        <input type="text" class="assign-filter" placeholder="Søg afdeling...">
        <div data-filter-list class="picker-list" style="display:none;">
          <table class="table"><tbody>
          <?php foreach ($departments as $d): ?>
            <tr class="assign-row"><td style="width:36px;"><input style="width:auto;" type="checkbox" name="assigned_departments[]" value="<?= h($d['department']) ?>"></td><td><?= h($d['department']) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>

        <label>Tildel medarbejder</label>
        <input type="text" class="assign-filter" placeholder="Søg medarbejder...">
        <div data-filter-list class="picker-list" style="display:none;">
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
        <a class="btn <?= $view==='global_archive' ? 'btn-solid' : '' ?>" href="sager.php?view=global_archive&archive_tab=<?= h($archiveTab) ?>">Arkiv</a>
      </div>
      <form method="get" style="display:flex;gap:8px;align-items:end;">
        <input type="hidden" name="view" value="<?= h($view) ?>"/>
        <?php if ($view === 'global_archive'): ?><input type="hidden" name="archive_tab" value="<?= h($archiveTab) ?>"/><?php endif; ?>
        <div>
          <label style="margin:0 0 4px 0;">Søg sager</label>
          <input name="q" value="<?= h($search) ?>" placeholder="Søg på journal nr, titel, tekst..."/>
        </div>
        <button type="submit">Søg</button>
      </form>
    </div>
  </div>

  <div class="card">
    <h2>
       <?php if ($view==='all'): ?>Alle Sager<?php elseif ($view==='global_archive'): ?>Arkiv<?php elseif ($view==='archive'): ?>Mit arkiv<?php else: ?>Mine Sager<?php endif; ?>
    </h2>
    <?php if ($view === 'global_archive'): ?>
      <div style="margin-bottom:10px;display:flex;gap:8px;">
        <a class="btn <?= $archiveTab==='stam' ? 'btn-solid' : '' ?>" href="sager.php?view=global_archive&archive_tab=stam<?= $search!=='' ? '&q='.urlencode($search) : '' ?>">Stam Arkiv</a>
        <a class="btn <?= $archiveTab==='henlagt' ? 'btn-solid' : '' ?>" href="sager.php?view=global_archive&archive_tab=henlagt<?= $search!=='' ? '&q='.urlencode($search) : '' ?>">Henlagt</a>
      </div>
    <?php endif; ?>
    <table class="table">
      <thead><tr><th>Journal Nr.</th><th>Titel</th><th>Sagsansvarlig</th><th>Type / Gerningskode</th><th>Status</th><th>Handling</th></tr></thead>
      <tbody>
      <?php foreach ($visibleCases as $c): ?>
        <?php
          $responsible = trim((string)($c['responsible_name'] ?? $c['assigned_name'] ?? ''));
          if ($responsible === '') $responsible = trim((string)($c['created_by_name'] ?? 'Ukendt'));
        ?>
        <tr>
          <td><?= h((string)($c['journal_number'] ?? $c['case_uid'])) ?></td>
          <td><?= h($c['title']) ?></td>
          <td><?= h($responsible) ?></td>
          <td><?= h((string)($c['case_type_text'] ?? 'Ukendt')) ?> / <?= h((string)($c['offense_code'] ?? '-')) ?></td>
          <td><?= h($c['status']) ?></td>
          <td>
           <?php $canOpenCase = $isLeader || !empty($caseAccessMap[(int)$c['id']]); ?>
            <?php if ($canOpenCase): ?>
            <button class="btn" type="button" onclick="openCaseModal(<?= (int)$c['id'] ?>)">Åben</button>
            <?php else: ?>
            <button class="btn" type="button" disabled>Ingen adgang</button>
            <?php endif; ?>
            <?php if (!$isArchivedView && $canOpenCase): ?>
            <form method="post" style="display:inline-block;">
              <input type="hidden" name="action" value="archive_case"/>
              <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
              <button type="submit">Arkivér</button>
            </form>
            <?php elseif ($view === 'global_archive' && (string)($c['archive_bucket'] ?? 'stam') !== 'henlagt'): ?>
            <form method="post" style="display:inline-block;">
              <input type="hidden" name="action" value="reopen_case"/>
              <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
              <input type="hidden" name="view" value="<?= h($view) ?>"/>
              <input type="hidden" name="archive_tab" value="<?= h($archiveTab) ?>"/>
              <input type="hidden" name="q" value="<?= h($search) ?>"/>
              <button type="submit">Genåben</button>
            </form>
            <button type="button" class="btn" onclick="openHenlaegModal(<?= (int)$c['id'] ?>)">Henlæg</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$visibleCases): ?><tr><td colspan="6">Ingen sager.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php foreach ($visibleCases as $c): ?>
    <div id="case-content-<?= (int)$c['id'] ?>" style="display:none;">
      <?php
        $cvm = $caseVisibility[(int)$c['id']] ?? ['employees' => [], 'departments' => []];
        $empNamesM = [];
        foreach (($cvm['employees'] ?? []) as $did) {
          $name = $employeeNameByDiscord[$did] ?? $did;
          $badge = trim((string)($employeeBadgeByDiscord[$did] ?? ''));
          $empNamesM[] = $badge !== '' ? $name . ' - ' . $badge : $name;
        }
        $assignedLabel = implode(', ', array_filter(array_merge(($cvm['departments'] ?? []), $empNamesM)));
        $createdByModal = trim((string)($c['created_by_name'] ?? ''));
        if ($createdByModal === '') $createdByModal = (string)($c['created_by_discord_id'] ?? 'Ukendt');
        $responsibleModal = trim((string)($c['responsible_name'] ?? $c['assigned_name'] ?? $createdByModal));
      ?>
      <h3 style="margin-top:0;"><?= h($c['title']) ?></h3>
      <p><b>Journal Nr.:</b> <?= h((string)($c['journal_number'] ?? $c['case_uid'])) ?></p>
      <p><b>Sagsansvarlig:</b> <?= h($responsibleModal) ?></p>
      <p><b>Type:</b> <?= h((string)($c['case_type_text'] ?? 'Ukendt')) ?> &nbsp;|&nbsp; <b>Gerningskode:</b> <?= h((string)($c['offense_code'] ?? '-')) ?></p>
      <p><b>Beskrivelse:</b><br><?= nl2br(h($c['description'])) ?></p>

      <h4 style="margin:14px 0 8px 0;">Kommentarer</h4>
      <div class="chat-box">
        <?php foreach (($caseMessages[(int)$c['id']] ?? []) as $msg): ?>
          <div class="chat-msg">
            <div><b><?= h($msg['sender_label']) ?></b> · <span class="muted"><?= h($msg['created_at']) ?></span></div>
            <div><?= nl2br(h($msg['message'])) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($caseMessages[(int)$c['id']] ?? [])): ?>
          <div class="muted">Ingen kommentarer endnu.</div>
        <?php endif; ?>
      </div>
      <?php if (!$isArchivedView): ?>
      <form method="post" style="margin-top:8px;display:flex;gap:8px;">
        <input type="hidden" name="action" value="send_message"/>
        <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
        <input type="hidden" name="view" value="<?= h($view) ?>"/>
        <input type="hidden" name="q" value="<?= h($search) ?>"/>
        <input type="text" name="message" placeholder="Skriv kommentar..." required>
        <button type="submit" class="btn btn-solid">Send</button>
      </form>
      <?php endif; ?>

      <h4 style="margin:12px 0 8px 0;">Bevismateriale</h4>
      <?php $canOpenCase = $isLeader || !empty($caseAccessMap[(int)$c['id']]); ?>
      <?php if (!$isArchivedView && $canOpenCase): ?>
      <form method="post" enctype="multipart/form-data" style="margin:0 0 10px 0;padding:10px;border:1px solid var(--border);border-radius:8px;">
        <input type="hidden" name="action" value="save_case_changes"/>
        <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
        <input type="hidden" name="view" value="<?= h($view) ?>"/>
        <input type="hidden" name="q" value="<?= h($search) ?>"/>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <input type="text" name="new_evidence_title" placeholder="Titel på bilag" style="min-width:220px;">
          <input type="file" name="evidence" accept="image/*,.pdf,.txt,.zip,.rar,.7z,.doc,.docx">
          <button type="submit" class="btn">Upload bilag</button>
        </div>
        <?php $responsibleDiscordCase = trim((string)($c['responsible_discord_id'] ?? $c['assigned_discord_id'] ?? '')); ?>
        <?php if ($responsibleDiscordCase === (string)($u['discord_id'] ?? '')): ?>
        <label style="margin-top:10px;">Tildel afdelinger</label>
        <input type="text" class="assign-filter" placeholder="Søg afdeling...">
        <div data-filter-list class="picker-list" style="display:none;">
          <table class="table"><tbody>
          <?php foreach ($departments as $d): ?>
            <?php $depName = (string)$d['department']; ?>
            <tr class="assign-row"><td style="width:36px;"><input style="width:auto;" type="checkbox" name="assigned_departments[]" value="<?= h($depName) ?>" <?= in_array($depName, ($cvm['departments'] ?? []), true) ? 'checked' : '' ?>></td><td><?= h($depName) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>

        <label style="margin-top:10px;">Tildel medarbejder</label>
        <input type="text" class="assign-filter" placeholder="Søg medarbejder...">
        <div data-filter-list class="picker-list" style="display:none;">
          <table class="table"><tbody>
          <?php foreach ($employees as $e): ?>
            <?php $did = trim((string)($e['discord_id'] ?? '')); ?>
            <tr class="assign-row"><td style="width:36px;"><input style="width:auto;" type="checkbox" name="employee_ids[]" value="<?= (int)$e['id'] ?>" <?= ($did !== '' && in_array($did, ($cvm['employees'] ?? []), true)) ? 'checked' : '' ?>></td><td><?= h($e['name']) ?> (<?= h($e['badge_number']) ?>)</td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div>

        <button type="submit" class="btn btn-solid" style="margin-top:10px;">Gem tildelinger</button>
        <?php endif; ?>
      </form>
      <?php endif; ?>
      <table class="table">
        <thead><tr><th>Bevis titel</th><th>Tilføjet dato</th><th>Fil</th><?php if (!$isArchivedView): ?><th>Handling</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach (($caseEvidence[(int)$c['id']] ?? []) as $ev): ?>
          <?php
            $normalizedPath = $normalizeUploadPath((string)($ev['file_path'] ?? ''));
            $downloadName = (string)($ev['original_name'] ?: basename($normalizedPath));
          ?>
          <tr>
            <td><?= h($ev['title']) ?></td>
            <td><?= h($ev['created_at']) ?></td>
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

      <?php if ($isLeader && !$isArchivedView): ?>
      <h4 style="margin:12px 0 8px 0;">Sagsansvarlig (leader)</h4>
      <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="transfer_responsible"/>
        <input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"/>
        <input type="hidden" name="view" value="<?= h($view) ?>"/>
        <input type="hidden" name="q" value="<?= h($search) ?>"/>
        <div style="min-width:260px;flex:1;">
          <label>Vælg ny sagsansvarlig</label>
          <select name="responsible_employee_id" required>
            <option value="">Vælg medarbejder</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= (int)$e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['badge_number']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-solid">Tildel ny sagsansvarlig</button>
      </form>
      <?php endif; ?>

      <details style="margin-top:14px;">
        <summary class="btn">SagsLog</summary>
        <div style="margin-top:8px;border:1px solid var(--border);border-radius:8px;padding:10px;background:#f9fbff;">
          <?php foreach (($caseLogs[(int)$c['id']] ?? []) as $log): ?>
            <div style="padding:8px 0;border-bottom:1px solid var(--border);"><?= h($log['log_text']) ?></div>
          <?php endforeach; ?>
          <?php if (empty($caseLogs[(int)$c['id']] ?? [])): ?>
            <div class="muted">Ingen log registreret endnu.</div>
          <?php endif; ?>
        </div>
      </details>

      <?php if ($isArchivedView): ?>
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
  <div style="max-width:980px;margin:5vh auto;background:#fff;padding:18px;border-radius:12px;max-height:88vh;overflow:auto;position:relative;box-shadow:0 20px 45px rgba(11,42,74,.22);">
    <button type="button" class="btn" onclick="closeCaseModal()" style="position:absolute;right:12px;top:12px;">Luk</button>
    <div id="caseModalContent"></div>
  </div>
  </div>

<div id="henlaegModal" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:10000;">
  <div style="max-width:520px;margin:16vh auto;background:#fff;padding:18px;border-radius:12px;position:relative;box-shadow:0 20px 45px rgba(11,42,74,.22);">
    <button type="button" class="btn" onclick="closeHenlaegModal()" style="position:absolute;right:12px;top:12px;">Luk</button>
    <h3 style="margin-top:0;">Grundet til henlæg.</h3>
    <form method="post">
      <input type="hidden" name="action" value="henlaeg_case"/>
      <input type="hidden" name="case_id" id="henlaegCaseId" value="0"/>
      <input type="hidden" name="view" value="<?= h($view) ?>"/>
      <input type="hidden" name="archive_tab" value="<?= h($archiveTab) ?>"/>
      <input type="hidden" name="q" value="<?= h($search) ?>"/>
      <textarea name="henlagt_reason" rows="4" required placeholder="Skriv en kort begrundelse..."></textarea>
      <button type="submit" class="btn btn-solid" style="margin-top:10px;">Henlæg</button>
    </form>
  </div>
</div>

<script>
const openCaseFromQuery = <?= $openCaseId > 0 ? (int)$openCaseId : 0 ?>;

function setupAssignmentFilters(root) {
  const scope = root || document;
  const filters = scope.querySelectorAll('.assign-filter');
  filters.forEach(function(input){
    if (input.dataset.bound === '1') return;
    input.dataset.bound = '1';
    const listWrap = input.nextElementSibling;
    if (!listWrap || !listWrap.hasAttribute('data-filter-list')) return;
    if (!input.value.trim()) listWrap.style.display = 'none';
    input.addEventListener('input', function(){
      const q = input.value.toLowerCase().trim();
      listWrap.style.display = q === '' ? 'none' : '';
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
  content.querySelectorAll('.assign-filter').forEach(function(input){
    input.dataset.bound = '';
  });
  modal.style.display = 'block';
  setupAssignmentFilters(content);
}
function closeCaseModal() {
  const modal = document.getElementById('caseModal');
  if (modal) modal.style.display = 'none';
}
function bindCreateTitleFromType(root) {
  (root || document).querySelectorAll('[data-case-type-select]').forEach(function(sel){
    const titleFieldName = sel.getAttribute('data-title-target') || 'title';
    const form = sel.closest('form');
    if (!form) return;
    const titleInput = form.querySelector('input[name="' + titleFieldName + '"]');
    if (!titleInput) return;
    const setTitle = function(){
      const v = (sel.value || '').trim();
     titleInput.value = v;
    };
    sel.addEventListener('change', setTitle);
    setTitle();
  });
}
function openHenlaegModal(caseId) {
  const modal = document.getElementById('henlaegModal');
  const input = document.getElementById('henlaegCaseId');
  if (!modal || !input) return;
  input.value = String(caseId || 0);
  modal.style.display = 'block';
}
function closeHenlaegModal() {
  const modal = document.getElementById('henlaegModal');
  if (modal) modal.style.display = 'none';
}
window.addEventListener('click', function(e){
  const modal = document.getElementById('caseModal');
  if (e.target === modal) closeCaseModal();
  const henlaeg = document.getElementById('henlaegModal');
  if (e.target === henlaeg) closeHenlaegModal();
});
setupAssignmentFilters(document);
bindCreateTitleFromType(document);
if (openCaseFromQuery > 0) {
  openCaseModal(openCaseFromQuery);
}
</script>

<footer class="footer">© 2026 Redline Politidistrikt — FiveM Roleplay Server</footer>
</body></html>
