<?php

$error = null;
$totals = [];
$feeds = [];
$appSnapshot = $appBaseline = $searchSnapshot = $radarSnapshot = null;
$risingApps = $legacyRisingApps = $chartEntries = $recentReleases = $websiteLeads = $radarDomains = [];
$olderSearchGains = $olderSteamGains = [];
$searchBaseline = $steamBaseline = $steamSnapshot = null;
$chartHistory = [];
try {
    if (!is_file($databasePath)) {
        throw new RuntimeException('Het SQLite-bestand is niet gevonden.');
    }
    $db = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA query_only = ON'); // This page only reads the database.
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->beginTransaction(); // Read all panels from a consistent database view.

    $totals = $db->query(
        "SELECT
            (SELECT COUNT(*) FROM sources) AS sources,
            (SELECT COUNT(*) FROM apps) AS apps,
            (SELECT COUNT(*) FROM source_items WHERE item_type = 'search_query') AS queries,
            (SELECT COUNT(*) FROM feed_snapshots) AS snapshots,
            (SELECT COUNT(*) FROM trend_observations) AS observations,
            (SELECT COUNT(*) FROM related_articles) AS articles"
    )->fetch();
    $sources = $db->query('SELECT * FROM sources ORDER BY provider, source_id')->fetchAll();
    $findSnapshot = $db->prepare(
        'SELECT snapshot_id, fetched_at, feed_updated_at FROM feed_snapshots WHERE source_id = ?
         ORDER BY fetched_at DESC, snapshot_id DESC LIMIT 1'
    );
    $findRows = $db->prepare(
        'SELECT o.*, a.app_id, a.title AS app_title, a.description AS app_description,
                a.category_id, a.store_url, a.developer_name, a.developer_url, a.category_label, a.price_amount, a.currency, a.released_at,
                (SELECT image.url FROM app_images image WHERE image.app_id = a.app_id
                 ORDER BY image.height DESC LIMIT 1) AS app_icon
         FROM trend_observations o
         JOIN source_items i ON i.item_id = o.item_id
         LEFT JOIN apps a ON a.app_id = i.app_id
         WHERE o.snapshot_id = ? ORDER BY o.list_position'
    );
    $findArticles = $db->prepare(
        'SELECT a.* FROM related_articles a
         JOIN trend_observations o ON o.observation_id = a.observation_id
         WHERE o.snapshot_id = ? ORDER BY o.list_position, a.position'
    );
    // Pick the earlier, distinct chart closest to the requested comparison time.
    $findBaseline = $db->prepare(
        'SELECT s.fetched_at, s.snapshot_id FROM feed_snapshots s
         WHERE s.source_id = :source AND s.snapshot_id <> :snapshot AND s.fetched_at <= :latest
           AND COALESCE(s.feed_updated_at, s.fetched_at) < :latest_chart
           AND EXISTS (SELECT 1 FROM trend_observations o
                       WHERE o.snapshot_id = s.snapshot_id AND o.chart_rank IS NOT NULL)
         ORDER BY ABS(strftime(\'%s\', s.fetched_at) - strftime(\'%s\', :target)),
                  s.fetched_at ASC, s.snapshot_id DESC LIMIT 1'
    );
    $findRanks = $db->prepare(
        'SELECT item_id, chart_rank FROM trend_observations WHERE snapshot_id = ? AND chart_rank IS NOT NULL'
    );
    $findChartHistory = $db->prepare(
        'SELECT s.snapshot_id, s.fetched_at, s.feed_updated_at FROM feed_snapshots s
         WHERE s.snapshot_id IN (
             SELECT MAX(sample.snapshot_id) FROM feed_snapshots sample
             WHERE sample.source_id = ? AND sample.fetched_at BETWEEN ? AND ?
               AND EXISTS (SELECT 1 FROM trend_observations o
                           WHERE o.snapshot_id = sample.snapshot_id AND o.chart_rank IS NOT NULL)
             GROUP BY COALESCE(sample.feed_updated_at, sample.fetched_at)
         ) ORDER BY s.fetched_at DESC, s.snapshot_id DESC LIMIT 5'
    );
    $findPayload = $db->prepare('SELECT raw_payload FROM feed_snapshots WHERE snapshot_id = ?');
    if ($olderOnly) {
        $findEarlierSnapshot = $db->prepare(
            "SELECT snapshot_id, fetched_at FROM feed_snapshots WHERE source_id = ? AND fetched_at < ?
             ORDER BY ABS(strftime('%s', fetched_at) - strftime('%s', ?)), fetched_at ASC, snapshot_id DESC LIMIT 1"
        );
        $findPlayerCounts = null;
        if (!$socialOnly && $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'steam_player_counts'")->fetchColumn()) {
            $findPlayerCounts = $db->prepare(
                'SELECT o.item_id, p.current_players FROM steam_player_counts p
                 JOIN trend_observations o USING (observation_id) WHERE o.snapshot_id = ?'
            );
        }
    }

    foreach ($sources as $source) {
        if ($socialOnly && !in_array($source['provider'], ['apple', 'google_trends', 'cloudflare_radar'], true)) {
            continue;
        }
        $findSnapshot->execute([$source['source_id']]);
        $snapshot = $findSnapshot->fetch();
        if (
            $source['provider'] === 'cloudflare_radar' && $source['feed_name'] === 'rising_domains'
            && $source['country_code'] === 'NL'
        ) {
            if ($snapshot !== false) {
                $radarSnapshot = $snapshot;
                $findPayload->execute([$snapshot['snapshot_id']]);
                $payload = json_decode((string) $findPayload->fetchColumn(), true);
                $radarDomains = $payload['result']['top_0'] ?? [];
                if ($socialOnly) {
                    $radarDomains = array_filter($radarDomains, static fn(array $entry): bool => isSocialDomain($entry['domain']));
                }
                if ($olderOnly) {
                    $knownDomains = [];
                    foreach ($radarDomains as $entry) {
                        $age = olderWebsite($entry['domain'], $ageCutoff);
                        if ($age !== null) {
                            $knownDomains[] = $entry + $age;
                        }
                    }
                    $radarDomains = $knownDomains;
                }
            }
            continue;
        }
        $rows = [];
        $articles = [];
        if ($snapshot !== false) {
            $findRows->execute([$snapshot['snapshot_id']]);
            $rows = $findRows->fetchAll();
            if ($socialOnly && $source['provider'] === 'apple') {
                $rows = array_filter($rows, static fn(array $app): bool => (int) $app['category_id'] === 6005
                    || in_array((string) $app['app_id'], SOCIAL_APP_IDS, true));
            }
            if ($socialOnly && $source['provider'] === 'google_trends') {
                $socialRows = [];
                foreach ($rows as $row) {
                    $term = mb_strtolower(trim($row['title']), 'UTF-8');
                    $alias = $websiteAliases[$term] ?? null;
                    $row['domain'] = $alias !== null && isSocialDomain($alias) ? $alias : socialSearchDomain($term);
                    if ($row['domain'] !== null) {
                        $socialRows[] = $row;
                    }
                }
                $rows = $socialRows;
            }
            $findArticles->execute([$snapshot['snapshot_id']]);
            foreach ($findArticles->fetchAll() as $article) {
                $articles[$article['observation_id']][] = $article;
            }

            if (
                $source['country_code'] === 'NL' && $source['provider'] === 'apple'
                && $source['feed_name'] === 'top_free_apps'
            ) {
                $appSnapshot = $snapshot;
                $latest = strtotime($snapshot['fetched_at'] . ' UTC');
                $releaseCutoff = gmdate('Y-m-d H:i:s', $latest - 30 * 86400);
                $recentReleases = array_values(array_filter($rows, static fn(array $app): bool =>
                $app['chart_rank'] !== null && $app['released_at'] !== null
                    && $app['released_at'] >= $releaseCutoff && $app['released_at'] <= $snapshot['fetched_at']));

                $findChartHistory->execute([
                    $source['source_id'],
                    gmdate('Y-m-d H:i:s', $latest - 7 * 86400),
                    $snapshot['fetched_at'],
                ]);
                foreach (array_reverse($findChartHistory->fetchAll()) as $sample) {
                    $findRanks->execute([$sample['snapshot_id']]);
                    $chartHistory[] = [
                        'at' => $sample['feed_updated_at'] ?? $sample['fetched_at'],
                        'ranks' => $findRanks->fetchAll(PDO::FETCH_KEY_PAIR)
                    ];
                }
                $findBaseline->execute([
                    'source' => $source['source_id'],
                    'snapshot' => $snapshot['snapshot_id'],
                    'latest' => $snapshot['fetched_at'],
                    'latest_chart' => $snapshot['feed_updated_at'] ?? $snapshot['fetched_at'],
                    'target' => gmdate('Y-m-d H:i:s', $latest - $comparisonWindows[$comparisonWindow]),
                ]);
                $appBaseline = $findBaseline->fetch() ?: null;
                if ($appBaseline !== null) {
                    $findRanks->execute([$appBaseline['snapshot_id']]);
                    $previousRanks = $findRanks->fetchAll(PDO::FETCH_KEY_PAIR);
                    foreach ($rows as $row) {
                        if ($row['chart_rank'] === null) {
                            continue;
                        }
                        $previousRank = $previousRanks[$row['item_id']] ?? null;
                        if ($previousRank === null) {
                            $chartEntries[] = $row;
                        } elseif ($previousRank > $row['chart_rank']) {
                            $row['previous_rank'] = $previousRank;
                            $row['gain'] = $previousRank - $row['chart_rank'];
                            $risingApps[] = $row;
                        }
                    }
                    usort($risingApps, static fn(array $a, array $b): int => ($b['gain'] <=> $a['gain']) ?: ($a['chart_rank'] <=> $b['chart_rank']));
                    usort($chartEntries, static fn(array $a, array $b): int => $a['chart_rank'] <=> $b['chart_rank']);
                    $legacyRisingApps = array_values(array_filter(
                        $risingApps,
                        static fn(array $app): bool =>
                            $app['released_at'] !== null && $app['released_at'] < '2010-01-01'
                    ));
                }
            }

            if (
                $source['country_code'] === 'NL' && $source['provider'] === 'google_trends'
                && $source['feed_name'] === 'trending_searches'
            ) {
                $searchSnapshot = $snapshot;
                foreach ($rows as $row) {
                    $term = mb_strtolower(trim($row['title']), 'UTF-8');
                    $domain = $row['domain'] ?? $websiteAliases[$term] ?? $term;
                    // Only explicit domain searches and exact configured aliases qualify.
                    // Article domains identify the publisher, not the subject of a search.
                    if (preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $domain)) {
                        $row['domain'] = $domain;
                        $websiteLeads[] = $row;
                    }
                }
                usort($websiteLeads, static fn(array $a, array $b): int => (($b['traffic_lower_bound'] ?? -1) <=> ($a['traffic_lower_bound'] ?? -1))
                    ?: ($a['list_position'] <=> $b['list_position']));
            }

            if ($olderOnly && in_array($source['provider'], ['google_trends', 'steam'], true)) {
                $target = gmdate('Y-m-d H:i:s', strtotime($snapshot['fetched_at'] . ' UTC') - $comparisonWindows[$comparisonWindow]);
                $findEarlierSnapshot->execute([$source['source_id'], $snapshot['fetched_at'], $target]);
                $baseline = $findEarlierSnapshot->fetch() ?: null;
                if ($source['provider'] === 'google_trends' && $source['country_code'] === 'NL'
                    && $source['feed_name'] === 'trending_searches' && $baseline !== null) {
                    $searchBaseline = $baseline;
                    $findRows->execute([$baseline['snapshot_id']]);
                    $previousSearches = array_column($findRows->fetchAll(), null, 'item_id');
                    foreach ($rows as $row) {
                        $term = mb_strtolower(trim($row['title']), 'UTF-8');
                        $domain = $row['domain'] ?? $websiteAliases[$term] ?? socialSearchDomain($term) ?? $term;
                        $age = olderWebsite($domain, $ageCutoff);
                        $previous = $previousSearches[$row['item_id']] ?? null;
                        if ($age === null || $row['source_published_at'] === null
                            || $row['source_published_at'] !== ($previous['source_published_at'] ?? null)
                            || $row['traffic_lower_bound'] === null || ($previous['traffic_lower_bound'] ?? null) === null
                            || $row['traffic_lower_bound'] <= $previous['traffic_lower_bound']) {
                            continue;
                        }
                        $olderSearchGains[] = $row + $age + [
                            'previous_label' => $previous['traffic_label'],
                            'gain' => $row['traffic_lower_bound'] - $previous['traffic_lower_bound'],
                        ];
                    }
                    usort($olderSearchGains, static fn(array $a, array $b): int => $b['gain'] <=> $a['gain']);
                } elseif ($source['provider'] === 'steam' && $source['feed_name'] === 'selected_game_players') {
                    $steamSnapshot = $snapshot;
                    $steamBaseline = $baseline;
                    if ($baseline !== null && $findPlayerCounts !== null) {
                        $findPlayerCounts->execute([$baseline['snapshot_id']]);
                        $previousCounts = $findPlayerCounts->fetchAll(PDO::FETCH_KEY_PAIR);
                        $findPlayerCounts->execute([$snapshot['snapshot_id']]);
                        $currentCounts = $findPlayerCounts->fetchAll(PDO::FETCH_KEY_PAIR);
                        foreach ($rows as $game) {
                            $release = STEAM_RELEASES[$game['app_id']] ?? null;
                            $before = $previousCounts[$game['item_id']] ?? null;
                            $after = $currentCounts[$game['item_id']] ?? null;
                            if ($release !== null && $release[0] < $ageCutoff && $before !== null && $after !== null && $after > $before) {
                                $olderSteamGains[] = $game + ['since' => $release[0], 'source' => $release[1],
                                    'before' => $before, 'after' => $after, 'gain' => $after - $before];
                            }
                        }
                        usort($olderSteamGains, static fn(array $a, array $b): int => $b['gain'] <=> $a['gain']);
                    }
                }
            }
        }
        $feeds[] = compact('source', 'snapshot', 'rows', 'articles');
    }
    $db->commit();
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    error_log($exception->getMessage());
    $error = is_file($databasePath)
        ? 'Controleer of PHP de database kan lezen en of het schema uit sql/schema.sql is uitgevoerd.'
        : 'Het SQLite-bestand is niet gevonden. Controleer het databasepad in index.php.';
}

$shownClimbers = $olderOnly ? array_values(array_filter($risingApps, static fn(array $app): bool =>
    $app['released_at'] !== null && substr($app['released_at'], 0, 10) < $ageCutoff)) : $risingApps;
