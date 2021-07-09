<?php declare(strict_types=1);

namespace Bref\Timeout;

/**
 * Helper class to trigger an exception just before the Lambda times out. This
 * will give the application a chance to shut down.
 */
final class Timeout
{
    /** @var bool */
    private static $initialized = false;

    /** @var string|null */
    private static $stackTrace = null;

    /**
     * Automatically setup a timeout (based on the AWS Lambda timeout).
     *
     * This method can only be called when running in PHP-FPM, i.e. when using a `php-XX-fpm` layer.
     */
    public static function enableInFpm(): void
    {
        if (! isset($_SERVER['LAMBDA_INVOCATION_CONTEXT'])) {
            throw new \LogicException('Could not find value for bref timeout. Are we running on Lambda?');
        }

        $context = json_decode($_SERVER['LAMBDA_INVOCATION_CONTEXT'], true, 512, JSON_THROW_ON_ERROR);
        $deadlineMs = $context['deadlineMs'];
        $remainingTimeInMillis = $deadlineMs - intval(microtime(true) * 1000);

        self::enable($remainingTimeInMillis);
    }

    /**
     * @internal
     */
    public static function enable(int $remainingTimeInMillis): void
    {
        self::init();

        $remainingTimeInSeconds = (int) floor($remainingTimeInMillis / 1000);

        // The script will timeout 2 seconds before the remaining time
        // to allow some time for Bref/our app to recover and cleanup
        $margin = 2;

        $timeoutDelayInSeconds = max(1, $remainingTimeInSeconds - $margin);

        // Trigger SIGALRM in X seconds
        pcntl_alarm($timeoutDelayInSeconds);
    }

    /**
     * Setup custom handler for SIGALRM.
     */
    private static function init(): void
    {
        self::$stackTrace = null;

        if (self::$initialized) {
            return;
        }

        if (! function_exists('pcntl_async_signals')) {
            trigger_error('Could not enable timeout exceptions because pcntl extension is not enabled.');
            return;
        }

        pcntl_async_signals(true);
        // Setup a handler for SIGALRM that throws an exception
        // This will interrupt any running PHP code, including `sleep()` or code stuck waiting for I/O.
        pcntl_signal(SIGALRM, function (): void {
            if (Timeout::$stackTrace !== null) {
                // we already thrown an exception, do a harder exit.
                error_log('Lambda timed out');
                error_log((new LambdaTimeout)->getTraceAsString());
                error_log('Original stack trace');
                error_log(Timeout::$stackTrace);

                exit(1);
            }

            $exception = new LambdaTimeout('Maximum AWS Lambda execution time reached');
            Timeout::$stackTrace = $exception->getTraceAsString();

            // Trigger another alarm after 1 second to do a hard exit.
            pcntl_alarm(1);

            throw $exception;
        });

        self::$initialized = true;
    }

    /**
     * Cancel all current timeouts.
     *
     * @internal
     */
    public static function reset(): void
    {
        if (self::$initialized) {
            pcntl_alarm(0);
            self::$stackTrace = null;
        }
    }
}
