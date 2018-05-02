<?php

declare(strict_types=1);

namespace MyComponent;

use Aws\S3\S3Client;
use Keboola\Component\BaseComponent;
use Keboola\ProjectBackup\S3Backup;
use Keboola\StorageApi\Client AS StorageApi;
use Monolog\Logger;

class Component extends BaseComponent
{
    public function run(): void
    {
        $action = $this->getConfig()->getAction();

        if ($action === 'run') {
            $this->handleRun();
        }

        if ($action === 'generate-read-credentials') {
            $credentials = $this->handleCredentials();
            echo json_encode($credentials);
        }
    }

    private function initSapi(): StorageApi
    {
        return new StorageApi([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);
    }

    private function initS3(): S3Client
    {
        $imageParams = $this->getConfig()->getImageParameters();

        return new S3Client([
            'version' => 'latest',
            'region' => $imageParams['region'],
            'credentials' => [
                'key' => $imageParams['access_key_id'],
                'secret' => $imageParams['#secret_access_key'],
            ]
        ]);
    }

    public function handleCredentials(): array
    {
        $backupId = $this->initSapi()->generateId();

        return [
          'backupId' => $backupId,
        ];
    }

    private function generateBackupPath(StorageApi $client): string
    {
        $token = $client->verifyToken();

        $region = $token['owner']['region'];
        $projectId = $token['owner']['id'];
        $actionParams = $this->getConfig()->getParameters();

        return sprintf('/data-takeout/%s/%s/%s', $region, $projectId, $actionParams['backupId']);
    }

    public function handleRun(): void
    {
        $sapi = $this->initSapi();

        $imageParams = $this->getConfig()->getImageParameters();

        //@FIXME RUN MUSI VALIDOVAT env variables
        $logger = new Logger(getenv('KBC_COMPONENTID'));

        $backup = new S3Backup($sapi, $this->initS3(), $logger);

        $bucket = $imageParams['#bucket'];
        $path = $this->generateBackupPath($sapi);

        $tableIds = $backup->backupTablesMetadata($bucket, $path);
        $tablesCount = count($tableIds);

        foreach ($tableIds as $i => $tableId) {
            $logger->info(sprintf('Table %d/%d', $i + 1, $tablesCount));
            $backup->backupTable($tableId, $bucket, $path);
        }

        $backup->backupConfigs($bucket, $path, 2);
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
