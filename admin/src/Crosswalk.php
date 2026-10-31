<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesAdmin;

/**
 * Links geoBoundaries units to GeoNames ids using geonames-bundle's geo.sqlite.
 *
 * ADM0 links by ISO3. ADM1 matches by name against the country's GeoNames admin1 rows. ADM2
 * matches by name against admin2 rows, restricted to the already-linked ADM1 parent when there is
 * one (GeoNames admin2 names repeat across admin1s, so the parent is what makes them unique).
 * ADM3+ has no GeoNames counterpart and is left alone.
 *
 * Only unique matches are written. `geoname_match` records how each row was decided (iso3, name,
 * normalized, stripped) or why it wasn't (ambiguous); rows with no candidate stay NULL/NULL.
 * Re-running first clears every link, so the result never depends on an earlier run.
 */
final class Crosswalk
{
    public function __construct(
        private readonly \PDO $geonames,
    ) {
    }

    /**
     * @return array{country: ?int, levels: array<int, array{total: int, matched: int, ambiguous: int, none: int}>}
     */
    public function run(\PDO $db, string $iso3): array
    {
        $db->exec('UPDATE boundary SET geoname_id = NULL, geoname_match = NULL');

        $country = $this->geonames->prepare('SELECT geoname_id, iso2 FROM country WHERE iso3 = ?');
        $country->execute([strtoupper($iso3)]);
        $countryRow = $country->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($countryRow)) {
            return ['country' => null, 'levels' => []];
        }
        $countryId = (int) $countryRow['geoname_id'];
        $iso2 = (string) $countryRow['iso2'];

        $update = $db->prepare('UPDATE boundary SET geoname_id = ?, geoname_match = ? WHERE id = ?');
        $levels = [];
        $tally = static function (int $level, ?int $id, string $method) use (&$levels): void {
            $levels[$level] ??= ['total' => 0, 'matched' => 0, 'ambiguous' => 0, 'none' => 0];
            ++$levels[$level]['total'];
            if (null !== $id) {
                ++$levels[$level]['matched'];
            } elseif ('ambiguous' === $method) {
                ++$levels[$level]['ambiguous'];
            } else {
                ++$levels[$level]['none'];
            }
        };

        $admin1Rows = $this->rows('SELECT geoname_id AS id, admin1_code, name, ascii_name AS ascii FROM admin1 WHERE country_code = ?', [$iso2]);
        $admin1Index = new NameIndex($admin1Rows);
        $admin1Code = array_column($admin1Rows, 'admin1_code', 'id');
        /** @var array<string, NameIndex> $admin2Indexes keyed by admin1_code, '*' = whole country */
        $admin2Indexes = [];
        /** @var array<int, int> $linked boundary id => geoname id, for parent lookups */
        $linked = [];

        $units = $db->query('SELECT id, level, name, parent_id FROM boundary ORDER BY level, id')->fetchAll(\PDO::FETCH_ASSOC);

        $db->beginTransaction();
        foreach ($units as $unit) {
            $level = (int) $unit['level'];
            $id = null;
            $method = 'none';

            if (0 === $level) {
                [$id, $method] = [$countryId, 'iso3'];
            } elseif (1 === $level) {
                [$id, $method] = $admin1Index->match((string) $unit['name']);
            } elseif (2 === $level) {
                $parentGeonameId = $linked[(int) $unit['parent_id']] ?? null;
                $scope = null !== $parentGeonameId && isset($admin1Code[$parentGeonameId]) ? (string) $admin1Code[$parentGeonameId] : '*';
                $admin2Indexes[$scope] ??= new NameIndex('*' === $scope
                    ? $this->rows('SELECT geoname_id AS id, name, ascii_name AS ascii FROM admin2 WHERE country_code = ?', [$iso2])
                    : $this->rows('SELECT geoname_id AS id, name, ascii_name AS ascii FROM admin2 WHERE country_code = ? AND admin1_code = ?', [$iso2, $scope]));
                [$id, $method] = $admin2Indexes[$scope]->match((string) $unit['name']);
            } else {
                continue; // no GeoNames table for ADM3+
            }

            if (null !== $id) {
                $linked[(int) $unit['id']] = $id;
            }
            if (null !== $id || 'ambiguous' === $method) {
                $update->execute([$id, $method, (int) $unit['id']]);
            }
            $tally($level, $id, $method);
        }
        $db->commit();

        return ['country' => $countryId, 'levels' => $levels];
    }

    /**
     * @param list<string> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->geonames->prepare($sql);
        $statement->execute($params);

        return array_map(static fn (array $r): array => $r + ['ascii' => null], $statement->fetchAll(\PDO::FETCH_ASSOC));
    }
}
