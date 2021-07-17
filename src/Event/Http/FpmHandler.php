<?php declare(strict_types=1);

namespace Bref\Event\Http;

use Bref\Context\Context;
use Bref\Event\Http\FastCgi\FastCgiCommunicationFailed;
use Bref\Event\Http\FastCgi\FastCgiRequest;
use Exception;
use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Interfaces\ProvidesRequestData;
use hollodotme\FastCGI\Interfaces\ProvidesResponseData;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Handles HTTP events coming from API Gateway/ALB by proxying them to PHP-FPM via FastCGI.
 *
 * Usage example:
 *
 *     $event = [get the Lambda event];
 *     $phpFpm = new PhpFpm('index.php');
 *     $phpFpm->start();
 *     $lambdaResponse = $phpFpm->handle($event);
 *     $phpFpm->stop();
 *     [send the $lambdaResponse];
 *
 * @internal
 */
final class FpmHandler extends HttpHandler
{
    private const SOCKET = '/tmp/.bref/php-fpm.sock';
    private const PID_FILE = '/tmp/.bref/php-fpm.pid';
    private const CONFIG = '/opt/bref/etc/php-fpm.conf';
    /**
     * We define this constant instead of using the PHP one because that avoids
     * depending on the pcntl extension.
     */
    private const SIGTERM = 15;

    /** @var Client|null */
    private $client;
    /** @var UnixDomainSocket */
    private $connection;
    /** @var string */
    private $handler;
    /** @var string */
    private $configFile;
    /** @var Process|null */
    private $fpm;

    /** @var string */
    private $lambdaRequestId;
    /** @var string */
    private $apiGatewayRequestId;

    public function __construct(string $handler, string $configFile = self::CONFIG)
    {
        $this->handler = $handler;
        $this->configFile = $configFile;
    }

    /**
     * Start the PHP-FPM process.
     */
    public function start(): void
    {
        // In case Lambda stopped our process (e.g. because of a timeout) we need to make sure PHP-FPM has stopped
        // as well and restart it
        if ($this->isReady()) {
            $this->killExistingFpm();
        }

        if (! is_dir(dirname(self::SOCKET))) {
            mkdir(dirname(self::SOCKET));
        }

        /**
         * --nodaemonize: we want to keep control of the process
         * --force-stderr: force logs to be sent to stderr, which will allow us to send them to CloudWatch
         */
        $this->fpm = new Process(['php-fpm', '--nodaemonize', '--force-stderr', '--fpm-config', $this->configFile]);

        $this->fpm->setTimeout(null);
        $this->fpm->start(function ($type, $output): void {
            // Send any PHP-FPM log to CloudWatch
            echo $output;
        });

        $this->client = new Client;
        $this->connection = new UnixDomainSocket(self::SOCKET, 1000, 900000);

        $this->waitUntilReady();
    }

    public function stop(): void
    {
        if ($this->fpm && $this->fpm->isRunning()) {
            $this->fpm->stop(2);
            if ($this->isReady()) {
                throw new Exception('PHP-FPM cannot be stopped');
            }
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Proxy the API Gateway event to PHP-FPM and return its response.
     */
    public function handleRequest(HttpRequestEvent $event, Context $context): HttpResponse
    {
        $this->lambdaRequestId = $context->getAwsRequestId();
        $this->apiGatewayRequestId = $event->getRequestContext()['requestId'] ?? null;

        $request = $this->eventToFastCgiRequest($event, $context);

        try {
            $response = $this->client->sendRequest($this->connection, $request);
        } catch (Throwable $e) {
            $this->logToCloudwatch(sprintf(
                'Error communicating with PHP-FPM to read the HTTP response. A root cause of this can be that the Lambda (or PHP) timed out, for example when trying to connect to a remote API or database, if this happens continuously check for those! Bref will restart PHP-FPM now. Original exception message: %s %s',
                get_class($e),
                $e->getMessage()
            ));

            // Restart PHP-FPM: in some cases PHP-FPM is borked, that's the only way we can recover
            $this->stop();
            $this->start();

            throw new FastCgiCommunicationFailed;
        }

        $responseHeaders = $this->getResponseHeaders($response);

        // Extract the status code
        if (isset($responseHeaders['status'])) {
            $status = (int) (is_array($responseHeaders['status']) ? $responseHeaders['status'][0] : $responseHeaders['status']);
            unset($responseHeaders['status']);
        }

        $this->ensureStillRunning();

        return new HttpResponse($response->getBody(), $responseHeaders, $status ?? 200);
    }

    /**
     * @throws Exception If the PHP-FPM process is not running anymore.
     */
    private function ensureStillRunning(): void
    {
        if (! $this->fpm || ! $this->fpm->isRunning()) {
            throw new Exception('PHP-FPM has stopped for an unknown reason');
        }
    }

    private function waitUntilReady(): void
    {
        $wait = 5000; // 5ms
        $timeout = 5000000; // 5 secs
        $elapsed = 0;

        while (! $this->isReady()) {
            usleep($wait);
            $elapsed += $wait;

            if ($elapsed > $timeout) {
                throw new Exception('Timeout while waiting for PHP-FPM socket at ' . self::SOCKET);
            }

            // If the process has crashed we can stop immediately
            if (! $this->fpm->isRunning()) {
                throw new Exception('PHP-FPM failed to start: ' . PHP_EOL . $this->fpm->getOutput() . PHP_EOL . $this->fpm->getErrorOutput());
            }
        }
    }

    private function isReady(): bool
    {
        clearstatcache(false, self::SOCKET);

        return file_exists(self::SOCKET);
    }

    private function eventToFastCgiRequest(HttpRequestEvent $event, Context $context): ProvidesRequestData
    {
        $request = new FastCgiRequest($event->getMethod(), $this->handler, $event->getBody());
        $request->setRequestUri($event->getUri());
        $request->setRemoteAddress('127.0.0.1');
        $request->setRemotePort($event->getRemotePort());
        $request->setServerAddress('127.0.0.1');
        $request->setServerName($event->getServerName());
        $request->setServerProtocol($event->getProtocol());
        $request->setServerPort($event->getServerPort());
        $request->setCustomVar('PATH_INFO', $event->getPath());
        $request->setCustomVar('QUERY_STRING', $event->getQueryString());
        $request->setCustomVar('LAMBDA_INVOCATION_CONTEXT', json_encode($context));
        $request->setCustomVar('LAMBDA_REQUEST_CONTEXT', json_encode($event->getRequestContext()));

        $contentType = $event->getContentType();
        if ($contentType) {
            $request->setContentType($contentType);
        }
        foreach ($event->getHeaders() as $header => $values) {
            foreach ($values as $value) {
                $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
                $request->setCustomVar($key, $value);
            }
        }

        return $request;
    }

    /**
     * This methods makes sure to kill any existing PHP-FPM process.
     */
    private function killExistingFpm(): void
    {
        // Never seen this happen but just in case
        if (! file_exists(self::PID_FILE)) {
            unlink(self::SOCKET);
            return;
        }

        $pid = (int) file_get_contents(self::PID_FILE);

        // Never seen this happen but just in case
        if ($pid <= 0) {
            $this->logToCloudwatch("PHP-FPM's PID file contained an invalid PID, assuming PHP-FPM isn't running.");
            unlink(self::SOCKET);
            unlink(self::PID_FILE);
            return;
        }

        // Check if the process is running
        if (posix_getpgid($pid) === false) {
            // PHP-FPM is not running anymore, we can cleanup
            unlink(self::SOCKET);
            unlink(self::PID_FILE);
            return;
        }

        // The PID could be reused by our new process: let's not kill ourselves
        // See https://github.com/brefphp/bref/pull/645
        if ($pid === posix_getpid()) {
            unlink(self::SOCKET);
            unlink(self::PID_FILE);
            return;
        }

        $this->logToCloudwatch("PHP-FPM seems to be running already. This might be because Lambda stopped the bootstrap process but didn't leave us an opportunity to stop PHP-FPM (did Lambda timeout?). Stopping PHP-FPM now to restart from a blank slate.");

        // The previous PHP-FPM process is running, let's try to kill it properly
        $result = posix_kill($pid, self::SIGTERM);
        if ($result === false) {
            $this->logToCloudwatch("PHP-FPM's PID file contained a PID that doesn't exist, assuming PHP-FPM isn't running.");
            unlink(self::SOCKET);
            unlink(self::PID_FILE);
            return;
        }
        $this->waitUntilStopped($pid);
        unlink(self::SOCKET);
        unlink(self::PID_FILE);
    }

    /**
     * Wait until PHP-FPM has stopped.
     */
    private function waitUntilStopped(int $pid): void
    {
        $wait = 5000; // 5ms
        $timeout = 1000000; // 1 sec
        $elapsed = 0;
        while (posix_getpgid($pid) !== false) {
            usleep($wait);
            $elapsed += $wait;
            if ($elapsed > $timeout) {
                throw new Exception('Timeout while waiting for PHP-FPM to stop');
            }
        }
    }

    /**
     * Return an array of the response headers.
     */
    private function getResponseHeaders(ProvidesResponseData $response): array
    {
        return array_change_key_case($response->getHeaders(), CASE_LOWER);
    }

    /**
     * Log a message to Cloudwatch, this is specific for FPM related errors and will include AWS Request IDs for the
     * current Lambda invocation and API Gateway (where applicable) to aid debugging any FPM-related issues.
     */
    private function logToCloudwatch(string $message): void
    {
        echo trim($message) . ' ' . json_encode($this->getLogContext()) . PHP_EOL;
    }

    /**
     * Returns an array with AWS Request IDs for the current Lambda invocation and API Gateway (where applicable)
     *
     * @return string[]|array
     */
    private function getLogContext(): array
    {
        $logContext = [];

        if ($this->lambdaRequestId) {
            $logContext['requestId'] = $this->lambdaRequestId;
        }

        if ($this->apiGatewayRequestId) {
            $logContext['apiGatewayRequestId'] = $this->apiGatewayRequestId;
        }

        return $logContext;
    }
}
