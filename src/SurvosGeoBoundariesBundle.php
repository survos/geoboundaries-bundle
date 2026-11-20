<?php

declare(strict_types=1);

namespace Survos\GeoBoundariesBundle;

use Survos\GeoBoundariesBundle\Service\GeoBoundariesService;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosGeoBoundariesBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('sqlite_dir')
                    ->defaultNull()
                    ->info('Directory containing the geoBoundaries SQLite databases (default: APP_SHARED_DIRECTORY/geoboundaries-data, else var/geoboundaries-data).')
                ->end()
                ->scalarNode('duckdb_uri')
                    ->defaultNull()
                    ->info('https URL (such as a Hugging Face dataset\'s resolve/main/ path) holding <name>.duckdb files. Used when ext-pdo_duckdb is loaded and no local .duckdb file exists.')
                ->end()
                ->booleanNode('duckdb_cache')
                    ->defaultTrue()
                    ->info('Download an https duckdb_uri file once into sqlite_dir and read it locally. Set to false to read the remote file in place.')
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $container->services()
            ->set(GeoBoundariesService::class)
                ->autowire()
                ->autoconfigure()
                ->arg('$sqliteDir', $config['sqlite_dir'])
                ->arg('$projectDir', '%kernel.project_dir%')
                ->arg('$duckdbUri', $config['duckdb_uri'])
                ->arg('$duckdbCache', $config['duckdb_cache']);
    }
}
