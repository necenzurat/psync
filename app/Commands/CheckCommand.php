<?php

namespace App\Commands;

use App\Services\ComposerFileReader;
use App\Services\PackagistClient;
use LaravelZero\Framework\Commands\Command;

class CheckCommand extends Command
{
    protected $signature = 'check
                            {target? : Project directory path or composer.json file}
                            {--path= : Path to the project directory (defaults to current directory)}
                            {--update-composer : Update composer.json constraints for outdated packages}
                            {--json : Output results as JSON}';

    protected $description = 'Check installed packages against latest Packagist versions';

    public function handle(PackagistClient $packagist): int
    {
        try {
            $path = $this->resolvePath();
            $reader = new ComposerFileReader($path);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $packageNames = $reader->getPackageNames();
        $constraints = $reader->getRequiredConstraints();
        $installed = $reader->getInstalledVersions();

        $this->info('Checking ' . count($packageNames) . ' packages...');
        $this->newLine();

        $latest = $packagist->getLatestVersions($packageNames);

        $rows = [];
        $updatesAvailable = 0;
        $constraintRows = [];
        $constraintUpdates = 0;
        $updatableConstraints = [];
        $manualReviewConstraints = [];

        foreach ($packageNames as $name) {
            $requiredConstraint = $constraints[$name] ?? 'N/A';
            $installedVersion = $installed[$name] ?? 'N/A';
            $latestVersion = $latest[$name] ?? 'N/A';

            $hasUpdate = $latestVersion !== 'N/A'
                && $installedVersion !== 'N/A'
                && version_compare($installedVersion, $latestVersion, '<');

            if ($hasUpdate) {
                $updatesAvailable++;
            }

            $constraintOutdated = $this->isConstraintOutdated($requiredConstraint, $latestVersion);

            if ($constraintOutdated) {
                $constraintUpdates++;
            }

            $suggestedConstraint = $constraintOutdated
                ? $this->buildSuggestedConstraint($requiredConstraint, $latestVersion)
                : null;

            $rows[] = [
                'package' => $name,
                'required' => $requiredConstraint,
                'installed' => $installedVersion,
                'latest' => $latestVersion,
                'status' => $hasUpdate ? 'UPDATE' : 'OK',
                'constraint_status' => $constraintOutdated ? ($suggestedConstraint === null ? 'REVIEW' : 'UPDATE') : 'OK',
                'suggested_constraint' => $suggestedConstraint,
            ];

            if ($constraintOutdated && $suggestedConstraint !== null && $suggestedConstraint !== $requiredConstraint) {
                $updatableConstraints[$name] = $suggestedConstraint;
            }

            if ($constraintOutdated && $suggestedConstraint === null) {
                $manualReviewConstraints[] = $name;
            }

            $constraintRows[] = [
                'package' => $name,
                'required' => $requiredConstraint,
                'latest' => $latestVersion,
                'suggested' => $suggestedConstraint ?? 'MANUAL',
                'status' => $constraintOutdated ? ($suggestedConstraint === null ? 'REVIEW' : 'UPDATE') : 'OK',
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

        $this->info('composer.json constraints vs latest:');
        $this->newLine();

        $constraintTableRows = array_map(function (array $row): array {
            if ($row['status'] === 'UPDATE') {
                return [
                    '<fg=yellow>' . $row['package'] . '</>',
                    '<fg=yellow>' . $row['required'] . '</>',
                    '<fg=yellow>' . $row['latest'] . '</>',
                    '<fg=yellow>' . $row['suggested'] . '</>',
                    '<fg=yellow>' . $row['status'] . '</>',
                ];
            }

            if ($row['status'] === 'REVIEW') {
                return [
                    '<fg=red>' . $row['package'] . '</>',
                    '<fg=red>' . $row['required'] . '</>',
                    '<fg=red>' . $row['latest'] . '</>',
                    '<fg=red>' . $row['suggested'] . '</>',
                    '<fg=red>' . $row['status'] . '</>',
                ];
            }

            return [$row['package'], $row['required'], $row['latest'], $row['suggested'], '<fg=green>' . $row['status'] . '</>'];
        }, $constraintRows);

        $this->table(
            ['Package', 'Required', 'Latest', 'Suggested', 'Status'],
            $constraintTableRows
        );

        $this->newLine();

        if ($constraintUpdates > 0) {
            $this->warn("{$constraintUpdates} composer.json constraints differ from latest versions.");

            if ($updatableConstraints !== []) {
                $shouldUpdateComposer = (bool) $this->option('update-composer');

                if (! $shouldUpdateComposer && ! app()->runningUnitTests() && $this->input->isInteractive()) {
                    $shouldUpdateComposer = $this->confirm('Update composer.json constraints automatically where possible?', false);
                }

                if ($shouldUpdateComposer) {
                    $updated = $reader->updateRequiredConstraints($updatableConstraints);
                    $this->info("Updated composer.json constraints for {$updated} package(s).");
                } else {
                    $this->line('Run with --update-composer to apply suggested composer.json updates.');
                }
            }

            if ($manualReviewConstraints !== []) {
                $this->warn('Manual review needed for: ' . implode(', ', $manualReviewConstraints));
            }

            $this->newLine();
        }

        if ($updatesAvailable > 0) {
            $this->warn("{$updatesAvailable} updates available.");

            return self::FAILURE;
        }

        $this->info('All packages are up to date!');

        return self::SUCCESS;
    }

    private function isConstraintOutdated(string $requiredConstraint, string $latestVersion): bool
    {
        if ($requiredConstraint === 'N/A' || $latestVersion === 'N/A') {
            return false;
        }

        $baseVersion = $this->extractBaseVersion($requiredConstraint);

        if ($baseVersion === null) {
            return false;
        }

        return version_compare($baseVersion, $latestVersion, '<');
    }

    private function extractBaseVersion(string $constraint): ?string
    {
        if (preg_match('/(\d+(?:\.\d+){0,3})/', $constraint, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function buildSuggestedConstraint(string $requiredConstraint, string $latestVersion): ?string
    {
        $trimmed = trim($requiredConstraint);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '^')) {
            return '^' . $latestVersion;
        }

        if (str_starts_with($trimmed, '~')) {
            return '~' . $latestVersion;
        }

        if (preg_match('/^[vV]?\d+(?:\.\d+){0,3}$/', $trimmed) === 1) {
            return $latestVersion;
        }

        return null;
    }

    private function resolvePath(): string
    {
        $target = $this->argument('target');
        $pathOption = $this->option('path');

        if (is_string($target) && $target !== '' && is_string($pathOption) && $pathOption !== '') {
            throw new \RuntimeException('Use either the target argument or --path, not both.');
        }

        $cwd = getcwd();
        $defaultPath = is_string($cwd) && $cwd !== '' ? $cwd : '.';

        $path = $pathOption ?: $target ?: $defaultPath;

        if (! is_string($path)) {
            return $defaultPath;
        }

        if (is_file($path)) {
            $filename = basename($path);

            if ($filename === 'composer.json' || $filename === 'composer.lock') {
                return dirname($path);
            }

            throw new \RuntimeException('Expected a project directory or composer.json/composer.lock file, got: ' . $path);
        }

        return $path;
    }
}
