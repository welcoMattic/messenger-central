<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tools\Mago;

use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\TextEdit;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

use function array_reverse;
use function count;
use function sprintf;
use function strlen;
use function strrpos;
use function substr;

/**
 * Keeps Symfony configuration trees indented by nesting level, which the
 * formatter would flatten: see {@see ConfigTreeIndentation}.
 */
final class ConfigTreeIndentationRule implements Rule
{
    public const string CODE = 'messenger-central/config-tree-indentation';

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: self::CODE,
            name: 'Config tree indentation',
            description: 'Symfony configuration trees are indented by nesting level, and left alone by the formatter.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::ExpressionStatement, NodeKind::Return],
        );
    }

    public function lint(LintContext $context): void
    {
        $file = $context->file;
        $expression = self::expression($file, $context->node);
        if (null === $expression) {
            return;
        }

        $calls = self::calls($file, $expression);
        if (!ConfigTreeIndentation::isTree($calls)) {
            return;
        }

        $statement = $context->node->span->start;
        if (!ConfigTreeIndentation::isProtected($file->contents, $statement)) {
            $context->report(Issue::new(
                'The formatter would flatten this configuration tree.',
                new Span($statement, $statement),
            )->withHelp(
                'Add a "// @mago-format-ignore-next" comment before it.',
            )->withEdit(TextEdit::insert($statement, sprintf("// @mago-format-ignore-next\n%s", ConfigTreeIndentation::indentation(
                $file->contents,
                $statement,
            )))));
        }

        foreach (ConfigTreeIndentation::misplaced($file->contents, $statement, $calls) as $line) {
            $context->report(Issue::new(
                sprintf(
                    'This call of the configuration tree should be indented by %d spaces.',
                    strlen($line['expected']),
                ),
                new Span($line['start'], $line['end']),
            )->withHelp(
                'Indent each call one level deeper than the node it configures, and "end()" at the level of the node it closes.',
            )->withEdit(TextEdit::replace(new Span($line['start'], $line['end']), $line['expected'])));
        }
    }

    /**
     * The expression of a statement, or the value it assigns, like in
     * "$rootNode = $treeBuilder->getRootNode()->children()...".
     */
    private static function expression(SourceFile $file, Node $statement): ?Node
    {
        foreach ($file->getChildren($statement) as $child) {
            if (NodeKind::Expression !== $child->kind) {
                continue;
            }

            $inner = $file->getChildren($child)[0] ?? null;

            return null !== $inner && NodeKind::Assignment === $inner->kind
                ? $file->getChildren($inner)[2] ?? null
                : $child;
        }

        return null;
    }

    /**
     * The method calls of the chain, in source order.
     *
     * @return list<array{name: string, arrow: int, arguments: int}>
     */
    private static function calls(SourceFile $file, Node $expression): array
    {
        $calls = [];
        $call = CallExpression::fromExpression($file, $expression);
        while (null !== $call && $call->isMethod()) {
            $member = $call->member;
            $receiver = $call->receiver;
            if (null === $member || null === $receiver) {
                break;
            }

            $calls[] = [
                'name' => $call->getName($file) ?? '',
                'arrow' => self::arrow($file->contents, $member->span->start),
                'arguments' => count($call->arguments),
            ];
            $call = CallExpression::fromExpression($file, $receiver);
        }

        return array_reverse($calls);
    }

    /**
     * The offset of the "->" (or "?->") before a member name.
     */
    private static function arrow(string $contents, int $member): int
    {
        $arrow = (int) strrpos(substr($contents, 0, $member), '->');

        return $arrow > 0 && '?' === $contents[$arrow - 1] ? $arrow - 1 : $arrow;
    }
}
