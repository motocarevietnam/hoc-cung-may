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

echo "==> Cài tiện ích PHP cần thiết (sqlite, curl, mbstring)"
apt-get install -y "php${PHP_VER}-sqlite3" "php${PHP_VER}-curl" "php${PHP_VER}-mbstring" >/dev/null

echo "==> Tạo user riêng 'may' (không đăng nhập được)"
id may >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin may

echo "==> Chép code vào $APP_DIR"
mkdir -p "$APP_DIR"
if [[ "$SRC_DIR" != "$APP_DIR" ]]; then
  rsync -a --delete --exclude 'app/config.php' --exclude 'app/data/*.sqlite*' --exclude 'app/backups/*.sqlite' --exclude '.git' "$SRC_DIR"/ "$APP_DIR"/
fi
mkdir -p "$APP_DIR/app/data" "$APP_DIR/app/backups" /var/log/hoc-cung-may

if [[ ! -f "$APP_DIR/app/config.php" ]]; then
  cp "$APP_DIR/app/config.example.php" "$APP_DIR/app/config.php"
  echo "!! Đã tạo app/config.php từ mẫu. Nhớ điền API key và 2 mã PIN: sudo nano $APP_DIR/app/config.php"
fi

echo "==> Phân quyền: code chỉ đọc, chỉ data/ và backups/ được ghi"
chown -R root:may "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} \;
find "$APP_DIR" -type f -exec chmod 640 {} \;
chmod 755 "$APP_DIR" "$APP_DIR/public" "$APP_DIR/public/icons"
find "$APP_DIR/public" -type f -exec chmod 644 {} \;
chown -R may:may "$APP_DIR/app/data" "$APP_DIR/app/backups" /var/log/hoc-cung-may
chmod 750 "$APP_DIR/app/data" "$APP_DIR/app/backups"

echo "==> PHP-FPM pool riêng"
cp "$APP_DIR/deploy/php-fpm-hoc-cung-may.conf" "/etc/php/${PHP_VER}/fpm/pool.d/hoc-cung-may.conf"
php-fpm${PHP_VER} -t
systemctl reload "php${PHP_VER}-fpm"

echo "==> nginx"
sed "s/may.TEN-MIEN-CUA-BAN.vn/${DOMAIN}/g" "$APP_DIR/deploy/nginx-hoc-cung-may.conf" > /etc/nginx/sites-available/hoc-cung-may
ln -sf /etc/nginx/sites-available/hoc-cung-may /etc/nginx/sites-enabled/hoc-cung-may
nginx -t
systemctl reload nginx

echo "==> Cron hằng đêm (xoá trò chuyện cũ + sao lưu)"
echo "0 3 * * * may /usr/bin/php $APP_DIR/app/cron.php >> /var/log/hoc-cung-may/cron.log 2>&1" > /etc/cron.d/hoc-cung-may
chmod 644 /etc/cron.d/hoc-cung-may

echo
echo "Xong phần cài đặt. Bước tiếp theo:"
echo "  1. Điền key và mã PIN:   sudo nano $APP_DIR/app/config.php"
echo "  2. Bật HTTPS:            sudo certbot --nginx -d $DOMAIN"
echo "  3. Mở thử:               https://$DOMAIN"
