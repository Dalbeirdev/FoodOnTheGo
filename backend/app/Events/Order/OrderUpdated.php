<?php

namespace App\Events\Order;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The realtime notice that an order changed (Module 32). It says "something changed, here is the new version" — it
 * is NOT the order: PostgreSQL is. Clients compare the version / sequence with what they have, apply what is newer,
 * ignore what is not, and read the order again when a number is missing or when the connection comes back.
 *
 * Sent after the commit (BroadcastOrderUpdate runs in the after-commit hook of the domain event), synchronously, and
 * a failure to send is counted and logged — never thrown into the request that made the change.
 *
 * Channels (private, server-authorized in routes/channels.php):
 *   customer.order.{order public id}           — the customer who owns the order
 *   restaurant.location.{location public id}   — staff who reach the location with restaurant.orders.view
 *
 * Payload: ids, number, version, state, the event, timing, the money state words — never a phone, a name, an item,
 * an amount, a payment reference, a journey, a note or a staff member.
 */
final class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const NAME = 'order.updated';

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $channels
     */
    public function __construct(public readonly array $payload, public readonly array $channels) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (string $name): Channel => new PrivateChannel($name), $this->channels);
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
