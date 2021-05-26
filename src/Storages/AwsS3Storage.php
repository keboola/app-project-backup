<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Storages;

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use Keboola\App\ProjectBackup\Config;
use Keboola\Component\UserException;
use Keboola\ProjectBackup\Backup;
use Keboola\ProjectBackup\S3Backup;
use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

class AwsS3Storage implements IStorage
{
    private Config $config;

    private array $imageParameters;

    private LoggerInterface $logger;

    public const FEDERATION_TOKEN_EXPIRATION_HOURS = 36;

    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->imageParameters = $config->getImageParameters();
    }

    public function generateTempReadCredentials(string $backupId, string $path): array
    {
        $federationToken = $this->generateFederationToken($path);

        $result = $this->initS3()->putObject([
            'Bucket' => $this->imageParameters['#bucket'],
            'Key' => $path,
        ]);

        return [
            'backupId' => $backupId,
            'backupUri' => $result['ObjectURL'],
            'region' => $this->imageParameters['region'],
            'credentials' => [
                'accessKeyId' => $federationToken['Credentials']['AccessKeyId'],
                'secretAccessKey' => $federationToken['Credentials']['SecretAccessKey'],
                'sessionToken' => $federationToken['Credentials']['SessionToken'],
                'expiration' => $federationToken['Credentials']['Expiration'],
            ],
        ];
    }

    public function getBackup(Client $sapi, string $path): Backup
    {
        $s3Client = $this->initS3();
        // check if backup folder was initialized
        try {
            $s3Client->getObject([
                'Bucket' => $this->imageParameters['#bucket'],
                'Key' => $path,
            ]);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey') {
                throw new UserException(
                    sprintf(
                        'Backup with ID "%s" was not initialized for this KBC project',
                        $this->config->getBackupId()
                    )
                );
            } else {
                throw $e;
            }
        }

        return new S3Backup(
            $sapi,
            $s3Client,
            $this->imageParameters['#bucket'],
            $path,
            $this->logger
        );
    }

    private function generateFederationToken(string $path): Result
    {
        $sts = $this->initSTS();

        $policy = [
            'Statement' => [
                [
                    'Effect' =>'Allow',
                    'Action' => 's3:GetObject',
                    'Resource' => ['arn:aws:s3:::' . $this->imageParameters['#bucket'] . '/' . $path . '*'],
                ],

                // List bucket is required for Redshift COPY command even if only one file is loaded
                [
                    'Effect' => 'Allow',
                    'Action' => 's3:ListBucket',
                    'Resource' => ['arn:aws:s3:::' . $this->imageParameters['#bucket']],
                    'Condition' => [
                        'StringLike' => [
                            's3:prefix' => [$path . '*'],
                        ],
                    ],
                ],
            ],
        ];

        return $sts->getFederationToken([
            'DurationSeconds' => self::FEDERATION_TOKEN_EXPIRATION_HOURS * 3600,
            'Name' => 'GetProjectBackupFile',
            'Policy' => json_encode($policy),
        ]);
    }

    private function initSTS(): StsClient
    {
        return new StsClient([
            'version' => 'latest',
            'region' => $this->imageParameters['region'],
            'credentials' => [
                'key' => $this->imageParameters['access_key_id'],
                'secret' => $this->imageParameters['#secret_access_key'],
            ],
        ]);
    }

    private function initS3(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $this->imageParameters['region'],
            'credentials' => [
                'key' => $this->imageParameters['access_key_id'],
                'secret' => $this->imageParameters['#secret_access_key'],
            ],
        ]);
    }
}
