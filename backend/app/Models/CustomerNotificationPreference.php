<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of a customer's notification matrix (Module 25): category × channel → enabled. Only cells the
 * customer changed are stored; the rest follow the policy defaults in config/customer.php. Preferences are
 * the customer's choices — delivery is a later module.
 */
class CustomerNotificationPreference extends Model
{
    use StoresUtcTimestamps;

    /** @var list<string> */
    protected $fillable = ['customer_id', 'category', 'channel', 'enabled'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['category' => NotificationCategory::class, 'channel' => NotificationChannel::class, 'enabled' => 'boolean'];
    }
}
