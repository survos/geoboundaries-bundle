<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\GeoBoundariesAdmin\DuckDbBuilder;
use Survos\GeoBoundariesBundle\Service\GeoBoundariesService;

/** Georgia's ISO3 code is GEO, so a country file and the global database must not share the name "geo". */
final class DuckDbWorldNameTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_duckdb')) {
            self::markTestSkipped('ext-pdo_duckdb is not loaded.');
        }
        $this->dir = sys_get_temp_dir().'/geoboundaries-world-'.uniqid('', true);
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
    public function aCountryCodedGeoKeepsItsOwnFileNextToTheWorldDatabase(): void
    {
        $this->buildGeorgia();
        $service = new GeoBoundariesService($this->dir, $this->dir);

        self::assertFileExists($this->dir.'/geo.duckdb');
        self::assertFileExists($this->dir.'/world.duckdb');
        self::assertSame(['Tbilisi', 'Georgia'], array_map(static fn ($b) => $b->name, $service->boundariesAt(5.0, 5.0, 'GEO')));
        self::assertSame('Georgia', $service->countryAt(5.0, 5.0)?->name);
        self::assertSame('GEO', $service->countries()[0]['iso3']);
    }

    #[Test]
    public function aWorldDatabaseNamedGeoFromAnOlderInstallStillWorks(): void
    {
        $this->buildGeorgia();
        // An install from before the rename: the global database is called geo and there is no world file.
        unlink($this->dir.'/geo.duckdb');
        rename($this->dir.'/world.duckdb', $this->dir.'/geo.duckdb');

        self::assertSame('Georgia', (new GeoBoundariesService($this->dir, $this->dir))->countries()[0]['name']);
    }

    private function buildGeorgia(): void
    {
        $feature = static fn (string $id, string $name, array $b): array => ['type' => 'Feature',
            'properties' => ['shapeID' => $id, 'shapeName' => $name],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$b[0], $b[1]], [$b[2], $b[1]], [$b[2], $b[3]], [$b[0], $b[3]], [$b[0], $b[1]]]]]];
        $layers = [0 => [$feature('G0', 'Georgia', [0, 0, 10, 10])], 1 => [$feature('G1', 'Tbilisi', [0, 0, 10, 10])]];
        $paths = [];
        foreach ($layers as $level => $features) {
            $paths[$level] = $this->dir.'/l'.$level.'.geojson';
            file_put_contents($paths[$level], json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_THROW_ON_ERROR));
        }

        $builder = new DuckDbBuilder();
        $builder->buildCountry($this->dir.'/geo.duckdb', 'GEO', $paths);
        $builder->buildGeo($this->dir.'/world.duckdb', $this->dir, ['GEO' => ['entries' => [[
            'boundaryID' => 'GEO-ADM0-1', 'boundaryType' => 'ADM0', 'boundaryISO' => 'GEO', 'boundaryName' => 'Georgia', 'admUnitCount' => '1',
        ]], 'geoname_id' => null]], 'gbOpen');
    }
}
