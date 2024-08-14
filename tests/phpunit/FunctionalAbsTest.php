<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Tests;

use Keboola\App\ProjectBackup\Config\Config;
use Keboola\Csv\CsvFile;
use Keboola\FileStorage\Abs\ClientFactory;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Temp\Temp;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use function json_decode;

class FunctionalAbsTest extends TestCase
{
    protected Temp $temp;

    protected StorageApi $sapiClient;

    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->temp = new Temp('project-backup');

        $this->sapiClient = new StorageApi([
            'url' => getenv('TEST_AZURE_STORAGE_API_URL'),
            'token' => getenv('TEST_AZURE_STORAGE_API_TOKEN'),
        ]);

        $this->cleanupKbcProject();

        $component = new Components($this->sapiClient);

        $config = new Configuration();
        $config->setComponentId('transformation');
        $config->setDescription('Test Configuration');
        $config->setConfigurationId('sapi-php-test');
        $config->setName('test-configuration');
        $component->addConfiguration($config);

        $this->testRunId = $this->sapiClient->generateRunId();
    }

    public function testCreateCredentials(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'generate-read-credentials',
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => getenv('TEST_AZURE_ACCOUNT_NAME'),
                    '#accountKey' => getenv('TEST_AZURE_ACCOUNT_KEY'),
                    'region' => getenv('TEST_AZURE_REGION'),
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        /** @var array $outputData */
        $outputData = json_decode($output, true);

        $this->assertArrayHasKey('backupId', $outputData);
        $this->assertArrayHasKey('region', $outputData);
        $this->assertArrayHasKey('container', $outputData);
        $this->assertArrayHasKey('credentials', $outputData);
        $this->assertArrayHasKey('connectionString', $outputData['credentials']);

        $readClient = ClientFactory::createClientFromConnectionString($outputData['credentials']['connectionString']);

        // read permissions
        $readClient->listBlobs($outputData['container']);

        // write permissions
        try {
            $readClient->createBlockBlob($outputData['container'], 'test.json', '{}');
            $this->fail('Adding files to backup folder should produce error');
        } catch (ServiceException $e) {
            $this->assertStringContainsString(
                'This request is not authorized to perform this operation using this permission',
                $e->getMessage(),
            );
        }
    }

    public function testSuccessfulRun(): void
    {
        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertCount(0, $events);

        $fileSystem = new Filesystem();

        // create backupId
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'generate-read-credentials',
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => getenv('TEST_AZURE_ACCOUNT_NAME'),
                    '#accountKey' => getenv('TEST_AZURE_ACCOUNT_KEY'),
                    'region' => getenv('TEST_AZURE_REGION'),
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        /** @var array $outputData */
        $outputData = json_decode($output, true);

        $this->assertArrayHasKey('backupId', $outputData);

        // run backup
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'parameters' => [
                    'backupId' => $outputData['backupId'],
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => getenv('TEST_AZURE_ACCOUNT_NAME'),
                    '#accountKey' => getenv('TEST_AZURE_ACCOUNT_KEY'),
                    'region' => getenv('TEST_AZURE_REGION'),
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
        $this->sapiClient->createTable('out.c-test-bucket', 'test-table', $csvFile);

        $fileSystem = new Filesystem();

        // create backupId
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'generate-read-credentials',
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => getenv('TEST_AZURE_ACCOUNT_NAME'),
                    '#accountKey' => getenv('TEST_AZURE_ACCOUNT_KEY'),
                    'region' => getenv('TEST_AZURE_REGION'),
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        /** @var array $outputData */
        $outputData = json_decode($output, true);

        $this->assertArrayHasKey('backupId', $outputData);

        // run backup
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'parameters' => [
                    'backupId' => $outputData['backupId'],
                    'exportStructureOnly' => true,
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => getenv('TEST_AZURE_ACCOUNT_NAME'),
                    '#accountKey' => getenv('TEST_AZURE_ACCOUNT_KEY'),
                    'region' => getenv('TEST_AZURE_REGION'),
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

    public function testBadBackupIdRun(): void
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
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => getenv('TEST_AZURE_ACCOUNT_NAME'),
                    '#accountKey' => getenv('TEST_AZURE_ACCOUNT_KEY'),
                    'region' => getenv('TEST_AZURE_REGION'),
                ],
            ]),
        );

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $this->assertEquals(1, $runProcess->getExitCode());

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertEmpty($output);
        $this->assertStringContainsString('The specified container', $errorOutput);
        $this->assertStringContainsString('does not exist.', $errorOutput);
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
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => getenv('TEST_AZURE_ACCOUNT_NAME'),
                    '#accountKey' => getenv('TEST_AZURE_ACCOUNT_KEY'),
                    'region' => 'unknown-custom-region',
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
            'KBC_URL' => getenv('TEST_AZURE_STORAGE_API_URL'),
            'KBC_TOKEN' => getenv('TEST_AZURE_STORAGE_API_TOKEN'),
            'KBC_RUNID' => $this->testRunId,
        ]);
    }
}
