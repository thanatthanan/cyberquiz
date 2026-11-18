<?php
require __DIR__ . '/inc/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$pdo = db();
$s = $pdo->prepare("SELECT a.*, t.code, t.title, t.project_id, p.full_name, p.agency, p.token
                    FROM attempts a JOIN tests t ON t.id=a.test_id JOIN participants p ON p.id=a.participant_id WHERE a.id=?");
$s->execute([$id]);
$a = $s->fetch();
$mine = $a && (
    in_array($id, $_SESSION['my_attempts'] ?? [], true)
    || in_array($id, $_SESSION['my_certs'] ?? [], true)
    || hash_equals($a['token'], (string)($_COOKIE['cq_token'] ?? ''))
    || is_admin()
);
if (!$mine) redirect('index.php');
$proj = project_by_id((int)$a['project_id']);
if ($proj) $GLOBALS['CQ_PROJECT'] = $proj;
$cs = cert_settings($proj);
if (!cert_passed($a, $proj)) {
    fatal('ยังไม่ผ่านเกณฑ์รับเกียรติบัตร — ต้องได้คะแนนแบบทดสอบหลังอบรมอย่างน้อย '
        . h($cs['cert_pass_pct']) . '% <br><a class="btn" href="result.php?id=' . $id . '">กลับหน้าผลคะแนน</a>');
}

// ต้องทำแบบประเมินการอบรมก่อน (ผู้ดูแลเปิดดูได้เสมอ)
if (eval_required($proj) && !is_admin() && !eval_done((int)$a['participant_id'])) redirect('eval.php?id=' . $id);

$certCode = cert_code_for($pdo, $id);
$verifyUrl = verify_url($certCode);
$data = [
    'cfg'   => cert_js_config('', $proj),
    'name'  => $a['full_name'],
    'date'  => thai_date($a['submitted_at']),
    'extra' => ['cert_code' => $certCode, 'verify_url' => $verifyUrl],
    'file'  => 'เกียรติบัตร_' . preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', $a['full_name']) . '.pdf',
];

page_header('เกียรติบัตร');
?>
<?php if (!empty($_GET['thanks'])): ?><div class="alert info">ขอบคุณที่ตอบแบบประเมิน — ดาวน์โหลดเกียรติบัตรได้ด้านล่าง</div><?php endif; ?>
<div class="card hero center">
  <h1>ขอแสดงความยินดี!</h1>
  <p class="muted">คุณผ่านแบบทดสอบหลังอบรมด้วยคะแนน <?= (int)$a['score'] ?>/<?= (int)$a['total'] ?> เมื่อวันที่ <?= h(thai_date($a['submitted_at'])) ?></p>
</div>
<div class="card certbox">
  <canvas id="cert" aria-label="ตัวอย่างเกียรติบัตร" style="display:block;width:100%;max-width:900px;height:auto;margin:0 auto"></canvas>
  <p id="status" class="muted">กำลังสร้างเกียรติบัตร…</p>
  <button class="btn primary" id="dl" disabled>ดาวน์โหลดเกียรติบัตร (PDF)</button>
  <p class="muted" style="margin-top:10px"><small>เลขที่เกียรติบัตร: <b><?= h($certCode) ?></b> · <a href="<?= h($verifyUrl) ?>" target="_blank">ลิงก์ตรวจสอบความถูกต้อง</a></small></p>
  <p><a href="result.php?id=<?= $id ?>">กลับหน้าผลคะแนน</a></p>
</div>
<script src="assets/vendor/jspdf.umd.min.js"></script>
<script src="assets/vendor/qrcode.min.js"></script>
<script src="assets/cert.js?v=<?= @filemtime(__DIR__ . '/assets/cert.js') ?>"></script>
<script>
(function(){
  var D = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var cv = document.getElementById('cert'), btn = document.getElementById('dl'), st = document.getElementById('status');
  CQCert.prepare(D.cfg).then(function(img){
    CQCert.draw(cv, img, D.cfg, D.name, D.date, null, D.extra);
    st.textContent = ''; btn.disabled = false;
    btn.onclick = function(){ CQCert.savePdf(cv, D.file); };
  }).catch(function(e){ st.textContent = e.message; });
})();
</script>
<?php page_footer();
