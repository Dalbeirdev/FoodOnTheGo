<?php

namespace App\Listeners;

use App\Events\Refund\RefundEvent;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Refund;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\Notifications\NotificationRegistry;
use Illuminate\Support\Facades\Log;
use NumberFormatter;
use Throwable;

/**
 * Refund event (dispatched after the commit) → customer notification, through the Module 33 notification domain —
 * registry, preference policy, templates, deliveries. There is no second engine.
 *
 *   the provider has the refund and has not confirmed it   → "refund initiated"   (never "completed")
 *   the provider confirmed it                              → "refund completed"   (only then)
 *   it failed or needs a person                            → "taking longer — our team is on it", once per refund
 *
 * "A refund is due" for a rejected / cancelled order is announced by the order's own event (Module 31 / 33). A
 * request nobody approved, an approval and a cancelled request notify nobody. No notice promises a number of days.
 * A notification problem never undoes or delays a refund.
 */
final class NotifyOnRefundEvent
{
    private const TYPES = [
        RefundEvent::PROCESSING => 'refund.initiated',
        RefundEvent::COMPLETED => 'refund.completed',
        RefundEvent::FAILED => 'refund.attention',
        RefundEvent::REVIEW_REQUIRED => 'refund.attention',
    ];

    public function __construct(private readonly NotificationRegistry $registry, private readonly NotificationOrchestrator $orchestrator) {}

    public function handle(RefundEvent $event): void
    {
        $key = self::TYPES[$event->name] ?? null;
        $type = $key === null ? null : $this->registry->get($key);
        if ($type === null || $event->refundKey === null) {
            return;
        }
        try {
            $refund = Refund::query()->with(['customer.market', 'order'])->find($event->refundKey);
            $customer = $refund?->customer;
            // A refund without an order has no page a notice could open: the customer is not sent one that leads nowhere.
            if ($refund === null || ! $customer instanceof Customer || $refund->order === null) {
                return;
            }
            // A refund that never left FoodOnTheGo (it was never approved) is no news for the customer.
            if ($key === 'refund.attention' && $refund->approved_at === null && $refund->requested_by_type !== Refund::BY_SYSTEM && $refund->requested_by_type !== Refund::BY_RESTAURANT) {
                return;
            }
            $this->orchestrator->notify(
                $type,
                $customer,
                ['type' => CustomerNotification::SUBJECT_ORDER, 'public_id' => (string) $refund->order->public_id, 'reference' => (string) $refund->order_number],
                ['restaurant' => (string) ($refund->restaurant_name ?? ''), 'order' => (string) $refund->order_number, 'refund_amount' => self::money($refund)],
                // Once per refund and kind: a refund that fails, is retried and fails again does not write twice.
                'refund:'.$refund->public_id.':'.$type->key,
            );
        } catch (Throwable $e) {
            Log::error('notifications.refund_listener_failed', ['refund' => $event->refundId, 'event' => $event->name, 'class' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 200)]);
            if (app()->environment('testing')) {
                throw $e; // a test must see a notification bug; production only logs it
            }
        }
    }

    /** The amount as a customer reads it, e.g. "₹500.00". Formatting only — the figure is the refund's own. */
    private static function money(Refund $refund): string
    {
        $money = $refund->amount();
        $digits = $money->fractionDigits();
        $unit = 10 ** $digits;
        // Whole-number arithmetic only: the minor units are split, never divided into a float.
        $major = number_format(intdiv($money->amount, $unit));
        $minor = $digits > 0 ? '.'.str_pad((string) ($money->amount % $unit), $digits, '0', STR_PAD_LEFT) : '';
        $symbol = (new NumberFormatter('en_IN@currency='.$money->currency, NumberFormatter::CURRENCY))->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return (is_string($symbol) && $symbol !== '' ? $symbol : $money->currency.' ').$major.$minor;
    }
}
