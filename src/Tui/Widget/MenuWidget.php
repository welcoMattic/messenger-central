<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\Widget;

use Closure;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

use function array_slice;
use function count;
use function max;
use function min;

/**
 * A keyboard driven list of rows, each one identified by a key.
 */
final class MenuWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /** @var list<array{key: string, label: string}> */
    private array $rows = [];

    private int $cursor = 0;

    /** @var (Closure(string): void)|null */
    private ?Closure $onMove = null;

    /** @var (Closure(string): void)|null */
    private ?Closure $onActivate = null;

    private bool $expanded = true;

    /**
     * Replaces the rows, keeping the cursor on the same key when it still exists.
     *
     * @param list<array{key: string, label: string}> $rows
     */
    public function setRows(array $rows): static
    {
        if ($rows === $this->rows) {
            return $this;
        }

        $key = $this->cursorKey();
        $this->rows = $rows;
        $this->cursor = 0;
        if (null !== $key) {
            $this->moveTo($key);
        }

        $this->invalidate();

        return $this;
    }

    public function cursorKey(): ?string
    {
        return $this->rows[$this->cursor]['key'] ?? null;
    }

    public function moveTo(string $key): static
    {
        foreach ($this->rows as $index => $row) {
            if ($row['key'] !== $key || $index === $this->cursor) {
                continue;
            }

            $this->cursor = $index;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * @param Closure(string): void $callback called with the key of the row under the cursor
     */
    public function onMove(Closure $callback): static
    {
        $this->onMove = $callback;

        return $this;
    }

    /**
     * @param Closure(string): void $callback called with the key of the activated row
     */
    public function onActivate(Closure $callback): static
    {
        $this->onActivate = $callback;

        return $this;
    }

    public function expandVertically(bool $expand): static
    {
        if ($this->expanded !== $expand) {
            $this->expanded = $expand;
            $this->invalidate();
        }

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }

    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }

        if ([] === $this->rows) {
            return;
        }

        $keybindings = $this->getKeybindings();
        $last = count($this->rows) - 1;
        $cursor = match (true) {
            $keybindings->matches($data, 'up') => 0 === $this->cursor ? $last : $this->cursor - 1,
            $keybindings->matches($data, 'down') => $this->cursor === $last ? 0 : $this->cursor + 1,
            $keybindings->matches($data, 'first') => 0,
            $keybindings->matches($data, 'last') => $last,
            default => null,
        };

        if (null !== $cursor) {
            $this->cursor = $cursor;
            $this->invalidate();
            if (null !== $this->onMove) {
                ($this->onMove)($this->rows[$cursor]['key']);
            }

            return;
        }

        if ($keybindings->matches($data, 'activate') && null !== $this->onActivate) {
            ($this->onActivate)($this->rows[$this->cursor]['key']);
        }
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $rows = max(1, $context->getRows());

        $offset = max(0, min($this->cursor - $rows + 1, count($this->rows) - $rows));
        $lines = [];
        foreach (array_slice($this->rows, $offset, $rows, true) as $index => $row) {
            if ($index === $this->cursor) {
                $label = AnsiUtils::truncateToWidth(AnsiUtils::stripAnsiCodes($row['label']), $columns, '…', true);
                $lines[] = $this->isFocused() ? "\e[1;7m" . $label . "\e[0m" : "\e[1m" . $label . "\e[0m";

                continue;
            }

            $lines[] = AnsiUtils::truncateToWidth($row['label'], $columns, '…');
        }

        // Fill the height, so that the border runs along the whole menu
        while ($this->expanded && count($lines) < $context->getRows()) {
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP, 'k'],
            'down' => [Key::DOWN, 'j'],
            'first' => [Key::HOME],
            'last' => [Key::END],
            'activate' => [Key::ENTER, Key::RIGHT, Key::SPACE],
        ];
    }
}
