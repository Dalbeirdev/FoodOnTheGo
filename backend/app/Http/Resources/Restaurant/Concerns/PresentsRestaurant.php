<?php

namespace App\Http\Resources\Restaurant\Concerns;

use App\Enums\RestaurantImageType;
use App\Models\Cuisine;
use App\Models\RestaurantFeature;
use App\Models\RestaurantImage;
use App\Models\RestaurantLocation;
use App\Models\RestaurantLocationHour;
use App\Models\RestaurantLocationPickupMethod;
use App\Models\RestaurantSpecialHour;
use App\Models\RestaurantSpecialHourPeriod;
use App\Services\Restaurant\PickupRules;
use Carbon\CarbonImmutable;

/**
 * Building blocks shared by the three restaurant representations (customer, restaurant staff, administrator).
 * Each resource decides WHICH blocks its audience gets; a block always has one shape, so the same thing never
 * looks different between endpoints.
 *
 * Every block reads relations that the controller has eager-loaded — nothing here runs a query.
 */
trait PresentsRestaurant
{
    /**
     * @return list<array{code: string, name: string}>
     */
    protected function cuisines(RestaurantLocation $location): array
    {
        return $location->cuisines->map(fn (Cuisine $c): array => ['code' => $c->code, 'name' => $c->name])->values()->all();
    }

    /**
     * @return list<array{code: string, name: string, category: string}>
     */
    protected function features(RestaurantLocation $location): array
    {
        return $location->features->map(fn (RestaurantFeature $f): array => ['code' => $f->code, 'name' => $f->name, 'category' => $f->category])->values()->all();
    }

    /**
     * @return array<string, string|null>
     */
    protected function address(RestaurantLocation $location): array
    {
        return [
            'formatted' => $location->formatted_address,
            'line1' => $location->address_line1,
            'postal_code' => $location->postal_code,
            'city' => $location->city->name,
            'city_slug' => $location->city->slug,
            'region' => $location->region->name,
            'region_code' => $location->region->code,
            'country_code' => $location->market->country_code,
        ];
    }

    /**
     * The week as seven days (0 = Sunday), each with its opening periods in order; a closed day has none.
     * Times are wall-clock "HH:MM" in the location's time zone; `closes_at` at or before `opens_at` runs past
     * midnight.
     *
     * @return list<array{day_of_week: int, periods: list<array{opens_at: string, closes_at: string}>}>
     */
    protected function weeklyHours(RestaurantLocation $location): array
    {
        $byDay = $location->hours->groupBy('day_of_week');

        return array_map(fn (int $day): array => [
            'day_of_week' => $day,
            'periods' => ($byDay[$day] ?? collect())->map(fn (RestaurantLocationHour $h): array => ['opens_at' => $h->opens(), 'closes_at' => $h->closes()])->values()->all(),
        ], range(0, 6));
    }

    /**
     * Special dates from today (local date of the location). Customers get the coming days and the public note
     * only; staff and administrators get every upcoming date with its id and the internal note.
     *
     * @return list<array<string, mixed>>
     */
    protected function specialHours(RestaurantLocation $location, bool $internal): array
    {
        $today = CarbonImmutable::now($location->timezone);
        $from = $today->format('Y-m-d');
        $until = $internal ? null : $today->addDays((int) config('restaurant.public_special_hours_days'))->format('Y-m-d');

        return $location->specialHours
            ->filter(fn (RestaurantSpecialHour $s): bool => $s->day() >= $from && ($until === null || $s->day() <= $until))
            ->map(fn (RestaurantSpecialHour $s): array => [
                ...($internal ? ['id' => $s->public_id] : []),
                'date' => $s->day(),
                'is_closed' => $s->is_closed,
                'periods' => $s->periods->map(fn (RestaurantSpecialHourPeriod $p): array => ['opens_at' => $p->opens(), 'closes_at' => $p->closes()])->values()->all(),
                ...($internal ? ['public_note' => $s->public_note, 'internal_note' => $s->internal_note] : ['note' => $s->public_note]),
            ])->values()->all();
    }

    /**
     * What a customer can actually use: the location's settings limited by what its market allows.
     *
     * @return array<string, mixed>
     */
    protected function pickupForCustomers(RestaurantLocation $location): array
    {
        $modes = PickupRules::offeredModes($location);

        return [
            'methods' => array_map(fn (RestaurantLocationPickupMethod $m): array => [
                'method' => $m->method->value, 'instructions' => $m->instructions, 'requires_vehicle_info' => $m->requires_vehicle_info,
            ], PickupRules::offeredMethods($location)),
            'asap' => $modes['asap'],
            'scheduled' => $modes['scheduled'],
            'default_prep_minutes' => $location->pickupSettings?->default_prep_minutes,
            'minimum_lead_minutes' => $location->pickupSettings?->minimum_lead_minutes,
            'instructions' => $location->pickup_instructions,
        ];
    }

    /**
     * Logo, cover and gallery as customers see them. `url` is a site-relative path or storage key: this module
     * stores no file (uploads arrive with the object-storage integration).
     *
     * @return array{logo: array<string, string|null>|null, cover: array<string, string|null>|null, gallery: list<array<string, string|null>>}
     */
    protected function imagesForCustomers(RestaurantLocation $location): array
    {
        $image = fn (?RestaurantImage $i): ?array => $i === null ? null : ['url' => $i->path, 'alt_text' => $i->alt_text];

        return [
            'logo' => $image($location->images->firstWhere('type', RestaurantImageType::Logo)),
            'cover' => $image($location->images->firstWhere('type', RestaurantImageType::Cover)),
            'gallery' => $location->images->where('type', RestaurantImageType::Gallery)->map($image)->values()->all(),
        ];
    }

    /**
     * Every active image with its id, for the people who manage them.
     *
     * @return list<array<string, mixed>>
     */
    protected function imagesForStaff(RestaurantLocation $location): array
    {
        return $location->images->map(fn (RestaurantImage $i): array => [
            'id' => $i->public_id, 'type' => $i->type->value, 'url' => $i->path, 'alt_text' => $i->alt_text, 'display_order' => $i->display_order,
            'width' => $i->width, 'height' => $i->height,
        ])->values()->all();
    }

    /**
     * The fields the restaurant itself manages, as staff and administrators read them.
     *
     * @return array<string, mixed>
     */
    protected function managedProfile(RestaurantLocation $location): array
    {
        return [
            'id' => $location->public_id,
            'slug' => $location->slug,
            'name' => $location->name,
            'branch_label' => $location->branch_label,
            'short_description' => $location->short_description,
            'description' => $location->description,
            'pickup_instructions' => $location->pickup_instructions,
            'cuisines' => $this->cuisines($location),
            'features' => $this->features($location),
            'price_level' => $location->price_level,
            'phone' => $location->phone_e164,
            'email' => $location->public_email,
            'website' => $location->website,
            'address' => $this->address($location),
            'location' => ['latitude' => $location->latitude, 'longitude' => $location->longitude],
            'timezone' => $location->timezone,
            'currency' => $location->currency,
            'images' => $this->imagesForStaff($location),
            'status' => $location->status->value,
            'status_note' => $location->status_note,
            'rejection_category' => $location->rejection_category?->value,
            'operational_status' => $location->operational_status->value,
            'accepting_orders' => $location->accepting_orders,
            'pause_reason' => $location->pause_reason,
            'paused_at' => $location->paused_at?->toIso8601String(),
            'paused_until' => $location->paused_until?->toIso8601String(),
            'hours' => ['version' => (int) $location->hours_version, 'timezone' => $location->timezone, 'weekly' => $this->weeklyHours($location), 'special' => $this->specialHours($location, internal: true)],
            'version' => (int) $location->version,
            'updated_at' => $location->updated_at?->toIso8601String(),
        ];
    }
}
