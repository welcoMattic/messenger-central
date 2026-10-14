# Changelog

## 0.1.0 (2026-10-14)

First release: a terminal UI, `bin/console messenger:central`, to drive the
Messenger workers of a Symfony application while you develop.

- **Transports** in a sidebar, with their running workers and waiting messages,
  the message classes routed to them and their options (credentials masked).
- **Workers** started, stopped and scaled from the keyboard. They are regular
  `messenger:consume` processes; the ones that stop by themselves are started
  again at once, the ones that crash after a growing delay.
- **Live view**: incoming rate, the messages each worker handles and for how
  long, the waiting messages, the last handled, retried and failed messages.
- **Failed messages**: paged list, errors in full with the stack trace, removal,
  and sending back to the transport they failed on, as is or after editing
  their properties as a JSON document.
- **Output of the workers** (logs, `dump()`), stats in the footer, and every
  key under `?`.
- **Concurrency** of Symfony 8.2: several messages handled at the same time by
  each worker, with `amphp/parallel`.
- A unique `MESSENGER_CONSUMER_NAME` for each worker, for the `consumer` option
  of Redis transports.

Requirements: PHP 8.4.1 or higher, Symfony 8.1 or higher with Messenger, and a
Unix-like system (Linux, macOS, WSL). The `pcntl` extension lets workers finish
their current messages when stopped. The command refuses to run when the kernel
runs without debug, unless `--force` is given.
