<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Podcatcher</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,500;0,600;1,400&family=IBM+Plex+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/../assets/style.css') ?>">
<script>
  (function() {
    var theme = localStorage.getItem('podcatcher-theme') || 'auto';
    document.documentElement.dataset.theme = theme;
  })();
</script>
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
      <a href="#" class="nav-link" data-tab="tab-discover">
        <span class="icon">★</span> Discover
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
      <a href="#" class="nav-link" data-tab="tab-settings">
        <span class="icon">⚙</span> Settings
      </a>
    </nav>
    <div class="sidebar-footer">
      <div class="sidebar-footer-text">
        Data: <code>~/.podcatcher/</code>
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
                <th>Title</th>
                <th class="num">Episodes</th>
                <th>Last Updated</th>
                <th>Slug</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php
                $sorted_feeds = $feeds;
                uasort($sorted_feeds, fn($a, $b) => strcasecmp($a['meta']['title'] ?? '', $b['meta']['title'] ?? ''));
              ?>
              <?php foreach ($sorted_feeds as $slug => $feed): ?>
              <tr>
                <td class="title"><?= htmlspecialchars($feed['meta']['title']) ?></td>
                <td class="num"><?= count($feed['episodes'] ?? []) ?></td>
                <td class="date"><?= ($lu = $feed['last_updated'] ?? '') ? date('D, j M Y', strtotime($lu)) : '' ?></td>
                <td class="slug"><?= htmlspecialchars($slug) ?></td>
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
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($feed['meta']['title']) ?> (<?= htmlspecialchars($slug) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-primary" id="btn-update" onclick="doUpdate()">↻ Update</button>
        </div>
        <label class="checkbox-row mt8">
          <input type="checkbox" id="update-auto-dl">
          Download new episodes automatically
        </label>
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
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($feed['meta']['title']) ?> (<?= htmlspecialchars($slug) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="max-width:100px">
            <label>Show Episodes</label>
            <select id="status-count" onchange="loadStatus()">
              <option value="5">5</option>
              <option value="10" selected>10</option>
              <option value="20">20</option>
              <option value="50">50</option>
              <option value="0">All</option>
            </select>
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
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($feed['meta']['title']) ?> (<?= htmlspecialchars($slug) ?>)</option>
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
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($feed['meta']['title']) ?> (<?= htmlspecialchars($slug) ?>)</option>
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
              <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($feed['meta']['title']) ?> (<?= htmlspecialchars($slug) ?>)</option>
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
        <div class="page-subtitle">Search across all episode titles &amp; descriptions</div>
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

    <!-- ── Discover ── -->
    <div id="tab-discover" class="tab-content">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher discover</div>
        <div class="page-subtitle">Find new podcasts via iTunes</div>
      </div>
      <div class="panel">
        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label>Search Podcasts</label>
            <input type="text" id="discover-query" placeholder="Search by name, author, or topic..." onkeydown="if(event.key==='Enter')doDiscover()">
          </div>
          <button class="btn btn-primary" onclick="doDiscover()">★ Search</button>
        </div>
      </div>
      <div id="discover-results" class="discover-grid"></div>
    </div>

    <div id="tab-settings" class="tab-content">
      <div class="page-header">
        <div class="page-title"><span>$</span> podcatcher settings</div>
        <div class="page-subtitle">Configure application preferences</div>
      </div>
      <div class="panel">
        <div class="panel-title">Appearance</div>
        <div class="form-row">
          <div class="form-group" style="max-width:300px">
            <label>Color Theme</label>
            <select id="setting-theme" onchange="changeTheme(this.value)">
              <option value="auto">Automatic (System Preference)</option>
              <option value="light">Light Mode</option>
              <option value="dark">Dark Mode</option>
            </select>
          </div>
        </div>
      </div>
    </div>

  </main>
</div>

<!-- ── Episode Detail Overlay ── -->
<div class="overlay" id="detail-overlay">
  <div class="dialog detail-dialog">
    <div class="detail-header">
      <div style="min-width:0">
        <div class="detail-feed-name" id="detail-feed-name"></div>
        <div class="detail-ep-title" id="detail-ep-title"></div>
      </div>
      <button class="btn btn-ghost btn-sm" onclick="closeDetail()" style="flex-shrink:0">✕</button>
    </div>
    <div class="detail-meta" id="detail-meta"></div>
    <div class="detail-desc" id="detail-desc"></div>
    <div class="detail-actions" id="detail-actions"></div>
  </div>
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

<!-- ── Audio Player Bar ── -->
<div id="player-bar">
  <div id="player-title">
    <strong id="player-ep-title">—</strong>
    <span id="player-feed-title"></span>
  </div>
  <div class="player-controls">
    <div class="player-btns">
      <button class="btn-player" onclick="skipPlayer(-30)" title="Back 30s">⟲ 30</button>
      <button class="btn-player" onclick="skipPlayer(-10)" title="Back 10s">⟲ 10</button>
      <button class="btn-player" onclick="skipPlayer(10)" title="Forward 10s">10 ⟳</button>
      <button class="btn-player" onclick="skipPlayer(30)" title="Forward 30s">30 ⟳</button>
      <select id="player-speed" onchange="setPlayerSpeed(this.value)" class="player-speed-select" title="Playback Speed">
        <option value="0.75">0.75x</option>
        <option value="1" selected>1.0x</option>
        <option value="1.25">1.25x</option>
        <option value="1.5">1.5x</option>
        <option value="2">2.0x</option>
      </select>
    </div>
    <audio id="player-audio" controls preload="metadata"></audio>
  </div>
  <button id="player-close" onclick="closePlayer()" title="Close player">✕</button>
</div>

<script>
  window.PODCATCHER_CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
</script>
<script src="assets/app.js?v=<?= filemtime(__DIR__.'/../assets/app.js') ?>"></script>
</body>
</html>
