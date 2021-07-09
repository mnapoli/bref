<?php declare(strict_types=1);

namespace Bref\Test\Timeout;

use Bref\Timeout\LambdaTimeout;
use Bref\Timeout\Timeout;
use PHPUnit\Framework\TestCase;

class TimeoutTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            self::markTestSkipped('PCNTL extension is not enabled.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['LAMBDA_INVOCATION_CONTEXT']);
    }

    protected function tearDown(): void
    {
        Timeout::reset();
        parent::tearDown();
    }

    public function test enable()
    {
        Timeout::enable(3000);
        $timeout = pcntl_alarm(0);
        // 1 second (2 seconds shorter than the 3s remaining time)
        $this->assertSame(1, $timeout);
    }

    public function test enable in FPM()
    {
        $_SERVER['LAMBDA_INVOCATION_CONTEXT'] = json_encode(['deadlineMs' => (time() + 30) * 1000]);

        Timeout::enableInFpm();
        $timeout = pcntl_alarm(0);
        $this->assertEqualsWithDelta(28, $timeout, 1);
    }

    public function test enable in FPM requires the context()
    {
        $this->expectException(\LogicException::class);
        Timeout::enableInFpm();
    }

    public function test timeouts are interrupted in time()
    {
        $start = microtime(true);
        Timeout::enable(3000);
        try {
            sleep(4);
            $this->fail('We expect a LambdaTimeout before we reach this line');
        } catch (LambdaTimeout $e) {
            $time = 1000 * (microtime(true) - $start);
            $this->assertEqualsWithDelta(1000, $time, 200, 'We must wait about 1 second');
            Timeout::reset();
        } catch (\Throwable $e) {
            $this->fail('It must throw a LambdaTimeout.');
        }
    }
}
