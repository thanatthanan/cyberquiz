<?php
require __DIR__ . '/inc/bootstrap.php';

$quiz = $_SESSION['quiz'] ?? null;
if (!$quiz) redirect('index.php');

$pdo = db();
$s = $pdo->prepare("SELECT * FROM tests WHERE id=?");
$s->execute([$quiz['tid']]);
$test = $s->fetch();
if (!$test) redirect('index.php');
$pool = questions((int)$test['id']);

// สุ่มเลือกข้อสอบจากคลัง (ครั้งเดียวต่อการเข้าทำ — รีเฟรชหน้าแล้วได้ชุดเดิม)
$byId = [];
foreach ($pool as $q) $byId[(int)$q['id']] = $q;
if (empty($quiz['qids']) || array_diff($quiz['qids'], array_keys($byId))) {
    $ids = array_keys($byId);
    shuffle($ids);
    $n = (int)(cfg('questions_per_test') ?? 0);
    if ($n > 0) $ids = array_slice($ids, 0, $n);
    $quiz['qids'] = $ids;
    $_SESSION['quiz'] = $quiz;
}
$qs = [];
foreach ($quiz['qids'] as $id) $qs[] = $byId[$id];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$test['is_open']) fatal('แบบทดสอบนี้ปิดรับคำตอบแล้ว');
    if ($test['code'] === 'post' && (cfg('require_pretest') ?? true)) {
        $pre = test_by_code('pre');
        if ($pre && !attempt_of($quiz['pid'], (int)$pre['id'])) fatal('ต้องทำแบบทดสอบก่อนเรียน (Pretest) ให้เสร็จก่อน');
    }
    $done = attempt_of($quiz['pid'], (int)$test['id']);
    $isRetake = $done && can_retake_post($done + ['code' => $test['code']]);
    if ($done && !$isRetake) {
        unset($_SESSION['quiz']);
        $_SESSION['my_attempts'][] = (int)$done['id'];
        redirect('result.php?id=' . $done['id'] . '&again=1');
    }
    $ans = $_POST['ans'] ?? [];
    $rows = [];
    $score = 0;
    foreach ($qs as $q) {
        $v = $ans[$q['id']] ?? null;
        $chosen = ($v !== null && in_array((string)$v, ['0', '1', '2', '3'], true)) ? (int)$v : null;
        $ok = ($chosen !== null && $chosen === (int)$q['correct']) ? 1 : 0;
        $score += $ok;
        $rows[] = [(int)$q['id'], $chosen, $ok];
    }
    $dur = max(0, time() - (int)$quiz['started']);
    $pdo->beginTransaction();
    try {
        if ($isRetake) {
            $aid = (int)$done['id'];
            $pdo->prepare("UPDATE attempts SET first_score=COALESCE(first_score,score), score=?, total=?, attempt_no=attempt_no+1, duration_sec=?, submitted_at=NOW(), ip=? WHERE id=?")
                ->execute([$score, count($qs), $dur, client_ip(), $aid]);
            $pdo->prepare("DELETE FROM answers WHERE attempt_id=?")->execute([$aid]);
        } else {
            $pdo->prepare("INSERT INTO attempts (participant_id,test_id,score,total,attempt_no,first_score,duration_sec,submitted_at,ip) VALUES (?,?,?,?,1,?,?,NOW(),?)")
                ->execute([$quiz['pid'], $test['id'], $score, count($qs), $score, $dur, client_ip()]);
            $aid = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO answers (attempt_id,question_id,chosen,is_correct) VALUES (?,?,?,?)");
        foreach ($rows as $r) $ins->execute([$aid, $r[0], $r[1], $r[2]]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $done = attempt_of($quiz['pid'], (int)$test['id']);
        if ($done) { $aid = (int)$done['id']; } else { throw $e; }
    }
    unset($_SESSION['quiz']);
    $_SESSION['my_attempts'][] = $aid;
    redirect('result.php?id=' . $aid);
}

$L = letters();
page_header($test['title']);
?>
<div class="card hero">
  <h1><?= h($test['title']) ?></h1>
  <p class="muted" style="margin-bottom:0">ทั้งหมด <?= count($qs) ?> ข้อ ข้อละ 1 คะแนน เลือกคำตอบที่ถูกต้องที่สุดเพียงข้อเดียว แล้วกด “ส่งคำตอบ” ด้านล่าง</p>
</div>
<div class="card quizprogress">
  <div class="qp-row">
    <small id="count">ตอบแล้ว 0 / <?= count($qs) ?> ข้อ</small>
  </div>
  <div class="progress"><div id="bar"></div></div>
</div>

<form method="post" id="quiz">
  <?= csrf_field() ?>
  <?php foreach ($qs as $i => $q):
      $order = [0, 1, 2, 3];
      if (cfg('shuffle_options')) { mt_srand((int)(($quiz['seed'] + (int)$q['id'] * 7919) % 2147483647)); shuffle($order); }
      $opts = q_options($q); ?>
    <div class="card q" role="radiogroup">
      <div class="qhead"><span class="num"><?= $i + 1 ?></span><span><?= nl2br(str_replace('@', '@&#8203;', h($q['question']))) ?></span></div>
      <?php foreach ($order as $pos => $orig): ?>
        <label class="opt">
          <input type="radio" name="ans[<?= (int)$q['id'] ?>]" value="<?= $orig ?>" required>
          <span class="let"><?= $L[$pos] ?></span><span><?= str_replace('@', '@&#8203;', h($opts[$orig])) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <div class="card submitbar">
    <button class="btn primary" type="submit">ส่งคำตอบ</button>
    <small class="muted">ส่งได้ครั้งเดียว ตรวจคำตอบให้ครบก่อนกดส่ง</small>
  </div>
</form>
<script>
(function(){
  var f=document.getElementById('quiz'), total=<?= count($qs) ?>;
  function upd(){
    var n=new Set([].slice.call(f.querySelectorAll('input[type=radio]:checked')).map(function(i){return i.name})).size;
    document.getElementById('bar').style.width=(n/total*100)+'%';
    document.getElementById('count').textContent='ตอบแล้ว '+n+' / '+total+' ข้อ';
  }
  f.addEventListener('change',upd);
  var sent=false;
  f.addEventListener('submit',function(e){ if(sent){e.preventDefault();return;} sent=true; f.querySelector('button').textContent='กำลังส่ง…'; });
  window.addEventListener('beforeunload',function(e){ if(!sent && f.querySelector('input:checked')){ e.preventDefault(); e.returnValue=''; } });
})();
</script>
<?php page_footer();
