<?php

declare(strict_types=1);

namespace Kighlander\GeoPlzDetails\Installer;

use RuntimeException;

final class ProjectInstallation
{
    public static function dataDirectory(string $projectDirectory): string
    {
        $root = rtrim($projectDirectory, '/\\');
        if (is_file($root . '/artisan') || is_dir($root . '/storage/app/private')) {
            return $root . '/storage/app/private/geo-plz-details';
        }
        return $root . '/.geo-plz-details';
    }

    public static function configPath(string $projectDirectory): string
    {
        return self::dataDirectory($projectDirectory) . '/installation.json';
    }

    /** @return array<string,mixed> */
    public static function load(string $projectDirectory): array
    {
        $path = self::configPath($projectDirectory);
        if (!is_file($path)) {
            throw new RuntimeException('GEO is not installed for this project. Run geo-plz-details install first.');
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['format_version'] ?? null) !== 1) {
            throw new RuntimeException('Invalid GEO installation configuration.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $config */
    public static function saveNew(string $projectDirectory, array $config): void
    {
        $directory = self::dataDirectory($projectDirectory);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create GEO configuration directory.');
        }
        $path = self::configPath($projectDirectory);
        if (file_exists($path)) {
            throw new RuntimeException('GEO configuration already exists and cannot be overwritten.');
        }
        $data = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot exclusively create GEO configuration.');
        }
        try {
            if (fwrite($handle, $data . "\n") === false) {
                throw new RuntimeException('Cannot write GEO configuration.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }
}
