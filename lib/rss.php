<?php
// lib/rss.php - RSS and URL fetching logic for Podcatcher Web

if (!function_exists('fetch_url')) {
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

    // Convert HH:MM:SS or MM:SS to seconds
    $duration_seconds = 0;
    if ($duration) {
        $parts = array_reverse(explode(':', $duration));
        if (count($parts) >= 1) $duration_seconds += (int)$parts[0]; // seconds
        if (count($parts) >= 2) $duration_seconds += (int)$parts[1] * 60; // minutes
        if (count($parts) >= 3) $duration_seconds += (int)$parts[2] * 3600; // hours
    }

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
        'audio_url'        => $audio_url,
        'file_size'        => $file_size,
        'mime_type'        => $mime_type,
        'duration'         => $duration,
        'duration_seconds' => $duration_seconds,
        'description'      => trim(strip_tags($description, '<p><br><a><strong><b><em><i><ul><ol><li><h1><h2><h3><h4><blockquote>')),
    ];
}
