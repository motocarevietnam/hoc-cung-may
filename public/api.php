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
// Ảnh bài tập cần nhiều chỗ hơn (tối đa 4 ảnh đã thu nhỏ trên máy); mọi yêu cầu khác giới hạn 64 KB
$maxBody = $route === 'homework' ? 9 * 1024 * 1024 : 65536;
$raw = file_get_contents('php://input', false, null, 0, $maxBody);
$body = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
if (!is_array($body)) $body = [];

$routes = [
    'state'               => 'r_state',
    'chat'                => 'r_chat',
    'quiz'                => 'r_quiz',
    'answer'              => 'r_answer',
    'review_list'         => 'r_review_list',
    'review_answer'       => 'r_review_answer',
    'homework'            => 'r_homework',
    'homework_list'       => 'r_homework_list',
    'parent/check'        => 'r_parent_check',
    'parent/report'       => 'r_parent_report',
    'parent/conversation' => 'r_parent_conversation',
    'parent/homework'     => 'r_parent_homework',
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

/* ---------------- chấm bài tập về nhà ---------------- */

// Nhận ảnh dạng data URL (đã thu nhỏ trên máy), kiểm tra đúng là ảnh rồi mới gửi cho Claude.
// Ảnh KHÔNG được lưu trên máy chủ, chỉ lưu kết quả chấm.
function homework_images(mixed $list): array
{
    if (!is_array($list) || count($list) < 1 || count($list) > 4) out(['error' => 'bad_photos'], 400);
    $blocks = [];
    foreach ($list as $item) {
        if (!is_string($item) || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $item, $m)) {
            out(['error' => 'bad_photos'], 400);
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 3_500_000) out(['error' => 'bad_photos'], 400);
        $info = @getimagesizefromstring($bin);
        $types = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
        if (!$info || $info[2] !== $types[$m[1]] || $info[0] < 200 || $info[1] < 200) out(['error' => 'bad_photos'], 400);
        $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $m[1], 'data' => $m[2]]];
    }
    return $blocks;
}

function clean_practice(mixed $p): ?array
{
    if (!is_array($p) || !is_string($p['q'] ?? null) || !is_array($p['options'] ?? null)) return null;
    $opts = array_values(array_filter($p['options'], 'is_string'));
    $ans = $p['answer'] ?? null;
    if (count($opts) < 2 || count($opts) > 4 || count($opts) !== count($p['options'])) return null;
    if (!is_int($ans) || $ans < 0 || $ans >= count($opts)) return null;
    return [
        'q' => clean($p['q'], 500),
        'options' => array_map(fn($o) => clean($o, 200), $opts),
        'answer' => $ans,
        'explain' => clean($p['explain'] ?? '', 400),
        'hint' => clean($p['hint'] ?? '', 300),
    ];
}

function r_homework(array $b): never
{
    $subject = subject_or_fail($b);
    $note = clean($b['note'] ?? '', 300);
    $images = homework_images($b['photos'] ?? null);

    $previous = null;
    $recheckOf = (int)($b['recheck_of'] ?? 0);
    if ($recheckOf > 0) {
        $row = q('SELECT result FROM homework WHERE id = ? AND subject = ?', [$recheckOf, $subject])->fetchColumn();
        $previous = $row !== false ? json_decode((string)$row, true) : null;
        if (!is_array($previous)) { $previous = null; $recheckOf = 0; }
    } else {
        $recheckOf = 0;
    }
    spend(5);

    $content = $images;
    $content[] = ['type' => 'text', 'text' => homework_prompt($note, count($images), $previous)];
    $text = ask_claude(
        (string)cfg('homework_model', cfg('quiz_model', 'claude-sonnet-5-5')),
        homework_system($subject, setting('nick')),
        [['role' => 'user', 'content' => $content]],
        4000,
        140
    );
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    $data = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
    if (!is_array($data)) out(['error' => 'bad_homework'], 502);

    $items = [];
    foreach ((is_array($data['items'] ?? null) ? $data['items'] : []) as $it) {
        if (!is_array($it)) continue;
        $status = in_array($it['status'] ?? '', HW_STATUS, true) ? $it['status'] : 'unclear';
        $practice = in_array($status, ['wrong', 'partial'], true) ? clean_practice($it['practice'] ?? null) : null;
        $items[] = [
            'label' => clean($it['label'] ?? '', 40) ?: ('Câu ' . (count($items) + 1)),
            'task' => clean($it['task'] ?? '', 300),
            'student' => clean($it['student'] ?? '', 300),
            'status' => $status,
            'comment' => clean($it['comment'] ?? '', 500),
            'hint' => clean($it['hint'] ?? '', 400),
            'fix' => clean($it['fix'] ?? '', 600),
            'practice' => $practice,
        ];
        if (count($items) === 20) break;
    }
    $readable = ($data['readable'] ?? true) !== false && $items;
    $result = [
        'readable' => $readable,
        'title' => clean($data['title'] ?? '', 100),
        'praise' => clean($data['praise'] ?? '', 400),
        'items' => $readable ? $items : [],
        'tips' => array_slice(array_values(array_filter(array_map(fn($t) => clean($t, 200), is_array($data['tips'] ?? null) ? $data['tips'] : []))), 0, 3),
        'summary_for_parent' => clean($data['summary_for_parent'] ?? '', 600),
    ];
    if (!$readable) {
        out(['readable' => false, 'praise' => $result['praise'] ?: 'Mây chưa đọc được ảnh này. Bạn chụp lại thật rõ, đủ sáng và thẳng trang giúp Mây nhé!', 'stars' => stars()]);
    }

    $right = count(array_filter($items, fn($x) => $x['status'] === 'right'));
    $graded = count(array_filter($items, fn($x) => in_array($x['status'], ['right', 'partial', 'wrong'], true)));
    q('INSERT INTO homework (subject, note, photos, result, right_n, graded_n, recheck_of, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$subject, $note, count($images), json_encode($result, JSON_UNESCAPED_UNICODE), $right, $graded, $recheckOf ?: null, time()]);
    $id = (int)db()->lastInsertId();
    $topic = mb_substr($result['title'], 0, 80);
    log_activity($subject, $topic, 'homework', $right, $graded);

    // Câu sai có bài luyện tương tự: đưa vào Sổ ôn tập để Mây đố lại sau 1, 3, 7 ngày
    foreach ($items as $x) {
        if ($x['practice']) remember_mistake($subject, $topic, $x['practice']);
    }

    // Thưởng sao: 2 sao vì đã tự giác gửi bài, thêm 1 sao nếu đúng hết; bài sửa lại được thêm 1 sao
    $stars = add_stars(2 + ($graded > 0 && $right === $graded ? 1 : 0) + ($recheckOf ? 1 : 0));

    out(['readable' => true, 'id' => $id, 'result' => homework_for_kid($result, $recheckOf > 0), 'stars' => $stars, 'due' => due_count()]);
}

// Gửi xuống máy của bé: bỏ phần tóm tắt cho ba mẹ và đáp án của bài luyện.
// Cách làm đúng ("fix") chỉ gửi khi bài đã được chấm lại: bé phải tự sửa và chụp lại trước đã.
function homework_for_kid(array $r, bool $answersOpen): array
{
    unset($r['summary_for_parent']);
    $r['answers_open'] = $answersOpen;
    $r['items'] = is_array($r['items'] ?? null) ? $r['items'] : [];
    foreach ($r['items'] as &$x) {
        $x['practice'] = ($x['practice'] ?? null) !== null;
        $x['has_fix'] = ($x['fix'] ?? '') !== '';
        if (!$answersOpen) $x['fix'] = '';
    }
    unset($x);
    return $r;
}

// Đáp án của một bài được mở khi: bài này là bài chấm lại, hoặc bé đã gửi bài sửa lại của nó
function homework_answers_open(int $id, mixed $recheckOf): bool
{
    if ($recheckOf !== null) return true;
    return q('SELECT 1 FROM homework WHERE recheck_of = ? LIMIT 1', [$id])->fetchColumn() !== false;
}

function r_homework_list(array $b): never
{
    $rows = q('SELECT id, subject, result, right_n, graded_n, created_at FROM homework ORDER BY id DESC LIMIT 10')->fetchAll();
    $list = array_map(function ($r) {
        $res = json_decode($r['result'], true) ?: [];
        return ['id' => (int)$r['id'], 'subject' => $r['subject'], 'title' => $res['title'] ?? '', 'right' => (int)$r['right_n'],
                'graded' => (int)$r['graded_n'], 'created_at' => (int)$r['created_at']];
    }, $rows);
    $open = (int)($b['open'] ?? 0);
    $detail = null;
    if ($open > 0) {
        $row = q('SELECT id, subject, note, result, recheck_of, created_at FROM homework WHERE id = ?', [$open])->fetch();
        if ($row) $detail = ['id' => (int)$row['id'], 'subject' => $row['subject'], 'note' => $row['note'],
                             'created_at' => (int)$row['created_at'], 'recheck_of' => $row['recheck_of'] !== null ? (int)$row['recheck_of'] : null,
                             'result' => homework_for_kid(json_decode($row['result'], true) ?: [], homework_answers_open((int)$row['id'], $row['recheck_of']))];
    }
    out(['list' => $list, 'detail' => $detail]);
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
    $homework = [];
    foreach (q('SELECT id, subject, result, right_n, graded_n, recheck_of, created_at FROM homework ORDER BY id DESC LIMIT 30')->fetchAll() as $h) {
        $res = json_decode($h['result'], true) ?: [];
        $homework[] = ['id' => (int)$h['id'], 'subject' => $h['subject'], 'title' => $res['title'] ?? '',
                       'right' => (int)$h['right_n'], 'graded' => (int)$h['graded_n'], 'recheck' => $h['recheck_of'] !== null,
                       'summary' => $res['summary_for_parent'] ?? '', 'created_at' => (int)$h['created_at']];
    }
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
        'homework' => $homework,
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

function r_parent_homework(array $b): never
{
    $row = q('SELECT id, subject, note, photos, result, recheck_of, created_at FROM homework WHERE id = ?', [(int)($b['id'] ?? 0)])->fetch();
    if (!$row) out(['error' => 'bad_request'], 400);
    $row['result'] = json_decode($row['result'], true);
    out(['homework' => $row]);
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
        $pdo->exec('DELETE FROM messages; DELETE FROM conversations; DELETE FROM quizzes; DELETE FROM mistakes; DELETE FROM activity; DELETE FROM homework;');
        set_setting('stars', '0');
    } else {
        out(['error' => 'bad_request'], 400);
    }
    $pdo->exec('VACUUM');
    out(['ok' => true]);
}
