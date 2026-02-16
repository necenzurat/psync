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
