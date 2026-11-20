<?php

declare(strict_types=1);

/*
 * Consistency checks for an Azuriom plugin repository: manifest, version,
 * translations, migrations and committed files.
 *
 * Usage: php .github/scripts/check-plugin.php
 *
 * Requires PHP 8.2+ and no dependency.
 * Exits with 1 when a check fails. In GitHub Actions, problems are reported as
 * annotations on the faulty files. When GITHUB_REF_TYPE is "tag", the tag
 * (GITHUB_REF_NAME, with or without a leading "v") must match the plugin version.
 */

chdir(dirname(__DIR__, 2));

const PLUGIN_JSON = 'plugin.json';
const COMPOSER_JSON = 'composer.json';
const CHANGELOG = 'CHANGELOG.md';
const LANG_PATH = 'resources/lang';
const MIGRATIONS_PATH = 'database/migrations';
const MAX_FILES_PER_REASON = 5;

const SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
    .'(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

/**
 * @return array{message: string, file: ?string, line: ?int}
 */
function problem(string $message, ?string $file = null, ?int $line = null): array
{
    return ['message' => $message, 'file' => $file, 'line' => $line];
}

function isFilledString(mixed $value): bool
{
    return is_string($value) && trim($value) !== '';
}

function studly(string $value): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
}

function lineAt(string $content, int $offset): int
{
    return substr_count($content, "\n", 0, $offset) + 1;
}

function lineOf(string $path, string $needle): ?int
{
    $content = is_file($path) ? (string) file_get_contents($path) : '';
    $position = $needle === '' ? false : strpos($content, $needle);

    return $position === false ? null : lineAt($content, $position);
}

/**
 * @param  list<array{message: string, file: ?string, line: ?int}>  $problems
 * @return array<string, mixed>|null
 */
function readJsonObject(string $path, array &$problems): ?array
{
    if (! is_file($path)) {
        $problems[] = problem("{$path} is missing.");

        return null;
    }

    try {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $problems[] = problem("Invalid JSON: {$e->getMessage()}.", $path);

        return null;
    }

    if (! is_array($data) || array_is_list($data)) {
        $problems[] = problem('Must contain a non-empty JSON object.', $path);

        return null;
    }

    return $data;
}

/**
 * plugin.json fields, composer name, PSR-4 namespace and service providers.
 *
 * @param  array<string, mixed>|null  $plugin
 * @param  array<string, mixed>|null  $composer
 * @return list<array{message: string, file: ?string, line: ?int}>
 */
function checkManifest(?array $plugin, ?array $composer): array
{
    if ($plugin === null) {
        return [];
    }

    $problems = [];

    foreach (['id', 'name', 'version', 'azuriom_api'] as $key) {
        if (! isFilledString($plugin[$key] ?? null)) {
            $problems[] = problem("\"{$key}\" must be a non-empty string.", PLUGIN_JSON, lineOf(PLUGIN_JSON, "\"{$key}\""));
        }
    }

    $api = $plugin['azuriom_api'] ?? null;

    if (isFilledString($api) && ! preg_match('/^\d+\.\d+\.\d+$/', $api)) {
        $problems[] = problem("\"azuriom_api\" must be an Azuriom version like \"1.1.0\", got \"{$api}\".", PLUGIN_JSON, lineOf(PLUGIN_JSON, '"azuriom_api"'));
    }

    $providers = $plugin['providers'] ?? []; // Optional for Azuriom.

    if (! is_array($providers) || ! array_is_list($providers) || array_filter($providers, fn (mixed $provider): bool => ! isFilledString($provider)) !== []) {
        $problems[] = problem('"providers" must be a list of class names.', PLUGIN_JSON, lineOf(PLUGIN_JSON, '"providers"'));
        $providers = [];
    }

    $id = $plugin['id'] ?? null;

    if (! isFilledString($id) || $composer === null) {
        return $problems;
    }

    $expectedName = "azuriom/{$id}";

    if (($composer['name'] ?? null) !== $expectedName) {
        $problems[] = problem("\"name\" must be \"{$expectedName}\" to match the plugin ID.", COMPOSER_JSON, lineOf(COMPOSER_JSON, '"name"'));
    }

    $namespace = 'Azuriom\\Plugin\\'.studly($id).'\\';
    $psr4 = $composer['autoload']['psr-4'] ?? null;
    $directories = is_array($psr4) ? array_values(array_filter((array) ($psr4[$namespace] ?? []), 'is_string')) : [];

    if ($directories === []) {
        $problems[] = problem("\"autoload.psr-4\" must map \"{$namespace}\" to the source directory.", COMPOSER_JSON, lineOf(COMPOSER_JSON, '"psr-4"'));

        return $problems;
    }

    foreach ($providers as $provider) {
        $class = ltrim($provider, '\\');
        $line = lineOf(PLUGIN_JSON, (string) json_encode($provider, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (! str_starts_with($class, $namespace)) {
            $problems[] = problem("Provider \"{$class}\" must be in the plugin namespace \"{$namespace}\".", PLUGIN_JSON, $line);

            continue;
        }

        $relativePath = str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
        $candidates = array_map(fn (string $directory): string => rtrim($directory, '/').'/'.$relativePath, $directories);
        $path = current(array_filter($candidates, 'is_file'));

        if ($path === false) {
            $problems[] = problem("Provider \"{$class}\" not found, expected {$candidates[0]}.", PLUGIN_JSON, $line);

            continue;
        }

        $source = (string) file_get_contents($path);
        $separator = (int) strrpos($class, '\\');
        $classNamespace = substr($class, 0, $separator);
        $shortName = substr($class, $separator + 1);

        if (! preg_match('/^(?:<\?php\s+)?namespace\s+'.preg_quote($classNamespace, '/').'\s*[;{]/m', $source)) {
            $problems[] = problem("Must declare \"namespace {$classNamespace};\" for the provider \"{$class}\".", $path);
        } elseif (! preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+'.preg_quote($shortName, '/').'\b/m', $source)) {
            $problems[] = problem("Must declare the class \"{$shortName}\" for the provider \"{$class}\".", $path);
        }
    }

    return $problems;
}

/**
 * Semantic version, matching changelog section and, on a tag build, matching tag.
 *
 * @param  array<string, mixed>|null  $plugin
 * @return list<array{message: string, file: ?string, line: ?int}>
 */
function checkVersion(?array $plugin): array
{
    $version = $plugin['version'] ?? null;

    if (! isFilledString($version)) {
        return []; // Reported by the manifest check.
    }

    $problems = [];
    $line = lineOf(PLUGIN_JSON, '"version"');

    if (! preg_match(SEMVER_PATTERN, $version)) {
        $problems[] = problem("Version \"{$version}\" must follow semantic versioning (MAJOR.MINOR.PATCH).", PLUGIN_JSON, $line);
    }

    if (! is_file(CHANGELOG)) {
        $problems[] = problem(CHANGELOG.' is missing.');
    } else {
        // The latest version section ("## 1.2.0", "## [v1.2.0] - date"...) must be the plugin version.
        // Sections that are not versions, like "## Unreleased", are ignored.
        $changelog = (string) file_get_contents(CHANGELOG);

        if (! preg_match('/^#{2,3}\s+\[?v?(\d+\.\d+\.\d+\S*?)\]?(?=\s|$)/m', $changelog, $latest, PREG_OFFSET_CAPTURE)) {
            $problems[] = problem("No \"## {$version}\" section for the current plugin version.", CHANGELOG, 1);
        } elseif ($latest[1][0] !== $version) {
            $problems[] = problem("The latest section is \"{$latest[1][0]}\" but the plugin version is \"{$version}\".", CHANGELOG, lineAt($changelog, $latest[0][1]));
        }
    }

    if (getenv('GITHUB_REF_TYPE') === 'tag') {
        $tag = (string) getenv('GITHUB_REF_NAME');

        if (preg_replace('/^v/', '', $tag) !== $version) {
            $problems[] = problem("Tag \"{$tag}\" does not match the plugin version \"{$version}\".", PLUGIN_JSON, $line);
        }
    }

    return $problems;
}

/**
 * @param  array<array-key, mixed>  $translations
 * @return list<string>
 */
function flattenKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        if (is_array($value) && $value !== []) {
            array_push($keys, ...flattenKeys($value, $prefix.$key.'.'));
        } else {
            $keys[] = $prefix.$key;
        }
    }

    return $keys;
}

/**
 * Every locale has the same translation files and keys.
 *
 * @return list<array{message: string, file: ?string, line: ?int}>
 */
function checkTranslations(): array
{
    $locales = array_map('basename', glob(LANG_PATH.'/*', GLOB_ONLYDIR) ?: []);

    if (count($locales) < 2) {
        return [];
    }

    $problems = [];
    $keys = []; // file => locale => keys

    foreach ($locales as $locale) {
        foreach (glob(LANG_PATH."/{$locale}/*.php") ?: [] as $path) {
            try {
                $translations = (static fn (string $file): mixed => require $file)($path);
            } catch (Throwable $e) {
                $problems[] = problem("Cannot be loaded: {$e->getMessage()}", $path);

                continue;
            }

            if (! is_array($translations)) {
                $problems[] = problem('Must return an array.', $path);

                continue;
            }

            $keys[basename($path)][$locale] = flattenKeys($translations);
        }
    }

    ksort($keys);

    foreach ($keys as $file => $keysByLocale) {
        $allKeys = array_unique(array_merge(...array_values($keysByLocale)));

        foreach ($locales as $locale) {
            $path = LANG_PATH."/{$locale}/{$file}";

            if (! isset($keysByLocale[$locale])) {
                if (! is_file($path)) {
                    $problems[] = problem("{$path} is missing, it exists for ".implode(', ', array_keys($keysByLocale)).'.');
                }

                continue;
            }

            foreach (array_diff($allKeys, $keysByLocale[$locale]) as $key) {
                $localesWithKey = array_keys(array_filter($keysByLocale, fn (array $localeKeys): bool => in_array($key, $localeKeys, true)));
                $problems[] = problem("Missing key \"{$key}\", present for ".implode(', ', $localesWithKey).'.', $path);
            }
        }
    }

    return $problems;
}

/**
 * Created and renamed tables are prefixed with the plugin ID.
 *
 * @return list<array{message: string, file: ?string, line: ?int}>
 */
function checkMigrations(?string $id): array
{
    if ($id === null) {
        return []; // Reported by the manifest check.
    }

    $prefix = str_replace('-', '_', $id);
    $problems = [];

    foreach (glob(MIGRATIONS_PATH.'/*.php') ?: [] as $path) {
        $source = (string) file_get_contents($path);

        preg_match_all('/Schema::create\(\s*([\'"])(?<table>[^\'"]+)\1/', $source, $creates, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        preg_match_all('/Schema::rename\(\s*([\'"])[^\'"]+\1\s*,\s*([\'"])(?<table>[^\'"]+)\2/', $source, $renames, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ([...$creates, ...$renames] as $match) {
            [$table, $offset] = $match['table'];

            if ($table !== $prefix && ! str_starts_with($table, $prefix.'_')) {
                $problems[] = problem("Table \"{$table}\" must be named \"{$prefix}\" or start with \"{$prefix}_\" to avoid collisions with Azuriom and other plugins.", $path, lineAt($source, $offset));
            }
        }
    }

    return $problems;
}

/**
 * @return list<string>
 */
function committedFiles(): array
{
    if (file_exists('.git')) {
        exec('git ls-files -z 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0) {
            return array_values(array_filter(explode("\0", implode("\n", $output)), fn (string $path): bool => $path !== ''));
        }
    }

    // Not a Git checkout: every file on disk is considered committed.
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS),
        fn (SplFileInfo $file): bool => $file->getFilename() !== '.git',
    ));

    $files = [];

    foreach ($iterator as $file) {
        $files[] = substr($file->getPathname(), 2);
    }

    return $files;
}

function forbiddenReason(string $path): ?string
{
    $segments = explode('/', $path);
    $name = end($segments);

    return match (true) {
        $segments[0] === 'vendor' => 'Composer dependencies are installed, not versioned',
        in_array('node_modules', $segments, true) => 'npm dependencies are installed, not versioned',
        $path === 'composer.lock' => 'Azuriom resolves the plugin dependencies, the lock file is not used',
        $name === '.DS_Store' => 'macOS metadata file',
        default => null,
    };
}

/**
 * No dependencies, lock file or macOS metadata file is committed.
 *
 * @return list<array{message: string, file: ?string, line: ?int}>
 */
function checkCommittedFiles(): array
{
    $pathsByReason = [];

    foreach (committedFiles() as $path) {
        $reason = forbiddenReason($path);

        if ($reason !== null) {
            $pathsByReason[$reason][] = $path;
        }
    }

    $problems = [];

    foreach ($pathsByReason as $reason => $paths) {
        foreach (array_slice($paths, 0, MAX_FILES_PER_REASON) as $path) {
            $problems[] = problem("Must not be committed ({$reason}).", $path);
        }

        if (count($paths) > MAX_FILES_PER_REASON) {
            $problems[] = problem(sprintf('%d more file(s) must not be committed (%s).', count($paths) - MAX_FILES_PER_REASON, $reason));
        }
    }

    return $problems;
}

function escapeData(string $value): string
{
    return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
}

function escapeProperty(string $value): string
{
    return str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
}

/**
 * @param  array{message: string, file: ?string, line: ?int}  $problem
 */
function report(string $check, array $problem): void
{
    ['message' => $message, 'file' => $file, 'line' => $line] = $problem;

    if (getenv('GITHUB_ACTIONS') === 'true') {
        $properties = array_filter(['file' => $file, 'line' => $line, 'title' => "Azuriom plugin: {$check}"], fn (mixed $value): bool => $value !== null);
        $properties = array_map(fn (string $key, int|string $value): string => $key.'='.escapeProperty((string) $value), array_keys($properties), $properties);

        echo '::error '.implode(',', $properties).'::'.escapeData($message).PHP_EOL;

        return;
    }

    $location = $file === null ? '' : $file.($line === null ? '' : ":{$line}").': ';

    echo "    {$location}{$message}".PHP_EOL;
}

$manifestProblems = [];
$plugin = readJsonObject(PLUGIN_JSON, $manifestProblems);
$composer = readJsonObject(COMPOSER_JSON, $manifestProblems);
$id = isFilledString($plugin['id'] ?? null) ? $plugin['id'] : null;

$checks = [
    'Manifest' => [...$manifestProblems, ...checkManifest($plugin, $composer)],
    'Version' => checkVersion($plugin),
    'Translations' => checkTranslations(),
    'Migrations' => checkMigrations($id),
    'Committed files' => checkCommittedFiles(),
];

$failed = 0;

foreach ($checks as $name => $problems) {
    echo ($problems === [] ? '✔' : '✘')." {$name}".PHP_EOL;

    foreach ($problems as $problem) {
        report($name, $problem);
    }

    $failed += $problems === [] ? 0 : 1;
}

echo PHP_EOL.($failed === 0 ? 'All Azuriom plugin checks passed.' : "{$failed} check(s) failed.").PHP_EOL;

exit($failed === 0 ? 0 : 1);
