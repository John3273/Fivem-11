<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

require_role('LEADER');

$db = db();
$u  = current_user();

// --- Lightweight "migrations" (safe to run on every request) ---
// Ranks, departments and per-department resource links.
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

// Ensure photo_url column exists on employees
try {
  $db->query("SELECT photo_url FROM employees LIMIT 1");
} catch (Throwable $e) {
  $db->exec("ALTER TABLE employees ADD COLUMN photo_url TEXT");
}
try {
  $db->query("SELECT notes FROM employees LIMIT 1");
} catch (Throwable $e) {
  $db->exec("ALTER TABLE employees ADD COLUMN notes TEXT");
}
// Ensure secondary_department column exists
try {
  $db->query("SELECT secondary_department FROM employees LIMIT 1");
} catch (Throwable $e) {
  $db->exec("ALTER TABLE employees ADD COLUMN secondary_department TEXT");
}
$db->exec("CREATE TABLE IF NOT EXISTS department_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  department_id INTEGER NOT NULL,
  link_key TEXT NOT NULL,
  url TEXT NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(department_id, link_key)
)");

// Citizen slideshow (shown on citizen index.php)
$db->exec("CREATE TABLE IF NOT EXISTS citizen_slides (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT,
  image_path TEXT NOT NULL,
  link_url TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  starts_at DATETIME,
  ends_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Citizen media board (global for all citizens)
$db->exec("CREATE TABLE IF NOT EXISTS media_board (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT,
  image_path TEXT NOT NULL,
  caption TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Department about pages
$db->exec("CREATE TABLE IF NOT EXISTS department_about (
  department_id INTEGER PRIMARY KEY,
  title TEXT,
  body TEXT NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS department_member_profiles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_discord_id TEXT NOT NULL,
  department_name TEXT NOT NULL,
  responsibility_title TEXT,
  note TEXT,
  UNIQUE(employee_discord_id, department_name)
)");

$db->exec("CREATE TABLE IF NOT EXISTS department_member_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_discord_id TEXT NOT NULL,
  department_name TEXT NOT NULL,
  file_path TEXT NOT NULL,
  original_name TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS department_training_columns (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  department_name TEXT NOT NULL,
  column_name TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(department_name, column_name)
)");

$db->exec("CREATE TABLE IF NOT EXISTS department_training_checks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  training_column_id INTEGER NOT NULL,
  employee_discord_id TEXT NOT NULL,
  is_checked INTEGER NOT NULL DEFAULT 0,
  UNIQUE(training_column_id, employee_discord_id)
)");

$db->exec("CREATE TABLE IF NOT EXISTS report_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id INTEGER NOT NULL,
  author_role TEXT NOT NULL,
  author_name TEXT NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

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
try {
  $db->query("SELECT report_kind FROM reports LIMIT 1");
} catch (Throwable $e) {
  $db->exec("ALTER TABLE reports ADD COLUMN report_kind TEXT NOT NULL DEFAULT 'Rapport/Klage'");
}

// Resource buttons (must match police.php "Ressourcer & Links")
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
function _post_is_archived($p, $hasCol) {
  if ($hasCol) return (int)($p['archived'] ?? 0) === 1;
  return strpos((string)($p['audience'] ?? ''), 'archived|') === 0;
}
function _post_audience_norm($aud) {
  $aud = (string)$aud;
  return (strpos($aud, 'archived|') === 0) ? substr($aud, 9) : $aud;
}


// Departments (for targeted posts + admin). Prefer departments table, fall back to employees.
$departments = [];
try {
  $departments = $db->query("SELECT name AS department FROM departments ORDER BY sort_order, name")->fetchAll();
} catch (Throwable $e) {
  $departments = [];
}
if (!$departments) {
  $departments = $db->query("
    SELECT DISTINCT COALESCE(NULLIF(TRIM(department),''),'Ukendt') AS department
    FROM employees
    ORDER BY department
  ")->fetchAll();
}

// Admin lists for selects
$rankRows = $db->query("SELECT id, name FROM ranks ORDER BY sort_order, name")->fetchAll();
$deptRows = $db->query("SELECT id, name FROM departments ORDER BY sort_order, name")->fetchAll();

$tab = $_GET['tab'] ?? 'employees';
$allowedTabs = ['employees','posts','reports','applications','users','admin','dept_overview'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'employees';

/**
 * ACTIONS
 */

// Save employee (add/update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_employee') {
  $discordId = trim($_POST['discord_id'] ?? '');
  if ($discordId === '') die("Discord ID mangler");

  $name = trim($_POST['name'] ?? '');
  $badge = trim($_POST['badge_number'] ?? '');
  $rank = trim($_POST['rank'] ?? '');
  $dept = trim($_POST['department'] ?? '');
  $secondaryDept = trim($_POST['secondary_department'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  $verbal = 0;
  $written = 0;
  $join = trim($_POST['join_date'] ?? '');
  $prom = trim($_POST['latest_promotion_date'] ?? '');

  $stmt = $db->prepare("SELECT id, rank FROM employees WHERE discord_id=?");
  $stmt->execute([$discordId]);
  $existing = $stmt->fetch();

  if ($existing) {
    // promotion tracking if rank changed
    $latestProm = null;
    if (($existing['rank'] ?? '') !== $rank && $rank !== '') {
      $latestProm = date('Y-m-d');
    }

    $stmt = $db->prepare("UPDATE employees SET
      name=?, badge_number=?, rank=?, department=?,secondary_department=?,
      verbal_warnings=?, written_warnings=?, notes=?, join_date=?,
      latest_promotion_date=COALESCE(?, latest_promotion_date)
      WHERE discord_id=?");
    $stmt->execute([$name,$badge,$rank,$dept,$secondaryDept,$verbal,$written,$notes,$join, ($prom !== '' ? $prom : $latestProm), $discordId]);
  } else {
    $stmt = $db->prepare("INSERT INTO employees(discord_id,name,badge_number,rank,department,secondary_department,verbal_warnings,written_warnings,notes,join_date,latest_promotion_date)
                          VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$discordId,$name,$badge,$rank,$dept,$secondaryDept,$verbal,$written,$notes,$join, ($prom !== '' ? $prom : null)]);
  }

  redirect("leader.php?tab=employees");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_employees_csv') {
  if (!isset($_FILES['employees_csv']) || (int)($_FILES['employees_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    redirect('leader.php?tab=employees');
  }

  $tmp = (string)($_FILES['employees_csv']['tmp_name'] ?? '');
  $fh = @fopen($tmp, 'r');
  if (!$fh) redirect('leader.php?tab=employees');

  $upsert = $db->prepare("INSERT INTO employees(discord_id,name,badge_number,rank,department,secondary_department,verbal_warnings,written_warnings,notes,join_date)
                         VALUES(?,?,?,?,?,?,0,0,?,?)
                         ON CONFLICT(discord_id) DO UPDATE SET
                           name=excluded.name,
                           badge_number=excluded.badge_number,
                           rank=excluded.rank,
                           department=excluded.department,
                           secondary_department=excluded.secondary_department,
                           notes=excluded.notes,
                           join_date=excluded.join_date");

  $line = 0;
  while (($row = fgetcsv($fh, 0, ';')) !== false) {
    $line++;
    if (!$row || count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) continue;

    $discordId = trim((string)($row[0] ?? ''));
    if ($line === 1 && in_array(strtolower($discordId), ['discord_id','id','discord'], true)) continue;
    if ($discordId === '') continue;

    $name = trim((string)($row[1] ?? ''));
    $badge = trim((string)($row[2] ?? ''));
    $rank = trim((string)($row[3] ?? ''));
    $dept = trim((string)($row[4] ?? ''));
    $secondaryDept = trim((string)($row[5] ?? ''));
    $joinDate = trim((string)($row[6] ?? ''));
    $notes = trim((string)($row[7] ?? ''));

    $upsert->execute([$discordId,$name,$badge,$rank,$dept,$secondaryDept,$notes,$joinDate]);
  }
  fclose($fh);

  redirect('leader.php?tab=employees');
}

// ADMIN: move rank order (drag/drop style via up/down)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_rank') {
  $id = (int)($_POST['id'] ?? 0);
  $dir = trim($_POST['dir'] ?? 'up');
  $rows = $db->query("SELECT id, sort_order FROM ranks ORDER BY sort_order, id")->fetchAll();
    // normalize sort_order first to avoid duplicates blocking movement
    $norm = $db->prepare("UPDATE ranks SET sort_order=? WHERE id=?");
    foreach ($rows as $idx => $rr) {
      $norm->execute([$idx + 1, (int)$rr['id']]);
      $rows[$idx]['sort_order'] = $idx + 1;
    }
  for ($i=0; $i<count($rows); $i++) {
    if ((int)$rows[$i]['id'] === $id) {
      $j = ($dir === 'down') ? $i+1 : $i-1;
      if (isset($rows[$j])) {
        $a = (int)$rows[$i]['sort_order'];
        $b = (int)$rows[$j]['sort_order'];
        $stmt = $db->prepare("UPDATE ranks SET sort_order=? WHERE id=?");
        $stmt->execute([$b, $id]);
        $stmt->execute([$a, (int)$rows[$j]['id']]);
      }
      break;
    }
  }
  redirect("leader.php?tab=admin#ranks");
}

// ADMIN: Add rank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_rank') {
  $name = trim($_POST['rank_name'] ?? '');
  if ($name !== '') {
    $next = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM ranks")->fetchColumn();
    $stmt = $db->prepare("INSERT OR IGNORE INTO ranks(name,sort_order) VALUES(?,?)");
    $stmt->execute([$name,$next]);
  }
  redirect("leader.php?tab=admin#ranks");
}

// ADMIN: Delete rank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_rank') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $stmt = $db->prepare("DELETE FROM ranks WHERE id=?");
    $stmt->execute([$id]);
  }
  redirect("leader.php?tab=admin#ranks");
}

// ADMIN: Add department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_department') {
  $name = trim($_POST['department_name'] ?? '');
  if ($name !== '') {
    $stmt = $db->prepare("INSERT OR IGNORE INTO departments(name) VALUES(?)");
    $stmt->execute([$name]);
  }
  redirect("leader.php?tab=admin#departments");
}

// ADMIN: Delete department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_department') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $stmt = $db->prepare("DELETE FROM department_links WHERE department_id=?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("DELETE FROM departments WHERE id=?");
    $stmt->execute([$id]);
  }
  redirect("leader.php?tab=admin#departments");
}

// ADMIN: Save resource links per department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_department_links') {
  $deptId = (int)($_POST['department_id'] ?? 0);
  if ($deptId > 0) {
    foreach ($RESOURCE_BUTTONS as $btn) {
      $k = $btn['key'];
      $url = trim($_POST['link_' . $k] ?? '');
      if ($url === '') {
        $stmt = $db->prepare("DELETE FROM department_links WHERE department_id=? AND link_key=?");
        $stmt->execute([$deptId, $k]);
      } else {
        $stmt = $db->prepare("INSERT INTO department_links(department_id, link_key, url, updated_at)
                              VALUES(?,?,?,CURRENT_TIMESTAMP)
                              ON CONFLICT(department_id, link_key) DO UPDATE SET url=excluded.url, updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$deptId, $k, $url]);
      }
    }
  }
  redirect("leader.php?tab=admin#links");
}
// ADMIN: Add citizen slide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_citizen_slide') {
  $title = trim($_POST['title'] ?? '');
  $link  = trim($_POST['link_url'] ?? '');
  $sort  = (int)($_POST['sort_order'] ?? 0);
  $active = (int)($_POST['is_active'] ?? 1);

  try {
    $img = save_upload('image');
  } catch (Throwable $e) {
    die("Upload fejl: " . h($e->getMessage()));
  }

  $stmt = $db->prepare("INSERT INTO citizen_slides(title,image_path,link_url,sort_order,is_active) VALUES(?,?,?,?,?)");
  $stmt->execute([$title, $img, ($link !== '' ? $link : null), $sort, $active]);

  redirect("leader.php?tab=admin#citizen_slides");
}

// ADMIN: Delete citizen slide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_citizen_slide') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $stmt = $db->prepare("DELETE FROM citizen_slides WHERE id=?");
    $stmt->execute([$id]);
  }
  redirect("leader.php?tab=admin#citizen_slides");
}

// ADMIN: Toggle citizen slide active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_citizen_slide') {
  $id = (int)($_POST['id'] ?? 0);
  $to = (int)($_POST['to'] ?? 0);
  if ($id > 0) {
    $stmt = $db->prepare("UPDATE citizen_slides SET is_active=? WHERE id=?");
    $stmt->execute([$to, $id]);
  }
  redirect("leader.php?tab=admin#citizen_slides");
}

// ADMIN: Add media board item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_media_item') {
  $title = trim($_POST['title'] ?? '');
  $caption = trim($_POST['caption'] ?? '');
  $sort  = (int)($_POST['sort_order'] ?? 0);
  $active = (int)($_POST['is_active'] ?? 1);

  try {
    $img = save_upload('image');
  } catch (Throwable $e) {
    die("Upload fejl: " . h($e->getMessage()));
  }

  $stmt = $db->prepare("INSERT INTO media_board(title,image_path,caption,sort_order,is_active,updated_at)
                        VALUES(?,?,?,?,?,CURRENT_TIMESTAMP)");
  $stmt->execute([$title, $img, ($caption !== '' ? $caption : null), $sort, $active]);

  redirect("leader.php?tab=admin#media_board");
}

// ADMIN: Replace media board image (swap only image)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'replace_media_image') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    try {
      $img = save_upload('image');
    } catch (Throwable $e) {
      die("Upload fejl: " . h($e->getMessage()));
    }
    $stmt = $db->prepare("UPDATE media_board SET image_path=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmt->execute([$img, $id]);
  }
  redirect("leader.php?tab=admin#media_board");
}

// ADMIN: Update media meta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_media_item') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $title = trim($_POST['title'] ?? '');
    $caption = trim($_POST['caption'] ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    $active = (int)($_POST['is_active'] ?? 1);

    $stmt = $db->prepare("UPDATE media_board SET title=?, caption=?, sort_order=?, is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmt->execute([$title, ($caption !== '' ? $caption : null), $sort, $active, $id]);
  }
  redirect("leader.php?tab=admin#media_board");
}

// ADMIN: Delete media item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_media_item') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $stmt = $db->prepare("DELETE FROM media_board WHERE id=?");
    $stmt->execute([$id]);
  }
  redirect("leader.php?tab=admin#media_board");
}

// ADMIN: Save department about
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_department_about') {
  $deptId = (int)($_POST['department_id'] ?? 0);
  $title = trim($_POST['title'] ?? '');
  $body  = trim($_POST['body'] ?? '');

  if ($deptId > 0) {
    if ($body === '') $body = ' '; // body is NOT NULL in table
    $stmt = $db->prepare("
      INSERT INTO department_about(department_id,title,body,updated_at)
      VALUES(?,?,?,CURRENT_TIMESTAMP)
      ON CONFLICT(department_id) DO UPDATE SET
        title=excluded.title,
        body=excluded.body,
        updated_at=CURRENT_TIMESTAMP
    ");
    $stmt->execute([$deptId, ($title !== '' ? $title : null), $body]);
  }

  redirect("leader.php?tab=admin#dept_about");
}

// Delete employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_employee') {
  $discordId = trim($_POST['discord_id'] ?? '');
  if ($discordId === '') die("ID mangler");

  // Fjern afdeling-specifikke data tilknyttet medarbejderen
  $stmt = $db->prepare("DELETE FROM department_member_profiles WHERE employee_discord_id=?");
  $stmt->execute([$discordId]);

  $stmt = $db->prepare("SELECT file_path FROM department_member_documents WHERE employee_discord_id=?");
  $stmt->execute([$discordId]);
  foreach ($stmt->fetchAll() as $docRow) {
    $abs = __DIR__ . '/' . ltrim((string)($docRow['file_path'] ?? ''), '/');
    if ($abs !== __DIR__ . '/' && is_file($abs)) { try { unlink($abs); } catch (Throwable $_) {} }
  }
  $stmt = $db->prepare("DELETE FROM department_member_documents WHERE employee_discord_id=?");
  $stmt->execute([$discordId]);

  $stmt = $db->prepare("DELETE FROM department_training_checks WHERE employee_discord_id=?");
  $stmt->execute([$discordId]);

  $stmt = $db->prepare("DELETE FROM employees WHERE discord_id=?");
  $stmt->execute([$discordId]);

  redirect("leader.php?tab=employees");
}

// Create post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_post') {
  $title = trim($_POST['title'] ?? '');
  $body  = trim($_POST['body'] ?? '');

  $aud = $_POST['audience'] ?? 'public';
  if ($aud === 'public') $aud = 'frontpage';
  if ($aud === 'police') {
    $audience = 'police';
  } elseif ($aud === 'dept') {
    $dept = trim($_POST['department'] ?? '');
    if ($dept === '') $dept = 'Ukendt';
    // Store department targeting in audience string (no DB migration needed)
    $audience = 'dept:' . $dept;
  } else {
    $audience = 'public';
  }

  if ($title && $body) {
    $authorName = trim((string)($u['name'] ?? $u['discord_id']));
    $stmt = $db->prepare("INSERT INTO posts(title, body, author_discord_id, audience) VALUES(?,?,?,?)");
    $stmt->execute([$title, $body, $authorName, $audience]);
  }
  redirect("leader.php?tab=posts");
}

// Delete post permanently
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_post') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $stmt = $db->prepare("DELETE FROM posts WHERE id=?");
    $stmt->execute([$id]);
  }
  redirect("leader.php?tab=posts");
}


// Archive/unarchive post (hide for everyone)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_post_archive') {
  $id = (int)($_POST['id'] ?? 0);
  $to = (int)($_POST['to'] ?? 0); // 1 = archive, 0 = unarchive
  if ($id <= 0) redirect("leader.php?tab=posts");

  if ($postsHasArchivedCol) {
    $stmt = $db->prepare("UPDATE posts SET archived=? WHERE id=?");
    $stmt->execute([$to, $id]);
  } else {
    // Fallback: encode in audience string
    $stmt = $db->prepare("SELECT audience FROM posts WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $aud = (string)($row['audience'] ?? 'public');

    if ($to === 1) {
      if (strpos($aud, 'archived|') !== 0) $aud = 'archived|' . $aud;
    } else {
      if (strpos($aud, 'archived|') === 0) $aud = substr($aud, 9);
    }
    $stmt = $db->prepare("UPDATE posts SET audience=? WHERE id=?");
    $stmt->execute([$aud, $id]);
  }

  redirect("leader.php?tab=posts");
}

// Administration: åbne/lukke ansøgninger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_applications') {
  $to = (int)($_POST['to'] ?? 1) === 1 ? '1' : '0';
  $stmt = $db->prepare("INSERT INTO portal_settings(setting_key, setting_value) VALUES('applications_open', ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value");
  $stmt->execute([$to]);
  redirect('leader.php?tab=admin#applications-control');
}
// Administration: opdater ansøgningskort tekst på index
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_application_card_text') {
  $title = trim($_POST['card_title'] ?? 'Bliv en del af politiet');
  $body = trim($_POST['card_body'] ?? 'Har du lyst til seriøs politi-RP? Send en ansøgning og fortæl os hvorfor du passer ind i Redline Politidistrikt.');

  $stmt = $db->prepare("INSERT INTO portal_settings(setting_key, setting_value) VALUES('applications_card_title', ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value");
  $stmt->execute([$title !== '' ? $title : 'Bliv en del af politiet']);

  $stmt = $db->prepare("INSERT INTO portal_settings(setting_key, setting_value) VALUES('applications_card_body', ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value");
  $stmt->execute([$body !== '' ? $body : 'Har du lyst til seriøs politi-RP? Send en ansøgning og fortæl os hvorfor du passer ind i Redline Politidistrikt.']);

  redirect('leader.php?tab=admin#applications-control');
}

// Ansøgninger: vælg Bestået / Ikke Bestået
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_application_status') {
  $id = (int)($_POST['id'] ?? 0);
  $status = trim($_POST['review_status'] ?? 'Afventer');
  if (!in_array($status, ['Afventer', 'Bestået', 'Ikke Bestået'], true)) $status = 'Afventer';

  if ($id > 0) {
    $stmt = $db->prepare("UPDATE applications SET review_status=? WHERE id=?");
    $stmt->execute([$status, $id]);
  }

  redirect('leader.php?tab=applications');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_application') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $stmt = $db->prepare("DELETE FROM applications WHERE id=?");
    $stmt->execute([$id]);
  }
  redirect('leader.php?tab=applications');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_discord_user_badge') {
  $discordId = trim($_POST['discord_id'] ?? '');
  $badge = trim($_POST['police_badge_number'] ?? '');
  if ($discordId !== '') {
    $stmt = $db->prepare("UPDATE users SET police_badge_number=? WHERE discord_id=?");
    $stmt->execute([$badge, $discordId]);
  }
  redirect('leader.php?tab=users');
}

// Update report status/notes/archive
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_report') {
  $id = (int)($_POST['id'] ?? 0);
  $status = trim($_POST['status'] ?? 'Aktiv');
  if (!in_array($status, ['Aktiv', 'Påbegyndt', 'Lukket'], true)) $status = 'Aktiv';
  $notes  = trim($_POST['leader_notes'] ?? '');

  // Arkiver hvis arkiver-knappen blev trykket
  $archivePressed = isset($_POST['archive']);
  $archived = $archivePressed ? 1 : (($reportView ?? 'active') === 'archive' ? 1 : 0);
  $stmt = $db->prepare("UPDATE reports SET status=?, leader_notes=?, archived=? WHERE id=?");
  $stmt->execute([$status,$notes,$archived,$id]);

  $leaderReply = trim($_POST['leader_reply'] ?? '');
  if ($leaderReply !== '' && $archived === 0 && $status !== 'Lukket') {
    $stmt = $db->prepare("INSERT INTO report_messages(report_id, author_role, author_name, message) VALUES(?,?,?,?)");
    $stmt->execute([$id, 'leader', ($u['name'] ?? 'Ledelse'), $leaderReply]);
  }

  redirect("leader.php?tab=" . urlencode($tab) . '#report-' . $id);
}

// ADMIN: Upload leader photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_leader_photo') {
  $discordId = trim($_POST['discord_id'] ?? '');
  if ($discordId && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $dir = __DIR__ . '/uploads/leadership';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $filename = preg_replace('/[^0-9A-Za-z_-]/','',$discordId) . '.' . $ext;
    $path = $dir . '/' . $filename;
    move_uploaded_file($_FILES['photo']['tmp_name'], $path);
    $url = 'uploads/leadership/' . $filename;
    $stmt = $db->prepare("UPDATE employees SET photo_url=? WHERE discord_id=?");
    $stmt->execute([$url, $discordId]);
  }
  redirect("leader.php?tab=admin#leaders");
}

// ADMIN: Remove leader photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_leader_photo') {
  $discordId = trim($_POST['discord_id'] ?? '');
  if ($discordId) {
    $stmt = $db->prepare("UPDATE employees SET photo_url=NULL WHERE discord_id=?");
    $stmt->execute([$discordId]);
  }
  redirect("leader.php?tab=admin#leaders");
}

/**
 * LOAD DATA
 */
$employees = $db->query("SELECT e.* FROM employees e LEFT JOIN ranks r ON r.name = e.rank ORDER BY COALESCE(r.sort_order,9999), e.name")->fetchAll();
// Preload links as map: [deptId][key] => url
$deptLinksMap = [];
$rows = $db->query("SELECT department_id, link_key, url FROM department_links")->fetchAll();
foreach ($rows as $r) {
  $did = (int)($r['department_id'] ?? 0);
  $k = (string)($r['link_key'] ?? '');
  if ($did > 0 && $k !== '') $deptLinksMap[$did][$k] = (string)($r['url'] ?? '');
}

$stmt = $db->prepare("SELECT * FROM posts ORDER BY id DESC LIMIT 25");
$stmt->execute();
$posts = $stmt->fetchAll();

$reports = [];
$reportView = $_GET['report_view'] ?? 'active';
if (!in_array($reportView, ['active','archive'], true)) $reportView = 'active';
if ($tab === 'reports') {
  $archivedFilter = $reportView === 'archive' ? 1 : 0;
  $stmt = $db->prepare("SELECT * FROM reports WHERE archived=? ORDER BY id DESC LIMIT 50");
  $stmt->execute([$archivedFilter]);
  $reports = $stmt->fetchAll();
}
$applicationsOpen = (string)$db->query("SELECT setting_value FROM portal_settings WHERE setting_key='applications_open'")->fetchColumn() === '1';
$appCardTitle = (string)($db->query("SELECT setting_value FROM portal_settings WHERE setting_key='applications_card_title'")->fetchColumn() ?: 'Bliv en del af politiet');
$appCardBody = (string)($db->query("SELECT setting_value FROM portal_settings WHERE setting_key='applications_card_body'")->fetchColumn() ?: 'Har du lyst til seriøs politi-RP? Send en ansøgning og fortæl os hvorfor du passer ind i Redline Politidistrikt.');
$applications = [];
if ($tab === 'applications') {
  $applications = $db->query("SELECT * FROM applications ORDER BY id DESC LIMIT 100")->fetchAll();
}
$discordUsers = [];
if ($tab === 'users') {
  $discordUsers = $db->query("SELECT discord_id, discord_name, police_display_name, police_badge_number, created_at FROM users ORDER BY datetime(created_at) DESC")->fetchAll();
}
$reportMessagesMap = [];
if ($tab === 'reports') {
  foreach ($reports as $rr) {
    $stmt = $db->prepare("SELECT * FROM report_messages WHERE report_id=? ORDER BY id ASC");
    $stmt->execute([(int)$rr['id']]);
    $reportMessagesMap[(int)$rr['id']] = $stmt->fetchAll();
  }
}

include __DIR__ . '/_layout.php';
?>

<div class="leader-container">

  <div class="pagehead">
    <h1>Lederportal</h1>
    <div class="muted">Administrer medarbejdere, opslag og sager</div>
  </div>

  <div class="tabs">
    <a class="<?= $tab==='employees'?'active':'' ?>" href="leader.php?tab=employees">Medarbejdere</a>
    <a class="<?= $tab==='posts'?'active':'' ?>" href="leader.php?tab=posts">Opslag</a>
    <a class="<?= $tab==='reports'?'active':'' ?>" href="leader.php?tab=reports">Sager</a>
    <a class="<?= $tab==='applications'?'active':'' ?>" href="leader.php?tab=applications">Ansøgninger</a>
    <a class="<?= $tab==='users'?'active':'' ?>" href="leader.php?tab=users">Discord Brugere</a>
    <a class="<?= $tab==='admin'?'active':'' ?>" href="leader.php?tab=admin">Administration</a>
    <a class="<?= $tab==='dept_overview'?'active':'' ?>" href="department_overview.php">Afdelingsoversigt</a>
  </div>

  <?php if ($tab === 'employees'): ?>

    <div style="height:18px"></div>

    <div class="card">
      <h2>Tilføj / opdater medarbejder</h2>
      <details style="margin-top:12px">
        <summary class="btn btn-solid">+ Tilføj medarbejder</summary>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="action" value="save_employee"/>

        <label>ID</label>
        <input name="discord_id" required>

        <label>Navn</label>
        <input name="name">

        <div class="row2">
          <div>
            <label>Badge</label>
            <input name="badge_number">
          </div>
          <div>
            <label>Rang</label>
            <select name="rank">
              <option value="">— Vælg rang —</option>
              <?php foreach ($rankRows as $r): ?>
                <option value="<?= h($r['name']) ?>"><?= h($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!$rankRows): ?><div class="muted" style="margin-top:6px">Opret rang under <b>Administration</b> først.</div><?php endif; ?>
          </div>
        </div>

        <label>Afdeling</label>
        <select name="department">
          <option value="">— Vælg afdeling —</option>
          <?php foreach ($deptRows as $d): ?>
            <option value="<?= h($d['name']) ?>"><?= h($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$deptRows): ?><div class="muted" style="margin-top:6px">Opret afdelinger under <b>Administration</b> først.</div><?php endif; ?>
          <label style="margin-top:15px;">Sekundær Afdeling</label>
<select name="secondary_department">
  <option value="">— Ingen —</option>
  <?php foreach ($deptRows as $d): ?>
    <option value="<?= h($d['name']) ?>">
      <?= h($d['name']) ?>
    </option>
  <?php endforeach; ?>
</select>

        <label>Tilmeldt dato</label>
        <input type="date" name="join_date">

        <div style="margin-top:12px">
          <button type="submit">Gem</button>
          <button type="button" class="btn" onclick="this.closest('details').removeAttribute('open')">Skjul</button>
        </div>
      </form>
      </details>

      <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08);">
        <h3 style="margin:0 0 8px 0;">Importér medarbejdere (CSV)</h3>
        <div class="muted" style="margin-bottom:8px;">Format: discord_id;navn;badge;rang;afdeling;sekundær_afdeling;tilmeldt_dato;noter</div>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <input type="hidden" name="action" value="import_employees_csv" />
          <input type="file" name="employees_csv" accept=".csv,text/csv" required />
          <button type="submit" class="btn btn-solid">Upload CSV</button>
        </form>
      </div>
       </div>
          <div class="card wide">
      <style>
        .d-none{display:none !important;}
        #empTable input.edit{width:100%; box-sizing:border-box;}
        .emp-fullwidth .table-wrapper{overflow:auto;}
        #empTable{min-width:1700px;font-size:12px;}
        #empTable th,#empTable td{padding:6px 8px;line-height:1.15;}
      </style>
      <div class="section-head">
        <h2 style="margin:0">Medarbejdertabel</h2>
        <input id="empSearch" class="search" placeholder="Søg medarbejder..." />
      </div>

      <div class="emp-fullwidth">
    <div class="table-wrapper">
        <table id="empTable" class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Navn</th>
            <th>Badge</th>
            <th>Rang</th>
            <th>Afdeling</th>
            <th>Sek. Afdeling</th>
            <th>Adv.</th>
            <th>Skr.</th>
            <th>Tilmeldt</th>
            <th>Seneste prom.</th>
            <th>Noter</th>
            <th>Handling</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($employees as $e): $eid = (string)($e['discord_id'] ?? ''); ?>
            <?php $fid = 'empform-' . preg_replace('/[^0-9A-Za-z_-]/', '', $eid); ?>
            <tr id="row-<?= h($eid) ?>">
              <td><?= h($eid) ?></td>

              <td>
                <span class="view"><b><?= h($e['name'] ?? '') ?></b></span>
                <input class="edit d-none" form="<?= h($fid) ?>" name="name" value="<?= h($e['name'] ?? '') ?>" data-orig="<?= h($e['name'] ?? '') ?>">
              </td>

              <td>
                <span class="view"><?= h($e['badge_number'] ?? '') ?></span>
                <input class="edit d-none" form="<?= h($fid) ?>" name="badge_number" value="<?= h($e['badge_number'] ?? '') ?>" data-orig="<?= h($e['badge_number'] ?? '') ?>">
              </td>

              <td>
                <span class="view"><span class="chip"><?= h($e['rank'] ?? '') ?></span></span>
                <input class="edit d-none" form="<?= h($fid) ?>" name="rank" value="<?= h($e['rank'] ?? '') ?>" data-orig="<?= h($e['rank'] ?? '') ?>">
              </td>

              <td>
                <span class="view"><?= h($e['department'] ?? '') ?></span>
                <input class="edit d-none" form="<?= h($fid) ?>" name="department" value="<?= h($e['department'] ?? '') ?>" data-orig="<?= h($e['department'] ?? '') ?>">
              </td>

              <td>
                <span class="view"><?= h($e['secondary_department'] ?? '') ?></span>
                <input class="edit d-none" form="<?= h($fid) ?>" name="secondary_department" value="<?= h($e['secondary_department'] ?? '') ?>" data-orig="<?= h($e['secondary_department'] ?? '') ?>">
              </td>

              <td>
                <span class="view"><?= (int)($e['verbal_warnings'] ?? 0) ?></span>
                <input class="small-num" type="number" min="0" form="<?= h($fid) ?>" name="verbal_warnings"
                       value="<?= (int)($e['verbal_warnings'] ?? 0) ?>" data-orig="<?= (int)($e['verbal_warnings'] ?? 0) ?>">
              </td>

              <td>
                <span class="view"><?= (int)($e['written_warnings'] ?? 0) ?></span>
                <input class="small-num" type="number" min="0" form="<?= h($fid) ?>" name="written_warnings"
                       value="<?= (int)($e['written_warnings'] ?? 0) ?>" data-orig="<?= (int)($e['written_warnings'] ?? 0) ?>">
              </td>

              <td>
                <span class="view"><?= h($e['join_date'] ?? '') ?></span>
                <input class="edit d-none" type="date" form="<?= h($fid) ?>" name="join_date"
                       value="<?= h($e['join_date'] ?? '') ?>" data-orig="<?= h($e['join_date'] ?? '') ?>">
              </td>

              <td>
                <span class="view"><?= h($e['latest_promotion_date'] ?? '') ?></span>
                <input class="edit d-none" type="date" form="<?= h($fid) ?>" name="latest_promotion_date"
                       value="<?= h($e['latest_promotion_date'] ?? '') ?>" data-orig="<?= h($e['latest_promotion_date'] ?? '') ?>">
              </td>

              <td>
                <span class="view"><?= h($e['notes'] ?? '') ?></span>
                <input class="edit d-none" form="<?= h($fid) ?>" name="notes" value="<?= h($e['notes'] ?? '') ?>" data-orig="<?= h($e['notes'] ?? '') ?>">
              </td>

              <td style="white-space:nowrap;">
                <form id="<?= h($fid) ?>" method="post" style="display:inline;">
                  <input type="hidden" name="discord_id" value="<?= h($eid) ?>"/>

                  <button type="button" class="btn btn-solid editBtn" onclick="enableEmpEdit('<?= h($eid) ?>')"><i class="fa-solid fa-pen"></i></button>
                  <button type="submit" name="action" value="save_employee" class="btn btn-solid d-none saveBtn">Gem</button>
                  <button type="button" class="btn d-none cancelBtn" onclick="cancelEmpEdit('<?= h($eid) ?>')">Annuller</button>

                  <button type="submit" name="action" value="delete_employee" class="btn"
                          onclick="return confirm('Er du sikker på at du vil fjerne denne person fra listen?');">
                          <i class="fa-solid fa-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$employees): ?>
            <tr><td colspan="11">Ingen medarbejdere endnu.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    </div>
    <script>
      (function(){
        const sel = document.getElementById('audienceSelect');
        const wrap = document.getElementById('deptWrap');
        if(!sel || !wrap) return;
        function sync(){ wrap.style.display = (sel.value === 'dept') ? 'block' : 'none'; }
        sel.addEventListener('change', sync);
        sync();
      })();
    </script>

    <script>
      const search = document.getElementById('empSearch');
      const table  = document.getElementById('empTable');
      search?.addEventListener('input', () => {
        const q = search.value.toLowerCase().trim();
        for (const row of table.tBodies[0].rows) {
          const txt = row.innerText.toLowerCase();
          row.style.display = txt.includes(q) ? '' : 'none';
        }
      });
    
      function enableEmpEdit(id) {
        const row = document.getElementById('row-' + id);
        if (!row) return;

        // Close any other row currently in edit mode
        document.querySelectorAll('#empTable tbody tr.editing').forEach(r => {
          if (r.id !== ('row-' + id)) cancelEmpEdit(r.id.replace('row-',''));
        });

        row.classList.add('editing');
        row.querySelectorAll('.view').forEach(el => el.classList.add('d-none'));
        row.querySelectorAll('.edit').forEach(el => el.classList.remove('d-none'));

        row.querySelectorAll('.editBtn').forEach(el => el.classList.add('d-none'));
        row.querySelectorAll('.saveBtn').forEach(el => el.classList.remove('d-none'));
        row.querySelectorAll('.cancelBtn').forEach(el => el.classList.remove('d-none'));
      }

      function cancelEmpEdit(id) {
        const row = document.getElementById('row-' + id);
        if (!row) return;

        row.classList.remove('editing');
        // Reset values to original
        row.querySelectorAll('.edit').forEach(el => {
          if (el.dataset && typeof el.dataset.orig !== 'undefined') {
            el.value = el.dataset.orig;
          }
          el.classList.add('d-none');
        });
        row.querySelectorAll('.view').forEach(el => el.classList.remove('d-none'));

        row.querySelectorAll('.editBtn').forEach(el => el.classList.remove('d-none'));
        row.querySelectorAll('.saveBtn').forEach(el => el.classList.add('d-none'));
        row.querySelectorAll('.cancelBtn').forEach(el => el.classList.add('d-none'));
      }

    </script>

  <?php elseif ($tab === 'posts'): ?>
    <div class="card">
      <h2>Lav opslag</h2>
      <form method="post">
        <input type="hidden" name="action" value="create_post"/>

        <label>Titel</label>
        <input name="title" required>

        <label>Tekst</label>
        <textarea name="body" rows="6" required></textarea>

        <label>Synlighed</label>
        <select name="audience" id="audienceSelect">
          <option value="frontpage">Forside</option>
          <option value="police">Kun Politi (alle afdelinger)</option>
          <option value="dept">Kun specifik afdeling</option>
        </select>

        <div id="deptWrap" style="display:none;margin-top:10px">
          <label>Vælg afdeling</label>
          <select name="department">
            <?php foreach ($departments as $d): ?>
              <option value="<?= h($d['department']) ?>"><?= h($d['department']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="muted" style="margin-top:6px">Opslaget vises kun på police.php for den valgte afdeling.</div>
        </div>

        <div style="margin-top:12px">
          <button type="submit">Udgiv</button>
        </div>
      </form>
    </div>
    <h2>Upload billeder til slideshow</h2>

<form method="post" enctype="multipart/form-data">
    <input type="file" name="slideshow_images[]" multiple accept="image/*">
    <button type="submit" name="upload_slideshow">Upload billeder</button>
</form>

<?php
$targetDir = __DIR__ . "/uploads/slideshow/";
$publicPath = "uploads/slideshow/";

// Opret mappe hvis den ikke findes
if (!file_exists($targetDir)) {
    mkdir($targetDir, 0777, true);
}

// UPLOAD
if (isset($_POST['upload_slideshow'])) {

    foreach ($_FILES['slideshow_images']['tmp_name'] as $key => $tmp_name) {

        $fileName = time() . "_" . basename($_FILES['slideshow_images']['name'][$key]);
        $targetFile = $targetDir . $fileName;

        move_uploaded_file($tmp_name, $targetFile);
    }

    echo "<p>Billeder uploadet!</p>";
}

// SLET
if (isset($_GET['delete'])) {
    $fileToDelete = basename($_GET['delete']);
    $filePath = $targetDir . $fileToDelete;

    if (file_exists($filePath)) {
        unlink($filePath);
        echo "<p>Billede slettet!</p>";
    }
}

// VIS ALLE BILLEDER MED SLET KNAP
$images = glob($targetDir . "*.{jpg,jpeg,png,gif,webp}", GLOB_BRACE);

echo "<h3>Nuværende billeder:</h3>";
echo "<div style='display:flex;flex-wrap:wrap;gap:12px'>";
foreach ($images as $image) {
    $fileName = basename($image);
    echo "<div style='margin-bottom:10px;display:flex;flex-direction:column;align-items:flex-start;gap:6px;'>";
    echo "<img src='{$publicPath}{$fileName}' width='150' style='border-radius:8px;'>";
    echo "<a href='?tab=posts&delete={$fileName}' onclick='return confirm(\"Slet billede?\")'>
        <button style=\"background:red;color:white;\">Slet</button>
      </a>";
    echo "</div>";
}
echo "</div>";
?>
    <script>
      (function(){
        const sel = document.getElementById('audienceSelect');
        const wrap = document.getElementById('deptWrap');
        if(!sel || !wrap) return;
        function sync(){ wrap.style.display = (sel.value === 'dept') ? 'block' : 'none'; }
        sel.addEventListener('change', sync);
        sync();
      })();
    </script>

    <div style="height:18px"></div>

    <div class="card">
      <h2>Seneste opslag</h2>
      <?php foreach ($posts as $p): ?>
        <?php
          $isArchived = _post_is_archived($p, $postsHasArchivedCol);
          $audNorm = _post_audience_norm($p['audience'] ?? 'public');
        ?>
        <div class="post" style="<?= $isArchived ? 'opacity:.55;' : '' ?>">
          <div class="body">
            <h3 style="display:flex;align-items:center;gap:8px;justify-content:space-between;">
              <span style="display:flex;align-items:center;gap:8px;">
                <?= h($p['title']) ?>
                <?php if ($audNorm === 'frontpage' || $audNorm === 'public'): ?><span class="badge">Forside</span><?php elseif ($audNorm === 'police'): ?>
                  <span class="badge">Kun politi</span>
                <?php elseif (strpos($audNorm, 'dept:') === 0): ?>
                  <span class="badge"><?= h(substr($audNorm, 5)) ?></span>
                <?php endif; ?>
                <?php if ($isArchived): ?>
                  <span class="badge" style="background:#6b7280">Arkiveret</span>
                <?php endif; ?>
              </span>

               <span style="display:flex;gap:8px;align-items:center;">
                <form method="post" style="margin:0;display:flex;gap:8px;align-items:center;">
                  <input type="hidden" name="action" value="toggle_post_archive">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <?php if (!$isArchived): ?>
                    <button class="btn" type="submit" name="to" value="1" title="Skjul opslag for alle">Arkiver</button>
                  <?php else: ?>
                    <button class="btn btn-solid" type="submit" name="to" value="0" title="Gør opslag synligt igen">Gendan</button>
                  <?php endif; ?>
                </form>
                <?php if (!$isArchived): ?>
                  <form method="post" style="margin:0;" onsubmit="return confirm('Slet opslag permanent?');">
                    <input type="hidden" name="action" value="delete_post">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn" type="submit" title="Slet opslag permanent">Slet</button>
                  </form>
                <?php endif; ?>
              </span>
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
      <?php if (!$posts): ?><p class="muted">Ingen opslag endnu.</p><?php endif; ?>
    </div>

    <?php elseif ($tab === 'users'): ?>
<div class="card">
  <div class="section-head">
    <h2 style="margin:0">Discord brugere</h2>
    <div class="muted">Alle loggede Discord-konti med badge og navn</div>
  </div>
  <table class="table">
    <thead><tr><th>Discord ID</th><th>Discord navn</th><th>Politi navn</th><th>Badge nr.</th><th>Logget ind</th><th>Handling</th></tr></thead>
    <tbody>
      <?php foreach ($discordUsers as $du): ?>
        <tr>
          <td><?= h($du['discord_id']) ?></td>
          <td><?= h($du['discord_name']) ?></td>
          <td><?= h($du['police_display_name'] ?? '') ?></td>
          <td>
            <input
              class="discord-badge-input"
              data-discord-id="<?= h($du['discord_id']) ?>"
              name="police_badge_number"
              value="<?= h($du['police_badge_number'] ?? '') ?>"
              style="min-width:100px"
            />
          </td>
          <td><?= h($du['created_at'] ?? '') ?></td>
          <td class="muted">Gemmer automatisk ved ændring</td>
        </tr>
      <?php endforeach; ?>
       <?php if (!$discordUsers): ?><tr><td colspan="6">Ingen brugere endnu.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

  <?php elseif ($tab === 'admin'): ?>

    <style>
       .admin-grid{display:grid;grid-template-columns:repeat(2,minmax(320px,1fr));gap:14px;align-items:start;}
      .mini-table{width:100%;border-collapse:collapse;margin-top:10px;}
      .mini-table th,.mini-table td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;}
      .mini-table th{opacity:.85;font-weight:700;font-size:.9rem;}
      .linkgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;}
      .linkcard{display:flex;gap:12px;align-items:flex-start;padding:14px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(255,255,255,.03);}
      .linkcard .icon{font-size:1.6rem;line-height:1;}
      .admin-scroll{max-height:170px;overflow:auto;margin-top:10px;border:1px solid var(--border);} 
    </style>

    <div class="admin-grid">

      <div class="card" id="ranks">
        <h2>Rang</h2>
        <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <input type="hidden" name="action" value="add_rank" />
          <div style="flex:1;min-width:220px">
            <label>Ny rang</label>
            <input name="rank_name" placeholder="Fx. Politichef" />
          </div>
          <div>
            <button class="btn btn-solid" type="submit">Opret</button>
          </div>
        </form>

        <div class="admin-scroll">
        <table class="mini-table">
        <thead><tr><th>Navn</th><th style="width:180px">Handling</th></tr></thead>
          <tbody>
            <?php foreach ($rankRows as $r): ?>
              <tr>
                <td><?= h($r['name']) ?></td>
                <td style="display:flex;gap:6px;">
                  <form method="post" style="margin:0">
                    <input type="hidden" name="action" value="move_rank" />
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" />
                    <input type="hidden" name="dir" value="up" />
                    <button class="btn" type="submit">↑</button>
                  </form>
                  <form method="post" style="margin:0">
                    <input type="hidden" name="action" value="move_rank" />
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" />
                    <input type="hidden" name="dir" value="down" />
                    <button class="btn" type="submit">↓</button>
                  </form>
                  <form method="post" style="margin:0">
                    <input type="hidden" name="action" value="delete_rank" />
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" />
                    <button class="btn" type="submit" onclick="return confirm('Slet rang?');">Slet</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rankRows): ?><tr><td colspan="2" class="muted">Ingen rang oprettet endnu.</td></tr><?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>

      <div class="card" id="departments">
        <h2>Afdelinger</h2>
        <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <input type="hidden" name="action" value="add_department" />
          <div style="flex:1;min-width:220px">
            <label>Ny afdeling</label>
            <input name="department_name" placeholder="Fx. Patruljeafdelingen" />
          </div>
          <div>
            <button class="btn btn-solid" type="submit">Opret</button>
          </div>
        </form>

        <div class="admin-scroll">
        <table class="mini-table">
        <thead><tr><th>Navn</th><th style="width:180px">Handling</th></tr></thead>
          <tbody>
            <?php foreach ($deptRows as $d): ?>
              <tr>
                <td><?= h($d['name']) ?></td>
                <td>
                  <form method="post" style="margin:0">
                    <input type="hidden" name="action" value="delete_department" />
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>" />
                    <button class="btn" type="submit" onclick="return confirm('Slet afdeling? (Links slettes også)');">Slet</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$deptRows): ?><tr><td colspan="2" class="muted">Ingen afdelinger oprettet endnu.</td></tr><?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>

     <div class="card" id="links" style="grid-column:2;grid-row:2;">
        <div class="section-head">
          <div>
            <div class="section-kicker">POLICE.PHP</div>
            <h2 style="margin:0">Ressourcer & Links pr. afdeling</h2>
          </div>
          <div class="muted">Vælg én afdeling ad gangen</div>
        </div>

        <?php if (!$deptRows): ?>
          <p class="muted" style="margin:0">Opret mindst 1 afdeling først.</p>
        <?php else: ?>
          <label>Vælg afdeling</label>
          <select id="linkDeptSelect" style="max-width:360px">
            <?php foreach ($deptRows as $d): ?>
              <option value="dept-link-<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($deptRows as $d): $did = (int)$d['id']; ?>
            <div class="dept-link-panel" id="dept-link-<?= $did ?>" style="display:none; margin-top:12px;">
              <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <h3 style="margin:0"><?= h($d['name']) ?></h3>
                <div class="muted">Afdelingen bruger disse links i <code>police.php</code></div>
              </div>

            <form method="post" style="margin-top:10px">
                <input type="hidden" name="action" value="save_department_links" />
                <input type="hidden" name="department_id" value="<?= $did ?>" />

                <div class="linkgrid">
                  <?php foreach ($RESOURCE_BUTTONS as $btn):
                    $k = $btn['key'];
                    $val = $deptLinksMap[$did][$k] ?? '';
                  ?>
                    <div class="linkcard">
                      <div class="icon"><?= h($btn['icon']) ?></div>
                      <div style="flex:1">
                        <div style="font-weight:800"><?= h($btn['title']) ?></div>
                        <div class="muted" style="margin-bottom:8px"><?= h($btn['desc']) ?></div>
                        <input name="link_<?= h($k) ?>" value="<?= h($val) ?>" placeholder="https://..." />
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

              <div style="margin-top:12px">
                  <button class="btn btn-solid" type="submit">Gem links for <?= h($d['name']) ?></button>
                </div>
              </form>
            </div>
          <?php endforeach; ?>
          <script>
            (function(){
              const select = document.getElementById('linkDeptSelect');
              if (!select) return;
              const panels = Array.from(document.querySelectorAll('.dept-link-panel'));
              function syncPanels(){
                panels.forEach((p) => { p.style.display = (p.id === select.value) ? 'block' : 'none'; });
              }
              select.addEventListener('change', syncPanels);
              syncPanels();
            })();
          </script>
        <?php endif; ?>
        </div>

      <div class="card" id="leaders" style="grid-column:1;grid-row:2;">
        <h2>Øverste Ledelse – Billeder</h2>
        <?php
          $leaders = $db->query("SELECT discord_id,name,photo_url FROM employees WHERE department='Øverste Ledelse' ORDER BY name")->fetchAll();
        ?>
        <?php if (!$leaders): ?>
          <div class="muted">Ingen medarbejdere i Øverste Ledelse.</div>
        <?php else: ?>
          <label>Vælg leder</label>
          <select id="leaderPhotoSelect" style="max-width:360px">
            <?php foreach ($leaders as $l): ?>
              <option value="leader-photo-<?= h($l['discord_id']) ?>"><?= h($l['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($leaders as $l): ?>
            <div class="leader-photo-panel" id="leader-photo-<?= h($l['discord_id']) ?>" style="display:none;margin-top:16px;padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:12px;">
              <strong><?= h($l['name']) ?></strong>
              <div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <?php if (!empty($l['photo_url'])): ?>
                  <img src="<?= h($l['photo_url']) ?>" style="width:70px;height:70px;border-radius:50%;object-fit:cover;">
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                  <input type="hidden" name="action" value="upload_leader_photo">
                  <input type="hidden" name="discord_id" value="<?= h($l['discord_id']) ?>">
                  <input type="file" name="photo" required>
                  <button class="btn btn-solid" type="submit">Upload</button>
                </form>
                <?php if (!empty($l['photo_url'])): ?>
                  <form method="post">
                    <input type="hidden" name="action" value="remove_leader_photo">
                    <input type="hidden" name="discord_id" value="<?= h($l['discord_id']) ?>">
                    <button class="btn" type="submit">Fjern</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <script>
            (function(){
              const select = document.getElementById('leaderPhotoSelect');
              const panels = Array.from(document.querySelectorAll('.leader-photo-panel'));
              if (!select) return;
              function sync(){
                panels.forEach((p) => p.style.display = (p.id === select.value) ? 'block' : 'none');
              }
              select.addEventListener('change', sync);
              sync();
            })();
          </script>
        <?php endif; ?>
      </div>
      <div class="card" id="applications-control" style="grid-column:2;grid-row:3;">
        <h2>Ansøgninger (Administration)</h2>
        <p class="muted">Styr om ansøgninger er åbne/lukkede og redigér teksten på ansøgningskortet på forsiden.</p>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
          <span class="badge">Status: <?= $applicationsOpen ? 'Åben' : 'Lukket' ?></span>
          <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="toggle_applications" />
            <button class="btn btn-solid" type="submit" name="to" value="<?= $applicationsOpen ? '0' : '1' ?>">
              <?= $applicationsOpen ? 'Luk ansøgning' : 'Åben ansøgning' ?>
            </button>
          </form>
        </div>

        <form method="post">
          <input type="hidden" name="action" value="save_application_card_text" />
          <label>Kort titel (Opslag på index.php)</label>
          <input name="card_title" value="<?= h($appCardTitle) ?>" />

          <label>Kort tekst (Opslag på index.php)</label>
          <textarea name="card_body" rows="3"><?= h($appCardBody) ?></textarea>

          <div style="margin-top:10px;">
            <button class="btn btn-solid" type="submit">Gem tekst</button>
          </div>
        </form>
      </div>

    </div>

    <?php elseif ($tab === 'reports'): ?>
<div class="card">
    <div class="section-head">
    <h2 style="margin:0">Sager</h2>
        <div class="muted">Administrér aktive og arkiverede sager</div>
    </div>
    <div style="margin:10px 0 12px;display:flex;gap:8px;">
      <a class="btn <?= $reportView==='active' ? 'btn-solid' : '' ?>" href="leader.php?tab=reports&report_view=active">Aktive</a>
      <a class="btn <?= $reportView==='archive' ? 'btn-solid' : '' ?>" href="leader.php?tab=reports&report_view=archive">Arkiv</a>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Oprettet af</th>
                <th>Type</th>
                <th>Status</th>
                <th>Oprettet</th>
                <th>Handling</th>
            </tr>
        </thead>
        <tbody>

        <?php foreach ($reports as $r): ?>
          <?php $isArchived = ($reportView === 'archive'); ?>
            <tr id="report-<?= (int)$r['id'] ?>">
                <td><?= h($r['report_uid']) ?></td>
                <td><?= h($r['created_by_name']) ?></td>
                <td><?= h($r['report_kind'] ?? 'Rapport/Klage') ?></td>
                  <td>  
                <?= h($r['status']) ?>
                    <?php if ($isArchived): ?>
                        <span style="color:red; font-weight:bold;">(ARKIVERET)</span>
                    <?php endif; ?>
                </td>
                <td><?= h($r['created_at']) ?></td>
                <td>
                    <button type="button"
                            class="btn btn-solid"
                            onclick="openReportModal(<?= (int)$r['id'] ?>)">
                        Åbn
                    </button>

                    <div id="report-content-<?= (int)$r['id'] ?>" style="display:none;">
                        <h3 style="margin:0 0 10px 0;">
                            Report #<?= (int)$r['id'] ?>
                        </h3>
                        <div style="margin-bottom:12px;"><div><b>Type:</b> <?= h($r['report_kind'] ?? 'Rapport/Klage') ?></div>
                            <label style="display:block; font-weight:600; margin-bottom:6px;">
                                Report content
                            </label>
                            <div class="muted"
                                 style="padding:10px; border:1px solid #ddd; border-radius:6px;">
                                <?= nl2br(h($r['details'])) ?>
                            </div>
                        </div>

                        <form method="post" style="margin-top:12px">

                            <input type="hidden" name="action" value="update_report"/>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"/>

                            <label>Status</label>
                            <select name="status" <?= $isArchived ? 'disabled' : '' ?>>
                                <?php foreach (['Aktiv','Påbegyndt','Lukket'] as $st): ?>
                                    <option value="<?= h($st) ?>"
                                        <?= $r['status']===$st?'selected':'' ?>>
                                        <?= h($st) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label>Leader notes (kun synlig for ledelse)</label>
                            <textarea name="leader_notes"
                                      rows="3"
                                      <?= $isArchived ? 'disabled' : '' ?>><?= h($r['leader_notes'] ?? '') ?></textarea>

                                      <h4 style="margin-top:12px;">Samtale</h4>
                            <?php foreach (($reportMessagesMap[(int)$r['id']] ?? []) as $msg): ?>
                              <div style="padding:8px;border:1px solid rgba(255,255,255,.08);border-radius:8px;margin-bottom:8px;">
                                <b><?= h($msg['author_name']) ?></b> <span class="muted">(<?= h($msg['author_role']) ?>) • <?= h($msg['created_at']) ?></span>
                                <div><?= nl2br(h($msg['message'])) ?></div>
                              </div>
                            <?php endforeach; ?>


                            <?php if (!$isArchived): ?>
                              <label>Svar til borger</label>
                              <textarea name="leader_reply" rows="3" placeholder="Skriv et svar som borgeren kan se"></textarea>
                                <button type="submit"
                                        name="archive"
                                        value="1"
                                        class="btn btn-danger">
                                        Arkiver sag
                                </button>

                                <div style="margin-top:10px">
                                    <button type="submit">Gem</button>
                                </div>
                            <?php else: ?>
                                <div style="margin-top:10px; color:red; font-weight:600;">
                                    Denne sag er arkiveret og kan ikke redigeres.
                                </div>
                            <?php endif; ?>

                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if (!$reports): ?>
            <tr>
                <td colspan="5">Ingen sager.</td>
            </tr>
        <?php endif; ?>

        </tbody>
    </table>
</div>
<?php elseif ($tab === 'applications'): ?>
<div class="card">
  <div class="section-head">
    <h2 style="margin:0">Ansøgninger</h2>
    <div class="muted">Indsendte ansøgninger fra borgersiden</div>
  </div>

  <table class="table">
    <thead><tr><th>ID</th><th>Navn</th><th>Status</th><th>Dato</th><th>Handling</th></tr></thead>
    <tbody>
      <?php foreach ($applications as $a): ?>
        <tr>
          <td><?= (int)$a['id'] ?></td>
          <td><?= h($a['applicant_name']) ?></td>
          <td>
            <form method="post" class="application-status-form" style="display:flex;gap:8px;align-items:center;">
              <input type="hidden" name="action" value="set_application_status" />
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>" />
              <select name="review_status" onchange="this.form.submit()">
                <?php foreach (['Afventer','Bestået','Ikke Bestået'] as $st): ?>
                  <option value="<?= h($st) ?>" <?= (($a['review_status'] ?? 'Afventer') === $st) ? 'selected' : '' ?>><?= h($st) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td><?= h($a['created_at']) ?></td>
          <td style="display:flex;gap:8px;align-items:center;"><button type="button" class="btn btn-solid" onclick="openApplicationModal(<?= (int)$a['id'] ?>)">Åbn</button><form method="post" style="margin:0;" onsubmit="return confirm('Slet ansøgning?');"><input type="hidden" name="action" value="delete_application" /><input type="hidden" name="id" value="<?= (int)$a['id'] ?>" /><button type="submit" class="btn">Slet</button></form></td>
        </tr>
      <?php endforeach; ?>
     <?php if (!$applications): ?><tr><td colspan="5">Ingen ansøgninger endnu.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php foreach ($applications as $a): ?>
    <div id="application-content-<?= (int)$a['id'] ?>" style="display:none;">
      <h3>Ansøgning #<?= (int)$a['id'] ?> - <?= h($a['applicant_name']) ?></h3>
      <p><b>Kort præsentation:</b><br><?= nl2br(h($a['q_presentation'])) ?></p>
      <p><b>Aktivitet pr. uge:</b><br><?= nl2br(h($a['q_activity'])) ?></p>
      <p><b>Tidligere erfaring:</b><br><?= nl2br(h($a['q_experience'])) ?></p>
      <p><b>Hvorfor vælge dig:</b><br><?= nl2br(h($a['q_why_me'])) ?></p>
      <p><b>Konflikthåndtering:</b><br><?= nl2br(h($a['q_conflict_handling'])) ?></p>
      <p><b>Struktur og kommunikation:</b><br><?= nl2br(h($a['q_structure_communication'])) ?></p>
      <p><b>Accepterer regler:</b> <?= h($a['q_accept_rules']) ?></p>
      <p><b>Status:</b> <?= h($a['review_status'] ?? 'Afventer') ?></p>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<footer class="footer">
  © 2026 Redline Politidistrikt — FiveM Roleplay Server
</footer>
<div id="reportModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeReportModal()">&times;</span>
    <div id="reportModalContent"></div>
  </div>
</div>
<div id="applicationModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeApplicationModal()">&times;</span>
    <div id="applicationModalContent"></div>
  </div>
</div>
<script>
function openReportModal(id) {
  const modal = document.getElementById("reportModal");
  const content = document.getElementById("reportModalContent");

  // hent eksisterende hidden HTML
  const reportContent = document.getElementById("report-content-" + id);
  if (!modal || !content || !reportContent) return;
  content.innerHTML = reportContent.innerHTML;

  modal.style.display = "block";
}

function closeReportModal() {
  const m = document.getElementById("reportModal");
  if (m) m.style.display = "none";
}
function openApplicationModal(id) {
  const modal = document.getElementById("applicationModal");
  const content = document.getElementById("applicationModalContent");
  const source = document.getElementById("application-content-" + id);
  if (!modal || !content || !source) return;
  content.innerHTML = source.innerHTML;
  modal.style.display = "block";
}
function closeApplicationModal() {
  const m = document.getElementById("applicationModal");
  if (m) m.style.display = "none";
}
document.querySelectorAll('.discord-badge-input').forEach(function(input){
  let t = null;
  const save = function(){
    const fd = new FormData();
    fd.append('action','update_discord_user_badge');
    fd.append('discord_id', input.dataset.discordId || '');
    fd.append('police_badge_number', input.value || '');
    fetch('leader.php?tab=users', { method:'POST', body: fd }).catch(function(){});
  };
  input.addEventListener('input', function(){
    if (t) clearTimeout(t);
    t = setTimeout(save, 450);
  });
  input.addEventListener('blur', save);
});


window.addEventListener('click', function(e){
  const reportModal = document.getElementById('reportModal');
  const appModal = document.getElementById('applicationModal');
  if (e.target === reportModal) closeReportModal();
  if (e.target === appModal) closeApplicationModal();
});
</script>
</body></html>
