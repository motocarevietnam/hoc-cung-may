<?php
declare(strict_types=1);

// Database-only quiz readiness. This file never confirms lessons, approves content,
// creates quizzes, awards stars, or invokes an AI provider.
function quiz_access_labels(string $subject): array
{
    return match ($subject) {
        'toan' => ['Toán'], 'tv' => ['Tiếng Việt'], 'cmath' => ['Math'],
        'ceng' => ['English ET', 'English LT'], 'csci' => ['Science'], default => [],
    };
}
function quiz_topic_key(string $topic): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $topic) ?? ''), 0, 255);
}
function quiz_access_catalog(string $subject): array
{
    $labels = quiz_access_labels($subject);
    if (!$labels) return [];
    $marks = implode(',', array_fill(0, count($labels), '?'));
    $rows = q("SELECT p.school_day,p.period_no,p.planned_content,c.status
        FROM school_lesson_plan p LEFT JOIN school_lesson_confirmations c
        ON c.school_day=p.school_day AND c.period_no=p.period_no
        WHERE p.subject IN ($marks) AND p.school_day<=?
        ORDER BY p.school_day DESC,p.period_no ASC", [...$labels, today()])->fetchAll();
    $catalog = [];
    foreach ($rows as $r) {
        $topic = quiz_topic_key((string)$r['planned_content']);
        if ($topic === '') continue;
        if (!isset($catalog[$topic])) {
            $catalog[$topic] = ['topic'=>$topic, 'school_day'=>$r['school_day'],
                'period'=>(int)$r['period_no'], 'confirmed'=>false,
                'latest_status'=>$r['status'] ?? null];
        }
        if ($r['status'] === 'taught') $catalog[$topic]['confirmed'] = true;
    }
    return $catalog;
}
function quiz_resolve_topic(string $requested, array $topics): ?string
{
    $requested = quiz_topic_key($requested);
    if (in_array($requested, $topics, true)) return $requested;
    // Old clients cut topic text at 80/140/200 characters. Resolve a prefix only
    // when its length is an old boundary AND it identifies exactly one lesson.
    if (!in_array(mb_strlen($requested), [80,140,200], true)) return null;
    $matches = array_values(array_filter($topics, fn($t)=>str_starts_with($t, $requested)));
    return count($matches) === 1 ? $matches[0] : null;
}
function quiz_access_choices(string $subject, array $catalog): array
{
    $counts = [];
    foreach (q("SELECT topic,COUNT(*) n FROM question_bank WHERE subject=? AND status='approved'
        GROUP BY topic", [$subject])->fetchAll() as $r) $counts[(string)$r['topic']] = (int)$r['n'];
    $choices = [];
    foreach ($catalog as $lesson) {
        $topic = $lesson['topic'];
        if (empty($counts[$topic])) continue;
        if (!$lesson['confirmed'] && $lesson['latest_status'] === 'not_taught') continue;
        // This is a count only. The endpoint never exposes unconfirmed questions.
        $available = count(quiz_bank_pick($subject, $topic, 10));
        if ($available === 0) continue;
        $choices[] = ['topic'=>$topic, 'school_day'=>$lesson['school_day'],
            'period'=>$lesson['period'], 'requires_confirmation'=>!$lesson['confirmed'],
            'available_count'=>$available, 'approved_count'=>$counts[$topic]];
        if (count($choices) >= 12) break;
    }
    usort($choices, fn($a,$b)=>[(int)$a['requires_confirmation'], -strtotime($a['school_day'])]
        <=> [(int)$b['requires_confirmation'], -strtotime($b['school_day'])]);
    return $choices;
}
function quiz_prepare_from_bank(string $subject, string $requested): array
{
    $catalog = quiz_access_catalog($subject);
    $confirmed = array_keys(array_filter($catalog, fn($x)=>$x['confirmed']));
    $topic = $requested === '' ? '' : quiz_resolve_topic($requested, array_keys($catalog));
    // Check learning eligibility BEFORE selecting any questions, including 10+ sets.
    $needsConfirmation = !$confirmed || ($requested !== '' && ($topic === null || !in_array($topic,$confirmed,true)));
    $questions = $needsConfirmation ? [] : quiz_bank_pick($subject, $topic ?? '', 10);
    if ($questions) return ['ready'=>true, 'topic'=>$topic ?? '', 'questions'=>$questions];
    $approved = (int)q("SELECT COUNT(*) FROM question_bank WHERE subject=? AND status='approved'",[$subject])->fetchColumn();
    $code = $needsConfirmation ? 'quiz_lesson_confirmation_required' : 'quiz_no_fresh_questions';
    if (!$needsConfirmation && $approved === 0) $code = 'quiz_bank_not_ready';
    return ['ready'=>false, 'error'=>$code, 'subject'=>$subject,
        'approved_subject_count'=>$approved, 'confirmed_topic_count'=>count($confirmed),
        'choices'=>quiz_access_choices($subject,$catalog), 'source'=>'question_bank',
        'ai_generation'=>false];
}
