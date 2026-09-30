<?php
require __DIR__ . '/../inc/bootstrap.php';
$PAGE_ROOT = '../';
if (!is_admin()) redirect('index.php');
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$back = (string)($_GET['back'] ?? $_POST['back'] ?? 'index.php');
if (!preg_match('/^(index\.php|cert_export\.php)(\?[\w=&%.-]*)?$/', $back)) $back = 'index.php';   // กลับได้เฉพาะหน้าในระบบ

$s = $pdo->prepare("SELECT * FROM participants WHERE id=?");
$s->execute([$id]);
$p = $s->fetch();
if (!$p) redirect($back);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['full_name'] ?? '')));
    $agency = trim((string)($_POST['agency'] ?? ''));
    if (mb_strlen($name) < 3) {
        $error = 'กรุณากรอกชื่อ-สกุลให้ถูกต้อง';
    } else {
        $key = name_key($name);
        $dup = $pdo->prepare("SELECT full_name FROM participants WHERE name_key=? AND id<>? LIMIT 1");
        $dup->execute([$key, $id]);
        $other = $dup->fetchColumn();
        if ($other !== false && empty($_POST['confirm_dup'])) {
            $error = 'มีผู้เข้าร่วมอีกคนใช้ชื่อ “' . $other . '” อยู่แล้ว — ถ้าเป็นคนละคนกัน ให้ติ๊ก “ยืนยันบันทึก” แล้วกดบันทึกอีกครั้ง';
        } else {
            $pdo->prepare("UPDATE participants SET full_name=?, agency=?, name_key=? WHERE id=?")
                ->execute([$name, mb_substr($agency, 0, 200), $key, $id]);
            $_SESSION['flash'] = 'แก้ไขชื่อเป็น “' . $name . '” แล้ว — เกียรติบัตรจะใช้ชื่อใหม่ทันที';
            redirect($back);
        }
    }
}

$att = $pdo->prepare("SELECT a.*, t.code, t.title FROM attempts a JOIN tests t ON t.id=a.test_id WHERE a.participant_id=? ORDER BY FIELD(t.code,'pre','post')");
$att->execute([$id]);
$att = $att->fetchAll();
$postAttempt = null;
foreach ($att as $x) if ($x['code'] === 'post') $postAttempt = $x;

page_header('แก้ไขข้อมูลผู้เข้าร่วม', admin_nav('dash'));
?>
<div class="adminbar">
  <h1>แก้ไขข้อมูลผู้เข้าร่วม</h1>
  <div class="actions"><a class="btn" href="<?= h($back) ?>">← กลับ</a></div>
</div>
<?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>

<form method="post" class="card form" style="max-width:640px">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
  <p class="muted" style="margin-top:0">ชื่อเดิมที่ผู้เข้าร่วมกรอก: <b><?= h($p['full_name']) ?></b></p>
  <label>ชื่อ-สกุล (แสดงบนเกียรติบัตร)
    <input name="full_name" required maxlength="200" value="<?= h($_POST['full_name'] ?? $p['full_name']) ?>" autofocus></label>
  <label>หน่วยงาน
    <input name="agency" maxlength="200" value="<?= h($_POST['agency'] ?? $p['agency']) ?>"></label>
  <?php if ($error && strpos($error, 'ยืนยันบันทึก') !== false): ?>
    <label class="tiny"><input type="checkbox" name="confirm_dup" value="1"> ยืนยันบันทึก (เป็นคนละคนกัน)</label>
  <?php endif; ?>
  <p class="muted"><small>ผลคะแนนและแบบประเมินยังผูกกับผู้เข้าร่วมคนเดิม · หากผู้เข้าร่วมยังไม่ได้ทำ Posttest ต้องกรอกชื่อตามที่แก้ไขนี้เพื่อให้ระบบจับคู่กับ Pretest</small></p>
  <div class="toggles">
    <button class="btn primary">บันทึก</button>
    <?php if ($postAttempt && cert_passed($postAttempt)): ?><?= cert_view_button($p['full_name'], $postAttempt['submitted_at'], 'ดูเกียรติบัตร', 'btn') ?><?php endif; ?>
  </div>
</form>

<div class="card" style="max-width:640px">
  <div class="label">ผลการทดสอบ</div>
  <table class="tbl">
    <?php foreach ($att as $x): ?>
      <tr><td><?= h($x['title']) ?></td><td><?= (int)$x['score'] ?>/<?= (int)$x['total'] ?></td><td><?= h(thai_date($x['submitted_at'])) ?></td></tr>
    <?php endforeach; if (!$att): ?><tr><td class="muted">ยังไม่มีผลการทดสอบ</td></tr><?php endif; ?>
  </table>
</div>
<?= cert_modal_assets() ?>
<?php page_footer();
