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
