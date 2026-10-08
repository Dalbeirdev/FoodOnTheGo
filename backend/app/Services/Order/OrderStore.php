<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RestaurantLocation;
use App\Models\RestaurantUser;
use App\Services\Restaurant\RestaurantAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Who may see which order (Module 31). An order a person may not see is "not found" — the same answer as for a
 * number that was never issued. The order number is a name, never a key: every lookup is narrowed by who asks.
 */
final class OrderStore
{
    public function __construct(private readonly RestaurantAccess $access) {}

    /**
     * @return list<string>
     */
    public static function relations(): array
    {
        return ['items.options', 'taxLines', 'feeLines', 'events', 'pickupCredential', 'refunds', 'location', 'market'];
    }

    /**
     * A customer's own order, by its number or its public id.
     *
     * @throws ApiException 404 order_not_found
     */
    public function ownedBy(Customer $customer, string $reference): Order
    {
        return $this->byReference(Order::query()->where('customer_id', $customer->getKey()), $reference)->with(self::relations())->first()
            ?? throw ApiException::notFound('order_not_found', 'This order does not exist.');
    }

    /**
     * An order of a location the restaurant user may open.
     *
     * @throws ApiException 404 order_not_found
     */
    public function forRestaurant(RestaurantUser $user, string $reference): Order
    {
        $locations = $this->access->locations($user)->pluck('restaurant_locations.id')->all();

        return $this->byReference(Order::query()->whereIn('location_id', $locations), $reference)->with(self::relations())->first()
            ?? throw ApiException::notFound('order_not_found', 'This order does not exist.');
    }

    /**
     * The order row, locked for a change of state. The caller is inside a transaction.
     */
    public function locked(int $orderId): Order
    {
        return Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function byReference(Builder $query, string $reference): Builder
    {
        $reference = trim($reference);
        if (Str::isUuid($reference)) {
            return $query->where('public_id', $reference);
        }

        return $query->where('order_number', Str::upper($reference));
    }

    public function location(RestaurantUser $user, string $locationPublicId): ?RestaurantLocation
    {
        return $this->access->locations($user)->where('restaurant_locations.public_id', $locationPublicId)->first();
    }
}
