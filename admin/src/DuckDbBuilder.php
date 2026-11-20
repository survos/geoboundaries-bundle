<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesAdmin;

use Survos\GeoBoundariesBundle\Service\Geometry;

/**
 * Builds the geoBoundaries DuckDB files straight from the downloaded GeoJSON layers: DuckDB reads
 * the JSON itself, and the work happens in an in-memory database that is written out once, sorted
 * and compact, at the end.
 *
 * Coordinates are stored as typed lists (DOUBLE[][][][], always MultiPolygon-shaped, with the
 * original type in `geometry_type`) and rounded to six decimals (about 0.1 m). The source
 * coordinates carry noise digits that defeat DuckDB's float compression, and rounding makes
 * them 6x smaller than the GeoJSON text (usa: 2.6 MB against 15.7 MB) while staying finer than
 * the simplification of the layers. Plain bbox columns locate a point, so the runtime needs no
 * spatial extension, and `Geometry` does the exact test, as it does when parents are derived.
 */
final class DuckDbBuilder
{
    public const DECIMALS = 6;

    private const BOUNDARY_COLUMNS = <<<'SQL'
    id INTEGER, shape_id VARCHAR, iso3 VARCHAR, level UTINYINT, name VARCHAR, shape_iso VARCHAR,
    parent_id INTEGER, geoname_id INTEGER, geoname_match VARCHAR,
    min_x DOUBLE, min_y DOUBLE, max_x DOUBLE, max_y DOUBLE,
    geometry_type VARCHAR, coordinates DOUBLE[][][][]
SQL;

    /**
     * Builds <iso3>.duckdb: every level, with parent_id derived by containment (see
     * buildCountryDatabase() for the rules, which this keeps) and, when $geonames is given, the
     * GeoNames crosswalk.
     *
     * @param array<int, string> $layers   GeoJSON file per level
     * @param \PDO|null          $geonames geonames-bundle's geo database (DuckDB or SQLite)
     *
     * @return array{units: int, orphans: int, crosswalk: ?array{country: ?int, levels: array<int, array{total: int, matched: int, ambiguous: int, none: int}>}}
     */
    public function buildCountry(string $duckdbPath, string $iso3, array $layers, ?\PDO $geonames = null): array
    {
        ksort($layers);
        $db = $this->memory();
        $db->exec('CREATE TABLE boundary ('.self::BOUNDARY_COLUMNS.')');

        $units = 0;
        foreach ($layers as $level => $path) {
            $units += $this->loadLayer($db, $iso3, (int) $level, $path);
        }

        $orphans = $this->assignParents($db, array_keys($layers));
        $crosswalk = null !== $geonames ? (new Crosswalk($geonames))->run($db, $iso3) : null;
        $this->write($db, $duckdbPath, 'SELECT * FROM boundary ORDER BY level, min_x', ['boundary']);

        return ['units' => $units, 'orphans' => $orphans, 'crosswalk' => $crosswalk];
    }

    /**
     * Builds world.duckdb (not geo.duckdb, which would collide with Georgia, ISO3 `GEO`): `country`, `release` (provenance per layer) and every built country's ADM0
     * `boundary`, copied from the finished <iso3>.duckdb files so that both agree.
     *
     * @param array<string, array{entries: list<array<string, mixed>>, geoname_id: ?int}> $countries by ISO3
     */
    public function buildGeo(string $duckdbPath, string $outputDir, array $countries, string $releaseType): void
    {
        $db = $this->memory();
        $db->exec('CREATE TABLE boundary ('.self::BOUNDARY_COLUMNS.')');
        $db->exec(<<<'SQL'
CREATE TABLE country (
    iso3 VARCHAR, name VARCHAR, continent VARCHAR, unsdg_region VARCHAR, unsdg_subregion VARCHAR,
    income_group VARCHAR, geoname_id INTEGER
)
SQL);
        $db->exec(<<<'SQL'
CREATE TABLE release (
    iso3 VARCHAR, level UTINYINT, release_type VARCHAR, boundary_id VARCHAR, build_date VARCHAR,
    source_update_date VARCHAR, year_represented VARCHAR, license VARCHAR, license_detail VARCHAR,
    license_source VARCHAR, source VARCHAR, unit_count INTEGER
)
SQL);

        $country = $db->prepare('INSERT INTO country VALUES (?, ?, ?, ?, ?, ?, ?)');
        $release = $db->prepare('INSERT INTO release VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($countries as $iso3 => $info) {
            $first = $info['entries'][0];
            $country->execute([
                $iso3,
                (string) ($first['boundaryName'] ?? $iso3),
                nullIfEmpty($first['Continent'] ?? null),
                nullIfEmpty($first['UNSDG-region'] ?? null),
                nullIfEmpty($first['UNSDG-subregion'] ?? null),
                nullIfEmpty($first['worldBankIncomeGroup'] ?? null),
                $info['geoname_id'],
            ]);
            foreach ($info['entries'] as $entry) {
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

            $file = rtrim($outputDir, '/').'/'.strtolower($iso3).'.duckdb';
            if (is_file($file)) {
                $db->exec('ATTACH '.$db->quote($file).' AS src (READ_ONLY)');
                // Ids are local to a file, and the ADM0 rows of all countries share this one.
                $db->exec(<<<'SQL'
INSERT INTO boundary
SELECT (SELECT coalesce(max(id), 0) FROM boundary) + row_number() OVER (), shape_id, iso3, level, name,
       shape_iso, NULL, geoname_id, geoname_match, min_x, min_y, max_x, max_y, geometry_type, coordinates
FROM src.boundary WHERE level = 0
SQL);
                $db->exec('DETACH src');
            }
        }

        $this->write($db, $duckdbPath, null, ['country', 'release', 'boundary']);
    }

    /**
     * Reads one GeoJSON layer with DuckDB's JSON reader. Features without geometry are skipped, and
     * so are repeated shape ids (the first one wins), as in the SQLite build.
     */
    private function loadLayer(\PDO $db, string $iso3, int $level, string $path): int
    {
        $offset = (int) $db->query('SELECT coalesce(max(id), 0) FROM boundary')->fetchColumn();
        $iso = $db->quote($iso3);
        $round = self::DECIMALS;
        $file = $db->quote($path);

        $db->exec(<<<SQL
INSERT INTO boundary
WITH features AS (
    -- format = 'unstructured' keeps the FeatureCollection as one value; the default would split its array into rows.
    SELECT unnest(json_extract(json, '\$.features[*]')::JSON[]) AS f
    FROM read_json_objects({$file}, format = 'unstructured', maximum_object_size = 1073741824)
), raw AS (
    SELECT f, row_number() OVER () AS n FROM features
), shaped AS (
    SELECT n,
           nullif(trim(json_extract_string(f, '\$.properties.shapeID')), '') AS shape_id,
           trim(coalesce(json_extract_string(f, '\$.properties.shapeName'), '')) AS name,
           nullif(trim(json_extract_string(f, '\$.properties.shapeISO')), '') AS shape_iso,
           json_extract_string(f, '\$.geometry.type') AS geometry_type,
           json_extract(f, '\$.geometry.coordinates') AS c
    FROM raw
    WHERE json_extract_string(f, '\$.geometry.type') IN ('Polygon', 'MultiPolygon')
), typed AS (
    SELECT n, shape_id, name, shape_iso, geometry_type,
           CASE geometry_type WHEN 'Polygon' THEN [c::DOUBLE[][][]] ELSE c::DOUBLE[][][][] END AS coords
    FROM shaped
), rounded AS (
    SELECT n, shape_id, name, shape_iso, geometry_type,
           list_transform(coords, lambda p: list_transform(p, lambda r: list_transform(r, lambda pt: list_transform(pt, lambda v: round(v, {$round}))))) AS coords
    FROM typed
), boxed AS (
    SELECT *, list_transform(flatten(flatten(coords)), lambda pt: pt[1]) AS xs,
              list_transform(flatten(flatten(coords)), lambda pt: pt[2]) AS ys
    FROM rounded
)
SELECT {$offset} + row_number() OVER (ORDER BY n), coalesce(shape_id, {$iso} || '-ADM{$level}-' || n), {$iso}, {$level}, name, shape_iso,
       NULL, NULL, NULL, list_min(xs), list_min(ys), list_max(xs), list_max(ys), geometry_type, coords
FROM boxed
WHERE len(xs) > 0
QUALIFY row_number() OVER (PARTITION BY coalesce(shape_id, {$iso} || '-ADM{$level}-' || n) ORDER BY n) = 1
SQL);

        return (int) $db->query(sprintf('SELECT count(*) FROM boundary WHERE level = %d', $level))->fetchColumn();
    }

    /**
     * A unit's parent is the unit one level up that contains a point inside it. A level can be
     * missing (ADM2 without ADM1), so parents come from the nearest lower level present. Units
     * with no containing parent keep NULL rather than a guess.
     *
     * @param list<int> $levels
     *
     * @return int children left without a parent
     */
    private function assignParents(\PDO $db, array $levels): int
    {
        $orphans = 0;
        $pairs = [];

        foreach ($levels as $index => $level) {
            if (0 === $index) {
                continue;
            }
            $parents = [];
            foreach ($db->query(sprintf('SELECT id, min_x, min_y, max_x, max_y, coordinates FROM boundary WHERE level = %d', $levels[$index - 1])) as $row) {
                $parents[] = [(int) $row['id'], [(float) $row['min_x'], (float) $row['min_y'], (float) $row['max_x'], (float) $row['max_y']], ['type' => 'MultiPolygon', 'coordinates' => $row['coordinates']]];
            }

            foreach ($db->query(sprintf('SELECT id, coordinates FROM boundary WHERE level = %d', $level))->fetchAll(\PDO::FETCH_ASSOC) as $child) {
                $point = Geometry::representativePoint(['type' => 'MultiPolygon', 'coordinates' => $child['coordinates']]);
                $parentId = null;
                if (null !== $point) {
                    foreach ($parents as [$parentRowId, $bbox, $geometry]) {
                        if ($point[0] < $bbox[0] || $point[0] > $bbox[2] || $point[1] < $bbox[1] || $point[1] > $bbox[3]) {
                            continue;
                        }
                        if (Geometry::contains($geometry, $point[0], $point[1])) {
                            $parentId = $parentRowId;
                            break;
                        }
                    }
                }
                if (null === $parentId) {
                    ++$orphans;
                    continue;
                }
                $pairs[] = [(int) $child['id'], $parentId];
            }
        }

        $db->exec('CREATE TEMP TABLE parent_map (id INTEGER, parent_id INTEGER)');
        foreach (array_chunk($pairs, 500) as $chunk) {
            $db->prepare('INSERT INTO parent_map VALUES '.implode(',', array_fill(0, count($chunk), '(?, ?)')))
                ->execute(array_merge(...$chunk));
        }
        $db->exec('UPDATE boundary SET parent_id = m.parent_id FROM parent_map m WHERE boundary.id = m.id');

        return $orphans;
    }

    private function memory(): \PDO
    {
        if (!extension_loaded('pdo_duckdb')) {
            throw new \RuntimeException('ext-pdo_duckdb is required to build DuckDB files.');
        }

        return new \PDO('duckdb::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * Copies the working tables into a new file (sorted and compact, without the update history),
     * then renames it into place.
     *
     * @param list<string> $tables
     */
    private function write(\PDO $db, string $duckdbPath, ?string $boundarySelect, array $tables): void
    {
        $tmp = $duckdbPath.'.tmp';
        @unlink($tmp);
        @unlink($tmp.'.wal');

        $db->exec('ATTACH '.$db->quote($tmp).' AS out');
        foreach ($tables as $table) {
            $select = 'boundary' === $table ? ($boundarySelect ?? 'SELECT * FROM boundary ORDER BY level, iso3, id') : 'SELECT * FROM '.$table;
            $db->exec(sprintf('CREATE TABLE out.%s AS %s', $table, $select));
        }
        $db->exec('CHECKPOINT out');
        $db->exec('DETACH out');

        @unlink($tmp.'.wal');
        rename($tmp, $duckdbPath);
    }
}
