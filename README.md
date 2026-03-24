# Podcatcher Web

A self-hosted PHP web interface for managing podcast subscriptions. Subscribe to RSS feeds, download episodes, track what you've listened to, and play audio directly in the browser — no account, no cloud, no ads.

Designed as a web front-end companion to the [Podcatcher CLI](https://github.com/podcatcher) Python tool. Both share the same `~/.podcatcher/` data directory, so you can use either interchangeably.

---

## Features

- **Subscribe** to any standard RSS/Atom podcast feed by URL
- **Update** feeds to check for new episodes — one at a time or all at once
- **Download** episodes to disk with a real-time progress bar (Server-Sent Events)
- **Stream** downloaded episodes directly in the browser with a persistent player bar and seeking support
- **Search** across all episode titles and descriptions
- **Track** played/unplayed state per episode
- **Remove** feeds (downloaded files are kept)

---

## Requirements

- PHP 8.1 or later
- Extensions: `simplexml`, `fileinfo` (both enabled by default in most PHP installs)
- A web server: Apache, Nginx, Caddy, or PHP's built-in server for local use
- Write access to the user's home directory (for `~/.podcatcher/`)

---

## Installation

**1. Clone the repository**

```bash
git clone https://github.com/youruser/podcatcher-web.git
cd podcatcher-web
```

**2. Serve it**

For local use, PHP's built-in server is the simplest option:

```bash
php -S localhost:8080
```

Then open [http://localhost:8080](http://localhost:8080) in your browser.

For a permanent installation, point your web server's document root at the project directory. The entry point is `index.php`.

**Apache example** (`/etc/apache2/sites-available/podcatcher.conf`):
```apache
<VirtualHost *:80>
    ServerName podcatcher.local
    DocumentRoot /var/www/podcatcher-web
    <Directory /var/www/podcatcher-web>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx example**:
```nginx
server {
    listen 80;
    server_name podcatcher.local;
    root /var/www/podcatcher-web;
    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

> **Nginx note:** Add `fastcgi_buffering off;` to your PHP location block, otherwise Nginx will buffer the Server-Sent Events stream and the download progress bar won't update in real time.

---

## Data storage

All data is stored in `~/.podcatcher/` relative to the user the web server runs as:

```
~/.podcatcher/
├── feeds.json          # subscriptions, episode metadata, played state
└── episodes/
    ├── my-podcast/
    │   └── episode-title.mp3
    └── another-show/
        └── another-episode.mp3
```

`feeds.json` is plain JSON and human-readable. It is compatible with the Podcatcher Python CLI — both tools read and write the same file.

Removing a feed through the UI deletes it from `feeds.json` but does **not** delete any downloaded audio files.

---

## Project structure

```
podcatcher-web/
├── index.php               # PHP backend — config, feed parsing, all action handlers
├── views/
│   └── main.html.php       # HTML template
└── assets/
    ├── style.css           # All styles
    └── app.js              # All client-side JavaScript
```

### How requests are handled

`index.php` serves three different roles depending on the request:

| Request type | How it's identified | What happens |
|---|---|---|
| Normal page load | `GET` with no `action` param | Loads feeds, renders `views/main.html.php` |
| AJAX action | `POST` with `X-Requested-With: XMLHttpRequest` | Returns JSON, handled by `match($action)` |
| Audio stream | `GET ?action=stream` | Streams local file with HTTP range support |
| Download progress | `GET ?action=sse_download` | Opens SSE stream, downloads file, pushes progress events |

### How downloads work

Clicking Download opens a `EventSource` connection to `?action=sse_download&slug=...&episode=...`. PHP fetches the remote audio file in 64 KB chunks, writing each to disk and pushing a Server-Sent Event with `{pct, mb_done, mb_total}` after each chunk. The browser updates the progress bar in real time. On completion, PHP writes the local file path back into `feeds.json` and sends a final `{done: true}` event. Multiple downloads run concurrently in separate SSE connections.

---

## Security

This application is designed for **personal/local use**. It has no authentication layer. If you expose it on a network:

- Put it behind HTTP basic auth at the web server level (`.htpasswd` for Apache, `auth_basic` for Nginx)
- Or use a VPN / SSH tunnel for remote access
- Restrict write access to `~/.podcatcher/` to the web server user only

---

## Compatibility with the Python CLI

The Python CLI and this web interface share `~/.podcatcher/feeds.json` without conflict, with one caveat: if both are running update or download operations at exactly the same time, the last writer wins on `feeds.json`. In practice this is unlikely to cause problems for personal use.

---

## License

MIT
