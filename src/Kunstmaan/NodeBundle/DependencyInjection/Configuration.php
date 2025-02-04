<?php

namespace Kunstmaan\NodeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('kunstmaan_node');
        $rootNode = $treeBuilder->getRootNode();

        /* @var ArrayNodeDefinition $pages */
        $rootNode
            ->children()
                ->arrayNode('pages')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('name')->isRequired()->end()
                            ->scalarNode('search_type')->end()
                            ->booleanNode('structure_node')->end()
                            ->booleanNode('indexable')->end()
                            ->scalarNode('icon')->defaultNull()->end()
                            ->scalarNode('hidden_from_tree')->end()
                            ->booleanNode('is_homepage')->end()
                            ->arrayNode('allowed_children')
                                ->prototype('array')
                                    ->beforeNormalization()
                                        ->ifString()->then(function ($v) {
                                            return ['class' => $v];
                                        })
                                    ->end()
                                    ->children()
                                        ->scalarNode('class')->isRequired()->end()
                                        ->scalarNode('name')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('enable_permissions')->defaultTrue()->end()
                ->scalarNode('publish_later_stepping')->defaultValue('15')->end()
                ->scalarNode('unpublish_later_stepping')->defaultValue('15')->end()
                ->booleanNode('show_add_homepage')->defaultTrue()->end()
                ->booleanNode('show_duplicate_with_children')->defaultFalse()->end()
                ->booleanNode('enable_export_page_template')->defaultFalse()->end()
                ->arrayNode('lock')
                    ->addDefaultsIfNotSet()
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('check_interval')->defaultValue(15)->end()
                        ->scalarNode('threshold')->defaultValue(35)->end()
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
