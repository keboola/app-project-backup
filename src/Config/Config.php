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
        return $this->getStringValue(['parameters', 'backupId'], '');
    }

    public function getStorageBackendType(): string
    {
        $storageBackendType = $this->getStringValue(['parameters', 'storageBackendType'], '');
        if (!empty($storageBackendType)) {
            return $storageBackendType;
        }
        return $this->getImageParameters()['storageBackendType'];
    }

    public function exportStructureOnly(): bool
    {
        /** @var bool $val */
        $val = $this->getValue(['parameters', 'exportStructureOnly'], false);
        return $val;
    }

    public function includeVersions(): bool
    {
        /** @var bool $val */
        $val = $this->getValue(['parameters', 'includeVersions'], false);
        return $val;
    }

    public function isUserDefinedCredentials(): bool
    {
        $storageBackendType = $this->getValue(['parameters', 'storageBackendType'], '');
        return !empty($storageBackendType);
    }

    public function getCredentialsParameters(): array
    {

        return $this->isUserDefinedCredentials() ? $this->getParameters() : $this->getImageParameters();
    }

    public function getPath(): string
    {
        /** @var string $path */
        $path = $this->getValue(['parameters', 'backupPath'], '');
        if ($this->getStorageBackendType() === self::STORAGE_BACKEND_S3) {
            return $path === '' ? '.' : rtrim($path, '/') . '/';
        }
        return $path;
    }

    public function getS3Config(): S3Config
    {
        return new S3Config($this->getCredentialsParameters());
    }

    public function getAbsConfig(): AbsConfig
    {
        return new AbsConfig($this->getCredentialsParameters());
    }
}
