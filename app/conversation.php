<?php
declare(strict_types=1);
require_once __DIR__ . '/conversation_logic.php';

function companion_context(): array
{
    $day = today();
    $off = in_array($day, (array)cfg('school_days_off', []), true);
    $expected = $off ? [] : companion_expected(schedule(), $day);
    // Kế hoạch học theo NGÀY ưu tiên hơn lịch tuần; không suy đoán bài đã thực học.
    if (!$off && ($schoolLessons = school_lessons_for_day($day))) {
        $bySubject = [];
        foreach ($schoolLessons as $lesson) {
            $label = trim((string)$lesson['subject']);
            if ($label === '') continue;
            $key = substr(hash('sha256', mb_strtolower($label)), 0, 16);
            if (!isset($bySubject[$key])) {
                $matches = subjects_in($label);
                $bySubject[$key] = ['key' => $key, 'label' => $label,
                    'subject' => count($matches) === 1 ? $matches[0] : null,
                    'planned_lessons' => []];
            }
            $content = trim((string)$lesson['planned_content']);
            if ($content !== '') $bySubject[$key]['planned_lessons'][] = $content;
        }
        $expected = array_values($bySubject);
    }
    $subjects = [];
    foreach (SUBJECTS as $id => $subject) {
        $modes = ['homework' => 'Bài cô giao', 'quiz' => 'Đố vui', 'chat' => 'Hỏi bài Mây'];
        if (in_array($id, ['tv', 'ceng'], true)) $modes['write'] = 'Tập làm văn';
        if ($id === 'ceng') {
            $modes = ['homework' => 'Homework', 'quiz' => 'Quiz', 'chat' => 'Ask Mây',
                      'write' => 'Writing', 'read' => 'Reading', 'speak' => 'Speaking',
                      'ipa' => 'Pronunciation & IPA'];
            if (tts_enabled()) $modes['listen'] = 'Listening';
        }
        if ($id === 'cmath') $modes = ['homework'=>'Homework','quiz'=>'Quiz','chat'=>'Ask Mây','wp'=>'Word Problems'];
        if ($id === 'csci') $modes = ['homework'=>'Homework','quiz'=>'Quiz','chat'=>'Ask Mây','sci'=>'Mini Experiment'];
        if ($id === 'tv' && function_exists('tvw_for_kid')) $modes['tvw'] = 'Luyện từ và câu';
        if ($id === 'csci' && function_exists('sci_for_kid')) $modes['sci'] = 'Thí nghiệm nhỏ';
        $subjects[] = ['id' => $id] + $subject + ['modes' => $modes];
    }
    return ['day' => $day, 'timezone' => (string)cfg('timezone', 'Asia/Ho_Chi_Minh'),
        'day_off' => $off, 'expected' => $expected, 'school_plan_coverage' => school_plan_coverage(), 'subjects' => $subjects,
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

// A new day starts a fresh visible chat, without deleting historical learning evidence.
function companion_day_start(): int
{
    return strtotime(today().' 00:00:00');
}
function companion_daily_greeting(): string
{
    $nick=setting('nick') ?: 'Kem';
    $minutes=(int)date('G')*60+(int)date('i');
    if (($minutes>=420 && $minutes<450)||($minutes>=990 && $minutes<1050)) {
        return "Kem ơi, Mây đây! ☁️ Hôm nay bạn thích hát một đoạn, chơi đoán từ hay kể chuyện vui trước? Nếu Kem đang trên xe và muốn, mình có thể thử một trò English nghe–nói 3 phút nhé. Không cần nhìn màn hình đâu!";
    }
    if ($minutes>=1170 && $minutes<1230) {
        return "Kem ơi, Mây đây! ☁️ Hôm nay có chuyện gì vui không? Nếu bạn đã sẵn sàng, mình cùng xem bài cô giao rồi thử một chút Toán có lời văn hoặc bài đã học nhé. Kem muốn kể chuyện trước cũng được!";
    }
    return "Kem ơi, Mây đây! ☁️ Hôm nay có bài hát hay chuyện gì vui muốn kể không? Khi nào bạn thích, mình có thể cùng ôn một bài ngắn theo những gì Kem đã học nhé!";
}
function companion_ensure_new_day(array $row): array
{
    $state=json_decode((string)$row['state'],true) ?: [];
    if(($state['day']??'')===today())return $row;
    $fresh=companion_day($state,today());
    $now=time();$pdo=db();$pdo->beginTransaction();
    try {
        q('UPDATE companion_sessions SET state=?,revision=revision+1,updated_at=? WHERE conv_id=?',
          [json_encode($fresh,JSON_UNESCAPED_UNICODE),$now,$row['conv_id']]);
        q("INSERT INTO messages (conv_id,role,content,created_at) VALUES (?,'assistant',?,?)",
          [$row['conv_id'],companion_daily_greeting(),$now]);
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    return companion_row((int)$row['conv_id']);
}
function companion_payload(array $row): array
{
    return ['session' => ['id' => (int)$row['conv_id'], 'revision' => (int)$row['revision'],
        'state' => companion_day(json_decode($row['state'], true), today()),
        'messages' => array_reverse(q('SELECT id, role, content, created_at FROM messages WHERE conv_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 80', [$row['conv_id'],companion_day_start()])->fetchAll())],
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
                    [$id, companion_daily_greeting(), $now]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            $row = companion_row($id);
        }
        // Nếu chưa có lượt Kem trả lời hôm nay, nâng lời chào cũ thành lời mời chủ động.
        // Không thêm tin mới mỗi lần mở ứng dụng và không chen vào cuộc trò chuyện đã bắt đầu.
        $row=companion_ensure_new_day($row);
        $first=q("SELECT id,role,content FROM messages WHERE conv_id=? AND created_at>=? ORDER BY id ASC LIMIT 2",
            [$row['conv_id'],companion_day_start()])->fetchAll();
        if (count($first)===1 && $first[0]['role']==='assistant'
            && !str_contains((string)$first[0]['content'],'mình có thể')) {
            q('UPDATE messages SET content=? WHERE id=?',[companion_daily_greeting(),$first[0]['id']]);
        }
        // Điểm danh một lần/ngày khi Kem gặp Mây.
        award('checkin', 'checkin:' . today(), 'Kem gặp Mây và điểm danh');
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
        if ((json_decode((string)$row['state'],true)['day'] ?? '') !== today()) return ['error' => 'conversation_conflict'];
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
        // Câu hỏi lịch học có nguồn dữ liệu xác định; không để AI tự suy đoán.
        if ($reply === null && $type === 'text') {
            $reply = companion_schedule_answer($text, $context['day']);
        }
        if ($reply === null) {
            spend(1);
            $history = array_reverse(q('SELECT role, content FROM messages WHERE conv_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 12', [$id,companion_day_start()])->fetchAll());
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
        $tables = ['quiz' => 'quizzes', 'read' => 'readings', 'listen' => 'readings', 'wp' => 'readings', 'write' => 'writings', 'speak' => 'speaking', 'homework' => 'homework', 'chat' => 'conversations', 'tvw' => 'readings', 'sci' => 'readings'];
        if ($ref && (!isset($tables[$card['view']]) || !q('SELECT id FROM ' . $tables[$card['view']] . ' WHERE id = ?', [$ref])->fetchColumn())) return ['error' => 'bad_request'];
        if ($ref && isset($tables[$card['view']]) && $card['view'] !== 'speak') {
            $linked = q('SELECT subject FROM ' . $tables[$card['view']] . ' WHERE id = ?', [$ref])->fetchColumn();
            if ($linked !== $card['subject']) return ['error' => 'bad_request'];
        }
        if ($ref && in_array($card['view'], ['read', 'wp', 'listen', 'tvw', 'sci'], true)) {
            if (q('SELECT kind FROM readings WHERE id = ?', [$ref])->fetchColumn() !== (['tvw' => 'tvword', 'sci' => 'sciexp'][$card['view']] ?? $card['view'])) return ['error' => 'bad_request'];
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
    } elseif (in_array($view, ['read', 'wp', 'listen', 'tvw', 'sci'], true)) {
        $r = q('SELECT * FROM readings WHERE id = ? AND kind = ?', [$id, ['tvw' => 'tvword', 'sci' => 'sciexp'][$view] ?? $view])->fetch();
        if (!$r) out(['error' => 'activity_gone'], 404);
        $data['activity'] = match ($view) { 'read' => reading_for_kid($r), 'wp' => wp_for_kid($r), 'listen' => listening_for_kid($r), 'tvw' => tvw_for_kid($r), 'sci' => sci_for_kid($r) };
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


function r_companion_school_week(array $b): never
{
    $start=(string)($b['start']??'');
    if (!preg_match('/^2026-\d{2}-\d{2}$/',$start) || date('N',strtotime($start))!=='1') out(['error'=>'bad_week'],400);
    $end=date('Y-m-d',strtotime($start.' +4 days'));
    try {
        $rows=q('SELECT school_day,period_no,time_range,subject,planned_content,preparation FROM school_lesson_plan WHERE school_day BETWEEN ? AND ? ORDER BY school_day,period_no',[$start,$end])->fetchAll();
    } catch (PDOException $e) {out(['error'=>'schedule_unavailable'],503);}
    out(['ok'=>true,'start'=>$start,'end'=>$end,'lessons'=>array_map(fn($x)=>[
      'day'=>$x['school_day'],'period'=>(int)$x['period_no'],'time'=>$x['time_range'],
      'subject'=>$x['subject'],'content'=>$x['planned_content'],'note'=>$x['preparation']],$rows)]);
}


// Trạng thái xác nhận từ Kem, riêng biệt với kế hoạch dự kiến của trường.
function school_lesson_confirmation_rows(string $from, string $to): array
{
    try {
        $rows=q('SELECT school_day,period_no,status FROM school_lesson_confirmations WHERE school_day BETWEEN ? AND ?',[$from,$to])->fetchAll();
    } catch(PDOException $e) {error_log('lesson confirmations: '.$e->getCode());return [];}
    $out=[];foreach($rows as $x)$out[$x['school_day'].'|'.$x['period_no']]=$x['status'];
    return $out;
}
function companion_review_plan(string $day): array
{
    $since=date('Y-m-d',strtotime($day.' -90 days'));
    try {
        $rows=q('SELECT school_day,period_no,subject,planned_content FROM school_lesson_plan WHERE school_day BETWEEN ? AND ? ORDER BY school_day DESC,period_no',[$since,$day])->fetchAll();
    } catch(PDOException $e) {$rows=[];}
    $confirmed=school_lesson_confirmation_rows($since,$day);
    $map=['Toán'=>'toan','Tiếng Việt'=>'tv','Math'=>'cmath','Science'=>'csci','Khoa học'=>'csci','English ET'=>'ceng','English LT'=>'ceng'];
    // Existing completed quiz activity is a signal of prior practice, not proof of mastery.
    $practice=[];
    try {
        foreach(q("SELECT subject,topic,MAX(created_at) last_at,COUNT(*) attempts,
                   SUM(CASE WHEN score IS NOT NULL AND score=total THEN 1 ELSE 0 END) perfect
                   FROM quizzes WHERE finished_at IS NOT NULL AND created_at>=? GROUP BY subject,topic",
                   [strtotime($since.' 00:00:00')])->fetchAll() as $a) {
            $key=$a['subject'].'|'.mb_strtolower(trim((string)$a['topic']));
            $practice[$key]=['last_at'=>(int)$a['last_at'],'attempts'=>(int)$a['attempts'],'perfect'=>(int)$a['perfect']];
        }
    } catch(PDOException $e) { error_log('review practice: '.$e->getCode()); }
    $ready=[];$unconfirmed=[];$seen=[];
    foreach($rows as $r) {
        $label=trim((string)$r['subject']);$id=$map[$label]??null;
        $topic=trim((string)$r['planned_content']);
        if(!$id||$topic==='')continue;
        $dayKey=$r['school_day'].'|'.$r['period_no'];
        $status=$confirmed[$dayKey]??'planned_unconfirmed';
        if($status==='not_taught')continue;
        $topic=mb_substr(preg_replace('/\s+/u',' ',$topic),0,140);
        $dedup=$id.'|'.$topic.'|'.$status;
        if(isset($seen[$dedup]))continue;$seen[$dedup]=true;
        $age=(int)floor((strtotime($day)-strtotime($r['school_day']))/86400);
        $key=$id.'|'.mb_strtolower($topic);
        $p=$practice[$key]??null;
        $last=$p ? (int)floor((strtotime($day)-$p['last_at'])/86400) : $age;
        // 1/3/7/14/30/60-day ladder. A perfect quiz stretches the next interval;
        // no quiz history means lesson age drives the first review.
        $intervals=[1,3,7,14,30,60];
        $attempts=$p['attempts']??0;
        $perfect=$p['perfect']??0;
        $step=$p ? min(5,max(0,$perfect)) : 0;
        $interval=$intervals[$step];
        $due=$last >= $interval;
        $rank=$due ? max(0,$last-$interval) : -1000;
        $item=['key'=>substr(hash('sha256',$dayKey),0,16),'source_day'=>$r['school_day'],'period'=>(int)$r['period_no'],
          'subject'=>$id,'subject_label'=>$label,'topic'=>$topic,'status'=>$status,
          'minutes'=>5,'rank'=>$rank,'days_since'=>$age,
          'interval_days'=>$interval,'days_since_review'=>$last,'due'=>$due,
          'reason'=>$status==='taught'?"Đã xác nhận học $age ngày trước · ".($due?'đến hạn ôn':'chưa đến hạn'):'Trường dự kiến dạy; Kem cần xác nhận trước khi ôn'];
        if($status==='taught' && $due)$ready[]=$item;
        elseif($status!=='taught' && $age<=14)$unconfirmed[]=$item;
    }
    usort($ready,fn($a,$b)=>[$b['rank'],-$b['days_since']]<=>[$a['rank'],-$a['days_since']]);
    // Chỉ bài trường đã xác nhận mới là nhiệm vụ ôn chính.
    $limit=in_array((int)date('N',strtotime($day)),[2,6],true)?2:3;
    $items=array_slice($ready,0,$limit);
    // Bài chưa xác nhận hiển thị trước Sổ ôn tập để Kem chọn bài đã học.
    $unconfirmed=array_slice($unconfirmed,0,12);
    $due=due_count();
    $extra=$due>0?['count'=>$due,'label'=>"$due câu từng làm sai đến hạn"] : null;
    return ['day'=>$day,'items'=>$items,'awaiting_confirmation'=>$unconfirmed,
        'extra_review'=>$extra,'source'=>'school_lesson_plan + confirmations + mistakes'];
}
function r_companion_confirm_lesson(array $b): never
{
    $day=(string)($b['day']??'');$period=(int)($b['period']??0);$status=(string)($b['status']??'');
    if(!preg_match('/^2026-\d{2}-\d{2}$/',$day)||$day>today()||$period<1||$period>8||
       !in_array($status,['taught','not_taught'],true))out(['error'=>'bad_request'],400);
    $row=q('SELECT 1 FROM school_lesson_plan WHERE school_day=? AND period_no=?',[$day,$period])->fetchColumn();
    if(!$row)out(['error'=>'lesson_not_found'],404);
    q('INSERT INTO school_lesson_confirmations(school_day,period_no,status,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),updated_at=VALUES(updated_at)',
       [$day,$period,$status,time()]);
    out(['ok'=>true,'plan'=>companion_review_plan(today())]);
}

function r_companion_review_plan(array $b): never
{
    out(['ok'=>true,'plan'=>companion_review_plan(today())]);
}
