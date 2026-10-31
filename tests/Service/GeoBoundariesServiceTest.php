<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\GeoBoundariesBundle\Service\GeoBoundariesService;
use Survos\GeoBoundariesBundle\Service\Geometry;

use function Survos\GeoBoundariesAdmin\addCountryToGeoDatabase;
use function Survos\GeoBoundariesAdmin\buildCountryDatabase;
use function Survos\GeoBoundariesAdmin\createGeoDatabase;
use function Survos\GeoBoundariesAdmin\finalizeBoundaryIndexes;

final class GeoBoundariesServiceTest extends TestCase
{
    private string $dir;
    private GeoBoundariesService $service;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/geoboundaries-tests-'.uniqid('', true);
        mkdir($this->dir, 0777, true);

        // Country XXX is the square 0..10; ADM1 "West"/"East" split it at x=5; ADM2 "Hole" is a
        // small square inside West; East has a hole where "Lake" is NOT part of any unit.
        $adm0 = [$this->feature('XXX-0', 'Xland', $this->rect(0, 0, 10, 10))];
        $adm1 = [
            $this->feature('XXX-W', 'West', $this->rect(0, 0, 5, 10)),
            $this->feature('XXX-E', 'East', $this->rect(5, 0, 10, 10)),
        ];
        $adm2 = [$this->feature('XXX-W1', 'Westville', $this->rect(1, 1, 3, 3))];

        buildCountryDatabase($this->dir.'/xxx.sqlite', 'XXX', [0 => $adm0, 1 => $adm1, 2 => $adm2]);

        $geo = createGeoDatabase($this->dir.'/geo.sqlite');
        addCountryToGeoDatabase($geo, 'XXX', 'gbOpen', [[
            'boundaryID' => 'XXX-ADM0-1', 'boundaryType' => 'ADM0', 'boundaryISO' => 'XXX',
            'boundaryName' => 'Xland', 'Continent' => 'Nowhere', 'buildDate' => 'Dec 12, 2023',
            'admUnitCount' => '1',
        ]], $adm0);
        finalizeBoundaryIndexes($geo);
        unset($geo);

        $this->service = new GeoBoundariesService($this->dir, $this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function countriesComeFromGeoDatabase(): void
    {
        $countries = $this->service->countries();

        self::assertCount(1, $countries);
        self::assertSame('XXX', $countries[0]['iso3']);
        self::assertSame('Nowhere', $countries[0]['continent']);
    }

    #[Test]
    public function countryAtLocatesAdm0(): void
    {
        self::assertSame('Xland', $this->service->countryAt(5.0, 2.0)?->name);
        self::assertNull($this->service->countryAt(50.0, 50.0));
    }

    #[Test]
    public function boundariesAtReturnsMostSpecificFirst(): void
    {
        $hits = $this->service->boundariesAt(2.0, 2.0, 'XXX');

        self::assertSame(['Westville', 'West', 'Xland'], array_map(static fn ($b) => $b->name, $hits));
        self::assertSame([2, 1, 0], array_map(static fn ($b) => $b->level, $hits));
    }

    #[Test]
    public function parentsAreDerivedByContainment(): void
    {
        $westville = $this->service->find('westville', 'XXX')[0];
        $west = $this->service->find('West', 'XXX', 1)[0];
        $east = $this->service->find('East', 'XXX', 1)[0];
        $country = $this->service->find('Xland', 'XXX')[0];

        self::assertSame($west->id, $westville->parentId);
        self::assertSame($country->id, $west->parentId);
        self::assertSame($country->id, $east->parentId);
        self::assertNull($country->parentId);

        $children = $this->service->children($country->id, 'XXX');
        self::assertSame(['East', 'West'], array_map(static fn ($b) => $b->name, $children));
    }

    #[Test]
    public function geometryContainsHonoursHolesAndMultiPolygons(): void
    {
        $donut = ['type' => 'Polygon', 'coordinates' => [
            [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],
            [[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]],
        ]];
        self::assertTrue(Geometry::contains($donut, 1.0, 1.0));
        self::assertFalse(Geometry::contains($donut, 5.0, 5.0));

        $multi = ['type' => 'MultiPolygon', 'coordinates' => [
            [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]],
            [[[5, 5], [6, 5], [6, 6], [5, 6], [5, 5]]],
        ]];
        self::assertTrue(Geometry::contains($multi, 5.5, 5.5));
        self::assertFalse(Geometry::contains($multi, 3.0, 3.0));
        self::assertSame([0.0, 0.0, 6.0, 6.0], Geometry::bbox($multi));
    }

    #[Test]
    public function representativePointLiesInsideAConcaveShape(): void
    {
        // "C" shape: the centroid/bbox centre sits in the notch, outside the polygon.
        $c = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [10, 0], [10, 2], [2, 2], [2, 8], [10, 8], [10, 10], [0, 10], [0, 0]]]];
        $point = Geometry::representativePoint($c);

        self::assertNotNull($point);
        self::assertTrue(Geometry::contains($c, $point[0], $point[1]));
    }

    #[Test]
    public function missingDatabaseFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->boundariesAt(0.0, 0.0, 'ZZZ');
    }

    /** @return array<string, mixed> */
    private function rect(float $x0, float $y0, float $x1, float $y1): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$x0, $y0], [$x1, $y0], [$x1, $y1], [$x0, $y1], [$x0, $y0]]]];
    }

    /**
     * @param array<string, mixed> $geometry
     *
     * @return array<string, mixed>
     */
    private function feature(string $shapeId, string $name, array $geometry): array
    {
        return ['type' => 'Feature', 'properties' => ['shapeID' => $shapeId, 'shapeName' => $name, 'shapeISO' => ''], 'geometry' => $geometry];
    }
}
