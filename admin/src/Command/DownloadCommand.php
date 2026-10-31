<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesAdmin\Command;

use Survos\GeoBoundariesAdmin\AdminConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;

use function Survos\GeoBoundariesAdmin\downloadToFile;
use function Survos\GeoBoundariesAdmin\fetchApiListing;
use function Survos\GeoBoundariesAdmin\localLayerPath;
use function Survos\GeoBoundariesAdmin\normalizeCodes;

#[AsCommand('download', 'Download geoBoundaries layers (API listing + per-layer GeoJSON) into the source directory.')]
final class DownloadCommand
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Release type: gbOpen (CC BY 4.0), gbHumanitarian or gbAuthoritative')] string $release = 'gbOpen',
        #[Option('ISO3 country code(s), comma-separated or repeated; ALL for every country')] array $country = ['ALL'],
        #[Option('Maximum ADM level to download (0-5)')] int $maxLevel = 2,
        #[Option('Also download the full-resolution GeoJSON, not just the simplified one')] bool $full = false,
        #[Option('Download again even when the local file already exists')] bool $force = false,
        #[Option('Pause between file downloads, in milliseconds (GitHub rate limits)')] int $delayMs = 250,
    ): int {
        $httpClient = HttpClient::create();
        $countries = normalizeCodes($country);
        $sourceDir = rtrim($this->config->sourceDir, '/');

        $io->title('geoBoundaries download');
        $io->text(sprintf('Release: %s · max level: ADM%d · source dir: %s', $release, $maxLevel, $sourceDir));

        // One listing per country (ALL/ALL for the world); the API answer is the build manifest
        // the build step reads back, so keep it verbatim as index.json.
        $entries = [];
        foreach (in_array('ALL', $countries, true) ? ['ALL'] : $countries as $iso3) {
            foreach (fetchApiListing($httpClient, $this->config->api, $release, $iso3, 'ALL') as $entry) {
                $level = (int) substr((string) ($entry['boundaryType'] ?? 'ADM9'), 3);
                if ($level <= $maxLevel) {
                    $entries[] = $entry;
                }
            }
        }
        $io->text(sprintf('%d layers listed.', count($entries)));

        @mkdir($sourceDir, 0775, true);
        $indexPath = $sourceDir.'/index.json';
        $merged = [];
        if (is_file($indexPath)) {
            foreach (json_decode((string) file_get_contents($indexPath), true) ?: [] as $existing) {
                $merged[$existing['boundaryID']] = $existing;
            }
        }
        foreach ($entries as $entry) {
            $merged[$entry['boundaryID']] = $entry;
        }
        file_put_contents($indexPath, json_encode(array_values($merged), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $fetched = $skipped = $failed = 0;
        foreach ($entries as $entry) {
            $fields = $full ? ['simplifiedGeometryGeoJSON', 'gjDownloadURL'] : ['simplifiedGeometryGeoJSON'];
            foreach ($fields as $field) {
                $target = localLayerPath($sourceDir, $entry, $field);
                if (null === $target) {
                    continue;
                }
                if (!$force && is_file($target)) {
                    ++$skipped;
                    continue;
                }
                try {
                    downloadToFile($httpClient, (string) $entry[$field], $target);
                    ++$fetched;
                    $io->writeln(sprintf('  <info>fetched</info> %s', substr($target, strlen($sourceDir) + 1)));
                    usleep($delayMs * 1000);
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->writeln(sprintf('  <error>failed</error> %s: %s', $entry['boundaryID'], $e->getMessage()));
                }
            }
        }

        $io->success(sprintf('fetched %d · skipped %d · failed %d', $fetched, $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
