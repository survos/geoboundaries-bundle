<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Service;

use Survos\GeoBoundariesBundle\Dto\Boundary;

/**
 * Lookup over the packaged geoBoundaries SQLite databases:
 *
 *  - geo.sqlite      `country` (+ `release` provenance) and every country's ADM0 `boundary`
 *  - <iso3>.sqlite   that country's `boundary` rows for every level (ADM0..ADM5)
 *
 * Boundaries carry simplified GeoJSON geometry plus a bbox mirrored in `boundary_rtree`, so
 * locating a point is an R*Tree range query followed by an exact point-in-polygon check.
 */
final class GeoBoundariesService
{
    private const COLUMNS = 'id, shape_id, iso3, level, name, parent_id, geoname_id, geoname_match, min_x, min_y, max_x, max_y';

    /** @var array<string, \PDO> */
    private array $connections = [];

    public function __construct(
        private readonly ?string $sqliteDir,
        private readonly string $projectDir,
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
        $statement = $this->countryConnection($iso3)->prepare(
            'SELECT '.self::COLUMNS.($withGeometry ? ', geometry' : '').' FROM boundary WHERE shape_id = :shape_id'
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
        $statement = $pdo->prepare(
            'SELECT b.id, b.shape_id, b.iso3, b.level, b.name, b.parent_id, b.geoname_id, b.geoname_match, b.min_x, b.min_y, b.max_x, b.max_y, b.geometry
             FROM boundary_rtree r JOIN boundary b ON b.id = r.id
             WHERE r.min_x <= :lon AND r.max_x >= :lon AND r.min_y <= :lat AND r.max_y >= :lat
             ORDER BY b.level DESC, b.id ASC'
        );
        $statement->execute(['lon' => $lon, 'lat' => $lat]);

        $hits = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $geometry = json_decode((string) $row['geometry'], true);
            if (is_array($geometry) && Geometry::contains($geometry, $lon, $lat)) {
                if (!$withGeometry) {
                    unset($row['geometry']);
                }
                $hits[] = $this->hydrate($row);
            }
        }

        return $hits;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Boundary
    {
        $geometry = isset($row['geometry']) ? json_decode((string) $row['geometry'], true) : null;

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
            geometry: is_array($geometry) ? $geometry : null,
        );
    }

    private function geoConnection(): \PDO
    {
        return $this->connectionFor($this->sqliteDir().'/geo.sqlite');
    }

    private function countryConnection(string $iso3): \PDO
    {
        return $this->connectionFor($this->sqliteDir().'/'.strtolower($iso3).'.sqlite');
    }

    private function connectionFor(string $path): \PDO
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('geoBoundaries SQLite file not found: %s', $path));
        }

        if (isset($this->connections[$path])) {
            return $this->connections[$path];
        }

        try {
            $pdo = new \PDO('sqlite:'.$path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return $this->connections[$path] = $pdo;
        } catch (\PDOException $exception) {
            throw new \RuntimeException(sprintf('Unable to open SQLite database %s', $path), 0, $exception);
        }
    }
}
