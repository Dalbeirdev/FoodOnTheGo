<?php

namespace App\Services\Support;

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\RestaurantUser;
use App\Models\SupportCaseEvent;

/**
 * Who did something to a case (Module 37) — always derived from the authenticated caller or "the system"; no request
 * names an actor.
 */
final class SupportActor
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $publicId = null,
        public readonly ?AdminUser $admin = null,
        public readonly ?Customer $customer = null,
        public readonly ?RestaurantUser $restaurantUser = null,
    ) {}

    public static function customer(Customer $customer): self
    {
        return new self(SupportCaseEvent::ACTOR_CUSTOMER, (string) $customer->public_id, customer: $customer);
    }

    public static function restaurant(RestaurantUser $user): self
    {
        return new self(SupportCaseEvent::ACTOR_RESTAURANT, (string) $user->public_id, restaurantUser: $user);
    }

    public static function admin(AdminUser $admin): self
    {
        return new self(SupportCaseEvent::ACTOR_ADMIN, (string) $admin->public_id, admin: $admin);
    }

    public static function system(): self
    {
        return new self(SupportCaseEvent::ACTOR_SYSTEM);
    }

    public function isRequester(): bool
    {
        return $this->type === SupportCaseEvent::ACTOR_CUSTOMER || $this->type === SupportCaseEvent::ACTOR_RESTAURANT;
    }

    public function isAdmin(): bool
    {
        return $this->type === SupportCaseEvent::ACTOR_ADMIN;
    }

    public function principal(): AdminUser|Customer|RestaurantUser|null
    {
        return $this->admin ?? $this->customer ?? $this->restaurantUser;
    }
}
