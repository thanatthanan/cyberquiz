<?php
require __DIR__ . '/inc/bootstrap.php';

$tests = tests();
page_header('หน้าหลัก');
?>
<div class="card hero center">
  <h1><?= h(course_title()) ?></h1>
  <p class="muted" style="margin-bottom:4px"><?= h(org_name()) ?></p>
  <p class="muted" style="margin:0">กรุณาเลือกแบบทดสอบที่ต้องการทำด้านล่าง</p>
</div>

<div class="grid two">
  <?php foreach ($tests as $t):
    $isPost = ($t['code'] === 'post');
    $href = $isPost ? 'posttest.php' : 'pretest.php';
  ?>
    <div class="card center" style="display:flex;flex-direction:column;justify-content:space-between;gap:12px">
      <div>
        <span class="badge <?= $isPost ? 'post' : 'pre' ?>"><?= $isPost ? 'หลังอบรม (Post-test)' : 'ก่อนอบรม (Pre-test)' ?></span>
        <div style="margin:4px 0 8px">
          <?= $t['is_open'] ? '<small class="up">● เปิดให้ทำอยู่</small>' : '<small class="muted">○ ยังไม่เปิดให้ทำ</small>' ?>
        </div>
        <h2 style="margin:0 0 6px;font-size:21px;color:var(--navy)"><?= h($t['title']) ?></h2>
        <p class="muted" style="margin:0"><small><?= $isPost
            ? 'สำหรับผู้ที่ผ่านการอบรมแล้ว และต้องการรับเกียรติบัตร'
            : 'ทำก่อนเริ่มการบรรยาย เพื่อวัดระดับความรู้พื้นฐาน' ?></small></p>
      </div>
      <div>
        <a class="btn <?= $t['is_open'] ? 'primary' : 'off' ?>" style="width:100%;justify-content:center" href="<?= h($href) ?>">
          <?= $isPost ? 'เข้าทำแบบทดสอบหลังอบรม →' : 'เข้าทำแบบทดสอบก่อนอบรม →' ?>
        </a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
  <a class="btn" href="verify.php">ตรวจสอบเกียรติบัตร</a>
  <a class="btn ghost" href="admin/">สำหรับผู้ดูแลระบบ →</a>
</div>
<?php page_footer();
