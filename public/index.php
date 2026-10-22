<?php
require __DIR__ . '/../lib.php';
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');

$base = $cfg['base_url'];
$channels = require __DIR__ . '/../channels.php';

function sub_by_id(int $id): ?array {
    $q = db()->prepare('SELECT * FROM subs WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function email_in(): ?string {
    $e = strtolower(trim($_POST['email'] ?? ''));
    return strlen($e) <= 254 && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
}

function send_confirm(array $s): void {
    global $base;
    $confirm = new_token($s['email'], $s['id'], 7 * 86400);
    $manage = new_token($s['email'], null, null);
    send_mail($s['email'], 'Confirm your Discord webhook for Urbanism YouTube', <<<TXT
        Someone, hopefully you, asked for new urbanism YouTube videos to be posted to the Discord webhook "{$s['name']}".

        Confirm it here. The link works for 7 days.
        {$base}?confirm=$confirm

        Keep this email. This link lists your webhooks and removes them at any time:
        {$base}?manage=$manage

        If you didn't ask for this, ignore this email and nothing will be posted.
        TXT);
}

function send_access(string $email): void {
    global $base;
    $manage = new_token($email, null, 86400);
    send_mail($email, 'Manage your Urbanism YouTube webhooks', <<<TXT
        This link lists the Discord webhooks that get new urbanism YouTube videos from $base and removes them. It works for 24 hours.
        {$base}?manage=$manage

        If you didn't ask for this, ignore this email.
        TXT);
}

function subscribe(): string {
    $email = email_in();
    $hook = webhook_url($_POST['webhook'] ?? '');
    if (!bot_check()) return 'The spam check failed. Reload the page and try again.';
    if (!$email) return "That email address doesn't look right.";
    if (!$hook) return "That isn't a Discord webhook URL. It starts with https://discord.com/api/webhooks/";
    $sent = "Check $email for a confirmation link. Nothing is posted to your channel until you confirm it.";
    if (limited('email:' . $email, 3, 3600)) return $sent;
    [$code, $body] = http('GET', $hook);
    if ($code !== 200) return "Discord doesn't recognize that webhook. Check the URL, and that the webhook still exists.";
    $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', json_decode($body, true)['name'] ?? '') ?: 'Webhook', 0, 80);

    $db = db();
    $q = $db->prepare('SELECT * FROM subs WHERE webhook = ?');
    $q->execute([$hook]);
    $s = $q->fetch();
    if ($s && $s['status'] === 'active') {
        // Already confirmed: its own email gets a manage link, and anyone else sees the same reply as a new request
        if ($s['email'] === $email) send_access($email);
        return $sent;
    }
    if ($s) {
        $db->prepare("UPDATE subs SET email = ?, name = ?, status = 'pending', created = ? WHERE id = ?")->execute([$email, $name, time(), $s['id']]);
        $db->prepare('DELETE FROM tokens WHERE sub = ?')->execute([$s['id']]);
        $id = $s['id'];
    } else {
        $db->prepare('INSERT INTO subs (email, webhook, name, created) VALUES (?, ?, ?, ?)')->execute([$email, $hook, $name, time()]);
        $id = (int)$db->lastInsertId();
    }
    send_confirm(sub_by_id($id));
    return $sent;
}

function access(): string {
    $email = email_in();
    if (!bot_check()) return 'The spam check failed. Reload the page and try again.';
    if (!$email) return "That email address doesn't look right.";
    $q = db()->prepare('SELECT 1 FROM subs WHERE email = ? LIMIT 1');
    $q->execute([$email]);
    if ($q->fetchColumn() && !limited('email:' . $email, 3, 3600)) send_access($email);
    return "If $email has a webhook here, a link to manage it is on its way.";
}

function confirm(): string {
    global $base;
    $t = token($_POST['token'] ?? '', true);
    $s = $t ? sub_by_id($t['sub']) : null;
    if (!$s) return 'That confirmation link has expired or was already used. Submit the webhook again for a new one.';
    db()->prepare('DELETE FROM tokens WHERE sub = ?')->execute([$s['id']]);
    $code = discord($s['webhook'], "This channel will now get every new urbanism YouTube video. See them all at <$base>");
    if ($code === 401 || $code === 404) {
        db()->prepare('DELETE FROM subs WHERE id = ?')->execute([$s['id']]);
        return 'Discord says that webhook no longer exists. Make a new one and submit it again.';
    }
    db()->prepare("UPDATE subs SET status = 'active', confirmed = ? WHERE id = ?")->execute([time(), $s['id']]);
    return "Confirmed. New videos will be posted to {$s['name']}, and the email you got has a link to remove it later.";
}

function remove(): string {
    $t = token($_POST['token'] ?? '', false);
    if (!$t) return 'That link has expired. Ask for a new one below.';
    $id = (int)($_POST['sub'] ?? 0);
    $q = db()->prepare('DELETE FROM subs WHERE id = ? AND email = ?');
    $q->execute([$id, $t['email']]);
    if (!$q->rowCount()) return 'That webhook was already removed.';
    db()->prepare('DELETE FROM tokens WHERE sub = ?')->execute([$id]);
    return 'Removed. Nothing more will be posted to that webhook.';
}

$notice = $_SERVER['REQUEST_METHOD'] === 'POST' ? match ($_POST['action'] ?? '') {
    'subscribe' => subscribe(),
    'access' => access(),
    'confirm' => confirm(),
    'remove' => remove(),
    default => null,
} : null;

$pending = null;
if (isset($_GET['confirm']) && !$notice) {
    $t = token((string)$_GET['confirm'], true);
    $pending = $t ? sub_by_id($t['sub']) : null;
    if (!$pending) $notice = 'That confirmation link has expired or was already used. Submit the webhook again for a new one.';
}
$manage = null;
if (isset($_GET['manage'])) {
    $manage = token((string)$_GET['manage'], false);
    if (!$manage) $notice = 'That link has expired. Ask for a new one below.';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$videos = db()->query('SELECT * FROM videos ORDER BY published DESC LIMIT 51 OFFSET ' . ($page - 1) * 50)->fetchAll();
$token_view = $pending || $manage;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Urbanism YouTube | New videos about cities, streets and transit</title>
<meta name="description" content="Every new video from <?= count($channels) ?> urbanism YouTube channels, newest first, with an RSS feed and Discord notifications.">
<?php if ($token_view): ?><meta name="robots" content="noindex"><?php endif ?>
<meta name="theme-color" content="#0c2340">
<link rel="alternate" type="application/rss+xml" title="Urbanism YouTube" href="feed.php">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --navy: #0c2340; --orange: #fb5c28; --orange-dark: #d9461a; --cream: #f6f3ee; --paper: #fff; --ink: #18202c; --muted: #5b6676; --line: #e2ddd4; --radius: 14px; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--cream); color: var(--ink); font: 16px/1.55 Inter, system-ui, sans-serif; }
a { color: inherit; }
h1, h2, h3 { font-family: "DM Serif Display", Georgia, serif; font-weight: 400; line-height: 1.15; margin: 0; }
.wrap { max-width: 1120px; margin: 0 auto; padding: 0 16px; }
header.top { background: var(--navy); color: #fff; }
header.top .wrap { display: flex; align-items: center; gap: 18px; height: 60px; }
.brand { font-weight: 600; text-decoration: none; }
header nav { margin-left: auto; display: flex; gap: 18px; font-size: 15px; }
header nav a { text-decoration: none; opacity: .85; }
header nav a:hover { opacity: 1; color: var(--orange); }
.hero { background: var(--navy); color: #fff; padding: 40px 0 48px; }
.hero h1 { font-size: clamp(34px, 5.5vw, 54px); max-width: 15em; }
.hero h1 em { font-style: normal; color: var(--orange); }
.hero p { color: #c9d4e3; font-size: 18px; max-width: 36em; margin: 14px 0 24px; }
.btn { display: inline-flex; align-items: center; padding: 11px 20px; border-radius: 999px; font: 600 15px Inter, system-ui, sans-serif; text-decoration: none; border: 0; cursor: pointer; }
.btn.primary { background: var(--orange); color: #fff; }
.btn.primary:hover { background: var(--orange-dark); }
.btn.primary:disabled { opacity: .7; cursor: wait; }
.btn.ghost { border: 1.5px solid rgba(255,255,255,.35); color: #fff; }
.btn.ghost:hover { border-color: #fff; }
.cta { display: flex; flex-wrap: wrap; gap: 12px; }
.layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 40px; padding-top: 36px; padding-bottom: 64px; align-items: start; }
@media (max-width: 860px) { .layout { grid-template-columns: 1fr; } }
.notice { background: var(--paper); border: 1px solid var(--line); border-left: 4px solid var(--orange); border-radius: 10px; padding: 14px 18px; margin-bottom: 24px; }
h2.day { font-size: 24px; margin: 28px 0 12px; }
h2.day:first-of-type { margin-top: 0; }
.video { display: flex; gap: 16px; align-items: center; padding: 10px; margin: 0 -10px; border-radius: 12px; text-decoration: none; }
.video:hover { background: var(--paper); }
.video img { width: 176px; aspect-ratio: 16/9; object-fit: cover; border-radius: 8px; flex: none; background: var(--line); }
.video strong { display: block; font-weight: 600; line-height: 1.35; }
.video small { color: var(--muted); font-size: 14px; }
@media (max-width: 520px) { .video img { width: 128px; } .video strong { font-size: 15px; } }
.pager { display: flex; justify-content: space-between; margin-top: 28px; }
.pager a { color: var(--orange-dark); font-weight: 600; text-decoration: none; }
.panel { background: var(--paper); border: 1px solid var(--line); border-radius: var(--radius); padding: 22px; margin-bottom: 20px; scroll-margin-top: 16px; }
.panel h2 { font-size: 26px; margin-bottom: 8px; }
.panel p { margin: 0 0 14px; color: #3a4554; font-size: 15px; }
.panel ol { margin: 0 0 16px; padding-left: 20px; font-size: 15px; color: #3a4554; }
label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 12px; }
input[type=url], input[type=email] { display: block; width: 100%; margin-top: 4px; padding: 10px 12px; font: 15px Inter, system-ui, sans-serif; border: 1px solid #c9c2b6; border-radius: 8px; background: #fff; }
input:focus { outline: 2px solid var(--orange); outline-offset: 1px; border-color: transparent; }
.hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
.channels { list-style: none; margin: 0; padding: 0; font-size: 15px; columns: 2; column-gap: 16px; }
@media (max-width: 860px) { .channels { columns: 3 180px; } }
.channels li { break-inside: avoid; padding: 2px 0; }
.channels a { text-decoration: none; }
.channels a:hover { color: var(--orange-dark); text-decoration: underline; }
.single { max-width: 640px; padding-top: 40px; padding-bottom: 64px; }
.hook { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px 0; border-top: 1px solid var(--line); }
.hook small { display: block; color: var(--muted); word-break: break-all; }
.textbtn { background: none; border: 0; color: var(--orange-dark); font: 600 15px Inter, system-ui, sans-serif; cursor: pointer; padding: 0; }
footer { border-top: 1px solid var(--line); padding: 24px 0 40px; color: var(--muted); font-size: 14px; }
</style>
</head>
<body>
<header class="top"><div class="wrap">
  <a class="brand" href="./">Urbanism YouTube</a>
  <nav><a href="feed.php">RSS</a><a href="./#discord">Discord</a><a href="https://github.com/strongtowns/UrbanismYoutube">GitHub</a></nav>
</div></header>

<?php if ($token_view): ?>
<main class="wrap single">
  <?php if ($notice): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif ?>
  <?php if ($pending): ?>
  <div class="panel">
    <h2>Confirm your webhook</h2>
    <p>New urbanism videos will be posted to <strong><?= h($pending['name']) ?></strong> (<?= h(webhook_label($pending['webhook'])) ?>) for <?= h($pending['email']) ?>.</p>
    <form method="post" action="./">
      <input type="hidden" name="action" value="confirm">
      <input type="hidden" name="token" value="<?= h($_GET['confirm']) ?>">
      <button class="btn primary">Confirm and start posting</button>
    </form>
  </div>
  <?php else:
    $q = db()->prepare('SELECT * FROM subs WHERE email = ? ORDER BY id');
    $q->execute([$manage['email']]);
    $subs = $q->fetchAll(); ?>
  <div class="panel">
    <h2>Your webhooks</h2>
    <p>Discord webhooks for <?= h($manage['email']) ?>.</p>
    <?php foreach ($subs as $s): ?>
    <div class="hook">
      <div>
        <strong><?= h($s['name']) ?></strong>
        <small><?= h(webhook_label($s['webhook'])) ?></small>
        <small><?= h(match ($s['status']) {
          'active' => 'Posting since ' . date('F j, Y', $s['confirmed']),
          'gone' => 'Discord says this webhook was deleted, so nothing is posted to it',
          default => 'Waiting for confirmation from the email we sent',
        }) ?></small>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="token" value="<?= h($_GET['manage']) ?>">
        <input type="hidden" name="sub" value="<?= (int)$s['id'] ?>">
        <button class="textbtn">Remove</button>
      </form>
    </div>
    <?php endforeach ?>
    <?php if (!$subs): ?><p>No webhooks left. <a class="textbtn" href="./#discord">Add one</a></p><?php endif ?>
  </div>
  <?php endif ?>
  <p><a class="textbtn" href="./">&larr; All videos</a></p>
</main>

<?php else: ?>
<section class="hero"><div class="wrap">
  <h1>New <em>urbanism</em> videos, as they go up</h1>
  <p>Every upload from <?= count($channels) ?> YouTube channels about cities, streets, housing and transit, newest first. Follow along by RSS, or have each new video posted to your Discord server.</p>
  <div class="cta"><a class="btn primary" href="#discord">Post to my Discord</a><a class="btn ghost" href="feed.php">RSS feed</a></div>
</div></section>

<main class="wrap layout">
  <div>
    <?php if ($notice): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif ?>
    <?php $day = null; foreach (array_slice($videos, 0, 50) as $v):
      $d = date('Y-m-d', $v['published']);
      if ($d !== $day): $day = $d; ?>
    <h2 class="day"><?= date(date('Y', $v['published']) === date('Y') ? 'l, F j' : 'l, F j, Y', $v['published']) ?></h2>
    <?php endif ?>
    <a class="video" href="https://www.youtube.com/watch?v=<?= h($v['id']) ?>">
      <img src="https://i.ytimg.com/vi/<?= h($v['id']) ?>/mqdefault.jpg" alt="" loading="lazy" width="320" height="180">
      <span><strong><?= h($v['title']) ?></strong><small><?= h($v['name']) ?> &middot; <?= date('g:i a', $v['published']) ?></small></span>
    </a>
    <?php endforeach ?>
    <?php if (!$videos): ?><p>No videos yet. They appear here within 15 minutes of the first check.</p><?php endif ?>
    <div class="pager">
      <span><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">&larr; Newer</a><?php endif ?></span>
      <span><?php if (count($videos) > 50): ?><a href="?page=<?= $page + 1 ?>">Older &rarr;</a><?php endif ?></span>
    </div>
  </div>

  <aside>
    <div class="panel" id="discord">
      <h2>Post them to Discord</h2>
      <ol>
        <li>In Discord, open the channel's settings, then Integrations, Webhooks, New Webhook.</li>
        <li>Copy the webhook URL and paste it below with your email.</li>
        <li>Confirm from the email we send. Posting starts with the next new video.</li>
      </ol>
      <form method="post" action="./" class="pow">
        <input type="hidden" name="action" value="subscribe">
        <input type="hidden" name="pow" value="<?= h(pow_challenge()) ?>">
        <input type="hidden" name="nonce">
        <label class="hp" aria-hidden="true">Website <input name="website" tabindex="-1" autocomplete="off"></label>
        <label>Discord webhook URL <input name="webhook" type="url" required placeholder="https://discord.com/api/webhooks/…"></label>
        <label>Your email <input name="email" type="email" required autocomplete="email"></label>
        <button class="btn primary">Send confirmation</button>
      </form>
    </div>
    <div class="panel">
      <h2>Manage or remove</h2>
      <p>The confirmation email has a link to remove your webhook. Lost it? We'll send a new one to the same address.</p>
      <form method="post" action="./" class="pow">
        <input type="hidden" name="action" value="access">
        <input type="hidden" name="pow" value="<?= h(pow_challenge()) ?>">
        <input type="hidden" name="nonce">
        <label class="hp" aria-hidden="true">Website <input name="website" tabindex="-1" autocomplete="off"></label>
        <label>Your email <input name="email" type="email" required autocomplete="email"></label>
        <button class="btn primary">Email me a link</button>
      </form>
    </div>
    <noscript><p>The forms need JavaScript for their spam check.</p></noscript>
    <div class="panel">
      <h2>Channels</h2>
      <ul class="channels">
        <?php foreach ($channels as $id => $name): ?>
        <li><a href="https://www.youtube.com/<?= str_starts_with($id, 'UC') ? 'channel/' : 'playlist?list=' ?><?= h($id) ?>"><?= h($name) ?></a></li>
        <?php endforeach ?>
      </ul>
    </div>
  </aside>
</main>
<?php endif ?>

<footer><div class="wrap">
  Checked every 15 minutes. Videos belong to their creators. Part of <a href="https://strongtowns.dev">STCD</a>, open source on <a href="https://github.com/strongtowns/UrbanismYoutube">GitHub</a>.
</div></footer>

<script>
// Each form carries a signed challenge. Work on it starts as soon as the form is touched, so it's
// usually done by the time the form is sent.
const bits = <?= (int)$cfg['pow_bits'] ?>;
async function solve(challenge) {
  const enc = new TextEncoder();
  for (let n = 0; ; n += 500) {
    const hashes = await Promise.all(Array.from({ length: 500 }, (_, i) => crypto.subtle.digest("SHA-256", enc.encode(challenge + ":" + (n + i)))));
    const i = hashes.findIndex(h => new DataView(h).getUint32(0) >>> (32 - bits) === 0);
    if (i >= 0) return String(n + i);
  }
}
document.querySelectorAll("form.pow").forEach(f => {
  let work;
  const start = () => work ??= solve(f.elements.pow.value);
  f.addEventListener("focusin", start);
  f.addEventListener("submit", async e => {
    e.preventDefault();
    const btn = f.querySelector("button");
    btn.disabled = true;
    btn.textContent = "Checking…";
    f.elements.nonce.value = await start();
    f.submit();
  });
});
</script>
</body>
</html>
