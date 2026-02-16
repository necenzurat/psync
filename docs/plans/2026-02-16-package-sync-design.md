# packageSync Design

## Overview

A global Composer CLI tool that reads a project's `composer.json` and `composer.lock`, compares installed package versions against the latest stable versions on Packagist, and prints a colored table showing which packages have updates available.

## Installation

```bash
composer global require necenzurat/package-sync
```

## Framework

Laravel Zero — micro-framework for console applications.

## Core Command: `package-sync check`

1. Read `composer.json` from current directory (or `--path` flag) to get list of all require + require-dev packages
2. Read `composer.lock` to get exact installed versions
3. Fetch latest stable version from Packagist v2 metadata API for each package
4. Compare installed vs latest
5. Print colored table with Package, Installed, Latest, Status columns
6. Exit code 0 if all up to date, 1 if updates exist

## Packagist API

- Endpoint: `https://repo.packagist.org/p2/{vendor}/{package}.json`
- No authentication required
- Concurrent HTTP requests via Laravel HTTP client
- Skip non-Packagist entries (php, ext-*)

## Output

```
+-------------------------+-----------+--------+--------+
| Package                 | Installed | Latest | Status |
+-------------------------+-----------+--------+--------+
| laravel/framework       | 12.0.1    | 12.3.0 | UPDATE |
| pestphp/pest            | 4.3.0     | 4.3.0  | OK     |
+-------------------------+-----------+--------+--------+

2 packages checked. 1 update available.
```

- UPDATE rows highlighted in yellow
- OK rows in green
- Summary line at bottom

## Flags

- `--path=/path/to/project` — check a different project directory
- `--json` — output as JSON for scripting/CI

## PHP Requirement

`^8.2`

## Package Name

`necenzurat/package-sync`

## Binary

`package-sync`
