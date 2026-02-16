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
