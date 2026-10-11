<?php

namespace App\Services\Notifications;

use App\Enums\NotificationActionTarget;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;

/**
 * One entry of the registry (config/notifications.php `types`): what a notification of this type is and which channels
 * it may use under which rule.
 */
final readonly class NotificationType
{
    public const RULE_MANDATORY = 'mandatory';

    public const RULE_PREFERENCE = 'preference';

    public const RULE_OFF = 'off';

    /**
     * @param  array<string, string>  $channelRules  channel value => mandatory | preference | off
     */
    public function __construct(
        public string $key,
        public NotificationCategory $category,
        public string $subjectType,
        public string $priority,
        public NotificationActionTarget $target,
        public array $channelRules,
    ) {}

    public static function fromConfig(string $key, array $row): self
    {
        return new self(
            $key,
            NotificationCategory::from((string) $row['category']),
            (string) ($row['subject'] ?? 'ORDER'),
            (string) ($row['priority'] ?? 'NORMAL') === 'HIGH' ? 'HIGH' : 'NORMAL',
            NotificationActionTarget::from((string) ($row['target'] ?? 'NONE')),
            array_map(fn ($rule): string => (string) $rule, (array) ($row['channels'] ?? [])),
        );
    }

    public function rule(NotificationChannel $channel): string
    {
        return $this->channelRules[$channel->value] ?? self::RULE_OFF;
    }

    public function transactional(): bool
    {
        return (bool) (config('customer.notifications.categories.'.$this->category->value.'.transactional') ?? true);
    }

    /** @return list<NotificationChannel> */
    public function channels(): array
    {
        $out = [];
        foreach ($this->channelRules as $channel => $rule) {
            if ($rule !== self::RULE_OFF) {
                $out[] = NotificationChannel::from($channel);
            }
        }

        return $out;
    }
}
