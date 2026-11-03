<?php
declare(strict_types=1);

/**
 * Đọc mục lục từ dữ liệu có phiên bản, không hardcode tên bài trong logic.
 * Không phụ thuộc DB; có thể mở rộng nhiều bộ sách/tập bằng registry.
 */
function curriculum_catalog(string $id): ?array
{
    if (!preg_match('/^[a-z0-9-]+$/', $id)) return null;
    $path = __DIR__ . '/curricula/' . $id . '.json';
    if (!is_file($path)) return null;
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || ($data['id'] ?? '') !== $id || !is_array($data['topics'] ?? null)) return null;
    return $data;
}

function curriculum_toc_text(string $id): string
{
    $catalog = curriculum_catalog($id);
    if (!$catalog) return '';
    $lines = [];
    foreach ($catalog['topics'] as $topic) {
        $lines[] = 'Chủ đề ' . (int)$topic['number'] . ': ' . $topic['title'];
        foreach ($topic['lessons'] ?? [] as $lesson) {
            $lines[] = 'Bài ' . (int)$lesson['number'] . ': ' . $lesson['title'];
        }
    }
    foreach ($catalog['reviews'] ?? [] as $review) {
        $lines[] = 'Ôn tập: ' . $review['title'] . ' (sau bài ' . (int)$review['after_lesson'] . ')';
    }
    return implode("\n", $lines);
}

function curriculum_for_book(string $book, string $subject, int $grade, int $volume): ?array
{
    if ($grade !== 4 || $volume !== 1) return null;
    if (stripos($book, 'Kết nối tri thức') === false && stripos($book, 'Ket noi tri thuc') === false) return null;
    $ids = ['toan' => 'vn-kntt-math-grade4-volume1', 'tv' => 'vn-kntt-vietnamese-grade4-volume1'];
    return isset($ids[$subject]) ? curriculum_catalog($ids[$subject]) : null;
}
