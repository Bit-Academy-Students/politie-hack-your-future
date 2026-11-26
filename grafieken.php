<?php

declare(strict_types=1);

$databasePath = __DIR__ . '/trends.sqlite';

$appId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$appId || $appId < 1) {
    die('Geen geldige app geselecteerd.');
}

$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// App ophalen
$stmt = $db->prepare(
    'SELECT app_id, title FROM apps WHERE app_id = ?'
);
$stmt->execute([$appId]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    die('App niet gevonden.');
}

// Historische posities ophalen
$stmt = $db->prepare(
    'SELECT f.fetched_at, o.chart_rank
     FROM trend_observations o
     JOIN feed_snapshots f ON f.snapshot_id = o.snapshot_id
     JOIN source_items i ON i.item_id = o.item_id
     WHERE i.app_id = ?
       AND o.chart_rank IS NOT NULL
     ORDER BY f.fetched_at ASC'
);
$stmt->execute([$appId]);

$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>App-grafiek</title>
    <link rel="stylesheet" href="style.css?v=<?= (int) filemtime(__DIR__ . '/style.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<header class="masthead">
    <div class="masthead-top">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true"></span>
            Politie app tracker
        </div>
        <a href="index.php">Terug naar overzicht</a>
    </div>

    <div class="masthead-copy">
        <h1><?= htmlspecialchars($app['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Historische posities in de Nederlandse App Store.</p>
    </div>
</header>

<main>
    <?php if (count($history) < 2): ?>
        <p>Er zijn nog niet genoeg metingen om een grafiek te tonen.</p>
    <?php else: ?>
        <section>
            <h2>Positiegeschiedenis</h2>
            <canvas id="appChart"></canvas>
        </section>

        <script>
            const history = <?= json_encode($history, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

            new Chart(document.getElementById('appChart'), {
                type: 'line',
                data: {
                    labels: history.map(item => item.fetched_at),
                    datasets: [{
                        label: 'App Store positie',
                        data: history.map(item => item.chart_rank),
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            reverse: true,
                            min: 1,
                            title: {
                                display: true,
                                text: 'Positie (lager nummer = beter)'
                            }
                        }
                    }
                }
            });
        </script>
    <?php endif; ?>
</main>

</body>
</html>