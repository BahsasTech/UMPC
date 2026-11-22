<?php
declare(strict_types=1);

/**
 * Sends repayment reminders and missed-payment alerts.
 *
 * Usage:
 *   php bin/send-reminders.php                 # reminders due in 3 days + missed
 *   php bin/send-reminders.php --days=7        # change the reminder window
 *   php bin/send-reminders.php --reminders     # reminders only
 *   php bin/send-reminders.php --missed        # missed-payment alerts only
 *   php bin/send-reminders.php --force         # resend even if already notified today
 */

const APP_ROOT = __DIR__ . '/..';

require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/auth.php';
require_once APP_ROOT . '/src/members.php';
require_once APP_ROOT . '/src/savings.php';
require_once APP_ROOT . '/src/loans.php';
require_once APP_ROOT . '/src/payments.php';
require_once APP_ROOT . '/src/notifications.php';
require_once APP_ROOT . '/src/reminders.php';

$days = 3;
$doReminders = true;
$doMissed = true;
$force = false;

foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(0, (int) $m[1]);
    } elseif ($arg === '--reminders') {
        $doReminders = true;
        $doMissed = false;
    } elseif ($arg === '--missed') {
        $doMissed = true;
        $doReminders = false;
    } elseif ($arg === '--force') {
        $force = true;
    }
}

echo "UMPCSL notification scheduler\n";
echo "-----------------------------\n";

$result = run_reminders($days, $doReminders, $doMissed, $force);
foreach ($result['lines'] as $line) {
    echo $line . "\n";
}
echo "Done. Sent: {$result['sent']}, skipped (already notified today): {$result['skipped']}.\n";
