<?php
/*
 * Yorum Listeleyici — VPS tarafı (JSON API)
 * Bu dosyayı düzenlemen gerekmez. Tüm ayarlar uygulamanın içinden yapılır
 * ve veri/ayar.php dosyasına kaydedilir.
 */
declare(strict_types=1);

const API_SURUM = 'v23.0';               // Meta hata verirse güncel sürümü yaz
define('GRAPH', getenv('YL_GRAPH') ?: 'https://graph.facebook.com');
define('VERI', __DIR__ . '/veri');
define('AYAR', VERI . '/ayar.php');
define('GECICI', VERI . '/gecici.php');
const CEREZ = 'yl_oturum';
const OTURUM_GUN = 60;

ini_set('display_errors', '0');
@set_time_limit(180);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function cevap(array $v): void { echo json_encode(['ok' => true] + $v, JSON_UNESCAPED_UNICODE); exit; }
function hata(string $m, string $kod = 'hata', int $durum = 400): void {
    http_response_code($durum);
    echo json_encode(['ok' => false, 'hata' => $m, 'kod' => $kod], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Depolama (PHP dosyası olarak saklanır; tarayıcıdan okunamaz) ----
function dosyaOku(string $yol): ?array {
    if (!is_file($yol)) return null;
    $v = include $yol;
    return is_array($v) ? $v : null;
}
function dosyaYaz(string $yol, array $v): void {
    if (!is_dir(VERI) && !@mkdir(VERI, 0700, true)) hata('veri klasörü oluşturulamadı. Klasöre yazma izni ver.');
    if (!is_file(VERI . '/.htaccess')) @file_put_contents(VERI . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_file(VERI . '/index.html')) @file_put_contents(VERI . '/index.html', '');
    $gecici = $yol . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($gecici, "<?php\nreturn " . var_export($v, true) . ";\n", LOCK_EX) === false) {
        hata('Ayar dosyası yazılamadı. veri klasörüne yazma izni ver.');
    }
    @chmod($gecici, 0600);
    rename($gecici, $yol);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($yol, true);
}

// ---- Oturum (imzalı çerez; sunucu oturumu gerektirmez) ----
function oturumAc(array $ayar): void {
    $bitis = time() + OTURUM_GUN * 86400;
    $deger = $bitis . '.' . hash_hmac('sha256', (string)$bitis, $ayar['gizli']);
    setcookie(CEREZ, $deger, [
        'expires' => $bitis, 'path' => '', 'httponly' => true, 'samesite' => 'Strict',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
}
function girisli(?array $ayar): bool {
    if (!$ayar || empty($_COOKIE[CEREZ])) return false;
    $p = explode('.', (string)$_COOKIE[CEREZ], 2);
    if (count($p) !== 2 || (int)$p[0] < time()) return false;
    return hash_equals(hash_hmac('sha256', $p[0], $ayar['gizli']), $p[1]);
}

// ---- Facebook Graph API ----
function fbGet(string $yol, array $par, bool $sessiz = false): array {
    $url = preg_match('~^https?://~', $yol) ? $yol : GRAPH . '/' . API_SURUM . '/' . $yol . '?' . http_build_query($par);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10]);
    $ham = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($ham === false) hata('Facebook\'a bağlanılamadı: ' . $err);
    $v = json_decode((string)$ham, true);
    if (!is_array($v)) hata('Facebook\'tan anlaşılmayan yanıt geldi.');
    if (isset($v['error'])) {
        if ($sessiz) return $v;
        $e = $v['error'];
        $kod = (int)($e['code'] ?? 0);
        $m = $e['message'] ?? 'bilinmeyen hata';
        if ($kod === 190) hata('Facebook anahtarı geçersiz veya süresi dolmuş. Ayarlar > Facebook bağlantısı bölümünden yenile. (' . $m . ')', 'token');
        if (in_array($kod, [4, 17, 32, 613], true)) hata('Facebook istek sınırına takıldı, birkaç dakika sonra tekrar dene.');
        hata('Facebook hatası: ' . $m);
    }
    return $v;
}
function fbHepsi(string $yol, array $par, int $maxSayfa): array {
    $v = fbGet($yol, $par);
    $tum = $v['data'] ?? [];
    for ($i = 1; $i < $maxSayfa && !empty($v['paging']['next']); $i++) {
        $v = fbGet($v['paging']['next'], []);
        foreach ($v['data'] ?? [] as $d) $tum[] = $d;
    }
    return $tum;
}
function sayfaDogrula(string $token): array {
    $me = fbGet('me', ['fields' => 'id,name', 'access_token' => $token]);
    $kat = fbGet('me', ['fields' => 'category', 'access_token' => $token], true);
    if (empty($kat['category'])) hata('Bu bir sayfa anahtarı değil. Kişisel hesap anahtarı girilmiş olabilir.');
    return ['id' => (string)$me['id'], 'ad' => (string)$me['name']];
}

// ---- İstek ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_YL'] ?? '') !== '1') hata('Geçersiz istek', 'hata', 405);
$girdi = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($girdi)) hata('Geçersiz istek');
$islem = (string)($girdi['islem'] ?? '');
$ayar = dosyaOku(AYAR);
$kurulu = $ayar !== null;
$oturum = girisli($ayar);

function durum(?array $ayar, bool $oturum): array {
    return ['kurulu' => $ayar !== null, 'girisli' => $oturum, 'sayfa' => $oturum ? ($ayar['sayfa'] ?? null) : null];
}

switch ($islem) {

case 'durum':
    cevap(durum($ayar, $oturum));

case 'giris':
    if (!$kurulu) hata('Önce kurulum yapılmalı', 'kurulum');
    if (!password_verify((string)($girdi['sifre'] ?? ''), $ayar['sifre'])) { sleep(2); hata('Şifre yanlış'); }
    oturumAc($ayar);
    cevap(durum($ayar, true));

case 'cikis':
    setcookie(CEREZ, '', ['expires' => 1, 'path' => '', 'httponly' => true, 'samesite' => 'Strict']);
    cevap(durum($ayar, false));

// Kolay kurulum: kısa süreli kullanıcı anahtarını uzun süreliye çevirip sayfaları getirir.
case 'sayfalari_getir':
    if ($kurulu && !$oturum) hata('Giriş gerekli', 'giris', 401);
    $appId = trim((string)($girdi['appId'] ?? ''));
    $secret = trim((string)($girdi['appSecret'] ?? ''));
    $kisa = trim((string)($girdi['kisaToken'] ?? ''));
    if ($appId === '' || $secret === '' || $kisa === '') hata('Üç alanı da doldur.');
    $uzun = fbGet('oauth/access_token', [
        'grant_type' => 'fb_exchange_token', 'client_id' => $appId,
        'client_secret' => $secret, 'fb_exchange_token' => $kisa,
    ]);
    if (empty($uzun['access_token'])) hata('Uzun süreli anahtar alınamadı.');
    $hesaplar = fbHepsi('me/accounts', ['fields' => 'id,name,access_token', 'limit' => 100, 'access_token' => $uzun['access_token']], 5);
    $sayfalar = [];
    foreach ($hesaplar as $h) {
        if (!empty($h['access_token'])) $sayfalar[] = ['id' => (string)$h['id'], 'ad' => (string)$h['name'], 'token' => $h['access_token']];
    }
    if (!$sayfalar) hata('Bu hesapla yönetilen sayfa bulunamadı. Anahtarı alırken sayfayı seçtiğinden ve pages_show_list iznini verdiğinden emin ol.');
    dosyaYaz(GECICI, ['zaman' => time(), 'sayfalar' => $sayfalar]);
    cevap(['sayfalar' => array_map(fn($s) => ['id' => $s['id'], 'ad' => $s['ad']], $sayfalar)]);

// Kurulumu tamamlar ya da Facebook bağlantısını değiştirir.
case 'kaydet':
    if ($kurulu && !$oturum) hata('Giriş gerekli', 'giris', 401);
    $sifre = (string)($girdi['sifre'] ?? '');
    if (!$kurulu && strlen($sifre) < 6) hata('Şifre en az 6 karakter olmalı.');
    $token = trim((string)($girdi['token'] ?? ''));
    $sayfaId = (string)($girdi['sayfaId'] ?? '');
    if ($sayfaId !== '') {
        $g = dosyaOku(GECICI);
        if (!$g || $g['zaman'] < time() - 1800) hata('Sayfa listesinin süresi doldu, tekrar getir.');
        foreach ($g['sayfalar'] as $s) if ($s['id'] === $sayfaId) $token = $s['token'];
        if ($token === '') hata('Seçilen sayfa bulunamadı.');
    }
    if ($token === '') hata('Facebook bağlantısı için bir yöntem seç.');
    $sayfa = sayfaDogrula($token);
    $yeni = [
        'sifre' => $kurulu ? $ayar['sifre'] : password_hash($sifre, PASSWORD_DEFAULT),
        'gizli' => $kurulu ? $ayar['gizli'] : bin2hex(random_bytes(32)),
        'token' => $token,
        'sayfa' => $sayfa,
    ];
    dosyaYaz(AYAR, $yeni);
    @unlink(GECICI);
    oturumAc($yeni);
    cevap(durum($yeni, true));

case 'sifre_degistir':
    if (!$oturum) hata('Giriş gerekli', 'giris', 401);
    if (!password_verify((string)($girdi['eski'] ?? ''), $ayar['sifre'])) { sleep(2); hata('Mevcut şifre yanlış'); }
    $y = (string)($girdi['yeni'] ?? '');
    if (strlen($y) < 6) hata('Yeni şifre en az 6 karakter olmalı.');
    $ayar['sifre'] = password_hash($y, PASSWORD_DEFAULT);
    $ayar['gizli'] = bin2hex(random_bytes(32)); // diğer cihazlardaki oturumlar kapanır
    dosyaYaz(AYAR, $ayar);
    oturumAc($ayar);
    cevap(durum($ayar, true));

case 'paylasimlar':
    if (!$oturum) hata('Giriş gerekli', $kurulu ? 'giris' : 'kurulum', 401);
    $par = [
        'fields' => 'id,message,story,created_time,permalink_url,full_picture,comments.summary(true).limit(0)',
        'limit' => 20, 'access_token' => $ayar['token'],
    ];
    if (!empty($girdi['sonraki'])) $par['after'] = (string)$girdi['sonraki'];
    $v = fbGet('me/posts', $par);
    $liste = [];
    foreach ($v['data'] ?? [] as $p) {
        $liste[] = [
            'id' => $p['id'],
            'metin' => $p['message'] ?? ($p['story'] ?? ''),
            'zaman' => $p['created_time'] ?? '',
            'link' => $p['permalink_url'] ?? '',
            'resim' => $p['full_picture'] ?? '',
            'yorum' => (int)($p['comments']['summary']['total_count'] ?? 0),
        ];
    }
    cevap(['liste' => $liste, 'sonraki' => !empty($v['paging']['next']) ? ($v['paging']['cursors']['after'] ?? null) : null]);

case 'yorumlar':
    if (!$oturum) hata('Giriş gerekli', $kurulu ? 'giris' : 'kurulum', 401);
    $ham = trim((string)($girdi['postId'] ?? ''));
    $sayfaId = $ayar['sayfa']['id'];
    if (preg_match('~^\d+_\d+$~', $ham)) $postId = $ham;
    elseif (preg_match('~^\d+$~', $ham)) $postId = $sayfaId . '_' . $ham;
    elseif (preg_match('~(?:/posts/|story_fbid=|fbid=|/videos/|/photos/[^/]+/)(\d+)~', $ham, $m)) $postId = $sayfaId . '_' . $m[1];
    elseif (str_contains($ham, 'pfbid')) hata('Bu tür (pfbid) bağlantılar çözülemiyor. Paylaşımı listeden seç.');
    else hata('Paylaşım kimliği anlaşılamadı.');

    $post = fbGet($postId, ['fields' => 'id,message,story,created_time,permalink_url,full_picture', 'access_token' => $ayar['token']]);
    $yorumlar = fbHepsi($postId . '/comments', [
        'fields' => 'id,message,created_time,from{id,name},parent{id}',
        'filter' => 'stream', 'order' => 'chronological', 'limit' => 100,
        'access_token' => $ayar['token'],
    ], 400);
    $liste = [];
    foreach ($yorumlar as $y) {
        $liste[] = [
            'k' => $y['from']['id'] ?? null,
            'a' => isset($y['from']['name']) ? trim((string)$y['from']['name']) : null,
            'm' => $y['message'] ?? '',
            't' => $y['created_time'] ?? '',
            'y' => isset($y['parent']),
        ];
    }
    cevap([
        'post' => [
            'id' => $post['id'], 'metin' => $post['message'] ?? ($post['story'] ?? ''),
            'zaman' => $post['created_time'] ?? '', 'link' => $post['permalink_url'] ?? '', 'resim' => $post['full_picture'] ?? '',
        ],
        'sayfaId' => $sayfaId,
        'yorumlar' => $liste,
        'zaman' => date('c'),
    ]);

default:
    hata('Bilinmeyen işlem');
}
