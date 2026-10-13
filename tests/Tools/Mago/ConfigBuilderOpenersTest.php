<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Tools\Mago;

use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Tools\Mago\ConfigBuilderOpeners;

use function array_filter;
use function array_intersect_key;
use function array_keys;

final class ConfigBuilderOpenersTest extends TestCase
{
    public function testItFindsTheMethodsThatReturnAnotherBuilder(): void
    {
        // When
        $openers = ConfigBuilderOpeners::all();

        // Then
        foreach ([
            'rootNode',
            'getRootNode',
            'children',
            'node',
            'arrayNode',
            'scalarNode',
            'stringNode',
            'enumNode',
            'prototype',
            'arrayPrototype',
            'scalarPrototype',
            'validate',
            'beforeNormalization',
        ] as $opener) {
            static::assertArrayHasKey($opener, $openers);
            static::assertTrue(ConfigBuilderOpeners::opens($opener, 1), $opener);
        }

        foreach ([
            'end',
            'info',
            'defaultValue',
            'append',
            'canBeEnabled',
            'ifTrue',
            'then',
            'min',
            'getNode',
        ] as $method) {
            static::assertFalse(ConfigBuilderOpeners::opens($method, 0), $method);
        }
    }

    public function testItTellsTheOpenersThatReturnThemselvesWithArguments(): void
    {
        static::assertTrue(ConfigBuilderOpeners::opens('before', 0));
        static::assertFalse(ConfigBuilderOpeners::opens('before', 1));
        static::assertTrue(ConfigBuilderOpeners::opens('rule', 0));
        static::assertFalse(ConfigBuilderOpeners::opens('rule', 1));
    }

    public function testNoFluentMethodSharesItsNameWithAnOpener(): void
    {
        // Given: the rule only knows method names, not the builder they are called on
        $openers = array_filter(
            ConfigBuilderOpeners::all(),
            static fn(bool $withoutArguments): bool => !$withoutArguments,
        );

        // Then
        static::assertSame([], array_keys(array_intersect_key(ConfigBuilderOpeners::fluent(), $openers)));
    }
}
