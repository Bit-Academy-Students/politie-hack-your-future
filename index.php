<?php
declare(strict_types=1);

// EDIT THIS to match the existing SQLite file used by import.php.
$databasePath = __DIR__ . '/trends.sqlite';

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

$error = null;
$totals = [];
$feeds = [];
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
        'SELECT * FROM feed_snapshots WHERE source_id = ?
         ORDER BY fetched_at DESC, snapshot_id DESC LIMIT 1'
    );
    $findRows = $db->prepare(
        'SELECT o.*, a.store_url, a.developer_name, a.category_label, a.price_amount, a.currency
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

    foreach ($sources as $source) {
        $findSnapshot->execute([$source['source_id']]);
        $snapshot = $findSnapshot->fetch();
        $rows = [];
        $articles = [];
        if ($snapshot !== false) {
            $findRows->execute([$snapshot['snapshot_id']]);
            $rows = $findRows->fetchAll();
            $findArticles->execute([$snapshot['snapshot_id']]);
            foreach ($findArticles->fetchAll() as $article) {
                $articles[$article['observation_id']][] = $article;
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
    <title>Trends overview</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #172b35; background: #f3f6f7; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        main { max-width: 1200px; margin: auto; padding: 36px 22px 60px; }
        header, .feed-heading, .trend-heading { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
        header { margin-bottom: 26px; }
        h1 { font-size: 30px; letter-spacing: -1px; margin: 0 0 5px; }
        h2 { font-size: 20px; margin: 0 0 6px; }
        h3 { font-size: 17px; margin: 0; }
        p { margin: 6px 0; }
        .muted { color: #5d6d76; font-size: 13px; line-height: 1.6; }
        .stats { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 26px; }
        .stat, section, .empty, .error { background: white; border: 1px solid #dce4e7; border-radius: 12px; }
        .stat { padding: 17px; }
        .stat strong { display: block; font-size: 27px; margin-bottom: 3px; }
        section { margin-bottom: 24px; overflow: hidden; }
        .feed-heading { padding: 22px; align-items: flex-start; }
        .badge { display: inline-block; white-space: nowrap; padding: 6px 10px; border-radius: 6px; background: #e8f4ef; color: #146247; font-size: 13px; font-weight: 600; }
        .scroll { overflow: auto; max-height: 570px; border-top: 1px solid #e4eaed; }
        table { border-collapse: collapse; width: 100%; text-align: left; font-size: 14px; }
        th, td { padding: 13px 18px; border-bottom: 1px solid #e8edef; vertical-align: top; }
        th { position: sticky; top: 0; background: #f8fafb; color: #5d6d76; font-weight: 600; font-size: 12px; }
        tbody tr:last-child td { border-bottom: 0; }
        a { color: #12674d; text-decoration: none; }
        a:hover { text-decoration: underline; }
        a:focus-visible, button:focus-visible { outline: 3px solid #8cc7b6; outline-offset: 3px; }
        button { border: 1px solid #cbd8dc; border-radius: 7px; padding: 9px 15px; background: white; color: #172b35; font: inherit; cursor: pointer; }
        .trends { border-top: 1px solid #e4eaed; }
        article { padding: 20px 22px; border-bottom: 1px solid #e8edef; }
        article:last-child { border-bottom: 0; }
        ul { padding-left: 20px; margin: 12px 0 0; }
        li { padding: 4px 0; line-height: 1.5; font-size: 14px; }
        .empty, .error { padding: 22px; }
        section .empty { border: 0; border-top: 1px solid #e4eaed; border-radius: 0; }
        .error { border-color: #d6a199; background: #fff7f5; }
        .nowrap { white-space: nowrap; }
        @media (max-width: 850px) { .stats { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 480px) { main { padding: 24px 14px; } .stats { grid-template-columns: repeat(2, 1fr); } .feed-heading { flex-wrap: wrap; } th, td { padding: 12px; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Trends overview</h1>
            <p class="muted"><?= h(basename($databasePath)) ?> &middot; All times shown in UTC</p>
        </div>
        <form method="get"><button type="submit">Refresh</button></form>
    </header>

    <?php if ($error !== null): ?>
        <div class="error" role="alert">
            <h2>Could not load the overview</h2>
            <p><?= h($error) ?></p>
            <p class="muted">Check the database path and run schema.sql against that database first.</p>
        </div>
    <?php else: ?>
        <div class="stats">
            <?php foreach (['sources' => 'Sources', 'apps' => 'Apps stored', 'queries' => 'Queries tracked',
                'snapshots' => 'Snapshots saved', 'observations' => 'Observations', 'articles' => 'Article appearances'] as $key => $label): ?>
                <div class="stat"><strong><?= number_format((int) $totals[$key]) ?></strong><span class="muted"><?= h($label) ?></span></div>
            <?php endforeach; ?>
        </div>
        <p class="muted" style="margin-bottom: 18px">Totals include all imports. Each feed below shows its latest saved snapshot. Refresh reloads the database; run import.php to fetch new data.</p>

        <?php if ($feeds === []): ?>
            <div class="empty"><h2>No data yet</h2><p>Run import.php, then refresh this page.</p></div>
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
                            <thead><tr><th scope="col">Rank</th><th scope="col">App</th><th scope="col">Developer</th><th scope="col">Category</th><th scope="col">Price</th></tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): $url = webUrl($row['store_url']); ?>
                                    <tr>
                                        <td><?= h($row['chart_rank'] ?? '—') ?></td>
                                        <td><?php if ($url !== null): ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($row['title']) ?></a><?php else: ?><?= h($row['title']) ?><?php endif; ?></td>
                                        <td><?= h($row['developer_name'] ?? '—') ?></td>
                                        <td><?= h($row['category_label'] ?? '—') ?></td>
                                        <td class="nowrap"><?= $row['price_amount'] !== null ? h(trim(($row['currency'] ?? '') . ' ' . $row['price_amount'])) : '—' ?></td>
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
