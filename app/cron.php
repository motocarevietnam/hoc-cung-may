<?php
// Chạy mỗi đêm bằng cron (user "may"): xoá trò chuyện cũ, sao lưu cơ sở dữ liệu.
//   0 3 * * * /usr/bin/php /var/www/hoc-cung-may/app/cron.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/lib.php';

$pdo = db();

// 1. Xoá cuộc trò chuyện cũ hơn số ngày quy định (tin nhắn tự xoá theo, nhờ ON DELETE CASCADE)
$days = max(1, (int)cfg('chat_retention_days', 30));
$cut = strtotime("-{$days} days");
$st = $pdo->prepare('DELETE FROM conversations WHERE updated_at < ?');
$st->execute([$cut]);
$deletedConvs = $st->rowCount();
$pdo->exec('DELETE FROM messages WHERE conv_id NOT IN (SELECT id FROM conversations)');

// 2. Dọn bảng đếm lượt và đề đố vui cũ (giữ 60 ngày)
$pdo->prepare('DELETE FROM usage WHERE day < ?')->execute([date('Y-m-d', strtotime('-60 days'))]);
$pdo->prepare('DELETE FROM quizzes WHERE created_at < ?')->execute([strtotime('-60 days')]);

// 3. Sao lưu: một file mỗi ngày, giữ N bản gần nhất
$dir = __DIR__ . '/backups';
if (!is_dir($dir)) mkdir($dir, 0750, true);
$file = $dir . '/may-' . date('Y-m-d') . '.sqlite';
if (is_file($file)) unlink($file);
$pdo->exec('VACUUM INTO ' . $pdo->quote($file));
chmod($file, 0640);

$keep = max(1, (int)cfg('backup_keep', 14));
$all = glob($dir . '/may-*.sqlite') ?: [];
sort($all);
foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $old) unlink($old);

echo date('c') . " ok: xoá {$deletedConvs} cuộc trò chuyện cũ, sao lưu " . basename($file) . PHP_EOL;
