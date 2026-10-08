<?php

namespace App\Services\Order;

use App\Models\CheckoutSession;
use App\Models\PaymentAttempt;

/**
 * Reads the confirmed checkout and says what the order's rows will be (Module 31). Everything is copied; nothing is
 * priced, looked up in the menu or taken from a client. Safe fields only: no card, no route, no provider secret.
 */
final class OrderSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function columns(CheckoutSession $session, ?PaymentAttempt $attempt): array
    {
        $snapshot = (array) $session->snapshot;
        $pickup = (array) ($snapshot['pickup'] ?? []);

        return [
            'customer_id' => $session->customer_id,
            'organization_id' => $session->location->organization_id,
            'location_id' => $session->location_id,
            'market_id' => $session->market_id,
            'checkout_session_id' => $session->getKey(),
            'checkout_public_id' => (string) $session->public_id,
            'payment_attempt_id' => $attempt?->getKey(),
            'payment_public_id' => $attempt === null ? null : (string) $attempt->public_id,
            'currency' => (string) $session->currency,
            'subtotal_minor' => (int) $session->subtotal_minor,
            'discount_minor' => (int) $session->discount_minor,
            'tax_minor' => (int) $session->tax_minor,
            'tax_included_minor' => (int) $session->tax_included_minor,
            'fee_minor' => (int) $session->fee_minor,
            'total_minor' => (int) $session->total_minor,
            'item_count' => (int) ($snapshot['item_count'] ?? array_sum(array_map(fn (array $l): int => (int) ($l['quantity'] ?? 0), (array) ($snapshot['items'] ?? [])))),
            'promotion_id' => $session->promotion_id,
            'promotion_code' => $session->promotion_code,
            'pickup_mode' => $session->pickup_mode->value,
            'pickup_method' => $session->pickup_method->value,
            'pickup_at' => $session->pickup_at,
            'pickup_slot_starts_at' => $session->pickup_slot_starts_at,
            'pickup_slot_ends_at' => $session->pickup_slot_ends_at,
            'restaurant_timezone' => (string) $session->restaurant_timezone,
            'estimated_prep_minutes' => isset($pickup['estimated_prep_minutes']) ? (int) $pickup['estimated_prep_minutes'] : null,
            'traveler_arrival_at' => $pickup['traveler_arrival_at'] ?? null,
            'customer_note' => $session->order_note,
            'snapshot' => self::context($session, $attempt),
        ];
    }

    /**
     * The part of the record that is not a column of its own.
     *
     * @return array<string, mixed>
     */
    private static function context(CheckoutSession $session, ?PaymentAttempt $attempt): array
    {
        $snapshot = (array) $session->snapshot;
        $customer = $session->customer;
        $location = $session->location;
        $organization = $location->organization;
        $journey = (array) ($session->cart?->journey_summary ?? []);

        return [
            'customer' => $customer === null ? null : [
                'id' => (string) $customer->public_id,
                'name' => $customer->displayName(),
                'phone' => (string) $customer->phone_e164,
                'email' => $customer->email,
            ],
            'restaurant' => [
                'organization_id' => (string) $organization->public_id,
                'organization_name' => (string) $organization->display_name,
                'location_id' => (string) $location->public_id,
                'slug' => (string) $location->slug,
                'name' => (string) $location->name,
                'branch_label' => $location->branch_label,
                'formatted_address' => (string) $location->formatted_address,
                'timezone' => (string) $location->timezone,
                'phone' => $location->phone_e164,
                'email' => $location->public_email,
                'pickup_instructions' => $location->pickup_instructions,
                'country_code' => (string) ($location->market?->country_code ?? ''),
            ],
            'legal' => $session->legalAcceptances->map(fn ($a): array => ['type' => $a->document_type instanceof \BackedEnum ? (string) $a->document_type->value : (string) $a->document_type, 'version' => (string) $a->document_version, 'accepted_at' => $a->accepted_at?->toIso8601String()])->values()->all(),
            'payment' => $attempt === null ? null : [
                'id' => (string) $attempt->public_id,
                'provider' => (string) $attempt->provider,
                'mode' => (string) $attempt->provider_mode,
                'provider_payment_reference' => $attempt->provider_payment_reference,
                'method_type' => $attempt->method_type,
                'method_summary' => (array) ($attempt->method_summary ?? []),
                'amount' => ['amount' => (int) $attempt->amount_minor, 'currency' => (string) $attempt->currency],
                'verified_at' => $attempt->verified_at?->toIso8601String(),
            ],
            'promotion' => $snapshot['promotion'] ?? null,
            'configuration' => $snapshot['configuration'] ?? null,
            'pricing_fingerprint' => (string) $session->pricing_fingerprint,
            'checkout_version' => (int) $session->version,
            // The journey only as far as the order needs it: when the traveller arrives and how far the detour is.
            // Never the origin, the destination or the route.
            'journey' => $session->cart?->journey_public_id === null ? null : [
                'id' => (string) $session->cart->journey_public_id,
                'estimated_arrival_at' => $journey['estimated_arrival_at'] ?? null,
                'detour' => $journey['detour'] ?? null,
            ],
        ];
    }

    /**
     * @return list<array{line: array<string, mixed>, options: list<array<string, mixed>>}>
     */
    public static function items(CheckoutSession $session): array
    {
        $out = [];
        foreach (array_values((array) (((array) $session->snapshot)['items'] ?? [])) as $i => $line) {
            $item = (array) ($line['item'] ?? []);
            $options = [];
            foreach (['variants' => 'VARIANT', 'modifiers' => 'MODIFIER'] as $key => $kind) {
                foreach ((array) ($line[$key] ?? []) as $group) {
                    foreach ((array) ($group['options'] ?? []) as $option) {
                        $options[] = [
                            'position' => count($options),
                            'kind' => $kind,
                            'group_name' => mb_substr((string) ($group['group_name'] ?? ''), 0, 120),
                            'option_name' => mb_substr((string) ($option['name'] ?? ''), 0, 120),
                            'price_adjustment_minor' => (int) ($option['price_adjustment_minor'] ?? 0),
                            'group_public_id' => $group['group_id'] ?? null,
                            'option_public_id' => $option['id'] ?? null,
                        ];
                    }
                }
            }
            $out[] = [
                'line' => [
                    'position' => $i,
                    'item_public_id' => $item['id'] ?? null,
                    'item_slug' => isset($item['slug']) ? mb_substr((string) $item['slug'], 0, 120) : null,
                    'name' => mb_substr((string) ($line['name'] ?? $item['name'] ?? ''), 0, 160),
                    'quantity' => (int) ($line['quantity'] ?? 1),
                    'unit_price_minor' => (int) ($line['unit_price_minor'] ?? 0),
                    'line_subtotal_minor' => (int) ($line['line_subtotal_minor'] ?? 0),
                    'currency' => (string) $session->currency,
                    'special_instructions' => isset($line['special_instructions']) ? mb_substr((string) $line['special_instructions'], 0, 300) : null,
                ],
                'options' => $options,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function taxLines(CheckoutSession $session): array
    {
        $out = [];
        foreach (array_values((array) (((array) $session->snapshot)['taxes'] ?? [])) as $i => $line) {
            $out[] = [
                'position' => $i,
                'code' => mb_substr((string) ($line['code'] ?? ''), 0, 40),
                'label' => mb_substr((string) ($line['label'] ?? ''), 0, 120),
                'applies_to' => mb_substr((string) ($line['applies_to'] ?? 'MERCHANDISE'), 0, 16),
                'rate_basis_points' => (int) ($line['rate_basis_points'] ?? 0),
                'taxable_minor' => self::minor($line['taxable_amount'] ?? $line['taxable_minor'] ?? 0),
                'amount_minor' => self::minor($line['amount'] ?? $line['amount_minor'] ?? 0),
                'included_in_prices' => (bool) ($line['included_in_prices'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function feeLines(CheckoutSession $session): array
    {
        $out = [];
        foreach (array_values((array) (((array) $session->snapshot)['fees'] ?? [])) as $i => $line) {
            $out[] = [
                'position' => $i,
                'code' => mb_substr((string) ($line['code'] ?? ''), 0, 40),
                'label' => mb_substr((string) ($line['label'] ?? ''), 0, 120),
                'amount_minor' => self::minor($line['amount'] ?? $line['amount_minor'] ?? 0),
                'taxable' => (bool) ($line['taxable'] ?? false),
            ];
        }

        return $out;
    }

    private static function minor(mixed $value): int
    {
        return is_array($value) ? (int) ($value['amount'] ?? 0) : (int) $value;
    }
}
