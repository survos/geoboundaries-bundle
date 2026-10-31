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
 * Fetches the published geoBoundaries authority SQLite databases (built + published by this
 * bundle's admin/ tooling, see PLAN.md) from the public `museado/geoboundaries-data` Hugging Face
 * dataset into GeoBoundariesService::sqliteDir() -- the same directory the service queries, so
 * fetching and lookup can't disagree on the path. Same fetch strategy as survos:geo.
 */
#[AsCommand(
    name: 'survos:geoboundaries',
    description: 'Fetch published geoBoundaries authority SQLite databases (geo.sqlite + per-country boundary dbs).',
)]
final class GeoBoundariesCommand
{
    private const REPO = 'museado/geoboundaries-data';
    private const BASE_URL = 'https://huggingface.co/datasets/'.self::REPO.'/resolve/main/';
    private const TREE_URL = 'https://huggingface.co/api/datasets/'.self::REPO.'/tree/main';

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
    ): int {
        $dir = $this->boundaries->sqliteDir();
        $io->title('geoBoundaries authority fetch');
        $io->text(sprintf('Target directory: %s', $dir));

        $files = ['geo.sqlite'];
        if ($all) {
            foreach ($this->listPublishedFiles() as $path) {
                if (str_ends_with($path, '.sqlite') && 'geo.sqlite' !== $path) {
                    $files[] = $path;
                }
            }
        } elseif (null !== $country && '' !== trim($country)) {
            foreach (explode(',', $country) as $code) {
                $code = strtolower(trim($code));
                if ('' !== $code) {
                    $files[] = $code.'.sqlite';
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
                $bytes = $this->chunkDownloader->download(self::BASE_URL.$file, $dest, null, ['overwrite' => $force]);
                ++$fetched;
                $io->writeln(sprintf('  <info>fetched</info> %s (%s)', $file, Bytes::parse($bytes)->humanize()));
            } catch (\Throwable $e) {
                ++$failed;
                $io->writeln(sprintf('  <error>failed</error> %s: %s', $file, $e->getMessage()));
            }
        }

        $io->success(sprintf('fetched %d · skipped %d · failed %d  →  %s', $fetched, $skipped, $failed, $dir));
        $io->note('geoBoundaries: credit Runfola et al. (2020), PLoS ONE 15(4): e0231866, and link www.geoboundaries.org. Layers keep their source license (see the release table in geo.sqlite; some are ODbL).');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<string> every file path published in the dataset repo */
    private function listPublishedFiles(): array
    {
        $result = $this->persistentFetcher->fetch(self::TREE_URL.'?recursive=1');
        if (!$result->isOkay()) {
            throw new \RuntimeException(sprintf('Failed to list %s: HTTP %d', self::REPO, $result->statusCode));
        }
        $rows = json_decode($result->contents ?? '', true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_filter(array_map(
            static fn (array $row): ?string => ($row['type'] ?? null) === 'file' ? (string) $row['path'] : null,
            $rows,
        )));
    }
}
