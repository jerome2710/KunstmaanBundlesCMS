<?php

namespace Kunstmaan\DashboardBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class WidgetCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('kunstmaan_dashboard.manager.widgets')) {
            return;
        }

        $definition = $container->getDefinition('kunstmaan_dashboard.manager.widgets');

        foreach ($container->findTaggedServiceIds('kunstmaan_dashboard.widget') as $id => $tags) {
            foreach ($tags as $tag) {
                if (!empty($tag['method'])) {
                    $widget = [new Reference($id), $tag['method']];
                } else {
                    $widget = new Reference($id);
                }
                $definition->addMethodCall('addWidget', [$widget]);
            }
        }
    }
}
