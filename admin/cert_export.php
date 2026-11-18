<?php
require __DIR__ . '/../inc/bootstrap.php';
$PAGE_ROOT = '../';
if (!is_admin()) redirect('index.php');
$pdo = db();

// ผู้ที่ผ่านเกณฑ์ Posttest ทั้งหมด (วันที่บนเกียรติบัตร = วันที่ส่ง Posttest)
$post = test_by_code('post');
$s = $pdo->prepare("SELECT a.id, a.score, a.total, a.submitted_at, p.id pid, p.full_name, p.agency,
                           (e.id IS NOT NULL) eval_done
                    FROM attempts a JOIN participants p ON p.id=a.participant_id
                    LEFT JOIN evaluations e ON e.participant_id=p.id
                    WHERE a.test_id=? ORDER BY a.submitted_at, p.full_name");
$s->execute([(int)($post['id'] ?? 0)]);
$rows = [];
$byDate = [];
foreach ($s->fetchAll() as $r) {
    if (!cert_passed($r + ['code' => 'post'])) continue;
    $d = substr($r['submitted_at'], 0, 10);
    $r['date'] = $d;
    $r['date_th'] = thai_date($r['submitted_at']);
    $rows[] = $r;
    $byDate[$d] = ($byDate[$d] ?? 0) + 1;
}
krsort($byDate);
$sel = (string)($_GET['date'] ?? (array_key_first($byDate) ?? ''));
if ($sel !== 'all' && !isset($byDate[$sel])) $sel = array_key_first($byDate) ?? '';
// ค้นหาจากชื่อหรือหน่วยงาน (ค้นทุกวันที่)
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $qk = name_key($q);
    $list = array_values(array_filter($rows, fn($r) =>
        mb_stripos($r['full_name'], $q) !== false || mb_stripos($r['agency'], $q) !== false
        || ($qk !== '' && mb_strpos(name_key($r['full_name']), $qk) !== false)));
} else {
    $list = array_values(array_filter($rows, fn($r) => $sel === 'all' || $r['date'] === $sel));
}
$backUrl = 'cert_export.php?' . ($q !== '' ? 'q=' . rawurlencode($q) : 'date=' . rawurlencode($sel));

$data = [
    'cfg'  => cert_js_config('../'),
    'rows' => array_map(function ($r) use ($pdo) {
        $code = cert_code_for($pdo, (int)$r['id']);
        return [
            'id'     => (int)$r['id'],
            'name'   => $r['full_name'],
            'date'   => $r['date_th'],
            'eval'   => (bool)$r['eval_done'],
            'code'   => $code,
            'verify' => verify_url($code),
        ];
    }, $list),
    'tag'  => $q !== '' ? 'ค้นหา_' . $q : ($sel === 'all' ? 'ทั้งหมด' : $sel),
];

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
page_header('ส่งออกเกียรติบัตร', admin_nav('cert'));
?>
<div class="adminbar">
  <h1>ส่งออกเกียรติบัตร</h1>
</div>

<?php if ($flash): ?><div class="alert info"><?= h($flash) ?></div><?php endif; ?>
<?php if (!$byDate): ?>
  <div class="card center"><p class="muted">ยังไม่มีผู้ที่ผ่านเกณฑ์รับเกียรติบัตร</p></div>
<?php else: ?>
<div class="grid two" style="margin-bottom:14px">
<form class="card form" method="get">
  <label>วันที่บนเกียรติบัตร (วันที่ทำแบบทดสอบหลังเรียนผ่าน)
    <select name="date" onchange="this.form.submit()">
      <?php if ($q !== ''): ?><option value="" selected disabled>— กำลังแสดงผลการค้นหา (ทุกวันที่) —</option><?php endif; ?>
      <?php foreach ($byDate as $d => $n): ?>
        <option value="<?= h($d) ?>" <?= $q === '' && $sel === $d ? 'selected' : '' ?>><?= h(thai_date($d)) ?> — <?= $n ?> คน</option>
      <?php endforeach; ?>
      <option value="all" <?= $q === '' && $sel === 'all' ? 'selected' : '' ?>>ทุกวันที่ — <?= count($rows) ?> คน</option>
    </select>
  </label>
  <noscript><button class="btn">แสดง</button></noscript>
  <?php if ($q !== ''): ?><small class="muted">กำลังแสดงผลการค้นหา — เลือกวันที่เพื่อกลับไปแสดงตามวันที่</small><?php endif; ?>
</form>
<form class="card form" method="get" role="search">
  <label for="q">ค้นหาจากชื่อ-สกุล หรือหน่วยงาน (ทุกวันที่)</label>
  <div class="searchrow">
    <input id="q" name="q" value="<?= h($q) ?>" placeholder="พิมพ์ชื่อ นามสกุล หรือหน่วยงาน" autocomplete="off">
    <button class="btn primary">ค้นหา</button>
    <?php if ($q !== ''): ?><a class="btn" href="cert_export.php">ล้าง</a><?php endif; ?>
  </div>
</form>
</div>
<?php if ($q !== ''): ?><div class="alert info">ผลการค้นหา “<?= h($q) ?>” พบ <?= count($list) ?> คน</div><?php endif; ?>

<div class="card">
  <div class="adminbar" style="margin-bottom:8px">
    <div>
      <label class="tiny"><input type="checkbox" id="onlyEval"> เฉพาะคนที่ทำแบบประเมินแล้ว</label>
      <p class="muted" style="margin:4px 0 0">เลือกแล้ว <b id="cnt">0</b> คน</p>
    </div>
    <div class="actions">
      <button class="btn primary" id="btnPdf" type="button">ดาวน์โหลดรวมเป็น PDF ไฟล์เดียว</button>
      <button class="btn" id="btnZip" type="button">ดาวน์โหลดแยกรายคน (ZIP)</button>
    </div>
  </div>
  <p id="status" class="muted"></p>
  <div class="progress" id="prog" style="display:none"><div id="bar"></div></div>
  <div class="scroll">
    <table class="tbl" id="tbl">
      <thead><tr><th><input type="checkbox" id="all" checked></th><th>#</th><th>ชื่อ-สกุล</th><th>หน่วยงาน</th><th>คะแนนหลังเรียน</th><th>วันที่บนเกียรติบัตร</th><th>แบบประเมิน</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $i => $r): ?>
        <tr data-eval="<?= $r['eval_done'] ? 1 : 0 ?>">
          <td><input type="checkbox" class="pick" value="<?= $i ?>" checked></td>
          <td><?= $i + 1 ?></td>
          <td><?= h($r['full_name']) ?> <a class="editlink" href="participant.php?id=<?= (int)$r['pid'] ?>&amp;back=<?= h(rawurlencode($backUrl)) ?>">แก้ไขชื่อ</a></td>
          <td><?= h($r['agency']) ?></td>
          <td><?= (int)$r['score'] ?>/<?= (int)$r['total'] ?></td>
          <td><?= h($r['date_th']) ?></td>
          <td><?= $r['eval_done'] ? '<span class="up">ทำแล้ว</span>' : '<span class="muted">ยังไม่ทำ</span>' ?></td>
          <td><?= cert_view_button($r['full_name'], $r['submitted_at']) ?></td>
        </tr>
      <?php endforeach; if (!$list): ?>
        <tr><td colspan="8" class="muted">ไม่พบผู้ผ่านเกณฑ์ที่ตรงกับคำค้นหา</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <canvas id="cv" style="display:none"></canvas>
</div>
<?php endif; ?>

<script src="../assets/vendor/jspdf.umd.min.js"></script>
<script src="../assets/vendor/jszip.min.js"></script>
<script src="../assets/vendor/qrcode.min.js"></script>
<script src="../assets/cert.js?v=<?= @filemtime(__DIR__ . '/../assets/cert.js') ?>"></script>
<script>
(function(){
  var D = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (!D.rows.length) return;
  var picks = [].slice.call(document.querySelectorAll('.pick')), all = document.getElementById('all');
  var onlyEval = document.getElementById('onlyEval'), st = document.getElementById('status');
  var prog = document.getElementById('prog'), bar = document.getElementById('bar'), cv = document.getElementById('cv');
  var btns = [document.getElementById('btnPdf'), document.getElementById('btnZip')];
  var MAXW = 2480, Q = 0.85;   // ขนาด/คุณภาพต่อหน้าเมื่อสร้างหลายใบ (ไฟล์เล็กลง ยังคมชัดสำหรับพิมพ์ A4)

  function chosen(){ return picks.filter(function(c){ return c.checked && !c.closest('tr').hidden; }).map(function(c){ return D.rows[+c.value]; }); }
  function upd(){ document.getElementById('cnt').textContent = chosen().length; }
  all.onchange = function(){ picks.forEach(function(c){ if (!c.closest('tr').hidden) c.checked = all.checked; }); upd(); };
  onlyEval.onchange = function(){ picks.forEach(function(c){ var tr = c.closest('tr'); tr.hidden = onlyEval.checked && tr.dataset.eval !== '1'; }); upd(); };
  picks.forEach(function(c){ c.onchange = upd; }); upd();

  function safe(s){ return String(s).replace(/[\\\/:*?"<>|\s]+/g, '_'); }
  function busy(on){ btns.forEach(function(b){ b.disabled = on; }); prog.style.display = on ? '' : 'none'; }
  function tick(){ return new Promise(function(r){ setTimeout(r, 0); }); }   // ให้หน้าจออัปเดตความคืบหน้า

  function run(mode){
    var list = chosen();
    if (!list.length) { st.textContent = 'ยังไม่ได้เลือกผู้รับเกียรติบัตร'; return; }
    busy(true); st.textContent = 'กำลังเตรียมภาพพื้นหลังและฟอนต์…';
    CQCert.prepare(D.cfg).then(function(img){
      var doc = null, zip = mode === 'zip' ? new JSZip() : null, i = 0;
      function next(){
        if (i >= list.length) return Promise.resolve();
        var r = list[i];
        CQCert.draw(cv, img, D.cfg, r.name, r.date, MAXW, { cert_code: r.code, verify_url: r.verify });
        if (zip) {
          var one = CQCert.newDoc(cv); CQCert.addCanvas(one, cv, true, Q);
          zip.file(String(i + 1).padStart(3, '0') + '_เกียรติบัตร_' + safe(r.name) + '.pdf', one.output('arraybuffer'));
        } else {
          if (!doc) doc = CQCert.newDoc(cv);
          CQCert.addCanvas(doc, cv, i === 0, Q);
        }
        i++; bar.style.width = (i / list.length * 100) + '%'; st.textContent = 'กำลังสร้าง ' + i + ' / ' + list.length + ' : ' + r.name;
        return tick().then(next);
      }
      return next().then(function(){
        var base = 'เกียรติบัตร_' + safe(D.tag) + '_' + list.length + 'คน';
        if (zip) {
          st.textContent = 'กำลังบีบอัดไฟล์ ZIP…';
          return zip.generateAsync({type: 'blob'}).then(function(blob){
            var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = base + '.zip';
            document.body.appendChild(a); a.click(); a.remove(); setTimeout(function(){ URL.revokeObjectURL(a.href); }, 5000);
          });
        }
        doc.save(base + '.pdf');
      });
    }).then(function(){ st.textContent = 'เสร็จแล้ว — ' + list.length + ' ใบ'; })
      .catch(function(e){ st.textContent = 'เกิดข้อผิดพลาด: ' + e.message; })
      .then(function(){ busy(false); });
  }
  btns[0].onclick = function(){ run('pdf'); };
  btns[1].onclick = function(){ run('zip'); };
})();
</script>
<?= cert_modal_assets(false) ?>
<?php page_footer();
