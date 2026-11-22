<?php
declare(strict_types=1);

/**
 * Shared PHP session storage for stateless/multi-instance deployments.
 * Enabled with SESSION_DRIVER=database (used by the Vercel container).
 */

final class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read(string $id)
    {
        $row = fetch_one('SELECT payload FROM app_sessions WHERE id = ? AND expires_at > ?', [ $id, time() ]);
        return $row === null ? '' : (string) $row['payload'];
    }

    public function write(string $id, string $data): bool
    {
        $expires = time() + max(3600, (int) ini_get('session.gc_maxlifetime'));
        $sql = db_driver() === 'pgsql'
            ? 'INSERT INTO app_sessions (id, payload, expires_at) VALUES (?, ?, ?)
               ON CONFLICT (id) DO UPDATE SET payload = EXCLUDED.payload, expires_at = EXCLUDED.expires_at'
            : 'INSERT INTO app_sessions (id, payload, expires_at) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)';
        q($sql, [$id, $data, $expires]);
        return true;
    }

    public function destroy(string $id): bool
    {
        q('DELETE FROM app_sessions WHERE id = ?', [$id]);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc(int $max_lifetime)
    {
        return q('DELETE FROM app_sessions WHERE expires_at < ?', [time()])->rowCount();
    }
}

/** Register the database session handler when configured. */
function register_database_sessions(): void
{
    if (config('session.driver') !== 'database') {
        return;
    }
    session_set_save_handler(new DatabaseSessionHandler(), true);
}
