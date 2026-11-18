<?php
/*
 * หน้าเข้าสู่แบบทดสอบ (ใช้ร่วมกันโดย pretest.php และ posttest.php)
 * ต้องกำหนด $CODE = 'pre' หรือ 'post' ก่อน require ไฟล์นี้
 */
require __DIR__ . '/bootstrap.php';

$pdo = db();
$proj = front_project();
$projId = (int)$proj['id'];
$GLOBALS['CQ_PROJECT'] = $proj;

$test = test_by_code($CODE, $projId);
if (!$test) fatal('ไม่พบแบบทดสอบนี้');
$isPost = ($CODE === 'post');
$requirePre = $isPost && (cfg('require_pretest') ?? true);
$preTest = $requirePre ? test_by_code('pre', $projId) : null;

$me = current_participant($projId);
$cookieAny = $me ?: cookie_participant_any();
$switch = $isPost && !empty($_GET['switch']);
$error = '';
$lookupQuery = '';
$candidates = null; // null = ยังไม่ได้ค้นหา, array = ผลลัพธ์การค้นหา

function start_quiz_for(array $p, array $test, bool $allowRetake = false): void
{
    setcookie('cq_token', $p['token'], ['expires' => time() + 86400 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    if ($done = attempt_of((int)$p['id'], (int)$test['id'])) {
        if ($allowRetake && $test['is_open'] && can_retake_post($done + ['code' => $test['code']])) {
            $_SESSION['quiz'] = ['pid' => (int)$p['id'], 'tid' => (int)$test['id'], 'started' => time(), 'seed' => random_int(1, PHP_INT_MAX)];
            redirect('quiz.php');
        }
        $_SESSION['my_attempts'][] = (int)$done['id'];
        redirect('result.php?id=' . $done['id'] . '&again=1');
    }
    $_SESSION['quiz'] = ['pid' => (int)$p['id'], 'tid' => (int)$test['id'], 'started' => time(), 'seed' => random_int(1, PHP_INT_MAX)];
    redirect('quiz.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? ($isPost ? 'lookup' : 'register'));

    if (!$test['is_open']) {
        $error = 'แบบทดสอบนี้ยังไม่เปิดให้ทำ';
    } elseif ($action === 'retake') {
        if (!$me) {
            $error = 'ไม่พบข้อมูลเดิมในเครื่องนี้ กรุณาค้นหาด้วยชื่อ-สกุล หรือรหัส 6 หลัก';
        } else {
            start_quiz_for($me, $test, true);
        }
    } elseif ($action === 'start_cookie') {
        if (!$me) {
            $error = 'ไม่พบข้อมูลเดิมในเครื่องนี้ กรุณาค้นหาด้วยชื่อ-สกุล หรือรหัส 6 หลัก';
        } elseif ($requirePre && $preTest && !attempt_of((int)$me['id'], (int)$preTest['id'])) {
            $error = 'ยังไม่พบผลแบบทดสอบก่อนอบรมของ “' . $me['full_name'] . '” — กรุณาทำ Pretest ก่อน หรือค้นหาจากชื่อ/รหัส 6 หลัก';
        } else {
            start_quiz_for($me, $test);
        }
    } elseif ($action === 'confirm_pid') {
        $pid = (int)($_POST['pid'] ?? 0);
        $allowed = $_SESSION['lookup_allowed'] ?? [];
        if (!$pid || !in_array($pid, $allowed, true)) {
            $error = 'รายการที่เลือกไม่ถูกต้องหรือหมดอายุ กรุณาค้นหาใหม่อีกครั้ง';
        } else {
            $s = $pdo->prepare("SELECT * FROM participants WHERE id=? AND project_id=?");
            $s->execute([$pid, $projId]);
            $p = $s->fetch() ?: null;
            if (!$p) {
                $error = 'ไม่พบข้อมูลผู้เข้าร่วมนี้';
            } elseif ($requirePre && $preTest && !attempt_of($pid, (int)$preTest['id'])) {
                $error = '“' . $p['full_name'] . '” ยังไม่ได้ทำแบบทดสอบก่อนอบรม (Pretest) — กรุณาทำ Pretest ก่อน';
            } else {
                unset($_SESSION['lookup_allowed']);
                start_quiz_for($p, $test);
            }
        }
    } elseif ($action === 'lookup') {
        $lookupQuery = trim((string)($_POST['lookup'] ?? ''));
        $lookupNorm = strtr($lookupQuery, ['๐'=>'0','๑'=>'1','๒'=>'2','๓'=>'3','๔'=>'4','๕'=>'5','๖'=>'6','๗'=>'7','๘'=>'8','๙'=>'9']);
        if (mb_strlen($lookupQuery) < 2) {
            $error = 'กรุณากรอกรหัสประจำตัว 6 หลัก หรือชื่อ-สกุล เพื่อค้นหา';
        } else {
            $qCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $lookupNorm));
            $rows = [];

            // 1) ค้นหาจากรหัสประจำตัว 6 หลัก (ref_code)
            if ($qCode !== '' && strlen($qCode) >= 4) {
                $s = $pdo->prepare("SELECT * FROM participants WHERE project_id=? AND (ref_code=? OR ref_code LIKE ?) ORDER BY (ref_code=?) DESC, id DESC LIMIT 10");
                $s->execute([$projId, $qCode, '%' . $qCode . '%', $qCode]);
                $rows = $s->fetchAll();
            }
            // 2) ค้นหาจากชื่อ-สกุล (บางส่วนหรือทั้งหมด)
            if (!$rows) {
                $nk = name_key($lookupNorm);
                $s = $pdo->prepare("SELECT * FROM participants WHERE project_id=? AND (name_key LIKE ? OR full_name LIKE ?) ORDER BY id DESC LIMIT 15");
                $s->execute([$projId, '%' . $nk . '%', '%' . $lookupQuery . '%']);
                $rows = $s->fetchAll();
            }

            if ($rows) {
                $candidates = $rows;
                $_SESSION['lookup_allowed'] = array_map(fn($r) => (int)$r['id'], $rows);
            } else {
                $candidates = [];
                $error = 'ไม่พบข้อมูล “' . $lookupQuery . '” ในระบบ — ตรวจสอบรหัส 6 หลัก หรือชื่อ-สกุลอีกครั้ง หรือกลับไปทำ Pretest ก่อน';
            }
        }
    } else {
        // action === 'register' (สำหรับ Pretest หรือ Posttest กรณีไม่ได้บังคับ Pretest)
        $name   = trim(preg_replace('/\s+/u', ' ', (string)($_POST['full_name'] ?? '')));
        $agency = trim((string)($_POST['agency'] ?? ''));

        if (mb_strlen($name) < 3) $error = 'กรุณากรอกชื่อ-สกุล';
        elseif ($agency === '')   $error = 'กรุณากรอกหน่วยงาน';

        $key = name_key($name);
        $p = null;
        if (!$error) {
            if ($me && $me['name_key'] === $key) {
                $p = $me;
            } elseif ($isPost) {
                $s = $pdo->prepare("SELECT * FROM participants WHERE project_id=? AND name_key=? ORDER BY id LIMIT 1");
                $s->execute([$projId, $key]);
                $p = $s->fetch() ?: null;
            }
        }

        if (!$error && $requirePre && $preTest && !($p && attempt_of((int)$p['id'], (int)$preTest['id']))) {
            $error = 'ไม่พบผลแบบทดสอบก่อนอบรมของ “' . $name . '” — กรุณาทำ Pretest ก่อน';
        }

        if (!$error) {
            if ($p) {
                $ref = $p['ref_code'] ?: generate_ref_code($pdo);
                $pdo->prepare("UPDATE participants SET full_name=?, agency=?, name_key=?, ref_code=? WHERE id=?")
                    ->execute([$name, $agency, $key, $ref, $p['id']]);
                $p['ref_code'] = $ref;
            } else {
                $tok = bin2hex(random_bytes(16));
                $ref = generate_ref_code($pdo);
                $pdo->prepare("INSERT INTO participants (project_id,token,full_name,agency,ref_code,name_key,created_at) VALUES (?,?,?,?,?,?,NOW())")
                    ->execute([$projId, $tok, $name, $agency, $ref, $key]);
                $p = ['id' => (int)$pdo->lastInsertId(), 'token' => $tok, 'ref_code' => $ref];
            }
            start_quiz_for($p, $test);
        }
    }
}

$done = ($me && !$switch) ? attempt_of((int)$me['id'], (int)$test['id']) : null;
$preDone = ($me && $preTest) ? attempt_of((int)$me['id'], (int)$preTest['id']) : null;

page_header($test['title']);
?>
<div class="card hero">
  <span class="badge <?= $isPost ? 'post' : 'pre' ?>"><?= $isPost ? 'หลังอบรม' : 'ก่อนอบรม' ?></span>
  <h1><?= h($test['title']) ?></h1>
  <p class="muted"><?= $isPost
      ? 'ยืนยันตัวตนเพื่อทำแบบทดสอบหลังอบรม และเปรียบเทียบพัฒนาการกับคะแนนก่อนอบรม'
      : 'กรอกชื่อ-สกุลและหน่วยงานเพียงครั้งเดียว ระบบจะจดจำข้อมูลและสร้างรหัสประจำตัวไว้ใช้ต่อในแบบทดสอบหลังอบรม' ?></p>
</div>

<?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>

<?php if (!$test['is_open']): ?>
  <div class="card center"><h2>ยังไม่เปิดให้ทำแบบทดสอบนี้</h2><p class="muted">กรุณารอสัญญาณจากวิทยากร แล้วรีเฟรชหน้านี้อีกครั้ง</p>
    <a class="btn" href="">รีเฟรช</a></div>

<?php elseif ($done): ?>
  <?php $canRetake = $isPost && can_retake_post($done + ['code' => $test['code']]); ?>
  <div class="card center"><h2>คุณทำแบบทดสอบนี้แล้ว</h2>
    <p class="muted"><?= h($me['full_name']) ?> (<?= h($me['agency']) ?>)</p>
    <p>ได้ <b><?= (int)$done['score'] ?>/<?= (int)$done['total'] ?></b> คะแนน</p>
    <div class="toggles" style="justify-content:center">
      <a class="btn" href="result.php?id=<?= (int)$done['id'] ?>">ดูผลคะแนน</a>
      <?php if ($canRetake): ?>
        <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="retake">
          <button class="btn primary" type="submit">ทำแบบทดสอบใหม่อีกครั้ง</button></form>
      <?php elseif (cert_passed($done + ['code' => $test['code']])): ?>
        <a class="btn primary" href="<?= (eval_required() && !eval_done((int)$done['participant_id'])) ? 'eval.php' : 'cert.php' ?>?id=<?= (int)$done['id'] ?>">รับเกียรติบัตร (PDF)</a>
      <?php endif; ?>
    </div>
    <?php if ($isPost): ?><p style="margin-top:14px"><a class="muted" href="posttest.php?switch=1"><small>ไม่ใช่คุณ? เปลี่ยนผู้ใช้งาน</small></a></p><?php endif; ?>
  </div>

<?php elseif ($isPost && $me && !$switch && (!$requirePre || $preDone)): ?>
  <!-- กรณีเครื่องเดิมมี cookie และผ่าน Pretest แล้ว: กดปุ่มเดียวเริ่มสอบทันที -->
  <form method="post" class="card center">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="start_cookie">
    <p class="muted" style="margin-bottom:4px">ยินดีต้อนรับกลับ</p>
    <h2 style="margin:4px 0"><?= h($me['full_name']) ?></h2>
    <p class="muted" style="margin-top:0"><?= h($me['agency']) ?>
      <?php if (!empty($me['ref_code'])): ?> · รหัสประจำตัว: <b><?= h($me['ref_code']) ?></b><?php endif; ?>
    </p>
    <?php if ($preDone): ?>
      <p class="alert info">ผลก่อนอบรมของคุณ: <b><?= (int)$preDone['score'] ?>/<?= (int)$preDone['total'] ?></b> คะแนน</p>
    <?php endif; ?>
    <button class="btn primary" type="submit">เริ่มทำแบบทดสอบหลังอบรม</button>
    <p style="margin-top:14px;margin-bottom:0"><a class="muted" href="posttest.php?switch=1"><small>ไม่ใช่คุณ? เปลี่ยนผู้ใช้งาน / ค้นหาด้วยชื่อหรือรหัส</small></a></p>
  </form>

<?php elseif ($isPost): ?>
  <!-- กรณีไม่มี cookie / เปิดผ่าน LINE Browser / เปลี่ยนเครื่อง: ค้นหาและยืนยันตัวตน -->
  <?php if ($me && !$switch && $requirePre && !$preDone): ?>
    <div class="alert info">เครื่องนี้บันทึกชื่อ <b><?= h($me['full_name']) ?></b> ไว้ แต่ยังไม่พบผลแบบทดสอบก่อนอบรม — หากทำ Pretest จากเครื่องอื่น กรุณาค้นหาด้วยรหัส 6 หลักหรือชื่อ-สกุลด้านล่าง หรือ <a href="pretest.php"><b>ไปทำ Pretest ก่อน</b></a></div>
  <?php endif; ?>

  <?php if (is_array($candidates) && count($candidates) > 0): ?>
    <div class="card" style="border-top:4px solid var(--navy2)">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <h2 style="margin:0;font-size:20px;color:var(--navy)">พบข้อมูลผู้เข้าร่วม <?= count($candidates) ?> รายการ</h2>
        <small class="muted">แตะปุ่มเพื่อเลือกชื่อของคุณ</small>
      </div>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($candidates as $c):
          $cPre = $preTest ? attempt_of((int)$c['id'], (int)$preTest['id']) : null;
          $cDone = attempt_of((int)$c['id'], (int)$test['id']);
        ?>
          <form method="post" style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:16px;border:2px solid <?= count($candidates) === 1 ? 'var(--navy2)' : 'var(--line)' ?>;background:#f8fafc;border-radius:12px;flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_pid">
            <input type="hidden" name="pid" value="<?= (int)$c['id'] ?>">
            <div style="flex:1 1 220px;min-width:0">
              <div style="font-size:20px;font-weight:700;color:var(--navy);line-height:1.3"><?= h($c['full_name']) ?></div>
              <div style="font-size:16px;color:var(--text);margin-top:2px"><?= h($c['agency']) ?></div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px">
                <?php if (!empty($c['ref_code'])): ?>
                  <span style="background:#e2e8f0;color:var(--navy);font-weight:700;font-size:13px;padding:2px 10px;border-radius:99px">รหัส <?= h($c['ref_code']) ?></span>
                <?php endif; ?>
                <?php if ($cPre): ?>
                  <span style="background:var(--okbg);color:var(--ok);font-weight:600;font-size:13px;padding:2px 10px;border-radius:99px">✓ ทำก่อนอบรมแล้ว (<?= (int)$cPre['score'] ?>/<?= (int)$cPre['total'] ?>)</span>
                <?php elseif ($requirePre): ?>
                  <span style="background:var(--badbg);color:var(--bad);font-weight:600;font-size:13px;padding:2px 10px;border-radius:99px">✗ ยังไม่พบผลก่อนอบรม</span>
                <?php endif; ?>
              </div>
            </div>
            <div style="flex:0 0 auto">
              <?php if ($cDone): ?>
                <button class="btn" type="submit" style="padding:10px 18px;font-size:16px">ดูผลคะแนน →</button>
              <?php elseif ($requirePre && !$cPre): ?>
                <a class="btn off" href="pretest.php" style="padding:10px 18px;font-size:15px">ไปทำก่อนอบรม</a>
              <?php else: ?>
                <button class="btn primary" type="submit" style="padding:11px 20px;font-size:16px">เลือกชื่อนี้ และเริ่มสอบ →</button>
              <?php endif; ?>
            </div>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="lookup">
    <label><?= (is_array($candidates) && count($candidates) > 0) ? 'ไม่ใช่รายชื่อด้านบน? ค้นหาด้วยรหัส 6 หลัก หรือชื่อ-สกุลอื่น' : 'ค้นหาข้อมูลเดิม (รหัสประจำตัว 6 หลัก หรือ ชื่อ-สกุล)' ?>
      <input name="lookup" required maxlength="100" <?= empty($candidates) ? 'autofocus' : '' ?>
             value="<?= h($lookupQuery) ?>" placeholder="เช่น 482910 หรือ สมชาย">
    </label>
    <button class="btn <?= empty($candidates) ? 'primary' : '' ?>" type="submit">ค้นหาข้อมูลเดิม</button>
    <?php if ($requirePre): ?>
      <p class="muted" style="margin:0"><small>ยังไม่เคยทำแบบทดสอบก่อนอบรม? <a href="pretest.php">คลิกที่นี่เพื่อทำ Pretest</a></small></p>
    <?php endif; ?>
  </form>

  <?php if (!$requirePre): ?>
    <details class="card" <?= ($candidates === [] ? 'open' : '') ?>>
      <summary style="cursor:pointer;font-weight:700">หรือลงทะเบียนใหม่ (กรณีไม่เคยทำ Pretest)</summary>
      <form method="post" class="form" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="register">
        <label>ชื่อ-สกุล
          <input name="full_name" required maxlength="200" autocomplete="name"
                 value="<?= h($_POST['full_name'] ?? '') ?>" placeholder="เช่น สมชาย ใจดี">
        </label>
        <label>หน่วยงาน
          <input name="agency" required maxlength="200"
                 value="<?= h($_POST['agency'] ?? '') ?>" placeholder="เช่น แผนก / ฝ่าย / สำนัก">
        </label>
        <button class="btn primary" type="submit">เริ่มทำแบบทดสอบ</button>
      </form>
    </details>
  <?php endif; ?>

<?php else: ?>
  <!-- หน้าลงทะเบียนทำแบบทดสอบก่อนอบรม (Pretest) -->
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="register">
    <label>ชื่อ-สกุล (ใช้แสดงบนเกียรติบัตร)
      <input name="full_name" required maxlength="200" autocomplete="name"
             value="<?= h($_POST['full_name'] ?? ($cookieAny['full_name'] ?? '')) ?>" placeholder="เช่น สมชาย ใจดี">
    </label>
    <label>หน่วยงาน
      <input name="agency" required maxlength="200"
             value="<?= h($_POST['agency'] ?? ($cookieAny['agency'] ?? '')) ?>" placeholder="เช่น แผนก / ฝ่าย / สำนัก">
    </label>
    <button class="btn primary" type="submit">เริ่มทำแบบทดสอบ</button>
  </form>
<?php endif; ?>
<?php page_footer();
