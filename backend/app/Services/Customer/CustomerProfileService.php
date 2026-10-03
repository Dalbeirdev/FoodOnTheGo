<?php

namespace App\Services\Customer;

use App\Auth\Principal;
use App\Enums\CustomerGender;
use App\Models\Cuisine;
use App\Models\Customer;
use App\Services\Audit\AuditRecorder;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The customer edits their own profile (Module 25). The target is always the authenticated customer; the
 * fields a client may change are listed here and nowhere else — status, phone, verification, market and roles
 * are not among them (the request rules refuse them with 422, and this service never reads them).
 *
 * Audit: a profile change is recorded with the names of the personal fields that changed (never their
 * values) and the values of the non-personal preferences.
 */
final class CustomerProfileService
{
    /** Fields whose values are personal data: the audit trail records only that they changed. */
    private const PERSONAL = ['name', 'email', 'date_of_birth', 'gender'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Locales the customer may choose from: the ones their market supports (the product is configured per
     * market, not per hard-coded list).
     *
     * @return list<string>
     */
    public function localeOptions(Customer $customer): array
    {
        $customer->loadMissing('market');
        $options = $customer->market?->supported_locales ?? [];
        $default = $customer->market?->default_locale ?? config('app.locale', 'en');
        $options = array_values(array_unique([...(is_array($options) ? $options : []), $default]));

        return $options === [] ? [$default] : $options;
    }

    /**
     * @param  array<string, mixed>  $input  validated fields; `version` optional (409 stale_update when it differs)
     */
    public function update(Customer $customer, array $input, Principal $actor): Customer
    {
        return DB::transaction(function () use ($customer, $input, $actor): Customer {
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            if (array_key_exists('version', $input) && $input['version'] !== null) {
                $locked->assertVersion((int) $input['version']);
            }

            if (array_key_exists('name', $input)) {
                $locked->name = PlainText::clean((string) $input['name'], 'name') ?? throw ValidationException::withMessages(['name' => ['Enter your name.']]);
            }
            if (array_key_exists('email', $input)) {
                $email = $input['email'] === null ? '' : trim((string) $input['email']);
                $locked->email = $email === '' ? null : mb_strtolower($email);
            }
            if (array_key_exists('preferred_locale', $input)) {
                $locale = $input['preferred_locale'];
                if ($locale !== null && ! in_array($locale, $this->localeOptions($locked), true)) {
                    throw ValidationException::withMessages(['preferred_locale' => ['This language is not available in your market.']]);
                }
                $locked->preferred_locale = $locale;
            }
            if (array_key_exists('date_of_birth', $input)) {
                $locked->date_of_birth = $input['date_of_birth'];
            }
            if (array_key_exists('gender', $input)) {
                $locked->gender = $input['gender'] === null || $input['gender'] === '' ? null : CustomerGender::from(strtoupper((string) $input['gender']));
            }
            if (array_key_exists('favorite_cuisines', $input)) {
                $locked->favorite_cuisines = $this->cuisineCodes((array) ($input['favorite_cuisines'] ?? []));
            }
            if (array_key_exists('vegetarian_only', $input)) {
                $locked->vegetarian_only = (bool) $input['vegetarian_only'];
            }
            if (array_key_exists('search_radius_km', $input)) {
                $locked->search_radius_km = $input['search_radius_km'] === null ? null : (int) $input['search_radius_km'];
            }

            $dirty = $locked->getDirty();
            if ($dirty !== []) {
                $changes = [];
                foreach (array_keys($dirty) as $field) {
                    $changes[$field] = in_array($field, self::PERSONAL, true)
                        ? ['from' => null, 'to' => 'changed']
                        : ['from' => $locked->getOriginal($field), 'to' => $locked->getAttribute($field) instanceof \BackedEnum ? $locked->getAttribute($field)->value : $locked->getAttribute($field)];
                }
                $locked->version = (int) $locked->version + 1;
                $locked->save();
                $this->audit->record('customer.profile_updated', $locked, $actor, $changes, null, $locked->market_id === null ? null : (int) $locked->market_id);
            }

            return $locked->refresh();
        });
    }

    /**
     * Active cuisine codes, each once, in the order given; unknown codes are a field error.
     *
     * @param  list<mixed>  $codes
     * @return list<string>
     */
    private function cuisineCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_map(fn ($c): string => (string) $c, $codes)));
        if ($codes === []) {
            return [];
        }
        $known = Cuisine::query()->active()->whereIn('code', $codes)->pluck('code')->all();
        $unknown = array_diff($codes, $known);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['favorite_cuisines' => ['Unknown cuisine: '.implode(', ', $unknown)]]);
        }

        return array_values(array_filter($codes, fn (string $c): bool => in_array($c, $known, true)));
    }
}
