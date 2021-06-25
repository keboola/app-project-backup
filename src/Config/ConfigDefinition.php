<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Config;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class ConfigDefinition extends BaseConfigDefinition
{
    /**
     * Root definition to be overridden in special cases
     */
    protected function getRootDefinition(TreeBuilder $treeBuilder): ArrayNodeDefinition
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->root('root');
        $rootNode->ignoreExtraKeys(false);

        // @formatter:off
        $rootNode
            ->children()
            ->enumNode('action')
                ->values(['run', 'generate-read-credentials'])
                ->isRequired()
            ->end()
            ->append($this->getParametersDefinition())
            ->append($this->getImageParametersDefinition());
        // @formatter:on

        return $rootNode;
    }

    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $parametersNode = parent::getParametersDefinition();
        // @formatter:off
        /** @noinspection NullPointerExceptionInspection */
        $parametersNode
            ->children()
                ->scalarNode('backupId')
                    ->isRequired()
                ->end()
            ->end()
        ;
        // @formatter:on
        return $parametersNode;
    }

    /**
     * Definition of parameters section. Override in extending class to validate parameters sent to the component early.
     */
    protected function getImageParametersDefinition(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder();
        /** @var ArrayNodeDefinition $parametersNode */
        $parametersNode = $builder->root('image_parameters');
        $parametersNode->isRequired();
        $parametersNode->ignoreExtraKeys(false);

        // @formatter:off
        /** @noinspection NullPointerExceptionInspection */
        $parametersNode
            ->children()
                ->scalarNode('storageBackendType')->isRequired()->end()
                ->scalarNode('access_key_id')->end()
                ->scalarNode('#secret_access_key')->end()
                ->scalarNode('region')->end()
                ->scalarNode('#bucket')->end()
            ->end()
        ;
        // @formatter:on
        return $parametersNode;
    }
}