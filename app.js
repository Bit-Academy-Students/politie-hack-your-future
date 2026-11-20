'use strict';

// Browser UI; all public source requests go through the unified PHP endpoint.
const $ = id => document.getElementById(id);
const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const safeURL = value => { try { const url = new URL(value); return url.protocol === 'https:' ? url.href : ''; } catch { return ''; } };
const STORAGE_KEY = 'trendradar.public.v1';
const THEME_KEY = 'trendradar.theme.v1';
const APP_HISTORY_APP_LIMIT = 120;
const APP_HISTORY_LIMIT = 400;
const APP_HISTORY_SAMPLE_INTERVAL = 86400000;
const APP_PERIODS = { day: 86400000, week: 604800000, month: 2592000000, year: 31536000000 };
const shortDate = value => new Intl.DateTimeFormat('nl-NL', { day: 'numeric', month: 'short', timeZone: 'Europe/Amsterdam' }).format(new Date(value));
const fullDate = value => new Intl.DateTimeFormat('nl-NL', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Amsterdam' }).format(new Date(value));
const formatNumber = value => new Intl.NumberFormat('nl-NL', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
const apiURL = (source, params = {}) => `api.php?${new URLSearchParams({ source, ...params })}`;

const platforms = [
  { id: 'playstore', name: 'Google Play', symbol: '▷', color: '#a4dcc2', bg: '#19352f', metric: 'downloads', region: 'global', method: 'external', source: 'Google Play', url: 'https://play.google.com/store/apps', docs: 'https://developers.google.com/android-publisher', description: 'Google Play biedt geen openbare API voor een actuele algemene downloadranglijst. De Google Play Developer API is bedoeld voor app-eigenaren en vereist toegang tot hun eigen Play Console. Daarom tonen we hier geen verzonnen downloadcijfers.', emptyTitle: 'Google Play: geen openbare download-API.', emptyText: 'De openbare Play Store-pagina bevat geen betrouwbare algemene API voor actuele downloads of gebruikersaantallen. Bezoek de Play Store om populaire apps te ontdekken; privéstatistieken zijn alleen beschikbaar voor de betreffende ontwikkelaar.', unit: 'niet openbaar' },
  { id: 'steam', name: 'Steam', symbol: '◉', color: '#9bcce3', bg: '#1b3041', metric: 'activity', region: 'global', method: 'live', source: 'Steam Web API', url: 'https://store.steampowered.com/charts/mostplayed', docs: 'https://api.steampowered.com/ISteamChartsService/GetMostPlayedGames/v1/', description: 'Liveaantallen gelijktijdig online spelers voor een transparante selectie games via de officiële Steam Web API. Dit is geen downloadtotaal of aantal unieke spelers. De publieke API ondersteunt actuele spelerstellingen per app, niet een algemene historische gebruikersgrafiek.', unit: 'spelers gelijktijdig online' },
  { id: 'x', name: 'X', symbol: '𝕏', color: '#d4d7e2', bg: '#2b2d38', metric: 'trending', region: 'NL', method: 'external', source: 'X Trends API', url: 'https://x.com/explore/tabs/trending', docs: 'https://docs.x.com/x-api/trends/trends-by-woeid/introduction', description: 'X publiceert trending-data via een API waarvoor een developeraccount en toegangssleutel nodig zijn. Zonder eigen sleutel halen we geen X-data op en tonen we geen nagebootste trends.', emptyTitle: 'X vereist API-toegang.', emptyText: 'De officiële X Trends API vereist een developeraccount en geldige toegang. Verbind een eigen API-sleutel via een beveiligde backend om echte trends op te halen.', unit: 'API-toegang vereist' },
  { id: 'instagram', name: 'Instagram', symbol: '◎', color: '#ee9fca', bg: '#352036', metric: 'trending', region: null, method: 'external', source: 'Instagram Explore', url: 'https://www.instagram.com/explore/', docs: 'https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api', description: 'Deze versie heeft geen verifieerbare algemene ranglijst van de meest bekeken Instagram-posts. Explore is een ontdekpagina die kan verschillen per gebruiker en een login kan vragen. De officiële API is gericht op professionele accounts; toegang daartoe is niet gekoppeld.', emptyTitle: 'Instagram, met de juiste context.', emptyText: 'Er is hier geen betrouwbare algemene top van Instagram beschikbaar. Je kunt Explore bij Instagram openen. Die pagina kan persoonlijk samengesteld zijn en is dus geen objectieve wereldwijde toplijst. We vullen ontbrekende views of rangposities niet zelf in.', unit: 'niet beschikbaar' },
  { id: 'youtube', name: 'YouTube', symbol: '▶', color: '#ff9eaa', bg: '#38202b', metric: 'views', region: 'NL', method: 'external', source: 'YouTube Data API', url: 'https://www.youtube.com/feed/trending', docs: 'https://developers.google.com/youtube/v3/docs/videos/list', description: 'De officiële YouTube Data API vereist een Google Cloud API-sleutel. Zonder sleutel tonen we geen verzonnen video’s, views of trendranglijst.', emptyTitle: 'YouTube vereist een API-sleutel.', emptyText: 'Voor echte video- en viewgegevens is een eigen YouTube Data API-sleutel nodig. De sleutel hoort in een serveromgeving; voeg hem niet toe aan deze browsercode.', unit: 'API-sleutel vereist' },
  { id: 'tiktok', name: 'TikTok', symbol: '♪', color: '#8fdcdd', bg: '#173338', metric: 'trending', region: null, method: 'external', source: 'TikTok Creative Center', url: 'https://ads.tiktok.com/creative/creativeCenter/trends', docs: 'https://business-api.tiktok.com/gateway/docs/index?doc_id=1825119005427714', description: 'TikTok Creative Center biedt trends zoals hashtags. De lijst wordt in deze losse HTML-versie niet automatisch ingelezen. Beschikbare filters en toegang worden door TikTok bepaald.', emptyTitle: 'Ontdek TikTok bij de bron.', emptyText: 'Het Creative Center is de officiële plek voor beschikbare TikTok-trends, zoals populaire hashtags. Deze website leest die lijsten niet automatisch uit. Bij de bron kunnen extra filters of een login nodig zijn. Er worden hier geen fictieve views getoond.', unit: 'bij bron' },
  { id: 'google', name: 'Google Trends', symbol: 'G', color: '#9db9ff', bg: '#202c43', metric: 'trending', region: 'NL', method: 'live', source: 'Google Trends RSS', url: 'https://trends.google.com/trending?geo=NL', docs: 'https://trends.google.com/trending/rss?geo=NL', description: 'Live Nederlandse Trending Now RSS-feed van Google. De feed bevat zoekonderwerpen, een geschatte verkeersklasse en publicatietijd; dit zijn geen exacte zoekvolumes of views.', unit: 'geschat zoekverkeer' },
  { id: 'apps', name: 'App Store', symbol: '↗', color: '#8bbcf5', bg: '#1b2d43', metric: 'downloads', region: 'NL', method: 'live', source: 'Apple RSS', url: 'https://apps.apple.com/nl/charts/iphone/top-free-apps/36', docs: 'https://rss.marketingtools.apple.com/', description: 'De actuele top 100 gratis iPhone-apps voor Nederland uit de Apple RSS-feed. Apple publiceert hier rangposities, geen download- of gebruikersaantallen. De ranghistorie begint bij de eerste meting in deze browser.', unit: 'App Store-positie' },
  { id: 'hn', name: 'Hacker News', symbol: 'Y', color: '#ffbd92', bg: '#39291f', metric: 'trending', region: 'global', method: 'live', source: 'Hacker News API', url: 'https://news.ycombinator.com/', docs: 'https://github.com/HackerNews/API', description: 'De eerste 30 beschikbare berichten uit de officiële topstories-lijst, met openbare titel en actueel puntentotaal. De volgorde komt rechtstreeks uit de Hacker News API. Punten zijn geen views; profiel- en accountgegevens worden niet opgehaald.', unit: 'punten' }
];
const platformById = Object.fromEntries(platforms.map(p => [p.id, p]));
const theme = p => `--platform-color:${p.color};--platform-bg:${p.bg}`;
const STEAM_GAMES = [
  { id: 730, title: 'Counter-Strike 2' },
  { id: 578080, title: 'PUBG: BATTLEGROUNDS' },
  { id: 570, title: 'Dota 2' },
  { id: 413150, title: 'Stardew Valley' }
];
const LIVE_PLATFORM_IDS = ['apps', 'google', 'hn', 'steam'];
const datasets = Object.fromEntries(platforms.map(p => [p.id, []]));
const sourceStates = Object.fromEntries(platforms.map(p => [p.id, { kind: p.method === 'live' ? 'idle' : 'external', at: null, message: '' }]));
let activePlatform = 'all', activeMetric = 'all', activePage = 'overview', appPlatformFilter = 'all', appLimit = 10, savedOnly = false, search = '', region = 'all', limit = 10;
let bookmarks = new Set(), savedItems = new Map(), refreshing = false, appRefreshing = false, storageBlocked = false, toastTimer;
const appHistory = new Map();
let appSortMode = 'popular', appTrendPeriod = 'day';

function applyTheme(theme) {
  document.documentElement.dataset.theme = theme;
  const isDark = theme === 'dark';
  const toggle = $('themeToggle');
  toggle.textContent = isDark ? '☀' : '☾';
  toggle.setAttribute('aria-label', `Schakel naar ${isDark ? 'licht' : 'donker'} thema`);
  toggle.title = `Schakel naar ${isDark ? 'licht' : 'donker'} thema`;
  document.querySelector('meta[name="theme-color"]').content = isDark ? '#0b1f38' : '#f4f7fb';
}
function restoreTheme() {
  let theme = 'dark';
  try {
    const savedTheme = localStorage.getItem(THEME_KEY);
    if (savedTheme === 'light' || savedTheme === 'dark') theme = savedTheme;
  } catch { storageBlocked = true; }
  applyTheme(theme);
}
function toggleTheme() {
  const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
  applyTheme(theme);
  try {
    localStorage.setItem(THEME_KEY, theme);
  } catch {
    storageBlocked = true;
    toast('Thema gewijzigd, maar je browser kan de voorkeur niet bewaren.');
  }
}

// Store a small allowlist of content fields, never raw API responses.
function cleanSignal(item, platform) {
  if (!item || typeof item.id !== 'string' || typeof item.title !== 'string' || !Number.isInteger(item.rank) || item.rank < 1 || item.rank > 100 || !Number.isFinite(Date.parse(item.observedAt))) return null;
  return {
    id: item.id.slice(0, 240), platform, title: item.title.slice(0, 500), rank: item.rank,
    value: typeof item.value === 'number' && Number.isFinite(item.value) && item.value >= 0 ? item.value : null,
    displayValue: String(item.displayValue || '').slice(0, 40), unit: platformById[platform].unit,
    metric: platformById[platform].metric, region: platformById[platform].region,
    observedAt: item.observedAt, period: String(item.period || '').slice(0, 160),
    url: safeURL(item.url), image: safeURL(item.image), sourceURL: platformById[platform].url
  };
}
function recordAppHistory(items) {
  let changed = false;
  for (const item of items) {
    if (!['apps', 'playstore'].includes(item.platform) || !Number.isInteger(item.rank)) continue;
    if (!appHistory.has(item.id) && appHistory.size >= APP_HISTORY_APP_LIMIT) {
      appHistory.delete(appHistory.keys().next().value);
    }
    const points = appHistory.get(item.id) || [];
    const sample = { observedAt: item.observedAt, rank: item.rank, value: item.value };
    const lastIndex = points.length - 1;
    if (lastIndex >= 0 && points[lastIndex].observedAt === item.observedAt) continue;
    if (lastIndex >= 0 && Date.parse(item.observedAt) - Date.parse(points[lastIndex].observedAt) < APP_HISTORY_SAMPLE_INTERVAL) {
      if (Date.parse(item.observedAt) <= Date.parse(points[lastIndex].observedAt)) continue;
      points[lastIndex] = sample;
    } else points.push(sample);
    appHistory.set(item.id, points.slice(-APP_HISTORY_LIMIT));
    changed = true;
  }
  return changed;
}
function restore() {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
    if (!saved || saved.version !== 1) return;
    bookmarks = new Set(Array.isArray(saved.bookmarks) ? saved.bookmarks.filter(id => typeof id === 'string').slice(0, 500) : []);
    if (Array.isArray(saved.savedItems)) for (const raw of saved.savedItems.slice(0, 500)) {
      if (!platformById[raw?.platform] || !bookmarks.has(raw.id)) continue;
      const obsolete = platformById[raw.platform].method === 'external' ||
        /^(Bronmeting; selectie|Afgelopen 24 uur bij raadplegen|Wereldwijde cumulatieve views; selectiebron|Wereldwijd cumulatief op Google Play|Momentopname)/.test(String(raw.period || ''));
      if (obsolete) { bookmarks.delete(raw.id); continue; }
      const item = cleanSignal(raw, raw.platform); if (item) savedItems.set(item.id, item);
    }
    for (const id of ['apps', 'google', 'hn', 'steam']) {
      if (!Array.isArray(saved.datasets?.[id])) continue;
      const cached = saved.datasets[id].slice(0, 100).map(item => cleanSignal(item, id)).filter(Boolean);
      if (cached.length) { datasets[id] = cached; sourceStates[id] = { kind: 'cached', at: cached[0].observedAt, message: 'Eerder in deze browser opgehaald' }; }
    }
    if (saved.appHistory && typeof saved.appHistory === 'object' && !Array.isArray(saved.appHistory)) {
      for (const [id, rawPoints] of Object.entries(saved.appHistory).slice(-APP_HISTORY_APP_LIMIT)) {
        if (typeof id !== 'string' || id.length > 240 || !/^apps:/.test(id) || !Array.isArray(rawPoints)) continue;
        const points = rawPoints.slice(-APP_HISTORY_LIMIT).filter(point =>
          point && Number.isFinite(Date.parse(point.observedAt)) &&
          Number.isInteger(point.rank) && point.rank >= 1 && point.rank <= 100
        ).map(point => ({
          observedAt: point.observedAt,
          rank: point.rank,
          value: typeof point.value === 'number' && Number.isFinite(point.value) && point.value >= 0 ? point.value : null
        }));
        if (points.length) appHistory.set(id, points);
      }
    }
  } catch { storageBlocked = true; }
}
function persist() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ version: 1, bookmarks: [...bookmarks], savedItems: [...savedItems.values()], datasets: { apps: datasets.apps, hn: datasets.hn, steam: datasets.steam.filter(item => !item.period.startsWith('Momentopname')) }, appHistory: Object.fromEntries(appHistory) }));
    storageBlocked = false;
  } catch { storageBlocked = true; }
}
function toast(message) {
  $('toast').textContent = message; $('toast').style.display = 'block';
  clearTimeout(toastTimer); toastTimer = setTimeout(() => $('toast').style.display = 'none', 4000);
}
function statusLabel(id) {
  const state = sourceStates[id];
  return { live: 'Live', external: 'API vereist', cached: 'Gecachet', loading: 'Laden…', error: 'Fout', idle: 'Wacht op laden' }[state.kind];
}
function statusClass(id) { return ['live', 'cached'].includes(sourceStates[id].kind) ? 'ready' : sourceStates[id].kind === 'external' ? 'external' : sourceStates[id].kind === 'error' ? 'error' : 'idle'; }
function icon(p) { return `<span class="platform-symbol" style="${theme(p)}" aria-hidden="true">${p.symbol}</span>`; }

function visibleGroups() {
  return platforms.filter(p => activePlatform === 'all' || p.id === activePlatform).map(p => {
    const available = savedOnly ? [...new Map([...savedItems.values(), ...datasets[p.id]].filter(item => item.platform === p.id).map(item => [item.id, item])).values()] : datasets[p.id];
    let rows = available.filter(item => (activeMetric === 'all' || item.metric === activeMetric) && (region === 'all' || item.region === region) && (!savedOnly || bookmarks.has(item.id)) && item.title.toLocaleLowerCase('nl').includes(search));
    rows.sort((a, b) => a.rank - b.rank);
    return { platform: p, rows: rows.slice(0, limit) };
  });
}
function renderNavigation() {
  $('sidePlatforms').innerHTML = platforms.map(p => `<button class="platform-nav ${activePlatform === p.id ? 'active' : ''}" data-platform="${p.id}" aria-pressed="${activePlatform === p.id}" style="${theme(p)}"><span class="small-symbol" aria-hidden="true">${p.symbol}</span>${p.name}</button>`).join('');
  const card = p => {
    const count = datasets[p.id].length;
    const countLabel = count ? `${count} ${count === 1 ? 'resultaat' : 'resultaten'}` : '';
    return `<button class="platform-card ${activePlatform === p.id ? 'active' : ''}" data-platform="${p.id}" aria-pressed="${activePlatform === p.id}" style="${theme(p)}">${icon(p)}<b>${p.name}</b><small class="${statusClass(p.id)}"><i></i>${statusLabel(p.id)}</small>${countLabel ? `<span class="platform-card-count">${countLabel}</span>` : ''}</button>`;
  };
  $('livePlatformCards').innerHTML = platforms.filter(p => p.method === 'live').map(card).join('');
  $('otherPlatformCards').innerHTML = platforms.filter(p => p.method === 'external').map(card).join('');
  $('connectedSourcesCount').textContent = LIVE_PLATFORM_IDS.length;
  $('navOverview').classList.toggle('active', activePage === 'overview' && !savedOnly);
  $('navApps').classList.toggle('active', activePage === 'apps');
  $('navSaved').classList.toggle('active', activePage === 'overview' && savedOnly);
  $('pageBreadcrumb').textContent = activePage === 'apps' ? 'App-ranglijsten' : savedOnly ? 'Bewaard' : 'Overzicht';
  $('overviewPage').hidden = activePage !== 'overview';
  $('appsPage').hidden = activePage !== 'apps';
  $('platformCards').innerHTML = platforms.map(p => {
    const count = datasets[p.id].length;
    return `<button class="platform-card ${activePlatform === p.id ? 'active' : ''}" data-platform="${p.id}" aria-pressed="${activePlatform === p.id}" style="${theme(p)}">${icon(p)}<b>${p.name}</b><div class="platform-card-meta"><small class="${statusClass(p.id)}"><i></i>${statusLabel(p.id)}</small><span class="platform-card-count">${count} ${count === 1 ? 'signaal' : 'signalen'}</span></div></button>`;
  }).join('');
  $('navOverview').classList.toggle('active', !savedOnly);
  $('navSaved').classList.toggle('active', savedOnly);
  $('savedCount').textContent = bookmarks.size;
  document.querySelectorAll('[data-metric]').forEach(button => {
    const active = button.dataset.metric === activeMetric;
    button.classList.toggle('active', active); button.setAttribute('aria-pressed', String(active));
  });
}
function render() {
  renderNavigation();
  const total = Object.values(datasets).reduce((sum, list) => sum + list.length, 0);
  $('signalCount').innerHTML = `${total}<span>in alle lijsten</span>`;
  const availableCount = LIVE_PLATFORM_IDS.filter(id => ['live', 'cached'].includes(sourceStates[id].kind)).length;
  $('readyCount').innerHTML = `${availableCount}<span>beschikbaar</span>`;
  $('heroLiveCount').textContent = availableCount;
  $('heroLiveIndicator').classList.toggle('is-live', availableCount > 0);
  const selected = activePlatform === 'all' ? null : platformById[activePlatform];
  const failures = LIVE_PLATFORM_IDS.filter(id => sourceStates[id].message);
  $('sourceNotice').textContent = storageBlocked
    ? 'Browseropslag is geblokkeerd. Bewaarde signalen en apphistorie kunnen na sluiten verdwijnen.'
    : location.protocol === 'file:'
      ? 'Start de PHP-server met php -S 127.0.0.1:4173 en open daarna http://127.0.0.1:4173 om live gegevens te laden.'
    : selected?.method === 'external'
      ? selected.emptyText
      : selected && sourceStates[selected.id].message
        ? sourceStates[selected.id].message
        : failures.length
          ? `Bronproblemen — ${failures.map(id => `${platformById[id].name}: ${sourceStates[id].message}`).join(' · ')}`
          : `${availableCount} van ${LIVE_PLATFORM_IDS.length} live bronnen beschikbaar. De andere platforms vragen toegang of publiceren geen openbare ranglijst.`;
  $('resultsTitle').textContent = savedOnly ? 'Bewaarde signalen' : activePlatform === 'all' ? 'Alle platforms' : platformById[activePlatform].name;
  const groups = visibleGroups(); const count = groups.reduce((sum, g) => sum + g.rows.length, 0);
  $('resultsCount').textContent = `${count} ${count === 1 ? 'signaal' : 'signalen'}`;
  if (activePlatform !== 'all' && platformById[activePlatform].method === 'external' && !savedOnly) {
    $('results').innerHTML = externalCard(platformById[activePlatform]);
  } else if (!count) {
    const selected = activePlatform !== 'all' ? platformById[activePlatform] : null;
    const loading = selected ? sourceStates[selected.id].kind === 'loading' : LIVE_PLATFORM_IDS.some(id => sourceStates[id].kind === 'loading');
    $('results').innerHTML = `<div class="empty">${loading ? 'Live bronnen worden opgehaald…' : savedOnly ? 'Je hebt hier nog geen signalen bewaard. Klik op ☆ bij een onderwerp.' : 'Nog geen gegevens beschikbaar. Gebruik Vernieuw bronnen om de live APIs opnieuw te proberen.'}<br><button class="border-button" data-reset>Wis filters</button>${selected && selected.method === 'live' ? `<a class="border-button" href="${selected.url}" target="_blank" rel="noopener noreferrer">Open ${selected.source} ↗</a>` : ''}</div>`;
  } else {
    $('results').innerHTML = groups.filter(g => g.rows.length).map(({ platform: p, rows }) => `<section class="source-group"><div class="group-head"><span><b style="color:${p.color}">${p.symbol} &nbsp;${p.name}</b><span>· ${p.region === 'NL' ? 'Nederland' : 'Wereldwijd'} · ${['playstore', 'steam'].includes(p.id) ? 'volgorde binnen selectie' : 'bronvolgorde'}</span></span><a href="${p.url}" target="_blank" rel="noopener noreferrer">${p.source} ↗</a></div>${rows.map(item => renderSignal(item, p)).join('')}</section>`).join('');
  }
  $('export').disabled = count === 0;
  renderAppsPage();
}
function renderAppsPage() {
  const appPlatforms = appSortMode === 'rising' ? ['apps'] : ['apps', 'playstore'].filter(id => appPlatformFilter === 'all' || id === appPlatformFilter);
  const groups = appPlatforms.map(id => {
    const p = platformById[id];
    const rows = [...datasets[id]];
    if (appSortMode === 'rising' && id === 'apps') {
      rows.sort((a, b) => {
        const aTrend = appMovement(a), bTrend = appMovement(b);
        if (aTrend === null && bTrend !== null) return 1;
        if (bTrend === null && aTrend !== null) return -1;
        return (bTrend ?? 0) - (aTrend ?? 0) || a.rank - b.rank;
      });
    } else rows.sort((a, b) => a.rank - b.rank);
    return { platform: p, rows };
  }).filter(group => group.rows.length);
  const visibleGroups = groups.map(group => ({ ...group, rows: group.rows.slice(0, appLimit) }));
  const count = visibleGroups.reduce((sum, group) => sum + group.rows.length, 0);
  $('appsPlatform').value = appSortMode === 'rising' ? 'apps' : appPlatformFilter;
  $('appsListTitle').textContent = appSortMode === 'rising' ? `Sterkste stijgers · ${periodName(appTrendPeriod)}` : 'Populaire apps';
  $('appsResultsCount').textContent = `${count} ${count === 1 ? 'app' : 'apps'}`;
  const appleDate = sourceStates.apps.at ? shortDate(sourceStates.apps.at) : null;
  $('appsNotice').textContent = appSortMode === 'rising'
    ? `Top ${appLimit} gratis iPhone-apps gesorteerd op stijging in App Store-rang. Metingen: ${appleDate || 'nog niet opgehaald'}. Ranghistorie wordt maximaal eenmaal per dag opgeslagen; gebruikersaantallen zijn niet beschikbaar.${sourceStates.apps.message ? ` ${sourceStates.apps.message}` : ''}`
    : `App Store: ${statusLabel('apps')}${appleDate ? ` · ${appleDate}` : ''}. Google Play heeft geen openbare downloadranglijst; de Play Console API is alleen beschikbaar voor app-eigenaren.${sourceStates.apps.message ? ` ${sourceStates.apps.message}` : ''}`;
  if (!count) {
    const loading = appPlatforms.some(id => sourceStates[id].kind === 'loading');
    $('appsResults').innerHTML = appPlatformFilter === 'playstore' && appSortMode !== 'rising'
      ? externalCard(platformById.playstore)
      : `<div class="empty">${loading ? 'App-ranglijsten worden opgehaald…' : 'Er zijn momenteel geen apps beschikbaar voor deze selectie.'}</div>`;
    return;
  }
  $('appsResults').innerHTML = visibleGroups.map(({ platform: p, rows }) => `<section class="source-group"><div class="group-head"><span><b style="color:${p.color}">${p.symbol} &nbsp;${p.name}</b><span>· ${p.id === 'apps' ? 'Nederland · top gratis iPhone-apps' : 'Wereldwijd · cumulatieve downloadondergrenzen'}</span></span><a href="${p.url}" target="_blank" rel="noopener noreferrer">${p.source} ↗</a></div>${rows.map(item => renderSignal(item, p, true)).join('')}</section>`).join('');
}
function periodName(period) {
  return { day: 'dag', week: 'week', month: 'maand', year: 'jaar' }[period];
}
function appHistoryPoints(item) {
  const cutoff = Date.now() - APP_PERIODS[appTrendPeriod];
  const history = [...(appHistory.get(item.id) || [])].sort((a, b) => Date.parse(a.observedAt) - Date.parse(b.observedAt));
  const before = history.filter(point => Date.parse(point.observedAt) < cutoff);
  const inRange = history.filter(point => Date.parse(point.observedAt) >= cutoff);
  if (!inRange.length) return [];
  return [...(before.length ? [before[before.length - 1]] : []), ...inRange];
}
function appMovement(item) {
  if (item.platform !== 'apps') return null;
  const points = appHistoryPoints(item);
  if (points.length < 2) return null;
  return points[0].rank - points[points.length - 1].rank;
}
function renderAppHistory(item) {
  const points = appHistoryPoints(item);
  const isRank = item.platform === 'apps';
  const values = points.map(point => isRank ? point.rank : point.value).filter(Number.isFinite);
  const movement = points.length > 1 && values.length > 1
    ? (isRank ? points[0].rank - points[points.length - 1].rank : points[points.length - 1].value - points[0].value)
    : null;
  let trendText = 'Nog geen historische vergelijking';
  if (movement !== null && isRank) {
    const direction = movement > 0 ? '↑' : movement < 0 ? '↓' : '↔';
    const from = shortDate(points[0].observedAt);
    trendText = `${direction} ${Math.abs(movement)} ${Math.abs(movement) === 1 ? 'plek' : 'plekken'} sinds ${from}`;
  } else if (movement !== null && !isRank) {
    trendText = movement > 0 ? 'Downloadgrens verhoogd' : movement < 0 ? 'Downloadgrens verlaagd' : 'Downloadgrens ongewijzigd';
  }
  if (!values.length) {
    return `<div class="app-history"><div><b>${isRank ? 'Ranghistorie' : 'Downloadgrenshistorie'}</b><span>${escapeHTML(trendText)}</span></div><p>Nog geen meetpunten voor deze app.</p></div>`;
  }
  const min = Math.min(...values), max = Math.max(...values), width = 300, height = 50, pad = 5;
  const range = max - min || 1;
  const coordinates = points.map((point, index) => {
    const value = isRank ? point.rank : point.value;
    if (!Number.isFinite(value)) return null;
    const x = points.length === 1 ? width / 2 : pad + index / (points.length - 1) * (width - pad * 2);
    const progress = isRank ? (max - value) / range : (value - min) / range;
    const y = height - pad - progress * (height - pad * 2);
    return { x, y, point, value };
  }).filter(Boolean);
  const line = coordinates.map(point => `${point.x.toFixed(1)},${point.y.toFixed(1)}`).join(' ');
  const dots = coordinates.map(({ x, y, point, value }) => `<circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="2.5"><title>${escapeHTML(fullDate(point.observedAt))}: ${isRank ? `rang #${value}` : escapeHTML(formatNumber(value))}</title></circle>`).join('');
  const latest = coordinates[coordinates.length - 1];
  const latestLabel = latest ? (isRank ? `#${latest.value}` : escapeHTML(formatNumber(latest.value))) : '—';
  return `<div class="app-history"><div class="app-history-heading"><b>${isRank ? 'App Store-rang' : 'Google Play-downloadgrens'}</b><span>${escapeHTML(trendText)}</span></div><div class="app-history-chart"><svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${isRank ? 'Grafiek van App Store-rangposities' : 'Grafiek van cumulatieve Google Play-downloadgrenzen'}">${coordinates.length > 1 ? `<polyline points="${line}"></polyline>` : ''}${dots}</svg><strong>${latestLabel}</strong></div><small>${points.length} ${points.length === 1 ? 'meting' : 'metingen'} · ${escapeHTML(periodName(appTrendPeriod))}</small></div>`;
}
function externalCard(p) {
  return `<div class="external-state" style="${theme(p)}">${icon(p)}<div><h3>${p.emptyTitle}</h3><p>${p.emptyText}</p><a class="border-button" href="${p.url}" target="_blank" rel="noopener noreferrer">Open ${p.source} ↗</a><p class="external-note">Geen account wordt gekoppeld aan Trendradar. Je opent een externe website.</p></div></div>`;
}
function renderSignal(item, p, withHistory = false) {
  const saved = bookmarks.has(item.id);
  const image = safeURL(item.image);
  const imageMarkup = image ? `<img class="signal-image" src="${escapeHTML(image)}" alt="" loading="lazy">` : '';
  const tag = p.id === 'steam' ? 'Game · live spelers' : p.id === 'apps' ? 'Gratis iPhone-app' : p.id === 'google' ? 'Zoektrend' : p.id === 'hn' ? 'Hacker News-artikel' : 'Trendonderwerp';
  return `<article class="signal"><span class="source-rank" title="Oorspronkelijke positie in de bron">${String(item.rank).padStart(2, '0')}</span><div><button class="signal-title ${image ? 'app-title' : ''}" data-detail="${escapeHTML(item.id)}">${imageMarkup}<span>${escapeHTML(item.title)}</span></button><div class="signal-meta"><span class="tag">${tag}</span><span>${item.region === 'NL' ? 'Nederland' : 'Wereldwijd'}</span></div></div><div class="metric">${escapeHTML(item.displayValue)}<small>${escapeHTML(item.unit)}</small></div><div class="availability ${statusClass(p.id)}"><i></i> ${statusLabel(p.id)}<small>Gemeten ${shortDate(item.observedAt)}</small></div><button class="save-button ${saved ? 'saved' : ''}" data-save="${escapeHTML(item.id)}" aria-label="${saved ? 'Verwijder' : 'Bewaar'} ${escapeHTML(item.title)}" aria-pressed="${saved}">${saved ? '★' : '☆'}</button>${withHistory ? renderAppHistory(item) : ''}</article>`;
}
function findSignal(id) { return Object.values(datasets).flat().find(item => item.id === id) || savedItems.get(id); }
function showDetail(id) {
  const item = findSignal(id); if (!item) return; const p = platformById[item.platform];
  $('detailBody').innerHTML = `<span class="eyebrow small">${p.name.toUpperCase()} · ${statusLabel(p.id).toUpperCase()}</span><h2 class="detail-title">${escapeHTML(item.title)}</h2><div class="detail-metric">${escapeHTML(item.displayValue)}</div><p class="detail-text">${escapeHTML(item.unit)}<br>${escapeHTML(item.period)}<br>Bronpositie: #${item.rank}<br>${p.id === 'x' ? 'Bronmeting' : 'Geraadpleegd'}: ${fullDate(item.observedAt)} (Nederlandse tijd)</p><p class="detail-text">${escapeHTML(p.description)}</p><div class="detail-actions"><a class="primary-button" href="${escapeHTML(safeURL(item.url) || p.url)}" target="_blank" rel="noopener noreferrer">Open onderwerp ↗</a><a class="border-button" href="${p.url}" target="_blank" rel="noopener noreferrer">Bron bekijken ↗</a></div>`;
  $('detailDialog').showModal();
}
function showSources() {
  $('sourceDetails').innerHTML = platforms.map(p => `<div class="source-detail"><b style="color:${p.color}">${p.symbol} &nbsp;${p.name}</b><span class="status-detail">${statusLabel(p.id)}${sourceStates[p.id].at ? ' · ' + fullDate(sourceStates[p.id].at) : ''}</span><p>${p.description}</p>${sourceStates[p.id].message ? `<p>${escapeHTML(sourceStates[p.id].message)}</p>` : ''}<a href="${p.url}" target="_blank" rel="noopener noreferrer">Bron ↗</a> &nbsp; <a href="${p.docs}" target="_blank" rel="noopener noreferrer">Documentatie ↗</a></div>`).join('');
  $('sourcesDialog').showModal();
}

async function getJSON(url, timeout = 9000) {
  const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), timeout);
  try {
    const response = await fetch(url, { signal: controller.signal, credentials: 'omit', referrerPolicy: 'no-referrer' });
    if (!response.ok && url.startsWith('api.php?')) {
      const error = await response.json().catch(() => ({}));
      throw new Error(error.error || `PHP API gaf HTTP ${response.status}`);
    }
    if (!response.ok) throw new Error(`Bron gaf HTTP ${response.status}`);
    return await response.json();
  } finally { clearTimeout(timer); }
}
function parseApple(data, observedAt) {
  let list;
  if (Array.isArray(data.feed?.entry)) list = data.feed.entry.map(a => ({ key: String(a.id?.attributes?.['im:id'] || ''), title: a['im:name']?.label, url: a.id?.label, image: a['im:image']?.slice(-1)[0]?.label }));
  else if (Array.isArray(data.feed?.results)) list = data.feed.results.map(a => ({ key: String(a.id || ''), title: a.name, url: a.url, image: a.artworkUrl512 || a.artworkUrl100 }));
  else throw new Error('Geen Apple-ranglijst');
  if (!list.length || list.some(a => !a.key || typeof a.title !== 'string')) throw new Error('Ongeldige Apple-ranglijst');
  return list.slice(0, 100).map((a, index) => ({ id: `apps:${a.key}`, platform: 'apps', title: a.title, rank: index + 1, value: null, displayValue: `#${index + 1}`, unit: platformById.apps.unit, region: 'NL', metric: 'downloads', observedAt, period: 'Top gratis iPhone-apps; geen downloadaantallen', url: safeURL(a.url), sourceURL: platformById.apps.url }));
}
function parseGoogleTrends(xml, fetchedAt) {
  const document = new DOMParser().parseFromString(xml, 'application/xml');
  if (document.querySelector('parsererror')) throw new Error('Google Trends stuurde ongeldige RSS-data.');
  const elements = [...document.getElementsByTagName('item')];
  if (!elements.length) throw new Error('De Google Trends RSS-feed bevat geen onderwerpen.');
  const rows = elements.slice(0, 100).map((element, index) => {
    const read = name => element.getElementsByTagNameNS('*', name)[0]?.textContent?.trim() || '';
    const title = element.getElementsByTagName('title')[0]?.textContent?.trim();
    const traffic = read('approx_traffic');
    const publishedAt = Date.parse(element.getElementsByTagName('pubDate')[0]?.textContent || '');
    if (!title) return null;
    return {
      id: `google:${title.toLocaleLowerCase('nl')}`,
      platform: 'google',
      title,
      rank: index + 1,
      value: null,
      displayValue: traffic || 'Trending',
      unit: platformById.google.unit,
      region: 'NL',
      metric: 'trending',
      observedAt: Number.isFinite(publishedAt) ? new Date(publishedAt).toISOString() : fetchedAt,
      period: 'Google Trending Now; verkeersklasse bij publicatie, geen exact zoekvolume',
      url: `https://trends.google.com/trends/explore?geo=NL&q=${encodeURIComponent(title)}`,
      image: read('picture'),
      sourceURL: platformById.google.docs
    };
  }).filter(Boolean);
  if (!rows.length) throw new Error('De Google Trends RSS-feed bevat geen geldige onderwerpen.');
  return rows;
}
function parseHN(item, rank, observedAt) {
  if (!item || !Number.isInteger(item.id) || item.deleted || item.dead || item.type !== 'story' || typeof item.title !== 'string') return null;
  const score = typeof item.score === 'number' && Number.isFinite(item.score) && item.score >= 0 ? item.score : null;
  return { id: `hn:${item.id}`, platform: 'hn', title: item.title, rank, value: score, displayValue: score === null ? '—' : formatNumber(score), unit: platformById.hn.unit, region: 'global', metric: 'trending', observedAt, period: 'Topstories-positie en huidig puntentotaal', url: `https://news.ycombinator.com/item?id=${encodeURIComponent(item.id)}`, sourceURL: platformById.hn.url };
}
function parseSteam(data, game, observedAt) {
  const count = data?.response?.player_count;
  if (data?.response?.result !== 1 || !Number.isSafeInteger(count) || count < 0) throw new Error('Geen geldig openbaar Steam-spelerstotaal.');
  return {
    id: 'steam:' + game.id, platform: 'steam', title: game.title, rank: 1, value: count,
    displayValue: new Intl.NumberFormat('nl-NL').format(count), unit: platformById.steam.unit,
    region: 'global', metric: 'activity', observedAt, period: 'Live aantal gelijktijdige spelers; geen downloads of unieke daggebruikers',
    url: 'https://store.steampowered.com/app/' + game.id, sourceURL: platformById.steam.url
  };
}
function applySourceResult(id, source, items) {
  const previous = sourceStates[id];
  if (Array.isArray(items) && items.length) {
    datasets[id] = items;
    if (id === 'apps') recordAppHistory(items);
    sourceStates[id] = {
      kind: source.status === 'live' ? 'live' : 'cached',
      at: source.fetchedAt || items[0].observedAt,
      message: source.error || ''
    };
  } else {
    sourceStates[id] = {
      kind: datasets[id].length ? 'cached' : 'error',
      at: datasets[id][0]?.observedAt || previous.at,
      message: source.error || `De bron ${platformById[id].name} gaf geen gegevens terug.`
    };
  }
}

function applyParsedSource(id, source, parse) {
  try {
    applySourceResult(id, source, parse());
  } catch (error) {
    applySourceResult(id, { ...source, status: 'error', error: `${platformById[id].name}: ${error.message}` }, []);
  }
}

function applyDashboardBundle(bundle) {
  if (!bundle || typeof bundle.sources !== 'object') throw new Error('De centrale API stuurde een ongeldig antwoord.');
  const { apps, google, hn, steam } = bundle.sources;
  const appTime = apps?.fetchedAt || bundle.updatedAt || new Date().toISOString();
  const googleTime = google?.fetchedAt || bundle.updatedAt || new Date().toISOString();
  const hnTime = hn?.fetchedAt || bundle.updatedAt || new Date().toISOString();
  const steamTime = steam?.fetchedAt || bundle.updatedAt || new Date().toISOString();

  applyParsedSource('apps', apps || {}, () => apps?.data ? parseApple(apps.data, appTime) : []);
  applyParsedSource('google', google || {}, () => typeof google?.data === 'string' ? parseGoogleTrends(google.data, googleTime) : []);

  const hnItems = Array.isArray(hn?.data?.items) ? hn.data.items : [];
  const hnRanks = new Map((Array.isArray(hn?.data?.ids) ? hn.data.ids : []).map((id, index) => [id, index + 1]));
  const parsedHN = hnItems.map(item => parseHN(item, hnRanks.get(item.id) || 0, hnTime)).filter(Boolean);
  applyParsedSource('hn', hn || {}, () => parsedHN);

  applyParsedSource('steam', steam || {}, () => (Array.isArray(steam?.data) ? steam.data : [])
    .map(game => parseSteam(game.data, { id: game.id, title: game.title }, steamTime))
    .filter(Boolean)
    .sort((a, b) => b.value - a.value)
    .map((item, index) => ({ ...item, rank: index + 1 })));
  persist();
}

async function refreshSources(force = false) {
  if (refreshing || appRefreshing) return;
  refreshing = true;
  for (const button of [$('refresh'), $('appsRefresh')]) { button.disabled = true; button.textContent = '↻ Bronnen laden…'; }
  for (const id of LIVE_PLATFORM_IDS) sourceStates[id] = { ...sourceStates[id], kind: 'loading', message: '' };
  render();
  try {
    const bundle = await getJSON(apiURL('all', force ? { refresh: '1' } : {}), 45000);
    applyDashboardBundle(bundle);
  } catch (error) {
    for (const id of LIVE_PLATFORM_IDS) {
      sourceStates[id] = {
        kind: datasets[id].length ? 'cached' : 'error',
        at: datasets[id][0]?.observedAt || sourceStates[id].at,
        message: String(error.message)
      };
    }
  }
  refreshing = false;
  $('refresh').disabled = false; $('refresh').textContent = '↻ Vernieuw bronnen';
  $('appsRefresh').disabled = false; $('appsRefresh').textContent = '↻ Vernieuw ranglijsten';
  render();
  if (force) toast('De centrale API heeft de bronnen bijgewerkt waar bereikbaar.');
}
async function refreshAppRanking() {
  return refreshSources(true);
}
function resetFilters() {
  activePlatform = 'all'; activeMetric = 'all'; activePage = 'overview'; savedOnly = false; search = ''; region = 'all'; $('search').value = ''; $('region').value = 'all'; render();
}
document.addEventListener('click', event => {
  const platform = event.target.closest('[data-platform]');
  if (platform) { activePlatform = activePlatform === platform.dataset.platform ? 'all' : platform.dataset.platform; activeMetric = 'all'; activePage = 'overview'; savedOnly = false; render(); return; }
  const save = event.target.closest('[data-save]');
  if (save) { const id = save.dataset.save; if (bookmarks.has(id)) { bookmarks.delete(id); savedItems.delete(id); } else { bookmarks.add(id); const item = findSignal(id); if (item) savedItems.set(id, item); } persist(); render(); if (storageBlocked) toast('Opslaan is geblokkeerd door je browser.'); return; }
  const detail = event.target.closest('[data-detail]'); if (detail) { showDetail(detail.dataset.detail); return; }
  if (event.target.closest('[data-open-sources]')) showSources();
  if (event.target.closest('[data-reset]')) resetFilters();
});
document.querySelectorAll('[data-metric]').forEach(button => button.onclick = () => { activeMetric = button.dataset.metric; render(); });
$('search').oninput = event => { search = event.target.value.toLocaleLowerCase('nl').trim(); render(); };
$('region').onchange = event => { region = event.target.value; render(); };
$('limit').onchange = event => { limit = Number(event.target.value); render(); };
$('navOverview').onclick = () => resetFilters();
$('navApps').onclick = () => { activePage = 'apps'; savedOnly = false; render(); };
$('navSaved').onclick = () => { activePage = 'overview'; savedOnly = true; activePlatform = 'all'; activeMetric = 'all'; search = ''; region = 'all'; $('search').value = ''; $('region').value = 'all'; render(); };
$('appsLimit').onchange = event => { appLimit = Number(event.target.value); renderAppsPage(); };
$('appsSort').onchange = event => {
  appSortMode = event.target.value;
  if (appSortMode === 'rising') {
    appPlatformFilter = 'apps';
    appLimit = 100;
    $('appsPlatform').value = 'apps';
    $('appsLimit').value = '100';
  }
  renderAppsPage();
};
$('appsPeriod').onchange = event => { appTrendPeriod = event.target.value; renderAppsPage(); };
$('appsPlatform').addEventListener('change', event => {
  if (event.target.value !== 'apps' && appSortMode === 'rising') {
    appSortMode = 'popular';
    $('appsSort').value = 'popular';
  }
  appPlatformFilter = event.target.value;
  renderAppsPage();
});
$('refresh').onclick = () => refreshSources(true);
$('appsRefresh').onclick = () => refreshAppRanking();
$('themeToggle').onclick = toggleTheme;
$('closeSources').onclick = () => $('sourcesDialog').close();
$('closeDetail').onclick = () => $('detailDialog').close();
for (const id of ['sourcesDialog', 'detailDialog']) $(id).onclick = event => {
  if (event.target !== $(id)) return; const box = $(id).getBoundingClientRect();
  if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) $(id).close();
};
$('export').onclick = () => {
  const rows = visibleGroups().flatMap(g => g.rows); if (!rows.length) return;
  const cell = value => { let s = String(value ?? ''); if (/^[\s]*[=+\-@]/.test(s)) s = "'" + s; return '"' + s.replace(/"/g, '""') + '"'; };
  const lines = [['Platform', 'Onderwerp', 'Bronpositie', 'Getoonde waarde', 'Eenheid', 'Regio selectie', 'Periode', 'Geraadpleegd of bronmeting', 'Status', 'Bron'].map(cell).join(';')];
  for (const item of rows) { const p = platformById[item.platform]; lines.push([p.name, item.title, item.rank, item.displayValue, item.unit, item.region, item.period, item.observedAt, statusLabel(p.id), p.url].map(cell).join(';')); }
  const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' }); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = 'trendradar-openbare-signalen.csv'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 5000); toast('De zichtbare signalen zijn geëxporteerd met bron en meetmoment.');
};
restoreTheme(); restore();
if (location.protocol === 'file:') {
  for (const id of LIVE_PLATFORM_IDS) {
    sourceStates[id] = { kind: 'error', at: null, message: 'Start de PHP-server met php -S 127.0.0.1:4173.' };
  }
}
render();
if (location.protocol !== 'file:') {
  refreshSources();
  setInterval(refreshAppRanking, APP_HISTORY_SAMPLE_INTERVAL);
}
