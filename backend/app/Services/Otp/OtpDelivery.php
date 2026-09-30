<?php

namespace App\Services\Otp;

use App\Contracts\Sms\SmsProvider;
use App\Exceptions\DeliveryException;
use App\Services\Sms\SmsManager;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Decides HOW a one-time code reaches the customer. The code itself always comes from OtpService.
 *
 * Channels are tried in the configured order (OTP_CHANNELS, e.g. "whatsapp,sms" — cheapest first):
 *
 *  - first request for a number → the first channel;
 *  - each resend within the validity window → the next channel (a customer who did not receive the WhatsApp
 *    message gets an SMS without having to know why);
 *  - a customer may name a channel ("send by SMS instead");
 *  - if the chosen channel cannot deliver, the remaining ones are tried in order.
 *
 * A channel whose credentials are missing is skipped rather than failing the sign-in.
 */
final class OtpDelivery
{
    public const CHANNELS = ['whatsapp', 'sms'];

    public function __construct(private readonly SmsManager $sms) {}

    /**
     * Channels that are enabled and configured in this environment, in order of preference.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        $configured = array_values(array_filter(array_map('trim', explode(',', (string) config('otp.channels')))));
        $channels = [];

        foreach ($configured as $channel) {
            if ($channel === 'sms' || ($channel === 'whatsapp' && $this->whatsAppConfigured())) {
                $channels[] = $channel;
            }
        }

        return array_values(array_unique($channels)) ?: ['sms'];
    }

    /**
     * True when no channel sends a real message (SMS through the log driver only): the local / test setup.
     */
    public function simulated(): bool
    {
        return $this->channels() === ['sms'] && config('services.sms.driver') === 'log';
    }

    /**
     * Sends the code and returns the channel that accepted it.
     *
     * @param  int  $sendNumber  1 for the first code of this sign-in, 2 for the first resend, …
     *
     * @throws DeliveryException when no channel could deliver
     */
    public function deliver(string $phone, #[SensitiveParameter] string $code, int $minutes, ?string $preferred = null, int $sendNumber = 1): string
    {
        $failure = null;

        foreach ($this->order($preferred, $sendNumber) as $channel) {
            try {
                $this->sender($channel)->sendOtp($phone, $code, $minutes);

                return $channel;
            } catch (DeliveryException $e) {
                $failure = $e;
                Log::warning('otp.channel_failed', ['channel' => $channel, 'provider' => $e->provider, 'reason' => $e->reason]);
            }
        }

        throw $failure ?? new DeliveryException('otp', 'no delivery channel is configured', true);
    }

    /**
     * The channel the next send would start with — shown to the customer ("Resend by SMS").
     */
    public function nextChannel(int $sendNumber): string
    {
        return $this->order(null, $sendNumber)[0];
    }

    /**
     * @return list<string>
     */
    private function order(?string $preferred, int $sendNumber): array
    {
        $channels = $this->channels();
        $start = $preferred !== null && in_array($preferred, $channels, true)
            ? array_search($preferred, $channels, true)
            : min(max($sendNumber, 1) - 1, count($channels) - 1);

        return [...array_slice($channels, (int) $start), ...array_slice($channels, 0, (int) $start)];
    }

    private function sender(string $channel): SmsProvider
    {
        return $channel === 'whatsapp'
            ? new WhatsAppOtpSender((array) config('services.whatsapp'), (int) config('services.whatsapp.timeout', 10))
            : $this->sms->provider();
    }

    private function whatsAppConfigured(): bool
    {
        foreach (['access_token', 'phone_number_id', 'otp_template'] as $key) {
            if ((string) config("services.whatsapp.{$key}") === '') {
                return false;
            }
        }

        return true;
    }
}
