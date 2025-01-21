<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Storages;

use Google\Cloud\Storage\StorageClient;
use Keboola\App\ProjectBackup\Config\GcsConfig;
use Keboola\ProjectBackup\Backup;
use Keboola\ProjectBackup\GcsBackup;
use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

class GoogleCloudStorage implements IStorage
{
    public function __construct(readonly GcsConfig $config, readonly LoggerInterface $logger)
    {
    }

    public function generateTempReadCredentials(string $backupId, string $path): array
    {
        // @TODO
        return [];
    }

    public function getBackup(Client $sapi, string $path): Backup
    {
        $storageClient = new StorageClient([
            'keyFile' => json_decode($this->config->getJsonKey(), true),
        ]);

        if (!str_ends_with($path, '/')) {
            $path .= '/';
        }

        return new GcsBackup(
            sapiClient: $sapi,
            storageClient: $storageClient,
            bucketName: $this->config->getBucket(),
            path: $path,
            generateSignedUrls: !$this->config->isUserDefinedCredentials(),
            logger: $this->logger,
        );
    }
}
