<?php
require __DIR__ . '/../inc/bootstrap.php';
$PAGE_ROOT = '../';
if (!is_admin()) redirect('index.php');
$pdo = db();
$proj = admin_project();
$projId = (int)$proj['id'];
$GLOBALS['CQ_PROJECT'] = $proj;

$pre = test_by_code('pre', $projId);
$post = test_by_code('post', $projId);
$type = $_GET['type'] ?? 'summary';
$L = letters();

$fname = ['answers' => 'answers', 'eval' => 'evaluation'][$type] ?? 'results';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="cyberquiz_' . $fname . '_' . date('Ymd_Hi') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM ให้ Excel อ่านภาษาไทยได้

if ($type === 'eval') {
    // ไม่ระบุชื่อผู้ตอบ ตามที่แจ้งไว้ในแบบประเมิน
    $E = eval_items();
    $keys = eval_rating_keys();
    $head = ['ลำดับ', 'วันเวลาที่ตอบ', 'ตำแหน่งงาน', 'ตำแหน่ง (อื่น ๆ)', 'เคยอบรมด้านไซเบอร์'];
    $n = 0;
    foreach ($keys as $k => $label) $head[] = (++$n) . '. ' . $label;
    foreach ($E['comments'] as $k => $label) $head[] = $label;
    fputcsv($out, $head);
    $i = 0;
    $evStmt = $pdo->prepare("SELECT e.* FROM evaluations e JOIN participants p ON p.id=e.participant_id WHERE p.project_id=? ORDER BY e.created_at");
    $evStmt->execute([$projId]);
    foreach ($evStmt as $e) {
        $r = (array)json_decode($e['ratings'], true);
        $row = [++$i, $e['created_at'], $e['position'], $e['position_other'], $e['prior_training'] === null ? '' : ($e['prior_training'] ? 'เคย' : 'ไม่เคย')];
        foreach ($keys as $k => $label) $row[] = $r[$k] ?? '';
        foreach ($E['comments'] as $k => $label) $row[] = $e[$k];
        fputcsv($out, $row);
    }
} elseif ($type === 'answers') {
    fputcsv($out, ['ชื่อ-สกุล', 'หน่วยงาน', 'แบบทดสอบ', 'ข้อ', 'หัวข้อ', 'คำตอบที่เลือก', 'คำตอบที่ถูก', 'ผล']);
    $s = $pdo->prepare("SELECT p.full_name, p.agency, t.title, q.seq, q.topic, q.opt_a, q.opt_b, q.opt_c, q.opt_d, q.correct, x.chosen, x.is_correct
        FROM answers x JOIN attempts a ON a.id=x.attempt_id JOIN participants p ON p.id=a.participant_id
        JOIN tests t ON t.id=a.test_id JOIN questions q ON q.id=x.question_id
        WHERE t.project_id=?
        ORDER BY p.full_name, FIELD(t.code,'pre','post'), q.seq");
    $s->execute([$projId]);
    foreach ($s as $r) {
        $o = [$r['opt_a'], $r['opt_b'], $r['opt_c'], $r['opt_d']];
        fputcsv($out, [$r['full_name'], $r['agency'], $r['title'], $r['seq'], $r['topic'],
            $r['chosen'] === null ? '' : $o[(int)$r['chosen']], $o[(int)$r['correct']], $r['is_correct'] ? 'ถูก' : 'ผิด']);
    }
} else {
    fputcsv($out, ['ชื่อ-สกุล', 'หน่วยงาน', 'คะแนนก่อนอบรม', 'เต็ม', 'เวลาส่งก่อนอบรม', 'คะแนนหลังอบรม', 'เต็ม', 'เวลาส่งหลังอบรม', 'เปลี่ยนแปลง']);
    $s = $pdo->prepare("SELECT p.full_name, p.agency, b.score bs, b.total bt, b.submitted_at ba, c.score cs, c.total ct, c.submitted_at ca
        FROM participants p
        LEFT JOIN attempts b ON b.participant_id=p.id AND b.test_id=?
        LEFT JOIN attempts c ON c.participant_id=p.id AND c.test_id=?
        WHERE p.project_id=?
        ORDER BY p.agency, p.full_name");
    $s->execute([(int)($pre['id'] ?? 0), (int)($post['id'] ?? 0), $projId]);
    foreach ($s as $r) {
        $d = ($r['bs'] !== null && $r['cs'] !== null) ? (int)$r['cs'] - (int)$r['bs'] : '';
        fputcsv($out, [$r['full_name'], $r['agency'], $r['bs'], $r['bt'], $r['ba'], $r['cs'], $r['ct'], $r['ca'], $d]);
    }
}
fclose($out);
