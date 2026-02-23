<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions.php';

$db = db();
$u  = current_user();

$deptId = (int)($_GET['dept_id'] ?? 0);
if ($deptId <= 0) redirect('index.php');

$stmt = $db->prepare("SELECT id,name FROM departments WHERE id=?");
$stmt->execute([$deptId]);
$dept = $stmt->fetch();
if (!$dept) redirect('index.php');

$stmt = $db->prepare("SELECT title, body, updated_at FROM department_about WHERE department_id=?");
$stmt->execute([$deptId]);
$about = $stmt->fetch();

include __DIR__ . '/_layout.php';
?>

<div class="container">
  <div class="grid grid-3">

    <!-- LEFT: Sidebar -->
    <div class="sidebar">
      <div class="side-title">AFDELINGER</div>
      <?php
        $deptRows = $db->query("SELECT id,name FROM departments ORDER BY sort_order, name")->fetchAll();
        foreach ($deptRows as $d):
          $active = ((int)$d['id'] === (int)$deptId) ? 'active' : '';
      ?>
        <a class="<?= $active ?>" href="about.php?dept_id=<?= (int)$d['id'] ?>">
          <?= h($d['name']) ?>
          <span class="pill">→</span>
        </a>
      <?php endforeach; ?>
      <a href="index.php">Tilbage til borger side <span class="pill">⟵</span></a>
    </div>

    <!-- MID: About -->
    <div class="card">
      <h2><?= h($dept['name']) ?></h2>
      <div class="muted" style="margin-bottom:10px">
        <?= $about ? 'Opdateret: ' . h($about['updated_at']) : 'Ingen beskrivelse endnu.' ?>
      </div>

      <?php if ($about && trim((string)$about['body']) !== ''): ?>
        <div class="post" style="margin-top:0">
          <div class="body">
            <?php if (!empty($about['title'])): ?>
              <h3><?= h($about['title']) ?></h3>
            <?php endif; ?>
            <p class="muted"><?= nl2br(h($about['body'])) ?></p>
          </div>
        </div>
      <?php else: ?>
        <div class="emptybox">
          <div class="emptyicon">ℹ️</div>
          <div>Der er endnu ikke skrevet en About-side for denne afdeling.</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: Info -->
    <div class="stack">
      <div class="card info-card">
        <h3>Los Santos Politidistrikt</h3>
        <p class="muted" style="margin:0">
          Afdelingssiderne styres fra administrationen.<br>
          <strong>Akutte situationer — ring 911.</strong>
        </p>
      </div>
    </div>

  </div>
</div>

<footer class="footer">
  © 2026 Redline Politidistrikt — FiveM Roleplay Server
</footer>

</body></html>