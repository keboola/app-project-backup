<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Tests;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\S3\S3UriParser;
use Google\Auth\FetchAuthTokenInterface;
use Google\Cloud\Core\Exception\ServiceException;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use Keboola\App\ProjectBackup\Config\Config;
use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalGCSTest extends TestCase
{
    protected Temp $temp;

    protected StorageApi $sapiClient;

    private StorageClient $storageClient;

    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->temp = new Temp('project-backup');

        $this->sapiClient = new StorageApi([
            'url' => getenv('TEST_GCP_STORAGE_API_URL'),
            'token' => getenv('TEST_GCP_STORAGE_API_TOKEN'),
        ]);

        $this->cleanupKbcProject();

        $this->storageClient = new StorageClient([
            'keyFile' => json_decode((string) getenv('TEST_GCP_SERVICE_ACCOUNT'), true),
        ]);

        $this->cleanupGCSBucket();

        $component = new Components($this->sapiClient);

        $config = new Configuration();
        $config->setComponentId('keboola.snowflake-transformation');
        $config->setDescription('Test Configuration');
        $config->setConfigurationId('sapi-php-test');
        $config->setName('test-configuration');
        $component->addConfiguration($config);

        $this->testRunId = $this->sapiClient->generateRunId();
    }

    public function testCreateCredentials(): void
    {
        $backupId = $this->sapiClient->generateId();
        // run backup
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'parameters' => [
                    'backupId' => $backupId,
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#jsonKey' => getenv('TEST_GCP_SERVICE_ACCOUNT'),
                    'region' => getenv('TEST_GCP_REGION'),
                    '#bucket' => getenv('TEST_GCP_BUCKET'),
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'generate-read-credentials',
                'parameters' => [
                    'backupId' => $backupId,
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#jsonKey' => getenv('TEST_GCP_SERVICE_ACCOUNT'),
                    'region' => getenv('TEST_GCP_REGION'),
                    '#bucket' => getenv('TEST_GCP_BUCKET'),
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        /** @var array $outputData */
        $outputData = json_decode($output, true);

        $this->assertArrayHasKey('projectId', $outputData);
        $this->assertArrayHasKey('bucket', $outputData);
        $this->assertArrayHasKey('backupUri', $outputData);
        $this->assertArrayHasKey('credentials', $outputData);
        $this->assertArrayHasKey('accessToken', $outputData['credentials']);
        $this->assertArrayHasKey('expiresIn', $outputData['credentials']);
        $this->assertArrayHasKey('tokenType', $outputData['credentials']);

        $credentials = $outputData['credentials'];
        $fetchAuthToken = $this->getAuthTokenClass([
            'access_token' => $credentials['accessToken'],
            'expires_in' => $credentials['expiresIn'],
            'token_type' => $credentials['tokenType'],
        ]);
        $storageClient = new StorageClient([
            'projectId' => $outputData['projectId'],
            'credentialsFetcher' => $fetchAuthToken,
        ]);

        // access signed urls file
        $storageClient
            ->bucket($outputData['bucket'])
            ->object($outputData['backupUri'] . 'signedUrls.json')
            ->exists();

        // access other file
        try {
            $storageClient
                ->bucket($outputData['bucket'])
                ->object($outputData['backupUri'] . 'configurations.json')
                ->exists();
            $this->fail('Getting configurations file should produce error');
        } catch (ServiceException $e) {
            $this->assertEquals(403, $e->getCode());
            $this->assertStringContainsString('does not have storage.objects.get access', $e->getMessage());
        }

        try {
            $storageClient->bucket($outputData['bucket'])->upload('Hello world', [
                'name' => $outputData['backupUri'] . 'sample.txt',
            ]);
            $this->fail('Uploading file should produce error');
        } catch (ServiceException $e) {
            $this->assertEquals(403, $e->getCode());
            $this->assertStringContainsString('does not have storage.objects.create access', $e->getMessage());
        }

        // access other backup
        try {
            $storageClient
                ->bucket($outputData['bucket'])
                ->object(
                    str_replace(
                        $backupId,
                        '123',
                        $outputData['backupUri'],
                    ) . 'signedUrls.json',
                )
                ->exists();
            $this->fail('Getting other backup should produce error');
        } catch (ServiceException $e) {
            $this->assertEquals(403, $e->getCode());
            $this->assertStringContainsString('does not have storage.objects.get access', $e->getMessage());
        }
    }

    public function testSuccessfulRun(): void
    {
        // run backup
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'parameters' => [
                    'backupId' => $this->sapiClient->generateId(),
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#jsonKey' => getenv('TEST_GCP_SERVICE_ACCOUNT'),
                    'region' => getenv('TEST_GCP_REGION'),
                    '#bucket' => getenv('TEST_GCP_BUCKET'),
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $this->assertStringContainsString('Exporting buckets', $output);
        $this->assertStringContainsString('Exporting tables', $output);
        $this->assertStringContainsString('Exporting configurations', $output);
        $this->assertStringContainsString('Exporting permanent files', $output);

        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertGreaterThan(0, count($events));
    }

    public function testSuccessfulRunOnlyStructure(): void
    {
        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertCount(0, $events);

        $tmp = new Temp();

        $file = $tmp->createFile('testStructureOnly.csv');
        file_put_contents($file->getPathname(), 'a,b,c,d,e,f');

        $csvFile = new CsvFile($file->getPathname());

        $this->sapiClient->createBucket('test-bucket', 'out');
        $this->sapiClient->createTableAsync('out.c-test-bucket', 'test-table', $csvFile);

        // run backup
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'parameters' => [
                    'backupId' => $this->sapiClient->generateId(),
                    'exportStructureOnly' => true,
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#jsonKey' => getenv('TEST_GCP_SERVICE_ACCOUNT'),
                    'region' => getenv('TEST_GCP_REGION'),
                    '#bucket' => getenv('TEST_GCP_BUCKET'),
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $this->assertStringContainsString('Exporting buckets', $output);
        $this->assertStringContainsString('Exporting tables', $output);
        $this->assertStringContainsString('Exporting configurations', $output);
        $this->assertStringNotContainsString('Table ', $output);

        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertGreaterThan(0, count($events));
    }

    public function testCreateUnexistsBackupFolder(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#jsonKey' => getenv('TEST_GCP_SERVICE_ACCOUNT'),
                    'region' => getenv('TEST_GCP_REGION'),
                    '#bucket' => getenv('TEST_GCP_BUCKET'),
                    'backupPath' => 'unexists/backup/folder',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    'access_key_id' => '',
                    '#secret_access_key' => '',
                    'region' => '',
                    '#bucket' => '',
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $this->assertEquals(0, $runProcess->getExitCode());

        $objects = $this->storageClient->bucket((string) getenv('TEST_GCP_BUCKET'))->objects();

        $files = [];
        foreach ($objects as $object) {
            $files[] = $object->name();
        }

        $this->assertNotEmpty($files);
    }

    public function testRegionErrorRun(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'parameters' => [
                    'backupId' => $this->sapiClient->generateId(),
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_GCS,
                    '#jsonKey' => getenv('TEST_GCP_SERVICE_ACCOUNT'),
                    'region' => 'unknown-custom-region',
                    '#bucket' => getenv('TEST_GCP_BUCKET'),
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $this->assertEquals(2, $runProcess->getExitCode());

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertEmpty($output);
        $this->assertStringContainsString('is not located in', $errorOutput);
        $this->assertStringContainsString('unknown-custom-region', $errorOutput);
    }

    private function cleanupKbcProject(): void
    {
        $components = new Components($this->sapiClient);
        foreach ($components->listComponents() as $component) {
            foreach ($component['configurations'] as $configuration) {
                $components->deleteConfiguration($component['id'], $configuration['id']);

                // delete configuration from trash
                $components->deleteConfiguration($component['id'], $configuration['id']);
            }
        }

        // drop linked buckets
        foreach ($this->sapiClient->listBuckets() as $bucket) {
            if (isset($bucket['sourceBucket'])) {
                $this->sapiClient->dropBucket($bucket['id'], ['force' => true]);
            }
        }

        foreach ($this->sapiClient->listBuckets() as $bucket) {
            $this->sapiClient->dropBucket($bucket['id'], ['force' => true]);
        }
    }

    private function createTestProcess(): Process
    {
        $runCommand = 'php /code/src/run.php';
        return Process::fromShellCommandline($runCommand, null, [
            'KBC_DATADIR' => $this->temp->getTmpFolder(),
            'KBC_URL' => getenv('TEST_GCP_STORAGE_API_URL'),
            'KBC_TOKEN' => getenv('TEST_GCP_STORAGE_API_TOKEN'),
            'KBC_RUNID' => $this->testRunId,
        ]);
    }

    private function cleanupGCSBucket(): void
    {
        $objects = $this->storageClient->bucket((string) getenv('TEST_GCP_BUCKET'))->objects();

        /** @var StorageObject $object */
        foreach ($objects as $object) {
            $object->delete();
        }
    }

    private function getAuthTokenClass(array $credentials): FetchAuthTokenInterface
    {
        return new class ($credentials) implements FetchAuthTokenInterface {
            private array $creds;

            public function __construct(
                array $creds,
            ) {
                $this->creds = $creds;
            }

            public function fetchAuthToken(?callable $httpHandler = null): array
            {
                return $this->creds;
            }

            public function getCacheKey(): string
            {
                return '';
            }

            public function getLastReceivedToken(): array
            {
                return $this->creds;
            }
        };
    }
}
