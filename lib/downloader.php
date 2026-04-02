<?php
// lib/downloader.php - Audio streaming and SSE download logic for Podcatcher Web

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

function sse_event(array $data): void {
    echo 'data: ' . json_encode($data) . "\n\n";
    flush();
}

/**
 * Core download logic reusable for SSE and CLI.
 */
function download_episode(string $slug, int $episodeNum, ?callable $progress_cb = null): array {
    $feeds = load_feeds();
    if (!isset($feeds[$slug])) return ['error' => "Feed '{$slug}' not found"];

    $episodes = $feeds[$slug]['episodes'] ?? [];
    $idx      = $episodeNum - 1;
    if (!isset($episodes[$idx])) return ['error' => 'Episode not found'];

    $ep  = $episodes[$idx];
    $url = $ep['audio_url'];

    $dest_dir = episode_dir($slug);
    $filename = safe_filename($ep['title'], $url);
    $dest     = $dest_dir . '/' . $filename;

    if (file_exists($dest)) {
        $feeds[$slug]['episodes'][$idx]['local_path'] = $dest;
        save_feeds($feeds);
        return [
            'pct'      => 100,
            'mb_done'  => round(filesize($dest) / 1048576, 1),
            'mb_total' => round(filesize($dest) / 1048576, 1),
            'done'     => true,
            'path'     => $dest,
            'already'  => true,
        ];
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
    if (!$remote) return ['error' => 'Could not open remote URL'];

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
        return ['error' => 'Could not write local file'];
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

        if ($progress_cb && ($pct !== $last_report || ($total === 0 && $downloaded % (256 * 1024) < $chunk_size))) {
            $last_report = $pct;
            $progress_cb([
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
        return ['error' => 'Download produced empty file'];
    }

    // Reload feeds to prevent race conditions during long downloads
    $feeds = load_feeds();
    $feeds[$slug]['episodes'][$idx]['local_path'] = $dest;
    save_feeds($feeds);

    return [
        'pct'      => 100,
        'mb_done'  => round($downloaded / 1048576, 1),
        'mb_total' => round($downloaded / 1048576, 1),
        'done'     => true,
        'path'     => $dest,
    ];
}

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

    $result = download_episode($slug, $episode, function($data) {
        sse_event($data);
    });

    sse_event($result);
    exit;
}
