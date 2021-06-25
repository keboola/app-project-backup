<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Config;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

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
            ->validate()->always(function ($v) {
                if (!empty($v['storageBackendType'])) {
                    switch ($v['storageBackendType']) {
                        case Config::STORAGE_BACKEND_ABS:
                            foreach (['backupPath', 'accountName', '#accountKey', 'region'] as $item) {
                                if (empty($v[$item])) {
                                    throw new InvalidConfigurationException(sprintf(
                                        'Missing required parameter "%s".',
                                        $item
                                    ));
                                }
                            }
                            break;
                        case Config::STORAGE_BACKEND_S3:
                            $requiredItems = [
                                'access_key_id',
                                '#secret_access_key',
                                'access_key_id',
                                'region',
                                '#bucket',
                            ];
                            foreach ($requiredItems as $item) {
                                if (empty($v[$item])) {
                                    throw new InvalidConfigurationException(sprintf(
                                        'Missing required parameter "%s".',
                                        $item
                                    ));
                                }
                            }
                            break;
                        default:
                            throw new InvalidConfigurationException('Unknown storage backend type.');
                    }
                }
                return $v;
            })->end()
            ->children()
                ->scalarNode('backupId')->isRequired()->end()
                ->scalarNode('backupPath')->end()
                ->booleanNode('exportStructureOnly')->end()
                ->scalarNode('storageBackendType')->end()
                ->scalarNode('accountName')->end()
                ->scalarNode('#accountKey')->end()
                ->scalarNode('region')->end()
                ->scalarNode('access_key_id')->end()
                ->scalarNode('#secret_access_key')->end()
                ->scalarNode('#bucket')->end()
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
                ->scalarNode('accountName')->end()
                ->scalarNode('#accountKey')->end()
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
