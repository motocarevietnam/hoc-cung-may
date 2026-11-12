# CLAUDE CODE — KIỂM DUYỆT NGÂN HÀNG CÂU HỎI

Ngày giao: 12/11/2026. Dự án: Học Cùng Mây / Kem's Buddy AI.
Repository: motocarevietnam/hoc-cung-may
VPS app: /var/www/hoc-cung-may
Tài liệu quy trình: /root/hoc-cung-may/QUESTION_BANK_QA_TASK.md

## Nhiệm vụ hiện tại
Đọc MariaDB và kiểm duyệt các câu đang chờ theo thứ tự:
1. 100 câu Toán, chủ đề **Bài 25. Tìm hai số biết tổng và hiệu của hai số đó** (pending).
2. 10 câu Cambridge Maths, chủ đề **UNIT 3 - Numbers with Many Digits** (pending).
3. 1 câu Cambridge English đang needs_review: xem nguyên nhân và đề xuất xử lý, không tự ép approved.
4. Rà soát dần các câu approved cũ chưa có approved_by=claude_qa, nhưng KHÔNG gắn nhãn kiểm duyệt cho câu chưa thực sự kiểm tra.

Số lượng là ảnh chụp tại lúc tạo file; luôn truy vấn lại trước khi xử lý để tránh duyệt trùng.

## Quy trình kiểm tra
- Thực hiện trực tiếp trong **phiên chat Claude Code hiện tại**, chia lô tối đa 20 câu.
- Với mỗi câu: đọc đề và lựa chọn trước, tự giải **không nhìn đáp án gốc**; sau đó mới đối chiếu answer/explain/hint.
- Kiểm tra đáp án duy nhất, tính đúng đắn, mức độ lớp 4, ngôn ngữ, đơn vị, trùng/gần trùng và tính đa dạng.
- Bài 25: ngoài kiểm phép tính, đặc biệt phát hiện các câu chỉ thay số, lặp dạng bài; 100 câu biến thể không đồng nghĩa 100 kỹ năng khác nhau.
- PASS khi đúng và không mơ hồ; FAIL khi có lỗi; UNCERTAIN khi thiếu căn cứ. Không đoán.
- Ghi question_id, reviewer=claude, model, verdict, predicted_answer, reason, thời điểm vào question_qa_reviews qua quy trình an toàn; cập nhật approved_by=claude_qa **chỉ sau** khi QA Engine xác nhận PASS và đáp án trùng.
- FAIL/UNCERTAIN => needs_review, ghi lý do để phụ huynh thấy trong Cổng phụ huynh. Không tự sửa rồi duyệt; không tự xóa câu.
- Trước khi ghi dữ liệu, xem schema thực tế, backup, giao dịch, kiểm tra trạng thái/phiên bản câu và chống ghi trùng. Không tái sử dụng kết quả kiểm tra cũ khi nội dung thay đổi.

## Giao diện và bảo mật
- Cổng phụ huynh: https://kembuddy.antifake.vn/parents/#questionqa ; kiểm tra danh sách 10 câu/trang, lọc trạng thái, xem đáp án, báo lỗi, loại câu.
- Không làm lộ API key, PIN, mật khẩu hoặc dữ liệu cá nhân của Kem.
- **Không gọi Anthropic API riêng, không chạy thêm lệnh claude -p hay tạo Claude CLI con, không gọi Gemini API/CLI.**
- Không tự tạo câu hỏi thay ChatGPT. Không merge PR. Nếu phải sửa mã nguồn, tạo branch/PR và báo cáo trước.
- Chỉ dùng nguồn bài Kem thực sự đã học để đưa vào Visual Quiz; câu pending/needs_review không được dùng.

## Báo cáo sau mỗi lô
Nêu số kiểm, PASS/FAIL/UNCERTAIN, số approved, số needs_review, số còn pending, trùng/gần trùng, dạng kỹ năng còn thiếu, và file/commit/PR nếu có. Nếu thiếu quyền hoặc công cụ, báo rõ thay vì tự động gọi dịch vụ trả phí.

**Bắt đầu từ 100 câu Bài 25, tiếp đến 10 câu Cambridge Maths.**
