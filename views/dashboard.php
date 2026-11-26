<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OSINT · Internettrends in Nederland</title>
    <link rel="stylesheet" href="style.css?v=<?= (int) filemtime(dirname(__DIR__) . '/style.css') ?>">
    <script defer src="herkomst.js?v=<?= (int) filemtime(dirname(__DIR__) . '/herkomst.js') ?>"></script>
</head>

<body>
    <main>
        <header class="masthead">
            <div class="masthead-top">
                <div class="brand"><span class="brand-mark" aria-hidden="true"></span>OSINT</div>
                <form method="get">
                    <input type="hidden" name="window" value="<?= h($comparisonWindow) ?>">
                    <?php if ($socialOnly): ?><input type="hidden" name="view" value="social"><?php endif; ?>
                    <?php if ($olderOnly): ?><input type="hidden" name="tab" value="older"><?php endif; ?>
                    <button type="submit">Overzicht vernieuwen</button>
                </form>
            </div>
            <div class="masthead-copy">
                <h1>Internettrends in Nederland</h1>
                <p>Volg welke apps stijgen in de Nederlandse App Store en welke websites meer aandacht krijgen.</p>
            </div>
        </header>

        <?php if ($error !== null): ?>
            <div class="error" role="alert">
                <h2>Het overzicht kon niet worden geladen</h2>
                <p><?= h($error) ?></p>
            </div>
        <?php else: ?>
            <div class="dashboard-filters">
                <nav class="window-picker view-filter" aria-label="Dashboardpagina">
                    <a href="<?= h(dashboardUrl($comparisonWindow, $socialOnly, false)) ?>"<?= !$olderOnly ? ' aria-current="page"' : '' ?>>Overzicht</a>
                    <a href="<?= h(dashboardUrl($comparisonWindow, $socialOnly, true)) ?>"<?= $olderOnly ? ' aria-current="page"' : '' ?>>Grootste stijgers · 7+ jaar</a>
                </nav>
                <nav class="window-picker view-filter" aria-label="Filter op soort trend">
                    <a href="<?= h(dashboardUrl($comparisonWindow, false, $olderOnly)) ?>"<?= !$socialOnly ? ' aria-current="true"' : '' ?>>Alles</a>
                    <a href="<?= h(dashboardUrl($comparisonWindow, true, $olderOnly)) ?>"<?= $socialOnly ? ' aria-current="true"' : '' ?>>Alleen sociale media</a>
                </nav>
            </div>
            <p class="muted chat-legend">Het chat-icoon opent de bevestigende bron. Een vraagteken betekent dat de appbeschrijving op chat wijst. Zonder icoon is de chatfunctie onbekend.</p>
            <?php if ($olderOnly): ?>
                <p class="muted older-note">Uitgebracht of online vóór <?= h(dutchDate($ageCutoff, false)) ?>. Elke bron heeft een eigen ranglijst.</p>
            <?php endif; ?>
            <div class="hotspots">
                <?php require __DIR__ . '/partials/app-hotspots.php'; ?>
                <div class="hotspot-column">
                    <?php require __DIR__ . '/partials/trend-hotspots.php'; ?>
                </div>
            </div>
            <?php if (!$olderOnly): ?>
                <?php require __DIR__ . '/partials/feeds.php'; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>

</html>
