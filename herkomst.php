<?php
declare(strict_types=1);

// Organisatie, land van oorsprong, bron, Apple-ontwikkelaars-ID's, domeinen en uitzonderingen per app.
const ORIGIN_COMPANIES = [
    ['Shopify', 'Canada', 'https://investors.shopify.com/files/doc_financials/2020/q4/Press-Release-Q4-2020.pdf', ['371294475'], ['shopify.com', 'shop.app']],
    ['Anthropic', 'Verenigde Staten', 'https://www.anthropic.com/transparency/voluntary-commitments/security%26amp%3Bprivacy', ['1719695350'], ['anthropic.com', 'claude.ai']],
    ['OpenAI', 'Verenigde Staten', 'https://openai.com/global-affairs/testimony-of-sam-altman-before-the-us-senate/', ['1684349733'], ['openai.com', 'chatgpt.com']],
    ['Visma', 'Noorwegen', 'https://www.visma.com/who-we-are', ['850373591'], ['visma.com']],
    ['Microsoft', 'Verenigde Staten', 'https://news.microsoft.com/facts-about-microsoft/', ['298856275'], ['microsoft.com', 'bing.com', 'outlook.com', 'live.com']],
    ['Google', 'Verenigde Staten', 'https://about.google/company-info/our-story/', ['281956209'], ['google.com', 'google.nl', 'gmail.com', 'youtube.com', 'youtu.be']],
    ['Amazon', 'Verenigde Staten', 'https://ir.aboutamazon.com/faqs/default.aspx', ['335187486', '297606954'], ['amazon.com', 'amazon.nl', 'primevideo.com']],
    ['Meta', 'Verenigde Staten', 'https://investor.atmeta.com/files/doc_downloads/2024/05/2024-anti-slavery-and-human-trafficking-statement.pdf', ['284882218'], ['facebook.com', 'fb.com', 'messenger.com', 'meta.ai']],
    ['Instagram', 'Verenigde Staten', 'https://about.fb.com/news/2012/04/facebook-to-acquire-instagram/', ['389801255'], ['instagram.com', 'threads.net', 'threads.com']],
    ['WhatsApp', 'Verenigde Staten', 'https://about.fb.com/news/2014/02/facebook-to-acquire-whatsapp/', [], ['whatsapp.com', 'wa.me'], ['310633997', '1386412985']],
    ['ByteDance', 'China', 'https://www.bytedance.com/about?locale=en', ['1497663561'], ['tiktok.com', 'capcut.com', 'bytedance.com'], ['835599320']],
    ['Snap Inc.', 'Verenigde Staten', 'https://www.sec.gov/Archives/edgar/data/1564408/000156459018002721/snap-10k_20171231.htm', [], ['snapchat.com'], ['447188370']],
    ['Twitter / X', 'Verenigde Staten', 'https://www.sec.gov/Archives/edgar/data/1418091/000141809120000037/twtr-20191231.htm', [], ['x.com', 'twitter.com'], ['333903271']],
    ['Reddit', 'Verenigde Staten', 'https://www.sec.gov/Archives/edgar/data/1713445/000171344525000096/redditannualreport2024.pdf', [], ['reddit.com', 'redd.it'], ['1064216828']],
    ['Discord', 'Verenigde Staten', 'https://discord.com/company-information', [], ['discord.com', 'discord.gg'], ['985746746']],
    ['Signal Technology Foundation', 'Verenigde Staten', 'https://signal.org/donate/', [], ['signal.org'], ['874139669']],
    ['Mastodon', 'Duitsland', 'https://joinmastodon.org/about', [], ['mastodon.social', 'joinmastodon.org']],
    ['LinkedIn', 'Verenigde Staten', 'https://press.linkedin.com/content/dam/press/docs/linkedin-company-fact-sheet-12-08-16.pdf', ['288429043'], ['linkedin.com']],
    ['Pinterest', 'Verenigde Staten', 'https://newsroom.pinterest.com/company/', [], ['pinterest.com', 'pinterest.nl'], ['429047995']],
    ['Twitch', 'Verenigde Staten', 'https://blog.twitch.tv/en/2023/03/16/16-years-of-twitch/', [], ['twitch.tv'], ['460177396']],
    ['Sony Interactive Entertainment', 'Japan', 'https://www.sony.com/SonyInfo/CorporateInfo/History/company/', ['912472174'], ['playstation.com']],
    ['Samsung Electronics', 'Zuid-Korea', 'https://news.samsung.com/global/the-history-of-samsung-electronics-1-paving-a-new-path-19681970', ['359580642'], ['samsung.com', 'smartthings.com']],
    ['Valve', 'Verenigde Staten', 'https://www.valvesoftware.com/en/press', [], ['steampowered.com', 'steamcommunity.com', 'valvesoftware.com'], ['steam:730', 'steam:570']],
    ['PUBG Studios / KRAFTON', 'Zuid-Korea', 'https://press.krafton.com/PUBG-CORPORATION-MERGES-WITH-KRAFTON-INC', [], ['pubg.com', 'krafton.com'], ['steam:578080']],
    ['Facepunch Studios', 'Verenigd Koninkrijk', 'https://facepunch.com/', [], ['facepunch.com'], ['steam:252490']],
    ['Vinted', 'Litouwen', 'https://careers.vinted.com/about-vinted', ['554164035'], ['vinted.com', 'vinted.nl']],
    ['Bolt', 'Estland', 'https://bolt.eu/en/company/', ['675033633'], ['bolt.eu']],
    ['Klarna', 'Zweden', 'https://owp.klarna.com/legacy/assets/sites/3/2022/01/06111530/Web-Klarna-Bank-AB-publ-response-to-HM-Treasury-Consultation-on-Regulation-of-Buy-Now-Pay-Later-January-2022-.pdf', ['1075880092'], ['klarna.com']],
    ['IKEA', 'Zweden', 'https://www.ikea.com/global/en/newsroom/corporate/ikea-thanks-for-the-first-75-wonderful-years-180728/', ['339722471'], ['ikea.com', 'ikea.nl']],
    ['Revolut', 'Verenigd Koninkrijk', 'https://www.revolut.com/news/revolut_tops_20_million_retail_customers_on_seventh_anniversary/', ['932493381'], ['revolut.com']],
    ['Zalando', 'Duitsland', 'https://corporate.zalando.com/en/about-us', ['488662889'], ['zalando.nl', 'zalando.com', 'zalando.de']],
    ['Lidl', 'Duitsland', 'https://www.lidl.com/c/about-us/s10089074', ['398474143'], ['lidl.nl', 'lidl.com', 'lidl.de']],
    ['Duolingo', 'Verenigde Staten', 'https://careers.duolingo.com/locations/pittsburgh', ['570060151'], ['duolingo.com']],
    ['Uber', 'Verenigde Staten', 'https://www.uber.com/us/en/newsroom/ubers-founding/', ['368677371'], ['uber.com']],
    ['Cloudflare', 'Verenigde Staten', 'https://www.cloudflare.com/our-story/', [], ['cloudflare.com']],
    ['Geopost', 'Frankrijk', 'https://www.geopost.com/fr/mentions-legales/', ['1060008009'], ['geopost.com', 'dpd.com']],
    ['My Jewellery', 'Nederland', 'https://www.my-jewellery.com/nl-nl/over-my-jewellery', ['1820427936'], ['my-jewellery.com']],
    ['bol', 'Nederland', 'https://over.bol.com/nl/faq/pers/', ['615282421'], ['bol.com']],
    ['Action', 'Nederland', 'https://www.action.com/nl-nl/over-ons/geschiedenis/', ['1531295964'], ['action.com']],
    ['Albert Heijn', 'Nederland', 'https://over.ah.nl/overzicht/geschiedenis', ['542198840'], ['ah.nl']],
    ['Jumbo', 'Nederland', 'https://www.jumbo.com/nieuws/over-ons-bedrijf/', ['512210597'], ['jumbo.com']],
    ['Thuisbezorgd.nl', 'Nederland', 'https://www.thuisbezorgd.nl/deals/food-trend-rapport-2022/images/Food_Trends_Report_Netherlands_2022.pdf', [], ['thuisbezorgd.nl']],
    ['WeTransfer', 'Nederland', 'https://wetransfer.com/docs/legal/WeTransfer_TOS_20190430.pdf', ['485103881'], ['wetransfer.com', 'we.tl']],
    ['RTL Nederland', 'Nederland', 'https://www.rtl.nl/over', ['398467676'], ['rtl.nl', 'videoland.com']],
    ['Translink', 'Nederland', 'https://www.ovpay.nl/privacy-ovpay-app', ['798508349'], ['ovpay.nl', 'translink.nl']],
    ['NS', 'Nederland', 'https://www.ns.nl/bedrijfsinformatie', ['370362304'], ['ns.nl']],
    ['PostNL', 'Nederland', 'https://www.postnl.nl/over-postnl/over-ons/', ['941607669'], ['postnl.nl']],
    ['Booking.com', 'Nederland', 'https://careers.booking.com/our-journey/', ['367003842'], ['booking.com']],
    ['The Sting', 'Nederland', 'https://www.thesting.com/nl-nl/over-ons.html', ['1520363843'], ['thesting.com']],
    ['Kruidvat', 'Nederland', 'https://www.kruidvat.nl/pers/', ['531631061'], ['kruidvat.nl']],
    ['HEMA', 'Nederland', 'https://corporate.hema.com/hema-geschiedenis/', ['375634615'], ['hema.nl', 'hema.com']],
    ['AFAS Software', 'Nederland', 'https://www.afas.nl/bedrijfspagina/de-afas-organisatie', ['719524915'], ['afas.nl']],
    ['KLM', 'Nederland', 'https://www.klm.com/information/corporate/history', ['371664248'], ['klm.nl', 'klm.com']],
    ['ANWB', 'Nederland', 'https://www.anwb.nl/over-anwb/geschiedenis/oprichting-en-organisatie/hoe-het-allemaal-begon', ['421372358'], ['anwb.nl']],
    ['ABN AMRO', 'Nederland', 'https://www.abnamro.com/en/about-abn-amro/information/our-history', ['438185490'], ['abnamro.nl', 'abnamro.com', 'tikkie.me']],
    ['9292', 'Nederland', 'https://9292.nl/contact/', ['556557693'], ['9292.nl']],
    ['VodafoneZiggo', 'Nederland', 'https://www.vodafoneziggo.nl/nieuws/vodafoneziggo-van-start/', [], ['vodafoneziggo.nl'], ['496189385']],
    ['Marktplaats', 'Nederland', 'https://www.marktplaats.nl/i/help/over-marktplaats/marktplaats-geschiedenis.dot.html', ['318979525'], ['marktplaats.nl']],
    ['Polarsteps', 'Nederland', 'https://www.polarsteps.com/nl/about', ['947925762'], ['polarsteps.com']],
    ['ChipSoft', 'Nederland', 'https://www.chipsoft.com/nl-nl/organisatie/onze-geschiedenis/', ['1073926311'], ['chipsoft.com', 'chipsoft.nl']],
    ['Nationale Postcode Loterij', 'Nederland', 'https://www.postcodeloterij.nl/over-ons/geschiedenis', ['1068416548'], ['postcodeloterij.nl']],
    ['Rijksoverheid', 'Nederland', 'https://www.rijksoverheid.nl/themas/overheid-en-democratie/digitale-overheid/vraag-en-antwoord/identiteitsfraude/ben-ik-verplicht-om-een-kopie-van-mijn-identiteitsbewijs-te-geven-aan-een-bedrijf', ['575932944'], ['rijksoverheid.nl', 'digid.nl']],
    ['Home Office', 'Verenigd Koninkrijk', 'https://www.gov.uk/government/news/uk-enforces-digital-permission-to-travel', ['1478906988'], ['gov.uk']],
];

function originFor(?string $appId = null, ?string $developerUrl = null, ?string $domain = null): ?array
{
    static $appOrigins = [], $developerOrigins = [], $domainOrigins = [];
    if ($domainOrigins === []) {
        foreach (ORIGIN_COMPANIES as $entry) {
            [$company, $country, $source, $developers, $domains] = $entry;
            $origin = ['company' => $company, 'country' => $country, 'source' => $source];
            foreach ($entry[5] ?? [] as $id) {
                $appOrigins[$id] = $origin;
            }
            foreach ($developers as $id) {
                $developerOrigins[$id] = $origin;
            }
            foreach ($domains as $host) {
                $domainOrigins[$host] = $origin;
            }
        }
    }

    if ($appId !== null && isset($appOrigins[$appId])) {
        return $appOrigins[$appId];
    }
    if ($developerUrl !== null && parse_url($developerUrl, PHP_URL_HOST) === 'apps.apple.com'
        && preg_match('~/id([0-9]+)$~', (string) parse_url($developerUrl, PHP_URL_PATH), $match)) {
        return $developerOrigins[$match[1]] ?? null;
    }
    $host = strtolower(rtrim($domain ?? '', '.'));
    if (!preg_match('/\A(?:[a-z0-9-]+\.)+[a-z]{2,63}\z/', $host)) {
        return null;
    }
    while (str_contains($host, '.')) {
        if (isset($domainOrigins[$host])) {
            return $domainOrigins[$host];
        }
        $host = substr($host, strpos($host, '.') + 1);
    }
    return null;
}

function originIcon(string $name, ?array $origin): void
{
    $title = 'Land van herkomst';
    $detail = $origin === null ? 'Onbekend' : $origin['country'] . ' · ' . $origin['company'];
    $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
    <button type="button" class="origin-icon" aria-label="<?= $escape($title . ' van ' . $name . ': ' . $detail) ?>"
        data-origin-title="<?= $escape($title) ?>" data-origin-detail="<?= $escape($detail) ?>">
        <span aria-hidden="true">i</span>
    </button>
    <?php
}
