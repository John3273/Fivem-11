<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

$db = db();
$u = current_user();
$isLeader = $u && in_array('LEADER', $u['roles'] ?? [], true);

$db->exec("CREATE TABLE IF NOT EXISTS guidelines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  section_number TEXT NOT NULL,
  category TEXT NOT NULL,
  guideline_text TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
)");

$count = (int)$db->query("SELECT COUNT(*) FROM guidelines")->fetchColumn();
if ($count === 0) {
  $seed = [
    ['§ 1.0', 'Adfærd', 'Vis respekt for alle spillere, også under konflikter.'],
    ['§ 2.0', 'RP Kvalitet', 'Hold karakter og undgå OOC i aktive scenarier.'],
    ['§ 3.0', 'Kommunikation', 'Brug klart og professionelt radiosprog.'],
  ];
  $ins = $db->prepare("INSERT INTO guidelines(section_number, category, guideline_text) VALUES(?,?,?)");
  foreach ($seed as $row) $ins->execute($row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLeader) {
  $action = $_POST['action'] ?? '';

  if ($action === 'add_guideline') {
    $section = trim($_POST['section_number'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $text = trim($_POST['guideline_text'] ?? '');

    if ($section !== '' && $category !== '' && $text !== '') {
      $stmt = $db->prepare("INSERT INTO guidelines(section_number, category, guideline_text) VALUES(?,?,?)");
      $stmt->execute([$section, $category, $text]);
    }
    redirect('retningslinjer.php');
  }

  if ($action === 'delete_guideline') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM guidelines WHERE id=?");
      $stmt->execute([$id]);
    }
    redirect('retningslinjer.php');
  }
}

$guidelines = $db->query("SELECT * FROM guidelines ORDER BY id ASC")->fetchAll();

include __DIR__ . '/_layout.php';
?>

<div class="container">
  <div class="card">
    <h2>Retningslinjer</h2>
    <p class="muted">Søg hurtigt i politiets gældende retningslinjer.</p>

    <?php if ($isLeader): ?>
      <details style="margin:12px 0 16px;">
        <summary class="btn btn-solid">+ Tilføj retningslinje</summary>
        <form method="post" style="margin-top:12px;">
          <input type="hidden" name="action" value="add_guideline">
          <div class="row2">
            <div>
              <label>Nr. (fx § 1.0)</label>
              <input name="section_number" required placeholder="§ 1.0">
            </div>
            <div>
              <label>Kategori</label>
              <input name="category" required placeholder="Adfærd">
            </div>
          </div>
          <label>Retningslinje</label>
          <textarea name="guideline_text" rows="3" required></textarea>
          <div style="margin-top:10px;"><button type="submit">Gem</button></div>
        </form>
      </details>
    <?php endif; ?>

    <input id="guidelineSearch" type="text" placeholder="Søg i nr., kategori eller retningslinje..." style="margin:10px 0 14px;">

    <div style="overflow:auto;">
      <table class="table" id="guidelineTable">
        <thead>
          <tr>
            <th>Nr.</th>
            <th>Kategori</th>
            <th>Retningslinje</th>
            <?php if ($isLeader): ?><th>Handling</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($guidelines as $g): ?>
            <tr>
              <td><?= h($g['section_number']) ?></td>
              <td><?= h($g['category']) ?></td>
              <td><?= h($g['guideline_text']) ?></td>
              <?php if ($isLeader): ?>
                <td>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="delete_guideline">
                    <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                    <button class="btn" type="submit" onclick="return confirm('Fjern denne retningslinje?');">Fjern</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$guidelines): ?><tr><td colspan="<?= $isLeader ? '4' : '3' ?>">Ingen retningslinjer endnu.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
  const input = document.getElementById('guidelineSearch');
  const rows = Array.from(document.querySelectorAll('#guidelineTable tbody tr'));
  input?.addEventListener('input', function(){
    const q = this.value.trim().toLowerCase();
    rows.forEach(row => {
      const txt = row.innerText.toLowerCase();
      row.style.display = txt.includes(q) ? '' : 'none';
    });
  });
})();
</script>
</body></html>
