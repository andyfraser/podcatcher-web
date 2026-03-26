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
  <audio id="player-audio" controls preload="metadata"></audio>
  <button id="player-close" onclick="closePlayer()" title="Close player">✕</button>
</div>

<script src="assets/app.js?v=<?= filemtime(__DIR__.'/../assets/app.js') ?>"></script>
</body>
</html>
