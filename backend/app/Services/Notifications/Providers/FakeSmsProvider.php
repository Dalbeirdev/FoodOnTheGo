<?php

namespace App\Services\Notifications\Providers;

use App\Contracts\Notifications\SmsNotificationProvider;

/**
 * Tests / local only: accepts deterministically and keeps the messages in memory. No SMS provider is approved for
 * notifications; REAL SMS PROVIDER = PENDING. (The Module 21 OTP sender is a different contract and policy.)
 */
final class FakeSmsProvider implements SmsNotificationProvider
{
    /** @var list<array{to: string, body: string}> */
    public array $sent = [];

    public function name(): string
    {
        return 'fake';
    }

    public function send(SmsMessage $message): ProviderResult
    {
        $this->sent[] = ['to' => $message->toE164, 'body' => $message->body];

        return ProviderResult::sent('fake-sms-'.count($this->sent));
    }

    public function health(): array
    {
        return ['status' => 'OPERATIONAL', 'driver' => 'fake', 'detail' => 'Development provider — no SMS is sent. REAL SMS PROVIDER = PENDING.', 'latency_ms' => 0];
    }
}
