<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Service;

use Survos\GeoBoundariesBundle\Dto\Boundary;

/**
 * Lookup over the packaged geoBoundaries databases:
 *
 *  - geo       `country` (+ `release` provenance) and every country's ADM0 `boundary`
 *  - <iso3>    that country's `boundary` rows for every level (ADM0..ADM5)
 *
 * Each is read from <name>.duckdb when ext-pdo_duckdb is loaded and the file exists, else from
 * <name>.sqlite (the legacy format). Locating a point is a bbox range query (the SQLite R*Tree, or
 * plain bbox columns in DuckDB) followed by an exact point-in-polygon check. DuckDB stores the
 * coordinates as typed lists, rounded to six decimals; SQLite stores GeoJSON text.
 */
final class GeoBoundariesService
{
    private const COLUMNS = 'id, shape_id, iso3, level, name, parent_id, geoname_id, geoname_match, min_x, min_y, max_x, max_y';

    /** @var array<string, \PDO> */
    private array $connections = [];

    public function __construct(
        private readonly ?string $sqliteDir,
        private readonly string $projectDir,
        // Directory or https URL holding <name>.duckdb files (the Hugging Face dataset's resolve/main/ path).
        private readonly ?string $duckdbUri = null,
        // An https duckdbUri is downloaded once into sqliteDir() and read from there (a read-through
        // cache): a remote read costs several round trips per lookup, and every web request would
        // pay them again. Set to false to read the remote file in place.
        private readonly bool $duckdbCache = true,
        private readonly ?\Survos\FetchBundle\Service\ChunkDownloader $downloader = null,
    ) {
    }

    /**
     * @return list<array{iso3: string, name: string, continent: ?string, unsdg_region: ?string, unsdg_subregion: ?string, income_group: ?string, geoname_id: ?int}>
     */
    public function countries(): array
    {
        $statement = $this->geoConnection()->query(
            'SELECT iso3, name, continent, unsdg_region, unsdg_subregion, income_group, geoname_id FROM country ORDER BY name ASC'
        );

        return $statement ? $statement->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /** The ADM0 boundary containing the point, or null over open water / unmapped land. */
    public function countryAt(float $lat, float $lon): ?Boundary
    {
        return $this->locate($this->geoConnection(), $lat, $lon)[0] ?? null;
    }

    /**
     * Every boundary containing the point within one country, most specific level first
     * (e.g. ADM2, ADM1, ADM0).
     *
     * @return list<Boundary>
     */
    public function boundariesAt(float $lat, float $lon, string $iso3, bool $withGeometry = false): array
    {
        return $this->locate($this->countryConnection($iso3), $lat, $lon, $withGeometry);
    }

    /**
     * Exact (case-insensitive) name match within a country, optionally narrowed to one level.
     *
     * @return list<Boundary>
     */
    public function find(string $name, string $iso3, ?int $level = null): array
    {
        $sql = 'SELECT '.self::COLUMNS.' FROM boundary WHERE lower(name) = :name';
        $params = ['name' => mb_strtolower(trim($name))];
        if (null !== $level) {
            $sql .= ' AND level = :level';
            $params['level'] = $level;
        }
        $statement = $this->countryConnection($iso3)->prepare($sql.' ORDER BY level ASC, id ASC');
        $statement->execute($params);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<Boundary> */
    public function children(int $parentId, string $iso3): array
    {
        $statement = $this->countryConnection($iso3)->prepare(
            'SELECT '.self::COLUMNS.' FROM boundary WHERE parent_id = :parent_id ORDER BY name ASC'
        );
        $statement->execute(['parent_id' => $parentId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findByShapeId(string $shapeId, string $iso3, bool $withGeometry = false): ?Boundary
    {
        $pdo = $this->countryConnection($iso3);
        $geometryColumns = $this->isDuckDb($pdo) ? ', geometry_type, coordinates' : ', geometry';
        $statement = $pdo->prepare(
            'SELECT '.self::COLUMNS.($withGeometry ? $geometryColumns : '').' FROM boundary WHERE shape_id = :shape_id'
        );
        $statement->execute(['shape_id' => $shapeId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Boundaries linked to a GeoNames id (admin0/1/2 ids from geonames-bundle), within a country.
     *
     * @return list<Boundary>
     */
    public function findByGeonameId(int $geonameId, string $iso3): array
    {
        $statement = $this->countryConnection($iso3)->prepare(
            'SELECT '.self::COLUMNS.' FROM boundary WHERE geoname_id = :geoname_id ORDER BY level ASC'
        );
        $statement->execute(['geoname_id' => $geonameId]);

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function sqliteDir(): string
    {
        if (null !== $this->sqliteDir && '' !== trim($this->sqliteDir)) {
            return rtrim($this->sqliteDir, '/');
        }

        $sharedDirectory = $_SERVER['APP_SHARED_DIRECTORY'] ?? $_ENV['APP_SHARED_DIRECTORY'] ?? null;
        if (is_string($sharedDirectory) && '' !== trim($sharedDirectory)) {
            return rtrim($sharedDirectory, '/').'/geoboundaries-data';
        }

        return $this->projectDir.'/var/geoboundaries-data';
    }

    /** @return list<Boundary> */
    private function locate(\PDO $pdo, float $lat, float $lon, bool $withGeometry = true): array
    {
        $statement = $this->isDuckDb($pdo)
            ? $pdo->prepare(
                'SELECT '.self::COLUMNS.', geometry_type, coordinates FROM boundary
                 WHERE min_x <= :lon AND max_x >= :lon AND min_y <= :lat AND max_y >= :lat
                 ORDER BY level DESC, id ASC'
            )
            : $pdo->prepare(
                'SELECT b.id, b.shape_id, b.iso3, b.level, b.name, b.parent_id, b.geoname_id, b.geoname_match, b.min_x, b.min_y, b.max_x, b.max_y, b.geometry
                 FROM boundary_rtree r JOIN boundary b ON b.id = r.id
                 WHERE r.min_x <= :lon AND r.max_x >= :lon AND r.min_y <= :lat AND r.max_y >= :lat
                 ORDER BY b.level DESC, b.id ASC'
            );
        $statement->execute(['lon' => $lon, 'lat' => $lat]);

        $hits = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $geometry = $this->geometryOf($row);
            if (null !== $geometry && Geometry::contains($geometry, $lon, $lat)) {
                if (!$withGeometry) {
                    unset($row['geometry'], $row['coordinates']);
                }
                $hits[] = $this->hydrate($row);
            }
        }

        return $hits;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Boundary
    {
        $geometry = $this->geometryOf($row);

        return new Boundary(
            id: (int) $row['id'],
            shapeId: (string) $row['shape_id'],
            iso3: (string) $row['iso3'],
            level: (int) $row['level'],
            name: (string) $row['name'],
            parentId: null !== $row['parent_id'] ? (int) $row['parent_id'] : null,
            geonameId: null !== $row['geoname_id'] ? (int) $row['geoname_id'] : null,
            geonameMatch: $row['geoname_match'],
            bbox: [(float) $row['min_x'], (float) $row['min_y'], (float) $row['max_x'], (float) $row['max_y']],
            geometry: $geometry,
        );
    }

    /**
     * GeoJSON geometry of a row, from either backend.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function geometryOf(array $row): ?array
    {
        if (isset($row['coordinates']) && is_array($row['coordinates'])) {
            // DuckDB keeps every shape as a list of polygons; give a Polygon back its own shape.
            return 'Polygon' === ($row['geometry_type'] ?? null)
                ? ['type' => 'Polygon', 'coordinates' => $row['coordinates'][0]]
                : ['type' => 'MultiPolygon', 'coordinates' => $row['coordinates']];
        }
        if (isset($row['geometry'])) {
            $geometry = json_decode((string) $row['geometry'], true);

            return is_array($geometry) ? $geometry : null;
        }

        return null;
    }

    private function isDuckDb(\PDO $pdo): bool
    {
        return 'duckdb' === $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    private function geoConnection(): \PDO
    {
        return $this->connectionFor('geo');
    }

    private function countryConnection(string $iso3): \PDO
    {
        return $this->connectionFor(strtolower($iso3));
    }

    /** A local .duckdb file when ext-pdo_duckdb is loaded, else the legacy .sqlite file. */
    private function connectionFor(string $name): \PDO
    {
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $duckdb = $this->sqliteDir().'/'.$name.'.duckdb';
        $sqlite = $this->sqliteDir().'/'.$name.'.sqlite';
        $isDuck = extension_loaded('pdo_duckdb');
        $remote = null !== $this->duckdbUri && '' !== $this->duckdbUri ? rtrim($this->duckdbUri, '/').'/'.$name.'.duckdb' : null;
        $isHttp = null !== $remote && 1 === preg_match('#^https?://#', $remote);
        $downloadError = null;

        try {
            if ($isDuck && $isHttp && !is_file($duckdb) && $this->duckdbCache && null !== $this->downloader) {
                try {
                    $this->cacheRemote($remote, $duckdb);
                } catch (\Throwable $exception) {
                    $downloadError = $exception;
                }
            }
            if ($isDuck && is_file($duckdb)) {
                return $this->connections[$name] = new \PDO('duckdb:'.$duckdb, null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only'],
                ]);
            }
            if ($isDuck && $isHttp && null === $downloadError) {
                $pdo = new \PDO('duckdb::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('LOAD httpfs');
                $pdo->exec('ATTACH '.$pdo->quote($remote).' AS source (READ_ONLY)');
                $pdo->exec('USE source');

                return $this->connections[$name] = $pdo;
            }
            if (!is_file($sqlite)) {
                throw new \RuntimeException(sprintf('geoBoundaries database not found: %s', $sqlite), 0, $downloadError);
            }

            $pdo = new \PDO('sqlite:'.$sqlite);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return $this->connections[$name] = $pdo;
        } catch (\PDOException $exception) {
            throw new \RuntimeException(sprintf('Unable to open geoBoundaries database "%s"', $name), 0, $exception);
        }
    }

    /** Downloads under a file lock, so that concurrent workers do not write the same .part file. */
    private function cacheRemote(string $url, string $target): void
    {
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory: %s', $directory));
        }

        $lock = fopen($target.'.lock', 'cb');
        if (false === $lock) {
            throw new \RuntimeException(sprintf('Cannot open lock file for %s', $target));
        }
        try {
            flock($lock, LOCK_EX);
            // Another worker may have finished the download while this one waited for the lock.
            if (!is_file($target)) {
                $this->downloader->download($url, $target);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
