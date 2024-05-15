<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup;

use Keboola\App\ProjectBackup\Config\Config;
use Keboola\App\ProjectBackup\Config\ConfigDefinition;
use Keboola\Component\BaseComponent;

class Component extends BaseComponent
{
    public function run(): void
    {
        echo json_encode(['abcdefg' => 'hijklmn']);
        return;
        /** @var Config $config */
        $config = $this->getConfig();

        $application = new Application($config, $this->getLogger());

        switch ($config->getAction()) {
            case 'run':
                $application->run();
                break;
            case 'generate-read-credentials':
                echo json_encode($application->generateReadCredentials());
                break;
        }
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
