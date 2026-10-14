<?php

declare(strict_types=1);

namespace Kighlander\GeoPlzDetails\InternalPreview;

use PDO;
use RuntimeException;

/**
 * Explicit private-only PDO reader for frozen D.50 SQLite or D.51 MariaDB.
 * Do not use in a public HTTP endpoint. No release authorization is granted.
 * The database must already exist. No CREATE/ALTER/INSERT operations.
 */
final class Connection
{
    public static function fromEnvironment(): PDO
    {
        $sqlite = getenv('GEO_PLZ_PRIVATE_SQLITE_PATH');
        $database = getenv('GEO_PLZ_PRIVATE_DATABASE');
        if ($sqlite !== false && $sqlite !== '' && $database !== false && $database !== '') {
            throw new RuntimeException('Private preview: choose either SQLite or MariaDB, not both.');
        }
        if ($sqlite !== false && $sqlite !== '') {
            $actual = realpath($sqlite);
            if ($actual === false || !is_file($actual)
                || basename($actual) !== 'geo-d50-INTERNAL-NOT_APPROVED.sqlite') {
                throw new RuntimeException('Private preview: invalid existing D50 SQLite file.');
            }
            if (!extension_loaded('pdo_sqlite')) {
                throw new RuntimeException('Private preview: pdo_sqlite is required.');
            }
            // URI mode=ro is not portable across PHP PDO SQLite installations.
            // PRAGMA query_only ensures no SQL modifications via this handle.
            $pdo = new PDO('sqlite:' . $actual, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
            return $pdo;
        }
        if (!is_string($database) || preg_match('/\Ageo_d51_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}\z/D', $database) !== 1) {
            throw new RuntimeException('Private preview: an existing geo_d51_* database is required.');
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Private preview: pdo_mysql is required.');
        }
        $host = getenv('GEO_PLZ_PRIVATE_HOST') ?: '127.0.0.1';
        $port = getenv('GEO_PLZ_PRIVATE_PORT') ?: '3306';
        $user = getenv('GEO_PLZ_PRIVATE_USER');
        $password = getenv('GEO_PLZ_PRIVATE_PASSWORD');
        if (preg_match('/\A[a-zA-Z0-9._-]{1,255}\z/D', $host) !== 1
            || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
            || !is_string($user) || $user === '' || $password === false) {
            throw new RuntimeException('Private preview: invalid host/port or missing credentials.');
        }
        // No password in CLI arguments or DSN. Use a SELECT-only MariaDB account.
        $pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
            $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 15,
            ]);
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Private preview: connected database identity mismatch.');
        }
        return $pdo;
    }
}
