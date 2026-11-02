<?php
// Sao chép file này thành config.php rồi điền thông tin. KHÔNG đưa config.php lên GitHub.
return [
    // Cơ sở dữ liệu MariaDB/MySQL. install.sh tự tạo database, user và mật khẩu rồi điền vào đây.
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'hoc_cung_may',
    'db_user' => 'hoc_cung_may',
    'db_pass' => 'DOI_MAT_KHAU_NAY',

    // Claude API key, lấy tại console.anthropic.com
    'anthropic_api_key' => 'sk-ant-...',

    // Mã PIN nhập trên iPad của bé (một lần mỗi máy)
    'app_pin' => '246813',

    // Mã PIN riêng của ba mẹ: xem báo cáo, lịch sử trò chuyện, cài đặt. Phải KHÁC app_pin.
    'parent_pin' => '97531864',

    // Model: chat cần nhanh, soạn đố vui cần chính xác
    'chat_model' => 'claude-haiku-5-5',
    'quiz_model' => 'claude-sonnet-5-5',
    // Chấm bài tập về nhà từ ảnh chụp: cần đọc chữ viết tay và chấm chính xác
    'homework_model' => 'claude-sonnet-5-5',

    // Mức "suy nghĩ" của Claude cho từng việc: low (nhanh, rẻ) / medium / high (kỹ hơn, chậm và tốn hơn)
    'chat_effort' => 'low',
    'quiz_effort' => 'medium',
    'homework_effort' => 'medium',

    // Số lượt tối đa mỗi ngày (1 tin nhắn = 1, 1 lượt đố vui = 3, 1 lần chấm bài tập = 5)
    'daily_limit' => 150,

    // Lịch sử trò chuyện tự xoá sau bao nhiêu ngày
    'chat_retention_days' => 30,

    // Giữ bao nhiêu bản sao lưu hằng đêm
    'backup_keep' => 14,

    'timezone' => 'Asia/Ho_Chi_Minh',
];
