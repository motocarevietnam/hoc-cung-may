# HỌC CÙNG MÂY — NHIỆM VỤ KIỂM ĐỊNH NGÂN HÀNG CÂU HỎI
Ngày giao: 2026-11-12. Repo: motocarevietnam/hoc-cung-may.
VPS application: /var/www/hoc-cung-may. Đây là tài liệu giao việc cho Claude Code và Gemini CLI.

## Vai trò và phạm vi
ChatGPT tạo câu hỏi trong cửa sổ chat và nhập bằng SSH, mặc định status=pending. Claude và Gemini là hai người kiểm tra độc lập; KHÔNG tạo câu hỏi thay ChatGPT, KHÔNG tự chỉnh sửa nội dung gốc, KHÔNG tự duyệt câu hỏi, KHÔNG merge PR hoặc sửa production nếu chưa được chủ dự án chấp thuận.
Môn: Toán, Tiếng Việt lớp 4 Kết nối tri thức; Cambridge Primary Maths, English, Science Stage 4.

## Quy trình bắt buộc
1. Kiểm tra repo, database schema và các script hiện có: scripts/import_chatgpt_questions.php, scripts/qa_question_bank.php, app/lib.php, public/api.php. Không giả định mọi PR đã merge.
2. Đọc câu hỏi từ MariaDB bằng tài khoản chỉ đọc hoặc dữ liệu JSON xuất từ VPS. Không gửi bí mật, config, dữ liệu cá nhân của Kem vào prompt.
3. Mỗi AI nhận câu hỏi và các lựa chọn, môn, chủ đề; KHÔNG nhận đáp án đánh dấu hoặc kết quả kiểm tra của AI kia. Tự giải từ đầu.
4. Kiểm tra: đáp án duy nhất; độ chính xác; lời giải/gợi ý; tính rõ nghĩa; phù hợp lớp 4; thuật ngữ và đơn vị; câu trùng/biến thể gần trùng. Toán cần kiểm tra bằng thuật toán khi khả thi.
5. Trả JSON cho từng question_id với reviewer, model, verdict (pass/fail/uncertain), predicted_answer (0-based), reason, issues, curriculum_match, timestamp. Không đoán khi thiếu cơ sở.
6. QA Engine (không phải AI reviewer) so khớp hai kết quả với đáp án gốc. Chỉ approved khi cả hai pass, cùng đáp án, đạt kiểm tra cấu trúc/thuật toán. Lỗi CLI, timeout, JSON không hợp lệ, bất đồng => needs_review; lỗi đã xác minh => rejected theo quy trình có ghi nhận.
7. Lưu dấu vết: question_id, phiên bản hoặc hash nội dung, hai kết quả, thời gian, model, quyết định. Sửa câu => hủy hiệu lực kiểm duyệt cũ và yêu cầu kiểm tra lại.
8. Phụ huynh báo lỗi => cách ly ngay câu khỏi Quiz mới, tạo case và log; phụ huynh xử lý ngoại lệ. Không được tự ép approved từ dashboard.

## An toàn khi thực hiện
- Bắt đầu bằng kiểm tra READ-ONLY, tối đa 5 câu thí điểm; chưa chạy hàng loạt.
- Không dùng chế độ bỏ qua xác nhận quyền, không cho AI CLI tự thực thi SQL ghi dữ liệu. CLI chỉ xuất kết quả JSON vào thư mục QA riêng.
- Kiểm tra trạng thái xác thực và quyền sử dụng Claude Code CLI / Gemini CLI. Không sử dụng API key Claude nếu mục tiêu là CLI; không khẳng định miễn phí.
- Trước khi viết mã: mô tả phương án, ảnh hưởng production, kiểm thử, chi phí/quota. Tạo branch + PR; không tự merge.
- Không đưa câu hỏi legacy status=approved vào nhóm dual-verified nếu chưa thực sự kiểm tra cả hai.

## Tiêu chí nghiệm thu
- Cùng 5 câu test: Claude và Gemini trả JSON hợp lệ độc lập; có cả trường hợp pass, fail, uncertain, lỗi CLI.
- Chỉ câu dual-pass mới có thể được công nhận dual_ai_approved; các trường hợp khác bị cách ly.
- Có unit tests cho đối chiếu đáp án, chống trùng, lỗi API/CLI và kiểm tra quyền phụ huynh.
- Có thống kê số pending / dual-verified / needs_review / rejected, log không lộ bí mật.
- Quiz của Kem chỉ lấy câu được phép sử dụng theo chính sách chuyển đổi có kiểm soát.

## Báo cáo cho chủ dự án
Ghi rõ: đã làm gì; file/commit/PR; kết quả từng CLI; lỗi còn tồn tại; tổng câu đã kiểm; số đạt/không đạt/chờ; chi phí hoặc quota quan sát được; đề xuất bước tiếp theo. Không báo đã hoàn tất nếu chưa kiểm thử.
