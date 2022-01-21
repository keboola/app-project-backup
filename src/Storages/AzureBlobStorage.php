<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Storages;

use DateTime;
use Keboola\App\ProjectBackup\Config\AbsConfig;
use Keboola\Component\UserException;
use Keboola\ProjectBackup\AbsBackup;
use Keboola\ProjectBackup\Backup;
use Keboola\StorageApi\Client;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\BlobSharedAccessSignatureHelper;
use MicrosoftAzure\Storage\Blob\Models\Container;
use MicrosoftAzure\Storage\Common\Internal\Resources;
use MicrosoftAzure\Storage\Common\Middlewares\RetryMiddleware;
use MicrosoftAzure\Storage\Common\Middlewares\RetryMiddlewareFactory;
use Psr\Log\LoggerInterface;

class AzureBlobStorage implements IStorage
{
    private AbsConfig $config;

    private LoggerInterface $logger;

    public const SAS_DEFAULT_EXPIRATION_HOURS = 36;

    public function __construct(AbsConfig $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function generateTempReadCredentials(string $backupId, string $path): array
    {
        $path = $this->modifyPath($path);

        $client = BlobRestProxy::createBlobService(sprintf(
            'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
            $this->config->getAccountName(),
            $this->config->getAccountKey()
        ));
        $client->pushMiddleware(self::createRetryMiddleware());
        $client->createContainer($path);

        $sasHelper = new BlobSharedAccessSignatureHelper(
            $this->config->getAccountName(),
            $this->config->getAccountKey()
        );

        $expirationDate = (new DateTime())->modify('+' . self::SAS_DEFAULT_EXPIRATION_HOURS . 'hour');
        $sasToken = $sasHelper->generateBlobServiceSharedAccessSignatureToken(
            Resources::RESOURCE_TYPE_CONTAINER,
            $path,
            'rl',
            $expirationDate,
            new DateTime('now')
        );

        return [
            'backupId' => $backupId,
            'region' => $this->config->getRegion(),
            'container' => $path,
            'credentials' => [
                'connectionString' => $this->getConnectionString($sasToken),
            ],
        ];
    }

    public function getBackup(Client $sapi, string $path): Backup
    {
        $path = $this->modifyPath($path);
        $client = BlobRestProxy::createBlobService(sprintf(
            'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;EndpointSuffix=core.windows.net',
            $this->config->getAccountName(),
            $this->config->getAccountKey()
        ));
        $client->pushMiddleware(self::createRetryMiddleware());

        $listContainers = array_map(fn(Container $v) => $v->getName(), $client->listContainers()->getContainers());
        if (!in_array($path, $listContainers)) {
            throw new UserException(sprintf(
                'The specified container "%s" does not exist.',
                $path
            ));
        }

        return new AbsBackup($sapi, $client, $path, $this->logger);
    }

    private function getConnectionString(string $sasToken): string
    {
        return sprintf(
            '%s=https://%s.blob.core.windows.net;SharedAccessSignature=%s',
            Resources::BLOB_ENDPOINT_NAME,
            $this->config->getAccountName(),
            $sasToken
        );
    }

    private function modifyPath(string $path): string
    {
        $path = str_replace('/', '-', $path);
        return rtrim($path, '-');
    }

    private static function createRetryMiddleware(): RetryMiddleware
    {
        return RetryMiddlewareFactory::create(
            RetryMiddlewareFactory::GENERAL_RETRY_TYPE,
            20,
            3000,
            RetryMiddlewareFactory::EXPONENTIAL_INTERVAL_ACCUMULATION,
            true
        );
    }
}
