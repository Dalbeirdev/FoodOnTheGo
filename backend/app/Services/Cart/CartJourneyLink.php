<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\Journey;
use App\Models\RestaurantLocation;
use App\Services\Journey\RouteDiscoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * A cart may remember the journey it was started from (Module 26) — as a REFERENCE plus a small display summary:
 *
 *   the journey's public id · the two place labels · whether the restaurant lies on the route and how far off ·
 *   the detour and arrival the customer was shown, when they had already been calculated
 *
 * No coordinates, no addresses and no route geometry are copied into the cart. Journeys are ephemeral (30 minutes,
 * then pruned): the cart outlives them, and from then on the summary is marked STALE — figures taken from an old
 * route are context, never a promise. Nothing here ever calls the routing provider or invents a figure: a detour
 * that was not calculated is null.
 */
final class CartJourneyLink
{
    public function __construct(private readonly RouteDiscoveryService $discovery) {}

    /**
     * The journey a cart may be linked to: it exists, has not expired, and belongs to the caller — the signed-in
     * customer who planned it, or the holder of its own guest token. Anything else is "no journey", with no hint
     * whether the id exists.
     */
    public function resolve(CartOwner $owner, ?string $journeyId, #[SensitiveParameter] ?string $journeyToken): ?Journey
    {
        if ($journeyId === null || $journeyId === '') {
            return null;
        }
        $journey = Journey::query()->where('journeys.public_id', $journeyId)->first();
        if ($journey === null || $journey->isExpired() || ! ($journey->ownedBy($owner->customer) || $journey->acceptsGuestToken($journeyToken))) {
            return null;
        }

        return $journey;
    }

    /**
     * Writes the reference and the summary onto the cart (the caller saves).
     */
    public function attach(Cart $cart, Journey $journey, RestaurantLocation $location): void
    {
        $distance = $this->distanceFromRoute($journey, $location);
        $detour = $this->discovery->knownDetourFor($journey, (string) $location->public_id);
        $usable = $detour !== null && $detour->usable();

        $cart->journey_public_id = $journey->public_id;
        $cart->journey_summary = [
            'origin' => self::label((array) $journey->origin),
            'destination' => self::label((array) $journey->destination),
            'restaurant_on_route' => $distance !== null && $distance <= (int) $journey->corridor_width_meters,
            'distance_from_route_meters' => $distance,
            'detour' => $usable ? ['duration_seconds' => (int) $detour->detourSeconds, 'distance_meters' => (int) $detour->detourDistanceMeters] : null,
            'estimated_arrival_at' => $usable ? $journey->departure()->addSeconds((int) $detour->legToWaypointSeconds)->utc()->toIso8601String() : null,
            'provider_mode' => (string) $journey->provider_mode,
            'journey_expires_at' => $journey->expires_at?->utc()->toIso8601String(),
            'linked_at' => CarbonImmutable::now()->utc()->toIso8601String(),
        ];
    }

    public function detach(Cart $cart): void
    {
        $cart->journey_public_id = null;
        $cart->journey_summary = null;
    }

    /**
     * Whether the linked journey can still be used: it must still exist and not have expired.
     */
    public function isLive(Cart $cart): bool
    {
        if ($cart->journey_public_id === null) {
            return false;
        }
        $expiresAt = DB::table('journeys')->where('public_id', $cart->journey_public_id)->value('expires_at');

        return $expiresAt !== null && CarbonImmutable::parse($expiresAt)->isFuture();
    }

    /**
     * The journey block of the cart resource.
     *
     * @return array<string, mixed>|null
     */
    public function present(Cart $cart, bool $live): ?array
    {
        if ($cart->journey_public_id === null) {
            return null;
        }
        $summary = (array) $cart->journey_summary;

        return [
            'id' => $cart->journey_public_id,
            'status' => $live ? 'ACTIVE' : 'STALE',
            'requires_refresh' => ! $live,
            'origin' => ['label' => $summary['origin'] ?? null],
            'destination' => ['label' => $summary['destination'] ?? null],
            'restaurant_on_route' => (bool) ($summary['restaurant_on_route'] ?? false),
            'distance_from_route_meters' => isset($summary['distance_from_route_meters']) ? (int) $summary['distance_from_route_meters'] : null,
            'detour' => $summary['detour'] ?? null,
            'estimated_arrival_at' => $summary['estimated_arrival_at'] ?? null,
            'estimate_source' => $summary['provider_mode'] ?? null,
            'linked_at' => $summary['linked_at'] ?? null,
        ];
    }

    /**
     * Straight distance from the restaurant to the route line (PostGIS, metres). It only says whether the
     * restaurant belongs to this route's corridor — the detour is the routing provider's figure, never this one.
     */
    private function distanceFromRoute(Journey $journey, RestaurantLocation $location): ?int
    {
        $row = DB::selectOne(
            'select ST_Distance(j.search_geometry::geography, l.location) as meters from journeys j, restaurant_locations l where j.id = ? and l.id = ?',
            [$journey->getKey(), $location->getKey()],
        );

        return $row === null || $row->meters === null ? null : (int) round((float) $row->meters);
    }

    /**
     * A short place name — the label the traveller saw, else the locality. Never an address or a coordinate.
     *
     * @param  array<string, mixed>  $end
     */
    private static function label(array $end): ?string
    {
        $label = $end['label'] ?? $end['locality'] ?? null;

        return is_string($label) && trim($label) !== '' ? mb_substr(trim($label), 0, 120) : null;
    }
}
