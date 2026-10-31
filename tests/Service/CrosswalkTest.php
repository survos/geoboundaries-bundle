<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\GeoBoundariesAdmin\Crosswalk;
use Survos\GeoBoundariesAdmin\NameIndex;
use Survos\GeoBoundariesBundle\Service\GeoBoundariesService;

use function Survos\GeoBoundariesAdmin\buildCountryDatabase;

final class CrosswalkTest extends TestCase
{
    private string $dir;
    private \PDO $geonames;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/geoboundaries-crosswalk-'.uniqid('', true);
        mkdir($this->dir, 0777, true);

        // YYY: ADM1 West / East / Twin / Lonely; ADM2 "Westville" inside West and "Eastville" inside East.
        $feature = static fn (string $id, string $name, array $b): array => ['type' => 'Feature',
            'properties' => ['shapeID' => $id, 'shapeName' => $name],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$b[0], $b[1]], [$b[2], $b[1]], [$b[2], $b[3]], [$b[0], $b[3]], [$b[0], $b[1]]]]]];
        buildCountryDatabase($this->dir.'/yyy.sqlite', 'YYY', [
            0 => [$feature('Y0', 'Yland', [0, 0, 20, 10])],
            1 => [
                $feature('Y-W', 'West', [0, 0, 5, 10]),
                $feature('Y-E', 'East', [5, 0, 10, 10]),
                $feature('Y-T', 'Twin', [10, 0, 15, 10]),
                $feature('Y-L', 'Lonely', [15, 0, 20, 10]),
            ],
            2 => [
                $feature('Y-W1', 'Cityville', [1, 1, 3, 3]),
                $feature('Y-E1', 'Cityville', [6, 1, 8, 3]),
            ],
            3 => [$feature('Y-W1a', 'Ward', [1.5, 1.5, 2.5, 2.5])],
        ]);

        $this->geonames = new \PDO('sqlite::memory:');
        $this->geonames->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->geonames->exec(<<<'SQL'
CREATE TABLE country (geoname_id INTEGER PRIMARY KEY, iso2 TEXT, iso3 TEXT);
CREATE TABLE admin1 (geoname_id INTEGER PRIMARY KEY, code TEXT, country_code TEXT, admin1_code TEXT, name TEXT, ascii_name TEXT);
CREATE TABLE admin2 (geoname_id INTEGER PRIMARY KEY, code TEXT, country_code TEXT, admin1_code TEXT, admin2_code TEXT, name TEXT, ascii_name TEXT);
INSERT INTO country VALUES (100, 'YY', 'YYY');
INSERT INTO admin1 VALUES (201, 'YY.01', 'YY', '01', 'West', 'West'),
                          (202, 'YY.02', 'YY', '02', 'East Province', 'East Province'),
                          (203, 'YY.03', 'YY', '03', 'Twin', 'Twin'),
                          (204, 'YY.04', 'YY', '04', 'Twin', 'Twin');
INSERT INTO admin2 VALUES (301, 'YY.01.A', 'YY', '01', 'A', 'Cityville', 'Cityville'),
                          (302, 'YY.02.A', 'YY', '02', 'A', 'Cityville', 'Cityville');
SQL);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function linksEachLevelAndRecordsHow(): void
    {
        $db = new \PDO('sqlite:'.$this->dir.'/yyy.sqlite');
        $result = (new Crosswalk($this->geonames))->run($db, 'YYY');

        self::assertSame(100, $result['country']);
        // ADM1: West (name), East (stripped from "East Province"), Twin ambiguous, Lonely none.
        self::assertSame(['total' => 4, 'matched' => 2, 'ambiguous' => 1, 'none' => 1], $result['levels'][1]);
        // ADM2: both "Cityville" link, because each is restricted to its own linked ADM1 parent.
        self::assertSame(['total' => 2, 'matched' => 2, 'ambiguous' => 0, 'none' => 0], $result['levels'][2]);
        self::assertArrayNotHasKey(3, $result['levels'], 'ADM3 has no GeoNames table and is skipped');

        $service = new GeoBoundariesService($this->dir, $this->dir);
        $by = static fn (array $found): array => [$found[0]->geonameId, $found[0]->geonameMatch];

        self::assertSame([100, 'iso3'], $by($service->find('Yland', 'YYY')));
        self::assertSame([201, 'name'], $by($service->find('West', 'YYY')));
        self::assertSame([202, 'stripped'], $by($service->find('East', 'YYY')));
        self::assertSame([null, 'ambiguous'], $by($service->find('Twin', 'YYY')));
        self::assertSame([null, null], $by($service->find('Lonely', 'YYY')));
        self::assertSame([null, null], $by($service->find('Ward', 'YYY')));

        $cityvilles = $service->find('Cityville', 'YYY');
        self::assertEqualsCanonicalizing([301, 302], array_map(static fn ($b) => $b->geonameId, $cityvilles));

        self::assertSame('West', $service->findByGeonameId(201, 'YYY')[0]->name);
    }

    #[Test]
    public function rerunClearsStaleLinks(): void
    {
        $db = new \PDO('sqlite:'.$this->dir.'/yyy.sqlite');
        $crosswalk = new Crosswalk($this->geonames);
        $crosswalk->run($db, 'YYY');

        $this->geonames->exec("UPDATE admin1 SET name = 'Renamed', ascii_name = 'Renamed' WHERE geoname_id = 201");
        $crosswalk->run($db, 'YYY');

        $service = new GeoBoundariesService($this->dir, $this->dir);
        self::assertNull($service->find('West', 'YYY')[0]->geonameId);
    }

    #[Test]
    public function unknownCountryLinksNothing(): void
    {
        $db = new \PDO('sqlite:'.$this->dir.'/yyy.sqlite');
        $this->geonames->exec('DELETE FROM country');

        self::assertSame(['country' => null, 'levels' => []], (new Crosswalk($this->geonames))->run($db, 'YYY'));
    }

    #[Test]
    public function nameIndexFoldsDiacriticsAndGenericWords(): void
    {
        $index = new NameIndex([
            ['id' => 1, 'name' => 'Zürich', 'ascii' => 'Zurich'],
            ['id' => 2, 'name' => 'Bern Canton', 'ascii' => null],
        ]);

        self::assertSame([1, 'name'], $index->match('zurich'));
        self::assertSame([1, 'normalized'], $index->match('ZÜRICH!'));
        self::assertSame([2, 'stripped'], $index->match('Canton of Bern'));
        self::assertSame([null, 'none'], $index->match('Geneva'));

        $us = new NameIndex([
            ['id' => 3, 'name' => 'Saint Clair', 'ascii' => null],
            ['id' => 4, 'name' => 'DeWitt', 'ascii' => null],
            ['id' => 5, 'name' => 'Dillingham Census Area', 'ascii' => null],
        ]);
        self::assertSame([3, 'normalized'], $us->match('St. Clair'));
        self::assertSame([4, 'squashed'], $us->match('De Witt'));
        self::assertSame([5, 'stripped'], $us->match('Dillingham'));
    }
}
