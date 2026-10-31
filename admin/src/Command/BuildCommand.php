<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesAdmin\Command;

use Survos\GeoBoundariesAdmin\AdminConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Survos\GeoBoundariesAdmin\addCountryToGeoDatabase;
use function Survos\GeoBoundariesAdmin\buildCountryDatabase;
use function Survos\GeoBoundariesAdmin\createGeoDatabase;
use function Survos\GeoBoundariesAdmin\finalizeBoundaryIndexes;
use function Survos\GeoBoundariesAdmin\localLayerPath;
use function Survos\GeoBoundariesAdmin\normalizeCodes;
use function Survos\GeoBoundariesAdmin\writeDatasetCard;

#[AsCommand('build', 'Build geo.sqlite and one <iso3>.sqlite per country from the downloaded layers.')]
final class BuildCommand
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Release type the layers were downloaded for')] string $release = 'gbOpen',
        #[Option('ISO3 country code(s) to build; ALL for every downloaded country')] array $country = ['ALL'],
        #[Option('Geometry to store: simplified, or full when the full-resolution file was downloaded')] string $geometry = 'simplified',
    ): int {
        $sourceDir = rtrim($this->config->sourceDir, '/');
        $outputDir = rtrim($this->config->outputDir, '/');
        $indexPath = $sourceDir.'/index.json';
        if (!is_file($indexPath)) {
            $io->error(sprintf('%s not found; run `download` first.', $indexPath));

            return Command::FAILURE;
        }

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
        $io->title('Build geoBoundaries SQLite databases');
        $io->text(sprintf('Source: %s · output: %s · geometry: %s', $sourceDir, $outputDir, $geometry));

        $geoDb = createGeoDatabase($outputDir.'/geo.sqlite');
        $geoDb->beginTransaction();

        $built = 0;
        foreach ($byCountry as $iso3 => $entries) {
            $layers = [];
            $usable = [];
            foreach ($entries as $entry) {
                $path = null;
                foreach ([$primary, $fallback] as $field) {
                    $candidate = localLayerPath($sourceDir, $entry, $field);
                    if (null !== $candidate && is_file($candidate)) {
                        $path = $candidate;
                        break;
                    }
                }
                if (null === $path) {
                    continue; // not downloaded (e.g. above --max-level)
                }
                $decoded = json_decode((string) file_get_contents($path), true);
                if (!is_array($decoded)) {
                    $io->warning(sprintf('%s: unreadable GeoJSON %s', $entry['boundaryID'], $path));
                    continue;
                }
                $layers[(int) substr((string) $entry['boundaryType'], 3)] = $decoded['features'] ?? [];
                $usable[] = $entry;
            }
            if ([] === $layers) {
                continue;
            }

            $result = buildCountryDatabase($outputDir.'/'.strtolower($iso3).'.sqlite', $iso3, $layers);
            addCountryToGeoDatabase($geoDb, $iso3, $release, $usable, $layers[0] ?? []);
            ++$built;
            $io->writeln(sprintf('  %s: %d units, %d without parent', $iso3, $result['units'], $result['orphans']));
        }

        $geoDb->commit();
        finalizeBoundaryIndexes($geoDb);
        writeDatasetCard($outputDir, $this->config->hfAccount);

        $io->success(sprintf('Built geo.sqlite and %d country databases in %s', $built, $outputDir));

        return Command::SUCCESS;
    }
}
