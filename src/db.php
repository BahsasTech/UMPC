<?php
declare(strict_types=1);

/**
 * Database connection helpers (PDO / MySQL or PostgreSQL/Supabase).
 */

/** Return the configured database driver: mysql or pgsql. */
function db_driver(): string
{
    $driver = strtolower((string) config('db.driver', 'mysql'));
    return in_array($driver, ['pgsql', 'postgres', 'postgresql'], true) ? 'pgsql' : 'mysql';
}

/** Build the PDO DSN for the configured driver. */
function db_dsn(bool $withDatabase = true): string
{
    $cfg = config('db');
    if (db_driver() === 'pgsql') {
        $parts = [
            'host=' . (string) $cfg['host'],
            'port=' . (int) ($cfg['port'] ?: 5432),
        ];
        if ($withDatabase) {
            $parts[] = 'dbname=' . (string) $cfg['database'];
        }
        if (!empty($cfg['sslmode'])) {
            $parts[] = 'sslmode=' . (string) $cfg['sslmode'];
        }
        return 'pgsql:' . implode(';', $parts);
    }

    $charset = (string) ($cfg['charset'] ?: 'utf8mb4');
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int) $cfg['port'], $charset);
    if ($withDatabase) {
        $dsn .= ';dbname=' . $cfg['database'];
    }
    return $dsn;
}

/** Return a shared PDO connection to the application database. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = config('db');
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pgsql = db_driver() === 'pgsql';
    $pooled = $pgsql && !empty($cfg['pgbouncer']);
    $options[PDO::ATTR_EMULATE_PREPARES] = $pooled;
    $connect = static function (array $options) use ($cfg): PDO {
        return new PDO(db_dsn(), (string) $cfg['username'], (string) $cfg['password'], $options);
    };

    try {
        $pdo = $connect($options);
    } catch (PDOException $e) {
        if (!preg_match('/attribute|not support/i', $e->getMessage())) {
            throw $e;
        }
        unset($options[PDO::ATTR_EMULATE_PREPARES]);
        $pdo = $connect($options);
    }

    if ($pgsql && !$pooled) {
        $pdo->exec("SET TIME ZONE '" . str_replace("'", '', (string) config('app.timezone', 'Africa/Lagos')) . "'");
    }

    return $pdo;
}

/**
 * Open a server-level connection. PostgreSQL manages databases outside the app,
 * so the migration uses the normal connection there.
 */
function db_server(): PDO
{
    if (db_driver() === 'pgsql') {
        return db();
    }
    $cfg = config('db');
    return new PDO(db_dsn(false), (string) $cfg['username'], (string) $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

/**
 * Translate the small amount of MySQL-specific SQL used by the app into
 * PostgreSQL syntax. Strings, quoted identifiers and comments are tokenized
 * first so replacements only touch executable SQL.
 */
function db_portable_sql(string $sql): string
{
    if (db_driver() !== 'pgsql') {
        return $sql;
    }

    $parts = [];
    $work = '';
    $length = strlen($sql);
    $i = 0;
    $salt = bin2hex(random_bytes(6));
    $addPart = static function (string $fragment) use (&$parts, &$work, $salt): void {
        $token = '__UMP' . $salt . '_' . count($parts) . '__';
        $parts[$token] = $fragment;
        $work .= $token;
    };

    while ($i < $length) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($char === "'") {
            $buffer = "'";
            $j = $i + 1;
            while ($j < $length) {
                $c = $sql[$j];
                $n = $j + 1 < $length ? $sql[$j + 1] : '';
                if ($c === '\\' && $n !== '') {
                    $buffer .= $c . $n;
                    $j += 2;
                    continue;
                }
                if ($c === "'") {
                    if ($n === "'") {
                        $buffer .= "''";
                        $j += 2;
                        continue;
                    }
                    $buffer .= "'";
                    $j++;
                    break;
                }
                $buffer .= $c;
                $j++;
            }
            $addPart($buffer);
            $i = $j;
            continue;
        }

        if ($char === '"') {
            $buffer = '"';
            $j = $i + 1;
            while ($j < $length) {
                $c = $sql[$j];
                $n = $j + 1 < $length ? $sql[$j + 1] : '';
                if ($c === '\\' && $n !== '') {
                    $buffer .= $c . $n;
                    $j += 2;
                    continue;
                }
                if ($c === '"') {
                    $buffer .= '"';
                    $j++;
                    break;
                }
                $buffer .= $c;
                $j++;
            }
            $content = stripslashes(substr($buffer, 1, -1));
            $addPart("'" . str_replace("'", "''", $content) . "'");
            $i = $j;
            continue;
        }

        if ($char === '`') {
            $identifier = '';
            $j = $i + 1;
            while ($j < $length) {
                if ($sql[$j] === '`') {
                    if ($j + 1 < $length && $sql[$j + 1] === '`') {
                        $identifier .= '`';
                        $j += 2;
                        continue;
                    }
                    $j++;
                    break;
                }
                $identifier .= $sql[$j++];
            }
            $addPart('"' . str_replace('"', '""', $identifier) . '"');
            $i = $j;
            continue;
        }

        if ($char === '-' && $next === '-') {
            $j = $i;
            while ($j < $length && $sql[$j] !== "\n") {
                $j++;
            }
            $addPart(substr($sql, $i, $j - $i));
            $i = $j;
            continue;
        }

        if ($char === '$' && preg_match('/^(\$([A-Za-z_][A-Za-z0-9_]*)?\$)/', substr($sql, $i, 64), $m)) {
            $tag = $m[1];
            $close = strpos($sql, $tag, $i + strlen($tag));
            $buffer = $close === false
                ? substr($sql, $i)
                : substr($sql, $i, $close + strlen($tag) - $i);
            $addPart($buffer);
            $i += strlen($buffer);
            continue;
        }

        $work .= $char;
        $i++;
    }

    $work = preg_replace_callback('/DATE_FORMAT\(\s*([^,]+?)\s*,\s*(__UMP[A-Za-z0-9]+_\d+__)\s*\)/i', static function ($m) use ($parts) {
        $format = trim((string) ($parts[$m[2]] ?? "''"), "'");
        $format = str_replace(['%Y', '%m', '%d'], ['YYYY', 'MM', 'DD'], $format);
        return 'to_char(' . trim($m[1]) . ", '" . $format . "')";
    }, $work);

    $work = preg_replace('/\bCURDATE\(\)/i', 'CURRENT_DATE', $work);
    $work = preg_replace('/\bIFNULL\(/i', 'COALESCE(', $work);
    $work = preg_replace_callback('/\bDATE\(\s*([^()]+?)\s*\)/i', static function ($m) {
        return 'CAST(' . trim($m[1]) . ' AS DATE)';
    }, $work);
    $work = preg_replace_callback('/\bYEAR\(\s*([^()]+?)\s*\)/i', static function ($m) {
        return 'EXTRACT(YEAR FROM ' . trim($m[1]) . ')';
    }, $work);
    $work = preg_replace_callback('/\bDATE_ADD\(\s*([^,]+?)\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i', static function ($m) {
        return '(' . trim($m[1]) . " + INTERVAL '" . (int) $m[2] . " DAY')";
    }, $work);
    $work = preg_replace('/\bLIKE\b/i', 'ILIKE', $work);

    return strtr((string) $work, $parts);
}

/** Run a query and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare(db_portable_sql($sql));
    $stmt->execute($params);
    return $stmt;
}

/** Fetch a single row or null. */
function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Fetch a single scalar value. */
function fetch_value(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}

/** Insert a row and return its id. */
function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $cols);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $cols),
        implode(', ', $placeholders)
    );
    if (db_driver() === 'pgsql') {
        return (int) q($sql . ' RETURNING id', $data)->fetchColumn();
    }
    q($sql, $data);
    return (int) db()->lastInsertId();
}

/** Update rows in a table by primary key. */
function db_update(string $table, int $id, array $data): void
{
    if ($data === []) {
        return;
    }
    $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
    $data['__id'] = $id;
    q("UPDATE $table SET $sets WHERE id = :__id", $data);
}
