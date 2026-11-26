<?php
declare(strict_types=1);

// Bij bronnen die alleen een jaar noemen, geldt 31 december als uiterste datum.
const WEBSITE_STARTS = [
    'google.com' => ['1998-12-31', 'https://about.google/company-info/our-story/'],
    'facebook.com' => ['2004-02-04', 'https://about.fb.com/ltam/company.info/'],
    'youtube.com' => ['2005-04-23', 'https://blog.youtube/news-and-events/youtube-at-15-my-personal-journey/'],
    'linkedin.com' => ['2003-05-05', 'https://www.linkedin.com/blog/member/archive/cinco-eighth-birthday'],
    'reddit.com' => ['2005-06-30', 'https://redditinc.com/hubfs/Reddit%20Inc/Content/PDFs/Docket%20Stamped%20Complaint.pdf'],
    'twitch.tv' => ['2011-12-31', 'https://blog.twitch.tv/en/2021/09/22/the-twitchcon-blog-youve-been-waiting-for/'],
    'pinterest.com' => ['2010-12-31', 'https://newsroom.pinterest.com/company/'],
    'whatsapp.com' => ['2015-01-21', 'https://blog.whatsapp.com/whats-app-web/'],
    'bol.com' => ['1999-12-31', 'https://over.bol.com/nl/'],
    'marktplaats.nl' => ['1999-12-31', 'https://www.marktplaats.nl/i/help/over-marktplaats/marktplaats-geschiedenis.dot.html'],
    'thuisbezorgd.nl' => ['2000-01-01', 'https://www.thuisbezorgd.nl/deals/food-trend-rapport-2022/images/Food_Trends_Report_Netherlands_2022.pdf'],
];

const STEAM_RELEASES = [
    'steam:570' => ['2013-07-09', 'https://store.steampowered.com/app/570/'],
    'steam:578080' => ['2017-12-21', 'https://store.steampowered.com/app/578080/'],
    'steam:252490' => ['2018-02-08', 'https://store.steampowered.com/app/252490/'],
    // Steam behoudt voor app-ID 730 de datum van voorganger CS:GO.
    'steam:730' => ['2023-09-27', 'https://store.steampowered.com/oldnews/?appids=730&enddate=1696143600&feed=steam_community_announcements'],
];

function olderWebsite(string $site, string $cutoff): ?array
{
    $site = preg_replace('/\Awww\./', '', strtolower(rtrim($site, '.')));
    $domain = match ($site) {
        'bol', 'bol.com' => 'bol.com',
        'marktplaats' => 'marktplaats.nl',
        'thuisbezorgd' => 'thuisbezorgd.nl',
        'm.youtube.com', 'youtu.be' => 'youtube.com',
        'web.whatsapp.com' => 'whatsapp.com',
        default => $site,
    };
    $record = WEBSITE_STARTS[$domain] ?? null;
    return $record !== null && $record[0] < $cutoff
        ? ['domain' => $domain, 'since' => $record[0], 'source' => $record[1]]
        : null;
}
