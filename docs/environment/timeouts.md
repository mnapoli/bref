---
title: Timeouts
current_menu: timeouts
introduction: Configure and handle timeouts.
---

When a Lambda function times out, it is like the power to the computer is suddenly
just turned off. This does not give the application a chance to shut down properly.
This leaves you without any logs and the problem could be hard to fix.

To allow your application to shut down properly and write logs, Bref can throw an exception just before the Lambda times out.

> Note, this feature is experimental and available since Bref 1.3.

To enable this feature **in `php-XX` layers**, set the environment variable `BREF_FEATURE_TIMEOUT`:

```yaml
provider: 
    environment:
        BREF_FEATURE_TIMEOUT: 1
```

To enable this feature **in `php-XX-fpm` layers**, call `Timeout::enableInFpm()` in your application.
For example in `index.php`:

```php
if (isset($_SERVER['LAMBDA_TASK_ROOT'])) {
    \Bref\Timeout\Timeout::enableInFpm();
}
```

Whenever a timeout happens, a full stack trace will be logged, including the line that was executing.

In most cases, it is an external call to a database, cache or API that is stuck waiting.
If you are using a RDS database, [you are encouraged to read this section](database.md#accessing-the-internet).

## Catching the exception

You can catch the timeout exception to perform some cleanup, logs or even display a proper error page.

In `php-XX-fpm` layers, most frameworks will catch the `LambdaTimeout` exception automatically (like any other error).

In `php-XX` layers, you can catch it in your handlers. For example:

```php
use Bref\Context\Context;
use Bref\Timeout\LambdaTimeout;

class Handler implements \Bref\Event\Handler
{
    public function handle($event, Context $context)
    {
        try {
            // your code here
            // ...
        } catch (LambdaTimeout $e) {
            echo 'Oops, sorry. We spent too much time on this.';
        } catch (\Throwable $e) {
            echo 'Some other unexpected error happened.';
        }
    }
}
```
