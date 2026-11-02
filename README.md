# Học cùng Mây ☁️ (bản chạy trên VPS)

Bạn học AI cho học sinh lớp 4, học song song sách giáo khoa Việt Nam và Cambridge Primary.
Chạy bằng **PHP 8.1+ và MariaDB/MySQL** có sẵn trên VPS (nginx + PHP-FPM), **tách riêng hoàn toàn** khỏi các website khác trên VPS
(tên miền phụ riêng, user `may` riêng, pool PHP-FPM riêng, database và user MariaDB riêng `hoc_cung_may`).

## Có gì trong bản này
- Trò chuyện với Mây (nói bằng giọng hoặc gõ), đố vui 5 câu, Mây đọc to câu trả lời.
- **Chấm bài về nhà** 📸: bé làm xong bài cô giao thì chụp ảnh (tối đa 4 trang) gửi Mây. Mây chấm từng câu, chỉ chỗ sai,
  cho gợi ý để bé tự sửa. Bé sửa vào vở rồi chụp lại để Mây chấm lại; **chỉ khi bé tự sửa đúng ít nhất một câu**
  thì mới xem được cách làm đúng của các câu còn lại; gửi lại bài chưa sửa thì không mở đáp án, không được sao (máy chủ giữ đáp án, không gửi xuống iPad trước đó). Ba mẹ thì luôn xem được đáp án trong báo cáo.
  Câu sai được biến thành bài luyện tương tự trong Sổ ôn tập. Ba mẹ xem tóm tắt và đáp án trong báo cáo.
  Ảnh chỉ được gửi tới Claude để chấm, **không lưu trên VPS**; kết quả chấm giữ 60 ngày.
- **Lịch học và kế hoạch** 📅: ba mẹ nhập thời khoá biểu, lớp học thêm, bộ sách, số phút học mỗi ngày (Góc ba mẹ → Lịch học của bé).
  Trang chính có **"Hôm nay của bé"**: 2–4 việc vừa sức (chấm bài về nhà, sổ ôn tập, đố vui và hỏi Mây môn của ngày mai),
  tự đánh dấu ✓ khi bé làm xong; ngày có lớp học thêm và cuối tuần thì nhẹ hơn. Màn hình **Lịch học** xem theo ngày, tuần, tháng, quý
  kèm tổng kết kết quả. **Kế hoạch quý** (13 tuần × 5 môn): Mây soạn nháp theo chương trình lớp 4 và bộ sách, ba mẹ sửa rồi duyệt mới áp dụng.
- **Luyện nói tiếng Anh (Speaking)** 🗣️: môn English → Luyện nói, 3 dạng: đọc to câu mẫu, trả lời câu hỏi (kiểu thi nói Cambridge),
  kể/miêu tả ngắn. Bé nghe mẫu (có đọc chậm), bấm 🎤 để nói; iPad chuyển giọng thành chữ, Mây so từng từ với câu mẫu
  và góp ý ngữ pháp, từ vựng, câu mẫu để nói lại. Bé nghe lại giọng mình ngay trên iPad (không gửi âm thanh lên máy chủ).
  Mây chỉ có phần chữ máy chép lại nên không chấm chi tiết từng âm; chấm phát âm chuyên sâu (Microsoft Azure) sẽ thêm sau.
- **Sổ ôn tập**: câu sai được lưu lại, Mây đố lại sau 1, 3, 7 ngày; đúng đủ 3 lần liên tiếp là thuộc hẳn.
- **Báo cáo cho ba mẹ**: hoạt động 14 ngày, tỉ lệ đúng theo môn, phần hay sai, đọc lại lịch sử trò chuyện.
- **Lịch sử trò chuyện tự xoá** sau 30 ngày (đổi được). Sao lưu cơ sở dữ liệu mỗi đêm, giữ 14 bản.
- Hai mã PIN: **app_pin** cho máy của bé, **parent_pin** cho ba mẹ (báo cáo, lịch sử, cài đặt).
- API key và nguyên tắc an toàn cho trẻ nằm trên máy chủ; máy chủ tự chấm điểm đố vui.

## Cấu trúc
```
public/            Thư mục web (nginx trỏ vào đây)
  index.html       Giao diện app (PWA cài được lên iPad)
  api.php          Cổng API duy nhất
app/               NGOÀI thư mục web, trình duyệt không truy cập được
  lib.php          Cơ sở dữ liệu, mã PIN, gọi Claude, nguyên tắc của Mây
  cron.php         Chạy mỗi đêm: xoá trò chuyện cũ, sao lưu (--check: kiểm tra kết nối database)
  config.example.php → chép thành config.php
  backups/         Bản sao lưu hằng đêm (mysqldump, nén .sql.gz)
deploy/            File cấu hình nginx, PHP-FPM và script cài đặt
```

## Cài đặt

### Bước 1: Tạo tên miền phụ
Trong trang quản lý DNS của tên miền, thêm bản ghi:
- Loại **A**, tên `may`, giá trị là **địa chỉ IP của VPS**.

Đợi vài phút, kiểm tra trên máy tính: `ping may.ten-mien-cua-ban.vn` thấy đúng IP là được.

### Bước 2: Đưa code lên VPS
Cách A, qua GitHub (khuyên dùng, cập nhật dễ):
1. Tạo repo **Private** `hoc-cung-may` và upload toàn bộ thư mục này lên.
2. Trên GitHub tạo token chỉ đọc: Settings → Developer settings → Fine-grained tokens → chọn repo `hoc-cung-may`, quyền **Contents: Read-only**.
3. Trên VPS:
   ```
   cd ~ && git clone https://github.com/TEN-GITHUB/hoc-cung-may.git
   ```
   Khi hỏi mật khẩu, dán token vào.

Cách B, không dùng GitHub: copy file zip lên VPS rồi giải nén:
```
scp hoc-cung-may-vps.zip user@IP-VPS:~
ssh user@IP-VPS
unzip hoc-cung-may-vps.zip && mv may-vps hoc-cung-may
```

### Bước 3: Chạy script cài đặt
```
cd ~/hoc-cung-may
sudo bash deploy/install.sh may.ten-mien-cua-ban.vn
```
Script sẽ: kiểm tra tiện ích PHP (pdo_mysql, curl, mbstring; chỉ cài thêm nếu thiếu), tạo user `may`, chép code vào `/var/www/hoc-cung-may`,
tạo database `hoc_cung_may` và user MariaDB riêng với mật khẩu ngẫu nhiên (tự điền vào `config.php`), tạo bảng, phân quyền, tạo pool PHP-FPM và trang nginx riêng, đặt lịch chạy mỗi đêm.
Script **chỉ thêm file mới**, không sửa cấu hình của website khác. Trước khi tải lại, nó kiểm tra
cấu hình bằng `nginx -t`, nếu có lỗi sẽ dừng lại và không ảnh hưởng gì.

### Bước 4: Điền API key và mã PIN
```
sudo nano /var/www/hoc-cung-may/app/config.php
```
Phần database đã được điền sẵn. Chỉ cần điền `anthropic_api_key`, `app_pin`, `parent_pin` (hai mã phải khác nhau, nên từ 6 số trở lên). Lưu bằng Ctrl+O, Enter, Ctrl+X.

### Bước 5: Bật HTTPS
```
sudo certbot --nginx -d may.ten-mien-cua-ban.vn
```
iPad bắt buộc phải có HTTPS thì mới dùng được micro và cài thành app.
(Nếu VPS chưa có certbot: `sudo apt install certbot python3-certbot-nginx`.)

### Bước 6: Cài lên iPad
1. Mở `https://may.ten-mien-cua-ban.vn` bằng **Safari** → **Chia sẻ** → **Thêm vào MH chính**.
2. Mở app → **👪** → nhập **mã PIN của app** → Lưu.
3. Ba mẹ muốn xem báo cáo: vào 👪 → nhập **mã PIN của ba mẹ** → Mở báo cáo. Báo cáo tự khoá khi rời Góc ba mẹ.
   Ba mẹ cũng mở được báo cáo từ điện thoại của mình bằng cùng địa chỉ.

## Cập nhật app sau này
Sửa code trên GitHub, rồi trên VPS:
```
cd ~/hoc-cung-may && git pull && sudo bash deploy/install.sh may.ten-mien-cua-ban.vn
```
Script giữ nguyên `config.php`, cơ sở dữ liệu và bản sao lưu.

## Sao lưu và khôi phục
- Bản sao lưu nằm ở `/var/www/hoc-cung-may/app/backups/`, mỗi ngày một file.
- Thỉnh thoảng nên tải một bản về máy tính: `scp user@IP-VPS:/var/www/hoc-cung-may/app/backups/may-*.sql.gz .`
- Khôi phục một bản (ghi đè dữ liệu hiện tại):
  ```
  zcat /var/www/hoc-cung-may/app/backups/may-NGAY.sql.gz | sudo mysql --default-character-set=utf8mb4 hoc_cung_may
  ```

## Khi có lỗi, xem ở đâu
| Hiện tượng | Xem |
|---|---|
| App báo “Mây chưa kết nối được tới Claude” | `sudo tail -50 /var/log/hoc-cung-may/php-error.log` (thường do sai API key hoặc hết tiền) |
| Trang trắng, lỗi 502 | `sudo tail -50 /var/log/nginx/hoc-cung-may.error.log` và `systemctl status php8.X-fpm` |
| Sao lưu, xoá lịch sử | `sudo tail /var/log/hoc-cung-may/cron.log` |
| Lỗi kết nối database | `sudo -u may php /var/www/hoc-cung-may/app/cron.php --check` |

## Mẹo cho iPad
- **Nút 🎤**: bật Cài đặt → Cài đặt chung → Bàn phím → Bật Đọc chính tả. Nếu nút 🎤 không chạy khi mở từ màn hình chính, bé bấm micro trên bàn phím để nói.
- **Giọng tiếng Việt hay hơn**: Cài đặt → Trợ năng → Nội dung được đọc → Giọng nói → Tiếng Việt → tải giọng Nâng cao.

## Sửa nội dung
- Môn, chủ đề, giao diện: `public/index.html`
- Tính cách, nguyên tắc của Mây, đề đố vui: `app/lib.php`
- Giới hạn lượt mỗi ngày, số ngày giữ lịch sử, model: `app/config.php`
- Cách Mây chấm bài tập về nhà: hàm `homework_system` và `homework_prompt` trong `app/lib.php`
