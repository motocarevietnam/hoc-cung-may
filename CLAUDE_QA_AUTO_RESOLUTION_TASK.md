# CLAUDE CODE — TỰ ĐỘNG KIỂM DUYỆT VÀ XỬ LÝ CÂU HỎI TRÙNG LẶP

Dự án: Kem's Buddy AI / Học Cùng Mây
Repository: motocarevietnam/hoc-cung-may
VPS ứng dụng: /var/www/hoc-cung-may
Tài liệu nền: /root/hoc-cung-may/CLAUDE_QUESTION_QA_TASK.md

## Mục tiêu
Claude chịu trách nhiệm kiểm duyệt và xử lý các câu hỏi; phụ huynh chỉ xử lý ngoại lệ. Không đẩy hàng loạt câu đã giải đúng sang phụ huynh chỉ vì trùng cấu trúc.

Số liệu tại lần kiểm tra gần nhất: 780 câu, 133 needs_review, 50 pending. Truy vấn MariaDB để xác nhận số liệu MỚI NHẤT trước khi xử lý.

## Kiểm tra từng câu
1. Đọc đề và lựa chọn, tự giải độc lập trước khi đối chiếu answer/explain/hint.
2. Kiểm tra đáp án đúng duy nhất, lời giải, thuật ngữ, độ khó và sự phù hợp với lớp 4 / Cambridge Primary Stage 4.
3. Đối chiếu với nội dung trường đã dạy, phân biệt kế hoạch dự kiến và bài Kem xác nhận đã học.
4. Kiểm tra trùng lặp trên TOÀN BỘ question_bank và câu đã dùng trong quizzes: trùng chính xác, chuẩn hóa chữ hoa/dấu câu, thay số/tên riêng, trùng cấu trúc, trùng ngữ nghĩa.
5. Phân biệt câu cùng kiến thức nhưng khác kỹ năng (nhận biết/giải thích/vận dụng/sửa lỗi), và biến thể hữu ích cho ôn tập giãn cách.

## Quyết định kiểm duyệt
- PASS: đúng, rõ ràng, duy nhất một đáp án, đủ khác biệt hoặc là biến thể có giá trị => approved.
- FAIL chắc chắn: sai kiến thức/đáp án, hoặc trùng hoàn toàn/biến thể máy móc không cần thiết => rejected; ghi lý do và giữ bản ghi.
- UNCERTAIN: thực sự mơ hồ hoặc có xung đột cần phụ huynh quyết định => needs_review.
- Không chuyển needs_review chỉ vì cùng cấu trúc nếu Claude có đủ căn cứ chọn câu đại diện và xử lý.
- Câu có thể sửa: lưu bản sửa có lịch sử, đưa về pending và KIỂM TRA LẠI trước khi approved; không tự phê duyệt bản sửa chưa được kiểm chứng.
- Không tự động loại câu chỉ vì cùng chủ đề. Không xóa vật lý, không mất lịch sử Quiz, sao thưởng hoặc tiến độ của Kem.
- Không ghi đè quyết định phụ huynh mà không có lý do và audit trail.

## Xử lý theo thứ tự
1. Rà soát toàn bộ needs_review, nhất là những câu review verdict=pass nhưng bị gắn needs_review vì trùng cấu trúc. Tự xác định câu đại diện, biến thể hữu ích, câu cần loại; chỉ chuyển phụ huynh ngoại lệ.
2. Kiểm duyệt tất cả pending theo dữ liệu thực tế, bao gồm các lô Tiếng Việt – Biện pháp nhân hóa; Cambridge Science – Light/Heat; Cambridge English – Compare and Contrast; Toán – Yến, tạ, tấn.
3. Xác minh mọi câu approved cũ không có approved_by=claude_qa chỉ được gắn nhãn Claude sau khi kiểm duyệt thực sự.

## QA Engine / MariaDB
- Trước khi ghi: sao lưu dữ liệu liên quan, kiểm tra schema, foreign keys, trạng thái hiện tại, fingerprint và lịch sử câu.
- Lập báo cáo dự kiến PASS/REJECT/UNCERTAIN trước khi cập nhật; kiểm thử bằng giao dịch rollback hoặc staging.
- Cập nhật theo giao dịch, có điều kiện trạng thái và kiểm tra nội dung không đổi; idempotent, chống duyệt trùng.
- Ghi đầy đủ question_qa_reviews: reviewer=claude, verdict, predicted_answer, reason, model, reviewed_at; ghi trường hợp loại/trùng vào audit tương ứng.
- Chỉ đặt approved_by=claude_qa khi Claude thực sự giải độc lập và PASS. approved_by=parent giữ riêng.
- Visual Quiz chỉ lấy approved và kiến thức Kem đã xác nhận học; pending/needs_review/rejected không được dùng.
- Nếu sửa code, tạo branch và PR; KHÔNG merge main.

## Giảm chi phí AI
Thực hiện trong PHIÊN CHAT CLAUDE CODE HIỆN TẠI. Không gọi Anthropic API riêng, không tạo lệnh claude -p/Claude CLI con, không gọi Gemini API/CLI, không tạo vòng lặp gọi mô hình. Dùng SQL/Python/PHP để chuẩn hóa, phát hiện trùng chính xác, thống kê; Claude tự đánh giá đáp án và ngữ nghĩa trong phiên hiện tại.

## Báo cáo
Trước khi cập nhật: số câu từng trạng thái, danh sách nhóm trùng theo ID, đề xuất giữ/loại, tác động tới Quiz lịch sử.
Sau mỗi lô tối đa 20 câu: PASS, FAIL vì sai, FAIL vì trùng, biến thể ôn tập được giữ, UNCERTAIN, pending còn lại.
Sau khi hoàn thành: thống kê đối chiếu DB và Cổng phụ huynh, thay đổi mã nguồn/PR, kiểm thử và rủi ro còn lại.

**Bắt đầu phân tích 133 needs_review theo trạng thái thực tế, xử lý trường hợp kết luận chắc chắn, rồi kiểm duyệt pending. Không yêu cầu phụ huynh giải lại các câu Claude đã kiểm chứng.**
