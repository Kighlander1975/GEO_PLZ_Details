#!/usr/bin/env php
<?php

declare(strict_types=1);

// Composer creates a bin-proxy that points at this file. It may also be
// invoked directly without a Composer autoloader during development.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require_once dirname(__DIR__) . '/src/GeoCatalog.php';
    foreach (['ConnectionDiscovery', 'ProjectInstallation', 'ReleaseImporter', 'Installer'] as $class) {
        require_once dirname(__DIR__) . '/src/Installer/' . $class . '.php';
    }
}

use Kighlander\GeoPlzDetails\GeoCatalog;
use Kighlander\GeoPlzDetails\Installer\ConnectionDiscovery;
use Kighlander\GeoPlzDetails\Installer\Installer;

function usage(): never
{
    fwrite(STDERR, <<<TXT
GEO_PLZ_Details (Composer package)
  geo-plz-details install [--project=DIR] [--release=DIR] [--sqlite] [--yes] [--dry-run]
  geo-plz-details status|check|lookup PLZ|subdivisions PLZ AGS|resolve PLZ AGS [KEY]
  geo-plz-details distance LAT1 LON1 LAT2 LON2
  geo-plz-details preview-check|preview-lookup PLZ|preview-subdivisions PLZ AGS
  geo-plz-details preview-resolve PLZ AGS [KEY]

Options: --project=DIR (app root, default current directory),
         --db=FILE (legacy standalone SQLite mode),
         --sqlite (explicitly use local fallback instead of configured app DB),
         --yes (confirm creation of prefixed tables),
         --private-preview (explicit opt-in, requires GEO_PLZ_PRIVATE_* environment).

TXT);
    exit(2);
}

try {
    $args = array_slice($argv, 1);
    $options = [];
    foreach ($args as $index => $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $pair = explode('=', substr($arg, 2), 2);
        $flag = $pair[0];
        if (!in_array($flag, ['project', 'release', 'sqlite', 'yes', 'dry-run', 'db', 'prefix', 'private-preview'], true)
            || (count($pair) === 1 && !in_array($flag, ['sqlite', 'yes', 'dry-run', 'private-preview'], true))
            || (count($pair) === 2 && in_array($flag, ['sqlite', 'yes', 'dry-run', 'private-preview'], true))) {
            usage();
        }
        if (array_key_exists($flag, $options)) {
            throw new RuntimeException('Duplicate CLI option: ' . $flag);
        }
        $options[$flag] = $pair[1] ?? true;
        unset($args[$index]);
    }
    $args = array_values($args);
    $command = array_shift($args);
    if (!is_string($command)) {
        usage();
    }
    $project = realpath((string) ($options['project'] ?? getcwd()));
    if ($project === false) {
        throw new RuntimeException('Project directory missing.');
    }
    // Private data never flows through GeoCatalog or the public installer.
    // Must be an explicit command and explicit opt-in, never an automatic fallback.
    if (str_starts_with($command, 'preview-')) {
        if (!isset($options['private-preview']) ||
            array_diff(array_keys($options), ['private-preview']) !== []) {
            throw new RuntimeException('Private preview requires only --private-preview (no install/project/db options).');
        }
        if (!class_exists(\Kighlander\GeoPlzDetails\InternalPreview\Catalog::class)) {
            require_once dirname(__DIR__) . '/src/InternalPreview/Catalog.php';
        }
        if (!class_exists(\Kighlander\GeoPlzDetails\InternalPreview\Connection::class)) {
            require_once dirname(__DIR__) . '/src/InternalPreview/Connection.php';
        }
        $preview = new \Kighlander\GeoPlzDetails\InternalPreview\Catalog(
            \Kighlander\GeoPlzDetails\InternalPreview\Connection::fromEnvironment(),
            \Kighlander\GeoPlzDetails\InternalPreview\Catalog::ACKNOWLEDGE,
        );
        $response = match ($command) {
            'preview-check' => count($args) === 0 ? $preview->check() : usage(),
            'preview-lookup' => count($args) === 1 ? $preview->municipalitiesByPostalCode($args[0]) : usage(),
            'preview-subdivisions' => count($args) === 2 ? $preview->subdivisionsByPlace($args[0], $args[1]) : usage(),
            'preview-resolve' => count($args) === 2 || count($args) === 3
                ? $preview->resolveSelection($args[0], $args[1], $args[2] ?? null) : usage(),
            default => usage(),
        };
        echo json_encode($response,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }
    if (isset($options['private-preview'])) {
        throw new RuntimeException('--private-preview is only valid with preview-* commands.');
    }
    if ($command === 'install') {
        if ($args !== [] || isset($options['db']) || isset($options['prefix'])) {
            usage();
        }
        $release = (string) ($options['release'] ?? '');
        if ($release === '') {
            $releases = glob($project . '/database/geo/releases/*/manifest.json') ?: [];
            if (count($releases) !== 1) {
                throw new RuntimeException('Specify --release=PATH (a single unambiguous release is required).');
            }
            $release = dirname($releases[0]);
        }
        $plan = Installer::plan($project, $release, isset($options['sqlite']));
        $public = $plan;
        unset($public['configured'], $public['source']);
        echo json_encode(['installation_plan' => $public], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
        if (isset($options['dry-run'])) {
            exit(0);
        }
        if (!isset($options['yes'])) {
            if (!defined('STDIN') || !stream_isatty(STDIN)) {
                throw new RuntimeException('Noninteractive installation needs --yes, after reviewing --dry-run.');
            }
            fwrite(STDERR, 'Create GEO-prefixed tables in this database? Type JA: ');
            $answer = trim((string) fgets(STDIN));
            if (strtoupper($answer) !== 'JA') {
                throw new RuntimeException('Installation cancelled without changes.');
            }
        }
        echo json_encode(['installation' => Installer::execute($plan)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }

    if ($command === 'distance') {
        if (count($args) !== 4 || !array_reduce($args, static fn (bool $ok, string $v): bool => $ok && is_numeric($v), true)) {
            usage();
        }
        $response = ['distance_km' => GeoCatalog::distanceKm(...array_map('floatval', $args))];
    } else {
        if (isset($options['db'])) {
            $catalog = new GeoCatalog((string) $options['db'], (string) ($options['prefix'] ?? ''));
        } else {
            $catalog = GeoCatalog::fromProject($project);
        }
        $response = match ($command) {
            'status' => count($args) === 0 ? $catalog->status() : usage(),
            'check' => count($args) === 0 ? $catalog->check() : usage(),
            'lookup' => count($args) === 1 ? $catalog->municipalitiesByPostalCode($args[0]) : usage(),
            'subdivisions' => count($args) === 2 ? $catalog->subdivisionsByPlace($args[0], $args[1]) : usage(),
            'resolve' => count($args) === 2 || count($args) === 3
                ? $catalog->resolveLocation($args[0], $args[1], $args[2] ?? null) : usage(),
            default => usage(),
        };
    }
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    if ($command === 'check' && $response['valid'] !== true) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
