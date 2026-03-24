<?php
/**
 * Podcatcher Web — A PHP web interface for managing podcast subscriptions
 * Mirrors the Python CLI tool's full feature set.
 */

// ─── Config & Storage ──────────────────────────────────────────────────────────

define('DATA_DIR',    (getenv('HOME') ?: posix_getpwuid(posix_getuid())['dir']) . '/.podcatcher');
define('FEEDS_FILE',  DATA_DIR . '/feeds.json');
define('EPISODES_DIR', DATA_DIR . '/episodes');
define('USER_AGENT',  'Podcatcher/1.0 +https://github.com/podcatcher');

function ensure_dirs(): void {
    if (!is_dir(DATA_DIR))     mkdir(DATA_DIR, 0755, true);
    if (!is_dir(EPISODES_DIR)) mkdir(EPISODES_DIR, 0755, true);
}

function episode_dir(string $slug): string {
    $path = EPISODES_DIR . '/' . $slug;
    if (!is_dir($path)) mkdir($path, 0755, true);
    return $path;
}

function load_feeds(): array {
    if (!file_exists(FEEDS_FILE)) return [];
    $json = file_get_contents(FEEDS_FILE);
    return json_decode($json, true) ?? [];
}

function save_feeds(array $feeds): void {
    file_put_contents(FEEDS_FILE, json_encode($feeds, JSON_PRETTY_PRINT));
}

function slugify(string $title): string {
    $s = strtolower($title);
    $s = preg_replace('/[^\w\s-]/', '', $s);
    $s = preg_replace('/[\s_-]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 40) ?: 'podcast';
}

function unique_slug(string $slug, array $feeds): string {
    if (!isset($feeds[$slug])) return $slug;
    $i = 2;
    while (isset($feeds["{$slug}-{$i}"])) $i++;
    return "{$slug}-{$i}";
}

function safe_filename(string $title, string $url): string {
    $parsed = parse_url($url);
    $ext = pathinfo($parsed['path'] ?? '', PATHINFO_EXTENSION);
    $ext = $ext ? '.' . $ext : '.mp3';
    $name = strtolower($title);
    $name = preg_replace('/[^\w\s-]/', '', $name);
    $name = preg_replace('/[\s_-]+/', '-', $name);
    $name = trim($name, '-');
    return substr($name, 0, 80) . $ext;
}

// ─── HTTP / RSS ────────────────────────────────────────────────────────────────

function fetch_url(string $url, int $timeout = 15): array {
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => 'User-Agent: ' . USER_AGENT . "\r\n",
            'timeout'         => $timeout,
            'follow_location' => 1,
            'max_redirects'   => 10,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $err = error_get_last();
        return ['ok' => false, 'error' => $err['message'] ?? 'Unknown error', 'body' => null];
    }
    return ['ok' => true, 'error' => null, 'body' => $body];
}

function parse_feed(string $xml): ?array {
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    if (!$doc) return null;

    $ns_itunes = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    $channel   = $doc->channel;
    if (!$channel) return null;

    $title       = (string)($channel->title ?? 'Untitled Podcast');
    $description = (string)($channel->description ?? '');
    $link        = (string)($channel->link ?? '');
    $lastBuild   = (string)($channel->lastBuildDate ?? '');

    $image_url = '';
    if ($channel->image) {
        $image_url = (string)($channel->image->url ?? '');
    }
    if (!$image_url) {
        $itunes = $channel->children($ns_itunes);
        if ($itunes && $itunes->image) {
            $attrs = $itunes->image->attributes();
            $image_url = (string)($attrs['href'] ?? '');
        }
    }

    $episodes = [];
    foreach ($channel->item as $item) {
        $ep = parse_episode($item);
        if ($ep) $episodes[] = $ep;
    }

    return [
        'title'       => $title,
        'description' => substr($description, 0, 200),
        'link'        => $link,
        'last_build'  => $lastBuild,
        'image_url'   => $image_url,
        'episodes'    => $episodes,
    ];
}

function parse_episode(SimpleXMLElement $item): ?array {
    $ns_itunes = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

    $title       = (string)($item->title ?? 'Untitled Episode');
    $pub_date    = (string)($item->pubDate ?? '');
    $guid        = (string)($item->guid ?? '');
    $description = (string)($item->description ?? '');

    $itunes   = $item->children($ns_itunes);
    $duration = $itunes ? (string)($itunes->duration ?? '') : '';

    $audio_url = '';
    $file_size = 0;
    $mime_type = '';
    if ($item->enclosure) {
        $attrs     = $item->enclosure->attributes();
        $audio_url = (string)($attrs['url'] ?? '');
        $file_size = (int)($attrs['length'] ?? 0);
        $mime_type = (string)($attrs['type'] ?? '');
    }

    if (!$audio_url) return null;

    return [
        'title'       => $title,
        'pub_date'    => $pub_date,
        'guid'        => $guid ?: $audio_url,
        'audio_url'   => $audio_url,
        'file_size'   => $file_size,
        'mime_type'   => $mime_type,
        'duration'    => $duration,
        'description' => trim(substr(strip_tags($description), 0, 300)),
    ];
}

// ─── Action Handlers ───────────────────────────────────────────────────────────

function action_add(): array {
    $url  = trim($_POST['url'] ?? '');
    $name = trim($_POST['name'] ?? '');

    if (!$url) return ['error' => 'URL is required.'];
    $p = parse_url($url);
    if (!in_array($p['scheme'] ?? '', ['http', 'https'])) {
        return ['error' => 'URL must begin with http:// or https://'];
    }

    $feeds = load_feeds();
    foreach ($feeds as $slug => $feed) {
        if ($feed['url'] === $url) {
            return ['info' => "Feed already exists as <strong>{$slug}</strong>: {$feed['meta']['title']}"];
        }
    }

    $result = fetch_url($url);
    if (!$result['ok']) return ['error' => 'Could not fetch feed: ' . $result['error']];

    $meta = parse_feed($result['body']);
    if (!$meta) return ['error' => 'Could not parse the RSS feed.'];

    $episodes = $meta['episodes'];
    unset($meta['episodes']);

    $slug = $name ? $name : slugify($meta['title']);
    $slug = unique_slug($slug, $feeds);

    $known_guids = array_column($episodes, 'guid');

    $feeds[$slug] = [
        'url'          => $url,
        'added'        => date('c'),
        'last_updated' => date('c'),
        'meta'         => $meta,
        'episodes'     => $episodes,
        'known_guids'  => $known_guids,
    ];

    save_feeds($feeds);
    $count = count($episodes);
    return ['success' => "Added <strong>{$meta['title']}</strong> as <code>[{$slug}]</code> — {$count} episode(s) found."];
}

function action_update(): array {
    $slug = trim($_POST['slug'] ?? '');
    $feeds = load_feeds();
    if (!$feeds) return ['error' => 'No feeds to update.'];

    if ($slug) {
        if (!isset($feeds[$slug])) return ['error' => "No feed with slug '{$slug}'."];
        $targets = [$slug => $feeds[$slug]];
    } else {
        $targets = $feeds;
    }

    $total_new = 0;
    $log = [];

    foreach ($targets as $s => $feed) {
        $result = fetch_url($feed['url']);
        if (!$result['ok']) { $log[] = "[{$s}] ✗ Fetch failed"; continue; }

        $parsed = parse_feed($result['body']);
        if (!$parsed) { $log[] = "[{$s}] ✗ Parse failed"; continue; }

        $new_eps     = $parsed['episodes'];
        $known_guids = array_flip($feed['known_guids'] ?? []);
        $fresh       = array_filter($new_eps, fn($e) => !isset($known_guids[$e['guid']]));

        $played_guids = array_flip(array_column(array_filter($feed['episodes'] ?? [], fn($e) => $e['played'] ?? false), 'guid'));
        $local_paths  = [];
        foreach ($feed['episodes'] ?? [] as $e) {
            if ($e['local_path'] ?? '') $local_paths[$e['guid']] = $e['local_path'];
        }

        foreach ($new_eps as &$ep) {
            $ep['played']     = isset($played_guids[$ep['guid']]);
            $ep['local_path'] = $local_paths[$ep['guid']] ?? '';
        }
        unset($ep);

        $feeds[$s]['meta']         = array_merge($feeds[$s]['meta'], array_diff_key($parsed, ['episodes' => true]));
        $feeds[$s]['episodes']     = $new_eps;
        $feeds[$s]['known_guids']  = array_column($new_eps, 'guid');
        $feeds[$s]['last_updated'] = date('c');

        $n = count($fresh);
        $total_new += $n;
        $log[] = "[{$s}] ✔ " . ($n > 0 ? "{$n} new episode(s)" : "No new episodes");
    }

    save_feeds($feeds);
    return ['success' => implode('<br>', $log) . "<br><em>{$total_new} new episode(s) total.</em>"];
}

function action_remove(): array {
    $slug = trim($_POST['slug'] ?? '');
    $feeds = load_feeds();
    if (!isset($feeds[$slug])) return ['error' => "No feed with slug '{$slug}'."];
    $title = $feeds[$slug]['meta']['title'];
    unset($feeds[$slug]);
    save_feeds($feeds);
    return ['success' => "Removed <strong>{$title}</strong>."];
}

function action_mark_played(): array {
    $slug      = trim($_POST['slug'] ?? '');
    $episode   = (int)($_POST['episode'] ?? 0);
    $unplayed  = ($_POST['unplayed'] ?? '') === '1';

    $feeds = load_feeds();
    if (!isset($feeds[$slug])) return ['error' => "No feed with slug '{$slug}'."];

    $episodes = &$feeds[$slug]['episodes'];
    $state    = !$unplayed;
    $label    = $unplayed ? 'unplayed' : 'played';

    if ($episode > 0) {
        $idx = $episode - 1;
        if (!isset($episodes[$idx])) return ['error' => 'Episode index out of range.'];
        $episodes[$idx]['played'] = $state;
        save_feeds($feeds);
        return ['success' => "Marked episode {$episode} as {$label}."];
    }

    foreach ($episodes as &$ep) $ep['played'] = $state;
    unset($ep);
    save_feeds($feeds);
    return ['success' => "Marked all episodes as {$label}."];
}

function action_search(): array {
    $query  = strtolower(trim($_POST['query'] ?? ''));
    $limit  = max(1, (int)($_POST['limit'] ?? 10));
    $feeds  = load_feeds();
    $output = [];

    foreach ($feeds as $slug => $feed) {
        $matches = array_filter($feed['episodes'] ?? [], fn($ep) =>
            str_contains(strtolower($ep['title']), $query) ||
            str_contains(strtolower($ep['description'] ?? ''), $query)
        );
        if ($matches) {
            $output[$slug] = [
                'title'    => $feed['meta']['title'],
                'episodes' => array_slice(array_values($matches), 0, $limit),
            ];
        }
    }

    return ['results' => $output, 'query' => $_POST['query'] ?? ''];
}


// ─── Stream local audio file ───────────────────────────────────────────────────

function action_stream(): void {
    $slug    = preg_replace('/[^a-z0-9_-]/i', '', $_GET['slug'] ?? '');
    $episode = (int)($_GET['episode'] ?? 0);
    if (!$slug || $episode < 1) { http_response_code(400); exit; }

    $feeds = load_feeds();
    if (!isset($feeds[$slug])) { http_response_code(404); exit; }

    $ep = $feeds[$slug]['episodes'][$episode - 1] ?? null;
    if (!$ep) { http_response_code(404); exit; }

    $path = $ep['local_path'] ?? '';
    if (!$path || !file_exists($path)) { http_response_code(404); exit; }

    $size = filesize($path);
    $mime = $ep['mime_type'] ?: mime_content_type($path) ?: 'audio/mpeg';

    // Range support so the browser can seek
    $start = 0;
    $end   = $size - 1;
    $status = 200;

    if (isset($_SERVER['HTTP_RANGE'])) {
        preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m);
        $start  = (int)$m[1];
        $end    = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $size - 1;
        $status = 206;
    }

    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: '    . $mime);
    header('Content-Length: '  . $length);
    header('Accept-Ranges: bytes');
    if ($status === 206) {
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    header('Cache-Control: no-store');

    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = min(65536, $remaining);
        echo fread($fh, $chunk);
        $remaining -= $chunk;
        flush();
    }
    fclose($fh);
    exit;
}

// ─── SSE: download one episode and stream progress ─────────────────────────────

function action_sse_download(): void {
    $slug    = preg_replace('/[^a-z0-9_-]/i', '', $_GET['slug'] ?? '');
    $episode = (int)($_GET['episode'] ?? 0);
    if (!$slug || $episode < 1) {
        sse_event(['error' => 'Missing slug or episode']); exit;
    }

    // Disable output buffering entirely
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');  // nginx: disable proxy buffering

    function sse_event(array $data): void {
        echo 'data: ' . json_encode($data) . "\n\n";
        flush();
    }

    $feeds = load_feeds();
    if (!isset($feeds[$slug])) { sse_event(['error' => "Feed '{$slug}' not found"]); exit; }

    $episodes = $feeds[$slug]['episodes'] ?? [];
    $idx = $episode - 1;
    if (!isset($episodes[$idx])) { sse_event(['error' => 'Episode not found']); exit; }

    $ep  = $episodes[$idx];
    $url = $ep['audio_url'];

    $dest_dir  = episode_dir($slug);
    $filename  = safe_filename($ep['title'], $url);
    $dest      = $dest_dir . '/' . $filename;

    if (file_exists($dest)) {
        // Already downloaded — update path and return immediately
        $feeds[$slug]['episodes'][$idx]['local_path'] = $dest;
        save_feeds($feeds);
        sse_event(['pct' => 100, 'mb_done' => round(filesize($dest)/1048576, 1),
                   'mb_total' => round(filesize($dest)/1048576, 1),
                   'done' => true, 'path' => $dest, 'already' => true]);
        exit;
    }

    // Open remote with User-Agent
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => 'User-Agent: ' . USER_AGENT . "\r\n",
            'timeout'         => 60,
            'follow_location' => 1,
            'max_redirects'   => 10,
        ],
        'ssl' => ['verify_peer' => true],
    ]);

    $remote = @fopen($url, 'rb', false, $ctx);
    if (!$remote) {
        sse_event(['error' => 'Could not open remote URL']); exit;
    }

    // Try to get Content-Length from response headers
    $meta_data = stream_get_meta_data($remote);
    $total = 0;
    foreach (($meta_data['wrapper_data'] ?? []) as $h) {
        if (stripos($h, 'Content-Length:') === 0) {
            $total = (int)trim(substr($h, 15));
            break;
        }
    }

    $local = fopen($dest, 'wb');
    if (!$local) {
        fclose($remote);
        sse_event(['error' => 'Could not write local file']); exit;
    }

    $downloaded  = 0;
    $chunk_size  = 65536;
    $last_report = -1;

    while (!feof($remote)) {
        $buf = fread($remote, $chunk_size);
        if ($buf === false) break;
        fwrite($local, $buf);
        $downloaded += strlen($buf);

        $pct = $total > 0 ? (int)($downloaded * 100 / $total) : -1;

        // Only push an event when percentage ticks (or every ~256 KB if no total)
        if ($pct !== $last_report || ($total === 0 && $downloaded % (256*1024) < $chunk_size)) {
            $last_report = $pct;
            sse_event([
                'pct'      => $pct,
                'mb_done'  => round($downloaded / 1048576, 1),
                'mb_total' => $total > 0 ? round($total / 1048576, 1) : null,
            ]);
        }
    }

    fclose($remote);
    fclose($local);

    if ($downloaded === 0) {
        @unlink($dest);
        sse_event(['error' => 'Download produced empty file']); exit;
    }

    // Persist local_path
    $feeds = load_feeds();   // re-load in case of concurrent writes
    $feeds[$slug]['episodes'][$idx]['local_path'] = $dest;
    save_feeds($feeds);

    sse_event(['pct' => 100, 'mb_done' => round($downloaded/1048576,1),
               'mb_total' => round($downloaded/1048576,1), 'done' => true, 'path' => $dest]);
    exit;
}


// ─── GET action routing (stream / SSE) ────────────────────────────────────────
$get_action = $_GET['action'] ?? null;
if ($get_action === 'stream')       { action_stream(); }
if ($get_action === 'sse_download') { action_sse_download(); }

// ─── Handle AJAX/POST ──────────────────────────────────────────────────────────

ensure_dirs();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$action  = $_POST['action'] ?? null;

if ($is_ajax && $action) {
    header('Content-Type: application/json');
    $response = match($action) {
        'add'         => action_add(),
        'update'      => action_update(),
        'remove'      => action_remove(),
        'mark_played' => action_mark_played(),
        'search'      => action_search(),
        'get_feeds'   => ['feeds' => load_feeds()],
        default       => ['error' => 'Unknown action'],
    };
    echo json_encode($response);
    exit;
}

$feeds = load_feeds();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Podcatcher</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,500;0,600;1,400&family=IBM+Plex+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  /* ── Reset & Root ── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #0d0e10;
    --bg2:       #141619;
    --bg3:       #1c1e22;
    --bg4:       #242629;
    --border:    #2e3035;
    --border2:   #3a3d43;
    --amber:     #f5a623;
    --amber2:    #ffc456;
    --amber-dim: #7a5210;
    --green:     #4caf7d;
    --red:       #e05c5c;
    --blue:      #5b9bd5;
    --muted:     #5a5e68;
    --text:      #d4d6db;
    --text2:     #8a8f9a;
    --mono:      'IBM Plex Mono', monospace;
    --sans:      'IBM Plex Sans', sans-serif;
    --radius:    4px;
    --gutter:    24px;
  }

  html { scroll-behavior: smooth; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--mono);
    font-size: 13px;
    line-height: 1.6;
    min-height: 100vh;
  }

  /* scanline overlay */
  body::before {
    content: '';
    position: fixed; inset: 0;
    background: repeating-linear-gradient(
      0deg, transparent, transparent 2px,
      rgba(0,0,0,0.04) 2px, rgba(0,0,0,0.04) 4px
    );
    pointer-events: none;
    z-index: 9999;
  }

  /* ── Layout ── */
  .app { display: flex; min-height: 100vh; }

  .sidebar {
    width: 220px;
    flex-shrink: 0;
    background: var(--bg2);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
  }

  .main {
    flex: 1;
    padding: var(--gutter);
    max-width: 960px;
    overflow: hidden;
  }

  /* ── Logo ── */
  .logo {
    padding: 20px var(--gutter) 16px;
    border-bottom: 1px solid var(--border);
  }
  .logo-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--amber);
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }
  .logo-sub {
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 0.12em;
    margin-top: 2px;
  }

  /* ── Nav ── */
  nav { padding: 10px 0; flex: 1; }
  nav a {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px var(--gutter);
    color: var(--text2);
    text-decoration: none;
    font-size: 12px;
    letter-spacing: 0.04em;
    transition: background 0.15s, color 0.15s;
    border-left: 2px solid transparent;
  }
  nav a:hover { background: var(--bg3); color: var(--text); }
  nav a.active {
    background: var(--bg3);
    color: var(--amber);
    border-left-color: var(--amber);
  }
  nav a .icon { font-size: 14px; width: 16px; text-align: center; }

  .nav-section {
    padding: 14px var(--gutter) 4px;
    font-size: 9px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: var(--muted);
  }

  /* ── Header ── */
  .page-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
  }
  .page-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    font-family: var(--mono);
  }
  .page-title span { color: var(--amber); }
  .page-subtitle {
    font-size: 11px;
    color: var(--muted);
    font-family: var(--sans);
  }

  /* ── Cards / Panels ── */
  .panel {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
  }
  .panel-title {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--amber);
    margin-bottom: 14px;
  }

  /* ── Forms ── */
  .form-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: flex-end;
  }
  .form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 200px;
  }
  label {
    font-size: 10px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted);
  }
  input[type=text], input[type=url], input[type=number], select {
    background: var(--bg3);
    border: 1px solid var(--border2);
    color: var(--text);
    font-family: var(--mono);
    font-size: 12px;
    padding: 7px 10px;
    border-radius: var(--radius);
    outline: none;
    transition: border-color 0.15s;
    width: 100%;
  }
  input:focus, select:focus {
    border-color: var(--amber-dim);
    box-shadow: 0 0 0 2px rgba(245,166,35,0.1);
  }
  input::placeholder { color: var(--muted); }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border: 1px solid transparent;
    border-radius: var(--radius);
    font-family: var(--mono);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s;
    text-decoration: none;
    letter-spacing: 0.03em;
  }
  .btn-primary {
    background: var(--amber);
    color: #0d0e10;
    border-color: var(--amber);
  }
  .btn-primary:hover { background: var(--amber2); border-color: var(--amber2); }
  .btn-ghost {
    background: transparent;
    color: var(--text2);
    border-color: var(--border2);
  }
  .btn-ghost:hover { background: var(--bg3); color: var(--text); border-color: var(--border2); }
  .btn-danger {
    background: transparent;
    color: var(--red);
    border-color: var(--red);
  }
  .btn-danger:hover { background: rgba(224,92,92,0.1); }
  .btn-sm { padding: 4px 10px; font-size: 11px; }
  .btn:disabled { opacity: 0.4; cursor: not-allowed; }

  /* ── Toast ── */
  #toast-container {
    position: fixed;
    bottom: 24px; right: 24px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .toast {
    background: var(--bg3);
    border: 1px solid var(--border2);
    border-radius: var(--radius);
    padding: 10px 14px;
    font-size: 12px;
    max-width: 360px;
    animation: toast-in 0.2s ease;
    display: flex;
    gap: 8px;
    align-items: flex-start;
  }
  .toast.success { border-left: 3px solid var(--green); }
  .toast.error   { border-left: 3px solid var(--red); }
  .toast.info    { border-left: 3px solid var(--blue); }
  .toast.fade-out { animation: toast-out 0.3s ease forwards; }
  @keyframes toast-in  { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
  @keyframes toast-out { from { opacity:1; } to { opacity:0; transform:translateX(20px); } }

  /* ── Feeds table ── */
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; }
  thead th {
    padding: 8px 10px;
    text-align: left;
    font-size: 10px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }
  tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background 0.1s;
  }
  tbody tr:hover { background: var(--bg3); }
  tbody tr:last-child { border-bottom: none; }
  td { padding: 10px 10px; vertical-align: middle; }
  td.slug { font-weight: 500; color: var(--amber); font-size: 12px; }
  td.num  { text-align: right; color: var(--text2); }
  td.date { color: var(--text2); font-size: 11px; white-space: nowrap; }
  td.title { font-family: var(--sans); font-size: 13px; }
  td.actions { white-space: nowrap; text-align: right; }

  /* ── Status / Episode list ── */
  .meta-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 4px 16px;
    margin-bottom: 16px;
    font-size: 12px;
  }
  .meta-key  { color: var(--muted); white-space: nowrap; }
  .meta-val  { color: var(--text); word-break: break-all; }

  .episode-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
  }
  .episode-row:last-child { border-bottom: none; }
  .ep-num   { color: var(--muted); font-size: 11px; width: 26px; flex-shrink: 0; padding-top: 1px; }
  .ep-markers { flex-shrink: 0; display: flex; flex-direction: column; gap: 2px; padding-top: 2px; }
  .ep-marker { font-size: 10px; line-height: 1; }
  .ep-marker.played { color: var(--green); }
  .ep-marker.dl     { color: var(--blue); }
  .ep-marker.empty  { color: transparent; }
  .ep-body  { flex: 1; min-width: 0; }
  .ep-title { font-family: var(--sans); font-size: 13px; color: var(--text); font-weight: 500; }
  .ep-meta  { font-size: 11px; color: var(--text2); margin-top: 2px; }
  .ep-actions { flex-shrink: 0; display: flex; gap: 4px; align-items: center; }

  /* ── Status badges ── */
  .badge {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.05em;
  }
  .badge-green  { background: rgba(76,175,125,0.12); color: var(--green); border: 1px solid rgba(76,175,125,0.3); }
  .badge-amber  { background: rgba(245,166,35,0.12); color: var(--amber); border: 1px solid rgba(245,166,35,0.3); }

  /* ── Tabs ── */
  .tabs { display: flex; gap: 2px; margin-bottom: 20px; border-bottom: 1px solid var(--border); }
  .tab-btn {
    padding: 8px 16px;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    color: var(--text2);
    cursor: pointer;
    font-family: var(--mono);
    font-size: 12px;
    letter-spacing: 0.04em;
    transition: all 0.15s;
  }
  .tab-btn:hover { color: var(--text); }
  .tab-btn.active { color: var(--amber); border-bottom-color: var(--amber); }
  .tab-content { display: none; }
  .tab-content.active { display: block; }

  /* ── Search results ── */
  .search-group { margin-bottom: 16px; }
  .search-group-title {
    font-size: 11px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--amber);
    margin-bottom: 6px;
    padding-bottom: 4px;
    border-bottom: 1px solid var(--border);
  }
  .search-ep {
    padding: 6px 0;
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 10px;
    align-items: baseline;
  }
  .search-ep:last-child { border-bottom: none; }
  .search-ep-title { font-family: var(--sans); font-size: 13px; flex: 1; }
  .search-ep-date  { font-size: 11px; color: var(--text2); white-space: nowrap; }

  /* ── Loader ── */
  .spinner {
    display: inline-block;
    width: 14px; height: 14px;
    border: 2px solid var(--border2);
    border-top-color: var(--amber);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    vertical-align: middle;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* ── Misc ── */
  .empty { color: var(--muted); font-size: 12px; padding: 20px 0; font-style: italic; }
  .text-amber { color: var(--amber); }
  .text-green { color: var(--green); }
  .text-red   { color: var(--red); }
  .text-muted { color: var(--muted); }
  .mt8  { margin-top: 8px; }
  .mt16 { margin-top: 16px; }
  .flex-gap { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

  /* ── Confirm overlay ── */
  .overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.7);
    z-index: 5000;
    align-items: center;
    justify-content: center;
  }
  .overlay.open { display: flex; }
  .dialog {
    background: var(--bg2);
    border: 1px solid var(--border2);
    border-radius: var(--radius);
    padding: 24px;
    max-width: 380px;
    width: 90%;
  }
  .dialog-title { font-size: 14px; font-weight: 600; margin-bottom: 8px; }
  .dialog-msg   { font-size: 12px; color: var(--text2); margin-bottom: 20px; font-family: var(--sans); }
  .dialog-btns  { display: flex; gap: 8px; justify-content: flex-end; }

  /* ── Detail slide-in ── */
  #detail-panel {
    display: none;
    margin-top: 0;
  }
  #detail-panel.open { display: block; }

  @media (max-width: 680px) {
    .sidebar { display: none; }
    .main { padding: 16px; }
  }

  /* ── Audio Player Bar ── */
  #player-bar {
    display: none;
    position: fixed;
    bottom: 0; left: 220px; right: 0;
    background: var(--bg2);
    border-top: 1px solid var(--border2);
    padding: 10px 20px;
    z-index: 4000;
    align-items: center;
    gap: 14px;
    box-shadow: 0 -4px 24px rgba(0,0,0,0.4);
  }
  #player-bar.open { display: flex; }
  #player-title {
    font-size: 11px;
    color: var(--text2);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 260px;
    flex-shrink: 0;
  }
  #player-title strong { color: var(--amber); display: block; font-size: 12px; }
  #player-audio {
    flex: 1;
    height: 32px;
    accent-color: var(--amber);
  }
  #player-audio::-webkit-media-controls-panel { background: var(--bg3); }
  #player-close {
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 16px;
    padding: 2px 6px;
    flex-shrink: 0;
    transition: color 0.15s;
  }
  #player-close:hover { color: var(--red); }

  /* ── Download progress items ── */
  .dl-item {
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 12px;
    margin-bottom: 8px;
    font-size: 12px;
  }
  .dl-item-title { color: var(--text); margin-bottom: 6px; font-family: var(--sans); }
  .dl-item-bar-wrap {
    height: 4px;
    background: var(--bg4);
    border-radius: 2px;
    overflow: hidden;
    margin-bottom: 4px;
  }
  .dl-item-bar {
    height: 100%;
    background: var(--amber);
    width: 0%;
    transition: width 0.3s;
    border-radius: 2px;
  }
  .dl-item-bar.indeterminate {
    width: 30%;
    animation: indeterminate 1.2s ease-in-out infinite;
  }
  @keyframes indeterminate {
    0%   { margin-left: -30%; }
    100% { margin-left: 100%; }
  }
  .dl-item-status { color: var(--text2); font-size: 11px; }
  .dl-item.done   { border-color: rgba(76,175,125,0.4); }
  .dl-item.done .dl-item-bar { background: var(--green); width: 100%; }
  .dl-item.error  { border-color: rgba(224,92,92,0.4); }
  .dl-item.error .dl-item-bar { background: var(--red); }

  @media (max-width: 680px) {
    #player-bar { left: 0; }
  }
</style>
</head>
<body>

<div class="app">

  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <div class="logo">
      <div class="logo-title">Podcatcher</div>
      <div class="logo-sub">// web edition</div>
    </div>
    <nav id="main-nav">
      <div class="nav-section">Feeds</div>
      <a href="#" class="nav-link active" data-tab="tab-list">
        <span class="icon">≡</span> List
      </a>
      <a href="#" class="nav-link" data-tab="tab-add">
        <span class="icon">+</span> Add Feed
      </a>
      <a href="#" class="nav-link" data-tab="tab-update">
        <span class="icon">↻</span> Update
      </a>
      <a href="#" class="nav-link" data-tab="tab-download">
        <span class="icon">⬇</span> Download
      </a>
      <div class="nav-section">Episodes</div>
      <a href="#" class="nav-link" data-tab="tab-status">
        <span class="icon">◎</span> Status
      </a>
      <a href="#" class="nav-link" data-tab="tab-mark">
        <span class="icon">✔</span> Mark Played
      </a>
      <a href="#" class="nav-link" data-tab="tab-search">
        <span class="icon">⌕</span> Search
      </a>
      <a href="#" class="nav-link" data-tab="tab-remove">
        <span class="icon">✕</span> Remove
      </a>
    </nav>
    <div style="padding:12px var(--gutter); border-top:1px solid var(--border);">
      <div style="font-size:10px;color:var(--muted);line-height:1.7;">
        Data: <code style="color:var(--text2);">~/.podcatcher/</code>
      </div>
    </div>
  </aside>

  <!-- ── Main ── -->
  <main class="main">

    <!-- ── List ── -->
    <div id="tab-list" class="tab-content active">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher list</div>
        <div class="page-subtitle"><?= count($feeds) ?> feed(s) subscribed</div>
      </div>

      <?php if (empty($feeds)): ?>
        <div class="empty">No feeds yet. Use <strong>Add Feed</strong> to subscribe to a podcast.</div>
      <?php else: ?>
      <div class="panel" style="padding:0">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Slug</th>
                <th class="num">Episodes</th>
                <th>Last Updated</th>
                <th>Title</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($feeds as $slug => $feed): ?>
              <tr>
                <td class="slug"><?= htmlspecialchars($slug) ?></td>
                <td class="num"><?= count($feed['episodes'] ?? []) ?></td>
                <td class="date"><?= htmlspecialchars(substr($feed['last_updated'] ?? '', 0, 19)) ?></td>
                <td class="title"><?= htmlspecialchars($feed['meta']['title']) ?></td>
                <td class="actions">
                  <button class="btn btn-ghost btn-sm" onclick="openStatus('<?= htmlspecialchars($slug) ?>')">status</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Add ── -->
    <div id="tab-add" class="tab-content">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher add</div>
        <div class="page-subtitle">Subscribe to a new podcast feed</div>
      </div>
      <div class="panel">
        <div class="panel-title">Feed URL</div>
        <div class="form-row">
          <div class="form-group" style="flex:3">
            <label>RSS / Atom URL</label>
            <input type="url" id="add-url" placeholder="https://feeds.megaphone.fm/example">
          </div>
          <div class="form-group" style="flex:1">
            <label>Custom Slug (optional)</label>
            <input type="text" id="add-name" placeholder="my-podcast">
          </div>
          <button class="btn btn-primary" id="btn-add" onclick="doAdd()">Add Feed</button>
        </div>
        <div id="add-result" class="mt8"></div>
      </div>
      <div class="panel" style="border-color:var(--amber-dim)">
        <div class="panel-title text-amber">Examples</div>
        <div style="font-size:11px;color:var(--text2);line-height:2;">
          <code>https://feeds.megaphone.fm/darknetdiaries</code><br>
          <code>https://feed.syntax.fm/rss</code><br>
          <code>https://www.relay.fm/cortex/feed</code>
        </div>
      </div>
    </div>

    <!-- ── Update ── -->
    <div id="tab-update" class="tab-content">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher update</div>
        <div class="page-subtitle">Refresh feeds for new episodes</div>
      </div>
      <div class="panel">
        <div class="panel-title">Refresh Options</div>
        <div class="form-row">
          <div class="form-group">
            <label>Specific Feed (leave blank to update all)</label>
            <select id="update-slug">
              <option value="">— All Feeds —</option>
              <?php foreach ($feeds as $slug => $feed): ?>
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($slug) ?> — <?= htmlspecialchars($feed['meta']['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-primary" id="btn-update" onclick="doUpdate()">↻ Update</button>
        </div>
        <div id="update-result" class="mt8"></div>
      </div>
    </div>

    <!-- ── Status ── -->
    <div id="tab-status" class="tab-content">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher status</div>
        <div class="page-subtitle">Detailed feed and episode info</div>
      </div>
      <div class="panel">
        <div class="form-row">
          <div class="form-group">
            <label>Select Feed</label>
            <select id="status-slug" onchange="loadStatus()">
              <option value="">— Choose a feed —</option>
              <?php foreach ($feeds as $slug => $feed): ?>
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($slug) ?> — <?= htmlspecialchars($feed['meta']['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="max-width:100px">
            <label>Show Episodes</label>
            <input type="number" id="status-count" value="10" min="1" max="500" oninput="loadStatus()">
          </div>
        </div>
      </div>
      <div id="status-panel"></div>
    </div>

    <!-- ── Download ── -->
    <div id="tab-download" class="tab-content">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher download</div>
        <div class="page-subtitle">Download episode audio to disk</div>
      </div>
      <div class="panel">
        <div class="panel-title">Download Options</div>
        <div class="form-row">
          <div class="form-group">
            <label>Feed (leave blank for all)</label>
            <select id="dl-slug" onchange="populateDlEpisodes()">
              <option value="">— All Feeds —</option>
              <?php foreach ($feeds as $slug => $feed): ?>
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($slug) ?> — <?= htmlspecialchars($feed['meta']['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="max-width:200px">
            <label>Episode (optional)</label>
            <select id="dl-episode-select">
              <option value="">— All undownloaded —</option>
            </select>
          </div>
        </div>
        <div class="flex-gap mt8">
          <button class="btn btn-primary" id="btn-dl"     onclick="doDownload(false)">⬇ Download</button>
          <button class="btn btn-ghost"   id="btn-dl-all" onclick="doDownload(true)">⬇ Download All</button>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:8px;">
          Files saved to <code>~/.podcatcher/episodes/&lt;slug&gt;/</code>
        </div>
      </div>
      <div id="dl-progress-list"></div>
    </div>

    <!-- ── Mark Played ── -->
    <div id="tab-mark" class="tab-content">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher mark-played</div>
        <div class="page-subtitle">Track episode listen state</div>
      </div>
      <div class="panel">
        <div class="panel-title">Mark Options</div>
        <div class="form-row">
          <div class="form-group">
            <label>Feed</label>
            <select id="mark-slug">
              <option value="">— Choose a feed —</option>
              <?php foreach ($feeds as $slug => $feed): ?>
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($slug) ?> — <?= htmlspecialchars($feed['meta']['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="max-width:120px">
            <label>Episode # (blank = all)</label>
            <input type="number" id="mark-episode" placeholder="e.g. 3" min="1">
          </div>
        </div>
        <div class="flex-gap mt8">
          <button class="btn btn-primary" onclick="doMark(false)">✔ Mark Played</button>
          <button class="btn btn-ghost"   onclick="doMark(true)">✕ Mark Unplayed</button>
        </div>
        <div id="mark-result" class="mt8"></div>
      </div>
    </div>

    <!-- ── Remove ── -->
    <div id="tab-remove" class="tab-content">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher remove</div>
        <div class="page-subtitle">Unsubscribe from a feed</div>
      </div>
      <div class="panel" style="border-color:rgba(224,92,92,0.3)">
        <div class="panel-title text-red">Remove Feed</div>
        <div class="form-row">
          <div class="form-group">
            <label>Feed to Remove</label>
            <select id="remove-slug">
              <option value="">— Choose a feed —</option>
              <?php foreach ($feeds as $slug => $feed): ?>
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($slug) ?> — <?= htmlspecialchars($feed['meta']['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-danger" onclick="confirmRemove()">✕ Remove</button>
        </div>
        <div id="remove-result" class="mt8"></div>
        <div class="mt16" style="font-size:11px;color:var(--muted);">
          Note: downloaded audio files in <code>~/.podcatcher/episodes/</code> are <strong>not</strong> deleted.
        </div>
      </div>
    </div>

    <!-- ── Search ── -->
    <div id="tab-search" class="tab-content">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher search</div>
        <div class="page-subtitle">Search across all episode titles & descriptions</div>
      </div>
      <div class="panel">
        <div class="form-row">
          <div class="form-group" style="flex:3">
            <label>Search Query</label>
            <input type="text" id="search-query" placeholder="e.g. javascript, linux, security…" onkeydown="if(event.key==='Enter')doSearch()">
          </div>
          <div class="form-group" style="max-width:110px">
            <label>Limit per feed</label>
            <input type="number" id="search-limit" value="10" min="1" max="100">
          </div>
          <button class="btn btn-primary" onclick="doSearch()">⌕ Search</button>
        </div>
      </div>
      <div id="search-results"></div>
    </div>

  </main>
</div>

<!-- ── Confirm Dialog ── -->
<div class="overlay" id="confirm-overlay">
  <div class="dialog">
    <div class="dialog-title" id="confirm-title">Confirm Action</div>
    <div class="dialog-msg" id="confirm-msg"></div>
    <div class="dialog-btns">
      <button class="btn btn-ghost" onclick="closeConfirm()">Cancel</button>
      <button class="btn btn-danger" id="confirm-ok">Confirm</button>
    </div>
  </div>
</div>

<!-- ── Toast container ── -->
<div id="toast-container"></div>

<script>
// ── Tab navigation ──────────────────────────────────────────────────────────
const tabs = document.querySelectorAll('.nav-link');
tabs.forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    tabs.forEach(x => x.classList.remove('active'));
    a.classList.add('active');
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.getElementById(a.dataset.tab).classList.add('active');
    if (a.dataset.tab === 'tab-list') refreshPage();
  });
});

// ── Toast ───────────────────────────────────────────────────────────────────
function toast(msg, type='success') {
  const c = document.getElementById('toast-container');
  const t = document.createElement('div');
  const icon = {success:'✔', error:'✗', info:'ℹ'}[type] || '•';
  t.className = `toast ${type}`;
  t.innerHTML = `<span>${icon}</span><span>${msg}</span>`;
  c.appendChild(t);
  setTimeout(() => {
    t.classList.add('fade-out');
    setTimeout(() => t.remove(), 400);
  }, 4000);
}

// ── Confirm dialog ──────────────────────────────────────────────────────────
let confirmCallback = null;
function showConfirm(title, msg, cb) {
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-msg').textContent = msg;
  confirmCallback = cb;
  document.getElementById('confirm-overlay').classList.add('open');
}
function closeConfirm() {
  document.getElementById('confirm-overlay').classList.remove('open');
  confirmCallback = null;
}
document.getElementById('confirm-ok').addEventListener('click', () => {
  closeConfirm();
  if (confirmCallback) confirmCallback();
});
document.getElementById('confirm-overlay').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeConfirm();
});

// ── API helper ──────────────────────────────────────────────────────────────
async function api(action, params={}) {
  const body = new FormData();
  body.append('action', action);
  for (const [k, v] of Object.entries(params)) body.append(k, v);
  const r = await fetch('', {
    method: 'POST',
    headers: {'X-Requested-With': 'XMLHttpRequest'},
    body
  });
  return r.json();
}

function setResult(id, data) {
  const el = document.getElementById(id);
  if (!el) return;
  if (data.success) {
    el.innerHTML = `<span class="text-green">✔ </span>${data.success}`;
    toast(data.success.replace(/<[^>]+>/g,''), 'success');
  } else if (data.error) {
    el.innerHTML = `<span class="text-red">✗ </span>${data.error}`;
    toast(data.error, 'error');
  } else if (data.info) {
    el.innerHTML = `<span class="text-amber">ℹ </span>${data.info}`;
    toast(data.info.replace(/<[^>]+>/g,''), 'info');
  }
  el.style.display = 'block';
}

// ── Add ─────────────────────────────────────────────────────────────────────
async function doAdd() {
  const url  = document.getElementById('add-url').value.trim();
  const name = document.getElementById('add-name').value.trim();
  if (!url) { toast('Please enter a feed URL', 'error'); return; }
  const btn = document.getElementById('btn-add');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Fetching…';
  const data = await api('add', {url, name});
  btn.disabled = false;
  btn.innerHTML = 'Add Feed';
  setResult('add-result', data);
  if (data.success) {
    document.getElementById('add-url').value = '';
    document.getElementById('add-name').value = '';
    refreshSelects();
  }
}

// ── Update ──────────────────────────────────────────────────────────────────
async function doUpdate() {
  const slug = document.getElementById('update-slug').value;
  const btn  = document.getElementById('btn-update');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Updating…';
  const data = await api('update', {slug});
  btn.disabled = false;
  btn.innerHTML = '↻ Update';
  setResult('update-result', data);
}

// ── Remove ──────────────────────────────────────────────────────────────────
function confirmRemove() {
  const slug = document.getElementById('remove-slug').value;
  if (!slug) { toast('Select a feed to remove', 'error'); return; }
  showConfirm('Remove Feed', `Remove "${slug}"? This cannot be undone.`, async () => {
    const data = await api('remove', {slug});
    setResult('remove-result', data);
    if (data.success) refreshSelects();
  });
}

// ── Mark Played ─────────────────────────────────────────────────────────────
async function doMark(unplayed) {
  const slug    = document.getElementById('mark-slug').value;
  const episode = document.getElementById('mark-episode').value;
  if (!slug) { toast('Select a feed first', 'error'); return; }
  const label = unplayed ? 'unplayed' : 'played';
  const runMark = async () => {
    const data = await api('mark_played', {slug, episode, unplayed: unplayed ? '1' : '0'});
    setResult('mark-result', data);
    if (data.success && document.getElementById('status-slug').value === slug) loadStatus();
  };
  if (!episode) {
    showConfirm('Mark All Episodes', `Mark all episodes of "${slug}" as ${label}?`, runMark);
  } else {
    await runMark();
  }
}

// ── Download ─────────────────────────────────────────────────────────────────
// ── Download with SSE progress ───────────────────────────────────────────────
async function populateDlEpisodes() {
  const slug = document.getElementById('dl-slug').value;
  const sel  = document.getElementById('dl-episode-select');
  sel.innerHTML = '<option value="">— All undownloaded —</option>';
  if (!slug) return;
  const data  = await api('get_feeds');
  const feed  = (data.feeds || {})[slug];
  if (!feed) return;
  feed.episodes.forEach((ep, i) => {
    const opt = document.createElement('option');
    opt.value = i + 1;
    const dl  = ep.local_path ? ' ⬇' : '';
    opt.textContent = `${i+1}. ${ep.title.substring(0,55)}${dl}`;
    sel.appendChild(opt);
  });
}

function sseDownloadOne(slug, episodeNum, titleText, feedTitle) {
  const list = document.getElementById('dl-progress-list');
  const id   = `dl-item-${slug}-${episodeNum}`;

  // Remove existing item for this episode if re-downloading
  document.getElementById(id)?.remove();

  const item = document.createElement('div');
  item.className = 'dl-item';
  item.id = id;
  item.innerHTML = `
    <div class="dl-item-title">${escHtml(titleText)}</div>
    <div class="dl-item-bar-wrap"><div class="dl-item-bar indeterminate" id="${id}-bar"></div></div>
    <div class="dl-item-status" id="${id}-status">Connecting…</div>`;
  list.prepend(item);

  const url = `?action=sse_download&slug=${encodeURIComponent(slug)}&episode=${episodeNum}`;
  const es  = new EventSource(url);

  es.onmessage = (e) => {
    const d   = JSON.parse(e.data);
    const bar = document.getElementById(`${id}-bar`);
    const st  = document.getElementById(`${id}-status`);

    if (d.error) {
      es.close();
      item.classList.add('error');
      bar.classList.remove('indeterminate');
      bar.style.width = '100%';
      st.textContent  = '✗ ' + d.error;
      toast(`Download failed: ${d.error}`, 'error');
      return;
    }

    if (d.pct >= 0) {
      bar.classList.remove('indeterminate');
      bar.style.width = d.pct + '%';
    }

    if (d.mb_total) {
      st.textContent = `${d.mb_done} / ${d.mb_total} MB${d.pct >= 0 ? '  ' + d.pct + '%' : ''}`;
    } else {
      st.textContent = `${d.mb_done} MB downloaded…`;
    }

    if (d.done) {
      es.close();
      item.classList.add('done');
      const already = d.already ? ' (already had it)' : '';
      st.innerHTML = `✔ Saved — <span class="text-muted">${escHtml(d.path)}</span>${already}
        <button class="btn btn-ghost btn-sm" style="margin-left:8px"
          onclick="playEpisode('${escHtml(slug)}',${episodeNum},'${escHtml(titleText)}','${escHtml(feedTitle)}')">▶ Play</button>`;
      toast(`Downloaded: ${titleText.substring(0,50)}`, 'success');
      _statusCache = null; // refresh status view if open
      if (document.getElementById('status-slug').value === slug) loadStatus(true);
    }
  };

  es.onerror = () => {
    es.close();
    const st = document.getElementById(`${id}-status`);
    if (st && !item.classList.contains('done') && !item.classList.contains('error')) {
      item.classList.add('error');
      st.textContent = '✗ Connection lost';
    }
  };
}

async function doDownload(all) {
  const slug    = document.getElementById('dl-slug').value;
  const epVal   = document.getElementById('dl-episode-select').value;
  const data    = await api('get_feeds');
  const feeds   = data.feeds || {};

  let queue = []; // [{slug, episode (1-based), title, feedTitle}]

  if (epVal) {
    // Single specific episode
    if (!slug) { toast('Select a feed first', 'error'); return; }
    const feed = feeds[slug];
    if (!feed) return;
    const ep = feed.episodes[parseInt(epVal) - 1];
    if (ep) queue.push({slug, episode: parseInt(epVal), title: ep.title, feedTitle: feed.meta.title});
  } else {
    // All undownloaded (or all if "all" flag)
    const targets = slug ? {[slug]: feeds[slug]} : feeds;
    for (const [s, feed] of Object.entries(targets)) {
      if (!feed) continue;
      feed.episodes.forEach((ep, i) => {
        const hasFile = ep.local_path && ep.local_path !== '';
        if (all || !hasFile) {
          queue.push({slug: s, episode: i+1, title: ep.title, feedTitle: feed.meta.title});
        }
      });
    }
  }

  if (!queue.length) { toast('Nothing to download', 'info'); return; }

  // Cap concurrent SSE connections — run sequentially
  toast(`Queuing ${queue.length} download(s)…`, 'info');
  for (const item of queue) {
    sseDownloadOne(item.slug, item.episode, item.title, item.feedTitle);
    await new Promise(r => setTimeout(r, 200)); // slight stagger
  }
}

// ── Search ───────────────────────────────────────────────────────────────────
async function doSearch() {
  const query = document.getElementById('search-query').value.trim();
  const limit = document.getElementById('search-limit').value;
  if (!query) { toast('Enter a search term', 'error'); return; }
  const data = await api('search', {query, limit});
  const container = document.getElementById('search-results');
  if (data.error) { toast(data.error, 'error'); return; }
  const results = data.results || {};
  if (Object.keys(results).length === 0) {
    container.innerHTML = `<div class="empty">No episodes matched "<strong>${escHtml(data.query)}</strong>".</div>`;
    return;
  }
  let html = '';
  for (const [slug, group] of Object.entries(results)) {
    html += `<div class="search-group">
      <div class="search-group-title">[${escHtml(slug)}] ${escHtml(group.title)}</div>`;
    for (const ep of group.episodes) {
      const d = ep.pub_date ? ep.pub_date.substring(0,16) : '';
      html += `<div class="search-ep">
        <div class="search-ep-title">${escHtml(ep.title)}</div>
        <div class="search-ep-date">${escHtml(d)}</div>
      </div>`;
    }
    html += `</div>`;
  }
  container.innerHTML = html;
}

// ── Status ───────────────────────────────────────────────────────────────────────────────
let _statusCache    = null;
let _statusSlug     = null;
let _statusDebounce = null;

async function loadStatus(forceRefresh = false) {
  const slug  = document.getElementById('status-slug').value;
  const count = parseInt(document.getElementById('status-count').value) || 10;
  const panel = document.getElementById('status-panel');
  if (!slug) { panel.innerHTML = ''; _statusCache = null; _statusSlug = null; return; }

  // Count changed but same feed: re-render from cache without a network call
  if (!forceRefresh && _statusCache && _statusSlug === slug) {
    renderStatus(slug, _statusCache, count, panel);
    return;
  }

  clearTimeout(_statusDebounce);
  _statusDebounce = setTimeout(async () => {
    const data  = await api('get_feeds');
    const feeds = data.feeds || {};
    _statusCache = feeds[slug] || null;
    _statusSlug  = slug;
    if (!_statusCache) { panel.innerHTML = '<div class="empty">Feed not found.</div>'; return; }
    renderStatus(slug, _statusCache, count, panel);
  }, 120);
}

function renderStatus(slug, feed, count, panel) {
  const meta     = feed.meta;
  const episodes = feed.episodes || [];
  const played   = episodes.filter(e => e.played).length;
  const shown    = episodes.slice(0, count);

  let html = `
    <div class="panel">
      <div class="panel-title">${escHtml(meta.title)}</div>
      <div class="meta-grid">
        <span class="meta-key">Slug</span>      <span class="meta-val text-amber">${escHtml(slug)}</span>
        <span class="meta-key">URL</span>       <span class="meta-val"><a href="${escHtml(feed.url)}" target="_blank" style="color:var(--blue)">${escHtml(feed.url)}</a></span>
        <span class="meta-key">Added</span>     <span class="meta-val">${escHtml((feed.added||'').substring(0,19))}</span>
        <span class="meta-key">Updated</span>   <span class="meta-val">${escHtml((feed.last_updated||'').substring(0,19))}</span>
        <span class="meta-key">Episodes</span>  <span class="meta-val">${episodes.length}</span>
        <span class="meta-key">Played</span>    <span class="meta-val">${played} / ${episodes.length}</span>
      </div>`;

  if (meta.description) {
    html += `<div style="font-size:12px;color:var(--text2);font-family:var(--sans);margin-bottom:14px;">${escHtml(meta.description)}</div>`;
  }

  html += `<div class="panel-title" style="margin-top:4px">Latest ${shown.length} Episode(s)</div>`;

  if (!shown.length) {
    html += `<div class="empty">No episodes.</div>`;
  } else {
    shown.forEach((ep, i) => {
      const played_m = ep.played ? '<span class="ep-marker played" title="Played">✔</span>' : '<span class="ep-marker empty">·</span>';
      const dl_m     = ep.local_path ? '<span class="ep-marker dl" title="Downloaded">⬇</span>' : '<span class="ep-marker empty">·</span>';
      const pub      = ep.pub_date ? ep.pub_date.substring(0,16) : '';
      const dur      = ep.duration ? ` · ${escHtml(ep.duration)}` : '';
      const mb       = ep.file_size > 0 ? ` · ${(ep.file_size/1048576).toFixed(1)} MB` : '';
      html += `<div class="episode-row">
        <div class="ep-num">${i+1}.</div>
        <div class="ep-markers">${played_m}${dl_m}</div>
        <div class="ep-body">
          <div class="ep-title">${escHtml(ep.title)}</div>
          <div class="ep-meta">${escHtml(pub)}${dur}${mb}</div>
        </div>
        <div class="ep-actions">
          ${ep.local_path
            ? `<button class="btn btn-ghost btn-sm" title="Play" onclick="playEpisode('${escHtml(slug)}',${i+1},'${escHtml(ep.title.replace(/'/g,"\\'"))}','${escHtml(meta.title.replace(/'/g,"\\'"))}')">▶ Play</button>`
            : `<button class="btn btn-ghost btn-sm" title="Download" onclick="startDownloadFromStatus('${escHtml(slug)}',${i+1},'${escHtml(ep.title.replace(/'/g,"\\'"))}','${escHtml(meta.title.replace(/'/g,"\\'"))}')">⬇</button>`
          }
          ${ep.played
            ? `<button class="btn btn-ghost btn-sm" onclick="quickMark('${escHtml(slug)}',${i+1},true)">unplayed</button>`
            : `<button class="btn btn-ghost btn-sm" onclick="quickMark('${escHtml(slug)}',${i+1},false)">mark played</button>`
          }
        </div>
      </div>`;
    });
  }
  html += `</div>`;
  panel.innerHTML = html;
}

async function openStatus(slug) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
  document.getElementById('tab-status').classList.add('active');
  document.querySelector('[data-tab="tab-status"]').classList.add('active');
  document.getElementById('status-slug').value = slug;
  _statusCache = null;
  await loadStatus();
}

async function quickMark(slug, episode, unplayed) {
  await api('mark_played', {slug, episode, unplayed: unplayed ? '1' : '0'});
  toast(`Episode ${episode} marked ${unplayed ? 'unplayed' : 'played'}`, 'success');
  _statusCache = null;
  loadStatus(true);
}

// ── Refresh page data ────────────────────────────────────────────────────────
async function refreshSelects() {
  const data  = await api('get_feeds');
  const feeds = data.feeds || {};
  const slugs = Object.keys(feeds);
  const makeOpts = (includBlank, blankLabel) => {
    let h = includBlank ? `<option value="">${blankLabel}</option>` : '';
    for (const [s, f] of Object.entries(feeds))
      h += `<option value="${escHtml(s)}">${escHtml(s)} — ${escHtml(f.meta.title)}</option>`;
    return h;
  };
  document.getElementById('update-slug').innerHTML    = makeOpts(true, '— All Feeds —');
  document.getElementById('dl-slug').innerHTML        = makeOpts(true, '— All Feeds —');
  document.getElementById('status-slug').innerHTML    = makeOpts(true, '— Choose a feed —');
  document.getElementById('mark-slug').innerHTML      = makeOpts(true, '— Choose a feed —');
  document.getElementById('remove-slug').innerHTML    = makeOpts(true, '— Choose a feed —');
  refreshList(feeds);
}

function refreshList(feeds) {
  const tbody  = document.querySelector('#tab-list tbody');
  const header = document.querySelector('#tab-list .page-subtitle');
  if (!tbody) return;
  if (header) header.textContent = Object.keys(feeds).length + ' feed(s) subscribed';
  if (!Object.keys(feeds).length) {
    tbody.closest('.panel').innerHTML = '<div class="empty" style="padding:20px">No feeds yet. Use <strong>Add Feed</strong> to subscribe to a podcast.</div>';
    return;
  }
  tbody.innerHTML = '';
  for (const [slug, feed] of Object.entries(feeds)) {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="slug">${escHtml(slug)}</td>
      <td class="num">${(feed.episodes||[]).length}</td>
      <td class="date">${escHtml((feed.last_updated||'').substring(0,19))}</td>
      <td class="title">${escHtml(feed.meta.title)}</td>
      <td class="actions"><button class="btn btn-ghost btn-sm" onclick="openStatus('${escHtml(slug)}')">status</button></td>`;
    tbody.appendChild(tr);
  }
}

function refreshPage() { refreshSelects(); }


// ── Audio Player ─────────────────────────────────────────────────────────────
function playEpisode(slug, episodeNum, title, feedTitle) {
  const bar   = document.getElementById('player-bar');
  const audio = document.getElementById('player-audio');
  const et    = document.getElementById('player-ep-title');
  const ft    = document.getElementById('player-feed-title');

  const src = `?action=stream&slug=${encodeURIComponent(slug)}&episode=${episodeNum}`;
  audio.src = src;
  et.textContent = title;
  ft.textContent = feedTitle;
  bar.classList.add('open');
  audio.play().catch(() => {});

  // Add bottom padding to main so content isn't hidden behind bar
  document.querySelector('.main').style.paddingBottom = '72px';
}

function closePlayer() {
  const audio = document.getElementById('player-audio');
  audio.pause();
  audio.src = '';
  document.getElementById('player-bar').classList.remove('open');
  document.querySelector('.main').style.paddingBottom = '';
}

// Start a download from the status view and switch to download tab
function startDownloadFromStatus(slug, episodeNum, title, feedTitle) {
  // Switch to download tab
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
  document.getElementById('tab-download').classList.add('active');
  document.querySelector('[data-tab="tab-download"]').classList.add('active');
  // Kick off the SSE download
  sseDownloadOne(slug, episodeNum, title, feedTitle);
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<!-- ── Audio Player Bar ── -->
<div id="player-bar">
  <div id="player-title">
    <strong id="player-ep-title">—</strong>
    <span id="player-feed-title"></span>
  </div>
  <audio id="player-audio" controls preload="metadata"></audio>
  <button id="player-close" onclick="closePlayer()" title="Close player">✕</button>
</div>

</body>
</html>
