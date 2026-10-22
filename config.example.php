<?php
// Copy to config.php and fill in. config.php is ignored by git.
return [
    // Public address of the public/ folder, with the trailing slash. Used for links in emails and the feed.
    'base_url' => 'https://example.org/urbanismyoutube/',
    'from' => 'Urbanism YouTube <urbanismyoutube@example.org>',
    // Envelope sender handed to sendmail as -f. Empty uses the server default, which is the safe choice
    // until the From domain has SPF or DKIM records for this server.
    'envelope' => '',
    // Long random string that signs the proof-of-work challenges: php -r 'echo bin2hex(random_bytes(32));'
    'secret' => '',
    // Folder for the SQLite database and cron lock, outside the web root and writable by PHP and cron.
    'data' => __DIR__ . '/data',
    // Leading zero bits the browser must find before a form is accepted. Each extra bit doubles the work.
    'pow_bits' => 18,
];
