<?php
require __DIR__ . '/../lib.php';
header('Content-Type: application/rss+xml; charset=utf-8');
$videos = db()->query('SELECT * FROM videos ORDER BY published DESC LIMIT 50')->fetchAll();
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
  <title>Urbanism YouTube</title>
  <link><?= h($cfg['base_url']) ?></link>
  <atom:link href="<?= h($cfg['base_url']) ?>feed.php" rel="self" type="application/rss+xml"/>
  <description>New videos from urbanism YouTube channels about cities, streets, housing and transit.</description>
  <language>en</language>
<?php foreach ($videos as $v): $url = 'https://www.youtube.com/watch?v=' . $v['id']; ?>
  <item>
    <title><?= h("{$v['name']}: {$v['title']}") ?></title>
    <link><?= h($url) ?></link>
    <guid><?= h($url) ?></guid>
    <dc:creator><?= h($v['name']) ?></dc:creator>
    <pubDate><?= date(DATE_RSS, $v['published']) ?></pubDate>
    <description><?= h('<a href="' . h($url) . '"><img src="https://i.ytimg.com/vi/' . h($v['id']) . '/hqdefault.jpg" alt=""></a><p>New video from ' . h($v['name']) . '.</p>') ?></description>
  </item>
<?php endforeach ?>
</channel>
</rss>
