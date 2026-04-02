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

// ─── CLI Logic ────────────────────────────────────────────────────────────────

if (PHP_SAPI === 'cli') {
    ensure_dirs();
    $cmd = $argv[1] ?? 'help';

    if ($cmd === 'update') {
        echo "Updating feeds...\n";
        $res = action_update();
        echo strip_tags(str_replace('<br>', "\n", $res['success'])) . "\n";

        $download = in_array('--download', $argv);
        if ($download && !empty($res['new_episodes'])) {
            echo "\nDownloading " . count($res['new_episodes']) . " new episodes...\n";
            foreach ($res['new_episodes'] as $ep) {
                echo "-> {$ep['title']} [{$ep['slug']}]... ";
                $dl = download_episode($ep['slug'], $ep['ep_num']);
                if (isset($dl['error'])) echo "ERROR: {$dl['error']}\n";
                else echo "DONE\n";
            }
        }
    } else {
        echo "Usage: php index.php update [--download]\n";
    }
    exit;
}

// ─── Web Session & Security ────────────────────────────────────────────────────

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ─── Routing ──────────────────────────────────────────────────────────────────

ensure_dirs();

$get_action = $_GET['action'] ?? null;
if ($get_action === 'stream')       { action_stream(); }
if ($get_action === 'sse_download') { action_sse_download(); }

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$action  = $_POST['action'] ?? null;

if ($is_ajax && $action) {
    // CSRF Validation
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        header('Content-Type: application/json', true, 403);
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }

    header('Content-Type: application/json');
    $response = match($action) {
        'add'           => action_add(),
        'update'        => action_update(),
        'remove'        => action_remove(),
        'mark_played'   => action_mark_played(),
        'save_progress' => action_save_progress(),
        'search'        => action_search(),
        'discover'      => action_discover(),
        'get_feeds'     => ['feeds' => load_feeds()],
        default         => ['error' => 'Unknown action'],
    };
    echo json_encode($response);
    exit;
}

$feeds = load_feeds();
require_once __DIR__ . '/views/main.html.php';
