<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesAdmin;

use Survos\GeoBoundariesBundle\Service\Geometry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetch one API listing. `ALL` is accepted for both iso3 and level; a single boundary comes back
 * as one object rather than a list, so normalise to a list.
 *
 * @return list<array<string, mixed>>
 */
function fetchApiListing(HttpClientInterface $httpClient, string $apiBase, string $release, string $iso3, string $level): array
{
    $url = sprintf('%s/current/%s/%s/%s/', rtrim($apiBase, '/'), $release, strtoupper($iso3), strtoupper($level));
    $data = $httpClient->request('GET', $url)->toArray();

    return isset($data['boundaryID']) ? [$data] : array_values($data);
}

/** Where a downloaded layer lives: <sourceDir>/<ISO3>/<ADMn>/<file name from the API link>. */
function localLayerPath(string $sourceDir, array $entry, string $urlField): ?string
{
    $url = $entry[$urlField] ?? '';
    if (!is_string($url) || '' === $url) {
        return null;
    }

    return sprintf(
        '%s/%s/%s/%s',
        rtrim($sourceDir, '/'),
        strtoupper((string) $entry['boundaryISO']),
        strtoupper((string) $entry['boundaryType']),
        basename((string) parse_url($url, PHP_URL_PATH)),
    );
}

/**
 * Download with a .part file and retries. The API links are GitHub LFS redirects, and GitHub
 * rate-limits, so back off on failure instead of failing the whole run on one throttled file.
 */
function downloadToFile(HttpClientInterface $httpClient, string $url, string $targetPath, int $attempts = 4): void
{
    @mkdir(dirname($targetPath), 0775, true);
    $tmpPath = $targetPath.'.part';

    for ($attempt = 1;; ++$attempt) {
        try {
            $response = $httpClient->request('GET', $url, ['timeout' => 60]);
            $handle = fopen($tmpPath, 'wb');
            if (false === $handle) {
                throw new \RuntimeException(sprintf('Unable to open "%s" for writing.', $tmpPath));
            }
            try {
                foreach ($httpClient->stream($response) as $chunk) {
                    if (!$chunk->isTimeout()) {
                        fwrite($handle, $chunk->getContent());
                    }
                }
            } finally {
                fclose($handle);
            }
            rename($tmpPath, $targetPath);

            return;
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            if ($attempt >= $attempts) {
                throw $e;
            }
            sleep(2 ** $attempt);
        }
    }
}

function createSqliteConnection(string $databasePath): \PDO
{
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
    $pdo = new \PDO('sqlite:'.$databasePath);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = OFF; PRAGMA synchronous = OFF; PRAGMA temp_store = MEMORY;');

    return $pdo;
}

function createBoundaryTables(\PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE boundary (
    id INTEGER PRIMARY KEY,
    shape_id TEXT NOT NULL UNIQUE,
    iso3 TEXT NOT NULL,
    level INTEGER NOT NULL,
    name TEXT NOT NULL,
    shape_iso TEXT NULL,
    parent_id INTEGER NULL,
    geoname_id INTEGER NULL,
    min_x REAL NOT NULL, min_y REAL NOT NULL, max_x REAL NOT NULL, max_y REAL NOT NULL,
    geometry TEXT NOT NULL
);
CREATE VIRTUAL TABLE boundary_rtree USING rtree(id, min_x, max_x, min_y, max_y);
SQL);
}

function finalizeBoundaryIndexes(\PDO $pdo): void
{
    $pdo->exec('CREATE INDEX idx_boundary_level ON boundary(level); CREATE INDEX idx_boundary_name ON boundary(lower(name)); CREATE INDEX idx_boundary_parent ON boundary(parent_id); ANALYZE; VACUUM;');
}

/**
 * Insert GeoJSON features as boundary rows (and rtree entries). Returns the number inserted.
 *
 * @param list<array<string, mixed>> $features
 */
function insertBoundaries(\PDO $pdo, string $iso3, int $level, array $features): int
{
    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO boundary (shape_id, iso3, level, name, shape_iso, min_x, min_y, max_x, max_y, geometry)
         VALUES (:shape_id, :iso3, :level, :name, :shape_iso, :min_x, :min_y, :max_x, :max_y, :geometry)'
    );
    $rtree = $pdo->prepare('INSERT INTO boundary_rtree (id, min_x, max_x, min_y, max_y) VALUES (?, ?, ?, ?, ?)');

    $count = 0;
    foreach ($features as $feature) {
        $geometry = $feature['geometry'] ?? null;
        $bbox = is_array($geometry) ? Geometry::bbox($geometry) : null;
        if (null === $bbox) {
            continue; // null/empty geometry: nothing to locate, nothing to store
        }
        $properties = $feature['properties'] ?? [];
        $insert->execute([
            'shape_id' => (string) ($properties['shapeID'] ?? sprintf('%s-ADM%d-%d', $iso3, $level, $count)),
            'iso3' => $iso3,
            'level' => $level,
            'name' => (string) ($properties['shapeName'] ?? ''),
            'shape_iso' => ($properties['shapeISO'] ?? '') !== '' ? (string) $properties['shapeISO'] : null,
            'min_x' => $bbox[0], 'min_y' => $bbox[1], 'max_x' => $bbox[2], 'max_y' => $bbox[3],
            'geometry' => json_encode($geometry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        if ($insert->rowCount() > 0) {
            $rtree->execute([(int) $pdo->lastInsertId(), $bbox[0], $bbox[2], $bbox[1], $bbox[3]]);
            ++$count;
        }
    }

    return $count;
}

/**
 * Build <iso3>.sqlite: every level, with parent_id derived by containment. geoBoundaries
 * features carry no parent reference, so a unit's parent is the lower-level unit containing a
 * point inside it. A level can be missing (ADM2 without ADM1), so parents come from the nearest
 * lower level present. Units with no containing parent (coastal slivers, simplification gaps)
 * keep parent_id NULL rather than guessing.
 *
 * @param array<int, list<array<string, mixed>>> $layers GeoJSON features keyed by level
 *
 * @return array{units: int, orphans: int}
 */
function buildCountryDatabase(string $databasePath, string $iso3, array $layers): array
{
    $pdo = createSqliteConnection($databasePath);
    createBoundaryTables($pdo);
    ksort($layers);

    $pdo->beginTransaction();
    $units = 0;
    foreach ($layers as $level => $features) {
        $units += insertBoundaries($pdo, $iso3, $level, $features);
    }
    $pdo->commit();

    $orphans = assignParents($pdo, array_keys($layers));
    finalizeBoundaryIndexes($pdo);

    return ['units' => $units, 'orphans' => $orphans];
}

/**
 * @param list<int> $levels
 *
 * @return int children left without a parent
 */
function assignParents(\PDO $pdo, array $levels): int
{
    $orphans = 0;
    $set = $pdo->prepare('UPDATE boundary SET parent_id = ? WHERE id = ?');
    $pdo->beginTransaction();

    foreach ($levels as $index => $level) {
        if (0 === $index) {
            continue;
        }
        $parentLevel = $levels[$index - 1];

        $parents = [];
        foreach ($pdo->query(sprintf('SELECT id, min_x, min_y, max_x, max_y, geometry FROM boundary WHERE level = %d', $parentLevel)) as $row) {
            $parents[] = [(int) $row['id'], [(float) $row['min_x'], (float) $row['min_y'], (float) $row['max_x'], (float) $row['max_y']], (string) $row['geometry']];
        }
        $decoded = [];

        foreach ($pdo->query(sprintf('SELECT id, geometry FROM boundary WHERE level = %d', $level))->fetchAll(\PDO::FETCH_ASSOC) as $child) {
            $point = Geometry::representativePoint(json_decode((string) $child['geometry'], true));
            $parentId = null;
            if (null !== $point) {
                foreach ($parents as [$parentRowId, $bbox, $json]) {
                    if ($point[0] < $bbox[0] || $point[0] > $bbox[2] || $point[1] < $bbox[1] || $point[1] > $bbox[3]) {
                        continue;
                    }
                    $decoded[$parentRowId] ??= json_decode($json, true);
                    if (Geometry::contains($decoded[$parentRowId], $point[0], $point[1])) {
                        $parentId = $parentRowId;
                        break;
                    }
                }
            }
            if (null === $parentId) {
                ++$orphans;
                continue;
            }
            $set->execute([$parentId, (int) $child['id']]);
        }
    }
    $pdo->commit();

    return $orphans;
}

function createGeoDatabase(string $databasePath): \PDO
{
    $pdo = createSqliteConnection($databasePath);
    $pdo->exec(<<<'SQL'
CREATE TABLE country (
    iso3 TEXT PRIMARY KEY, name TEXT NOT NULL, continent TEXT NULL,
    unsdg_region TEXT NULL, unsdg_subregion TEXT NULL, income_group TEXT NULL,
    geoname_id INTEGER NULL
);
CREATE TABLE release (
    iso3 TEXT NOT NULL, level INTEGER NOT NULL, release_type TEXT NOT NULL,
    boundary_id TEXT NOT NULL, build_date TEXT NULL, source_update_date TEXT NULL,
    year_represented TEXT NULL, license TEXT NULL, license_detail TEXT NULL, license_source TEXT NULL,
    source TEXT NULL, unit_count INTEGER NULL,
    PRIMARY KEY (iso3, level, release_type)
);
SQL);
    createBoundaryTables($pdo);

    return $pdo;
}

/**
 * Record one country's provenance + country row in geo.sqlite, and copy its ADM0 geometry.
 *
 * @param list<array<string, mixed>> $entries      API metadata for the country, every level
 * @param list<array<string, mixed>> $adm0Features
 */
function addCountryToGeoDatabase(\PDO $pdo, string $iso3, string $releaseType, array $entries, array $adm0Features): void
{
    $first = $entries[0];
    $pdo->prepare('INSERT OR REPLACE INTO country (iso3, name, continent, unsdg_region, unsdg_subregion, income_group) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            $iso3,
            (string) ($first['boundaryName'] ?? $iso3),
            nullIfEmpty($first['Continent'] ?? null),
            nullIfEmpty($first['UNSDG-region'] ?? null),
            nullIfEmpty($first['UNSDG-subregion'] ?? null),
            nullIfEmpty($first['worldBankIncomeGroup'] ?? null),
        ]);

    $release = $pdo->prepare('INSERT OR REPLACE INTO release VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($entries as $entry) {
        $release->execute([
            $iso3,
            (int) substr((string) $entry['boundaryType'], 3),
            $releaseType,
            (string) $entry['boundaryID'],
            nullIfEmpty($entry['buildDate'] ?? null),
            nullIfEmpty($entry['sourceDataUpdateDate'] ?? null),
            nullIfEmpty($entry['boundaryYearRepresented'] ?? null),
            nullIfEmpty($entry['boundaryLicense'] ?? null),
            nullIfEmpty($entry['licenseDetail'] ?? null),
            nullIfEmpty($entry['licenseSource'] ?? null),
            nullIfEmpty($entry['boundarySource'] ?? null),
            isset($entry['admUnitCount']) && '' !== $entry['admUnitCount'] ? (int) $entry['admUnitCount'] : null,
        ]);
    }

    insertBoundaries($pdo, $iso3, 0, $adm0Features);
}

function nullIfEmpty(mixed $value): ?string
{
    $value = trim((string) $value);

    return '' === $value ? null : $value;
}

/** @return list<string> upper-cased, de-duplicated; accepts comma-separated values too */
function normalizeCodes(array $values): array
{
    $codes = [];
    foreach ($values as $value) {
        foreach (explode(',', (string) $value) as $code) {
            $code = strtoupper(trim($code));
            if ('' !== $code) {
                $codes[$code] = $code;
            }
        }
    }

    return array_values($codes);
}

/** Dataset card for the Hugging Face repo; carries the CC BY 4.0 attribution the license requires. */
function writeDatasetCard(string $outputDir, string $hfAccount): void
{
    $files = [];
    foreach (glob(rtrim($outputDir, '/').'/*.sqlite') ?: [] as $path) {
        $files[] = sprintf('- `%s` (%s bytes)', basename($path), number_format((int) filesize($path)));
    }

    file_put_contents(rtrim($outputDir, '/').'/README.md', sprintf(<<<'MD'
---
license: other
license_name: per-layer-see-release-table
pretty_name: geoBoundaries (SQLite)
tags: [geography, boundaries, administrative-divisions, sqlite]
---

# geoBoundaries (SQLite)

Administrative boundary polygons from [geoBoundaries](https://www.geoboundaries.org) (gbOpen
release), repackaged as SQLite for local lookup by `survos/geoboundaries-bundle`.

- `geo.sqlite`: `country`, per-layer `release` provenance, and every country's ADM0 `boundary`.
- `<iso3>.sqlite`: that country's `boundary` rows for every level (ADM0..ADM5), simplified
  GeoJSON geometry, bbox + `boundary_rtree`, and `parent_id` derived by containment.

## Attribution and licenses (required)

geoBoundaries distributes gbOpen under CC BY 4.0, but each layer keeps the license of its
primary source (some are ODbL, e.g. OpenStreetMap-derived layers, which also carries
share-alike terms). Check `release.license`, `release.license_source` and `release.source` for
the layers you use and credit them.

Runfola, D. et al. (2020) geoBoundaries: A global database of political administrative
boundaries. PLoS ONE 15(4): e0231866. https://doi.org/10.1371/journal.pone.0231866

Source: https://www.geoboundaries.org. Each layer's `build_date`, `year_represented` and
original `license` are in the `release` table.

## Files

%s
MD, implode("\n", $files)));
}
