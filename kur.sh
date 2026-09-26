#!/bin/bash
# ==========================================================
#  Yayın Paneli — tek komutla sunucu kurulumu
#  Ubuntu 20.04+ / Debian 11+ ; root (veya sudo) ile çalıştır:
#     sudo bash kur.sh
#  Seçenekler (ortam değişkeni):
#     ALAN=yorum.ornek.com   HTTPS için kendi alan adın (varsayılan: IP.sslip.io)
#     HTTPS_ATLA=1           HTTPS kurma
#     SIFRE_SIFIRLA=1        Panel şifresini yeniden üret
#  Tekrar çalıştırmak güvenlidir: uygulamalar ve ayarlar korunur.
# ==========================================================
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
KOK=/var/www/yayin
Y="\033[1;32m"; K="\033[1;31m"; S="\033[1;33m"; M="\033[1;36m"; N="\033[0m"
adim() { echo -e "\n${M}▶ $*${N}"; }
ok()   { echo -e "  ${Y}✓${N} $*"; }
uyar() { echo -e "  ${S}!${N} $*"; }
dur()  { echo -e "\n${K}✗ DURDU:${N} $*\n"; exit 1; }

servis() { # servis <ad> : etkinleştir ve yeniden başlat
  if [ -d /run/systemd/system ]; then systemctl enable "$1" >/dev/null 2>&1 || true; systemctl restart "$1"
  else service "$1" restart >/dev/null 2>&1 || service "$1" start; fi
}

echo -e "${M}"
echo "  ╔══════════════════════════════════╗"
echo "  ║      🚀 Yayın Paneli Kurulumu     ║"
echo "  ╚══════════════════════════════════╝"
echo -e "${N}"

# ---------- Ön kontroller ----------
adim "Sunucu kontrol ediliyor"
[ "$(id -u)" -eq 0 ] || dur "Yönetici yetkisi gerekli. Komutu başına sudo ekleyerek çalıştır."
command -v apt-get >/dev/null || dur "Bu kurulum Ubuntu veya Debian içindir. Sunucu sağlayıcından Ubuntu 22.04/24.04 seç."
. /etc/os-release; ok "İşletim sistemi: ${PRETTY_NAME:-bilinmiyor}"

for p in /www/server/panel /usr/local/cpanel /usr/local/psa /usr/local/hestia /usr/local/CyberCP /usr/local/lsws /usr/local/directadmin; do
  [ -e "$p" ] && dur "Bu sunucuda zaten bir kontrol paneli kurulu ($p). Çakışmamak için kurulum yapılmadı. Bu çıktıyı Claude'a gönder."
done
if pgrep -x apache2 >/dev/null || pgrep -x httpd >/dev/null; then
  dur "Sunucuda Apache çalışıyor; üzerinde başka siteler olabilir. Bozmamak için durdum. Bu çıktıyı Claude'a gönder."
fi
if [ -d /etc/nginx/sites-enabled ]; then
  DIGER=$(ls /etc/nginx/sites-enabled 2>/dev/null | grep -Ev '^(default|yayin|yayin-.*)$' || true)
  [ -n "$DIGER" ] && dur "nginx'te başka siteler var: $DIGER. Bozmamak için durdum. Bu çıktıyı Claude'a gönder."
fi
if command -v ss >/dev/null; then
  PORT80=$(ss -ltnpH 'sport = :80' 2>/dev/null | grep -v nginx || true)
  [ -n "$PORT80" ] && dur "80 portunu başka bir program kullanıyor:\n$PORT80"
fi
BOS_MB=$(df -Pm / | awk 'NR==2{print $4}')
[ "${BOS_MB:-0}" -lt 800 ] && dur "Diskte yeterli yer yok (${BOS_MB} MB boş, en az 800 MB gerekli)."
ok "Çakışma yok, ${BOS_MB} MB boş alan var"

# ---------- Paketler ----------
adim "Gerekli programlar kuruluyor (2-5 dakika sürebilir)"
apt-get update -q >/dev/null || apt-get update
apt-get install -y -q --no-install-recommends \
  nginx php-fpm php-cli php-curl php-zip php-mbstring php-gd php-sqlite3 php-mysql php-intl php-xml \
  git unzip curl ca-certificates cron sudo certbot python3-certbot-nginx >/dev/null
# php-fpm'in sürümünü esas al (sunucuda birden çok PHP olabilir)
PHPV=$(ls -d /etc/php/*/fpm 2>/dev/null | sort -V | tail -1 | cut -d/ -f4)
[ -n "$PHPV" ] || dur "php-fpm kurulamadı."
PHPBIN=$(command -v "php${PHPV}" || command -v php)
SOCK="/run/php/php${PHPV}-fpm.sock"
ok "nginx, PHP ${PHPV}, git, certbot kuruldu"

mkdir -p /etc/yayin
echo "$SOCK" > /etc/yayin/php-sock
touch /etc/yayin/alanlar; chmod 644 /etc/yayin/alanlar
IP=$(curl -4 -fs -m 8 https://api.ipify.org || curl -4 -fs -m 8 https://ifconfig.me || true)
[[ "$IP" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || IP=$(hostname -I | awk '{print $1}')
echo "$IP" > /etc/yayin/ip
ok "Sunucu IP: $IP"

# ---------- PHP ayarları ----------
cat > "/etc/php/${PHPV}/fpm/conf.d/99-yayin.ini" <<'INI'
upload_max_filesize = 128M
post_max_size = 130M
max_file_uploads = 100
max_execution_time = 600
memory_limit = 256M
expose_php = Off
date.timezone = Europe/Istanbul
INI

# ---------- Panel dosyaları ----------
adim "Yayın Paneli dosyaları yerleştiriliyor"
GEC=$(mktemp -d)
base64 -d > "$GEC/yuk.tgz" <<'YUK_SON'
H4sIAAAAAAAAA+xbW3fbRpLOs35FW8MTkBNeZUlOqIujWIystS1pRToejazgNIEm2UvcBhdJlO2c
fdmzf2Af94wf87p+mae8yf4j80u2qhoAARC0nYyT3T0b+NgEGo3q6qrqqq+q24FvtD77la82XPc2
NugXruIv3Xc21jY27m2019trn7U77bsb7c/Yxq/NGF5REHKfsc981w3f1+9D7/+PXgHo/+rq6le1
gZ+v/07n3vrv+v8trkT/0jHFdXMS2tanHwMVvLm+vkz/nfa9zYL+73bubXzG2p+elcXr/7n+t+/s
Hz8YnJ30GOp+dxv/ZRZ3xjurob8Kz4Kbu9u2CDkzJtwPRLizGoWjxpercavDbbGzeinFlef64Soz
XCcUDvS6kmY42THFpTREgx7q0pGh5FYjMLgldjp5Er47dMMgQ8BxyShXd1e2QxlaYrcfOZERsQm/
efvG326pxu0gnMHP0DVnL2zuj6XTbW/Z0mlMhBxPwm6n3b6cbJky8Cw+6459aW7BHbAkQ2EHXQOG
Ev7WCAZtjLgtrVk3mAXwqhHJesCdoBEIX462htyYjn03cszuH0br8OfeluFart/9Q2cT/phbobgO
G9ySYyeh6XHTlM6429n0rl+tfG0LU3JW9XwxEn7QoK9BEhNhi67J/WntBc0hO1B71B517iYDCUMY
o86rV/xF3LB5d3MTGrZbSgTbLVLWyjbS2d025SWor7P79//8NxZL7vZHbr198+712zczFwQIL7e9
3W3OJsDTzmrL446wWqu7Z3z29o3DTvBRbrc4UIZuLSLYUsRbZC0r/9PW+/v1j16J/1e6/3XG+Dnx
f/Mu9Ous3W3f/T3+/xZXXv9T7hi86U28TzrGB+L/2r3OvUL8X2/fa/8e/3+La/s+aHul9ccV9kd2
IMOH0ZBdieHEdadd5gvPnUF0ED4b3/4NYrEvbVMw7kB0MDmbUZhoZr7cZX0RhhDzArh9psgEXQgk
M8vlJnt6+pjtsGHEuOmLoM4eqEjPwpkn4AX3PEsaPJSu0/qXwHXqSLkvDF+E8JYM1BRTyVZjysAI
n4Dy3r5ZRSZaK6YwLO6LahD60gh1JBvsdGpbK774SyR9wbTWJffJ3IF36bQsOcS/aPDa1gpGT+FX
tZitxgC+7zKM6y1ADNLZShEQASANCFfG7qWJvNOYzrg2kpbQxyLUYxQTVDUg3m0BvvaiED8J/Rl7
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
YUK_SON
tar -xzf "$GEC/yuk.tgz" -C "$GEC"
mkdir -p "$KOK"/{www,lib,veri,git,gecici}
rm -rf "$KOK/lib" && cp -r "$GEC/src/lib" "$KOK/lib"
rm -rf "$KOK/www/panel" && cp -r "$GEC/src/www/panel" "$KOK/www/panel"
[ -f "$KOK/www/index.html" ] || cp "$GEC/src/www/index.html" "$KOK/www/index.html"
install -m 755 "$GEC/src/bin/yayin-ssl" /usr/local/bin/yayin-ssl
rm -rf "$GEC"
chown -R root:root "$KOK/lib"; chmod -R a+rX,go-w "$KOK/lib"
chown -R www-data:www-data "$KOK/www" "$KOK/veri" "$KOK/git" "$KOK/gecici"
chmod 700 "$KOK/veri" "$KOK/git" "$KOK/gecici"; chmod 755 "$KOK" "$KOK/www"
ok "Panel: $KOK/www/panel"

# ---------- Panel şifresi ----------
YENI_SIFRE=""
if [ ! -f "$KOK/veri/panel.php" ] || [ "${SIFRE_SIFIRLA:-0}" = "1" ]; then
  YENI_SIFRE=$("$PHPBIN" -r 'echo substr(strtr(base64_encode(random_bytes(24)), ["+"=>"","/"=>"","="=>"","0"=>"","O"=>"","l"=>"","I"=>""]), 0, 12);')
  YP_SIFRE="$YENI_SIFRE" "$PHPBIN" -r '
    $d = "/var/www/yayin/veri/panel.php";
    $a = is_file($d) ? (include $d) : [];
    $a["sifre"] = password_hash(getenv("YP_SIFRE"), PASSWORD_DEFAULT);
    $a["gizli"] = bin2hex(random_bytes(32));
    $a["kanca"] = $a["kanca"] ?? bin2hex(random_bytes(20));
    $a["github"] = $a["github"] ?? [];
    file_put_contents($d, "<?php\nreturn " . var_export($a, true) . ";\n");'
  chown www-data:www-data "$KOK/veri/panel.php"; chmod 600 "$KOK/veri/panel.php"
  printf 'Yayın Paneli\nŞifre : %s\n' "$YENI_SIFRE" > /root/yayin-bilgi.txt; chmod 600 /root/yayin-bilgi.txt
  ok "Yeni panel şifresi üretildi"
else
  ok "Mevcut panel şifresi korundu"
fi

# ---------- nginx ----------
adim "Web sunucusu ayarlanıyor"
cat > /etc/nginx/snippets/yayin-ortak.conf <<NGX
root $KOK/www;
index index.html index.php;
client_max_body_size 130m;
server_tokens off;
add_header X-Content-Type-Options nosniff always;
location ~ /\.(?!well-known) { deny all; }
location ~ ^/[^/]+/veri(/|\$) { deny all; return 404; }
location / { try_files \$uri \$uri/ =404; }
location ~ \.php\$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:$SOCK;
    fastcgi_read_timeout 600;
}
NGX
V6=""; [ -s /proc/net/if_inet6 ] && V6="listen [::]:80 default_server;"
cat > /etc/nginx/sites-available/yayin <<NGX
server {
    listen 80 default_server;
    $V6
    server_name _;
    include /etc/nginx/snippets/yayin-ortak.conf;
}
NGX
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/yayin /etc/nginx/sites-enabled/yayin
nginx -t >/dev/null 2>&1 || { nginx -t; dur "nginx ayarı hatalı."; }
servis "php${PHPV}-fpm"; servis nginx
ok "nginx ve PHP çalışıyor"

# ---------- Yetkiler, zamanlayıcı, güvenlik duvarı ----------
echo 'www-data ALL=(root) NOPASSWD: /usr/local/bin/yayin-ssl' > /etc/sudoers.d/yayin
chmod 440 /etc/sudoers.d/yayin
visudo -cf /etc/sudoers.d/yayin >/dev/null || { rm -f /etc/sudoers.d/yayin; uyar "HTTPS yardımcısı için yetki verilemedi"; }
echo "*/5 * * * * www-data $PHPBIN $KOK/lib/cek.php >/dev/null 2>&1" > /etc/cron.d/yayin
chmod 644 /etc/cron.d/yayin
servis cron || true
ok "GitHub otomatik güncelleme zamanlayıcısı kuruldu (5 dk)"
if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow 22/tcp >/dev/null; ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null
  ok "Güvenlik duvarında 80 ve 443 açıldı"
fi

# ---------- Doğrulama ----------
KOD=$(curl -s -o /dev/null -w '%{http_code}' -H 'X-YP: 1' -H 'Content-Type: application/json' -d '{"islem":"durum"}' http://127.0.0.1/panel/ || true)
[ "$KOD" = "200" ] && ok "Panel yanıt veriyor" || uyar "Panel yerel testte $KOD döndü"

# ---------- HTTPS ----------
ADRES="http://$IP/panel/"
if [ "${HTTPS_ATLA:-0}" != "1" ] && [ -n "$IP" ]; then
  adim "HTTPS sertifikası alınıyor"
  HEDEF_ALAN="${ALAN:-${IP//./-}.sslip.io}"
  if /usr/local/bin/yayin-ssl "$HEDEF_ALAN" | sed 's/^/  /'; then
    ADRES="https://$HEDEF_ALAN/panel/"
  else
    uyar "HTTPS şimdilik kurulamadı. Panelde Sistem sekmesinden tekrar deneyebilirsin."
  fi
fi

# ---------- Özet ----------
SIFRE_GOSTER=$(grep '^Şifre' /root/yayin-bilgi.txt 2>/dev/null | sed 's/^Şifre : //' || true)
{
  echo "Yayın Paneli"
  echo "Adres : $ADRES"
  [ -n "$SIFRE_GOSTER" ] && echo "Şifre : $SIFRE_GOSTER"
  echo "Kurulum: $(date '+%Y-%m-%d %H:%M')"
} > /root/yayin-bilgi.new
mv /root/yayin-bilgi.new /root/yayin-bilgi.txt; chmod 600 /root/yayin-bilgi.txt

echo -e "\n${Y}══════════════════════════════════════════${N}"
echo -e "${Y}  ✅ KURULUM TAMAM${N}\n"
echo -e "  Panel adresi : ${M}$ADRES${N}"
echo -e "  Panel şifresi: ${M}${SIFRE_GOSTER:-cat /root/yayin-bilgi.txt ile bak}${N}"
echo -e "\n  Bu iki bilgiyi bir yere not et (ekran görüntüsü al)."
echo -e "  Adresi telefonunun tarayıcısında aç, şifreyle gir."
echo -e "  Bilgileri tekrar görmek için: cat /root/yayin-bilgi.txt"
echo -e "${Y}══════════════════════════════════════════${N}\n"
