<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup;

use Exception;
use Keboola\App\ProjectBackup\Config\Config;
use Keboola\App\ProjectBackup\Storages\AwsS3Storage;
use Keboola\App\ProjectBackup\Storages\AzureBlobStorage;
use Keboola\App\ProjectBackup\Storages\IStorage;
use Keboola\Component\UserException;
use Keboola\StorageApi\Client as StorageApi;
use Psr\Log\LoggerInterface;

class Application
{
    private IStorage $storageBackend;

    private Config $config;

    private LoggerInterface $logger;

    private array $excludeTables = [
        'in.c-kds-team-ex-mixpanel-152770611.output',
        'in.c-L1_RAW_PS_scenarios.scenario-execution-log',
        'in.c-L1_RAW_PS_scenarios.scenario_version',
        'in.c-L1_RAW_PS_stats.scenario_counter',
        'in.c-L1_RAW_PS_scenarios.scenario-log2',
        'out.c-L2_PS_scenarios.scenario_version',
        'out.c-L2_PS_scenarios.scenario_log2',
        'out.c-L2_PS_scenarios.scenario_module_snapshot',
        'out.c-3_scn.scn_daily_stats',
        'out.c-L2_PS_scenarios.scenario_snapshot',
        'out.c-L2_PS_users.user_snapshot',
        'out.c-L2_PS_users.company_user_snapshot',
        'out.c-3_org.org_scn_daily_stats',
        'out.c-L2_P_stats.scenario_counter',
    ];

    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;

        switch ($config->getStorageBackendType()) {
            case Config::STORAGE_BACKEND_S3:
                $this->storageBackend = new AwsS3Storage(
                    $config->getS3Config(),
                    $config->isUserDefinedCredentials(),
                    $logger
                );
                break;
            case Config::STORAGE_BACKEND_ABS:
                $this->storageBackend = new AzureBlobStorage($config->getAbsConfig(), $logger);
                break;
            default:
                throw new UserException(sprintf(
                    'Unknown storage backend type "%s".',
                    $config->getStorageBackendType()
                ));
        }
    }

    public function run(): void
    {
        $sapi = $this->initSapi();

        if ($this->config->isUserDefinedCredentials()) {
            $path = $this->config->getPath();
        } else {
            $path = $this->generateBackupPath((int) $this->config->getBackupId(), $sapi);
        }

        $backup = $this->storageBackend->getBackup($sapi, $path);

        $backup->backupTablesMetadata();

        if (!$this->config->exportStructureOnly()) {
            $tables = $sapi->listTables();
            $tablesCount = count($tables);
            foreach ($tables as $i => $table) {
                if (in_array($table['id'], $this->excludeTables)) {
                    continue;
                }
                $this->logger->info(sprintf('Table %d/%d', $i + 1, $tablesCount));
                $backup->backupTable($table['id']);
            }
        }

        $backup->backupConfigs($this->config->includeVersions());
    }

    public function generateReadCredentials(): array
    {
        $sapi = $this->initSapi();
        /** @var string */
        $backupId = $sapi->generateId();
        if ($this->config->isUserDefinedCredentials()) {
            $path = $this->config->getPath();
        } else {
            $path = $this->generateBackupPath((int) $backupId, $sapi);
        }

        return $this->storageBackend->generateTempReadCredentials($backupId, $path);
    }

    private function generateBackupPath(int $backupId, StorageApi $client): string
    {
        $token = $client->verifyToken();
        $imageParams = $this->config->getImageParameters();

        $region = $token['owner']['region'];
        $projectId = $token['owner']['id'];

        if ($region !== $imageParams['region']) {
            throw new Exception(
                sprintf(
                    'Project with ID "%s" is not located in %s region',
                    $projectId,
                    $imageParams['region']
                )
            );
        }

        return sprintf('data-takeout/%s/%s/%s/', $region, $projectId, $backupId);
    }

    private function initSapi(): StorageApi
    {
        $storageApi = new StorageApi([
            'url' => getenv('KBC_URL'),
            'token' => getenv('KBC_TOKEN'),
        ]);

        $storageApi->setRunId(getenv('KBC_RUNID'));
        return $storageApi;
    }
}
