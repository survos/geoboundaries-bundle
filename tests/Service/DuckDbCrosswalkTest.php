<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\GeoBoundariesAdmin\DuckDbBuilder;
use Survos\GeoBoundariesBundle\Service\GeoBoundariesService;

/** The DuckDB build runs the GeoNames crosswalk while it builds; the rules are the ones CrosswalkTest covers. */
final class DuckDbCrosswalkTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_duckdb')) {
            self::markTestSkipped('ext-pdo_duckdb is not loaded.');
        }
        $this->dir = sys_get_temp_dir().'/geoboundaries-duck-crosswalk-'.uniqid('', true);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function linksUnitsWhileBuilding(): void
    {
        $feature = static fn (string $id, string $name, array $b): array => ['type' => 'Feature',
            'properties' => ['shapeID' => $id, 'shapeName' => $name],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$b[0], $b[1]], [$b[2], $b[1]], [$b[2], $b[3]], [$b[0], $b[3]], [$b[0], $b[1]]]]]];
        $layers = [
            0 => [$feature('Y0', 'Yland', [0, 0, 20, 10])],
            1 => [
                $feature('Y-W', 'West', [0, 0, 5, 10]),
                $feature('Y-E', 'East', [5, 0, 10, 10]),
                $feature('Y-T', 'Twin', [10, 0, 15, 10]),
            ],
            2 => [$feature('Y-W1', 'Cityville', [1, 1, 3, 3]), $feature('Y-E1', 'Cityville', [6, 1, 8, 3])],
        ];
        $paths = [];
        foreach ($layers as $level => $features) {
            $paths[$level] = $this->dir.'/l'.$level.'.geojson';
            file_put_contents($paths[$level], json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_THROW_ON_ERROR));
        }

        $geonames = new \PDO('duckdb::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $geonames->exec(<<<'SQL'
CREATE TABLE country (geoname_id INTEGER, iso2 VARCHAR, iso3 VARCHAR);
CREATE TABLE admin1 (geoname_id INTEGER, code VARCHAR, country_code VARCHAR, admin1_code VARCHAR, name VARCHAR, ascii_name VARCHAR);
CREATE TABLE admin2 (geoname_id INTEGER, code VARCHAR, country_code VARCHAR, admin1_code VARCHAR, admin2_code VARCHAR, name VARCHAR, ascii_name VARCHAR);
INSERT INTO country VALUES (100, 'YY', 'YYY');
INSERT INTO admin1 VALUES (201, 'YY.01', 'YY', '01', 'West', 'West'), (202, 'YY.02', 'YY', '02', 'East Province', 'East Province'),
                          (203, 'YY.03', 'YY', '03', 'Twin', 'Twin'), (204, 'YY.04', 'YY', '04', 'Twin', 'Twin');
INSERT INTO admin2 VALUES (301, 'YY.01.A', 'YY', '01', 'A', 'Cityville', 'Cityville'), (302, 'YY.02.A', 'YY', '02', 'A', 'Cityville', 'Cityville');
SQL);

        $result = (new DuckDbBuilder())->buildCountry($this->dir.'/yyy.duckdb', 'YYY', $paths, $geonames);

        self::assertSame(100, $result['crosswalk']['country']);
        self::assertSame(['total' => 3, 'matched' => 2, 'ambiguous' => 1, 'none' => 0], $result['crosswalk']['levels'][1]);

        $service = new GeoBoundariesService($this->dir, $this->dir);
        $by = static fn (array $found): array => [$found[0]->geonameId, $found[0]->geonameMatch];
        self::assertSame([100, 'iso3'], $by($service->find('Yland', 'YYY')));
        self::assertSame([201, 'name'], $by($service->find('West', 'YYY')));
        self::assertSame([202, 'stripped'], $by($service->find('East', 'YYY')));
        self::assertSame([null, 'ambiguous'], $by($service->find('Twin', 'YYY')));
        self::assertEqualsCanonicalizing([301, 302], array_map(static fn ($b) => $b->geonameId, $service->find('Cityville', 'YYY')));
        self::assertSame('West', $service->findByGeonameId(201, 'YYY')[0]->name);
    }
}
