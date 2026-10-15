<?php

namespace Tests\Feature\Foundation;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Module 40 — the schedule survives a crashed or duplicated scheduler: every entry runs on one server, and the overlap
 * lock of every frequent sweep expires quickly instead of the framework's 24 hours.
 */
class SchedulerResilienceTest extends TestCase
{
    private const MONEY_SWEEPS = ['payments:reconcile', 'orders:recover', 'refunds:reconcile', 'finance:post-pending', 'notifications:recover', 'support:sweep'];

    public function test_every_entry_runs_on_one_server_and_frequent_sweeps_release_their_overlap_lock_quickly(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());
        $this->assertGreaterThanOrEqual(20, $events->count());

        foreach ($events as $event) {
            $this->assertTrue($event->onOneServer, (string) $event->command.' must run on one server');
            if ($event->withoutOverlapping && in_array($event->expression, ['* * * * *', '*/5 * * * *'], true)) {
                $this->assertLessThanOrEqual(10, $event->expiresAt, (string) $event->command.' keeps its overlap lock too long');
            }
        }

        foreach (self::MONEY_SWEEPS as $command) {
            $event = $events->first(fn (Event $e): bool => str_contains((string) $e->command, $command));
            $this->assertNotNull($event, $command);
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(10, $event->expiresAt);
        }
    }

    public function test_the_broadcast_client_keeps_its_short_timeouts(): void
    {
        // A duplicate 'client_options' key once replaced these with the framework's 10 s / 30 s.
        $this->assertSame(['timeout' => 2.0, 'connect_timeout' => 1.0], config('broadcasting.connections.reverb.client_options'));
    }
}
