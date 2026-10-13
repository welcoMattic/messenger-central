# Messenger Central

A terminal UI to drive [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
workers while you develop: list the transports of your application, start and
scale their workers, watch messages flow in real time, and send failed messages
back once fixed.

Built on top of the [Symfony TUI component](https://symfony.com/doc/current/tui.html).

If it saves you time, [sponsor my open source work](https://github.com/sponsors/welcoMattic).

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
> Do not enable the bundle in production. It is tested with Symfony 8.1, and
> with the development version of Symfony 8.2.

## Features

- **Transports** in a sidebar, with their running workers and waiting messages.
  Open a transport to reach its actions:
  - **Message classes** routed to it, from the routing configuration and the
    `#[AsMessage]` attribute of handled messages;
  - **Start workers**, as many as you want (one by default);
  - **Concurrency**: how many messages each worker handles at the same time,
    with Symfony 8.2;
  - **Options**: DSN, transport options, retry strategy, failure transport,
    serializer. Credentials are masked.
- **Live view** of the selected transport:
  - incoming messages per second, as a sparkline;
  - each worker, with the messages it handles and for how long, or why it
    exited;
  - the messages waiting in the transport, when it can tell;
  - the last handled, retried and failed messages, with their error.
- **Failed messages**: workers do not consume failure transports. Open one to
  list its messages, then edit a message and send it back to the transport it
  failed on.
- **Stats** in the footer: processed, failed and retried messages, throughput,
  memory used by the workers and their peak.

## Requirements

- PHP 8.4.1 or higher; with the `pcntl` extension, workers finish their current messages when stopped
- Symfony 8.1 or higher, with Messenger enabled; Symfony 8.2 and
  [amphp/parallel](https://github.com/amphp/parallel) to handle several
  messages at the same time in each worker
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
| `⏎`              | Open a transport, or run the action under the cursor       |
| `s`              | Start or stop the workers of the selected transport        |
| `+` `-`          | Scale the workers up or down (before or after starting)    |
| `c`              | Type the concurrency of the workers (Symfony 8.2)          |
| `a`, `Esc`       | Live view                                                  |
| `m`              | Message classes                                            |
| `o`              | Options                                                    |
| `q`, `Ctrl+C`    | Quit, stopping every worker                                |
| `r`              | Send the failed message under the cursor back, as is       |
| `Ctrl+S`         | Send the failed message being edited back to its transport |
| `Esc`            | Leave the failed message being edited, without sending it  |

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

Give the variable a default value in `.env`, for the processes the dashboard
does not start (a worker started by hand, a web request...):

```shell
MESSENGER_CONSUMER_NAME=consumer
```

Waiting messages are counted by the dashboard itself, for transports that
implement `MessageCountAwareInterface` (Doctrine, Redis, AMQP, SQS, Beanstalkd),
and listed for those implementing `ListableReceiverInterface` (Doctrine).

When you stop workers or quit, they get `SIGTERM` and finish their current
messages; the ones still running after the stop timeout (10 seconds by default)
are killed. If the dashboard itself is killed, its workers notice it and stop
after their current messages.

### Failed messages

Workers cannot be started on a failure transport. Open it instead: its failed
messages (the first 20) are listed under it, with the transport they failed on
and their last error. Press `r` on one to send it back as is to the transport
it failed on, which removes it from the failure transport. Press `⏎` to edit it
first: its properties show up as a JSON document, `Ctrl+S` sends the message
back with your changes, `Esc` cancels.

```json
{
    "email": "jane@example.com",
    "status": "pending",
    "placedAt": "2026-10-13T10:42:01.000000+02:00",
    "address": {
        "street": "1 rue de la Paix",
        "city": "Paris"
    }
}
```

- The document does not depend on the serializer of the transport: it holds
  the properties of the message, private and read-only ones included, and of
  the objects it contains. Enums are written with their value (or the name of
  their case), dates as strings.
- Values that JSON cannot hold, like closures or instances of PHP classes
  other than dates, are left out and kept as they are. A message that is itself
  an instance of a PHP class, like a message that could not be decoded, cannot
  be edited.
- The message keeps its stamps (bus, retries and errors so far), but not the
  ones about its stay in the failure transport, so that it goes back there if
  it fails again.
- Listing and editing failed messages needs a failure transport that lists its
  messages (`ListableReceiverInterface`, like Doctrine).

### Concurrency

With Symfony 8.2 and `amphp/parallel` installed, press `c` to type how many
messages each worker of a transport handles at the same time: the dashboard
starts its workers with `messenger:consume --concurrency=N`. Changing it
restarts the running workers. A transport has at most 32 messages handled at
the same time (workers × concurrency), as each one is a process that boots
your application.

The child processes that handle the messages are started by Symfony through
the console of your application (`bin/console`), whatever the `console_path`
of the dashboard. The memory shown is the one of the workers, without their
child processes.

## Configuration

Everything is optional:

```yaml
# config/packages/dev/messenger_central.yaml
messenger_central:
    # The console used to start the workers
    console_path: '%kernel.project_dir%/bin/console'
    # Extra options given to messenger:consume (but --concurrency, see below)
    consume_options: ['--time-limit=3600', '--memory-limit=256M']
    # Messages handled at the same time by each worker, Symfony 8.2 only
    concurrency: 1
    # Seconds given to the workers to finish their messages when stopped
    stop_timeout: 10
```

## Limitations

- Only the workers started by the dashboard are shown.
- In-memory transports live in the memory of each process: the dashboard cannot
  see their messages.
- Failed messages are sent back to their transport without going through the
  bus: its middlewares do not run until a worker handles the message.
- Message classes are found from the routing configuration and from the
  messages that have a handler. A message routed with `#[AsMessage]` but
  handled by another application is not listed.

## Contributing

```shell
composer install
composer qa   # mago fmt --dry-run, mago lint, mago analyze, phpunit
```

Configuration trees are indented by nesting level, which the formatter would
flatten: a project rule of `mago lint` (in `tools/mago`) checks it, and
`mago lint --fix` fixes it. The builder methods that open a level are read from
the installed Symfony Config component, so new node types need no change.

## Sponsors

Messenger Central is free and open source. Sponsoring funds my open source
work, with a goal of two full working days per month on Symfony:
[become a sponsor](https://github.com/sponsors/welcoMattic).

Companies at the
[Company tier](https://github.com/sponsors/welcoMattic/sponsorships?tier_id=662811)
($100 a month) or above get their logo and a link here. Sponsors at the
[Developer tier](https://github.com/sponsors/welcoMattic/sponsorships?tier_id=662810)
($25 a month) or above are listed in [SPONSORS.md](SPONSORS.md).

<!-- Logos of the Company tier sponsors and above, linked with rel="sponsored" -->

## License

MIT, see [LICENSE](LICENSE).
