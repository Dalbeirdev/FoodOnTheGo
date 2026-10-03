<?php

namespace App\Services\Customer;

use App\Auth\Principal;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\Customer;
use App\Models\CustomerNotificationPreference;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Notification preferences of a customer (Module 25): a category × channel matrix of what the customer wants
 * to hear about. The policy lives in config/customer.php — transactional categories default to on, marketing
 * (PROMOTIONS) defaults to off and is never inferred, and the locked channels of ACCOUNT_SECURITY cannot be
 * switched off: a marketing opt-out never silences a security notice. Preferences are choices only; delivery
 * (push, SMS, e-mail) is a later module, and device push tokens are registered separately (Module 33).
 */
final class NotificationPreferenceService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * The full matrix: policy defaults merged with the customer's stored choices.
     *
     * @return array<string, mixed>
     */
    public function matrix(Customer $customer): array
    {
        $stored = CustomerNotificationPreference::query()->where('customer_id', $customer->getKey())->get()
            ->keyBy(fn (CustomerNotificationPreference $p): string => $p->category->value.'|'.$p->channel->value);
        $categories = [];
        foreach (config('customer.notifications.categories') as $code => $policy) {
            $channels = [];
            foreach (config('customer.notifications.channels') as $channel) {
                $locked = in_array($channel, $policy['locked'], true);
                $row = $stored->get($code.'|'.$channel);
                $channels[] = [
                    'channel' => $channel,
                    'enabled' => $locked ? true : ($row?->enabled ?? (bool) ($policy['defaults'][$channel] ?? false)),
                    'locked' => $locked,
                    'chosen' => $row !== null,
                ];
            }
            $categories[] = ['category' => $code, 'name' => $policy['name'], 'description' => $policy['description'], 'transactional' => (bool) $policy['transactional'], 'channels' => $channels];
        }

        return [
            'categories' => $categories,
            'channels' => array_values(config('customer.notifications.channels')),
            'marketing_consent' => ['granted_at' => $customer->promotions_consented_at?->toIso8601String(), 'withdrawn_at' => $customer->promotions_withdrawn_at?->toIso8601String()],
        ];
    }

    /**
     * @param  list<array{category: string, channel: string, enabled: bool}>  $preferences
     * @return array<string, mixed>
     */
    public function update(Customer $customer, array $preferences, Principal $actor): array
    {
        $policy = config('customer.notifications.categories');
        $channels = config('customer.notifications.channels');
        $changes = [];

        DB::transaction(function () use ($customer, $preferences, $policy, $channels, $actor, &$changes): void {
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $promotionsBefore = $this->promotionsEnabled($locked);
            foreach (array_values($preferences) as $i => $pref) {
                $category = (string) ($pref['category'] ?? '');
                $channel = (string) ($pref['channel'] ?? '');
                if (! isset($policy[$category]) || NotificationCategory::tryFrom($category) === null) {
                    throw ValidationException::withMessages(["preferences.$i.category" => ['Unknown notification category.']]);
                }
                if (! in_array($channel, $channels, true) || NotificationChannel::tryFrom($channel) === null) {
                    throw ValidationException::withMessages(["preferences.$i.channel" => ['Unknown notification channel.']]);
                }
                $enabled = (bool) $pref['enabled'];
                if (! $enabled && in_array($channel, $policy[$category]['locked'], true)) {
                    throw ValidationException::withMessages(["preferences.$i.enabled" => ['Security notices on this channel cannot be switched off.']]);
                }
                $row = CustomerNotificationPreference::query()->firstOrNew(['customer_id' => $locked->getKey(), 'category' => $category, 'channel' => $channel]);
                $before = $row->exists ? (bool) $row->enabled : (bool) ($policy[$category]['defaults'][$channel] ?? false);
                if ($before !== $enabled || ! $row->exists) {
                    $row->forceFill(['customer_id' => $locked->getKey(), 'category' => $category, 'channel' => $channel, 'enabled' => $enabled])->save();
                }
                if ($before !== $enabled) {
                    $changes[$category.'.'.$channel] = ['from' => $before, 'to' => $enabled];
                }
            }
            $promotionsAfter = $this->promotionsEnabled($locked);
            if (! $promotionsBefore && $promotionsAfter) {
                $locked->forceFill(['promotions_consented_at' => now(), 'promotions_withdrawn_at' => null])->save();
            } elseif ($promotionsBefore && ! $promotionsAfter) {
                $locked->forceFill(['promotions_withdrawn_at' => now()])->save();
            }
            if ($changes !== []) {
                $this->audit->record('customer.notification_preferences_changed', $locked, $actor, $changes, null, $locked->market_id === null ? null : (int) $locked->market_id);
            }
        });

        return $this->matrix($customer->refresh());
    }

    private function promotionsEnabled(Customer $customer): bool
    {
        return CustomerNotificationPreference::query()->where('customer_id', $customer->getKey())->where('category', NotificationCategory::Promotions->value)->where('enabled', true)->exists();
    }
}
