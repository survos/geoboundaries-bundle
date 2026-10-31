<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesAdmin\Command;

use Survos\GeoBoundariesAdmin\AdminConfig;
use Survos\GeoBoundariesAdmin\Crosswalk;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Survos\GeoBoundariesAdmin\normalizeCodes;

#[AsCommand('crosswalk', 'Link built boundaries to GeoNames ids using geonames-bundle\'s geo.sqlite.')]
final class CrosswalkCommand
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Directory holding geonames-bundle\'s geo.sqlite')] ?string $geonamesDir = null,
        #[Option('ISO3 country code(s) to link; ALL for every built country')] array $country = ['ALL'],
    ): int {
        $outputDir = rtrim($this->config->outputDir, '/');
        $geonamesPath = rtrim($geonamesDir ?? $this->config->geonamesDir, '/').'/geo.sqlite';
        if (!is_file($geonamesPath) || !is_file($outputDir.'/geo.sqlite')) {
            $io->error(sprintf('Need both %s (GeoNames) and %s/geo.sqlite (run `build` first).', $geonamesPath, $outputDir));

            return Command::FAILURE;
        }

        $geonames = new \PDO('sqlite:'.$geonamesPath);
        $geonames->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $geoDb = new \PDO('sqlite:'.$outputDir.'/geo.sqlite');
        $geoDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $crosswalk = new Crosswalk($geonames);

        $wanted = normalizeCodes($country);
        $io->title('GeoNames crosswalk');
        $rows = [];
        foreach (glob($outputDir.'/*.sqlite') ?: [] as $path) {
            $iso3 = strtoupper(basename($path, '.sqlite'));
            if ('GEO' === $iso3 || (!in_array('ALL', $wanted, true) && !in_array($iso3, $wanted, true))) {
                continue;
            }

            $db = new \PDO('sqlite:'.$path);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            if (!$this->hasMatchColumn($db)) {
                $io->warning(sprintf('%s predates the geoname_match column; rebuild it first.', basename($path)));
                continue;
            }
            $result = $crosswalk->run($db, $iso3);
            if (null === $result['country']) {
                $io->warning(sprintf('%s: no GeoNames country with ISO3 %s.', basename($path), $iso3));
                continue;
            }

            $geoDb->prepare('UPDATE country SET geoname_id = ? WHERE iso3 = ?')->execute([$result['country'], $iso3]);
            $geoDb->prepare("UPDATE boundary SET geoname_id = ?, geoname_match = 'iso3' WHERE iso3 = ? AND level = 0")->execute([$result['country'], $iso3]);

            foreach ($result['levels'] as $level => $stat) {
                if ($level > 0) {
                    $rows[] = [$iso3, 'ADM'.$level, $stat['total'], $stat['matched'], $stat['ambiguous'], $stat['none']];
                }
            }
        }

        $io->table(['Country', 'Level', 'Units', 'Linked', 'Ambiguous', 'No match'], $rows);
        $io->success('Crosswalk written to the country databases and geo.sqlite.');

        return Command::SUCCESS;
    }

    private function hasMatchColumn(\PDO $db): bool
    {
        foreach ($db->query('PRAGMA table_info(boundary)') as $column) {
            if ('geoname_match' === $column['name']) {
                return true;
            }
        }

        return false;
    }
}
