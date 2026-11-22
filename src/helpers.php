<?php
declare(strict_types=1);

/**
 * Small collection of shared helper functions.
 */

/** Read an environment variable (real env, then $_ENV/$_SERVER). */
function env(string $key, $default = null)
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    foreach ([$_ENV, $_SERVER] as $source) {
        if (array_key_exists($key, $source) && $source[$key] !== '') {
            return $source[$key];
        }
    }
    return $default;
}

/** Apply deployment environment variables on top of the PHP config file. */
function config_apply_env(array $config): array
{
    if (env('APP_URL')) {
        $config['app']['url'] = rtrim((string) env('APP_URL'), '/');
    }
    if (env('APP_NAME')) {
        $config['app']['name'] = (string) env('APP_NAME');
    }
    if (env('APP_TIMEZONE')) {
        $config['app']['timezone'] = (string) env('APP_TIMEZONE');
    }

    $driver = strtolower((string) env('DB_DRIVER', (string) ($config['db']['driver'] ?? 'mysql')));
    if (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
        $driver = 'pgsql';
    }
    $config['db']['driver'] = $driver;
    if ($driver === 'pgsql' && (string) $config['db']['port'] === '3306' && env('DB_PORT') === null) {
        $config['db']['port'] = 5432;
    }

    $databaseUrl = env('DATABASE_URL') ?: env('POSTGRES_URL');
    if ($databaseUrl) {
        $parts = parse_url((string) $databaseUrl);
        if ($parts === false || empty($parts['host'])) {
            throw new RuntimeException('DATABASE_URL is not a valid connection URL.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'postgres'));
        $config['db']['driver'] = str_starts_with($scheme, 'mysql') ? 'mysql' : 'pgsql';
        $config['db']['host'] = (string) $parts['host'];
        $config['db']['port'] = (int) ($parts['port'] ?? ($config['db']['driver'] === 'mysql' ? 3306 : 5432));
        $config['db']['database'] = ltrim((string) ($parts['path'] ?? ''), '/');
        $config['db']['username'] = urldecode((string) ($parts['user'] ?? ''));
        $config['db']['password'] = urldecode((string) ($parts['pass'] ?? ''));
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (!empty($query['sslmode'])) {
            $config['db']['sslmode'] = (string) $query['sslmode'];
        }
        if (!empty($query['pgbouncer'])) {
            $config['db']['pgbouncer'] = filter_var($query['pgbouncer'], FILTER_VALIDATE_BOOLEAN);
        }
        if (!empty($query['charset']) && $config['db']['driver'] === 'mysql') {
            $config['db']['charset'] = (string) $query['charset'];
        }
    }

    foreach ([
        'DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_NAME' => 'database',
        'DB_USER' => 'username', 'DB_PASS' => 'password', 'DB_CHARSET' => 'charset',
    ] as $envKey => $key) {
        if (env($envKey) !== null) {
            $config['db'][$key] = env($envKey);
        }
    }
    if (env('DB_SSLMODE')) {
        $config['db']['sslmode'] = (string) env('DB_SSLMODE');
    }
    if (env('DB_PGBOUNCER') !== null) {
        $config['db']['pgbouncer'] = filter_var(env('DB_PGBOUNCER'), FILTER_VALIDATE_BOOLEAN);
    }

    if (env('SESSION_DRIVER')) {
        $config['session']['driver'] = (string) env('SESSION_DRIVER');
    }
    if (env('SESSION_SECURE') !== null) {
        $config['session']['secure'] = filter_var(env('SESSION_SECURE'), FILTER_VALIDATE_BOOLEAN);
    }
    if (env('STORAGE_DRIVER')) {
        $config['storage']['driver'] = (string) env('STORAGE_DRIVER');
    }
    foreach ([
        'SUPABASE_URL' => 'url',
        'SUPABASE_SERVICE_ROLE_KEY' => 'service_role_key',
        'SUPABASE_BUCKET_PHOTOS' => 'bucket_photos',
        'SUPABASE_BUCKET_PRODUCTS' => 'bucket_products',
    ] as $envKey => $key) {
        if (env($envKey) !== null) {
            $config['storage']['supabase'][$key] = (string) env($envKey);
        }
    }

    return $config;
}

/** Read a config value using dot notation, e.g. config('db.host'). */
function config(?string $key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $file = APP_ROOT . '/config/config.php';
        $base = file_exists($file) ? require $file : [];
        $defaults = [
            'db' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'umpcsl',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'pgbouncer' => false,
            ],
            'session' => ['driver' => 'files', 'secure' => null],
            'storage' => [
                'driver' => 'local',
                'supabase' => [
                    'url' => '',
                    'service_role_key' => '',
                    'bucket_photos' => 'umpcsl-photos',
                    'bucket_products' => 'umpcsl-products',
                ],
            ],
        ];
        $config = config_apply_env(array_replace_recursive($defaults, is_array($base) ? $base : []));
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

/** Cached settings table, loaded once per request. */
function settings_all(bool $force = false): array
{
    static $rows = null;
    if ($force || $rows === null) {
        $rows = [];
        try {
            if (function_exists('db')) {
                foreach (db()->query('SELECT `key`, `value` FROM settings') as $row) {
                    $rows[(string) $row['key']] = (string) $row['value'];
                }
            }
        } catch (Throwable $e) {
            $rows = [];
        }
    }
    return $rows;
}

/** Read a setting saved by an administrator, falling back to a default. */
function setting_get(string $key, ?string $default = null): string
{
    $rows = settings_all();
    $value = $rows[$key] ?? null;
    return $value !== null && $value !== '' ? $value : (string) $default;
}

/** Insert or update a settings row on both MySQL and PostgreSQL. */
function setting_set(string $key, string $value): void
{
    $sql = db_driver() === 'pgsql'
        ? 'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON CONFLICT (`key`) DO UPDATE SET `value` = EXCLUDED.`value`'
        : 'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)';
    q($sql, [$key, $value]);
    settings_all(true);
}

/** Admin-editable organisation display name. */
function app_name(): string
{
    return setting_get('app.name', setting_get('org_name', (string) config('app.name', 'UMPCSL')));
}

/** Admin-editable short brand name. */
function app_short_name(): string
{
    return setting_get('app.short_name', (string) config('app.short_name', 'UMPCSL'));
}

/** Admin-editable currency symbol. */
function app_currency(): string
{
    return setting_get('app.currency', (string) config('app.currency', '₦'));
}

/** Admin-editable PHP timezone. */
function app_timezone(): string
{
    return setting_get('app.timezone', (string) config('app.timezone', 'Africa/Lagos'));
}

/** Escape a value for safe HTML output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Format a number as currency using the configured symbol. */
function money($amount, bool $symbol = true): string
{
    $formatted = number_format((float) $amount, 2);
    return $symbol ? app_currency() . $formatted : $formatted;
}

/** Format a date/time value, returning a dash when empty. */
function fmt_date(?string $value, string $format = 'd M Y'): string
{
    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : '—';
}

/** Human readable label for an enum slug. */
function label(string $value): string
{
    return ucwords(str_replace('_', ' ', $value));
}

/** Issue an HTTP redirect and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Store a one-time flash message. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Retrieve and clear all flash messages. */
function flashes(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

/** Get (creating if necessary) the CSRF token for this session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input containing the CSRF token. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Validate a submitted CSRF token. */
function csrf_check(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/** Abort with a 403 response when the CSRF token is missing or wrong. */
function require_csrf(): void
{
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid or expired security token. Please go back and try again.');
    }
}

/** Read a request value from POST. */
function post(string $key, $default = null)
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

/** Read a request value from GET. */
function get(string $key, $default = null)
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

/** Generate a unique reference such as UMP-MBR-000123. */
function next_reference(string $table, string $column, string $prefix, int $pad = 6): string
{
    $base = 'SELECT COALESCE(MAX(id), 0) + 1 FROM ' . $table;
    $next = (int) fetch_value($base);
    do {
        $ref = $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
        $exists = (int) fetch_value("SELECT COUNT(*) FROM $table WHERE $column = ?", [$ref]);
        $next++;
    } while ($exists > 0);
    return $ref;
}

/** Turn a decimal string into a float safely. */
function to_amount($value): float
{
    return round((float) str_replace(',', '', (string) $value), 2);
}
