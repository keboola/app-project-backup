<?php

declare(strict_types=1);

namespace Keboola\App\ProjectBackup\Tests;

use Keboola\App\ProjectBackup\Config\Config;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class FunctionalS3WrongCredentialsTest extends TestCase
{
    protected Temp $temp;

    protected StorageApi $sapiClient;

    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->temp = new Temp('project-backup');
        $this->temp->initRunFolder();

        $this->sapiClient = new StorageApi([
            'url' => getenv('TEST_STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);

        $this->testRunId = $this->sapiClient->generateRunId();
    }

    public function testWrongSecret(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID'),
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY') . 'invalid',
                    'region' => getenv('TEST_AWS_REGION'),
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $exception = null;

        try {
            $runProcess->mustRun();
        } catch (ProcessFailedException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(ProcessFailedException::class, $exception);
        $this->assertEquals(1, $runProcess->getExitCode());

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertEmpty($output);
        $this->assertSame(
            'The request signature we calculated does not match the signature you provided. ' .
            "Check your key and signing method.\n",
            $errorOutput
        );
    }

    public function testWrongId(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_S3,
                    'access_key_id' => getenv('TEST_AWS_ACCESS_KEY_ID') . 'invalid',
                    '#secret_access_key' => getenv('TEST_AWS_SECRET_ACCESS_KEY'),
                    'region' => getenv('TEST_AWS_REGION'),
                    '#bucket' => getenv('TEST_AWS_S3_BUCKET'),
                ],
            ])
        );

        $runProcess = $this->createTestProcess();
        $exception = null;

        try {
            $runProcess->mustRun();
        } catch (ProcessFailedException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(ProcessFailedException::class, $exception);
        $this->assertEquals(1, $runProcess->getExitCode());

        $output = $runProcess->getOutput();
        $errorOutput = $runProcess->getErrorOutput();

        $this->assertEmpty($output);
        $this->assertSame("The AWS Access Key Id you provided does not exist in our records.\n", $errorOutput);
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
}
