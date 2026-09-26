<?php
/* Otomatik GitHub çekme — cron her 5 dakikada www-data olarak çalıştırır. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
$kilit = fopen(YP_VERI . '/cek.kilit', 'c');
if (!flock($kilit, LOCK_EX | LOCK_NB)) exit; // önceki çalışma sürüyor
$hedef = $argv[1] ?? null;
foreach (ypAyar()['github'] as $b) {
    if ($hedef ? $b['ad'] !== $hedef : empty($b['oto'])) continue;
    try { ypGithubCalistir($b['ad']); } catch (Throwable $e) { /* günlüğe yazıldı */ }
}
