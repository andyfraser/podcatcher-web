// ── State Management ────────────────────────────────────────────────────────
let state = {
  feeds: {},
  activeTab: 'tab-list',
  statusSlug: null,
  statusCount: 10,
  searchQuery: '',
  searchResults: {},
  discoverQuery: '',
  discoverResults: [],
  theme: localStorage.getItem('podcatcher-theme') || 'auto',
  player: {
    open: false,
    slug: null,
    episodeNum: null,
    title: '',
    feedTitle: '',
    speed: 1.0
  },
  downloads: [] // { slug, epNum, title, pct, mbDone, mbTotal, status, done, error, path }
};

function setState(patch) {
  state = { ...state, ...patch };
  render();
}

// ── Rendering Engine ────────────────────────────────────────────────────────
function render() {
  renderTabs();
  renderFeedList();
  renderStatus();
  renderSearch();
  renderDiscover();
  renderPlayer();
  renderDownloads();
  updateSidebarCounts();
  applyTheme();
}

function applyTheme() {
  const theme = state.theme;
  document.documentElement.dataset.theme = theme;
  const select = document.getElementById('setting-theme');
  if (select) select.value = theme;
}

function renderTabs() {
  const navLinks = document.querySelectorAll('.nav-link');
  navLinks.forEach(a => {
    if (a.dataset.tab === state.activeTab) a.classList.add('active');
    else a.classList.remove('active');
  });

  document.querySelectorAll('.tab-content').forEach(t => {
    if (t.id === state.activeTab) t.classList.add('active');
    else t.classList.remove('active');
  });
}

function renderFeedList() {
  const tbody  = document.querySelector('#tab-list tbody');
  const header = document.querySelector('#tab-list .page-subtitle');
  if (!tbody) return;

  const feedArray = Object.entries(state.feeds);
  if (header) header.textContent = feedArray.length + ' feed(s) subscribed';

  if (!feedArray.length) {
    const listPanel = tbody.closest('.panel');
    if (listPanel) listPanel.innerHTML = '<div class="empty" style="padding:20px">No feeds yet. Use <strong>Add Feed</strong> to subscribe to a podcast.</div>';
    return;
  }

  const sorted = feedArray.sort(([, a], [, b]) =>
    (a.meta.title || '').localeCompare(b.meta.title || '', undefined, { sensitivity: 'base' }));

  let html = '';
  for (const [slug, feed] of sorted) {
    html += `
      <tr>
        <td class="title">${escHtml(feed.meta.title)}</td>
        <td class="num">${(feed.episodes || []).length}</td>
        <td class="date">${escHtml(fmtDate(feed.last_updated))}</td>
        <td class="slug">${escHtml(slug)}</td>
        <td class="actions">
          <button class="btn btn-ghost btn-sm" onclick="openStatus('${escHtml(slug)}')">status</button>
        </td>
      </tr>`;
  }
  tbody.innerHTML = html;
}

function renderStatus() {
  const panel = document.getElementById('status-panel');
  if (!panel) return;

  const slug = state.statusSlug;
  
  const sel = document.getElementById('status-slug');
  if (sel && sel.value !== (slug || '')) {
    sel.value = slug || '';
  }

  const feed = state.feeds[slug];

  if (!slug) { panel.innerHTML = ''; return; }
  if (!feed) { panel.innerHTML = '<div class="empty">Feed not found.</div>'; return; }

  const meta     = feed.meta;
  const episodes = feed.episodes || [];
  const played   = episodes.filter(e => e.played).length;
  const count    = state.statusCount === 0 ? episodes.length : state.statusCount;
  const shown    = episodes.slice(0, count);

  let html = `
    <div class="panel">
      <div class="panel-title">${escHtml(meta.title)}</div>
      <div class="meta-grid">
        <span class="meta-key">Slug</span>      <span class="meta-val text-amber">${escHtml(slug)}</span>
        <span class="meta-key">URL</span>       <span class="meta-val"><a href="${escHtml(feed.url)}" target="_blank" style="color:var(--blue)">${escHtml(feed.url)}</a></span>
        <span class="meta-key">Added</span>     <span class="meta-val">${escHtml(fmtDate(feed.added))}</span>
        <span class="meta-key">Updated</span>   <span class="meta-val">${escHtml(fmtDate(feed.last_updated))}</span>
        <span class="meta-key">Episodes</span>  <span class="meta-val">${episodes.length}</span>
        <span class="meta-key">Played</span>    <span class="meta-val">${played} / ${episodes.length}</span>
      </div>
      ${meta.description ? descriptionHtml(meta.description) : ''}
      <div class="panel-title" style="margin-top:4px">Latest ${shown.length} Episode(s)</div>`;

  if (!shown.length) {
    html += '<div class="empty">No episodes.</div>';
  } else {
    shown.forEach((ep, i) => {
      const epNum = i + 1;
      const pub   = ep.pub_date ? ep.pub_date.substring(0, 16) : '';
      const dur   = ep.duration ? ` · ${ep.duration}` : '';
      const mb    = ep.file_size > 0 ? ` · ${(ep.file_size / 1048576).toFixed(1)} MB` : '';

      // Progress bar calculation
      let progressHtml = '';
      if (ep.progress > 0 && ep.duration_seconds > 0 && !ep.played) {
        const pct = Math.min(100, Math.max(0, (ep.progress / ep.duration_seconds) * 100));
        progressHtml = `<div class="ep-progress-bar"><div class="ep-progress-fill" style="width: ${pct}%"></div></div>`;
      }

      html += `
        <div class="episode-row">
          <div class="ep-num">${epNum}.</div>
          <div class="ep-body">
            <div class="ep-title-row">
              <span class="ep-glyph played${ep.played ? ' on' : ''}" title="${ep.played ? 'Played' : ''}">✔</span>
              <span class="ep-title">${escHtml(ep.title)}</span>
            </div>
            <div class="ep-meta-row">
              <span class="ep-glyph dl${ep.local_path ? ' on' : ''}" title="${ep.local_path ? 'Downloaded' : ''}">⬇</span>
              <span class="ep-meta">${escHtml(pub)}${escHtml(dur)}${mb}</span>
            </div>
            ${progressHtml}
          </div>
          <div class="ep-actions">
            ${ep.local_path
              ? `<button class="btn btn-ghost btn-sm" onclick="playEpisode('${escHtml(slug)}', ${epNum}, '${escJs(ep.title)}', '${escJs(meta.title)}', '${escJs(ep.guid)}')" title="Play">▶ Play</button>`
              : `<button class="btn btn-ghost btn-sm" onclick="startDownloadFromStatus('${escHtml(slug)}', ${epNum}, '${escJs(ep.title)}', '${escJs(meta.title)}', '${escJs(ep.guid)}')" title="Download">⬇</button>`
            }
            <button class="btn btn-ghost btn-sm ep-mark-btn" onclick="quickMark('${escHtml(slug)}', ${epNum}, ${ep.played}, '${escJs(ep.guid)}')">
              ${ep.played ? 'mark unplayed' : 'mark played'}
            </button>
            <button class="btn btn-ghost btn-sm" onclick="showEpisodeDetail('${escHtml(slug)}', ${epNum}, '${escJs(ep.guid)}')" title="View details">…</button>
          </div>
        </div>`;
    });
  }
  html += '</div>';
  panel.innerHTML = html;
}

function renderSearch() {
  const container = document.getElementById('search-results');
  if (!container) return;

  const results = state.searchResults;
  if (!state.searchQuery) { container.innerHTML = ''; return; }

  if (Object.keys(results).length === 0) {
    container.innerHTML = `<div class="empty">No episodes matched "<strong>${escHtml(state.searchQuery)}</strong>".</div>`;
    return;
  }

  let html = '';
  for (const [slug, group] of Object.entries(results)) {
    html += `
      <div class="search-group">
        <div class="search-group-title">[${escHtml(slug)}] ${escHtml(group.title)}</div>`;
    for (const ep of group.episodes) {
      html += `
        <div class="search-ep" onclick="showEpisodeDetail('${escHtml(slug)}', ${ep.ep_num}, '${escJs(ep.guid)}')">
          <div class="search-ep-title">${escHtml(ep.title)}</div>
          <div class="search-ep-date">${escHtml(ep.pub_date ? ep.pub_date.substring(0, 16) : '')}</div>
        </div>`;
    }
    html += '</div>';
  }
  container.innerHTML = html;
}

function renderDiscover() {
  const container = document.getElementById('discover-results');
  if (!container) return;

  if (!state.discoverQuery) { container.innerHTML = ''; return; }
  const results = state.discoverResults;

  if (results.length === 0) {
    container.innerHTML = `<div class="empty">No podcasts found for "<strong>${escHtml(state.discoverQuery)}</strong>".</div>`;
    return;
  }

  let html = '';
  results.forEach(res => {
    html += `
      <div class="discover-card">
        <img src="${escHtml(res.image)}" alt="Artwork" class="discover-img">
        <div class="discover-body">
          <div class="discover-title" title="${escHtml(res.title)}">${escHtml(res.title)}</div>
          <div class="discover-author">${escHtml(res.author)}</div>
          <div class="discover-genres">${escHtml(res.genres.slice(0, 2).join(', '))}</div>
          <button class="btn btn-primary btn-sm mt8" onclick="addFromDiscover('${escHtml(res.url)}')">+ Add</button>
        </div>
      </div>`;
  });
  container.innerHTML = html;
}

function renderPlayer() {
  const bar = document.getElementById('player-bar');
  const et  = document.getElementById('player-ep-title');
  const ft  = document.getElementById('player-feed-title');
  const sp  = document.getElementById('player-speed');

  if (state.player.open) {
    bar.classList.add('open');
    et.textContent = state.player.title;
    ft.textContent = state.player.feedTitle;
    sp.value = state.player.speed;
    document.querySelector('.main').style.paddingBottom = '84px';
  } else {
    bar.classList.remove('open');
    document.querySelector('.main').style.paddingBottom = '';
  }
}

function renderDownloads() {
  const container = document.getElementById('dl-progress-list');
  if (!container) return;

  if (state.downloads.length === 0) {
    container.innerHTML = '';
    return;
  }

  let html = '';
  state.downloads.forEach(dl => {
    const id = `dl-item-${dl.slug}-${dl.epNum}`;
    const statusText = dl.error
      ? `✗ ${dl.error}`
      : dl.done
        ? `✔ Saved — <span class="text-muted">${escHtml(dl.path)}</span>`
        : dl.mbTotal
          ? `${dl.mbDone} / ${dl.mbTotal} MB ${dl.pct >= 0 ? ' · ' + dl.pct + '%' : ''}`
          : `${dl.mbDone} MB downloaded…`;

    html += `
      <div class="dl-item ${dl.done ? 'done' : ''} ${dl.error ? 'error' : ''}" id="${id}">
        <div class="dl-item-title">${escHtml(dl.title)}</div>
        <div class="dl-item-bar-wrap">
          <div class="dl-item-bar ${!dl.done && !dl.error && dl.pct < 0 ? 'indeterminate' : ''}"
               style="width: ${dl.pct >= 0 ? dl.pct : (dl.done ? 100 : 30)}%"></div>
        </div>
        <div class="dl-item-status">
          ${statusText}
          ${dl.done ? `<button class="btn btn-ghost btn-sm" style="margin-left:8px" onclick="playEpisode('${escHtml(dl.slug)}', ${dl.epNum}, '${escJs(dl.title)}', '${escJs(dl.feedTitle)}')">▶ Play</button>` : ''}
        </div>
      </div>`;
  });
  container.innerHTML = html;
}

function updateSidebarCounts() {
  // Option to add unplayed counts next to feed names in the sidebar could go here
}

// ── Event Handlers ──────────────────────────────────────────────────────────
document.querySelectorAll('.nav-link').forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    const tabId = a.dataset.tab;
    setState({ activeTab: tabId });
    if (tabId === 'tab-list') refreshData();
  });
});

async function refreshData() {
  const data = await api('get_feeds');
  if (data.feeds) {
    setState({ feeds: data.feeds });
    updateSelectOptions();
  }
}

function updateSelectOptions() {
  const feeds = state.feeds;
  const makeOpts = (includeBlank, blankLabel) => {
    let h = includeBlank ? `<option value="">${blankLabel}</option>` : '';
    const sorted = Object.entries(feeds).sort(([, a], [, b]) =>
      (a.meta.title || '').localeCompare(b.meta.title || '', undefined, { sensitivity: 'base' }));
    for (const [s, f] of sorted)
      h += `<option value="${escHtml(s)}">${escHtml(f.meta.title)} (${escHtml(s)})</option>`;
    return h;
  };

  const updateSlug = document.getElementById('update-slug');
  if (updateSlug) updateSlug.innerHTML = makeOpts(true, '— All Feeds —');

  const dlSlug = document.getElementById('dl-slug');
  if (dlSlug) dlSlug.innerHTML = makeOpts(true, '— All Feeds —');

  const statusSlug = document.getElementById('status-slug');
  if (statusSlug) {
    const current = statusSlug.value;
    statusSlug.innerHTML = makeOpts(true, '— Choose a feed —');
    statusSlug.value = current;
  }

  const markSlug = document.getElementById('mark-slug');
  if (markSlug) markSlug.innerHTML = makeOpts(true, '— Choose a feed —');

  const removeSlug = document.getElementById('remove-slug');
  if (removeSlug) removeSlug.innerHTML = makeOpts(true, '— Choose a feed —');
}

// ── API Actions ─────────────────────────────────────────────────────────────
async function doAdd() {
  const url  = document.getElementById('add-url').value.trim();
  const name = document.getElementById('add-name').value.trim();
  if (!url) { toast('Please enter a feed URL', 'error'); return; }

  const btn = document.getElementById('btn-add');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Fetching…';

  const data = await api('add', { url, name });
  btn.disabled = false;
  btn.innerHTML = 'Add Feed';

  if (data.success) {
    document.getElementById('add-url').value = '';
    document.getElementById('add-name').value = '';
    toast(data.success.replace(/<[^>]+>/g, ''), 'success');
    await refreshData();
  } else {
    toast(data.error || 'Failed to add feed', 'error');
  }
}

async function doUpdate() {
  const slug   = document.getElementById('update-slug').value;
  const autoDl = document.getElementById('update-auto-dl').checked;
  const btn    = document.getElementById('btn-update');
  const resEl  = document.getElementById('update-result');

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Updating…';
  resEl.innerHTML = '';

  const url = `?action=sse_update&slug=${encodeURIComponent(slug)}`;
  const es = new EventSource(url);

  es.onmessage = async (e) => {
    const d = JSON.parse(e.data);
    if (d.error) {
      toast(d.error, 'error');
      es.close();
      btn.disabled = false;
      btn.innerHTML = '↻ Update';
    } else if (d.done) {
      es.close();
      btn.disabled = false;
      btn.innerHTML = '↻ Update';
      toast('Feeds updated successfully', 'success');
      resEl.innerHTML += `<div class="text-green mt8">Done. ${d.total_new || 0} new episode(s).</div>`;
      await refreshData();

      const newEps = d.new_episodes || [];
      if (autoDl && newEps.length) {
        setState({ activeTab: 'tab-download' });
        toast(`Downloading ${newEps.length} new episode(s)…`, 'info');
        for (const ep of newEps) {
          sseDownloadOne(ep.slug, ep.ep_num, ep.title, ep.feed_title);
          await new Promise(r => setTimeout(r, 200));
        }
      }
    } else if (d.status) {
      resEl.innerHTML += `<div style="font-size:13px;color:var(--text2);font-family:monospace">${escHtml(d.status)}</div>`;
    }
  };

  es.onerror = () => {
    es.close();
    btn.disabled = false;
    btn.innerHTML = '↻ Update';
    toast('Connection lost during update', 'error');
  };
}

async function doSearch() {
  const query = document.getElementById('search-query').value.trim();
  const limit = document.getElementById('search-limit').value;
  if (!query) { toast('Enter a search term', 'error'); return; }

  const data = await api('search', { query, limit });
  if (data.error) {
    toast(data.error, 'error');
  } else {
    setState({ searchQuery: query, searchResults: data.results || {} });
  }
}

async function doDiscover() {
  const query = document.getElementById('discover-query').value.trim();
  if (!query) { toast('Enter a podcast name or topic', 'error'); return; }

  const container = document.getElementById('discover-results');
  container.innerHTML = '<div class="empty"><span class="spinner"></span> Searching iTunes...</div>';

  const data = await api('discover', { query });
  if (data.error) {
    toast(data.error, 'error');
    setState({ discoverQuery: query, discoverResults: [] });
  } else {
    setState({ discoverQuery: query, discoverResults: data.results || [] });
  }
}

function addFromDiscover(url) {
  document.getElementById('add-url').value = url;
  setState({ activeTab: 'tab-add' });
  document.getElementById('add-name').focus();
  toast('Feed URL copied to Add tab', 'info');
}

function changeTheme(theme) {
  localStorage.setItem('podcatcher-theme', theme);
  setState({ theme });
}

// ── Audio Player ─────────────────────────────────────────────────────────────
let lastSyncedPosition = -1;

function playEpisode(slug, episodeNum, title, feedTitle, guid = '') {
  const audio = document.getElementById('player-audio');
  const src = `?action=stream&slug=${encodeURIComponent(slug)}&episode=${episodeNum}&guid=${encodeURIComponent(guid)}`;

  setState({
    player: {
      ...state.player,
      open: true,
      slug,
      episodeNum,
      guid,
      title,
      feedTitle
    }
  });

  audio.src = src;
  audio.playbackRate = state.player.speed;

  // Resume from saved progress once metadata is loaded
  const onMetadata = () => {
    const feed = state.feeds[slug];
    const ep = feed ? feed.episodes[episodeNum - 1] : null;
    if (ep && ep.progress > 0 && !ep.played) {
      audio.currentTime = ep.progress;
    }
    audio.removeEventListener('loadedmetadata', onMetadata);
  };
  audio.addEventListener('loadedmetadata', onMetadata);

  audio.play().catch(() => {});
}

async function syncProgress(force = false) {
  const audio = document.getElementById('player-audio');
  if (!audio.src || !state.player.slug) return;

  const slug = state.player.slug;
  const epNum = state.player.episodeNum;
  const guid = state.player.guid;
  const position = audio.currentTime;
  const duration = audio.duration || 0;

  // Update local state immediately for responsiveness
  const epIdx = findEpisodeIdx(slug, epNum, guid);
  if (epIdx !== -1) {
    state.feeds[slug].episodes[epIdx].progress = position;
    if (duration > 0) {
      state.feeds[slug].episodes[epIdx].duration_seconds = duration;
    }
    render(); // Re-render to show updated progress bar
  }

  // Skip API call if audio is paused and we aren't forcing a sync
  if (!force && (audio.paused || position === lastSyncedPosition)) return;
  lastSyncedPosition = position;

  const res = await api('save_progress', {
    slug,
    episode: epNum,
    guid,
    position,
    duration
  });

  if (res.auto_played) {
    if (epIdx !== -1) {
      state.feeds[slug].episodes[epIdx].played = true;
      render();
    }
  }
}

function closePlayer() {
  const audio = document.getElementById('player-audio');
  syncProgress(true); // Final sync
  lastSyncedPosition = -1;
  audio.pause();
  audio.src = '';
  setState({ player: { ...state.player, open: false } });
}

function skipPlayer(seconds) {
  const audio = document.getElementById('player-audio');
  if (!audio.src) return;
  audio.currentTime += seconds;
}

function setPlayerSpeed(rate) {
  const audio = document.getElementById('player-audio');
  const speed = parseFloat(rate);
  setState({ player: { ...state.player, speed } });
  if (audio.src) audio.playbackRate = speed;
}

// ── Downloads ───────────────────────────────────────────────────────────────
function sseDownloadOne(slug, epNum, title, feedTitle, guid = '') {
  // Initialize download in state
  const downloads = [...state.downloads];
  const existingIdx = downloads.findIndex(d => d.slug === slug && d.guid === guid && (guid || d.epNum === epNum));
  const newDl = {
    slug, epNum, guid, title, feedTitle,
    pct: -1, mbDone: 0, mbTotal: null,
    status: 'Connecting…', done: false, error: null
  };

  if (existingIdx >= 0) downloads[existingIdx] = newDl;
  else downloads.unshift(newDl);

  setState({ downloads });

  const url = `?action=sse_download&slug=${encodeURIComponent(slug)}&episode=${epNum}&guid=${encodeURIComponent(guid)}`;
  const es = new EventSource(url);

  es.onmessage = (e) => {
    const d = JSON.parse(e.data);
    const curDownloads = [...state.downloads];
    const idx = curDownloads.findIndex(dl => dl.slug === slug && dl.guid === guid && (guid || dl.epNum === epNum));
    if (idx === -1) return;

    if (d.error) {
      es.close();
      curDownloads[idx] = { ...curDownloads[idx], error: d.error, status: '✗ ' + d.error };
      setState({ downloads: curDownloads });
      toast(`Download failed: ${d.error}`, 'error');
      return;
    }

    curDownloads[idx] = {
      ...curDownloads[idx],
      pct: d.pct ?? curDownloads[idx].pct,
      mbDone: d.mb_done ?? curDownloads[idx].mbDone,
      mbTotal: d.mb_total ?? curDownloads[idx].mbTotal,
      done: !!d.done,
      path: d.path ?? curDownloads[idx].path
    };

    if (d.done) {
      es.close();
      setState({ downloads: curDownloads });
      toast(`Downloaded: ${title.substring(0, 50)}`, 'success');
      refreshData(); // Refresh to update "Downloaded" status in episode lists
    } else {
      setState({ downloads: curDownloads });
    }
  };

  es.onerror = () => {
    es.close();
    const curDownloads = [...state.downloads];
    const idx = curDownloads.findIndex(dl => dl.slug === slug && dl.epNum === epNum);
    if (idx !== -1 && !curDownloads[idx].done && !curDownloads[idx].error) {
      curDownloads[idx] = { ...curDownloads[idx], error: 'Connection lost', status: '✗ Connection lost' };
      setState({ downloads: curDownloads });
    }
  };
}

async function doDownload(all) {
  const slug  = document.getElementById('dl-slug').value;
  const epVal = document.getElementById('dl-episode-select').value;
  const feeds = state.feeds;

  let queue = [];
  if (epVal) {
    if (!slug) { toast('Select a feed first', 'error'); return; }
    const feed = feeds[slug];
    const epNum = parseInt(epVal);
    const ep = feed.episodes[epNum - 1];
    if (ep) queue.push({ slug, episode: epNum, guid: ep.guid, title: ep.title, feedTitle: feed.meta.title });
  } else {
    const targets = slug ? { [slug]: feeds[slug] } : feeds;
    for (const [s, feed] of Object.entries(targets)) {
      feed.episodes.forEach((ep, i) => {
        if (all || !ep.local_path) {
          queue.push({ slug: s, episode: i + 1, guid: ep.guid, title: ep.title, feedTitle: feed.meta.title });
        }
      });
    }
  }

  if (!queue.length) { toast('Nothing to download', 'info'); return; }

  toast(`Queuing ${queue.length} download(s)…`, 'info');
  for (const item of queue) {
    sseDownloadOne(item.slug, item.episode, item.title, item.feedTitle, item.guid);
    await new Promise(r => setTimeout(r, 200));
  }
}

// ── Helpers & Utils ──────────────────────────────────────────────────────────
function openStatus(slug) {
  setState({ activeTab: 'tab-status', statusSlug: slug });
}

function loadStatus() {
  const slug = document.getElementById('status-slug').value;
  const count = parseInt(document.getElementById('status-count').value) || 10;
  setState({ statusSlug: slug, statusCount: count });
}

async function quickMark(slug, episode, wasPlayed, guid = '') {
  const data = await api('mark_played', { slug, episode, guid, unplayed: wasPlayed ? '1' : '0' });
  if (data.success) {
    toast(`Episode marked ${wasPlayed ? 'unplayed' : 'played'}`, 'success');
    await refreshData();
  }
}

async function doMark(unplayed) {
  const slug = document.getElementById('mark-slug').value;
  const episode = document.getElementById('mark-episode').value;
  if (!slug) { toast('Select a feed first', 'error'); return; }

  const runMark = async () => {
    const data = await api('mark_played', { slug, episode, unplayed: unplayed ? '1' : '0' });
    if (data.success) {
      toast(data.success, 'success');
      await refreshData();
    }
  };

  if (!episode) {
    showConfirm('Mark All Episodes', `Mark all episodes of "${slug}" as ${unplayed ? 'unplayed' : 'played'}?`, runMark);
  } else {
    await runMark();
  }
}

async function populateDlEpisodes() {
  const slug = document.getElementById('dl-slug').value;
  const sel  = document.getElementById('dl-episode-select');
  sel.innerHTML = '<option value="">— All undownloaded —</option>';
  if (!slug) return;

  const feed = state.feeds[slug];
  if (!feed) return;
  feed.episodes.forEach((ep, i) => {
    const opt = document.createElement('option');
    opt.value = i + 1;
    const dl  = ep.local_path ? ' ⬇' : '';
    opt.textContent = `${i + 1}. ${ep.title.substring(0, 55)}${dl}`;
    sel.appendChild(opt);
  });
}

function startDownloadFromStatus(slug, epNum, title, feedTitle, guid = '') {
  setState({ activeTab: 'tab-download' });
  sseDownloadOne(slug, epNum, title, feedTitle, guid);
}

function confirmRemove() {
  const slug = document.getElementById('remove-slug').value;
  if (!slug) { toast('Select a feed to remove', 'error'); return; }
  showConfirm('Remove Feed', `Remove "${slug}"? This cannot be undone.`, async () => {
    const data = await api('remove', { slug });
    if (data.success) {
      toast(data.success.replace(/<[^>]+>/g, ''), 'success');
      await refreshData();
    }
  });
}

function showEpisodeDetail(slug, epNum, guid = '') {
  const feed = state.feeds[slug];
  if (!feed) return;
  const idx = findEpisodeIdx(slug, epNum, guid);
  const ep = (idx !== -1) ? feed.episodes[idx] : null;
  if (!ep) return;

  document.getElementById('detail-feed-name').textContent = feed.meta.title + ' [' + slug + ']';
  document.getElementById('detail-ep-title').textContent = ep.title;

  const metaEl = document.getElementById('detail-meta');
  metaEl.innerHTML = '';
  const addMeta = (text, cls) => {
    const s = document.createElement('span');
    s.textContent = text;
    if (cls) s.className = cls;
    metaEl.appendChild(s);
  };

  if (ep.pub_date) addMeta(ep.pub_date.substring(0, 16));
  if (ep.duration) addMeta(ep.duration);
  if (ep.file_size > 0) addMeta((ep.file_size / 1048576).toFixed(1) + ' MB');
  if (ep.local_path) addMeta('⬇ downloaded', 'text-blue');
  if (ep.played) addMeta('✔ played', 'text-green');

  document.getElementById('detail-desc').innerHTML = ep.description || '<em>No description available.</em>';

  const actions = document.getElementById('detail-actions');
  actions.innerHTML = '';

  const mediaBtn = document.createElement('button');
  mediaBtn.className = 'btn btn-primary';
  if (ep.local_path) {
    mediaBtn.textContent = '▶ Play';
    mediaBtn.addEventListener('click', () => { closeDetail(); playEpisode(slug, epNum, ep.title, feed.meta.title, guid); });
    actions.appendChild(mediaBtn);

    const delBtn = document.createElement('button');
    delBtn.className = 'btn btn-ghost text-red';
    delBtn.textContent = '✕ Delete File';
    delBtn.addEventListener('click', async () => {
      closeDetail();
      const r = await api('remove_download', { slug, episode: epNum, guid });
      if (r.success) {
        toast('Download removed', 'success');
        await refreshData();
      } else {
        toast(r.error || 'Failed to remove', 'error');
      }
    });
    actions.appendChild(delBtn);
  } else {
    mediaBtn.textContent = '⬇ Download';
    mediaBtn.addEventListener('click', () => { closeDetail(); startDownloadFromStatus(slug, epNum, ep.title, feed.meta.title, guid); });
    actions.appendChild(mediaBtn);
  }

  const markBtn = document.createElement('button');
  markBtn.className = 'btn btn-ghost';
  markBtn.textContent = ep.played ? '✕ Mark Unplayed' : '✔ Mark Played';
  markBtn.addEventListener('click', () => { closeDetail(); quickMark(slug, epNum, ep.played, guid); });
  actions.appendChild(markBtn);

  document.getElementById('detail-overlay').classList.add('open');
}

function closeDetail() { document.getElementById('detail-overlay').classList.remove('open'); }

// ── Core Infrastructure ─────────────────────────────────────────────────────
async function api(action, params = {}) {
  const body = new FormData();
  body.append('action', action);
  for (const [k, v] of Object.entries(params)) body.append(k, v);
  try {
    const r = await fetch(location.pathname, {
      method: 'POST',
      headers: { 
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': window.PODCATCHER_CSRF_TOKEN || ''
      },
      body,
    });
    const text = await r.text();
    try { return JSON.parse(text); }
    catch (e) {
      console.error('Non-JSON response:', text);
      return { error: 'Invalid server response' };
    }
  } catch (e) {
    return { error: 'Request failed: ' + e.message };
  }
}

function toast(msg, type = 'success') {
  const c = document.getElementById('toast-container');
  const t = document.createElement('div');
  const icon = { success: '✔', error: '✗', info: 'ℹ' }[type] || '•';
  t.className = `toast ${type}`;
  t.innerHTML = `<span>${icon}</span><span>${msg}</span>`;
  c.appendChild(t);
  setTimeout(() => {
    t.classList.add('fade-out');
    setTimeout(() => t.remove(), 400);
  }, 4000);
}

let confirmCallback = null;
function showConfirm(title, msg, cb) {
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-msg').textContent = msg;
  confirmCallback = cb;
  document.getElementById('confirm-overlay').classList.add('open');
}
function closeConfirm() { document.getElementById('confirm-overlay').classList.remove('open'); }
document.getElementById('confirm-ok').addEventListener('click', () => {
  if (confirmCallback) confirmCallback();
  closeConfirm();
});

function escHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function escJs(s) {
  return String(s).replace(/'/g, "\\'");
}

function findEpisodeIdx(slug, episodeNum, guid = '') {
  const feed = state.feeds[slug] || {};
  const episodes = feed.episodes || [];
  if (guid) {
    const idx = episodes.findIndex(e => e.guid === guid);
    if (idx !== -1) return idx;
  }
  const idx = episodeNum - 1;
  return episodes[idx] ? idx : -1;
}
function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}
function descriptionHtml(text, maxChars = 220) {
  if (!text) return '';
  const esc = escHtml(text);
  if (text.length <= maxChars) return `<div class="feed-desc">${esc}</div>`;
  const short = escHtml(text.substring(0, maxChars));
  return `<div class="feed-desc">` +
    `<span class="desc-short">${short}&hellip; <a href="#" class="desc-toggle" onclick="toggleDesc(this);return false;">show more</a></span>` +
    `<span class="desc-full" hidden>${esc} <a href="#" class="desc-toggle" onclick="toggleDesc(this);return false;">show less</a></span>` +
    `</div>`;
}
function toggleDesc(link) {
  const wrap = link.closest('.feed-desc');
  wrap.querySelector('.desc-short').hidden = !wrap.querySelector('.desc-short').hidden;
  wrap.querySelector('.desc-full').hidden = !wrap.querySelector('.desc-full').hidden;
}

// ── Initialize ──────────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
  // Initial state load
  api('get_feeds').then(data => {
    if (data.feeds) {
      state.feeds = data.feeds;
      updateSelectOptions();
      render();
    }
  });

  // Audio player listeners
  const audio = document.getElementById('player-audio');
  audio.addEventListener('pause', () => syncProgress(true));
  audio.addEventListener('ended', () => syncProgress(true));
  
  // Update local UI state as it plays
  audio.addEventListener('timeupdate', () => {
    if (!state.player.slug) return;
    const slug = state.player.slug;
    const epNum = state.player.episodeNum;
    if (state.feeds[slug] && state.feeds[slug].episodes[epNum - 1]) {
      state.feeds[slug].episodes[epNum - 1].progress = audio.currentTime;
      if (audio.duration > 0) {
        state.feeds[slug].episodes[epNum - 1].duration_seconds = audio.duration;
      }
      render(); 
    }
  });

  // Periodic sync every 15 seconds
  setInterval(syncProgress, 15000);

  // Global listeners
  document.getElementById('detail-overlay').addEventListener('click', e => {
    if (e.target.id === 'detail-overlay') closeDetail();
  });
  document.getElementById('confirm-overlay').addEventListener('click', e => {
    if (e.target.id === 'confirm-overlay') closeConfirm();
  });
});
