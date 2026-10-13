<?php

declare(strict_types=1);

namespace Welcomattic\MessengerCentral\Tests\Worker;

use PHPUnit\Framework\TestCase;
use Welcomattic\MessengerCentral\Worker\TransportActivity;
use Welcomattic\MessengerCentral\Worker\WorkerReport;

final class TransportActivityTest extends TestCase
{
    public function testItCountsWhatHappened(): void
    {
        // Given
        $activity = new TransportActivity();

        // When
        $activity->record(['type' => WorkerReport::RECEIVED], 1, 100.0);
        $activity->record(['type' => WorkerReport::HANDLED, 'class' => 'App\A', 'duration_ms' => 3], 1, 100.1);
        $activity->record(
            ['type' => WorkerReport::FAILED, 'class' => 'App\B', 'will_retry' => true, 'retry' => 2],
            2,
            100.2,
        );
        $activity->record(['type' => WorkerReport::FAILED, 'class' => 'App\C', 'error' => 'boom'], 2, 100.3);

        // Then
        static::assertSame(1, $activity->received);
        static::assertSame(1, $activity->processed);
        static::assertSame(1, $activity->retried);
        static::assertSame(1, $activity->failed);
        static::assertSame(['App\C', 'App\B', 'App\A'], array_column($activity->recent, 'class'));
        static::assertSame(TransportActivity::RETRIED, $activity->recent[1]['status']);
        static::assertSame(2, $activity->recent[1]['retry']);
        static::assertSame('boom', $activity->recent[0]['error']);
        static::assertSame(3.0, $activity->recent[2]['duration']);
    }

    public function testItComputesTheIncomingRate(): void
    {
        // Given
        $activity = new TransportActivity();
        foreach ([100.1, 100.5, 101.2, 103.9, 105.0] as $at) {
            $activity->record(['type' => WorkerReport::RECEIVED], 1, $at);
        }

        // When
        $history = $activity->history(6, 105.5);
        $rate = $activity->rate(105.5);

        // Then
        static::assertSame([2, 1, 0, 1, 0, 1], $history);
        static::assertSame(0.8, $rate, 'Four messages over the last five complete seconds');
        static::assertSame(0.0, new TransportActivity()->rate(105.5));
    }

    public function testItForgetsOldHistoryAndRecentEvents(): void
    {
        // Given
        $activity = new TransportActivity();
        $activity->record(['type' => WorkerReport::RECEIVED], 1, 100.0);
        for ($i = 0; $i < 60; ++$i) {
            $activity->record(['type' => WorkerReport::HANDLED], 1, 100.0);
        }

        // When
        $activity->record(['type' => WorkerReport::RECEIVED], 1, 300.0);

        // Then
        static::assertSame([0, 0, 1], $activity->history(3, 300.0));
        static::assertSame(0, array_sum($activity->history(300, 300.0)) - 1, 'The second 100 is forgotten');
        static::assertCount(50, $activity->recent);
    }
}
