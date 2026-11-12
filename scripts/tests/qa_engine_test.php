<?php
declare(strict_types=1);
// Self-contained tests for lib_qa_engine.php. Needs an EMPTY scratch database the app user can use:
//   QA_TEST_DSN='mysql:host=127.0.0.1;dbname=hcm_qa_test;charset=utf8mb4' QA_TEST_USER=.. QA_TEST_PASS=.. php scripts/tests/qa_engine_test.php
require dirname(__DIR__) . '/lib_qa_engine.php';
$pdo = new PDO(getenv('QA_TEST_DSN') ?: '', getenv('QA_TEST_USER') ?: '', getenv('QA_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!str_contains((string)$pdo->query('SELECT DATABASE()')->fetchColumn(), 'test')) exit("refusing: database name must contain 'test'\n");
foreach (['question_bank', 'question_qa_reviews', 'question_qa_cases'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("CREATE TABLE question_bank (id BIGINT UNSIGNED PRIMARY KEY, subject VARCHAR(10), topic VARCHAR(255) DEFAULT '', q TEXT, options TEXT, answer TINYINT UNSIGNED, `explain` TEXT, hint TEXT, fingerprint CHAR(64), status VARCHAR(16), used_count INT DEFAULT 0, created_at INT UNSIGNED DEFAULT 0, approved_by VARCHAR(32) NULL, UNIQUE KEY(subject,fingerprint)) DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE question_qa_reviews (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question_id BIGINT UNSIGNED, reviewer VARCHAR(20), verdict VARCHAR(24), predicted_answer INT NULL, reason TEXT, model VARCHAR(80), reviewed_at INT UNSIGNED) DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE question_qa_cases (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question_id BIGINT UNSIGNED, status VARCHAR(20), reason VARCHAR(500), reported_by VARCHAR(32), resolution TEXT NULL, resolved_by VARCHAR(32) NULL, created_at INT UNSIGNED, resolved_at INT UNSIGNED NULL) DEFAULT CHARSET=utf8mb4");
function add(PDO $p, int $id, string $status, ?string $by = null, string $q = ''): string {
    $q = $q ?: "Câu $id: 2 + $id = ?"; $o = [(string)(2 + $id), 'x', 'y', 'z'];
    $p->prepare('INSERT INTO question_bank (id,subject,q,options,answer,`explain`,hint,fingerprint,status,approved_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$id, 'toan', $q, json_encode($o, JSON_UNESCAPED_UNICODE), 0, 'e', 'h', qa_fingerprint($q), $status, $by]);
    return qa_content_hash($q, $o, 0);
}
function d(int $id, string $h, string $dec, array $x = []): array {
    return $x + ['question_id' => $id, 'expected_hash' => $h, 'reviewer' => 'claude', 'model' => 'test', 'verdict' => 'pass', 'predicted_answer' => 0, 'decision' => $dec, 'reason' => 'r'];
}
function st(PDO $p, int $id): array { return $p->query("SELECT status,approved_by FROM question_bank WHERE id=$id")->fetch(); }
$fail = 0; function ok(bool $c, string $m) { global $fail; echo ($c ? 'PASS ' : 'FAIL ') . "$m\n"; if (!$c) $fail++; }

$h1 = add($pdo, 1, 'pending'); $h2 = add($pdo, 2, 'pending'); $h3 = add($pdo, 3, 'needs_review');
$h4 = add($pdo, 4, 'approved', 'parent'); $h5 = add($pdo, 5, 'needs_review'); $h6 = add($pdo, 6, 'pending');
$h7 = add($pdo, 7, 'approved', 'claude_qa'); $h8 = add($pdo, 8, 'pending'); $h9 = add($pdo, 9, 'pending');
$pdo->exec("INSERT INTO question_qa_cases(question_id,status,reason,reported_by,resolved_by,created_at) VALUES (5,'resolved','x','parent','parent',0)");

// dry run changes nothing
$s = qa_apply_all($pdo, [d(1, $h1, 'approve')], false, 1);
ok(st($pdo, 1)['status'] === 'pending' && count($s['approved']) === 1, 'dry run rolls back');
// approve pass + matching answer
qa_apply_all($pdo, [d(1, $h1, 'approve')], true, 1);
ok(st($pdo, 1) === ['status' => 'approved', 'approved_by' => 'claude_qa'], 'approve -> approved/claude_qa');
// idempotent re-run
$s = qa_apply_all($pdo, [d(1, $h1, 'approve')], true, 2);
ok(count($s['skipped']) === 1 && (int)$pdo->query('SELECT COUNT(*) FROM question_qa_reviews WHERE question_id=1')->fetchColumn() === 1, 'rerun is skipped, no duplicate review');
// predicted != answer never approves
qa_apply_all($pdo, [d(2, $h2, 'approve', ['predicted_answer' => 1])], true, 1);
ok(st($pdo, 2)['status'] === 'needs_review', 'answer mismatch -> needs_review');
// reject as duplicate of approved question
qa_apply_all($pdo, [d(3, $h3, 'reject', ['duplicate_of' => 1, 'category' => 'B structure'])], true, 1);
ok(st($pdo, 3)['status'] === 'rejected' && (int)$pdo->query('SELECT COUNT(*) FROM question_qa_cases WHERE question_id=3')->fetchColumn() === 1, 'reject duplicate -> rejected + case');
// reject without fail/duplicate_of is invalid and rolls back the batch
$s = qa_apply_all($pdo, [d(6, $h6, 'approve'), d(8, $h8, 'reject')], true, 1);
ok($s['error'] && st($pdo, 6)['status'] === 'pending', 'invalid reject rolls back whole batch');
// duplicate_of must be approved
$s = qa_apply_all($pdo, [d(8, $h8, 'reject', ['duplicate_of' => 2])], true, 1);
ok($s['error'] && st($pdo, 8)['status'] === 'pending', 'duplicate_of non-approved refused');
// parent decisions untouched
qa_apply_all($pdo, [d(4, $h4, 'reject', ['verdict' => 'fail'])], true, 1);
ok(st($pdo, 4) === ['status' => 'approved', 'approved_by' => 'parent'], 'parent-approved row not overwritten');
qa_apply_all($pdo, [d(5, $h5, 'approve')], true, 1);
ok(st($pdo, 5)['status'] === 'needs_review', 'parent-resolved case not overwritten');
// already approved by claude: skipped
$s = qa_apply_all($pdo, [d(7, $h7, 'reject', ['verdict' => 'fail'])], true, 1);
ok(st($pdo, 7)['status'] === 'approved' && count($s['skipped']) === 1, 'approved row skipped');
// stale hash refused
$s = qa_apply_all($pdo, [d(6, str_repeat('0', 64), 'approve')], true, 1);
ok($s['error'] && st($pdo, 6)['status'] === 'pending', 'stale content hash refused');
// fix: archives old content, recomputes fingerprint, approves after recheck
$s = qa_apply_all($pdo, [d(9, $h9, 'fix', ['verdict' => 'fail', 'fix' => ['q' => 'Câu 9 đã sửa: 2 + 9 = ?', 'hint' => 'mới'], 'post_fix_predicted' => 0])], true, 1);
$r = $pdo->query('SELECT q,hint,fingerprint,status,approved_by FROM question_bank WHERE id=9')->fetch();
$case = json_decode((string)$pdo->query("SELECT resolution FROM question_qa_cases WHERE question_id=9 AND reason='claude_fix'")->fetchColumn(), true);
ok($r['status'] === 'approved' && $r['hint'] === 'mới' && $r['fingerprint'] === qa_fingerprint($r['q']) && $case['old']['q'] === 'Câu 9: 2 + 9 = ?', 'fix archived + fingerprint + approved');
$s = qa_apply_all($pdo, [d(9, $h9, 'fix', ['verdict' => 'fail', 'fix' => ['hint' => 'x'], 'post_fix_predicted' => 0])], true, 2);
ok(count($s['skipped']) === 1, 'fix rerun skipped');
// fix colliding fingerprint refused
$s = qa_apply_all($pdo, [d(6, $h6, 'fix', ['verdict' => 'fail', 'fix' => ['q' => 'Câu 1: 2 + 1 = ?'], 'post_fix_predicted' => 0])], true, 1);
ok($s['error'] && st($pdo, 6)['status'] === 'pending', 'fix fingerprint collision refused');
// two-phase fix: no post_fix_predicted -> back to pending; approval only via a fresh review of the new content
$h10 = add($pdo, 10, 'pending');
$s = qa_apply_all($pdo, [d(10, $h10, 'fix', ['verdict' => 'fail', 'fix' => ['q' => 'Câu 10 đã sửa: 2 + 10 = ?']])], true, 1);
ok(st($pdo, 10) === ['status' => 'pending', 'approved_by' => null] && count($s['pending']) === 1, 'two-phase fix -> pending, not approved');
$s = qa_apply_all($pdo, [d(10, $h10, 'approve')], true, 2);
ok($s['error'] && st($pdo, 10)['status'] === 'pending', 'old-hash approval after fix refused');
$r10 = $pdo->query('SELECT q,options,answer FROM question_bank WHERE id=10')->fetch();
$nh = qa_content_hash($r10['q'], json_decode($r10['options'], true), (int)$r10['answer']);
qa_apply_all($pdo, [d(10, $nh, 'approve')], true, 3);
ok(st($pdo, 10) === ['status' => 'approved', 'approved_by' => 'claude_qa'], 'recheck of new content approves');
// approve with verdict uncertain invalid
ok(qa_validate_item(d(6, $h6, 'approve', ['verdict' => 'uncertain'])) !== null, 'approve requires pass');
foreach (['question_bank', 'question_qa_reviews', 'question_qa_cases'] as $t) $pdo->exec("DROP TABLE $t");
echo $fail ? "$fail FAILED\n" : "ALL PASSED\n"; exit($fail ? 1 : 0);
