<?php
/*
 * Yayın Paneli — ortak işlevler (web kökünün dışında durur)
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Istanbul');

define('YP_KOK', getenv('YP_KOK') ?: '/var/www/yayin');
define('YP_WWW', YP_KOK . '/www');
define('YP_VERI', YP_KOK . '/veri');
define('YP_GIT', YP_KOK . '/git');
define('YP_GECICI', YP_KOK . '/gecici');
define('YP_AYAR', YP_VERI . '/panel.php');
const YP_AYRILMIS = ['panel', 'index', 'veri', 'www', 'git', 'well-known', 'durum'];

/* ---------- Ayar ---------- */
function ypAyar(): array {
    if (!is_file(YP_AYAR)) throw new RuntimeException('Panel ayarı bulunamadı. kur.sh ile kurulum yapılmamış.');
    if (function_exists('opcache_invalidate')) @opcache_invalidate(YP_AYAR, true);
    $a = include YP_AYAR;
    if (!is_array($a)) throw new RuntimeException('Panel ayarı okunamadı.');
    return $a + ['github' => [], 'alanlar' => []];
}
function ypAyarYaz(array $a): void {
    $g = YP_AYAR . '.' . bin2hex(random_bytes(4));
    if (file_put_contents($g, "<?php\nreturn " . var_export($a, true) . ";\n", LOCK_EX) === false) {
        throw new RuntimeException('Ayar yazılamadı: ' . YP_VERI);
    }
    @chmod($g, 0600);
    rename($g, YP_AYAR);
    if (function_exists('opcache_invalidate')) @opcache_invalidate(YP_AYAR, true);
}
/** Ayarı kilitleyerek oku-değiştir-yaz (panel ve cron aynı anda yazmasın) */
function ypAyarGuncelle(callable $f) {
    $k = fopen(YP_VERI . '/ayar.kilit', 'c');
    flock($k, LOCK_EX);
    try { $a = ypAyar(); $sonuc = $f($a); ypAyarYaz($a); return $sonuc; }
    finally { flock($k, LOCK_UN); fclose($k); }
}

function ypGunluk(string $m): void {
    $d = YP_VERI . '/gunluk.txt';
    if (is_file($d) && filesize($d) > 512 * 1024) @rename($d, $d . '.1');
    @file_put_contents($d, date('Y-m-d H:i:s') . '  ' . $m . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------- Dosya işlemleri ---------- */
function ypAdGecerli(string $ad): bool {
    return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,38}[a-z0-9]$|^[a-z0-9]$/', $ad) && !in_array($ad, YP_AYRILMIS, true);
}
function ypSil(string $yol): void {
    if (is_link($yol) || is_file($yol)) { @unlink($yol); return; }
    if (!is_dir($yol)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($yol, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($yol);
}
function ypKopyala(string $kaynak, string $hedef, array $atla = []): void {
    if (!is_dir($hedef)) mkdir($hedef, 0755, true);
    foreach (new DirectoryIterator($kaynak) as $f) {
        if ($f->isDot()) continue;
        $ad = $f->getFilename();
        if (in_array($ad, $atla, true) || $f->isLink()) continue;
        $h = $hedef . '/' . $ad;
        if ($f->isDir()) ypKopyala($f->getPathname(), $h, ['.git']);
        else copy($f->getPathname(), $h);
    }
}
function ypIzinler(string $yol): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($yol, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
        @chmod($f->getPathname(), $f->isDir() ? 0755 : 0644);
    }
    @chmod($yol, 0755);
    if (is_dir($yol . '/veri')) {
        @chmod($yol . '/veri', 0700);
        foreach (glob($yol . '/veri/*') ?: [] as $v) @chmod($v, is_dir($v) ? 0700 : 0600);
    }
}
function ypBoyut(string $yol): int {
    $t = 0;
    if (!is_dir($yol)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($yol, FilesystemIterator::SKIP_DOTS)) as $f) $t += $f->isFile() ? $f->getSize() : 0;
    return $t;
}
function ypGeciciDizin(): string {
    if (!is_dir(YP_GECICI)) mkdir(YP_GECICI, 0700, true);
    // yarım kalmış eski geçici klasörleri temizle
    foreach (glob(YP_GECICI . '/*') ?: [] as $g) if (filemtime($g) < time() - 3600) ypSil($g);
    $d = YP_GECICI . '/' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    mkdir($d, 0700);
    return $d;
}
/** Klasörün içinde tek bir alt klasör varsa (zip'lerde sık olur) onu kaynak kabul et */
function ypKaynakBul(string $dizin): string {
    $ic = array_values(array_filter(scandir($dizin) ?: [], fn($x) => !in_array($x, ['.', '..', '__MACOSX', '.DS_Store'], true)));
    if (count($ic) === 1 && is_dir($dizin . '/' . $ic[0])) return ypKaynakBul($dizin . '/' . $ic[0]);
    return $dizin;
}

/**
 * Bir klasörü uygulama olarak yayınlar. Mevcut uygulamanın veri/ klasörü (ayarlar, kayıtlar) korunur.
 * Önce yeni sürüm hazırlanır, sonra tek hamlede yer değiştirilir; yarım yayın olmaz.
 */
function ypYayinla(string $ad, string $kaynak, string $neden): array {
    if (!ypAdGecerli($ad)) throw new RuntimeException('Geçersiz uygulama adı: küçük harf, rakam ve tire kullan.');
    $kaynak = ypKaynakBul($kaynak);
    $hedef = YP_WWW . '/' . $ad;
    $yeni = YP_WWW . '/.' . $ad . '.yeni';
    $eski = YP_WWW . '/.' . $ad . '.eski';
    ypSil($yeni); ypSil($eski);
    ypKopyala($kaynak, $yeni, ['.git', '.github', '__MACOSX', '.DS_Store']);
    $dosyaSay = iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($yeni, FilesystemIterator::SKIP_DOTS)));
    if ($dosyaSay === 0) { ypSil($yeni); throw new RuntimeException('Yayınlanacak dosya yok.'); }

    $korunan = 0;
    if (is_dir($hedef . '/veri')) {
        if (!is_dir($yeni . '/veri')) mkdir($yeni . '/veri', 0700);
        foreach (scandir($hedef . '/veri') as $v) {
            if ($v === '.' || $v === '..') continue;
            ypSil($yeni . '/veri/' . $v);
            rename($hedef . '/veri/' . $v, $yeni . '/veri/' . $v);
            $korunan++;
        }
    }
    ypIzinler($yeni);
    if (is_dir($hedef)) rename($hedef, $eski);
    rename($yeni, $hedef);
    ypSil($eski);

    $kontrol = ypKontrol($hedef);
    ypGunluk("YAYIN $ad ($neden) — $dosyaSay dosya" . ($korunan ? ", veri korundu" : ''));
    return ['ad' => $ad, 'dosya' => $dosyaSay, 'veriKorundu' => $korunan > 0, 'uyarilar' => $kontrol];
}

/** Yayından sonra sık görülen sorunları yakala */
function ypKontrol(string $dizin): array {
    $u = [];
    $php = glob($dizin . '/*.php') ?: [];
    if (!is_file($dizin . '/index.html') && !is_file($dizin . '/index.php')) $u[] = 'Klasörde index.html veya index.php yok; adres açılınca 404 görünebilir.';
    foreach ($php as $p) {
        $icerik = (string)@file_get_contents($p, false, null, 0, 300000);
        if (str_contains($icerik, 'curl_init') && !function_exists('curl_init')) $u[] = basename($p) . ' curl kullanıyor ama php-curl kurulu değil.';
        if (str_contains($icerik, 'ZipArchive') && !class_exists('ZipArchive')) $u[] = basename($p) . ' zip kullanıyor ama php-zip kurulu değil.';
        if (preg_match('/\bmb_[a-z_]+\(/', $icerik) && !function_exists('mb_strlen')) $u[] = basename($p) . ' mbstring kullanıyor ama php-mbstring kurulu değil.';
        if (preg_match('/new\s+PDO\(\s*[\'"]mysql/', $icerik) && !in_array('mysql', class_exists('PDO') ? PDO::getAvailableDrivers() : [], true)) $u[] = basename($p) . ' MySQL kullanıyor ama php-mysql kurulu değil.';
    }
    return $u;
}

/* ---------- Zip ---------- */
function ypZipAc(string $zipYol, string $hedef): void {
    if (!class_exists('ZipArchive')) throw new RuntimeException('php-zip kurulu değil.');
    $z = new ZipArchive();
    if ($z->open($zipYol) !== true) throw new RuntimeException('Zip dosyası açılamadı. Dosya bozuk olabilir.');
    $toplam = 0;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        $ad = str_replace('\\', '/', $st['name']);
        if ($ad === '' || str_starts_with($ad, '/') || preg_match('~(^|/)\.\.(/|$)~', $ad) || str_contains($ad, "\0")) {
            $z->close(); throw new RuntimeException('Zip içinde güvensiz dosya yolu var: ' . $ad);
        }
        $toplam += $st['size'];
        if ($toplam > 500 * 1024 * 1024) { $z->close(); throw new RuntimeException('Zip açıldığında 500 MB\'ı geçiyor.'); }
    }
    if (!$z->extractTo($hedef)) { $z->close(); throw new RuntimeException('Zip açılamadı.'); }
    $z->close();
}

/* ---------- GitHub ---------- */
function ypKomut(array $arg, ?string $cwd = null, int $sure = 180): array {
    $env = ['HOME' => YP_VERI, 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'GIT_TERMINAL_PROMPT' => '0', 'LANG' => 'C.UTF-8'];
    $p = proc_open($arg, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $borular, $cwd, $env);
    if (!is_resource($p)) return [1, 'komut çalıştırılamadı'];
    stream_set_blocking($borular[1], false); stream_set_blocking($borular[2], false);
    $cikti = ''; $bas = time();
    while (true) {
        $cikti .= stream_get_contents($borular[1]) . stream_get_contents($borular[2]);
        $d = proc_get_status($p);
        if (!$d['running']) { $kod = $d['exitcode']; break; }
        if (time() - $bas > $sure) { proc_terminate($p); $kod = 124; $cikti .= "\nzaman aşımı"; break; }
        usleep(100000);
    }
    $cikti .= stream_get_contents($borular[1]) . stream_get_contents($borular[2]);
    fclose($borular[1]); fclose($borular[2]); proc_close($p);
    return [$kod, trim($cikti)];
}
function ypRepoGecerli(string $r): bool { return (bool)preg_match('~^https://github\.com/[\w.-]+/[\w.-]+?(\.git)?/?$~', $r); }
function ypGizle(string $m, array $g): string {
    foreach ($g as $x) if ($x !== '') $m = str_replace($x, '***', $m);
    return $m;
}

/**
 * GitHub bağlantısındaki son sürümü çeker, değiştiyse yayınlar.
 * @return array ['degisti'=>bool, 'commit'=>..., 'yayin'=>?array]
 */
function ypGithubCek(array $b, bool $zorla = false): array {
    $ad = $b['ad'];
    $repo = rtrim(preg_replace('~\.git/?$~', '', $b['repo']), '/') . '.git';
    $url = $b['token'] !== '' ? str_replace('https://', 'https://x-access-token:' . $b['token'] . '@', $repo) : $repo;
    $dal = $b['dal'] !== '' ? $b['dal'] : 'main';
    if (!preg_match('~^[\w./-]+$~', $dal)) throw new RuntimeException('Geçersiz dal adı.');
    $dizin = YP_GIT . '/' . $ad;
    if (!is_dir(YP_GIT)) mkdir(YP_GIT, 0700, true);

    if (!is_dir($dizin . '/.git')) {
        ypSil($dizin);
        [$k, $c] = ypKomut(['git', 'clone', '--depth', '1', '--branch', $dal, $url, $dizin]);
        if ($k !== 0) { ypSil($dizin); throw new RuntimeException('GitHub\'dan indirilemedi: ' . ypGizle($c, [$b['token']])); }
    } else {
        ypKomut(['git', 'remote', 'set-url', 'origin', $url], $dizin);
        [$k, $c] = ypKomut(['git', 'fetch', '--depth', '1', 'origin', $dal], $dizin);
        if ($k !== 0) throw new RuntimeException('GitHub\'dan güncelleme alınamadı: ' . ypGizle($c, [$b['token']]));
        [$k, $c] = ypKomut(['git', 'reset', '--hard', 'FETCH_HEAD'], $dizin);
        if ($k !== 0) throw new RuntimeException('Güncelleme uygulanamadı: ' . $c);
    }
    [, $commit] = ypKomut(['git', 'rev-parse', 'HEAD'], $dizin);
    [, $mesaj] = ypKomut(['git', 'log', '-1', '--pretty=%s'], $dizin);
    $commit = substr(trim($commit), 0, 40);
    if (!$zorla && $commit === ($b['commit'] ?? '') && is_dir(YP_WWW . '/' . $ad)) return ['degisti' => false, 'commit' => $commit, 'mesaj' => $mesaj];

    $klasor = trim((string)($b['klasor'] ?? ''), '/');
    if ($klasor !== '' && (str_contains($klasor, '..') || !is_dir($dizin . '/' . $klasor))) throw new RuntimeException("Repoda '$klasor' klasörü yok.");
    $kaynak = $klasor !== '' ? $dizin . '/' . $klasor : $dizin;
    // .git'i yayına taşımamak için kopyalayıp yayınla
    $gecici = ypGeciciDizin();
    try {
        ypKopyala($kaynak, $gecici, ['.git', '.github']);
        $y = ypYayinla($ad, $gecici, 'GitHub ' . substr($commit, 0, 7) . ' ' . mb_substr($mesaj, 0, 60));
    } finally { ypSil($gecici); }
    return ['degisti' => true, 'commit' => $commit, 'mesaj' => $mesaj, 'yayin' => $y];
}

/** Otomatik ve webhook çekmeleri için: bağlantıyı çek, sonucu ayara işle */
function ypGithubCalistir(string $ad, bool $zorla = false): array {
    $a = ypAyar();
    $b = null;
    foreach ($a['github'] as $x) if ($x['ad'] === $ad) $b = $x;
    if (!$b) throw new RuntimeException('Bağlantı bulunamadı.');
    try {
        $s = ypGithubCek($b, $zorla);
        $durum = $s['degisti'] ? 'Yayınlandı: ' . substr($s['commit'], 0, 7) . ' ' . $s['mesaj'] : 'Güncel';
        $hata = null;
    } catch (Throwable $e) {
        $s = null; $durum = 'Hata'; $hata = $e->getMessage();
        ypGunluk("GITHUB HATA $ad: $hata");
    }
    ypAyarGuncelle(function (array &$a) use ($ad, $s, $durum, $hata) {
        foreach ($a['github'] as &$x) if ($x['ad'] === $ad) {
            $x['kontrol'] = time();
            $x['durum'] = $durum;
            $x['hata'] = $hata;
            if ($s) { $x['commit'] = $s['commit']; if ($s['degisti']) $x['yayin'] = time(); }
        }
    });
    if ($hata) throw new RuntimeException($hata);
    return $s;
}

/* ---------- Ana repo: uygulamalar/ altındaki her klasör kendiliğinden bağlanır ---------- */
/**
 * Ayardaki 'ana' repoyu çeker; kök klasörünün altındaki her uygulama klasörü için
 * henüz bağlantı yoksa otomatik bir GitHub bağlantısı ekler. Böylece repoya yeni bir
 * klasör eklemek, panele girmeden yeni bir uygulama yayınlamak için yeterlidir.
 * @return string[] yeni bağlanan uygulama adları
 */
function ypAnaEsitle(): array {
    $ana = ypAyar()['ana'] ?? null;
    if (!is_array($ana) || empty($ana['repo']) || !ypRepoGecerli((string)$ana['repo'])) return [];
    $dal = (string)($ana['dal'] ?? '') ?: 'main';
    $kok = trim((string)($ana['kok'] ?? 'uygulamalar'), '/');
    if (!preg_match('~^[\w./-]+$~', $dal) || $kok === '' || str_contains($kok, '..')) return [];
    $repo = rtrim(preg_replace('~\.git/?$~', '', (string)$ana['repo']), '/');
    $dizin = YP_GIT . '/.ana';
    if (!is_dir(YP_GIT)) mkdir(YP_GIT, 0700, true);
    if (!is_dir($dizin . '/.git')) {
        ypSil($dizin);
        [$k, $c] = ypKomut(['git', 'clone', '--depth', '1', '--branch', $dal, $repo . '.git', $dizin]);
        if ($k !== 0) { ypSil($dizin); throw new RuntimeException('Ana repo indirilemedi: ' . $c); }
    } else {
        [$k, $c] = ypKomut(['git', 'fetch', '--depth', '1', 'origin', $dal], $dizin);
        if ($k !== 0) throw new RuntimeException('Ana repo güncellenemedi: ' . $c);
        ypKomut(['git', 'reset', '--hard', 'FETCH_HEAD'], $dizin);
    }
    $adlar = [];
    foreach (scandir($dizin . '/' . $kok) ?: [] as $ad) {
        $yol = $dizin . '/' . $kok . '/' . $ad;
        if ($ad[0] !== '.' && is_dir($yol) && !is_link($yol) && ypAdGecerli($ad)) $adlar[] = $ad;
    }
    $eklenen = [];
    ypAyarGuncelle(function (array &$a) use ($adlar, $repo, $dal, $kok, &$eklenen) {
        $var = array_column($a['github'], 'ad');
        foreach ($adlar as $ad) {
            if (in_array($ad, $var, true)) continue;
            $a['github'][] = [
                'ad' => $ad, 'repo' => $repo, 'dal' => $dal, 'klasor' => $kok . '/' . $ad, 'oto' => true,
                'token' => '', 'commit' => '', 'durum' => 'Bekliyor', 'hata' => null, 'kontrol' => 0, 'yayin' => 0,
            ];
            $eklenen[] = $ad;
        }
    });
    foreach ($eklenen as $ad) ypGunluk("OTOMATİK BAĞLANDI $ad ($kok/$ad)");
    return $eklenen;
}

/**
 * Son yayın durumunu gizli adresli bir dosyaya yazar: /durum/<anahtar>.json
 * Sunucuya girmeden "yayın oldu mu, hata var mı" sorusunun yanıtı buradan okunur.
 */
function ypDurumYaz(): void {
    $a = ypAyar();
    $anahtar = (string)($a['durum'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $anahtar)) return;
    $d = YP_WWW . '/durum';
    if (!is_dir($d)) mkdir($d, 0755, true);
    $satirlar = is_file(YP_VERI . '/gunluk.txt') ? (file(YP_VERI . '/gunluk.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $v = [
        'zaman' => date('Y-m-d H:i:s'),
        'uygulamalar' => array_map(fn($b) => [
            'ad' => $b['ad'], 'klasor' => $b['klasor'] ?? '', 'durum' => $b['durum'] ?? '', 'hata' => $b['hata'] ?? null,
            'commit' => substr((string)($b['commit'] ?? ''), 0, 7),
            'yayin' => !empty($b['yayin']) ? date('Y-m-d H:i:s', (int)$b['yayin']) : null,
            'kontrol' => !empty($b['kontrol']) ? date('Y-m-d H:i:s', (int)$b['kontrol']) : null,
            'uyarilar' => is_dir(YP_WWW . '/' . $b['ad']) ? ypKontrol(YP_WWW . '/' . $b['ad']) : ['Yayında değil'],
        ], $a['github']),
        'gunluk' => array_slice($satirlar, -40),
    ];
    $g = $d . '/.' . bin2hex(random_bytes(4));
    file_put_contents($g, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @chmod($g, 0644);
    rename($g, $d . '/' . $anahtar . '.json');
}

