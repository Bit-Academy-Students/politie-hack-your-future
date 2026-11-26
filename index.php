<?php

declare(strict_types=1);

require_once __DIR__ . '/herkomst.php';
require_once __DIR__ . '/leeftijden.php';

$databasePath = __DIR__ . '/trends.sqlite';

$websiteAliases = [];

$comparisonWindows = ['24h' => 86400, '7d' => 7 * 86400];
$requestedWindow = $_GET['window'] ?? '24h';
$comparisonWindow = is_string($requestedWindow) && isset($comparisonWindows[$requestedWindow])
    ? $requestedWindow : '24h';
$socialOnly = ($_GET['view'] ?? null) === 'social';
$olderOnly = ($_GET['tab'] ?? null) === 'older';
$ageCutoff = (new DateTimeImmutable('today', new DateTimeZone('Europe/Amsterdam')))
    ->sub(new DateInterval('P7Y'))->format('Y-m-d');

function dashboardUrl(string $window, bool $socialOnly, bool $olderOnly): string
{
    return '?' . http_build_query(array_filter([
        'window' => $window,
        'view' => $socialOnly ? 'social' : null,
        'tab' => $olderOnly ? 'older' : null,
    ]));
}

// Apple plaatst sommige sociale apps onder nieuws, video of andere categorieën.
const SOCIAL_APP_IDS = [
    '389801252',  // Instagram
    '835599320',  // TikTok
    '447188370',  // Snapchat
    '333903271',  // Twiter/X
    '544007664',  // YouTube
    '288429040',  // LinkedIn
    '429047995',  // Pinterest
    '460177396',  // Twitch
    '1386412985', // WhatsApp Business
    '1064216828', // Reddit
];

const SOCIAL_DOMAINS = [
    'instagram.com', 'tiktok.com', 'snapchat.com', 'facebook.com', 'fb.com', 'messenger.com',
    'x.com', 'twitter.com', 'threads.net', 'threads.com', 'reddit.com', 'redd.it', 'youtube.com', 'youtu.be',
    'linkedin.com', 'pinterest.com', 'pinterest.nl', 'discord.com', 'discord.gg', 'whatsapp.com', 'wa.me',
    'telegram.org', 't.me', 'bsky.app', 'mastodon.social', 'twitch.tv', 'signal.org',
];

function isSocialDomain(string $host): bool
{
    $host = strtolower(rtrim($host, '.'));
    foreach (SOCIAL_DOMAINS as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }
    return false;
}

function socialSearchDomain(string $term): ?string
{
    if ($term === 'threads') {
        return 'threads.com';
    }
    $domainPattern = '/[a-z0-9-]+(?:\.[a-z0-9-]+)+/i';
    preg_match_all($domainPattern, $term, $hosts);
    foreach ($hosts[0] as $host) {
        if (isSocialDomain($host)) {
            return $host;
        }
    }

    // Herken platformnamen als losse woorden, zonder onbekende domeinen mee te tellen.
    $words = preg_replace($domainPattern, ' ', $term);
    $platforms = [
        'instagram threads|threads app' => 'threads.com',
        'instagram' => 'instagram.com', 'tiktok|tik tok' => 'tiktok.com', 'snapchat' => 'snapchat.com',
        'facebook' => 'facebook.com', 'twitter' => 'x.com', 'reddit' => 'reddit.com',
        'youtube|you tube' => 'youtube.com', 'linkedin' => 'linkedin.com', 'pinterest' => 'pinterest.com',
        'discord' => 'discord.com', 'whatsapp|whats app' => 'whatsapp.com', 'telegram' => 'telegram.org',
        'bluesky' => 'bsky.app', 'mastodon' => 'mastodon.social', 'twitch' => 'twitch.tv',
        'signal messenger|signal app' => 'signal.org',
    ];
    foreach ($platforms as $names => $domain) {
        if (preg_match('/(?<![\p{L}\p{N}])(?:' . $names . ')(?![\p{L}\p{N}])/u', $words)) {
            return $domain;
        }
    }
    return null;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function verifiedChatSource(?string $appId, ?string $domain): ?string
{
    static $apps = null, $domains = [];
    if ($apps === null) {
        $apps = [];
        $contents = @file_get_contents(__DIR__ . '/chat-bronnen.json');
        $entries = $contents === false ? [] : json_decode($contents, true);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $url = $entry['source'] ?? null;
            $source = is_string($url) ? webUrl($url) : null;
            if ($source === null) {
                continue;
            }
            foreach ((array) ($entry['app_store_ids'] ?? []) as $id) {
                if (is_string($id) && ctype_digit($id)) {
                    $apps[$id] = $source;
                }
            }
            foreach ((array) ($entry['domains'] ?? []) as $host) {
                if (is_string($host)) {
                    $domains[strtolower($host)] = $source;
                }
            }
        }
    }

    $host = preg_replace('/\Awww\./', '', strtolower(rtrim($domain ?? '', '.')));
    return $apps[$appId ?? ''] ?? $domains[$host] ?? null;
}

function appDescriptionSuggestsChat(?string $description): bool
{
    $text = mb_strtolower($description ?? '', 'UTF-8');
    $sentences = preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: [];
    $communication = '(?:chat(?:s|ten)?|messag(?:e|es|ing)|berichten)';
    $people = '(?:friends?|other users|other players|other members|followers|each other|vrienden|andere gebruikers|andere spelers|andere leden|volgers|elkaar)';

    foreach ($sentences as $sentence) {
        if (preg_match('/\b(?:chatbots?|ai|kunstmatige intelligentie|automated|automatisch|support|customer service|klantenservice|helpdesk)\b/u', $sentence)
            || preg_match('/\b(?:geen|zonder|niet|no|not|without|disables?)\b.{0,45}\b(?:chat\w*|messag\w*|berichten|priv[eé]berichten)\b/u', $sentence)
            || preg_match('/\b(?:chat\w*|messag\w*|berichten)\b.{0,30}\b(?:unavailable|not supported|not available|niet beschikbaar|uitgeschakeld)\b/u', $sentence)) {
            continue;
        }
        if (preg_match('/\b(?:direct (?:messages?|messaging)|directe berichten|priv[eé]berichten?|private messages?|group chats?|groepschats?)\b/u', $sentence)
            || preg_match('/\b' . $communication . '\b.{0,45}\b' . $people . '\b/u', $sentence)) {
            return true;
        }
    }
    return false;
}

function renderChatBadge(?string $appId = null, ?string $description = null, ?string $domain = null): void
{
    $source = verifiedChatSource($appId, $domain);
    if ($source === null && !appDescriptionSuggestsChat($description)) {
        return;
    }

    $label = $source === null
        ? 'Mogelijke chat tussen gebruikers volgens de appbeschrijving'
        : 'Chat tussen gebruikers bevestigd. Bekijk de bron.';
    $svg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
        . ' stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" aria-hidden="true" focusable="false">'
        . '<path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H6l-3 2v-5.5A7.5 7.5 0 1 1 20 11.5Z"></path>'
        . '<path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01"></path></svg>';
    $text = ' title="' . h($label) . '" aria-label="' . h($label) . '"';
    if ($source === null) {
        echo '<span class="chat-capability chat-possible" role="img"' . $text . '>'
            . $svg . '<span aria-hidden="true">?</span></span>';
    } else {
        echo '<a class="chat-capability" href="' . h($source) . '"' . $text
            . ' target="_blank" rel="noopener noreferrer">' . $svg . '</a>';
    }
}

function webUrl(?string $url): ?string
{
    if ($url === null || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
        ? $url : null;
}

function dutchDate(string $utc, bool $withTime = true): string
{
    $months = [1 => 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    $date = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Amsterdam'));
    $label = $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
    return $withTime ? $label . ', ' . $date->format('H:i') : $label;
}

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
        'SELECT o.*, a.app_id, a.description AS app_description,
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
                    $domain = $row['domain'] ?? $websiteAliases[$term] ?? socialSearchDomain($term) ?? $term;
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
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OSINT · Internettrends in Nederland</title>
    <link rel="stylesheet" href="style.css?v=<?= (int) filemtime(__DIR__ . '/style.css') ?>">
    <script defer src="herkomst.js?v=<?= (int) filemtime(__DIR__ . '/herkomst.js') ?>"></script>
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
                <section class="movers" id="climbs" aria-labelledby="app-hotspots-title">
                    <div class="feed-heading movers-heading">
                        <div>
                            <p class="source-label">Nederlandse App Store · <?= $socialOnly ? 'Sociale apps in de top 100' : 'Top 100 gratis apps' ?></p>
                            <h2 id="app-hotspots-title"><?= $olderOnly ? 'Stijgende apps' : 'Grootste stijgers' ?></h2>
                        </div>
                        <nav class="window-picker" aria-label="Vergelijkingsperiode">
                            <?php foreach (['24h' => '24 uur', '7d' => '7 dagen'] as $window => $label): ?>
                                <a href="<?= h(dashboardUrl($window, $socialOnly, $olderOnly)) ?>#climbs"<?= $window === $comparisonWindow ? ' aria-current="true"' : '' ?>><?= h($label) ?></a>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                    <div class="hotspot-content">
                        <?php if ($appBaseline === null): ?>
                            <p class="comparison-note">Zodra er twee verschillende Nederlandse ranglijsten zijn opgeslagen, zie je hier welke apps zijn gestegen.</p>
                        <?php else: ?>
                            <p class="comparison-note">
                                <strong><?= h(dutchDate($appBaseline['fetched_at'])) ?> → <?= h(dutchDate($appSnapshot['fetched_at'])) ?></strong>
                                <span>De vergelijking gebruikt de opgeslagen ranglijst die het dichtst bij de gekozen periode ligt.</span>
                            </p>
                            <?php if ($shownClimbers === []): ?>
                                <p class="empty-inline"><?= $olderOnly ? 'In deze periode stegen geen apps die aan het leeftijdsfilter voldoen.' : ($socialOnly ? 'Tussen deze twee ranglijsten is geen sociale app gestegen.' : 'Tussen deze twee ranglijsten is geen app gestegen.') ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php foreach (
                            ($olderOnly ? ['climbs' => ['label' => null, 'items' => $shownClimbers]] : [
                                'climbs' => ['label' => null, 'items' => $shownClimbers],
                                'entries' => ['label' => 'Nieuw in de top 100', 'items' => $chartEntries],
                                'legacy' => ['label' => 'Stijgende apps uit 2009 of ouder', 'items' => $legacyRisingApps],
                                'releases' => ['label' => 'Uitgebracht in de afgelopen 30 dagen', 'items' => $recentReleases],
                            ]) as $kind => ['label' => $label, 'items' => $items]
                        ): ?>
                            <?php if ($items === []) {
                                continue;
                            } ?>
                            <?php if ($label !== null): ?><h3 class="list-heading"><?= h($label) ?></h3><?php endif; ?>
                            <ul class="mover-list <?= $kind === 'climbs' ? 'gain-list' : 'support-list' ?>" aria-label="<?= h($label ?? 'Apps op volgorde van aantal gewonnen plaatsen') ?>">
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
                                            <div class="origin-name">
                                                <?php if ($url !== null): ?><a href="<?= h($url) ?>"><?= h($app['title']) ?></a><?php else: ?><span><?= h($app['title']) ?></span><?php endif; ?>
                                                <?php originIcon($app['title'], originFor($app['app_id'], $app['developer_url'])); ?>
                                                <?php renderChatBadge($app['app_id'], $app['app_description']); ?>
                                            </div>
                                            <?php if ($olderOnly || in_array($kind, ['releases', 'legacy'], true)): ?>
                                                <div class="muted">Uitgebracht op <?= h(dutchDate($app['released_at'], false)) ?></div>
                                            <?php elseif ($app['category_label'] !== null): ?>
                                                <div class="muted"><?= h($app['category_label']) ?></div>
                                            <?php endif; ?>
                                            <?php if (count($chartHistory) >= 2): ?>
                                                <details class="muted rank-history">
                                                    <summary>Recente posities</summary>
                                                    <dl>
                                                        <?php foreach ($chartHistory as $chart): ?>
                                                            <dt><?= h(dutchDate($chart['at'])) ?></dt>
                                                            <dd><?= isset($chart['ranks'][$app['item_id']]) ? '#' . h($chart['ranks'][$app['item_id']]) : 'Buiten de top 100' ?></dd>
                                                        <?php endforeach; ?>
                                                    </dl>
                                                </details>
                                            <?php endif; ?>
                                        </div>
                                        <div class="rank-result">
                                            <?php if ($kind === 'climbs'): ?>
                                                <div class="rank-gain"><strong>+<?= h($app['gain']) ?></strong> <span><?= $app['gain'] === 1 ? 'plaats' : 'plaatsen' ?></span></div>
                                                <div class="rank-change"><span>#<?= h($app['previous_rank']) ?></span> <span aria-label="naar">→</span> <strong>#<?= h($app['chart_rank']) ?></strong></div>
                                                <div class="gain-track" aria-hidden="true"><span style="width: <?= (int) round(100 * $app['gain'] / $shownClimbers[0]['gain']) ?>%"></span></div>
                                            <?php elseif ($kind === 'legacy'): ?>
                                                <div class="rank-gain"><strong>+<?= h($app['gain']) ?></strong> <span><?= $app['gain'] === 1 ? 'plaats' : 'plaatsen' ?></span></div>
                                                <div class="rank-change"><span>#<?= h($app['previous_rank']) ?></span> <span aria-label="naar">→</span> <strong>#<?= h($app['chart_rank']) ?></strong></div>
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
                                <h2 id="radar-hotspots-title"><?= $socialOnly ? 'Stijgende sociale platforms' : 'Stijgende domeinen' ?></h2>
                                <?php if ($radarSnapshot !== null): ?>
                                    <p class="muted"><?= h(dutchDate($radarSnapshot['feed_updated_at'] ?? $radarSnapshot['fetched_at'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="hotspot-content">
                            <?php if ($radarSnapshot === null): ?>
                                <p class="empty-inline">Stel Cloudflare Radar in en importeer de ranglijst om te zien welke domeinen in Nederland aan populariteit winnen.</p>
                            <?php elseif ($radarDomains === []): ?>
                                <p class="muted"><?= $olderOnly ? 'In de laatste ranglijst staan geen passende domeinen met een bevestigde leeftijd.' : ($socialOnly ? 'In de laatste meting staan geen herkende sociale platforms.' : 'De laatst opgeslagen ranglijst van Cloudflare is leeg.') ?></p>
                            <?php else: ?>
                                <ol aria-label="Domeinranglijst van Cloudflare Radar">
                                    <?php foreach ($radarDomains as $domain): ?>
                                        <li>
                                            <div>
                                                <div class="origin-name">
                                                    <strong><?= h($domain['domain']) ?></strong>
                                                    <?php originIcon($domain['domain'], originFor(domain: $domain['domain'])); ?>
                                                    <?php renderChatBadge(domain: $domain['domain']); ?>
                                                </div>
                                                <?php if ($olderOnly): ?><p class="muted"><a href="<?= h($domain['source']) ?>">Online in <?= h(substr($domain['since'], 0, 4)) ?></a></p><?php endif; ?>
                                            </div>
                                            <span class="badge">#<?= h($domain['rank']) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                                <?php if ($olderOnly): ?><p class="muted">Volgorde van de laatste Cloudflare-ranglijst.</p><?php endif; ?>
                            <?php endif; ?>
                            <?php if (!$socialOnly): ?>
                                <p class="muted"><a href="https://radar.cloudflare.com/domains/nl">Bekijk de Nederlandse domeinranglijst ↗</a></p>
                            <?php endif; ?>
                        </div>
                    </section>
                    <section aria-labelledby="website-hotspots-title">
                        <div class="feed-heading">
                            <div>
                                <p class="source-label">Google Trends</p>
                                <h2 id="website-hotspots-title"><?= $olderOnly ? 'Stijgende zoekinteresse in websites' : ($socialOnly ? 'Zoektrends rond sociale media' : 'Websites in opkomende zoekopdrachten') ?></h2>
                                <?php if ($olderOnly && $searchBaseline !== null): ?>
                                    <p class="muted"><?= h(dutchDate($searchBaseline['fetched_at'])) ?> → <?= h(dutchDate($searchSnapshot['fetched_at'])) ?></p>
                                <?php elseif ($searchSnapshot !== null): ?>
                                    <p class="muted"><?= h(dutchDate($searchSnapshot['fetched_at'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="hotspot-content">
                            <?php if ($olderOnly): ?>
                                <?php if ($olderSearchGains === []): ?>
                                    <p class="empty-inline">Geen hogere zoekvolumeklassen gevonden voor dezelfde zoektrend in beide metingen.</p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach (array_slice($olderSearchGains, 0, 5) as $lead): ?>
                                            <li>
                                                <div>
                                                    <div class="origin-name">
                                                        <strong><?= h($lead['domain']) ?></strong>
                                                        <?php originIcon($lead['domain'], originFor(domain: $lead['domain'])); ?>
                                                        <?php renderChatBadge(domain: $lead['domain']); ?>
                                                    </div>
                                                    <p class="muted"><?= h($lead['title']) ?> · <a href="<?= h($lead['source']) ?>">Online in <?= h(substr($lead['since'], 0, 4)) ?></a></p>
                                                </div>
                                                <span class="badge"><?= h($lead['previous_label']) ?> → <?= h($lead['traffic_label']) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            <?php elseif ($searchSnapshot === null): ?>
                                <p class="muted">Na de eerste import van Nederlandse Google Trends-gegevens verschijnen hier zoekopdrachten naar websites.</p>
                            <?php elseif ($websiteLeads === []): ?>
                                <p class="empty-inline"><?= $socialOnly ? 'In de laatste meting kwamen geen zoekopdrachten naar herkende sociale platforms voor.' : 'In de laatst opgehaalde zoektrends kwamen geen domeinnamen of gevolgde websites voor.' ?></p>
                            <?php else: ?>
                                <ul>
                                    <?php foreach (array_slice($websiteLeads, 0, 5) as $lead): ?>
                                        <li>
                                            <div>
                                                <div class="origin-name">
                                                    <strong><?= h($lead['domain']) ?></strong>
                                                    <?php originIcon($lead['domain'], originFor(domain: $lead['domain'])); ?>
                                                    <?php renderChatBadge(domain: $lead['domain']); ?>
                                                </div>
                                                <div class="muted">Gezocht op Google: <?= h($lead['title']) ?></div>
                                            </div>
                                            <span class="badge"><?= h($lead['traffic_label'] ?? 'Zoekvolume onbekend') ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <p class="muted">De zoekopdrachten wijzen op interesse in een website. De zoekvolumes zijn schattingen van Google.</p>
                        </div>
                    </section>
                    <?php if ($olderOnly && !$socialOnly): ?>
                        <section aria-labelledby="older-games-title">
                            <div class="feed-heading">
                                <div>
                                    <p class="source-label">Steam · Wereldwijd</p>
                                    <h2 id="older-games-title">Stijgende games</h2>
                                    <?php if ($steamBaseline !== null): ?>
                                        <p class="muted"><?= h(dutchDate($steamBaseline['fetched_at'])) ?> → <?= h(dutchDate($steamSnapshot['fetched_at'])) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="hotspot-content">
                                <?php if ($olderSteamGains === []): ?>
                                    <p class="empty-inline">Geen stijging in het aantal spelers gevonden voor games die aan het leeftijdsfilter voldoen.</p>
                                <?php else: ?>
                                    <ul>
                                        <?php foreach (array_slice($olderSteamGains, 0, 5) as $game): ?>
                                            <li>
                                                <div>
                                                    <div class="origin-name">
                                                        <a href="<?= h($game['source']) ?>"><?= h($game['title']) ?></a>
                                                        <?php originIcon($game['title'], originFor($game['app_id'])); ?>
                                                    </div>
                                                    <p class="muted">Uitgebracht in <?= h(substr($game['since'], 0, 4)) ?></p>
                                                    <p class="muted"><?= number_format($game['before'], 0, ',', '.') ?> → <?= number_format($game['after'], 0, ',', '.') ?> spelers</p>
                                                </div>
                                                <span class="badge">+<?= number_format($game['gain'], 0, ',', '.') ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$olderOnly): ?>
            <h2 class="feeds-title">Bronoverzicht</h2>
            <?php if (!$socialOnly): ?>
            <div class="stats">
                <?php foreach (
                    [
                        //comment
                        'sources' => 'Bronnen',
                        'apps' => 'Apps en games',
                        'queries' => 'Zoekopdrachten',
                        'snapshots' => 'Opgeslagen metingen',
                        'observations' => 'Waarnemingen',
                        'articles' => 'Artikelvermeldingen'
                    ] as $key => $label
                ): ?>
                    <div class="stat"><strong><?= number_format((int) $totals[$key], 0, ',', '.') ?></strong><span class="muted"><?= h($label) ?></span></div>
                <?php endforeach; ?>
            </div>
            <p class="muted overview-note">De totalen tellen alle imports mee. Hieronder zie je de laatste meting per bron, in Nederlandse tijd.</p>
            <?php endif; ?>

            <?php if ($feeds === [] && $radarSnapshot === null): ?>
                <div class="empty">
                    <h2>Nog geen gegevens</h2>
                    <p>Voer <code>php import.php</code> uit en vernieuw daarna deze pagina.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($feeds as $feed):
                ['source' => $source, 'snapshot' => $snapshot, 'rows' => $rows, 'articles' => $articles] = $feed;
                $isApple = $source['provider'] === 'apple';
                $isGoogle = $source['provider'] === 'google_trends';
                $heading = match ($source['provider']) {
                    'apple' => $socialOnly ? 'App Store · Sociale apps in de top 100' : 'App Store · Top 100 gratis apps',
                    'google_trends' => $socialOnly ? 'Google Trends · Sociale media' : 'Google Trends · Opkomende zoekopdrachten',
                    'steam' => 'Steam · Geselecteerde games',
                    default => $source['feed_name'],
                };
            ?>
                <section>
                    <div class="feed-heading">
                        <div>
                            <h2><?= h($heading) ?></h2>
                            <?php if ($snapshot !== false): ?>
                                <p class="muted">Opgehaald op <?= h(dutchDate($snapshot['fetched_at'])) ?> &middot; Meting #<?= h($snapshot['snapshot_id']) ?></p>
                                <?php if ($snapshot['feed_updated_at'] !== null): ?>
                                    <p class="muted">Bron bijgewerkt op <?= h(dutchDate($snapshot['feed_updated_at'])) ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <span class="badge"><?= h(match ($source['country_code']) {
                                                'NL' => 'Nederland',
                                                'GLOBAL' => 'Wereldwijd',
                                                default => $source['country_code']
                                            }) ?> &middot; <?= count($rows) ?> <?= count($rows) === 1 ? 'waarneming' : 'waarnemingen' ?></span>
                    </div>

                    <?php if ($rows === []): ?>
                        <?php if ($socialOnly && $snapshot !== false): ?>
                            <div class="empty">Er zijn in de laatste meting geen resultaten voor dit filter.</div>
                        <?php else: ?>
                            <div class="empty">De laatste meting van deze bron bevat geen waarnemingen. Voer <code>php import.php</code> uit om gegevens op te halen.</div>
                        <?php endif; ?>
                    <?php elseif ($isApple): ?>
                        <div class="scroll" role="region" aria-label="Laatste ranglijst van de App Store" tabindex="0">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">Positie</th>
                                        <th scope="col">App</th>
                                        <th scope="col">Ontwikkelaar</th>
                                        <th scope="col">Categorie</th>
                                        <th scope="col">Prijs</th>
                                        <th scope="col">Grafiek</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): $url = webUrl($row['store_url']); ?>
                                        <tr>
                                            <td><?= h($row['chart_rank'] ?? '—') ?></td>
                                            <td>
                                                <div class="origin-name">
                                                    <?php if ($url !== null): ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($row['title']) ?></a><?php else: ?><span><?= h($row['title']) ?></span><?php endif; ?>
                                                    <?php originIcon($row['title'], originFor($row['app_id'], $row['developer_url'])); ?>
                                                    <?php renderChatBadge($row['app_id'], $row['app_description']); ?>
                                                </div>
                                            </td>
                                            <td><?= h($row['developer_name'] ?? '—') ?></td>
                                            <td><?= h($row['category_label'] ?? '—') ?></td>
                                            <td class="nowrap"><?= $row['price_amount'] !== null ? h(trim(($row['currency'] === 'EUR' ? '€' : ($row['currency'] ?? '')) . ' ' . str_replace('.', ',', $row['price_amount']))) : '—' ?></td>
                                            <td>
                                                <?php if ($row['app_id'] !== null): ?>
                                                    <a href="grafieken.php?id=<?= h(rawurlencode((string) $row['app_id'])) ?>">Grafiek bekijken</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="muted" style="padding: 10px 22px">De appnamen en posities komen uit deze meting. Ontwikkelaar, categorie en prijs komen uit de laatst opgeslagen appgegevens.</p>
                    <?php else: ?>
                        <div class="trends">
                            <?php foreach ($rows as $row):
                                $term = mb_strtolower(trim($row['title']), 'UTF-8');
                                $domain = $isGoogle ? ($row['domain'] ?? $websiteAliases[$term] ?? socialSearchDomain($term)) : null;
                                if ($isGoogle && $domain === null && preg_match('/\A(?:[a-z0-9-]+\.)+[a-z]{2,63}\z/', $term)) {
                                    $domain = $term;
                                }
                            ?>
                                <article>
                                    <div class="trend-heading">
                                        <div class="origin-name">
                                            <h3><?= h($row['title']) ?></h3>
                                            <?php if ($row['app_id'] !== null || $domain !== null): ?>
                                                <?php originIcon($row['title'], originFor($row['app_id'], $row['developer_url'], $domain)); ?>
                                                <?php renderChatBadge($row['app_id'], $row['app_description'], $domain); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($isGoogle): ?><span class="badge"><?= h($row['traffic_label'] ?? 'Zoekvolume onbekend') ?></span><?php endif; ?>
                                    </div>
                                    <p class="muted">Positie in de bronlijst <?= h($row['list_position']) ?><?php if ($row['source_published_at'] !== null): ?> &middot; Gepubliceerd op <?= h(dutchDate($row['source_published_at'])) ?><?php endif; ?></p>
                                    <?php if (!empty($articles[$row['observation_id']])): ?>
                                        <ul>
                                            <?php foreach ($articles[$row['observation_id']] as $article): $url = webUrl($article['url']); ?>
                                                <li>
                                                    <?php if ($url !== null): ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($article['title']) ?></a><?php else: ?><?= h($article['title']) ?><?php endif; ?>
                                                        <?php if ($article['publisher'] !== null): ?><span class="muted"> &middot; <?= h($article['publisher']) ?></span><?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php elseif ($isGoogle): ?>
                                        <p class="muted">Er zijn geen bijbehorende artikelen opgeslagen.</p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($isGoogle): ?>
                            <p class="muted" style="padding: 10px 22px">Google geeft het zoekvolume als geschatte ondergrens. De volgorde komt uit de bronlijst.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>

</html>
