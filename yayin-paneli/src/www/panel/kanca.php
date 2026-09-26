<?php
/*
 * GitHub webhook: repoya her gönderimde anında yayın.
 * GitHub > Settings > Webhooks: Payload URL = bu adres, Content type = application/json,
 * Secret = paneldeki "Webhook anahtarı".
 */
declare(strict_types=1);
require '/var/www/yayin/lib/lib.php';
header('Content-Type: text/plain; charset=utf-8');
$govde = (string)file_get_contents('php://input');
try { $a = ypAyar(); } catch (Throwable $e) { http_response_code(500); exit('ayar yok'); }
$imza = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!hash_equals('sha256=' . hash_hmac('sha256', $govde, $a['kanca']), $imza)) { http_response_code(403); exit('imza geçersiz'); }
if (($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '') === 'ping') exit('pong');
$v = json_decode($govde, true) ?: [];
$repo = strtolower((string)($v['repository']['full_name'] ?? ''));
$dal = preg_replace('~^refs/heads/~', '', (string)($v['ref'] ?? ''));
$eslesen = [];
foreach ($a['github'] as $b) {
    $r = strtolower(preg_replace('~^https://github\.com/|\.git/?$|/$~', '', $b['repo']));
    if ($r === $repo && ($b['dal'] ?: 'main') === $dal) $eslesen[] = $b['ad'];
}
if (!$eslesen) exit('eşleşen bağlantı yok');
// GitHub 10 sn içinde yanıt bekler: önce yanıtla, sonra arka planda yayınla
echo 'alındı: ' . implode(', ', $eslesen);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
ignore_user_abort(true); @set_time_limit(600);
foreach ($eslesen as $ad) { try { ypGithubCalistir($ad); } catch (Throwable $e) {} }
