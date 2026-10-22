<?php
// Shared by the site, the feed and cron.php: database, HTTP, email, tokens and the anti-spam checks.
$cfg ??= require __DIR__ . '/config.php';
if (strlen($cfg['secret']) < 32) exit("Set a secret of at least 32 characters in config.php\n");
date_default_timezone_set($cfg['timezone'] ?? 'UTC');

function db(): PDO {
    static $db;
    if ($db) return $db;
    global $cfg;
    if (!is_dir($cfg['data'])) mkdir($cfg['data'], 0770, true);
    $db = new PDO('sqlite:' . $cfg['data'] . '/urbanismyoutube.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec("PRAGMA busy_timeout = 5000;
        CREATE TABLE IF NOT EXISTS videos (id TEXT PRIMARY KEY, channel TEXT NOT NULL, name TEXT NOT NULL, title TEXT NOT NULL, published INTEGER NOT NULL, notified INTEGER NOT NULL DEFAULT 0);
        CREATE INDEX IF NOT EXISTS videos_published ON videos (published);
        CREATE TABLE IF NOT EXISTS subs (id INTEGER PRIMARY KEY, email TEXT NOT NULL, webhook TEXT NOT NULL UNIQUE, name TEXT, status TEXT NOT NULL DEFAULT 'pending', created INTEGER, confirmed INTEGER);
        CREATE TABLE IF NOT EXISTS tokens (hash TEXT PRIMARY KEY, email TEXT NOT NULL, sub INTEGER, expires INTEGER);
        CREATE TABLE IF NOT EXISTS channels (id TEXT PRIMARY KEY, icon TEXT, checked INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS hits (key TEXT NOT NULL, at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS hits_key ON hits (key, at);");
    return $db;
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE);
}

// No redirects are followed, so a request only ever reaches the host in $url.
function http(string $method, string $url, ?array $json = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'UrbanismYoutube (+https://github.com/strongtowns/UrbanismYoutube)',
        // YouTube's relative dates ("3 days ago") are read in English
        CURLOPT_HTTPHEADER => array_merge(['Accept-Language: en'], $json === null ? [] : ['Content-Type: application/json']),
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    $body = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string)$body];
}

// Posts a message, waiting out Discord's rate limit up to twice. Mentions are switched off so nothing pings.
function discord(string $webhook, string $content): int {
    for ($try = 0; $try < 3; $try++) {
        [$code, $body] = http('POST', $webhook, ['content' => $content, 'allowed_mentions' => ['parse' => []]]);
        if ($code !== 429) return $code;
        sleep(min(30, (int)ceil(json_decode($body, true)['retry_after'] ?? 2)));
    }
    return $code;
}

// Returns the webhook in its canonical discord.com form, or null for anything that isn't a Discord webhook.
function webhook_url(string $url): ?string {
    return preg_match('~^https://(?:(?:canary|ptb)\.)?discord(?:app)?\.com/api/(?:v\d+/)?webhooks/(\d{17,20})/([\w-]{20,100})/?$~', trim($url), $m)
        ? "https://discord.com/api/webhooks/$m[1]/$m[2]" : null;
}

// Shows the webhook ID and the start of its secret, enough to tell two apart.
function webhook_label(string $url): string {
    return preg_replace('~^.*/webhooks/(\d+)/([\w-]{4}).*$~', '…/webhooks/$1/$2…', $url);
}

function feed_url(string $id): string {
    return 'https://www.youtube.com/feeds/videos.xml?' . (str_starts_with($id, 'UC') ? 'channel_id' : 'playlist_id') . '=' . urlencode($id);
}

// A channel's avatar at 88 pixels, from the og:image on its YouTube page.
function channel_icon(string $channel_id): ?string {
    [$code, $body] = http('GET', 'https://www.youtube.com/channel/' . $channel_id);
    return $code === 200 && preg_match('~<meta property="og:image" content="(https://yt3\.googleusercontent\.com/[\w/-]+)=s\d+[^"]*"~', $body, $m)
        ? $m[1] . '=s88-c-k-c0x00ffffff-no-rj' : null;
}

// Videos in a YouTube Atom feed, without Shorts.
function parse_feed(string $xml): array {
    $feed = @simplexml_load_string($xml);
    $out = [];
    foreach ($feed ? $feed->entry : [] as $e) {
        if (str_contains((string)$e->link['href'], '/shorts/')) continue;
        $id = (string)$e->children('yt', true)->videoId;
        if (preg_match('/^[\w-]{11}$/', $id)) $out[] = ['id' => $id, 'title' => (string)$e->title, 'published' => strtotime((string)$e->published)];
    }
    return $out;
}

// The 15 newest videos on a channel's Videos tab, for channels whose feed YouTube won't serve. Dates there are
// relative ("12 minutes ago", "2 months ago"), so they're exact for new videos and rough for older ones.
function parse_videos_tab(string $html): array {
    if (!preg_match('~var ytInitialData = (\{.*?\});</script>~s', $html, $m)) return [];
    $out = [];
    $walk = function ($n) use (&$walk, &$out) {
        if (!is_array($n)) return;
        $l = $n['lockupViewModel'] ?? null;
        if ($l && ($l['contentType'] ?? '') === 'LOCKUP_CONTENT_TYPE_VIDEO') {
            $md = $l['metadata']['lockupMetadataViewModel'] ?? [];
            $ago = '';
            array_walk_recursive($md, function ($v, $k) use (&$ago) {
                if ($k === 'accessibilityLabel' && is_string($v) && str_ends_with($v, ' ago')) $ago = $v;
            });
            // "Streamed 2 days ago" loses its first word so strtotime can read it
            $t = strtotime(preg_replace('/^\D+(?=\d)/', '', $ago));
            $title = (string)($md['title']['content'] ?? '');
            if ($t && $title !== '' && preg_match('/^[\w-]{11}$/', $l['contentId'] ?? '')) {
                $out[] = ['id' => $l['contentId'], 'title' => $title, 'published' => $t];
            }
            return;
        }
        foreach ($n as $c) $walk($c);
    };
    $walk(json_decode($m[1], true));
    return array_slice($out, 0, 15);
}

function send_mail(string $to, string $subject, string $body): bool {
    global $cfg;
    $headers = ['From' => $cfg['from'], 'Content-Type' => 'text/plain; charset=utf-8'];
    return mail($to, $subject, $body, $headers, $cfg['envelope'] ? '-f' . $cfg['envelope'] : '');
}

// Tokens are emailed once and only their hash is kept. A token with a sub confirms that subscription;
// one without manages every webhook for its email.
function new_token(string $email, ?int $sub, ?int $ttl): string {
    $t = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO tokens VALUES (?, ?, ?, ?)')->execute([hash('sha256', $t), $email, $sub, $ttl ? time() + $ttl : null]);
    return $t;
}

function token(string $t, bool $confirm): ?array {
    $q = db()->prepare('SELECT * FROM tokens WHERE hash = ? AND (expires IS NULL OR expires > ?) AND sub IS ' . ($confirm ? 'NOT NULL' : 'NULL'));
    $q->execute([hash('sha256', $t), time()]);
    return $q->fetch() ?: null;
}

// True once $key has been seen $max times in the last $window seconds; otherwise counts this one.
function limited(string $key, int $max, int $window): bool {
    $q = db()->prepare('SELECT COUNT(*) FROM hits WHERE key = ? AND at > ?');
    $q->execute([$key, time() - $window]);
    if ($q->fetchColumn() >= $max) return true;
    db()->prepare('INSERT INTO hits VALUES (?, ?)')->execute([$key, time()]);
    return false;
}

// Proof of work: the server signs a challenge, the browser finds a nonce whose SHA-256 with it starts with
// pow_bits zero bits, and each challenge is accepted once within two hours.
function pow_challenge(): string {
    global $cfg;
    $c = time() . '.' . bin2hex(random_bytes(8));
    return $c . '.' . hash_hmac('sha256', $c, $cfg['secret']);
}

function pow_ok(string $challenge, string $nonce): bool {
    global $cfg;
    $p = explode('.', $challenge);
    return count($p) === 3 && strlen($nonce) <= 20
        && hash_equals(hash_hmac('sha256', "$p[0].$p[1]", $cfg['secret']), $p[2])
        && time() - (int)$p[0] < 7200
        && unpack('N', hash('sha256', "$challenge:$nonce", true))[1] >> (32 - $cfg['pow_bits']) === 0
        && !limited('pow:' . $p[1], 1, 7200);
}

// Honeypot field, a per-address limit (the address is stored only as a keyed hash) and the proof of work.
function bot_check(): bool {
    global $cfg;
    return ($_POST['website'] ?? '') === ''
        && !limited('ip:' . hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', $cfg['secret']), 10, 3600)
        && pow_ok($_POST['pow'] ?? '', $_POST['nonce'] ?? '');
}
