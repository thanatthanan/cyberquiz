<?php
require __DIR__ . '/inc/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$pdo = db();
$s = $pdo->prepare("SELECT a.*, t.code, p.token, p.full_name FROM attempts a JOIN tests t ON t.id=a.test_id
                    JOIN participants p ON p.id=a.participant_id WHERE a.id=?");
$s->execute([$id]);
$a = $s->fetch();
$mine = $a && (in_array($id, $_SESSION['my_attempts'] ?? [], true) || hash_equals($a['token'], (string)($_COOKIE['cq_token'] ?? '')));
if (!$mine) redirect('index.php');
if (!cert_passed($a)) redirect('result.php?id=' . $id);
if (eval_done((int)$a['participant_id'])) redirect('cert.php?id=' . $id);

$E = eval_items();
$keys = eval_rating_keys();
$error = '';
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pos = (string)($_POST['position'] ?? '');
    $posOther = trim((string)($_POST['position_other'] ?? ''));
    $prior = $_POST['prior'] ?? '';
    $ratings = [];
    foreach ($keys as $k => $label) {
        $v = (int)($_POST['r'][$k] ?? 0);
        if ($v >= 1 && $v <= 5) $ratings[$k] = $v;
    }
    if (!in_array($pos, $E['positions'], true))        $error = 'กรุณาเลือกตำแหน่งงาน';
    elseif (!in_array($prior, ['0', '1'], true))        $error = 'กรุณาระบุว่าเคยเข้าอบรมด้านไซเบอร์มาก่อนหรือไม่';
    elseif (count($ratings) < count($keys))             $error = 'กรุณาให้คะแนนความพึงพอใจให้ครบทุกข้อ';

    if (!$error) {
        $txt = fn($k) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, 2000);
        try {
            $pdo->prepare("INSERT INTO evaluations (participant_id,position,position_other,prior_training,ratings,benefit,topics,suggestion,created_at)
                           VALUES (?,?,?,?,?,?,?,?,NOW())")
                ->execute([(int)$a['participant_id'], $pos, mb_substr($posOther, 0, 200), (int)$prior,
                           json_encode($ratings), $txt('benefit'), $txt('topics'), $txt('suggestion')]);
        } catch (PDOException $e) {
            if (!eval_done((int)$a['participant_id'])) throw $e; // ส่งซ้ำ = ถือว่าทำแล้ว
        }
        redirect('cert.php?id=' . $id . '&thanks=1');
    }
}

page_header('แบบประเมินการอบรม');
$chk = function ($name, $val) use ($old) { return (string)($old[$name] ?? '') === (string)$val ? 'checked' : ''; };
?>
<div class="card hero">
  <span class="badge post">ขั้นตอนสุดท้าย</span>
  <h1>แบบประเมินผลการอบรม</h1>
  <p class="muted">ทำแบบประเมินให้ครบ แล้วดาวน์โหลดเกียรติบัตรได้ทันที · ผลใช้ปรับปรุงการอบรมครั้งต่อไป และรายงานเป็นภาพรวมเท่านั้น ไม่ระบุตัวตนผู้ตอบ</p>
</div>
<?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>

<form method="post" id="ev">
  <?= csrf_field() ?>
  <div class="card form">
    <h3>ส่วนที่ 1 ข้อมูลทั่วไป</h3>
    <div class="label">ตำแหน่งงาน</div>
    <div class="chips">
      <?php foreach ($E['positions'] as $p): ?>
        <label class="chip"><input type="radio" name="position" value="<?= h($p) ?>" <?= $chk('position', $p) ?> required><span><?= h($p) ?></span></label>
      <?php endforeach; ?>
    </div>
    <label id="otherbox">โปรดระบุ<input name="position_other" maxlength="200" value="<?= h($old['position_other'] ?? '') ?>"></label>
    <div class="label">เคยเข้าอบรมด้านความมั่นคงปลอดภัยไซเบอร์มาก่อนหรือไม่</div>
    <div class="chips">
      <label class="chip"><input type="radio" name="prior" value="1" <?= $chk('prior', '1') ?> required><span>เคย</span></label>
      <label class="chip"><input type="radio" name="prior" value="0" <?= $chk('prior', '0') ?>><span>ไม่เคย</span></label>
    </div>
  </div>

  <div class="card">
    <h3>ส่วนที่ 2 ความพึงพอใจต่อการอบรม</h3>
    <p class="muted">5 = มากที่สุด · 4 = มาก · 3 = ปานกลาง · 2 = น้อย · 1 = น้อยที่สุด</p>
    <?php $n = 0; foreach ($E['ratings'] as $group => $items): ?>
      <div class="evgroup"><?= h($group) ?></div>
      <?php foreach ($items as $k => $label): $n++; ?>
        <div class="evrow">
          <div class="evq"><?= $n ?>. <?= h($label) ?></div>
          <div class="scale">
            <?php foreach ($E['scale'] as $v => $txt): ?>
              <label title="<?= h($txt) ?>"><input type="radio" name="r[<?= h($k) ?>]" value="<?= $v ?>" <?= (string)($old['r'][$k] ?? '') === (string)$v ? 'checked' : '' ?> required><span><b><?= $v ?></b><small><?= h($txt) ?></small></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <div class="card form">
    <h3>ส่วนที่ 3 ข้อคิดเห็นและข้อเสนอแนะ <small class="muted">(ไม่บังคับ)</small></h3>
    <?php $i = 0; foreach ($E['comments'] as $k => $label): $i++; ?>
      <label><?= $i ?>. <?= h($label) ?><textarea name="<?= h($k) ?>" rows="2" maxlength="2000"><?= h($old[$k] ?? '') ?></textarea></label>
    <?php endforeach; ?>
  </div>

  <div class="card submitbar">
    <button class="btn primary" type="submit">ส่งแบบประเมิน และรับเกียรติบัตร</button>
    <small class="muted" id="left"></small>
  </div>
</form>
<script>
(function(){
  var f=document.getElementById('ev'), other=document.getElementById('otherbox');
  var total=<?= count($keys) ?>;
  function upd(){
    var p=f.querySelector('input[name=position]:checked');
    other.style.display = (p && p.value==='อื่น ๆ') ? '' : 'none';
    var done=f.querySelectorAll('.scale input:checked').length;
    document.getElementById('left').textContent = done<total ? ('ให้คะแนนแล้ว '+done+' / '+total+' ข้อ') : 'ครบทุกข้อแล้ว';
  }
  f.addEventListener('change',upd); upd();
  var sent=false; f.addEventListener('submit',function(e){ if(sent){e.preventDefault();return;} sent=true; });
})();
</script>
<?php page_footer();
