<?php

declare(strict_types=1);

require_once __DIR__ . '/herkomst.php';
require_once __DIR__ . '/leeftijden.php';
require_once __DIR__ . '/includes/dashboard_helpers.php';

session_start(['use_strict_mode' => 1, 'cookie_httponly' => 1, 'cookie_samesite' => 'Lax']);
$importToken = $_SESSION['import_token'] ??= bin2hex(random_bytes(32));
$dashboardUrl = dashboardUrl($comparisonWindow, $socialOnly, $olderOnly);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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
    } catch (Throwable $error) {
        error_log('Trendimport: ' . $error->getMessage());
        $importStatus[] = 'Bijwerken mislukt. De SQLite-database kon niet worden geopend.';
    }

    $_SESSION['import_status'] = $importStatus;
    session_write_close();
    header('Location: index.php' . $dashboardUrl, true, 303);
    exit;
}

$importStatus = $_SESSION['import_status'] ?? [];
unset($_SESSION['import_status']);
session_write_close();

require __DIR__ . '/includes/dashboard_data.php';
require __DIR__ . '/views/dashboard.php';
