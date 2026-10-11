<?php

namespace App\Services\Notifications\Providers;

use SensitiveParameter;

/**
 * One push to one device. `data` values are strings (FCM requires it). The token is the only sensitive field and is
 * never logged or serialised by anything in this domain.
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        #[SensitiveParameter] public string $token,
        public string $title,
        public string $body,
        public array $data,
        public string $priority,           // NORMAL | HIGH
        public string $androidChannelId,
        public string $collapseKey,        // e.g. "order-<order public id>" so repeated notices replace each other
        public int $ttlSeconds,
        public string $deliveryPublicId,
    ) {}

    /** The FCM HTTP v1 message body (without the token when $withToken is false — for size checks and samples). */
    public function toFcm(bool $withToken = true): array
    {
        $message = [
            'notification' => ['title' => $this->title, 'body' => $this->body],
            'data' => $this->data,
            'android' => [
                'priority' => $this->priority === 'HIGH' ? 'HIGH' : 'NORMAL',
                'ttl' => $this->ttlSeconds.'s',
                'collapse_key' => $this->collapseKey,
                'notification' => ['channel_id' => $this->androidChannelId, 'tag' => $this->collapseKey, 'visibility' => 'PRIVATE'],
            ],
        ];
        if ($withToken) {
            $message['token'] = $this->token;
        }

        return $message;
    }

    public function payloadBytes(): int
    {
        return strlen((string) json_encode($this->toFcm(false), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
