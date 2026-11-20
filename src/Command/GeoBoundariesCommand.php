<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Command;

use Survos\FetchBundle\Contract\PersistentFetcherInterface;
use Survos\FetchBundle\Service\ChunkDownloader;
use Survos\GeoBoundariesBundle\Service\GeoBoundariesService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Bytes;

/**
 * Fetches the published geoBoundaries authority databases (built + published by this bundle's
 * admin/ tooling, see PLAN.md) from a public Hugging Face dataset (`museado/geoboundaries-duckdb`
 * for DuckDB, `museado/geoboundaries-data` for the legacy SQLite files) into GeoBoundariesService::sqliteDir() -- the same directory the service queries, so
 * fetching and lookup can't disagree on the path. Same fetch strategy as survos:geo.
 */
#[AsCommand(
    name: 'survos:geoboundaries',
    description: 'Fetch published geoBoundaries authority databases (geo + per-country boundary dbs), DuckDB by default.',
)]
final class GeoBoundariesCommand
{
    /** Hugging Face dataset repo per format; both are built by admin/publish.sh. */
    private const REPOS = [
        'duckdb' => 'museado/geoboundaries-duckdb',
        'sqlite' => 'museado/geoboundaries-data',
    ];

    public function __construct(
        private readonly ChunkDownloader $chunkDownloader,
        private readonly PersistentFetcherInterface $persistentFetcher,
        private readonly GeoBoundariesService $boundaries,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Comma-separated ISO3 country code(s) to fetch, e.g. usa or gbr,fra.')]
        ?string $country = null,
        #[Option('Fetch every published per-country database.')]
        bool $all = false,
        #[Option('Re-download even if the file already exists locally.')]
        bool $force = false,
        #[Option('File format: duckdb (default when ext-pdo_duckdb is loaded) or sqlite (legacy).')]
        ?string $format = null,
    ): int {
        $format ??= extension_loaded('pdo_duckdb') ? 'duckdb' : 'sqlite';
        if (!isset(self::REPOS[$format])) {
            $io->error(sprintf('Unknown format "%s": use duckdb or sqlite.', $format));

            return Command::FAILURE;
        }
        $extension = '.'.$format;
        $repo = self::REPOS[$format];

        $dir = $this->boundaries->sqliteDir();
        $io->title('geoBoundaries authority fetch');
        $io->text(sprintf('Target directory: %s (%s from %s)', $dir, $format, $repo));

        $files = ['geo'.$extension];
        if ($all) {
            foreach ($this->listPublishedFiles($repo) as $path) {
                if (str_ends_with($path, $extension) && 'geo'.$extension !== $path) {
                    $files[] = $path;
                }
            }
        } elseif (null !== $country && '' !== trim($country)) {
            foreach (explode(',', $country) as $code) {
                $code = strtolower(trim($code));
                if ('' !== $code) {
                    $files[] = $code.$extension;
                }
            }
        }
        $files = array_values(array_unique($files));

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $io->error(sprintf('Cannot create directory: %s', $dir));

            return Command::FAILURE;
        }

        $fetched = $skipped = $failed = 0;
        foreach ($files as $file) {
            $dest = $dir.'/'.$file;
            if (!$force && is_file($dest) && filesize($dest) > 0) {
                ++$skipped;
                continue;
            }

            try {
                $bytes = $this->chunkDownloader->download('https://huggingface.co/datasets/'.$repo.'/resolve/main/'.$file, $dest, null, ['overwrite' => $force]);
                ++$fetched;
                $io->writeln(sprintf('  <info>fetched</info> %s (%s)', $file, Bytes::parse($bytes)->humanize()));
            } catch (\Throwable $e) {
                ++$failed;
                $io->writeln(sprintf('  <error>failed</error> %s: %s', $file, $e->getMessage()));
            }
        }

        $io->success(sprintf('fetched %d · skipped %d · failed %d  →  %s', $fetched, $skipped, $failed, $dir));
        $io->note('geoBoundaries: credit Runfola et al. (2020), PLoS ONE 15(4): e0231866, and link www.geoboundaries.org. Layers keep their source license (see the release table in the geo database; some are ODbL).');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<string> every file path published in the dataset repo */
    private function listPublishedFiles(string $repo): array
    {
        $result = $this->persistentFetcher->fetch('https://huggingface.co/api/datasets/'.$repo.'/tree/main?recursive=1');
        if (!$result->isOkay()) {
            throw new \RuntimeException(sprintf('Failed to list %s: HTTP %d', $repo, $result->statusCode));
        }
        $rows = json_decode($result->contents ?? '', true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter(array_map(
            static fn (array $row): ?string => ($row['type'] ?? null) === 'file' ? (string) $row['path'] : null,
            $rows,
        )));
    }
}
