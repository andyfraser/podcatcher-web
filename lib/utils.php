<?php
// lib/utils.php - General utility functions for Podcatcher Web

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

function delete_dir(string $path): bool {
    if (!is_dir($path)) return false;
    $files = array_diff(scandir($path), ['.', '..']);
    foreach ($files as $file) {
        (is_dir("$path/$file")) ? delete_dir("$path/$file") : unlink("$path/$file");
    }
    return rmdir($path);
}

function find_episode_idx(array $episodes, int $episodeNum, ?string $guid = null): int {
    if ($guid) {
        foreach ($episodes as $idx => $ep) {
            if (($ep['guid'] ?? '') === $guid) return $idx;
        }
    }
    $idx = $episodeNum - 1;
    return (isset($episodes[$idx])) ? $idx : -1;
}
