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
