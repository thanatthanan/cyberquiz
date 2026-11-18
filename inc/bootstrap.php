<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Bangkok');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('CYBERQUIZ');
    session_start();
}
if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    exit('<p style="font-family:sans-serif">ยังไม่มีไฟล์ <b>config.php</b> — คัดลอก <code>config.example.php</code> เป็น <code>config.php</code> แล้วแก้ค่าฐานข้อมูลและรหัสผ่านผู้ดูแล</p>');
}
$CFG = require __DIR__ . '/../config.php';

/* ---------- helpers ---------- */
function cfg(string $k)
{
    global $CFG;
    return $CFG[$k] ?? null;
}
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        fatal('หมดเวลาหรือคำขอไม่ถูกต้อง กรุณากลับไปหน้าก่อนหน้าแล้วลองใหม่');
    }
}
function letters(): array
{
    return ['ก', 'ข', 'ค', 'ง'];
}
function name_key(string $name): string
{
    $s = preg_replace('/\s+/u', '', $name);
    return mb_strtolower((string)$s, 'UTF-8');
}
function is_line_browser(): bool
{
    return stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'Line/') !== false;
}
function generate_ref_code(PDO $pdo): string
{
    $chk = $pdo->prepare("SELECT 1 FROM participants WHERE ref_code=?");
    for ($i = 0; $i < 20; $i++) {
        $code = (string)random_int(100000, 999999);
        $chk->execute([$code]);
        if (!$chk->fetchColumn()) return $code;
    }
    return strtoupper(bin2hex(random_bytes(3)));
}
function fatal(string $msg): void
{
    $GLOBALS['CQ_NO_DB_HEADER'] = true;
    page_header('เกิดข้อผิดพลาด');
    echo '<div class="card"><h2>เกิดข้อผิดพลาด</h2><p>' . $msg . '</p></div>';
    page_footer();
    exit;
}

function course_title(): string
{
    if (!empty($GLOBALS['CQ_NO_DB_HEADER'])) return (string)cfg('course_title');
    $v = trim((string)setting('course_title', ''));
    return $v !== '' ? $v : (string)cfg('course_title');
}
function org_name(): string
{
    if (!empty($GLOBALS['CQ_NO_DB_HEADER'])) return (string)cfg('org_name');
    $v = setting('org_name', null);
    return ($v !== null && trim((string)$v) !== '') ? (string)$v : (string)cfg('org_name');
}

/* ---------- layout ---------- */
function page_header(string $title, string $nav = ''): void
{
    $root = $GLOBALS['PAGE_ROOT'] ?? '';
    $course = h(course_title());
    echo '<!doctype html><html lang="th"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">'
        . '<title>' . h($title) . ' · ' . $course . '</title>'
        . '<link rel="icon" href="' . $root . 'assets/favicon.ico" sizes="any">'
        . '<link rel="icon" type="image/png" sizes="32x32" href="' . $root . 'assets/favicon-32x32.png">'
        . '<link rel="icon" type="image/png" sizes="16x16" href="' . $root . 'assets/favicon-16x16.png">'
        . '<link rel="apple-touch-icon" href="' . $root . 'assets/apple-touch-icon.png">'
        . '<link rel="stylesheet" href="' . $root . 'assets/style.css?v=' . @filemtime(__DIR__ . '/../assets/style.css') . '"></head><body>'
        . '<header class="top"><div class="wrap"><div class="brand"><a class="logo-link" href="' . $root . 'index.php" title="กลับหน้าแรก"><img class="logo" src="' . $root . 'assets/logo.png" alt="สกมช. NCSA"></a>'
        . '<span><b>' . $course . '</b><small>' . h(org_name()) . '</small></span></div></div></header>'
        . $nav
        . '<main class="wrap">';
    if ($root === '' && is_line_browser()) {
        echo '<div class="alert info">คุณกำลังเปิดผ่านแอป LINE — หากต้องการให้ระบบจำชื่ออัตโนมัติและดาวน์โหลดเกียรติบัตรได้สะดวก '
           . 'แนะนำให้กดเมนู <b>⋮</b> แล้วเลือก <b>เปิดในเบราว์เซอร์เริ่มต้น (Safari / Chrome)</b></div>';
    }
}
function page_footer(): void
{
    echo '</main><footer class="wrap foot">SAFE, SECURE, &amp; TRUSTED CYBERSPACE FOR THAILAND</footer></body></html>';
}

/* ---------- database ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', cfg('db_host'), cfg('db_port'));
    try {
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        $pdo = null;
        fatal('เชื่อมต่อฐานข้อมูลไม่ได้ — ตรวจสอบว่าเปิด <b>MySQL</b> ใน XAMPP Control Panel แล้ว และตั้งค่าใน config.php ถูกต้อง<br><small>' . h($e->getMessage()) . '</small>');
    }
    $name = str_replace('`', '', (string)cfg('db_name'));
    // โฮสต์จริงส่วนใหญ่ (เช่น cPanel) ไม่ให้สิทธิ์สร้างฐานข้อมูล — สร้างไว้ก่อนในแผงควบคุมแล้วระบบจะใช้ฐานข้อมูลนั้น
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) { /* ไม่มีสิทธิ์สร้าง — ข้ามไป */ }
    try {
        $pdo->exec("USE `$name`");
    } catch (PDOException $e) {
        fatal('ไม่พบฐานข้อมูล <b>' . h($name) . '</b> หรือผู้ใช้ไม่มีสิทธิ์เข้าถึง — สร้างฐานข้อมูลและกำหนดสิทธิ์ผู้ใช้ในแผงควบคุมโฮสต์ แล้วตรวจชื่อใน config.php');
    }
    install($pdo);
    migrate($pdo);
    return $pdo;
}

/* นำเข้าข้อสอบชุดเพิ่มเติมครั้งเดียว (ไม่ซ้ำข้อเดิม ตรวจจากข้อความคำถาม) */
function migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(50) PRIMARY KEY, v TEXT NOT NULL)
                ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $svCol = $pdo->query("SHOW COLUMNS FROM settings LIKE 'v'")->fetch();
    if ($svCol && stripos((string)$svCol['Type'], 'varchar(200)') !== false) {
        $pdo->exec("ALTER TABLE settings MODIFY COLUMN v TEXT NOT NULL");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS evaluations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        participant_id INT NOT NULL UNIQUE,
        position VARCHAR(100) NOT NULL DEFAULT '',
        position_other VARCHAR(200) NOT NULL DEFAULT '',
        prior_training TINYINT(1) NULL,
        ratings TEXT NOT NULL,
        benefit TEXT, topics TEXT, suggestion TEXT,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    import_question_bank($pdo, 'bank2_imported', 'bank2.php');
    import_question_bank($pdo, 'bank3_ldd_imported', 'bank3_ldd.php');
    sync_question_bank_exact($pdo, 'bank3_ldd_40_v2_synced', 'bank3_ldd.php');

    // เพิ่มคอลัมน์ ref_code สำหรับยืนยันตัวตนข้ามเครื่อง / LINE Browser
    $cols = $pdo->query("SHOW COLUMNS FROM participants")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('ref_code', $cols, true)) {
        $pdo->exec("ALTER TABLE participants ADD COLUMN ref_code VARCHAR(16) NULL AFTER name_key, ADD UNIQUE KEY uq_ref_code (ref_code)");
    }
    $missing = $pdo->query("SELECT id FROM participants WHERE ref_code IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    if ($missing) {
        $upd = $pdo->prepare("UPDATE participants SET ref_code=? WHERE id=?");
        foreach ($missing as $pid) {
            $upd->execute([generate_ref_code($pdo), (int)$pid]);
        }
    }

    // เพิ่มคอลัมน์ attempt_no และ first_score ใน attempts สำหรับรองรับการสอบซ่อม Posttest
    $acols = $pdo->query("SHOW COLUMNS FROM attempts")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('attempt_no', $acols, true)) {
        $pdo->exec("ALTER TABLE attempts ADD COLUMN attempt_no INT NOT NULL DEFAULT 1 AFTER total");
    }
    if (!in_array('first_score', $acols, true)) {
        $pdo->exec("ALTER TABLE attempts ADD COLUMN first_score INT NULL AFTER attempt_no");
    }
    if (!in_array('cert_code', $acols, true)) {
        $pdo->exec("ALTER TABLE attempts ADD COLUMN cert_code VARCHAR(24) NULL AFTER first_score, ADD UNIQUE KEY uq_cert_code (cert_code)");
    }

    // ปรับชื่อแบบทดสอบในฐานข้อมูลเดิมจาก ก่อนเรียน/หลังเรียน เป็น ก่อนอบรม/หลังอบรม
    $pdo->exec("UPDATE tests SET title = REPLACE(REPLACE(title, 'ก่อนเรียน', 'ก่อนอบรม'), 'หลังเรียน', 'หลังอบรม') WHERE title LIKE '%ก่อนเรียน%' OR title LIKE '%หลังเรียน%'");
}

function import_question_bank(PDO $pdo, string $settingKey, string $filename): void
{
    $done = $pdo->prepare("SELECT v FROM settings WHERE k=?");
    $done->execute([$settingKey]);
    if ($done->fetchColumn()) return;
    // คลังข้อสอบจริงไม่อยู่ใน git — ถ้าไม่มีไฟล์ ให้ข้าม (ไม่ตั้ง flag เพื่อให้นำเข้าได้เมื่อวางไฟล์ภายหลัง)
    $bankFile = __DIR__ . '/' . $filename;
    if (!is_file($bankFile)) return;
    $bank = require $bankFile;
    $pdo->beginTransaction();
    $insQ = $pdo->prepare("INSERT INTO questions (test_id,seq,topic,question,opt_a,opt_b,opt_c,opt_d,correct) VALUES (?,?,?,?,?,?,?,?,?)");
    $exists = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE test_id=? AND question=?");
    foreach ($bank as $code => $list) {
        $s = $pdo->prepare("SELECT id FROM tests WHERE code=?");
        $s->execute([$code]);
        $tid = (int)$s->fetchColumn();
        if (!$tid) continue;
        $s = $pdo->prepare("SELECT COALESCE(MAX(seq),0) FROM questions WHERE test_id=?");
        $s->execute([$tid]);
        $seq = (int)$s->fetchColumn();
        foreach ($list as $q) {
            $exists->execute([$tid, $q['question']]);
            if ((int)$exists->fetchColumn() > 0) continue;
            $o = $q['options'];
            $insQ->execute([$tid, ++$seq, $q['topic'], $q['question'], $o[0], $o[1], $o[2], $o[3], $q['correct']]);
        }
    }
    $pdo->prepare("INSERT INTO settings (k,v) VALUES (?, ?)")->execute([$settingKey, date('Y-m-d H:i:s')]);
    $pdo->commit();
}
function sync_question_bank_exact(PDO $pdo, string $settingKey, string $filename): void
{
    $done = $pdo->prepare("SELECT v FROM settings WHERE k=?");
    $done->execute([$settingKey]);
    if ($done->fetchColumn()) return;
    $bankFile = __DIR__ . '/' . $filename;
    if (!is_file($bankFile)) return;
    $bank = require $bankFile;
    if (!is_array($bank)) return;
    $pdo->beginTransaction();
    $delQ = $pdo->prepare("DELETE FROM questions WHERE test_id=?");
    $insQ = $pdo->prepare("INSERT INTO questions (test_id,seq,topic,question,opt_a,opt_b,opt_c,opt_d,correct) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($bank as $code => $list) {
        $s = $pdo->prepare("SELECT id FROM tests WHERE code=?");
        $s->execute([$code]);
        $tid = (int)$s->fetchColumn();
        if (!$tid) continue;
        $delQ->execute([$tid]);
        $seq = 0;
        foreach ($list as $q) {
            $seq++;
            $o = $q['options'];
            $insQ->execute([$tid, $seq, $q['topic'], $q['question'], $o[0], $o[1], $o[2], $o[3], (int)$q['correct']]);
        }
    }
    $pdo->prepare("INSERT INTO settings (k,v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute([$settingKey, date('Y-m-d H:i:s')]);
    $pdo->commit();
}

function install(PDO $pdo): void
{
    if ($pdo->query("SHOW TABLES LIKE 'answers'")->fetch()) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS tests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) NOT NULL UNIQUE,
        title VARCHAR(200) NOT NULL,
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        show_answers TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        test_id INT NOT NULL,
        seq INT NOT NULL,
        topic VARCHAR(200) NOT NULL DEFAULT '',
        question TEXT NOT NULL,
        opt_a TEXT NOT NULL, opt_b TEXT NOT NULL, opt_c TEXT NOT NULL, opt_d TEXT NOT NULL,
        correct TINYINT NOT NULL,
        FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token CHAR(32) NOT NULL UNIQUE,
        full_name VARCHAR(200) NOT NULL,
        agency VARCHAR(200) NOT NULL DEFAULT '',
        name_key VARCHAR(200) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX (name_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        participant_id INT NOT NULL,
        test_id INT NOT NULL,
        score INT NOT NULL,
        total INT NOT NULL,
        duration_sec INT NOT NULL DEFAULT 0,
        submitted_at DATETIME NOT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        UNIQUE KEY uq_once (participant_id, test_id),
        FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
        FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS answers (
        attempt_id INT NOT NULL,
        question_id INT NOT NULL,
        chosen TINYINT NULL,
        is_correct TINYINT(1) NOT NULL,
        PRIMARY KEY (attempt_id, question_id),
        FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ((int)$pdo->query("SELECT COUNT(*) FROM tests")->fetchColumn() === 0) {
        // ใช้ seed.php (ข้อสอบจริง ไม่อยู่ใน git) ถ้าไม่มีใช้ seed.example.php แทน
        $seedFile = is_file(__DIR__ . '/seed.php') ? __DIR__ . '/seed.php' : __DIR__ . '/seed.example.php';
        $seed = require $seedFile;
        $insT = $pdo->prepare("INSERT INTO tests (code,title) VALUES (?,?)");
        $insQ = $pdo->prepare("INSERT INTO questions (test_id,seq,topic,question,opt_a,opt_b,opt_c,opt_d,correct) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($seed as $code => $t) {
            $insT->execute([$code, $t['title']]);
            $tid = (int)$pdo->lastInsertId();
            foreach ($t['questions'] as $i => $q) {
                $o = $q['options'];
                $insQ->execute([$tid, $i + 1, $q['topic'], $q['question'], $o[0], $o[1], $o[2], $o[3], $q['correct']]);
            }
        }
    }
}

/* ---------- data access ---------- */
function tests(): array
{
    return db()->query("SELECT * FROM tests ORDER BY FIELD(code,'pre','post'), id")->fetchAll();
}
function test_by_code(string $code): ?array
{
    $s = db()->prepare("SELECT * FROM tests WHERE code=?");
    $s->execute([$code]);
    return $s->fetch() ?: null;
}
function questions(int $testId): array
{
    $s = db()->prepare("SELECT * FROM questions WHERE test_id=? ORDER BY seq, id");
    $s->execute([$testId]);
    return $s->fetchAll();
}
function q_options(array $q): array
{
    return [$q['opt_a'], $q['opt_b'], $q['opt_c'], $q['opt_d']];
}
function current_participant(): ?array
{
    $tok = $_COOKIE['cq_token'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) return null;
    $s = db()->prepare("SELECT * FROM participants WHERE token=?");
    $s->execute([$tok]);
    return $s->fetch() ?: null;
}
function attempt_of(int $pid, int $tid): ?array
{
    $s = db()->prepare("SELECT * FROM attempts WHERE participant_id=? AND test_id=?");
    $s->execute([$pid, $tid]);
    return $s->fetch() ?: null;
}
function is_admin(): bool
{
    return !empty($_SESSION['is_admin']);
}
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ---------- settings (ตาราง settings) ---------- */
function setting(string $k, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = db()->query("SELECT k, v FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return array_key_exists($k, $cache) ? $cache[$k] : $default;
}
function set_setting(string $k, string $v): void
{
    db()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute([$k, $v]);
}

/* ---------- เกียรติบัตร ---------- */
function thai_date(string $datetime): string
{
    $m = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    $t = strtotime($datetime);
    return (int)date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' พ.ศ. ' . ((int)date('Y', $t) + 543);
}
function cert_defaults(): array
{
    return [
        'cert_enabled' => '1', 'cert_pass_pct' => '60', 'cert_bg' => '', 'cert_show_course' => '1',
        'cert_name_x' => '50', 'cert_name_y' => '57.7', 'cert_name_size' => '6', 'cert_name_color' => '#1f3864',
        'cert_date_x' => '50', 'cert_date_y' => '76.5', 'cert_date_size' => '2.6', 'cert_date_color' => '#1f3864',
        'cert_date_prefix' => 'ให้ไว้ ณ วันที่ ',
    ];
}
function cert_settings(): array
{
    $out = [];
    foreach (cert_defaults() as $k => $d) $out[$k] = (string)setting($k, $d);
    return $out;
}
/** ค่าที่ส่งให้ JavaScript วาดเกียรติบัตร ($root = '' หรือ '../') */
function cert_js_config(string $root = ''): array
{
    $s = cert_settings();
    $bg = $s['cert_bg'] !== '' && is_file(__DIR__ . '/../' . $s['cert_bg']) ? $s['cert_bg'] : 'assets/cert_default.jpg';
    return [
        'bg' => $root . $bg . '?v=' . @filemtime(__DIR__ . '/../' . $bg),
        'name_x' => (float)$s['cert_name_x'], 'name_y' => (float)$s['cert_name_y'], 'name_size' => (float)$s['cert_name_size'], 'name_color' => $s['cert_name_color'],
        'date_x' => (float)$s['cert_date_x'], 'date_y' => (float)$s['cert_date_y'], 'date_size' => (float)$s['cert_date_size'], 'date_color' => $s['cert_date_color'],
        'date_prefix' => $s['cert_date_prefix'],
        'course_title' => $s['cert_show_course'] === '1' ? course_title() : '',
        'course_x' => 50.0, 'course_y' => 70.1, 'course_size' => 3.5, 'course_color' => '#1f3864',
        'org_name' => $s['cert_show_course'] === '1' ? org_name() : '',
        'org_x' => 50.0, 'org_y' => 83.5, 'org_size' => 2.9, 'org_color' => '#1f3864',
    ];
}
/** ผ่านเกณฑ์รับเกียรติบัตรหรือไม่ (ใช้ผล Posttest) */
function cert_passed(array $attempt): bool
{
    if (setting('cert_enabled', '1') !== '1') return false;
    if (($attempt['code'] ?? '') !== 'post' || (int)$attempt['total'] <= 0) return false;
    return ((int)$attempt['score'] / (int)$attempt['total'] * 100) >= (float)setting('cert_pass_pct', '60');
}
function cert_min_score(int $total): int
{
    return (int)ceil($total * (float)setting('cert_pass_pct', '60') / 100 - 1e-9);
}
function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    if (($GLOBALS['PAGE_ROOT'] ?? '') === '../') {
        $dir = preg_replace('#/[^/]+$#', '', $dir);
    }
    return $scheme . '://' . $host . $dir . '/';
}
function verify_url(string $code): string
{
    return base_url() . 'verify.php?c=' . rawurlencode($code);
}
function cert_code_for(PDO $pdo, int $attemptId): string
{
    $s = $pdo->prepare("SELECT cert_code, submitted_at FROM attempts WHERE id=?");
    $s->execute([$attemptId]);
    $r = $s->fetch();
    if (!$r) return '';
    if (!empty($r['cert_code'])) return (string)$r['cert_code'];
    $yr = substr((string)((int)date('Y', strtotime((string)$r['submitted_at'])) + 543), -2);
    $upd = $pdo->prepare("UPDATE attempts SET cert_code=? WHERE id=? AND cert_code IS NULL");
    for ($i = 0; $i < 10; $i++) {
        $code = 'CQ-' . $yr . '-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $upd->execute([$code, $attemptId]);
            return $code;
        } catch (PDOException $e) {
            // ชนรหัสเดิม สุ่มใหม่
        }
    }
    return 'CQ-' . $yr . '-' . str_pad((string)$attemptId, 6, '0', STR_PAD_LEFT);
}
function post_max_attempts(): int
{
    return max(0, (int)setting('post_max_attempts', '3'));
}
function can_retake_post(array $attempt): bool
{
    if (($attempt['code'] ?? '') !== 'post') return false;
    if (setting('cert_enabled', '1') !== '1') return false;
    if (cert_passed($attempt)) return false;
    $max = post_max_attempts();
    if ($max === 1) return false;
    if ($max === 0) return true;
    return (int)($attempt['attempt_no'] ?? 1) < $max;
}

/* ---------- แบบประเมินการอบรม ---------- */
function eval_items(): array
{
    static $items = null;
    return $items ?? ($items = require __DIR__ . '/eval_items.php');
}
function eval_rating_keys(): array
{
    $keys = [];
    foreach (eval_items()['ratings'] as $group) foreach ($group as $k => $label) $keys[$k] = $label;
    return $keys;
}
/** ต้องทำแบบประเมินก่อนรับเกียรติบัตรหรือไม่ */
function eval_required(): bool
{
    return setting('eval_required', '1') === '1';
}
function eval_done(int $participantId): bool
{
    $s = db()->prepare("SELECT 1 FROM evaluations WHERE participant_id=?");
    $s->execute([$participantId]);
    return (bool)$s->fetchColumn();
}

/** สคริปต์ป๊อปอัปดูเกียรติบัตรในหน้าผู้ดูแล ($withLibs=false เมื่อหน้าโหลด jspdf/cert.js เองแล้ว) */
function cert_modal_assets(bool $withLibs = true): string
{
    $v = fn($f) => @filemtime(__DIR__ . '/../assets/' . $f);
    $out = '';
    if ($withLibs) $out .= '<script src="../assets/vendor/jspdf.umd.min.js"></script><script src="../assets/vendor/qrcode.min.js"></script><script src="../assets/cert.js?v=' . $v('cert.js') . '"></script>';
    return $out . '<script>window.CQ_CERT_CFG=' . json_encode(cert_js_config('../'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
        . '<script src="../assets/cert_modal.js?v=' . $v('cert_modal.js') . '"></script>';
}
/** ปุ่มเปิดป๊อปอัปเกียรติบัตร */
function cert_view_button(string $name, string $datetime, string $label = 'ดู', string $cls = 'btn tiny', string $certCode = ''): string
{
    $attr = 'data-cert-name="' . h($name) . '" data-cert-date="' . h(thai_date($datetime)) . '"';
    if ($certCode !== '') {
        $attr .= ' data-cert-code="' . h($certCode) . '" data-cert-verify="' . h(verify_url($certCode)) . '"';
    }
    return '<button type="button" class="' . h($cls) . '" ' . $attr . '>' . h($label) . '</button>';
}

/* ---------- เมนูผู้ดูแล (navbar แบบ dropdown) ---------- */
function admin_nav(string $active = ''): string
{
    $tests = [];
    try { $tests = tests(); } catch (Throwable $e) { }
    $menu = [
        'dash' => ['แดชบอร์ด', 'index.php', null],
        'quiz' => ['ข้อสอบ', null, array_map(fn($t) => ['จัดการ' . $t['title'], 'questions.php?test=' . rawurlencode($t['code'])], $tests)],
        'cert' => ['เกียรติบัตร', null, [
            ['ตั้งค่าเกียรติบัตร', 'certificate.php'],
            ['ส่งออกเกียรติบัตร', 'cert_export.php'],
        ]],
        'data' => ['ดาวน์โหลด', null, [
            ['ผลคะแนนรายคน (CSV)', 'export.php?type=summary'],
            ['คำตอบรายข้อ (CSV)', 'export.php?type=answers'],
            ['ผลแบบประเมิน (CSV)', 'export.php?type=eval'],
        ]],
        'links' => ['ลิงก์ผู้เข้าร่วม', null, [
            ['เปิดหน้าแบบทดสอบก่อนอบรม', '../pretest.php', true],
            ['เปิดหน้าแบบทดสอบหลังอบรม', '../posttest.php', true],
        ]],
    ];
    $o = '<nav class="adminnav" aria-label="เมนูผู้ดูแล"><div class="wrap"><ul class="nav-main">';
    foreach ($menu as $k => [$label, $href, $items]) {
        $on = $k === $active ? ' on' : '';
        if ($items === null) {
            $o .= '<li><a class="navlink' . $on . '" href="' . h($href) . '">' . h($label) . '</a></li>';
            continue;
        }
        $o .= '<li><details class="dd"><summary class="navlink' . $on . '">' . h($label) . '</summary><div class="dd-menu">';
        foreach ($items as $it) {
            $o .= '<a href="' . h($it[1]) . '"' . (!empty($it[2]) ? ' target="_blank" rel="noopener"' : '') . '>' . h($it[0]) . (!empty($it[2]) ? ' ↗' : '') . '</a>';
        }
        $o .= '</div></details></li>';
    }
    $o .= '</ul><ul class="nav-side"><li><a class="navlink" href="../index.php">← หน้าแรก</a></li><li><details class="dd dd-right"><summary class="navlink">ผู้ดูแล</summary><div class="dd-menu">'
        . '<form method="post" action="index.php">' . csrf_field() . '<input type="hidden" name="action" value="logout">'
        . '<button type="submit">ออกจากระบบ</button></form></div></details></li></ul></div>'
        . '<script>(function(){var dd=[].slice.call(document.querySelectorAll(".adminnav details"));'
        . 'dd.forEach(function(d){d.addEventListener("toggle",function(){if(d.open)dd.forEach(function(x){if(x!==d)x.open=false;});});});'
        . 'document.addEventListener("click",function(e){if(!e.target.closest(".adminnav details"))dd.forEach(function(x){x.open=false;});});'
        . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")dd.forEach(function(x){x.open=false;});});})();</script></nav>';
    return $o;
}
