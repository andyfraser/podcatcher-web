<?php
/**
 * Podcatcher Web — A PHP web interface for managing podcast subscriptions
 * Entry point and router.
 */

// ─── Config & Storage ──────────────────────────────────────────────────────────

define('DATA_DIR',     (getenv('HOME') ?: posix_getpwuid(posix_getuid())['dir']) . '/.podcatcher');
define('FEEDS_FILE',   DATA_DIR . '/feeds.json');
define('EPISODES_DIR', DATA_DIR . '/episodes');
define('USER_AGENT',   'Podcatcher/1.0 +https://github.com/podcatcher');

// ─── Include Libraries ────────────────────────────────────────────────────────

require_once __DIR__ . '/lib/utils.php';
require_once __DIR__ . '/lib/rss.php';
require_once __DIR__ . '/lib/actions.php';
require_once __DIR__ . '/lib/downloader.php';

// ─── Routing ──────────────────────────────────────────────────────────────────

// Ensure data directories exist on every request
ensure_dirs();

// Handle GET-based actions (streaming and SSE)
$get_action = $_GET['action'] ?? null;
if ($get_action === 'stream')       { action_stream(); }
if ($get_action === 'sse_download') { action_sse_download(); }

// Handle AJAX POST actions
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$action  = $_POST['action'] ?? null;

if ($is_ajax && $action) {
    header('Content-Type: application/json');
    $response = match($action) {
        'add'         => action_add(),
        'update'      => action_update(),
        'remove'      => action_remove(),
        'mark_played'   => action_mark_played(),
        'save_progress' => action_save_progress(),
        'search'        => action_search(),
        'discover'    => action_discover(),
        'get_feeds'   => ['feeds' => load_feeds()],
        default       => ['error' => 'Unknown action'],
    };
    echo json_encode($response);
    exit;
}

// Default: Render the main view
$feeds = load_feeds();
require_once __DIR__ . '/views/main.html.php';
