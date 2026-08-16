<?php
declare(strict_types=1);

/**
 * Health check endpoint. Point the ALB target group at /health.php.
 *
 * Note what this does NOT do: it does not start a session. A session write
 * would insert a database row on every health check from every AZ, every few
 * seconds, forever.
 *
 * SHALLOW BY DEFAULT — and that is on purpose:
 *   /health.php          "can this instance run PHP and read its config?"
 *   /health.php?deep=1   also checks the database
 *
 * If the ALB health check tested the database, then a single RDS failover
 * would mark BOTH instances unhealthy at once, the Auto Scaling group would
 * terminate them, and the replacements would fail their checks too — you would
 * lose the whole fleet because of a database blip. Keep the load-balancer
 * check shallow; use the deep check from CloudWatch Synthetics or by hand.
 */

// Only the two classes this endpoint actually needs — no session, no Cognito.
require dirname(__DIR__) . '/src/Config.php';
require dirname(__DIR__) . '/src/Db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$report = [
    'status'   => 'ok',
    'instance' => gethostname(),
    'time'     => gmdate('c'),
];

try {
    Config::load();
    Config::mustGet('db.host'); // config file present and readable?
} catch (Throwable $e) {
    http_response_code(503);
    error_log('letsvote: health check failed: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'reason' => 'configuration'], JSON_PRETTY_PRINT);
    exit;
}

if (($_GET['deep'] ?? '') === '1') {
    $report['database'] = Db::ping() ? 'ok' : 'unreachable';
    if ($report['database'] !== 'ok') {
        $report['status'] = 'degraded';
        http_response_code(503);
    }
}

echo json_encode($report, JSON_PRETTY_PRINT), "\n";
