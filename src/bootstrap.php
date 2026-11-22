<?php
declare(strict_types=1);

/**
 * Application bootstrap: configuration, session, database and helpers.
 * Every public page requires this file.
 */

const APP_ROOT = __DIR__ . '/..';

mb_internal_encoding('UTF-8');
date_default_timezone_set('Africa/Lagos');

require_once APP_ROOT . '/src/helpers.php';

if (!file_exists(APP_ROOT . '/config/config.php')
    && !env('DATABASE_URL') && !env('POSTGRES_URL') && !env('DB_HOST')) {
    http_response_code(500);
    exit('Missing database configuration. Copy config/config.example.php to config.php or set DATABASE_URL / DB_HOST.');
}

require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/session.php';
require_once APP_ROOT . '/src/storage.php';
require_once APP_ROOT . '/src/auth.php';
require_once APP_ROOT . '/src/members.php';
require_once APP_ROOT . '/src/savings.php';
require_once APP_ROOT . '/src/loans.php';
require_once APP_ROOT . '/src/payments.php';
require_once APP_ROOT . '/src/reports.php';
require_once APP_ROOT . '/src/notifications.php';
require_once APP_ROOT . '/src/accounting.php';
require_once APP_ROOT . '/src/store.php';
require_once APP_ROOT . '/src/import.php';
require_once APP_ROOT . '/src/gateway.php';
require_once APP_ROOT . '/src/reminders.php';

date_default_timezone_set(app_timezone());

if (session_status() !== PHP_SESSION_ACTIVE) {
    register_database_sessions();
    session_name('umpcsl_sid');
    $configuredSecure = config('session.secure');
    $secure = $configuredSecure !== null
        ? (bool) $configuredSecure
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_start();
}
