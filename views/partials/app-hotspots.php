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
                                                <?php if (hasPublicChat($app['app_title'], $app['app_description'])): publicChatIcon(); endif; ?>
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
