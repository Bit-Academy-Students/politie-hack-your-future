<?php

declare(strict_types=1);

// EDIT THIS to match the existing SQLite file used by import.php.
$databasePath = __DIR__ . '/trends.sqlite';

// Optional exact Google Trends terms for websites you monitor, in lowercase.
// Example: ['example platform' => 'example.com']
$websiteAliases = [];

$comparisonWindows = ['24h' => 86400, '7d' => 7 * 86400];
$requestedWindow = $_GET['window'] ?? '24h';
$comparisonWindow = is_string($requestedWindow) && isset($comparisonWindows[$requestedWindow])
    ? $requestedWindow : '24h';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function webUrl(?string $url): ?string
{
    if ($url === null || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
        ? $url : null;
}

function dutchTime(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('j M H:i T');
}

$error = null;
$totals = [];
$feeds = [];
$appSnapshot = $appBaseline = $searchSnapshot = $radarSnapshot = null;
$risingApps = $chartEntries = $recentReleases = $websiteLeads = $radarDomains = [];
$chartHistory = [];
try {
    if (!is_file($databasePath)) {
        throw new RuntimeException('Database not found. Set $databasePath at the top of this file.');
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
        'SELECT o.*, a.store_url, a.developer_name, a.category_label, a.price_amount, a.currency, a.released_at,
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

    foreach ($sources as $source) {
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
            }
            continue;
        }
        $rows = [];
        $articles = [];
        if ($snapshot !== false) {
            $findRows->execute([$snapshot['snapshot_id']]);
            $rows = $findRows->fetchAll();
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
                }
            }

            if (
                $source['country_code'] === 'NL' && $source['provider'] === 'google_trends'
                && $source['feed_name'] === 'trending_searches'
            ) {
                $searchSnapshot = $snapshot;
                foreach ($rows as $row) {
                    $term = mb_strtolower(trim($row['title']), 'UTF-8');
                    $domain = $websiteAliases[$term] ?? $term;
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
        }
        $feeds[] = compact('source', 'snapshot', 'rows', 'articles');
    }
    $db->commit();
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TrendRadar · Internet hotspots in the Netherlands</title>
    <link rel="stylesheet" href="style.css?v=<?= (int) filemtime(__DIR__ . '/style.css') ?>">
</head>

<body>
    <main>
        <header class="masthead">
            <div class="masthead-top">
                <div class="brand"><span class="brand-mark" aria-hidden="true"></span>TrendRadar</div>
                <form method="get">
                    <input type="hidden" name="window" value="<?= h($comparisonWindow) ?>">
                    <button type="submit">Refresh overview</button>
                </form>
            </div>
            <div class="masthead-copy">
                <h1>Internet hotspots in the Netherlands</h1>
                <p>Track changes in the Dutch App Store and spot websites gaining attention.</p>
            </div>
        </header>

        <?php if ($error !== null): ?>
            <div class="error" role="alert">
                <h2>Could not load the overview</h2>
                <p><?= h($error) ?></p>
                <p class="muted">Check the database path and run schema.sql against that database first.</p>
            </div>
        <?php else: ?>
            <div class="hotspots">
                <section class="movers" id="climbs" aria-labelledby="app-hotspots-title">
                    <div class="feed-heading movers-heading">
                        <div>
                            <p class="source-label">Dutch App Store · Top 100 free apps</p>
                            <h2 id="app-hotspots-title">Biggest climbs</h2>
                        </div>
                        <nav class="window-picker" aria-label="Rank comparison period">
                            <?php foreach (['24h' => '24 hours', '7d' => '7 days'] as $window => $label): ?>
                                <a href="?window=<?= h($window) ?>#climbs"<?= $window === $comparisonWindow ? ' aria-current="true"' : '' ?>><?= h($label) ?></a>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                    <div class="hotspot-content">
                        <?php if ($appBaseline === null): ?>
                            <p class="comparison-note">Rank changes appear once two distinct Dutch charts have been saved.</p>
                        <?php else: ?>
                            <p class="comparison-note">
                                <strong><?= h(dutchTime($appBaseline['fetched_at'])) ?> → <?= h(dutchTime($appSnapshot['fetched_at'])) ?></strong>
                                <span>Using the saved chart closest to the selected period.</span>
                            </p>
                            <?php if ($risingApps === []): ?>
                                <p class="empty-inline">No apps climbed between these charts.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php foreach (
                            [
                                'climbs' => ['label' => null, 'items' => $risingApps],
                                'entries' => ['label' => 'Entered the top 100', 'items' => $chartEntries],
                                'releases' => ['label' => 'Released in the last 30 days', 'items' => $recentReleases],
                            ] as $kind => ['label' => $label, 'items' => $items]
                        ): ?>
                            <?php if ($items === []) {
                                continue;
                            } ?>
                            <?php if ($label !== null): ?><h3 class="list-heading"><?= h($label) ?></h3><?php endif; ?>
                            <ul class="mover-list <?= $kind === 'climbs' ? 'gain-list' : 'support-list' ?>" aria-label="<?= h($label ?? 'Apps by places gained') ?>">
                                <?php foreach (array_slice($items, 0, 5) as $app):
                                    $url = webUrl($app['store_url']);
                                    $icon = webUrl($app['app_icon']);
                                ?>
                                    <li class="mover-row">
                                        <span class="app-icon" aria-hidden="true">
                                            <?= h(mb_strtoupper(mb_substr(trim($app['title']), 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                            <?php if ($icon !== null): ?><img src="<?= h($icon) ?>" alt="" width="44" height="44" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php endif; ?>
                                        </span>
                                        <div class="mover-name">
                                            <?php if ($url !== null): ?><a href="<?= h($url) ?>"><?= h($app['title']) ?></a><?php else: ?><?= h($app['title']) ?><?php endif; ?>
                                            <?php if ($kind === 'releases'): ?>
                                                <div class="muted">Released <?= h(substr($app['released_at'], 0, 10)) ?></div>
                                            <?php elseif ($app['category_label'] !== null): ?>
                                                <div class="muted"><?= h($app['category_label']) ?></div>
                                            <?php endif; ?>
                                            <?php if (count($chartHistory) >= 2): ?>
                                                <details class="muted rank-history">
                                                    <summary>Recent ranks</summary>
                                                    <dl>
                                                        <?php foreach ($chartHistory as $chart): ?>
                                                            <dt><?= h(dutchTime($chart['at'])) ?></dt>
                                                            <dd><?= isset($chart['ranks'][$app['item_id']]) ? '#' . h($chart['ranks'][$app['item_id']]) : 'Outside this top 100' ?></dd>
                                                        <?php endforeach; ?>
                                                    </dl>
                                                </details>
                                            <?php endif; ?>
                                        </div>
                                        <div class="rank-result">
                                            <?php if ($kind === 'climbs'): ?>
                                                <div class="rank-gain"><strong>+<?= h($app['gain']) ?></strong> <span><?= $app['gain'] === 1 ? 'place' : 'places' ?></span></div>
                                                <div class="rank-change"><span>#<?= h($app['previous_rank']) ?></span> <span aria-label="to">→</span> <strong>#<?= h($app['chart_rank']) ?></strong></div>
                                                <div class="gain-track" aria-hidden="true"><span style="width: <?= (int) round(100 * $app['gain'] / $risingApps[0]['gain']) ?>%"></span></div>
                                            <?php else: ?>
                                                <span class="badge rank-now">#<?= h($app['chart_rank']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>
                    </div>
                </section>
                <div class="hotspot-column">
                    <section aria-labelledby="radar-hotspots-title">
                        <div class="feed-heading">
                            <div>
                                <p class="source-label">Cloudflare Radar</p>
                                <h2 id="radar-hotspots-title">Rising domains</h2>
                                <?php if ($radarSnapshot !== null): ?>
                                    <p class="muted"><?= h(dutchTime($radarSnapshot['feed_updated_at'] ?? $radarSnapshot['fetched_at'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="hotspot-content">
                            <?php if ($radarSnapshot === null): ?>
                                <p class="empty-inline">No ranking has been imported. Configure Cloudflare Radar to show rising Dutch domains here.</p>
                            <?php elseif ($radarDomains === []): ?>
                                <p class="muted">Cloudflare returned an empty ranking for this snapshot.</p>
                            <?php else: ?>
                                <ol aria-label="Cloudflare Radar domain ranking">
                                    <?php foreach ($radarDomains as $domain): ?>
                                        <li>
                                            <strong><?= h($domain['domain']) ?></strong>
                                            <span class="badge">#<?= h($domain['rank']) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                            <p class="muted"><a href="https://radar.cloudflare.com/domains/nl">View Dutch domain rankings ↗</a></p>
                        </div>
                    </section>
                    <section aria-labelledby="website-hotspots-title">
                        <div class="feed-heading">
                            <div>
                                <p class="source-label">Google Trends</p>
                                <h2 id="website-hotspots-title">Website search leads</h2>
                                <?php if ($searchSnapshot !== null): ?>
                                    <p class="muted"><?= h(dutchTime($searchSnapshot['fetched_at'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="hotspot-content">
                            <?php if ($searchSnapshot === null): ?>
                                <p class="muted">Website search leads appear after the first Dutch Google Trends import.</p>
                            <?php elseif ($websiteLeads === []): ?>
                                <p class="empty-inline">No domain searches or tracked websites appeared in this snapshot.</p>
                            <?php else: ?>
                                <ul>
                                    <?php foreach (array_slice($websiteLeads, 0, 5) as $lead): ?>
                                        <li>
                                            <div>
                                                <strong><?= h($lead['domain']) ?></strong>
                                                <div class="muted">Google query: <?= h($lead['title']) ?></div>
                                            </div>
                                            <span class="badge"><?= h($lead['traffic_label'] ?? 'Volume unavailable') ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <p class="muted">Search queries show interest in a site. The volume labels are Google’s estimates.</p>
                        </div>
                    </section>
                </div>
            </div>
            <h2 class="feeds-title">Source overview</h2>
            <div class="stats">
                <?php foreach (
                    [
                        'sources' => 'Sources',
                        'apps' => 'Apps stored',
                        'queries' => 'Queries tracked',
                        'snapshots' => 'Snapshots saved',
                        'observations' => 'Observations',
                        'articles' => 'Article appearances'
                    ] as $key => $label
                ): ?>
                    <div class="stat"><strong><?= number_format((int) $totals[$key]) ?></strong><span class="muted"><?= h($label) ?></span></div>
                <?php endforeach; ?>
            </div>
            <p class="muted overview-note">Totals include all imports. The feeds below show the latest saved snapshots in UTC.</p>

            <?php if ($feeds === [] && $radarSnapshot === null): ?>
                <div class="empty">
                    <h2>No data yet</h2>
                    <p>Run import.php, then refresh this page.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($feeds as $feed):
                ['source' => $source, 'snapshot' => $snapshot, 'rows' => $rows, 'articles' => $articles] = $feed;
                $isApple = $source['provider'] === 'apple';
                $heading = match ($source['provider']) {
                    'apple' => 'App Store · Top free apps',
                    'google_trends' => 'Google · Trending searches',
                    default => $source['feed_name'],
                };
            ?>
                <section>
                    <div class="feed-heading">
                        <div>
                            <h2><?= h($heading) ?></h2>
                            <?php if ($snapshot !== false): ?>
                                <p class="muted">Fetched <?= h($snapshot['fetched_at']) ?> UTC &middot; Snapshot #<?= h($snapshot['snapshot_id']) ?></p>
                                <?php if ($snapshot['feed_updated_at'] !== null): ?>
                                    <p class="muted">Source updated <?= h($snapshot['feed_updated_at']) ?> UTC</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <span class="badge"><?= h($source['country_code']) ?> &middot; <?= count($rows) ?> items</span>
                    </div>

                    <?php if ($rows === []): ?>
                        <div class="empty">No observations in this source's latest snapshot. Run import.php to fetch data.</div>
                    <?php elseif ($isApple): ?>
                        <div class="scroll" role="region" aria-label="Latest App Store chart" tabindex="0">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">Rank</th>
                                        <th scope="col">App</th>
                                        <th scope="col">Developer</th>
                                        <th scope="col">Category</th>
                                        <th scope="col">Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): $url = webUrl($row['store_url']); ?>
                                        <tr>
                                            <td><?= h($row['chart_rank'] ?? '—') ?></td>
                                            <td><?php if ($url !== null): ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($row['title']) ?></a><?php else: ?><?= h($row['title']) ?><?php endif; ?></td>
                                            <td><?= h($row['developer_name'] ?? '—') ?></td>
                                            <td><?= h($row['category_label'] ?? '—') ?></td>
                                            <td class="nowrap"><?= $row['price_amount'] !== null ? h(trim(($row['currency'] ?? '') . ' ' . $row['price_amount'])) : '—' ?></td>
                                            <td></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="muted" style="padding: 10px 22px">App titles and ranks are from this snapshot; developer, category and price show the latest stored app details.</p>
                    <?php else: ?>
                        <div class="trends">
                            <?php foreach ($rows as $row): ?>
                                <article>
                                    <div class="trend-heading">
                                        <h3><?= h($row['title']) ?></h3>
                                        <span class="badge"><?= h($row['traffic_label'] ?? 'Not provided') ?></span>
                                    </div>
                                    <p class="muted">Feed position <?= h($row['list_position']) ?><?php if ($row['source_published_at'] !== null): ?> &middot; Published <?= h($row['source_published_at']) ?> UTC<?php endif; ?></p>
                                    <?php if (!empty($articles[$row['observation_id']])): ?>
                                        <ul>
                                            <?php foreach ($articles[$row['observation_id']] as $article): $url = webUrl($article['url']); ?>
                                                <li>
                                                    <?php if ($url !== null): ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($article['title']) ?></a><?php else: ?><?= h($article['title']) ?><?php endif; ?>
                                                        <?php if ($article['publisher'] !== null): ?><span class="muted"> &middot; <?= h($article['publisher']) ?></span><?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="muted">No related articles saved.</p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <p class="muted" style="padding: 10px 22px">Traffic labels are approximate thresholds as reported by Google. Feed order is not treated as a popularity rank.</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>

</html>
