<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui;

use Closure;
use DateTimeZone;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Throwable;
use Welcomattic\MessengerCentral\Failure\FailedMessage;
use Welcomattic\MessengerCentral\Failure\FailedMessageException;
use Welcomattic\MessengerCentral\Failure\FailedMessages;
use Welcomattic\MessengerCentral\Transport\QueueProbe;
use Welcomattic\MessengerCentral\Transport\QueueSnapshot;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;
use Welcomattic\MessengerCentral\Transport\TransportDefinition;
use Welcomattic\MessengerCentral\Tui\View\ActivityView;
use Welcomattic\MessengerCentral\Tui\View\FailedMessagesView;
use Welcomattic\MessengerCentral\Tui\View\FailedMessageView;
use Welcomattic\MessengerCentral\Tui\View\HelpView;
use Welcomattic\MessengerCentral\Tui\View\LogsView;
use Welcomattic\MessengerCentral\Tui\View\MessagesView;
use Welcomattic\MessengerCentral\Tui\View\OptionsView;
use Welcomattic\MessengerCentral\Tui\Widget\CanvasWidget;
use Welcomattic\MessengerCentral\Tui\Widget\MenuWidget;
use Welcomattic\MessengerCentral\Worker\ParallelExecution;
use Welcomattic\MessengerCentral\Worker\WorkerProcess;
use Welcomattic\MessengerCentral\Worker\WorkerStatus;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

use function array_slice;
use function array_values;
use function bin2hex;
use function ceil;
use function constant;
use function count;
use function ctype_digit;
use function defined;
use function explode;
use function hex2bin;
use function intdiv;
use function max;
use function min;
use function preg_replace;
use function sprintf;
use function strlen;
use function substr;

/**
 * The terminal UI: transports on the left, the selected one on the right.
 */
final class Dashboard
{
    public const string VIEW_ACTIVITY = 'activity';
    public const string VIEW_MESSAGES = 'messages';
    public const string VIEW_OPTIONS = 'options';
    public const string VIEW_LOGS = 'logs';

    public const string PARALLEL_REQUIREMENTS = 'Symfony 8.2 and amphp/parallel';

    private const float TICK_INTERVAL = 0.1;
    private const float SELECTED_QUEUE_INTERVAL = 1.0;
    private const float OTHER_QUEUES_INTERVAL = 3.0;
    private const float MAX_QUEUE_BACKOFF = 30.0;
    private const int QUEUE_PEEK_LIMIT = 5;
    private const int FAILED_LIST_LIMIT = 20;
    private const float NOTICE_SECONDS = 5.0;

    // Workers × concurrency: each slot is a process that boots the application
    private const int MAX_SLOTS = 32;

    private readonly Tui $tui;
    private readonly MenuWidget $menu;
    private readonly CanvasWidget $header;
    private readonly CanvasWidget $body;
    private readonly CanvasWidget $footer;
    private readonly ContainerWidget $main;
    private readonly EditorWidget $editor;
    private readonly Keybindings $keybindings;
    private readonly Keybindings $promptKeybindings;
    private readonly Keybindings $editorKeybindings;
    private readonly Keybindings $confirmKeybindings;
    private readonly float $startedAt;
    private readonly bool $parallel;
    private readonly int $defaultConcurrency;
    private readonly DateTimeZone $timezone;

    private ?string $selected;
    private ?string $expanded = null;

    // The id of the failed message under the cursor
    private ?string $selectedMessage = null;

    /** @var array{transport: string, id: string, message: FailedMessage|null, error: string|null}|null */
    private ?array $failedMessage = null;

    /** @var array{transport: string, id: string, class: string, from: string, error: string|null}|null the failed message being edited */
    private ?array $editing = null;

    /** @var array<string, string> */
    private array $views = [];

    /** @var array<string, int> */
    private array $scales = [];

    /** @var array<string, int> */
    private array $concurrencies = [];

    /** @var array<string, QueueSnapshot> */
    private array $queues = [];

    /** @var array<string, int> transports that failed to tell their queue, with the number of failures in a row */
    private array $queueFailures = [];

    /** @var array<string, float> when to inspect again a transport that failed */
    private array $queueRetryAt = [];

    private float $selectedQueueAt = 0.0;
    private float $otherQueuesAt = 0.0;
    private ?string $notice = null;
    private float $noticeAt = 0.0;

    /** @var array{transport: string, value: string}|null the concurrency being typed */
    private ?array $prompt = null;

    /** @var array{question: string, action: Closure(): void, quit: bool}|null an action waiting to be confirmed */
    private ?array $confirmation = null;

    // When quitting started: the dashboard waits for its workers to stop
    private ?float $quittingAt = null;

    /** @var array<string, int> the page of failed messages shown, per failure transport, from 0 */
    private array $failedPages = [];

    // Whether the selected failed message shows its errors instead of its properties
    private bool $showErrors = false;

    private bool $help = false;

    /**
     * @param int       $concurrency default number of messages a worker handles at the same time
     * @param bool|null $parallel    whether "--concurrency" is supported, detected when null
     */
    public function __construct(
        private readonly TransportCatalog $catalog,
        private readonly WorkerSupervisor $supervisor,
        private readonly QueueProbe $probe,
        private readonly FailedMessages $failedMessages,
        TerminalInterface $terminal,
        private readonly ClockInterface $clock = new NativeClock(),
        int $concurrency = 1,
        ?bool $parallel = null,
        ?DateTimeZone $timezone = null,
    ) {
        $this->startedAt = $this->now();
        $this->parallel = $parallel ?? ParallelExecution::isAvailable();
        $this->defaultConcurrency = $this->parallel ? max(1, min(self::MAX_SLOTS, $concurrency)) : 1;
        $this->timezone = $timezone ?? LocalTimezone::detect();
        $this->selected = ($catalog->all()[0] ?? null)?->name;
        $this->keybindings = new Keybindings([
            'quit' => ['q', 'ctrl+c'],
            'toggle' => ['s'],
            'scale_up' => ['+', '='],
            'scale_down' => ['-', '_'],
            'concurrency' => ['c'],
            'activity' => ['a', Key::ESCAPE],
            'messages' => ['m'],
            'options' => ['o'],
            'collapse' => [Key::LEFT],
            'resend' => ['r'],
            'remove' => ['d', Key::DELETE],
            'resend_all' => ['shift+r'],
            'remove_all' => ['shift+d'],
            'next_page' => [']', Key::PAGE_DOWN],
            'previous_page' => ['[', Key::PAGE_UP],
            'errors' => ['e'],
            'logs' => ['l'],
            'help' => ['?'],
        ]);
        $this->promptKeybindings = new Keybindings([
            'submit' => [Key::ENTER],
            'cancel' => [Key::ESCAPE, 'ctrl+c'],
            'erase' => [Key::BACKSPACE],
        ]);
        $this->editorKeybindings = new Keybindings([
            'send' => ['ctrl+s'],
            'cancel' => [Key::ESCAPE, 'ctrl+c'],
        ]);
        $this->confirmKeybindings = new Keybindings([
            'yes' => ['y'],
            'no' => ['n', Key::ESCAPE, 'ctrl+c'],
        ]);

        if ($concurrency > 1 && !$this->parallel) {
            $this->notify(sprintf('The concurrency option is ignored: it needs %s.', self::PARALLEL_REQUIREMENTS));
        }

        $this->tui = new Tui(terminal: $terminal);
        $this->menu = new MenuWidget()
            ->onMove($this->onMove(...))
            ->onActivate($this->onActivate(...));
        $this->menu->setStyle(new Style(
            border: new Border(0, 1, 0, 0, color: 'gray'),
            padding: new Padding(0, 1, 0, 0),
            flex: 1,
        ));

        $this->body = new CanvasWidget($this->paintBody(...))->expandVertically(true);
        $this->body->setStyle(new Style(padding: new Padding(0, 0, 0, 1), flex: 3));

        // Enter breaks lines in JSON documents: sending and cancelling are handled here
        $this->editor = new EditorWidget(new Keybindings([
            'new_line' => [Key::ENTER, 'shift+enter'],
            'submit' => [],
            'select_cancel' => [],
        ]))->expandVertically(true);
        $this->editor->setStyle(new Style(padding: new Padding(0, 0, 0, 1), flex: 3));

        $this->footer = new CanvasWidget($this->paintFooter(...));

        $this->main = new ContainerWidget()->expandVertically(true);
        $this->main->setStyle(new Style(direction: Direction::Horizontal));
        $this->main->add($this->menu)->add($this->body);

        $this->header = new CanvasWidget($this->paintHeader(...));

        $this->tui->add($this->header)->add($this->main)->add($this->footer);
        $this->tui->addListener($this->onInput(...));
        $this->tui->setFocus($this->menu);
        $this->refreshMenu();
    }

    /**
     * Runs the dashboard until the user quits.
     */
    public function run(): void
    {
        $terminal = $this->tui->getTerminal();
        $terminal->write("\e[?1049h\e[H");

        $callbacks = [EventLoop::repeat(self::TICK_INTERVAL, $this->tick(...))];
        foreach (['SIGINT', 'SIGTERM', 'SIGHUP'] as $signal) {
            if (!defined($signal)) {
                continue;
            }

            // A terminal that went away cannot show the workers stopping
            $handler = 'SIGHUP' === $signal ? $this->tui->stop(...) : $this->onQuitSignal(...);
            try {
                $callbacks[] = EventLoop::onSignal((int) constant($signal), $handler);
            } catch (UnsupportedFeatureException) {
                // Signals are not supported without ext-pcntl
                continue;
            }
        }

        try {
            $this->tui->run();
        } finally {
            foreach ($callbacks as $callback) {
                EventLoop::cancel($callback);
            }

            $terminal->write("\e[?1049l");
        }
    }

    /**
     * Starts the dashboard without the event loop, to drive it step by step.
     */
    public function start(): void
    {
        $this->tui->start();
    }

    public function stop(): void
    {
        $this->tui->stop();
    }

    public function input(string $data): void
    {
        $this->tui->handleInput($data);
    }

    public function render(): void
    {
        $this->tui->requestRender();
        $this->tui->processRender();
    }

    /**
     * Collects what workers and transports reported, and repaints if needed.
     */
    public function tick(): void
    {
        $now = $this->now();
        $changed = $this->supervisor->poll();
        if (null !== $this->quittingAt && !$this->supervisor->hasRunning()) {
            $this->tui->stop();

            return;
        }

        if (null !== $this->selected && ($now - $this->selectedQueueAt) >= self::SELECTED_QUEUE_INTERVAL) {
            $this->selectedQueueAt = $now;
            $limit = $this->catalog->get($this->selected)->failureTransport
                ? $this->failedLimit($this->selected)
                : self::QUEUE_PEEK_LIMIT;
            $this->inspect($this->selected, $limit, $now);
        }

        if (($now - $this->otherQueuesAt) >= self::OTHER_QUEUES_INTERVAL) {
            $this->otherQueuesAt = $now;
            foreach ($this->catalog->all() as $transport) {
                if ($transport->name === $this->selected || !$transport->consumable) {
                    continue;
                }

                // The failed messages of an open failure transport are rows of the menu
                $limit = $transport->failureTransport && $this->expanded === $transport->name
                    ? $this->failedLimit($transport->name)
                    : 0;
                $this->inspect($transport->name, $limit, $now);
            }
        }

        foreach ($this->probe->poll() as $answer) {
            $this->received($answer['transport'], $answer['snapshot'], $now);
            $changed = true;
        }

        if ($changed || $this->supervisor->hasRunning()) {
            $this->refresh();
        }
    }

    public function selected(): ?string
    {
        return $this->selected;
    }

    public function view(string $transport): string
    {
        return $this->views[$transport] ?? self::VIEW_ACTIVITY;
    }

    public function scale(string $transport): int
    {
        return $this->scales[$transport] ?? 1;
    }

    public function concurrency(string $transport): int
    {
        return $this->concurrencies[$transport] ?? $this->defaultConcurrency;
    }

    public function isEditing(): bool
    {
        return null !== $this->editing;
    }

    public function isRunning(): bool
    {
        return $this->tui->isRunning();
    }

    public function isQuitting(): bool
    {
        return null !== $this->quittingAt;
    }

    /**
     * Asks for the queue of a transport, less and less often while it fails.
     */
    private function inspect(string $transport, int $limit, float $now): void
    {
        if ($now >= ($this->queueRetryAt[$transport] ?? 0.0) && !$this->probe->pending($transport)) {
            $this->probe->request($transport, $limit);
        }
    }

    private function received(string $transport, QueueSnapshot $snapshot, float $now): void
    {
        $this->queues[$transport] = $snapshot;
        if (null === $snapshot->error) {
            unset($this->queueFailures[$transport], $this->queueRetryAt[$transport]);

            return;
        }

        $failures = ($this->queueFailures[$transport] ?? 0) + 1;
        $this->queueFailures[$transport] = $failures;
        $this->queueRetryAt[$transport] = $now + min(self::MAX_QUEUE_BACKOFF, 2 ** $failures);
    }

    private function onInput(InputEvent $event): void
    {
        $data = $event->getData();
        if (null !== $this->quittingAt) {
            // Quitting again kills the workers that are still stopping
            $event->stopPropagation();
            if ($this->keybindings->matches($data, 'quit')) {
                $this->killAndQuit();
            }

            return;
        }

        $editing = $this->editing;
        if (null !== $editing) {
            // The editor takes every key, like the ones that move the focus
            $event->stopPropagation();
            $this->onEditorInput($data, $editing);
            $this->refresh();

            return;
        }

        $confirmation = $this->confirmation;
        if (null !== $confirmation) {
            // Other keys are ignored, so that a confirmation is not lost by mistake
            $event->stopPropagation();
            $this->onConfirmationInput($data, $confirmation['action'], $confirmation['quit']);
            $this->refresh();

            return;
        }

        if (null !== $this->prompt) {
            // The prompt takes every key, so that the menu does not move while typing
            $event->stopPropagation();
            $this->onPromptInput($data, $this->prompt);
            $this->refresh();

            return;
        }

        $action = null;
        foreach ($this->keybindings->all() as $name => $_) {
            if (!$this->keybindings->matches($data, $name)) {
                continue;
            }

            $action = $name;

            break;
        }

        if (null === $action) {
            return;
        }

        $event->stopPropagation();
        $this->help = 'help' === $action && !$this->help;
        if ('quit' === $action) {
            $this->requestQuit();
            $this->refresh();

            return;
        }

        $transport = $this->selected;
        if (null === $transport) {
            return;
        }

        match ($action) {
            'toggle' => $this->toggleWorkers($transport),
            'scale_up' => $this->changeScale($transport, 1),
            'scale_down' => $this->changeScale($transport, -1),
            'concurrency' => $this->openPrompt($transport),
            'activity' => $this->show($transport, self::VIEW_ACTIVITY),
            'messages' => $this->show($transport, self::VIEW_MESSAGES),
            'options' => $this->show($transport, self::VIEW_OPTIONS),
            'resend' => $this->resendAsIs($transport),
            'remove' => $this->confirmRemove($transport),
            'resend_all' => $this->confirmResendAll($transport),
            'remove_all' => $this->confirmRemoveAll($transport),
            'next_page' => $this->changePage($transport, 1),
            'previous_page' => $this->changePage($transport, -1),
            'errors' => $this->toggleErrors(),
            'logs' => $this->show($transport, self::VIEW_LOGS),
            'help' => null,
            default => $this->collapse(),
        };

        $this->refresh();
    }

    /**
     * @param Closure(): void $action
     * @param bool            $quit   whether quitting again confirms, like Ctrl+C twice
     */
    private function onConfirmationInput(string $data, Closure $action, bool $quit): void
    {
        if ($quit && $this->keybindings->matches($data, 'quit')) {
            $this->confirmation = null;
            $action();

            return;
        }

        if ($this->confirmKeybindings->matches($data, 'no')) {
            $this->confirmation = null;

            return;
        }

        if ($this->confirmKeybindings->matches($data, 'yes')) {
            $this->confirmation = null;
            $action();
        }
    }

    /**
     * @param array{transport: string, value: string} $prompt
     */
    private function onPromptInput(string $data, array $prompt): void
    {
        if ($this->promptKeybindings->matches($data, 'cancel')) {
            $this->prompt = null;

            return;
        }

        if ($this->promptKeybindings->matches($data, 'submit')) {
            $this->prompt = null;
            $this->changeConcurrency($prompt['transport'], $prompt['value']);

            return;
        }

        if ($this->promptKeybindings->matches($data, 'erase')) {
            $this->prompt = ['transport' => $prompt['transport'], 'value' => substr($prompt['value'], 0, -1)];

            return;
        }

        if (ctype_digit($data) && strlen($prompt['value'] . $data) <= 3) {
            $this->prompt = ['transport' => $prompt['transport'], 'value' => $prompt['value'] . $data];
        }
    }

    /**
     * @param array{transport: string, id: string, class: string, from: string, error: string|null} $editing
     */
    private function onEditorInput(string $data, array $editing): void
    {
        if ($this->editorKeybindings->matches($data, 'cancel')) {
            $this->closeEditor();

            return;
        }

        if (!$this->editorKeybindings->matches($data, 'send')) {
            $this->editor->handleInput($data);

            return;
        }

        $error = $this->sendBack($editing['transport'], $editing['id'], $editing['class'], $this->editor->getText());
        if (null !== $error) {
            $this->editing = ['error' => $error] + $editing;

            return;
        }

        $this->closeEditor();
    }

    /**
     * Sends the failed message under the cursor back, without editing it.
     */
    private function resendAsIs(string $transport): void
    {
        $id = $this->selectedMessage;
        if (null === $id) {
            return;
        }

        $this->loadFailedMessage($transport, $id);
        $error = $this->sendBack($transport, $id, $this->failedMessage['message']->class ?? '?', null);
        if (null !== $error) {
            $this->notify($error);
        }
    }

    private function toggleErrors(): void
    {
        $this->showErrors = null !== $this->selectedMessage && !$this->showErrors;
    }

    /**
     * @param Closure(): void $action
     */
    private function confirm(string $question, Closure $action, bool $quit = false): void
    {
        $this->confirmation = ['question' => $question, 'action' => $action, 'quit' => $quit];
    }

    /**
     * Quits at once without workers, asks first otherwise.
     */
    private function requestQuit(): void
    {
        $workers = $this->supervisor->stopping()['workers'];
        if (0 === $workers) {
            $this->tui->stop();

            return;
        }

        $this->confirm(
            sprintf('Stop %s and quit?', 1 === $workers ? 'the worker' : sprintf('the %d workers', $workers)),
            $this->beginQuit(...),
            quit: true,
        );
    }

    /**
     * Stops the workers, letting them finish their messages, and quits once they are all gone.
     */
    private function beginQuit(): void
    {
        $this->quittingAt = $this->now();
        $this->confirmation = null;
        $this->prompt = null;
        $this->supervisor->stopEach();
        $this->refresh();
    }

    private function killAndQuit(): void
    {
        $this->supervisor->killAll();
        $this->tui->stop();
    }

    private function onQuitSignal(): void
    {
        if (null === $this->quittingAt) {
            $this->beginQuit();

            return;
        }

        $this->killAndQuit();
    }

    private function confirmRemove(string $transport): void
    {
        $id = $this->selectedMessage;
        if (null === $id) {
            return;
        }

        $this->loadFailedMessage($transport, $id);
        $class = Format::shortClass($this->failedMessage['message']->class ?? '?');
        $this->confirm(sprintf('Remove %s #%s for good?', $class, $id), function () use (
            $transport,
            $id,
            $class,
        ): void {
            try {
                $this->failedMessages->remove($transport, $id);
            } catch (Throwable $error) {
                $this->notify($error->getMessage());

                return;
            }

            $this->forgetFailedMessage($transport);
            $this->notify(sprintf('Removed %s #%s.', $class, $id));
        });
    }

    private function confirmResendAll(string $transport): void
    {
        if (!$this->catalog->get($transport)->failureTransport) {
            return;
        }

        $this->confirm(
            sprintf(
                'Send %s of "%s" back to the transports they failed on?',
                $this->failedCount($transport),
                $transport,
            ),
            function () use ($transport): void {
                try {
                    $result = $this->failedMessages->resendAll($transport);
                } catch (Throwable $error) {
                    $this->notify($error->getMessage());

                    return;
                }

                $this->forgetFailedMessage($transport);
                $this->notify(sprintf(
                    'Sent %d message%s back%s.',
                    $result['sent'],
                    1 === $result['sent'] ? '' : 's',
                    $result['kept'] > 0
                        ? sprintf(', kept %d that do not tell which transport they failed on', $result['kept'])
                        : '',
                ));
            },
        );
    }

    private function confirmRemoveAll(string $transport): void
    {
        if (!$this->catalog->get($transport)->failureTransport) {
            return;
        }

        $this->confirm(
            sprintf('Remove %s of "%s" for good?', $this->failedCount($transport), $transport),
            function () use ($transport): void {
                try {
                    $removed = $this->failedMessages->removeAll($transport);
                } catch (Throwable $error) {
                    $this->notify($error->getMessage());

                    return;
                }

                $this->forgetFailedMessage($transport);
                $this->notify(sprintf('Removed %d message%s.', $removed, 1 === $removed ? '' : 's'));
            },
        );
    }

    /**
     * "the 12 failed messages", as far as the transport tells.
     */
    private function failedCount(string $transport): string
    {
        $count = $this->queues[$transport]->count ?? null;

        return match ($count) {
            null => 'all the failed messages',
            1 => 'the failed message',
            default => sprintf('the %d failed messages', $count),
        };
    }

    /**
     * Goes back to the transport row once its messages changed, and asks for them again.
     */
    private function forgetFailedMessage(string $transport): void
    {
        $this->failedMessage = null;
        $this->selectedMessage = null;
        $this->showErrors = false;
        $this->menu->moveTo('transport:' . $transport);
        $this->selectedQueueAt = 0.0;
        $this->otherQueuesAt = 0.0;
    }

    private function changePage(string $transport, int $delta): void
    {
        if (!$this->catalog->get($transport)->failureTransport) {
            return;
        }

        $count = $this->queues[$transport]->count ?? null;
        $last = null === $count ? 0 : max(0, intdiv($count - 1, self::FAILED_LIST_LIMIT));
        $page = max(0, min($last, $this->failedPage($transport) + $delta));
        if ($page === $this->failedPage($transport)) {
            return;
        }

        $this->failedPages[$transport] = $page;
        $this->selectedQueueAt = 0.0;
        $this->otherQueuesAt = 0.0;
    }

    private function failedPage(string $transport): int
    {
        return $this->failedPages[$transport] ?? 0;
    }

    /**
     * Messages are listed from the first one: the receivers cannot skip some.
     */
    private function failedLimit(string $transport): int
    {
        return ($this->failedPage($transport) + 1) * self::FAILED_LIST_LIMIT;
    }

    /**
     * @return list<array{class: string, id: string|null, from: string|null, failed_at: float|null, error: string|null}>
     */
    private function failedPageMessages(string $transport): array
    {
        return array_values(array_slice(
            $this->queues[$transport]->messages ?? [],
            $this->failedPage($transport) * self::FAILED_LIST_LIMIT,
            self::FAILED_LIST_LIMIT,
        ));
    }

    /**
     * @param string|null $json the edited properties, null to send the message as is
     *
     * @return string|null why the message could not be sent back
     */
    private function sendBack(string $transport, string $id, string $class, ?string $json): ?string
    {
        try {
            $to = $this->failedMessages->resend($transport, $id, $json);
        } catch (FailedMessageException $error) {
            return $error->getMessage();
        } catch (Throwable $error) {
            return Format::shortClass($error::class) . ': ' . $error->getMessage();
        }

        $this->forgetFailedMessage($transport);
        $this->notify(sprintf('Sent %s #%s back to "%s".', Format::shortClass($class), $id, $to));

        return null;
    }

    private function onMove(string $key): void
    {
        $this->selected = self::transportOf($key) ?? $this->selected;
        $this->selectedMessage = self::messageOf($key);
        $this->showErrors = false;
        if (null !== $this->selected && null !== $this->selectedMessage) {
            $this->loadFailedMessage($this->selected, $this->selectedMessage);
        }

        $this->selectedQueueAt = 0.0;
        $this->refresh();
    }

    private function onActivate(string $key): void
    {
        $transport = self::transportOf($key);
        if (null === $transport || !$this->catalog->has($transport)) {
            return;
        }

        $this->selected = $transport;
        $id = self::messageOf($key);
        if (null !== $id) {
            $this->openEditor($transport, $id);
            $this->refresh();

            return;
        }

        match (self::actionOf($key)) {
            'workers' => $this->toggleWorkers($transport),
            'concurrency' => $this->openPrompt($transport),
            self::VIEW_MESSAGES => $this->show($transport, self::VIEW_MESSAGES),
            self::VIEW_LOGS => $this->show($transport, self::VIEW_LOGS),
            self::VIEW_OPTIONS => $this->show($transport, self::VIEW_OPTIONS),
            default => $this->toggleActions($transport),
        };

        $this->refresh();
    }

    /**
     * Menu keys are "transport:<name>", "action:<action>:<name>" and
     * "failed:<hexadecimal id>:<name>": the name comes last, as it may contain ":".
     */
    private static function transportOf(string $key): ?string
    {
        $parts = explode(':', $key, 2);

        return match ($parts[0]) {
            'transport' => $parts[1] ?? null,
            'action', 'failed' => explode(':', $parts[1] ?? '', 2)[1] ?? null,
            default => null,
        };
    }

    private static function messageOf(string $key): ?string
    {
        $parts = explode(':', $key, 3);
        $id = 'failed' === $parts[0] ? hex2bin($parts[1] ?? '') : false;

        return false === $id ? null : $id;
    }

    private function loadFailedMessage(string $transport, string $id): void
    {
        $loaded = $this->failedMessage;
        if (null !== $loaded && $loaded['transport'] === $transport && $loaded['id'] === $id) {
            return;
        }

        try {
            $message = $this->failedMessages->get($transport, $id);
            $error = null;
        } catch (Throwable $exception) {
            $message = null;
            $error = (string) preg_replace('/\s+/', ' ', $exception->getMessage());
        }

        $this->failedMessage = ['transport' => $transport, 'id' => $id, 'message' => $message, 'error' => $error];
    }

    private function openEditor(string $transport, string $id): void
    {
        $this->loadFailedMessage($transport, $id);
        $message = $this->failedMessage['message'] ?? null;
        if (null === $message) {
            $this->notify(sprintf('The message #%s cannot be read.', $id));

            return;
        }

        if (null === $message->from) {
            $this->notify(sprintf('The message #%s does not tell which transport it failed on.', $id));

            return;
        }

        if (null === $message->json) {
            $this->notify(sprintf('The message #%s cannot be edited: press r to send it back as is.', $id));

            return;
        }

        $this->editing = [
            'transport' => $transport,
            'id' => $id,
            'class' => $message->class,
            'from' => $message->from,
            'error' => null,
        ];
        $this->editor->setText($message->json);
        $this->main->remove($this->body)->add($this->editor);
        $this->tui->setFocus($this->editor);
    }

    private function closeEditor(): void
    {
        $this->editing = null;
        $this->main->remove($this->editor)->add($this->body);
        $this->tui->setFocus($this->menu);
    }

    private static function actionOf(string $key): ?string
    {
        $parts = explode(':', $key, 3);

        return 'action' === $parts[0] ? $parts[1] ?? null : null;
    }

    private function toggleWorkers(string $transport): void
    {
        if (!$this->canRunWorkers($transport)) {
            return;
        }

        // Workers waiting to be started again count as running
        if ($this->supervisor->desired($transport) > 0) {
            $this->supervisor->stop($transport);
            $this->notify(sprintf('Stopping the workers of "%s".', $transport));

            return;
        }

        $count = $this->scale($transport);
        $concurrency = $this->concurrency($transport);
        $this->supervisor->scale($transport, $count, $concurrency);
        $this->views[$transport] = self::VIEW_ACTIVITY;
        $this->notify(sprintf(
            'Started %d worker%s on "%s"%s.',
            $count,
            1 === $count ? '' : 's',
            $transport,
            $concurrency > 1 ? sprintf(', %d messages at a time each', $concurrency) : '',
        ));
    }

    private function notify(string $notice): void
    {
        $this->notice = $notice;
        $this->noticeAt = $this->now();
    }

    private function changeScale(string $transport, int $delta): void
    {
        if (!$this->catalog->get($transport)->canRunWorkers()) {
            return;
        }

        $running = $this->supervisor->desired($transport);
        $maximum = max(1, intdiv(self::MAX_SLOTS, $this->concurrency($transport)));
        $scale = max(1, min($maximum, ($running > 0 ? $running : $this->scale($transport)) + $delta));
        $this->scales[$transport] = $scale;

        if ($running > 0) {
            $this->supervisor->scale($transport, $scale, $this->concurrency($transport));
            $this->notify(sprintf('Scaled "%s" to %d worker%s.', $transport, $scale, 1 === $scale ? '' : 's'));
        }
    }

    /**
     * Tells why when workers cannot be started on the transport.
     */
    private function canRunWorkers(string $transport): bool
    {
        $definition = $this->catalog->get($transport);
        if ($definition->canRunWorkers()) {
            return true;
        }

        $this->notify(
            $definition->failureTransport
                ? sprintf('"%s" is a failure transport: open it to send its messages back.', $transport)
                : sprintf('"%s" cannot be consumed.', $transport),
        );

        return false;
    }

    private function openPrompt(string $transport): void
    {
        if (!$this->canRunWorkers($transport)) {
            return;
        }

        if (!$this->parallel) {
            $this->notify(sprintf('Handling several messages at a time needs %s.', self::PARALLEL_REQUIREMENTS));

            return;
        }

        $this->prompt = ['transport' => $transport, 'value' => ''];
    }

    private function maxConcurrency(string $transport): int
    {
        $workers = $this->supervisor->desired($transport);

        return max(1, intdiv(self::MAX_SLOTS, $workers > 0 ? $workers : $this->scale($transport)));
    }

    private function changeConcurrency(string $transport, string $value): void
    {
        if ('' === $value) {
            return;
        }

        $concurrency = (int) $value;
        $maximum = $this->maxConcurrency($transport);
        if ($concurrency < 1 || $concurrency > $maximum) {
            $this->notify(sprintf('The concurrency of "%s" must be between 1 and %d.', $transport, $maximum));

            return;
        }

        $this->concurrencies[$transport] = $concurrency;
        $running = $this->supervisor->desired($transport);
        if (0 === $running) {
            $this->notify(sprintf(
                'Workers of "%s" will handle %d message%s at a time.',
                $transport,
                $concurrency,
                1 === $concurrency ? '' : 's',
            ));

            return;
        }

        // The concurrency of a running worker cannot change: they are replaced
        $this->supervisor->scale($transport, $running, $concurrency);
        $this->notify(sprintf('Restarting the workers of "%s" with a concurrency of %d.', $transport, $concurrency));
    }

    private function toggleActions(string $transport): void
    {
        $this->expanded = $this->expanded === $transport ? null : $transport;
    }

    private function show(string $transport, string $view): void
    {
        $this->views[$transport] = $view;
    }

    private function collapse(): void
    {
        if (null !== $this->expanded) {
            $this->menu->moveTo('transport:' . $this->expanded);
            $this->expanded = null;
        }
    }

    private function refresh(): void
    {
        $this->refreshMenu();
        $this->header->invalidate();
        $this->body->invalidate();
        $this->footer->invalidate();
        $this->tui->requestRender();
    }

    private function refreshMenu(): void
    {
        $rows = [];
        foreach ($this->catalog->all() as $transport) {
            $name = $transport->name;
            $rows[] = ['key' => 'transport:' . $name, 'label' => $this->transportLabel($transport)];
            if ($this->expanded !== $name) {
                continue;
            }

            if ($transport->failureTransport) {
                foreach ($this->failedPageMessages($name) as $message) {
                    if (null === $message['id']) {
                        continue;
                    }

                    $rows[] = [
                        'key' => 'failed:' . bin2hex($message['id']) . ':' . $name,
                        'label' => sprintf(
                            '  ├ #%s %s',
                            Format::safe($message['id']),
                            Format::safe(Format::shortClass($message['class'])),
                        ),
                    ];
                }
            }

            $rows[] = [
                'key' => 'action:' . self::VIEW_MESSAGES . ':' . $name,
                'label' => sprintf('  ├ Message classes (%d)', count($transport->messageClasses)),
            ];
            if ($transport->canRunWorkers()) {
                $rows[] = [
                    'key' => 'action:workers:' . $name,
                    'label' => 0 === $this->supervisor->desired($name)
                        ? sprintf('  ├ Start workers (×%d)', $this->scale($name))
                        : sprintf('  ├ Stop workers (%d running)', count($this->supervisor->active($name))),
                ];
                if ($this->parallel) {
                    $rows[] = [
                        'key' => 'action:concurrency:' . $name,
                        'label' => sprintf('  ├ Concurrency (%d)', $this->concurrency($name)),
                    ];
                }

                $rows[] = ['key' => 'action:' . self::VIEW_LOGS . ':' . $name, 'label' => '  ├ Output of the workers'];
            }

            $rows[] = ['key' => 'action:' . self::VIEW_OPTIONS . ':' . $name, 'label' => '  └ Options'];
        }

        $cursor = $this->menu->cursorKey();
        $this->menu->setRows($rows);
        if (null !== $cursor && $this->menu->cursorKey() !== $cursor) {
            // The row under the cursor went away, like a message sent back
            $this->selectedMessage = null;
            $this->menu->moveTo('transport:' . ($this->selected ?? ''));
        }
    }

    private function transportLabel(TransportDefinition $transport): string
    {
        $name = Format::safe($transport->name);
        $arrow = $this->expanded === $transport->name ? '▾ ' : '▸ ';
        $active = count($this->supervisor->active($transport->name));
        $workers = $active > 0 ? Format::color(sprintf(' ●%d', $active), 'green') : '';
        $count = $this->queues[$transport->name]->count ?? null;
        $queue = null === $count ? '' : Format::dim(sprintf(' (%d)', $count));

        return $arrow . ($transport->canRunWorkers() ? $name : Format::dim($name)) . $workers . $queue;
    }

    /**
     * @return list<string>
     */
    private function paintHeader(int $columns): array
    {
        $running = 0;
        $slots = 0;
        foreach ($this->catalog->all() as $transport) {
            foreach ($this->supervisor->active($transport->name) as $worker) {
                ++$running;
                $slots += $worker->concurrency;
            }
        }

        $title =
            Format::bold(Format::color(' Messenger Central', 'cyan'))
            . Format::dim(sprintf(
                '  ·  %d transports  ·  %d worker%s running%s',
                count($this->catalog->all()),
                $running,
                1 === $running ? '' : 's',
                $slots > $running ? sprintf(', up to %d messages at a time', $slots) : '',
            ));

        return [$title, Format::dim(Format::rule($columns))];
    }

    /**
     * @return list<string>
     */
    private function paintBody(int $columns, int $rows): array
    {
        if (null === $this->selected) {
            return [Format::dim('No Messenger transport is configured.')];
        }

        if ($this->help) {
            return HelpView::render($this->parallel);
        }

        $selected = $this->selected;
        $transport = $this->catalog->get($selected);

        $failedMessage = $this->failedMessage;
        if (null !== $this->selectedMessage && null !== $failedMessage) {
            return FailedMessageView::render(
                $selected,
                $failedMessage['message'],
                $failedMessage['error'],
                $this->now(),
                $this->timezone,
                $this->showErrors,
                $columns,
            );
        }

        if ($transport->failureTransport && self::VIEW_ACTIVITY === $this->view($selected)) {
            return FailedMessagesView::render(
                $transport,
                $this->queues[$selected] ?? null,
                $this->now(),
                $this->timezone,
                $this->failedPage($selected),
                self::FAILED_LIST_LIMIT,
            );
        }

        return match ($this->view($selected)) {
            self::VIEW_MESSAGES => MessagesView::render($transport),
            self::VIEW_LOGS => LogsView::render(
                $transport,
                $this->supervisor->workers($selected),
                $this->timezone,
                $rows,
            ),
            self::VIEW_OPTIONS => OptionsView::render($transport, $this->concurrency($selected), $this->parallel),
            default => ActivityView::render(
                $transport,
                $this->supervisor->workers($selected),
                $this->supervisor->activity($selected),
                $this->queues[$selected] ?? null,
                $this->scale($selected),
                $this->concurrency($selected),
                $this->now(),
                $this->timezone,
                $columns,
                $rows,
                $this->supervisor->nextRestart($selected),
                $this->supervisor->restarted($selected),
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function paintFooter(int $columns): array
    {
        $lines = [Format::dim(Format::rule($columns))];

        $now = $this->now();
        $selected = $this->selected;
        if (null !== $selected) {
            $lines[] = $this->summary($selected, $now);
        }

        $failureTransport = null !== $selected && $this->catalog->get($selected)->failureTransport;

        $editing = $this->editing;
        if (null !== $editing) {
            $lines[] = sprintf(
                ' Editing %s #%s   %s',
                Format::bold(Format::safe(Format::shortClass($editing['class']))),
                Format::safe($editing['id']),
                Format::dim(sprintf('ctrl+s send it back to %s  esc cancel', Format::safe($editing['from']))),
            );
            if (null !== $editing['error']) {
                $lines[] = ' ' . Format::color(Format::safe($editing['error']), 'red');
            }

            return $lines;
        }

        if (null !== $this->quittingAt) {
            $stopping = $this->supervisor->stopping();
            $lines[] = sprintf(
                ' %s  %s',
                Format::color(
                    sprintf(
                        'Stopping %d worker%s%s…',
                        $stopping['workers'],
                        1 === $stopping['workers'] ? '' : 's',
                        $stopping['messages'] > 0
                            ? sprintf(
                                ', finishing %d message%s',
                                $stopping['messages'],
                                1 === $stopping['messages'] ? '' : 's',
                            )
                            : '',
                    ),
                    'yellow',
                ),
                Format::dim(
                    (
                        null === $stopping['kill_at']
                            ? ''
                            : sprintf('killed in %d s  ', max(0, (int) ceil($stopping['kill_at'] - $now)))
                    ) . 'q kill now',
                ),
            );

            return $lines;
        }

        $confirmation = $this->confirmation;
        if (null !== $confirmation) {
            $lines[] = sprintf(
                ' %s   %s',
                Format::bold(Format::color(Format::safe($confirmation['question']), 'yellow')),
                Format::dim('y yes  n no'),
            );

            return $lines;
        }

        $prompt = $this->prompt;
        if (null !== $prompt) {
            $lines[] = sprintf(
                ' Messages handled at a time by each worker of %s (1-%d): %s%s   %s',
                Format::bold(Format::safe($prompt['transport'])),
                $this->maxConcurrency($prompt['transport']),
                $prompt['value'],
                Format::color('█', 'cyan'),
                Format::dim('⏎ apply  esc cancel'),
            );

            return $lines;
        }

        if (null !== $this->notice && ($now - $this->noticeAt) <= self::NOTICE_SECONDS) {
            // In place of the help, that leaves too little room
            $lines[] = ' ' . Format::color(Format::safe($this->notice), 'yellow');

            return $lines;
        }

        $lines[] = Format::dim(match (true) {
            null !== $this->selectedMessage
                => ' ↑↓ move  ⏎ edit and send back  r send back as is  d remove  e errors  [ ] pages  ? help  q quit',
            $failureTransport => ' ↑↓ move  ⏎ open  [ ] pages  R send all back  D remove all  ? help  q quit',
            default => ' ↑↓ move  ⏎ open  s start/stop  +/- scale'
                . ($this->parallel ? '  c concurrency' : '')
                . '  l output  o options  ? help  q quit',
        });

        return $lines;
    }

    /**
     * What the workers of a transport did, on a single line.
     */
    private function summary(string $transport, float $now): string
    {
        $uptime = Format::dim('up ' . Format::elapsed($now - $this->startedAt));
        if (!$this->catalog->get($transport)->canRunWorkers()) {
            return sprintf(' %s  %s', Format::bold(Format::safe($transport)), $uptime);
        }

        $activity = $this->supervisor->activity($transport);
        [$memory, $peak, $children] = self::memory($this->supervisor->workers($transport));

        return sprintf(
            ' %s  %s  %s  %s  %.1f msg/s  memory %s, worker peak %s%s  %s',
            Format::bold(Format::safe($transport)),
            Format::color(sprintf('✓ %d processed', $activity->processed), 'green'),
            Format::color(sprintf('✗ %d failed', $activity->failed), $activity->failed > 0 ? 'red' : 'gray'),
            Format::color(sprintf('↻ %d retried', $activity->retried), $activity->retried > 0 ? 'yellow' : 'gray'),
            $activity->rate($now),
            Format::bytes($memory),
            Format::bytes($peak),
            $children ? Format::dim(' (child processes excluded)') : '',
            $uptime,
        );
    }

    /**
     * @param list<WorkerProcess> $workers
     *
     * @return array{int, int, bool} memory used by the running workers, the highest peak, and whether
     *                               workers have child processes, whose memory is not reported
     */
    private static function memory(array $workers): array
    {
        $memory = 0;
        $peak = 0;
        $children = false;
        foreach ($workers as $worker) {
            if (WorkerStatus::Exited !== $worker->status) {
                $memory += $worker->memory;
                $children = $children || $worker->concurrency > 1;
            }

            $peak = max($peak, $worker->peakMemory);
        }

        return [$memory, $peak, $children];
    }

    private function now(): float
    {
        $now = $this->clock->now();

        return $now->getTimestamp() + ($now->getMicrosecond() / 1_000_000);
    }
}
