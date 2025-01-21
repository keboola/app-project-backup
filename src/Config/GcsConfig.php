<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Config;

class GcsConfig
{
    private string $jsonKey;
    private string $bucket;
    private string $region;

    public function __construct(array $params, readonly bool $isUserDefinedCredentials)
    {
        $this->jsonKey = $params['#jsonKey'];
        $this->bucket = $params['#bucket'];
        $this->region = $params['region'];
    }

    public function getJsonKey(): string
    {
        return $this->jsonKey;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function isUserDefinedCredentials(): bool
    {
        return $this->isUserDefinedCredentials;
    }
}
