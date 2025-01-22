<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Tests;

use Generator;
use Keboola\App\ProjectBackup\Config\Config;
use Keboola\App\ProjectBackup\Config\ConfigDefinition;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigTest extends TestCase
{

    /** @dataProvider validConfigDataProvider */
    public function testValidConfig(
        array $configArray,
        array $expectedCredentialsParameters,
        string $expectedPath,
        bool $expectedIsUserDefinedCredentials,
        string $expectedStorageBackendType,
    ): void {
        $config = new Config($configArray, new ConfigDefinition());

        Assert::assertEquals($expectedCredentialsParameters, $config->getCredentialsParameters());
        Assert::assertEquals($expectedPath, $config->getPath());
        Assert::assertEquals($expectedIsUserDefinedCredentials, $config->isUserDefinedCredentials());
        Assert::assertEquals($expectedStorageBackendType, $config->getStorageBackendType());
    }

    /** @dataProvider invalidConfigDataProvider */
    public function testInvalidConfig(array $configArray, string $expectedExceptionMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);
        new Config($configArray, new ConfigDefinition());
    }

    public function validConfigDataProvider(): Generator
    {
        yield 'basic-config' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupId' => 123456,
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                ],
            ],
            [
                'storageBackendType' => Config::STORAGE_BACKEND_S3,
            ],
            '.',
            false,
            Config::STORAGE_BACKEND_S3,
        ];

        yield 's3-global-config' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupId' => 123456,
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => 'testAccessKeyId',
                    '#secret_access_key' => 'testAccessKey',
                    'region' => 'testRegion',
                    '#bucket' => 'testBucket',
                ],
            ],
            [
                'storageBackendType' => Config::STORAGE_BACKEND_S3,
                'access_key_id' => 'testAccessKeyId',
                '#secret_access_key' => 'testAccessKey',
                'region' => 'testRegion',
                '#bucket' => 'testBucket',
            ],
            '.',
            false,
            Config::STORAGE_BACKEND_S3,
        ];

        yield 'abs-global-config' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupId' => 123456,
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => 'testAccountName',
                    '#accountKey' => 'testAccountKey',
                    'region' => 'testRegion',
                ],
            ],
            [
                'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                'accountName' => 'testAccountName',
                '#accountKey' => 'testAccountKey',
                'region' => 'testRegion',
            ],
            '',
            false,
            Config::STORAGE_BACKEND_ABS,
        ];

        yield 'replace-global-config-s3' => [
            [
                'action' => 'run',
                'parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => 'testAccessKeyId',
                    '#secret_access_key' => 'testAccessKey',
                    'region' => 'testRegion',
                    '#bucket' => 'testBucket',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => 'testAccountName',
                    '#accountKey' => 'testAccountKey',
                    'region' => 'testRegion',
                ],
            ],
            [
                'storageBackendType' => Config::STORAGE_BACKEND_S3,
                'access_key_id' => 'testAccessKeyId',
                '#secret_access_key' => 'testAccessKey',
                'region' => 'testRegion',
                '#bucket' => 'testBucket',
            ],
            '.',
            true,
            Config::STORAGE_BACKEND_S3,
        ];

        yield 'replace-global-config-abs' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupPath' => 'testPath',
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => 'testAccountName',
                    '#accountKey' => 'testAccountKey',
                    'region' => 'testRegion',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => 'testAccessKeyId',
                    '#secret_access_key' => 'testAccessKey',
                    'region' => 'testRegion',
                    '#bucket' => 'testBucket',
                ],
            ],
            [
                'backupPath' => 'testPath',
                'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                'accountName' => 'testAccountName',
                '#accountKey' => 'testAccountKey',
                'region' => 'testRegion',
            ],
            'testPath',
            true,
            Config::STORAGE_BACKEND_ABS,
        ];

        yield 's3-add-missing-backslash-in-path' => [
            [
                'action' => 'run',
                'parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => 'testAccessKeyId',
                    '#secret_access_key' => 'testAccessKey',
                    'region' => 'testRegion',
                    'backupPath' => 'testPath',
                    '#bucket' => 'testBucket',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => 'testAccountName',
                    '#accountKey' => 'testAccountKey',
                    'region' => 'testRegion',
                ],
            ],
            [
                'storageBackendType' => Config::STORAGE_BACKEND_S3,
                'access_key_id' => 'testAccessKeyId',
                '#secret_access_key' => 'testAccessKey',
                'region' => 'testRegion',
                '#bucket' => 'testBucket',
                'backupPath' => 'testPath',

            ],
            'testPath/',
            true,
            Config::STORAGE_BACKEND_S3,
        ];

        yield 'abs-config-without-region' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupPath' => 'testPath',
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => 'testAccountName',
                    '#accountKey' => 'testAccountKey',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => 'testAccessKeyId',
                    '#secret_access_key' => 'testAccessKey',
                    '#bucket' => 'testBucket',
                ],
            ],
            [
                'backupPath' => 'testPath',
                'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                'accountName' => 'testAccountName',
                '#accountKey' => 'testAccountKey',
            ],
            'testPath',
            true,
            Config::STORAGE_BACKEND_ABS,
        ];
    }

    public function invalidConfigDataProvider(): Generator
    {
        yield 'abs-missing-path' => [
            [
                'action' => 'run',
                'parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => 'testAccountName',
                    '#accountKey' => 'testAccountKey',
                    'region' => 'testRegion',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                ],
            ],
            'Missing required parameter "backupPath".',
        ];

        yield 'abs-missing-accountName' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupId' => 123456,
                    'backupPath' => 'testBackupPath',
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    '#accountKey' => 'testAccountKey',
                    'region' => 'testRegion',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                ],
            ],
            'Missing required parameter "accountName".',
        ];

        yield 'abs-missing-accountKey' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupPath' => 'testBackupPath',
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => 'testAccountName',
                    'region' => 'testRegion',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                ],
            ],
            'Missing required parameter "#accountKey".',
        ];

        yield 's3-missing-AccessKeyId' => [
            [
                'action' => 'run',
                'parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    '#secret_access_key' => 'testAccessKey',
                    'region' => 'testRegion',
                    '#bucket' => 'testBucket',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                ],
            ],
            'Missing required parameter "access_key_id".',
        ];

        yield 's3-missing-secretAccessKey' => [
            [
                'action' => 'run',
                'parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => 'testAccessKeyId',
                    'region' => 'testRegion',
                    '#bucket' => 'testBucket',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                ],
            ],
            'Missing required parameter "#secret_access_key".',
        ];

        yield 's3-missing-region' => [
            [
                'action' => 'run',
                'parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => 'testAccessKeyId',
                    '#secret_access_key' => 'testAccessKey',
                    '#bucket' => 'testBucket',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                ],
            ],
            'Missing required parameter "region".',
        ];

        yield 's3-missing-bucket' => [
            [
                'action' => 'run',
                'parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => 'testAccessKeyId',
                    '#secret_access_key' => 'testAccessKey',
                    'region' => 'testRegion',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                ],
            ],
            'Missing required parameter "#bucket".',
        ];

        yield 'missing-backupId' => [
            [
                'action' => 'run',
                'parameters' => [],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => 'testAccessKeyId',
                    '#secret_access_key' => 'testAccessKey',
                    'region' => 'testRegion',
                    '#bucket' => 'testBucket',
                ],
            ],
            'The child node "backupId" at path "root.parameters" must be configured.',
        ];

        yield 'gcs-missing-backupId' => [
            [
                'action' => 'run',
                'parameters' => [
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#jsonKey' => 'testJsonKey',
                    '#bucket' => 'testBucket',
                    'region' => 'testRegion',
                ],
            ],
            'The child node "backupId" at path "root.parameters" must be configured.',
        ];

        yield 'gcs-missing-jsonKey' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupId' => 'testBackupId',
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#bucket' => 'testBucket',
                    'region' => 'testRegion',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                ],
            ],
            'Missing required parameter "#jsonKey".',
        ];

        yield 'gcs-missing-bucket' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupId' => 'testBackupId',
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#jsonKey' => 'testJsonKey',
                    'region' => 'testRegion',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                ],
            ],
            'Missing required parameter "#bucket".',
        ];

        yield 'gcs-missing-region' => [
            [
                'action' => 'run',
                'parameters' => [
                    'backupId' => 'testBackupId',
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#jsonKey' => 'testJsonKey',
                    '#bucket' => 'testBucket',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                ],
            ],
            'Missing required parameter "region".',
        ];
    }
}
