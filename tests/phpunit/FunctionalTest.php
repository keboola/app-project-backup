<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Tests;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Keboola\App\ProjectBackup\S3UriParser;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalTest extends TestCase
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
            \json_encode([
                'action' => 'generate-read-credentials',
                'image_parameters' => [
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
            'region' => $uriParts['region'],
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
            \json_encode([
                'action' => 'generate-read-credentials',
                'image_parameters' => [
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
            \json_encode([
                'action' => 'run',
                'parameters' => [
                    'backupId' => $outputData['backupId'],
                ],
                'image_parameters' => [
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

        $events = $this->sapiClient->listEvents(['runId' => $this->testRunId]);
        self::assertGreaterThan(0, count($events));
    }

    public function testBadBackupIdRun(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            \json_encode([
                'action' => 'run',
                'parameters' => [
                    'backupId' => $this->sapiClient->generateId(),
                ],
                'image_parameters' => [
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
        $this->assertContains('was not initialized for this KBC project', $errorOutput);
    }

    public function testRegionErrorRun(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            \json_encode([
                'action' => 'run',
                'parameters' => [
                    'backupId' => $this->sapiClient->generateId(),
                ],
                'image_parameters' => [
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
                $this->sapiClient->dropBucket($bucket["id"], ["force" => true]);
            }
        }

        foreach ($this->sapiClient->listBuckets() as $bucket) {
            $this->sapiClient->dropBucket($bucket["id"], ["force" => true]);
        }
    }

    private function createTestProcess(): Process
    {
        $runCommand = "php /code/src/run.php";
        return new  Process($runCommand, null, [
            'KBC_DATADIR' => $this->temp->getTmpFolder(),
            'KBC_URL' => getenv('TEST_STORAGE_API_URL'),
            'KBC_TOKEN' => getenv('TEST_STORAGE_API_TOKEN'),
            'KBC_RUNID' => $this->testRunId,
        ]);
    }
}
