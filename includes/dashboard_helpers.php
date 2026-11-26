<?php

declare(strict_types=1);

$databasePath = dirname(__DIR__) . '/trends.sqlite';

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

function hasPublicChat(?string $title, ?string $description): bool
{
    $text = mb_strtolower(trim(($title ?? '') . ' ' . ($description ?? '')), 'UTF-8');
    $groupChat = '/\b(?:group\s+chats?|groepschats?)\b/u';
    $chatTerms = '(?:chat(?:ten)?|messag(?:e|es|ing)|bericht(?:en)?|priv[eé]bericht(?:en)?|directe berichten)';
    $peerTerms = '(?:friends?|family|other users|users|people|communities|community|members|followers|'
        . 'vrienden|familie|andere gebruikers|gebruikers|mensen|leden|volgers|elkaar|anderen)';
    $liveChat = '/\b(?:live|livestream)\b.{0,100}\b(?:interacties?|interactions?)\b.{0,80}\b(?:hosts?|makers|streamers|viewers|kijkers)\b/u';

    return preg_match($groupChat, $text) === 1
        || preg_match($liveChat, $text) === 1
        || preg_match('/\b' . $chatTerms . '\b.{0,100}\b' . $peerTerms . '\b/us', $text) === 1
        || preg_match('/\b' . $peerTerms . '\b.{0,100}\b' . $chatTerms . '\b/us', $text) === 1;
}

function publicChatIcon(): void
{
    echo '<span class="chat-capability" role="img" title="Chat tussen gebruikers" aria-label="Chat tussen gebruikers"'
        . ' style="display:inline-flex;flex:0 0 20px;width:20px;height:20px;align-items:center;justify-content:center;color:var(--blue)">'
        . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
        . ' stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" aria-hidden="true" focusable="false">'
        . '<path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H6l-3 2v-5.5A7.5 7.5 0 1 1 20 11.5Z"></path>'
        . '<path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01"></path></svg></span>';
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
