# Urbanism YouTube
**https://strongtowns.dev/urbanismyoutube/**

Every new video from urbanism YouTube channels about cities, streets, housing and transit, in one list, newest first. Follow it by RSS at https://strongtowns.dev/urbanismyoutube/feed.php, or have each new video posted to your group's Discord server by pasting a webhook on the page and confirming it by email. There's nothing to install.

Want a channel added? Open an issue or a pull request against `channels.php`.

## How it works
`cron.php` runs every 15 minutes. It reads each channel's public YouTube feed (no API key and no yt-dlp), stores new videos in SQLite and posts each one to every confirmed webhook. Shorts are left out. When YouTube won't serve a channel's feed, which happens every time for some channels, its videos are read from the channel's Videos tab instead. The first read of a channel fills in its recent videos without posting them, so adding a channel never floods anyone's Discord. Each channel's icon is refreshed from its YouTube page once a day.

No video is posted twice. Videos are tracked by ID, so one that's taken down never brings an older video back as new, and a video re-uploaded with the same title as one already posted is skipped.

The site in `public/` shows the videos by day, serves the RSS feed at `feed.php`, and handles webhooks:

- **Adding a webhook.** A visitor pastes a Discord webhook URL and an email. The site checks with Discord that the webhook exists, then emails a confirmation link. Nothing is posted until the link is opened and confirmed with a button, so mail scanners that follow links can't confirm on their own.
- **Managing it.** The confirmation email also carries a link that lists the webhooks for that address and removes them. It keeps working, so the original email is enough to unlink later. Anyone who lost it can ask for a new link, which is sent to the same address and works for 24 hours.
- **Spam protection.** Each form carries a challenge signed by the server, and the browser has to find a proof-of-work answer before it's accepted. There's also a hidden honeypot field and limits per address and per email. Only Discord webhook URLs are accepted, and requests go nowhere else.
- **Deleted webhooks.** When Discord says a webhook is gone, it stops getting posts and shows as deleted on its manage page.

Links in emails are random tokens, and only their SHA-256 hash is stored. Visitor IP addresses are stored only as a keyed hash, for the rate limit, and cleared after a day.

## Running your own copy
The page is open source, so a group that wants its own channel list can run a copy.

Needs PHP 8.1 or later with the curl, pdo_sqlite, simplexml and mbstring extensions, a working `mail()` (sendmail or postfix), and cron.

1. Clone the repository into the site's folder, for example `/var/www/example.org/urbanismyoutube`, and serve only its `public/` folder. `lib.php`, `config.php`, `cron.php` and the database must not be reachable from the web, so hide the folder from the rest of the site as in the Caddy example below.
2. Copy `config.example.php` to `config.php` and fill it in. Generate the secret with `php -r 'echo bin2hex(random_bytes(32));'`.
3. Create the data folder and make it writable by the user PHP runs as, for example `mkdir data && sudo chown www-data data`.
4. Run `php check.php` to test the code, then run `cron.php` once as the web server user to fill in the videos: `sudo -u www-data php cron.php`.
5. Add the cron job as the same user, for example in `/etc/cron.d/urbanismyoutube`:
```
*/15 * * * * www-data php /var/www/example.org/urbanismyoutube/cron.php >> /var/www/example.org/urbanismyoutube/data/cron.log 2>&1
```

To serve it from a subfolder with Caddy:
```
example.org {
    redir /urbanismyoutube /urbanismyoutube/
    handle_path /urbanismyoutube/* {
        root * /var/www/example.org/urbanismyoutube/public
        php_fastcgi unix//run/php/php8.4-fpm.sock
        file_server
    }
    handle {
        root * /var/www/example.org
        file_server {
            hide /var/www/example.org/urbanismyoutube
        }
    }
}
```

### Email delivery
Confirmation emails come from the `from` address in `config.php`. For them to reach inboxes, the From domain needs an SPF record that includes the server, ideally with DKIM signing too. Until it has one, leave `envelope` empty so the server's own, already trusted, domain is used as the envelope sender.

## Channels
`channels.php` lists the channels by YouTube channel ID or playlist ID. Edit it to change what the site follows; a new channel is picked up on the next run.

## License
Released under the [MIT License](LICENSE).
