<?php
/*
 * Yayın Paneli — telefondan dosya gönder, otomatik yayınla.
 */
declare(strict_types=1);
require '/var/www/yayin/lib/lib.php';
const YP_SURUM = '1.0';
const YP_CEREZ = 'yp_oturum';

function cik(array $v, int $d = 200): void {
    http_response_code($d);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function tamam(array $v = []): void { cik(['ok' => true] + $v); }
function hata(string $m, int $d = 400, string $kod = 'hata'): void { cik(['ok' => false, 'hata' => $m, 'kod' => $kod], $d); }
function guvenli(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function oturumAc(array $a): void {
    $bitis = time() + 30 * 86400;
    setcookie(YP_CEREZ, $bitis . '.' . hash_hmac('sha256', (string)$bitis, $a['gizli']), [
        'expires' => $bitis, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => guvenli(),
    ]);
}
function oturumVar(array $a): bool {
    $p = explode('.', (string)($_COOKIE[YP_CEREZ] ?? ''), 2);
    return count($p) === 2 && (int)$p[0] > time() && hash_equals(hash_hmac('sha256', $p[0], $a['gizli']), $p[1]);
}
function girisSayaci(bool $basarisiz = false, bool $sifirla = false): int {
    $d = YP_VERI . '/giris.json';
    $v = is_file($d) ? (json_decode((string)file_get_contents($d), true) ?: []) : [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    foreach ($v as $k => $x) if ($x['t'] < time() - 900) unset($v[$k]);
    if ($sifirla) unset($v[$ip]);
    if ($basarisiz) $v[$ip] = ['n' => ($v[$ip]['n'] ?? 0) + 1, 't' => time()];
    file_put_contents($d, json_encode($v), LOCK_EX);
    return $v[$ip]['n'] ?? 0;
}
function sunucuIp(): string {
    $d = '/etc/yayin/ip';
    return is_readable($d) ? trim((string)file_get_contents($d)) : ($_SERVER['SERVER_ADDR'] ?? '');
}
function uygulamalar(array $a): array {
    $l = [];
    $gh = array_column($a['github'], null, 'ad');
    foreach (scandir(YP_WWW) ?: [] as $ad) {
        if ($ad[0] === '.' || $ad === 'panel' || !is_dir(YP_WWW . '/' . $ad)) continue;
        $y = YP_WWW . '/' . $ad;
        $l[] = [
            'ad' => $ad, 'boyut' => ypBoyut($y), 'tarih' => filemtime($y),
            'veri' => is_dir($y . '/veri'), 'github' => isset($gh[$ad]),
            'giris' => is_file($y . '/index.html') || is_file($y . '/index.php'),
        ];
    }
    usort($l, fn($x, $y) => $y['tarih'] <=> $x['tarih']);
    return $l;
}
function yuklemeHatasi(int $k): string {
    return [
        UPLOAD_ERR_INI_SIZE => 'Dosya sunucunun izin verdiği boyuttan büyük.',
        UPLOAD_ERR_FORM_SIZE => 'Dosya çok büyük.', UPLOAD_ERR_PARTIAL => 'Dosya yarım yüklendi, tekrar dene.',
        UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.', UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici klasör yok.',
        UPLOAD_ERR_CANT_WRITE => 'Sunucu diske yazamadı (disk dolu olabilir).',
    ][$k] ?? 'Yükleme hatası (' . $k . ')';
}

try { $A = ypAyar(); } catch (Throwable $e) { http_response_code(500); exit('Panel kurulumu eksik: ' . htmlspecialchars($e->getMessage())); }
$oturum = oturumVar($A);

/* ---------- Yedek indirme (GET) ---------- */
if (isset($_GET['indir'])) {
    if (!$oturum) { http_response_code(403); exit('Giriş gerekli'); }
    $ad = (string)$_GET['indir'];
    $dizin = YP_WWW . '/' . $ad;
    if (!ypAdGecerli($ad) || !is_dir($dizin)) { http_response_code(404); exit('Yok'); }
    $zipYol = ypGeciciDizin() . '/yedek.zip';
    $z = new ZipArchive(); $z->open($zipYol, ZipArchive::CREATE);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dizin, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) $z->addFile($f->getPathname(), $ad . '/' . substr($f->getPathname(), strlen($dizin) + 1));
    }
    $z->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $ad . '-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($zipYol));
    readfile($zipYol);
    ypSil(dirname($zipYol));
    exit;
}

/* ---------- API (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SERVER['HTTP_X_YP'] ?? '') !== '1') hata('Geçersiz istek', 405);
    $cokParcali = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/');
    $G = $cokParcali ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?: []);
    if ($cokParcali && empty($_POST) && empty($_FILES)) hata('Dosya sunucunun izin verdiği boyuttan büyük (en fazla ' . ini_get('post_max_size') . ').');
    $islem = (string)($G['islem'] ?? '');

    if ($islem === 'giris') {
        if (girisSayaci() >= 5) hata('Çok fazla hatalı deneme. 15 dakika sonra tekrar dene.', 429);
        if (!password_verify((string)($G['sifre'] ?? ''), $A['sifre'])) { girisSayaci(true); sleep(1); hata('Şifre yanlış'); }
        girisSayaci(false, true);
        oturumAc($A);
        tamam();
    }
    if ($islem === 'cikis') { setcookie(YP_CEREZ, '', ['expires' => 1, 'path' => '/']); tamam(); }
    if ($islem === 'durum') tamam(['girisli' => $oturum]);
    if (!$oturum) hata('Giriş gerekli', 401, 'giris');

    @set_time_limit(600);
    try {
        switch ($islem) {

        case 'uygulamalar':
            tamam(['liste' => uygulamalar($A)]);

        case 'yukle':
            $ad = strtolower(trim((string)($G['ad'] ?? '')));
            $mod = (string)($G['mod'] ?? 'zip');
            if (!ypAdGecerli($ad)) hata('Uygulama adı geçersiz. Küçük harf, rakam ve tire kullan (ör. yorumlar). Şu adlar ayrılmış: ' . implode(', ', YP_AYRILMIS));
            $dosyalar = $_FILES['dosya'] ?? null;
            if (!$dosyalar) hata('Dosya seçilmedi.');
            $adlar = (array)$dosyalar['name']; $tmp = (array)$dosyalar['tmp_name']; $hat = (array)$dosyalar['error'];
            foreach ($hat as $h) if ($h !== UPLOAD_ERR_OK) hata(yuklemeHatasi((int)$h));

            if ($mod === 'zip') {
                if (count($adlar) !== 1 || !preg_match('/\.zip$/i', $adlar[0])) hata('Zip modunda tek bir .zip dosyası seç.');
                $g = ypGeciciDizin();
                try { ypZipAc($tmp[0], $g); $s = ypYayinla($ad, $g, 'zip: ' . $adlar[0]); }
                finally { ypSil($g); }
                tamam(['yayin' => $s]);
            }

            // Tek tek dosya gönderme: var olan uygulamanın içine yazar (tüm dosyaları değiştirmez)
            $alt = trim(str_replace('\\', '/', (string)($G['alt'] ?? '')), '/');
            if ($alt !== '' && (preg_match('~(^|/)\.\.(/|$)~', $alt) || !preg_match('~^[\w./-]+$~', $alt))) hata('Alt klasör adı geçersiz.');
            $hedef = YP_WWW . '/' . $ad . ($alt !== '' ? '/' . $alt : '');
            if (!is_dir($hedef)) mkdir($hedef, 0755, true);
            $yazilan = [];
            foreach ($adlar as $i => $n) {
                $n = basename(str_replace('\\', '/', $n));
                if ($n === '' || $n[0] === '.') hata("'$n' adında dosya gönderilemez.");
                if (!move_uploaded_file($tmp[$i], $hedef . '/' . $n)) hata("$n yazılamadı.");
                @chmod($hedef . '/' . $n, str_starts_with($alt, 'veri') ? 0600 : 0644);
                $yazilan[] = ($alt !== '' ? $alt . '/' : '') . $n;
            }
            @touch(YP_WWW . '/' . $ad);
            ypGunluk("DOSYA $ad: " . implode(', ', $yazilan));
            tamam(['yazilan' => $yazilan, 'uyarilar' => ypKontrol(YP_WWW . '/' . $ad)]);

        case 'sil':
            $ad = (string)($G['ad'] ?? '');
            if (!ypAdGecerli($ad) || !is_dir(YP_WWW . '/' . $ad)) hata('Uygulama bulunamadı.');
            ypSil(YP_WWW . '/' . $ad);
            ypSil(YP_GIT . '/' . $ad);
            ypAyarGuncelle(function (array &$a) use ($ad) { $a['github'] = array_values(array_filter($a['github'], fn($x) => $x['ad'] !== $ad)); });
            ypGunluk("SİL $ad");
            tamam();

        case 'github_kaydet':
            $ad = strtolower(trim((string)($G['ad'] ?? '')));
            $repo = trim((string)($G['repo'] ?? ''));
            if (!ypAdGecerli($ad)) hata('Uygulama adı geçersiz.');
            if (!ypRepoGecerli($repo)) hata('Repo adresi https://github.com/kullanici/repo biçiminde olmalı.');
            $yeni = [
                'ad' => $ad, 'repo' => $repo, 'dal' => trim((string)($G['dal'] ?? '')) ?: 'main',
                'klasor' => trim((string)($G['klasor'] ?? ''), " /"), 'oto' => !empty($G['oto']),
                'token' => trim((string)($G['token'] ?? '')), 'commit' => '', 'durum' => 'Bekliyor', 'hata' => null, 'kontrol' => 0, 'yayin' => 0,
            ];
            ypAyarGuncelle(function (array &$a) use ($yeni) {
                foreach ($a['github'] as $i => $x) if ($x['ad'] === $yeni['ad']) {
                    if ($yeni['token'] === '') $yeni['token'] = $x['token']; // boş bırakılırsa eskisini koru
                    unset($a['github'][$i]);
                }
                $a['github'][] = $yeni;
                $a['github'] = array_values($a['github']);
            });
            ypSil(YP_GIT . '/' . $ad);
            $s = ypGithubCalistir($ad, true);
            tamam(['sonuc' => $s]);

        case 'github_cek':
            $s = ypGithubCalistir((string)($G['ad'] ?? ''), !empty($G['zorla']));
            tamam(['sonuc' => $s]);

        case 'github_sil':
            $ad = (string)($G['ad'] ?? '');
            ypAyarGuncelle(function (array &$a) use ($ad) { $a['github'] = array_values(array_filter($a['github'], fn($x) => $x['ad'] !== $ad)); });
            ypSil(YP_GIT . '/' . $ad);
            tamam();

        case 'github_liste':
            $A = ypAyar();
            tamam(['liste' => array_map(fn($b) => ['token' => $b['token'] !== ''] + $b, $A['github']), 'kanca' => $A['kanca']]);

        case 'sistem':
            $ext = [];
            foreach (['curl', 'zip', 'mbstring', 'gd', 'pdo_mysql', 'pdo_sqlite', 'intl'] as $e) $ext[$e] = extension_loaded($e);
            $gunluk = is_file(YP_VERI . '/gunluk.txt') ? array_slice(file(YP_VERI . '/gunluk.txt', FILE_IGNORE_NEW_LINES) ?: [], -40) : [];
            $alanlar = is_readable('/etc/yayin/alanlar') ? array_values(array_filter(array_map('trim', file('/etc/yayin/alanlar') ?: []))) : [];
            $ip = sunucuIp();
            tamam([
                'surum' => YP_SURUM, 'php' => PHP_VERSION, 'eklenti' => $ext,
                'disk' => ['bos' => @disk_free_space('/'), 'toplam' => @disk_total_space('/')],
                'yukleme' => ini_get('upload_max_filesize'), 'ip' => $ip, 'https' => guvenli(), 'host' => $_SERVER['HTTP_HOST'] ?? '',
                'alanlar' => $alanlar, 'oneri' => $ip ? str_replace('.', '-', $ip) . '.sslip.io' : '',
                'git' => ypKomut(['git', '--version'])[0] === 0, 'gunluk' => array_reverse($gunluk),
            ]);

        case 'ssl':
            $alan = strtolower(trim((string)($G['alan'] ?? '')));
            if (!preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $alan)) hata('Alan adı geçersiz.');
            [$k, $c] = ypKomut(['sudo', '-n', '/usr/local/bin/yayin-ssl', $alan], null, 300);
            ypGunluk("HTTPS $alan: " . ($k === 0 ? 'tamam' : 'hata'));
            if ($k !== 0) hata(trim($c) ?: 'HTTPS kurulamadı (kod ' . $k . ')');
            tamam(['cikti' => $c, 'adres' => 'https://' . $alan . '/panel/']);

        case 'sifre':
            if (!password_verify((string)($G['eski'] ?? ''), $A['sifre'])) { sleep(1); hata('Mevcut şifre yanlış.'); }
            $y = (string)($G['yeni'] ?? '');
            if (strlen($y) < 8) hata('Yeni şifre en az 8 karakter olmalı.');
            $A = ypAyarGuncelle(function (array &$a) use ($y) { $a['sifre'] = password_hash($y, PASSWORD_DEFAULT); $a['gizli'] = bin2hex(random_bytes(32)); return $a; });
            oturumAc($A);
            tamam();

        case 'kanca_yenile':
            ypAyarGuncelle(function (array &$a) { $a['kanca'] = bin2hex(random_bytes(20)); });
            tamam();

        default:
            hata('Bilinmeyen işlem');
        }
    } catch (Throwable $e) {
        hata($e->getMessage());
    }
}

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0f766e">
<title>Yayın Paneli</title>
<style>
:root{--ana:#0f766e;--ana-2:#0d9488;--ana-acik:#ccfbf1;--zemin:#f3f5f5;--kart:#fff;--yazi:#111b1a;--soluk:#5f6b6a;--cizgi:#e1e6e5;
  --kirmizi:#b91c1c;--kirmizi-z:#fee2e2;--sari-z:#fef3c7;--sari:#92400e;--yesil:#15803d}
@media (prefers-color-scheme:dark){:root{--ana:#2dd4bf;--ana-2:#14b8a6;--ana-acik:#123c38;--zemin:#0c1111;--kart:#161d1d;--yazi:#e8efee;--soluk:#94a3a1;--cizgi:#26302f;
  --kirmizi:#fca5a5;--kirmizi-z:#3b1414;--sari-z:#3a2a0a;--sari:#fcd34d;--yesil:#4ade80}}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;background:var(--zemin);color:var(--yazi);font:16px/1.45 system-ui,-apple-system,Roboto,sans-serif}
button,input,select{font:inherit;color:inherit}
a{color:var(--ana)}
header{position:sticky;top:0;z-index:5;background:#0f766e;color:#fff;padding:calc(env(safe-area-inset-top) + 14px) 16px 14px;display:flex;align-items:center;justify-content:space-between}
@media (prefers-color-scheme:dark){header{background:#0b3b37}}
header h1{margin:0;font-size:18px}
main{max-width:720px;margin:0 auto;padding:14px 14px calc(env(safe-area-inset-bottom) + 90px)}
nav{position:fixed;bottom:0;left:0;right:0;background:var(--kart);border-top:1px solid var(--cizgi);display:flex;padding-bottom:env(safe-area-inset-bottom);z-index:5}
nav button{flex:1;background:none;border:0;padding:9px 2px 8px;font-size:12px;font-weight:600;color:var(--soluk);display:flex;flex-direction:column;align-items:center;gap:2px;cursor:pointer}
nav button.aktif{color:var(--ana)}
nav svg{width:22px;height:22px}
.kart{background:var(--kart);border:1px solid var(--cizgi);border-radius:14px;padding:14px;margin-bottom:12px}
.kart h2{font-size:16px;margin:0 0 8px}
.soluk{color:var(--soluk);font-size:13px}
label{display:block;font-size:14px;font-weight:600;margin:12px 0 4px}
input[type=text],input[type=password],input[type=url],select{width:100%;padding:12px;border:1px solid var(--cizgi);border-radius:10px;background:var(--zemin);font-size:16px;outline:none}
input:focus{border-color:var(--ana)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;background:var(--ana);color:#fff;border:0;border-radius:10px;padding:11px 16px;font-weight:600;cursor:pointer;text-decoration:none;min-height:44px;font-size:15px}
@media (prefers-color-scheme:dark){.btn{color:#062522}}
.btn:disabled{opacity:.55}
.btn.tam{width:100%;margin-top:14px}
.btn.ikincil{background:var(--ana-acik);color:var(--ana)}
.btn.tehlike{background:var(--kirmizi-z);color:var(--kirmizi)}
.btn.kucuk{min-height:36px;padding:7px 12px;font-size:14px}
.satir{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.mesaj{border-radius:10px;padding:12px;margin:10px 0;font-size:14px;white-space:pre-wrap;word-break:break-word}
.mesaj.hata{background:var(--kirmizi-z);color:var(--kirmizi)}
.mesaj.uyari{background:var(--sari-z);color:var(--sari)}
.mesaj.iyi{background:var(--ana-acik);color:var(--ana)}
.app-ust{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.app-ad{font-weight:700;font-size:17px;word-break:break-all}
.etiket{display:inline-block;font-size:12px;font-weight:600;padding:1px 8px;border-radius:12px;background:var(--zemin);color:var(--soluk);margin:4px 4px 0 0}
.sekme{display:flex;background:var(--zemin);border-radius:10px;padding:4px;gap:4px}
.sekme button{flex:1;border:0;background:none;padding:9px 6px;border-radius:8px;font-size:14px;font-weight:600;color:var(--soluk);cursor:pointer}
.sekme button.aktif{background:var(--kart);color:var(--ana)}
.dosya-sec{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;border:2px dashed var(--cizgi);border-radius:12px;padding:26px 12px;text-align:center;cursor:pointer;margin-top:12px}
.dosya-sec b{color:var(--ana)}
.dosya-sec input{display:none}
.ilerleme{height:8px;background:var(--zemin);border-radius:8px;overflow:hidden;margin-top:12px}
.ilerleme div{height:100%;width:0;background:var(--ana);transition:width .2s}
.onay{display:flex;align-items:center;gap:10px;margin-top:12px;font-size:15px}
.onay input{width:20px;height:20px;accent-color:var(--ana)}
.tablo div{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--cizgi);font-size:14px;gap:10px}
.tablo div:last-child{border:0}
.gunluk{font:12px/1.5 ui-monospace,Menlo,monospace;background:var(--zemin);border-radius:10px;padding:10px;max-height:300px;overflow:auto;white-space:pre-wrap;word-break:break-word}
code{background:var(--zemin);padding:2px 6px;border-radius:6px;font-size:13px;word-break:break-all}
.bos{text-align:center;color:var(--soluk);padding:26px 8px}
.yukleniyor{display:flex;justify-content:center;padding:40px}
.donen{width:30px;height:30px;border:3px solid var(--cizgi);border-top-color:var(--ana);border-radius:50%;animation:d .8s linear infinite}
@keyframes d{to{transform:rotate(360deg)}}
.bilgi{position:fixed;left:50%;bottom:calc(env(safe-area-inset-bottom) + 80px);transform:translateX(-50%);background:#222;color:#fff;padding:10px 16px;border-radius:10px;font-size:14px;z-index:9;max-width:90vw}
.gizli{display:none!important}
</style>
</head>
<body>
<header><h1>🚀 Yayın Paneli</h1><button class="btn ikincil kucuk gizli" id="cikisBtn">Çıkış</button></header>
<main id="icerik"><div class="yukleniyor"><div class="donen"></div></div></main>
<nav id="nav" class="gizli">
  <button data-e="uygulamalar" class="aktif"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>Uygulamalar</button>
  <button data-e="gonder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>Gönder</button>
  <button data-e="github"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="8" r="2.5"/><path d="M6 8.5v7M18 10.5c0 4-6 3-10.5 6"/></svg>GitHub</button>
  <button data-e="sistem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>Sistem</button>
</nav>
<script>
'use strict';
const $ = s => document.querySelector(s);
const icerik = $('#icerik');
const e = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const boyut = b => b > 1073741824 ? (b / 1073741824).toFixed(1) + ' GB' : b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
const tarih = t => t ? new Date(t * 1000).toLocaleString('tr-TR', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '-';
const kok = location.origin + '/';
let ekran = 'uygulamalar', gonderAd = '';

function bilgi(m) { const d = document.createElement('div'); d.className = 'bilgi'; d.textContent = m; document.body.appendChild(d); setTimeout(() => d.remove(), 2600); }
function mesaj(tur, m) { return `<div class="mesaj ${tur}">${e(m)}</div>`; }

async function api(islem, veri = {}) {
  let r;
  try { r = await fetch('./', { method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-YP': '1'}, body: JSON.stringify({islem, ...veri}) }); }
  catch { throw new Error('Sunucuya ulaşılamadı.'); }
  let j; try { j = await r.json(); } catch { throw new Error('Sunucu yanıtı okunamadı (HTTP ' + r.status + ').'); }
  if (!j.ok) { if (j.kod === 'giris') girisEkrani(); throw new Error(j.hata); }
  return j;
}
function yukle(form, ilerle) {
  return new Promise((coz, red) => {
    const x = new XMLHttpRequest();
    x.open('POST', './'); x.setRequestHeader('X-YP', '1'); x.withCredentials = true;
    x.upload.onprogress = ev => ev.lengthComputable && ilerle(ev.loaded / ev.total);
    x.onload = () => { let j; try { j = JSON.parse(x.responseText); } catch { return red(new Error(x.status === 413 ? 'Dosya çok büyük.' : 'Sunucu yanıtı okunamadı (HTTP ' + x.status + ').')); } j.ok ? coz(j) : red(new Error(j.hata)); };
    x.onerror = () => red(new Error('Bağlantı koptu. Tekrar dene.'));
    x.send(form);
  });
}

document.querySelectorAll('nav button').forEach(b => b.onclick = () => git(b.dataset.e));
$('#cikisBtn').onclick = async () => { await api('cikis').catch(() => {}); girisEkrani(); };
function git(ad) {
  ekran = ad;
  document.querySelectorAll('nav button').forEach(b => b.classList.toggle('aktif', b.dataset.e === ad));
  window.scrollTo(0, 0);
  ({ uygulamalar: cizUygulamalar, gonder: cizGonder, github: cizGithub, sistem: cizSistem })[ad]();
}

function girisEkrani() {
  $('#nav').classList.add('gizli'); $('#cikisBtn').classList.add('gizli');
  icerik.innerHTML = `<div class="kart" style="margin-top:40px"><h2>Giriş</h2>
    <form id="gf"><input type="password" id="gs" placeholder="Panel şifresi" autocomplete="current-password"><div id="gh"></div>
    <button class="btn tam">Giriş yap</button></form>
    <p class="soluk">Şifre, kurulum bittiğinde ekrana yazıldı. Unuttuysan sunucuda <code>cat /root/yayin-bilgi.txt</code> ile görebilirsin.</p></div>`;
  $('#gf').onsubmit = async ev => {
    ev.preventDefault(); $('#gh').innerHTML = '';
    try { await api('giris', { sifre: $('#gs').value }); basla(); } catch (h) { $('#gh').innerHTML = mesaj('hata', h.message); }
  };
}
function basla() { $('#nav').classList.remove('gizli'); $('#cikisBtn').classList.remove('gizli'); git(ekran); }

/* ---------- Uygulamalar ---------- */
async function cizUygulamalar() {
  icerik.innerHTML = '<div class="yukleniyor"><div class="donen"></div></div>';
  let j; try { j = await api('uygulamalar'); } catch (h) { icerik.innerHTML = mesaj('hata', h.message); return; }
  if (ekran !== 'uygulamalar') return;
  icerik.innerHTML = (j.liste.length ? '' : `<div class="kart bos">Henüz uygulama yok.<br><br><button class="btn" id="ilkGonder">İlk uygulamayı gönder</button></div>`)
    + j.liste.map(u => `
    <div class="kart">
      <div class="app-ust"><div><div class="app-ad">${e(u.ad)}</div><a href="${e(kok + u.ad + '/')}" target="_blank" rel="noopener">${e(location.host + '/' + u.ad + '/')}</a></div></div>
      <div>${u.github ? '<span class="etiket">GitHub bağlı</span>' : ''}${u.veri ? '<span class="etiket">veri korunuyor</span>' : ''}<span class="etiket">${boyut(u.boyut)}</span><span class="etiket">${tarih(u.tarih)}</span>${u.giris ? '' : '<span class="etiket" style="color:var(--kirmizi)">index dosyası yok</span>'}</div>
      <div class="satir">
        <a class="btn kucuk" href="${e(kok + u.ad + '/')}" target="_blank" rel="noopener">Aç</a>
        <button class="btn ikincil kucuk" data-guncelle="${e(u.ad)}">Güncelle</button>
        <a class="btn ikincil kucuk" href="?indir=${encodeURIComponent(u.ad)}">Yedek indir</a>
        <button class="btn tehlike kucuk" data-sil="${e(u.ad)}">Sil</button>
      </div>
    </div>`).join('');
  const ilk = $('#ilkGonder'); if (ilk) ilk.onclick = () => git('gonder');
  document.querySelectorAll('[data-guncelle]').forEach(b => b.onclick = () => { gonderAd = b.dataset.guncelle; git('gonder'); });
  document.querySelectorAll('[data-sil]').forEach(b => b.onclick = async () => {
    const ad = b.dataset.sil;
    if (prompt(`"${ad}" ve içindeki tüm veriler silinecek. Onaylamak için adını yaz:`) !== ad) return;
    try { await api('sil', { ad }); bilgi(ad + ' silindi'); cizUygulamalar(); } catch (h) { bilgi(h.message); }
  });
}

/* ---------- Gönder ---------- */
function cizGonder() {
  icerik.innerHTML = `
  <div class="kart">
    <h2>Dosya gönder, otomatik yayınla</h2>
    <div class="sekme" id="gMod"><button data-m="zip" class="aktif">Zip (tüm uygulama)</button><button data-m="dosya">Tek tek dosya</button></div>
    <label>Uygulama adı <span class="soluk">(adres: ${e(location.host)}/<b id="gOnizle">${e(gonderAd || 'ad')}</b>/)</span></label>
    <input type="text" id="gAd" value="${e(gonderAd)}" placeholder="ör. yorumlar" autocapitalize="none" autocomplete="off" spellcheck="false">
    <div id="gAltKutu" class="gizli"><label>Alt klasör <span class="soluk">(boş = ana klasör)</span></label><input type="text" id="gAlt" placeholder="ör. resimler" autocapitalize="none"></div>
    <label class="dosya-sec" id="gSec"><input type="file" id="gDosya" accept=".zip,application/zip"><div style="font-size:30px">📦</div><b id="gSecYazi">Zip dosyası seç</b><span class="soluk" id="gSecAlt">Telefondaki indirilenlerden seçebilirsin</span></label>
    <div class="ilerleme gizli" id="gIler"><div></div></div>
    <div id="gSonuc"></div>
    <button class="btn tam" id="gBtn" disabled>Gönder ve yayınla</button>
  </div>
  <div class="kart soluk" id="gAciklama">Zip modunda uygulamanın tüm dosyaları yenisiyle değişir. <b>veri</b> klasöründeki ayarlar ve kayıtlar korunur, yani güncelleme yaparken kurulumu baştan yapmazsın.</div>`;
  let mod = 'zip';
  const ad = $('#gAd'), dosya = $('#gDosya'), btn = $('#gBtn');
  const durum = () => {
    const n = dosya.files.length;
    $('#gSecYazi').textContent = n ? (n === 1 ? dosya.files[0].name : n + ' dosya seçildi') : (mod === 'zip' ? 'Zip dosyası seç' : 'Dosyaları seç');
    $('#gSecAlt').textContent = n ? boyut([...dosya.files].reduce((t, f) => t + f.size, 0)) : 'Telefondaki indirilenlerden seçebilirsin';
    btn.disabled = !n || !ad.value.trim();
    $('#gOnizle').textContent = ad.value.trim().toLowerCase() || 'ad';
  };
  ad.oninput = () => { ad.value = ad.value.toLowerCase().replace(/ı/g, 'i').replace(/ş/g, 's').replace(/ğ/g, 'g').replace(/ü/g, 'u').replace(/ö/g, 'o').replace(/ç/g, 'c').replace(/[^a-z0-9-]/g, '-'); durum(); };
  dosya.onchange = () => {
    if (mod === 'zip' && dosya.files[0] && !ad.value) { ad.value = dosya.files[0].name.replace(/\.zip$/i, ''); ad.oninput(); }
    durum();
  };
  document.querySelectorAll('#gMod button').forEach(b => b.onclick = () => {
    mod = b.dataset.m;
    document.querySelectorAll('#gMod button').forEach(x => x.classList.toggle('aktif', x === b));
    dosya.value = ''; dosya.multiple = mod === 'dosya'; dosya.accept = mod === 'zip' ? '.zip,application/zip' : '';
    $('#gAltKutu').classList.toggle('gizli', mod === 'zip');
    $('#gAciklama').innerHTML = mod === 'zip'
      ? 'Zip modunda uygulamanın tüm dosyaları yenisiyle değişir. <b>veri</b> klasöründeki ayarlar ve kayıtlar korunur, yani güncelleme yaparken kurulumu baştan yapmazsın.'
      : 'Seçtiğin dosyalar uygulamanın içine yazılır, aynı adlı dosyanın yerine geçer. Diğer dosyalara dokunulmaz. Tek bir dosyayı düzeltmek için idealdir.';
    durum();
  });
  btn.onclick = async () => {
    const f = new FormData();
    f.append('islem', 'yukle'); f.append('mod', mod); f.append('ad', ad.value.trim());
    if (mod === 'dosya') f.append('alt', $('#gAlt').value.trim());
    for (const d of dosya.files) f.append('dosya[]', d);
    btn.disabled = true; btn.textContent = 'Gönderiliyor…'; $('#gSonuc').innerHTML = '';
    const il = $('#gIler'); il.classList.remove('gizli');
    try {
      const j = await yukle(f, p => { il.firstElementChild.style.width = (p * 100) + '%'; if (p >= 1) btn.textContent = 'Yayınlanıyor…'; });
      const adres = kok + ad.value.trim() + '/';
      const uy = (j.yayin?.uyarilar || j.uyarilar || []);
      $('#gSonuc').innerHTML = mesaj('iyi', j.yayin
          ? `✅ Yayında: ${j.yayin.dosya} dosya${j.yayin.veriKorundu ? ', mevcut veriler korundu' : ''}.`
          : `✅ Yazıldı: ${j.yazilan.join(', ')}`)
        + uy.map(u => mesaj('uyari', '⚠️ ' + u)).join('')
        + `<a class="btn tam" href="${e(adres)}" target="_blank" rel="noopener">Uygulamayı aç</a>`;
      dosya.value = ''; gonderAd = '';
    } catch (h) { $('#gSonuc').innerHTML = mesaj('hata', h.message); }
    il.classList.add('gizli'); il.firstElementChild.style.width = 0;
    btn.textContent = 'Gönder ve yayınla'; durum();
  };
  durum();
}

/* ---------- GitHub ---------- */
async function cizGithub() {
  icerik.innerHTML = '<div class="yukleniyor"><div class="donen"></div></div>';
  let j; try { j = await api('github_liste'); } catch (h) { icerik.innerHTML = mesaj('hata', h.message); return; }
  if (ekran !== 'github') return;
  const kanca = kok + 'panel/kanca.php';
  icerik.innerHTML = j.liste.map(b => `
    <div class="kart">
      <div class="app-ad">${e(b.ad)}</div>
      <div class="soluk" style="word-break:break-all">${e(b.repo.replace('https://github.com/', ''))} · ${e(b.dal)}${b.klasor ? ' · /' + e(b.klasor) : ''}</div>
      <div><span class="etiket">${b.oto ? 'Otomatik: 5 dk' : 'Elle'}</span>${b.token ? '<span class="etiket">Özel repo</span>' : ''}<span class="etiket">${b.commit ? e(b.commit.slice(0, 7)) : 'henüz yok'}</span></div>
      <div class="soluk" style="margin-top:6px">Son kontrol: ${tarih(b.kontrol)} · ${e(b.durum || '')}</div>
      ${b.hata ? mesaj('hata', b.hata) : ''}
      <div class="satir">
        <button class="btn kucuk" data-cek="${e(b.ad)}">Şimdi güncelle</button>
        <a class="btn ikincil kucuk" href="${e(kok + b.ad + '/')}" target="_blank" rel="noopener">Aç</a>
        <button class="btn tehlike kucuk" data-gsil="${e(b.ad)}">Bağlantıyı kaldır</button>
      </div>
    </div>`).join('') + `
  <details class="kart" ${j.liste.length ? '' : 'open'}><summary style="font-weight:700;cursor:pointer">＋ GitHub reposu bağla</summary>
    <p class="soluk">Repoya her yeni gönderim otomatik olarak yayına alınır. Claude'a GitHub erişimi verirsen yaptığı güncellemeler de böylece sunucuna kendiliğinden gelir.</p>
    <label>Uygulama adı</label><input type="text" id="hAd" placeholder="ör. yorumlar" autocapitalize="none">
    <label>Repo adresi</label><input type="url" id="hRepo" placeholder="https://github.com/kullanici/repo" autocapitalize="none">
    <label>Dal</label><input type="text" id="hDal" value="main" autocapitalize="none">
    <label>Repo içindeki klasör <span class="soluk">(boş = tüm repo)</span></label><input type="text" id="hKlasor" placeholder="ör. yorum-listeleyici" autocapitalize="none">
    <label>Erişim anahtarı <span class="soluk">(sadece özel repo için)</span></label><input type="password" id="hToken" placeholder="github_pat_..." autocomplete="off">
    <label class="onay"><input type="checkbox" id="hOto" checked> 5 dakikada bir kendiliğinden kontrol et</label>
    <div id="hSonuc"></div>
    <button class="btn tam" id="hBtn">Bağla ve yayınla</button>
  </details>
  <details class="kart"><summary style="font-weight:700;cursor:pointer">⚡ Anında yayın (webhook)</summary>
    <p class="soluk">5 dakika beklemek istemezsen GitHub'da repo &gt; Settings &gt; Webhooks &gt; Add webhook bölümüne şunları gir:</p>
    <label>Payload URL</label><code>${e(kanca)}</code>
    <label>Content type</label><code>application/json</code>
    <label>Secret</label><code>${e(j.kanca)}</code>
    ${location.protocol !== 'https:' ? mesaj('uyari', 'Webhook için panelin HTTPS ile açılması önerilir (Sistem sekmesi).') : ''}
    <button class="btn ikincil kucuk" id="kYenile" style="margin-top:10px">Anahtarı yenile</button>
  </details>`;
  document.querySelectorAll('[data-cek]').forEach(b => b.onclick = async () => {
    b.disabled = true; b.textContent = 'Güncelleniyor…';
    try { const r = await api('github_cek', { ad: b.dataset.cek }); bilgi(r.sonuc.degisti ? 'Yeni sürüm yayınlandı' : 'Zaten güncel'); } catch (h) { bilgi(h.message); }
    cizGithub();
  });
  document.querySelectorAll('[data-gsil]').forEach(b => b.onclick = async () => {
    if (!confirm('Bağlantı kaldırılsın mı? Yayındaki uygulama silinmez.')) return;
    await api('github_sil', { ad: b.dataset.gsil }).catch(h => bilgi(h.message)); cizGithub();
  });
  $('#kYenile').onclick = async () => { if (!confirm('Eski anahtar çalışmaz olacak. Devam?')) return; await api('kanca_yenile'); cizGithub(); };
  $('#hBtn').onclick = async () => {
    const btn = $('#hBtn'); btn.disabled = true; btn.textContent = 'İndiriliyor ve yayınlanıyor…'; $('#hSonuc').innerHTML = '';
    try {
      await api('github_kaydet', { ad: $('#hAd').value.trim().toLowerCase(), repo: $('#hRepo').value.trim(), dal: $('#hDal').value.trim(),
        klasor: $('#hKlasor').value.trim(), token: $('#hToken').value.trim(), oto: $('#hOto').checked });
      bilgi('Bağlandı ve yayınlandı'); cizGithub();
    } catch (h) { $('#hSonuc').innerHTML = mesaj('hata', h.message); btn.disabled = false; btn.textContent = 'Bağla ve yayınla'; }
  };
}

/* ---------- Sistem ---------- */
async function cizSistem() {
  icerik.innerHTML = '<div class="yukleniyor"><div class="donen"></div></div>';
  let s; try { s = await api('sistem'); } catch (h) { icerik.innerHTML = mesaj('hata', h.message); return; }
  if (ekran !== 'sistem') return;
  const ekl = Object.entries(s.eklenti).map(([k, v]) => `<span class="etiket" style="color:${v ? 'var(--yesil)' : 'var(--kirmizi)'}">${v ? '✓' : '✗'} ${e(k)}</span>`).join('');
  icerik.innerHTML = `
  <div class="kart"><h2>HTTPS</h2>
    ${s.https ? mesaj('iyi', '🔒 Panel güvenli bağlantıyla açık.') : mesaj('uyari', 'Panel şifresiz (HTTP) bağlantıyla açık. Uygulamaların telefona kurulabilmesi ve güvenlik için HTTPS gerekli.')}
    ${s.alanlar.length ? '<div class="soluk">Sertifikalı adresler:</div>' + s.alanlar.map(a => `<div><a href="https://${e(a)}/panel/">https://${e(a)}</a></div>`).join('') : ''}
    <label>Alan adı</label><input type="text" id="sAlan" value="${e(s.alanlar.length ? '' : s.oneri)}" placeholder="ör. yorum.arac37.com" autocapitalize="none">
    <div class="soluk" style="margin-top:6px">Kendi alan adını kullanmak için önce DNS'te bir <b>A kaydı</b> ekleyip değerini <code>${e(s.ip)}</code> yap. Hazır alan adın yoksa önerilen <b>sslip.io</b> adresi hiçbir ayar gerektirmeden çalışır.</div>
    <div id="sSonuc"></div>
    <button class="btn tam" id="sBtn">HTTPS kur</button>
  </div>
  <div class="kart"><h2>Sunucu</h2><div class="tablo">
    <div><span>IP</span><b>${e(s.ip)}</b></div>
    <div><span>PHP</span><b>${e(s.php)}</b></div>
    <div><span>En büyük yükleme</span><b>${e(s.yukleme)}</b></div>
    <div><span>Disk</span><b>${s.disk.bos ? boyut(s.disk.bos) + ' boş / ' + boyut(s.disk.toplam) : '-'}</b></div>
    <div><span>Git</span><b>${s.git ? 'kurulu' : 'yok'}</b></div>
    <div><span>Panel sürümü</span><b>${e(s.surum)}</b></div>
  </div><div style="margin-top:8px">${ekl}</div></div>
  <details class="kart"><summary style="font-weight:700;cursor:pointer">Panel şifresini değiştir</summary>
    <label>Mevcut şifre</label><input type="password" id="pEski" autocomplete="current-password">
    <label>Yeni şifre</label><input type="password" id="pYeni" autocomplete="new-password">
    <div id="pSonuc"></div><button class="btn tam" id="pBtn">Değiştir</button></details>
  <div class="kart"><h2>Son işlemler</h2><div class="gunluk">${s.gunluk.length ? s.gunluk.map(e).join('\n') : 'Henüz kayıt yok.'}</div></div>`;
  $('#sBtn').onclick = async () => {
    const btn = $('#sBtn'); btn.disabled = true; btn.textContent = 'Sertifika alınıyor (1 dk sürebilir)…'; $('#sSonuc').innerHTML = '';
    try {
      const r = await api('ssl', { alan: $('#sAlan').value.trim() });
      $('#sSonuc').innerHTML = mesaj('iyi', '✅ HTTPS hazır. Paneli artık bu adresten aç ve yer imlerine ekle:') + `<a class="btn tam" href="${e(r.adres)}">${e(r.adres)}</a>`;
    } catch (h) { $('#sSonuc').innerHTML = mesaj('hata', h.message); }
    btn.disabled = false; btn.textContent = 'HTTPS kur';
  };
  $('#pBtn').onclick = async () => {
    $('#pSonuc').innerHTML = '';
    try { await api('sifre', { eski: $('#pEski').value, yeni: $('#pYeni').value }); $('#pSonuc').innerHTML = mesaj('iyi', 'Şifre değişti.'); }
    catch (h) { $('#pSonuc').innerHTML = mesaj('hata', h.message); }
  };
}

(async () => {
  try { const d = await api('durum'); d.girisli ? basla() : girisEkrani(); }
  catch (h) { icerik.innerHTML = mesaj('hata', h.message); }
})();
</script>
</body>
</html>
