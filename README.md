# Urbanism YouTube
A small PHP site that lists every new video from a set of urbanism YouTube channels, newest first, with an RSS feed. Anyone can have each new video posted to their own Discord channel by submitting a webhook and confirming it by email. It runs at https://strongtowns.dev/urbanismyoutube/.

## How it works
`cron.php` runs every 15 minutes. It reads each channel's public YouTube feed (no API key and no yt-dlp), stores new videos in SQLite and posts each one to every confirmed webhook. Shorts are left out. The first read of a channel fills in its recent videos without posting them, so adding a channel never floods anyone's Discord.

The site in `public/` shows the videos by day, serves the RSS feed at `feed.php`, and handles webhooks:

- **Adding a webhook.** A visitor pastes a Discord webhook URL and an email. The site checks with Discord that the webhook exists, then emails a confirmation link. Nothing is posted until the link is opened and confirmed with a button, so mail scanners that follow links can't confirm on their own.
- **Managing it.** The confirmation email also carries a link that lists the webhooks for that address and removes them. It keeps working, so the original email is enough to unlink later. Anyone who lost it can ask for a new link, which is sent to the same address and works for 24 hours.
- **Spam protection.** Each form carries a challenge signed by the server, and the browser has to find a proof-of-work answer before it's accepted. There's also a hidden honeypot field and limits per address and per email. Only Discord webhook URLs are accepted, and requests go nowhere else.
- **Deleted webhooks.** When Discord says a webhook is gone, it stops getting posts and shows as deleted on its manage page.

Links in emails are random tokens, and only their SHA-256 hash is stored. Visitor IP addresses are stored only as a keyed hash, for the rate limit, and cleared after a day.

## Setup
Needs PHP 8.1 or later with the curl, pdo_sqlite, simplexml and mbstring extensions, a working `mail()` (sendmail or postfix), and cron.

1. Clone the repository outside the web root and point the web server at `public/` only. `lib.php`, `config.php`, `cron.php` and the database must not be reachable from the web.
2. Copy `config.example.php` to `config.php` and fill it in. Generate the secret with `php -r 'echo bin2hex(random_bytes(32));'`.
3. Create the data folder and make it writable by the user PHP runs as, for example `mkdir data && sudo chown www-data data`.
4. Run `php check.php` to test the code, then run `cron.php` once as the web server user to fill in the videos: `sudo -u www-data php cron.php`.
5. Add the cron job as the same user, for example in `/etc/cron.d/urbanismyoutube`:
```
*/15 * * * * www-data php /var/www/urbanismyoutube/cron.php >> /var/www/urbanismyoutube/data/cron.log 2>&1
```

To serve it from a subfolder with Caddy:
```
example.org {
    redir /urbanismyoutube /urbanismyoutube/
    handle_path /urbanismyoutube/* {
        root * /var/www/urbanismyoutube/public
        php_fastcgi unix//run/php/php8.4-fpm.sock
        file_server
    }
}
```

### Email delivery
Confirmation emails come from the `from` address in `config.php`. For them to reach inboxes, the From domain needs an SPF record that includes the server, ideally with DKIM signing too. Until it has one, leave `envelope` empty so the server's own, already trusted, domain is used as the envelope sender.

## Channels
`channels.php` lists the channels by YouTube channel ID or playlist ID. Edit it to change what the site follows; a new channel is picked up on the next run.

## License
Released under the [MIT License](LICENSE).
