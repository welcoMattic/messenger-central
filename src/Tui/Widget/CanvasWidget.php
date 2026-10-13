<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tui\Widget;

use Closure;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

use function array_slice;

/**
 * Renders the lines produced by a painter, cut to the available space.
 */
final class CanvasWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $expanded = false;

    /**
     * @param Closure(int $columns, int $rows): list<string> $painter
     */
    public function __construct(
        private readonly Closure $painter,
    ) {}

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

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $lines = ($this->painter)($columns, $context->getRows());
        if ($this->expanded) {
            $lines = array_slice($lines, 0, $context->getRows());
        }

        $result = [];
        foreach ($lines as $line) {
            $result[] = AnsiUtils::truncateToWidth($line, $columns, '…');
        }

        return $result;
    }
}
