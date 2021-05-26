<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Storages;

use Keboola\ProjectBackup\Backup;
use Keboola\StorageApi\Client;

interface IStorage
{
    public function generateTempReadCredentials(string $backupId, string $path): array;
    public function getBackup(Client $sapi, string $path): Backup;
}
