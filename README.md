# Podcatcher Web

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.1-777bb4?logo=php)
![License](https://img.shields.io/badge/license-MIT-green)
![Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)

A self-hosted PHP web interface for managing podcast subscriptions. Subscribe to RSS feeds, download episodes, track what you've listened to, and play audio directly in the browser — no account, no cloud, no ads.

Designed as a web front-end companion to the [Podcatcher CLI](https://github.com/podcatcher) Python tool. Both share the same `~/.podcatcher/` data directory, so you can use either interchangeably.

---

## Features

- **Discover** new podcasts using the integrated iTunes Search API
- **Subscribe** to any standard RSS/Atom podcast feed by URL
- **Update** feeds to check for new episodes — manually in the UI or automatically via CLI
- **Download** episodes to disk with a real-time progress bar (Server-Sent Events)
- **Persistent Progress**: Your playback position is saved to the server and automatically resumed on any device. Episodes are marked as played automatically when nearly finished.
- **Advanced Player**: Skip (+/- 10s, +/- 30s) and variable playback speed controls
- **Themes**: Support for System, Light, and Dark modes via the Settings tab
- **Search**: Across all episode titles and descriptions with "Show More" toggles for long summaries
- **Track** played/unplayed state per episode
- **Manage Downloads**: Download individual episodes or auto-download all new episodes during updates. Remove specific downloads to reclaim space without losing your place.
- **Auto-Cleanup**: Episodes removed from the RSS feed by the publisher are automatically cleaned up from local storage.
- **Remove Feeds**: Completely remove a subscription and all its downloaded episodes.
- **Security**: Built-in CSRF protection for all state-changing actions

---

## Requirements

- PHP 8.1 or later
- Extensions: `simplexml`, `fileinfo`, `session`
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

---

## CLI Automation

Podcatcher Web can be run from the command line to automate feed updates and downloads (ideal for `cron` jobs).

```bash
# Update all feeds
php index.php update

# Update all feeds and automatically download any new episodes
php index.php update --download
```

**Example Crontab** (updates every hour):
```bash
0 * * * * cd /path/to/podcatcher-web && /usr/bin/php index.php update --download >> updates.log
```

---

## Project Structure

```
podcatcher-web/
├── index.php               # Entry point, router, and CLI handler
├── lib/                    # Modular PHP logic
│   ├── actions.php         # AJAX API action handlers
│   ├── downloader.php      # Streaming and SSE download logic
│   ├── rss.php             # Feed fetching and XML parsing
│   └── utils.php           # Storage and filesystem helpers
├── views/
│   └── main.html.php       # Main UI template
└── assets/
    ├── style.css           # Themeable CSS variables and styles
    └── app.js              # State-driven Vanilla JS frontend
```

### Technical Architecture

- **Backend**: Modular PHP with a single-entry router (`index.php`). Uses a "no-framework" approach with clean separation of concerns in `lib/`.
- **Frontend**: Declarative "render-from-state" model in `app.js`. Every state change (`setState()`) triggers a full UI synchronization (`render()`), ensuring the interface is always consistent.
- **Communication**: Uses standard AJAX (`fetch`) for actions and Server-Sent Events (SSE) for real-time progress during downloads.

---

## Data Storage

All data is stored in `~/.podcatcher/` relative to the user the web server runs as:

```
~/.podcatcher/
├── feeds.json          # subscriptions, episode metadata, played state, and progress
└── episodes/
    ├── podcast-slug/
    │   └── episode-file.mp3
```

`feeds.json` is compatible with the Podcatcher Python CLI — both tools read and write the same file format.

---

## Security

- **CSRF Protection**: All `POST` requests require a valid session-based security token.
- **Session Security**: Cookies are configured with `HttpOnly` and `SameSite=Strict`.
- **Access Control**: Designed for **personal/local use**. If exposing it to the internet, put it behind HTTP basic auth at the web server level.

---

## License

MIT
