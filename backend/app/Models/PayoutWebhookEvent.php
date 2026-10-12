<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A payout provider's webhook, once (Module 36): the provider's event id is unique, so a repeated delivery is seen
 * and does nothing. No payload is stored.
 */
class PayoutWebhookEvent extends Model
{
    use StoresUtcTimestamps;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['provider_created_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
