---
title: Console commands
current_menu: console-applications
introduction: Learn how to run serverless console commands on AWS Lambda with Symfony Console or Laravel Artisan.
previous:
    link: /docs/websites.html
    title: Website assets
next:
    link: /docs/web-apps/cron.html
    title: Cron commands
---

Bref provides a way to run console commands on AWS Lambda.

This can be used to run PHP scripts, the [Symfony Console](https://symfony.com/doc/current/console.html), as well as [Laravel Artisan](https://laravel.com/docs/artisan) commands in production.

## Configuration

The lambda function used for running console applications must use two Lambda layers:

- the base PHP layer that provides the `php` binary,
- the `console` layer that overrides the base runtime to execute our console commands.

Below is a minimal `serverless.yml`. To create it automatically run `vendor/bin/bref init` and select "Console application".

```yaml
service: app
provider:
    name: aws
    runtime: provided.al2
plugins:
    - ./vendor/bref/bref
functions:
    hello:
        handler: bin/console # or 'artisan' for Laravel
        layers:
            - ${bref:layer.php-74} # PHP runtime
            - ${bref:layer.console} # Console layer
```

## Usage

To run a console command on AWS Lambda, run `bref cli` on your computer:

```bash
vendor/bin/bref cli <function-name> -- <command>
```

`<function-name>` is the name of the function that was define in `serverless.yml`. In our example above that would be `hello`.

Pass your command, arguments and options by putting them after `--`. The `--` delimiter separates between options for the `bref cli` command (before `--`) and your command (after `--`).

```bash
vendor/bin/bref cli hello <bref options> -- <your command, your options>
```

For example:

```bash
# Runs the CLI application without arguments and displays the help
$ vendor/bin/bref cli hello-dev
# ...

$ vendor/bin/bref cli hello -- doctrine:migrations:migrate
Your database will be migrated.
To execute the SQL queries run the command with the `--force` option.

$ vendor/bin/bref cli hello -- doctrine:migrations:migrate --force
Your database has been migrated.

# Use environment variables to configure your AWS credentials
$ AWS_DEFAULT_REGION=eu-central-1 AWS_ACCESS_KEY_ID=foo AWS_SECRET_ACCESS_KEY=bar vendor/bin/bref cli my-function
# ...
```

## Lambda context

Lambda provides information about the invocation, function, and execution environment via the *lambda context*.

This context is usually available as a parameter (alongside the event), within the defined handler.
However, within the console runtime we do not have direct access to this parameter.
To work around that, Bref puts the Lambda context in the `$_SERVER['LAMBDA_INVOCATION_CONTEXT']` variable as a JSON-encoded string.

```php
$lambdaContext = json_decode($_SERVER['LAMBDA_INVOCATION_CONTEXT'], true);
```
