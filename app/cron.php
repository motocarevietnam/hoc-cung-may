<?php
// Chạy mỗi đêm bằng cron (user "may"): xoá trò chuyện cũ, sao lưu cơ sở dữ liệu.
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

// 3. Sao lưu bằng mysqldump: một file .sql.gz mỗi ngày, giữ N bản gần nhất.
//    Mật khẩu đưa qua file tạm chỉ user "may" đọc được, không lộ trên dòng lệnh.
$dir = __DIR__ . '/backups';
if (!is_dir($dir)) mkdir($dir, 0750, true);
$file = $dir . '/may-' . date('Y-m-d') . '.sql.gz';

$cnf = tempnam(sys_get_temp_dir(), 'may-dump-');
chmod($cnf, 0600);
file_put_contents($cnf, sprintf("[client]\nhost=%s\nport=%d\nuser=%s\npassword=\"%s\"\n",
    cfg('db_host', '127.0.0.1'), (int)cfg('db_port', 3306), cfg('db_user', 'hoc_cung_may'),
    addcslashes((string)cfg('db_pass', ''), "\\\"")));

$dump = is_executable('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : 'mysqldump';
$cmd = escapeshellarg($dump) . ' --defaults-extra-file=' . escapeshellarg($cnf)
     . ' --single-transaction --skip-lock-tables --default-character-set=utf8mb4 '
     . escapeshellarg((string)cfg('db_name', 'hoc_cung_may'));
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$ok = false;
$err = '';
if (is_resource($proc)) {
    $gz = gzopen($file . '.part', 'wb6');
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1 << 16);
        if ($chunk !== false && $chunk !== '') gzwrite($gz, $chunk);
    }
    gzclose($gz);
    $err = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $ok = proc_close($proc) === 0;
}
unlink($cnf);

if ($ok) {
    rename($file . '.part', $file);
    chmod($file, 0640);
    $keep = max(1, (int)cfg('backup_keep', 14));
    $all = glob($dir . '/may-*.sql.gz') ?: [];
    sort($all);
    foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $old) unlink($old);
    echo date('c') . " ok: xoá {$deletedConvs} cuộc trò chuyện cũ, sao lưu " . basename($file) . PHP_EOL;
} else {
    @unlink($file . '.part');
    fwrite(STDERR, date('c') . " LỖI sao lưu: {$err}" . PHP_EOL);
    exit(1);
}
