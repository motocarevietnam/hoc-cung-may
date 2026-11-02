<?php
// Cổng API duy nhất của Học cùng Mây. nginx chuyển mọi yêu cầu /api/... vào file này.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../app/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['error' => 'method_not_allowed'], 405);

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$route = preg_replace('#^/api/#', '', $path) ?? '';
$raw = file_get_contents('php://input', false, null, 0, 65536);
$body = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
if (!is_array($body)) $body = [];

$routes = [
    'state'               => 'r_state',
    'chat'                => 'r_chat',
    'quiz'                => 'r_quiz',
    'answer'              => 'r_answer',
    'review_list'         => 'r_review_list',
    'review_answer'       => 'r_review_answer',
    'parent/check'        => 'r_parent_check',
    'parent/report'       => 'r_parent_report',
    'parent/conversation' => 'r_parent_conversation',
    'parent/settings'     => 'r_parent_settings',
    'parent/clear'        => 'r_parent_clear',
];
if (!isset($routes[$route])) out(['error' => 'not_found'], 404);

try {
    if (str_starts_with($route, 'parent/')) require_parent_pin();
    else require_app_pin();
    $routes[$route]($body);
} catch (UpstreamError $e) {
    error_log('[hoc-cung-may] ' . $e->getMessage());
    out(['error' => 'upstream'], 502);
} catch (Throwable $e) {
    error_log('[hoc-cung-may] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'server'], 500);
}

/* ---------------- cho bé ---------------- */

function r_state(array $b): never
{
    out(['nick' => setting('nick'), 'stars' => stars(), 'due' => due_count()]);
}

function subject_or_fail(array $b): string
{
    $s = clean($b['subject'] ?? '', 10);
    if (!isset(SUBJECTS[$s])) out(['error' => 'bad_request'], 400);
    return $s;
}

function r_chat(array $b): never
{
    $subject = subject_or_fail($b);
    $topic = clean($b['topic'] ?? '', 80);
    $text = clean($b['text'] ?? '', 1500);
    if ($text === '') out(['error' => 'bad_request'], 400);
    spend(1);

    $now = time();
    $conv = (int)($b['conv_id'] ?? 0);
    if ($conv > 0) {
        $found = q('SELECT id FROM conversations WHERE id = ? AND subject = ?', [$conv, $subject])->fetchColumn();
        if ($found === false) $conv = 0;
    }
    if ($conv === 0) {
        q('INSERT INTO conversations (subject, topic, started_at, updated_at) VALUES (?, ?, ?, ?)', [$subject, $topic, $now, $now]);
        $conv = (int)db()->lastInsertId();
        log_activity($subject, $topic, 'chat');
    }
    q('INSERT INTO messages (conv_id, role, content, created_at) VALUES (?, \'user\', ?, ?)', [$conv, $text, $now]);

    // Lịch sử lấy từ cơ sở dữ liệu (12 tin gần nhất), gộp các lượt liền nhau cùng vai
    $rows = array_reverse(q('SELECT role, content FROM messages WHERE conv_id = ? ORDER BY id DESC LIMIT 12', [$conv])->fetchAll());
    $messages = [];
    foreach ($rows as $r) {
        $role = $r['role'] === 'assistant' ? 'assistant' : 'user';
        $last = count($messages) - 1;
        if ($last >= 0 && $messages[$last]['role'] === $role) $messages[$last]['content'] .= "\n" . $r['content'];
        else $messages[] = ['role' => $role, 'content' => $r['content']];
    }
    while ($messages && $messages[0]['role'] !== 'user') array_shift($messages);

    $system = chat_system($subject, $topic, setting('nick'), setting('focus'), memory_note($subject));
    $reply = trim(ask_claude((string)cfg('chat_model', 'claude-haiku-5-5'), $system, $messages, 600));
    if ($reply === '') $reply = '…';

    q('INSERT INTO messages (conv_id, role, content, created_at) VALUES (?, \'assistant\', ?, ?)', [$conv, $reply, time()]);
    q('UPDATE conversations SET updated_at = ? WHERE id = ?', [time(), $conv]);

    $asked = (int)q('SELECT COUNT(*) FROM messages WHERE conv_id = ? AND role = \'user\'', [$conv])->fetchColumn();
    $stars = $asked === 3 ? add_stars(1) : stars();
    out(['conv_id' => $conv, 'text' => $reply, 'stars' => $stars]);
}

function r_quiz(array $b): never
{
    $subject = subject_or_fail($b);
    $topic = clean($b['topic'] ?? '', 80);
    spend(3);

    $text = ask_claude(
        (string)cfg('quiz_model', 'claude-sonnet-5-5'),
        'Bạn soạn câu hỏi ôn tập cho học sinh lớp 4. Nội dung luôn phù hợp trẻ em và chính xác. Chỉ trả lời bằng JSON.',
        [['role' => 'user', 'content' => quiz_prompt($subject, $topic, setting('focus'))]],
        2000
    );
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    $data = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;

    $questions = [];
    foreach ((is_array($data['questions'] ?? null) ? $data['questions'] : []) as $item) {
        if (!is_array($item) || !is_string($item['q'] ?? null) || !is_array($item['options'] ?? null)) continue;
        $opts = array_values(array_filter($item['options'], 'is_string'));
        $ans = $item['answer'] ?? null;
        if (count($opts) < 2 || count($opts) > 4 || count($opts) !== count($item['options'])) continue;
        if (!is_int($ans) || $ans < 0 || $ans >= count($opts)) continue;
        $questions[] = [
            'q' => clean($item['q'], 500),
            'options' => array_map(fn($o) => clean($o, 200), $opts),
            'answer' => $ans,
            'explain' => clean($item['explain'] ?? '', 400),
            'hint' => clean($item['hint'] ?? '', 300),
        ];
        if (count($questions) === 5) break;
    }
    if (count($questions) < 3) out(['error' => 'bad_quiz'], 502);

    q('INSERT INTO quizzes (subject, topic, questions, total, created_at) VALUES (?, ?, ?, ?, ?)',
        [$subject, $topic, json_encode($questions, JSON_UNESCAPED_UNICODE), count($questions), time()]);
    $id = (int)db()->lastInsertId();

    // Không gửi đáp án xuống máy: máy chủ chấm điểm
    out(['quiz_id' => $id, 'questions' => array_map(fn($x) => ['q' => $x['q'], 'options' => $x['options'], 'hint' => $x['hint']], $questions)]);
}

function remember_mistake(string $subject, string $topic, array $x): void
{
    $tomorrow = strtotime('tomorrow');
    $existing = q('SELECT id FROM mistakes WHERE subject = ? AND q = ?', [$subject, $x['q']])->fetchColumn();
    if ($existing !== false) {
        q('UPDATE mistakes SET box = 0, due_at = ?, times_wrong = times_wrong + 1, mastered_at = NULL WHERE id = ?', [$tomorrow, $existing]);
    } else {
        q('INSERT INTO mistakes (subject, topic, q, options, answer, explain, hint, due_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$subject, $topic, $x['q'], json_encode($x['options'], JSON_UNESCAPED_UNICODE), $x['answer'], $x['explain'], $x['hint'], $tomorrow, time()]);
    }
}

function r_answer(array $b): never
{
    $quiz = q('SELECT * FROM quizzes WHERE id = ?', [(int)($b['quiz_id'] ?? 0)])->fetch();
    if (!$quiz) out(['error' => 'bad_request'], 400);
    $questions = json_decode($quiz['questions'], true);
    $answers = json_decode($quiz['answers'], true) ?: [];
    $index = (int)($b['index'] ?? -1);
    $picked = (int)($b['picked'] ?? -1);
    if (!isset($questions[$index])) out(['error' => 'bad_request'], 400);
    $x = $questions[$index];

    $key = (string)$index;
    $first = !array_key_exists($key, $answers);
    if ($first) $answers[$key] = $picked;
    $right = $answers[$key] === $x['answer'];

    $stars = stars();
    $bonus = false;
    if ($first) {
        if ($right) $stars = add_stars(1);
        else remember_mistake($quiz['subject'], $quiz['topic'], $x);
        q('UPDATE quizzes SET answers = ? WHERE id = ?', [json_encode($answers), $quiz['id']]);
    }

    $done = count($answers) >= count($questions);
    $score = 0;
    foreach ($questions as $i => $qq) {
        if (array_key_exists((string)$i, $answers) && $answers[(string)$i] === $qq['answer']) $score++;
    }
    if ($done && $first) {
        q('UPDATE quizzes SET score = ?, finished_at = ? WHERE id = ?', [$score, time(), $quiz['id']]);
        log_activity($quiz['subject'], $quiz['topic'], 'quiz', $score, count($questions));
        if ($score >= count($questions) - 1) {
            $stars = add_stars(2);
            $bonus = true;
        }
    }
    out(['right' => $right, 'answer' => $x['answer'], 'explain' => $x['explain'], 'stars' => $stars,
         'done' => $done, 'score' => $score, 'total' => count($questions), 'bonus' => $bonus, 'due' => due_count()]);
}

function r_review_list(array $b): never
{
    $rows = q('SELECT id, subject, topic, q, options, hint FROM mistakes WHERE mastered_at IS NULL AND due_at <= ? ORDER BY due_at, id LIMIT 5', [time()])->fetchAll();
    $items = array_map(fn($r) => [
        'id' => (int)$r['id'], 'subject' => $r['subject'], 'topic' => $r['topic'],
        'q' => $r['q'], 'options' => json_decode($r['options'], true), 'hint' => $r['hint'],
    ], $rows);
    out(['items' => $items]);
}

function r_review_answer(array $b): never
{
    $m = q('SELECT * FROM mistakes WHERE id = ? AND mastered_at IS NULL', [(int)($b['id'] ?? 0)])->fetch();
    if (!$m) out(['error' => 'bad_request'], 400);
    $picked = (int)($b['picked'] ?? -1);
    $right = $picked === (int)$m['answer'];
    $mastered = false;
    $stars = stars();

    if ($right) {
        // Hộp Leitner: đúng thì lên hộp, khoảng cách ôn dài dần 1 → 3 → 7 ngày; đúng ở hộp 3 là thuộc hẳn
        $box = (int)$m['box'] + 1;
        $days = [1 => 1, 2 => 3, 3 => 7][$box] ?? null;
        if ($days === null) {
            q('UPDATE mistakes SET box = ?, mastered_at = ? WHERE id = ?', [$box, time(), $m['id']]);
            $mastered = true;
        } else {
            q('UPDATE mistakes SET box = ?, due_at = ? WHERE id = ?', [$box, strtotime("+{$days} days", strtotime('today')), $m['id']]);
        }
        $stars = add_stars(1);
    } else {
        q('UPDATE mistakes SET box = 0, due_at = ?, times_wrong = times_wrong + 1 WHERE id = ?', [strtotime('tomorrow'), $m['id']]);
    }
    log_activity($m['subject'], $m['topic'], 'review', $right ? 1 : 0, 1);
    out(['right' => $right, 'answer' => (int)$m['answer'], 'explain' => $m['explain'], 'stars' => $stars,
         'mastered' => $mastered, 'due' => due_count()]);
}

/* ---------------- cho ba mẹ ---------------- */

function r_parent_check(array $b): never
{
    out(['ok' => true]);
}

function r_parent_report(array $b): never
{
    $from = date('Y-m-d', strtotime('-13 days', strtotime('today')));
    $perDay = [];
    foreach (q('SELECT day, COUNT(*) AS n FROM activity WHERE day >= ? GROUP BY day', [$from])->fetchAll() as $r) {
        $perDay[$r['day']] = (int)$r['n'];
    }
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days", strtotime('today')));
        $days[] = ['day' => $d, 'n' => $perDay[$d] ?? 0];
    }

    $subjects = q('SELECT subject, COUNT(*) AS quizzes, SUM(score) AS correct, SUM(total) AS total
                   FROM activity WHERE type = \'quiz\' GROUP BY subject')->fetchAll();
    $weak = q('SELECT subject, topic, COUNT(*) AS n FROM mistakes WHERE mastered_at IS NULL
               GROUP BY subject, topic ORDER BY n DESC LIMIT 8')->fetchAll();
    $review = [
        'pending' => (int)q('SELECT COUNT(*) FROM mistakes WHERE mastered_at IS NULL')->fetchColumn(),
        'due' => due_count(),
        'mastered' => (int)q('SELECT COUNT(*) FROM mistakes WHERE mastered_at IS NOT NULL')->fetchColumn(),
    ];
    $convs = q('SELECT c.id, c.subject, c.topic, c.started_at,
                  (SELECT COUNT(*) FROM messages m WHERE m.conv_id = c.id AND m.role = \'user\') AS n,
                  (SELECT content FROM messages m WHERE m.conv_id = c.id AND m.role = \'user\' ORDER BY m.id LIMIT 1) AS preview
                FROM conversations c ORDER BY c.updated_at DESC LIMIT 40')->fetchAll();
    foreach ($convs as &$c) $c['preview'] = mb_substr((string)$c['preview'], 0, 90);
    unset($c);

    out([
        'settings' => ['nick' => setting('nick'), 'focus' => setting('focus')],
        'stars' => stars(),
        'days' => $days,
        'subjects' => $subjects,
        'weak' => $weak,
        'review' => $review,
        'conversations' => $convs,
        'retention_days' => (int)cfg('chat_retention_days', 30),
    ]);
}

function r_parent_conversation(array $b): never
{
    $id = (int)($b['id'] ?? 0);
    $c = q('SELECT id, subject, topic, started_at FROM conversations WHERE id = ?', [$id])->fetch();
    if (!$c) out(['error' => 'bad_request'], 400);
    $msgs = q('SELECT role, content, created_at FROM messages WHERE conv_id = ? ORDER BY id', [$id])->fetchAll();
    out(['conversation' => $c, 'messages' => $msgs]);
}

function r_parent_settings(array $b): never
{
    set_setting('nick', clean($b['nick'] ?? '', 30));
    set_setting('focus', clean($b['focus'] ?? '', 400));
    out(['ok' => true]);
}

function r_parent_clear(array $b): never
{
    $what = (string)($b['what'] ?? '');
    $pdo = db();
    if ($what === 'chats') {
        $pdo->exec('DELETE FROM messages; DELETE FROM conversations;');
    } elseif ($what === 'all') {
        $pdo->exec('DELETE FROM messages; DELETE FROM conversations; DELETE FROM quizzes; DELETE FROM mistakes; DELETE FROM activity;');
        set_setting('stars', '0');
    } else {
        out(['error' => 'bad_request'], 400);
    }
    $pdo->exec('VACUUM');
    out(['ok' => true]);
}
