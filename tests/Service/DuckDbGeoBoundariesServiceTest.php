<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use Survos\GeoBoundariesAdmin\DuckDbBuilder;

/** Builds the same fixtures as GeoBoundariesServiceTest into .duckdb files, from GeoJSON files, and reads them back. */
class DuckDbGeoBoundariesServiceTest extends GeoBoundariesServiceTest
{
    protected function buildFixtures(): void
    {
        if (!extension_loaded('pdo_duckdb')) {
            self::markTestSkipped('ext-pdo_duckdb is not loaded.');
        }

        $paths = [];
        foreach ($this->layers() as $level => $features) {
            $paths[$level] = $this->dir.'/layer'.$level.'.geojson';
            file_put_contents($paths[$level], json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_THROW_ON_ERROR));
        }

        $builder = new DuckDbBuilder();
        $builder->buildCountry($this->dir.'/xxx.duckdb', 'XXX', $paths);
        $builder->buildGeo($this->dir.'/world.duckdb', $this->dir, ['XXX' => ['entries' => $this->releaseEntries(), 'geoname_id' => null]], 'gbOpen');
    }

    #[Test]
    public function polygonsKeepTheirTypeAndRoundCoordinates(): void
    {
        $westville = $this->service->findByShapeId('XXX-W1', 'XXX', withGeometry: true);

        self::assertSame('Polygon', $westville?->geometry['type']);
        self::assertSame([[[1.0, 1.0], [3.0, 1.0], [3.0, 3.0], [1.0, 3.0], [1.0, 1.0]]], $westville->geometry['coordinates']);
        self::assertSame([1.0, 1.0, 3.0, 3.0], $westville->bbox);
    }

    #[Test]
    public function geoDatabaseCopiesAdm0AndReleaseRows(): void
    {
        $country = $this->service->countryAt(5.0, 2.0);

        self::assertSame('XXX', $country?->iso3);
        self::assertSame(0, $country->level);
    }
}
