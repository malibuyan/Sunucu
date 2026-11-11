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

# ---------- Ana repo ve durum adresi ----------
# Ana repodaki uygulamalar/ altındaki her klasör kendiliğinden yayınlanır (panelden bağlamak gerekmez).
YP_ANA="${ANA_REPO:-https://github.com/malibuyan/sunucu}" "$PHPBIN" -r '
  $d = "/var/www/yayin/veri/panel.php";
  $a = include $d;
  if (empty($a["ana"])) $a["ana"] = ["repo" => getenv("YP_ANA"), "dal" => "main", "kok" => "uygulamalar"];
  if (empty($a["durum"])) $a["durum"] = bin2hex(random_bytes(16));
  file_put_contents($d, "<?php\nreturn " . var_export($a, true) . ";\n");'
chown www-data:www-data "$KOK/veri/panel.php"; chmod 600 "$KOK/veri/panel.php"
DURUM_ANAHTAR=$("$PHPBIN" -r '$a = include "/var/www/yayin/veri/panel.php"; echo $a["durum"] ?? "";')
ok "Ana repo: ${ANA_REPO:-https://github.com/malibuyan/sunucu} (uygulamalar/ otomatik yayınlanır)"

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

# ---------- İlk yayın ----------
adim "Repodaki uygulamalar yayınlanıyor"
if sudo -u www-data "$PHPBIN" "$KOK/lib/cek.php"; then
  YAYINDA=$(ls -d "$KOK"/www/*/ 2>/dev/null | xargs -r -n1 basename | grep -Ev '^(panel|durum)$' | tr '\n' ' ' || true)
  ok "Yayında: ${YAYINDA:-henüz uygulama yok}"
else
  uyar "İlk yayın yapılamadı; 5 dakika içinde kendiliğinden yeniden denenecek."
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
  [ -n "$DURUM_ANAHTAR" ] && echo "Durum : ${ADRES%panel/}durum/${DURUM_ANAHTAR}.json"
  echo "Kurulum: $(date '+%Y-%m-%d %H:%M')"
} > /root/yayin-bilgi.new
mv /root/yayin-bilgi.new /root/yayin-bilgi.txt; chmod 600 /root/yayin-bilgi.txt

echo -e "\n${Y}══════════════════════════════════════════${N}"
echo -e "${Y}  ✅ KURULUM TAMAM${N}\n"
echo -e "  Panel adresi : ${M}$ADRES${N}"
echo -e "  Panel şifresi: ${M}${SIFRE_GOSTER:-cat /root/yayin-bilgi.txt ile bak}${N}"
[ -n "$DURUM_ANAHTAR" ] && echo -e "  Durum adresi : ${M}${ADRES%panel/}durum/${DURUM_ANAHTAR}.json${N}"
echo -e "\n  Durum adresini Claude'a gönder; yayınları oradan izler."
echo -e "  Panel adresi ve şifresini bir yere not et (ekran görüntüsü al)."
echo -e "  Adresi telefonunun tarayıcısında aç, şifreyle gir."
echo -e "  Bilgileri tekrar görmek için: cat /root/yayin-bilgi.txt"
echo -e "${Y}══════════════════════════════════════════${N}\n"
