<?php

namespace App\Enums;

/**
 * Why a payment did not succeed, in terms a customer and a support agent can act on. Mapped from what the provider
 * actually reports; whatever cannot be mapped is UNKNOWN rather than a guess.
 */
enum PaymentFailureCategory: string
{
    case CustomerCancelled = 'CUSTOMER_CANCELLED';
    case AuthenticationFailed = 'AUTHENTICATION_FAILED';
    case InsufficientFunds = 'INSUFFICIENT_FUNDS';
    case ProviderDeclined = 'PROVIDER_DECLINED';
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';
    case Timeout = 'TIMEOUT';
    case VerificationFailed = 'VERIFICATION_FAILED';
    case InitializationFailed = 'INITIALIZATION_FAILED';
    case Expired = 'EXPIRED';
    case Unknown = 'UNKNOWN';

    /**
     * Never the provider's own words, never a reproach.
     */
    public function customerMessage(): string
    {
        return match ($this) {
            self::CustomerCancelled => 'The payment was cancelled. Nothing was charged.',
            self::AuthenticationFailed => 'The payment could not be confirmed with your bank. Please try again.',
            self::InsufficientFunds => 'The payment could not be completed. Please try another payment method.',
            self::ProviderDeclined => 'The payment could not be completed. Please try another payment method.',
            self::ProviderUnavailable, self::InitializationFailed => 'Payments are temporarily unavailable. Please try again in a moment.',
            self::Timeout, self::Expired => 'The payment was not completed in time. Please try again.',
            self::VerificationFailed, self::Unknown => 'The payment could not be completed. Please try again.',
        };
    }

    public function nextAction(): PaymentNextAction
    {
        return match ($this) {
            self::InsufficientFunds, self::ProviderDeclined => PaymentNextAction::SelectAnotherMethod,
            default => PaymentNextAction::RetryPayment,
        };
    }

    /**
     * From the provider's reason codes (as documented by the provider) to a category. An unknown reason stays UNKNOWN.
     */
    public static function fromProvider(?string $reason, ?string $code = null, ?string $source = null): self
    {
        $reason = strtolower(trim((string) $reason));
        $text = $reason.' '.strtolower((string) $code);

        return match (true) {
            $reason === '' && trim((string) $code) === '' => self::Unknown,
            str_contains($text, 'cancel') => self::CustomerCancelled,
            str_contains($text, 'insufficient') => self::InsufficientFunds,
            str_contains($text, 'otp') || str_contains($text, 'authentication') || str_contains($text, 'pin') || str_contains($text, '3ds') => self::AuthenticationFailed,
            str_contains($text, 'timed_out') || str_contains($text, 'timeout') || str_contains($text, 'expired') => self::Timeout,
            str_contains($text, 'gateway') || str_contains($text, 'server_error') || str_contains($text, 'technical') || str_contains($text, 'unavailable') || str_contains($text, 'downtime') => self::ProviderUnavailable,
            str_contains($text, 'declined') || str_contains($text, 'risk') || str_contains($text, 'blocked') || str_contains($text, 'not_supported') || str_contains($text, 'invalid') || str_contains($text, 'limit') => self::ProviderDeclined,
            strtolower((string) $source) === 'customer' => self::ProviderDeclined,
            default => self::Unknown,
        };
    }
}
