<?php declare(strict_types=1);

namespace Bref\Timeout;

/**
 * The application took too long to produce a response. This exception is thrown
 * to give the application a chance to flush logs and shut it self down before
 * the power to AWS Lambda is disconnected.
 */
class LambdaTimeout extends \RuntimeException
{
}
