<?php
declare(strict_types=1);
/*
 * QA Engine: applies reviewer decisions to question_bank with full audit trail.
 *
 * Decision item (JSON):
 *  {question_id, expected_hash, reviewer, model, verdict: pass|fail|uncertain,
 *   predicted_answer (0-based, solved blind), decision: approve|reject|needs_review|fix,
 *   reason, category?, duplicate_of?, fix?: {q?,options?,answer?,explain?,hint?}, post_fix_predicted?}
 *
 * Rules:
 *  - Only rows in pending/needs_review are touched. approved/rejected rows and anything a
 *    parent decided (approved_by='parent' or a parent-resolved case) are skipped, never overwritten.
 *  - expected_hash must equal sha256(q \n options-json \n answer) of the current row: a review
 *    of older content is never reused.
 *  - approve  => verdict=pass AND predicted_answer==answer; sets approved_by='claude_qa'.
 *  - reject   => non-empty reason AND (verdict=fail OR duplicate_of points to an approved question).
 *  - fix      => content edited in-transaction, old content archived in question_qa_cases,
 *                fingerprint recomputed and checked; approved only if post_fix_predicted==new answer.
 *  - needs_review => reason required.
 *  - Idempotent: the same (question, reviewer, model, content hash, decision) is applied once.
 */

function qa_content_hash(string $q, array $options, int $answer): string
{
    return hash('sha256', $q . "\n" . json_encode($options, JSON_UNESCAPED_UNICODE) . "\n" . $answer);
}

function qa_fingerprint(string $q): string
{
    // Same normalisation as quiz_norm() in app/lib.php.
    return hash('sha256', trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($q)) ?? ''));
}

function qa_tag(array $d, string $hash): string
{
    return '[qa ' . substr($hash, 0, 12) . ' ' . $d['decision'] . ']';
}

/** Validate shape only; returns error string or null. */
function qa_validate_item(array $d): ?string
{
    if (!is_int($d['question_id'] ?? null)) return 'question_id';
    if (!in_array($d['verdict'] ?? null, ['pass', 'fail', 'uncertain'], true)) return 'verdict';
    if (!is_int($d['predicted_answer'] ?? null) || $d['predicted_answer'] < 0 || $d['predicted_answer'] > 5) return 'predicted_answer';
    if (!in_array($d['decision'] ?? null, ['approve', 'reject', 'needs_review', 'fix'], true)) return 'decision';
    if (trim((string)($d['reason'] ?? '')) === '') return 'reason';
    if (!is_string($d['expected_hash'] ?? null) || strlen($d['expected_hash']) !== 64) return 'expected_hash';
    if (trim((string)($d['reviewer'] ?? '')) === '' || trim((string)($d['model'] ?? '')) === '') return 'reviewer/model';
    if ($d['decision'] === 'approve' && $d['verdict'] !== 'pass') return 'approve needs pass';
    if ($d['decision'] === 'fix' && (!is_array($d['fix'] ?? null) || !$d['fix'])) return 'fix payload';
    if ($d['decision'] === 'fix' && array_key_exists('post_fix_predicted', $d) && !is_int($d['post_fix_predicted']) && $d['post_fix_predicted'] !== null) return 'post_fix_predicted';
    if ($d['decision'] === 'reject' && $d['verdict'] !== 'fail' && !is_int($d['duplicate_of'] ?? null)) return 'reject needs fail or duplicate_of';
    return null;
}

/**
 * Apply one decision inside the caller's transaction.
 * Returns ['outcome'=>approved|rejected|needs_review|skipped|error, 'detail'=>string].
 */
function qa_apply_item(PDO $pdo, array $d, int $now): array
{
    if ($err = qa_validate_item($d)) return ['outcome' => 'error', 'detail' => "invalid: $err"];
    $id = $d['question_id'];
    $st = $pdo->prepare('SELECT * FROM question_bank WHERE id=? FOR UPDATE');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['outcome' => 'error', 'detail' => 'missing'];
    $opts = json_decode((string)$row['options'], true);
    if (!is_array($opts) || !isset($opts[(int)$row['answer']])) return ['outcome' => 'error', 'detail' => 'bad options'];
    $hash = qa_content_hash((string)$row['q'], $opts, (int)$row['answer']);
    $tag = qa_tag($d, $d['expected_hash']);

    // Idempotency first: re-running a decision that already landed is a silent skip.
    $dup = $pdo->prepare('SELECT COUNT(*) FROM question_qa_reviews WHERE question_id=? AND reviewer=? AND model=? AND reason LIKE ?');
    $dup->execute([$id, $d['reviewer'], $d['model'], $tag . '%']);
    if ((int)$dup->fetchColumn() > 0) return ['outcome' => 'skipped', 'detail' => 'already applied'];

    if (!in_array($row['status'], ['pending', 'needs_review'], true)) return ['outcome' => 'skipped', 'detail' => 'status=' . $row['status']];
    if (($row['approved_by'] ?? null) === 'parent') return ['outcome' => 'skipped', 'detail' => 'parent decision'];
    $pc = $pdo->prepare("SELECT COUNT(*) FROM question_qa_cases WHERE question_id=? AND resolved_by='parent'");
    $pc->execute([$id]);
    if ((int)$pc->fetchColumn() > 0) return ['outcome' => 'skipped', 'detail' => 'parent resolved case'];
    if (!hash_equals($hash, $d['expected_hash'])) return ['outcome' => 'error', 'detail' => 'content changed since review'];

    $answer = (int)$row['answer'];
    $review = $pdo->prepare('INSERT INTO question_qa_reviews(question_id,reviewer,verdict,predicted_answer,reason,model,reviewed_at) VALUES (?,?,?,?,?,?,?)');
    $reason = mb_substr($tag . ' ' . trim((string)$d['reason']), 0, 1000);
    $setStatus = $pdo->prepare('UPDATE question_bank SET status=?, approved_by=? WHERE id=?');

    switch ($d['decision']) {
        case 'approve':
            if ($d['predicted_answer'] !== $answer) {
                $review->execute([$id, $d['reviewer'], 'uncertain', $d['predicted_answer'], $reason . ' [engine: predicted≠answer -> needs_review]', $d['model'], $now]);
                $setStatus->execute(['needs_review', null, $id]);
                return ['outcome' => 'needs_review', 'detail' => 'answer mismatch'];
            }
            $review->execute([$id, $d['reviewer'], 'pass', $d['predicted_answer'], $reason, $d['model'], $now]);
            $setStatus->execute(['approved', 'claude_qa', $id]);
            return ['outcome' => 'approved', 'detail' => ''];

        case 'reject':
            if (is_int($d['duplicate_of'] ?? null)) {
                $k = $pdo->prepare("SELECT status FROM question_bank WHERE id=?");
                $k->execute([$d['duplicate_of']]);
                if ($k->fetchColumn() !== 'approved') return ['outcome' => 'error', 'detail' => 'duplicate_of not approved'];
            }
            $review->execute([$id, $d['reviewer'], $d['verdict'], $d['predicted_answer'], $reason, $d['model'], $now]);
            $setStatus->execute(['rejected', null, $id]);
            $pdo->prepare("INSERT INTO question_qa_cases(question_id,status,reason,reported_by,resolution,resolved_by,created_at,resolved_at) VALUES (?,'resolved',?,'claude_qa',?,'claude_qa',?,?)")
                ->execute([$id, mb_substr((string)($d['category'] ?? 'reject'), 0, 500), mb_substr('reject: ' . $reason, 0, 5000), $now, $now]);
            return ['outcome' => 'rejected', 'detail' => ''];

        case 'needs_review':
            $review->execute([$id, $d['reviewer'], $d['verdict'], $d['predicted_answer'], $reason, $d['model'], $now]);
            $setStatus->execute(['needs_review', null, $id]);
            return ['outcome' => 'needs_review', 'detail' => ''];

        case 'fix':
            $new = ['q' => $row['q'], 'options' => $opts, 'answer' => $answer, 'explain' => $row['explain'], 'hint' => $row['hint']];
            foreach (['q', 'explain', 'hint'] as $f) if (isset($d['fix'][$f])) $new[$f] = (string)$d['fix'][$f];
            if (isset($d['fix']['options'])) $new['options'] = array_values($d['fix']['options']);
            if (isset($d['fix']['answer'])) $new['answer'] = (int)$d['fix']['answer'];
            if (!isset($new['options'][$new['answer']])) return ['outcome' => 'error', 'detail' => 'fix: bad answer index'];
            $fp = qa_fingerprint($new['q']);
            $c = $pdo->prepare('SELECT id FROM question_bank WHERE subject=? AND fingerprint=? AND id<>? LIMIT 1');
            $c->execute([$row['subject'], $fp, $id]);
            if ($c->fetchColumn()) return ['outcome' => 'error', 'detail' => 'fix: fingerprint collision'];
            $old = ['q' => $row['q'], 'options' => $opts, 'answer' => $answer, 'explain' => $row['explain'], 'hint' => $row['hint']];
            $pdo->prepare("INSERT INTO question_qa_cases(question_id,status,reason,reported_by,resolution,resolved_by,created_at,resolved_at) VALUES (?,'resolved',?,'claude_qa',?,'claude_qa',?,?)")
                ->execute([$id, 'claude_fix', json_encode(['old' => $old, 'new' => $new, 'why' => $d['reason']], JSON_UNESCAPED_UNICODE), $now, $now]);
            $pdo->prepare('UPDATE question_bank SET q=?,options=?,answer=?,`explain`=?,hint=?,fingerprint=? WHERE id=?')
                ->execute([$new['q'], json_encode($new['options'], JSON_UNESCAPED_UNICODE), $new['answer'], $new['explain'], $new['hint'], $fp, $id]);
            $review->execute([$id, $d['reviewer'], $d['verdict'], $d['predicted_answer'], $reason . ' [nội dung cũ đã lưu trong question_qa_cases]', $d['model'], $now]);
            $newHash = substr(qa_content_hash($new['q'], $new['options'], $new['answer']), 0, 12);
            if (!is_int($d['post_fix_predicted'] ?? null)) {
                // Two-phase fix (default): the edited question goes back to pending and must be
                // solved again from the stored content before any approval.
                $setStatus->execute(['pending', null, $id]);
                return ['outcome' => 'pending', 'detail' => 'fixed, awaiting recheck'];
            }
            if ($d['post_fix_predicted'] === $new['answer']) {
                $review->execute([$id, $d['reviewer'], 'pass', $d['post_fix_predicted'], "[qa $newHash recheck] Kiểm lại sau sửa: đáp án duy nhất, khớp.", $d['model'], $now]);
                $setStatus->execute(['approved', 'claude_qa', $id]);
                return ['outcome' => 'approved', 'detail' => 'fixed'];
            }
            $review->execute([$id, $d['reviewer'], 'uncertain', $d['post_fix_predicted'], "[qa $newHash recheck] Sau sửa đáp án tự giải không khớp -> needs_review.", $d['model'], $now]);
            $setStatus->execute(['needs_review', null, $id]);
            return ['outcome' => 'needs_review', 'detail' => 'fixed but recheck mismatch'];
    }
    return ['outcome' => 'error', 'detail' => 'unreachable'];
}

/** Apply a whole list atomically: any error rolls everything back unless $commit is false (dry run always rolls back). */
function qa_apply_all(PDO $pdo, array $items, bool $commit, int $now): array
{
    $sum = ['approved' => [], 'rejected' => [], 'needs_review' => [], 'pending' => [], 'skipped' => [], 'error' => []];
    $pdo->beginTransaction();
    try {
        foreach ($items as $d) {
            $r = qa_apply_item($pdo, $d, $now);
            $sum[$r['outcome']][] = ($d['question_id'] ?? '?') . ($r['detail'] !== '' ? ':' . $r['detail'] : '');
        }
        if ($sum['error'] || !$commit) $pdo->rollBack(); else $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $sum['error'][] = 'exception: ' . $e->getMessage();
    }
    return $sum;
}
