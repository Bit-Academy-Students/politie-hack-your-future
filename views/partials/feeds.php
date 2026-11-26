            <h2 class="feeds-title">Bronoverzicht</h2>
            <?php if (!$socialOnly): ?>
            <div class="stats">
                <?php foreach (
                    [
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
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="muted" style="padding: 10px 22px">De appnamen en posities komen uit deze meting. Ontwikkelaar en categorie komen uit de laatst opgeslagen appgegevens.</p>
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
