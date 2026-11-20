<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesAdmin\Command;

use Survos\GeoBoundariesAdmin\AdminConfig;
use Survos\GeoBoundariesAdmin\DuckDbBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Bytes;

use function Survos\GeoBoundariesAdmin\localLayerPath;
use function Survos\GeoBoundariesAdmin\normalizeCodes;

#[AsCommand('build-duckdb', 'Build world.duckdb and one <iso3>.duckdb per country from the downloaded layers, with parents and the GeoNames crosswalk.')]
final class BuildDuckDbCommand
{
    public function __construct(
        private readonly AdminConfig $config,
        private readonly DuckDbBuilder $builder = new DuckDbBuilder(),
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Release type the layers were downloaded for')] string $release = 'gbOpen',
        #[Option('ISO3 country code(s) to build; ALL for every downloaded country')] array $country = ['ALL'],
        #[Option('Geometry to store: simplified, or full when the full-resolution file was downloaded')] string $geometry = 'simplified',
        #[Option('Directory holding geonames-bundle\'s geo.duckdb (or geo.sqlite) for the crosswalk')] ?string $geonamesDir = null,
        #[Option('Directory where DuckDB files should be written')] ?string $outputDir = null,
    ): int {
        $sourceDir = rtrim($this->config->sourceDir, '/');
        $outputDir = rtrim($outputDir ?? $this->config->duckdbOutputDir, '/');
        $indexPath = $sourceDir.'/index.json';
        if (!is_file($indexPath)) {
            $io->error(sprintf('%s not found; run `download` first.', $indexPath));

            return Command::FAILURE;
        }

        $geonames = $this->openGeonames(rtrim($geonamesDir ?? $this->config->geonamesDir, '/'), $io);

        $primary = 'full' === $geometry ? 'gjDownloadURL' : 'simplifiedGeometryGeoJSON';
        $fallback = 'full' === $geometry ? 'simplifiedGeometryGeoJSON' : 'gjDownloadURL';

        $byCountry = [];
        foreach (json_decode((string) file_get_contents($indexPath), true, flags: JSON_THROW_ON_ERROR) as $entry) {
            $byCountry[strtoupper((string) $entry['boundaryISO'])][] = $entry;
        }
        ksort($byCountry);
        $wanted = normalizeCodes($country);
        if (!in_array('ALL', $wanted, true)) {
            $byCountry = array_intersect_key($byCountry, array_flip($wanted));
        }

        @mkdir($outputDir, 0775, true);
        $io->title('Build geoBoundaries DuckDB databases');
        $io->text(sprintf('Source: %s · output: %s · geometry: %s', $sourceDir, $outputDir, $geometry));

        $built = [];
        foreach ($byCountry as $iso3 => $entries) {
            $layers = [];
            $usable = [];
            foreach ($entries as $entry) {
                foreach ([$primary, $fallback] as $field) {
                    $candidate = localLayerPath($sourceDir, $entry, $field);
                    if (null !== $candidate && is_file($candidate)) {
                        $layers[(int) substr((string) $entry['boundaryType'], 3)] = $candidate;
                        $usable[] = $entry;
                        break;
                    }
                }
            }
            if ([] === $layers) {
                continue; // not downloaded (e.g. above --max-level)
            }

            $target = $outputDir.'/'.strtolower($iso3).'.duckdb';
            $result = $this->builder->buildCountry($target, $iso3, $layers, $geonames);
            $built[$iso3] = ['entries' => $usable, 'geoname_id' => $result['crosswalk']['country'] ?? null];
            $io->writeln(sprintf('  %s: %d units, %d without parent, %s', $iso3, $result['units'], $result['orphans'], (string) Bytes::parse((int) filesize($target))));
        }

        $this->builder->buildGeo($outputDir.'/world.duckdb', $outputDir, $built, $release);
        file_put_contents($outputDir.'/README.md', $this->datasetCard($this->config->hfDuckdbRepo));

        $io->success(sprintf('Built world.duckdb and %d country databases in %s', count($built), $outputDir));

        return Command::SUCCESS;
    }

    private function openGeonames(string $dir, SymfonyStyle $io): ?\PDO
    {
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
        if (is_file($dir.'/geo.duckdb')) {
            return new \PDO('duckdb:'.$dir.'/geo.duckdb', null, null, $options + [\PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only']]);
        }
        if (is_file($dir.'/geo.sqlite')) {
            return new \PDO('sqlite:'.$dir.'/geo.sqlite', null, null, $options);
        }
        $io->warning(sprintf('No GeoNames geo.duckdb or geo.sqlite in %s: building without the crosswalk.', $dir));

        return null;
    }

    /** Carries the CC BY 4.0 attribution the geoBoundaries license requires. */
    private function datasetCard(string $repo): string
    {
        return <<<MD
---
license: other
license_name: per-layer-see-release-table
pretty_name: geoBoundaries (DuckDB)
tags: [geography, boundaries, administrative-divisions, duckdb]
---

# geoBoundaries (DuckDB)

Administrative boundary polygons from [geoBoundaries](https://www.geoboundaries.org) (gbOpen release),
as typed DuckDB files for `survos/geoboundaries-bundle`.

- `world.duckdb`: `country`, per-layer `release` provenance, and every country's ADM0 `boundary`. (Not `geo.duckdb`:
  that is Georgia's own file, because its ISO3 code is `GEO`.)
- `<iso3>.duckdb`: that country's `boundary` rows for every level (ADM0..ADM5), with `parent_id` derived
  by containment, links to GeoNames ids, a bbox, and `coordinates` as `DOUBLE[][][][]` (a list of polygons,
  rings, points and `[lon, lat]`, rounded to six decimals). `geometry_type` says whether the original shape
  was a `Polygon` or a `MultiPolygon`.

```sql
INSTALL httpfs; LOAD httpfs;
ATTACH 'https://huggingface.co/datasets/{$repo}/resolve/main/usa.duckdb' AS usa (READ_ONLY);
SELECT name, level FROM usa.boundary WHERE min_x <= -77.23 AND max_x >= -77.23 AND min_y <= 39.83 AND max_y >= 39.83;
```

## Attribution and licenses (required)

geoBoundaries distributes gbOpen under CC BY 4.0, but each layer keeps the license of its primary source
(some are ODbL, for example OpenStreetMap-derived layers, which also carries share-alike terms). Check
`release.license`, `release.license_source` and `release.source` for the layers you use and credit them.

Runfola, D. et al. (2020) geoBoundaries: A global database of political administrative boundaries.
PLoS ONE 15(4): e0231866. https://doi.org/10.1371/journal.pone.0231866

Source: https://www.geoboundaries.org.

MD;
    }
}
