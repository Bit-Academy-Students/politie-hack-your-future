<section id="app-samenvatting" class="demo-panel" aria-labelledby="app-samenvatting-titel">
    <h2 id="app-samenvatting-titel">App Store-samenvatting</h2>
    <p id="demo-help" class="muted">Ollama vat de App Store-beschrijving samen. Het resultaat wordt automatisch opgeslagen.</p>
    <form id="app-summary-form" method="post" action="<?= h(dashboardUrl($comparisonWindow, $socialOnly, false)) ?>#app-samenvatting">
        <label for="demo-app">App Store-link of app-ID</label>
        <div class="demo-form-row">
            <input id="demo-app" name="app" type="text" value="<?= h($summaryInput) ?>" maxlength="2000"
                placeholder="https://apps.apple.com/nl/app/…" aria-describedby="demo-help"
                required autocomplete="off" autocapitalize="none" spellcheck="false">
            <button type="submit">Maak samenvatting</button>
        </div>
        <p id="demo-status" role="status"></p>
    </form>

    <?php if ($summaryError !== null): ?>
        <div class="demo-message error" role="alert"><?= h($summaryError) ?></div>
    <?php endif; ?>

    <?php if ($summaryResult !== null): ?>
        <article id="app-samenvatting-resultaat" class="demo-result" aria-labelledby="demo-app-name" tabindex="-1">
            <header>
                <h3 id="demo-app-name"><?= h($summaryResult['app_name']) ?></h3>
                <?php if ($summaryResult['developer_name'] !== null && $summaryResult['developer_name'] !== ''): ?>
                    <p class="muted"><?= h($summaryResult['developer_name']) ?></p>
                <?php endif; ?>
            </header>
            <div>
                <h4>Waarvoor dient de app?</h4>
                <p><?= h($summaryResult['summary']) ?></p>
            </div>
            <div>
                <h4>Belangrijkste functies</h4>
                <?php if ($summaryResult['capabilities'] === []): ?>
                    <p>De App Store-beschrijving noemt geen concrete functies.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($summaryResult['capabilities'] as $capability): ?>
                            <li><?= h($capability) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <footer class="demo-source">
                <a href="https://apps.apple.com/<?= h($summaryResult['country_code']) ?>/app/id<?= h($summaryResult['app_id']) ?>">Bekijk in de App Store</a>
                <span class="muted">Ollama · <?= h($summaryResult['model']) ?> · <?= h(dutchDate($summaryResult['generated_at'])) ?></span>
            </footer>
        </article>
    <?php endif; ?>

    <?php if ($savedSummaries !== []): ?>
        <details class="demo-saved">
            <summary>Opgeslagen samenvattingen (<?= count($savedSummaries) ?>)</summary>
            <ul>
                <?php foreach ($savedSummaries as $savedSummary): ?>
                    <li>
                        <a href="<?= h(dashboardUrl($comparisonWindow, $socialOnly, false, $savedSummary['summary_key'])) ?>#app-samenvatting-resultaat"<?= $savedSummary['summary_key'] === $summaryKey ? ' aria-current="true"' : '' ?>><?= h($savedSummary['app_name']) ?></a>
                        <span class="muted"> · <?= h(strtoupper($savedSummary['country_code'])) ?> · <?= h(dutchDate($savedSummary['generated_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</section>
