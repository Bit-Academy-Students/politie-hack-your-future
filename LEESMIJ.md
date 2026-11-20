# TRENDRADAR — OPENBARE DATA EN HISTORIE

Deze versie haalt Nederlandse App Store- en Google Trends-gegevens op met PHP,
slaat iedere succesvolle import op in SQLite en toont een overzicht van de
laatste opgeslagen gegevens. Er zijn geen npm-pakketten nodig.

## Benodigdheden

- PHP 8.1 of nieuwer.
- PDO SQLite, mbstring en OpenSSL ingeschakeld.
- SimpleXML beschikbaar; dit is al ingeschakeld in de gecontroleerde PHP-installatie.
- `allow_url_fopen = On` voor het ophalen van de HTTPS-bronnen.
- SQLite 3.24 of nieuwer. De SQLite-bibliotheek van PDO SQLite is voldoende
  voor de PHP-scripts; het losse `sqlite3`-programma is optioneel.

Op Windows kun je de benodigde extensies inschakelen in `php.ini`:

```ini
extension=pdo_sqlite
extension=mbstring
extension=openssl
allow_url_fopen = On
```

De actieve configuratie en modules controleer je met:

```powershell
php --ini
php -m
```

## Starten

1. Open een terminal in de projectmap met `import.php` en `index.php`.
2. Maak je SQLite-database aan of open je bestaande database en voer `schema.sql`
   in de submap `sql` uit.
   Met het optionele SQLite-programma kan dat zo:

   ```powershell
   sqlite3 trends.sqlite ".read sql/schema.sql"
   ```

3. Controleer `$databasePath` in **beide** PHP-bestanden. In `import.php` staat
   dit onderaan, in `index.php` bovenaan. Standaard gebruiken ze:

   ```php
   $databasePath = __DIR__ . '/trends.sqlite';
   ```

4. Haal de gegevens op:

   ```powershell
   php import.php
   ```

5. Start de lokale webserver:

   ```powershell
   php -S 127.0.0.1:4173
   ```

6. Open [het overzicht](http://127.0.0.1:4173/) in je browser.

PHP haalt de bronnen tijdens het importeren op vanaf de server. Daardoor heeft
de browser geen externe CORS-toegang nodig. Open de overzichtspagina via de
PHP-server, zodat de PHP-code wordt uitgevoerd.

## Gegevens bijwerken

Voer `php import.php` opnieuw uit wanneer je nieuwe gegevens wilt opslaan. Het
script probeert de ingestelde bronnen en toont hoeveel items per bron zijn geïmporteerd.
Als één bron mislukt, worden de overige bronnen alsnog geprobeerd. Bij een fout eindigt
het script met exitcode 1.

De knop **Refresh** op de overzichtspagina leest de database opnieuw. Nieuwe
brondata wordt opgehaald door het importscript. Voor regelmatige metingen kun je
`php import.php` zelf periodiek uitvoeren, bijvoorbeeld via Windows Taakplanner.

## Database en opslag

De database bevat deze tabellen:

| Tabel | Inhoud |
| --- | --- |
| `sources` | Provider, feed, land en vaste bron-URL. |
| `apps` | Laatst bekende appgegevens, zoals naam, ontwikkelaar, categorie en prijs. |
| `app_images` | App-iconen per formaat. |
| `feed_snapshots` | Ophaaltijd, beschikbare bronupdate en het oorspronkelijke JSON- of XML-antwoord. |
| `source_items` | Herbruikbare identificatie van apps en zoektermen binnen een bron. |
| `trend_observations` | Waarnemingen per snapshot, inclusief rang of verkeersklasse. |
| `related_articles` | Nieuwsartikelen bij een waarneming uit Google Trends. |

Iedere succesvolle import maakt een nieuw snapshot met waarnemingen, ook als het
bronantwoord gelijk is gebleven. Bestaande apps en zoektermen worden hergebruikt.
Appgegevens en iconen worden bijgewerkt; eerder opgeslagen waarnemingen blijven
bewaard. Artikelen worden per waarneming opgeslagen en kunnen daardoor in meerdere
snapshots voorkomen.

Elke bronimport gebruikt één transactie. Als er tijdens de opslag iets misgaat,
worden alle wijzigingen van die import teruggedraaid. De eerder opgeslagen data
blijft beschikbaar. Het overzicht toont per bron het meest recente opgeslagen
snapshot, met de ophaaltijd erbij.

De opslag is bedoeld voor historie. Er is geen automatische vervaldatum of
opschoning; de database groeit bij iedere succesvolle import. Alle datums en
tijden worden als UTC-tekst opgeslagen. Prijzen worden als de oorspronkelijke
decimale tekst opgeslagen om de precisie te behouden.

Standaard staat de database naast de PHP-bestanden. Wil je deze buiten de
publieke webmap bewaren, maak dan bijvoorbeeld een map `data` naast de projectmap
en stel in beide scripts hetzelfde pad in:

```php
$databasePath = __DIR__ . '/../data/trendradar.sqlite';
```

Maak de database en tabellen op die locatie vooraf aan. PHP heeft leesrechten
nodig voor het overzicht en schrijfrechten op de database en de bijbehorende map
voor imports. Het databasepad wordt ingesteld met `$databasePath` in beide scripts.

## Aangesloten bronnen

### Apple App Store

Bron: [Nederlandse top 100 gratis apps](https://itunes.apple.com/nl/rss/topfreeapplications/limit=100/json).

De functie `importAppleApps(PDO $db)` slaat appgegevens, iconen en chartposities
op. Het App Store-ID identificeert een app. De positie in de feed wordt opgeslagen
als `chart_rank`.

De chart geeft rangposities weer. Deze feed levert geen download- of
gebruikersaantallen.

### Google Trends

Bron: [Nederlandse Trending Now RSS-feed](https://trends.google.com/trending/rss?geo=NL).

De functie `importGoogleTrends(PDO $db)` slaat zoektermen, publicatietijden,
verkeersklassen, afbeeldingen en bijbehorende nieuwsartikelen op. Zoektermen
worden binnen hun bron herkend met een hash van de kleine letters en
genormaliseerde witruimte in de term.

Een label zoals `500+` wordt ongewijzigd bewaard, met waar mogelijk een numerieke
ondergrens voor filtering. Het is een geschatte verkeersklasse. Het XML-bestand
specificeert geen meetvenster; tel waarden uit opeenvolgende snapshots daarom
niet bij elkaar op als een totaal zoekvolume. De feedvolgorde wordt opgeslagen
als lijstpositie en wordt niet als populariteitsrang behandeld.

### Cloudflare Radar (optioneel)

Cloudflare Radar geeft een ranglijst van domeinen die stijgen in Nederland,
op basis van metingen van de 1.1.1.1 DNS-resolver.
[Nederlandse ranglijst](https://radar.cloudflare.com/domains/nl).

Maak een Cloudflare API-token met de rechten voor het rankingendpoint en zet
`CLOUDFLARE_API_TOKEN` in de omgeving van de terminal waarin je importeert.
In PowerShell kan dat met een invoerprompt waarbij het token verborgen blijft:

```powershell
$secret = Read-Host 'Cloudflare API-token' -AsSecureString
$env:CLOUDFLARE_API_TOKEN = [System.Net.NetworkCredential]::new('', $secret).Password
Remove-Variable secret
php import.php
Remove-Item Env:CLOUDFLARE_API_TOKEN
```

De importer gebruikt `location=NL` en `rankingType=TRENDING_RISE`.
Het antwoord gaat als JSON naar `feed_snapshots`. Het bestaande databaseschema
volstaat. Zonder token verwerkt het script de andere bronnen zoals gebruikelijk.
[Rechten en parameters voor het endpoint](https://developers.cloudflare.com/api/resources/radar/subresources/ranking/methods/top/).

## Overzichtspagina

`index.php` toont:

- App Store-stijgers en apps die in de laatste 30 dagen zijn uitgebracht en in de top 100 staan.
- Uitklapbare recente posities uit maximaal vijf verschillende chartupdates in zeven dagen.
- De Nederlandse Cloudflare Radar-ranglijst zodra er een snapshot is opgeslagen.
- Websitezoekopdrachten uit Google Trends, op basis van domeinen of namen in `$websiteAliases`.

- Totalen voor bronnen, apps, zoektermen, snapshots, waarnemingen en artikelvermeldingen.
- De volledige appchart uit het laatste Apple-snapshot.
- De zoektermen en gerelateerde nieuwsartikelen uit het laatste Google-snapshot.
- De ophaaltijd en, indien beschikbaar, de bronupdatetijd.

Appnamen en rangen komen uit het getoonde snapshot. Ontwikkelaar, categorie en
prijs komen uit de laatst opgeslagen appgegevens. Een ontbrekende database,
ontbrekende tabellen of een lege database krijgt een melding op de pagina.

De overzichtspagina leest alleen uit de database. De browser hoeft niet open
te blijven voor imports die je afzonderlijk uitvoert.

## Historie en interpretatie

Historie begint bij de eerste import en wordt bij elke volgende import uitgebreid.
Historische gegevens worden niet met terugwerkende kracht ingevuld. De huidige
overzichtspagina toont de nieuwste snapshots; de eerdere waarnemingen zijn in
SQLite beschikbaar voor verdere analyse of grafieken.

App Store-rangen en Google-verkeersklassen meten verschillende zaken. Er wordt
geen gezamenlijke populariteitsscore berekend. Een item dat ontbreekt in een
snapshot is daarin niet waargenomen; dat betekent geen nul downloads of nul
zoekopdrachten.

## Privacy en lokaal gebruik

De scripts verwerken openbare brongegevens en bewaren deze in de ingestelde
SQLite-database. De meegeleverde pagina bevat geen analytics of bezoekersprofielen.
Snapshots en historie worden op de server opgeslagen.

Het startcommando bindt de ontwikkelserver aan `127.0.0.1`, voor toegang vanaf
dezelfde computer. De importer gebruikt twee vaste bron-URL's. Externe bronlinks
openen de oorspronkelijke websites. De gebruiksvoorwaarden van de bronnen zijn
van toepassing.

## Bestanden

| Bestand | Functie |
| --- | --- |
| `schema.sql` | Alle tabellen, relaties en indexen voor SQLite. |
| `import.php` | Importfuncties en het startpunt voor imports via de terminal. |
| `index.php` | Overzichtspagina met ingebouwde vormgeving. |
| `README.md` | Installatie, gebruik en uitleg over de gegevens. |

## Controle

PHP-syntaxcontrole en SQLite-integratiecontroles zijn uitgevoerd met de aangeleverde
voorbeelden en opgehaalde bronantwoorden. Daarbij zijn herhaalde imports,
artikelverwerking, UTC-tijden, verkeersklassen, rollback en database-integriteit
gecontroleerd. Voor het overzicht zijn ook lege databases, ontbrekende tabellen,
de selectie van de laatste snapshots en veilige tekstweergave gecontroleerd.
