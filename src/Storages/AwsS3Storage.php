<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Storages;

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use Keboola\App\ProjectBackup\Config\S3Config;
use Keboola\Component\UserException;
use Keboola\ProjectBackup\Backup;
use Keboola\ProjectBackup\S3Backup;
use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

class AwsS3Storage implements IStorage
{
    private S3Config $config;

    private LoggerInterface $logger;

    private bool $userDefinedCredentials;

    public const FEDERATION_TOKEN_EXPIRATION_HOURS = 36;

    public function __construct(S3Config $config, bool $userDefinedCredentials, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->userDefinedCredentials = $userDefinedCredentials;
    }

    public function generateTempReadCredentials(string $backupId, string $path): array
    {
        $federationToken = $this->generateFederationToken($path);

        $result = $this->createBackupPath($path);

        return [
            'backupId' => $backupId,
            'backupUri' => $result['ObjectURL'],
            'region' => $this->config->getRegion(),
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
                'Bucket' => $this->config->getBucket(),
                'Key' => $path,
            ]);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey') {
                if ($this->userDefinedCredentials) {
                    $this->createBackupPath($path);
                } else {
                    throw new UserException(
                        sprintf(
                            'Backup path "%s" not found in the bucket "%s".',
                            $path,
                            $this->config->getBucket()
                        )
                    );
                }
            } else {
                throw $e;
            }
        }

        return new S3Backup(
            $sapi,
            $s3Client,
            $this->config->getBucket(),
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
                    'Resource' => ['arn:aws:s3:::' . $this->config->getBucket() . '/' . $path . '*'],
                ],

                // List bucket is required for Redshift COPY command even if only one file is loaded
                [
                    'Effect' => 'Allow',
                    'Action' => 's3:ListBucket',
                    'Resource' => ['arn:aws:s3:::' . $this->config->getBucket()],
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
            'region' => $this->config->getRegion(),
            'credentials' => [
                'key' => $this->config->getAccessKeyId(),
                'secret' => $this->config->getSecretAccessKey(),
            ],
        ]);
    }

    private function initS3(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $this->config->getRegion(),
            'credentials' => [
                'key' => $this->config->getAccessKeyId(),
                'secret' => $this->config->getSecretAccessKey(),
            ],
        ]);
    }

    private function createBackupPath(string $path): Result
    {
        return $this->initS3()->putObject([
            'Bucket' => $this->config->getBucket(),
            'Key' => $path,
        ]);
    }
}
