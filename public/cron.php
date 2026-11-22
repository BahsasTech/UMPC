<?php
declare(strict_types=1);

/**
 * Vercel Cron / external scheduler endpoint.
 * Protected by CRON_SECRET (Vercel sends it as an Authorization: Bearer header).
 */

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$secret = (string) env('CRON_SECRET', '');
if ($secret === '') {
    http_response_code(503);
    echo "CRON_SECRET is not configured.\n";
    exit;
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$bearer = preg_match('/^Bearer\s+(.+)$/i', $authorization, $m) ? trim($m[1]) : '';
if (!hash_equals($secret, $bearer)) {
    http_response_code(401);
    echo "Unauthorized.\n";
    exit;
}

$days = isset($_GET['days']) ? max(0, min(365, (int) $_GET['days'])) : 3;
$force = isset($_GET['force']) && (string) $_GET['force'] === '1';

$result = run_reminders($days, true, true, $force);
foreach ($result['lines'] as $line) {
    echo $line . "\n";
}
echo "Done. Sent: {$result['sent']}, skipped: {$result['skipped']}.\n";
