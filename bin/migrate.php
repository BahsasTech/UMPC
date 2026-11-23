<?php
declare(strict_types=1);

/**
 * Creates the UMPCSL database and applies database/schema.sql.
 * Usage:  php bin/migrate.php
 */

const APP_ROOT = __DIR__ . '/..';

require_once APP_ROOT . '/src/helpers.php';
require_once APP_ROOT . '/src/db.php';
require_once APP_ROOT . '/src/accounting.php';

$dbName = (string) config('db.database');

/** Split a schema file into statements, respecting strings and PostgreSQL dollar quotes. */
function sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $i = 0;
    $inString = false;
    $dollarTag = null;

    while ($i < $length) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inString) {
            $buffer .= $char;
            if ($char === '\\' && $next !== '') {
                $buffer .= $next;
                $i += 2;
                continue;
            }
            if ($char === "'") {
                if ($next === "'") {
                    $buffer .= $next;
                    $i += 2;
                    continue;
                }
                $inString = false;
            }
            $i++;
            continue;
        }

        if ($dollarTag !== null) {
            if (substr($sql, $i, strlen($dollarTag)) === $dollarTag) {
                $buffer .= $dollarTag;
                $i += strlen($dollarTag);
                $dollarTag = null;
                continue;
            }
            $buffer .= $char;
            $i++;
            continue;
        }

        if ($char === "'") {
            $inString = true;
            $buffer .= $char;
            $i++;
            continue;
        }

        if ($char === '$' && preg_match('/^(\$([A-Za-z_][A-Za-z0-9_]*)?\$)/', substr($sql, $i, 64), $m)) {
            $dollarTag = $m[1];
            $buffer .= $dollarTag;
            $i += strlen($dollarTag);
            continue;
        }

        if ($char === '-' && $next === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            $i++;
            continue;
        }

        $buffer .= $char;
        $i++;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }
    return $statements;
}

echo "UMPCSL database migration\n";
echo "-------------------------\n";

try {
    $pgsql = db_driver() === 'pgsql';
    $pdo = db();
    if (!$pgsql) {
        $server = db_server();
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        echo "✓ Database `$dbName` ready.\n";
    } else {
        echo "✓ PostgreSQL/Supabase connection ready (create the database in Supabase first).\n";
    }

    $schemaFile = $pgsql ? '/database/schema.postgres.sql' : '/database/schema.sql';
    $sql = file_get_contents(APP_ROOT . $schemaFile);
    if ($sql === false) {
        throw new RuntimeException('Could not read ' . $schemaFile);
    }

    $statements = sql_statements($sql);

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    echo "✓ Applied " . count($statements) . " schema statements.\n";

    if ($pgsql) {
        $hasAssignment = fetch_value("SELECT 1 FROM information_schema.columns WHERE table_name = 'members' AND column_name = 'assigned_user_id'");
        if (!$hasAssignment) {
            $pdo->exec('ALTER TABLE members ADD COLUMN assigned_user_id INTEGER REFERENCES users (id) ON DELETE SET NULL');
        }
    } else {
        $hasAssignment = fetch_value("SHOW COLUMNS FROM `members` LIKE 'assigned_user_id'");
        if (!$hasAssignment) {
            $pdo->exec('ALTER TABLE `members` ADD COLUMN `assigned_user_id` INT UNSIGNED NULL AFTER `status`, ADD CONSTRAINT `fk_members_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');
        }
    }
    echo "✓ Member assignment field ready.\n";

    $memberFields = [
        'pn_number'   => ['mysql' => 'VARCHAR(50) DEFAULT NULL AFTER `occupation`', 'pgsql' => 'VARCHAR(50)'],
        'npa_branch'  => ['mysql' => "ENUM('LPC','Tincan','Leki Port','Onne','Rivers Port','Abuja Office') DEFAULT NULL AFTER `pn_number`", 'pgsql' => "VARCHAR(40) CHECK (npa_branch IN ('LPC','Tincan','Leki Port','Onne','Rivers Port','Abuja Office'))"],
        'department'  => ['mysql' => 'VARCHAR(120) DEFAULT NULL AFTER `npa_branch`', 'pgsql' => 'VARCHAR(120)'],
        'designation' => ['mysql' => 'VARCHAR(120) DEFAULT NULL AFTER `department`', 'pgsql' => 'VARCHAR(120)'],
    ];
    foreach ($memberFields as $column => $definition) {
        if ($pgsql) {
            $exists = fetch_value('SELECT 1 FROM information_schema.columns WHERE table_name = \'members\' AND column_name = ?', [$column]);
            if (!$exists) {
                $pdo->exec('ALTER TABLE members ADD COLUMN ' . $column . ' ' . $definition['pgsql']);
            }
        } else {
            $exists = fetch_value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = ?", [$column]);
            if (!$exists) {
                $pdo->exec('ALTER TABLE `members` ADD COLUMN `' . $column . '` ' . $definition['mysql']);
            }
        }
    }
    echo "✓ NPA member fields ready.\n";

    if ($pgsql) {
        $tokenLength = fetch_value("SELECT character_maximum_length FROM information_schema.columns WHERE table_name = 'import_uploads' AND column_name = 'token'");
        if ($tokenLength !== false && (int) $tokenLength < 64) {
            $pdo->exec('ALTER TABLE import_uploads ALTER COLUMN token TYPE VARCHAR(64)');
        }
    } else {
        $tokenLength = fetch_value("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'import_uploads' AND column_name = 'token'");
        if ($tokenLength !== false && (int) $tokenLength < 64) {
            $pdo->exec('ALTER TABLE `import_uploads` MODIFY COLUMN `token` VARCHAR(64) NOT NULL');
        }
    }
    echo "✓ Import upload token field ready.\n";

    if ($pgsql) {
        $hasTxnIndex = fetch_value("SELECT 1 FROM pg_indexes WHERE tablename = 'payment_requests' AND indexname = 'uq_payment_requests_txn'");
        if (!$hasTxnIndex) {
            $pdo->exec('CREATE UNIQUE INDEX uq_payment_requests_txn ON payment_requests (transaction_reference)');
        }
    } else {
        $hasTxnIndex = fetch_value("SHOW INDEX FROM `payment_requests` WHERE Key_name = 'uq_payment_requests_txn'");
        if (!$hasTxnIndex) {
            $pdo->exec('ALTER TABLE `payment_requests` ADD UNIQUE KEY `uq_payment_requests_txn` (`transaction_reference`)');
        }
    }
    echo "✓ Payment request transaction index ready.\n";

    ensure_default_accounts();
    echo "✓ Default chart of accounts ready.\n";

    echo "Migration complete.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
