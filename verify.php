<?php
require __DIR__ . '/inc/bootstrap.php';

$code = strtoupper(trim((string)($_GET['c'] ?? '')));
$pdo = db();
$rec = null;
$valid = false;

if ($code !== '') {
    $s = $pdo->prepare("SELECT a.*, t.code, t.title, p.full_name, p.agency
                        FROM attempts a
                        JOIN tests t ON t.id = a.test_id
                        JOIN participants p ON p.id = a.participant_id
                        WHERE a.cert_code = ? AND t.code = 'post'
                        LIMIT 1");
    $s->execute([$code]);
    $rec = $s->fetch() ?: null;
    $valid = $rec && cert_passed($rec);
}

page_header('ตรวจสอบเกียรติบัตร');
?>
<div class="card hero center">
  <h1>ตรวจสอบความถูกต้องของเกียรติบัตร</h1>
  <p class="muted">กรอกเลขที่เกียรติบัตร หรือสแกน QR Code บนเกียรติบัตรเพื่อตรวจสอบข้อมูล</p>
</div>

<form method="get" class="card form" style="max-width:540px;margin:0 auto 16px">
  <label>เลขที่เกียรติบัตร
    <input name="c" value="<?= h($code) ?>" placeholder="เช่น CQ-69-A1B2C3" required style="text-transform:uppercase">
  </label>
  <button class="btn primary" type="submit">ตรวจสอบ</button>
</form>

<?php if ($code !== ''): ?>
  <?php if ($valid): ?>
    <div class="card" style="max-width:640px;margin:0 auto;border-top:4px solid var(--ok)">
      <div class="up" style="font-size:20px;font-weight:700;margin-bottom:10px">✓ เกียรติบัตรฉบับนี้ถูกต้องและมีอยู่จริงในระบบ</div>
      <table class="tbl">
        <tr><th style="width:36%">เลขที่เกียรติบัตร</th><td><b><?= h($rec['cert_code']) ?></b></td></tr>
        <tr><th>ชื่อ-สกุลผู้รับ</th><td><b><?= h($rec['full_name']) ?></b></td></tr>
        <tr><th>หน่วยงาน</th><td><?= h($rec['agency']) ?></td></tr>
        <tr><th>หลักสูตร</th><td><?= h(course_title()) ?></td></tr>
        <tr><th>หน่วยงานผู้จัด</th><td><?= h(org_name()) ?></td></tr>
        <tr><th>วันที่ออกเกียรติบัตร</th><td><?= h(thai_date($rec['submitted_at'])) ?></td></tr>
        <tr><th>ผลการทดสอบ</th><td><?= (int)$rec['score'] ?> / <?= (int)$rec['total'] ?> คะแนน (ผ่านเกณฑ์)</td></tr>
      </table>
    </div>
  <?php else: ?>
    <div class="alert" style="max-width:640px;margin:0 auto">
      <b>✗ ไม่พบข้อมูลเกียรติบัตรเลขที่ “<?= h($code) ?>”</b><br>
      <small>กรุณาตรวจสอบเลขที่เกียรติบัตรอีกครั้ง หรือติดต่อผู้ดูแลระบบ</small>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php page_footer();
