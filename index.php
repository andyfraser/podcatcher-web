<?php
/**
 * Podcatcher Web — A PHP web interface for managing podcast subscriptions
 * Mirrors the Python CLI tool's full feature set.
 */

// ─── Config & Storage ──────────────────────────────────────────────────────────

define('DATA_DIR',     (getenv('HOME') ?: posix_getpwuid(posix_getuid())['dir']) . '/.podcatcher');
define('FEEDS_FILE',   DATA_DIR . '/feeds.json');
define('EPISODES_DIR', DATA_DIR . '/episodes');
define('USER_AGENT',   'Podcatcher/1.0 +https://github.com/podcatcher');

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
    $ext    = pathinfo($parsed['path'] ?? '', PATHINFO_EXTENSION);
    $ext    = $ext ? '.' . $ext : '.mp3';
    $name   = strtolower($title);
    $name   = preg_replace('/[^\w\s-]/', '', $name);
    $name   = preg_replace('/[\s_-]+/', '-', $name);
    $name   = trim($name, '-');
    return substr($name, 0, 80) . $ext;
}

// ─── HTTP / RSS ────────────────────────────────────────────────────────────────

function fetch_url(string $url, int $timeout = 15): array {
    $ctx  = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => 'User-Agent: ' . USER_AGENT . "\r\n",
            'timeout'         => $timeout,
            'follow_location' => 1,
            'max_redirects'   => 10,
        ],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
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
            $attrs     = $itunes->image->attributes();
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
        'description' => $description,
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
        'description' => trim(strip_tags($description, '<p><br><a><strong><b><em><i><ul><ol><li><h1><h2><h3><h4><blockquote>')),
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
    $slug  = trim($_POST['slug'] ?? '');
    $feeds = load_feeds();
    if (!$feeds) return ['error' => 'No feeds to update.'];

    if ($slug) {
        if (!isset($feeds[$slug])) return ['error' => "No feed with slug '{$slug}'."];
        $targets = [$slug => $feeds[$slug]];
    } else {
        $targets = $feeds;
    }

    $total_new    = 0;
    $log          = [];
    $new_episodes = [];

    foreach ($targets as $s => $feed) {
        $result = fetch_url($feed['url']);
        if (!$result['ok']) { $log[] = "[{$s}] ✗ Fetch failed"; continue; }

        $parsed = parse_feed($result['body']);
        if (!$parsed) { $log[] = "[{$s}] ✗ Parse failed"; continue; }

        $new_eps     = $parsed['episodes'];
        $known_guids = array_flip($feed['known_guids'] ?? []);
        $fresh       = array_filter($new_eps, fn($e) => !isset($known_guids[$e['guid']]));

        $played_guids = array_flip(array_column(
            array_filter($feed['episodes'] ?? [], fn($e) => $e['played'] ?? false),
            'guid'
        ));
        $local_paths = [];
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

        $feed_title = $feeds[$s]['meta']['title'];
        foreach ($new_eps as $i => $ep) {
            if (!isset($known_guids[$ep['guid']])) {
                $new_episodes[] = [
                    'slug'       => $s,
                    'ep_num'     => $i + 1,
                    'title'      => $ep['title'],
                    'feed_title' => $feed_title,
                ];
            }
        }

        $n          = count($fresh);
        $total_new += $n;
        $log[]      = "[{$s}] ✔ " . ($n > 0 ? "{$n} new episode(s)" : "No new episodes");
    }

    save_feeds($feeds);
    return [
        'success'      => implode('<br>', $log) . "<br><em>{$total_new} new episode(s) total.</em>",
        'new_episodes' => $new_episodes,
    ];
}

function action_remove(): array {
    $slug  = trim($_POST['slug'] ?? '');
    $feeds = load_feeds();
    if (!isset($feeds[$slug])) return ['error' => "No feed with slug '{$slug}'."];
    $title = $feeds[$slug]['meta']['title'];
    unset($feeds[$slug]);
    save_feeds($feeds);
    return ['success' => "Removed <strong>{$title}</strong>."];
}

function action_mark_played(): array {
    $slug     = trim($_POST['slug'] ?? '');
    $episode  = (int)($_POST['episode'] ?? 0);
    $unplayed = ($_POST['unplayed'] ?? '') === '1';

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
        $matches = [];
        foreach (($feed['episodes'] ?? []) as $idx => $ep) {
            if (str_contains(strtolower($ep['title']), $query) ||
                str_contains(strtolower($ep['description'] ?? ''), $query)) {
                $ep['ep_num'] = $idx + 1;
                $matches[] = $ep;
                if (count($matches) >= $limit) break;
            }
        }
        if ($matches) {
            $output[$slug] = [
                'title'    => $feed['meta']['title'],
                'episodes' => $matches,
            ];
        }
    }

    return ['results' => $output, 'query' => $_POST['query'] ?? ''];
}

function action_discover(): array {
    $query = trim($_POST['query'] ?? '');
    if (!$query) return ['error' => 'Query is required.'];

    $url = "https://itunes.apple.com/search?term=" . urlencode($query) . "&entity=podcast&limit=24";
    $res = fetch_url($url);
    if (!$res['ok']) return ['error' => 'Discovery failed: ' . $res['error']];

    $data = json_decode($res['body'], true);
    $results = [];
    foreach ($data['results'] ?? [] as $item) {
        // Use 600x600 artwork if available, otherwise fall back to 100x100
        $image = $item['artworkUrl600'] ?? $item['artworkUrl100'] ?? '';
        
        $results[] = [
            'title'  => $item['collectionName'] ?? 'Unknown',
            'author' => $item['artistName'] ?? '',
            'url'    => $item['feedUrl'] ?? '',
            'image'  => $image,
            'genres' => $item['genres'] ?? [],
        ];
    }
    return ['results' => $results];
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

    $size   = filesize($path);
    $mime   = $ep['mime_type'] ?: mime_content_type($path) ?: 'audio/mpeg';
    $start  = 0;
    $end    = $size - 1;
    $status = 200;

    if (isset($_SERVER['HTTP_RANGE'])) {
        preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m);
        $start  = (int)$m[1];
        $end    = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $size - 1;
        $status = 206;
    }

    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: '   . $mime);
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');
    if ($status === 206) {
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    header('Cache-Control: no-store');

    $fh        = fopen($path, 'rb');
    fseek($fh, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fh)) {
        $chunk      = min(65536, $remaining);
        echo fread($fh, $chunk);
        $remaining -= $chunk;
        flush();
    }
    fclose($fh);
    exit;
}

// ─── SSE helper ───────────────────────────────────────────────────────────────

function sse_event(array $data): void {
    echo 'data: ' . json_encode($data) . "\n\n";
    flush();
}

// ─── SSE: download one episode and stream progress ─────────────────────────────

function action_sse_download(): void {
    $slug    = preg_replace('/[^a-z0-9_-]/i', '', $_GET['slug'] ?? '');
    $episode = (int)($_GET['episode'] ?? 0);
    if (!$slug || $episode < 1) {
        sse_event(['error' => 'Missing slug or episode']); exit;
    }

    while (ob_get_level()) ob_end_clean();
    set_time_limit(0);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $feeds = load_feeds();
    if (!isset($feeds[$slug])) { sse_event(['error' => "Feed '{$slug}' not found"]); exit; }

    $episodes = $feeds[$slug]['episodes'] ?? [];
    $idx      = $episode - 1;
    if (!isset($episodes[$idx])) { sse_event(['error' => 'Episode not found']); exit; }

    $ep  = $episodes[$idx];
    $url = $ep['audio_url'];

    $dest_dir = episode_dir($slug);
    $filename = safe_filename($ep['title'], $url);
    $dest     = $dest_dir . '/' . $filename;

    if (file_exists($dest)) {
        $feeds[$slug]['episodes'][$idx]['local_path'] = $dest;
        save_feeds($feeds);
        sse_event([
            'pct'      => 100,
            'mb_done'  => round(filesize($dest) / 1048576, 1),
            'mb_total' => round(filesize($dest) / 1048576, 1),
            'done'     => true,
            'path'     => $dest,
            'already'  => true,
        ]);
        exit;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => 'User-Agent: ' . USER_AGENT . "\r\n",
            'timeout'         => 60,
            'follow_location' => 1,
            'max_redirects'   => 10,
        ],
        'ssl'  => ['verify_peer' => true],
    ]);

    $remote = @fopen($url, 'rb', false, $ctx);
    if (!$remote) {
        sse_event(['error' => 'Could not open remote URL']); exit;
    }

    $meta_data = stream_get_meta_data($remote);
    $total     = 0;
    foreach (($meta_data['wrapper_data'] ?? []) as $h) {
        if (stripos($h, 'Content-Length:') === 0) {
            $total = (int)trim(substr($h, 15));
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

        if ($pct !== $last_report || ($total === 0 && $downloaded % (256 * 1024) < $chunk_size)) {
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

    $feeds = load_feeds();
    $feeds[$slug]['episodes'][$idx]['local_path'] = $dest;
    save_feeds($feeds);

    sse_event([
        'pct'      => 100,
        'mb_done'  => round($downloaded / 1048576, 1),
        'mb_total' => round($downloaded / 1048576, 1),
        'done'     => true,
        'path'     => $dest,
    ]);
    exit;
}

// ─── GET action routing (stream / SSE) ────────────────────────────────────────
$get_action = $_GET['action'] ?? null;
if ($get_action === 'stream')       { action_stream(); }
if ($get_action === 'sse_download') { action_sse_download(); }

// ─── Handle AJAX/POST ──────────────────────────────────────────────────────────

ensure_dirs();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$action  = $_POST['action'] ?? null;

if ($is_ajax && $action) {
    header('Content-Type: application/json');
    $response = match($action) {
        'add'         => action_add(),
        'update'      => action_update(),
        'remove'      => action_remove(),
        'mark_played' => action_mark_played(),
        'search'      => action_search(),
        'discover'    => action_discover(),
        'get_feeds'   => ['feeds' => load_feeds()],
        default       => ['error' => 'Unknown action'],
    };
    echo json_encode($response);
    exit;
}

$feeds = load_feeds();

require __DIR__ . '/views/main.html.php';

