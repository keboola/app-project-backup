<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup;

use Keboola\Component\Config\BaseConfig;

class Config extends BaseConfig
{
    public const STORAGE_BACKEND_AWS = 'aws';

    public const STORAGE_BACKEND_AZURE = 'azure';

    public function getBackupId(): string
    {
        return $this->getValue(['parameters', 'backupId']);
    }
}
