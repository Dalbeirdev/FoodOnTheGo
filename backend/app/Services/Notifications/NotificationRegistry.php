<?php

namespace App\Services\Notifications;

/**
 * The documented mapping domain event → notification type → category → channels → preference rule → template
 * (config/notifications.php). The only place that knows which events notify anyone.
 */
final class NotificationRegistry
{
    /** @var array<string, NotificationType>|null */
    private ?array $types = null;

    public function get(string $key): ?NotificationType
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, NotificationType> */
    public function all(): array
    {
        if ($this->types === null) {
            $this->types = [];
            foreach ((array) config('notifications.types') as $key => $row) {
                $this->types[$key] = NotificationType::fromConfig((string) $key, (array) $row);
            }
        }

        return $this->types;
    }

    public function forOrderEvent(string $eventName): ?NotificationType
    {
        $key = ((array) config('notifications.order_events'))[$eventName] ?? null; // event names carry dots: never dot-notation here

        return is_string($key) ? $this->get($key) : null;
    }

    public function forSecurityEvent(string $eventName): ?NotificationType
    {
        $key = ((array) config('notifications.security_events'))[$eventName] ?? null;

        return is_string($key) ? $this->get($key) : null;
    }

    /** The registry as the admin and the documentation see it. */
    public function describe(): array
    {
        $templates = app(NotificationTemplates::class);
        $out = [];
        foreach ($this->all() as $type) {
            $out[] = [
                'type' => $type->key,
                'category' => $type->category->value,
                'subject' => $type->subjectType,
                'priority' => $type->priority,
                'target' => $type->target->value,
                'transactional' => $type->transactional(),
                'channels' => $type->channelRules,
                'template_version' => $templates->version($type->key),
                'locales' => $templates->locales($type->key),
                'events' => array_values(array_merge(
                    array_keys(array_filter((array) config('notifications.order_events'), fn ($k): bool => $k === $type->key)),
                    array_keys(array_filter((array) config('notifications.security_events'), fn ($k): bool => $k === $type->key)),
                )),
            ];
        }

        return $out;
    }
}
