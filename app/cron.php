<?php
// Chạy mỗi đêm bằng cron (user "may"): xoá trò chuyện cũ, sao lưu cơ sở dữ liệu (trên VPS và Google Drive).
//   0 3 * * * /usr/bin/php /var/www/hoc-cung-may/app/cron.php
// Kiểm tra kết nối và tạo bảng (dùng khi cài đặt):
//   sudo -u may php /var/www/hoc-cung-may/app/cron.php --check
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/lib.php';

$pdo = db();

if (($argv[1] ?? '') === '--check') {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    echo "ok: kết nối cơ sở dữ liệu '" . cfg('db_name', 'hoc_cung_may') . "' được, có {$n} bảng" . PHP_EOL;
    exit(0);
}

// 1. Xoá cuộc trò chuyện cũ hơn số ngày quy định (tin nhắn tự xoá theo, nhờ ON DELETE CASCADE)
$days = max(1, (int)cfg('chat_retention_days', 30));
$cut = strtotime("-{$days} days");
$st = $pdo->prepare('DELETE FROM conversations WHERE updated_at < ?');
$st->execute([$cut]);
$deletedConvs = $st->rowCount();

// 2. Dọn bảng đếm lượt, đề đố vui và kết quả chấm bài cũ (giữ 60 ngày; ảnh bài tập không bao giờ được lưu)
$pdo->prepare('DELETE FROM daily_usage WHERE day < ?')->execute([date('Y-m-d', strtotime('-60 days'))]);
$pdo->prepare('DELETE FROM quizzes WHERE created_at < ?')->execute([strtotime('-60 days')]);
$pdo->prepare('DELETE FROM homework WHERE created_at < ?')->execute([strtotime('-60 days')]);
// Luyện nói: chỉ có chữ do iPad nhận dạng (không có âm thanh), giữ 60 ngày
$pdo->prepare('DELETE FROM speaking WHERE created_at < ?')->execute([strtotime('-60 days')]);

// Dọn phiên đăng nhập hết hạn và các lần nhập sai PIN cũ
$pdo->prepare('DELETE FROM parent_sessions WHERE expires_at < ?')->execute([time()]);
$pdo->prepare('DELETE FROM auth_failures WHERE created_at < ?')->execute([time() - 86400]);

// Dọn file giọng đọc lưu tạm (Azure) không dùng tới trong 30 ngày
foreach (glob(__DIR__ . '/cache/tts/*.mp3') ?: [] as $f) {
    if (filemtime($f) < strtotime('-30 days')) @unlink($f);
}

// Xoá ảnh bài tập trên Google Drive quá hạn (mặc định 365 ngày = 1 năm học)
try {
    $deletedDrive = drive_cleanup();
    if ($deletedDrive) echo date('c') . " Drive: xoá {$deletedDrive} ảnh quá hạn" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' LỖI Drive: ' . $e->getMessage() . PHP_EOL);
}

// 3. Sao lưu: một file .sql.gz mỗi ngày trên VPS (giữ backup_keep bản), rồi gửi một bản lên Google Drive nếu đã kết nối
try {
    $file = db_backup_local();
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' LỖI ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
echo date('c') . " ok: xoá {$deletedConvs} cuộc trò chuyện cũ, sao lưu " . basename($file) . PHP_EOL;

if (drive_connected()) {
    try {
        $b = drive_backup($file);
        echo date('c') . ' Drive: đã gửi bản sao lưu ' . $b['name'] . ' (' . round($b['bytes'] / 1024) . ' KB)' . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . ' LỖI sao lưu lên Drive: ' . $e->getMessage() . PHP_EOL);
    }
}
