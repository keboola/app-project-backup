<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Config;

use Keboola\Component\Config\BaseConfig;

class Config extends BaseConfig
{
    public const STORAGE_BACKEND_S3 = 's3';

    public const STORAGE_BACKEND_ABS = 'abs';

    public function getBackupId(): string
    {
        return $this->getValue(['parameters', 'backupId'], '');
    }

    public function getStorageBackendType(): string
    {
        return $this->getImageParameters()['storageBackendType'];
    }

    public function getS3Config(): S3Config
    {
        return new S3Config($this->getImageParameters());
    }

    public function getAbsConfig(): AbsConfig
    {
        return new AbsConfig($this->getImageParameters());
    }
}
