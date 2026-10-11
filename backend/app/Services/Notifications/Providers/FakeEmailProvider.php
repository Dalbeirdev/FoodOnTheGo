<?php

namespace App\Services\Notifications\Providers;

use App\Contracts\Notifications\EmailNotificationProvider;

/** Tests only: records nothing, delivers nothing, always accepts. */
final class FakeEmailProvider implements EmailNotificationProvider
{
    /** @var list<array{to: string, subject: string}> */
    public array $sent = [];

    public function name(): string
    {
        return 'fake';
    }

    public function send(EmailMessage $message): ProviderResult
    {
        $this->sent[] = ['to' => $message->to, 'subject' => $message->subject];

        return ProviderResult::sent('fake-mail-'.count($this->sent));
    }

    public function health(): array
    {
        return ['status' => 'OPERATIONAL', 'driver' => 'fake', 'detail' => 'Test provider.', 'latency_ms' => 0];
    }
}
