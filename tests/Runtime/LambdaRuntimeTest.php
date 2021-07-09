<?php declare(strict_types=1);

namespace Bref\Test\Runtime;

use Bref\Context\Context;
use Bref\Event\EventBridge\EventBridgeEvent;
use Bref\Event\EventBridge\EventBridgeHandler;
use Bref\Event\Handler;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\S3\S3Event;
use Bref\Event\S3\S3Handler;
use Bref\Event\Sns\SnsEvent;
use Bref\Event\Sns\SnsHandler;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Bref\Runtime\LambdaRuntime;
use Bref\Test\Server;
use Bref\Timeout\LambdaTimeout;
use Bref\Timeout\Timeout;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Tests the communication between `LambdaRuntime` and the Lambda Runtime HTTP API.
 *
 * The API is mocked using a fake HTTP server.
 */
class LambdaRuntimeTest extends TestCase
{
    /** @var LambdaRuntime */
    private $runtime;

    protected function setUp(): void
    {
        ob_start();
        Server::start();
        // Mock the API with our test server
        putenv('AWS_LAMBDA_RUNTIME_API=localhost:8126');
        $this->runtime = LambdaRuntime::fromEnvironmentVariable();
    }

    protected function tearDown(): void
    {
        Server::stop();
        ob_end_clean();
        // Cleanup
        putenv('AWS_LAMBDA_RUNTIME_API=');
        putenv('BREF_FEATURE_TIMEOUT=');
    }

    public function test Lambda timeouts can be anticipated()
    {
        // Re-create the runtime class with timeout prevention enabled
        putenv('BREF_FEATURE_TIMEOUT=1');
        $this->runtime = LambdaRuntime::fromEnvironmentVariable();

        $this->givenAnEvent([]);

        $start = microtime(true);
        $this->runtime->processNextEvent(function () {
            // This 10s sleep should be interrupted
            sleep(10);
        });

        $elapsedTime = microtime(true) - $start;
        // The Lambda timeout was 2 seconds, we expect the Bref timeout to trigger 1 second before that: 1 second
        $this->assertEqualsWithDelta(1, $elapsedTime, 0.2);
        Timeout::reset();

        $this->assertInvocationErrorResult(LambdaTimeout::class, 'Maximum AWS Lambda execution time reached');
        $this->assertErrorInLogs(LambdaTimeout::class, 'Maximum AWS Lambda execution time reached');
    }

    public function test basic behavior()
    {
        $this->givenAnEvent(['Hello' => 'world!']);

        $this->runtime->processNextEvent(function () {
            return ['hello' => 'world'];
        });

        $this->assertInvocationResult(['hello' => 'world']);
    }

    public function test handler receives context()
    {
        $this->givenAnEvent(['Hello' => 'world!']);

        $this->runtime->processNextEvent(function (array $event, Context $context) {
            return ['hello' => 'world', 'received-function-arn' => $context->getInvokedFunctionArn()];
        });

        $this->assertInvocationResult([
            'hello' => 'world',
            'received-function-arn' => 'test-function-name',
        ]);
    }

    public function test exceptions in the handler result in an invocation error()
    {
        $this->givenAnEvent(['Hello' => 'world!']);

        $this->runtime->processNextEvent(function () {
            throw new \RuntimeException('This is an exception');
        });

        $this->assertInvocationErrorResult('RuntimeException', 'This is an exception');
        $this->assertErrorInLogs('RuntimeException', 'This is an exception');
    }

    public function test nested exceptions in the handler result in an invocation error()
    {
        $this->givenAnEvent(['Hello' => 'world!']);

        $this->runtime->processNextEvent(function () {
            throw new \RuntimeException('This is an exception', 0, new \RuntimeException('The previous exception.', 0, new \Exception('The original exception.')));
        });

        $this->assertInvocationErrorResult('RuntimeException', 'This is an exception');
        $this->assertErrorInLogs('RuntimeException', 'This is an exception');
        $this->assertPreviousErrorsInLogs([
            ['errorClass' => 'RuntimeException', 'errorMessage' => 'The previous exception.'],
            ['errorClass' => 'Exception', 'errorMessage' => 'The original exception.'],
        ]);
    }

    public function test an error is thrown if the runtime API returns a wrong response()
    {
        $this->expectExceptionMessage('Failed to fetch next Lambda invocation: The requested URL returned error: 404 Not Found');
        Server::enqueue([
            new Response( // lambda event
                404, // 404 instead of 200
                [
                    'lambda-runtime-aws-request-id' => 1,
                ],
                '{ "Hello": "world!"}'
            ),
        ]);

        $this->runtime->processNextEvent(function ($event) {
        });
    }

    public function test an error is thrown if the invocation id is missing()
    {
        $this->expectExceptionMessage('Failed to determine the Lambda invocation ID');
        Server::enqueue([
            new Response( // lambda event
                200,
                [], // Missing `lambda-runtime-aws-request-id`
                '{ "Hello": "world!"}'
            ),
        ]);

        $this->runtime->processNextEvent(function () {
        });
    }

    public function test an error is thrown if the invocation body is empty()
    {
        $this->expectExceptionMessage('Empty Lambda runtime API response');
        Server::enqueue([
            new Response( // lambda event
                200,
                [
                    'lambda-runtime-aws-request-id' => 1,
                ]
            ),
        ]);

        $this->runtime->processNextEvent(function () {
        });
    }

    public function test a wrong response from the runtime API turns the invocation into an error()
    {
        Server::enqueue([
            new Response( // lambda event
                200,
                [
                    'lambda-runtime-aws-request-id' => 1,
                ],
                '{ "Hello": "world!"}'
            ),
            new Response(400), // The Lambda API returns a 400 instead of a 200
            new Response(200),
        ]);

        $this->runtime->processNextEvent(function ($event) {
            return $event;
        });
        $requests = Server::received();
        $this->assertCount(3, $requests);

        [$eventRequest, $eventFailureResponse, $eventFailureLog] = $requests;
        $this->assertSame('GET', $eventRequest->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/next', $eventRequest->getUri()->__toString());
        $this->assertSame('POST', $eventFailureResponse->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/1/response', $eventFailureResponse->getUri()->__toString());
        $this->assertSame('POST', $eventFailureLog->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/1/error', $eventFailureLog->getUri()->__toString());

        // Check the lambda result contains the error message
        $error = json_decode((string) $eventFailureLog->getBody(), true);
        $this->assertSame('Error while calling the Lambda runtime API: The requested URL returned error: 400 Bad Request', $error['errorMessage']);

        $this->assertErrorInLogs('Exception', 'Error while calling the Lambda runtime API: The requested URL returned error: 400 Bad Request');
    }

    public function test function results that cannot be encoded are reported as invocation errors()
    {
        $this->givenAnEvent(['hello' => 'world!']);

        $this->runtime->processNextEvent(function () {
            return "\xB1\x31";
        });

        $message = <<<ERROR
The Lambda response cannot be encoded to JSON.
This error usually happens when you try to return binary content. If you are writing an HTTP application and you want to return a binary HTTP response (like an image, a PDF, etc.), please read this guide: https://bref.sh/docs/runtimes/http.html#binary-responses
Here is the original JSON error: 'Malformed UTF-8 characters, possibly incorrectly encoded'
ERROR;
        $this->assertInvocationErrorResult('Exception', $message);
        $this->assertErrorInLogs('Exception', $message);
    }

    public function test generic event handler()
    {
        $handler = new class() implements Handler {
            /**
             * @param mixed $event
             * @return mixed
             */
            public function handle($event, Context $context)
            {
                return $event;
            }
        };

        $this->givenAnEvent(['foo' => 'bar']);

        $this->runtime->processNextEvent($handler);

        $this->assertInvocationResult(['foo' => 'bar']);
    }

    public function test SQS event handler()
    {
        $handler = new class() extends SqsHandler {
            /** @var SqsEvent */
            public $event;
            public function handleSqs(SqsEvent $event, Context $context): void
            {
                $this->event = $event;
            }
        };

        $eventData = json_decode(file_get_contents(__DIR__ . '/../Event/Sqs/sqs.json'), true);
        $this->givenAnEvent($eventData);

        $this->runtime->processNextEvent($handler);

        $this->assertEquals(new SqsEvent($eventData), $handler->event);
    }

    public function test SNS event handler()
    {
        $handler = new class() extends SnsHandler {
            /** @var SnsEvent */
            public $event;
            public function handleSns(SnsEvent $event, Context $context): void
            {
                $this->event = $event;
            }
        };

        $eventData = json_decode(file_get_contents(__DIR__ . '/../Event/Sns/sns.json'), true);
        $this->givenAnEvent($eventData);

        $this->runtime->processNextEvent($handler);

        $this->assertEquals(new SnsEvent($eventData), $handler->event);
    }

    public function test S3 event handler()
    {
        $handler = new class() extends S3Handler {
            /** @var S3Event */
            public $event;
            public function handleS3(S3Event $event, Context $context): void
            {
                $this->event = $event;
            }
        };

        $eventData = json_decode(file_get_contents(__DIR__ . '/../Event/S3/s3.json'), true);
        $this->givenAnEvent($eventData);

        $this->runtime->processNextEvent($handler);

        $this->assertEquals(new S3Event($eventData), $handler->event);
    }

    public function test PSR15 event handler()
    {
        $handler = new class() implements RequestHandlerInterface {
            /** @var ServerRequestInterface */
            public $request;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new Response(200, [
                    'Content-Type' => 'text/html',
                ], 'Hello world!');
            }
        };

        $eventData = json_decode(file_get_contents(__DIR__ . '/../Event/Http/Fixture/ag-v1-simple.json'), true);
        $this->givenAnEvent($eventData);

        $this->runtime->processNextEvent($handler);

        $this->assertEquals('GET', $handler->request->getMethod());
        $this->assertEquals('/path', (string) $handler->request->getUri());
        $this->assertInstanceOf(HttpRequestEvent::class, $handler->request->getAttribute('lambda-event'));
        $this->assertInstanceOf(Context::class, $handler->request->getAttribute('lambda-context'));
        $this->assertInvocationResult([
            'isBase64Encoded' => false,
            'statusCode' => 200,
            'headers' => [
                'Content-Type' => 'text/html',
            ],
            'body' => 'Hello world!',
        ]);
    }

    public function test EventBridge event handler()
    {
        $handler = new class() extends EventBridgeHandler {
            /** @var EventBridgeEvent */
            public $event;
            public function handleEventBridge(EventBridgeEvent $event, Context $context): void
            {
                $this->event = $event;
            }
        };

        $eventData = json_decode(file_get_contents(__DIR__ . '/../Event/EventBridge/eventbridge.json'), true);
        $this->givenAnEvent($eventData);

        $this->runtime->processNextEvent($handler);

        $this->assertEquals(new EventBridgeEvent($eventData), $handler->event);
    }

    public function test invalid handlers are rejected properly()
    {
        $eventData = json_decode(file_get_contents(__DIR__ . '/../Event/Http/Fixture/ag-v1-simple.json'), true);
        $this->givenAnEvent($eventData);

        $this->runtime->processNextEvent(null);

        $this->assertInvocationErrorResult('Exception', 'The lambda handler must be a callable or implement handler interfaces');
        $this->assertErrorInLogs('Exception', 'The lambda handler must be a callable or implement handler interfaces');
    }

    /**
     * @param mixed $event
     */
    private function givenAnEvent($event): void
    {
        Server::enqueue([
            new Response( // lambda event
                200,
                [
                    'lambda-runtime-aws-request-id' => '1',
                    'lambda-runtime-invoked-function-arn' => 'test-function-name',
                    // now + 2 seconds
                    'lambda-runtime-deadline-ms' => intval((microtime(true) + 2) * 1000),
                ],
                json_encode($event)
            ),
            new Response(200), // lambda response accepted
        ]);
    }

    /**
     * @param mixed $result
     */
    private function assertInvocationResult($result)
    {
        $requests = Server::received();
        $this->assertCount(2, $requests);

        [$eventRequest, $eventResponse] = $requests;
        $this->assertSame('GET', $eventRequest->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/next', $eventRequest->getUri()->__toString());
        $this->assertSame('POST', $eventResponse->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/1/response', $eventResponse->getUri()->__toString());
        $this->assertEquals($result, json_decode($eventResponse->getBody()->__toString(), true));
    }

    private function assertInvocationErrorResult(string $errorClass, string $errorMessage)
    {
        $requests = Server::received();
        $this->assertCount(2, $requests);

        [$eventRequest, $eventResponse] = $requests;
        $this->assertSame('GET', $eventRequest->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/next', $eventRequest->getUri()->__toString());
        $this->assertSame('POST', $eventResponse->getMethod());
        $this->assertSame('http://localhost:8126/2018-06-01/runtime/invocation/1/error', $eventResponse->getUri()->__toString());

        // Check the content of the result of the lambda
        $invocationResult = json_decode($eventResponse->getBody()->__toString(), true);
        $this->assertSame([
            'errorType',
            'errorMessage',
            'stackTrace',
        ], array_keys($invocationResult));
        $this->assertEquals($errorClass, $invocationResult['errorType']);
        $this->assertEquals($errorMessage, $invocationResult['errorMessage']);
        $this->assertIsArray($invocationResult['stackTrace']);
    }

    private function assertErrorInLogs(string $errorClass, string $errorMessage): void
    {
        // Decode the logs from stdout
        $stdout = $this->getActualOutput();

        [$requestId, $message, $json] = explode("\t", $stdout);

        $this->assertSame('Invoke Error', $message);

        // Check the request ID matches a UUID
        $this->assertNotEmpty($requestId);

        $invocationResult = json_decode($json, true);
        unset($invocationResult['previous']);
        $this->assertSame([
            'errorType',
            'errorMessage',
            'stack',
        ], array_keys($invocationResult));
        $this->assertEquals($errorClass, $invocationResult['errorType']);
        $this->assertEquals($errorMessage, $invocationResult['errorMessage']);
        $this->assertIsArray($invocationResult['stack']);
    }

    private function assertPreviousErrorsInLogs(array $previousErrors)
    {
        // Decode the logs from stdout
        $stdout = $this->getActualOutput();

        [, , $json] = explode("\t", $stdout);

        ['previous' => $previous] = json_decode($json, true);
        $this->assertCount(count($previousErrors), $previous);
        foreach ($previous as $index => $error) {
            $this->assertSame([
                'errorType',
                'errorMessage',
                'stack',
            ], array_keys($error));
            $this->assertEquals($previousErrors[$index]['errorClass'], $error['errorType']);
            $this->assertEquals($previousErrors[$index]['errorMessage'], $error['errorMessage']);
            $this->assertIsArray($error['stack']);
        }
    }
}
