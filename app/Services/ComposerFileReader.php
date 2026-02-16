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
        return array_keys($this->getRequiredConstraints());
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredConstraints(): array
    {
        $composerJson = $this->readJson('composer.json');

        $constraints = [];
        $sections = ['require', 'require-dev'];

        foreach ($sections as $section) {
            $packages = $composerJson[$section] ?? [];

            if (! is_array($packages)) {
                continue;
            }

            foreach ($packages as $name => $constraint) {
                if (! is_string($name) || ! is_string($constraint)) {
                    continue;
                }

                if (! str_contains($name, '/') || str_starts_with($name, 'ext-')) {
                    continue;
                }

                $constraints[$name] = $constraint;
            }
        }

        return $constraints;
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
     * @param array<string, string> $constraints
     */
    public function updateRequiredConstraints(array $constraints): int
    {
        if ($constraints === []) {
            return 0;
        }

        $composerJson = $this->readJson('composer.json');
        $updated = 0;
        $sections = ['require', 'require-dev'];

        foreach ($sections as $section) {
            $packages = $composerJson[$section] ?? null;

            if (! is_array($packages)) {
                continue;
            }

            foreach ($constraints as $name => $constraint) {
                if (! array_key_exists($name, $packages)) {
                    continue;
                }

                if (! is_string($packages[$name]) || $packages[$name] === $constraint) {
                    continue;
                }

                $composerJson[$section][$name] = $constraint;
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->writeJson('composer.json', $composerJson);
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $filename): array
    {
        $content = file_get_contents($this->path . '/' . $filename);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function writeJson(string $filename, array $content): void
    {
        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $result = file_put_contents($this->path . '/' . $filename, $json . PHP_EOL);

        if ($result === false) {
            throw new RuntimeException('Unable to write ' . $filename . ' in: ' . $this->path);
        }
    }
}
