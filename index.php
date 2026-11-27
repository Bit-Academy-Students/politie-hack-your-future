<?php

declare(strict_types=1);

require_once __DIR__ . '/herkomst.php';
require_once __DIR__ . '/leeftijden.php';
require_once __DIR__ . '/includes/dashboard_helpers.php';
require_once __DIR__ . '/app-summaries.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$importDashboardUrl = dashboardUrl($comparisonWindow, $socialOnly, $olderOnly);
session_start(['use_strict_mode' => 1, 'cookie_httponly' => 1, 'cookie_samesite' => 'Lax']);
$importToken = $_SESSION['import_token'] ??= bin2hex(random_bytes(32));

if ($requestMethod === 'POST' && ($_POST['action'] ?? null) === 'import-trends') {
    $submittedToken = $_POST['import_token'] ?? null;
    if (!is_string($submittedToken) || !hash_equals($importToken, $submittedToken)) {
        http_response_code(403);
        exit('Vernieuw de dashboardpagina en probeer het opnieuw.');
    }

    $_SESSION['import_token'] = bin2hex(random_bytes(32));
    require_once __DIR__ . '/import.php';
    set_time_limit(180);

    $importStatus = [];
    try {
        foreach (importTrends($databasePath) as $import => $result) {
            if ($result['error'] !== null) {
                error_log($import . ': ' . $result['error']);
                $importStatus[] = $result['source'] . ' kon niet worden bijgewerkt.';
            } else {
                $count = $result['count'];
                $importStatus[] = $result['source'] . ' bijgewerkt (' . $count
                    . ($count === 1 ? ' resultaat).' : ' resultaten).');
            }
        }
    } catch (RuntimeException $error) {
        error_log('Trendimport: ' . $error->getMessage());
        $importStatus[] = 'Bijwerken mislukt. De SQLite-database kon niet worden geopend.';
    }

    $_SESSION['import_status'] = $importStatus;
    session_write_close();
    header('Location: ' . $importDashboardUrl, true, 303);
    exit;
}
$importStatus = $_SESSION['import_status'] ?? [];
unset($_SESSION['import_status']);
session_write_close();

$summaryInput = '';
$summaryError = $summaryResult = null;
$savedSummaries = [];
$summaryKey = is_string($_GET['samenvatting'] ?? null) ? $_GET['samenvatting'] : '';
if (!$olderOnly) {
    header('Cache-Control: no-store');
    if ($requestMethod === 'POST') {
        if (!in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'none', ['none', 'same-origin'], true)) {
            http_response_code(403);
            exit('Open het dashboard om een samenvatting te maken.');
        }

        $summaryInput = is_string($_POST['app'] ?? null) ? trim($_POST['app']) : '';
        try {
            if (strlen($summaryInput) > 2000) {
                throw new InvalidArgumentException('De App Store-link is te lang.');
            }
            set_time_limit(180);
            [$country, $id] = appSummaryReference($summaryInput);
            $app = lookupAppStoreListing($country, $id);
            $model = selectAppSummaryModel();
            $summary = generateAppSummary($app, $model);
            saveAppSummary($databasePath, $country, $id, $app, $model, $summary);

            $destination = dashboardUrl($comparisonWindow, $socialOnly, false, "$country-$id");
            header("Location: $destination#app-samenvatting-resultaat", true, 303);
            exit;
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $summaryError = 'De samenvatting kon niet worden opgeslagen. Controleer of PHP naar de SQLite-database kan schrijven.';
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $summaryError = $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/dashboard_data.php';

if (!$olderOnly && isset($db)) {
    try {
        $savedSummaries = listAppSummaries($db);
        if ($savedSummaries !== [] && preg_match('/^([a-z]{2})-([1-9][0-9]{0,14})$/D', $summaryKey, $summaryParts)) {
            $summaryResult = loadAppSummary($db, $summaryParts[1], $summaryParts[2]);
        }
    } catch (PDOException | JsonException $exception) {
        error_log($exception->getMessage());
        $summaryError ??= 'De opgeslagen samenvattingen konden niet worden geladen.';
    }
}
if (!$olderOnly && $summaryKey !== '' && $summaryResult === null) {
    $summaryError ??= 'Deze opgeslagen samenvatting is niet gevonden.';
}

require __DIR__ . '/views/dashboard.php';
