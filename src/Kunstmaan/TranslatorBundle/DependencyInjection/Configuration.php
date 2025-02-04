<?php

namespace Kunstmaan\TranslatorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('kuma_translator');
        $rootNode = $treeBuilder->getRootNode();

        $availableStorageEngines = ['orm'];
        $defaultFileFormats = ['yml', 'yaml', 'xliff'];

        $rootNode
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                ->end()

                ->scalarNode('default_bundle')
                    ->cannotBeEmpty()
                    ->defaultValue('own')
                ->end()

                ->arrayNode('bundles')
                    ->defaultValue([])
                    ->prototype('scalar')->end()
                ->end()

                ->scalarNode('cache_dir')
                    ->cannotBeEmpty()
                    ->defaultValue('%kernel.cache_dir%/translations')
                ->end()

                ->booleanNode('debug')
                    ->defaultValue(null)
                ->end()

                ->arrayNode('managed_locales')
                    ->defaultValue([])
                    ->prototype('scalar')->end()
                ->end()

                ->arrayNode('file_formats')
                    ->defaultValue($defaultFileFormats)
                    ->prototype('scalar')->end()
                ->end()

                ->arrayNode('storage_engine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('type')
                            ->cannotBeEmpty()
                            ->defaultValue('orm')
                            ->validate()
                                ->ifNotInArray($availableStorageEngines)
                                ->thenInvalid('Storage engine should be one of the following: ' . implode(', ', $availableStorageEngines))
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
