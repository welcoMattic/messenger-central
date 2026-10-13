<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tools\Mago;

use function array_pop;
use function count;
use function in_array;
use function max;
use function preg_match;
use function rtrim;
use function strrpos;
use function strspn;
use function substr;
use function trim;

/**
 * The indentation of a Symfony configuration tree, which follows its nesting:
 *
 *     $definition
 *         ->rootNode()
 *             ->children()
 *                 ->scalarNode('path')
 *                     ->defaultValue('/tmp')
 *                 ->end()
 *             ->end()
 *         ->end()
 *     ;
 *
 * Each call is one level deeper than the line of the node it configures,
 * and "end()" is at the level of the node it closes. A tree that starts on
 * the line of its root, "$treeBuilder->getRootNode()", is one level less
 * deep, as in the Symfony code base. The calls that open a level are the
 * ones of the Symfony builders that return another builder: see
 * {@see ConfigBuilderOpeners}.
 */
final class ConfigTreeIndentation
{
    public const string INDENT = '    ';

    private const string CLOSER = 'end';
    private const array ROOTS = ['rootNode', 'getRootNode'];

    /**
     * A chain is a tree when it starts from the root, or when it starts with
     * an opener and closes it, like the ones of methods that add a section to
     * a node: "$node->children()->arrayNode('section')...->end()->end()".
     *
     * @param list<array{name: string, arrow: int, arguments: int}> $calls
     */
    public static function isTree(array $calls): bool
    {
        $closes = false;
        foreach ($calls as $call) {
            if (in_array($call['name'], self::ROOTS, true)) {
                return true;
            }

            $closes = $closes || self::CLOSER === $call['name'];
        }

        $first = $calls[0] ?? null;

        return $closes && null !== $first && ConfigBuilderOpeners::opens($first['name'], $first['arguments']);
    }

    /**
     * Whether the formatter leaves the statement alone, as it would flatten the tree.
     */
    public static function isProtected(string $contents, int $statement): bool
    {
        $before = substr($contents, 0, $statement);
        $opened = self::lastPosition($before, ['@mago-format-ignore-start', '@mago-formatter-ignore-start']);
        $closed = self::lastPosition($before, ['@mago-format-ignore-end', '@mago-formatter-ignore-end']);
        if (null !== $opened && (null === $closed || $closed < $opened)) {
            return true;
        }

        $before = rtrim($before);
        $previousLine = substr($before, (int) strrpos($before, "\n"));

        return 1 === preg_match('/@mago-format(?:ter)?-ignore-next\b/', $previousLine);
    }

    /**
     * @param int                                   $statement offset of the statement that holds the tree
     * @param list<array{name: string, arrow: int, arguments: int}> $calls     the calls of the chain in source order, with the offset of their "->"
     *
     * @return list<array{start: int, end: int, expected: string}> the indentations to replace
     */
    public static function misplaced(string $contents, int $statement, array $calls): array
    {
        // The indentation of the lines that hold the opened builders: what they hold goes one level deeper
        $levels = [self::indentation($contents, $statement)];
        $line = $levels[0];
        $misplaced = [];
        foreach ($calls as $call) {
            $closes = self::CLOSER === $call['name'];
            if ($closes && count($levels) > 1) {
                array_pop($levels);
            }

            $lineStart = self::lineStart($contents, $call['arrow']);
            $actual = substr($contents, $lineStart, $call['arrow'] - $lineStart);

            // Calls that follow something else on their line are left as they are
            if ('' === trim($actual)) {
                $line = $levels[count($levels) - 1] . self::INDENT;
                if ($actual !== $line) {
                    $misplaced[] = ['start' => $lineStart, 'end' => $call['arrow'], 'expected' => $line];
                }
            }

            if (!$closes && ConfigBuilderOpeners::opens($call['name'], $call['arguments'])) {
                $levels[] = $line;
            }
        }

        return $misplaced;
    }

    public static function indentation(string $contents, int $offset): string
    {
        $lineStart = self::lineStart($contents, $offset);

        return substr($contents, $lineStart, strspn($contents, " \t", $lineStart));
    }

    public static function lineStart(string $contents, int $offset): int
    {
        $newLine = strrpos(substr($contents, 0, $offset), "\n");

        return false === $newLine ? 0 : $newLine + 1;
    }

    /**
     * @param list<string> $needles
     */
    private static function lastPosition(string $haystack, array $needles): ?int
    {
        $last = null;
        foreach ($needles as $needle) {
            $position = strrpos($haystack, $needle);
            if (false !== $position) {
                $last = max($last ?? 0, $position);
            }
        }

        return $last;
    }
}
