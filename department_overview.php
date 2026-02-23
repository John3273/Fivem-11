<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

require_role('POLICE', 'LEADER');

$db = db();
$u = current_user();
$isLeader = in_array('LEADER', $u['roles'] ?? [], true);

$db->exec("CREATE TABLE IF NOT EXISTS department_member_profiles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_discord_id TEXT NOT NULL,
  department_name TEXT NOT NULL,
  responsibility_title TEXT,
  note TEXT,
  is_trainer INTEGER NOT NULL DEFAULT 0,
  UNIQUE(employee_discord_id, department_name)
)");
try { $db->query("SELECT is_trainer FROM department_member_profiles LIMIT 1"); } catch (Throwable $e) { $db->exec("ALTER TABLE department_member_profiles ADD COLUMN is_trainer INTEGER NOT NULL DEFAULT 0"); }

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

$deptRows = $db->query("SELECT name FROM departments ORDER BY sort_order, name")->fetchAll();
$selectedDept = trim((string)($_GET['dept'] ?? ($_POST['department'] ?? ($deptRows[0]['name'] ?? ''))));

$canTrainerToggle = false;
if (!$isLeader && $selectedDept !== '') {
  $stmt = $db->prepare("SELECT 1 FROM department_member_profiles WHERE department_name=? AND employee_discord_id=? AND COALESCE(is_trainer,0)=1 LIMIT 1");
  $stmt->execute([$selectedDept, (string)($u['discord_id'] ?? '')]);
  $canTrainerToggle = (bool)$stmt->fetchColumn();
}
$canToggleChecks = $isLeader || $canTrainerToggle;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $postDept = trim((string)($_POST['department'] ?? $selectedDept));
  $isAjax = (($_POST['ajax'] ?? '') === '1') || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
  $json = function(array $payload) use ($isAjax): void {
    if ($isAjax) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode($payload);
      exit;
    }
  };

  if ($action === 'add_training_column' && $postDept !== '') {
    if (!$isLeader) {
      http_response_code(403);
      $json(['ok' => false, 'message' => 'Ingen adgang']);
      exit('Ingen adgang');
    }
    $name = trim((string)($_POST['column_name'] ?? ''));
    if ($name !== '') {
      $stmt = $db->prepare("INSERT OR IGNORE INTO department_training_columns(department_name, column_name) VALUES(?,?)");
      $stmt->execute([$postDept, $name]);
    }
    if ($isAjax) $json(['ok' => true]);
    redirect('department_overview.php?dept=' . urlencode($postDept));
  }

  if ($action === 'delete_training_column' && $postDept !== '') {
    if (!$isLeader) {
      http_response_code(403);
      $json(['ok' => false, 'message' => 'Ingen adgang']);
      exit('Ingen adgang');
    }
    $colId = (int)($_POST['column_id'] ?? 0);
    if ($colId > 0) {
      $db->prepare("DELETE FROM department_training_checks WHERE training_column_id=?")->execute([$colId]);
      $db->prepare("DELETE FROM department_training_columns WHERE id=? AND department_name=?")->execute([$colId, $postDept]);
    }
    if ($isAjax) $json(['ok' => true]);
    redirect('department_overview.php?dept=' . urlencode($postDept));
  }

  if ($action === 'toggle_training_check' && $postDept !== '') {
    if (!$canToggleChecks) {
      http_response_code(403);
      $json(['ok' => false, 'message' => 'Ingen adgang']);
      exit('Ingen adgang');
    }
    $colId = (int)($_POST['column_id'] ?? 0);
    $employeeId = trim((string)($_POST['employee_discord_id'] ?? ''));
    $checked = (int)($_POST['is_checked'] ?? 0) === 1 ? 1 : 0;
    if ($colId > 0 && $employeeId !== '') {
      $stmt = $db->prepare("INSERT INTO department_training_checks(training_column_id, employee_discord_id, is_checked) VALUES(?,?,?)
        ON CONFLICT(training_column_id, employee_discord_id) DO UPDATE SET is_checked=excluded.is_checked");
      $stmt->execute([$colId, $employeeId, $checked]);
    }
    $json(['ok' => true, 'checked' => $checked]);
    redirect('department_overview.php?dept=' . urlencode($postDept));
  }

  if ($action === 'save_member_meta' && $postDept !== '') {
    if (!$isLeader) {
      http_response_code(403);
      $json(['ok' => false, 'message' => 'Ingen adgang']);
      exit('Ingen adgang');
    }
    $employeeId = trim((string)($_POST['employee_discord_id'] ?? ''));
    $title = trim((string)($_POST['responsibility_title'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $isTrainer = (int)($_POST['is_trainer'] ?? 0) === 1 ? 1 : 0;
    if ($employeeId !== '') {
      $stmt = $db->prepare("INSERT INTO department_member_profiles(employee_discord_id, department_name, responsibility_title, note, is_trainer)
        VALUES(?,?,?,?,?)
        ON CONFLICT(employee_discord_id, department_name) DO UPDATE SET
          responsibility_title=excluded.responsibility_title,
          note=excluded.note,
          is_trainer=excluded.is_trainer");
      $stmt->execute([$employeeId, $postDept, ($title !== '' ? $title : null), ($note !== '' ? $note : null), $isTrainer]);
    }
    $json(['ok' => true]);
    redirect('department_overview.php?dept=' . urlencode($postDept));
  }

  if ($action === 'upload_member_document' && $postDept !== '') {
    if (!$isLeader) { http_response_code(403); exit('Ingen adgang'); }
    $employeeId = trim((string)($_POST['employee_discord_id'] ?? ''));
    if ($employeeId !== '' && isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
      $dir = __DIR__ . '/uploads/department_documents';
      if (!is_dir($dir)) mkdir($dir, 0777, true);
      $origName = basename((string)($_FILES['attachment']['name'] ?? 'bilag'));
      $safeName = preg_replace('/[^0-9A-Za-z._-]/', '_', $origName);
      $fileName = preg_replace('/[^0-9A-Za-z_-]/', '', $employeeId) . '_' . time() . '_' . $safeName;
      $abs = $dir . '/' . $fileName;
      if (move_uploaded_file($_FILES['attachment']['tmp_name'], $abs)) {
        $rel = 'uploads/department_documents/' . $fileName;
        $stmt = $db->prepare("INSERT INTO department_member_documents(employee_discord_id, department_name, file_path, original_name) VALUES(?,?,?,?)");
        $stmt->execute([$employeeId, $postDept, $rel, $origName]);
      }
    }
    redirect('department_overview.php?dept=' . urlencode($postDept));
  }

  if ($action === 'delete_document' && $postDept !== '') {
    if (!$isLeader) { http_response_code(403); exit('Ingen adgang'); }
    $docId = (int)($_POST['document_id'] ?? 0);
    if ($docId > 0) {
      $stmt = $db->prepare("SELECT file_path FROM department_member_documents WHERE id=?");
      $stmt->execute([$docId]);
      $path = (string)$stmt->fetchColumn();
      if ($path !== '') {
        $abs = __DIR__ . '/' . ltrim($path, '/');
        if (is_file($abs)) { try { unlink($abs); } catch (Throwable $_) {} }
      }
      $db->prepare("DELETE FROM department_member_documents WHERE id=?")->execute([$docId]);
    }
    redirect('department_overview.php?dept=' . urlencode($postDept));
  }
}

$employees = [];
if ($selectedDept !== '') {
  $stmt = $db->prepare("SELECT e.discord_id, e.name, e.rank, e.badge_number
    FROM employees e
    LEFT JOIN ranks r ON r.name=e.rank
    WHERE e.department=? OR e.secondary_department=?
    ORDER BY COALESCE(r.sort_order,9999) ASC, e.name ASC");
  $stmt->execute([$selectedDept, $selectedDept]);
  $employees = $stmt->fetchAll();
}

$columns = [];
if ($selectedDept !== '') {
  $stmt = $db->prepare("SELECT id, column_name FROM department_training_columns WHERE department_name=? ORDER BY id ASC");
  $stmt->execute([$selectedDept]);
  $columns = $stmt->fetchAll();
}

$profiles = [];
if ($selectedDept !== '') {
  $stmt = $db->prepare("SELECT employee_discord_id, responsibility_title, note, COALESCE(is_trainer,0) AS is_trainer FROM department_member_profiles WHERE department_name=?");
  $stmt->execute([$selectedDept]);
  foreach ($stmt->fetchAll() as $r) $profiles[$r['employee_discord_id']] = $r;
}

$docs = [];
if ($selectedDept !== '') {
  $stmt = $db->prepare("SELECT * FROM department_member_documents WHERE department_name=? ORDER BY created_at DESC");
  $stmt->execute([$selectedDept]);
  foreach ($stmt->fetchAll() as $r) $docs[$r['employee_discord_id']][] = $r;
}

$checks = [];
if ($columns) {
  $colIds = array_map(fn($c)=> (int)$c['id'], $columns);
  $ph = implode(',', array_fill(0, count($colIds), '?'));
  $stmt = $db->prepare("SELECT training_column_id, employee_discord_id, is_checked FROM department_training_checks WHERE training_column_id IN ($ph)");
  $stmt->execute($colIds);
  foreach ($stmt->fetchAll() as $r) $checks[(int)$r['training_column_id'] . '|' . (string)$r['employee_discord_id']] = (int)$r['is_checked'] === 1;
}

include __DIR__ . '/_layout.php';
?>
<div class="leader-container">
  <div class="pagehead">
    <h1>Afdelingsoversigt</h1>
    <div class="muted">Fuldbredde tabel pr. afdeling med træninger, bilag og noter</div>
  </div>

  <div class="card" style="width:100%;max-width:none;">
    <form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
      <div style="min-width:260px;">
        <label>Vælg afdeling</label>
        <select name="dept" onchange="this.form.submit()">
          <?php foreach ($deptRows as $d): ?>
            <option value="<?= h($d['name']) ?>" <?= $selectedDept===$d['name']?'selected':'' ?>><?= h($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="min-width:240px;">
        <label>Søg medarbejder</label>
        <input id="employeeSearch" type="text" placeholder="Søg navn, rang eller badge...">
      </div>
      <noscript><button type="submit">Åbn</button></noscript>
    </form>
  </div>

  <?php if ($isLeader): ?>
  <div class="card" style="width:100%;max-width:none;">
    <h2 style="margin-top:0;">Træningskolonner for <?= h($selectedDept) ?></h2>
    <form method="post" style="display:flex;gap:10px;align-items:end;max-width:620px;">
      <input type="hidden" name="action" value="add_training_column">
      <input type="hidden" name="department" value="<?= h($selectedDept) ?>">
      <div style="flex:1;">
        <label>Ny kolonne (fx NDM)</label>
        <input name="column_name" required>
      </div>
      <button class="btn btn-solid" type="submit">Tilføj</button>
    </form>
    <?php if ($columns): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
      <?php foreach ($columns as $c): ?>
        <form method="post" style="margin:0;" onsubmit="return confirm('Fjern kolonne <?= h($c['column_name']) ?>?');">
          <input type="hidden" name="action" value="delete_training_column">
          <input type="hidden" name="department" value="<?= h($selectedDept) ?>">
          <input type="hidden" name="column_id" value="<?= (int)$c['id'] ?>">
          <button class="btn" type="submit">Fjern <?= h($c['column_name']) ?></button>
        </form>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card" style="width:100%;max-width:none;overflow:auto;">
    <table class="table" style="min-width:1500px;font-size:13px;">
      <thead>
        <tr>
          <th>Navn</th><th>Rang</th><th>P-nr</th><th>Ansvarsområde</th><th>Noter</th><th>Træner</th><th>Bilag</th>
          <?php foreach ($columns as $c): ?><th><?= h($c['column_name']) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $e): $eid = (string)$e['discord_id']; $profile = $profiles[$eid] ?? []; ?>
          <tr class="employee-row" data-search="<?= h(strtolower(($e['name'] ?? '') . ' ' . ($e['rank'] ?? '') . ' ' . ($e['badge_number'] ?? ''))) ?>">
            <td><?= h($e['name']) ?></td>
            <td><?= h($e['rank']) ?></td>
            <td><?= h($e['badge_number']) ?></td>
            <td>
              <input class="meta-field" data-field="responsibility_title" data-employee-id="<?= h($eid) ?>" value="<?= h($profile['responsibility_title'] ?? '') ?>" placeholder="Titel/ansvar" <?= $isLeader ? '' : 'disabled' ?>>
            </td>
            <td>
              <input class="meta-field" data-field="note" data-employee-id="<?= h($eid) ?>" value="<?= h($profile['note'] ?? '') ?>" placeholder="Noter" <?= $isLeader ? '' : 'disabled' ?>>
            </td>
            <td>
              <input class="trainer-toggle" style="width:auto;" type="checkbox" data-employee-id="<?= h($eid) ?>" <?= !empty($profile['is_trainer']) ? 'checked' : '' ?> <?= $isLeader ? '' : 'disabled' ?>>
            </td>
            <td>
              <button class="btn" type="button" onclick="openBilagModal('<?= h($eid) ?>')">Bilag</button>
              <div id="bilag-data-<?= h($eid) ?>" style="display:none;">
                <?php if ($isLeader): ?>
                <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                  <input type="hidden" name="action" value="upload_member_document">
                  <input type="hidden" name="department" value="<?= h($selectedDept) ?>">
                  <input type="hidden" name="employee_discord_id" value="<?= h($eid) ?>">
                  <input type="file" name="attachment" required>
                  <button class="btn" type="submit">Upload</button>
                </form>
                <?php endif; ?>
                <?php foreach (($docs[$eid] ?? []) as $doc): ?>
                  <div style="display:flex;gap:6px;align-items:center;margin-top:6px;flex-wrap:wrap;">
                    <a href="download_file.php?path=<?= urlencode($doc['file_path']) ?>&filename=<?= urlencode($doc['original_name'] ?: basename($doc['file_path'])) ?>" download><?= h($doc['original_name'] ?: basename($doc['file_path'])) ?></a>
                    <?php if ($isLeader): ?>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Slet bilag?');">
                      <input type="hidden" name="action" value="delete_document">
                      <input type="hidden" name="department" value="<?= h($selectedDept) ?>">
                      <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                      <button class="btn" type="submit">Slet</button>
                    </form>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($docs[$eid])): ?><div class="muted" style="margin-top:8px;">Ingen bilag.</div><?php endif; ?>
              </div>
            </td>
            <?php foreach ($columns as $c):
              $key = (int)$c['id'] . '|' . $eid;
              $isChecked = !empty($checks[$key]);
            ?>
              <td>
                <button type="button" class="btn training-toggle <?= $isChecked ? 'btn-solid' : '' ?>" data-column-id="<?= (int)$c['id'] ?>" data-employee-id="<?= h($eid) ?>" data-checked="<?= $isChecked ? '1' : '0' ?>" <?= $canToggleChecks ? '' : 'disabled' ?>><?= $isChecked ? '✓' : '—' ?></button>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?><tr><td colspan="99">Ingen medarbejdere i valgt afdeling.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="bilagModal" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;">
  <div style="max-width:760px;margin:7vh auto;background:#fff;padding:18px;border-radius:8px;max-height:85vh;overflow:auto;position:relative;">
    <button type="button" class="btn" onclick="closeBilagModal()" style="position:absolute;right:12px;top:12px;">Luk</button>
    <div id="bilagModalContent"></div>
  </div>
</div>

<script>
const departmentName = <?= json_encode($selectedDept) ?>;

function saveMeta(employeeId) {
  const row = document.querySelector('.employee-row [data-employee-id="' + CSS.escape(employeeId) + '"]')?.closest('tr');
  if (!row) return;
  const titleInput = row.querySelector('.meta-field[data-field="responsibility_title"]');
  const noteInput = row.querySelector('.meta-field[data-field="note"]');
  const trainerInput = row.querySelector('.trainer-toggle');
  const formData = new FormData();
  formData.append('ajax', '1');
  formData.append('action', 'save_member_meta');
  formData.append('department', departmentName);
  formData.append('employee_discord_id', employeeId);
  formData.append('responsibility_title', titleInput ? titleInput.value : '');
  formData.append('note', noteInput ? noteInput.value : '');
  formData.append('is_trainer', trainerInput && trainerInput.checked ? '1' : '0');
  fetch('department_overview.php', { method: 'POST', body: formData, headers: {'X-Requested-With':'XMLHttpRequest'} });
}

const metaDebounce = {};
document.querySelectorAll('.meta-field').forEach(function(input){
  input.addEventListener('input', function(){
    const id = input.dataset.employeeId;
    clearTimeout(metaDebounce[id]);
    metaDebounce[id] = setTimeout(function(){ saveMeta(id); }, 450);
  });
});
document.querySelectorAll('.trainer-toggle').forEach(function(input){
  input.addEventListener('change', function(){
    saveMeta(input.dataset.employeeId);
  });
});

document.querySelectorAll('.training-toggle').forEach(function(btn){
  btn.addEventListener('click', function(){
    const employeeId = btn.dataset.employeeId;
    const columnId = btn.dataset.columnId;
    const next = btn.dataset.checked === '1' ? '0' : '1';
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('action', 'toggle_training_check');
    fd.append('department', departmentName);
    fd.append('employee_discord_id', employeeId);
    fd.append('column_id', columnId);
    fd.append('is_checked', next);
    fetch('department_overview.php', { method: 'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
      .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
      .then(function(data){
        const checked = String(data.checked) === '1';
        btn.dataset.checked = checked ? '1' : '0';
        btn.textContent = checked ? '✓' : '—';
        btn.classList.toggle('btn-solid', checked);
      })
      .catch(function(){});
  });
});

const searchInput = document.getElementById('employeeSearch');
searchInput?.addEventListener('input', function(){
  const q = searchInput.value.toLowerCase().trim();
  document.querySelectorAll('.employee-row').forEach(function(row){
    const txt = row.dataset.search || '';
    row.style.display = (!q || txt.indexOf(q) !== -1) ? '' : 'none';
  });
});

function openBilagModal(employeeId) {
  const src = document.getElementById('bilag-data-' + employeeId);
  const modal = document.getElementById('bilagModal');
  const content = document.getElementById('bilagModalContent');
  if (!src || !modal || !content) return;
  content.innerHTML = src.innerHTML;
  modal.style.display = 'block';
}
function closeBilagModal() {
  const modal = document.getElementById('bilagModal');
  if (modal) modal.style.display = 'none';
}
window.addEventListener('click', function(e){
  const modal = document.getElementById('bilagModal');
  if (e.target === modal) closeBilagModal();
});
</script>
</body></html>