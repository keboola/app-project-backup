<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Storages;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Cloud\Storage\StorageClient;
use Google\Service\CloudSecurityToken;
use Google\Service\CloudSecurityToken\GoogleIdentityStsV1ExchangeTokenRequest;
use Google_Client;
use Keboola\App\ProjectBackup\Config\GcsConfig;
use Keboola\ProjectBackup\Backup;
use Keboola\ProjectBackup\GcsBackup;
use Keboola\StorageApi\Client;
use Psr\Log\LoggerInterface;

class GoogleCloudStorage implements IStorage
{
    public function __construct(readonly GcsConfig $config, readonly LoggerInterface $logger)
    {
    }

    public function generateTempReadCredentials(string $backupId, string $path): array
    {
        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            (array) json_decode($this->config->getJsonKey(), true),
        );

        $httpHandler = HttpHandlerFactory::build();
        $authToken = $credentials->fetchAuthToken($httpHandler);

        /** @var array{
         *     project_id: string,
         * } $jsonKey
         */
        $jsonKey = json_decode($this->config->getJsonKey(), true);

        $sts = new CloudSecurityToken(new Google_Client([
            'keyFile' => $jsonKey,
        ]));
        $request = new GoogleIdentityStsV1ExchangeTokenRequest();

        $permissionOptions = [
            'accessBoundary' => [
                'accessBoundaryRules' => [
                    [
                        'availableResource' => sprintf(
                            '//storage.googleapis.com/projects/_/buckets/%s',
                            $this->config->getBucket(),
                        ),
                        'availablePermissions' => [
                            'inRole:roles/storage.objectViewer',
                        ],
                        'availabilityCondition' => [
                            'expression' => sprintf(
                                'resource.name == \'projects/_/buckets/%s/objects/%s\'',
                                $this->config->getBucket(),
                                $path . 'signedUrls.json',
                            ),
                        ],
                    ],
                ],
            ],
        ];

        $request->setOptions(urlencode(json_encode($permissionOptions, JSON_THROW_ON_ERROR)));
        $request->setGrantType('urn:ietf:params:oauth:grant-type:token-exchange');
        $request->setRequestedTokenType('urn:ietf:params:oauth:token-type:access_token');
        $request->setSubjectToken($authToken['access_token']);
        $request->setSubjectTokenType('urn:ietf:params:oauth:token-type:access_token');

        $response = $sts->v1->token($request);

        return [
            'projectId' => $jsonKey['project_id'],
            'bucket' => $this->config->getBucket(),
            'backupUri' => $path,
            'credentials' => $response,
        ];
    }

    public function getBackup(Client $sapi, string $path): Backup
    {
        $storageClient = new StorageClient([
            'keyFile' => json_decode($this->config->getJsonKey(), true),
        ]);

        if (!str_ends_with($path, '/')) {
            $path .= '/';
        }

        return new GcsBackup(
            sapiClient: $sapi,
            storageClient: $storageClient,
            bucketName: $this->config->getBucket(),
            path: $path,
            generateSignedUrls: !$this->config->isUserDefinedCredentials(),
            logger: $this->logger,
        );
    }
}
