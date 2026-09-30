<?php
/*
 * หน้าเข้าสู่แบบทดสอบ (ใช้ร่วมกันโดย pretest.php และ posttest.php)
 * ต้องกำหนด $CODE = 'pre' หรือ 'post' ก่อน require ไฟล์นี้
 */
require __DIR__ . '/bootstrap.php';

$test = test_by_code($CODE);
if (!$test) fatal('ไม่พบแบบทดสอบนี้');
$isPost = ($CODE === 'post');
$requirePre = $isPost && (cfg('require_pretest') ?? true);
$preTest = $requirePre ? test_by_code('pre') : null;

$me = current_participant();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name   = trim((string)($_POST['full_name'] ?? ''));
    $agency = trim((string)($_POST['agency'] ?? ''));

    if (!$test['is_open'])          $error = 'แบบทดสอบนี้ยังไม่เปิดให้ทำ';
    elseif (mb_strlen($name) < 3)   $error = 'กรุณากรอกชื่อ-สกุล';
    elseif ($agency === '')         $error = 'กรุณากรอกหน่วยงาน';

    $pdo = db();
    $key = name_key($name);
    $p = null;
    if (!$error) {
        // จับคู่ผู้เข้าร่วมเดิม: เครื่องเดิม+ชื่อตรงกัน หรือชื่อเดียวกันในระบบ
        $p = ($me && $me['name_key'] === $key) ? $me : null;
        if (!$p) {
            $s = $pdo->prepare("SELECT * FROM participants WHERE name_key=? ORDER BY id LIMIT 1");
            $s->execute([$key]);
            $p = $s->fetch() ?: null;
        }
    }

    // ป้องกันการทำ Posttest ก่อน Pretest
    if (!$error && $requirePre && $preTest && !($p && attempt_of((int)$p['id'], (int)$preTest['id']))) {
        $error = 'ไม่พบผลแบบทดสอบก่อนเรียนของ “' . $name . '” — กรุณาทำแบบทดสอบก่อนเรียน (Pretest) ให้เสร็จก่อน '
               . 'หรือตรวจสอบว่ากรอกชื่อ-สกุลเหมือนตอนทำ Pretest';
    }

    if (!$error) {
        if ($p) {
            $pdo->prepare("UPDATE participants SET full_name=?, agency=? WHERE id=?")->execute([$name, $agency, $p['id']]);
        } else {
            $tok = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO participants (token,full_name,agency,name_key,created_at) VALUES (?,?,?,?,NOW())")
                ->execute([$tok, $name, $agency, $key]);
            $p = ['id' => (int)$pdo->lastInsertId(), 'token' => $tok];
        }
        setcookie('cq_token', $p['token'], ['expires' => time() + 86400 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);

        if ($done = attempt_of((int)$p['id'], (int)$test['id'])) {
            $_SESSION['my_attempts'][] = (int)$done['id'];
            redirect('result.php?id=' . $done['id'] . '&again=1');
        }
        $_SESSION['quiz'] = ['pid' => (int)$p['id'], 'tid' => (int)$test['id'], 'started' => time(), 'seed' => random_int(1, PHP_INT_MAX)];
        redirect('quiz.php');
    }
}

$done = $me ? attempt_of((int)$me['id'], (int)$test['id']) : null;
$preDone = ($me && $preTest) ? attempt_of((int)$me['id'], (int)$preTest['id']) : null;

page_header($test['title']);
?>
<div class="card hero">
  <span class="badge <?= $isPost ? 'post' : 'pre' ?>"><?= $isPost ? 'หลังเรียน' : 'ก่อนเรียน' ?></span>
  <h1><?= h($test['title']) ?></h1>
  <p class="muted"><?= $isPost
      ? 'กรอกชื่อ-สกุลให้ตรงกับตอนทำแบบทดสอบก่อนเรียน เพื่อให้ระบบเทียบผลพัฒนาการของคุณได้'
      : 'กรอกชื่อ-สกุลและหน่วยงาน แล้วกดเริ่มทำแบบทดสอบ โปรดใช้ชื่อเดียวกันนี้ตอนทำแบบทดสอบหลังเรียน' ?></p>
</div>

<?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>

<?php if (!$test['is_open']): ?>
  <div class="card center"><h2>ยังไม่เปิดให้ทำแบบทดสอบนี้</h2><p class="muted">กรุณารอสัญญาณจากวิทยากร แล้วรีเฟรชหน้านี้อีกครั้ง</p>
    <a class="btn" href="">รีเฟรช</a></div>
<?php elseif ($done): ?>
  <div class="card center"><h2>คุณทำแบบทดสอบนี้แล้ว</h2>
    <p>ได้ <b><?= (int)$done['score'] ?>/<?= (int)$done['total'] ?></b> คะแนน</p>
    <a class="btn" href="result.php?id=<?= (int)$done['id'] ?>">ดูผลคะแนน</a>
    <?php if (cert_passed($done + ['code' => $test['code']])): ?><a class="btn primary" href="<?= (eval_required() && !eval_done((int)$done['participant_id'])) ? 'eval.php' : 'cert.php' ?>?id=<?= (int)$done['id'] ?>">รับเกียรติบัตร (PDF)</a><?php endif; ?></div>
<?php else: ?>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <label>ชื่อ-สกุล
      <input name="full_name" required maxlength="200" autocomplete="name"
             value="<?= h($_POST['full_name'] ?? ($me['full_name'] ?? '')) ?>" placeholder="เช่น สมชาย ใจดี">
    </label>
    <label>หน่วยงาน
      <input name="agency" required maxlength="200"
             value="<?= h($_POST['agency'] ?? ($me['agency'] ?? '')) ?>" placeholder="เช่น แผนก / ฝ่าย / สำนัก">
    </label>
    <?php if ($requirePre && $me && !$preDone): ?>
      <p class="alert info">เครื่องนี้ยังไม่พบผลแบบทดสอบก่อนเรียน — ต้องทำ Pretest ก่อนจึงจะทำแบบทดสอบนี้ได้</p>
    <?php endif; ?>
    <button class="btn primary" type="submit">เริ่มทำแบบทดสอบ</button>
  </form>
<?php endif; ?>
<?php page_footer();
