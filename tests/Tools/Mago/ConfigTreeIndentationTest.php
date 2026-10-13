<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Tools\Mago;

use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Tools\Mago\ConfigTreeIndentation;

use function preg_match;
use function preg_match_all;
use function strlen;
use function substr_count;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

final class ConfigTreeIndentationTest extends TestCase
{
    private const string TREE = <<<'PHP'
        <?php
            public function configure(DefinitionConfigurator $definition): void
            {
                // @mago-format-ignore-next
                $definition
                    ->rootNode()
                        ->children()
                            ->arrayNode('consume_options')
                                ->scalarPrototype()->end()
                                ->validate()
                                    ->ifTrue(
                                        static fn(array $options): bool => [] !== $options,
                                    )
                                    ->thenInvalid('Invalid.')
                                ->end()
                            ->end()
                            ->integerNode('concurrency')
                                ->min(1)
                            ->end()
                        ->end()
                    ->end()
                ;
            }
        PHP;

    public function testItAcceptsATreeIndentedByNesting(): void
    {
        // When
        $calls = self::calls(self::TREE);

        // Then
        static::assertTrue(ConfigTreeIndentation::isTree($calls));
        static::assertTrue(ConfigTreeIndentation::isProtected(self::TREE, self::statement(self::TREE)));
        static::assertSame([], ConfigTreeIndentation::misplaced(self::TREE, self::statement(self::TREE), $calls));
    }

    public function testItFindsTheCallsThatAreNotIndentedByNesting(): void
    {
        // Given
        $code = <<<'PHP'
            <?php
                $definition
                    ->rootNode()
                    ->children()
                        ->scalarNode('path')
                        ->defaultValue('/tmp')
                    ->end()
                    ->end()
                ->end();
            PHP;

        // When
        $misplaced = ConfigTreeIndentation::misplaced($code, self::statement($code), self::calls($code));

        // Then: line number and expected indentation
        $lines = [];
        foreach ($misplaced as $line) {
            $lines[] = [substr_count($code, "\n", 0, $line['start']) + 1, strlen($line['expected'])];
        }

        static::assertSame([[4, 12], [5, 16], [6, 20], [7, 16], [8, 12], [9, 8]], $lines);
    }

    public function testItAcceptsATreeThatStartsOnTheLineOfItsRoot(): void
    {
        // Given: the style of the Symfony code base
        $code = <<<'PHP'
            <?php
                $rootNode = $treeBuilder->getRootNode()
                    ->children()
                        ->arrayNode('section')->canBeEnabled()
                            ->children()
                                ->stringNode('name')->end()
                            ->end()
                        ->end()
                    ->end()
                ;
            PHP;

        // Then
        static::assertSame([], ConfigTreeIndentation::misplaced($code, self::statement($code), self::calls($code)));
    }

    public function testItChecksTheSectionsAddedToANode(): void
    {
        // Given
        $code = <<<'PHP'
            <?php
                $node
                    ->children()
                        ->arrayNode('section')
                            ->beforeNormalization()
                                ->ifString()
                                ->then(static fn(string $value): array => [$value])
                            ->end()
                            ->stringPrototype()->end()
                        ->end()
                    ->end()
                ;
            PHP;
        $calls = self::calls($code);

        // Then
        static::assertTrue(ConfigTreeIndentation::isTree($calls));
        static::assertSame([], ConfigTreeIndentation::misplaced($code, self::statement($code), $calls));
    }

    public function testItOnlyOpensALevelWhenTheBuilderReturnsAnother(): void
    {
        // Given: "before()" returns itself when given a closure, an expression builder otherwise
        $code = <<<'PHP'
            <?php
                $normalization
                    ->before(static fn(array $value): array => $value)
                    ->before()
                        ->always(static fn(array $value): array => $value)
                    ->end()
                ;
            PHP;

        // Then
        static::assertSame([], ConfigTreeIndentation::misplaced($code, self::statement($code), self::calls($code)));
    }

    public function testItOnlyChecksConfigurationTrees(): void
    {
        // Given
        $crawler = "<?php\n\$crawler\n    ->filter('ul')\n        ->children()\n;";
        $notClosed = "<?php\n\$node\n    ->children()\n    ->scalarNode('a');";

        // Then
        static::assertFalse(ConfigTreeIndentation::isTree(self::calls($crawler)));
        static::assertFalse(ConfigTreeIndentation::isTree(self::calls($notClosed)));
    }

    public function testItTellsWhetherTheFormatterLeavesTheTreeAlone(): void
    {
        $tree = "\$definition\n    ->rootNode()\n    ->end();";
        $protected = static fn(string $before): bool => ConfigTreeIndentation::isProtected(
            "<?php\n" . $before . $tree,
            strlen("<?php\n" . $before),
        );

        static::assertFalse($protected(''));
        static::assertTrue($protected("// @mago-format-ignore-next\n"));
        static::assertTrue($protected("// @mago-format-ignore-start\n"));
        static::assertFalse($protected("// @mago-format-ignore-start\n// @mago-format-ignore-end\n"));
        static::assertFalse($protected("// @mago-format-ignore-next\nfoo();\n"));
    }

    /**
     * The calls of the first chain, with the offset of their arrow, and
     * whether they have arguments.
     *
     * @return list<array{name: string, arrow: int, arguments: int}>
     */
    private static function calls(string $code): array
    {
        $matches = [];
        preg_match_all('/\??->(\w+)\((\))?/', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $calls = [];
        foreach ($matches as $match) {
            $calls[] = [
                'name' => $match[1][0],
                'arrow' => (int) $match[0][1],
                'arguments' => isset($match[2]) ? 0 : 1,
            ];
        }

        return $calls;
    }

    /**
     * The offset of the first statement that starts with a variable.
     */
    private static function statement(string $code): int
    {
        $match = [];
        preg_match('/^\h*\K\$/m', $code, $match, PREG_OFFSET_CAPTURE);

        return (int) ($match[0][1] ?? 0);
    }
}
