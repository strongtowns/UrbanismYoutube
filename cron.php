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
$checked = $db->prepare('SELECT checked FROM channels WHERE id = ?');
$icon = $db->prepare('INSERT INTO channels (id, icon, checked) VALUES (?, ?, ?)
    ON CONFLICT (id) DO UPDATE SET icon = COALESCE(excluded.icon, icon), checked = excluded.checked');
foreach (require __DIR__ . '/channels.php' as $channel => $name) {
    [$code, $body] = http('GET', feed_url($channel));
    // YouTube's feeds answer 404 now and then for channels that exist, so each gets a second try
    if ($code !== 200) [$code, $body] = http('GET', feed_url($channel));
    $videos = $code === 200 ? parse_feed($body) : [];
    // Some channels' feeds answer 404 every time, so those are read from the channel's Videos tab instead
    if (!$videos && str_starts_with($channel, 'UC')) {
        [$code, $body] = http('GET', "https://www.youtube.com/channel/$channel/videos");
        $videos = $code === 200 ? parse_videos_tab($body) : [];
    }
    if (!$videos) { echo date('c') . " $name: HTTP $code, no videos\n"; continue; }
    // The icon is refreshed once a day from the channel that owns the feed, which for a playlist is named in its entries
    $checked->execute([$channel]);
    if ($checked->fetchColumn() < time() - 86400 && preg_match('~(?:<yt:channelId>|"externalId":")(UC[\w-]{22})~', $body, $m)) {
        $icon->execute([$channel, channel_icon($m[1]), time()]);
    }
    $seen->execute([$channel]);
    // A channel's first read fills in its recent videos without posting them, and so does a video older than a week
    $first = !$seen->fetchColumn();
    foreach ($videos as $v) {
        $add->execute([$v['id'], $channel, $name, $v['title'], $v['published'], (int)($first || $v['published'] < time() - 7 * 86400)]);
    }
}

// Each video is marked before it's posted, so a run that dies partway skips a post rather than repeating it.
// Videos are known by ID, so one that's taken down never brings an older video back as new. One taken down and
// uploaded again gets a new ID, so a video with the same channel and title as one already posted isn't posted.
$subs = $db->query("SELECT * FROM subs WHERE status = 'active'")->fetchAll();
$mark = $db->prepare('UPDATE videos SET notified = 1 WHERE id = ?');
$posted = $db->prepare('SELECT 1 FROM videos WHERE channel = ? AND title = ? AND id <> ? AND notified = 1 LIMIT 1');
foreach ($db->query('SELECT * FROM videos WHERE notified = 0 ORDER BY published')->fetchAll() as $v) {
    $mark->execute([$v['id']]);
    $posted->execute([$v['channel'], $v['title'], $v['id']]);
    if ($posted->fetchColumn()) { echo date('c') . " Already posted from {$v['name']}: {$v['title']}\n"; continue; }
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
}

$db->prepare('DELETE FROM tokens WHERE expires < ?')->execute([time()]);
$db->prepare('DELETE FROM hits WHERE at < ?')->execute([time() - 86400]);
$db->prepare("DELETE FROM subs WHERE status = 'pending' AND created < ?")->execute([time() - 7 * 86400]);
