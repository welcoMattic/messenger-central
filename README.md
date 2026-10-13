# Messenger Central

A terminal UI to drive [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
workers while you develop: list the transports of your application, start and
scale their workers, and watch messages flow in real time.

Built on top of the [Symfony TUI component](https://symfony.com/doc/current/tui.html).

```
 Messenger Central  ·  9 transports  ·  8 workers running
────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
▸ emails ●3 (28)             │ emails  ● 3 workers running
▸ reports (327)              │ doctrine://default?queue_name=emails
▸ images ●2 (115)            │
▸ urgent ●1 (15)             │ Incoming                                     ▂▄▅▆▅▃▃▅▅▅▁█▃▄  158.4 msg/s  2069 received
▸ inventory ●2 (0)           │
▸ notifications (476)        │ Workers
▸ failed (26)                │  ⠇ #1   pid 570    16.0 MB  ✓ 623 ↻ 10  App\Message\SendEmail  8.4 ms
▸ inventory_failed (0)       │  ⠇ #2   pid 571    16.0 MB  ✓ 808 ↻ 13  App\Message\SendEmail  3.2 ms
▸ sync                       │  ⠇ #3   pid 572    16.0 MB  ✓ 598 ↻ 16  App\Message\SendEmail #10031  12.8 ms
                             │
                             │ Queue  28 waiting
                             │   · App\Message\SendEmail #9780
                             │   · App\Message\SendEmail #9912
                             │
                             │ Recent
                             │  22:12:55 ✓  App\Message\SendEmail  5.0 ms  #3
                             │  22:12:55 ✓  App\Message\SendEmail  10.7 ms  #3
                             │  22:12:55 ✓  App\Message\SendEmail  5.9 ms  #3
────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
 emails  ✓ 2029 processed  ✗ 0 failed  ↻ 39 retried  158.4 msg/s  memory 48.0 MB, worker peak 16.0 MB  up 16s
 ↑↓ move  ⏎ open  s start/stop  +/- scale  a activity  m messages  o options  q quit
```

> [!WARNING]
> This is a development tool, and the Symfony TUI component it relies on is
> [experimental](https://symfony.com/doc/current/contributing/code/experimental.html).
> Do not enable the bundle in production. Version 0.1 is tested with Symfony 8.1.

## Features

- **Transports** in a sidebar, with their running workers and waiting messages.
  Open a transport to reach its actions:
  - **Message classes** routed to it, from the routing configuration and the
    `#[AsMessage]` attribute of handled messages;
  - **Start workers**, as many as you want (one by default);
  - **Options**: DSN, transport options, retry strategy, failure transport,
    serializer. Credentials are masked.
- **Live view** of the selected transport:
  - incoming messages per second, as a sparkline;
  - each worker, with the message it handles and for how long;
  - the messages waiting in the transport, when it can tell;
  - the last handled, retried and failed messages, with their error.
- **Stats** in the footer: processed, failed and retried messages, throughput,
  memory used by the workers and their peak.

## Requirements

- PHP 8.4.1 or higher; with the `pcntl` extension, workers finish their current message when stopped
- Symfony 8.1 or higher, with Messenger enabled
- A Unix-like system (Linux, macOS, WSL)

## Installation

```shell
composer require --dev welcomattic/messenger-central
```

Enable the bundle in the `dev` environment only, in `config/bundles.php`:

```php
return [
    // ...
    Welcomattic\MessengerCentral\MessengerCentralBundle::class => ['dev' => true],
];
```

## Usage

```shell
bin/console messenger:central
```

| Key              | Action                                                     |
|------------------|------------------------------------------------------------|
| `↑` `↓`          | Move in the sidebar                                        |
| `⏎`              | Open a transport, or run the action under the cursor      |
| `s`              | Start or stop the workers of the selected transport        |
| `+` `-`          | Scale the workers up or down (before or after starting)    |
| `a`, `Esc`       | Live view                                                  |
| `m`              | Message classes                                            |
| `o`              | Options                                                    |
| `q`, `Ctrl+C`    | Quit, stopping every worker                                |

## How it works

Workers are regular `messenger:consume <transport>` processes, started through
your application console. Each one runs with the
`MESSENGER_CENTRAL_WORKER` environment variable, which turns on a listener of
the worker events: it writes one JSON line per event (message received,
handled, failed, heartbeat...) on the standard output, read by the dashboard.
Outside the dashboard, the listener does nothing.

Workers also get a unique `MESSENGER_CONSUMER_NAME` environment variable. Use it
as the consumer name of Redis transports, as the
[Symfony documentation](https://symfony.com/doc/current/messenger.html#redis-transport)
recommends, so that scaled workers do not share their pending messages:

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    consumer: '%env(MESSENGER_CONSUMER_NAME)%'
```

Waiting messages are counted by the dashboard itself, for transports that
implement `MessageCountAwareInterface` (Doctrine, Redis, AMQP, SQS, Beanstalkd),
and listed for those implementing `ListableReceiverInterface` (Doctrine).

When you quit, workers get `SIGTERM` and finish their current message.

## Configuration

Everything is optional:

```yaml
# config/packages/dev/messenger_central.yaml
messenger_central:
    # The console used to start the workers
    console_path: '%kernel.project_dir%/bin/console'
    # Extra options given to messenger:consume
    consume_options: ['--time-limit=3600', '--memory-limit=256M']
```

## Limitations

- Only the workers started by the dashboard are shown.
- In-memory transports live in the memory of each process: the dashboard cannot
  see their messages.
- Message classes are found from the routing configuration and from the
  messages that have a handler. A message routed with `#[AsMessage]` but
  handled by another application is not listed.

## Contributing

```shell
composer install
composer qa   # mago fmt --dry-run, mago lint, mago analyze, phpunit
```

## License

MIT, see [LICENSE](LICENSE).
