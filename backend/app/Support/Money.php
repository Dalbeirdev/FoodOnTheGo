<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use NumberFormatter;

/**
 * Authoritative money value: an integer amount in the currency's minor unit plus an ISO 4217 code.
 * Floats never enter this class; formatting with symbols is presentation and belongs to the clients. API shape: {"amount": 24900, "currency": "INR"} (amount = minor units).
 */
final readonly class Money implements JsonSerializable
{
    public string $currency;

    public function __construct(public int $amount, string $currency)
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be an ISO 4217 alphabetic code, e.g. INR.');
        }

        $this->currency = $currency;
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * @param  array{amount: int, currency: string}  $value
     */
    public static function fromArray(array $value): self
    {
        if (! is_int($value['amount'] ?? null) || ! is_string($value['currency'] ?? null)) {
            throw new InvalidArgumentException('Money requires an integer amount (minor units) and a currency code.');
        }

        return new self($value['amount'], $value['currency']);
    }

    public function plus(self $other): self
    {
        return new self($this->amount + $this->sameCurrency($other)->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        return new self($this->amount - $this->sameCurrency($other)->amount, $this->currency);
    }

    public function times(int $quantity): self
    {
        return new self($this->amount * $quantity, $this->currency);
    }

    /**
     * Applies a rate expressed in basis points (1% = 100 bp) using integer arithmetic, rounding half up.
     */
    public function basisPoints(int $basisPoints): self
    {
        return new self(intdiv($this->amount * $basisPoints + 5000, 10000), $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /**
     * Number of minor-unit digits of the currency (INR 2, JPY 0, KWD 3), taken from ICU data.
     */
    public function fractionDigits(): int
    {
        $formatter = new NumberFormatter('en@currency='.$this->currency, NumberFormatter::CURRENCY);

        return $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    /**
     * @return array{amount: int, currency: string}
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    /**
     * @return array{amount: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function sameCurrency(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }

        return $other;
    }
}
