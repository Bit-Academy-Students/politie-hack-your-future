'use strict';

const http = require('node:http');
const path = require('node:path');
const fs = require('node:fs/promises');

const root = __dirname;
const port = Number(process.env.PORT || 4173);
const steamAppIds = new Set(['730', '578080', '570', '413150']);
const assets = new Map([
  ['/', ['index.html', 'text/html; charset=utf-8']],
  ['/index.html', ['index.html', 'text/html; charset=utf-8']],
  ['/app.js', ['app.js', 'text/javascript; charset=utf-8']],
  ['/styles.css', ['styles.css', 'text/css; charset=utf-8']]
]);
const baseHeaders = {
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'Cache-Control': 'no-store'
};

function send(response, status, contentType, body) {
  response.writeHead(status, { ...baseHeaders, 'Content-Type': contentType });
  response.end(body);
}

async function fetchSource(response, url, accept) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 12000);
  try {
    const upstream = await fetch(url, {
      headers: { Accept: accept },
      signal: controller.signal,
      redirect: 'follow'
    });
    const body = await upstream.text();
    if (!upstream.ok) {
      send(response, 502, 'application/json; charset=utf-8', JSON.stringify({
        error: `De bron antwoordde met HTTP ${upstream.status}.`
      }));
      return;
    }
    if (body.length > 4_000_000) {
      send(response, 502, 'application/json; charset=utf-8', JSON.stringify({ error: 'Bronantwoord is te groot.' }));
      return;
    }
    send(response, 200, accept.includes('xml') ? 'application/xml; charset=utf-8' : 'application/json; charset=utf-8', body);
  } catch (error) {
    const message = error.name === 'AbortError' ? 'De bron reageerde niet op tijd.' : 'De bron is niet bereikbaar.';
    send(response, 502, 'application/json; charset=utf-8', JSON.stringify({ error: message }));
  } finally {
    clearTimeout(timeout);
  }
}

const server = http.createServer(async (request, response) => {
  if (request.method !== 'GET') {
    send(response, 405, 'application/json; charset=utf-8', JSON.stringify({ error: 'Alleen GET-verzoeken zijn toegestaan.' }));
    return;
  }

  let url;
  try {
    url = new URL(request.url, 'http://127.0.0.1');
  } catch {
    send(response, 400, 'application/json; charset=utf-8', JSON.stringify({ error: 'Ongeldig verzoek.' }));
    return;
  }

  if (url.pathname === '/api/apps') {
    const feeds = [
      'https://itunes.apple.com/nl/rss/topfreeapplications/limit=100/json',
      'https://rss.marketingtools.apple.com/api/v2/nl/apps/top-free/100/apps.json'
    ];
    for (const feed of feeds) {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 9000);
      try {
        const upstream = await fetch(feed, { headers: { Accept: 'application/json' }, signal: controller.signal });
        if (upstream.ok) {
          send(response, 200, 'application/json; charset=utf-8', await upstream.text());
          return;
        }
      } catch {
        continue;
      } finally {
        clearTimeout(timeout);
      }
    }
    send(response, 502, 'application/json; charset=utf-8', JSON.stringify({ error: 'Apple App Store-feed is niet bereikbaar.' }));
    return;
  }

  if (url.pathname === '/api/google-trends') {
    await fetchSource(response, 'https://trends.google.com/trending/rss?geo=NL', 'application/rss+xml, application/xml');
    return;
  }
  if (url.pathname === '/api/hacker-news/topstories') {
    await fetchSource(response, 'https://hacker-news.firebaseio.com/v0/topstories.json', 'application/json');
    return;
  }

  const story = url.pathname.match(/^\/api\/hacker-news\/item\/(\d+)$/);
  if (story) {
    await fetchSource(response, `https://hacker-news.firebaseio.com/v0/item/${story[1]}.json`, 'application/json');
    return;
  }

  if (url.pathname === '/api/steam/current-players') {
    const appIds = url.searchParams.getAll('appid');
    if (appIds.length !== 1 || !steamAppIds.has(appIds[0])) {
      send(response, 400, 'application/json; charset=utf-8', JSON.stringify({ error: 'Onbekende Steam-app.' }));
      return;
    }
    await fetchSource(
      response,
      `https://api.steampowered.com/ISteamUserStats/GetNumberOfCurrentPlayers/v1/?appid=${appIds[0]}`,
      'application/json'
    );
    return;
  }

  const asset = assets.get(url.pathname);
  if (!asset) {
    send(response, 404, 'text/plain; charset=utf-8', 'Niet gevonden.');
    return;
  }

  try {
    const body = await fs.readFile(path.join(root, asset[0]));
    send(response, 200, asset[1], body);
  } catch (error) {
    console.error(`Asset laden mislukt (${asset[0]}):`, error.message);
    send(response, 500, 'text/plain; charset=utf-8', 'Bestand kon niet worden geladen.');
  }
});

server.listen(port, '127.0.0.1', () => {
  console.log(`Trendradar draait op http://127.0.0.1:${port}`);
});

server.on('error', error => {
  console.error('Trendradar-server kon niet starten:', error.message);
  process.exitCode = 1;
});
