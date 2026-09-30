<?php
/*
 * คัดลอกไฟล์นี้เป็น config.php แล้วแก้ค่าให้ตรงกับเครื่อง/โฮสต์ของคุณ
 * (config.php ถูกยกเว้นไว้ใน .gitignore — อย่า commit รหัสผ่านจริงขึ้น git)
 */
return [
    // ---- ฐานข้อมูล MySQL / MariaDB ----
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'cyber_quiz',      // ระบบสร้างตารางให้อัตโนมัติครั้งแรก
    'db_user' => 'root',
    'db_pass' => '',                // XAMPP ค่าเริ่มต้นคือว่าง

    // ---- รหัสผ่านหน้าผู้ดูแล (admin/) — เปลี่ยนก่อนใช้งานจริง ----
    'admin_password' => 'change-me',

    // ---- ข้อความที่แสดงบนหน้าเว็บ ----
    'org_name'     => 'ชื่อหน่วยงานผู้จัด',
    'course_title' => 'Cyber Security Awareness',

    // ---- การสุ่มข้อสอบ ----
    'questions_per_test' => 10,     // จำนวนข้อที่สุ่มให้แต่ละคน (จากคลังทั้งหมดของชุดนั้น)
    'shuffle_options'    => true,   // สลับลำดับตัวเลือกแบบสุ่ม
    'require_pretest'    => true,   // ต้องทำ Pretest ก่อนจึงทำ Posttest ได้
];
