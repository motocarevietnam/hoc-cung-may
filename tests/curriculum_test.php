<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/curriculum.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
$id = 'vn-kntt-math-grade4-volume1';
$c = curriculum_catalog($id);
check($c !== null, 'catalog loads');
check(count($c['topics']) === 7, '7 topics');
$numbers = [];
foreach ($c['topics'] as $i => $topic) {
    check($topic['number'] === $i + 1, 'topic ordering');
    foreach ($topic['lessons'] as $lesson) {
        $numbers[] = $lesson['number'];
        check(trim($lesson['title']) !== '', 'lesson title');
    }
}
check($numbers === range(1, 37), '37 sequential lessons');
check(str_contains(curriculum_toc_text($id), 'Bài 37: Ôn tập chung'), 'last lesson');
check(curriculum_for_book('Kết nối tri thức với cuộc sống', 'toan', 4, 1) !== null, 'matching textbook');
check(curriculum_for_book('Cánh Diều', 'toan', 4, 1) === null, 'different textbook not mapped');
check(curriculum_catalog('../config') === null, 'path traversal rejected');
$tv = curriculum_for_book('Kết nối tri thức với cuộc sống', 'tv', 4, 1);
check($tv !== null, 'Vietnamese catalog loads');
check(count($tv['topics']) === 4, '4 Vietnamese topics');
check(array_sum(array_map(fn($t) => count($t['lessons']), $tv['topics'])) === 32, '32 Vietnamese lessons');
check(count($tv['reviews']) === 2, 'two review milestones');
check(str_contains(curriculum_toc_text((string)$tv['id']), 'Ôn tập cuối học kì I'), 'final review included');
echo "OK: 7 topics, 37 lessons, catalog resolution and safety\n";
