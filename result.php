<?php
require __DIR__ . '/inc/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$pdo = db();
$s = $pdo->prepare("SELECT a.*, t.code, t.title, t.show_answers, p.full_name, p.agency, p.token
                    FROM attempts a JOIN tests t ON t.id=a.test_id JOIN participants p ON p.id=a.participant_id WHERE a.id=?");
$s->execute([$id]);
$a = $s->fetch();
$mine = $a && (in_array($id, $_SESSION['my_attempts'] ?? [], true) || hash_equals($a['token'], (string)($_COOKIE['cq_token'] ?? '')) || is_admin());
if (!$mine) redirect('index.php');

$pct = $a['total'] ? round($a['score'] / $a['total'] * 100) : 0;
$other = null;
if ($a['code'] === 'post') {
    $pre = test_by_code('pre');
    if ($pre) $other = attempt_of((int)$a['participant_id'], (int)$pre['id']);
}
$msg = $pct >= 80 ? 'ยอดเยี่ยม! คุณมีความตระหนักรู้ด้านความปลอดภัยไซเบอร์ในระดับดีมาก'
     : ($pct >= 60 ? 'ดี! ยังมีบางหัวข้อที่ควรทบทวนเพิ่มเติม' : 'ลองทบทวนเนื้อหาอีกครั้ง แล้วนำไปปรับใช้ในการทำงานนะครับ');

page_header('ผลการทดสอบ');
?>
<?php if (!empty($_GET['again'])): ?><div class="alert info">คุณทำ<?= h($a['title']) ?>ไปแล้ว ระบบให้ส่งได้ครั้งเดียว ด้านล่างคือผลที่บันทึกไว้</div><?php endif; ?>
<div class="card hero center">
  <p class="muted"><?= h($a['title']) ?> · <?= h($a['full_name']) ?> (<?= h($a['agency']) ?>)</p>
  <div class="score"><span><?= (int)$a['score'] ?></span>/<?= (int)$a['total'] ?></div>
  <div class="progress big"><div style="width:<?= $pct ?>%"></div></div>
  <p><b><?= $pct ?>%</b> — <?= h($msg) ?></p>
  <?php if ($other): $d = (int)$a['score'] - (int)$other['score']; ?>
    <div class="compare">
      <div><small>ก่อนเรียน</small><b><?= (int)$other['score'] ?>/<?= (int)$other['total'] ?></b></div>
      <div><small>หลังเรียน</small><b><?= (int)$a['score'] ?>/<?= (int)$a['total'] ?></b></div>
      <div class="<?= $d > 0 ? 'up' : ($d < 0 ? 'down' : '') ?>"><small>เปลี่ยนแปลง</small><b><?= $d > 0 ? '+' : '' ?><?= $d ?></b></div>
    </div>
  <?php endif; ?>
  <?php if ($a['code'] === 'post' && setting('cert_enabled', '1') === '1'): ?>
    <?php if (cert_passed($a)): ?>
      <?php $needEval = eval_required() && !eval_done((int)$a['participant_id']); ?>
      <p><a class="btn primary" href="<?= $needEval ? 'eval.php' : 'cert.php' ?>?id=<?= (int)$a['id'] ?>">รับเกียรติบัตร (PDF)</a></p>
      <?php if ($needEval): ?><p class="muted">กรุณาทำแบบประเมินการอบรมสั้น ๆ ก่อนดาวน์โหลดเกียรติบัตร</p><?php endif; ?>
    <?php else: ?>
      <p class="passnote">เกณฑ์รับเกียรติบัตร: ได้คะแนนหลังเรียนอย่างน้อย <?= h((string)setting('cert_pass_pct', '60')) ?>% (<?= cert_min_score((int)$a['total']) ?>/<?= (int)$a['total'] ?> คะแนน)</p>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($a['code'] === 'pre'): ?><p class="muted">เมื่อจบการบรรยาย วิทยากรจะแจกลิงก์แบบทดสอบหลังเรียน โปรดใช้ชื่อ-สกุลเดิม</p><?php endif; ?>
  <a class="btn" href="<?= $a['code'] === 'post' ? 'posttest.php' : 'pretest.php' ?>">กลับหน้าแบบทดสอบ</a>
</div>

<?php if ($a['show_answers']):
    $s = $pdo->prepare("SELECT q.*, x.chosen, x.is_correct FROM answers x JOIN questions q ON q.id=x.question_id WHERE x.attempt_id=? AND q.test_id=? ORDER BY q.seq, q.id");
    $s->execute([$id, $a['test_id']]); ?>
  <h2 class="section">เฉลย</h2>
  <?php foreach ($s->fetchAll() as $i => $q): $o = q_options($q); ?>
    <div class="card q review <?= $q['is_correct'] ? 'ok' : 'bad' ?>">
      <div class="qhead"><span class="num"><?= $i + 1 ?></span><span><?= h($q['question']) ?></span></div>
      <?php if (!$q['is_correct'] && $q['chosen'] !== null): ?><p class="yours">คำตอบของคุณ: <?= h($o[(int)$q['chosen']]) ?></p><?php endif; ?>
      <p class="right">คำตอบที่ถูก: <?= h($o[(int)$q['correct']]) ?></p>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php page_footer();
