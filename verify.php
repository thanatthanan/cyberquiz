<?php
require __DIR__ . '/inc/bootstrap.php';

$pdo = db();
$code = strtoupper(trim((string)($_GET['c'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));
$rec = null;
$recProj = null;
$valid = false;
$foundCerts = null;
$lookupErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open_cert') {
    csrf_check();
    $aid = (int)($_POST['aid'] ?? 0);
    $allowed = $_SESSION['cert_lookup_allowed'] ?? [];
    if ($aid > 0 && in_array($aid, $allowed, true)) {
        $_SESSION['my_certs'][] = $aid;
        redirect('cert.php?id=' . $aid);
    }
    $lookupErr = 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณาค้นหาใหม่อีกครั้ง';
}

if ($code !== '') {
    $s = $pdo->prepare("SELECT a.*, t.code, t.title, t.project_id, p.full_name, p.agency
                        FROM attempts a
                        JOIN tests t ON t.id = a.test_id
                        JOIN participants p ON p.id = a.participant_id
                        WHERE a.cert_code = ? AND t.code = 'post'
                        LIMIT 1");
    $s->execute([$code]);
    $rec = $s->fetch() ?: null;
    if ($rec) {
        $recProj = project_by_id((int)$rec['project_id']);
        $valid = cert_passed($rec, $recProj);
    }
}

if ($q !== '') {
    $qNorm = strtr($q, ['๐'=>'0','๑'=>'1','๒'=>'2','๓'=>'3','๔'=>'4','๕'=>'5','๖'=>'6','๗'=>'7','๘'=>'8','๙'=>'9']);
    if (mb_strlen($q) < 2) {
        $lookupErr = 'กรุณากรอกรหัสประจำตัว 6 หลัก หรือชื่อ-สกุล อย่างน้อย 2 ตัวอักษร';
    } else {
        $qCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $qNorm));
        $nk = name_key($qNorm);
        $sql = "SELECT a.*, t.code, t.title, t.project_id, p.full_name, p.agency, p.ref_code,
                       pr.course_title, pr.org_name, pr.cert_settings, pr.post_max_attempts
                FROM attempts a
                JOIN tests t ON t.id = a.test_id
                JOIN participants p ON p.id = a.participant_id
                JOIN projects pr ON pr.id = t.project_id
                WHERE t.code = 'post' AND (";
        $params = [];
        if ($qCode !== '' && strlen($qCode) >= 4) {
            $sql .= "p.ref_code = ? OR p.ref_code LIKE ? OR ";
            $params[] = $qCode;
            $params[] = '%' . $qCode . '%';
        }
        $sql .= "p.name_key LIKE ? OR p.full_name LIKE ?)
                ORDER BY a.submitted_at DESC, a.id DESC LIMIT 30";
        $params[] = '%' . $nk . '%';
        $params[] = '%' . $q . '%';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $foundCerts = [];
        $allowedIds = [];
        foreach ($st->fetchAll() as $r) {
            $pRow = [
                'id' => (int)$r['project_id'],
                'course_title' => $r['course_title'],
                'org_name' => $r['org_name'],
                'cert_settings' => $r['cert_settings'],
                'post_max_attempts' => $r['post_max_attempts'],
            ];
            if (!cert_passed($r, $pRow)) continue;
            $r['cert_code'] = cert_code_for($pdo, (int)$r['id']);
            $foundCerts[] = $r;
            $allowedIds[] = (int)$r['id'];
        }
        $_SESSION['cert_lookup_allowed'] = $allowedIds;
    }
}

page_header('ค้นหาและตรวจสอบเกียรติบัตร');
?>
<div class="card hero center">
  <h1>ค้นหาและตรวจสอบเกียรติบัตร</h1>
  <p class="muted">ตรวจสอบความถูกต้องด้วยเลขที่เกียรติบัตร หรือค้นหาเกียรติบัตรเดิมของคุณจากทุกโครงการที่ผ่านมา</p>
</div>

<?php if ($lookupErr): ?><div class="alert" style="max-width:720px;margin:0 auto 16px"><?= h($lookupErr) ?></div><?php endif; ?>

<div class="grid two" style="margin-bottom:16px">
  <form method="get" class="card form">
    <h2 style="margin:0 0 6px;font-size:18px;color:var(--navy)">1. ค้นหาเกียรติบัตรเดิมของคุณ</h2>
    <p class="muted" style="margin:0 0 8px"><small>สำหรับผู้เข้าอบรมเดิมที่ต้องการเปิดดูหรือดาวน์โหลดเกียรติบัตรย้อนหลัง</small></p>
    <label>ชื่อ-สกุล หรือ รหัสประจำตัว 6 หลัก
      <input name="q" value="<?= h($q) ?>" placeholder="เช่น สมชาย ใจดี หรือ 482910" required>
    </label>
    <button class="btn primary" type="submit">ค้นหาเกียรติบัตรของฉัน</button>
  </form>

  <form method="get" class="card form">
    <h2 style="margin:0 0 6px;font-size:18px;color:var(--navy)">2. ตรวจสอบความถูกต้องด้วยเลขที่</h2>
    <p class="muted" style="margin:0 0 8px"><small>กรอกเลขที่เกียรติบัตร หรือสแกน QR Code บนเกียรติบัตร</small></p>
    <label>เลขที่เกียรติบัตร
      <input name="c" value="<?= h($code) ?>" placeholder="เช่น CQ-69-A1B2C3" required style="text-transform:uppercase">
    </label>
    <button class="btn" type="submit">ตรวจสอบ</button>
  </form>
</div>

<?php if ($foundCerts !== null): ?>
  <?php if ($foundCerts): ?>
    <div class="card" style="margin-bottom:16px">
      <h2 style="margin:0 0 12px;font-size:19px;color:var(--navy)">พบเกียรติบัตรที่ผ่านเกณฑ์ <?= count($foundCerts) ?> รายการ</h2>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($foundCerts as $r): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:14px 16px;border:1px solid var(--line);border-radius:10px;background:#f8fafc;flex-wrap:wrap">
            <div style="flex:1 1 260px;min-width:0">
              <div style="font-size:13px;font-weight:700;color:var(--navy2)"><?= h($r['course_title']) ?></div>
              <div style="font-size:18px;font-weight:700;color:var(--navy);margin:2px 0"><?= h($r['full_name']) ?></div>
              <div class="muted" style="font-size:14px">
                <?= h($r['agency']) ?>
                · วันที่ <?= h(thai_date($r['submitted_at'])) ?>
                · คะแนน <?= (int)$r['score'] ?>/<?= (int)$r['total'] ?>
                · เลขที่ <b><?= h($r['cert_code']) ?></b>
              </div>
            </div>
            <form method="post" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="open_cert">
              <input type="hidden" name="aid" value="<?= (int)$r['id'] ?>">
              <button class="btn primary" type="submit">เปิดดู / ดาวน์โหลด (PDF)</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="alert" style="margin-bottom:16px">
      <b>ไม่พบเกียรติบัตรที่ผ่านเกณฑ์สำหรับ “<?= h($q) ?>”</b><br>
      <small>ตรวจสอบการสะกดชื่อ-สกุล หรือรหัสประจำตัว 6 หลักอีกครั้ง</small>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($code !== ''): ?>
  <?php if ($valid): ?>
    <div class="card" style="max-width:640px;margin:0 auto;border-top:4px solid var(--ok)">
      <div class="up" style="font-size:20px;font-weight:700;margin-bottom:10px">✓ เกียรติบัตรฉบับนี้ถูกต้องและมีอยู่จริงในระบบ</div>
      <table class="tbl">
        <tr><th style="width:36%">เลขที่เกียรติบัตร</th><td><b><?= h($rec['cert_code']) ?></b></td></tr>
        <tr><th>ชื่อ-สกุลผู้รับ</th><td><b><?= h($rec['full_name']) ?></b></td></tr>
        <tr><th>หน่วยงาน</th><td><?= h($rec['agency']) ?></td></tr>
        <tr><th>หลักสูตร</th><td><?= h(course_title($recProj)) ?></td></tr>
        <tr><th>หน่วยงานผู้จัด</th><td><?= h(org_name($recProj)) ?></td></tr>
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

<div class="center" style="margin-top:16px">
  <a class="btn ghost" href="index.php">← กลับหน้าหลัก</a>
</div>
<?php page_footer();
