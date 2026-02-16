# packageSync Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a global Composer CLI tool that compares installed package versions from `composer.lock` against the latest stable versions on Packagist and prints a colored table.

**Architecture:** Laravel Zero CLI app with a single `check` command. A `PackagistClient` service fetches latest versions via the Packagist v2 metadata API. A `ComposerFileReader` service parses `composer.json` and `composer.lock`. Results are displayed as a Symfony Console table with colored status indicators.

**Tech Stack:** Laravel Zero, PHP 8.2+, Laravel HTTP Client (Guzzle), Pest for testing.

---

### Task 1: Scaffold Laravel Zero Project

**Files:**
- Create: entire project scaffold in `/Users/necenzurat/apps/menta.ro/packageSync/`

**Step 1: Create the Laravel Zero project**

Run:
```bash
cd /Users/necenzurat/apps/menta.ro && composer create-project --prefer-dist laravel-zero/laravel-zero packageSync-tmp
```

**Step 2: Move scaffold files into the packageSync directory**

The scaffold creates a new directory. Move its contents into our existing `packageSync/` dir (which has our docs/ and .git):

```bash
# Move everything except .git from tmp into packageSync
cp -r /Users/necenzurat/apps/menta.ro/packageSync-tmp/. /Users/necenzurat/apps/menta.ro/packageSync/
rm -rf /Users/necenzurat/apps/menta.ro/packageSync-tmp
```

**Step 3: Rename the application**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && php application app:rename package-sync
```

This renames the binary from `application` to `package-sync`.

**Step 4: Install HTTP client add-on**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && php package-sync app:install http
```

**Step 5: Install Pest for testing**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && composer require pestphp/pest --dev --with-all-dependencies
```

Then initialize Pest:
```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && ./vendor/bin/pest --init
```

**Step 6: Verify it works**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && php package-sync
```

Expected: Laravel Zero welcome output showing the app name.

**Step 7: Update composer.json metadata**

Edit `composer.json` to set:
- `"name": "necenzurat/package-sync"`
- `"description": "Compare installed Composer packages against latest Packagist versions"`
- `"bin": ["package-sync"]`
- `"type": "project"`

**Step 8: Commit**

```bash
git add -A && git commit -m "feat: scaffold Laravel Zero project with HTTP client and Pest"
```

---

### Task 2: ComposerFileReader Service

**Files:**
- Create: `app/Services/ComposerFileReader.php`
- Test: `tests/Unit/ComposerFileReaderTest.php`
- Create: `tests/Fixtures/composer.json` (test fixture)
- Create: `tests/Fixtures/composer.lock` (test fixture)

**Step 1: Create test fixtures**

Create `tests/Fixtures/composer.json`:
```json
{
    "require": {
        "php": "^8.2",
        "laravel/framework": "^12.0",
        "guzzlehttp/guzzle": "^7.0"
    },
    "require-dev": {
        "pestphp/pest": "^4.0"
    }
}
```

Create `tests/Fixtures/composer.lock`:
```json
{
    "packages": [
        {
            "name": "laravel/framework",
            "version": "v12.0.1"
        },
        {
            "name": "guzzlehttp/guzzle",
            "version": "7.8.1"
        }
    ],
    "packages-dev": [
        {
            "name": "pestphp/pest",
            "version": "v4.3.0"
        }
    ]
}
```

**Step 2: Write the failing tests**

Create `tests/Unit/ComposerFileReaderTest.php`:
```php
<?php

use App\Services\ComposerFileReader;

beforeEach(function () {
    $this->reader = new ComposerFileReader(
        __DIR__ . '/../Fixtures'
    );
});

it('reads package names from composer.json require and require-dev', function () {
    $packages = $this->reader->getPackageNames();

    expect($packages)->toBe([
        'laravel/framework',
        'guzzlehttp/guzzle',
        'pestphp/pest',
    ]);
});

it('skips php and ext-* entries', function () {
    $packages = $this->reader->getPackageNames();

    expect($packages)->not->toContain('php');
});

it('reads installed versions from composer.lock', function () {
    $versions = $this->reader->getInstalledVersions();

    expect($versions)->toBe([
        'laravel/framework' => '12.0.1',
        'guzzlehttp/guzzle' => '7.8.1',
        'pestphp/pest' => '4.3.0',
    ]);
});

it('normalizes version strings by removing v prefix', function () {
    $versions = $this->reader->getInstalledVersions();

    expect($versions['laravel/framework'])->toBe('12.0.1');
    expect($versions['pestphp/pest'])->toBe('4.3.0');
});

it('throws exception when composer.json is missing', function () {
    new ComposerFileReader('/nonexistent/path');
})->throws(RuntimeException::class, 'composer.json not found');

it('throws exception when composer.lock is missing', function () {
    // Create a temp dir with only composer.json
    $tmpDir = sys_get_temp_dir() . '/package-sync-test-' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/composer.json', '{"require":{}}');

    $reader = new ComposerFileReader($tmpDir);
    $reader->getInstalledVersions();

    // Cleanup
    unlink($tmpDir . '/composer.json');
    rmdir($tmpDir);
})->throws(RuntimeException::class, 'composer.lock not found');
```

**Step 3: Run tests to verify they fail**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && ./vendor/bin/pest tests/Unit/ComposerFileReaderTest.php
```

Expected: FAIL — class not found.

**Step 4: Implement ComposerFileReader**

Create `app/Services/ComposerFileReader.php`:
```php
<?php

namespace App\Services;

use RuntimeException;

class ComposerFileReader
{
    private string $path;

    public function __construct(string $path)
    {
        if (! file_exists($path . '/composer.json')) {
            throw new RuntimeException('composer.json not found in: ' . $path);
        }

        $this->path = $path;
    }

    /**
     * @return list<string>
     */
    public function getPackageNames(): array
    {
        $composerJson = $this->readJson('composer.json');

        $require = array_keys($composerJson['require'] ?? []);
        $requireDev = array_keys($composerJson['require-dev'] ?? []);

        $all = array_merge($require, $requireDev);

        return array_values(array_filter($all, function (string $name): bool {
            return str_contains($name, '/') && ! str_starts_with($name, 'ext-');
        }));
    }

    /**
     * @return array<string, string>
     */
    public function getInstalledVersions(): array
    {
        $lockFile = $this->path . '/composer.lock';

        if (! file_exists($lockFile)) {
            throw new RuntimeException('composer.lock not found in: ' . $this->path);
        }

        $lock = $this->readJson('composer.lock');

        $versions = [];

        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $versions[$package['name']] = ltrim($package['version'], 'v');
        }

        return $versions;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $filename): array
    {
        $content = file_get_contents($this->path . '/' . $filename);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
```

**Step 5: Run tests to verify they pass**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && ./vendor/bin/pest tests/Unit/ComposerFileReaderTest.php
```

Expected: All 6 tests PASS.

**Step 6: Commit**

```bash
git add app/Services/ComposerFileReader.php tests/Unit/ComposerFileReaderTest.php tests/Fixtures/
git commit -m "feat: add ComposerFileReader service with tests"
```

---

### Task 3: PackagistClient Service

**Files:**
- Create: `app/Services/PackagistClient.php`
- Test: `tests/Unit/PackagistClientTest.php`

**Step 1: Write the failing tests**

Create `tests/Unit/PackagistClientTest.php`:
```php
<?php

use App\Services\PackagistClient;
use Illuminate\Support\Facades\Http;

it('fetches the latest stable version for a package', function () {
    Http::fake([
        'repo.packagist.org/p2/laravel/framework.json' => Http::response([
            'packages' => [
                'laravel/framework' => [
                    ['version' => 'v12.51.0', 'version_normalized' => '12.51.0.0'],
                    ['version' => 'v12.50.0', 'version_normalized' => '12.50.0.0'],
                    ['version' => 'v12.51.1-beta.1', 'version_normalized' => '12.51.1.0-beta1'],
                ],
            ],
        ]),
    ]);

    $client = new PackagistClient();
    $version = $client->getLatestVersion('laravel/framework');

    expect($version)->toBe('12.51.0');
});

it('skips dev and pre-release versions', function () {
    Http::fake([
        'repo.packagist.org/p2/some/package.json' => Http::response([
            'packages' => [
                'some/package' => [
                    ['version' => 'dev-main', 'version_normalized' => 'dev-main'],
                    ['version' => 'v2.0.0-RC1', 'version_normalized' => '2.0.0.0-RC1'],
                    ['version' => 'v1.5.0', 'version_normalized' => '1.5.0.0'],
                ],
            ],
        ]),
    ]);

    $client = new PackagistClient();
    $version = $client->getLatestVersion('some/package');

    expect($version)->toBe('1.5.0');
});

it('returns null when package is not found', function () {
    Http::fake([
        'repo.packagist.org/p2/nonexistent/package.json' => Http::response([], 404),
    ]);

    $client = new PackagistClient();
    $version = $client->getLatestVersion('nonexistent/package');

    expect($version)->toBeNull();
});

it('fetches latest versions for multiple packages concurrently', function () {
    Http::fake([
        'repo.packagist.org/p2/laravel/framework.json' => Http::response([
            'packages' => [
                'laravel/framework' => [
                    ['version' => 'v12.51.0', 'version_normalized' => '12.51.0.0'],
                ],
            ],
        ]),
        'repo.packagist.org/p2/pestphp/pest.json' => Http::response([
            'packages' => [
                'pestphp/pest' => [
                    ['version' => 'v4.5.0', 'version_normalized' => '4.5.0.0'],
                ],
            ],
        ]),
    ]);

    $client = new PackagistClient();
    $versions = $client->getLatestVersions(['laravel/framework', 'pestphp/pest']);

    expect($versions)->toBe([
        'laravel/framework' => '12.51.0',
        'pestphp/pest' => '4.5.0',
    ]);
});
```

**Step 2: Run tests to verify they fail**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && ./vendor/bin/pest tests/Unit/PackagistClientTest.php
```

Expected: FAIL — class not found.

**Step 3: Implement PackagistClient**

Create `app/Services/PackagistClient.php`:
```php
<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class PackagistClient
{
    private const BASE_URL = 'https://repo.packagist.org/p2';

    public function getLatestVersion(string $packageName): ?string
    {
        $response = Http::get(self::BASE_URL . '/' . $packageName . '.json');

        if ($response->failed()) {
            return null;
        }

        $versions = $response->json("packages.{$packageName}", []);

        return $this->findLatestStable($versions);
    }

    /**
     * @param list<string> $packageNames
     * @return array<string, string|null>
     */
    public function getLatestVersions(array $packageNames): array
    {
        $responses = Http::pool(function (Pool $pool) use ($packageNames) {
            foreach ($packageNames as $name) {
                $pool->as($name)->get(self::BASE_URL . '/' . $name . '.json');
            }
        });

        $results = [];

        foreach ($packageNames as $name) {
            $response = $responses[$name];

            if ($response->failed()) {
                $results[$name] = null;

                continue;
            }

            $versions = $response->json("packages.{$name}", []);
            $results[$name] = $this->findLatestStable($versions);
        }

        return $results;
    }

    /**
     * @param array<int, array{version: string, version_normalized: string}> $versions
     */
    private function findLatestStable(array $versions): ?string
    {
        foreach ($versions as $entry) {
            $normalized = $entry['version_normalized'] ?? '';

            if (str_starts_with($normalized, 'dev-')) {
                continue;
            }

            if (preg_match('/-(alpha|beta|rc|dev|patch)/i', $normalized)) {
                continue;
            }

            return ltrim($entry['version'], 'v');
        }

        return null;
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && ./vendor/bin/pest tests/Unit/PackagistClientTest.php
```

Expected: All 4 tests PASS.

**Step 5: Commit**

```bash
git add app/Services/PackagistClient.php tests/Unit/PackagistClientTest.php
git commit -m "feat: add PackagistClient service with concurrent Packagist API calls"
```

---

### Task 4: Check Command

**Files:**
- Create: `app/Commands/CheckCommand.php`
- Test: `tests/Feature/CheckCommandTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/CheckCommandTest.php`:
```php
<?php

use Illuminate\Support\Facades\Http;

it('displays a table of packages with update status', function () {
    // Point the command at our test fixtures
    Http::fake([
        'repo.packagist.org/p2/laravel/framework.json' => Http::response([
            'packages' => [
                'laravel/framework' => [
                    ['version' => 'v12.5.0', 'version_normalized' => '12.5.0.0'],
                ],
            ],
        ]),
        'repo.packagist.org/p2/guzzlehttp/guzzle.json' => Http::response([
            'packages' => [
                'guzzlehttp/guzzle' => [
                    ['version' => '7.8.1', 'version_normalized' => '7.8.1.0'],
                ],
            ],
        ]),
        'repo.packagist.org/p2/pestphp/pest.json' => Http::response([
            'packages' => [
                'pestphp/pest' => [
                    ['version' => 'v4.5.0', 'version_normalized' => '4.5.0.0'],
                ],
            ],
        ]),
    ]);

    $fixturePath = __DIR__ . '/../Fixtures';

    $this->artisan('check', ['--path' => $fixturePath])
        ->expectsTable(
            ['Package', 'Installed', 'Latest', 'Status'],
            [
                ['laravel/framework', '12.0.1', '12.5.0', 'UPDATE'],
                ['guzzlehttp/guzzle', '7.8.1', '7.8.1', 'OK'],
                ['pestphp/pest', '4.3.0', '4.5.0', 'UPDATE'],
            ]
        )
        ->expectsOutputToContain('2 updates available')
        ->assertExitCode(1);
});

it('returns exit code 0 when all packages are up to date', function () {
    Http::fake([
        'repo.packagist.org/p2/laravel/framework.json' => Http::response([
            'packages' => [
                'laravel/framework' => [
                    ['version' => 'v12.0.1', 'version_normalized' => '12.0.1.0'],
                ],
            ],
        ]),
        'repo.packagist.org/p2/guzzlehttp/guzzle.json' => Http::response([
            'packages' => [
                'guzzlehttp/guzzle' => [
                    ['version' => '7.8.1', 'version_normalized' => '7.8.1.0'],
                ],
            ],
        ]),
        'repo.packagist.org/p2/pestphp/pest.json' => Http::response([
            'packages' => [
                'pestphp/pest' => [
                    ['version' => 'v4.3.0', 'version_normalized' => '4.3.0.0'],
                ],
            ],
        ]),
    ]);

    $fixturePath = __DIR__ . '/../Fixtures';

    $this->artisan('check', ['--path' => $fixturePath])
        ->expectsOutputToContain('All packages are up to date')
        ->assertExitCode(0);
});

it('outputs JSON when --json flag is used', function () {
    Http::fake([
        'repo.packagist.org/p2/laravel/framework.json' => Http::response([
            'packages' => [
                'laravel/framework' => [
                    ['version' => 'v12.5.0', 'version_normalized' => '12.5.0.0'],
                ],
            ],
        ]),
        'repo.packagist.org/p2/guzzlehttp/guzzle.json' => Http::response([
            'packages' => [
                'guzzlehttp/guzzle' => [
                    ['version' => '7.8.1', 'version_normalized' => '7.8.1.0'],
                ],
            ],
        ]),
        'repo.packagist.org/p2/pestphp/pest.json' => Http::response([
            'packages' => [
                'pestphp/pest' => [
                    ['version' => 'v4.5.0', 'version_normalized' => '4.5.0.0'],
                ],
            ],
        ]),
    ]);

    $fixturePath = __DIR__ . '/../Fixtures';

    $this->artisan('check', ['--path' => $fixturePath, '--json' => true])
        ->expectsOutputToContain('"laravel/framework"')
        ->assertExitCode(1);
});
```

**Step 2: Run tests to verify they fail**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && ./vendor/bin/pest tests/Feature/CheckCommandTest.php
```

Expected: FAIL — command not found.

**Step 3: Create the Check command**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && php package-sync make:command CheckCommand
```

Then replace its contents with:

`app/Commands/CheckCommand.php`:
```php
<?php

namespace App\Commands;

use App\Services\ComposerFileReader;
use App\Services\PackagistClient;
use LaravelZero\Framework\Commands\Command;

class CheckCommand extends Command
{
    protected $signature = 'check
                            {--path= : Path to the project directory (defaults to current directory)}
                            {--json : Output results as JSON}';

    protected $description = 'Check installed packages against latest Packagist versions';

    public function handle(PackagistClient $packagist): int
    {
        $path = $this->option('path') ?: getcwd();

        try {
            $reader = new ComposerFileReader($path);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $packageNames = $reader->getPackageNames();
        $installed = $reader->getInstalledVersions();

        $this->info('Checking ' . count($packageNames) . ' packages...');
        $this->newLine();

        $latest = $packagist->getLatestVersions($packageNames);

        $rows = [];
        $updatesAvailable = 0;

        foreach ($packageNames as $name) {
            $installedVersion = $installed[$name] ?? 'N/A';
            $latestVersion = $latest[$name] ?? 'N/A';

            $hasUpdate = $latestVersion !== 'N/A'
                && $installedVersion !== 'N/A'
                && version_compare($installedVersion, $latestVersion, '<');

            if ($hasUpdate) {
                $updatesAvailable++;
            }

            $rows[] = [
                'package' => $name,
                'installed' => $installedVersion,
                'latest' => $latestVersion,
                'status' => $hasUpdate ? 'UPDATE' : 'OK',
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT));

            return $updatesAvailable > 0 ? self::FAILURE : self::SUCCESS;
        }

        $tableRows = array_map(function (array $row): array {
            $status = $row['status'] === 'UPDATE'
                ? '<fg=yellow>' . $row['status'] . '</>'
                : '<fg=green>' . $row['status'] . '</>';

            return [$row['package'], $row['installed'], $row['latest'], $status];
        }, $rows);

        $this->table(
            ['Package', 'Installed', 'Latest', 'Status'],
            $tableRows
        );

        $this->newLine();

        if ($updatesAvailable > 0) {
            $this->warn("{$updatesAvailable} updates available.");

            return self::FAILURE;
        }

        $this->info('All packages are up to date!');

        return self::SUCCESS;
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && ./vendor/bin/pest tests/Feature/CheckCommandTest.php
```

Expected: All 3 tests PASS.

**Step 5: Run all tests**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && ./vendor/bin/pest
```

Expected: All 13 tests PASS.

**Step 6: Commit**

```bash
git add app/Commands/CheckCommand.php tests/Feature/CheckCommandTest.php
git commit -m "feat: add check command with table output, JSON mode, and --path flag"
```

---

### Task 5: Remove Default Command & Set Check as Default

**Files:**
- Modify: `app/Commands/InspiringCommand.php` (delete)
- Modify: `config/commands.php` (if exists, set default command)

**Step 1: Delete the default InspiringCommand**

```bash
rm /Users/necenzurat/apps/menta.ro/packageSync/app/Commands/InspiringCommand.php
```

**Step 2: Make `check` the default command**

In `app/Commands/CheckCommand.php`, set:
```php
protected $signature = 'check ...';
```

Also in `config/commands.php`, set the default command:
```php
'default' => NunoMaduro\LaravelConsoleSummary\SummaryCommand::class,
```

Change to:
```php
'default' => App\Commands\CheckCommand::class,
```

**Step 3: Verify running the binary without arguments runs check**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && php package-sync --help
```

Expected: Shows the `check` command help.

**Step 4: Run all tests**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && ./vendor/bin/pest
```

Expected: All tests PASS.

**Step 5: Commit**

```bash
git add -A && git commit -m "feat: set check as default command, remove InspiringCommand"
```

---

### Task 6: Manual Integration Test

**Step 1: Run against the parent Laravel project**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && php package-sync check --path=/Users/necenzurat/apps/menta.ro
```

Expected: A table showing all packages from the Laravel project with their installed vs latest versions.

**Step 2: Run with --json flag**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && php package-sync check --path=/Users/necenzurat/apps/menta.ro --json
```

Expected: JSON array output.

**Step 3: Verify exit code**

```bash
cd /Users/necenzurat/apps/menta.ro/packageSync && php package-sync check --path=/Users/necenzurat/apps/menta.ro; echo "Exit code: $?"
```

Expected: Exit code 1 if any packages have updates, 0 if all up to date.

**Step 4: Final commit if any tweaks needed**

```bash
git add -A && git commit -m "fix: integration test adjustments"
```
