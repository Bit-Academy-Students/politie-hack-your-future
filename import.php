<?php
declare(strict_types=1);

// PHP 8.1+ with PDO_SQLite, SimpleXML, mbstring, and HTTPS streams enabled.
// Run schema.sql against your existing SQLite database first.
// Each function returns the number of imported items or throws on failure.

function importAppleApps(PDO $db): int
{
    $url = 'https://itunes.apple.com/nl/rss/topfreeapplications/limit=100/json';
    $context = stream_context_create(['http' => [
        'timeout' => 30,
        'header' => "User-Agent: SimpleTrendsImporter/1.0\r\nAccept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Could not download the Apple feed.');
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $feed = $data['feed'] ?? null;
    if (!is_array($feed) || !isset($feed['entry']) || !is_array($feed['entry'])) {
        throw new RuntimeException('The Apple response does not contain feed.entry.');
    }
    $fetchedAt = gmdate('Y-m-d H:i:s');
    $utc = new DateTimeZone('UTC');
    $updatedAt = !empty($feed['updated']['label'])
        ? (new DateTimeImmutable($feed['updated']['label']))->setTimezone($utc)->format('Y-m-d H:i:s')
        : null;

    $db->beginTransaction();
    try {
        $source = $db->prepare(
            "INSERT INTO sources (provider, feed_name, country_code, endpoint_url, format)
             VALUES ('apple', 'top_free_apps', 'NL', ?, 'json')
             ON CONFLICT(provider, feed_name, country_code)
             DO UPDATE SET endpoint_url = excluded.endpoint_url"
        );
        $source->execute([$url]);
        $sourceId = (int) $db->query(
            "SELECT source_id FROM sources
             WHERE provider = 'apple' AND feed_name = 'top_free_apps' AND country_code = 'NL'"
        )->fetchColumn();

        $snapshot = $db->prepare(
            'INSERT INTO feed_snapshots (source_id, fetched_at, feed_updated_at, raw_payload)
             VALUES (?, ?, ?, ?)'
        );
        $snapshot->execute([$sourceId, $fetchedAt, $updatedAt, $raw]);
        $snapshotId = (int) $db->lastInsertId();

        $saveApp = $db->prepare(
            'INSERT INTO apps (
                app_id, bundle_id, name, title, description, store_url,
                developer_name, developer_url, category_id, category_term,
                category_label, category_url, price_amount, currency, price_label,
                released_at, rights, preview_url, last_seen_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(app_id) DO UPDATE SET
                bundle_id = excluded.bundle_id, name = excluded.name,
                title = excluded.title, description = excluded.description,
                store_url = excluded.store_url, developer_name = excluded.developer_name,
                developer_url = excluded.developer_url, category_id = excluded.category_id,
                category_term = excluded.category_term, category_label = excluded.category_label,
                category_url = excluded.category_url, price_amount = excluded.price_amount,
                currency = excluded.currency, price_label = excluded.price_label,
                released_at = excluded.released_at, rights = excluded.rights,
                preview_url = excluded.preview_url, last_seen_at = excluded.last_seen_at'
        );
        $deleteImages = $db->prepare('DELETE FROM app_images WHERE app_id = ?');
        $saveImage = $db->prepare('INSERT INTO app_images (app_id, height, url) VALUES (?, ?, ?)');
        $saveItem = $db->prepare(
            "INSERT INTO source_items (source_id, source_key, item_type, display_name, app_id)
             VALUES (?, ?, 'app', ?, ?)
             ON CONFLICT(source_id, source_key) DO UPDATE SET display_name = excluded.display_name"
        );
        $findItem = $db->prepare('SELECT item_id FROM source_items WHERE source_id = ? AND source_key = ?');
        $saveObservation = $db->prepare(
            'INSERT INTO trend_observations
                (source_id, snapshot_id, item_id, title, list_position, chart_rank, image_url)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $position = 0;
        foreach ($feed['entry'] as $entry) {
            $position++;
            $appId = (string) ($entry['id']['attributes']['im:id'] ?? '');
            $name = trim((string) ($entry['im:name']['label'] ?? ''));
            if ($appId === '' || $name === '') {
                throw new RuntimeException('Apple entry is missing its app ID or name.');
            }
            $links = $entry['link'] ?? [];
            if (isset($links['attributes'])) {
                $links = [$links]; // Also accept an entry with just one link.
            }
            $previewUrl = null;
            foreach ($links as $link) {
                if (($link['attributes']['rel'] ?? '') === 'enclosure') {
                    $previewUrl = $link['attributes']['href'] ?? null;
                    break;
                }
            }
            $releasedAt = !empty($entry['im:releaseDate']['label'])
                ? (new DateTimeImmutable($entry['im:releaseDate']['label']))->setTimezone($utc)->format('Y-m-d H:i:s')
                : null;
            $category = $entry['category']['attributes'] ?? [];
            $price = $entry['im:price']['attributes'] ?? [];

            $saveApp->execute([
                $appId, $entry['id']['attributes']['im:bundleId'] ?? null,
                $name, $entry['title']['label'] ?? null, $entry['summary']['label'] ?? null,
                $entry['id']['label'] ?? null, $entry['im:artist']['label'] ?? null,
                $entry['im:artist']['attributes']['href'] ?? null,
                $category['im:id'] ?? null, $category['term'] ?? null,
                $category['label'] ?? null, $category['scheme'] ?? null,
                $price['amount'] ?? null, $price['currency'] ?? null,
                $entry['im:price']['label'] ?? null, $releasedAt,
                $entry['rights']['label'] ?? null, $previewUrl, $fetchedAt,
            ]);
            $deleteImages->execute([$appId]);
            foreach ($entry['im:image'] ?? [] as $image) {
                $saveImage->execute([$appId, (int) $image['attributes']['height'], $image['label']]);
            }

            $saveItem->execute([$sourceId, $appId, $name, $appId]);
            $findItem->execute([$sourceId, $appId]);
            $itemId = (int) $findItem->fetchColumn();
            $saveObservation->execute([
                $sourceId, $snapshotId, $itemId, $name, $position, $position, $previewUrl,
            ]);
        }
        $db->commit();
        return $position;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function importGoogleTrends(PDO $db): int
{
    $url = 'https://trends.google.com/trending/rss?geo=NL';
    $context = stream_context_create(['http' => [
        'timeout' => 30,
        'header' => "User-Agent: SimpleTrendsImporter/1.0\r\nAccept: application/rss+xml, application/xml\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Could not download the Google feed.');
    }
    $previousErrors = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    if ($xml === false || $xml->getName() !== 'rss' || !isset($xml->channel)) {
        throw new RuntimeException('The Google response is not a valid RSS feed.');
    }
    $namespaces = $xml->getDocNamespaces(true);
    $htNamespace = $namespaces['ht'] ?? null;
    if ($htNamespace === null) {
        throw new RuntimeException('Google RSS is missing its ht namespace.');
    }
    $fetchedAt = gmdate('Y-m-d H:i:s');
    $utc = new DateTimeZone('UTC');

    $db->beginTransaction();
    try {
        $source = $db->prepare(
            "INSERT INTO sources (provider, feed_name, country_code, endpoint_url, format)
             VALUES ('google_trends', 'trending_searches', 'NL', ?, 'xml')
             ON CONFLICT(provider, feed_name, country_code)
             DO UPDATE SET endpoint_url = excluded.endpoint_url"
        );
        $source->execute([$url]);
        $sourceId = (int) $db->query(
            "SELECT source_id FROM sources
             WHERE provider = 'google_trends' AND feed_name = 'trending_searches' AND country_code = 'NL'"
        )->fetchColumn();

        $snapshot = $db->prepare(
            'INSERT INTO feed_snapshots (source_id, fetched_at, raw_payload) VALUES (?, ?, ?)'
        );
        $snapshot->execute([$sourceId, $fetchedAt, $raw]);
        $snapshotId = (int) $db->lastInsertId();

        $saveItem = $db->prepare(
            "INSERT INTO source_items (source_id, source_key, item_type, display_name)
             VALUES (?, ?, 'search_query', ?)
             ON CONFLICT(source_id, source_key) DO UPDATE SET display_name = excluded.display_name"
        );
        $findItem = $db->prepare('SELECT item_id FROM source_items WHERE source_id = ? AND source_key = ?');
        $saveObservation = $db->prepare(
            'INSERT INTO trend_observations (
                source_id, snapshot_id, item_id, title, source_published_at, list_position,
                traffic_label, traffic_lower_bound, image_url, image_source
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $saveArticle = $db->prepare(
            'INSERT INTO related_articles
                (observation_id, position, title, url, publisher, snippet, image_url)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $position = 0;
        foreach ($xml->channel->item as $entry) {
            $position++;
            $title = trim((string) $entry->title);
            if ($title === '') {
                throw new RuntimeException('Google entry is missing its search term.');
            }
            // The feed has no per-item ID. Track each normalized query within this source.
            $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $title), 'UTF-8');
            $sourceKey = hash('sha256', $normalized);
            $ht = $entry->children($htNamespace);
            $trafficLabel = trim((string) $ht->approx_traffic);
            $trafficLowerBound = null;
            // Examples: 500+, 1,000+, 20K+, 1.5M+. Keep the original label too.
            if (preg_match('/^([\d,]+(?:\.\d+)?)\s*([KMB]?)\+?$/i', $trafficLabel, $matches)) {
                $multiplier = match (strtoupper($matches[2])) {
                    'K' => 1000,
                    'M' => 1000000,
                    'B' => 1000000000,
                    default => 1,
                };
                $trafficLowerBound = (int) round((float) str_replace(',', '', $matches[1]) * $multiplier);
            }
            $publishedAt = trim((string) $entry->pubDate);
            $publishedAt = $publishedAt !== ''
                ? (new DateTimeImmutable($publishedAt))->setTimezone($utc)->format('Y-m-d H:i:s')
                : null;

            $saveItem->execute([$sourceId, $sourceKey, $title]);
            $findItem->execute([$sourceId, $sourceKey]);
            $itemId = (int) $findItem->fetchColumn();
            // chart_rank stays NULL: feed order is not treated as a popularity rank.
            $saveObservation->execute([
                $sourceId, $snapshotId, $itemId, $title, $publishedAt, $position,
                $trafficLabel !== '' ? $trafficLabel : null, $trafficLowerBound,
                trim((string) $ht->picture) ?: null,
                trim((string) $ht->picture_source) ?: null,
            ]);
            $observationId = (int) $db->lastInsertId();

            $articlePosition = 0;
            foreach ($ht->news_item as $article) {
                $news = $article->children($htNamespace);
                $saveArticle->execute([
                    $observationId, ++$articlePosition,
                    (string) $news->news_item_title, (string) $news->news_item_url,
                    trim((string) $news->news_item_source) ?: null,
                    trim((string) $news->news_item_snippet) ?: null,
                    trim((string) $news->news_item_picture) ?: null,
                ]);
            }
        }
        $db->commit();
        return $position;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

// Edit the default list below, or pass your own [Steam app ID => game name] array.
function importSteamPlayers(PDO $db, array $games = [
    730 => 'Counter-Strike 2',
    570 => 'Dota 2',
    578080 => 'PUBG: BATTLEGROUNDS',
    252490 => 'Rust',
]): int {
    if ($games === []) {
        throw new InvalidArgumentException('Configure at least one Steam game.');
    }
    $endpoint = 'https://api.steampowered.com/ISteamUserStats/GetNumberOfCurrentPlayers/v1/';
    $context = stream_context_create(['http' => [
        'timeout' => 15,
        'header' => "User-Agent: SimpleTrendsImporter/1.0\r\nAccept: application/json\r\n",
    ]]);

    // Fetch all selected games before starting the database transaction.
    // A failed game prevents a partial Steam snapshot from replacing the last complete one.
    $results = [];
    foreach ($games as $steamId => $name) {
        $steamId = (string) $steamId;
        if (!ctype_digit($steamId) || (int) $steamId < 1 || (int) $steamId > 4294967295
            || !is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('Steam games must map positive app IDs to game names.');
        }
        $url = $endpoint . '?appid=' . rawurlencode($steamId);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Could not download Steam player count for app ' . $steamId . '.');
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $response = $data['response'] ?? [];
        if (($response['result'] ?? null) !== 1 || !isset($response['player_count'])
            || !is_int($response['player_count']) || $response['player_count'] < 0) {
            throw new RuntimeException('Steam returned an invalid player count for app ' . $steamId . '.');
        }
        $results[] = [
            'steam_app_id' => $steamId,
            'name' => trim($name),
            'url' => $url,
            'current_players' => $response['player_count'],
            'measured_at' => gmdate('Y-m-d H:i:s'),
            'raw_response' => $raw,
        ];
    }
    $fetchedAt = gmdate('Y-m-d H:i:s');
    $payload = json_encode(['games' => $results], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $db->beginTransaction();
    try {
        $source = $db->prepare(
            "INSERT INTO sources (provider, feed_name, country_code, endpoint_url, format)
             VALUES ('steam', 'selected_game_players', 'GLOBAL', ?, 'json')
             ON CONFLICT(provider, feed_name, country_code)
             DO UPDATE SET endpoint_url = excluded.endpoint_url"
        );
        $source->execute([$endpoint]);
        $sourceId = (int) $db->query(
            "SELECT source_id FROM sources
             WHERE provider = 'steam' AND feed_name = 'selected_game_players' AND country_code = 'GLOBAL'"
        )->fetchColumn();
        $snapshot = $db->prepare(
            'INSERT INTO feed_snapshots (source_id, fetched_at, raw_payload) VALUES (?, ?, ?)'
        );
        $snapshot->execute([$sourceId, $fetchedAt, $payload]);
        $snapshotId = (int) $db->lastInsertId();

        $saveApp = $db->prepare(
            'INSERT INTO apps (app_id, name, title, store_url, last_seen_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(app_id) DO UPDATE SET name = excluded.name, title = excluded.title,
                 store_url = excluded.store_url, last_seen_at = excluded.last_seen_at'
        );
        $saveItem = $db->prepare(
            "INSERT INTO source_items (source_id, source_key, item_type, display_name, app_id)
             VALUES (?, ?, 'app', ?, ?)
             ON CONFLICT(source_id, source_key) DO UPDATE SET display_name = excluded.display_name"
        );
        $findItem = $db->prepare('SELECT item_id FROM source_items WHERE source_id = ? AND source_key = ?');
        $saveObservation = $db->prepare(
            'INSERT INTO trend_observations (source_id, snapshot_id, item_id, title, list_position)
             VALUES (?, ?, ?, ?, ?)'
        );
        $saveCount = $db->prepare(
            'INSERT INTO steam_player_counts (observation_id, current_players, measured_at) VALUES (?, ?, ?)'
        );
        foreach ($results as $index => $game) {
            $appId = 'steam:' . $game['steam_app_id'];
            $storeUrl = 'https://store.steampowered.com/app/' . $game['steam_app_id'] . '/';
            $saveApp->execute([$appId, $game['name'], $game['name'], $storeUrl, $game['measured_at']]);
            $saveItem->execute([$sourceId, $game['steam_app_id'], $game['name'], $appId]);
            $findItem->execute([$sourceId, $game['steam_app_id']]);
            $itemId = (int) $findItem->fetchColumn();
            $saveObservation->execute([$sourceId, $snapshotId, $itemId, $game['name'], $index + 1]);
            $observationId = (int) $db->lastInsertId();
            $saveCount->execute([$observationId, $game['current_players'], $game['measured_at']]);
        }
        $db->commit();
        return count($results);
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function importCloudflareRadar(PDO $db): int
{
    $token = trim((string) getenv('CLOUDFLARE_API_TOKEN'));
    if ($token === '' || preg_match('/\s/', $token)) {
        throw new RuntimeException('Set a valid CLOUDFLARE_API_TOKEN before importing Radar.');
    }

    $url = 'https://api.cloudflare.com/client/v4/radar/ranking/top?location=NL&rankingType=TRENDING_RISE&limit=10';
    $context = stream_context_create(['http' => [
        'timeout' => 20,
        'follow_location' => 0,
        'header' => "Accept: application/json\r\nAuthorization: Bearer {$token}\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Could not download Cloudflare Radar. Check the token permissions and HTTPS connection.');
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $domains = $data['result']['top_0'] ?? null;
    if (($data['success'] ?? null) !== true || !is_array($domains) || !array_is_list($domains)) {
        throw new RuntimeException('Cloudflare Radar did not return a domain ranking.');
    }
    foreach ($domains as $domain) {
        if (!is_string($domain['domain'] ?? null) || trim($domain['domain']) === ''
            || !is_int($domain['rank'] ?? null) || $domain['rank'] < 1) {
            throw new RuntimeException('Cloudflare Radar returned an invalid domain ranking.');
        }
    }

    $fetchedAt = gmdate('Y-m-d H:i:s');
    $lastUpdated = $data['result']['meta']['lastUpdated'] ?? null;
    $updatedAt = is_string($lastUpdated) && $lastUpdated !== ''
        ? (new DateTimeImmutable($lastUpdated))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        : null;

    // The small ranked list is self-contained in the snapshot's original response.
    $db->beginTransaction();
    try {
        $source = $db->prepare(
            "INSERT INTO sources (provider, feed_name, country_code, endpoint_url, format)
             VALUES ('cloudflare_radar', 'rising_domains', 'NL', ?, 'json')
             ON CONFLICT(provider, feed_name, country_code)
             DO UPDATE SET endpoint_url = excluded.endpoint_url"
        );
        $source->execute([$url]);
        $sourceId = $db->query(
            "SELECT source_id FROM sources
             WHERE provider = 'cloudflare_radar' AND feed_name = 'rising_domains' AND country_code = 'NL'"
        )->fetchColumn();
        $snapshot = $db->prepare(
            'INSERT INTO feed_snapshots (source_id, fetched_at, feed_updated_at, raw_payload) VALUES (?, ?, ?, ?)'
        );
        $snapshot->execute([$sourceId, $fetchedAt, $updatedAt, $raw]);
        $db->commit();
        return count($domains);
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

// Run the imports from the command line: php import.php
// Requiring this file from another PHP script only defines the import functions.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    // EDIT THIS to point to the SQLite database you already created.
    $databasePath = __DIR__ . '/trends.sqlite';
    if (!is_file($databasePath)) {
        throw new RuntimeException('SQLite database not found: ' . $databasePath);
    }
    $db = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');

    $failed = false;
    $imports = ['importAppleApps', 'importGoogleTrends', 'importSteamPlayers'];
    if (trim((string) getenv('CLOUDFLARE_API_TOKEN')) !== '') {
        $imports[] = 'importCloudflareRadar';
    }
    foreach ($imports as $import) {
        try {
            echo $import . ': imported ' . $import($db) . " items.\n";
        } catch (Throwable $error) {
            $failed = true;
            echo $import . ': FAILED - ' . $error->getMessage() . "\n";
        }
    }
    exit($failed ? 1 : 0);
}
