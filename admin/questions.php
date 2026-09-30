<?php
require __DIR__ . '/../inc/bootstrap.php';
$PAGE_ROOT = '../';
if (!is_admin()) redirect('index.php');
$pdo = db();

$tests = tests();
$code = $_GET['test'] ?? ($tests[0]['code'] ?? 'pre');
$test = test_by_code((string)$code);
if (!$test) redirect('questions.php');
$tid = (int)$test['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE tests SET title=? WHERE id=?")->execute([trim((string)$_POST['title']) ?: $test['title'], $tid]);
    $upd = $pdo->prepare("UPDATE questions SET seq=?, topic=?, question=?, opt_a=?, opt_b=?, opt_c=?, opt_d=?, correct=? WHERE id=? AND test_id=?");
    $ins = $pdo->prepare("INSERT INTO questions (test_id,seq,topic,question,opt_a,opt_b,opt_c,opt_d,correct) VALUES (?,?,?,?,?,?,?,?,?)");
    $del = $pdo->prepare("DELETE FROM questions WHERE id=? AND test_id=?");
    foreach ((array)($_POST['q'] ?? []) as $id => $q) {
        $vals = [(int)($q['seq'] ?? 0), trim((string)($q['topic'] ?? '')), trim((string)($q['question'] ?? '')),
                 trim((string)($q['a'] ?? '')), trim((string)($q['b'] ?? '')), trim((string)($q['c'] ?? '')), trim((string)($q['d'] ?? '')),
                 max(0, min(3, (int)($q['correct'] ?? 0)))];
        if ($id === 'new') {
            if ($vals[2] !== '') $ins->execute(array_merge([$tid], $vals));
        } elseif (!empty($q['delete'])) {
            $del->execute([(int)$id, $tid]);
        } else {
            $upd->execute(array_merge($vals, [(int)$id, $tid]));
        }
    }
    $pdo->commit();
    $_SESSION['flash'] = 'บันทึกข้อสอบแล้ว';
    redirect('questions.php?test=' . urlencode($test['code']));
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$qs = questions($tid);
$s = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE test_id=?");
$s->execute([$tid]);
$nAttempts = (int)$s->fetchColumn();
$L = letters();

function qform($id, array $q, int $seq, array $L): string
{
    $o = [$q['opt_a'] ?? '', $q['opt_b'] ?? '', $q['opt_c'] ?? '', $q['opt_d'] ?? ''];
    $n = 'q[' . h((string)$id) . ']';
    $html = '<fieldset class="card q edit"><div class="row"><label class="seq">ลำดับ<input type="number" name="' . $n . '[seq]" value="' . $seq . '" min="0"></label>'
        . '<label class="grow">หัวข้อที่วัด (ใช้ในสถิติ)<input name="' . $n . '[topic]" value="' . h($q['topic'] ?? '') . '"></label></div>'
        . '<label>คำถาม<textarea name="' . $n . '[question]" rows="2">' . h($q['question'] ?? '') . '</textarea></label>';
    foreach (['a', 'b', 'c', 'd'] as $k => $key) {
        $chk = ((int)($q['correct'] ?? -1) === $k) ? 'checked' : '';
        $html .= '<div class="optedit"><label class="correct" title="คำตอบที่ถูก"><input type="radio" name="' . $n . '[correct]" value="' . $k . '" ' . $chk . '> ' . $L[$k] . '</label>'
            . '<input name="' . $n . '[' . $key . ']" value="' . h($o[$k]) . '"></div>';
    }
    if ($id !== 'new') $html .= '<label class="tiny delete"><input type="checkbox" name="' . $n . '[delete]" value="1"> ลบข้อนี้</label>';
    return $html . '</fieldset>';
}

page_header('จัดการข้อสอบ', admin_nav('quiz'));
?>
<div class="adminbar">
  <h1>จัดการข้อสอบ</h1>
</div>
<div class="tabs">
  <?php foreach ($tests as $t): ?><a class="<?= $t['code'] === $test['code'] ? 'on' : '' ?>" href="?test=<?= h($t['code']) ?>"><?= h($t['title']) ?></a><?php endforeach; ?>
</div>
<?php if ($flash): ?><div class="alert info"><?= h($flash) ?></div><?php endif; ?>
<?php if ($nAttempts): ?><div class="alert">มีผู้ส่งคำตอบชุดนี้แล้ว <?= $nAttempts ?> คน การแก้คำตอบที่ถูกจะไม่คำนวณคะแนนเดิมใหม่ และการลบข้อจะลบคำตอบของข้อนั้นไปด้วย</div><?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card form"><label>ชื่อแบบทดสอบ<input name="title" value="<?= h($test['title']) ?>"></label>
    <small class="muted">คลังชุดนี้มี <?= count($qs) ?> ข้อ ระบบสุ่มให้ผู้เข้าร่วมคนละ <?= (int)(cfg('questions_per_test') ?: count($qs)) ?> ข้อ (ตั้งค่า questions_per_test ใน config.php) · เลือกวงกลมหน้าตัวเลือกเพื่อกำหนดคำตอบที่ถูก · ผู้เข้าร่วมจะเห็นตัวเลือกสลับลำดับแบบสุ่ม<?= cfg('shuffle_options') ? '' : ' (ปิดอยู่ใน config.php)' ?></small></div>
  <?php foreach ($qs as $i => $q) echo qform($q['id'], $q, (int)$q['seq'] ?: $i + 1, $L); ?>
  <h2 class="section">เพิ่มข้อใหม่</h2>
  <?= qform('new', ['correct' => 0], count($qs) + 1, $L) ?>
  <div class="card submitbar"><button class="btn primary">บันทึกทั้งหมด</button></div>
</form>
<?php page_footer();
