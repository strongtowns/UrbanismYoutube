<?php
// Self-check: php check.php. Uses a throwaway database in the temp folder and sends nothing.
$cfg = ['secret' => str_repeat('k', 32), 'data' => sys_get_temp_dir() . '/urbanismyoutube-check-' . getmypid(), 'pow_bits' => 8];
require __DIR__ . '/lib.php';

function ok(bool $pass, string $what): void {
    if (!$pass) { echo "FAIL: $what\n"; exit(1); }
}

ok(webhook_url(' https://discordapp.com/api/v10/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_-0123/ ')
    === 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_-0123', 'webhook URL is made canonical');
ok(webhook_url('https://discord.com.example.org/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz') === null, 'other hosts are refused');
ok(webhook_url('https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz?x=1') === null, 'extra parts are refused');
ok(webhook_label('https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnop') === '…/webhooks/123456789012345678/abcd…', 'label hides the secret');

$c = pow_challenge();
for ($n = 0; unpack('N', hash('sha256', "$c:$n", true))[1] >> 24 !== 0; $n++);
ok(pow_ok($c, (string)$n), 'a solved challenge passes');
ok(!pow_ok($c, (string)$n), 'a challenge passes only once');
ok(!pow_ok(substr($c, 0, -1) . 'x', (string)$n), 'a tampered challenge fails');

$feed = '<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns="http://www.w3.org/2005/Atom">
<entry><yt:videoId>abcdefghijk</yt:videoId><title>A &amp; B</title><link rel="alternate" href="https://www.youtube.com/watch?v=abcdefghijk"/><published>2026-10-21T16:00:08+00:00</published></entry>
<entry><yt:videoId>shortshorts</yt:videoId><title>Short</title><link rel="alternate" href="https://www.youtube.com/shorts/shortshorts"/><published>2026-10-21T16:00:08+00:00</published></entry>
</feed>';
ok(parse_feed($feed) === [['id' => 'abcdefghijk', 'title' => 'A & B', 'published' => strtotime('2026-10-21T16:00:08Z')]], 'feed parses without Shorts');
ok(parse_feed('not xml') === [], 'bad feed gives nothing');

$lockup = fn($id, $type, $label) => ['lockupViewModel' => ['contentId' => $id, 'contentType' => $type, 'metadata' => ['lockupMetadataViewModel' => [
    'title' => ['content' => "Video $id"], 'metadata' => ['parts' => [['accessibilityLabel' => '5 thousand views'], ['accessibilityLabel' => $label]]]]]]];
$tab = '<script>var ytInitialData = ' . json_encode(['items' => [
    $lockup('abcdefghijk', 'LOCKUP_CONTENT_TYPE_VIDEO', 'Streamed 3 days ago'),
    $lockup('PLnotavideo', 'LOCKUP_CONTENT_TYPE_PLAYLIST', '1 day ago'),
]]) . ';</script>';
$r = parse_videos_tab($tab);
ok(count($r) === 1 && $r[0]['id'] === 'abcdefghijk' && $r[0]['title'] === 'Video abcdefghijk' && abs($r[0]['published'] - strtotime('-3 days')) < 5,
    'Videos tab parses videos only, with relative dates');

$t = new_token('a@example.org', 7, 60);
ok(token($t, true)['sub'] === 7 && token($t, false) === null, 'confirm token does not manage');
$m = new_token('a@example.org', null, null);
ok(token($m, false)['email'] === 'a@example.org' && token($m, true) === null, 'manage token does not confirm');

ok(!limited('k', 2, 60) && !limited('k', 2, 60) && limited('k', 2, 60), 'limit trips on the third hit');

array_map('unlink', glob($cfg['data'] . '/*'));
rmdir($cfg['data']);
echo "All checks passed\n";
