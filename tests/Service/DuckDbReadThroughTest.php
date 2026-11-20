<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use Survos\FetchBundle\Service\ChunkDownloader;
use Survos\GeoBoundariesBundle\Service\GeoBoundariesService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The DuckDB files are served by a mock "Hugging Face" and cached on first use, so every inherited
 * test case also runs through the read-through cache.
 */
final class DuckDbReadThroughTest extends DuckDbGeoBoundariesServiceTest
{
    private const URI = 'https://huggingface.test/datasets/museado/geoboundaries-duckdb/resolve/main';

    private string $remote;
    private string $cache;
    /** @var list<string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->remote = $this->dir.'/remote';
        $this->cache = $this->dir.'/cache';
        mkdir($this->remote);
        foreach (['geo', 'xxx'] as $name) {
            rename($this->dir.'/'.$name.'.duckdb', $this->remote.'/'.$name.'.duckdb');
        }

        $this->service = $this->newService();
    }

    protected function tearDown(): void
    {
        foreach ([$this->remote, $this->cache] as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($directory);
        }
        parent::tearDown();
    }

    #[Test]
    public function filesAreDownloadedOnceAndThenReadLocally(): void
    {
        $this->service->boundariesAt(2.0, 2.0, 'XXX');

        self::assertFileExists($this->cache.'/xxx.duckdb');
        $afterFirst = count($this->requests);
        self::assertGreaterThan(0, $afterFirst);

        self::assertCount(3, $this->newService()->boundariesAt(2.0, 2.0, 'XXX'));
        self::assertCount($afterFirst, $this->requests, 'The cached file is read without any HTTP request.');
    }

    #[Test]
    public function failedDownloadReportsAnErrorWhenThereIsNoSqliteFallback(): void
    {
        foreach (glob($this->remote.'/*') ?: [] as $file) {
            unlink($file);
        }

        $this->expectException(\RuntimeException::class);
        $this->newService()->boundariesAt(2.0, 2.0, 'XXX');
    }

    private function newService(): GeoBoundariesService
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $url;
            $file = $this->remote.'/'.basename($url);
            if (!is_file($file)) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return new MockResponse((string) file_get_contents($file), ['response_headers' => ['content-length: '.filesize($file)]]);
        });

        return new GeoBoundariesService($this->cache, $this->dir, self::URI, true, new ChunkDownloader($client));
    }
}
