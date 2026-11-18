<?php
require __DIR__ . '/../inc/bootstrap.php';
$PAGE_ROOT = '../';
if (!is_admin()) redirect('index.php');
$pdo = db();
$proj = admin_project();
$projId = (int)$proj['id'];
$GLOBALS['CQ_PROJECT'] = $proj;

$uploadDir = __DIR__ . '/../uploads';
$errors = [];

$bgInUseElsewhere = function (string $path) use ($pdo, $projId): bool {
    if ($path === '') return false;
    foreach (projects_all() as $ap) {
        if ((int)$ap['id'] === $projId) continue;
        $c = json_decode((string)($ap['cert_settings'] ?? ''), true);
        if (is_array($c) && ($c['cert_bg'] ?? '') === $path) return true;
    }
    return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? 'save';

    if ($act === 'reset_bg') {
        $old = cert_settings($proj)['cert_bg'];
        if ($old !== '' && strpos($old, 'uploads/') === 0 && is_file(__DIR__ . '/../' . $old) && !$bgInUseElsewhere($old)) {
            @unlink(__DIR__ . '/../' . $old);
        }
        save_project_cert_settings($projId, ['cert_bg' => '']);
        $_SESSION['flash'] = 'กลับไปใช้ภาพพื้นหลังตั้งต้นแล้ว';
        redirect('certificate.php');
    }
    if ($act === 'reset_layout') {
        $rst = [];
        foreach (cert_defaults() as $k => $v) if (!in_array($k, ['cert_bg', 'cert_enabled', 'cert_pass_pct'], true)) $rst[$k] = $v;
        save_project_cert_settings($projId, $rst);
        $_SESSION['flash'] = 'คืนค่าตำแหน่งและขนาดตัวอักษรเป็นค่าตั้งต้นแล้ว';
        redirect('certificate.php');
    }

    // ---- บันทึกค่าตั้งค่า ----
    $num = function (string $k, float $min, float $max) {
        $v = (float)str_replace(',', '.', (string)($_POST[$k] ?? ''));
        return (string)max($min, min($max, $v));
    };
    $color = function (string $k) {
        $v = (string)($_POST[$k] ?? '');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : '#1f3864';
    };
    $upd = [
        'cert_enabled'     => !empty($_POST['cert_enabled']) ? '1' : '0',
        'eval_required'    => !empty($_POST['eval_required']) ? '1' : '0',
        'cert_show_course' => !empty($_POST['cert_show_course']) ? '1' : '0',
        'cert_pass_pct'    => $num('cert_pass_pct', 0, 100),
        'cert_date_prefix' => mb_substr((string)($_POST['cert_date_prefix'] ?? ''), 0, 100),
    ];
    foreach (['name', 'date'] as $f) {
        $upd["cert_{$f}_x"]     = $num("cert_{$f}_x", 0, 100);
        $upd["cert_{$f}_y"]     = $num("cert_{$f}_y", 0, 100);
        $upd["cert_{$f}_size"]  = $num("cert_{$f}_size", 0.5, 30);
        $upd["cert_{$f}_color"] = $color("cert_{$f}_color");
    }

    // ---- อัปโหลดภาพพื้นหลัง ----
    $f = $_FILES['bg'] ?? null;
    if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'อัปโหลดไม่สำเร็จ (รหัส ' . (int)$f['error'] . ') — ไฟล์อาจใหญ่เกินค่าที่เซิร์ฟเวอร์อนุญาต (upload_max_filesize = ' . h(ini_get('upload_max_filesize')) . ')';
        } else {
            $info = @getimagesize($f['tmp_name']);
            $ext = $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'][$info[2]] ?? null) : null;
            if (!$ext) {
                $errors[] = 'รองรับเฉพาะไฟล์ภาพ JPG หรือ PNG';
            } elseif ($f['size'] > 15 * 1024 * 1024) {
                $errors[] = 'ไฟล์ใหญ่เกิน 15 MB';
            } else {
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                $name = 'cert_bg_' . date('Ymd_His') . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $name)) {
                    $old = cert_settings($proj)['cert_bg'];
                    if ($old !== '' && strpos($old, 'uploads/') === 0 && is_file(__DIR__ . '/../' . $old) && !$bgInUseElsewhere($old)) {
                        @unlink(__DIR__ . '/../' . $old);
                    }
                    $upd['cert_bg'] = 'uploads/' . $name;
                } else {
                    $errors[] = 'บันทึกไฟล์ไม่ได้ — ตรวจสอบสิทธิ์การเขียนโฟลเดอร์ uploads';
                }
            }
        }
    }
    save_project_cert_settings($projId, $upd);
    $_SESSION['flash'] = $errors ? implode('<br>', $errors) : 'บันทึกการตั้งค่าเกียรติบัตรแล้ว';
    $_SESSION['flash_err'] = (bool)$errors;
    redirect('certificate.php');
}

$flash = $_SESSION['flash'] ?? '';
$flashErr = !empty($_SESSION['flash_err']);
unset($_SESSION['flash'], $_SESSION['flash_err']);
$s = cert_settings($proj);
$js = cert_js_config('../', $proj);
$custom = $s['cert_bg'] !== '' && is_file(__DIR__ . '/../' . $s['cert_bg']);

function numfield(string $label, string $name, string $val, string $step = '0.1', string $unit = '%'): string
{
    return '<label>' . $label . ' <small class="muted">(' . $unit . ')</small><input type="number" name="' . $name . '" value="' . h($val) . '" step="' . $step . '" data-live></label>';
}

page_header('ตั้งค่าเกียรติบัตร', admin_nav('cert'));
?>
<div class="adminbar">
  <h1>ตั้งค่าเกียรติบัตร</h1>
</div>
<?php if ($flash): ?><div class="alert <?= $flashErr ? '' : 'info' ?>"><?= $flash ?></div><?php endif; ?>

<div class="certgrid">
  <form method="post" enctype="multipart/form-data" class="card" id="f">
    <?= csrf_field() ?>
    <fieldset><legend>เงื่อนไข</legend>
      <label><input type="checkbox" name="cert_enabled" value="1" <?= $s['cert_enabled'] === '1' ? 'checked' : '' ?>> เปิดให้ดาวน์โหลดเกียรติบัตร</label>
      <label><input type="checkbox" name="eval_required" value="1" <?= eval_required() ? 'checked' : '' ?>> ต้องทำแบบประเมินการอบรมก่อนดาวน์โหลด</label>
      <label><input type="checkbox" name="cert_show_course" value="1" <?= $s['cert_show_course'] === '1' ? 'checked' : '' ?>> แสดงชื่อหัวข้อการอบรมและหน่วยงานผู้จัดอัตโนมัติ (ดึงจากหน้าตั้งค่า)</label>
      <label>เกณฑ์ผ่าน: คะแนนแบบทดสอบหลังอบรมอย่างน้อย (%)
        <input type="number" name="cert_pass_pct" min="0" max="100" step="1" value="<?= h($s['cert_pass_pct']) ?>"></label>
      <small class="muted">เช่น 60% ของ 10 ข้อ = ต้องได้ 6 คะแนนขึ้นไป · ใช้วันที่ส่ง Posttest เป็นวันที่บนเกียรติบัตร</small>
    </fieldset>

    <fieldset><legend>ภาพพื้นหลัง</legend>
      <p class="muted" style="margin-top:0">ตอนนี้ใช้: <b><?= $custom ? 'ภาพที่อัปโหลดเอง' : 'ภาพตั้งต้นของระบบ' ?></b></p>
      <label>อัปโหลดภาพใหม่ (JPG / PNG)<input type="file" name="bg" id="bg" accept="image/jpeg,image/png"></label>
      <small class="muted">แนะนำ A4 แนวนอน <b>3508 × 2480 px</b> (หรือ 1754 × 1240 px) ออกแบบใน Canva/PowerPoint ได้ เว้นพื้นที่ว่างไว้สำหรับชื่อและวันที่ ระบบจะวาดสองส่วนนี้ทับให้เอง</small>
      <p><a href="../assets/cert_default.jpg" download>ดาวน์โหลดภาพตั้งต้นไปแก้ไข</a></p>
    </fieldset>

    <fieldset><legend>ชื่อผู้รับ</legend>
      <div class="row3"><?= numfield('แนวนอน', 'cert_name_x', $s['cert_name_x']) ?><?= numfield('แนวตั้ง', 'cert_name_y', $s['cert_name_y']) ?><?= numfield('ขนาด', 'cert_name_size', $s['cert_name_size'], '0.1', '% ความสูง') ?></div>
      <label>สี<input type="color" name="cert_name_color" value="<?= h($s['cert_name_color']) ?>" data-live></label>
    </fieldset>

    <fieldset><legend>วันที่</legend>
      <label>ข้อความนำหน้าวันที่<input name="cert_date_prefix" value="<?= h($s['cert_date_prefix']) ?>" data-live></label>
      <div class="row3"><?= numfield('แนวนอน', 'cert_date_x', $s['cert_date_x']) ?><?= numfield('แนวตั้ง', 'cert_date_y', $s['cert_date_y']) ?><?= numfield('ขนาด', 'cert_date_size', $s['cert_date_size'], '0.1', '% ความสูง') ?></div>
      <label>สี<input type="color" name="cert_date_color" value="<?= h($s['cert_date_color']) ?>" data-live></label>
    </fieldset>
    <small class="muted">ตำแหน่งเป็น % ของภาพ: แนวนอน 50 = กึ่งกลาง · แนวตั้งคือระดับเส้นฐานของตัวอักษร (0 = บนสุด, 100 = ล่างสุด)</small>
    <p><button class="btn primary">บันทึกการตั้งค่า</button></p>
  </form>

  <div class="card certprev certbox">
    <label style="text-align:left">ชื่อตัวอย่างสำหรับดูตัวอย่าง<input id="sample" value="นางสาวสมหญิง ตัวอย่างนามสกุลยาวมาก"></label>
    <canvas id="cert" style="display:block;width:100%;max-width:480px;height:auto;margin:0 auto"></canvas>
    <p id="status" class="muted"></p>
    <button class="btn" id="dl" type="button">ทดลองดาวน์โหลด PDF</button>
    <div class="toggles" style="justify-content:center">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_layout"><button class="btn ghost">คืนค่าตำแหน่งตั้งต้น</button></form>
      <?php if ($custom): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_bg"><button class="btn ghost">ใช้ภาพพื้นหลังตั้งต้น</button></form><?php endif; ?>
    </div>
  </div>
</div>

<script src="../assets/vendor/jspdf.umd.min.js"></script>
<script src="../assets/vendor/qrcode.min.js"></script>
<script src="../assets/cert.js?v=<?= @filemtime(__DIR__ . '/../assets/cert.js') ?>"></script>
<script>
(function(){
  var cfg = <?= json_encode($js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var sampleExtra = { cert_code: 'CQ-69-SAMPLE', verify_url: <?= json_encode(verify_url('CQ-69-SAMPLE'), JSON_UNESCAPED_UNICODE) ?> };
  var today = <?= json_encode(thai_date(date('Y-m-d')), JSON_UNESCAPED_UNICODE) ?>;
  var f = document.getElementById('f'), cv = document.getElementById('cert'), st = document.getElementById('status');
  var img = null;
  function val(n){ return f.elements[n].value; }
  function current(){
    var c = Object.assign({}, cfg);
    ['name','date'].forEach(function(k){
      c[k+'_x'] = parseFloat(val('cert_'+k+'_x'))||0; c[k+'_y'] = parseFloat(val('cert_'+k+'_y'))||0;
      c[k+'_size'] = parseFloat(val('cert_'+k+'_size'))||1; c[k+'_color'] = val('cert_'+k+'_color');
    });
    c.date_prefix = val('cert_date_prefix');
    return c;
  }
  function redraw(){ if (img) CQCert.draw(cv, img, current(), document.getElementById('sample').value, today, null, sampleExtra); }
  function load(src){
    st.textContent = 'กำลังโหลดภาพ…';
    CQCert.prepare(Object.assign({}, cfg, {bg: src})).then(function(i){ img = i; st.textContent = 'ขนาดภาพ ' + i.naturalWidth + ' × ' + i.naturalHeight + ' px'; redraw(); })
      .catch(function(e){ st.textContent = e.message; });
  }
  f.addEventListener('input', function(e){ if (e.target.hasAttribute('data-live')) redraw(); });
  document.getElementById('sample').addEventListener('input', redraw);
  document.getElementById('bg').addEventListener('change', function(){ if (this.files[0]) load(URL.createObjectURL(this.files[0])); });
  document.getElementById('dl').onclick = function(){ CQCert.savePdf(cv, 'ตัวอย่างเกียรติบัตร.pdf'); };
  load(cfg.bg);
})();
</script>
<?php page_footer();
