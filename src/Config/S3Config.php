<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Config;

class S3Config
{
    private string $accessKeyId;

    private string $secretAccessKey;

    private string $region;

    private string $bucket;

    public function __construct(array $params)
    {
        $this->accessKeyId = $params['access_key_id'];
        $this->secretAccessKey = $params['#secret_access_key'];
        $this->region = $params['region'];
        $this->bucket = $params['#bucket'];
    }

    public function getAccessKeyId(): string
    {
        return $this->accessKeyId;
    }

    public function getSecretAccessKey(): string
    {
        return $this->secretAccessKey;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }
}
