<?php

declare(strict_types=1);

use Aws\Sts\Exception\StsException;
use Keboola\Component\UserException;
use Keboola\Component\Logger;
use Keboola\App\ProjectBackup\Component;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

require __DIR__ . '/../vendor/autoload.php';

$logger = new Logger();
try {
    $app = new Component($logger);

    try {
        $app->run();
    } catch (StsException $e) {
        if (in_array($e->getAwsErrorCode(), ['InvalidClientTokenId', 'SignatureDoesNotMatch'], true)) {
            throw new UserException($e->getAwsErrorMessage());
        }

        throw $e;
    } catch (ServiceException $e) {
        if ($e->getCode() === 403) {
            throw new UserException($e->getErrorMessage());
        }

        throw $e;
    }

    exit(0);
} catch (UserException $e) {
    $logger->error($e->getMessage());
    exit(1);
} catch (\Throwable $e) {
    $logger->critical(
        get_class($e) . ':' . $e->getMessage(),
        [
            'errFile' => $e->getFile(),
            'errLine' => $e->getLine(),
            'errCode' => $e->getCode(),
            'errTrace' => $e->getTraceAsString(),
            'errPrevious' => $e->getPrevious() ? get_class($e->getPrevious()) : '',
        ]
    );
    exit(2);
}
