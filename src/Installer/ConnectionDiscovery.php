<?php

declare(strict_types=1);

namespace Kighlander\GeoPlzDetails\Installer;

use PDO;
use RuntimeException;

/**
 * Read an application's configured database, not arbitrary database servers.
 * Supports Laravel's cached DB configuration, .env, and DB_URL.
 * No database is created or modified during discovery.
 */
final class ConnectionDiscovery
{
    /** @return array<string,mixed>|null */
    public static function detect(string $projectDirectory): ?array
    {
        $projectDirectory = rtrim($projectDirectory, '/\\');
        $genericDsn = getenv('GEO_PLZ_DSN');
        if ($genericDsn !== false && trim($genericDsn) !== '') {
            return self::fromPdoDsn($genericDsn, $projectDirectory);
        }
        $cache = $projectDirectory . '/bootstrap/cache/config.php';
        if (is_file($cache)) {
            $config = require $cache;
            if (!is_array($config) || !isset($config['database'])) {
                throw new RuntimeException('Laravel database config cache is invalid.');
            }
            $db = $config['database'];
            $default = $db['default'] ?? null;
            if (!is_string($default) || !isset($db['connections'][$default])) {
                throw new RuntimeException('Laravel default database connection is not configured.');
            }
            $connection = $db['connections'][$default];
            if (!is_array($connection)) {
                throw new RuntimeException('Invalid Laravel DB connection configuration.');
            }
            if (!empty($connection['url'])) {
                return self::fromUrl((string) $connection['url'], $projectDirectory);
            }
            return self::normalize([
                'driver' => $connection['driver'] ?? $default,
                'host' => $connection['host'] ?? null,
                'port' => $connection['port'] ?? null,
                'database' => $connection['database'] ?? null,
                'username' => $connection['username'] ?? null,
                'password' => $connection['password'] ?? null,
                'socket' => $connection['unix_socket'] ?? null,
            ], $projectDirectory);
        }

        $env = self::dotenv($projectDirectory . '/.env');
        foreach (['DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
            'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET'] as $key) {
            $process = getenv($key);
            if ($process !== false) {
                $env[$key] = $process;
            }
        }
        if (!empty($env['DB_URL'])) {
            return self::fromUrl((string) $env['DB_URL'], $projectDirectory);
        }
        $driver = strtolower(trim((string) ($env['DB_CONNECTION'] ?? '')));
        if ($driver === '') {
            return null;
        }
        return self::normalize([
            'driver' => $driver,
            'host' => $env['DB_HOST'] ?? null,
            'port' => $env['DB_PORT'] ?? null,
            'database' => $env['DB_DATABASE'] ?? null,
            'username' => $env['DB_USERNAME'] ?? null,
            'password' => $env['DB_PASSWORD'] ?? null,
            'socket' => $env['DB_SOCKET'] ?? null,
        ], $projectDirectory);
    }

    /** @return array<string,string> */
    public static function dotenv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $result = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (preg_match('/\A([A-Za-z_][A-Za-z_0-9]*)\s*=\s*(.*)\z/s', $line, $match) !== 1) {
                continue;
            }
            $value = trim($match[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
                if ($match[2][0] === '"') {
                    $value = str_replace(['\\n', '\\"'], ["\n", '"'], $value);
                }
            } else {
                $value = (string) preg_replace('/\s+#.*\z/', '', $value);
            }
            $result[$match[1]] = $value;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function fromPdoDsn(string $dsn, string $projectDirectory): array
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            return self::normalize([
                'driver' => 'sqlite', 'database' => substr($dsn, 7),
            ], $projectDirectory) ?? throw new RuntimeException('Configured GEO_PLZ_DSN SQLite file does not exist.');
        }
        if (preg_match('/\A(mysql|mariadb):(.*)\z/s', $dsn, $parts) !== 1) {
            throw new RuntimeException('GEO_PLZ_DSN currently supports mysql/mariadb/sqlite only.');
        }
        $values = [];
        foreach (explode(';', $parts[2]) as $segment) {
            if (str_contains($segment, '=')) {
                [$key, $value] = explode('=', $segment, 2);
                $values[trim($key)] = trim($value);
            }
        }
        return self::normalize([
            'driver' => $parts[1],
            'host' => $values['host'] ?? null,
            'port' => $values['port'] ?? null,
            'database' => $values['dbname'] ?? null,
            'socket' => $values['unix_socket'] ?? null,
            'username' => getenv('GEO_PLZ_USERNAME') ?: '',
            'password' => getenv('GEO_PLZ_PASSWORD') ?: '',
        ], $projectDirectory) ?? throw new RuntimeException('Incomplete GEO_PLZ_DSN.');
    }

    /** @return array<string,mixed> */
    private static function fromUrl(string $url, string $projectDirectory): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            throw new RuntimeException('Invalid configured DB_URL.');
        }
        $driver = strtolower((string) $parts['scheme']);
        $driver = match ($driver) { 'mysql', 'mariadb' => $driver, 'sqlite', 'sqlite3' => 'sqlite', default => $driver };
        if ($driver === 'sqlite') {
            throw new RuntimeException('SQLite DB_URL needs manual configuration via DB_CONNECTION=sqlite and DB_DATABASE.');
        }
        return self::normalize([
            'driver' => $driver,
            'host' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? null,
            'database' => isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : null,
            'username' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        ], $projectDirectory);
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|null */
    private static function normalize(array $input, string $projectDirectory): ?array
    {
        $driver = strtolower((string) ($input['driver'] ?? ''));
        if ($driver === 'sqlite' || $driver === 'sqlite3') {
            $db = trim((string) ($input['database'] ?? ''));
            if ($db === '') {
                // Laravel's default SQLite path may be in config/database.php,
                // even when the .env file leaves DB_DATABASE empty.
                $default = $projectDirectory . '/database/database.sqlite';
                return is_file($default)
                    ? ['driver' => 'sqlite', 'database' => $default]
                    : null;
            }
            if ($db === ':memory:') {
                return null;
            }
            $path = self::absolutePath($db, $projectDirectory);
            if (!is_file($path)) {
                return null; // Not an existing configured application database.
            }
            return ['driver' => 'sqlite', 'database' => $path];
        }
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $database = trim((string) ($input['database'] ?? ''));
            if ($database === '') {
                throw new RuntimeException('MySQL/MariaDB is configured but DB_DATABASE is missing; no silent SQLite fallback.');
            }
            if (preg_match('/\A[A-Za-z0-9_\-]+\z/D', $database) !== 1) {
                throw new RuntimeException('Configured DB_DATABASE contains unsupported characters.');
            }
            return [
                'driver' => $driver,
                'database' => $database,
                'host' => (string) ($input['host'] ?: '127.0.0.1'),
                'port' => (int) ($input['port'] ?: 3306),
                'socket' => (string) ($input['socket'] ?? ''),
                'username' => (string) ($input['username'] ?? ''),
                'password' => (string) ($input['password'] ?? ''),
            ];
        }
        throw new RuntimeException('Configured DB_CONNECTION ' . $driver . ' is not supported yet; refusing automatic fallback.');
    }

    private static function absolutePath(string $value, string $root): string
    {
        $path = str_replace('\\', '/', $value);
        if (preg_match('/\A[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }
        // Laravel commonly uses "database/database.sqlite" as a relative path.
        return $root . '/' . $path;
    }

    /** @param array<string,mixed> $info */
    public static function connect(array $info): PDO
    {
        $driver = $info['driver'] ?? null;
        if ($driver === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                throw new RuntimeException('pdo_sqlite is required to use an SQLite database.');
            }
            $db = (string) ($info['database'] ?? '');
            if (!is_file($db)) {
                throw new RuntimeException('Configured SQLite database is missing; no replacement created: ' . $db);
            }
            $pdo = new PDO('sqlite:' . $db);
            $pdo->exec('PRAGMA foreign_keys=ON');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            if (!extension_loaded('pdo_mysql')) {
                throw new RuntimeException('pdo_mysql is required for the configured MySQL/MariaDB database.');
            }
            $base = !empty($info['socket'])
                ? 'unix_socket=' . $info['socket']
                : 'host=' . $info['host'] . ';port=' . $info['port'];
            $pdo = new PDO(
                'mysql:' . $base . ';dbname=' . $info['database'] . ';charset=utf8mb4',
                (string) ($info['username'] ?? ''),
                (string) ($info['password'] ?? ''),
            );
        } else {
            throw new RuntimeException('Unsupported database driver.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
        return $pdo;
    }

    /** @param array<string,mixed> $install */
    public static function connectFromInstallation(string $projectDirectory, array $install): PDO
    {
        if (($install['mode'] ?? '') === 'sqlite_fallback') {
            $dir = ProjectInstallation::dataDirectory($projectDirectory);
            $file = $dir . '/geo-plz-details.sqlite';
            return self::connect(['driver' => 'sqlite', 'database' => $file]);
        }
        if (($install['mode'] ?? '') !== 'existing_database') {
            throw new RuntimeException('Unsupported installation mode.');
        }
        $detected = self::detect($projectDirectory);
        if ($detected === null) {
            throw new RuntimeException('Original configured application database is no longer detectable.');
        }
        if (($detected['driver'] ?? '') !== ($install['driver'] ?? '')) {
            throw new RuntimeException('Application DB driver changed since installation.');
        }
        if (self::connectionIdentity($detected) !== ($install['connection_identity'] ?? '')) {
            throw new RuntimeException('Application database target changed since GEO installation.');
        }
        return self::connect($detected);
    }

    /** @param array<string,mixed> $info */
    public static function connectionIdentity(array $info): string
    {
        $parts = [$info['driver'], $info['database']];
        if (($info['driver'] ?? '') !== 'sqlite') {
            $parts[] = $info['host'] ?? '';
            $parts[] = (string) ($info['port'] ?? '');
            $parts[] = $info['socket'] ?? '';
        }
        return hash('sha256', implode("\0", $parts));
    }
}
