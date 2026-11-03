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

    // Giọng đọc tự nhiên (Google Cloud Text-to-Speech). Để trống key thì Mây dùng giọng có sẵn của iPad.
    // Lấy key: console.cloud.google.com → bật "Cloud Text-to-Speech API" (project cần gắn thanh toán, có hạn mức
    // miễn phí hằng tháng) → APIs & Services → Credentials → Create credentials → API key → Restrict key:
    // chỉ chọn Cloud Text-to-Speech API.
    'google_tts_key' => '',
    // Có cả key Google và Azure thì chọn 'google' hoặc 'azure' (mặc định Google)
    'tts_provider' => '',

    // Mây nghe phát âm (Luyện nói): Gemini API trong project Google Cloud CÓ GẮN THANH TOÁN (gói trả phí:
    // Google không dùng giọng của bé để huấn luyện). Lấy key: aistudio.google.com/apikey → Create API key → chọn project.
    'gemini_api_key' => '',
    'gemini_model' => 'gemini-flash-latest',

    // Hoặc Microsoft Azure Speech (có giọng bé gái cho tiếng Anh).
    // Lấy key: portal.azure.com → tạo tài nguyên "Speech" → Keys and Endpoint → Key 1 và Location/Region.
    'azure_speech_key' => '',
    'azure_speech_region' => 'southeastasia',

    // Lưu ảnh bài tập (đã nén) lên Google Drive của gia đình. Để trống thì không lưu ảnh ở đâu cả.
    // Tạo OAuth client (Web application) trên console.cloud.google.com, bật Google Drive API,
    // Authorized redirect URI: https://TEN-MIEN/api/google/callback. Ba mẹ bấm "Kết nối" trong Góc ba mẹ.
    'google_client_id' => '',
    'google_client_secret' => '',
    // Giữ ảnh trên Drive bao nhiêu ngày rồi tự xoá (365 = 1 năm học)
    'drive_keep_days' => 365,
    // Mỗi đêm gửi một bản sao lưu dữ liệu học lên thư mục "Học cùng Mây – Sao lưu" trên Drive, giữ bao nhiêu bản
    'drive_backup_keep' => 30,

    // Cổng ba mẹ /parents/: đăng nhập bằng parent_pin, giữ đăng nhập bao nhiêu ngày
    'parent_session_days' => 7,

    // Số lượt tối đa mỗi ngày (1 tin nhắn = 1, 1 lượt đố vui = 3, 1 lần chấm bài tập = 5)
    'daily_limit' => 150,

    // Lịch sử trò chuyện tự xoá sau bao nhiêu ngày
    'chat_retention_days' => 30,

    // Giữ bao nhiêu bản sao lưu hằng đêm
    'backup_keep' => 14,

    'timezone' => 'Asia/Ho_Chi_Minh',
];
