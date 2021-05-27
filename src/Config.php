<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup;

use Keboola\Component\Config\BaseConfig;

class Config extends BaseConfig
{
    public const STORAGE_BACKEND_S3 = 's3';

    public const STORAGE_BACKEND_ABS = 'abs';

    public function getBackupId(): string
    {
        return $this->getValue(['parameters', 'backupId']);
    }
}
