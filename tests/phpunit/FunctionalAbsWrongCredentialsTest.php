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

class FunctionalAbsWrongCredentialsTest extends TestCase
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

        $this->testRunId = $this->sapiClient->generateRunId();
    }

    public function testWrongAccountKey(): void
    {
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile(
            $this->temp->getTmpFolder() . '/config.json',
            (string) json_encode([
                'action' => 'run',
                'image_parameters' => [
                    'storageBackendType' => Config::STORAGE_BACKEND_ABS,
                    'accountName' => getenv('TEST_AZURE_ACCOUNT_NAME'),
                    '#accountKey' => 'wrongsecret',
                    'region' => getenv('TEST_AZURE_REGION'),
                ],
            ]),
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
        $this->assertMatchesRegularExpression(
            '/Server failed to authenticate the request. ' .
            'Make sure the value of Authorization header is formed correctly including the signature./',
            $errorOutput,
        );
    }

    private function createTestProcess(): Process
    {
        $runCommand = 'php /code/src/run.php';
        $process = Process::fromShellCommandline($runCommand, null, [
            'KBC_DATADIR' => $this->temp->getTmpFolder(),
            'KBC_URL' => getenv('TEST_AZURE_STORAGE_API_URL'),
            'KBC_TOKEN' => getenv('TEST_AZURE_STORAGE_API_TOKEN'),
            'KBC_RUNID' => $this->testRunId,
        ]);
        $process->setTimeout(300);

        return $process;
    }
}
