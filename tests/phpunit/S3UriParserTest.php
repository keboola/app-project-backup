<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Tests;

use Aws\S3\S3UriParser as BaseS3UriParser;
use Keboola\App\ProjectBackup\S3UriParser;
use PHPUnit\Framework\TestCase;

class S3UriParserTest extends TestCase
{
    private const TEST_S3_BUCKET = 'my-bucket';

    private const TEST_S3_PATH = 'my/project/backup';

    public function testUri(): void
    {
        $region = 'us-west-2';

        $uri = sprintf(
            'https://%s.s3.%s.amazonaws.com/%s',
            self::TEST_S3_BUCKET,
            $region,
            self::TEST_S3_PATH
        );

        $parts = (new BaseS3UriParser())->parse($uri);
        $this->assertEquals(self::TEST_S3_BUCKET, $parts['bucket']);
        $this->assertEquals(self::TEST_S3_PATH, $parts['key']);
        $this->assertEquals($region, $parts['region']);

        $parts = (new S3UriParser())->parse($uri);
        $this->assertEquals(self::TEST_S3_BUCKET, $parts['bucket']);
        $this->assertEquals(self::TEST_S3_PATH, $parts['key']);
        $this->assertEquals($region, $parts['region']);
    }

    public function testUsEastUri(): void
    {
        $uri = sprintf(
            'https://%s.s3.amazonaws.com/%s',
            self::TEST_S3_BUCKET,
            self::TEST_S3_PATH
        );

        $parts = (new BaseS3UriParser())->parse($uri);

        $this->assertEquals(self::TEST_S3_BUCKET, $parts['bucket']);
        $this->assertEquals(self::TEST_S3_PATH, $parts['key']);
        $this->assertEmpty($parts['region']);

        $parts = (new S3UriParser())->parse($uri);
        $this->assertEquals(self::TEST_S3_BUCKET, $parts['bucket']);
        $this->assertEquals(self::TEST_S3_PATH, $parts['key']);
        $this->assertEquals('us-east-1', $parts['region']);
    }
}
