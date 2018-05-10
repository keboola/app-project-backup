<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\ProjectBackup\S3Backup;
use Keboola\StorageApi\Client as StorageApi;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class Component extends BaseComponent
{
    public const FEDERATION_TOKEN_EXPIRATION_HOURS = 24;

    public function run(): void
    {
        $action = $this->getConfig()->getAction();

        if ($action === 'run') {
            $this->handleRun();
        }

        if ($action === 'generate-read-credentials') {
            $credentials = $this->handleCredentials();
            echo json_encode($credentials);
        }
    }

    private function initSapi(): StorageApi
    {
        return new StorageApi([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);
    }

    private function initS3(): S3Client
    {
        $imageParams = $this->getConfig()->getImageParameters();

        return new S3Client([
            'version' => 'latest',
            'region' => $imageParams['region'],
            'credentials' => [
                'key' => $imageParams['access_key_id'],
                'secret' => $imageParams['#secret_access_key'],
            ],
        ]);
    }

    private function initSTS(): StsClient
    {
        $imageParams = $this->getConfig()->getImageParameters();

        return new StsClient([
            'version' => 'latest',
            'region' => $imageParams['region'],
            'credentials' => [
                'key' => $imageParams['access_key_id'],
                'secret' => $imageParams['#secret_access_key'],
            ],
        ]);
    }

    public function handleCredentials(): array
    {
        $imageParams = $this->getConfig()->getImageParameters();

        $sapi = $this->initSapi();
        $sts = $this->initSTS();

        /** @var string */
        $backupId = $sapi->generateId();

        $path = $this->generateBackupPath((int) $backupId, $sapi);

        $result = $this->initS3()->putObject([
            'Bucket' => $imageParams['#bucket'],
            'Key' => $path,
        ]);

        $policy = [
            'Statement' => [
                [
                    'Effect' =>'Allow',
                    'Action' => 's3:GetObject',
                    'Resource' => ['arn:aws:s3:::' . $imageParams['#bucket'] . '/' . $path . '*'],
                ],

                // List bucket is required for Redshift COPY command even if only one file is loaded
                [
                    'Effect' => 'Allow',
                    'Action' => 's3:ListBucket',
                    'Resource' => ['arn:aws:s3:::' . $imageParams['#bucket']],
                    'Condition' => [
                        'StringLike' => [
                            's3:prefix' => [$path . '*'],
                        ],
                    ],
                ],
            ],
        ];

        $federationToken = $sts->getFederationToken([
            'DurationSeconds' => self::FEDERATION_TOKEN_EXPIRATION_HOURS * 3600,
            'Name' => 'GetProjectBackupFile',
            'Policy' => json_encode($policy),
        ]);

        return [
          'backupId' => $backupId,
          'backupUri' => $result['ObjectURL'],
          'region' => $imageParams['region'],
          'credentials' => [
              'accessKeyId' => $federationToken['Credentials']['AccessKeyId'],
              'secretAccessKey' => $federationToken['Credentials']['SecretAccessKey'],
              'sessionToken' => $federationToken['Credentials']['SessionToken'],
              'expiration' => $federationToken['Credentials']['Expiration'],
          ],
        ];
    }

    private function generateBackupPath(int $backupId, StorageApi $client): string
    {
        $token = $client->verifyToken();
        $imageParams = $this->getConfig()->getImageParameters();

        $region = $token['owner']['region'];
        $projectId = $token['owner']['id'];

        if ($region !== $imageParams['region']) {
            throw new \Exception(sprintf('Project with ID "%s" is not located in %s region', $projectId, $imageParams['region']));
        }

        return sprintf('data-takeout/%s/%s/%s/', $region, $projectId, $backupId);
    }

    public function handleRun(): void
    {
        $sapi = $this->initSapi();
        $s3Client = $this->initS3();

        $imageParams = $this->getConfig()->getImageParameters();
        $actionParams = $this->getConfig()->getParameters();

        $bucket = $imageParams['#bucket'];
        $path = $this->generateBackupPath((int) $actionParams['backupId'], $sapi);

        // check if backup folder was initialized
        try {
            $s3Client->getObject([
                'Bucket' => $bucket,
                'Key' => $path,
            ]);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey') {
                throw new UserException(sprintf('Backup with ID "%s" was not initialized for this KBC project', $actionParams['backupId']));
            } else {
                throw $e;
            }
        }

        $logger = $this->initLogger();
        $backup = new S3Backup($sapi, $s3Client, $this->initLogger());

        $backup->backupTablesMetadata($bucket, $path);

        $tables = $sapi->listTables(null);
        $tablesCount = count($tables);

        foreach ($tables as $i => $table) {
            $logger->info(sprintf('Table %d/%d', $i + 1, $tablesCount));
            $backup->backupTable($table['id'], $bucket, $path);
        }

        $backup->backupConfigs($bucket, $path, true);
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }

    private function initLogger(): Logger
    {
        $formatter = new LineFormatter("%message%\n");

        $errorHandler = new StreamHandler('php://stderr', Logger::WARNING, false);
        $errorHandler->setFormatter($formatter);

        $handler = new StreamHandler('php://stdout', Logger::INFO);
        $handler->setFormatter($formatter);

        $logger = new Logger(
            getenv('KBC_COMPONENTID')?: 'project-backup',
            [
                $errorHandler,
                $handler,
            ]
        );

        return $logger;
    }
}
