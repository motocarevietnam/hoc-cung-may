<?php
// Cổng API duy nhất của Học cùng Mây. nginx chuyển mọi yêu cầu /api/... vào file này.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../app/lib.php';

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$route = preg_replace('#^/api/#', '', $path) ?? '';

// Google chuyển ba mẹ về đây sau khi đồng ý cấp quyền (GET, kiểm tra bằng mã state một lần)
if ($route === 'google/callback' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') google_callback();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['error' => 'method_not_allowed'], 405);
// Ảnh bài tập cần nhiều chỗ hơn (tối đa 4 ảnh đã thu nhỏ trên máy); mọi yêu cầu khác giới hạn 64 KB
$maxBody = in_array($route, ['homework', 'write_submit'], true) ? 9 * 1024 * 1024 : 65536;
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
    'write_start'         => 'r_write_start',
    'write_outline'       => 'r_write_outline',
    'write_begin'         => 'r_write_begin',
    'write_submit'        => 'r_write_submit',
    'write_list'          => 'r_write_list',
    'homework_list'       => 'r_homework_list',
    'plan'                => 'r_plan',
    'task'                => 'r_task',
    'shop'                => 'r_shop',
    'check_start'         => 'r_check_start',
    'check_explain'       => 'r_check_explain',
    'check_questions'     => 'r_check_questions',
    'check_answer'        => 'r_check_answer',
    'shop_redeem'         => 'r_shop_redeem',
    'shop_cancel'         => 'r_shop_cancel',
    'speak_start'         => 'r_speak_start',
    'speak_check'         => 'r_speak_check',
    'tts'                 => 'r_tts',
    'parent/check'        => 'r_parent_check',
    'parent/login'        => 'r_parent_login',
    'parent/logout'       => 'r_parent_logout',
    'parent/report'       => 'r_parent_report',
    'parent/conversation' => 'r_parent_conversation',
    'parent/homework'     => 'r_parent_homework',
    'parent/settings'     => 'r_parent_settings',
    'parent/clear'        => 'r_parent_clear',
    'parent/schedule'     => 'r_parent_schedule',
    'parent/plan'         => 'r_parent_plan',
    'parent/plan_generate'=> 'r_parent_plan_generate',
    'parent/plan_save'    => 'r_parent_plan_save',
    'parent/rewards'      => 'r_parent_rewards',
    'parent/rewards_save' => 'r_parent_rewards_save',
    'parent/shop'         => 'r_parent_shop',
    'parent/shop_save'    => 'r_parent_shop_save',
    'parent/shop_decide'  => 'r_parent_shop_decide',
    'parent/google'       => 'r_parent_google',
    'parent/google_connect' => 'r_parent_google_connect',
    'parent/google_disconnect' => 'r_parent_google_disconnect',
];
if (!isset($routes[$route])) out(['error' => 'not_found'], 404);

try {
    if ($route === 'parent/login') r_parent_login($body);
    if (str_starts_with($route, 'parent/')) require_parent();
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
    out(['nick' => setting('nick'), 'stars' => stars(), 'due' => due_count(), 'tts' => tts_enabled(), 'tts_voices' => tts_enabled() ? tts_voices() : null,
        'tts_by' => ['google' => 'Google', 'azure' => 'Microsoft', '' => ''][tts_provider()],
         'drive' => drive_connected(), 'now_topics' => current_topics(today())]);
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
    // Bé nói qua micro: nhắc Mây rằng chữ (nhất là chữ số) do máy chép, không phải bé tự viết
    if (!empty($b['mic']) && $messages) {
        $messages[count($messages) - 1]['content'] .= "\n\n[Ghi chú của hệ thống: tin nhắn trên do bé NÓI qua micro, máy tự chép thành chữ."
            . " Chữ số, dấu chấm, dấu phẩy là do máy tự định dạng, không phải bé viết.]";
    }

    $system = chat_system($subject, $topic, setting('nick'), setting('focus'), memory_note($subject), schedule_note());
    $reply = trim(ask_claude((string)cfg('chat_model', 'claude-haiku-5-5'), $system, $messages, 4000, 60, (string)cfg('chat_effort', 'low')));
    if ($reply === '') $reply = '…';

    q('INSERT INTO messages (conv_id, role, content, created_at) VALUES (?, \'assistant\', ?, ?)', [$conv, $reply, time()]);
    q('UPDATE conversations SET updated_at = ? WHERE id = ?', [time(), $conv]);

    $asked = (int)q('SELECT COUNT(*) FROM messages WHERE conv_id = ? AND role = \'user\'', [$conv])->fetchColumn();
    out(['conv_id' => $conv, 'text' => $reply, 'stars' => stars()]);
}

function r_quiz(array $b): never
{
    $subject = subject_or_fail($b);
    $topic = clean($b['topic'] ?? '', 200);
    spend(5);

    $text = ask_claude(
        (string)cfg('quiz_model', 'claude-sonnet-5-5'),
        quiz_system(),
        [['role' => 'user', 'content' => quiz_prompt($subject, $topic, setting('focus'), quiz_context($subject))]],
        16000,
        60,
        (string)cfg('quiz_effort', 'medium')
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
    }
    // Lượt kiểm tra lại: bỏ các câu có đáp án sai hoặc mơ hồ (Mây đôi khi đánh dấu nhầm đáp án)
    if ($questions) {
        try {
            $v = claude_json(ask_claude((string)cfg('quiz_model', 'claude-sonnet-5-5'), quiz_system(),
                [['role' => 'user', 'content' => quiz_verify_prompt($subject, $questions)]], 8000, 45, 'medium')) ?? [];
            $bad = array_map('intval', is_array($v['bad'] ?? null) ? $v['bad'] : []);
            if ($bad) error_log('[hoc-cung-may] quiz verify bỏ câu ' . implode(',', $bad) . ': ' . mb_substr((string)($v['notes'] ?? ''), 0, 200));
            $questions = array_values(array_filter($questions, fn($k) => !in_array($k, $bad, true), ARRAY_FILTER_USE_KEY));
        } catch (Throwable $e) {
            error_log('[hoc-cung-may] quiz verify lỗi: ' . $e->getMessage());   // không kiểm được thì vẫn dùng đề, không chặn bé
        }
    }
    $questions = array_slice($questions, 0, 10);
    if (count($questions) < 6) out(['error' => 'bad_quiz'], 502);

    q('INSERT INTO quizzes (subject, topic, questions, answers, total, created_at) VALUES (?, ?, ?, \'{}\', ?, ?)',
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
        q('INSERT INTO mistakes (subject, topic, q, options, answer, `explain`, hint, due_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
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

    // Sao không tính theo từng câu đúng (không chạy theo điểm): hoàn thành lượt đố vui trong "Hôm nay" mới được sao
    $bonus = false;
    if ($first) {
        if (!$right) remember_mistake($quiz['subject'], $quiz['topic'], $x);
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
    }
    out(['right' => $right, 'answer' => $x['answer'], 'explain' => $x['explain'], 'stars' => stars(),
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
        // Làm đúng câu từng sai = tự sửa sai; thuộc hẳn (đúng qua đủ các lần ôn cách quãng) = tiến bộ có bằng chứng
        award('self_fix', "fix:review:{$m['id']}:{$box}", 'Làm đúng câu từng sai: ' . mb_substr($m['q'], 0, 60));
        if ($mastered) award('progress', "progress:mistake:{$m['id']}", 'Thuộc hẳn: ' . mb_substr($m['q'], 0, 60));
    } else {
        q('UPDATE mistakes SET box = 0, due_at = ?, times_wrong = times_wrong + 1 WHERE id = ?', [strtotime('tomorrow'), $m['id']]);
    }
    log_activity($m['subject'], $m['topic'], 'review', $right ? 1 : 0, 1);
    out(['right' => $right, 'answer' => (int)$m['answer'], 'explain' => $m['explain'], 'stars' => stars(),
         'mastered' => $mastered, 'due' => due_count()]);
}

/* ---------------- chấm bài tập về nhà ---------------- */

// Nhận ảnh dạng data URL (đã thu nhỏ trên máy), kiểm tra đúng là ảnh rồi mới gửi cho Claude.
// Ảnh KHÔNG được lưu trên máy chủ, chỉ lưu kết quả chấm.
function homework_images(mixed $list): array
{
    if (!is_array($list) || count($list) < 1 || count($list) > 4) out(['error' => 'bad_photos'], 400);
    $blocks = [];
    $bins = [];
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
        $bins[] = ['type' => $m[1], 'bin' => $bin];
    }
    return [$blocks, $bins];
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
    [$images, $imageBins] = homework_images($b['photos'] ?? null);

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
        16000,
        140,
        (string)cfg('homework_effort', 'medium')
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

    // Bài chấm lại: đếm số câu lần trước sai/gần đúng mà nay đã đúng (so theo tên câu)
    $improved = 0;
    if ($previous) {
        $wasBad = [];
        foreach ($previous['items'] ?? [] as $p) {
            if (in_array($p['status'] ?? '', ['wrong', 'partial'], true)) $wasBad[hw_label_key((string)($p['label'] ?? ''))] = true;
        }
        foreach ($result['items'] as &$x) {
            $x['fixed'] = $x['status'] === 'right' && isset($wasBad[hw_label_key($x['label'])]);
            if ($x['fixed']) $improved++;
        }
        unset($x);
        $items = $result['items'];
    }
    $result['improved'] = $improved;

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

    // Mỗi câu bé tự sửa đúng khi chấm lại = tự sửa sai (gửi bài thì đã được sao hoàn thành nhiệm vụ qua "Hôm nay")
    foreach ($items as $x) {
        if (!empty($x['fixed'])) award('self_fix', 'fix:hw:' . $recheckOf . ':' . hw_label_key($x['label']), 'Tự sửa đúng ' . $x['label']);
    }
    $stars = stars();

    $response = ['readable' => true, 'id' => $id, 'result' => homework_for_kid($result, $improved > 0), 'stars' => $stars, 'due' => due_count()];
    if (!drive_connected()) out($response);

    // Trả kết quả cho bé trước, rồi mới lưu ảnh (đã nén trên iPad) lên Google Drive; lỗi Drive không ảnh hưởng bài chấm
    respond_now($response);
    try {
        drive_upload_homework($id, $subject, $result['title'], $imageBins);
    } catch (Throwable $e) {
        error_log('[hoc-cung-may] Drive: ' . $e->getMessage());
    }
    exit;
}

/* ---------------- Viết đoạn văn (Tiếng Việt, English) ---------------- */

function writing_or_fail(array $b): array
{
    $w = q('SELECT * FROM writings WHERE id = ?', [(int)($b['id'] ?? 0)])->fetch();
    if (!$w) out(['error' => 'bad_request'], 400);
    return $w;
}

function writing_for_kid(array $w): array
{
    return ['id' => (int)$w['id'], 'subject' => $w['subject'], 'prompt' => $w['prompt'],
            'questions' => json_decode((string)$w['questions'], true) ?: null, 'answers' => json_decode((string)$w['answers'], true) ?: [],
            'outline' => json_decode((string)$w['outline'], true) ?: null, 'started_at' => $w['started_at'] !== null ? (int)$w['started_at'] : null,
            'result' => json_decode((string)$w['result'], true) ?: null, 'submits' => (int)$w['submits'],
            'minutes' => WRITING_MINUTES, 'now' => time(), 'created_at' => (int)$w['created_at']];
}

// Bước 1: bé nhập đề → Mây soạn câu hỏi gợi ý để bé tự nghĩ ý
function r_write_start(array $b): never
{
    $subject = (string)($b['subject'] ?? '');
    if (!in_array($subject, ['tv', 'ceng'], true)) out(['error' => 'bad_request'], 400);
    $prompt = clean($b['prompt'] ?? '', 400);
    if (mb_strlen($prompt) < 5) out(['error' => 'write_no_prompt'], 400);
    spend(2);
    $d = claude_json(ask_claude((string)cfg('chat_model', 'claude-haiku-5-5'), quiz_system(),
        [['role' => 'user', 'content' => writing_questions_prompt($subject, $prompt)]], 4000, 60, 'low')) ?? [];
    $qs = array_values(array_filter(array_map(fn($x) => clean($x, 200), is_array($d['questions'] ?? null) ? array_slice($d['questions'], 0, 7) : []), fn($x) => $x !== ''));
    if (count($qs) < 3) out(['error' => 'upstream'], 502);
    $questions = ['type' => clean($d['type'] ?? '', 80), 'list' => $qs, 'tip' => clean($d['tip'] ?? '', 200)];
    q('INSERT INTO writings (subject, prompt, questions, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$subject, $prompt, json_encode($questions, JSON_UNESCAPED_UNICODE), time(), time()]);
    $id = (int)db()->lastInsertId();
    out(['writing' => writing_for_kid(writing_or_fail(['id' => $id]))]);
}

// Bước 2: bé trả lời ngắn (nói hoặc gõ) → Mây xếp ý của bé thành dàn ý
function r_write_outline(array $b): never
{
    $w = writing_or_fail($b);
    $qs = json_decode((string)$w['questions'], true)['list'] ?? [];
    $ans = is_array($b['answers'] ?? null) ? $b['answers'] : [];
    $qa = [];
    foreach ($qs as $i => $q) $qa[] = ['q' => $q, 'a' => clean($ans[$i] ?? '', 300)];
    if (count(array_filter($qa, fn($x) => $x['a'] !== '')) < 2) out(['error' => 'write_few_answers'], 400);
    spend(2);
    $d = claude_json(ask_claude((string)cfg('chat_model', 'claude-haiku-5-5'), quiz_system(),
        [['role' => 'user', 'content' => writing_outline_prompt($w['subject'], $w['prompt'], $qa)]], 4000, 60, 'low')) ?? [];
    $list = fn($k, $n) => array_values(array_filter(array_map(fn($x) => clean($x, 160), is_array($d[$k] ?? null) ? array_slice($d[$k], 0, $n) : []), fn($x) => $x !== ''));
    $outline = ['mo_bai' => $list('mo_bai', 3), 'than_bai' => $list('than_bai', 8), 'ket_bai' => $list('ket_bai', 3),
                'words' => $list('words', 6), 'warning' => clean($d['warning'] ?? '', 300)];
    if (!$outline['than_bai']) out(['error' => 'upstream'], 502);
    q('UPDATE writings SET answers = ?, outline = ?, updated_at = ? WHERE id = ?',
        [json_encode(array_column($qa, 'a'), JSON_UNESCAPED_UNICODE), json_encode($outline, JSON_UNESCAPED_UNICODE), time(), $w['id']]);
    out(['writing' => writing_for_kid(writing_or_fail($b))]);
}

// Bước 3: bé bấm bắt đầu viết → máy chủ ghi giờ bắt đầu (đồng hồ 35 phút tính theo giờ máy chủ, tải lại trang không mất)
function r_write_begin(array $b): never
{
    $w = writing_or_fail($b);
    if ($w['started_at'] === null) q('UPDATE writings SET started_at = ?, updated_at = ? WHERE id = ?', [time(), time(), $w['id']]);
    out(['writing' => writing_for_kid(writing_or_fail($b))]);
}

// Bước 4: chụp bài viết tay → Mây nhận xét theo tiêu chí; nộp lại sau khi sửa → so với lần trước
function r_write_submit(array $b): never
{
    $w = writing_or_fail($b);
    [$images, $imageBins] = homework_images($b['photos'] ?? null);
    $previous = json_decode((string)$w['result'], true) ?: null;
    $minutes = $w['started_at'] !== null ? (int)ceil((time() - (int)$w['started_at']) / 60) : 0;
    spend(5);
    $content = $images;
    $content[] = ['type' => 'text', 'text' => writing_review_prompt($w['prompt'], json_decode((string)$w['outline'], true) ?: null, $previous, $previous ? 0 : $minutes)];
    $d = claude_json(ask_claude((string)cfg('homework_model', cfg('quiz_model', 'claude-sonnet-5-5')),
        writing_review_system($w['subject'], setting('nick')), [['role' => 'user', 'content' => $content]], 16000, 100, 'medium')) ?? [];
    if (($d['readable'] ?? true) === false) out(['readable' => false]);
    $st = fn($v) => in_array($v, ['ok', 'thiếu', 'cần thêm'], true) ? $v : 'ok';
    $strList = fn($k, $n, $len) => array_values(array_filter(array_map(fn($x) => clean($x, $len), is_array($d[$k] ?? null) ? array_slice($d[$k], 0, $n) : []), fn($x) => $x !== ''));
    $fixes = [];
    foreach (is_array($d['fixes'] ?? null) ? array_slice($d['fixes'], 0, 3) : [] as $f) {
        if (is_array($f) && clean($f['issue'] ?? '', 200) !== '') $fixes[] = ['quote' => clean($f['quote'] ?? '', 160), 'issue' => clean($f['issue'], 200), 'hint' => clean($f['hint'] ?? '', 240)];
    }
    $spelling = [];
    foreach (is_array($d['spelling'] ?? null) ? array_slice($d['spelling'], 0, 6) : [] as $x) {
        if (is_array($x) && clean($x['wrong'] ?? '', 40) !== '') $spelling[] = ['wrong' => clean($x['wrong'], 40), 'right' => clean($x['right'] ?? '', 40)];
    }
    $s = is_array($d['structure'] ?? null) ? $d['structure'] : [];
    $result = ['on_topic' => ($d['on_topic'] ?? true) !== false, 'topic_note' => clean($d['topic_note'] ?? '', 300),
               'structure' => ['mo_bai' => $st($s['mo_bai'] ?? ''), 'than_bai' => $st($s['than_bai'] ?? ''), 'ket_bai' => $st($s['ket_bai'] ?? ''), 'note' => clean($s['note'] ?? '', 300)],
               'strengths' => $strList('strengths', 2, 240), 'fixes' => $fixes, 'spelling' => $spelling,
               'fixed' => $previous ? $strList('fixed', 3, 200) : [],
               'overall' => in_array($d['overall'] ?? '', ['great', 'good', 'try_again'], true) ? $d['overall'] : 'good',
               'encourage' => clean($d['encourage'] ?? '', 200), 'minutes' => $previous ? ($previous['minutes'] ?? $minutes) : $minutes];
    q('UPDATE writings SET result = ?, first_result = COALESCE(first_result, ?), submits = submits + 1, updated_at = ? WHERE id = ?',
        [json_encode($result, JSON_UNESCAPED_UNICODE), json_encode($result, JSON_UNESCAPED_UNICODE), time(), $w['id']]);
    if (!$previous) log_activity($w['subject'], mb_substr($w['prompt'], 0, 120), 'writing');
    elseif ($result['fixed']) award('self_fix', 'fix:write:' . $w['id'], 'Sửa bài viết: ' . mb_substr($w['prompt'], 0, 60));
    $response = ['readable' => true, 'writing' => writing_for_kid(writing_or_fail($b)), 'stars' => stars()];
    if (!drive_connected()) out($response);
    respond_now($response);
    try {
        drive_upload_homework(0, $w['subject'], 'Bài viết – ' . mb_substr($w['prompt'], 0, 50), $imageBins);
    } catch (Throwable $e) {
        error_log('[hoc-cung-may] Drive: ' . $e->getMessage());
    }
    exit;
}

function r_write_list(array $b): never
{
    if (!empty($b['open'])) out(['writing' => writing_for_kid(writing_or_fail(['id' => $b['open']]))]);
    $rows = q('SELECT id, subject, prompt, submits, result, created_at FROM writings ORDER BY id DESC LIMIT 10')->fetchAll();
    out(['list' => array_map(fn($r) => ['id' => (int)$r['id'], 'subject' => $r['subject'], 'prompt' => $r['prompt'], 'submits' => (int)$r['submits'],
        'overall' => (json_decode((string)$r['result'], true) ?: [])['overall'] ?? null, 'created_at' => (int)$r['created_at']], $rows)]);
}

function respond_now(array $data): void
{
    http_response_code(200);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    ignore_user_abort(true);
}

// Gửi xuống máy của bé: bỏ phần tóm tắt cho ba mẹ và đáp án của bài luyện.
// Cách làm đúng ("fix") chỉ gửi khi bé đã tự sửa đúng ít nhất một câu và gửi chấm lại.
function homework_for_kid(array $r, bool $answersOpen): array
{
    unset($r['summary_for_parent']);
    $r['answers_open'] = $answersOpen;
    $r['items'] = is_array($r['items'] ?? null) ? $r['items'] : [];
    foreach ($r['items'] as &$x) {
        $x['practice'] = ($x['practice'] ?? null) !== null;
        $x['has_fix'] = ($x['fix'] ?? '') !== '';
        $x['fixed'] = (bool)($x['fixed'] ?? false);
        if (!$answersOpen) $x['fix'] = '';
    }
    unset($x);
    return $r;
}

function hw_label_key(string $label): string
{
    return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($label)) ?? '';
}

// Đáp án của một bài được mở khi bài đó, hoặc một lần chấm lại sau nó, có ít nhất một câu bé đã sửa đúng
function homework_answers_open(int $id): bool
{
    $rows = q('WITH RECURSIVE chain(id) AS (SELECT ? UNION SELECT h.id FROM homework h JOIN chain c ON h.recheck_of = c.id)
               SELECT result FROM homework WHERE id IN (SELECT id FROM chain)', [$id])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $r) {
        if (((json_decode((string)$r, true) ?: [])['improved'] ?? 0) > 0) return true;
    }
    return false;
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
                             'result' => homework_for_kid(json_decode($row['result'], true) ?: [], homework_answers_open((int)$row['id']))];
    }
    out(['list' => $list, 'detail' => $detail]);
}

/* ---------------- giọng đọc tự nhiên (Google Cloud TTS hoặc Azure) ---------------- */

// Đọc một đoạn đã tách theo ngôn ngữ thành một file mp3 (mỗi phần một giọng). Trả về âm thanh, không phải JSON.
function r_tts(array $b): never
{
    if (!tts_enabled()) out(['error' => 'tts_off'], 404);
    $chunks = [];
    $total = 0;
    foreach (is_array($b['chunks'] ?? null) ? array_slice($b['chunks'], 0, 30) : [] as $c) {
        if (!is_array($c)) continue;
        $t = clean($c['t'] ?? '', 1500);
        $l = ($c['l'] ?? '') === 'en' ? 'en' : 'vi';
        if ($t === '') continue;
        $total += mb_strlen($t);
        $chunks[] = ['l' => $l, 't' => $t];
    }
    if (!$chunks || $total > 2500) out(['error' => 'bad_request'], 400);
    $voices = ['vi' => tts_voice('vi', (string)($b['vi'] ?? '')), 'en' => tts_voice('en', (string)($b['en'] ?? ''))];
    $rate = max(0.5, min(1.5, (float)($b['rate'] ?? 1)));
    $mp3 = tts_mp3($chunks, $voices, $rate);
    header('Content-Type: audio/mpeg');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . strlen($mp3));
    echo $mp3;
    exit;
}

/* ---------------- luyện nói tiếng Anh ---------------- */

function claude_json(string $text): ?array
{
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    $d = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
    return is_array($d) ? $d : null;
}

function strs(mixed $v, int $n, int $max): array
{
    return array_slice(array_values(array_filter(array_map(fn($x) => clean($x, $max), is_array($v) ? $v : []))), 0, $n);
}

function r_speak_start(array $b): never
{
    $type = (string)($b['type'] ?? '');
    if (!isset(SPEAK_TYPES[$type])) out(['error' => 'bad_request'], 400);
    $topic = clean($b['topic'] ?? '', 80);
    spend(2);
    $data = claude_json(ask_claude(
        (string)cfg('quiz_model', 'claude-sonnet-5-5'),
        "Bạn soạn bài luyện nói tiếng Anh cho học sinh lớp 4.\n\n" . no_fabrication_rules() . "\nChỉ trả lời bằng JSON.",
        [['role' => 'user', 'content' => speaking_gen_prompt($type, $topic, setting('focus'))]],
        16000, 100, 'low'
    ));
    $items = [];
    foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $it) {
        if (!is_array($it)) continue;
        $x = match ($type) {
            'read' => ['text' => clean($it['text'] ?? '', 200), 'vi' => clean($it['vi'] ?? '', 200)],
            'answer' => ['q' => clean($it['q'] ?? '', 200), 'vi' => clean($it['vi'] ?? '', 200), 'hints' => strs($it['hints'] ?? [], 4, 40),
                         'sample' => clean($it['sample'] ?? '', 300)],
            default => ['topic' => clean($it['topic'] ?? '', 200), 'vi' => clean($it['vi'] ?? '', 200), 'guide' => strs($it['guide'] ?? [], 3, 120),
                        'words' => strs($it['words'] ?? [], 6, 30), 'sample' => clean($it['sample'] ?? '', 600)],
        };
        if (($x['text'] ?? $x['q'] ?? $x['topic']) === '') continue;
        $items[] = $x;
        if (count($items) === ($type === 'tell' ? 2 : 5)) break;
    }
    if (!$items) out(['error' => 'bad_speaking'], 502);
    q('INSERT INTO speaking (type, topic, items, created_at) VALUES (?, ?, ?, ?)',
        [$type, $topic, json_encode($items, JSON_UNESCAPED_UNICODE), time()]);
    out(['session_id' => (int)db()->lastInsertId(), 'type' => $type, 'items' => $items]);
}

function r_speak_check(array $b): never
{
    $sess = q('SELECT * FROM speaking WHERE id = ?', [(int)($b['session_id'] ?? 0)])->fetch();
    $idx = (int)($b['index'] ?? -1);
    $items = $sess ? json_decode($sess['items'], true) : [];
    if (!$sess || !isset($items[$idx])) out(['error' => 'bad_request'], 400);
    $item = $items[$idx];
    $transcript = clean($b['transcript'] ?? '', 1500);
    spend(1);

    $diff = $sess['type'] === 'read' ? word_diff($item['text'], $transcript) : null;
    $data = claude_json(ask_claude(
        (string)cfg('chat_model', 'claude-haiku-5-5'),
        speaking_check_system(),
        [['role' => 'user', 'content' => speaking_check_prompt($sess['type'], $item, $transcript, $diff)]],
        4000, 60, 'low'
    )) ?? [];
    $level = in_array($data['level'] ?? '', ['great', 'good', 'try_again'], true) ? $data['level'] : 'good';
    if ($diff) $level = $diff['score'] >= 90 ? 'great' : ($diff['score'] >= 60 ? 'good' : 'try_again'); // bài đọc: chấm theo số từ khớp
    if (trim($transcript) === '') $level = 'try_again';
    $fixes = [];
    foreach (is_array($data['fixes'] ?? null) ? array_slice($data['fixes'], 0, 2) : [] as $f) {
        if (is_array($f)) $fixes[] = ['said' => clean($f['said'] ?? '', 200), 'better' => clean($f['better'] ?? '', 200), 'why' => clean($f['why'] ?? '', 300)];
    }
    $result = ['level' => $level, 'praise' => clean($data['praise'] ?? '', 300), 'fixes' => $fixes, 'tip' => clean($data['tip'] ?? '', 300),
               'say_it' => clean($data['say_it'] ?? '', 400), 'diff' => $diff];

    $first = q('SELECT COUNT(*) FROM speaking_tries WHERE session_id = ? AND idx = ?', [$sess['id'], $idx])->fetchColumn() == 0;
    q('INSERT INTO speaking_tries (session_id, idx, transcript, result, level, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$sess['id'], $idx, $transcript, json_encode($result, JSON_UNESCAPED_UNICODE), $level, time()]);
    if ($first) log_activity('ceng', $sess['topic'], 'speaking', $level === 'try_again' ? 0 : 1, 1);
    out(['result' => $result, 'stars' => stars()]);
}

function r_task(array $b): never
{
    $r = task_update(clean($b['key'] ?? '', 40), (string)($b['op'] ?? ''), clean($b['note'] ?? '', 255));
    if (isset($r['error'])) out($r, 400);
    out(['ok' => true, 'day' => day_plan(today()), 'stars' => stars()]);
}

/* ---------------- "Mình hiểu rồi": tự giải thích + kiểm tra lại ---------------- */

// Bắt đầu từ một cuộc trò chuyện (chat) hoặc một bài về nhà đã chấm (homework)
function r_check_start(array $b): never
{
    $source = (string)($b['source'] ?? '');
    $ref = (int)($b['ref'] ?? 0);
    if ($source === 'chat') {
        $c = q('SELECT * FROM conversations WHERE id = ?', [$ref])->fetch();
        if (!$c) out(['error' => 'bad_request'], 400);
        $msgs = array_reverse(q('SELECT role, content FROM messages WHERE conv_id = ? ORDER BY id DESC LIMIT 8', [$ref])->fetchAll());
        if (count(array_filter($msgs, fn($m) => $m['role'] === 'user')) < 2) out(['error' => 'check_too_early'], 400);
        $context = implode("\n", array_map(fn($m) => ($m['role'] === 'user' ? 'Bé: ' : 'Mây: ') . mb_substr($m['content'], 0, 500), $msgs));
        $subject = $c['subject'];
        $skill = skill_key($c['topic'] !== '' ? $c['topic'] : skill_name_from_chat($subject, $context));
    } elseif ($source === 'homework') {
        $h = q('SELECT * FROM homework WHERE id = ?', [$ref])->fetch();
        if (!$h) out(['error' => 'bad_request'], 400);
        $res = json_decode($h['result'], true) ?: [];
        $items = array_filter($res['items'] ?? [], fn($x) => in_array($x['status'] ?? '', ['wrong', 'partial'], true) || !empty($x['fixed']));
        if (!$items) $items = $res['items'] ?? [];
        $context = implode("\n", array_map(fn($x) => '- ' . ($x['label'] ?? '') . ': ' . ($x['task'] ?? '') . ' | bé làm: ' . ($x['student'] ?? '')
            . (($x['fix'] ?? '') !== '' ? ' | cách đúng: ' . $x['fix'] : ''), array_slice(array_values($items), 0, 4)));
        $subject = $h['subject'];
        $skill = skill_key((string)($res['title'] ?? ''));
    } else {
        out(['error' => 'bad_request'], 400);
    }
    q('INSERT INTO checks (subject, skill, source, context, created_at) VALUES (?, ?, ?, ?, ?)', [$subject, $skill, "{$source}:{$ref}", $context, time()]);
    out(['id' => (int)db()->lastInsertId(), 'subject' => $subject, 'skill' => $skill]);
}

// Cuộc trò chuyện chưa có chủ đề: nhờ Mây đặt tên kỹ năng ngắn (để gom tiến bộ theo kỹ năng, không theo từng câu hỏi).
// Lỗi thì dùng câu hỏi đầu tiên của bé.
function skill_name_from_chat(string $subject, string $context): string
{
    try {
        $name = trim(ask_claude((string)cfg('chat_model', 'claude-haiku-5-5'),
            'Bạn đặt tên kỹ năng học tập lớp 4. Chỉ trả về tên kỹ năng, không giải thích, không dấu ngoặc kép.',
            [['role' => 'user', 'content' => 'Môn: ' . SUBJECTS[$subject]['name'] . " (lớp 4). Đặt tên NGẮN (2–6 từ, theo cách gọi trong sách lớp 4) cho kỹ năng bé đang học trong đoạn trò chuyện sau, ví dụ \"Cộng phân số khác mẫu\", \"Past simple\":\n" . mb_substr($context, 0, 2000)]],
            2000, 30, 'low'));
        $name = clean(preg_replace('/["“”*]/u', '', strtok($name, "\n")) ?? '', 60);
        if ($name !== '') return $name;
    } catch (Throwable $e) {
        error_log('[hoc-cung-may] skill name: ' . $e->getMessage());
    }
    foreach (explode("\n", $context) as $line) if (str_starts_with($line, 'Bé: ')) return mb_substr(substr($line, 5), 0, 60);
    return 'Tổng hợp';
}

function check_row(int $id): array
{
    $c = q('SELECT * FROM checks WHERE id = ?', [$id])->fetch();
    if (!$c) out(['error' => 'bad_request'], 400);
    return $c;
}

function r_check_explain(array $b): never
{
    $c = check_row((int)($b['id'] ?? 0));
    $text = clean($b['text'] ?? '', 1500);
    if (mb_strlen($text) < 5) out(['error' => 'explain_too_short'], 400);
    if ((int)$c['explain_tries'] >= 3) out(['error' => 'explain_limit'], 400);
    spend(1);
    $d = claude_json(ask_claude((string)cfg('quiz_model', 'claude-sonnet-5-5'),
        "Bạn là \"Mây\", bạn học AI của một bạn nhỏ lớp 4. Bạn đánh giá lời giải thích của bé một cách ấm áp, công bằng.\n\n"
        . grade4_scope($c['subject']) . "\n\n" . no_fabrication_rules() . "\nChỉ trả lời bằng JSON.",
        [['role' => 'user', 'content' => explain_judge_prompt($c['subject'], $c['skill'], $c['context'], $text)]], 16000, 100, 'low')) ?? [];
    $verdict = in_array($d['verdict'] ?? '', ['correct', 'partial', 'incorrect'], true) ? $d['verdict'] : 'partial';
    $wasCorrect = (json_decode((string)$c['explain_result'], true)['verdict'] ?? '') === 'correct';
    $result = ['verdict' => $verdict, 'praise' => clean($d['praise'] ?? '', 300), 'missing' => clean($d['missing'] ?? '', 400), 'tip' => clean($d['tip'] ?? '', 300)];
    q('UPDATE checks SET explain_text = ?, explain_result = ?, explain_tries = explain_tries + 1 WHERE id = ?',
        [$text, json_encode($result, JSON_UNESCAPED_UNICODE), $c['id']]);
    if ($verdict === 'correct' && !$wasCorrect) {   // mỗi lượt kiểm tra chỉ ghi nhận một lần giải thích đúng
        q('INSERT INTO skill_mastery (subject, skill, explained, updated_at) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE explained = explained + 1, updated_at = VALUES(updated_at)',
            [$c['subject'], $c['skill'], time()]);
        award('explain', "explain:{$c['id']}", 'Giải thích đúng: ' . $c['skill']);
    }
    out(['result' => $result, 'tries_left' => 3 - (int)$c['explain_tries'] - 1, 'stars' => stars()]);
}

function r_check_questions(array $b): never
{
    $c = check_row((int)($b['id'] ?? 0));
    if ($c['questions'] === null) {
        spend(3);
        $d = claude_json(ask_claude((string)cfg('quiz_model', 'claude-sonnet-5-5'), quiz_system(),
            [['role' => 'user', 'content' => check_questions_prompt($c['subject'], $c['skill'], $c['context'])]], 16000, 100, (string)cfg('quiz_effort', 'medium'))) ?? [];
        $qs = [];
        foreach (is_array($d['questions'] ?? null) ? $d['questions'] : [] as $x) {
            $x = clean_practice($x);
            if ($x) $qs[] = $x;
            if (count($qs) === 3) break;
        }
        if (count($qs) < 2) out(['error' => 'bad_quiz'], 502);
        q('UPDATE checks SET questions = ?, answers = ?, total = ? WHERE id = ?', [json_encode($qs, JSON_UNESCAPED_UNICODE), '{}', count($qs), $c['id']]);
        $c['questions'] = json_encode($qs, JSON_UNESCAPED_UNICODE);
    }
    $qs = json_decode($c['questions'], true);
    out(['questions' => array_map(fn($x) => ['q' => $x['q'], 'options' => $x['options'], 'hint' => $x['hint']], $qs), 'skill' => $c['skill']]);
}

function r_check_answer(array $b): never
{
    $c = check_row((int)($b['id'] ?? 0));
    $qs = json_decode((string)$c['questions'], true) ?: [];
    $answers = json_decode((string)$c['answers'], true) ?: [];
    $i = (int)($b['index'] ?? -1);
    if (!isset($qs[$i])) out(['error' => 'bad_request'], 400);
    $key = (string)$i;
    $first = !array_key_exists($key, $answers);
    if ($first) {
        $answers[$key] = (int)($b['picked'] ?? -1);
        q('UPDATE checks SET answers = ? WHERE id = ?', [json_encode($answers), $c['id']]);
        if ($answers[$key] !== $qs[$i]['answer']) remember_mistake($c['subject'], $c['skill'], $qs[$i]);
    }
    $right = $answers[$key] === $qs[$i]['answer'];
    $res = ['right' => $right, 'answer' => $qs[$i]['answer'], 'explain' => $qs[$i]['explain']];
    if (count($answers) >= count($qs) && $c['finished_at'] === null) {
        $score = count(array_filter(array_keys($qs), fn($k) => ($answers[(string)$k] ?? -1) === $qs[$k]['answer']));
        $total = count($qs);
        $passed = $score >= max(2, $total - 1) && $score >= $total - 1;   // 2/2 hoặc 3/3, 2/3
        q('UPDATE checks SET score = ?, finished_at = ? WHERE id = ? AND finished_at IS NULL', [$score, time(), $c['id']]);
        $level = skill_record($c['subject'], $c['skill'], $score, $total, $passed);
        log_activity($c['subject'], $c['skill'], 'check', $score, $total);
        if ($passed) award('progress', "check:{$c['id']}", 'Kiểm tra lại đạt ' . "{$score}/{$total}: " . $c['skill']);
        $res += ['done' => true, 'score' => $score, 'total' => $total, 'passed' => $passed, 'level' => $level, 'level_label' => SKILL_LEVELS[$level]];
    }
    out($res + ['stars' => stars(), 'due' => due_count()]);
}

/* ---------------- đổi quà (bé) ---------------- */

function r_shop(array $b): never
{
    out(rewards_for_kid());
}

function r_shop_redeem(array $b): never
{
    $nonce = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['nonce'] ?? '')) ?? '';
    if (strlen($nonce) < 8) out(['error' => 'bad_request'], 400);
    $r = redeem_request((int)($b['reward_id'] ?? 0), $nonce, clean($b['note'] ?? '', 255));
    if (isset($r['error'])) out($r, 409);
    out($r + rewards_for_kid());
}

function r_shop_cancel(array $b): never
{
    $r = redeem_decide((int)($b['id'] ?? 0), 'cancel', '', true);
    if (isset($r['error'])) out($r, 409);
    out($r + rewards_for_kid());
}

/* ---------------- kế hoạch học: ngày, tuần, tháng, quý ---------------- */

function r_plan(array $b): never
{
    $view = (string)($b['view'] ?? 'day');
    $today = today();
    if ($view === 'day') out(['view' => 'day', 'day' => day_plan($today)]);

    if ($view === 'week') {
        $mon = date('Y-m-d', strtotime('monday this week', strtotime($today)));
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("{$mon} +{$i} days"));
            $p = day_plan($d);
            if ($d > $today) foreach ($p['items'] as &$it) $it['done'] = null; // ngày chưa tới
            unset($it);
            $days[] = $p;
        }
        $sun = date('Y-m-d', strtotime("{$mon} +6 days"));
        $plan = approved_plan($today);
        out(['view' => 'week', 'from' => $mon, 'to' => $sun, 'days' => $days, 'topics' => plan_week($plan, $today),
             'stats' => period_stats($mon, $sun)]);
    }

    if ($view === 'month') {
        $from = date('Y-m-01', strtotime($today));
        $to = date('Y-m-t', strtotime($today));
        out(['view' => 'month', 'from' => $from, 'to' => $to, 'weeks' => plan_weeks_between($from, $to),
             'stats' => period_stats($from, $to)]);
    }

    if ($view === 'quarter') {
        $plan = approved_plan($today);
        if ($plan) {
            $from = $plan['start_day'];
            $to = $plan['end_day'];
        } else {
            $qStartMonth = intdiv((int)date('n', strtotime($today)) - 1, 3) * 3 + 1;
            $from = date('Y-m-d', mktime(0, 0, 0, $qStartMonth, 1, (int)date('Y', strtotime($today))));
            $to = date('Y-m-t', strtotime("{$from} +2 months"));
        }
        $months = [];
        for ($m = strtotime(date('Y-m-01', strtotime($from))); $m <= strtotime($to); $m = strtotime('+1 month', $m)) {
            $mFrom = max($from, date('Y-m-01', $m));
            $mTo = min($to, date('Y-m-t', $m));
            $months[] = ['month' => date('Y-m', $m), 'weeks' => plan_weeks_between($mFrom, $mTo),
                         'stats' => $mFrom <= $today ? period_stats($mFrom, min($mTo, $today)) : null];
        }
        out(['view' => 'quarter', 'from' => $from, 'to' => $to, 'has_plan' => (bool)$plan, 'months' => $months,
             'stats' => period_stats($from, min($to, $today))]);
    }
    out(['error' => 'bad_request'], 400);
}

// Các tuần trong kế hoạch đã duyệt có ngày bắt đầu (thứ 2) nằm trong khoảng [from, to].
// Tuần vắt qua hai tháng thuộc về tháng chứa ngày thứ 2 đầu tuần, để không bị lặp.
function plan_weeks_between(string $from, string $to): array
{
    $plan = approved_plan($from) ?? approved_plan($to) ?? approved_plan(today());
    $weeks = [];
    foreach ($plan['content']['weeks'] ?? [] as $w) {
        $start = (string)($w['start'] ?? '');
        if ($start >= $from && $start <= $to) $weeks[] = $w;
    }
    return $weeks;
}

/* ---------------- cho ba mẹ ---------------- */

function r_parent_schedule(array $b): never
{
    if (isset($b['save']) && is_array($b['save'])) {
        $in = $b['save'];
        $tt = [];
        $grid = timetable_grid($in['grid'] ?? null);
        for ($d = 1; $d <= 7; $d++) {
            $tt[(string)$d] = $grid ? timetable_day_text($grid, $d) : clean($in['timetable'][(string)$d] ?? '', 200);
        }
        $classes = [];
        foreach (is_array($in['classes'] ?? null) ? array_slice($in['classes'], 0, 10) : [] as $c) {
            if (!is_array($c) || clean($c['name'] ?? '', 60) === '') continue;
            $time = clean($c['time'] ?? '', 5);
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $time = '';
            $classes[] = ['day' => max(1, min(7, (int)($c['day'] ?? 1))), 'time' => $time,
                          'minutes' => max(0, min(300, (int)($c['minutes'] ?? 0))), 'name' => clean($c['name'], 60)];
        }
        set_setting('schedule', json_encode([
            'book' => clean($in['book'] ?? '', 60),
            'syllabus' => clean_multiline($in['syllabus'] ?? '', 4000),
            'minutes' => max(15, min(90, (int)($in['minutes'] ?? 40))),
            'timetable' => $tt,
            'grid' => $grid,
            'exams' => exams_clean($in['exams'] ?? []),
            'classes' => $classes,
        ], JSON_UNESCAPED_UNICODE));
    }
    out(['schedule' => schedule()]);
}

// Giữ xuống dòng (cho mục lục sách), bỏ ký tự điều khiển khác
function clean_multiline(mixed $v, int $max): string
{
    $s = is_string($v) ? $v : '';
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r", '', $s)) ?? '';
    return trim(mb_substr($s, 0, $max));
}

function plan_row(?array $row): ?array
{
    if (!$row) return null;
    $row['content'] = json_decode((string)$row['content'], true) ?: ['weeks' => []];
    return $row;
}

function r_parent_plan(array $b): never
{
    $draft = q("SELECT * FROM plans WHERE status = 'draft' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    $approved = q("SELECT * FROM plans WHERE status = 'approved' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    out(['draft' => plan_row($draft), 'approved' => plan_row($approved)]);
}

function r_parent_plan_generate(array $b): never
{
    $mon = date('Y-m-d', strtotime('monday this week', strtotime(today())));
    $weeks = [];
    for ($i = 0; $i < 13; $i++) $weeks[] = date('Y-m-d', strtotime("{$mon} +{$i} weeks"));
    $weak = implode(', ', array_map(fn($r) => SUBJECTS[$r['subject']]['name'] . ': ' . $r['topic'],
        q("SELECT subject, topic FROM mistakes WHERE mastered_at IS NULL AND topic <> '' GROUP BY subject, topic
           ORDER BY COUNT(*) DESC LIMIT 6")->fetchAll()));
    spend(5);
    $text = ask_claude(
        (string)cfg('quiz_model', 'claude-sonnet-5-5'),
        "Bạn giúp ba mẹ lập kế hoạch ôn tập ở nhà cho học sinh lớp 4.\n\n" . no_fabrication_rules() . "\nChỉ trả lời bằng JSON.",
        [['role' => 'user', 'content' => plan_prompt($weeks, schedule(), setting('focus'), $weak)]],
        16000, 110, (string)cfg('quiz_effort', 'medium')
    );
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    $data = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
    $byStart = [];
    foreach (is_array($data['weeks'] ?? null) ? $data['weeks'] : [] as $w) {
        if (is_array($w) && in_array($w['start'] ?? '', $weeks, true)) $byStart[$w['start']] = $w;
    }
    if (count($byStart) < 4) out(['error' => 'bad_plan'], 502);
    $content = ['weeks' => array_map(fn($d) => clean_plan_week($byStart[$d] ?? ['start' => $d]), $weeks),
                'summary' => clean($data['summary'] ?? '', 600)];
    q("DELETE FROM plans WHERE status = 'draft'");
    q("INSERT INTO plans (status, start_day, end_day, content, created_at) VALUES ('draft', ?, ?, ?, ?)",
        [$weeks[0], date('Y-m-d', strtotime(end($weeks) . ' +6 days')), json_encode($content, JSON_UNESCAPED_UNICODE), time()]);
    $id = (int)db()->lastInsertId();
    out(['draft' => plan_row(q('SELECT * FROM plans WHERE id = ?', [$id])->fetch())]);
}

function clean_plan_week(array $w): array
{
    $out = ['start' => (string)$w['start']];
    foreach (array_keys(SUBJECTS) as $sub) $out[$sub] = clean($w[$sub] ?? '', 120);
    $out['note'] = clean($w['note'] ?? '', 200);
    return $out;
}

function r_parent_plan_save(array $b): never
{
    $row = plan_row(q("SELECT * FROM plans WHERE id = ? AND status IN ('draft', 'approved')", [(int)($b['id'] ?? 0)])->fetch() ?: null);
    if (!$row) out(['error' => 'bad_request'], 400);
    $edited = [];
    foreach (is_array($b['weeks'] ?? null) ? $b['weeks'] : [] as $w) {
        if (is_array($w) && isset($w['start'])) $edited[(string)$w['start']] = $w;
    }
    $weeks = [];
    foreach ($row['content']['weeks'] as $w) $weeks[] = clean_plan_week(array_merge($w, $edited[$w['start']] ?? [], ['start' => $w['start']]));
    $content = json_encode(['weeks' => $weeks, 'summary' => $row['content']['summary'] ?? ''], JSON_UNESCAPED_UNICODE);
    if (!empty($b['approve'])) {
        db()->beginTransaction();
        q("UPDATE plans SET status = 'old' WHERE status = 'approved' AND id <> ?", [$row['id']]);
        q("UPDATE plans SET content = ?, status = 'approved', approved_at = ? WHERE id = ?", [$content, time(), $row['id']]);
        db()->commit();
    } else {
        q('UPDATE plans SET content = ? WHERE id = ?', [$content, $row['id']]);
    }
    r_parent_plan([]);
}

// Cổng ba mẹ: đăng nhập bằng mã PIN ba mẹ → phiên 7 ngày (cookie HttpOnly, SameSite=Strict, chỉ gửi tới /api/)
function r_parent_login(array $b): never
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'may') out(['error' => 'bad_request'], 400);
    check_pin('parent', clean($b['pin'] ?? '', 40), (string)cfg('parent_pin', ''), 'bad_parent_pin');
    create_parent_session();
    out(['ok' => true, 'days' => max(1, (int)cfg('parent_session_days', 7))]);
}

function r_parent_logout(array $b): never
{
    $token = (string)($_COOKIE[SESSION_COOKIE] ?? '');
    if ($token !== '') q('DELETE FROM parent_sessions WHERE token_hash = ?', [hash('sha256', $token)]);
    set_session_cookie('', 0);
    out(['ok' => true]);
}

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
    $subjects = array_map(fn($r) => ['subject' => $r['subject'], 'quizzes' => (int)$r['quizzes'],
                                     'correct' => (int)$r['correct'], 'total' => (int)$r['total']], $subjects);
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
    $speaking = [];
    foreach (q('SELECT t.transcript, t.level, t.created_at, t.idx, s.type, s.items FROM speaking_tries t JOIN speaking s ON s.id = t.session_id
                ORDER BY t.id DESC LIMIT 30')->fetchAll() as $t) {
        $it = (json_decode($t['items'], true) ?: [])[$t['idx']] ?? [];
        $speaking[] = ['type' => $t['type'], 'prompt' => $it['text'] ?? $it['q'] ?? $it['topic'] ?? '', 'transcript' => $t['transcript'],
                       'level' => $t['level'], 'created_at' => (int)$t['created_at']];
    }
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
        'today' => day_plan(today()),
        'shop_pending' => (int)q("SELECT COUNT(*) FROM redemptions WHERE status = 'pending'")->fetchColumn(),
        'skills' => q('SELECT subject, skill, checks, passed, explained, last_score, level, updated_at FROM skill_mastery ORDER BY updated_at DESC LIMIT 30')->fetchAll(),
        'writings' => array_map(fn($r) => ['subject' => $r['subject'], 'prompt' => $r['prompt'], 'submits' => (int)$r['submits'], 'created_at' => (int)$r['created_at'],
            'first' => json_decode((string)$r['first_result'], true) ?: null, 'last' => json_decode((string)$r['result'], true) ?: null],
            q('SELECT subject, prompt, submits, first_result, result, created_at FROM writings WHERE submits > 0 ORDER BY id DESC LIMIT 8')->fetchAll()),
        'speaking' => $speaking,
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
    $row['drive'] = q('SELECT file_id, name FROM drive_files WHERE homework_id = ? AND deleted_at IS NULL ORDER BY id', [$row['id']])->fetchAll();
    out(['homework' => $row]);
}

/* ---------------- Sổ sao và luật thưởng (Góc ba mẹ) ---------------- */

function r_parent_rewards(array $b): never
{
    $rules = array_map(fn($r) => ['code' => $r['code'], 'label' => $r['label'], 'stars' => (int)$r['stars'],
        'daily_cap' => $r['daily_cap'] === null ? null : (int)$r['daily_cap'], 'weekly_cap' => $r['weekly_cap'] === null ? null : (int)$r['weekly_cap'],
        'enabled' => (bool)$r['enabled']], q('SELECT * FROM reward_rules ORDER BY sort, code')->fetchAll());
    $ledger = q('SELECT created_at, rule_code, stars, note, balance_after FROM star_ledger ORDER BY id DESC LIMIT 60')->fetchAll();
    $week = q("SELECT COALESCE(SUM(CASE WHEN stars > 0 AND rule_code IN (SELECT code FROM reward_rules) THEN stars END), 0) AS earned,
                      COALESCE(SUM(CASE WHEN rule_code = 'task_complete' THEN 1 END), 0) AS tasks
               FROM star_ledger WHERE week = ?", [week_start()])->fetch();
    out(['balance' => stars(), 'rules' => $rules, 'ledger' => $ledger,
         'daily_max' => (int)setting('stars_daily_max', '40'), 'weekly_goal' => (int)setting('weekly_goal_tasks', '12'),
         'week' => ['earned' => (int)$week['earned'], 'tasks' => (int)$week['tasks']]]);
}

function r_parent_rewards_save(array $b): never
{
    $cap = fn($v) => ($v === null || $v === '') ? null : max(0, min(100, (int)$v));
    foreach (is_array($b['rules'] ?? null) ? $b['rules'] : [] as $r) {
        if (!is_array($r)) continue;
        q('UPDATE reward_rules SET stars = ?, daily_cap = ?, weekly_cap = ?, enabled = ?, updated_at = ? WHERE code = ?',
            [max(0, min(100, (int)($r['stars'] ?? 0))), $cap($r['daily_cap'] ?? null), $cap($r['weekly_cap'] ?? null),
             !empty($r['enabled']) ? 1 : 0, time(), (string)($r['code'] ?? '')]);
    }
    if (isset($b['daily_max'])) set_setting('stars_daily_max', (string)max(0, min(500, (int)$b['daily_max'])));
    if (isset($b['weekly_goal'])) set_setting('weekly_goal_tasks', (string)max(0, min(50, (int)$b['weekly_goal'])));
    r_parent_rewards([]);
}

/* ---------------- Đổi quà (Góc ba mẹ) ---------------- */

function r_parent_shop(array $b): never
{
    $rewards = q('SELECT * FROM rewards ORDER BY enabled DESC, cost, id')->fetchAll();
    $pending = q("SELECT * FROM redemptions WHERE status = 'pending' ORDER BY id")->fetchAll();
    $history = q("SELECT * FROM redemptions WHERE status <> 'pending' ORDER BY id DESC LIMIT 30")->fetchAll();
    out(['balance' => stars(), 'rewards' => $rewards, 'pending' => $pending, 'history' => $history]);
}

// Thêm/sửa quà (id = 0 là thêm mới). Không xoá quà đã có lịch sử: chỉ tắt đi.
function r_parent_shop_save(array $b): never
{
    $name = clean($b['name'] ?? '', 120);
    $cost = (int)($b['cost'] ?? 0);
    if ($name === '' || $cost < 1 || $cost > 10000) out(['error' => 'bad_request'], 400);
    $lim = fn($v) => ($v === null || $v === '') ? null : max(0, min(1000, (int)$v));
    $vals = [$name, clean($b['description'] ?? '', 255), $cost, !empty($b['enabled']) ? 1 : 0, $lim($b['weekly_limit'] ?? null), $lim($b['stock'] ?? null), time()];
    $id = (int)($b['id'] ?? 0);
    if ($id > 0) q('UPDATE rewards SET name = ?, description = ?, cost = ?, enabled = ?, weekly_limit = ?, stock = ?, updated_at = ? WHERE id = ?', [...$vals, $id]);
    else q('INSERT INTO rewards (name, description, cost, enabled, weekly_limit, stock, updated_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, time()]);
    r_parent_shop([]);
}

function r_parent_shop_decide(array $b): never
{
    $r = redeem_decide((int)($b['id'] ?? 0), (string)($b['decision'] ?? ''), clean($b['note'] ?? '', 255));
    if (isset($r['error'])) out($r, 409);
    r_parent_shop([]);
}

/* ---------------- Google Drive (Góc ba mẹ) ---------------- */

function r_parent_google(array $b): never
{
    $stat = q('SELECT COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS bytes FROM drive_files WHERE deleted_at IS NULL')->fetch();
    out(['configured' => google_configured(), 'connected' => drive_connected(), 'email' => setting('google_email'),
         'files' => (int)$stat['n'], 'bytes' => (int)$stat['bytes'], 'keep_days' => max(30, (int)cfg('drive_keep_days', 365)),
         'redirect_uri' => google_configured() ? google_redirect_uri() : '', 'backup' => backup_status()]);
}

function r_parent_google_connect(array $b): never
{
    if (!google_configured()) out(['error' => 'google_not_configured'], 400);
    $state = bin2hex(random_bytes(16));
    set_setting('google_state', json_encode(['s' => hash('sha256', $state), 'exp' => time() + 600]));
    out(['url' => google_auth_url($state)]);
}

function google_callback(): never
{
    $go = function (string $result): never {
        header('Content-Type: text/html; charset=utf-8');
        header('Location: /parents/?google=' . $result, true, 302);
        exit;
    };
    $saved = json_decode(setting('google_state', '{}'), true) ?: [];
    set_setting('google_state', '');
    $state = (string)($_GET['state'] ?? '');
    if (!google_configured() || ($saved['exp'] ?? 0) < time() || !hash_equals((string)($saved['s'] ?? ''), hash('sha256', $state))) $go('bad_state');
    if (isset($_GET['error']) || empty($_GET['code'])) $go('denied');
    try {
        $d = google_token_request(['grant_type' => 'authorization_code', 'code' => (string)$_GET['code'], 'redirect_uri' => google_redirect_uri()]);
        if (empty($d['refresh_token'])) $go('no_refresh');
        set_setting('google_refresh', seal((string)$d['refresh_token']));
        set_setting('google_access', json_encode(['t' => seal((string)$d['access_token']), 'exp' => time() + (int)($d['expires_in'] ?? 3600)]));
        [$code, $about] = drive_api('GET', '/about?fields=user(emailAddress)');
        set_setting('google_email', $code === 200 ? clean($about['user']['emailAddress'] ?? '', 120) : '');
        drive_folder(DRIVE_ROOT, null);
    } catch (Throwable $e) {
        error_log('[hoc-cung-may] Google connect: ' . $e->getMessage());
        $go('error');
    }
    $go('ok');
}

function r_parent_google_disconnect(array $b): never
{
    $refresh = unseal(setting('google_refresh'));
    if ($refresh !== '') {
        google_http('POST', (string)cfg('google_oauth_base', 'https://oauth2.googleapis.com') . '/revoke',
            ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $refresh]));
    }
    foreach (['google_refresh', 'google_access', 'google_email', 'drive_folders'] as $k) set_setting($k, '');
    r_parent_google([]);
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
    $tables = match ($what) {
        'chats' => ['messages', 'conversations'],
        'all' => ['messages', 'conversations', 'quizzes', 'mistakes', 'activity', 'homework', 'speaking_tries', 'speaking'],
        default => out(['error' => 'bad_request'], 400),
    };
    $pdo->beginTransaction();
    foreach ($tables as $t) $pdo->exec("DELETE FROM {$t}");
    $pdo->commit();
    // Sổ sao không bị xoá: thêm một dòng đưa số dư về 0
    if ($what === 'all' && stars() !== 0) ledger_add('reset', -stars(), 'reset:' . time() . ':' . bin2hex(random_bytes(3)), 'Ba mẹ xoá toàn bộ dữ liệu học');
    out(['ok' => true]);
}
