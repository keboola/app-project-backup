<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Tests;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\S3\S3UriParser;
use Keboola\App\ProjectBackup\Config\Config;
use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalS3Test extends TestCase
{
    /**
     * @var Temp
     */
    protected $temp;

    /**
     * @var StorageApi
     */
    protected $sapiClient;

    /**
     * @var string
     */
    private $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->temp = new Temp('project-backup');
        $this->temp->initRunFolder();

        $this->sapiClient = new StorageApi([
            'url' => getenv('TEST_STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);

        $this->cleanupKbcProject();
        $this->cleanupS3Bucket();

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
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                    'region' => getenv('TEST_AWS_REGION'),
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $outputData = \json_decode($output, true);

        $this->assertArrayHasKey('backupId', $outputData);
        $this->assertArrayHasKey('backupUri', $outputData);
        $this->assertArrayHasKey('region', $outputData);
        $this->assertArrayHasKey('credentials', $outputData);
        $this->assertArrayHasKey('accessKeyId', $outputData['credentials']);
        $this->assertArrayHasKey('secretAccessKey', $outputData['credentials']);
        $this->assertArrayHasKey('sessionToken', $outputData['credentials']);
        $this->assertArrayHasKey('expiration', $outputData['credentials']);

        $uriParser = new S3UriParser();
        $uriParts = $uriParser->parse($outputData['backupUri']);

        $readS3Client = new S3Client([
            'version' => 'latest',
            'region' => $outputData['region'],
            'credentials' => [
                'key' => $outputData['credentials']['accessKeyId'],
                'secret' => $outputData['credentials']['secretAccessKey'],
                'token' => $outputData['credentials']['sessionToken'],
            ],
        ]);

        // read permissions
        $readS3Client->getObject([
            'Bucket' => $uriParts['bucket'],
            'Key' => $uriParts['key'],
        ]);

        // write permissions
        try {
            $readS3Client->putObject([
                'Bucket' => $uriParts['bucket'],
                'Key' => $uriParts['key'] . 'sample.txt',
                'Body' => 'Hello world',
            ]);
            $this->fail('Adding files to backup folder should produce error');
        } catch (S3Exception $e) {
            $this->assertEquals('AccessDenied', $e->getAwsErrorCode());
        }

        // other backup
        try {
            $readS3Client->getObject([
                'Bucket' => $uriParts['bucket'],
                'Key' => str_replace($outputData['backupId'], '123', $uriParts['key']),
            ]);

            $this->fail('Getting other backup should produce error');
        } catch (S3Exception $e) {
            $this->assertEquals('AccessDenied', $e->getAwsErrorCode());
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
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                    'region' => getenv('TEST_AWS_REGION'),
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $outputData = \json_decode($output, true);

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
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                    'region' => getenv('TEST_AWS_REGION'),
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $this->assertContains('Exporting buckets', $output);
        $this->assertContains('Exporting tables', $output);
        $this->assertContains('Exporting configurations', $output);
        $this->assertContains('Exporting permanent files', $output);

        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertGreaterThan(0, count($events));
    }

    public function testSuccessfulRunOnlyStructure(): void
    {
        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertCount(0, $events);

        $tmp = new Temp();
        $tmp->initRunFolder();

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
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                    'region' => getenv('TEST_AWS_REGION'),
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $outputData = \json_decode($output, true);

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
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                    'region' => getenv('TEST_AWS_REGION'),
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->mustRun();

        $this->assertEmpty($runProcess->getErrorOutput());

        $output = $runProcess->getOutput();
        $this->assertContains('Exporting buckets', $output);
        $this->assertContains('Exporting tables', $output);
        $this->assertContains('Exporting configurations', $output);
        $this->assertNotContains('Table ', $output);

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
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                    'region' => getenv('TEST_AWS_REGION'),
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $this->assertEquals(1, $runProcess->getExitCode());

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertEmpty($output);
        $this->assertStringMatchesFormat(
            'Backup path "%s" not found in the bucket "%s".',
            trim($errorOutput)
        );
    }

    public function testCreateUnexistsBackupFolderS3(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                    'region' => getenv('TEST_AWS_REGION'),
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                    'backupPath' => 'unexists/backup/folder',
                ],
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => '',
                    '#secret_access_key' => '',
                    'region' => '',
                    '#bucket' => '',
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $this->assertEquals(0, $runProcess->getExitCode());

        $client = new S3Client([
            'version' => 'latest',
            'region' => getenv('TEST_AWS_REGION'),
            'credentials' => [
                'key' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                'secret' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
            ],
        ]);

        $objects = $client->listObjects([
            'Bucket' => getenv('TEST_AWS_S3_BUCKET'),
            'Key' => 'unexists/backup/folder',
        ])->toArray();

        $this->assertNotEmpty($objects['Contents']);
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
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                    'region' => 'unknown-custom-region',
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $runProcess->run();

        $this->assertEquals(2, $runProcess->getExitCode());

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertEmpty($output);
        $this->assertContains('is not located in', $errorOutput);
        $this->assertContains('unknown-custom-region', $errorOutput);
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
            'KBC_URL' => getenv('TEST_STORAGE_API_URL'),
            'KBC_TOKEN' => getenv('TEST_STORAGE_API_TOKEN'),
            'KBC_RUNID' => $this->testRunId,
        ]);
    }

    private function cleanupS3Bucket(): void
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => getenv('TEST_AWS_REGION'),
            'credentials' => [
                'key' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                'secret' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
            ],
        ]);

        $objects = $client->listObjects([
            'Bucket' => getenv('TEST_AWS_S3_BUCKET'),
        ])->toArray();

        if (!empty($objects['Contents'])) {
            $client->deleteObjects(
                [
                    'Bucket' => getenv('TEST_AWS_S3_BUCKET'),
                    'Delete' => ['Objects' => $objects['Contents']],
                ]
            );
        }
    }
}
