<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup;

use Keboola\App\ProjectBackup\Config\Config;
use Keboola\App\ProjectBackup\Config\ConfigDefinition;
use Keboola\Component\BaseComponent;

class Component extends BaseComponent
{
    protected function run(): void
    {
        /** @var Config $config */
        $config = $this->getConfig();

        $application = new Application($config, $this->getLogger());
        $application->run();
    }

    public function generateReadCredentialsAction(): array
    {
        /** @var Config $config */
        $config = $this->getConfig();
        $application = new Application($config, $this->getLogger());

        return $application->generateReadCredentials();
    }

    protected function getSyncActions(): array
    {
        return [
            'generate-read-credentials' => 'generateReadCredentialsAction',
        ];
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }
}
