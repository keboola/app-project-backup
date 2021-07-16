<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Config;

class AbsConfig
{
    private string $accountName;

    private string $accountKey;

    private string $region;

    public function __construct(array $params)
    {
        $this->accountName = $params['accountName'];
        $this->accountKey = $params['#accountKey'];
        $this->region = $params['region'] ?? '';
    }

    public function getAccountName(): string
    {
        return $this->accountName;
    }

    public function getAccountKey(): string
    {
        return $this->accountKey;
    }

    public function getRegion(): string
    {
        return $this->region;
    }
}
