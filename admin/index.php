<?php
require __DIR__ . '/../inc/bootstrap.php';
$PAGE_ROOT = '../';
$pdo = db();

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'login') {
        if (hash_equals((string)cfg('admin_password'), (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
        } else {
            $_SESSION['flash'] = 'รหัสผ่านไม่ถูกต้อง';
        }
        redirect('index.php');
    }
    if (!is_admin()) redirect('index.php');
    $tid = (int)($_POST['test_id'] ?? 0);
    switch ($act) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            redirect('index.php');
        case 'toggle_open':
            $pdo->prepare("UPDATE tests SET is_open=1-is_open WHERE id=?")->execute([$tid]);
            break;
        case 'toggle_answers':
            $pdo->prepare("UPDATE tests SET show_answers=1-show_answers WHERE id=?")->execute([$tid]);
            break;
        case 'delete_participant':
            $pdo->prepare("DELETE FROM participants WHERE id=?")->execute([(int)($_POST['pid'] ?? 0)]);
            $_SESSION['flash'] = 'ลบผู้เข้าร่วมแล้ว';
            break;
        case 'save_course':
            $cTitle = trim((string)($_POST['course_title'] ?? ''));
            $cOrg   = trim((string)($_POST['org_name'] ?? ''));
            $maxAtt = max(0, (int)($_POST['post_max_attempts'] ?? 3));
            if ($cTitle === '') {
                $_SESSION['flash'] = 'กรุณากรอกชื่อหัวข้อการอบรม';
            } else {
                set_setting('course_title', mb_substr($cTitle, 0, 500));
                set_setting('org_name', mb_substr($cOrg, 0, 500));
                set_setting('post_max_attempts', (string)$maxAtt);
                $_SESSION['flash'] = 'บันทึกการตั้งค่าการอบรมแล้ว';
            }
            break;
        case 'reset':
            if (trim((string)($_POST['confirm'] ?? '')) === 'RESET') {
                $pdo->exec("DELETE FROM participants");
                $_SESSION['flash'] = 'ล้างผลการทดสอบทั้งหมดแล้ว (ข้อสอบยังอยู่ครบ)';
            } else {
                $_SESSION['flash'] = 'พิมพ์คำว่า RESET ให้ถูกต้องเพื่อยืนยันการล้างข้อมูล';
            }
            break;
    }
    $t = (string)($_GET['tab'] ?? '');
    redirect('index.php' . (preg_match('/^[a-z]+$/', $t) ? '?tab=' . $t : ''));
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

/* ---------- login page ---------- */
if (!is_admin()) {
    page_header('ผู้ดูแลระบบ');
    if ($flash) echo '<div class="alert">' . h($flash) . '</div>';
    echo '<form method="post" class="card form narrow"><h2>เข้าสู่ระบบผู้ดูแล</h2>' . csrf_field()
        . '<input type="hidden" name="action" value="login"><label>รหัสผ่าน<input type="password" name="password" required autofocus></label>'
        . '<button class="btn primary">เข้าสู่ระบบ</button><p><a href="../index.php">← กลับหน้าผู้เข้าร่วม</a></p></form>';
    page_footer();
    exit;
}

/* ---------- statistics ---------- */
$tests = tests();
$byCode = [];
foreach ($tests as $t) $byCode[$t['code']] = $t;
$preId = (int)($byCode['pre']['id'] ?? 0);
$postId = (int)($byCode['post']['id'] ?? 0);

$summary = [];
foreach ($tests as $t) {
    $s = $pdo->prepare("SELECT COUNT(*) n, AVG(score) avg_s, MAX(total) tot, MIN(score) mn, MAX(score) mx, AVG(duration_sec) dur FROM attempts WHERE test_id=?");
    $s->execute([$t['id']]);
    $summary[$t['id']] = $s->fetch();
}
$nPeople = (int)$pdo->query("SELECT COUNT(*) FROM participants")->fetchColumn();

$paired = $pdo->prepare("SELECT COUNT(*) n, AVG(b.score) pre, AVG(c.score) post, AVG(c.score-b.score) diff,
    SUM(c.score>b.score) up, SUM(c.score=b.score) same, SUM(c.score<b.score) down
    FROM attempts b JOIN attempts c ON c.participant_id=b.participant_id AND c.test_id=? WHERE b.test_id=?");
$paired->execute([$postId, $preId]);
$pair = $paired->fetch();

$perQ = [];
$dist = [];
foreach ($tests as $t) {
    $s = $pdo->prepare("SELECT q.id, q.seq, q.topic, q.question, COUNT(x.question_id) n, COALESCE(SUM(x.is_correct),0) c
        FROM questions q LEFT JOIN answers x ON x.question_id=q.id WHERE q.test_id=? GROUP BY q.id ORDER BY q.seq, q.id");
    $s->execute([$t['id']]);
    $perQ[$t['id']] = $s->fetchAll();
    $s = $pdo->prepare("SELECT score, COUNT(*) n FROM attempts WHERE test_id=? GROUP BY score");
    $s->execute([$t['id']]);
    $dist[$t['id']] = $s->fetchAll(PDO::FETCH_KEY_PAIR);
}

$people = $pdo->prepare("SELECT p.id, p.full_name, p.agency, p.ref_code, p.created_at,
    b.score pre_s, b.total pre_t, b.submitted_at pre_at, c.id post_id, c.score post_s, c.total post_t, c.submitted_at post_at
    FROM participants p
    LEFT JOIN attempts b ON b.participant_id=p.id AND b.test_id=?
    LEFT JOIN attempts c ON c.participant_id=p.id AND c.test_id=?
    ORDER BY p.created_at DESC, p.id DESC");
$people->execute([$preId, $postId]);
$people = $people->fetchAll();

$agencies = $pdo->prepare("SELECT p.agency, COUNT(DISTINCT p.id) n, AVG(b.score) pre, AVG(c.score) post
    FROM participants p
    LEFT JOIN attempts b ON b.participant_id=p.id AND b.test_id=?
    LEFT JOIN attempts c ON c.participant_id=p.id AND c.test_id=?
    GROUP BY p.agency ORDER BY n DESC, p.agency");
$agencies->execute([$preId, $postId]);
$agencies = $agencies->fetchAll();

/* ผลแบบประเมินการอบรม */
$EV = eval_items();
$evRows = $pdo->query("SELECT * FROM evaluations ORDER BY created_at DESC")->fetchAll();
$evN = count($evRows);
$evSum = []; $evCnt = []; $evPos = []; $evPrior = ['1' => 0, '0' => 0]; $evText = [];
foreach ($EV['comments'] as $k => $label) $evText[$k] = [];
foreach ($evRows as $e) {
    foreach ((array)json_decode($e['ratings'], true) as $k => $v) { $evSum[$k] = ($evSum[$k] ?? 0) + (int)$v; $evCnt[$k] = ($evCnt[$k] ?? 0) + 1; }
    $pos = $e['position'] === 'อื่น ๆ' && $e['position_other'] !== '' ? 'อื่น ๆ (' . $e['position_other'] . ')' : $e['position'];
    $evPos[$pos] = ($evPos[$pos] ?? 0) + 1;
    if ($e['prior_training'] !== null) $evPrior[(string)(int)$e['prior_training']]++;
    foreach ($EV['comments'] as $k => $label) if (trim((string)$e[$k]) !== '') $evText[$k][] = $e[$k];
}
arsort($evPos);
$evAll = 0; $evAllN = 0;
$evKnow = $EV['knowledge'] ?? [];
$evSkip = array_values($evKnow);   // ข้อความรู้ก่อน/หลัง ไม่นับรวมค่าเฉลี่ยความพึงพอใจ
foreach ($evSum as $k => $v) { if (in_array($k, $evSkip, true)) continue; $evAll += $v; $evAllN += $evCnt[$k]; }
function level_th(float $m): string { return $m >= 4.5 ? 'มากที่สุด' : ($m >= 3.5 ? 'มาก' : ($m >= 2.5 ? 'ปานกลาง' : ($m >= 1.5 ? 'น้อย' : 'น้อยที่สุด'))); }

/* สรุปผลแบบประเมิน (แสดงในแท็บภาพรวม) */
$evGroups = [];      // ค่าเฉลี่ยรายด้าน
$evItems = [];       // ค่าเฉลี่ยรายข้อ (ไว้หาสูงสุด/ต่ำสุด)
foreach ($EV['ratings'] as $g => $items) {
    $gs = 0; $gn = 0;
    foreach ($items as $k => $label) {
        if (!empty($evCnt[$k]) && !in_array($k, $evSkip, true)) { $gs += $evSum[$k]; $gn += $evCnt[$k]; $evItems[$k] = ['label' => $label, 'm' => $evSum[$k] / $evCnt[$k]]; }
    }
    if ($gn) $evGroups[$g] = $gs / $gn;
}
$evTop = $evItems ? array_reduce($evItems, fn($c, $x) => ($c === null || $x['m'] > $c['m']) ? $x : $c) : null;
$evLow = $evItems ? array_reduce($evItems, fn($c, $x) => ($c === null || $x['m'] < $c['m']) ? $x : $c) : null;
$passN = 0;
$ps = $pdo->prepare("SELECT score, total FROM attempts WHERE test_id=?");
$ps->execute([$postId]);
foreach ($ps as $r) if (cert_passed($r + ['code' => 'post'])) $passN++;
$evOverall = $evAllN ? $evAll / $evAllN : null;
$evSat = !empty($evItems['all']) ? $evItems['all']['m'] : null;
$kb = $evKnow['before'] ?? ''; $ka = $evKnow['after'] ?? '';
$evKB = !empty($evCnt[$kb]) ? $evSum[$kb] / $evCnt[$kb] : null;
$evKA = !empty($evCnt[$ka]) ? $evSum[$ka] / $evCnt[$ka] : null;   // ข้อ "ความพึงพอใจโดยรวม"

$path = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
if (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host)) $host = gethostbyname(gethostname()); // เครื่องในห้องอบรม: ใช้ IP ในวง LAN
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$lanUrl = $scheme . '://' . $host . $path . '/';

function per_test_count(int $pool): int { $n = (int)(cfg('questions_per_test') ?? 0); return $n > 0 ? min($n, $pool) : $pool; }
function fmt($v, int $d = 1): string { return $v === null ? '–' : number_format((float)$v, $d); }
function bar(float $pct, string $cls = ''): string
{
    $c = $pct < 50 ? 'low' : ($pct < 75 ? 'mid' : 'high');
    return '<div class="hbar ' . $cls . '"><div class="' . $c . '" style="width:' . max(0, min(100, $pct)) . '%"></div><span>' . round($pct) . '%</span></div>';
}

$live = !empty($_GET['live']);
page_header('แดชบอร์ดผู้ดูแล', admin_nav('dash'));
if ($live) echo '<meta http-equiv="refresh" content="15">';
?>
<div class="adminbar">
  <h1>แดชบอร์ดผลการทดสอบ</h1>
  <div class="actions">
    <a class="btn <?= $live ? 'ok' : '' ?>" id="livelink" href="?<?= $live ? '' : 'live=1&amp;' ?>tab=<?= h($_GET['tab'] ?? 'overview') ?>"><?= $live ? '⏸ หยุดอัปเดตอัตโนมัติ' : '⟳ อัปเดตอัตโนมัติทุก 15 วินาที' ?></a>
    <a class="btn off" href="../index.php">← กลับหน้าแรก</a>
  </div>
</div>
<?php if ($flash): ?><div class="alert info"><?= h($flash) ?></div><?php endif; ?>

<?php
$tabs = ['overview' => 'ภาพรวม', 'questions' => 'ร้อยละที่ตอบถูกรายข้อ', 'eval' => 'ผลแบบประเมินการอบรม', 'agency' => 'สรุปตามหน่วยงาน', 'people' => 'ผู้เข้าร่วม'];
$badge = ['eval' => $evN, 'agency' => count($agencies), 'people' => count($people)];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'overview';
?>
<nav class="tabs dashtabs" role="tablist">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="?tab=<?= $k ?><?= $live ? '&amp;live=1' : '' ?>" role="tab" data-tab="<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= h($label) ?><?php if (isset($badge[$k])): ?> <span class="tabcount"><?= (int)$badge[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>
<section class="tabpane" data-pane="overview" <?= $tab === 'overview' ? '' : 'hidden' ?>>
<div class="card linkbox" style="display:block">
  <small class="muted">ลิงก์สำหรับผู้เข้าร่วม (เครื่องที่ต่อเครือข่ายเดียวกัน) · ถ้าเครื่องอื่นเปิดไม่ได้ ให้อนุญาต Apache ใน Windows Firewall</small>
  <div class="linkrow">
    <div><span class="badge pre">ก่อนอบรม</span> <?= !empty($byCode['pre']['is_open']) ? '<small class="up">● เปิดอยู่</small>' : '<small class="muted">○ ปิดอยู่</small>' ?>
      <div class="url"><?= h($lanUrl . 'pretest.php?openExternalBrowser=1') ?></div>
      <small class="muted">ใช้ลิงก์นี้สร้าง QR Code (สแกนผ่าน LINE จะเปิด Chrome/Safari ให้อัตโนมัติ)</small></div>
    <div><span class="badge post">หลังอบรม</span> <?= !empty($byCode['post']['is_open']) ? '<small class="up">● เปิดอยู่</small>' : '<small class="muted">○ ปิดอยู่</small>' ?>
      <div class="url"><?= h($lanUrl . 'posttest.php?openExternalBrowser=1') ?></div>
      <small class="muted"><?= (cfg('require_pretest') ?? true) ? 'เครื่องเดิมกดเริ่มสอบได้เลย / ย้ายเครื่องค้นหาด้วยชื่อหรือรหัส 6 หลัก' : 'ไม่บังคับทำ Pretest ก่อน (ตั้งค่าใน config.php)' ?></small></div>
  </div>
</div>

<div class="grid cards">
  <div class="card stat"><small>ผู้เข้าร่วมทั้งหมด</small><b><?= $nPeople ?></b><span>คน</span></div>
  <?php foreach ($tests as $t): $sm = $summary[$t['id']]; ?>
    <div class="card stat"><small><?= h($t['title']) ?></small><b><?= fmt($sm['avg_s']) ?></b>
      <span>คะแนนเฉลี่ย / <?= (int)($sm['tot'] ?: per_test_count(count($perQ[$t['id']]))) ?> · ส่งแล้ว <?= (int)$sm['n'] ?> คน</span></div>
  <?php endforeach; ?>
  <div class="card stat accent"><small>พัฒนาการ (คนที่ทำครบทั้ง 2 ชุด)</small>
    <b class="<?= !$pair['n'] ? '' : ($pair['diff'] > 0 ? 'up' : ($pair['diff'] < 0 ? 'down' : 'flat')) ?>"><?= $pair['n'] ? (($pair['diff'] >= 0 ? '+' : '') . fmt($pair['diff'])) : '–' ?></b>
    <span><?= (int)$pair['n'] ?> คน · ดีขึ้น <?= (int)$pair['up'] ?> · เท่าเดิม <?= (int)$pair['same'] ?> · ลดลง <?= (int)$pair['down'] ?></span></div>
</div>

<h2 class="section">สรุปผลแบบประเมินการอบรม</h2>
<div class="card evsum">
  <?php if (!$evN): ?>
    <p class="muted" style="margin:0">ยังไม่มีผู้ตอบแบบประเมิน<?= $passN ? ' — ผู้ผ่านเกณฑ์ ' . $passN . ' คน ยังไม่ได้ทำแบบประเมิน' : '' ?></p>
  <?php else: ?>
  <div class="evsum-grid">
    <div class="evkpi"><small>ตอบแบบประเมินแล้ว</small><b><?= $evN ?></b><span>คน<?= $passN ? ' · ' . round($evN / max($passN, $evN) * 100) . '% ของผู้ผ่านเกณฑ์ (' . $passN . ' คน)' : '' ?></span></div>
    <div class="evkpi"><small>ค่าเฉลี่ยความพึงพอใจรวม</small><b><?= number_format($evOverall, 2) ?></b><span>จาก 5 · ระดับ<?= level_th($evOverall) ?></span></div>
    <?php if ($evSat !== null): ?><div class="evkpi accent"><small>ความพึงพอใจโดยรวม</small><b><?= number_format($evSat, 2) ?></b><span>จาก 5 · ระดับ<?= level_th($evSat) ?></span></div><?php endif; ?>
    <?php if ($evKB !== null && $evKA !== null): $dk = $evKA - $evKB; ?><div class="evkpi"><small>ความรู้ของผู้เข้าอบรม (ประเมินตนเอง)</small><b><?= number_format($evKB, 2) ?> → <?= number_format($evKA, 2) ?></b><span>ก่อน → หลังอบรม · <span class="<?= $dk > 0 ? 'up' : ($dk < 0 ? 'down' : '') ?>"><?= $dk >= 0 ? '+' : '' ?><?= number_format($dk, 2) ?></span></span></div><?php endif; ?>
  </div>
  <div class="evsum-grid two">
    <div>
      <div class="label">ค่าเฉลี่ยความพึงพอใจรายด้าน</div>
      <table class="tbl">
        <?php foreach ($evGroups as $g => $m): $cl = $m >= 4 ? 'high' : ($m >= 3 ? 'mid' : 'low'); ?>
          <tr><td style="width:40%"><?= h($g) ?></td><td><div class="hbar"><div class="<?= $cl ?>" style="width:<?= $m / 5 * 100 ?>%"></div><span><?= number_format($m, 2) ?></span></div></td><td style="width:90px"><?= level_th($m) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div>
      <div class="label">จุดเด่น / จุดที่ควรปรับปรุง</div>
      <?php if ($evTop): ?><p class="evnote up">▲ สูงสุด: <?= h($evTop['label']) ?> <b>(<?= number_format($evTop['m'], 2) ?>)</b></p><?php endif; ?>
      <?php if ($evLow && $evLow !== $evTop): ?><p class="evnote down">▼ ต่ำสุด: <?= h($evLow['label']) ?> <b>(<?= number_format($evLow['m'], 2) ?>)</b></p><?php endif; ?>
      <?php $nComments = 0; foreach ($evText as $list) $nComments += count($list); ?>
      <p class="muted">ข้อเสนอแนะทั้งหมด <?= $nComments ?> ความเห็น</p>
      <a class="btn" href="?tab=eval" data-goto-tab="eval">ดูผลแบบประเมินทั้งหมด →</a>
    </div>
  </div>
  <?php endif; ?>
</div>

<h2 class="section">ควบคุมแบบทดสอบ</h2>
<div class="grid two">
<?php foreach ($tests as $t): $sm = $summary[$t['id']]; ?>
  <div class="card">
    <h3><?= h($t['title']) ?></h3>
    <p class="muted">ต่ำสุด <?= $sm['n'] ? (int)$sm['mn'] : '–' ?> · สูงสุด <?= $sm['n'] ? (int)$sm['mx'] : '–' ?> · เวลาเฉลี่ย <?= $sm['n'] ? gmdate('i:s', (int)$sm['dur']) : '–' ?> นาที</p>
    <div class="toggles">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_open"><input type="hidden" name="test_id" value="<?= (int)$t['id'] ?>">
        <button class="btn <?= $t['is_open'] ? 'ok' : 'off' ?>"><?= $t['is_open'] ? '● เปิดให้ทำอยู่' : '○ ปิดอยู่' ?></button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_answers"><input type="hidden" name="test_id" value="<?= (int)$t['id'] ?>">
        <button class="btn <?= $t['show_answers'] ? 'ok' : 'off' ?>"><?= $t['show_answers'] ? '● แสดงเฉลยหลังส่ง' : '○ ไม่แสดงเฉลย' ?></button></form>
    </div>
    <div class="label">การกระจายคะแนน</div>
    <?php $tot = (int)($sm['tot'] ?: per_test_count(count($perQ[$t['id']]))); $maxN = max(array_merge([1], array_values($dist[$t['id']]))); ?>
    <div class="dist">
      <?php for ($sc = 0; $sc <= $tot; $sc++): $n = (int)($dist[$t['id']][$sc] ?? 0); ?>
        <div class="col" title="<?= $sc ?> คะแนน: <?= $n ?> คน"><span><?= $n ?: '' ?></span><div style="height:<?= round($n / $maxN * 100) ?>%"></div><small><?= $sc ?></small></div>
      <?php endfor; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<h2 class="section">ตั้งค่าหัวข้อการอบรม</h2>
<form method="post" class="card form">
  <?= csrf_field() ?><input type="hidden" name="action" value="save_course">
  <label>ชื่อหัวข้อการอบรม (แสดงบนแถบหัวเว็บทุกหน้า)
    <input name="course_title" required maxlength="500" value="<?= h(course_title()) ?>" placeholder="เช่น การอบรมความตระหนักรู้ด้านความมั่นคงปลอดภัยไซเบอร์">
  </label>
  <label>ชื่อหน่วยงาน / ผู้จัด
    <input name="org_name" maxlength="500" value="<?= h(org_name()) ?>" placeholder="เช่น สำนักงานคณะกรรมการการรักษาความมั่นคงปลอดภัยไซเบอร์แห่งชาติ (สกมช.)">
  </label>
  <label>จำนวนครั้งสูงสุดที่ทำแบบทดสอบหลังอบรมได้ (กรณีไม่ผ่านเกณฑ์)
    <?php $curMax = post_max_attempts(); ?>
    <select name="post_max_attempts">
      <option value="1" <?= $curMax === 1 ? 'selected' : '' ?>>1 รอบ (ทำได้ครั้งเดียว ไม่ให้สอบซ่อม)</option>
      <option value="2" <?= $curMax === 2 ? 'selected' : '' ?>>2 รอบ (สอบซ่อมได้ 1 ครั้ง)</option>
      <option value="3" <?= $curMax === 3 ? 'selected' : '' ?>>3 รอบ (สอบซ่อมได้ 2 ครั้ง — แนะนำ)</option>
      <option value="5" <?= $curMax === 5 ? 'selected' : '' ?>>5 รอบ (สอบซ่อมได้ 4 ครั้ง)</option>
      <option value="0" <?= $curMax === 0 ? 'selected' : '' ?>>ไม่จำกัดรอบ (ทำซ้ำได้จนกว่าจะผ่านเกณฑ์)</option>
    </select>
  </label>
  <button class="btn primary">บันทึกการตั้งค่า</button>
</form>

<h2 class="section">ล้างข้อมูลเพื่อเริ่มรุ่นใหม่</h2>
<form method="post" class="card form danger">
  <?= csrf_field() ?><input type="hidden" name="action" value="reset">
  <p>ลบผู้เข้าร่วมและผลการทดสอบทั้งหมด (ข้อสอบไม่ถูกลบ) — ควรดาวน์โหลด CSV เก็บไว้ก่อน</p>
  <label>พิมพ์คำว่า <b>RESET</b> เพื่อยืนยัน<input name="confirm" autocomplete="off"></label>
  <button class="btn danger">ล้างผลการทดสอบทั้งหมด</button>
</form>
</section>

<section class="tabpane" data-pane="questions" <?= $tab === 'questions' ? '' : 'hidden' ?>>
<h2 class="section">ร้อยละที่ตอบถูกรายข้อ</h2>
<p class="muted">ข้อที่ต่ำกว่า 50% (สีแดง) คือหัวข้อที่ควรเน้นย้ำในการบรรยาย · ระบบสุ่ม <?= (int)(cfg('questions_per_test') ?: 0) ?: 'ทุก' ?> ข้อต่อคนจากคลัง จำนวนคนที่ได้แต่ละข้อจึงไม่เท่ากัน</p>
<div class="grid two">
<?php foreach ($tests as $t): ?>
  <div class="card">
    <h3><?= h($t['title']) ?></h3>
    <table class="tbl">
      <thead><tr><th>ข้อ</th><th>หัวข้อ</th><th>ได้ข้อนี้</th><th style="width:38%">ตอบถูก</th></tr></thead>
      <tbody>
      <?php foreach ($perQ[$t['id']] as $i => $q): $p = $q['n'] ? $q['c'] / $q['n'] * 100 : 0; ?>
        <tr title="<?= h($q['question']) ?>"><td><?= $i + 1 ?></td><td><?= h($q['topic']) ?></td><td><?= (int)$q['n'] ?> คน</td>
          <td><?= $q['n'] ? bar($p) : '<span class="muted">ยังไม่มีข้อมูล</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>
</div>
</section>

<section class="tabpane" data-pane="eval" <?= $tab === 'eval' ? '' : 'hidden' ?>>
<h2 class="section">ผลแบบประเมินการอบรม</h2>
<div class="card">
  <div class="adminbar" style="margin-bottom:6px">
    <p class="muted" style="margin:0">ตอบแล้ว <b><?= $evN ?></b> คน<?= $evN ? ' · ค่าเฉลี่ยความพึงพอใจรวม <b>' . number_format($evAll / max(1, $evAllN), 2) . '</b> (' . level_th($evAll / max(1, $evAllN)) . ')' : '' ?> · แสดงเป็นภาพรวม ไม่ระบุชื่อผู้ตอบ</p>
    <a class="btn" href="export.php?type=eval">ดาวน์โหลดผลแบบประเมิน (CSV)</a>
  </div>
  <?php if ($evN): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>รายการประเมิน</th><th style="width:34%">ค่าเฉลี่ย (เต็ม 5)</th><th>ระดับ</th></tr></thead>
    <tbody>
    <?php $n = 0; foreach ($EV['ratings'] as $group => $items): ?>
      <tr><td colspan="3" class="evgroup" style="display:table-cell"><?= h($group) ?></td></tr>
      <?php foreach ($items as $k => $label): $n++; $m = !empty($evCnt[$k]) ? $evSum[$k] / $evCnt[$k] : null; ?>
        <tr><td><?= $n ?>. <?= h($label) ?></td>
          <td><?php if ($m !== null): $pct = $m / 5 * 100; $cl = $m >= 4 ? 'high' : ($m >= 3 ? 'mid' : 'low'); ?>
            <div class="hbar"><div class="<?= $cl ?>" style="width:<?= $pct ?>%"></div><span><?= number_format($m, 2) ?></span></div><?php else: ?>–<?php endif; ?></td>
          <td><?= $m !== null ? level_th($m) : '' ?></td></tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="grid two" style="margin-top:14px">
    <div><div class="label">ตำแหน่งงาน</div>
      <table class="tbl"><?php foreach ($evPos as $k => $v): ?><tr><td><?= h($k) ?></td><td><?= $v ?> คน</td><td><?= round($v / $evN * 100) ?>%</td></tr><?php endforeach; ?></table></div>
    <div><div class="label">เคยอบรมด้านไซเบอร์มาก่อน</div>
      <table class="tbl"><tr><td>เคย</td><td><?= $evPrior['1'] ?> คน</td><td><?= round($evPrior['1'] / $evN * 100) ?>%</td></tr>
        <tr><td>ไม่เคย</td><td><?= $evPrior['0'] ?> คน</td><td><?= round($evPrior['0'] / $evN * 100) ?>%</td></tr></table></div>
  </div>
  <div class="grid two" style="margin-top:14px">
    <?php foreach ($EV['comments'] as $k => $label): ?>
      <div><div class="label"><?= h($label) ?> <small class="muted">(<?= count($evText[$k]) ?>)</small></div>
        <?php if ($evText[$k]): ?><ul class="comments"><?php foreach ($evText[$k] as $t): ?><li><?= nl2br(h($t)) ?></li><?php endforeach; ?></ul>
        <?php else: ?><p class="muted">ยังไม่มีความคิดเห็น</p><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
  <?php else: ?><p class="muted">ยังไม่มีผู้ตอบแบบประเมิน</p><?php endif; ?>
</div>
</section>

<section class="tabpane" data-pane="agency" <?= $tab === 'agency' ? '' : 'hidden' ?>>
<h2 class="section">สรุปตามหน่วยงาน</h2>
<div class="card">
  <table class="tbl">
    <thead><tr><th>หน่วยงาน</th><th>จำนวน (คน)</th><th>เฉลี่ยก่อนอบรม</th><th>เฉลี่ยหลังอบรม</th></tr></thead>
    <tbody>
    <?php foreach ($agencies as $g): ?>
      <tr><td><?= h($g['agency']) ?></td><td><?= (int)$g['n'] ?></td><td><?= fmt($g['pre']) ?></td><td><?= fmt($g['post']) ?></td></tr>
    <?php endforeach; if (!$agencies): ?><tr><td colspan="4" class="muted">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
</section>

<section class="tabpane" data-pane="people" <?= $tab === 'people' ? '' : 'hidden' ?>>
<h2 class="section">ผู้เข้าร่วม</h2>
<div class="card">
  <input class="search" id="search" placeholder="ค้นหาชื่อ, หน่วยงาน หรือรหัส 6 หลัก…">
  <div class="scroll">
  <table class="tbl" id="people">
    <thead><tr><th>#</th><th>รหัส</th><th>ชื่อ-สกุล</th><th>หน่วยงาน</th><th>ก่อนอบรม</th><th>หลังอบรม</th><th>เปลี่ยนแปลง</th><th>เกียรติบัตร</th><th>ส่งล่าสุด</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $i => $p):
        $d = ($p['pre_s'] !== null && $p['post_s'] !== null) ? (int)$p['post_s'] - (int)$p['pre_s'] : null;
        $last = max((string)$p['pre_at'], (string)$p['post_at']); ?>
      <tr>
        <td><?= count($people) - $i ?></td>
        <td><code><?= h($p['ref_code'] ?: '–') ?></code></td>
        <td><?= h($p['full_name']) ?> <a class="editlink" href="participant.php?id=<?= (int)$p['id'] ?>&amp;back=<?= rawurlencode('index.php?tab=people') ?>" title="แก้ไขชื่อ">แก้ไข</a></td>
        <td><?= h($p['agency']) ?></td>
        <td><?= $p['pre_s'] !== null ? (int)$p['pre_s'] . '/' . (int)$p['pre_t'] : '<span class="muted">–</span>' ?></td>
        <td><?= $p['post_s'] !== null ? (int)$p['post_s'] . '/' . (int)$p['post_t'] : '<span class="muted">–</span>' ?></td>
        <td class="<?= $d === null ? '' : ($d > 0 ? 'up' : ($d < 0 ? 'down' : '')) ?>"><?= $d === null ? '' : (($d > 0 ? '+' : '') . $d) ?></td>
        <td><?php if ($p['post_s'] !== null && cert_passed(['code' => 'post', 'score' => $p['post_s'], 'total' => $p['post_t']])): ?><?= cert_view_button($p['full_name'], (string)$p['post_at'], 'ดู / ดาวน์โหลด', 'btn tiny', cert_code_for($pdo, (int)$p['post_id'])) ?><?php elseif ($p['post_s'] !== null): ?><span class="muted">ไม่ผ่าน</span><?php endif; ?></td>
        <td><small><?= $last ? h(date('d/m H:i', strtotime($last))) : '' ?></small></td>
        <td><form method="post" action="index.php?tab=people" class="inline" onsubmit="return this.confirm_del.checked"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_participant"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
            <label class="tiny"><input type="checkbox" name="confirm_del"> ลบ</label><button class="btn tiny ghost">ยืนยัน</button></form></td>
      </tr>
    <?php endforeach; if (!$people): ?><tr><td colspan="10" class="muted">ยังไม่มีผู้เข้าร่วม</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
</section>

<script>
(function(){
  var links=[].slice.call(document.querySelectorAll('.dashtabs a')), panes=[].slice.call(document.querySelectorAll('.tabpane'));
  var live=document.getElementById('livelink');
  function show(k, push){
    panes.forEach(function(p){ p.hidden = p.getAttribute('data-pane') !== k; });
    links.forEach(function(a){ var on=a.getAttribute('data-tab')===k; a.classList.toggle('on',on); a.setAttribute('aria-selected',on?'true':'false'); });
    var u=new URL(location.href); u.searchParams.set('tab',k);
    if (push) history.replaceState(null,'',u);
    if (live) { var lu=new URL(live.href, location.href); lu.searchParams.set('tab',k); live.href=lu.pathname+lu.search; }
  }
  links.forEach(function(a){ a.addEventListener('click',function(ev){ ev.preventDefault(); show(a.getAttribute('data-tab'),true); }); });
  document.querySelectorAll('[data-goto-tab]').forEach(function(a){ a.addEventListener('click',function(ev){ ev.preventDefault(); show(a.getAttribute('data-goto-tab'),true); window.scrollTo(0,0); }); });
})();
</script>
<script>
document.getElementById('search').addEventListener('input',function(){
  var q=this.value.trim().toLowerCase();
  document.querySelectorAll('#people tbody tr').forEach(function(tr){ tr.style.display=tr.textContent.toLowerCase().indexOf(q)>-1?'':'none'; });
});
</script>
<?= cert_modal_assets() ?>
<?php page_footer();
