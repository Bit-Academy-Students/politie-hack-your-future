<?php

declare(strict_types=1);

$databasePath = dirname(__DIR__) . '/trends.sqlite';

$websiteAliases = ['threads' => 'threads.com'];

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
        $contents = @file_get_contents(dirname(__DIR__) . '/chat-bronnen.json');
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
