<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Config;

class AbsConfig
{
    private string $accountName;

    private string $accountKey;

    private string $region;

    public function __construct(array $imageParameters)
    {
        $this->accountName = $imageParameters['accountName'];
        $this->accountKey = $imageParameters['#accountKey'];
        $this->region = $imageParameters['region'];
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
