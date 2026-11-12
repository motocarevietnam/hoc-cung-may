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

## BỔ SUNG 12/11 — KIỂM TRA TRÙNG LẶP TOÀN NGÂN HÀNG
- Lô mới: 100 câu Cambridge Maths, chủ đề UNIT 5 - Addition and Subtraction Students will apply addition and subtraction strategies to solve real-world problems, use sum-and-difference reasoning, and justify their solutions with mathematical language and estimation. Đọc trực tiếp question_bank, trạng thái pending; không dùng số ID đoán trước.
- Khi kiểm duyệt, đối chiếu với **toàn bộ question_bank cùng môn**, không chỉ cùng lô hoặc cùng topic; so với các câu đã được Kem làm trong quizzes nếu phù hợp.
- Phân loại: (1) trùng chính xác sau chuẩn hóa Unicode/chữ hoa/khoảng trắng/dấu câu; (2) trùng cấu trúc khi thay các con số/đơn vị/tên riêng; (3) gần trùng về ngữ nghĩa và cách giải; (4) khác biệt thực sự về kỹ năng, bối cảnh và mức độ khó.
- Chuẩn hóa chữ số thành placeholder để tìm biến thể số liệu; so sánh bộ dữ kiện, thao tác giải, câu hỏi và đáp án; tránh đánh đồng mọi câu cộng/trừ với nhau.
- Lập báo cáo nhóm trùng có question_id, dạng trùng, số lượng và đề xuất giữ câu đại diện; KHÔNG tự xóa dữ liệu, không tự gộp các câu có lịch sử làm bài.
- Nếu lô có 100 câu nhưng chỉ 7 cấu trúc câu, báo rõ mức độ đa dạng thấp; không phê duyệt hàng loạt chỉ dựa trên phép tính đúng. Câu có nội dung đúng nhưng lặp máy móc cần đánh dấu kiểm tra chất lượng và đề xuất cải thiện.
- Kiểm tra cả trùng lặp xuyên môn Toán Việt Nam/Cambridge Maths nếu có cùng bài, nhưng không tự loại vì khác ngôn ngữ hay mục tiêu kỹ năng.
- Mỗi lô phải có thống kê: unique exact, numeric-template duplicates, semantic near duplicates, distinct skill types, QA PASS/FAIL/UNCERTAIN.
- Ưu tiên 100 câu Bài 25 và 100 câu Cambridge Maths Unit 5, rồi các câu pending khác. Chỉ cập nhật QA sau khi tự giải từng câu và đối chiếu đầy đủ.

## BỔ SUNG — BA MÔN MỚI VÀ BẮT BUỘC CHỐNG TRÙNG (12/11)
ChatGPT đã nhập 60 câu pending mới: 20 Tiếng Việt (Luyện từ và câu: Động từ), 20 Cambridge Science (UNIT 2 - ENERGY - SOUND ... vibrations), 20 Cambridge English (Unit 9: Writing ... cause and effect signal words). Truy vấn DB để lấy ID thực tế.
Trước khi PASS bất kỳ câu nào: so sánh với toàn bộ question_bank (mọi trạng thái) và các quiz đã dùng; kiểm tra trùng nguyên văn, chuẩn hóa dấu câu/hoa thường, thay số liệu/tên riêng, cùng ý nghĩa và cùng kỹ năng. Báo cáo các cụm trùng theo ID và đánh giá đa dạng kỹ năng. Không tự loại câu vì chỉ cùng chủ đề nhưng kiểm tra rõ câu quá giống nhau; ưu tiên giữ câu có diễn đạt và đáp án tốt nhất, đề xuất các câu khác needs_review. Đặc biệt kiểm tra câu Tiếng Việt có đáp án thực sự là động từ, Cambridge Science không mơ hồ, Cambridge English không có nhiều liên từ đúng tùy ngữ cảnh. Không được chỉ nhìn nhãn đáp án hoặc xác nhận hàng loạt bằng script. Lưu từng verdict cùng lý do vào question_qa_reviews, kiểm tra nguồn kiến thức đã được học trước khi dùng cho Kem.
Số liệu các lô cũ đã biến động do QA đang diễn ra: không áp dụng cứng số lượng hoặc ghi đè kết quả đã kiểm duyệt; truy vấn mới, xử lý từng câu bằng giao dịch, không gọi API/CLI con, không tự merge.
