<?php
declare(strict_types=1);

const MAX_RESPONSE_BYTES = 4_000_000;
const STEAM_GAMES = [
    '730' => 'Counter-Strike 2',
    '578080' => 'PUBG: BATTLEGROUNDS',
    '570' => 'Dota 2',
    '413150' => 'Stardew Valley',
];

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): never
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        http_response_code(500);
        echo '{"error":"API-antwoord kon niet als JSON worden gecodeerd."}';
        exit;
    }
    http_response_code($status);
    echo $body;
    exit;
}

function database(): PDO
{
    static $db;
    if ($db instanceof PDO) {
        return $db;
    }

    $path = getenv('TRENDRADAR_DB_PATH') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'trendradar.sqlite';
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('De map voor de lokale API-database kan niet worden aangemaakt.');
    }

    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $schemaPath = __DIR__ . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'schema.sql';
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        throw new RuntimeException('Het SQL-schema kon niet worden gelezen.');
    }
    $db->exec($schema);

    $legacyPaths = [
        __DIR__ . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'trendradar.sqlite',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'trendradar.sqlite',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'trendradar.sqlite',
    ];
    if ((int) $db->query('SELECT COUNT(*) FROM api_cache')->fetchColumn() === 0) {
        $insert = $db->prepare(
            'INSERT OR IGNORE INTO api_cache (cache_key, body, fetched_at, expires_at)
             VALUES (:cache_key, :body, :fetched_at, :expires_at)'
        );
        foreach (array_unique($legacyPaths) as $legacyPath) {
            if (realpath($legacyPath) === realpath($path) || !is_file($legacyPath)) {
                continue;
            }
            $legacy = new PDO('sqlite:' . $legacyPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $hasCache = $legacy->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'api_cache'")->fetchColumn();
            if ($hasCache) {
                $db->beginTransaction();
                foreach ($legacy->query('SELECT cache_key, body, fetched_at, expires_at FROM api_cache') as $row) {
                    $insert->execute($row);
                }
                $db->commit();
            }
        }
    }
    return $db;
}

function cached(string $key): ?array
{
    $statement = database()->prepare('SELECT body, fetched_at, expires_at FROM api_cache WHERE cache_key = :key');
    $statement->execute(['key' => $key]);
    $row = $statement->fetch();
    return $row ?: null;
}

function saveCache(string $key, string $body, int $ttl): array
{
    $fetchedAt = gmdate('c');
    $statement = database()->prepare(
        'INSERT INTO api_cache (cache_key, body, fetched_at, expires_at)
         VALUES (:key, :body, :fetched_at, :expires_at)
         ON CONFLICT(cache_key) DO UPDATE SET
            body = excluded.body,
            fetched_at = excluded.fetched_at,
            expires_at = excluded.expires_at'
    );
    $statement->execute([
        'key' => $key,
        'body' => $body,
        'fetched_at' => $fetchedAt,
        'expires_at' => time() + $ttl,
    ]);
    return ['body' => $body, 'fetched_at' => $fetchedAt, 'expires_at' => time() + $ttl];
}

function fetchMany(array $urls, string $accept): array
{
    $multi = curl_multi_init();
    $handles = [];
    $bodies = [];
    $errors = [];

    foreach ($urls as $key => $url) {
        $bodies[$key] = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ["Accept: {$accept}", 'User-Agent: Trendradar/1.0'],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$bodies, $key): int {
                if (strlen($bodies[$key]) + strlen($chunk) > MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $bodies[$key] .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$key] = $handle;
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1) {
                usleep(100_000);
            }
        }
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $key => $handle) {
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if (strlen($bodies[$key]) > MAX_RESPONSE_BYTES || $error !== '') {
            $errors[$key] = $error !== '' ? $error : 'Antwoord van bron is te groot.';
        } elseif ($code < 200 || $code >= 300) {
            $errors[$key] = "Bron antwoordde met HTTP {$code}.";
        } else {
            $results[$key] = $bodies[$key];
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    return ['results' => $results, 'errors' => $errors];
}

function readSource(string $key, string $body, ?string $error, int $ttl, bool $refresh): array
{
    $old = cached($key);
    if (!$refresh && $old !== null && (int) $old['expires_at'] > time()) {
        return ['body' => $old['body'], 'fetchedAt' => $old['fetched_at'], 'status' => 'cached', 'error' => null];
    }

    if ($error === null) {
        $saved = saveCache($key, $body, $ttl);
        return ['body' => $saved['body'], 'fetchedAt' => $saved['fetched_at'], 'status' => 'live', 'error' => null];
    }

    if ($old !== null) {
        return ['body' => $old['body'], 'fetchedAt' => $old['fetched_at'], 'status' => 'cached', 'error' => $error];
    }
    return ['body' => null, 'fetchedAt' => null, 'status' => 'error', 'error' => $error];
}

function decodeJson(array $record): ?array
{
    if ($record['body'] === null) {
        return null;
    }
    $decoded = json_decode($record['body'], true);
    return is_array($decoded) ? $decoded : null;
}

function sourcePayload(array $record, mixed $data = null): array
{
    return [
        'status' => $record['status'],
        'fetchedAt' => $record['fetchedAt'],
        'error' => $record['error'],
        'data' => $data,
    ];
}

function allSources(bool $refresh): array
{
    $database = database();
    $urls = [
        'apps' => 'https://itunes.apple.com/nl/rss/topfreeapplications/limit=100/json',
        'google' => 'https://trends.google.com/trending/rss?geo=NL',
        'hn' => 'https://hacker-news.firebaseio.com/v0/topstories.json',
    ];
    foreach (STEAM_GAMES as $appId => $_title) {
        $urls['steam:' . $appId] = "https://api.steampowered.com/ISteamUserStats/GetNumberOfCurrentPlayers/v1/?appid={$appId}";
    }

    $ttls = ['apps' => 900, 'google' => 900, 'hn' => 120];
    $needed = [];
    foreach ($urls as $key => $url) {
        $old = cached($key);
        if ($refresh || $old === null || (int) $old['expires_at'] <= time()) {
            $needed[$key] = $url;
        }
    }
    $batch = $needed ? fetchMany($needed, 'application/json, application/rss+xml, application/xml') : ['results' => [], 'errors' => []];

    $records = [];
    foreach ($urls as $key => $_url) {
        $ttl = str_starts_with($key, 'steam:') ? 60 : ($ttls[$key] ?? 900);
        $fetchError = $batch['errors'][$key] ?? (array_key_exists($key, $batch['results']) ? null : 'De bron gaf geen antwoord.');
        $records[$key] = readSource(
            $key,
            $batch['results'][$key] ?? '',
            $fetchError,
            $ttl,
            $refresh
        );
    }

    if ($records['apps']['body'] === null) {
        $fallback = fetchMany(
            ['apps' => 'https://rss.marketingtools.apple.com/api/v2/nl/apps/top-free/100/apps.json'],
            'application/json'
        );
        if (isset($fallback['results']['apps'])) {
            $records['apps'] = readSource('apps', $fallback['results']['apps'], null, 900, true);
        } elseif ($records['apps']['error'] === null) {
            $records['apps']['error'] = $fallback['errors']['apps'] ?? 'Apple App Store is niet bereikbaar.';
        }
    }

    $hnIds = decodeJson($records['hn']);
    $hnIds = is_array($hnIds) ? array_slice(array_filter($hnIds, 'is_int'), 0, 30) : [];
    $hnUrls = [];
    foreach ($hnIds as $id) {
        $key = 'hn-item:' . $id;
        $old = cached($key);
        if ($refresh || $old === null || (int) $old['expires_at'] <= time()) {
            $hnUrls[$key] = "https://hacker-news.firebaseio.com/v0/item/{$id}.json";
        }
    }
    $hnBatch = $hnUrls ? fetchMany($hnUrls, 'application/json') : ['results' => [], 'errors' => []];
    $hnItems = [];
    $hnHadErrors = false;
    $hnUsedCache = $records['hn']['status'] === 'cached';
    foreach ($hnIds as $id) {
        $key = 'hn-item:' . $id;
        $itemError = $hnBatch['errors'][$key] ?? (array_key_exists($key, $hnBatch['results']) ? null : 'Hacker News-item is niet bereikbaar.');
        $record = readSource($key, $hnBatch['results'][$key] ?? '', $itemError, 120, $refresh);
        $hnUsedCache = $hnUsedCache || $record['status'] === 'cached';
        $item = decodeJson($record);
        if ($item !== null) {
            $hnItems[] = $item;
        } else {
            $hnHadErrors = true;
        }
    }
    if ($hnHadErrors && $records['hn']['error'] === null) {
        $records['hn']['error'] = 'Een of meer Hacker News-items konden niet worden opgehaald.';
    }
    if ($hnUsedCache) {
        $records['hn']['status'] = 'cached';
    }
    $hnData = $records['hn']['body'] === null ? null : ['ids' => $hnIds, 'items' => $hnItems];

    $steamRows = [];
    $steamHasError = false;
    $steamUsedCache = false;
    $steamFetchedAt = null;
    foreach (STEAM_GAMES as $appId => $title) {
        $record = $records['steam:' . $appId];
        $steamUsedCache = $steamUsedCache || $record['status'] === 'cached';
        if ($steamFetchedAt === null || strcmp($record['fetchedAt'] ?? '', $steamFetchedAt) > 0) {
            $steamFetchedAt = $record['fetchedAt'];
        }
        $data = decodeJson($record);
        if ($data === null) {
            $steamHasError = true;
        } else {
            $steamRows[] = ['id' => (int) $appId, 'title' => $title, 'data' => $data];
            $steamHasError = $steamHasError || $record['error'] !== null;
        }
    }

    $appsData = decodeJson($records['apps']);
    $googleData = $records['google']['body'];
    $sources = [
        'apps' => sourcePayload($records['apps'], $appsData),
        'google' => sourcePayload($records['google'], $googleData),
        'hn' => sourcePayload($records['hn'], $hnData),
        'steam' => sourcePayload([
            'status' => count($steamRows) === 0 ? 'error' : ($steamHasError || $steamUsedCache ? 'cached' : 'live'),
            'fetchedAt' => $steamFetchedAt,
            'error' => $steamHasError ? 'Een of meer Steam-spelerstanden konden niet worden ververst.' : null,
        ], $steamRows),
    ];

    return ['updatedAt' => gmdate('c'), 'sources' => $sources];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    respond(405, ['error' => 'Alleen GET-verzoeken zijn toegestaan.']);
}

$source = $_GET['source'] ?? '';
if ($source !== 'all') {
    respond(404, ['error' => 'Gebruik source=all voor de centrale Trendradar-API.']);
}

$refresh = ($_GET['refresh'] ?? '') === '1';
try {
    respond(200, allSources($refresh));
} catch (Throwable $error) {
    error_log('Trendradar API/database error: ' . $error->getMessage());
    respond(500, ['error' => 'De centrale API of SQLite-database kon niet worden geladen. Controleer PHP cURL- en SQLite-extensies en schrijfrechten.']);
}
