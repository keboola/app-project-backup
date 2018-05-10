<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup;

use Aws\S3\S3UriParser as BaseS3UriParser;

class S3UriParser extends BaseS3UriParser
{
    /**
     * @param \Psr\Http\Message\UriInterface|string $uri
     * @return array
     */
    public function parse($uri): array
    {
        if (preg_match('/s3\.amazonaws\.com/ui', $uri)) {
            $uri = str_replace('s3.amazonaws.com', 's3.us-east-1.amazonaws.com', $uri);
        }

        return parent::parse($uri);
    }
}
