// ── Tab navigation ──────────────────────────────────────────────────────────
const tabs = document.querySelectorAll('.nav-link');
tabs.forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    tabs.forEach(x => x.classList.remove('active'));
    a.classList.add('active');
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.getElementById(a.dataset.tab).classList.add('active');
    if (a.dataset.tab === 'tab-list') refreshPage();
  });
});

// ── Toast ───────────────────────────────────────────────────────────────────
function toast(msg, type = 'success') {
  const c    = document.getElementById('toast-container');
  const t    = document.createElement('div');
  const icon = { success: '✔', error: '✗', info: 'ℹ' }[type] || '•';
  t.className = `toast ${type}`;
  t.innerHTML = `<span>${icon}</span><span>${msg}</span>`;
  c.appendChild(t);
  setTimeout(() => {
    t.classList.add('fade-out');
    setTimeout(() => t.remove(), 400);
  }, 4000);
}

// ── Confirm dialog ──────────────────────────────────────────────────────────
let confirmCallback = null;

function showConfirm(title, msg, cb) {
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-msg').textContent   = msg;
  confirmCallback = cb;
  document.getElementById('confirm-overlay').classList.add('open');
}

function closeConfirm() {
  document.getElementById('confirm-overlay').classList.remove('open');
  confirmCallback = null;
}

document.getElementById('confirm-ok').addEventListener('click', () => {
  const cb = confirmCallback;
  closeConfirm();
  if (cb) cb();
});

document.getElementById('confirm-overlay').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeConfirm();
});

document.querySelector('#confirm-overlay .dialog').addEventListener('click', e => {
  e.stopPropagation();
});

// ── API helper ──────────────────────────────────────────────────────────────
async function api(action, params = {}) {
  const body = new FormData();
  body.append('action', action);
  for (const [k, v] of Object.entries(params)) body.append(k, v);
  try {
    const r = await fetch(location.pathname, {
      method:  'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body,
    });
    const text = await r.text();
    try {
      return JSON.parse(text);
    } catch (e) {
      console.error('Non-JSON response for action=' + action + ':', text);
      return { error: 'Server returned an unexpected response. Check console for details.' };
    }
  } catch (e) {
    console.error('Fetch failed for action=' + action + ':', e);
    return { error: 'Request failed: ' + e.message };
  }
}

function setResult(id, data) {
  const el = document.getElementById(id);
  if (!el) return;
  if (data.success) {
    el.innerHTML = `<span class="text-green">✔ </span>${data.success}`;
    toast(data.success.replace(/<[^>]+>/g, ''), 'success');
  } else if (data.error) {
    el.innerHTML = `<span class="text-red">✗ </span>${data.error}`;
    toast(data.error, 'error');
  } else if (data.info) {
    el.innerHTML = `<span class="text-amber">ℹ </span>${data.info}`;
    toast(data.info.replace(/<[^>]+>/g, ''), 'info');
  }
  el.style.display = 'block';
}

// ── Add ─────────────────────────────────────────────────────────────────────
async function doAdd() {
  const url  = document.getElementById('add-url').value.trim();
  const name = document.getElementById('add-name').value.trim();
  if (!url) { toast('Please enter a feed URL', 'error'); return; }
  const btn = document.getElementById('btn-add');
  btn.disabled  = true;
  btn.innerHTML = '<span class="spinner"></span> Fetching…';
  const data    = await api('add', { url, name });
  btn.disabled  = false;
  btn.innerHTML = 'Add Feed';
  setResult('add-result', data);
  if (data.success) {
    document.getElementById('add-url').value  = '';
    document.getElementById('add-name').value = '';
    refreshSelects();
  }
}

// ── Update ──────────────────────────────────────────────────────────────────
async function doUpdate() {
  const slug    = document.getElementById('update-slug').value;
  const autoDl  = document.getElementById('update-auto-dl').checked;
  const btn     = document.getElementById('btn-update');
  btn.disabled  = true;
  btn.innerHTML = '<span class="spinner"></span> Updating…';
  const data    = await api('update', { slug });
  btn.disabled  = false;
  btn.innerHTML = '↻ Update';
  setResult('update-result', data);

  const newEps = data.new_episodes || [];
  if (autoDl && newEps.length) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
    document.getElementById('tab-download').classList.add('active');
    document.querySelector('[data-tab="tab-download"]').classList.add('active');
    toast(`Downloading ${newEps.length} new episode(s)…`, 'info');
    for (const ep of newEps) {
      sseDownloadOne(ep.slug, ep.ep_num, ep.title, ep.feed_title);
      await new Promise(r => setTimeout(r, 200));
    }
  }
}

// ── Remove ──────────────────────────────────────────────────────────────────
function confirmRemove() {
  const slug = document.getElementById('remove-slug').value;
  if (!slug) { toast('Select a feed to remove', 'error'); return; }
  showConfirm('Remove Feed', `Remove "${slug}"? This cannot be undone.`, async () => {
    const data = await api('remove', { slug });
    setResult('remove-result', data);
    if (data.success) refreshSelects();
  });
}

// ── Mark Played ─────────────────────────────────────────────────────────────
async function doMark(unplayed) {
  const slug    = document.getElementById('mark-slug').value;
  const episode = document.getElementById('mark-episode').value;
  if (!slug) { toast('Select a feed first', 'error'); return; }
  const label   = unplayed ? 'unplayed' : 'played';
  const runMark = async () => {
    const data = await api('mark_played', { slug, episode, unplayed: unplayed ? '1' : '0' });
    setResult('mark-result', data);
    if (data.success && document.getElementById('status-slug').value === slug) loadStatus();
  };
  if (!episode) {
    showConfirm('Mark All Episodes', `Mark all episodes of "${slug}" as ${label}?`, runMark);
  } else {
    await runMark();
  }
}

// ── Download with SSE progress ───────────────────────────────────────────────
async function populateDlEpisodes() {
  const slug = document.getElementById('dl-slug').value;
  const sel  = document.getElementById('dl-episode-select');
  sel.innerHTML = '<option value="">— All undownloaded —</option>';
  if (!slug) return;
  const data = await api('get_feeds');
  const feed = (data.feeds || {})[slug];
  if (!feed) return;
  feed.episodes.forEach((ep, i) => {
    const opt = document.createElement('option');
    opt.value = i + 1;
    const dl  = ep.local_path ? ' ⬇' : '';
    opt.textContent = `${i + 1}. ${ep.title.substring(0, 55)}${dl}`;
    sel.appendChild(opt);
  });
}

function sseDownloadOne(slug, episodeNum, titleText, feedTitle) {
  const list = document.getElementById('dl-progress-list');
  const id   = `dl-item-${slug}-${episodeNum}`;

  document.getElementById(id)?.remove();

  const item = document.createElement('div');
  item.className = 'dl-item';
  item.id = id;
  item.innerHTML = `
    <div class="dl-item-title">${escHtml(titleText)}</div>
    <div class="dl-item-bar-wrap"><div class="dl-item-bar indeterminate" id="${id}-bar"></div></div>
    <div class="dl-item-status" id="${id}-status">Connecting…</div>`;
  list.prepend(item);

  const url = `?action=sse_download&slug=${encodeURIComponent(slug)}&episode=${episodeNum}`;
  const es  = new EventSource(url);

  es.onmessage = (e) => {
    const d   = JSON.parse(e.data);
    const bar = document.getElementById(`${id}-bar`);
    const st  = document.getElementById(`${id}-status`);

    if (d.error) {
      es.close();
      item.classList.add('error');
      bar.classList.remove('indeterminate');
      bar.style.width = '100%';
      st.textContent  = '✗ ' + d.error;
      toast(`Download failed: ${d.error}`, 'error');
      return;
    }

    if (d.pct >= 0) {
      bar.classList.remove('indeterminate');
      bar.style.width = d.pct + '%';
    }

    if (d.mb_total) {
      st.textContent = `${d.mb_done} / ${d.mb_total} MB${d.pct >= 0 ? '  ' + d.pct + '%' : ''}`;
    } else {
      st.textContent = `${d.mb_done} MB downloaded…`;
    }

    if (d.done) {
      es.close();
      item.classList.add('done');
      const already = d.already ? ' (already had it)' : '';
      st.innerHTML = `✔ Saved — <span class="text-muted">${escHtml(d.path)}</span>${already}`;
      const playBtn = document.createElement('button');
      playBtn.className = 'btn btn-ghost btn-sm';
      playBtn.style.marginLeft = '8px';
      playBtn.textContent = '▶ Play';
      playBtn.addEventListener('click', () => playEpisode(slug, episodeNum, titleText, feedTitle));
      st.appendChild(playBtn);
      toast(`Downloaded: ${titleText.substring(0, 50)}`, 'success');
      _statusCache = null;
      if (document.getElementById('status-slug').value === slug) loadStatus(true);
    }
  };

  es.onerror = () => {
    es.close();
    const st = document.getElementById(`${id}-status`);
    if (st && !item.classList.contains('done') && !item.classList.contains('error')) {
      item.classList.add('error');
      st.textContent = '✗ Connection lost';
    }
  };
}

async function doDownload(all) {
  const slug  = document.getElementById('dl-slug').value;
  const epVal = document.getElementById('dl-episode-select').value;
  const data  = await api('get_feeds');
  const feeds = data.feeds || {};

  let queue = [];

  if (epVal) {
    if (!slug) { toast('Select a feed first', 'error'); return; }
    const feed = feeds[slug];
    if (!feed) return;
    const ep = feed.episodes[parseInt(epVal) - 1];
    if (ep) queue.push({ slug, episode: parseInt(epVal), title: ep.title, feedTitle: feed.meta.title });
  } else {
    const targets = slug ? { [slug]: feeds[slug] } : feeds;
    for (const [s, feed] of Object.entries(targets)) {
      if (!feed) continue;
      feed.episodes.forEach((ep, i) => {
        const hasFile = ep.local_path && ep.local_path !== '';
        if (all || !hasFile) {
          queue.push({ slug: s, episode: i + 1, title: ep.title, feedTitle: feed.meta.title });
        }
      });
    }
  }

  if (!queue.length) { toast('Nothing to download', 'info'); return; }

  toast(`Queuing ${queue.length} download(s)…`, 'info');
  for (const item of queue) {
    sseDownloadOne(item.slug, item.episode, item.title, item.feedTitle);
    await new Promise(r => setTimeout(r, 200));
  }
}

// ── Episode Detail Overlay ───────────────────────────────────────────────────
function showEpisodeDetail(slug, epNum, ep, feedTitle) {
  document.getElementById('detail-feed-name').textContent = feedTitle + '  [' + slug + ']';
  document.getElementById('detail-ep-title').textContent  = ep.title;

  const metaEl = document.getElementById('detail-meta');
  metaEl.innerHTML = '';
  const addMeta = (text, cls) => {
    const s = document.createElement('span');
    s.textContent = text;
    if (cls) s.className = cls;
    metaEl.appendChild(s);
  };
  if (ep.pub_date)    addMeta(ep.pub_date.substring(0, 16));
  if (ep.duration)    addMeta(ep.duration);
  if (ep.file_size > 0) addMeta((ep.file_size / 1048576).toFixed(1) + ' MB');
  if (ep.local_path)  addMeta('⬇ downloaded', 'text-blue');
  if (ep.played)      addMeta('✔ played', 'text-green');

  document.getElementById('detail-desc').innerHTML = ep.description || '<em>No description available.</em>';

  const actions = document.getElementById('detail-actions');
  actions.innerHTML = '';

  const mediaBtn = document.createElement('button');
  mediaBtn.className = 'btn btn-primary';
  if (ep.local_path) {
    mediaBtn.textContent = '▶ Play';
    mediaBtn.addEventListener('click', () => { closeDetail(); playEpisode(slug, epNum, ep.title, feedTitle); });
  } else {
    mediaBtn.textContent = '⬇ Download';
    mediaBtn.addEventListener('click', () => { closeDetail(); startDownloadFromStatus(slug, epNum, ep.title, feedTitle); });
  }
  actions.appendChild(mediaBtn);

  const markBtn = document.createElement('button');
  markBtn.className   = 'btn btn-ghost';
  markBtn.textContent = ep.played ? '✕ Mark Unplayed' : '✔ Mark Played';
  markBtn.addEventListener('click', async () => { closeDetail(); await quickMark(slug, epNum, ep.played); });
  actions.appendChild(markBtn);

  document.getElementById('detail-overlay').classList.add('open');
}

function closeDetail() {
  document.getElementById('detail-overlay').classList.remove('open');
}

document.getElementById('detail-overlay').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeDetail();
});

// ── Search ───────────────────────────────────────────────────────────────────
async function doSearch() {
  const query     = document.getElementById('search-query').value.trim();
  const limit     = document.getElementById('search-limit').value;
  if (!query) { toast('Enter a search term', 'error'); return; }
  const data      = await api('search', { query, limit });
  const container = document.getElementById('search-results');
  if (data.error) { toast(data.error, 'error'); return; }
  const results   = data.results || {};
  if (Object.keys(results).length === 0) {
    container.innerHTML = `<div class="empty">No episodes matched "<strong>${escHtml(data.query)}</strong>".</div>`;
    return;
  }
  container.innerHTML = '';
  for (const [slug, group] of Object.entries(results)) {
    const groupDiv = document.createElement('div');
    groupDiv.className = 'search-group';

    const groupTitle = document.createElement('div');
    groupTitle.className = 'search-group-title';
    groupTitle.textContent = '[' + slug + '] ' + group.title;
    groupDiv.appendChild(groupTitle);

    for (const ep of group.episodes) {
      const row = document.createElement('div');
      row.className = 'search-ep';

      const titleEl = document.createElement('div');
      titleEl.className = 'search-ep-title';
      titleEl.textContent = ep.title;

      const dateEl = document.createElement('div');
      dateEl.className = 'search-ep-date';
      dateEl.textContent = ep.pub_date ? ep.pub_date.substring(0, 16) : '';

      row.appendChild(titleEl);
      row.appendChild(dateEl);
      row.addEventListener('click', () => showEpisodeDetail(slug, ep.ep_num, ep, group.title));
      groupDiv.appendChild(row);
    }

    container.appendChild(groupDiv);
  }
}

// ── Status ───────────────────────────────────────────────────────────────────
let _statusCache    = null;
let _statusSlug     = null;
let _statusDebounce = null;

async function loadStatus(forceRefresh = false) {
  const slug  = document.getElementById('status-slug').value;
  const count = parseInt(document.getElementById('status-count').value) || 10;
  const panel = document.getElementById('status-panel');
  if (!slug) { panel.innerHTML = ''; _statusCache = null; _statusSlug = null; return; }

  if (!forceRefresh && _statusCache && _statusSlug === slug) {
    renderStatus(slug, _statusCache, count, panel);
    return;
  }

  clearTimeout(_statusDebounce);
  _statusDebounce = setTimeout(async () => {
    const data  = await api('get_feeds');
    const feeds = data.feeds || {};
    _statusCache = feeds[slug] || null;
    _statusSlug  = slug;
    if (!_statusCache) { panel.innerHTML = '<div class="empty">Feed not found.</div>'; return; }
    renderStatus(slug, _statusCache, count, panel);
  }, 120);
}

function renderStatus(slug, feed, count, panel) {
  const meta     = feed.meta;
  const episodes = feed.episodes || [];
  const played   = episodes.filter(e => e.played).length;
  const shown    = episodes.slice(0, count);

  const wrap = document.createElement('div');
  wrap.className = 'panel';
  wrap.innerHTML = `
    <div class="panel-title">${escHtml(meta.title)}</div>
    <div class="meta-grid">
      <span class="meta-key">Slug</span>      <span class="meta-val text-amber">${escHtml(slug)}</span>
      <span class="meta-key">URL</span>       <span class="meta-val"><a href="${escHtml(feed.url)}" target="_blank" style="color:var(--blue)">${escHtml(feed.url)}</a></span>
      <span class="meta-key">Added</span>     <span class="meta-val">${escHtml((feed.added || '').substring(0, 19))}</span>
      <span class="meta-key">Updated</span>   <span class="meta-val">${escHtml((feed.last_updated || '').substring(0, 19))}</span>
      <span class="meta-key">Episodes</span>  <span class="meta-val">${episodes.length}</span>
      <span class="meta-key">Played</span>    <span class="meta-val">${played} / ${episodes.length}</span>
    </div>
    ${meta.description ? `<div style="font-size:12px;color:var(--text2);font-family:var(--sans);margin-bottom:14px;">${escHtml(meta.description)}</div>` : ''}
    <div class="panel-title" style="margin-top:4px">Latest ${shown.length} Episode(s)</div>`;

  if (!shown.length) {
    const empty = document.createElement('div');
    empty.className   = 'empty';
    empty.textContent = 'No episodes.';
    wrap.appendChild(empty);
  } else {
    shown.forEach((ep, i) => {
      const epNum = i + 1;
      const pub   = ep.pub_date ? ep.pub_date.substring(0, 16) : '';
      const dur   = ep.duration ? ` · ${ep.duration}` : '';
      const mb    = ep.file_size > 0 ? ` · ${(ep.file_size / 1048576).toFixed(1)} MB` : '';

      const row = document.createElement('div');
      row.className = 'episode-row';
      row.innerHTML = `
        <div class="ep-num">${epNum}.</div>
        <div class="ep-markers">
          <span class="ep-marker ${ep.played ? 'played' : 'empty'}" title="${ep.played ? 'Played' : ''}">${ep.played ? '\u2714' : '\u00b7'}</span>
          <span class="ep-marker ${ep.local_path ? 'dl' : 'empty'}" title="${ep.local_path ? 'Downloaded' : ''}">${ep.local_path ? '\u2b07' : '\u00b7'}</span>
        </div>
        <div class="ep-body">
          <div class="ep-title">${escHtml(ep.title)}</div>
          <div class="ep-meta">${escHtml(pub)}${escHtml(dur)}${mb}</div>
        </div>
        <div class="ep-actions"></div>`;

      const actions = row.querySelector('.ep-actions');

      const mediaBtn = document.createElement('button');
      mediaBtn.className = 'btn btn-ghost btn-sm';
      if (ep.local_path) {
        mediaBtn.title       = 'Play';
        mediaBtn.textContent = '\u25b6 Play';
        mediaBtn.addEventListener('click', () => playEpisode(slug, epNum, ep.title, meta.title));
      } else {
        mediaBtn.title       = 'Download';
        mediaBtn.textContent = '\u2b07';
        mediaBtn.addEventListener('click', () => startDownloadFromStatus(slug, epNum, ep.title, meta.title));
      }
      actions.appendChild(mediaBtn);

      const markBtn = document.createElement('button');
      markBtn.className   = 'btn btn-ghost btn-sm';
      markBtn.textContent = ep.played ? 'unplayed' : 'mark played';
      markBtn.addEventListener('click', () => quickMark(slug, epNum, ep.played));
      actions.appendChild(markBtn);

      const infoBtn = document.createElement('button');
      infoBtn.className   = 'btn btn-ghost btn-sm';
      infoBtn.title       = 'View details';
      infoBtn.textContent = '…';
      infoBtn.addEventListener('click', () => showEpisodeDetail(slug, epNum, ep, meta.title));
      actions.appendChild(infoBtn);

      wrap.appendChild(row);
    });
  }

  panel.innerHTML = '';
  panel.appendChild(wrap);
}

async function openStatus(slug) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
  document.getElementById('tab-status').classList.add('active');
  document.querySelector('[data-tab="tab-status"]').classList.add('active');
  document.getElementById('status-slug').value = slug;
  _statusCache = null;
  await loadStatus();
}

async function quickMark(slug, episode, unplayed) {
  await api('mark_played', { slug, episode, unplayed: unplayed ? '1' : '0' });
  toast(`Episode ${episode} marked ${unplayed ? 'unplayed' : 'played'}`, 'success');
  _statusCache = null;
  loadStatus(true);
}

// ── Refresh page data ────────────────────────────────────────────────────────
async function refreshSelects() {
  const data  = await api('get_feeds');
  const feeds = data.feeds || {};
  const makeOpts = (includeBlank, blankLabel) => {
    let h = includeBlank ? `<option value="">${blankLabel}</option>` : '';
    for (const [s, f] of Object.entries(feeds))
      h += `<option value="${escHtml(s)}">${escHtml(s)} — ${escHtml(f.meta.title)}</option>`;
    return h;
  };
  document.getElementById('update-slug').innerHTML = makeOpts(true, '— All Feeds —');
  document.getElementById('dl-slug').innerHTML     = makeOpts(true, '— All Feeds —');
  document.getElementById('status-slug').innerHTML = makeOpts(true, '— Choose a feed —');
  document.getElementById('mark-slug').innerHTML   = makeOpts(true, '— Choose a feed —');
  document.getElementById('remove-slug').innerHTML = makeOpts(true, '— Choose a feed —');
  refreshList(feeds);
}

function refreshList(feeds) {
  const tbody  = document.querySelector('#tab-list tbody');
  const header = document.querySelector('#tab-list .page-subtitle');
  if (!tbody) return;
  if (header) header.textContent = Object.keys(feeds).length + ' feed(s) subscribed';
  if (!Object.keys(feeds).length) {
    tbody.closest('.panel').innerHTML = '<div class="empty" style="padding:20px">No feeds yet. Use <strong>Add Feed</strong> to subscribe to a podcast.</div>';
    return;
  }
  tbody.innerHTML = '';
  for (const [slug, feed] of Object.entries(feeds)) {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td class="slug">${escHtml(slug)}</td>
      <td class="num">${(feed.episodes || []).length}</td>
      <td class="date">${escHtml((feed.last_updated || '').substring(0, 19))}</td>
      <td class="title">${escHtml(feed.meta.title)}</td>
      <td class="actions"><button class="btn btn-ghost btn-sm" onclick="openStatus('${escHtml(slug)}')">status</button></td>`;
    tbody.appendChild(tr);
  }
}

function refreshPage() { refreshSelects(); }

// ── Audio Player ─────────────────────────────────────────────────────────────
function playEpisode(slug, episodeNum, title, feedTitle) {
  const bar   = document.getElementById('player-bar');
  const audio = document.getElementById('player-audio');
  const et    = document.getElementById('player-ep-title');
  const ft    = document.getElementById('player-feed-title');

  const src = `?action=stream&slug=${encodeURIComponent(slug)}&episode=${episodeNum}`;
  audio.src = src;
  et.textContent = title;
  ft.textContent = feedTitle;
  bar.classList.add('open');
  audio.play().catch(() => {});

  document.querySelector('.main').style.paddingBottom = '72px';
}

function closePlayer() {
  const audio = document.getElementById('player-audio');
  audio.pause();
  audio.src = '';
  document.getElementById('player-bar').classList.remove('open');
  document.querySelector('.main').style.paddingBottom = '';
}

// Start a download from the status view and switch to the download tab
function startDownloadFromStatus(slug, episodeNum, title, feedTitle) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
  document.getElementById('tab-download').classList.add('active');
  document.querySelector('[data-tab="tab-download"]').classList.add('active');
  sseDownloadOne(slug, episodeNum, title, feedTitle);
}

// ── Utility ──────────────────────────────────────────────────────────────────
function escHtml(s) {
  return String(s)
    .replace(/&/g,  '&amp;')
    .replace(/</g,  '&lt;')
    .replace(/>/g,  '&gt;')
    .replace(/"/g,  '&quot;');
}
