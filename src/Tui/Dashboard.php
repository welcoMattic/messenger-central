<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui;

use Closure;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
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
use Welcomattic\MessengerCentral\Transport\QueueInspector;
use Welcomattic\MessengerCentral\Transport\QueueSnapshot;
use Welcomattic\MessengerCentral\Transport\TransportCatalog;
use Welcomattic\MessengerCentral\Transport\TransportDefinition;
use Welcomattic\MessengerCentral\Tui\View\ActivityView;
use Welcomattic\MessengerCentral\Tui\View\MessagesView;
use Welcomattic\MessengerCentral\Tui\View\OptionsView;
use Welcomattic\MessengerCentral\Tui\Widget\CanvasWidget;
use Welcomattic\MessengerCentral\Tui\Widget\MenuWidget;
use Welcomattic\MessengerCentral\Worker\WorkerProcess;
use Welcomattic\MessengerCentral\Worker\WorkerStatus;
use Welcomattic\MessengerCentral\Worker\WorkerSupervisor;

use function array_key_first;
use function constant;
use function count;
use function defined;
use function explode;
use function max;
use function microtime;
use function sprintf;
use function str_repeat;

/**
 * The terminal UI: transports on the left, the selected one on the right.
 */
final class Dashboard
{
    public const string VIEW_ACTIVITY = 'activity';
    public const string VIEW_MESSAGES = 'messages';
    public const string VIEW_OPTIONS = 'options';

    private const float TICK_INTERVAL = 0.1;
    private const float SELECTED_QUEUE_INTERVAL = 1.0;
    private const float OTHER_QUEUES_INTERVAL = 3.0;
    private const int QUEUE_PEEK_LIMIT = 5;
    private const int MAX_SCALE = 32;
    private const float NOTICE_SECONDS = 5.0;

    private readonly Tui $tui;
    private readonly MenuWidget $menu;
    private readonly CanvasWidget $header;
    private readonly CanvasWidget $body;
    private readonly CanvasWidget $footer;
    private readonly Keybindings $keybindings;

    /** @var Closure(): float */
    private readonly Closure $clock;

    private readonly float $startedAt;
    private ?string $selected;
    private ?string $expanded = null;

    /** @var array<string, string> */
    private array $views = [];

    /** @var array<string, int> */
    private array $scales = [];

    /** @var array<string, QueueSnapshot> */
    private array $queues = [];

    private float $selectedQueueAt = 0.0;
    private float $otherQueuesAt = 0.0;
    private ?string $notice = null;
    private float $noticeAt = 0.0;

    /**
     * @param (Closure(): float)|null $clock
     */
    public function __construct(
        private readonly TransportCatalog $catalog,
        private readonly WorkerSupervisor $supervisor,
        private readonly QueueInspector $inspector,
        TerminalInterface $terminal,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->startedAt = ($this->clock)();
        $this->selected = array_key_first($catalog->all());
        $this->keybindings = new Keybindings([
            'quit' => ['q', 'ctrl+c'],
            'toggle' => ['s'],
            'scale_up' => ['+', '='],
            'scale_down' => ['-', '_'],
            'activity' => ['a', Key::ESCAPE],
            'messages' => ['m'],
            'options' => ['o'],
            'collapse' => [Key::LEFT],
        ]);

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

        $this->footer = new CanvasWidget($this->paintFooter(...));

        $main = new ContainerWidget()->expandVertically(true);
        $main->setStyle(new Style(direction: Direction::Horizontal));
        $main->add($this->menu)->add($this->body);

        $this->header = new CanvasWidget($this->paintHeader(...));

        $this->tui->add($this->header)->add($main)->add($this->footer);
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

            try {
                $callbacks[] = EventLoop::onSignal((int) constant($signal), $this->tui->stop(...));
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
        $now = ($this->clock)();
        $changed = $this->supervisor->poll();

        if (null !== $this->selected && ($now - $this->selectedQueueAt) >= self::SELECTED_QUEUE_INTERVAL) {
            $this->selectedQueueAt = $now;
            $this->queues[$this->selected] = $this->inspector->inspect($this->selected, self::QUEUE_PEEK_LIMIT);
            $changed = true;
        }

        if (($now - $this->otherQueuesAt) >= self::OTHER_QUEUES_INTERVAL) {
            $this->otherQueuesAt = $now;
            foreach ($this->catalog->all() as $name => $transport) {
                if ($name === $this->selected || !$transport->consumable) {
                    continue;
                }

                $this->queues[$name] = $this->inspector->inspect($name);
            }

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

    private function onInput(InputEvent $event): void
    {
        $data = $event->getData();
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
        if ('quit' === $action) {
            $this->tui->stop();

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
            'activity' => $this->show($transport, self::VIEW_ACTIVITY),
            'messages' => $this->show($transport, self::VIEW_MESSAGES),
            'options' => $this->show($transport, self::VIEW_OPTIONS),
            default => $this->collapse(),
        };

        $this->refresh();
    }

    private function onMove(string $key): void
    {
        $this->selected = explode(':', $key)[1] ?? $this->selected;
        $this->selectedQueueAt = 0.0;
        $this->refresh();
    }

    private function onActivate(string $key): void
    {
        $parts = explode(':', $key, 3);
        $transport = $parts[1] ?? null;
        if (null === $transport || !$this->catalog->has($transport)) {
            return;
        }

        $this->selected = $transport;
        match ($parts[2] ?? '') {
            'workers' => $this->toggleWorkers($transport),
            self::VIEW_MESSAGES => $this->show($transport, self::VIEW_MESSAGES),
            self::VIEW_OPTIONS => $this->show($transport, self::VIEW_OPTIONS),
            default => $this->toggleActions($transport),
        };

        $this->refresh();
    }

    private function toggleWorkers(string $transport): void
    {
        $definition = $this->catalog->get($transport);
        if (!$definition->consumable) {
            $this->notify(sprintf('"%s" cannot be consumed.', $transport));

            return;
        }

        if ([] !== $this->supervisor->active($transport)) {
            $this->supervisor->stop($transport);
            $this->notify(sprintf('Stopping the workers of "%s".', $transport));

            return;
        }

        $count = $this->scale($transport);
        $this->supervisor->scale($transport, $count);
        $this->views[$transport] = self::VIEW_ACTIVITY;
        $this->notify(sprintf('Started %d worker%s on "%s".', $count, 1 === $count ? '' : 's', $transport));
    }

    private function notify(string $notice): void
    {
        $this->notice = $notice;
        $this->noticeAt = ($this->clock)();
    }

    private function changeScale(string $transport, int $delta): void
    {
        if (!$this->catalog->get($transport)->consumable) {
            return;
        }

        $running = count($this->supervisor->active($transport));
        $scale = max(1, min(self::MAX_SCALE, ($running > 0 ? $running : $this->scale($transport)) + $delta));
        $this->scales[$transport] = $scale;

        if ($running > 0) {
            $this->supervisor->scale($transport, $scale);
            $this->notify(sprintf('Scaled "%s" to %d worker%s.', $transport, $scale, 1 === $scale ? '' : 's'));
        }
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
        foreach ($this->catalog->all() as $name => $transport) {
            $rows[] = ['key' => 'transport:' . $name, 'label' => $this->transportLabel($transport)];
            if ($this->expanded !== $name) {
                continue;
            }

            $rows[] = [
                'key' => 'action:' . $name . ':' . self::VIEW_MESSAGES,
                'label' => sprintf('  ├ Message classes (%d)', count($transport->messageClasses)),
            ];
            if ($transport->consumable) {
                $active = count($this->supervisor->active($name));
                $rows[] = [
                    'key' => 'action:' . $name . ':workers',
                    'label' => 0 === $active
                        ? sprintf('  ├ Start workers (×%d)', $this->scale($name))
                        : sprintf('  ├ Stop workers (%d running)', $active),
                ];
            }

            $rows[] = ['key' => 'action:' . $name . ':' . self::VIEW_OPTIONS, 'label' => '  └ Options'];
        }

        $this->menu->setRows($rows);
    }

    private function transportLabel(TransportDefinition $transport): string
    {
        $name = Format::safe($transport->name);
        $arrow = $this->expanded === $transport->name ? '▾ ' : '▸ ';
        $active = count($this->supervisor->active($transport->name));
        $workers = $active > 0 ? Format::color(sprintf(' ●%d', $active), 'green') : '';
        $count = $this->queues[$transport->name]->count ?? null;
        $queue = null === $count ? '' : Format::dim(sprintf(' (%d)', $count));

        return $arrow . ($transport->consumable ? $name : Format::dim($name)) . $workers . $queue;
    }

    /**
     * @return list<string>
     */
    private function paintHeader(int $columns): array
    {
        $running = 0;
        foreach ($this->catalog->all() as $name => $_) {
            $running += count($this->supervisor->active($name));
        }

        $title =
            Format::bold(Format::color(' Messenger Central', 'cyan'))
            . Format::dim(sprintf('  ·  %d transports  ·  %d workers running', count($this->catalog->all()), $running));

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

        $selected = $this->selected;
        $transport = $this->catalog->get($selected);

        return match ($this->view($selected)) {
            self::VIEW_MESSAGES => MessagesView::render($transport),
            self::VIEW_OPTIONS => OptionsView::render($transport),
            default => ActivityView::render(
                $transport,
                $this->supervisor->workers($selected),
                $this->supervisor->activity($selected),
                $this->queues[$selected] ?? null,
                $this->scale($selected),
                ($this->clock)(),
                $columns,
                $rows,
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function paintFooter(int $columns): array
    {
        $lines = [Format::dim(Format::rule($columns))];

        if (null !== $this->selected) {
            $now = ($this->clock)();
            $activity = $this->supervisor->activity($this->selected);
            $workers = $this->supervisor->workers($this->selected);
            [$memory, $peak] = self::memory($workers);

            $lines[] = sprintf(
                ' %s  %s  %s  %s  %.1f msg/s  memory %s, worker peak %s  %s',
                Format::bold(Format::safe($this->selected)),
                Format::color(sprintf('✓ %d processed', $activity->processed), 'green'),
                Format::color(sprintf('✗ %d failed', $activity->failed), $activity->failed > 0 ? 'red' : 'gray'),
                Format::color(sprintf('↻ %d retried', $activity->retried), $activity->retried > 0 ? 'yellow' : 'gray'),
                $activity->rate($now),
                Format::bytes($memory),
                Format::bytes($peak),
                Format::dim('up ' . Format::elapsed($now - $this->startedAt)),
            );
        }

        $help = ' ↑↓ move  ⏎ open  s start/stop  +/- scale  a activity  m messages  o options  q quit';
        $lines[] =
            Format::dim($help)
            . (
                null === $this->notice || (($this->clock)() - $this->noticeAt) > self::NOTICE_SECONDS
                    ? ''
                    : str_repeat(' ', 3) . Format::color($this->notice, 'yellow')
            );

        return $lines;
    }

    /**
     * @param list<WorkerProcess> $workers
     *
     * @return array{int, int} memory used by the running workers, and the highest peak
     */
    private static function memory(array $workers): array
    {
        $memory = 0;
        $peak = 0;
        foreach ($workers as $worker) {
            if (WorkerStatus::Exited !== $worker->status) {
                $memory += $worker->memory;
            }

            $peak = max($peak, $worker->peakMemory);
        }

        return [$memory, $peak];
    }
}
