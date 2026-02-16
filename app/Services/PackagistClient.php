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
