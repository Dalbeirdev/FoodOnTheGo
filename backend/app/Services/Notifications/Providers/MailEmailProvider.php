<?php

namespace App\Services\Notifications\Providers;

use App\Contracts\Notifications\EmailNotificationProvider;
use App\Enums\NotificationErrorCategory;
use App\Mail\CustomerNotificationMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Laravel's mailer as the e-mail provider. Locally MAIL_MAILER=log writes the rendered e-mail to the log (the LOCAL
 * provider); a real transport is PENDING CONFIGURATION and nothing here pretends otherwise.
 */
final class MailEmailProvider implements EmailNotificationProvider
{
    public function name(): string
    {
        return 'mail:'.(string) config('mail.default');
    }

    public function send(EmailMessage $message): ProviderResult
    {
        try {
            Mail::to($message->to)->send(new CustomerNotificationMail($message->subject, $message->textBody, $message->htmlBody));

            return ProviderResult::sent();
        } catch (Throwable $e) {
            return ProviderResult::failed(NotificationErrorCategory::Transient, 'MAIL_FAILED', 'The mailer could not send the message.');
        }
    }

    public function health(): array
    {
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            return ['status' => 'OPERATIONAL', 'driver' => 'mail:'.$mailer, 'detail' => 'Local mailer ('.$mailer.'): e-mails are written locally, none is delivered. REAL EMAIL PROVIDER = PENDING.', 'latency_ms' => 0];
        }

        return ['status' => 'OPERATIONAL', 'driver' => 'mail:'.$mailer, 'detail' => 'Transport '.$mailer.' configured (not probed).', 'latency_ms' => null];
    }
}
