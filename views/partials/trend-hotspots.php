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
