<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle\Tests;

use Survos\FetchBundle\SurvosFetchBundle;
use Survos\GeoBoundariesBundle\Service\GeoBoundariesService;
use Survos\GeoBoundariesBundle\SurvosGeoBoundariesBundle;
use Survos\JsonlBundle\SurvosJsonlBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/** An app that configures nothing: the bundle must compile with only its own defaults. */
final class TestKernel extends Kernel
{
    /** @param array<string, mixed> $bundleConfig */
    public function __construct(string $environment, bool $debug, private readonly array $bundleConfig = [])
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): array
    {
        return [new FrameworkBundle(), new SurvosKitBundle(), new SurvosFetchBundle(), new SurvosJsonlBundle(), new SurvosGeoBoundariesBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', ['secret' => 'test', 'test' => true, 'http_client' => []]);
            if ([] !== $this->bundleConfig) {
                $container->loadFromExtension('survos_geo_boundaries', $this->bundleConfig);
            }
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        // GeoBoundariesService is only used by a command, so the container would otherwise inline and drop it.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(GeoBoundariesService::class)->setPublic(true);
            }
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/geoboundaries-bundle-tests/cache/'.spl_object_hash($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/geoboundaries-bundle-tests/log';
    }
}
