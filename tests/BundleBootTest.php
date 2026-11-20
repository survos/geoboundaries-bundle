<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\GeoBoundariesBundle\Service\GeoBoundariesService;
use Symfony\Bundle\FrameworkBundle\Console\Application;

/**
 * Boots the real container through the bundle's extension and configuration. GeoBoundariesServiceTest builds
 * the service by hand, so it never exercises this: a missing config default (missing config defaults) once broke
 * every app's cache:clear while all of those tests passed.
 */
final class BundleBootTest extends TestCase
{
    #[Test]
    public function containerCompilesWithoutAnyConfiguration(): void
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();

        try {
            self::assertInstanceOf(GeoBoundariesService::class, $kernel->getContainer()->get(GeoBoundariesService::class));
            self::assertArrayHasKey('survos:geoboundaries', (new Application($kernel))->all());
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function dataDirOptionSetsTheDirectory(): void
    {
        self::assertSame('/tmp/data-dir-test', $this->service(['data_dir' => '/tmp/data-dir-test'])->dataDir());
    }

    /** @group legacy */
    #[Test]
    public function deprecatedSqliteDirOptionStillWorks(): void
    {
        $previous = set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);
        try {
            self::assertSame('/tmp/legacy-dir-test', $this->service(['sqlite_dir' => '/tmp/legacy-dir-test'])->dataDir());
        } finally {
            restore_error_handler();
        }
    }

    /** @param array<string, mixed> $config */
    private function service(array $config): GeoBoundariesService
    {
        $kernel = new TestKernel('test', true, $config);
        $kernel->boot();
        try {
            return $kernel->getContainer()->get(GeoBoundariesService::class);
        } finally {
            $kernel->shutdown();
        }
    }
}
