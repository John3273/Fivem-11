<?php
// scripts/init_db.php
require __DIR__ . '/../app/db.php';

$db = db();

$db->exec("
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  discord_id TEXT UNIQUE,
  discord_name TEXT,
  avatar_url TEXT,
  roles_json TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  author_discord_id TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_uid TEXT UNIQUE NOT NULL,
  created_by_discord_id TEXT NOT NULL,
  created_by_name TEXT NOT NULL,
  page_origin TEXT NOT NULL,           -- citizen|police
  badge_number TEXT,                   -- police only
  details TEXT NOT NULL,
  evidence_path TEXT,
  visibility_json TEXT,                -- police: allowed roles
  status TEXT NOT NULL DEFAULT 'Open', -- Open|Ongoing|Closed
  leader_notes TEXT,
  archived INTEGER NOT NULL DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);

CREATE TRIGGER IF NOT EXISTS reports_updated_at
AFTER UPDATE ON reports
BEGIN
  UPDATE reports SET updated_at = datetime('now') WHERE id = NEW.id;
END;


CREATE TABLE IF NOT EXISTS cases (
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
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);

CREATE TRIGGER IF NOT EXISTS cases_updated_at
AFTER UPDATE ON cases
BEGIN
  UPDATE cases SET updated_at = datetime('now') WHERE id = NEW.id;
END;

CREATE TABLE IF NOT EXISTS case_comments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id INTEGER NOT NULL,
  author_discord_id TEXT NOT NULL,
  author_name TEXT,
  comment TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS employees (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  discord_id TEXT UNIQUE NOT NULL,
  name TEXT,
  badge_number TEXT,
  rank TEXT,
  department TEXT,
  qualifications TEXT,
  verbal_warnings INTEGER DEFAULT 0,
  written_warnings INTEGER DEFAULT 0,
  notes TEXT,
  join_date TEXT,
  latest_promotion_date TEXT
);
");

$cols = $db->query("PRAGMA table_info(case_comments)")->fetchAll();
$cc = [];
foreach ($cols as $c) {
  $name = (string)($c['name'] ?? '');
  if ($name !== '') $cc[$name] = true;
}
if (!isset($cc['case_id'])) {
  try { $db->exec("ALTER TABLE case_comments ADD COLUMN case_id INTEGER"); } catch (Throwable $e) {}
}
if (!isset($cc['author_discord_id'])) {
  try { $db->exec("ALTER TABLE case_comments ADD COLUMN author_discord_id TEXT"); } catch (Throwable $e) {}
}
if (!isset($cc['author_name'])) {
  try { $db->exec("ALTER TABLE case_comments ADD COLUMN author_name TEXT"); } catch (Throwable $e) {}
}
if (!isset($cc['comment'])) {
  try { $db->exec("ALTER TABLE case_comments ADD COLUMN comment TEXT"); } catch (Throwable $e) {}
}
if (!isset($cc['created_at'])) {
  try { $db->exec("ALTER TABLE case_comments ADD COLUMN created_at TEXT"); } catch (Throwable $e) {}
}


// --- migration: add posts.audience column if missing ---
$cols = $db->query("PRAGMA table_info(posts)")->fetchAll();
$hasAudience = false;
foreach ($cols as $c) {
  if (($c['name'] ?? '') === 'audience') { $hasAudience = true; break; }
}
if (!$hasAudience) {
  $db->exec("ALTER TABLE posts ADD COLUMN audience TEXT NOT NULL DEFAULT 'public'");
}


$cols = $db->query("PRAGMA table_info(users)")->fetchAll();
$hasPoliceDisplay = false;
$hasPoliceBadge = false;
foreach ($cols as $c) {
  if (($c['name'] ?? '') === 'police_display_name') $hasPoliceDisplay = true;
  if (($c['name'] ?? '') === 'police_badge_number') $hasPoliceBadge = true;
}
if (!$hasPoliceDisplay) {
  $db->exec("ALTER TABLE users ADD COLUMN police_display_name TEXT");
}
if (!$hasPoliceBadge) {
  $db->exec("ALTER TABLE users ADD COLUMN police_badge_number TEXT");
}

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