<?php

namespace App\Services\Notifications;

use App\Enums\CustomerStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPolicyResult;
use App\Models\Customer;
use App\Services\Customer\NotificationPreferenceService;

/**
 * The one place that decides whether a (type, channel) reaches a customer. Inputs: the registry rule, the platform
 * channel configuration, the customer's status and consent, the Module 25 preference matrix and whether a destination
 * exists. Mandatory security notices are a rule here, never a scattered exception.
 */
final class NotificationPolicyService
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    /**
     * @return array{categories: list<array<string, mixed>>, channels: list<string>, marketing_consent: array<string, string|null>}
     */
    public function matrix(Customer $customer): array
    {
        return $this->preferences->matrix($customer);
    }

    public function channelEnabled(NotificationChannel $channel): bool
    {
        if ($channel === NotificationChannel::InApp) {
            return true;
        }
        $driver = (string) config('notifications.channels.'.$channel->value.'.driver', 'off');

        return $driver !== '' && $driver !== 'off';
    }

    /**
     * @param  array<string, mixed>  $matrix  the Module 25 matrix of this customer
     */
    public function decide(NotificationType $type, NotificationChannel $channel, Customer $customer, array $matrix, bool $destinationAvailable): NotificationPolicyResult
    {
        $rule = $type->rule($channel);
        if ($rule === NotificationType::RULE_OFF) {
            return NotificationPolicyResult::Unsupported;
        }
        if (! $this->channelEnabled($channel)) {
            return NotificationPolicyResult::SkipChannelDisabled;
        }

        $transactional = $type->transactional();
        $security = $type->category === NotificationCategory::AccountSecurity;
        if ($customer->status === CustomerStatus::Deactivated && ! $security) {
            return NotificationPolicyResult::SkipCustomerStatus;
        }
        if ($customer->status === CustomerStatus::Suspended && ! $transactional) {
            return NotificationPolicyResult::SkipCustomerStatus;
        }
        if (! $transactional && $type->category === NotificationCategory::Promotions && $customer->promotions_consented_at === null) {
            return NotificationPolicyResult::SkipUserPreference;
        }

        if ($rule === NotificationType::RULE_MANDATORY || $this->cellLocked($matrix, $type->category, $channel)) {
            return $destinationAvailable ? NotificationPolicyResult::Mandatory : NotificationPolicyResult::SkipNoDestination;
        }
        if (! $this->cellEnabled($matrix, $type->category, $channel)) {
            return NotificationPolicyResult::SkipUserPreference;
        }

        return $destinationAvailable ? NotificationPolicyResult::Send : NotificationPolicyResult::SkipNoDestination;
    }

    private function cellEnabled(array $matrix, NotificationCategory $category, NotificationChannel $channel): bool
    {
        $cell = $this->cell($matrix, $category, $channel);

        return (bool) ($cell['enabled'] ?? false);
    }

    private function cellLocked(array $matrix, NotificationCategory $category, NotificationChannel $channel): bool
    {
        $cell = $this->cell($matrix, $category, $channel);

        return (bool) ($cell['locked'] ?? false);
    }

    /** @return array<string, mixed>|null */
    private function cell(array $matrix, NotificationCategory $category, NotificationChannel $channel): ?array
    {
        foreach ((array) ($matrix['categories'] ?? []) as $row) {
            if (($row['category'] ?? null) !== $category->value) {
                continue;
            }
            foreach ((array) ($row['channels'] ?? []) as $cell) {
                if (($cell['channel'] ?? null) === $channel->value) {
                    return $cell;
                }
            }
        }

        return null;
    }
}
