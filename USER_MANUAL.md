# Podcatcher Web - User Manual

Podcatcher Web is a lightweight, self-hosted tool for managing your podcast subscriptions. This guide covers how to use its features and maintain your library.

## Getting Started

### Installation
1. Ensure you have **PHP 8.1+** installed with `simplexml` and `fileinfo` extensions.
2. Place the project files in a directory accessible by your web server.
3. Ensure the user running the web server has write access to their home directory (where `~/.podcatcher/` will be created).

### Running Locally
You can use PHP's built-in server for a quick setup:
```bash
php -S localhost:8080
```
Visit `http://localhost:8080` in your browser.

---

## Using the Interface

### 1. Discovering & Adding Podcasts
- **Discover Tab**: Use the search bar to find podcasts via the iTunes API. Click "Subscribe" on any result.
- **Add Manually**: If you have a direct RSS/Atom URL, use the "Add Feed" button (plus icon) and paste the link.

### 2. Managing Your Library
- **Feeds Tab**: View all your subscriptions. Click a podcast to see its episode list.
- **Updating**: Click the "Update All" button to check for new episodes across all feeds.
- **Search**: Use the global search bar to find specific episodes by title or description.

### 3. Listening to Episodes
- **Player**: Click an episode to start playback. The player supports:
    - **Variable Speed**: Adjust from 0.5x to 2.5x.
    - **Seek**: Skip forward 30s or backward 10s.
    - **Resume**: Your progress is automatically saved and synced to the server.
- **Played State**: Episodes are automatically marked as "Played" when you reach the end (>95% or <30s remaining). You can also manually toggle this state.

### 4. Downloads
- **Download**: Click the download icon on an episode to save it locally. This allows for offline listening and faster streaming.
- **Remove Download**: If you need to save space, click the "Remove Download" button on an episode. This keeps your progress but deletes the local audio file.

### 5. Settings & Themes
- Toggle between **Light**, **Dark**, and **System** (Auto) themes in the Settings tab.

---

## CLI & Automation

Podcatcher Web can be controlled from the terminal, making it easy to automate tasks like daily updates.

### Update Feeds
```bash
# Update metadata for all feeds
php index.php update

# Update metadata and automatically download all new episodes
php index.php update --download
```

### Automation (Cron)
To keep your library fresh, add a cron job:
```bash
# Update every hour
0 * * * * cd /path/to/podcatcher-web && /usr/bin/php index.php update --download >> updates.log 2>&1
```

---

## Maintenance & Data

- **Data Location**: All settings and metadata are stored in `~/.podcatcher/feeds.json`. Audio files are in `~/.podcatcher/episodes/`.
- **Auto-Cleanup**: When an episode is removed from a publisher's RSS feed, Podcatcher Web automatically deletes the corresponding local file to save space.
- **Backups**: Every time the feed data is saved, a `.bak` copy of `feeds.json` is created in the data directory.

---

## Troubleshooting

- **Permissions**: If feeds aren't saving, ensure PHP has permission to write to `~/.podcatcher/`.
- **Large Downloads**: For very large episodes, ensure your PHP `memory_limit` and `max_execution_time` are sufficient, though the downloader is designed to be memory-efficient.
- **RSS Failures**: Some feeds may use non-standard XML. If a feed fails to parse, check that it is a valid RSS or Atom podcast feed.
