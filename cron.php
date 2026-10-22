<?php
// Reads every channel's feed, stores new videos and posts each one to every confirmed webhook.
// Run every 15 minutes from cron, as the same user PHP runs as. Prints only problems and new videos.
require __DIR__ . '/lib.php';

$db = db();
$lock = fopen($cfg['data'] . '/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;

$seen = $db->prepare('SELECT 1 FROM videos WHERE channel = ? LIMIT 1');
$add = $db->prepare('INSERT INTO videos (id, channel, name, title, published, notified) VALUES (?, ?, ?, ?, ?, ?)
    ON CONFLICT (id) DO UPDATE SET title = excluded.title');
foreach (require __DIR__ . '/channels.php' as $channel => $name) {
    [$code, $body] = http('GET', feed_url($channel));
    // YouTube's feeds answer 404 now and then for channels that exist, so each gets a second try
    if ($code !== 200) [$code, $body] = http('GET', feed_url($channel));
    $videos = $code === 200 ? parse_feed($body) : [];
    if (!$videos) { echo date('c') . " $name: HTTP $code, no videos\n"; continue; }
    $seen->execute([$channel]);
    // A channel's first read fills in its recent videos without posting them, and so does a video older than a week
    $first = !$seen->fetchColumn();
    foreach ($videos as $v) {
        $add->execute([$v['id'], $channel, $name, $v['title'], $v['published'], (int)($first || $v['published'] < time() - 7 * 86400)]);
    }
}

$subs = $db->query("SELECT * FROM subs WHERE status = 'active'")->fetchAll();
foreach ($db->query('SELECT * FROM videos WHERE notified = 0 ORDER BY published')->fetchAll() as $v) {
    echo date('c') . " New from {$v['name']}: {$v['title']}\n";
    foreach ($subs as &$s) {
        if ($s['status'] !== 'active') continue;
        $code = discord($s['webhook'], "New video from **{$v['name']}**: https://www.youtube.com/watch?v={$v['id']}");
        // Discord answers 401 or 404 once a webhook is deleted, so it stops getting posts and shows as removed
        if ($code === 401 || $code === 404) {
            $s['status'] = 'gone';
            $db->prepare("UPDATE subs SET status = 'gone' WHERE id = ?")->execute([$s['id']]);
        } elseif ($code < 200 || $code >= 300) {
            echo date('c') . " Webhook {$s['id']}: HTTP $code\n";
        }
    }
    unset($s);
    $db->prepare('UPDATE videos SET notified = 1 WHERE id = ?')->execute([$v['id']]);
}

$db->prepare('DELETE FROM tokens WHERE expires < ?')->execute([time()]);
$db->prepare('DELETE FROM hits WHERE at < ?')->execute([time() - 86400]);
$db->prepare("DELETE FROM subs WHERE status = 'pending' AND created < ?")->execute([time() - 7 * 86400]);
