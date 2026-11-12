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

## LÔ MỚI 500 CÂU / 10 CHỦ ĐỀ (12/11/2026)
ChatGPT vừa nhập 500 câu pending vào question_bank: Toán 150 (Bài 14 So sánh số có nhiều chữ số; Bài 11 Hàng và lớp; Bài 18 Đơn vị diện tích), Cambridge Maths 100 (hai chủ đề Unit 5 Addition/Subtraction), Tiếng Việt 100 (Luyện tập về động từ; Tính từ), Cambridge English 100 (Unit 2 Reading scientific vocabulary; Unit 9 Cause and Effect Writing), Cambridge Science 50 (Unit 2 Heat). Truy vấn ID thật từ MariaDB, không dùng khoảng ID giả định.
BẮT BUỘC kiểm tra trùng nguyên văn trên toàn ngân hàng và trùng cấu trúc/ý nghĩa/biến thể chỉ thay số. Lô 500 dùng nhiều khuôn mẫu có tham số: KHÔNG PASS hàng loạt vì đáp án số học đúng. Đánh giá tính tự nhiên của câu, các lựa chọn nhiễu có hợp lý không, lỗi đơn vị, câu hỏi quá dễ, dữ kiện sai, câu tiếng Anh có nhiều đáp án đúng, động từ/tính từ có nghĩa khác theo ngữ cảnh. Câu hỏi về diện tích phải kiểm tra tên cạnh dài/rộng và đơn vị; bài toán so sánh cần dấu đúng; số hàng và lớp phải kiểm tra chữ số xuất hiện nhiều lần; Cambridge Science cần phân biệt nhiệt độ và nhiệt năng.
Ưu tiên những câu thuộc bài Kem đã xác nhận học; những chủ đề chưa học có thể kiểm duyệt và lưu approved nhưng tuyệt đối không tự mở cho Kem làm trước khi xác nhận bài học. Chia lô tối đa 20 câu, tự giải độc lập từng câu, lưu audit và kết quả QA; loại hoặc cách ly các câu trùng máy móc không có giá trị. Không gọi Anthropic/Gemini API hay CLI con, không tự merge.

## LÔ MỚI: 500 CÂU / 29 CHỦ ĐỀ (12/11/2026)
Đã nhập 500 câu vào MariaDB, 100 câu/môn toan, tv, cmath, ceng, csci; tất cả trạng thái pending. Tệp nguồn được lưu tại /root/kem-learning-import/qa_batches/20261112_500/ (29 JSON và script sinh câu). Truy vấn ID thực tế từ DB; không giả định ID cố định.
**LƯU Ý CHẤT LƯỢNG:** Đã kiểm tra không trùng nguyên văn/quiz_norm với ngân hàng (500/500 khác nhau), nhưng nhiều câu trong cùng kỹ năng dùng chung cấu trúc, một số câu có thể mơ hồ hoặc có lựa chọn chưa hợp lý. KHÔNG tự động duyệt hàng loạt. Claude bắt buộc tự giải, so sánh từng đáp án và đối chiếu trùng ý nghĩa, cùng mẫu chỉ thay tên/số/đối tượng; ưu tiên đa dạng kỹ năng. Phải xem kỹ Science về nguồn sáng và truyền nhiệt, English modal verbs và cách diễn đạt, Vietnamese động từ/tính từ/nhân hóa, Toán và Cambridge Maths về hàng số và phép tính. Chỉ approved_by=claude_qa khi kiểm chứng độc lập; rejected cho câu trùng vô ích/sai rõ ràng; needs_review cho câu thực sự chưa thể kết luận. Câu chưa thuộc bài Kem xác nhận đã học không được đưa vào Quiz dù đã approved.
Không gọi Claude API/CLI con, không tự merge.
