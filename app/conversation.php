<?php
declare(strict_types=1);
require_once __DIR__ . '/conversation_logic.php';

function companion_context(): array
{
    $day = today();
    $off = in_array($day, (array)cfg('school_days_off', []), true);
    $expected = $off ? [] : companion_expected(schedule(), $day);
    $subjects = [];
    foreach (SUBJECTS as $id => $subject) {
        $modes = ['homework' => 'Bài cô giao', 'quiz' => 'Đố vui', 'chat' => 'Hỏi bài Mây'];
        if (in_array($id, ['tv', 'ceng'], true)) $modes['write'] = 'Tập làm văn';
        if ($id === 'ceng') {
            $modes += ['read' => 'Đọc hiểu', 'speak' => 'Luyện nói'];
            if (tts_enabled()) $modes['listen'] = 'Luyện nghe';
        }
        if ($id === 'cmath') $modes['wp'] = 'Toán có lời văn';
        $subjects[] = ['id' => $id] + $subject + ['modes' => $modes];
    }
    return ['day' => $day, 'timezone' => (string)cfg('timezone', 'Asia/Ho_Chi_Minh'),
        'day_off' => $off, 'expected' => $expected, 'subjects' => $subjects,
        'retention_days' => max(1, (int)cfg('chat_retention_days', 30))];
}

function companion_lock(callable $fn): array
{
    if (!(int)q("SELECT GET_LOCK('may_companion', 5)")->fetchColumn()) return ['error' => 'conversation_busy'];
    try { return $fn(); }
    finally { q("SELECT RELEASE_LOCK('may_companion')"); }
}

function companion_row(int $id = 0): ?array
{
    if ($id) return q('SELECT * FROM companion_sessions WHERE conv_id = ?', [$id])->fetch() ?: null;
    return q('SELECT * FROM companion_sessions ORDER BY updated_at DESC, conv_id DESC LIMIT 1')->fetch() ?: null;
}

function companion_expire(int $cut): array
{
    return companion_lock(function () use ($cut) {
        // Apply the same retention period even when the companion conversation remains active.
        q('DELETE m FROM messages m JOIN companion_sessions s ON s.conv_id = m.conv_id WHERE m.created_at < ?', [$cut]);
        q('DELETE FROM companion_events WHERE created_at < ?', [$cut]);
        foreach (q('SELECT conv_id, state FROM companion_sessions')->fetchAll() as $session) {
            $state = json_decode($session['state'], true) ?: [];
            foreach (['recaps', 'schedule_changes'] as $key) {
                $state[$key] = array_values(array_filter($state[$key] ?? [], fn($r) => ($r['day'] ?? '') >= date('Y-m-d', $cut)));
            }
            $json = json_encode($state, JSON_UNESCAPED_UNICODE);
            if ($json !== $session['state']) q('UPDATE companion_sessions SET state = ?, revision = revision + 1 WHERE conv_id = ?', [$json, $session['conv_id']]);
        }
        return ['ok' => true];
    });
}

function companion_payload(array $row): array
{
    return ['session' => ['id' => (int)$row['conv_id'], 'revision' => (int)$row['revision'],
        'state' => companion_day(json_decode($row['state'], true), today()),
        'messages' => array_reverse(q('SELECT id, role, content, created_at FROM messages WHERE conv_id = ? ORDER BY id DESC LIMIT 80', [$row['conv_id']])->fetchAll())],
        'context' => companion_context(), 'stars' => stars()];
}

function companion_result(array $data): never
{
    $code = isset($data['error']) ? match ($data['error']) {
        'session_gone' => 404, 'conversation_conflict', 'conversation_busy' => 409, default => 400,
    } : 200;
    out($data, $code);
}

function r_companion_session(array $b): never
{
    companion_result(companion_lock(function () {
        $row = companion_row();
        if (!$row) {
            $pdo = db(); $pdo->beginTransaction();
            try {
                $now = time();
                q("INSERT INTO conversations (subject, topic, started_at, updated_at) VALUES ('companion', '', ?, ?)", [$now, $now]);
                $id = (int)$pdo->lastInsertId();
                q('INSERT INTO companion_sessions (conv_id, state, revision, updated_at) VALUES (?, ?, 0, ?)',
                    [$id, json_encode(companion_initial(today()), JSON_UNESCAPED_UNICODE), $now]);
                $name = setting('nick') ?: 'Kem';
                q("INSERT INTO messages (conv_id, role, content, created_at) VALUES (?, 'assistant', ?, ?)",
                    [$id, "Chào {$name}! Hôm nay có chuyện gì vui không? Mây muốn nghe bạn kể nè! ☁️", $now]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            $row = companion_row($id);
        }
        return companion_payload($row);
    }));
}

// Resolve only activities already implemented by the app. Client/model never supplies grades/stars.
function companion_card(array $input, array $context): ?array
{
    $view = (string)($input['view'] ?? '');
    $subject = (string)($input['subject'] ?? '');
    $catalog = array_column($context['subjects'], null, 'id');
    if ($view === 'review') return ['view' => 'review', 'subject' => null, 'topic' => ''];
    if (!isset($catalog[$subject]['modes'][$view])) return null;
    return ['view' => $view, 'subject' => $subject, 'topic' => clean($input['topic'] ?? '', 80)];
}

function companion_lesson(array $b, string $view): string
{
    $id = (int)($b['companion_id'] ?? 0);
    if (!$id) return '';
    $row = companion_row($id);
    if (!$row) return '';
    $s = companion_day(json_decode($row['state'], true), today());
    if (($s['active_card']['view'] ?? '') !== $view) return '';
    $subject = $s['active_card']['subject'];
    foreach (array_reverse($s['recaps']) as $recap) {
        if ($recap['kind'] === 'lesson_report' && $recap['subject'] === $subject) return clean($recap['text'], 120);
    }
    return '';
}

function r_companion_turn(array $b): never
{
    $nonce = (string)($b['request_id'] ?? '');
    $type = (string)($b['type'] ?? 'text');
    $allowed = ['text', 'joy', 'recap', 'direct', 'rest', 'resume', 'subject', 'skip', 'offer', 'learning', 'reflection'];
    if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/', $nonce) || !in_array($type, $allowed, true)) out(['error' => 'bad_request'], 400);
    $text = clean($b['text'] ?? '', 1500);
    if ($type === 'text' && $text === '') out(['error' => 'bad_request'], 400);
    companion_result(companion_lock(function () use ($b, $type, $text, $nonce) {
        $row = companion_row((int)($b['session_id'] ?? 0));
        if (!$row) return ['error' => 'session_gone'];
        $id = (int)$row['conv_id'];
        if (q('SELECT 1 FROM companion_events WHERE conv_id = ? AND nonce = ?', [$id, $nonce])->fetchColumn()) return companion_payload($row);
        if ((int)($b['revision'] ?? -1) !== (int)$row['revision']) return ['error' => 'conversation_conflict'];
        $context = companion_context();
        $event = ['type' => $type, 'text' => $text];
        if ($type === 'subject') {
            $choices = $context['expected'];
            foreach ($context['subjects'] as $sub) $choices[] = ['key' => $sub['id'], 'label' => $sub['name'], 'subject' => $sub['id']];
            $choice = array_column($choices, null, 'key')[(string)($b['subject_key'] ?? '')] ?? null;
            if (!$choice) return ['error' => 'bad_request'];
            $event['selected'] = $choice;
        }
        if ($type === 'learning') {
            $event['card'] = companion_card((array)($b['card'] ?? []), $context);
            if (!$event['card']) return ['error' => 'bad_request'];
        }
        $state = companion_day(json_decode($row['state'], true), today());
        [$state, $reply, $evidence] = companion_reduce($state, $event, $context['expected']);
        if ($reply === null) {
            spend(1);
            $history = array_reverse(q('SELECT role, content FROM messages WHERE conv_id = ? ORDER BY id DESC LIMIT 12', [$id])->fetchAll());
            while ($history && $history[0]['role'] !== 'user') array_shift($history);
            $history[] = ['role' => 'user', 'content' => $text];
            $reply = trim(ask_claude((string)cfg('chat_model', 'claude-haiku-5-5'),
                companion_system($state, $context['expected'], setting('nick')), $history, 1200, 60, (string)cfg('chat_effort', 'low')));
            if ($reply === '') throw new UpstreamError('empty_companion_reply');
        }
        $labels = ['joy' => '😊 Có chuyện vui!', 'recap' => '📚 Kể chuyện học', 'direct' => '✏️ Làm bài luôn',
            'rest' => '😴 Hơi mệt rồi', 'resume' => 'Mình quay lại rồi', 'subject' => 'Mình muốn kể môn ' . ($event['selected']['label'] ?? ''),
            'skip' => 'Bỏ qua', 'offer' => 'Cùng ôn bài này', 'learning' => 'Mở hoạt động học', 'reflection' => 'Mình quay lại trò chuyện'];
        $pdo = db(); $pdo->beginTransaction();
        try {
            $now = time();
            q("INSERT INTO messages (conv_id, role, content, created_at) VALUES (?, 'user', ?, ?)", [$id, $text ?: ($labels[$type] ?? '') , $now]);
            $messageId = (int)$pdo->lastInsertId();
            if ($evidence) $state['recaps'][count($state['recaps']) - 1]['message_id'] = $messageId;
            q("INSERT INTO messages (conv_id, role, content, created_at) VALUES (?, 'assistant', ?, ?)", [$id, $reply, $now]);
            q('UPDATE companion_sessions SET state = ?, revision = revision + 1, updated_at = ? WHERE conv_id = ?',
                [json_encode($state, JSON_UNESCAPED_UNICODE), $now, $id]);
            q('INSERT INTO companion_events (conv_id, nonce, created_at) VALUES (?, ?, ?)', [$id, $nonce, $now]);
            q('UPDATE conversations SET updated_at = ? WHERE id = ?', [$now, $id]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        return companion_payload(companion_row($id));
    }));
}

function r_companion_checkpoint(array $b): never
{
    companion_result(companion_lock(function () use ($b) {
        $row = companion_row((int)($b['session_id'] ?? 0));
        if (!$row) return ['error' => 'session_gone'];
        $s = json_decode($row['state'], true);
        $card = $s['active_card'];
        // A delayed checkpoint cannot reopen a card after reflection/rest or replace another card.
        if (!$card || ($b['view'] ?? '') !== $card['view'] || (int)($b['revision'] ?? -1) !== (int)$row['revision']) return ['error' => 'conversation_conflict'];
        $ref = max(0, (int)($b['ref'] ?? 0));
        $tables = ['quiz' => 'quizzes', 'read' => 'readings', 'listen' => 'readings', 'wp' => 'readings', 'write' => 'writings', 'speak' => 'speaking', 'homework' => 'homework', 'chat' => 'conversations'];
        if ($ref && (!isset($tables[$card['view']]) || !q('SELECT id FROM ' . $tables[$card['view']] . ' WHERE id = ?', [$ref])->fetchColumn())) return ['error' => 'bad_request'];
        if ($ref && isset($tables[$card['view']]) && $card['view'] !== 'speak') {
            $linked = q('SELECT subject FROM ' . $tables[$card['view']] . ' WHERE id = ?', [$ref])->fetchColumn();
            if ($linked !== $card['subject']) return ['error' => 'bad_request'];
        }
        if ($ref && in_array($card['view'], ['read', 'wp', 'listen'], true)) {
            if (q('SELECT kind FROM readings WHERE id = ?', [$ref])->fetchColumn() !== $card['view']) return ['error' => 'bad_request'];
        }
        if ($ref) $s['active_card']['ref'] = $ref;
        q('UPDATE companion_sessions SET state = ? WHERE conv_id = ?', [json_encode($s, JSON_UNESCAPED_UNICODE), $row['conv_id']]);
        return ['ok' => true];
    }));
}

// Resume an existing server-owned activity without generating a new one or accepting a client score.
function r_companion_activity(array $b): never
{
    $row = companion_row((int)($b['session_id'] ?? 0));
    if (!$row) out(['error' => 'session_gone'], 404);
    $card = (json_decode($row['state'], true) ?: [])['active_card'] ?? null;
    if (!$card) out(['error' => 'bad_request'], 400);
    $id = (int)($card['ref'] ?? 0);
    $view = $card['view'];
    $data = ['card' => $card];
    if (!$id) out($data);
    if ($view === 'quiz') {
        $qz = q('SELECT * FROM quizzes WHERE id = ?', [$id])->fetch();
        if (!$qz) out(['error' => 'activity_gone'], 404);
        $qs = json_decode($qz['questions'], true);
        $answers = json_decode($qz['answers'], true) ?: [];
        $results = [];
        foreach ($qs as $i => &$question) {
            if (array_key_exists($i, $answers)) $results[] = $answers[$i] === $question['answer'];
            else unset($question['answer'], $question['explain']);
        }
        unset($question);
        $data['quiz'] = ['kind' => 'quiz', 'id' => $id, 'qs' => $qs, 'i' => count($answers),
            'results' => $results, 'picked' => null, 'hint' => false, 'waiting' => false];
    } elseif (in_array($view, ['read', 'wp', 'listen'], true)) {
        $r = q('SELECT * FROM readings WHERE id = ? AND kind = ?', [$id, $view])->fetch();
        if (!$r) out(['error' => 'activity_gone'], 404);
        $data['activity'] = match ($view) { 'read' => reading_for_kid($r), 'wp' => wp_for_kid($r), 'listen' => listening_for_kid($r) };
    } elseif ($view === 'write') {
        $r = q('SELECT * FROM writings WHERE id = ?', [$id])->fetch();
        if (!$r) out(['error' => 'activity_gone'], 404);
        $data['writing'] = writing_for_kid($r);
    } elseif ($view === 'chat') {
        $r = q('SELECT id FROM conversations WHERE id = ? AND subject = ?', [$id, $card['subject']])->fetch();
        if (!$r) out(['error' => 'activity_gone'], 404);
        $data['chat'] = ['id' => $id, 'messages' => q('SELECT role, content FROM messages WHERE conv_id = ? ORDER BY id', [$id])->fetchAll()];
    } elseif ($view === 'speak') {
        $r = q('SELECT * FROM speaking WHERE id = ?', [$id])->fetch();
        if (!$r) out(['error' => 'activity_gone'], 404);
        $data['speaking'] = ['sid' => $id, 'type' => $r['type'], 'topic' => $r['topic'],
            'items' => json_decode($r['items'], true), 'i' => 0, 'phase' => 'item', 'res' => [],
            'tries' => [], 'live' => '', 'showVi' => false, 'audio' => [], 'blob' => [], 'pron' => []];
        $tries = q('SELECT idx, transcript, result FROM speaking_tries WHERE session_id = ? ORDER BY id', [$id])->fetchAll();
        foreach ($tries as $try) {
            $i = (int)$try['idx'];
            $data['speaking']['res'][$i] = json_decode($try['result'], true);
            $data['speaking']['tries'][$i] = ($data['speaking']['tries'][$i] ?? 0) + 1;
            $data['speaking']['i'] = $i;
            $data['speaking']['live'] = $try['transcript'];
        }
        // No fresh grading/award request is made on restore.
    }
    out($data);
}
