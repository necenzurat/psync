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
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $updatesAvailable > 0 ? self::FAILURE : self::SUCCESS;
        }

        $tableRows = array_map(function (array $row): array {
            if ($row['status'] === 'UPDATE') {
                return [
                    '<fg=red>' . $row['package'] . '</>',
                    '<fg=red>' . $row['installed'] . '</>',
                    '<fg=red>' . $row['latest'] . '</>',
                    '<fg=red>' . $row['status'] . '</>',
                ];
            }

            return [$row['package'], $row['installed'], $row['latest'], '<fg=green>' . $row['status'] . '</>'];
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
