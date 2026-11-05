#!/usr/bin/env bash
# Cài Học cùng Mây lên VPS (Ubuntu/Debian, đã có nginx + PHP-FPM). Chạy bằng: sudo bash deploy/install.sh may.ten-mien.vn
set -euo pipefail

DOMAIN="${1:-}"
APP_DIR=/var/www/hoc-cung-may
SRC_DIR="$(cd "$(dirname "$0")/.." && pwd)"

if [[ -z "$DOMAIN" ]]; then echo "Cách dùng: sudo bash deploy/install.sh may.ten-mien.vn"; exit 1; fi
if [[ $EUID -ne 0 ]]; then echo "Cần chạy bằng sudo"; exit 1; fi

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "==> PHP $PHP_VER"
php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' || { echo "Cần PHP 8.1 trở lên"; exit 1; }

echo "==> Kiểm tra tiện ích PHP (pdo_mysql, curl, mbstring)"
MISSING=""
for ext in pdo_mysql curl mbstring; do php -m | grep -qix "$ext" || MISSING="$MISSING $ext"; done
if [[ -n "$MISSING" ]]; then
  echo "   Thiếu:$MISSING -> cài thêm"
  PKGS=""; for ext in $MISSING; do case $ext in pdo_mysql) PKGS="$PKGS php${PHP_VER}-mysql";; *) PKGS="$PKGS php${PHP_VER}-$ext";; esac; done
  apt-get install -y $PKGS >/dev/null
fi
command -v mysql >/dev/null || { echo "Không thấy MariaDB/MySQL trên máy (lệnh mysql)"; exit 1; }

echo "==> Tạo user riêng 'may' (không đăng nhập được)"
id may >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin may

echo "==> Chép code vào $APP_DIR"
mkdir -p "$APP_DIR"
if [[ "$SRC_DIR" != "$APP_DIR" ]]; then
  rsync -a --delete --exclude 'app/config.php' --exclude 'app/backups/*.sql.gz' --exclude 'app/cache/' --exclude '.git' "$SRC_DIR"/ "$APP_DIR"/
fi
mkdir -p "$APP_DIR/app/backups" "$APP_DIR/app/cache/tts" "$APP_DIR/app/cache/img" /var/log/hoc-cung-may

if [[ ! -f "$APP_DIR/app/config.php" ]]; then
  echo "==> Tạo database và user MariaDB riêng cho Mây (không đụng tới database khác)"
  DB_PASS="$(php -r 'echo bin2hex(random_bytes(16));')"
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS hoc_cung_may CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'hoc_cung_may'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS 'hoc_cung_may'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'hoc_cung_may'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'hoc_cung_may'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON hoc_cung_may.* TO 'hoc_cung_may'@'localhost';
GRANT ALL PRIVILEGES ON hoc_cung_may.* TO 'hoc_cung_may'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  sed "s/'DOI_MAT_KHAU_NAY'/'${DB_PASS}'/" "$APP_DIR/app/config.example.php" > "$APP_DIR/app/config.php"
  unset DB_PASS
  echo "!! Đã tạo app/config.php (đã có mật khẩu database). Nhớ điền API key và 2 mã PIN: sudo nano $APP_DIR/app/config.php"
fi

echo "==> Phân quyền: code chỉ đọc, chỉ backups/ được ghi"
chown -R root:may "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} \;
find "$APP_DIR" -type f -exec chmod 640 {} \;
chmod 755 "$APP_DIR" "$APP_DIR/public" "$APP_DIR/public/icons"
find "$APP_DIR/public" -type f -exec chmod 644 {} \;
chown -R may:may "$APP_DIR/app/backups" "$APP_DIR/app/cache" /var/log/hoc-cung-may
chmod 750 "$APP_DIR/app/backups" "$APP_DIR/app/cache" "$APP_DIR/app/cache/tts" "$APP_DIR/app/cache/img"
chmod 640 "$APP_DIR/app/config.php"

echo "==> Kiểm tra kết nối database và tạo bảng"
sudo -u may php "$APP_DIR/app/cron.php" --check

echo "==> PHP-FPM pool riêng"
cp "$APP_DIR/deploy/php-fpm-hoc-cung-may.conf" "/etc/php/${PHP_VER}/fpm/pool.d/hoc-cung-may.conf"
php-fpm${PHP_VER} -t
systemctl reload "php${PHP_VER}-fpm"

echo "==> nginx"
SITE=/etc/nginx/sites-available/hoc-cung-may
restore_site() {
  echo "!! Cấu hình nginx mới bị lỗi, trả lại cấu hình cũ (các website khác không bị ảnh hưởng)"
  if [[ -f "$SITE.bak" ]]; then mv "$SITE.bak" "$SITE"; else rm -f "$SITE" /etc/nginx/sites-enabled/hoc-cung-may; fi
  nginx -t && systemctl reload nginx
  exit 1
}
[[ -f "$SITE" ]] && cp "$SITE" "$SITE.bak"
sed "s/may.TEN-MIEN-CUA-BAN.vn/${DOMAIN}/g" "$APP_DIR/deploy/nginx-hoc-cung-may.conf" > "$SITE"
ln -sf "$SITE" /etc/nginx/sites-enabled/hoc-cung-may
nginx -t || restore_site
# Đã có chứng chỉ HTTPS từ lần cài trước: gắn lại vào cấu hình mới (file mẫu chỉ có HTTP)
if [[ -d "/etc/letsencrypt/live/${DOMAIN}" ]] && command -v certbot >/dev/null; then
  echo "==> Gắn lại chứng chỉ HTTPS có sẵn cho ${DOMAIN}"
  certbot install --nginx --cert-name "$DOMAIN" -d "$DOMAIN" --redirect --non-interactive >/dev/null || restore_site
  nginx -t || restore_site
  HAS_CERT=1
fi
systemctl reload nginx
rm -f "$SITE.bak"

echo "==> Cron hằng đêm (xoá trò chuyện cũ + sao lưu bằng mysqldump)"
echo "0 3 * * * may /usr/bin/php $APP_DIR/app/cron.php >> /var/log/hoc-cung-may/cron.log 2>&1" > /etc/cron.d/hoc-cung-may
chmod 644 /etc/cron.d/hoc-cung-may

echo
echo "Xong phần cài đặt. Bước tiếp theo:"
echo "  1. Điền key và mã PIN:   sudo nano $APP_DIR/app/config.php"
if [[ -z "${HAS_CERT:-}" ]]; then echo "  2. Bật HTTPS:            sudo certbot --nginx -d $DOMAIN"; else echo "  2. HTTPS:                đã bật sẵn"; fi
echo "  3. Mở thử:               https://$DOMAIN"
