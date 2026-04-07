<?php
// lib/actions.php - AJAX action handlers for Podcatcher Web

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

    $dest_dir = episode_dir($slug);
    foreach ($episodes as &$ep) {
        $filename = safe_filename($ep['title'], $ep['audio_url']);
        $dest     = $dest_dir . '/' . $filename;
        if (file_exists($dest) && filesize($dest) >= 1024) {
            $ep['local_path'] = $dest;
        } else {
            $ep['local_path'] = '';
        }
    }
    unset($ep);

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

function action_update(?callable $progress_cb = null): array {
    $slug  = trim($_REQUEST['slug'] ?? '');
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
        $feed_title = $feed['meta']['title'] ?? $s;
        if ($progress_cb) $progress_cb(['status' => "Checking {$feed_title} [{$s}]..."]);

        $result = fetch_url($feed['url']);
        if (!$result['ok']) { 
            $msg = "[{$s}] ✗ Fetch failed";
            $log[] = $msg;
            if ($progress_cb) $progress_cb(['status' => $msg]);
            continue; 
        }

        $parsed = parse_feed($result['body']);
        if (!$parsed) { 
            $msg = "[{$s}] ✗ Parse failed";
            $log[] = $msg;
            if ($progress_cb) $progress_cb(['status' => $msg]);
            continue; 
        }

        $new_eps     = $parsed['episodes'];
        $new_guids   = array_flip(array_column($new_eps, 'guid'));
        $old_eps     = $feed['episodes'] ?? [];

        // Cleanup episodes no longer in the feed
        foreach ($old_eps as $old_ep) {
            $guid = $old_ep['guid'] ?? '';
            if ($guid && !isset($new_guids[$guid])) {
                $path = $old_ep['local_path'] ?? '';
                if ($path && file_exists($path)) {
                    @unlink($path);
                }
            }
        }

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

        $dest_dir = episode_dir($s);
        foreach ($new_eps as &$ep) {
            $ep['played']     = isset($played_guids[$ep['guid']]);
            $ep['local_path'] = $local_paths[$ep['guid']] ?? '';

            // If local_path is empty but file exists, set it
            if (!$ep['local_path']) {
                $filename = safe_filename($ep['title'], $ep['audio_url']);
                $dest     = $dest_dir . '/' . $filename;
                if (file_exists($dest) && filesize($dest) >= 1024) {
                    $ep['local_path'] = $dest;
                }
            }
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
                    'guid'       => $ep['guid'],
                    'title'      => $ep['title'],
                    'feed_title' => $feed_title,
                ];
            }
        }

        $n          = count($fresh);
        $total_new += $n;
        $msg        = "[{$s}] ✔ " . ($n > 0 ? "{$n} new episode(s)" : "No new episodes");
        $log[]      = $msg;
        if ($progress_cb) $progress_cb(['status' => $msg]);
    }

    save_feeds($feeds);
    $res = [
        'success'      => implode('<br>', $log) . "<br><em>{$total_new} new episode(s) total.</em>",
        'new_episodes' => $new_episodes,
    ];
    if ($progress_cb) $progress_cb(array_merge(['done' => true, 'total_new' => $total_new], $res));
    return $res;
}

function action_sse_update(): void {
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $res = action_update(function($data) {
        echo 'data: ' . json_encode($data) . "\n\n";
        flush();
    });
    
    // In case there was an error before callback could run
    if (isset($res['error'])) {
        echo 'data: ' . json_encode(['error' => $res['error']]) . "\n\n";
        flush();
    }
    exit;
}

function action_remove(): array {
    $slug  = trim($_POST['slug'] ?? '');
    $feeds = load_feeds();
    if (!isset($feeds[$slug])) return ['error' => "No feed with slug '{$slug}'."];
    $title = $feeds[$slug]['meta']['title'];
    
    // Cleanup downloaded episodes
    $dest_dir = EPISODES_DIR . '/' . $slug;
    if (is_dir($dest_dir)) {
        delete_dir($dest_dir);
    }

    unset($feeds[$slug]);
    save_feeds($feeds);
    return ['success' => "Removed <strong>{$title}</strong>."];
}

function action_mark_played(): array {
    $slug     = trim($_POST['slug'] ?? '');
    $episode  = (int)($_POST['episode'] ?? 0);
    $guid     = trim($_POST['guid'] ?? '');
    $unplayed = ($_POST['unplayed'] ?? '') === '1';

    $feeds = load_feeds();
    if (!isset($feeds[$slug])) return ['error' => "No feed with slug '{$slug}'."];

    $episodes = &$feeds[$slug]['episodes'];
    $state    = !$unplayed;
    $label    = $unplayed ? 'unplayed' : 'played';

    if ($episode > 0 || $guid) {
        $idx = find_episode_idx($episodes, $episode, $guid);
        if ($idx === -1) return ['error' => 'Episode not found.'];
        $episodes[$idx]['played'] = $state;
        save_feeds($feeds);
        return ['success' => "Marked episode as {$label}."];
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

function action_save_progress(): array {
    $slug     = trim($_POST['slug'] ?? '');
    $episode  = (int)($_POST['episode'] ?? 0);
    $guid     = trim($_POST['guid'] ?? '');
    $position = (float)($_POST['position'] ?? 0);
    $duration = (float)($_POST['duration'] ?? 0);

    if (!$slug || (!$episode && !$guid)) return ['error' => 'Missing slug or episode'];

    $feeds = load_feeds();
    if (!isset($feeds[$slug])) return ['error' => 'Feed not found'];

    $idx = find_episode_idx($feeds[$slug]['episodes'] ?? [], $episode, $guid);
    if ($idx === -1) return ['error' => 'Episode not found'];

    $feeds[$slug]['episodes'][$idx]['progress'] = $position;
    if ($duration > 0) {
        $feeds[$slug]['episodes'][$idx]['duration_seconds'] = $duration;
    }
    $feeds[$slug]['episodes'][$idx]['last_listen'] = date('c');

    // Automatically mark as played if near the end (e.g., > 95% or < 30s remaining)
    $auto_played = false;
    if ($duration > 0) {
        $percent = ($position / $duration) * 100;
        $remaining = $duration - $position;
        if ($percent > 95 || $remaining < 30) {
            $feeds[$slug]['episodes'][$idx]['played'] = true;
            $auto_played = true;
        }
    }

    save_feeds($feeds);
    return ['success' => true, 'auto_played' => $auto_played];
}

function action_remove_download(): array {
    $slug    = trim($_POST['slug'] ?? '');
    $episode = (int)($_POST['episode'] ?? 0);
    $guid    = trim($_POST['guid'] ?? '');
    $feeds   = load_feeds();
    if (!isset($feeds[$slug])) return ['error' => 'Feed not found'];

    $idx = find_episode_idx($feeds[$slug]['episodes'] ?? [], $episode, $guid);
    if ($idx === -1) return ['error' => 'Episode not found'];

    $path = $feeds[$slug]['episodes'][$idx]['local_path'] ?? '';
    if ($path && file_exists($path)) {
        @unlink($path);
    }
    $feeds[$slug]['episodes'][$idx]['local_path'] = '';
    save_feeds($feeds);
    return ['success' => 'Download removed.'];
}
